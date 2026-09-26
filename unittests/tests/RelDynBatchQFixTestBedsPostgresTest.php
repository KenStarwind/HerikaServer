<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynBatchQFixPgDb
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
 * Batch-Q review fixes end to end with the four test beds (standing rule feedback_reldyn_testbeds):
 * Aela the Huntress, Ashe (Serene's hand-set vector, never read: nothing of her story anywhere),
 * Muiri (toxic, nudged fearful) and Lynly Star-Sung (the shy bard), on CHIM 3.4.1 core-shaped rows
 * and the committed seed's reads, through the real hooks as main.php runs them (prerequest ->
 * core's action list with the ext functions.php -> context_pre -> context -> postrequest), the real
 * eval producer and worker (LLM stubbed at the connector boundary). No LLM call; feelings, never
 * numbers, in front of the LLM; Jev gets the numbers.
 *   the fresh break   bond-break-resentment: a few days away break only the partners for whom the
 *                     absence already feels intentional (who she is, how long); a bond that broke
 *                     this absence is strained on the return (no social reach, no smile toward
 *                     him next to her own hurt), a bond that held reaches for him.
 *   her own line      rescue-bonus: core voicing her bleedout comment (Papyrus RecoverFromCombat's
 *                     'instruction') is not the player's answer to her fall; his caring word after
 *                     it still is, and the rescue is paid once (no rescue spike on top).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBatchQFixTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const SUITOR = 'Mikael';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const MINUTE = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;   // a minute of real play on the game clock
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
    private RelDynBatchQFixPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** npc => label => the <subtext> block context.php appended */
    private array $subtext = [];
    /** CACHE_PEOPLE for the next requests (home, everyone, by default) */
    private ?string $people = null;
    /** The mood core records for the NPC's reply (moods_issued) */
    private string $mood = 'default';

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_qfx_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // core's speech table (data/database_default.sql plus the live 3.4.1 mood / emotion columns)
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns)
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        // moods_issued of data/table_moods_issued.sql
        pg_query($admin, "CREATE TABLE moods_issued (sess character varying(1024), speaker text, mood text, listener text,
            localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY)");
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

        $this->db = new RelDynBatchQFixPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdqfxbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_qfx_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        // The weather's pull held still (no internal weather): the moment and the floor alone
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false]))]);
        RelationshipDynamics::clearConfigCache();
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

    /** Core rows (partners: Player romantic at core affinity $aff), Mikael, voice types, placeholder templates and the seed's reads. */
    private function seed(int $aff): void
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
                     'relationships' => [self::PLAYER => ['aff' => $aff, 'type' => 'romantic'], self::SUITOR => ['aff' => 10, 'type' => 'neutral']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;   // Serene's hand-set vector, never read
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
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $this->people ?? $this->home(), '', $state]);
    }

    /** A spoken line as core records it in its speech table. */
    private function speech(string $speaker, string $listener, string $text, int $gamets): void
    {
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', $speaker, $text, 'Breezehome', $listener, $this->realTs, $gamets, $gamets]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /** $npc's request through the real hooks as main.php runs them. */
    private function request(string $npc, array $request, string $listener, string $label): void
    {
        foreach (['prerequest.php', 'functions.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to {$listener})", (int) $request[2], 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, $this->mood, $listener, $this->realTs, (int) $request[2], (int) $request[2]]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people ?? $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = $listener;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'functions.php') $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_ACTIONS;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                $this->subtext[$npc][$label] = '';
                foreach ((array) $GLOBALS['contextDataFull'] as $m) {
                    if (str_starts_with((string) ($m['content'] ?? ''), '<subtext>')) $this->subtext[$npc][$label] = (string) $m['content'];
                }
            }
            if ($hook !== 'functions.php') RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['ENABLED_FUNCTIONS']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** One player line to $npc at $gamets, logged as core logs it (input row, spoken line, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        $this->speech(self::PLAYER, $npc, $line, $gamets);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], self::PLAYER, $label);
    }

    /** The same line to all four, 10 game minutes apart from $t, then the eval worker (stubbed LLM). Returns the next free gamets. */
    private function round(string $line, int $t, string $label): int
    {
        foreach (array_keys(self::BEDS) as $i => $npc) $this->turn($npc, $line, $t + 600 * $i, $label);
        $this->worker();
        return $t + 600 * count(self::BEDS);
    }

    /**
     * Core voicing $npc's own line addressed to the player, no player input: Papyrus
     * RecoverFromCombat's bleedout 'instruction' (main.php lets it reach the LLM when RPG_COMMENTS
     * includes bleedout), through the same hooks.
     */
    private function ownLine(string $npc, string $data, int $gamets, string $label): void
    {
        $this->event('instruction', $data, $gamets);
        $this->request($npc, ['instruction', (string) $this->realTs, (string) $gamets, $data], self::PLAYER, $label);
    }

    /** $line to each of the four alone with the player (no one else around), then the eval worker. */
    private function alone(string $line, int $t, string $label): int
    {
        foreach (array_keys(self::BEDS) as $i => $npc) {
            $this->people = "|{$npc}|" . self::PLAYER . '|';
            $this->turn($npc, $line, $t + 600 * $i, $label);
        }
        $this->people = null;
        $this->worker();
        return $t + 600 * count(self::BEDS);
    }

    private function worker(): void
    {
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    /** $minutes of play (core 'request' rows 5 real seconds apart): the play clock runs on it. */
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
     * The eval LLM at the connector boundary, by the player's line in THIS EXCHANGE:
     *   "take my hand" after her fall: care (the player protects her: rescue, reassurance);
     *   "hold you": an embrace (touch, a little passion);
     *   "missed you": a meaningful evening together (quality time);
     *   anything else: small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "\nTASK:", $from) - $from));
            $care = str_contains($exchange, 'take my hand');
            $hug = str_contains($exchange, 'hold you');
            $warm = str_contains($exchange, 'missed you');
            return json_encode([
                'signals' => ['affinity' => ($care || $warm) ? 2 : 0, 'trust' => ($care || $warm) ? 2 : 0, 'comfort' => ($care || $warm) ? 2 : 0,
                              'respect' => 0, 'passion' => $hug ? 2 : 0, 'maturity' => 0],
                'tags' => $care ? ['rescue', 'reassurance'] : ($hug ? ['touch'] : ($warm ? ['quality_time'] : [])),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => ($care || $warm) ? 0.6 : ($hug ? 0.3 : 0.1),
                'summary' => $care ? 'She went down in the fight; the player knelt by her and helped her up.'
                    : ($hug ? 'The player held her.' : ($warm ? 'The player said they missed her and stayed a while.' : 'Small talk.')),
                'romantic_intent' => 0,
                'charisma' => 'none',
            ]);
        };
    }

    /** Evening at home: everyone with the player. */
    private function hello(): int
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 17.9));
        $t = $this->round('Well met, love.', self::at(self::N0, 18.0), 'hello');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d['_core_rel_type'], "{$npc}: a partner");
        }
        return $t;
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

    /** Core's relationship to the player moves on (CHIM 3.4.1 extended_data.relationships). */
    private function setCore(string $npc, int $aff, string $type): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, $2::text[], $3::jsonb)
            WHERE npc_name = $1", [$npc, '{relationships,' . self::PLAYER . '}', json_encode(['aff' => $aff, 'type' => $type])]);
    }

    /** Every bed's earned passion toward the player set to $p (as the editor would), no moment on it. */
    private function floors(float $p): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d) use ($p): void {
                RelationshipDynamics::setPassion($d, $p);
                unset($d[RelDynPassion::SPIKE_KEY], $d[RelDynPassion::SPIKE_TRIGGER_KEY]);
            });
        }
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** The romantic level the last compose left in her short band (impulse points). */
    private static function urge(array $d, string $type = 'romantic'): float
    {
        return floatval($d[RelDynImpulse::KEY]['levels'][$type] ?? 0.0);
    }

    /** RELDYN_PROBE=1: print a scene's numbers (tuning aid, no effect on the test). */
    private function probe(string $label, array $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "\n=== {$label}\n" . json_encode($data, JSON_PRETTY_PRINT));
    }

    /** Every test ends the same way: feelings in front of the LLM, no LLM call, no failed query. */
    private function assertClean(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
        foreach ($this->subtext as $npc => $turns) {
            foreach ($turns as $label => $block) $this->assertDoesNotMatchRegularExpression('/\d/', $block, "{$npc} {$label} <subtext>");
        }
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertGreaterThan(0, $this->evalCalls);
        $this->assertSame([], $this->db->failures);
    }


    // ------------------------------------------------------------------ the fresh break

    /**
     * absence x impulse x attraction. Four partners at core affinity 60 (a few points above a
     * partner's threshold, 56) spend a meaningful evening with the player, who then stays away
     * about a game week. Whether that breaks the bond is who she is and how long he was gone:
     * the absence has to feel intentional to her (past her own neglect grace x
     * break_after_grace_mult), not merely carry the number across the line. The codependent,
     * fearful Muiri and the shy bard Lynly take a week as intentional; Aela (secure, independent)
     * and Ashe (mature) do not, though the week carried the number across the line for them too.
     * On the return:
     *   - a bond that broke this absence is strained: her hurt is said (the break's line), and
     *     next to it no social reach toward him and no attraction line;
     *   - a bond that held reaches for him (the loneliness timer ran all week).
     * One warm exchange after the break lifts the strain (her resentment stays hers).
     */
    public function testAFreshBreakStrainsTheReturnAndOnlyWhereTheAbsenceFeltIntentional(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->floors(30.0);
        $t = $this->play($t + 600, 10.0);
        $this->round('I missed you today. Tell me about your evening.', $t + 600, 'evening');
        $beds = array_keys(self::BEDS);
        $mult = floatval(RelDynAbsence::breakConfig()['break_after_grace_mult']);

        // ---- a game week later he walks back in
        $t = $this->play(self::at(self::N0 + 7, 22.0), 10.0);
        $back = $this->round('I missed you today. Tell me about your evening.', $t, 'return');
        $d = $broke = $info = $grace = [];
        foreach ($beds as $npc) {
            $d[$npc] = $this->dynamics($npc);
            // the neglect grace the absence ran under (game days; the band the evening left behind)
            $grace[$npc] = RelationshipDynamics::neglectGraceGameDays($d[$npc], true);
            $broke[$npc] = is_array($d[$npc][RelDynAbsence::BREAK_KEY] ?? null);
            $info[$npc] = ['grace' => round($grace[$npc], 3), 'aff' => RelationshipDynamics::getCoreAffinity($d[$npc]),
                'break' => $d[$npc][RelDynAbsence::BREAK_KEY] ?? null, 'conflict' => !empty($d[$npc]['in_conflict']),
                'resentment' => self::x($d[$npc], 'resentment'), 'impulse' => $d[$npc][RelDynImpulse::KEY] ?? null,
                'felt' => array_keys($this->felt[$npc]['return'] ?? [])];
        }
        $this->probe('fresh break', $info);
        $why = json_encode($info);
        $this->assertSame([self::AELA => false, 'Ashe' => false, 'Muiri' => true, self::LYNLY => true], $broke, $why);
        foreach (['Muiri', self::LYNLY] as $npc) {
            $b = $d[$npc][RelDynAbsence::BREAK_KEY];
            $this->assertGreaterThan($mult * $grace[$npc], $b['absent_game_days'], "{$npc}: intentional to her {$why}");
            $this->assertTrue(RelDynAbsence::strains($d[$npc]), "{$npc}: the fresh break strains the bond {$why}");
            $this->assertArrayHasKey('bond_break_' . $b['mode'], $this->felt[$npc]['return'], "{$npc}: her hurt is said {$why}");
            $this->assertArrayNotHasKey('attraction', $this->felt[$npc]['return'], "{$npc}: no pull shown next to it {$why}");
            $this->assertNotContains('social', (array) ($d[$npc][RelDynImpulse::KEY]['firing'] ?? []), "{$npc}: no reach toward him {$why}");
            $this->assertStringNotContainsString('a pull to talk with', $this->subtext[$npc]['return'], "{$npc} {$why}");
        }
        foreach ([self::AELA, 'Ashe'] as $npc) {
            $this->assertLessThan(56.0, $info[$npc]['aff'], "{$npc}: the week took the number across the line as well {$why}");
            $this->assertGreaterThan(7.0, $mult * $grace[$npc], "{$npc}: a week is not yet intentional to her {$why}");
            $this->assertFalse(RelDynAbsence::strains($d[$npc]), $npc);
            $this->assertContains('social', (array) ($d[$npc][RelDynImpulse::KEY]['firing'] ?? []), "{$npc}: the bond held; she missed him {$why}");
        }

        // One warm exchange after the break lifts the strain (her resentment stays hers); said once
        $t = $this->round('I am sorry I was gone. I missed you.', $back + 600, 'after');
        $this->round('Stay with me a while.', $t + 600, 'later');
        foreach (['Muiri', self::LYNLY] as $npc) {
            $later = $this->dynamics($npc);
            $this->assertFalse(RelDynAbsence::strains($later), "{$npc}: he has been warm to her since the break");
            $this->assertGreaterThan(0.0, self::x($later, 'resentment'), "{$npc}: her resentment stays hers");
            foreach (['after', 'later'] as $label) {
                foreach (array_keys($this->felt[$npc][$label]) as $key) $this->assertStringStartsNotWith('bond_break_', (string) $key, "{$npc}: said once");
            }
        }
        $this->assertClean();
    }

    /**
     * absence x impulse, the strain alone. With break_after_grace_mult at 1 (the grace alone, as
     * a config may set it), about four game days away break the fearful, codependent Muiri
     * (grace about three days) with a small decay: no fight opens, her resentment stays low. It is
     * the break alone that strains her return: her hurt is said, and next to it she does not reach
     * for him, where a partner whose bond held (Ashe: four days are inside her grace) does.
     */
    public function testABreakWithNoFightStillStrainsHerReturn(): void
    {
        $this->seed(60);
        $cfg = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelationshipDynamics::CONFIG_ROW_ID]), 0, 0), true);
        $cfg['bond_break'] = ['break_after_grace_mult' => 1.0];
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
        $t = $this->hello();
        $this->floors(30.0);
        $t = $this->play($t + 600, 10.0);
        $this->round('I missed you today. Tell me about your evening.', $t + 600, 'evening');

        $t = $this->play(self::at(self::N0 + 4, 18.0), 10.0);
        $this->round('I missed you today. Tell me about your evening.', $t, 'return');
        $d = $info = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $info[$npc] = ['grace' => RelationshipDynamics::neglectGraceGameDays($d[$npc], true), 'aff' => RelationshipDynamics::getCoreAffinity($d[$npc]),
                'break' => $d[$npc][RelDynAbsence::BREAK_KEY] ?? null, 'conflict' => !empty($d[$npc]['in_conflict']),
                'resentment' => self::x($d[$npc], 'resentment'), 'firing' => $d[$npc][RelDynImpulse::KEY]['firing'] ?? null,
                'felt' => array_keys($this->felt[$npc]['return'] ?? [])];
        }
        $this->probe('break alone', $info);
        $why = json_encode($info);
        $b = $d['Muiri'][RelDynAbsence::BREAK_KEY] ?? null;
        $this->assertIsArray($b, "Muiri: four days are past her grace {$why}");
        $this->assertEmpty($d['Muiri']['in_conflict'] ?? false, "Muiri: a small decay, no fight {$why}");
        $this->assertLessThan(51.0, self::x($d['Muiri'], 'resentment'), "Muiri: below the frustrated band {$why}");
        $this->assertTrue(RelDynAbsence::strains($d['Muiri']), $why);
        $this->assertArrayHasKey('bond_break_' . $b['mode'], $this->felt['Muiri']['return'], "Muiri: her hurt is said {$why}");
        $this->assertNotContains('social', (array) ($d['Muiri'][RelDynImpulse::KEY]['firing'] ?? []), "Muiri: no reach toward him next to it {$why}");
        $this->assertStringNotContainsString('a pull to talk with', $this->subtext['Muiri']['return'], "Muiri {$why}");
        $this->assertArrayNotHasKey('attraction', $this->felt['Muiri']['return'], "Muiri {$why}");
        $this->assertNull($d['Ashe'][RelDynAbsence::BREAK_KEY] ?? null, "Ashe: inside her grace {$why}");
        $this->assertContains('social', (array) ($d['Ashe'][RelDynImpulse::KEY]['firing'] ?? []), "Ashe: the bond held; she reaches for him {$why}");
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the cold romance

    /**
     * absence x attraction x rot. Four partners (core romantic, affinity 70) whose passion has
     * cooled below the spark; the player is there every day, small talk, never one warm exchange.
     * MDD 6.5: prolonged low passion bleeds affinity, from a week without a positive interaction.
     * It is the romance between them, so it runs while he is there (and not over an absence: the
     * absence path owns that); and MDD 8.3's political marriage (a partner the Matrix finds
     * unattracted: loveless by nature) does not rot, only a partner who could burn and does not.
     * Each at her own pace (M_modifiers: who she is).
     */
    public function testTheColdRomanceRotsWhileHeIsThereAndOnlyWhereItCouldBurn(): void
    {
        $this->seed(70);
        $t = $this->hello();
        $this->floors(12.0);
        $beds = array_keys(self::BEDS);
        $t = $this->play($t + 600, 10.0);
        $at = [];
        foreach ($beds as $npc) $at[$npc] = RelationshipDynamics::getCoreAffinity($this->dynamics($npc));
        for ($k = 1; $k <= 12; $k++) {
            $t = $this->play(max($t + 600, self::at(self::N0 + $k, 9.0)), 2.0);
            $t = $this->round('Morning.', $t, "day{$k}");
            if ($k === 6) {
                foreach ($beds as $npc) $this->assertSame(0.0, floatval(RelDynAbsence::jev($this->dynamics($npc))['rot_applied']), "{$npc}: a week of grace");
            }
        }
        $lost = $attracted = $info = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $attracted[$npc] = !empty($d['_attraction']['attracted']) && empty($d['_attraction']['hard_zero']);
            $lost[$npc] = -floatval(RelDynAbsence::jev($d)['rot_applied']);
            $info[$npc] = ['attracted' => $attracted[$npc], 'lost' => $lost[$npc], 'passion' => RelationshipDynamics::getPassion($d),
                'rot' => $d[RelDynAbsence::ROT_KEY] ?? null, 'aff0' => $at[$npc], 'aff' => RelationshipDynamics::getCoreAffinity($d)];
        }
        $this->probe('cold romance', $info);
        $why = json_encode($info);
        foreach ($beds as $npc) {
            if ($attracted[$npc]) {
                $this->assertGreaterThan(1.0, $lost[$npc], "{$npc}: a romance gone cold between them bleeds {$why}");
                $this->assertContains('low_passion', RelDynAbsence::jev($this->dynamics($npc))['rot_conditions'], $npc);
            } else {
                $this->assertSame(0.0, $lost[$npc], "{$npc}: loveless by nature, not rotting (MDD 8.3) {$why}");
            }
        }
        // (all four are drawn to Kaida here; the political marriage is RelDynBatchQFixTest's)
        $this->assertGreaterThan(1.0, max($lost) - min($lost), "each at her own pace {$why}");
        $this->assertGreaterThan($lost['Ashe'], $lost['Muiri'], "the immature toxic bed bleeds faster than the mature one {$why}");
        $this->assertClean();
    }

    // ------------------------------------------------------------------ her own line

    /**
     * combat x the pair. Lynly goes down; core voices her own bleedout comment (the 'instruction'
     * request, her line to the player) before the player says anything; no eval scores anything
     * (no connector). Her own line is not the player's answer to her fall: it neither spends nor
     * decides the rescue. The player's next word, care, answered grateful, is the answer (MDD 3.3).
     */
    public function testHerOwnBleedoutLineIsNotThePlayersAnswerToHerFall(): void
    {
        $this->seed(60);
        $t = $this->hello();
        unset($GLOBALS['RELLLM_CONNECTOR']);
        $t1 = $t + 600;
        $this->event('bleedout', self::LYNLY . ' falls to the ground almost unconscious', $t1);
        $this->ownLine(self::LYNLY, self::LYNLY . ' has lost combat and is wounded bleedingout.', $t1 + 200, 'own');
        $own = $this->dynamics(self::LYNLY);
        $this->assertIsArray($own[RelDynCombat::RESCUE_PENDING_KEY] ?? null, 'her own line leaves the fall waiting for the player');
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_LAST_KEY, $own);
        $this->mood = 'grateful';
        $this->turn(self::LYNLY, 'Are you hurt? Here, take my hand.', $t1 + self::MINUTE, 'rescue');
        $this->mood = 'default';
        $after = $this->dynamics(self::LYNLY);
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $after);
        $this->assertIsArray($after[RelDynCombat::RESCUE_LAST_KEY] ?? null, 'the player answered her fall with care');
        $this->assertStringStartsWith('local:', $after[RelDynCombat::RESCUE_LAST_KEY]['via']);
        $this->assertStringEndsWith('/grateful', $after[RelDynCombat::RESCUE_LAST_KEY]['via']);
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls);
    }

    /**
     * combat x passion x eval. All four partners with an earned floor go down; core voices each
     * one's own bleedout comment (the eval scores it: it addressed the player), then the player's
     * word to each is care, scored as a rescue. The eval of her own line decides nothing; the
     * player's does. The rescue is paid once: the MDD 3.3 response on the floor (by attachment,
     * Aela least), and no 'rescue' moment on top of it, so what moved is MDD 3.3's response.
     */
    public function testTheRescueIsPaidOnceAndHerOwnLineDecidesNothing(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->floors(30.0);
        $t = $this->play($t + 600, 10.0);
        $beds = array_keys(self::BEDS);
        $t1 = $t + 600;
        foreach ($beds as $i => $npc) $this->event('bleedout', "{$npc} falls to the ground almost unconscious", $t1 + 1000 * $i);
        foreach ($beds as $i => $npc) $this->ownLine($npc, "{$npc} has lost combat and is wounded bleedingout.", $t1 + 1000 * $i + 200, 'own');
        $this->worker();
        $pre = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertIsArray($d[RelDynCombat::RESCUE_PENDING_KEY] ?? null, "{$npc}: her own line decided nothing");
            $this->assertArrayNotHasKey(RelDynCombat::RESCUE_LAST_KEY, $d, $npc);
            $pre[$npc] = RelationshipDynamics::getEffectivePassion($d);
        }
        $t2 = $t1 + 2 * self::MINUTE;
        foreach ($beds as $i => $npc) $this->turn($npc, 'Are you hurt? Here, take my hand.', $t2 + 600 * $i, 'rescue');
        $this->worker();
        $bonus = $gain = $spike = $moved = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $last = $d[RelDynCombat::RESCUE_LAST_KEY] ?? null;
            $this->assertIsArray($last, "{$npc}: the player's care answered the fall");
            $this->assertStringStartsWith('eval:', $last['via']);
            $bonus[$npc] = floatval($last['bonus']);
            $gain[$npc] = floatval($last['gain']);
            $spike[$npc] = RelDynPassion::spike($d);
            $moved[$npc] = RelationshipDynamics::getEffectivePassion($d) - $pre[$npc];
        }
        $why = json_encode(compact('bonus', 'gain', 'spike', 'moved', 'pre'));
        $this->probe('rescue once', compact('bonus', 'gain', 'spike', 'moved', 'pre'));
        foreach ($beds as $npc) {
            $this->assertSame(0.0, $spike[$npc], "{$npc}: no rescue moment on top of the rescue {$why}");
            $this->assertEqualsWithDelta($gain[$npc], $moved[$npc], 0.05, "{$npc}: what moved is the rescue response {$why}");
        }
        $this->assertDoesNotMatchRegularExpression('/spike:rescue/', (string) file_get_contents($this->errorLog), 'the rescue is paid once');
        foreach (['Ashe', 'Muiri', self::LYNLY] as $npc) $this->assertGreaterThan($bonus[self::AELA], $bonus[$npc], "MDD 3.3: {$npc} above Aela {$why}");
        $this->assertClean();
    }
}
