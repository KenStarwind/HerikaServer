<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCrossLaneV014PgDb
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
 * reldyn-v0.14 across its lanes, end to end with the four test beds (standing rule
 * feedback_reldyn_testbeds): Aela the Huntress (a werewolf of the Circle), Ashe (Serene's
 * hand-set vector, never read), Muiri and Lynly Star-Sung, each the player's partner, on CHIM
 * 3.4.1 core-shaped rows, through the real hooks (prerequest -> context -> postrequest), the real
 * eval producer and worker (LLM stubbed at the connector boundary) and the committed seed's reads.
 * No LLM call. Where the lanes meet:
 *   - resentment confrontation x creature moodifications: maturity shapes how a grievance comes
 *     out (MDD 15.5, rulings §9), and the full moon takes Aela's composure (maturity -10), so the
 *     grievance she means to say evenly by day comes out as a blow-up under the moon; the moon
 *     touches none of the others;
 *   - baseline drift x creature moodifications: the moon's pull is a held, temporary offset, taken
 *     back exactly; the drift samples leave it out, so three full-moon nights of company move the
 *     bond (trust) and not who Aela is (maturity).
 * Feelings, never numbers, in front of the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCrossLaneV014TestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the hello (Skyrim's moon: full on the nights of days 213-215)
    private const AELA = 'Aela the Huntress';
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
    private RelDynCrossLaneV014PgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => key => felt text the context hook put in front of the LLM, per turn label */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_x14_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns)
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
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

        $this->db = new RelDynCrossLaneV014PgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdx14beds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_x14_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        // Shipped defaults, stored as the config page stores them
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
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
        // Core's locations rows: the Bannered Mare is an Inn, Breezehome a player house
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld'), ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
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
     * row, then the reply). The RELDYN_* globals live for the whole request, as in main.php
     * (prerequest hands the diary trigger to postrequest).
     */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
        }
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Evening: everyone at home with the player; their dynamics, after the hello. */
    private function hello(): array
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met, love.', self::at(self::N0, 18.0) + 600 * $i++, 'hello');
        $d = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $this->assertSame('read', $d[$npc]['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d[$npc]['_core_rel_type'], "{$npc}: a partner");
        }
        return $d;
    }

    /** Edit $npc's stored RelDyn state (as the NPC editor would). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
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
     * The eval LLM at the connector boundary: an insult is a grievance (the MDD 15.5 +5, severity
     * 1) with a small affinity and comfort loss; a day together is quality time; anything else is
     * small talk. Never romantic (romantic_intent 0).
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $insult = str_contains($exchange, 'useless');
            $together = str_contains($exchange, 'about your day');
            return json_encode([
                'signals' => ['affinity' => $insult ? -2 : ($together ? 2 : 0), 'trust' => $together ? 4 : 0,
                              'comfort' => $insult ? -2 : ($together ? 3 : 0), 'respect' => $insult ? -2 : ($together ? 2 : 0),
                              'passion' => 0, 'maturity' => 0],
                'tags' => $insult ? ['insult'] : ($together ? ['quality_time'] : []),
                'grievance' => $insult ? ['flag' => true, 'kind' => 'insult', 'severity' => 1] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $insult ? 0.4 : ($together ? 0.5 : 0.1),
                'summary' => $insult ? 'The player called her useless in front of the household.'
                    : ($together ? 'A quiet day together.' : 'Small talk.'),
                'romantic_intent' => 0,
            ]);
        };
    }

    /** $line to each of $npcs from $gamets (ten game minutes apart), then the real eval worker. Returns the next free gamets. */
    private function round(int $gamets, string $line, array $npcs, string $label): int
    {
        $this->event('infoloc', self::HOME, $gamets - 100, $this->home());
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 600 * $i++, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
        return $gamets + 600 * $i;
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /**
     * $text says $expected, allowing the low-maturity degradation of an intense line
     * (RelDynFelt::degrade: chaotic casing, a dropped letter shown as '_', a pause for a comma).
     */
    private function assertSaysDegraded(string $expected, string $text, string $message = ''): void
    {
        $re = '';
        foreach (preg_split('//u', $expected, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (ctype_alpha($ch)) $re .= '(?:' . preg_quote($ch, '/') . '|_)';
            elseif ($ch === ',') $re .= '(?:,|\.\.\.?)';
            else $re .= preg_quote($ch, '/');
        }
        $this->assertMatchesRegularExpression('/' . $re . '/i', $text, $message);
    }

    /** No digit in anything the felt steering put in front of the LLM. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
    }

    // ------------------------------------------------------------------ confrontation x creatures

    /**
     * The hello, one slight at midday (the real eval worker files the grievance and the stored
     * event), then the editor puts every bed's resentment at 55, and at $gamets the player asks
     * each of them to talk. Returns npc => felt lines of that turn.
     */
    private function askedToTalkAt(int $gamets): array
    {
        $this->hello();
        $this->round(self::at(self::N0 + 1, 12.0), "You're useless, you know that?", array_keys(self::BEDS), 'slight');
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d): void { $d['dimensions']['resentment']['x'] = 55.0; });
        }
        $this->event('infoloc', self::HOME, $gamets - 100, $this->home());
        $felt = [];
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Can we talk?', $gamets + 600 * $i++, 'talk');
            $felt[$npc] = $this->felt[$npc]['talk'];
        }
        return $felt;
    }

    /**
     * By day (game day 212, noon; the beast blood only simmers) Aela is just below the mature
     * band: she means to say it evenly and it comes out as an accusation. Muiri and Lynly, in
     * between, the same way in their style; Ashe (avoidant, threshold 70) holds it.
     */
    public function testByDayAelaMeansToSayItEvenly(): void
    {
        $felt = $this->askedToTalkAt(self::at(self::N0 + 2, 12.0));
        $aela = $this->dynamics(self::AELA);
        $this->assertSame('werewolf_day', $aela['_creature']['state'] ?? null);
        foreach ([self::AELA, 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertArrayHasKey('resentment_confront', $felt[$npc], "{$npc}: " . json_encode(array_keys($felt[$npc])));
            $this->assertStringContainsString('means to say it evenly', $felt[$npc]['resentment_confront'], $npc);
            $this->assertStringContainsString('called her useless in front of the household', $felt[$npc]['resentment_confront'], $npc);
        }
        $this->assertArrayNotHasKey('resentment_confront', $felt['Ashe'], 'Ashe holds it (avoidant)');
        $this->assertArrayNotHasKey('creature', $felt[self::AELA], 'by day the blood simmers without a line');
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    /**
     * Under the full moon (game day 214, 22:00: Skyrim's cycle day 23) the beast blood overrides
     * Aela's composure (maturity -10): the same grievance comes out as a blow-up, the line itself
     * unsteady, the moon's own line beside it. The moon touches none of the others: Muiri and
     * Lynly say it exactly as by day, Ashe still holds it.
     */
    public function testUnderTheFullMoonAelaBlowsUp(): void
    {
        $felt = $this->askedToTalkAt(self::at(self::N0 + 4, 22.0));
        $aela = $this->dynamics(self::AELA);
        $this->assertSame(['werewolf_moon', 'full'], [$aela['_creature']['state'] ?? null, $aela['_creature']['moon'] ?? null]);
        $expr = RelDynConcern::expression($aela, RelDynConcern::traitsOf($aela));
        $this->assertSame('immature', $expr['path'], json_encode($expr));
        $this->assertArrayHasKey('resentment_confront', $felt[self::AELA], json_encode(array_keys($felt[self::AELA])));
        $this->assertSaysDegraded('It all comes out at once', $felt[self::AELA]['resentment_confront'], 'Aela blows up under the moon');
        $this->assertStringNotContainsString('means to say it evenly', $felt[self::AELA]['resentment_confront']);
        $this->assertStringContainsString('full moon', $felt[self::AELA]['creature'] ?? '', 'the moon line beside it');
        $this->assertArrayNotHasKey('_ick_confrontation_resolved', $this->dynamics(self::AELA), 'a blow-up resolves nothing');
        foreach (['Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertStringContainsString('means to say it evenly', $felt[$npc]['resentment_confront'] ?? '', "{$npc}: the moon is not hers");
            $this->assertArrayNotHasKey('creature', $felt[$npc], $npc);
        }
        $this->assertArrayNotHasKey('resentment_confront', $felt['Ashe'], 'Ashe holds it (avoidant)');
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ baseline drift x creatures

    /**
     * Three full-moon nights of company (game days 213-215 at 22:00, twenty talks a night, a quiet
     * day together each time): the bond moves who they are (baseline drift on trust), but the
     * moon's pull is a held, temporary offset, not who Aela is: her drift samples leave it out
     * and her maturity baseline does not move. The others' maturity was never away from theirs.
     */
    public function testTheMoonIsNotWhoAelaIs(): void
    {
        $start = $this->hello();
        $all = array_keys(self::BEDS);
        foreach ([1, 2, 3] as $k) {   // the nights of days 213, 214, 215: cycle days 22, 23, 0
            $day = self::N0 + 2 + $k;
            // the day's play (half an hour: the diary's reflection cooldown), then the evening in
            $t = max($this->play(self::at($day, 9.5), 31.0), self::at($day, 20.5));
            for ($r = 0; $r < 20; $r++) $t = $this->round($t, 'Tell me about your day.', $all, "night{$k}_{$r}") + 60;
            $a = $this->dynamics(self::AELA);
            $this->assertSame(['werewolf_moon', 'full'], [$a['_creature']['state'] ?? null, $a['_creature']['moon'] ?? null], "night {$k}");
            $this->assertLessThan(-5.0, floatval($a['_creature']['applied']['maturity'] ?? 0), "night {$k}: the moon's pull is held");
        }
        $drift = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $drift[$npc] = RelationshipDynamics::driftBaseline($d, 'trust') - RelationshipDynamics::driftBaseline($start[$npc], 'trust');
            $this->assertEqualsWithDelta(RelationshipDynamics::driftBaseline($start[$npc], 'maturity'),
                RelationshipDynamics::driftBaseline($d, 'maturity'), 1e-9,
                "{$npc}: maturity baseline " . json_encode($d['_baseline_drift_samples']['maturity'] ?? null));
        }
        $this->assertGreaterThan(0.0, max($drift), 'the diary ran and the bond moved trust ' . json_encode($drift));
        $a = $this->dynamics(self::AELA);
        $held = floatval($a['_creature']['applied']['maturity']);
        $samples = (array) $a['_baseline_drift_samples']['maturity'];
        $last = end($samples);
        $this->assertEqualsWithDelta(self::x($a, 'maturity') - $held, floatval($last['v']), 1e-3, 'the sample is Aela without the moon');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }
}
