<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynRevFixBedsPgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * The v0.14 review's findings end to end with the four test beds (standing rule): Aela the
 * Huntress, Ashe (Serene's hand-set vector), Muiri and Lynly Star-Sung, each the player's partner
 * (core Player.type romantic, affinity 60) on CHIM 3.4.1 core-shaped rows (moods_issued as core
 * creates it) and the committed seed's reads. Every exchange goes through the real hooks
 * (prerequest -> context -> postrequest), the real eval producer and worker (the LLM stubbed at
 * the connector boundary), and the eval inbox. No LLM call.
 *
 *   resentment-self   the player confiding his own grief to four ashamed partners is not their
 *                     confession: one confession after the self-reflection surfaced, never a
 *                     wipe-out in one sitting; the decay beside it diverges by maturity;
 *   guilt-bleed /     the guilt reaches each partner's felt comfort (it no longer saturates away
 *   per-bond          under the partner multiplier) and the drift sample leaves it out;
 *   eval-extra-fields the Ick counts each scored exchange once, with that exchange's own reply
 *                     mood: the one who flirts back is never counted; the postrequest's peek at
 *                     the inbox feeds nothing;
 *   walkaway          back from a walkaway, with the bond broken (-20), each partner's return
 *                     grace is heard as the return, never as "leaving", until it is spent.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynReviewFixesV014TestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the hello
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynRevFixBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => the mood she answers in (moods_issued), 'default' when unset */
    private array $moods = [];
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_rev14' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and its history table
        $columns = "npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text";
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, {$columns})");
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(), " . str_replace('npc_name text NOT NULL', 'npc_name text', $columns) . ")");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        // data/database_default.sql eventlog and moods_issued (data/table_moods_issued.sql)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE moods_issued (sess character varying(1024), speaker text, mood text, listener text,
            localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns)
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynRevFixBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdrev14beds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_rev14_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        $this->setConfig([]);
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    /** Shipped defaults (+ $overrides), stored as the config page stores them. */
    private function setConfig(array $overrides): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows (partners: Player romantic, affinity 60), voice types, placeholder templates and the seed's reads. */
    private function seed(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, string $people, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people, '', $state]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /**
     * One player line to $npc through the real hooks at $gamets, logged as core logs it (input
     * row, then the reply and the mood it was said in).
     */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->home(), 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, $this->moods[$npc] ?? 'default', self::PLAYER, $this->realTs, $gamets, $gamets]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            }
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Evening: everyone at home with the player. */
    private function hello(): void
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met, love.', self::at(self::N0, 18.0) + 600 * $i++, 'hello');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d['_core_rel_type'], "{$npc}: a partner");
        }
    }

    /** Edit $npc's stored RelDyn state (as the NPC editor would). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /** Core's Player.aff for $npc (as core's relationship eval writes it). */
    private function setCoreAffinity(string $npc, int $aff): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][self::PLAYER]['aff'] = $aff;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
    }

    /** $minutes of play (core 'request' rows 5 real seconds apart): the play clock's cooldowns run on it. */
    private function play(int $fromGamets, float $minutes): int
    {
        $step = 5 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        $n = (int) ceil($minutes * 12);
        pg_query_params($this->db->link,
            "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
             SELECT 'request', '', 'web', \$1::bigint + i * \$2::bigint, 0, \$1::bigint + i * \$2::bigint, '', '' FROM generate_series(1, \$3) AS i",
            [(string) $fromGamets, (string) $step, (string) $n]);
        return $fromGamets + $n * $step;
    }

    /**
     * The eval LLM at the connector boundary: the player telling of his father is the player
     * confiding (the tag's "or the player did"), met with care; "kiss me" is open romantic
     * pursuit (romantic_intent 3); "your day" is quality time; anything else small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $father = str_contains($exchange, 'my father');
            $kiss = str_contains($exchange, 'kiss me');
            $day = str_contains($exchange, 'your day');
            return json_encode([
                'signals' => ['affinity' => $day ? 2 : 0, 'trust' => $father ? 2 : 0, 'comfort' => $father ? 2 : ($day ? 3 : 0),
                              'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $father ? ['confiding'] : ($day ? ['quality_time'] : []),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $father ? 0.5 : 0.2,
                'romantic_intent' => $kiss ? 3 : 0,
                'summary' => $father ? 'The player told her how his father died, and she listened.'
                    : ($kiss ? 'The player pressed for a kiss.' : ($day ? 'They talked about her day.' : 'Small talk.')),
            ]);
        };
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /**
     * Four ashamed partners (resentment_self 80, the editor's value) and a player who tells each
     * of them about his father eight times in one sitting (tagged confiding, met with care). The
     * self-reflection surfaces on the first turn (past 50, the player speaking). What the player
     * confides is not hers to confess (rulings 2026-09-25 §18 #10: her confession is its own tag,
     * 'confessing', RelDynP3pRulingsTest): no one is wiped clean in a sitting (before: 80 to 0 in
     * under a game minute), and no confession at all. Only the decay moves it (-0.5 x (1 +
     * maturity/100), while she is at ease enough: comfort above 20, once per 15 play minutes):
     * the four end apart.
     */
    public function testThePlayersGriefIsNotTheirConfession(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        foreach ($all as $npc) {
            $this->editDynamics($npc, function (array &$dd): void { $dd['dimensions']['resentment_self']['x'] = 80.0; });
        }
        $start = $this->play(self::at(self::N0 + 1, 0.5), 20.0);
        $trace = [];
        for ($k = 0; $k < 8; $k++) {
            foreach ($all as $i => $npc) {
                $this->turn($npc, 'Can I tell you about my father? I never told anyone how he died.', $start + 100 * (4 * $k + $i), "c{$k}");
                $stats = RelDynEval::runWorker($this->evalLlm());
                $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
                $trace[$npc][$k] = round(self::x($this->dynamics($npc), 'resentment_self'), 3);
            }
        }
        $why = json_encode($trace);
        $final = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $final[$npc] = round(self::x($d, 'resentment_self'), 3);
            $this->assertArrayHasKey('resentment_reflection', $this->felt[$npc]['c0'], "{$npc}: the reflection surfaced " . $why);
            $decay = 0.5 * (1.0 + self::x($d, 'maturity') / 100.0);
            $this->assertGreaterThan(80.0 - $decay - 0.05, $final[$npc], "{$npc}: one decay at most " . $why);
            $this->assertLessThanOrEqual(80.0, $final[$npc], "{$npc}: the decay at most (only while she is at ease enough) " . $why);
            $drops = array_values(array_filter(array_map(fn($a, $b) => $a - $b,
                array_merge([80.0], array_slice($trace[$npc], 0, -1)), $trace[$npc]), fn($x) => $x > 5.0));
            $this->assertCount(0, $drops, "{$npc}: his grief is not her confession " . $why);
            $this->assertTrue($d['_resentment_arc']['self']['confess_open'], "{$npc}: her own confession is still to come " . $why);
            $this->assertSame('normal', $d['_walkaway_state'] ?? 'normal', $npc);
        }
        $this->assertGreaterThanOrEqual(3, count(array_unique($final)), 'the decay beside it: they end apart ' . $why);
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures, 'no failed statement');
    }

    /**
     * The four partners, ashamed (resentment_self 60) over something that is not the player: the
     * guilt bleeds into each one's comfort toward him (bounded at 15). Felt through the partner
     * multiplier it now shows: the held offsets sit outside the multiplier, so her comfort as the
     * LLM reads it is the multiplied comfort without them, lowered by exactly what is held (before,
     * the multiplier saturated the guilt away: raw 45 with -15 held read as the top band, the same
     * as no guilt at all), and her comfort line is that value's band. The day's drift sample
     * records her comfort without the guilt.
     */
    public function testThePartnersFeelTheGuiltAndTheDriftLeavesItOut(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        foreach ($all as $npc) {
            $this->editDynamics($npc, function (array &$dd): void {
                $dd['dimensions']['resentment_self']['x'] = 60.0;
                $dd['dimensions']['comfort']['x'] = 60.0;
            });
        }
        $t = $this->play(self::at(self::N0 + 1, 0.5), 20.0);
        $at = [];
        foreach ($all as $i => $npc) {
            $this->turn($npc, 'Tell me about your day.', $t + 600 * $i, 'g1');
            $at[$npc] = $this->dynamics($npc);   // as the context hook read her (the eval is not applied yet)
        }
        foreach ($all as $npc) {
            $d = $at[$npc];
            $guilt = floatval($d['_resentment_arc']['guilt']['applied'] ?? 0);
            $held = RelationshipDynamics::heldTemporaryOffset($d, 'comfort');
            $own = self::x($d, 'comfort') - $held;
            $mult = RelationshipDynamics::perBondMultiplier($d, 'comfort');
            $shown = RelationshipDynamics::getEffectiveDimensionValue($d, 'comfort');
            $why = json_encode(['guilt' => $guilt, 'held' => $held, 'own' => $own, 'mult' => $mult, 'shown' => $shown,
                'felt' => $this->felt[$npc]['g1']['comfort'] ?? null]);
            $this->assertEqualsWithDelta(-15.0, $guilt, 1e-6, "{$npc}: the partner's guilt, bounded " . $why);
            $this->assertGreaterThan(1.9, $mult, "{$npc}: a partner " . $why);
            // (rulings 2026-09-25 §18 #8: a partner's comfort saturates, 100 x (1 - (1 - x/100)^mult))
            $scaled = RelationshipDynamics::perBondScaled('comfort', $own, $mult);
            $this->assertEqualsWithDelta(100.0 * (1.0 - pow(1.0 - $own / 100.0, $mult)), $scaled, 1e-9, $npc);
            $this->assertEqualsWithDelta(max(0.0, min(100.0, $scaled + $held)), $shown, 1e-6,
                "{$npc}: the multiplier on her comfort without the held offsets, the offsets as they are " . $why);
            $this->assertLessThan($scaled - 10.0, $shown, "{$npc}: the guilt shows " . $why);
            if (isset($this->felt[$npc]['g1']['comfort'])) {
                $words = implode(' ', array_slice(explode(' ', RelationshipDynamics::getDimensionBand('comfort', $shown)['keywords']), 0, 3));
                $this->assertStringContainsString(strtolower($words), strtolower((string) $this->felt[$npc]['g1']['comfort']), "{$npc}: the felt line is that band " . $why);
            }
            $samples = (array) ($d['_baseline_drift_samples']['comfort'] ?? []);
            $this->assertNotEmpty($samples, $npc);
            $last = end($samples);
            // recorded at the prerequest, before this turn's own small moves (Muiri, fearful since her
            // preset, rulings §18 #9, loses a little more comfort over the absence: the anxious part
            // of her blend); far from the 15 points of guilt either way
            $this->assertEqualsWithDelta($own, floatval($last['v']), 2.0, "{$npc}: the sample is her comfort without the guilt " . $why);
            $this->assertGreaterThan(self::x($d, 'comfort') + 13.0, floatval($last['v']), $npc);
        }
        $this->assertSame([], $this->db->failures);
    }

    /**
     * An evening of the player pressing each partner for a kiss while she is not receptive
     * (comfort 25, passion 5: the editor's values). The Ick counts each scored exchange once, with
     * the mood she answered that exchange in: Muiri flirts back every time (reciprocated, never
     * counted), the other three do not. Before the worker has scored anything the postrequest
     * counts nothing from the inbox (it no longer peeks).
     */
    public function testTheIckCountsEachScoredExchangeOnceWithItsOwnMood(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        foreach ($all as $npc) {
            $this->editDynamics($npc, function (array &$dd): void {
                $dd['dimensions']['comfort']['x'] = 25.0;
                $dd['dimensions']['passion']['x'] = 5.0;
                unset($dd['_ick_tracker']);
            });
        }
        $this->moods = ['Muiri' => 'flirty'];
        $t = $this->play(self::at(self::N0 + 1, 0.5), 20.0);
        for ($k = 0; $k < 3; $k++) {
            foreach ($all as $i => $npc) $this->turn($npc, 'Come here and kiss me.', $t + 100 * (4 * $k + $i), "k{$k}");
        }
        foreach ($all as $npc) {
            $tr = $this->dynamics($npc)['_ick_tracker'] ?? [];
            $this->assertSame(0, intval($tr['romantic_count'] ?? 0), "{$npc}: nothing scored yet, nothing counted " . json_encode($tr));
            $this->assertGreaterThanOrEqual(3, intval($tr['total_count'] ?? 0), $npc);
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        RelDynEval::runWorker($this->evalLlm());   // a second run finds nothing new
        $counts = [];
        foreach ($all as $npc) $counts[$npc] = intval($this->dynamics($npc)['_ick_tracker']['romantic_count'] ?? 0);
        $this->assertSame(['Aela the Huntress' => 3, 'Ashe' => 3, 'Muiri' => 0, 'Lynly Star-Sung' => 3], $counts,
            'each scored exchange once; Muiri flirted back ' . json_encode($counts));
        $this->assertGreaterThan(0, $this->evalCalls);
        $this->assertSame([], $this->db->failures);
    }

    /**
     * Back from a walkaway with the bond broken (core affinity -20 in a bond that was close): each
     * partner's return grace (2 contacts) is the return the LLM hears ("has returned because they
     * chose to"), never "they are leaving", and the walkaway's own action filter stays off; once
     * the grace is spent the next contact is the walkaway. With walkaway_enabled off it never
     * starts and she never says she is leaving.
     */
    public function testTheReturnGraceIsHeardAsTheReturn(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        foreach ($all as $npc) {
            $this->setCoreAffinity($npc, -20);
            $this->editDynamics($npc, function (array &$dd): void {
                $dd['context_tier_hwm'] = 3;
                $dd['_walkaway_return_grace'] = 2;
            });
        }
        $t = $this->play(self::at(self::N0 + 2, 10.0), 5.0);
        $states = [];
        for ($k = 0; $k < 3; $k++) {
            foreach ($all as $i => $npc) {
                $this->turn($npc, 'Can we talk?', $t + 1000 * (4 * $k + $i), "w{$k}");
                $states[$npc][$k] = $this->dynamics($npc)['_walkaway_state'] ?? 'normal';
            }
        }
        $why = json_encode(['states' => $states, 'felt' => array_map(fn($f) => array_map(fn($l) => $l['autonomy'] ?? null, $f), $this->felt)]);
        foreach ($all as $npc) {
            foreach (['w0', 'w1'] as $label) {
                $text = (string) ($this->felt[$npc][$label]['autonomy'] ?? '');
                $this->assertStringContainsString('has returned because they chose to', $text, "{$npc} {$label}: the grace is the return " . $why);
                $this->assertStringNotContainsString('leaving', $text, "{$npc} {$label} " . $why);
            }
            // the walkaway starts on the next contact (pending) and its tick carries it out (active)
            $this->assertSame(['normal', 'normal', 'active'], $states[$npc], "{$npc}: the grace spent, then the walkaway " . $why);
            $this->assertStringContainsString('has left', (string) ($this->felt[$npc]['w2']['autonomy'] ?? ''), "{$npc} " . $why);
        }

        // Switched off: due, never started, never "leaving"
        $this->setConfig(['walkaway_enabled' => false]);
        foreach ($all as $npc) {
            $this->editDynamics($npc, function (array &$dd): void {
                foreach (array_keys($dd) as $k) if (str_starts_with((string) $k, '_walkaway')) unset($dd[$k]);
            });
        }
        foreach ($all as $i => $npc) $this->turn($npc, 'Can we talk?', $t + 1000 * (20 + $i), 'off');
        foreach ($all as $npc) {
            $this->assertSame('normal', $this->dynamics($npc)['_walkaway_state'] ?? 'normal', $npc);
            $this->assertStringNotContainsString('leaving', (string) ($this->felt[$npc]['off']['autonomy'] ?? ''), $npc);
        }
        $this->assertSame([], $this->db->failures);
    }
}
