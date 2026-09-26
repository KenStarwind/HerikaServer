<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynAbsencePgDb
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
 * Absence end to end with the four test beds (standing rule feedback_reldyn_testbeds): Aela the
 * Huntress, Ashe (Serene's hand-set vector, never read), Muiri and Lynly Star-Sung, each the
 * player's partner (core Player.type romantic, affinity 80), on CHIM 3.4.1 core-shaped rows and
 * the committed seed's reads, through the real hooks as main.php runs them (prerequest -> core's
 * action list with the ext functions.php -> context_pre -> context -> postrequest), the real
 * eval producer and worker (LLM stubbed at the connector boundary) and core's relationship
 * writes. No LLM call.
 *   bond-break-resentment  two game weeks away break each partner her own way (the calm §9
 *                          boundary, the blow-up, the walls going back up);
 *   affinity-rot           an unresolved fight bleeds each bond at its own pace past a week,
 *                          committed to core, until contact restarts the clock.
 * Feelings, never numbers, in front of the LLM; Jev gets the numbers.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAbsenceTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const SUITOR = 'Mikael';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the first evening
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const CORE_ACTIONS = ['MoveTo', 'OpenInventory', 'OpenInventory2', 'Attack', 'Follow', 'Inspect', 'TravelTo', 'FollowPlayer',
        'ComeCloser', 'ReturnBackHome', 'GiveGoldTo', 'GiveItemTo', 'MakeFollower', 'EndConversation'];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynAbsencePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** CACHE_PEOPLE for the next requests (home by default) */
    private ?string $people = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_abs_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // core's speech table (data/database_default.sql plus the live 3.4.1 mood / emotion columns):
        // main.php reads its last row's speaker for a continue
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
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
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynAbsencePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'ENABLED_FUNCTIONS',
                     'RECHAT_PREVIOUS_SPEAKER', 'RECHAT_REQUEST_PAYLOAD'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdabsbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_absence_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        $this->storeConfig([]);
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

    /** Shipped defaults (with $overrides), stored as the config page stores them. */
    private function storeConfig(array $overrides): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows (partners: Player romantic, affinity 80), Mikael, voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => ['aff' => 80, 'type' => 'romantic'], self::SUITOR => ['aff' => 10, 'type' => 'neutral']]])]);
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
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
            [self::SUITOR, 'male', 'ImperialRace', '', 'Roleplay as Mikael', '', json_encode(['skills' => $all]),
             json_encode(['class' => ['name' => 'Bard', 'formid' => '0x0001317f'], 'factions' => [], 'relationships' => []])]);
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

    /** A spoken line as core records it in its speech table (what a continue answers). */
    private function speech(string $speaker, string $listener, string $text, int $gamets): void
    {
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', $speaker, $text, 'Breezehome', $listener, $this->realTs, $gamets, $gamets]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /**
     * $npc's request through the real hooks as main.php runs them: the prerequest, core's action
     * list (functions/functions.php loads the enabled codes and requires every ext
     * functions.php), context_pre, context, postrequest. Core resolves a rechat / continue's
     * previous speaker only after the prerequest hooks ($previousSpeaker).
     */
    private function request(string $npc, array $request, string $listener, string $label, ?string $previousSpeaker = null): void
    {
        foreach (['prerequest.php', 'functions.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to {$listener})", (int) $request[2], $this->people ?? $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people ?? $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = $listener;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($previousSpeaker !== null && $hook !== 'prerequest.php') $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = $previousSpeaker;
            if ($hook === 'functions.php') $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_ACTIONS;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            if ($hook !== 'functions.php') RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** One player line to $npc at $gamets, logged as core logs it (input row, spoken line, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->people ?? $this->home());
        $this->speech(self::PLAYER, $npc, $line, $gamets);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], self::PLAYER, $label);
    }

    /** The same line to all four, 10 game minutes apart from $t, then the eval worker (stubbed LLM). Returns the next free gamets. */
    private function round(string $line, int $t, string $label): int
    {
        foreach (array_keys(self::BEDS) as $i => $npc) $this->turn($npc, $line, $t + 600 * $i, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
        return $t + 600 * count(self::BEDS);
    }

    /**
     * $minutes of play from $fromGamets: core's poll rows every five real seconds (the global
     * play heartbeat reads them), so the play clock's cooldowns can pass. Returns the gamets after.
     */
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

    /** Evening: everyone at home with the player. */
    private function hello(): void
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $this->round('Well met, love.', self::at(self::N0, 18.0), 'hello');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d['_core_rel_type'], "{$npc}: a partner");
        }
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

    private function coreType(string $npc): string
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode($r['extended_data'], true) ?: []);
        return (string) ($rel['type'] ?? '');
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
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

    // ------------------------------------------------------------------ helpers of this lane

    /** Core relationships.Player.aff of $npc as core holds it (-100..100). */
    private function coreAff(string $npc): float
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode($r['extended_data'], true) ?: []);
        return floatval($rel['aff'] ?? 0);
    }

    /** Mikael's own remark at $gamets: a request that is not the player pair's (the calendar moves for every bond). */
    private function elsewhere(int $gamets, string $label): void
    {
        $this->people = '|' . self::SUITOR . '|';
        $this->event('inputtext', self::PLAYER . ": Another round, Hulda. (Talking to " . self::SUITOR . ")", $gamets, $this->people);
        $this->request(self::SUITOR, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ': Another round, Hulda. (Talking to ' . self::SUITOR . ')'],
            self::PLAYER, $label);
        $this->people = null;
    }

    /**
     * The eval LLM at the connector boundary, by the player's line in THIS EXCHANGE:
     *   "useless" (a cutting insult): a flagged grievance, affinity -25, trust -5;
     *   "missed you" (warm company): quality time and reassurance;
     *   anything else: small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "\nTASK:", $from) - $from));
            $insult = str_contains($exchange, 'useless');
            $warm = str_contains($exchange, 'missed you');
            return json_encode([
                'signals' => ['affinity' => $insult ? -25 : ($warm ? 3 : 0), 'trust' => $insult ? -5 : ($warm ? 2 : 0),
                              'comfort' => $warm ? 3 : 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $insult ? ['insult'] : ($warm ? ['quality_time', 'reassurance'] : []),
                'grievance' => $insult ? ['flag' => true, 'kind' => 'insult', 'severity' => 2] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $insult ? 1.0 : ($warm ? 0.6 : 0.1),
                'summary' => $insult ? 'The player called her useless.' : ($warm ? 'The player said they missed her and stayed a while.' : 'Small talk.'),
                'romantic_intent' => 0,
                'charisma' => 'none',
            ]);
        };
    }

    // ------------------------------------------------------------------ the bond break

    /**
     * Bond-break resentment with the four partners (core romantic, affinity 80). The player
     * leaves without a word. Time moves for every bond (the calendar scan on the return: daily
     * neglect, the passion fade; the cold-romance rot does not run while he is away, the absence
     * path owns that time: batch-Q review); on the return each one's absence, past her own
     * neglect grace x break_after_grace_mult (intentional, to her), may carry her below a
     * partner's threshold, and each takes it as who she is.
     *   Two game weeks: the absence erodes Muiri (possessive enough, fearful) below it: she
     *     protests ("where WERE you?"); Aela (secure-leaning, low possessiveness), Ashe (mature,
     *     Resilient) and Lynly hold: the bond is intact (Lynly's absence decay alone leaves her just
     *     above the line). Ashe, mature, states her §9 boundary calmly instead (the absence left
     *     her needs unmet).
     *   Five more weeks: Aela breaks and the walls go back up (guarded, not in the anxious half
     *     even after the neglect's drift); Lynly breaks and it spills out; Ashe's probation ran out
     *     while the player was gone: a deliberate step-back from romance, no break, no rage, her
     *     resentment at her own ceiling; Muiri, already below it, does not break twice.
     * Said once, to the player's face, as feelings; Jev gets the numbers; core carries the affinity.
     */
    public function testLongAbsencesBreakEachPartnerHerOwnWay(): void
    {
        $this->hello();
        $beds = array_keys(self::BEDS);
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertNull($d[RelDynAbsence::BREAK_KEY] ?? null, "{$npc}: nothing broken yet");
            $this->assertNotNull(RelDynFulfillment::pairState($d), "{$npc}: a fulfillment pair (the §9 machine)");
        }

        // ---- two game weeks later the player walks back in and greets each of them
        $t = $this->play(self::at(self::N0 + 14, 18.0), 20.0);
        $t = $this->round('I am back.', $t, 'return1');
        $d = $res = [];
        foreach ($beds as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $res[$npc] = self::x($d[$npc], 'resentment');
        }
        $why = fn() => json_encode(array_map(fn($x) => ['aff' => RelationshipDynamics::getCoreAffinity($x), 'jev' => RelDynAbsence::jev($x)], $d));
        foreach ($beds as $npc) {
            $this->assertSame(0.0, floatval(RelDynAbsence::jev($d[$npc])['rot_applied']), "{$npc}: no rot while he was away " . $why());
        }
        foreach (['Muiri'] as $npc) {
            $b = $d[$npc][RelDynAbsence::BREAK_KEY] ?? null;
            $this->assertIsArray($b, "{$npc}: two weeks without a word broke the bond " . $why());
            $this->assertSame('bonded', $b['bond_type'], $npc);
            $this->assertGreaterThan(13.9, $b['absent_game_days'], $npc);
            $this->assertGreaterThanOrEqual(56.0, $b['affinity_before'], $npc);
            $this->assertLessThan(56.0, $b['affinity_after'], $npc);
            $this->assertLessThan(56.0, $this->coreAff($npc), "{$npc}: core carries the absence");
            $this->assertLessThan(0.0, $b['trust_delta'], "{$npc}: a trust hit");
            $this->assertLessThan(0.0, $b['comfort_delta'], "{$npc}: she coped alone");
            $this->assertSame('confront', $b['mode'], "{$npc}: it spills out " . $why());
            $lines = $this->felt[$npc]['return1'];
            $this->assertTrue(isset($lines['bond_break_confront']) || isset($lines['resentment_confront']),
                "{$npc}: said to the player's face (her line, or the confrontation that carries it) " . json_encode(array_keys($lines)));
        }
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) {
            $this->assertNull($d[$npc][RelDynAbsence::BREAK_KEY] ?? null, "{$npc}: two weeks do not break her " . $why());
            $this->assertGreaterThanOrEqual(56.0, $this->coreAff($npc), "{$npc}: still close");
        }
        $this->assertGreaterThan(max($res[self::AELA], $res['Ashe'], $res[self::LYNLY]), $res['Muiri'],
            'Muiri, codependent and fearful, takes the same absence hardest: ' . json_encode($res));
        $this->assertArrayHasKey('fulfillment_boundary', $this->felt['Ashe']['return1'], 'Ashe states her boundary calmly: '
            . json_encode(array_keys($this->felt['Ashe']['return1'])));
        $this->assertSame('probation', RelDynFulfillment::pairState($d['Ashe'])['boundary']['state']);

        // ---- the player leaves again, five game weeks this time
        $t = $this->play(self::at(self::N0 + 14 + 35, 18.0), 20.0);
        $t = $this->round('I am back. I am sorry it took so long.', $t, 'return2');
        $d2 = $modes = [];
        foreach ($beds as $npc) {
            $d2[$npc] = $this->dynamics($npc);
            $modes[$npc] = $d2[$npc][RelDynAbsence::BREAK_KEY]['mode'] ?? null;
        }
        $why2 = json_encode(['modes' => $modes, 'aff' => array_map(fn($npc) => $this->coreAff($npc), array_combine($beds, $beds))]);
        $b = $d2[self::AELA][RelDynAbsence::BREAK_KEY] ?? null;
        $this->assertIsArray($b, "Aela: five weeks broke the bond {$why2}");
        $this->assertGreaterThan(34.9, $b['absent_game_days']);
        $this->assertLessThan(0.0, $b['trust_delta'], 'Aela: a trust hit');
        $this->assertSame('withdraw', $modes[self::AELA], "Aela: guarded, not in the anxious half; the walls go back up {$why2}");
        $this->assertTrue(RelDynAbsence::withdraws($d2[self::AELA]));
        $this->assertArrayHasKey('bond_break_withdraw', $this->felt[self::AELA]['return2'], json_encode(array_keys($this->felt[self::AELA]['return2'])));
        $this->assertArrayNotHasKey('resentment_confront', $this->felt[self::AELA]['return2'], 'Aela: no scene');

        // Ashe: the probation ran out while the player was away; the deliberate step-back (§9)
        $this->assertNull($d2['Ashe'][RelDynAbsence::BREAK_KEY] ?? null, "Ashe: no break, a decision {$why2}");
        $this->assertSame('platonic', $this->coreType('Ashe'), 'Ashe stepped back from romance');
        $this->assertArrayHasKey('fulfillment_step_back', $this->felt['Ashe']['return2'], json_encode(array_keys($this->felt['Ashe']['return2'])));
        $this->assertArrayNotHasKey('resentment_confront', $this->felt['Ashe']['return2'], 'no rage');
        $this->assertLessThanOrEqual(RelationshipDynamics::getNeglectProfile($d2['Ashe'])['ceiling'] + 1e-6, self::x($d2['Ashe'], 'resentment'),
            'a floor on her anger: seven weeks alone take her no further than her own ceiling');
        $this->assertLessThan(self::x($d2['Muiri'], 'resentment') + 20.0, self::x($d2['Ashe'], 'resentment'));
        $this->assertSame(1, $d2['Muiri'][RelDynAbsence::BREAK_KEY]['count'], "Muiri: already below it, not broken twice");
        $lb = $d2[self::LYNLY][RelDynAbsence::BREAK_KEY] ?? null;
        $this->assertIsArray($lb, "Lynly: five weeks broke the bond {$why2}");
        $this->assertGreaterThan(34.9, $lb['absent_game_days']);
        $this->assertSame('confront', $modes[self::LYNLY], "Lynly: it spills out {$why2}");
        $this->assertTrue(isset($this->felt[self::LYNLY]['return2']['bond_break_confront']) || isset($this->felt[self::LYNLY]['return2']['resentment_confront']),
            "Lynly: said to the player's face " . json_encode(array_keys($this->felt[self::LYNLY]['return2'])));
        $reactions = ['Muiri' => $d['Muiri'][RelDynAbsence::BREAK_KEY]['mode'], self::LYNLY => $modes[self::LYNLY],
                      self::AELA => $modes[self::AELA], 'Ashe' => 'step_back'];
        $this->assertCount(3, array_unique($reactions), 'four partners, three reactions: ' . json_encode($reactions));

        // said once; Jev gets the numbers
        $t = $this->round('Let us sit by the fire.', $t, 'after');
        foreach ($beds as $npc) {
            foreach (array_keys($this->felt[$npc]['after']) as $key) $this->assertStringStartsNotWith('bond_break_', (string) $key, "{$npc}: said once");
            $jev = RelationshipDynamics::jevStateBlock($npc);
            $this->assertSame($this->dynamics($npc)[RelDynAbsence::BREAK_KEY]['mode'] ?? null, $jev['absence']['bond_break']['mode'] ?? null, "{$npc}: Jev gets the numbers");
            if ($npc !== 'Ashe') $this->assertStringContainsString('absence=break(' . $jev['absence']['bond_break']['mode'], $jev['text'], $npc);
            if ($npc !== 'Ashe') $this->assertNull($this->dynamics($npc)[RelDynAbsence::BREAK_KEY]['say'], "{$npc}: said once");
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ affinity rot

    /**
     * Affinity rot with the four partners: the player cuts each of them down (a flagged insult
     * the eval scores; the affinity drop opens a conflict) and then leaves it unresolved. The
     * calendar moves on Mikael's requests (not the player pair's). For a week nothing rots; past
     * it, each bleeds core affinity at her own pace (who she is: maturity, attachment), committed
     * to core, the number going past the floors the absence decay would hold. Contact heals: a
     * warm exchange with Ashe restarts her clock while the others keep bleeding; time alone
     * never gives anything back.
     */
    public function testAnUnresolvedFightRotsEachBondAtItsOwnPaceUntilContact(): void
    {
        $this->hello();
        $beds = array_keys(self::BEDS);
        $t = $this->play(self::at(self::N0 + 1, 9.0), 20.0);
        for ($i = 0; $i < 3; $i++) {
            $open = array_filter($beds, fn($npc) => !empty($this->dynamics($npc)['in_conflict']));
            if (count($open) === count($beds)) break;
            $t = $this->round('You are useless to me.', $t, "insult{$i}");
        }
        $at = [];
        foreach ($beds as $npc) {
            $dn = $this->dynamics($npc);
            $this->assertNotEmpty($dn['in_conflict'], "{$npc}: the fight is open");
            $this->assertGreaterThan(0, floatval($dn['_conflict_entered_gamets'] ?? 0), "{$npc}: its game-calendar onset");
            $at[$npc] = $this->coreAff($npc);
        }

        // a week goes by on Mikael's requests: inside the grace, nothing rots
        $day = self::N0 + 1;
        for ($k = 1; $k <= 6; $k++) $this->elsewhere(self::at($day + $k, 20.0), "day{$k}");
        foreach ($beds as $npc) {
            $this->assertEqualsWithDelta($at[$npc], $this->coreAff($npc), 1e-9, "{$npc}: a week of grace");
        }
        // then it bleeds, every day, for everyone
        for ($k = 7; $k <= 14; $k++) $this->elsewhere(self::at($day + $k, 20.0), "day{$k}");
        $lost = [];
        foreach ($beds as $npc) {
            $dn = $this->dynamics($npc);
            $lost[$npc] = $at[$npc] - $this->coreAff($npc);
            $this->assertGreaterThanOrEqual(3.0, $lost[$npc], "{$npc}: the rot reached core " . json_encode(RelDynAbsence::jev($dn)));
            $this->assertContains('conflict', RelDynAbsence::jev($dn)['rot_conditions'], $npc);
            $this->assertLessThan(0.0, RelDynAbsence::jev($dn)['rot_applied'], $npc);
        }
        $why = json_encode($lost);
        $this->assertGreaterThan(1.0, max($lost) - min($lost), "each at her own pace {$why}");
        $this->assertGreaterThan($lost['Ashe'], $lost['Muiri'], "the immature toxic test bed rots faster than the mature one {$why}");

        // contact heals: the player seeks Ashe out warmly; her clock restarts, the others keep bleeding
        $back = self::at($day + 15, 10.0);
        $this->turn('Ashe', 'I missed you. I was wrong to say that.', $back, 'warm');
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $this->assertGreaterThanOrEqual($back, floatval($this->dynamics('Ashe')['_last_positive_gamets'] ?? 0), 'the positive exchange restarts her clock');
        $mark = [];
        foreach ($beds as $npc) $mark[$npc] = $this->coreAff($npc);
        for ($k = 16; $k <= 19; $k++) $this->elsewhere(self::at($day + $k, 20.0), "late{$k}");
        $this->assertEqualsWithDelta($mark['Ashe'], $this->coreAff('Ashe'), 1e-9, 'Ashe: inside a fresh week of grace');
        foreach (['Muiri', self::AELA, self::LYNLY] as $npc) {
            $this->assertLessThan($mark[$npc], $this->coreAff($npc), "{$npc}: still unresolved, still bleeding");
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }
}
