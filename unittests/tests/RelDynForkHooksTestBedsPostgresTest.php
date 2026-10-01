<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
// Production requests have core's game-clock helpers loaded; core's relationship worker may not (the damper
// falls back to the newest game time CHIM logged), so a test below runs it both ways.
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_system/relationship_llm.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_system/async_queue.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_dynamics.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure; failures are kept). */
final class RelDynForkHooksPgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function record(string $what): void
    {
        $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $what), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->record((string) $q); return []; }
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
        if (!$res) $this->record((string) $q);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->record("insert {$table}");
        return $res;
    }

    /** lib/postgresql.class.php updateRow(): parameterized SET, caller-built WHERE. */
    public function updateRow($table, $data, $where)
    {
        $set = [];
        $i = 0;
        foreach (array_keys($data) as $col) $set[] = "{$col} = $" . (++$i);
        $res = @pg_query_params($this->link, "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}", array_values($data));
        if (!$res) { $this->record("updateRow {$table}"); return false; }
        return true;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/** The relationship LLM's driver: canned replies at the connector boundary (the only fake). */
final class RelDynForkHooksStubDriver
{
    /** @var string[] */
    public array $replies = [];
    public array $calls = [];

    public function fast_request($messages, $params, $context)
    {
        $this->calls[] = $context;
        if (!$this->replies) throw new RuntimeException("unexpected LLM call ({$context})");
        return array_shift($this->replies);
    }
}

/**
 * The two CHIM fork hooks of decisions §20 #19 ("RelDyn should override most social dynamics within CHIM"),
 * end to end with the four test beds (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe
 * (Serene's hand-set vector, never read; nothing of her story anywhere), Muiri and Lynly Star-Sung, on CHIM
 * 3.4.1 core-shaped rows, with the player's partners in the beds built by the real hooks:
 *
 *   1. Exclusivity damping of core's own NPC-to-NPC romance (lib/relationship_manager.php
 *      chimRelationshipDeltaFor, asked by RelationshipLLM::applyChanges and RelationshipManager::parseChanges):
 *      through core's real worker path (_relProcessQueue -> evaluateNpcToNpcContext -> applyChanges -> save)
 *      and its #REL tag path. A romantic gain toward another NPC is cut by how held she is (the pull, §17: who
 *      she is), never to nothing; everything else of core's stays core's.
 *   2. Marked moments and maturity depth into core's diary prompt (chimDiaryContextFor), through core's real
 *      generateFollowerDiary; the nearby diary and the player's diary request are asked the same way.
 *
 * With RelDyn absent core behaves byte-identically. No LLM call: both connectors are stubs at the boundary.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynForkHooksTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const SUITOR = 'Mikael';
    private const BROTHER = 'Farkas';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 200;
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const HOME = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 1:00 PM, 7th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';
    private const DIARY_PROMPT = 'Write #HERIKA_NAME#\'s diary entry about #PLAYER_NAME# and the day. WRITE AS IF YOU WERE #HERIKA_NAME#.';
    private const PAGE = 'Another day gone. The road was long and the company was good.';

    private string $dsn;
    private string $schema;
    private RelDynForkHooksPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private RelDynForkHooksStubDriver $driver;
    private RelationshipLLM $llm;
    /** @var string[] temp engine trees to remove */
    private array $engines = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_forkhooks_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog and diarylog
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
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
        // ext/relationship_system/async_queue.php _relCreateQueueTable()
        pg_query($admin, "CREATE TABLE relationship_eval_queue (id SERIAL PRIMARY KEY, npc_id INTEGER NOT NULL UNIQUE,
            eval_data JSONB NOT NULL, created_at TIMESTAMP DEFAULT NOW(), retry_count INTEGER DEFAULT 0, last_error TEXT)");
        pg_query($admin, "CREATE TABLE prompts (prompt_key text PRIMARY KEY, custom_prompt text, default_prompt text)");
        pg_query($admin, "CREATE TABLE audit_request (id serial PRIMARY KEY, request text, result text, connector text, url text)");
        // lib/core/database_schema/core_profiles.sql and core_llm_connector.sql (the diary connector)
        pg_query($admin, "CREATE TABLE core_profiles (id integer PRIMARY KEY, label text, default_npc text, default_narrator text,
            tts_connector_id integer, itt_connector_id integer, llm_primary_id integer, llm_secondary_id integer,
            llm_tertiary_id integer, llm_quaternary_id integer, llm_formatter_id integer, llm_fallback_id integer,
            metadata jsonb, diary_connector_id integer, slot integer, prompt text)");
        pg_query($admin, "CREATE TABLE core_llm_connector (id integer PRIMARY KEY, label text, metadata jsonb, url text, model text,
            provider text, driver text, reasoning_model integer, max_tokens integer, enforce_json integer DEFAULT 1,
            prefill_json integer DEFAULT 0, api_badge_id integer, json_schema integer, temperature numeric,
            presence_penalty numeric, frequency_penalty numeric, repetition_penalty numeric, top_p numeric, top_k integer,
            min_p numeric, top_a numeric, service text)");
        pg_close($admin);

        $this->db = new RelDynForkHooksPgDb($dsn, $this->schema);
        $keys = ['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                 'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                 'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'RECHAT_PREVIOUS_SPEAKER', 'RECHAT_REQUEST_PAYLOAD',
                 'ENGINE_PATH', 'CHIM_RELATIONSHIP_AFFINITY_OWNERS', 'CHIM_RELATIONSHIP_DELTA_DAMPERS', 'CHIM_DIARY_CONTEXT_PROVIDERS',
                 'DIARY_PROMPT', 'PROMPT_HEAD', 'COMMAND_PROMPT', 'CONTEXT_HISTORY', 'CONTEXT_HISTORY_DIARY', 'CHIM_CORE_CURRENT_CONNECTOR_DATA'];
        // Core finds each extension's hook file the first time it asks (and keeps the registration): ask now, before
        // the globals are saved, so tearDown restores RelDyn's registration after a test that removes it.
        chimRelationshipDeltaFor(0, '', 0);
        chimRelationshipAffinityOwned(0, '');
        chimDiaryContextFor('');
        foreach ($keys as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_diff($keys, ['ENGINE_PATH', 'CHIM_RELATIONSHIP_AFFINITY_OWNERS', 'CHIM_RELATIONSHIP_DELTA_DAMPERS', 'CHIM_DIARY_CONTEXT_PROVIDERS']) as $key) {
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the calls themselves are stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdforkhooks');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_forkhooks_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        RelDynDiary::$launcher = function (): void {};
        RelDynDiary::$llm = function () { $this->llmCalls++; return null; };

        // Shipped defaults, stored as the config page stores them
        $this->storeRelDynConfig([]);
        $this->seed();

        // Core's RelationshipLLM with its connector boundary replaced by the canned driver.
        $this->driver = new RelDynForkHooksStubDriver();
        $this->llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
        foreach (['db' => $this->db, 'driver' => $this->driver, 'modelName' => 'stub',
                     'connector' => ['id' => 1, 'driver' => 'stub', 'model' => 'stub', 'label' => 'stub']] as $prop => $value) {
            $p = new ReflectionProperty(RelationshipLLM::class, $prop);
            $p->setAccessible(true);
            $p->setValue($this->llm, $value);
        }
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        RelDynDiary::$launcher = null;
        RelDynDiary::$llm = null;
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearReldynGlobals();
        foreach ($this->engines as $dir) $this->removeTree($dir);
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixture helpers

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    /** RelDyn's config row the way saveConfig() stores it (shipped defaults, then $overrides). */
    private function storeRelDynConfig(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace_recursive(
                array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]), $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * Core rows: the four beds (the player's entry at affinity 60, type platonic: no title yet; a standing
     * 'neutral' toward the suitor and a familial bond toward Farkas), the suitor Mikael and Farkas (neither
     * ever held by RelDyn), voice types, placeholder templates and the committed seed's reads.
     */
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
                     'relationships' => ['Player' => ['aff' => 60, 'type' => 'platonic'],
                                         self::SUITOR => ['aff' => 20, 'type' => 'neutral'],
                                         self::BROTHER => ['aff' => 20, 'type' => 'familial']]])]);
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
        foreach ([[self::SUITOR, 'male', 'ImperialRace', 'Bard'], [self::BROTHER, 'male', 'NordRace', 'Warrior']] as [$name, $gender, $race, $class]) {
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, $gender, $race, '', "Roleplay as {$name}", '', json_encode(['skills' => $all]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => [], 'relationships' => []])]);
        }
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld')");
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
     * One player line to $npc through the real hooks at $gamets, logged as core logs it (input row, then the
     * reply). The RELDYN_* globals live for the whole request, as in main.php.
     */
    private function turn(string $npc, string $line, int $gamets): void
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

    /** Edit $npc's stored RelDyn state (as the NPC editor would). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /**
     * The hello (each bed meets the player at the Mare), then the editor sets who the story says they are where
     * their read does not: Muiri volatile (maturity 30, a toxic attachment), Lynly anxious. Aela keeps her read
     * (secure-leaning), Ashe her hand-set vector.
     */
    private function meet(int $day = self::N0): void
    {
        $this->event('infoloc', self::HOME, self::at($day, 12.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met.', self::at($day, 12.0) + 600 * $i++);
        $this->editDynamics('Muiri', function (array &$d): void {
            $d['dimensions']['maturity']['x'] = 30.0;
            $d['dimensions']['maturity']['baseline'] = 30.0;
            $d['profile_overrides']['attachment_style'] = 'toxic';
        });
        $this->editDynamics('Lynly Star-Sung', function (array &$d): void { $d['profile_overrides']['attachment_style'] = 'anxious'; });
        foreach (array_keys(self::BEDS) as $npc) $this->assertNotSame([], $this->dynamics($npc), "{$npc}: RelDyn holds her now");
    }

    /** Her feelings for the player, as the editor would set them: passion (points) and a well-covered player pair at $gamets. */
    private function feelings(string $npc, float $passion, int $gamets): void
    {
        $this->editDynamics($npc, function (array &$d) use ($passion, $gamets): void {
            RelationshipDynamics::setPassion($d, $passion);
            $s = RelDynFulfillment::pairState($d);
            $s['lv'] = array_map(fn() => 2.25, (array) $s['lv']);   // three quarters of target_units on every need
            $s['gamets'] = $gamets;
            RelDynFulfillment::setPairState($d, RelDynFulfillment::PLAYER, $s);
        });
    }

    /** Core's relationships entry $target of $npc, as core's own eval would write it. */
    private function setCoreBond(string $npc, string $target, array $entry): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][$target] = array_merge($ext['relationships'][$target] ?? [], $entry);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
    }

    /** Core's relationships map of $npc. */
    private function rels(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['extended_data'], true)['relationships'] ?? [];
    }

    private function npcId(string $npc): int
    {
        return (int) pg_fetch_result(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', [$npc]), 0, 0);
    }

    /** The suitor's line to $npc, then her rechat turn through the real hooks: the ledger meets his move. */
    private function flirt(string $npc, string $line, int $gamets): void
    {
        $this->event('chat', self::SUITOR . ": {$line} (talking to {$npc})", $gamets, $this->home(), 'emitted');
        $request = ['rechat', (string) $this->realTs, (string) ($gamets + 20), json_encode(['speaker' => self::SUITOR, 'listener_hint' => $npc])];
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::SUITOR . ')', $gamets + 20, $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::SUITOR;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook !== 'prerequest.php') $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = self::SUITOR;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    // ------------------------------------------------------------------ a temp engine tree (RelDyn present / absent)

    /**
     * A copy of the engine root made of symlinks, so core's own requires resolve to the same files: the connector
     * folder gets the diary stub connector (CHIM loads connector/<driver>.php from ENGINE_PATH), and ext/ carries
     * every extension but RelDyn when $relDyn is false: core as it is on a server that never installed RelDyn.
     */
    private function engine(bool $relDyn): string
    {
        $real = rtrim((string) $this->savedGlobals['ENGINE_PATH'][0], '/');
        $dir = sys_get_temp_dir() . '/rdforkhooks_engine_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->engines[] = $dir;
        if (!@mkdir($dir . '/connector', 0777, true) || !@mkdir($dir . '/ext', 0777, true)) $this->markTestSkipped('cannot make a temp engine tree');
        foreach (scandir($real) as $entry) {
            if (in_array($entry, ['.', '..', 'connector', 'ext', '.git'], true)) continue;
            if (!@symlink($real . '/' . $entry, $dir . '/' . $entry)) $this->markTestSkipped('symlinks are not available');
        }
        foreach (scandir($real . '/connector') as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            @symlink($real . '/connector/' . $entry, $dir . '/connector/' . $entry);
        }
        foreach (scandir($real . '/ext') as $entry) {
            if (in_array($entry, ['.', '..'], true) || (!$relDyn && $entry === 'relationship_dynamics')) continue;
            @symlink($real . '/ext/' . $entry, $dir . '/ext/' . $entry);
        }
        file_put_contents($dir . '/connector/rdforkstub.php', <<<'PHP'
<?php
/** The diary connector at the boundary: records every prompt it is handed and writes a plain page. */
if (!class_exists('rdforkstub', false)) {
    class rdforkstub
    {
        public static array $requests = [];
        public function fast_request($messages, $params, $context)
        {
            self::$requests[] = ['context' => $context, 'messages' => $messages];
            return 'Another day gone. The road was long and the company was good.';
        }
    }
}
PHP);
        return $dir . '/';
    }

    /** Core as installed without RelDyn: its engine tree has no RelDyn, and nothing is registered. */
    private function withoutRelDyn(): void
    {
        $GLOBALS['ENGINE_PATH'] = $this->engine(false);
        unset($GLOBALS['CHIM_RELATIONSHIP_AFFINITY_OWNERS'], $GLOBALS['CHIM_RELATIONSHIP_DELTA_DAMPERS'], $GLOBALS['CHIM_DIARY_CONTEXT_PROVIDERS']);
    }

    private function removeTree(string $dir): void
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) @unlink($path); else $this->removeTree($path);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ core's worker, as the relationship daemon runs it

    /**
     * One NPC-to-NPC eval job the way core's postrequest queues it (_relQueueEvaluation): $npc said a line to
     * $listener; the canned relationship LLM judges it. Core's worker entry point, then the NPC's relationship map.
     */
    private function npcToNpcEval(string $npc, string $listener, string $reply, bool $workerWithoutGameClock = false): array
    {
        $id = $this->npcId($npc);
        pg_query_params($this->db->link, 'INSERT INTO relationship_eval_queue (npc_id, eval_data) VALUES ($1, $2::jsonb)', [$id, json_encode([
            'npc_id' => $id, 'npc_name' => $npc,
            'dialogue' => "{$listener}, you stood well today.",
            'context' => [], 'is_npc2npc' => true,
            'listener_npc_id' => $this->npcId($listener), 'listener_name' => $listener, 'has_player_action' => false,
        ])]);
        $this->driver->replies = [$reply];
        // Core's relationship worker runs without a game request (worker.php bootstrap)
        unset($GLOBALS['gameRequest']);
        $result = _relProcessQueue(10, $this->llm);
        $this->assertSame([], $result['errors'], 'core worker errors');
        $this->assertSame([], $this->db->failures, 'failed SQL statements');
        $this->assertContains('relationship_npc_to_npc', $this->driver->calls);
        return $this->rels($npc);
    }

    /** The reply of core's NPC-to-NPC eval: $speaker's own change toward the listener, and the listener's toward her. */
    private static function eval(int $speaker, int $listener, ?string $type = null): string
    {
        return json_encode([
            'speaker' => ['delta' => $speaker, 'reason' => 'enjoyed his company'] + ($type !== null ? ['type' => $type] : []),
            'listener' => ['delta' => $listener, 'reason' => 'flattered by her attention'],
        ]);
    }

    /** What the pull toward the player is for $npc as the damper reads it (core's Player title romantic, affinity 60). */
    private function expectedPull(string $npc, bool $titled = true): float
    {
        $d = RelationshipDynamics::getDynamics($npc);
        RelationshipDynamics::setCoreRelationshipType($d, $titled ? 'romantic' : 'platonic');
        RelationshipDynamics::refreshAffinityMirror($d, 60.0);
        return RelDynExclusivity::pull($d, (float) $this->lastGamets())['pull'];
    }

    /** The hook's own lines of this test's error log (the damping it logged, and any error). */
    private function logTail(): string
    {
        $lines = preg_split('/\R/', (string) file_get_contents($this->errorLog));
        return implode(' | ', array_slice(array_filter($lines, fn($l) => str_contains($l, 'core gain') || str_contains($l, 'ERROR')), -6));
    }

    private function lastGamets(): int
    {
        return (int) pg_fetch_result(pg_query($this->db->link, 'SELECT max(gamets) FROM eventlog'), 0, 0);
    }

    /** $npc's feelings for the player settled the same way in all four beds (passion $passion, a title when $titled). */
    private function committed(float $passion = 45.0, bool $titled = true): int
    {
        $this->meet();
        $t = self::at(self::N0, 14.0);
        foreach (array_keys(self::BEDS) as $npc) {
            $this->feelings($npc, $passion, $t);
            if ($titled) $this->setCoreBond($npc, 'Player', ['type' => 'romantic']);
            // core's NPC-to-NPC eval already holds a crush of hers on the suitor
            $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
        }
        $this->event('chat', 'The Narrator: A quiet afternoon at the Mare.', $t + 600, $this->home(), 'emitted');
        return $t;
    }

    // ------------------------------------------------------------------ 1. exclusivity damping of core's NPC-NPC romance

    /**
     * The same gain core's NPC-NPC eval awards each bed toward the suitor she already holds a crush on (+10
     * for "enjoyed his company"), all four the player's partner with the same feelings: each is cut by how
     * held she is (the pull, who she is: Muiri's volatility loosens it most, so she lets the most through),
     * never to nothing, and exactly as the ledger's damping factor reads it. Core's side of the same eval is
     * core's: the suitor, whom RelDyn never held, gains in full from her attention. Without RelDyn the full +10.
     */
    public function testCoresRomanticGainsAreDampedByHowHeldEachBedIs(): void
    {
        $this->committed();
        $cfg = RelDynExclusivity::configDefaults();
        $gain = $pull = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $pull[$npc] = $this->expectedPull($npc);
            $this->assertGreaterThan(0.25, $pull[$npc], "{$npc}: held by the player");
            $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
            $gain[$npc] = $rels[self::SUITOR]['aff'] - 20;
            $expected = (int) round(10 * (1.0 - $cfg['damping'] * $pull[$npc]));
            $this->assertSame(max(1, $expected), $gain[$npc], "{$npc}: gain at pull {$pull[$npc]} " . $this->logTail());
            $this->assertGreaterThanOrEqual(1, $gain[$npc], "{$npc}: never to nothing");
            $this->assertLessThan(10, $gain[$npc], "{$npc}: damped");
            $this->assertSame('crush', $rels[self::SUITOR]['type'], "{$npc}: type untouched");
            $this->assertSame('enjoyed his company', $rels[self::SUITOR]['note'], "{$npc}: the note is core's");
            $this->assertSame(6, $this->rels(self::SUITOR)[$npc]['aff'], "{$npc}: the suitor's own gain (from nothing) is core's, in full");
            $this->assertSame(60, $rels['Player']['aff'], "{$npc}: the player's number is not touched");
            pg_query_params($this->db->link, 'DELETE FROM core_npc_master_history WHERE npc_id = $1', [$this->npcId($npc)]);
        }
        // Who she is: the more she is held, the less gets through; Muiri holds loosest of the four
        $order = array_keys($pull);
        usort($order, fn($a, $b) => $pull[$b] <=> $pull[$a]);
        for ($i = 1; $i < count($order); $i++) {
            $this->assertLessThanOrEqual($gain[$order[$i]], $gain[$order[$i - 1]], 'gain ' . json_encode($gain) . ' vs pull ' . json_encode($pull));
        }
        foreach (['Aela the Huntress', 'Ashe', 'Lynly Star-Sung'] as $npc) {
            $this->assertLessThan($pull[$npc], $pull['Muiri'], "Muiri holds less than {$npc}: " . json_encode($pull));
            $this->assertLessThanOrEqual($gain['Muiri'], $gain[$npc], "{$npc} lets no more through than Muiri: " . json_encode($gain));
        }
        $this->assertGreaterThan(min($gain), max($gain), 'meaningful divergence between who they are: ' . json_encode($gain));
        $this->assertStringContainsString('[EXCL] core gain Aela the Huntress -> Mikael: +10 damped to +' . $gain['Aela the Huntress'],
            (string) file_get_contents($this->errorLog));
        $this->assertSame(0, $this->llmCalls);
    }

    /**
     * RelDyn absent (an engine tree with no RelDyn, nothing registered): core applies its delta whole, to the byte:
     * the same eval, the same rows, as before the hook existed.
     */
    public function testWithoutRelDynCoreAppliesItsRomanticGainWhole(): void
    {
        $this->committed();
        $this->withoutRelDyn();
        $this->assertSame(10, chimRelationshipDeltaFor($this->npcId('Aela the Huntress'), self::SUITOR, 10, 'romantic'), 'inert');
        foreach (array_keys(self::BEDS) as $npc) {
            $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
            $this->assertSame(30, $rels[self::SUITOR]['aff'], "{$npc}: core's +10 whole");
            $this->assertEquals(['aff' => 30, 'type' => 'crush', 'note' => 'enjoyed his company', 'best' => 'enjoyed his company', 'best_delta' => 10],
                $rels[self::SUITOR], "{$npc}: the whole entry as core writes it");
            $this->assertSame(60, $rels['Player']['aff'], "{$npc}: the player's entry is as it was");
            $this->assertSame(['aff' => 20, 'type' => 'familial'], $rels[self::BROTHER], "{$npc}: other targets untouched");
        }
        $this->assertSame([], $this->db->failures);
    }

    /** Only romantic gains of a held NPC are touched: a brother's, a loss, and a first, unheld NPC's are core's. */
    public function testOnlyRomanticGainsOfAHeldNpcAreDamped(): void
    {
        $this->committed();
        $npc = 'Aela the Huntress';
        $before = $this->rels($npc);
        // A familial bond, however held she is
        $rels = $this->npcToNpcEval($npc, self::BROTHER, self::eval(10, 4));
        $this->assertSame(30, $rels[self::BROTHER]['aff'], 'a brother is no romance: core whole');
        // A loss in the romantic bond is core's too (RelDyn damps gains toward a rival, never grief)
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(-8, -2));
        $this->assertSame(12, $rels[self::SUITOR]['aff'], 'a loss is core whole');
        // The same gain in a neutral bond nothing makes romantic: core whole
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'neutral', 'aff' => 20]);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertSame(30, $rels[self::SUITOR]['aff'], 'no romance, no damping');
        // His standing romantic type toward her makes it romantic, though hers is neutral
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'neutral', 'aff' => 20]);
        $this->setCoreBond(self::SUITOR, $npc, ['type' => 'romantic', 'aff' => 26]);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertLessThan(30, $rels[self::SUITOR]['aff'], 'his romantic type toward her: damped');
        $this->assertGreaterThan(20, $rels[self::SUITOR]['aff']);
        $this->assertNotSame($before, $rels);
    }

    /**
     * A romantic move of his she has lately met (the ledger of §17, built by the real hooks) makes the gain romantic
     * though no type is held yet; the type the eval itself sets does too. A move long past does not.
     */
    public function testAMoveSheMetOrTheTypeTheEvalSetsMakesTheGainRomantic(): void
    {
        $this->committed();
        $npc = 'Aela the Huntress';
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'neutral', 'aff' => 20]);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertSame(30, $rels[self::SUITOR]['aff'], 'nothing romantic between them yet');

        // A romance the eval itself proposes (core's earned-romance gate still decides the type: below fond it stays)
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'neutral', 'aff' => 20]);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6, 'crush'));
        $this->assertLessThan(30, $rels[self::SUITOR]['aff'], 'the eval calls it a crush: a romantic gain, damped');
        $this->assertSame('neutral', $rels[self::SUITOR]['type'], "core's earned-romance gate is core's");

        // He made a move she met: the real hooks keep it in her ledger
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'neutral', 'aff' => 20]);
        $t = self::at(self::N0, 15.0);
        $this->flirt($npc, 'You look lovely today. Share a drink with me?', $t);
        $entry = RelDynExclusivity::suitor($this->dynamics($npc), self::SUITOR);
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('last_move_gamets', $entry);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertLessThan(30, $rels[self::SUITOR]['aff'], 'a move she met: the gain is romantic');

        // Days later, the move is long past
        $this->event('chat', 'The Narrator: Days pass.', self::at(self::N0 + 3, 15.0), $this->home(), 'emitted');
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'neutral', 'aff' => 20]);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertSame(30, $rels[self::SUITOR]['aff'], 'a move long past is no romance now');
    }

    /**
     * It is dynamic, scaled by who she is, never a gate: no romantic spark for the player (passion nothing)
     * means no pull and core's gain whole; a title with no spark holds nothing; a spark without a title
     * already damps ("I like the player enough not to entertain other options"); and however held she is, a
     * gain keeps at least a point.
     */
    public function testNoSparkNoPullButASparkWithoutATitleDampsAndNoOneIsImmune(): void
    {
        $this->committed(0.0, true);
        $npc = 'Aela the Huntress';
        $this->assertSame(0.0, $this->expectedPull($npc), 'a title creates nothing');
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertSame(30, $rels[self::SUITOR]['aff'], 'no pull: core whole');

        $t = self::at(self::N0, 14.0);
        $this->feelings($npc, 45.0, $t);
        $this->setCoreBond($npc, 'Player', ['type' => 'platonic']);
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
        $untitled = $this->expectedPull($npc, false);
        $this->assertGreaterThan(0.2, $untitled, 'a natural exclusivity, no title');
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $this->assertLessThan(30, $rels[self::SUITOR]['aff'], 'untitled: damped by the pull alone');

        // The strongest hold there is: even so, a small gain keeps its point and a large one a share
        $this->feelings($npc, 100.0, $t);
        $this->setCoreBond($npc, 'Player', ['type' => 'romantic']);
        foreach ([1, 2, 3, 4] as $small) {
            $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
            $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval($small, 0));
            $this->assertGreaterThanOrEqual(21, $rels[self::SUITOR]['aff'], "+{$small} keeps at least a point: no one is immune");
            $this->assertLessThanOrEqual(20 + $small, $rels[self::SUITOR]['aff']);
        }
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(20, 0));
        $this->assertGreaterThan(21, $rels[self::SUITOR]['aff'], '+20 keeps a share, not only the floor');
        $this->assertLessThan(40, $rels[self::SUITOR]['aff']);
    }

    /** Each switch hands the number back to core: RelDyn off, exclusivity off, the damping off, the hook's own table. */
    public function testEverySwitchHandsTheGainBackToCore(): void
    {
        $this->committed();
        $npc = 'Aela the Huntress';
        $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
        $damped = $rels[self::SUITOR]['aff'];
        $this->assertLessThan(30, $damped, 'on: damped ' . $this->logTail());
        foreach ([
            'RelDyn off' => ['enabled' => false],
            'exclusivity off' => ['exclusivity' => ['enabled' => false]],
            'core damping off' => ['exclusivity' => ['core_damping' => false]],
        ] as $label => $over) {
            $this->storeRelDynConfig($over);
            $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
            $rels = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6));
            $this->assertSame(30, $rels[self::SUITOR]['aff'], "{$label}: core whole");
        }
        $this->storeRelDynConfig([]);
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
        $this->assertSame($damped, $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6))[self::SUITOR]['aff'], 'back on: damped again');
    }

    /** An NPC RelDyn has never held (no stored state) is core's, and nothing is written for her. */
    public function testAnNpcRelDynNeverHeldIsCores(): void
    {
        $this->meet();
        $this->setCoreBond(self::SUITOR, self::BROTHER, ['type' => 'romantic', 'aff' => 20]);
        $this->assertNull(RelDynStorage::loadDynamics($this->npcId(self::SUITOR)));
        $rels = $this->npcToNpcEval(self::SUITOR, self::BROTHER, self::eval(10, 6));
        $this->assertSame(30, $rels[self::BROTHER]['aff']);
        $this->assertNull(RelDynStorage::loadDynamics($this->npcId(self::SUITOR)), 'reading never creates her state');
    }

    /** The #REL tag path (core's REL LLM off) asks the same hook: the same damping, the same floor, the same rest. */
    public function testTheRelTagPathIsDampedTheSame(): void
    {
        $this->committed();
        $GLOBALS['RELLLM_CONNECTOR'] = 0;
        $this->storeRelDynConfig(['eval_producer' => ['connector_id' => 7]]);
        $npc = 'Aela the Huntress';
        unset($GLOBALS['gameRequest']);
        $viaWorker = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6))[self::SUITOR]['aff'];
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
        $clean = RelationshipManager::parseChanges('Well fought. #REL:Mikael=+10# #REL:Farkas=+10# #REL:Player=+10#', $npc);
        $this->assertSame('Well fought.   ', $clean);
        $rels = $this->rels($npc);
        $this->assertSame($viaWorker, $rels[self::SUITOR]['aff'], 'the tag and the eval agree');
        $this->assertLessThan(30, $rels[self::SUITOR]['aff']);
        $this->assertSame(30, $rels[self::BROTHER]['aff'], 'a brother whole');
        $this->assertSame(60, $rels['Player']['aff'], "the player's number is RelDyn's: not applied");
        $this->assertStringContainsString('aff owned by extension', (string) file_get_contents($this->errorLog));

        // And without RelDyn the tag is whole
        $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
        $this->withoutRelDyn();
        RelationshipManager::parseChanges('Well fought. #REL:Mikael=+10#', $npc);
        $this->assertSame(30, $this->rels($npc)[self::SUITOR]['aff']);
        $this->assertSame([], $this->db->failures);
    }

    /**
     * Core's relationship worker loads no RelDyn file and has no game request: the damper still finds RelDyn through
     * the hook file, reads the game clock from the eventlog without core's helper, and damps the same.
     */
    public function testTheDamperWorksInAProcessLoadedLikeCoresRelationshipWorker(): void
    {
        $this->committed();
        $npc = 'Aela the Huntress';
        $inProcess = $this->npcToNpcEval($npc, self::SUITOR, self::eval(10, 6))[self::SUITOR]['aff'];
        $this->assertLessThan(30, $inProcess);

        $base = tempnam(sys_get_temp_dir(), 'rdforkhookschild');
        $child = $base . '.php';
        file_put_contents($child, <<<'PHP'
<?php
[, $enginePath, $dsn, $schema, $npcId] = $argv;
$GLOBALS['ENGINE_PATH'] = $enginePath;
require_once $enginePath . 'lib/logger.php';
require_once $enginePath . 'ext/relationship_system/async_queue.php';        // worker.php
require_once $enginePath . 'ext/relationship_system/relationship_llm.php';   // _relProcessQueue()
final class ChildDb {
    public $link;
    public function __construct($dsn, $schema) { $this->link = pg_connect($dsn); pg_query($this->link, "SET search_path TO {$schema}"); }
    public function fetchOne($q, array $params = []) { $r = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q); if (!$r) throw new RuntimeException(pg_last_error($this->link)); return pg_fetch_assoc($r) ?: []; }
    public function fetchAll($q, $log = false) { $r = pg_query($this->link, $q); if (!$r) throw new RuntimeException(pg_last_error($this->link)); $o = []; while ($row = pg_fetch_assoc($r)) $o[] = $row; return $o; }
    public function escape($s) { return pg_escape_string($this->link, (string) $s); }
    public function execQuery($q) { return pg_query($this->link, $q); }
}
$GLOBALS['db'] = new ChildDb($dsn, $schema);
$GLOBALS['PLAYER_NAME'] = 'Kaida';
$GLOBALS['RELLLM_CONNECTOR'] = 5;
echo json_encode(['reldyn_loaded_before' => class_exists('RelDynExclusivity', false),
    'clock_helper' => function_exists('DataLastKnownGameTS'),
    'damped' => chimRelationshipDeltaFor((int) $npcId, 'Mikael', 10),
    'brother' => chimRelationshipDeltaFor((int) $npcId, 'Farkas', 10),
    'loss' => chimRelationshipDeltaFor((int) $npcId, 'Mikael', -10)]);
PHP);
        try {
            $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'display_errors=stderr', $child,
                $GLOBALS['ENGINE_PATH'], $this->dsn, $this->schema, (string) $this->npcId($npc)])) . ' 2>&1';
            // the stored bond: a crush, so the gain is romantic
            $this->setCoreBond($npc, self::SUITOR, ['type' => 'crush', 'aff' => 20]);
            exec($cmd, $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $got = json_decode(end($out), true);
            $this->assertFalse($got['reldyn_loaded_before'], 'a worker loads no RelDyn file of its own');
            $this->assertFalse($got['clock_helper'], "and not core's game-clock helper");
            $this->assertSame($inProcess - 20, $got['damped'], 'damped the same');
            $this->assertSame(10, $got['brother']);
            $this->assertSame(-10, $got['loss']);
        } finally {
            @unlink($child);
            @unlink($base);
        }
    }

    // ------------------------------------------------------------------ 2. marked moments and maturity depth into core's diary prompt

    /** The prompt core's generateFollowerDiary handed the diary connector for $npc, and the entry it stored. */
    private function coreDiary(string $npc, string $eventType = 'goodnight'): array
    {
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/chat_helper_functions.php';
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/data_functions.php';
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/tts_connector.class.php';
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/llm_connector.class.php';
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/dynamic_update_util.php';
        $this->assertTrue(function_exists('generateFollowerDiary'));
        pg_query($this->db->link, "INSERT INTO core_llm_connector (id, label, driver, model, max_tokens) VALUES (9, 'diary stub', 'rdforkstub', 'stub', 300)
            ON CONFLICT (id) DO NOTHING");
        pg_query($this->db->link, "INSERT INTO core_profiles (id, label, diary_connector_id, metadata) VALUES (1, 'default', 9, '{}'::jsonb) ON CONFLICT (id) DO NOTHING");
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET profile_id = 1 WHERE npc_name = $1', [$npc]);
        $GLOBALS['DIARY_PROMPT'] = self::DIARY_PROMPT;
        $GLOBALS['PROMPT_HEAD'] = 'You are a person of Skyrim.';
        $GLOBALS['COMMAND_PROMPT'] = 'Stay in character.';
        $GLOBALS['CONTEXT_HISTORY'] = 5;
        $GLOBALS['HERIKA_NAME'] = $npc;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $at = self::at(self::N0, 23.0);
        $this->event('chat', self::PLAYER . ": We should rest. (talking to {$npc})", $at, $this->home(), 'emitted');
        if (class_exists('rdforkstub', false)) rdforkstub::$requests = [];
        ob_start(fn() => '');   // core echoes its "Diary Entry Written" notification to the game plugin
        try {
            $ok = generateFollowerDiary($npc, ['diary', (string) $this->realTs, (string) $at, 'Auto diary'], $eventType);
        } finally {
            ob_end_clean();
        }
        $this->assertTrue($ok, "core wrote {$npc}'s page");
        $this->assertTrue(class_exists('rdforkstub', false), 'core loaded the diary connector from its engine tree');
        $this->assertCount(1, rdforkstub::$requests);
        $messages = rdforkstub::$requests[0]['messages'];
        $this->assertSame('diary', rdforkstub::$requests[0]['context']);
        $last = end($messages);
        $this->assertSame('user', $last['role']);
        return ['prompt' => (string) $last['content'], 'messages' => $messages];
    }

    /** Core's own diary instruction for $npc, as generateFollowerDiary composes it with nothing added. */
    private static function coreDiaryPrompt(string $npc): string
    {
        return strtr(self::DIARY_PROMPT, ['#HERIKA_NAME#' => $npc, '#PLAYER_NAME#' => self::PLAYER]);
    }

    /** Mark moments the way the diary trigger keeps them (RelDynDiary::keepMoments), as the editor would. */
    private function markMoments(string $npc, array $triggerKinds, ?float $maturity = null): void
    {
        $this->editDynamics($npc, function (array &$d) use ($triggerKinds, $maturity): void {
            if ($maturity !== null) {
                $d['dimensions']['maturity']['x'] = $maturity;
                $d['dimensions']['maturity']['baseline'] = $maturity;
            }
            $d['_diary_moments'] = [['gamets' => self::at(self::N0, 20.0), 'triggers' => $triggerKinds]];
        });
    }

    /**
     * Four women, four diary prompts. Core writes each page through its own generateFollowerDiary (the connector a
     * stub at the boundary): its diary instruction stands as it was, then RelDyn's guidance: her depth by her own
     * maturity (Aela and Ashe by their reads, Muiri's 30 a pattern-noticer, Lynly's 15 a flat recounting) and the
     * moments marked since she last wrote, as she would feel them. Feelings, never numbers.
     */
    public function testCoresDiaryPromptCarriesEachBedsMomentsAndDepth(): void
    {
        $this->meet();
        $this->markMoments('Aela the Huntress', ['defining_moment:vow', 'boundary:hands']);
        $this->markMoments('Ashe', ['bond_changed:title']);
        $this->markMoments('Muiri', ['conflict_opened:quarrel', 'drunk_night:ale'], 30.0);
        $this->markMoments('Lynly Star-Sung', ['tier_changed:warmer'], 15.0);
        $cfg = RelDynDiary::config();
        $GLOBALS['ENGINE_PATH'] = $this->engine(true);
        $depths = $added = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $depth = RelDynDiary::depth(RelDynDiary::ownMaturity($d), $cfg);
            $depths[$npc] = $depth;
            $page = $this->coreDiary($npc)['prompt'];
            $core = self::coreDiaryPrompt($npc);
            $this->assertStringStartsWith($core . "\n\n", $page, "{$npc}: core's instruction stands as it was");
            $added[$npc] = substr($page, strlen($core) + 2);
            $this->assertStringStartsWith($cfg['prompt']['lead'], $added[$npc]);
            $this->assertStringContainsString(strtr($cfg['prompt'][$depth], ['{NAME}' => $npc]), $added[$npc], "{$npc}: her depth ({$depth})");
            foreach (RelDynDiary::momentPhrases($d['_diary_moments'], self::PLAYER) as $phrase) {
                $this->assertStringContainsString($phrase, $added[$npc], "{$npc}: the moment as she feels it");
            }
            $this->assertDoesNotMatchRegularExpression('/\d/', $added[$npc], "{$npc}: feelings, never numbers");
            $this->assertStringNotContainsString('_diary', $added[$npc]);
            // Read only: her moments wait for her next prerequest to read the page
            $this->assertSame($d['_diary_moments'], $this->dynamics($npc)['_diary_moments'], "{$npc}: moments untouched");
            $this->assertSame(self::PAGE, (string) pg_fetch_result(pg_query_params($this->db->link,
                'SELECT content FROM diarylog WHERE people = $1', [$npc]), 0, 0), "{$npc}: core stored the page it always does");
        }
        // Who she is: different depths and different moments
        $this->assertSame('examination', $depths['Aela the Huntress'], json_encode($depths));
        $this->assertSame('pattern', $depths['Muiri']);
        $this->assertSame('shallow', $depths['Lynly Star-Sung']);
        $this->assertGreaterThan(1, count(array_unique($depths)), json_encode($depths));
        $this->assertCount(4, array_unique($added), 'four different diaries are asked for');
        $this->assertStringContainsString('something with Kaida that mattered a great deal', $added['Aela the Huntress']);
        $this->assertStringContainsString('a quarrel with Kaida that is not settled', $added['Muiri']);
        $this->assertStringNotContainsString('quarrel', $added['Aela the Huntress']);
        $this->assertSame(0, $this->llmCalls);
    }

    /** Without RelDyn core's diary prompt is exactly core's, byte for byte, for every bed. */
    public function testWithoutRelDynCoresDiaryPromptIsUntouched(): void
    {
        $this->meet();
        $this->markMoments('Aela the Huntress', ['defining_moment:vow']);
        $this->withoutRelDyn();
        $this->assertSame('', chimDiaryContextFor('Aela the Huntress'), 'inert');
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertSame(self::coreDiaryPrompt($npc), $this->coreDiary($npc)['prompt'], "{$npc}: core's prompt, byte for byte");
        }
    }

    /** Null (core's prompt as it was) for the Narrator, a never-held NPC, and each switch; and it stands for a depth-only entry. */
    public function testTheDiaryContextIsInertWhereRelDynHasNothingToSay(): void
    {
        $this->meet();
        $this->assertNull(RelDynDiary::promptContext('The Narrator'));
        $this->assertNull(RelDynDiary::promptContext(''));
        $this->assertNull(RelDynDiary::promptContext(self::SUITOR), 'never held');
        $this->assertNull(RelDynStorage::loadDynamics($this->npcId(self::SUITOR)), 'and not created by asking');
        $this->assertSame('', chimDiaryContextFor('The Narrator'));
        $this->assertSame('', chimDiaryContextFor(self::SUITOR));

        // No moments marked: the depth still reaches her diary
        $text = chimDiaryContextFor('Aela the Huntress');
        $this->assertStringStartsWith("\n\n" . RelDynDiary::config()['prompt']['lead'], $text);
        $this->assertStringNotContainsString('What has stayed with', $text);

        foreach ([
            'RelDyn off' => ['enabled' => false],
            'autonomous diary off' => ['autonomous_diary_enabled' => false],
            'dimension engine off' => ['dimension_engine_enabled' => false],
            'prompt context off' => ['diary_reflection' => ['prompt' => ['enabled' => false]]],
        ] as $label => $over) {
            $this->storeRelDynConfig($over);
            $this->assertSame('', chimDiaryContextFor('Aela the Huntress'), $label);
        }
        $this->storeRelDynConfig([]);
        $this->assertNotSame('', chimDiaryContextFor('Aela the Huntress'));
    }

    /**
     * The three diary writers of core all ask the same hook, and no other place composes a diary instruction without it:
     * generateFollowerDiary (run above), generateNearbyDiary and the player's 'diary' request (processor/request.php).
     * Their connectors load from core's own folder (a real HTTP client), so these two are read at the source.
     */
    public function testEveryCoreDiaryInstructionAsksTheHook(): void
    {
        $root = dirname(__DIR__, 2);
        $sites = 0;
        foreach (['lib/dynamic_update_util.php', 'processor/request.php'] as $file) {
            $src = (string) file_get_contents("{$root}/{$file}");
            foreach (preg_split('/\R/', $src) as $i => $line) {
                if (preg_match('/\$diaryPrompt\s*=\s*(?:isset\(\$GLOBALS\["DIARY_PROMPT"\]\)|strtr\(\$GLOBALS\["DIARY_PROMPT"\])/', $line)) {
                    $sites++;
                    $window = implode("\n", array_slice(preg_split('/\R/', $src), $i, 14));
                    $this->assertMatchesRegularExpression('/\$diaryPrompt \.= chimDiaryContextFor\(/', $window, "{$file}:" . ($i + 1));
                    $this->assertStringContainsString('// RelDyn fork hook', $window, "{$file}:" . ($i + 1));
                }
            }
        }
        $this->assertSame(3, $sites, 'the three places that compose core\'s diary instruction');
    }
}
