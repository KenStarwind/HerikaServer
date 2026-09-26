<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynIntimacyLanePgDb
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
 * The p4r intimacy lane end to end with the four test beds (standing rule feedback_reldyn_testbeds):
 * Aela the Huntress, Ashe (Serene's hand-set vector, never read: nothing of her story anywhere),
 * Muiri (toxic, nudged fearful) and Lynly Star-Sung (the shy bard), on CHIM 3.4.1 core-shaped rows
 * and the committed seed's reads, through the real hooks as main.php runs them (prerequest ->
 * core's action list with the ext functions.php -> context_pre -> context -> postrequest), the real
 * eval producer and worker (LLM stubbed at the connector boundary). No LLM call; feelings, never
 * numbers, in front of the LLM; Jev gets the numbers.
 *   post-intimacy       the same scene, four outcomes by who she is; the afterglow held and taken
 *                       back; the drunken night judged by the sober morning
 *   arousal-valence     the scene's spike read warm or uneasy (the horseshoe), shown to the eval,
 *                       settling with time
 *   gift-delta-formula  a re-gift she recognizes, a stolen ring: no gifts
 *   mf-coordinates      the envelope derived from how she feels toward the player
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynIntimacyLaneTestBedsPostgresTest extends TestCase
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
    private RelDynIntimacyLanePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** Every eval prompt (the user message) the stubbed LLM was shown */
    private array $prompts = [];
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
        $this->schema = 'reldyn_p4ri_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynIntimacyLanePgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdp4ribeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_p4ri_beds_test.log');
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
    private function seed(int $aff, string $type = 'romantic', array $extraRelationships = []): void
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
                     'relationships' => [self::PLAYER => ['aff' => $aff, 'type' => $type], self::SUITOR => ['aff' => 10, 'type' => 'neutral']]
                         + ($extraRelationships[$name] ?? [])])]);
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
            $this->prompts[] = (string) $messages[1]['content'];
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
    private function hello(string $type = 'romantic'): int
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 17.9));
        $t = $this->round('Well met, love.', self::at(self::N0, 18.0), 'hello');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame($type, $d['_core_rel_type'], "{$npc}: core's type toward the player");
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


    // ------------------------------------------------------------------ lane helpers

    /** A Sharmat scene stage with the player and $npc (ext_nsfw_sexcene, as Sharmat's OStim handler reports it), alone. */
    private function scene(string $npc, int $gamets, string $label): void
    {
        $data = 'OStimScene/vaginal,romantic/Stage1_A1/' . self::PLAYER . "^dom,vaginal/{$npc}^sub,vaginal";
        $this->people = "|{$npc}|" . self::PLAYER . '|';
        $this->event('ext_nsfw_sexcene', $data, $gamets);
        $this->request($npc, ['ext_nsfw_sexcene', (string) $this->realTs, (string) $gamets, $data], self::PLAYER, $label);
        $this->people = null;
    }

    /**
     * Does the rendered $felt line say $text? Intensity formatting may set words in caps, add
     * pauses and '!' and (low maturity) drop a letter: most of its words must be there.
     */
    private static function reads(string $felt, string $text): bool
    {
        $words = fn(string $s) => array_values(array_filter(preg_split('/[^a-z_]+/', strtolower($s)), fn($w) => strlen($w) >= 4));
        $said = $words($felt);
        $want = $words($text);
        return $want !== [] && count(array_intersect($want, $said)) >= 0.7 * count($want);
    }

    /** Jev's block for $npc now (the numbers; decisions §3). */
    private function jev(string $npc, int $gamets): array
    {
        return RelDynJev::state($npc, $this->dynamics($npc), (float) $gamets);
    }

    /** The eval prompts of $npc's exchanges since $from (the stub keeps every one). */
    private function promptsOf(string $npc, int $from = 0): array
    {
        $out = [];
        foreach (array_slice($this->prompts, $from) as $p) {
            if (str_contains($p, "THIS EXCHANGE") && preg_match('/\b' . preg_quote($npc, '/') . '\b/', $p)) $out[] = $p;
        }
        return $out;
    }

    // ------------------------------------------------------------------ post-intimacy x arousal-valence

    /**
     * post-intimacy x arousal-valence x felt steering x eval x Jev. Four partners (core romantic,
     * affinity 60) spend an evening with the player, then a scene with each, alone, as Sharmat
     * reports it. The act is the same; what follows is the context (dimension draft "Context
     * Determines Outcome"): Aela, secure and trusting, deepens; the fearful Muiri wants the closeness
     * and fears it, though her trust is as high; each outcome holds its own afterglow (the draft's bonded row: comfort
     * +15 for two game hours) and the same arousal spike, read as warm or as uneasy (the horseshoe:
     * the eval is shown the band, never scores it). The dialogue model gets the afterglow as
     * behaviour, never numbers; Jev gets the outcome. Three game hours later the glow is taken back
     * exactly, the lasting trust of a deep bond stays, and arousal has settled.
     */
    public function testTheSameSceneFollowsWhoSheIsAndTheGlowFades(): void
    {
        // Serene's fearful reading of the draft's "manipulated" row is opt-in (batch-R review,
        // post_intimacy.fearful_vulnerable, off by default): this scene shows it switched on
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false,
                 'post_intimacy' => array_replace(RelDynPostIntimacy::configDefaults(), ['fearful_vulnerable' => true])]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seed(60);
        $t = $this->hello();
        $this->floors(30.0);
        $t = $this->play($t + 600, 10.0);
        $t = $this->alone('I missed you today. Tell me about your evening.', $t + 600, 'evening');
        $beds = array_keys(self::BEDS);
        $pre = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $pre[$npc] = ['comfort' => self::x($d, 'comfort'), 'trust' => self::x($d, 'trust'), 'arousal' => self::x($d, 'arousal')];
        }
        $night = $t + 600;
        foreach ($beds as $i => $npc) $this->scene($npc, $night + 1200 * $i, 'scene');
        $p0 = count($this->prompts);
        $t = $this->alone('Stay. Just like this.', $night + 1200 * count($beds) + 600, 'pillow');

        $out = $info = $nervous = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $s = $d[RelDynPostIntimacy::KEY] ?? null;
            $this->assertIsArray($s, "{$npc}: the scene the plugin reported is an encounter");
            $out[$npc] = $s['outcome'];
            $info[$npc] = ['outcome' => $s['outcome'], 'context' => $s['context'], 'held' => $s['held'], 'lasting' => $s['lasting'],
                'mood' => $s['mood'], 'arousal' => self::x($d, 'arousal'), 'valence' => self::x($d, 'valence'),
                'felt' => array_keys($this->felt[$npc]['pillow'] ?? [])];
        }
        $this->probe('the same scene', $info);
        $why = json_encode($info);
        $this->assertSame(RelDynPostIntimacy::BONDED, $out[self::AELA], "Aela: secure and trusting, it deepens {$why}");
        $this->assertSame(RelDynPostIntimacy::VULNERABLE, $out['Muiri'], "Muiri: fearful, she wants it and fears it {$why}");
        $this->assertGreaterThanOrEqual($info[self::AELA]['context']['trust'] - 5.0, $info['Muiri']['context']['trust'], "not for want of trust {$why}");
        $this->assertGreaterThanOrEqual(2, count(array_unique($out)), "the context decides, not the act {$why}");
        $cfg = RelDynPostIntimacy::config();
        foreach ($beds as $npc) {
            $row = $cfg['outcomes'][$out[$npc]];
            // the afterglow is said as behaviour
            $this->assertArrayHasKey('post_intimacy', $this->felt[$npc]['pillow'], "{$npc}: the afterglow reaches the LLM {$why}");
            $this->assertTrue(self::reads($this->felt[$npc]['pillow']['post_intimacy'], $row['glow']),
                "{$npc}: her outcome's own read: " . $this->felt[$npc]['pillow']['post_intimacy']);
            // the horseshoe: the same spike for everyone, the valence is the context's
            $this->assertGreaterThan($pre[$npc]['arousal'] + 5.0, $info[$npc]['arousal'], "{$npc}: an arousal spike {$why}");
            $this->assertSame($row['valence'] > 0, $info[$npc]['mood']['valence'] > 0, "{$npc}: the valence is the context's {$why}");
            // Jev gets the outcome
            $this->assertStringContainsString("post_intimacy={$out[$npc]}", $this->jev($npc, $t)['text']);
            // the eval sees the state as input (unless she is settled: a stoic pulse barely stirs)
            $prompts = $this->promptsOf($npc, $p0);
            $this->assertNotEmpty($prompts, $npc);
            $band = RelationshipDynamics::getArousalValenceBand($info[$npc]['arousal'], $info[$npc]['valence']);
            $nervous[$npc] = $band['label'] === 'Settled' ? null : $band['label'];
            if ($nervous[$npc] !== null) {
                $this->assertStringContainsString("Nervous state: {$band['label']} ({$band['keywords']})", end($prompts), "{$npc}: the band is the eval's input {$why}");
            } else {
                $this->assertStringNotContainsString('Nervous state: ', end($prompts), "{$npc}: settled {$why}");
            }
        }
        // the horseshoe in the eval's input: the same kind of spike, read as ease or as unease
        $this->assertSame('Content', $nervous[self::AELA], json_encode($nervous) . $why);
        $this->assertContains($nervous['Muiri'], ['Numb', 'Panicked'], json_encode($nervous) . $why);
        $this->assertGreaterThan(0.0, $info[self::AELA]['mood']['valence']);
        $this->assertLessThan(0.0, $info['Muiri']['mood']['valence'], "Muiri: the same spike reads uneasy {$why}");
        $this->assertGreaterThan($pre[self::AELA]['comfort'] + 5.0, self::x($this->dynamics(self::AELA), 'comfort'), "Aela: the bonded glow {$why}");

        // Three game hours later: the glow is taken back exactly, the lasting trust stays, arousal settled
        $morning = $this->play((int) round($t + 3 * self::HOUR), 1.0);
        $this->alone('Good morning.', $morning, 'later');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertEmpty($d[RelDynPostIntimacy::KEY]['held'] ?? [], "{$npc}: the afterglow ended");
            $this->assertArrayNotHasKey('post_intimacy', $this->felt[$npc]['later'] ?? [], "{$npc}: nothing left to show but the after of a pending verdict");
            $this->assertEqualsWithDelta($pre[$npc]['comfort'], self::x($d, 'comfort'), 0.5, "{$npc}: the glow is taken back exactly {$why}");
            // what the scene lifted above her rest (baseline + the states held on it) has more than halved
            $rest = floatval($d['dimensions']['arousal']['baseline'] ?? 10.0) + RelationshipDynamics::heldTemporaryOffset($d, 'arousal');
            $this->assertLessThanOrEqual(0.5 * max(0.0, $info[$npc]['arousal'] - $rest) + 0.01, max(0.0, self::x($d, 'arousal') - $rest),
                "{$npc}: arousal settles (rest {$rest}) {$why}");
        }
        $this->assertGreaterThan($pre[self::AELA]['trust'] + 1.0, self::x($this->dynamics(self::AELA), 'trust'),
            "Aela: chose to be vulnerable with him, the trust stays {$why}");
        $this->assertClean();
    }

    /**
     * post-intimacy x item modifiers x resentment_self x diary depth. Four women the player is not
     * with (core friend, affinity 30) each drink a mead with him, then a scene. It is the drunken
     * night of the draft for all four, the same in the moment ("relaxed, guards down"). The sober
     * morning (nine game hours later, the mead long worn off) judges it by who she is: comfort
     * crashes below where it started, the shame is scaled by how much she holds herself to (her
     * own maturity), a stranger's trust is not the question; a shallow mind never looks back.
     */
    public function testTheDrunkenNightIsJudgedBySoberMorningByWhoSheIs(): void
    {
        $this->seed(30, 'friend');
        $t = $this->hello('friend');
        $t = $this->play($t + 600, 10.0);
        $beds = array_keys(self::BEDS);
        $pre = [];
        foreach ($beds as $i => $npc) {
            $at = $t + 600 + 3000 * $i;
            $this->event('itemfound', "{$npc} drank Nord Mead", $at);
            $this->people = "|{$npc}|" . self::PLAYER . '|';
            $this->turn($npc, 'Another round? On me.', $at + 60, 'drink');
            $this->people = null;
            $d = $this->dynamics($npc);
            $this->assertTrue(RelDynPostIntimacy::intoxicated($d), "{$npc}: the mead is on her");
            $pre[$npc] = ['comfort' => self::x($d, 'comfort') - RelationshipDynamics::heldTemporaryOffset($d, 'comfort'),
                'rs' => self::x($d, 'resentment_self'), 'trust' => self::x($d, 'trust')];
            $this->scene($npc, $at + 600, 'scene');
        }
        $this->worker();
        $moment = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame(RelDynPostIntimacy::DRUNK, $d[RelDynPostIntimacy::KEY]['outcome'] ?? null, "{$npc}: " . json_encode($d[RelDynPostIntimacy::KEY] ?? null));
            $moment[$npc] = RelDynPostIntimacy::feltText($d, (float) ($t + 600 + 3000 * 4))['phase'] ?? null;
        }
        // the sober morning
        $morning = $this->play(self::at(self::N0 + 1, 9.0), 1.0);
        $t = $this->alone('Morning. About last night...', $morning, 'morning');
        $info = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $s = $d[RelDynPostIntimacy::KEY] ?? null;
            $info[$npc] = ['maturity' => RelDynDiary::ownMaturity($d), 'state' => $s, 'comfort' => self::x($d, 'comfort'),
                'rs' => self::x($d, 'resentment_self'), 'trust' => self::x($d, 'trust'), 'pre' => $pre[$npc],
                'felt' => array_keys($this->felt[$npc]['morning'] ?? []), 'moment' => $moment[$npc]];
        }
        $this->probe('drunken night', $info);
        $why = json_encode($info);
        $corrected = [];
        foreach ($beds as $npc) {
            $this->assertSame('glow', $info[$npc]['moment'], "{$npc}: in the moment it is the glow {$why}");
            $s = $info[$npc]['state'];
            if (RelDynDiary::depth($info[$npc]['maturity']) === 'shallow') {
                $this->assertTrue(!empty($s['shallow']) || $s === null, "{$npc}: a shallow mind never looks back {$why}");
                $this->assertEqualsWithDelta($pre[$npc]['rs'], $info[$npc]['rs'], 0.01, $npc);
                continue;
            }
            $corrected[] = $npc;
            $this->assertIsArray($s, $npc);
            $this->assertLessThan($pre[$npc]['comfort'], $info[$npc]['comfort'], "{$npc}: crashed past where she started {$why}");
            $this->assertGreaterThan($pre[$npc]['rs'] + 3.0, $info[$npc]['rs'], "{$npc}: shame {$why}");
            $this->assertArrayNotHasKey('trust', $s['correction'], "{$npc}: a man she is not with: trust unchanged {$why}");
            $this->assertArrayHasKey('post_intimacy', $this->felt[$npc]['morning'], "{$npc}: the sober self shows {$why}");
            $this->assertTrue(self::reads($this->felt[$npc]['morning']['post_intimacy'], RelDynPostIntimacy::config()['outcomes'][RelDynPostIntimacy::DRUNK]['after']),
                "{$npc}: " . $this->felt[$npc]['morning']['post_intimacy']);
        }
        $this->assertGreaterThanOrEqual(3, count($corrected), $why);
        // the more she holds herself to, the harder the shame
        $byMaturity = $corrected;
        usort($byMaturity, fn($a, $b) => $info[$a]['maturity'] <=> $info[$b]['maturity']);
        $low = $byMaturity[0];
        $high = end($byMaturity);
        if ($info[$high]['maturity'] - $info[$low]['maturity'] > 10.0) {
            $this->assertGreaterThan($info[$low]['rs'] - $pre[$low]['rs'], $info[$high]['rs'] - $pre[$high]['rs'], "{$high} over {$low} {$why}");
        }
        $this->assertClean();
    }

    // ------------------------------------------------------------------ gift-delta-formula

    /**
     * gift delta x core data x felt steering. Ysolda gave the player four jade amulets last week;
     * he hands one to Aela, who knows Ysolda ("This was Ysolda's, wasn't it?": trust and respect
     * fall, no gift) and one to Lynly, who does not (an ordinary gift). A gold ring the game marks
     * stolen goes to Ashe and to Muiri: no gift for either, trust and respect fall and a grievance
     * is logged. Each reads it as behaviour, never numbers.
     */
    public function testAReGiftSheRecognizesAndAStolenRingAreNoGifts(): void
    {
        $this->seed(40, 'friend', [self::AELA => ['Ysolda' => ['aff' => 20, 'type' => 'platonic']]]);
        $this->event('itemfound', 'Ysolda gave 4 Jade Amulet to ' . self::PLAYER, self::at(self::N0 - 7, 12.0));
        $t = $this->hello('friend');
        $give = [self::AELA => 'Jade Amulet', self::LYNLY => 'Jade Amulet', 'Ashe' => 'Gold Ring (stolen)', 'Muiri' => 'Gold Ring (stolen)'];
        $pre = [];
        $i = 0;
        foreach ($give as $npc => $item) {
            $d = $this->dynamics($npc);
            $pre[$npc] = ['trust' => self::x($d, 'trust'), 'respect' => self::x($d, 'respect'), 'comfort' => self::x($d, 'comfort'),
                'resentment' => self::x($d, 'resentment')];
            $at = $t + 600 + 1200 * $i++;
            $this->event('itemfound', self::PLAYER . " gave 1 {$item} to {$npc},(value 75 gold)", $at);
            $this->people = "|{$npc}|" . self::PLAYER . '|';
            $this->turn($npc, 'This is for you.', $at + 60, 'gift');
            $this->turn($npc, 'Do you like it?', $at + 600, 'after');
            $this->people = null;
        }
        $this->worker();
        $info = [];
        foreach ($give as $npc => $item) {
            $d = $this->dynamics($npc);
            $info[$npc] = ['trust' => self::x($d, 'trust'), 'respect' => self::x($d, 'respect'), 'comfort' => self::x($d, 'comfort'),
                'resentment' => self::x($d, 'resentment'), 'pre' => $pre[$npc], 'felt' => $this->felt[$npc]['after']['gift'] ?? null,
                'log' => array_column((array) ($d['dimensions']['resentment']['grievance_log'] ?? []), 'kind')];
        }
        $this->probe('gifts', $info);
        $why = json_encode($info);
        foreach ([self::AELA, 'Ashe', 'Muiri'] as $npc) {
            $this->assertLessThan($pre[$npc]['trust'], $info[$npc]['trust'], "{$npc}: trust falls {$why}");
            $this->assertLessThan($pre[$npc]['respect'], $info[$npc]['respect'], "{$npc}: respect falls {$why}");
            $this->assertIsString($info[$npc]['felt'], "{$npc}: she shows it {$why}");
            $this->assertDoesNotMatchRegularExpression('/\d/', $info[$npc]['felt']);
        }
        $this->assertStringContainsString('whose Jade Amulet', $info[self::AELA]['felt'], $why);
        foreach (['Ashe', 'Muiri'] as $npc) {
            $this->assertStringContainsString('stolen', $info[$npc]['felt'], $why);
            $this->assertContains('stolen_gift', $info[$npc]['log'], "{$npc}: a grievance {$why}");
        }
        $this->assertNotContains('stolen_gift', $info[self::AELA]['log'], "a re-gift is no theft {$why}");
        $this->assertGreaterThan($pre[self::LYNLY]['comfort'], $info[self::LYNLY]['comfort'], "Lynly does not know whose it was: a gift {$why}");
        $this->assertGreaterThanOrEqual($pre[self::LYNLY]['trust'], $info[self::LYNLY]['trust'], $why);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ mf-coordinates

    /**
     * mf-coordinates x felt steering x Jev. The M/F envelope is derived at display time from how
     * she feels toward the player (Fix 6: respect + self-confidence -> M, trust + comfort -> F,
     * x maturity, the traits engine's directional Y), around the anchor her traits set. Ashe has
     * come to trust him and be at ease with him (as the editor would set it); Muiri has neither
     * trust, comfort nor respect for him. Ashe's F rises above her anchor; Muiri's F and M fall
     * below theirs; the stored anchors are not written. The felt envelope line (a candidate for both,
     * displaced past the deadband; rendered where its salience earns a place) speaks the derived
     * quadrant as behaviour; Jev gets the numbers.
     */
    public function testTheEnvelopeFollowsHowSheFeelsTowardHim(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->editDynamics('Ashe', function (array &$d): void {
            $d['dimensions']['trust']['x'] = 90.0;
            $d['dimensions']['comfort']['x'] = 90.0;
        });
        $this->editDynamics('Muiri', function (array &$d): void {
            $d['dimensions']['trust']['x'] = 5.0;
            $d['dimensions']['comfort']['x'] = 5.0;
            $d['dimensions']['respect']['x'] = 5.0;
        });
        $anchors = [];
        foreach (['Ashe', 'Muiri'] as $npc) {
            $d = $this->dynamics($npc);
            $anchors[$npc] = ['m' => self::x($d, 'coord_m'), 'f' => self::x($d, 'coord_f')];
        }
        $t = $this->alone('Tell me what you are thinking.', $t + 600, 'envelope');
        $mf = $info = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $mf[$npc] = $this->jev($npc, $t)['mf'];
            $info[$npc] = ['mf' => $mf[$npc], 'felt' => $this->felt[$npc]['envelope']['coord_mf'] ?? null];
        }
        $this->probe('envelope', $info);
        $why = json_encode($info);
        $this->assertGreaterThan($mf['Ashe']['anchor_f'] + 10.0, $mf['Ashe']['f'], "Ashe: trust and ease soften her toward +F {$why}");
        $this->assertLessThan($mf['Muiri']['anchor_f'] - 10.0, $mf['Muiri']['f'], "Muiri: no trust, no ease: -F {$why}");
        $this->assertLessThan($mf['Muiri']['anchor_m'], $mf['Muiri']['m'], "Muiri: no respect: -M {$why}");
        foreach (['Ashe', 'Muiri'] as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame($anchors[$npc], ['m' => self::x($d, 'coord_m'), 'f' => self::x($d, 'coord_f')], "{$npc}: the anchor is not written");
            $band = RelationshipDynamics::getMFQuadrantBand($mf[$npc]['m'], $mf[$npc]['f']);
            $candidates = [];
            foreach (RelDynFelt::compose($npc, self::PLAYER, $d, (float) $t)['lines'] as $l) $candidates[$l['key']] = $l['text'];
            $this->assertSame($band['keywords'], $candidates['coord_mf'] ?? null, "{$npc}: displaced, the envelope is a candidate in the derived quadrant {$why}");
            if ($info[$npc]['felt'] !== null) {
                $this->assertStringContainsStringIgnoringCase(explode(',', $band['keywords'])[0], $info[$npc]['felt'], "{$npc}: rendered as it reads {$why}");
            }
            $this->assertStringContainsString('mf=', $this->jev($npc, $t)['text']);
        }
        $this->assertIsString($info['Ashe']['felt'], "Ashe: her envelope earns its place {$why}");
        $this->assertClean();
    }
}
