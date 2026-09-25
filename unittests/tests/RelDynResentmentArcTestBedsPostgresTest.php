<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynResentBedsPgDb
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
 * The resentment threshold events end to end with the four test beds (standing rule
 * feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector), Muiri and Lynly
 * Star-Sung, each the player's partner (core Player.type romantic, affinity 60) on CHIM 3.4.1
 * core-shaped rows and the committed seed's reads (RelDynTraitTestBedsPostgresTest has the
 * vectors). Day after day the player slights them; everything goes through the real hooks
 * (prerequest -> context -> postrequest), the real eval producer and worker (LLM stubbed at the
 * connector boundary: an insult is a grievance), the play clock read from core's eventlog rows,
 * and the core relationship write. No LLM call.
 *
 * What the design says, and what the four do:
 *   - MDD 15.5: at resentment 50 the NPC confronts; the attachment row's
 *     confrontation_threshold is the NPC's own (Ashe's avoidant region: 70, she holds it longest);
 *   - maturity shapes it: mature, calm and direct in one conversation (Aela, Ashe); in between,
 *     meant evenly and coming out in their style (Muiri: an accusation; Lynly); immature, a blow-up
 *     that comes again (Muiri at maturity 30);
 *   - decisions §14 / rulings §9: a mature NPC wronged again after saying it draws the one values
 *     boundary (never a second), and the next slight inside its probation is a step-back;
 *   - autonomy design: a people-pleaser (Lynly with her confidence and maturity worn down) never
 *     says it: resentment_self, guilt bleeding into her comfort, the self-reflection past 50, and
 *     the recovery path when she is asked gently and opens up.
 * Feelings, never numbers, in front of the LLM; Jev gets the numbers.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynResentmentArcTestBedsPostgresTest extends TestCase
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
    private RelDynResentBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => key => felt text the context hook put in front of the LLM, per turn label */
    private array $felt = [];
    /** npc => the <subtext> blocks the LLM got */
    private array $subtext = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_resent' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynResentBedsPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdresentbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_resent_beds_test.log');
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

    /** One player line to $npc through the real hooks at $gamets, logged as core logs it (input row, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
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
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                foreach ((array) $GLOBALS['contextDataFull'] as $m) $this->subtext[$npc][] = (string) ($m['content'] ?? '');
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

    private function coreType(string $npc): string
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode($r['extended_data'], true) ?: []);
        return (string) ($rel['type'] ?? '');
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
     * 1) with a small affinity and comfort loss; "what is weighing on you" is a confiding moment met
     * with care; anything else is small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $insult = str_contains($exchange, 'useless');
            $confide = str_contains($exchange, 'weighing on you');
            return json_encode([
                'signals' => ['affinity' => $insult ? -2 : 0, 'trust' => $confide ? 3 : 0, 'comfort' => $insult ? -2 : ($confide ? 4 : 0),
                              'respect' => $insult ? -2 : 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $insult ? ['insult'] : ($confide ? ['confiding', 'quality_time'] : []),
                'grievance' => $insult ? ['flag' => true, 'kind' => 'insult', 'severity' => 1] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $insult ? 0.4 : ($confide ? 0.5 : 0.1),
                'summary' => $insult ? 'The player called her useless in front of the household.'
                    : ($confide ? 'She opened up about what she has been carrying and was met with care.' : 'Small talk.'),
            ]);
        };
    }

    /**
     * Round $k (game day N0 + k): an hour of play, then $line to each of $npcs through the real
     * hooks, and the real eval worker scores it (stubbed LLM). Returns the round's label.
     */
    private function round(int $k, string $line, array $npcs): string
    {
        $start = self::at(self::N0 + $k, 0.5);
        $end = $this->play($start, 61.0);   // past the confrontation cooldown (60 play minutes)
        $this->event('infoloc', self::HOME, $end + 100, $this->home());
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $end + 600 * ++$i, "r{$k}");
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
        return "r{$k}";
    }

    /** The first round label whose felt lines for $npc hold $key, or null. */
    private function firstRound(string $npc, string $key): ?string
    {
        foreach ((array) ($this->felt[$npc] ?? []) as $label => $lines) {
            if (isset($lines[$key])) return (string) $label;
        }
        return null;
    }

    private static function roundNo(?string $label): int
    {
        return $label === null ? PHP_INT_MAX : intval(substr($label, 1));
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** Every felt line the resentment lane and the values boundary put in front of the LLM holds no digit. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) {
                    if (str_starts_with($key, 'resentment_') || str_starts_with($key, 'concern_')) {
                        $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$npc} {$label} {$key}");
                    }
                }
            }
        }
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

    /**
     * The same slights, day after day, to four partners (MDD 15.5 at the attachment row's
     * threshold; maturity shapes it; decisions §14: repetition feeds the one boundary):
     *   - Aela (secure, maturity 55, a werewolf of the Circle): her beast blood holds her maturity
     *     a little lower (creature moodifications), just below the mature band, so she means to say
     *     it evenly and it comes out as an accusation; she still takes the mature path;
     *   - Muiri (secure, 52, in between, accusation her style) and Lynly (secure, 51, in between)
     *     mean to say it evenly and it comes out in their style;
     *   - Ashe (avoidant region, maturity 75) holds it longest: her threshold is 70, so she says it
     *     rounds after the other three, and calmly: the one composed confrontation of the four.
     * Wronged again after saying it, the three draw the values boundary instead of a second
     * confrontation, and the next slight inside its probation is a deliberate step-back
     * (romantic -> platonic), said on its own turn (no new boundary beside it). Ashe's unmet needs
     * brought her fulfillment boundary first: her grievances are still said, once, and never a
     * second boundary beside hers.
     */
    public function testTheSameSlightsDivergeAcrossTheFourTestBeds(): void
    {
        $d = $this->hello();
        $all = array_keys(self::BEDS);
        $at = array_map(fn($dd) => RelDynResentment::confrontationThreshold($dd), $d);
        $expr = array_map(fn($dd) => RelDynConcern::expression($dd, RelDynConcern::traitsOf($dd)), $d);
        $why = json_encode(['at' => $at, 'expr' => $expr]);
        $this->assertSame(['Aela the Huntress' => 50.0, 'Ashe' => 70.0, 'Muiri' => 50.0, 'Lynly Star-Sung' => 50.0], $at, $why);
        // Aela's beast blood (werewolf by her CompanionsCircle faction) is held on her maturity;
        // without it she is in the mature band, with it just below
        $aela = $d['Aela the Huntress'];
        $held = floatval($aela['_creature']['applied']['maturity'] ?? 0.0);
        $this->assertSame('werewolf', $aela['_creature']['type'] ?? null, $why);
        $this->assertLessThan(0.0, $held, 'beast blood: maturity a little lower ' . $why);
        $bare = $aela;
        $bare['dimensions']['maturity']['x'] -= $held;
        $this->assertSame('mature', RelDynConcern::expression($bare, RelDynConcern::traitsOf($bare))['band'], $why);
        $this->assertSame(['mixed', 'mature', 'accusation'], [$expr['Aela the Huntress']['band'], $expr['Aela the Huntress']['path'],
            $expr['Aela the Huntress']['style']], $why);
        $this->assertSame('mature', $expr['Ashe']['band'], $why);
        $this->assertSame(['mixed', 'mature', 'accusation'], [$expr['Muiri']['band'], $expr['Muiri']['path'], $expr['Muiri']['style']], $why);
        $this->assertSame(['mixed', 'mature'], [$expr['Lynly Star-Sung']['band'], $expr['Lynly Star-Sung']['path']], $why);

        $trace = [];
        $type = [];
        for ($k = 1; $k <= 10; $k++) {
            $this->round($k, "You're useless, you know that?", $all);
            foreach ($all as $npc) {
                $dd = $this->dynamics($npc);
                $type[$npc]["r{$k}"] = $this->coreType($npc);
                $trace[$npc][$k] = round(self::x($dd, 'resentment'), 1) . '/' . ($dd['_concern']['boundary']['state'] ?? '-')
                    . '/' . ($dd['_fulfillment']['boundary']['state'] ?? '-') . '/' . ($dd['_walkaway_state'] ?? '-') . '/' . $type[$npc]["r{$k}"]
                    . '/' . implode(',', array_filter(array_keys((array) ($this->felt[$npc]["r{$k}"] ?? [])),
                        fn($key) => preg_match('/^(resentment_confront|concern_|fulfillment_boundary)/', $key) === 1));
            }
        }
        $why = json_encode($trace);

        $confront = array_map(fn($npc) => $this->firstRound($npc, 'resentment_confront'), array_combine($all, $all));
        foreach ($all as $npc) {
            $this->assertNotNull($confront[$npc], "{$npc} said it " . $why);
            $said = array_filter((array) $this->felt[$npc], fn($lines) => isset($lines['resentment_confront']));
            $this->assertCount(1, $said, "{$npc}: one conversation " . $why);
        }
        foreach (['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertLessThan(self::roundNo($confront['Ashe']), self::roundNo($confront[$npc]), "Ashe holds it longest (avoidant, 70) " . $why);
        }
        foreach (['Aela the Huntress', 'Ashe'] as $npc) {
            $text = $this->felt[$npc][$confront[$npc]]['resentment_confront'];
            $this->assertStringContainsString('called her useless in front of the household', $text, "{$npc}: the grievance as it happened");
        }
        $this->assertStringContainsString('calmly and directly, in one conversation', $this->felt['Ashe'][$confront['Ashe']]['resentment_confront']);
        foreach (['Aela the Huntress', 'Muiri'] as $npc) {
            $this->assertStringContainsString('means to say it evenly, but it comes out as an accusation', $this->felt[$npc][$confront[$npc]]['resentment_confront'], $npc);
        }
        $this->assertStringContainsString('means to say it evenly', $this->felt['Lynly Star-Sung'][$confront['Lynly Star-Sung']]['resentment_confront']);

        // Aela, Muiri, Lynly: the repetition went to the one boundary, then the step-back
        foreach (['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $boundary = $this->firstRound($npc, 'concern_boundary');
            $this->assertNotNull($boundary, "{$npc}: the values boundary " . $why);
            $this->assertGreaterThan(self::roundNo($confront[$npc]), self::roundNo($boundary), $npc);
            $this->assertStringContainsString('being hurt the same way after already speaking up', $this->felt[$npc][$boundary]['concern_boundary']);
            $step = $this->firstRound($npc, 'concern_step_back');
            $this->assertNotNull($step, "{$npc}: wronged inside the probation: a step-back " . $why);
            $this->assertStringContainsString('stepping back from a romance to friendship', $this->felt[$npc][$step]['concern_step_back']);
            $this->assertSame('platonic', $type[$npc][$step], "{$npc}: core Player.type " . $why);
            $this->assertArrayNotHasKey('concern_boundary', $this->felt[$npc][$step], "{$npc}: no new boundary on the step-back's turn " . $why);
            $this->assertNull($this->firstRound($npc, 'fulfillment_boundary'), "{$npc}: never a second boundary " . $why);
        }
        // Ashe: her unmet needs drew the fulfillment boundary before her threshold; her
        // grievances were said during its probation, once, and no values boundary beside it
        $fb = $this->firstRound('Ashe', 'fulfillment_boundary');
        $this->assertNotNull($fb, 'Ashe: the fulfillment boundary ' . $why);
        $this->assertLessThan(self::roundNo($confront['Ashe']), self::roundNo($fb), $why);
        $this->assertNull($this->firstRound('Ashe', 'concern_boundary'), 'Ashe: never a second boundary ' . $why);

        $this->assertFeelingsNotNumbers();
        $jev = RelationshipDynamics::jevStateBlock('Ashe');
        $this->assertSame(1, $jev['resentment_arc']['confrontations']);
        $this->assertStringContainsString('confront=1/at 70', $jev['text']);
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertGreaterThan(0, $this->evalCalls);
        $this->assertSame([], $this->db->failures, 'no failed statement');
    }

    /**
     * Maturity shapes it (rulings §9: immature NPCs fester and blow up): Muiri at maturity 30
     * (the editor's maturity, her own vector otherwise) blows up, as an accusation, the line
     * itself unsteady (the low-maturity degradation of an intense line), and blows up again when
     * it happens again; no boundary, no step-back, the romance stands. Beside her the same slights
     * bring Aela (in between: her beast blood) to one conversation, meant evenly, and then the boundary.
     */
    public function testAnImmatureMuiriBlowsUpAgainWhereAelaDrawsTheBoundary(): void
    {
        $this->hello();
        $this->editDynamics('Muiri', function (array &$dd): void {
            $dd['dimensions']['maturity']['x'] = 30.0;
            $dd['dimensions']['maturity']['baseline'] = 30.0;
        });
        $pair = ['Aela the Huntress', 'Muiri'];
        $types = [];
        for ($k = 1; $k <= 9; $k++) {
            $this->round($k, "You're useless, you know that?", $pair);
            $types["r{$k}"] = $this->coreType('Muiri');
        }
        $blowups = array_filter((array) $this->felt['Muiri'], fn($lines) => isset($lines['resentment_confront']));
        $this->assertGreaterThanOrEqual(2, count($blowups), 'festers and blows up again ' . json_encode(array_keys($blowups)));
        foreach ($blowups as $label => $lines) {
            $this->assertSaysDegraded('It all comes out at once, as an accusation', $lines['resentment_confront'], (string) $label);
            $this->assertSaysDegraded('older hurts dragged in', $lines['resentment_confront'], (string) $label);
            $this->assertMatchesRegularExpression('/[a-z][A-Z]|_|\.\.\./', $lines['resentment_confront'], "{$label}: unsteady, not composed");
        }
        $m = $this->dynamics('Muiri');
        $this->assertSame('none', $m['_concern']['boundary']['state'] ?? 'none', 'no calm boundary');
        $this->assertNull($this->firstRound('Muiri', 'concern_boundary'));
        $this->assertArrayNotHasKey('_ick_confrontation_resolved', $m, 'a blow-up resolves nothing');
        foreach (array_keys($blowups) as $label) $this->assertSame('romantic', $types[$label], "{$label}: a blow-up is not a step-back");
        $this->assertNull($this->firstRound('Muiri', 'concern_step_back'), 'never a deliberate step-back ' . json_encode($types));
        $this->assertNotContains('platonic', $types, json_encode($types));
        if ($types['r9'] !== 'romantic') {
            // festered to the MDD 15.5 walkaway, and followed: a rage walkaway severs it (romantic -> ex)
            $this->assertSame(['ex', 'permanent', 'resentment'], [$types['r9'], $m['_walkaway_state'] ?? null, $m['_walkaway_reason'] ?? null]);
        }
        $aela = $this->felt['Aela the Huntress'][$this->firstRound('Aela the Huntress', 'resentment_confront')]['resentment_confront'];
        $this->assertStringContainsString('means to say it evenly', $aela, 'Aela means to say it evenly (her beast blood)');
        $this->assertStringNotContainsString('It all comes out at once', $aela, 'Aela does not blow up');
        $this->assertNotNull($this->firstRound('Aela the Huntress', 'concern_boundary'), 'Aela, the same slights: the boundary');
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }

    /**
     * Autonomy design: a people-pleaser suffers inward. Lynly with her confidence and maturity
     * worn down (self-confidence 20, maturity 30: the editor's values) takes the same slights as
     * resentment_self where Aela, beside her, takes them as resentment toward the player: no
     * confrontation from Lynly; comfort baseline -5 past 30 and the guilt bleeding into her
     * comfort toward the player, bounded at 15; past 50 the self-reflection, said when the player
     * asks gently; and when she opens up and it is met with care, the confession and the kindness
     * take it down (the recovery path: the open issue's "trapped in permanent guilt"), the
     * standing effects lifting as it falls back.
     */
    public function testAPeoplePleaserLynlySuffersInwardAndCanRecover(): void
    {
        $this->hello();
        $this->editDynamics('Lynly Star-Sung', function (array &$dd): void {
            foreach (['self_confidence' => 20.0, 'maturity' => 30.0] as $dim => $v) {
                $dd['dimensions'][$dim]['x'] = $v;
                $dd['dimensions'][$dim]['baseline'] = $v;
            }
        });
        $pair = ['Aela the Huntress', 'Lynly Star-Sung'];
        $self = [];
        for ($k = 1; $k <= 6; $k++) {
            $this->round($k, "You're useless, you know that?", $pair);
            $self[$k] = self::x($this->dynamics('Lynly Star-Sung'), 'resentment_self');
            if ($self[$k] > 50.0) break;
        }
        $l = $this->dynamics('Lynly Star-Sung');
        $a = $this->dynamics('Aela the Huntress');
        $why = json_encode(['self' => $self, 'resentment' => self::x($l, 'resentment'), 'aela' => self::x($a, 'resentment'),
            'arc' => $l['_resentment_arc'] ?? null, 'walk' => $l['_walkaway_state'] ?? null]);
        $this->assertTrue(RelationshipDynamics::isPeoplePleaser($l));
        $this->assertGreaterThan(50.0, self::x($l, 'resentment_self'), $why);
        $this->assertLessThan(90.0, self::x($l, 'resentment_self'), 'short of the crisis ' . $why);
        $insults = array_filter((array) $l['dimensions']['resentment']['grievance_log'], fn($g) => ($g['kind'] ?? null) === 'insult');
        $this->assertNotEmpty($insults);
        $this->assertSame(['resentment_self'], array_values(array_unique(array_column($insults, 'target'))), 'every slight went inward ' . $why);
        $this->assertSame(0.0, self::x($a, 'resentment_self'), 'Aela: none inward');
        $this->assertGreaterThan(self::x($l, 'resentment'), self::x($a, 'resentment'), 'Aela holds it against the player ' . $why);
        $this->assertLessThan(RelDynResentment::confrontationThreshold($l), self::x($l, 'resentment'), $why);
        $this->assertNull($this->firstRound('Lynly Star-Sung', 'resentment_confront'), 'she never says it ' . $why);
        $this->assertNull($this->firstRound('Lynly Star-Sung', 'resentment_reflection'), 'not yet: nobody asked ' . $why);

        // The player asks gently: the reflection surfaces that turn; she opens up, met with care, day after day
        $before = self::x($l, 'resentment_self');
        $gentle = [];
        for ($j = 1; $j <= 3; $j++) {
            $gentle[] = $this->round($k + $j, "Tell me what's weighing on you. I'm listening.", ['Lynly Star-Sung']);
            if ($j === 1) {
                $mid = $this->dynamics('Lynly Star-Sung');
                $this->assertSame(-5.0, floatval($mid['_resentment_arc']['self']['baselines']['comfort']['offset'] ?? 0), 'comfort baseline -5 above 30 ' . $why);
                $this->assertLessThan(0.0, $mid['_resentment_arc']['guilt']['applied'], 'the guilt bleeds into her comfort toward the player');
                $this->assertGreaterThanOrEqual(-15.0, $mid['_resentment_arc']['guilt']['applied'], 'bounded');
                $prior = floatval($mid['_resentment_arc']['self']['baselines']['comfort']['prior']);
            }
        }
        $this->assertSame($gentle[0], $this->firstRound('Lynly Star-Sung', 'resentment_reflection'), 'past 50, asked gently: the reflection');
        $this->assertStringContainsString('If Kaida asks gently, it might finally come out',
            $this->felt['Lynly Star-Sung'][$gentle[0]]['resentment_reflection']);
        $after = $this->dynamics('Lynly Star-Sung');
        $trace = json_encode([$before, self::x($after, 'resentment_self'), $after['_resentment_arc'] ?? null]);
        $this->assertLessThan($before - 25.0, self::x($after, 'resentment_self'), 'three confessions and the kindness: the recovery path ' . $trace);
        $this->assertSame('normal', $after['_walkaway_state'] ?? 'normal', $trace);
        $this->assertLessThanOrEqual(30.0, self::x($after, 'resentment_self'), $trace);
        // The next time they speak, the standing effects are lifted exactly (and the reflection re-armed)
        $this->round($k + 4, 'Good evening, love.', ['Lynly Star-Sung']);
        $lifted = $this->dynamics('Lynly Star-Sung');
        $trace = json_encode($lifted['_resentment_arc'] ?? null);
        $this->assertSame([], $lifted['_resentment_arc']['self']['baselines'], 'the baseline offsets lift ' . $trace);
        $this->assertSame(0.0, floatval($lifted['_resentment_arc']['guilt']['applied']), 'the guilt bleed lifts ' . $trace);
        $this->assertTrue($lifted['_resentment_arc']['self']['reflect_armed'], $trace);
        $this->assertEqualsWithDelta($prior, floatval($lifted['dimensions']['comfort']['baseline'] ?? 0), 1e-6, 'her own comfort baseline again');
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }
}
