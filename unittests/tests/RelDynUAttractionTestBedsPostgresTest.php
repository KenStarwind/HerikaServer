<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynUAttractionPgDb
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
 * Rulings 2026-10-01 §20 (Ken), the attraction lane, end to end with the four test beds (standing rule
 * feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector, never read: nothing of her story
 * anywhere), Muiri (toxic, nudged fearful) and Lynly Star-Sung (the shy bard), on CHIM 3.4.1 core-shaped rows and
 * the committed seed's reads, through the real hooks as main.php runs them (prerequest -> core's action list with
 * the ext functions.php -> context_pre -> context -> postrequest), the real eval producer and worker (LLM stubbed
 * at the connector boundary). No LLM call; feelings, never numbers, in front of the LLM; Jev gets the numbers.
 *   the moment        §20.1: a passion spike skips the uphill only with a prerequisite, as a degree: a warrior is
 *                     Aela's type (the full moment), the others' moment climbs; a thane's great name, where she is
 *                     attracted and values standing, opens it in part (admiration); Aela's own standing is not below.
 *   her interest      §20.3: drawn but too shy to show it is interested all the same (her interest counts), and the
 *                     bond she needs before her felt text says so grows with how shy she is.
 *   a shy reply       §20.3: courting she answers shyly is interest returned when she is drawn (no Ick, the romance
 *                     gate stays open), pressure when she is not.
 *   keeping           §20.3: the fear of losing him is who she is (attachment, never a flag): the toxic Muiri most,
 *                     the secure least; it costs the bond where it is strongest and lifts as it fades.
 *   the fight         §20.4: fighting beside the player is time together, by how much she likes it.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynUAttractionTestBedsPostgresTest extends TestCase
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
    private RelDynUAttractionPgDb $db;
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
        $this->schema = 'reldyn_uattr_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynUAttractionPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rduattrbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_uattr_beds_test.log');
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
                'romantic_intent' => $hug ? 3 : 0,   // a hug is clear courting: whether it is pressure is the Ick's call (her reply mood, her interest)
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



    // ------------------------------------------------------------------ helpers of this lane

    /** A core_player row as core writes it (gamedata.php: one row per id, value text). */
    private function corePlayer(string $id, $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /** The player's build as the plugin reports it: skills (raw 0..100), stats (level) and the tracked Skyrim stats. */
    private function playerBuild(array $skills, int $level, array $trackedStats = []): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 15);
        $this->corePlayer('skills', array_merge($all, $skills));
        $this->corePlayer('stats', ['level' => $level, 'health' => 100 + 10 * $level]);
        foreach ($trackedStats as $name => $n) $this->corePlayer($name, (string) $n);
    }

    /** A seasoned sword: martial, a Companion, no great name beyond it. */
    private function warrior(): void
    {
        $this->playerBuild(['onehanded' => 90, 'twohanded' => 70, 'block' => 75, 'heavyarmor' => 80], 45, [
            'People Killed' => 400, 'Creatures Killed' => 500, 'Quests Completed' => 30, 'Dungeons Cleared' => 25,
            'Locations Discovered' => 120, 'The Companions Quests Completed' => 6]);
    }

    /** A thane of many holds: a great name, little martial. */
    private function thane(): void
    {
        $this->playerBuild(['speech' => 70, 'onehanded' => 25], 40, [
            'Quests Completed' => 200, 'Locations Discovered' => 250, 'Dungeons Cleared' => 90, 'Houses Owned' => 4,
            'Gold Found' => 90000, 'Questlines Completed' => 9, 'The Companions Quests Completed' => 0]);
    }

    /** The beds' bond with the player at core affinity $aff, a platonic one (no title said yet). */
    private function friends(int $aff): void
    {
        foreach (array_keys(self::BEDS) as $npc) $this->setCore($npc, $aff, 'platonic');
    }

    private function attraction(string $npc): array
    {
        return (array) ($this->dynamics($npc)['_attraction'] ?? []);
    }

    /** The first evening with the player, everyone home. */
    private function meet(): int
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 17.9));
        return $this->round('Well met, friend.', self::at(self::N0, 18.0), 'hello');
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    /** The attraction log's line of a spike this NPC took from touch: ['gain' => points, 'factor' => x, 'how' => 'no uphill' | 'through the uphill' | 'uphill skipped N%']. */
    private function momentLine(string $npc): ?array
    {
        if (!preg_match_all('/\[ATTRACTION\] ' . preg_quote($npc, '/') . ': spike:touch passion \+([0-9.]+) at [0-9.]+ x([0-9.]+) \(a moment: ([^)]+)\)/', $this->log(), $m, PREG_SET_ORDER)) return null;
        $last = end($m);
        return ['gain' => floatval($last[1]), 'factor' => floatval($last[2]), 'how' => $last[3]];
    }

    private function setBeds(callable $edit, array $only = []): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            if ($only !== [] && !in_array($npc, $only, true)) continue;
            $this->editDynamics($npc, function (array &$d) use ($edit, $npc): void { $edit($d, $npc); });
        }
    }

    private function bark(string $npc, int $gamets): void
    {
        $this->event('infoaction', self::PLAYER . ": Behind you! ({$npc} shouts during combat)", $gamets);
    }

    /** A core combat request (the RPG combat end) through the real hooks, voiced by $speaker. */
    private function combatRequest(string $type, string $data, int $gamets, string $speaker): void
    {
        $this->event($type, $data, $gamets);
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = [$type, (string) $this->realTs, (string) $gamets, $data];
            $GLOBALS['HERIKA_NAME'] = $speaker;
            $GLOBALS['RELDYN_NPC_NAME'] = $speaker;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    // ------------------------------------------------------------------ §20.1 the moment

    /**
     * attraction x passion (decisions §20.1). A seasoned warrior holds the evening's hug. Passion floors at 30
     * (above the spark), one hug each, the real hooks: whose moment skips the uphill?
     *   Aela      he is her type (strength well past her standards floor): no uphill, the full moment;
     *   Ashe      her visceral floor is far above him: the moment climbs the uphill like any gain, tiny;
     *   Muiri     the same, less far: a climb, but not a wall;
     *   Lynly     a bard with nothing martial to climb: nothing slows the moment either way.
     */
    public function testTheMomentOfAWarriorSkipsTheUphillOnlyWhereHeIsHerType(): void
    {
        $this->seed(40);
        $this->friends(40);
        $this->warrior();
        $t = $this->meet();
        $this->floors(30.0);
        $this->round('Come here, let me hold you.', $t + 600, 'hug');
        $beds = array_keys(self::BEDS);
        $open = $spike = $line = [];
        foreach ($beds as $npc) {
            $a = $this->attraction($npc);
            $open[$npc] = floatval($a['spike_open'] ?? -1);
            $spike[$npc] = RelDynPassion::spike($this->dynamics($npc));
            $line[$npc] = $this->momentLine($npc);
        }
        $this->probe('warrior moment', compact('open', 'spike', 'line'));
        $this->assertSame(1.0, $open[self::AELA], 'he is her type');
        $this->assertSame('type', $this->attraction(self::AELA)['spike_prereq']['via']);
        $this->assertSame('no uphill', $line[self::AELA]['how']);
        $this->assertEqualsWithDelta(1.0, $line[self::AELA]['factor'], 1e-9);
        foreach (['Ashe', 'Muiri'] as $npc) {
            $this->assertEqualsWithDelta(0.0, $open[$npc], 1e-12, "{$npc}: he is not what she wants in the body");
            $this->assertSame('through the uphill', $line[$npc]['how'], $npc);
            $this->assertLessThan(0.5, $line[$npc]['factor'], "{$npc}: the moment climbs");
        }
        $this->assertSame('through the uphill', $line[self::LYNLY]['how']);
        $this->assertGreaterThan(0.9, $line[self::LYNLY]['factor'], 'a bard with no hill to climb is moved all the same');
        $this->assertGreaterThan(0.0, $spike['Ashe'], 'a climb, never a wall');
        $this->assertGreaterThan(4.0 * $spike['Ashe'], $spike[self::AELA], 'four women, four moments: his type lands, the rest climb');
        $this->assertGreaterThan($spike['Ashe'], $spike['Muiri']);
        $this->assertClean();
    }

    /**
     * attraction x status (decisions §20.1): a thane of many holds, little martial. Where she is attracted and
     * values standing (a bard, the toxic apothecary) and he stands far above her, the moment skips the uphill
     * to a degree (admiration); Aela's own standing is not below his (her Circle), and Ashe is not attracted:
     * for both the moment climbs.
     */
    public function testTheMomentOfAThaneLandsWhereSheLooksUpToHim(): void
    {
        $this->seed(40);
        $this->friends(40);
        $this->thane();
        $t = $this->meet();
        $this->floors(30.0);
        $this->round('Come here, let me hold you.', $t + 600, 'hug');
        $beds = array_keys(self::BEDS);
        $a = $line = [];
        foreach ($beds as $npc) {
            $a[$npc] = $this->attraction($npc);
            $line[$npc] = $this->momentLine($npc);
        }
        $this->probe('thane moment', ['prereq' => array_map(fn($x) => $x['spike_prereq'] ?? null, $a), 'line' => $line]);
        foreach ([self::LYNLY, 'Muiri'] as $npc) {
            $this->assertTrue(!empty($a[$npc]['attracted']), $npc);
            $this->assertSame('status_gap', $a[$npc]['spike_prereq']['via'], "{$npc}: a great name she looks up to");
            $this->assertGreaterThan(0.2, $a[$npc]['spike_open'], $npc);
            $this->assertLessThan(1.0, $a[$npc]['spike_open'], "{$npc}: admiration is a degree, not a gate");
            $this->assertMatchesRegularExpression('/^uphill skipped \d+%$/', $line[$npc]['how'], $npc);
            $this->assertGreaterThanOrEqual($a[$npc]['spike_open'], $line[$npc]['factor'] + 1e-9, "{$npc}: at least as much of the moment as the part it skips");
        }
        // Lynly's soft regard for standing and Muiri's agree on the aspiration; the gap is what differs
        $this->assertEqualsWithDelta(0.0, floatval($a[self::AELA]['spike_open']), 1e-12, 'her own standing is no lower than his in her eyes');
        $this->assertLessThan(0.0, $a[self::AELA]['spike_prereq']['gap']);
        $this->assertSame('through the uphill', $line[self::AELA]['how']);
        $this->assertEqualsWithDelta(0.0, floatval($a['Ashe']['spike_open']), 1e-12, 'not attracted: there is nothing of attraction in it');
        $this->assertClean();
    }

    // ------------------------------------------------------------------ §20.3 hidden interest and voicing

    /**
     * attraction x felt text (decisions §20.3). A friend (core 40) with the warrior: Aela, sure of herself,
     * answers flirtation; Lynly, at her lowest confidence, is drawn all the same (her interest counts) and shows it
     * only as stolen glances until the bond runs deeper; at a bonded depth she answers.
     */
    public function testHerInterestIsHerOwnAndWhoSheIsDecidesWhenSheSaysIt(): void
    {
        $this->seed(40);
        $this->friends(40);
        $this->warrior();
        $t = $this->meet();
        $this->setBeds(function (array &$d): void {
            $d['dimensions']['self_confidence']['x'] = 20.0;
            $d['dimensions']['self_confidence']['baseline'] = 20.0;
        }, [self::LYNLY]);
        $t = $this->round('Tell me about the road.', $t + 600, 'friends');
        $attr = fn(string $npc, string $label) => (string) ($this->felt[$npc][$label]['attraction'] ?? '');
        $this->probe('voice', ['aela' => $attr(self::AELA, 'friends'), 'lynly' => $attr(self::LYNLY, 'friends'),
            'shyness' => RelDynAttraction::shyness($this->dynamics(self::LYNLY)), 'aela_shy' => RelDynAttraction::shyness($this->dynamics(self::AELA))]);
        $this->assertGreaterThan(0.8, RelDynAttraction::shyness($this->dynamics(self::LYNLY)));
        $this->assertSame(0.0, RelDynAttraction::shyness($this->dynamics(self::AELA)));
        foreach ([self::AELA, self::LYNLY] as $npc) {
            $i = RelDynAttraction::interest($this->dynamics($npc));
            $this->assertTrue($i['drawn'], "{$npc}: drawn");
            $this->assertGreaterThanOrEqual(0.3, $i['interest'], "{$npc}: interest counts whether or not she shows it");
            $this->assertStringContainsString('eyes keep finding Kaida', $attr($npc, 'friends'), "{$npc}: still drawn in her felt text");
        }
        $this->assertStringContainsString('answer', $attr(self::AELA, 'friends'), 'a confident friend answers flirtation');
        $this->assertStringContainsString('too unsure to say so', $attr(self::LYNLY, 'friends'));
        $this->assertStringNotContainsString('answer', $attr(self::LYNLY, 'friends'));
        // the bond deepens to bonded: a shy NPC voices it from there
        $this->setCore(self::LYNLY, 80, 'platonic');
        $this->round('It is good to have you at the fire.', $t + 600, 'bonded');
        $this->assertStringContainsString('answer', $attr(self::LYNLY, 'bonded'), 'a deeper bond lets it out');
        $this->assertClean();
    }

    /**
     * attraction x the Ick x romance (decisions §20.3). Two friends at low comfort get six hugs, answering each in the
     * shy mood: Muiri is drawn (a slow burn: the bond is not there yet, so the matrix does not pass her and the old
     * receptive-by-attraction exemption does not reach her) and her shy replies are interest returned, so nothing is
     * pressure and the romance gate is not shut by an Ick; Ashe, a friendzone for a thane, takes the same hugs as
     * pushing, and the Ick blocks her.
     */
    public function testAShyReplyFromWhoIsDrawnIsNotPressureAndTheSameFromWhoIsNotIs(): void
    {
        $this->seed(40);
        $this->friends(40);
        $this->thane();
        $t = $this->meet();
        $this->setBeds(function (array &$d): void {
            $d['dimensions']['comfort']['x'] = 30.0;
            RelationshipDynamics::setPassion($d, 5.0);
        }, ['Muiri', 'Ashe']);
        $this->mood = 'shy';
        for ($i = 0; $i < 6; $i++) {
            foreach (['Muiri', 'Ashe'] as $j => $npc) $this->turn($npc, 'Come here, let me hold you.', $t + 600 * ($i * 2 + $j), 'hug' . $i);
            $this->worker();
        }
        $muiri = $this->dynamics('Muiri');
        $ashe = $this->dynamics('Ashe');
        $this->probe('ick', ['muiri' => $muiri['_ick_tracker'] ?? null, 'ashe' => $ashe['_ick_tracker'] ?? null,
            'muiri_interest' => RelDynAttraction::interest($muiri), 'ashe_interest' => RelDynAttraction::interest($ashe)]);
        $this->assertTrue(RelDynAttraction::interest($muiri)['drawn']);
        $this->assertFalse(!empty($this->attraction('Muiri')['passes']), 'the matrix does not pass her yet: the old exemption would not reach her');
        $this->assertFalse(RelDynAttraction::interest($ashe)['drawn']);
        $this->assertSame(0, intval($muiri['_ick_tracker']['romantic_count']), 'her shy replies are interest returned');
        $this->assertFalse(!empty($muiri['_ick_tracker']['ick_active']));
        $this->assertNotContains('ick', RelDynRomance::blockingStates($muiri));
        $this->assertGreaterThan(2, intval($ashe['_ick_tracker']['romantic_count']), 'the same hugs, no interest: pressure');
        $this->assertTrue(!empty($ashe['_ick_tracker']['ick_active']), 'Ashe has the Ick');
        $this->assertContains('ick', RelDynRomance::blockingStates($ashe));
        $this->assertClean();
    }

    /**
     * The same six shy hugs with the hidden-interest settings back at the old rule (no mood counts as interest returned, no
     * threshold lift): the one who is drawn is pushed too far like a stranger, and the Ick shuts the romance gate. It is
     * the ruling that keeps her out of it.
     */
    public function testWithoutHiddenInterestTheSameShyRepliesAreIckedAsBefore(): void
    {
        $protocols = RelDynProtocols::configDefaults();
        $protocols['ick']['hidden_interest_moods'] = [];
        $protocols['ick']['hidden_interest_threshold_gain'] = 0.0;
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'internal_weather_enabled' => false, 'protocols' => $protocols]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seed(40);
        $this->friends(40);
        $this->thane();
        $t = $this->meet();
        $this->setBeds(function (array &$d): void {
            $d['dimensions']['comfort']['x'] = 30.0;
            RelationshipDynamics::setPassion($d, 5.0);
        }, ['Muiri']);
        $this->mood = 'shy';
        for ($i = 0; $i < 6; $i++) {
            $this->turn('Muiri', 'Come here, let me hold you.', $t + 600 * $i, 'hug' . $i);
            $this->worker();
        }
        $muiri = $this->dynamics('Muiri');
        $this->assertTrue(RelDynAttraction::interest($muiri)['drawn'], 'she is just as drawn');
        $this->assertGreaterThan(2, intval($muiri['_ick_tracker']['romantic_count']), 'her shy replies count as pushing under the old rule');
        $this->assertTrue(!empty($muiri['_ick_tracker']['ick_active']));
        $this->assertContains('ick', RelDynRomance::blockingStates($muiri));
        $this->assertClean();
    }

    /**
     * attraction x romance (decisions §20.3: hidden interest counts, "like moving the romance forward"). Three evenings
     * of "I missed you" with each of the four, friends at a close bond, the eval seeing a warm, significant moment and no
     * passion in anything she showed. Lynly (hand-set to her lowest confidence, as the committed read does not give her
     * one) is drawn and too shy to show it: her evenings move the romance in proportion to the pull she hides; Aela, who
     * shows what she feels, has nothing hidden and moves it by the passion the eval saw alone, so none here.
     */
    public function testEveningsWithAShyOneWhoIsDrawnMoveTheRomanceAndWithAnOpenOneTheyDoNot(): void
    {
        $this->seed(60);
        $this->friends(60);
        $this->warrior();
        $t = $this->meet();
        $this->setBeds(function (array &$d): void {
            $d['dimensions']['self_confidence']['x'] = 20.0;
            $d['dimensions']['self_confidence']['baseline'] = 20.0;
        }, [self::LYNLY]);
        for ($i = 0; $i < 3; $i++) $t = $this->round('I missed you. Stay a while.', $t + 600, 'eve' . $i);
        $rows = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $rows[$npc] = ['momentum' => floatval($d['_romance']['momentum'] ?? 0.0), 'interest' => RelDynAttraction::interest($d),
                'shyness' => RelDynAttraction::shyness($d), 'hidden' => RelDynRomance::hiddenShare($d),
                'moments' => preg_match_all('/\[ROMANCE\] ' . preg_quote($npc, '/') . ': moment \+([0-9.]+)[^\n]*unvoiced/', $this->log(), $m) ? $m[1] : []];
        }
        $this->probe('romance', $rows);
        $this->assertTrue($rows[self::LYNLY]['interest']['drawn'], 'Lynly is drawn');
        $this->assertGreaterThan(0.8, $rows[self::LYNLY]['shyness']);
        $this->assertGreaterThan(0.0, $rows[self::LYNLY]['hidden']);
        $this->assertCount(3, $rows[self::LYNLY]['moments'], 'each evening moved her romance, by the pull she hides');
        foreach ($rows[self::LYNLY]['moments'] as $w) $this->assertEqualsWithDelta(0.6 * $rows[self::LYNLY]['hidden'], floatval($w), 0.05);
        $this->assertGreaterThan(0.0, $rows[self::LYNLY]['momentum']);
        $this->assertSame(0.0, $rows[self::AELA]['shyness'], 'Aela shows what she feels');
        $this->assertSame([], $rows[self::AELA]['moments'], 'nothing hidden, nothing credited: only the passion the eval saw counts');
        $this->assertSame(0.0, $rows[self::AELA]['momentum']);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ §20.3 keeping

    /**
     * attachment x jealousy x absence (decisions §20.3). The four at core 75, comfort and trust 70, two game days
     * since they last spoke, each jealous (70). Everyone fears losing him a little, by who she is: the toxic
     * Muiri most, then Ashe (avoidant), the secure ones (Aela, Lynly) least. Only the strongest says anything
     * (felt text, no numbers); the grip costs the bond where it is strongest (her trust and comfort held down by a
     * standing offset), a day of calm later lifts it.
     */
    public function testTheFearOfLosingHimIsWhoSheIsAndCostsTheBondWhereItIsStrongest(): void
    {
        $this->seed(75);
        $this->friends(75);
        $this->warrior();
        $t = $this->meet();
        $beds = array_keys(self::BEDS);
        $this->setBeds(function (array &$d): void {
            foreach (['comfort', 'trust'] as $k) {
                $d['dimensions'][$k]['x'] = 70.0;
                $d['dimensions'][$k]['baseline'] = 70.0;
            }
            $d['dimensions']['jealousy']['x'] = 70.0;
        });
        $t2 = self::at(self::N0 + 2, 18.0);
        foreach ($beds as $i => $npc) $this->turn($npc, 'I am back.', $t2 + 600 * $i, 'back');
        $this->worker();
        $fear = $held = $line = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $fear[$npc] = RelDynKeeping::fear($d);
            $held[$npc] = floatval($d[RelDynKeeping::KEY]['applied']['trust'] ?? 0.0);
            foreach (['keeping_uneasy', 'keeping_clinging', 'keeping_controlling'] as $k) {
                if (isset($this->felt[$npc]['back'][$k])) $line[$npc] = [$k, $this->felt[$npc]['back'][$k]];
            }
        }
        $this->probe('keeping', compact('fear', 'held', 'line'));
        foreach ($beds as $npc) $this->assertGreaterThan(0.0, $fear[$npc], "{$npc}: nobody is without the fear, never a hard switch");
        $this->assertGreaterThan($fear['Ashe'], $fear['Muiri'], 'the toxic one most');
        $this->assertGreaterThan($fear[self::AELA], $fear['Ashe']);
        $this->assertGreaterThan($fear[self::LYNLY], $fear['Ashe']);
        $this->assertGreaterThan(2.0 * $fear[self::AELA], $fear['Muiri'], 'meaningfully apart');
        $this->assertArrayHasKey('Muiri', $line, 'she says it');
        $this->assertArrayNotHasKey(self::AELA, $line, 'a secure partner says nothing about it');
        $this->assertArrayNotHasKey(self::LYNLY, $line);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line['Muiri'][1]);
        $this->assertStringContainsString('Muiri', $line['Muiri'][1]);
        $this->assertLessThan(0.0, $held['Muiri'], 'the grip holds her trust down: to its detriment');
        $this->assertSame(0.0, $held[self::AELA]);
        $this->assertLessThan(70.0, floatval($this->dynamics('Muiri')['dimensions']['trust']['x']));
        // Jev gets the numbers: the fear, what it is made of, what it holds down; and her interest, shown or not
        $jev = RelDynJev::state('Muiri', $this->dynamics('Muiri'), RelationshipDynamics::currentGamets());
        $this->assertSame('clinging', $jev['keeping']['band']);
        $this->assertEqualsWithDelta($fear['Muiri'], $jev['keeping']['fear'], 1e-3);
        $this->assertLessThan(0.0, $jev['keeping']['held']['trust']);
        $this->assertStringContainsString('keeping=', $jev['text'], 'and the compact line carries it while she fears');
        $this->assertMatchesRegularExpression('/keeping=0\.\d\d\(clinging \w+\/\w+\) held trust -[0-9.]+/', $jev['text']);
        $this->assertArrayHasKey('interest', $jev['attraction']);
        $this->assertArrayHasKey('shyness', $jev['attraction']);
        $this->assertArrayHasKey('spike_open', $jev['attraction']);
        // a day of calm: he is here, no rival; the fear eases and what it held is lifted
        $this->setBeds(function (array &$d): void { $d['dimensions']['jealousy']['x'] = 0.0; });
        $t3 = self::at(self::N0 + 3, 18.0);
        foreach ($beds as $i => $npc) $this->turn($npc, 'Good to be home.', $t3 + 600 * $i, 'calm');
        $this->worker();
        $after = RelDynKeeping::fear($this->dynamics('Muiri'));
        $this->assertLessThan($fear['Muiri'], $after, 'it eases with him here');
        $this->assertGreaterThan($held['Muiri'], floatval($this->dynamics('Muiri')[RelDynKeeping::KEY]['applied']['trust']), 'what it held is lifted as it fades');
        $this->assertClean();
    }

    // ------------------------------------------------------------------ §20.4 the fight

    /**
     * combat x fulfillment (decisions §20.4). A fight beside the player is time together for each of them, by how much
     * she likes fighting: Aela (combat high) nearly a full half unit, Muiri (does not care for it) about
     * a quarter, and Ashe, made the scholar who dreads it (the editor's facet overrides), a sliver.
     */
    public function testFightingSideBySideIsTimeTogetherByHerTaste(): void
    {
        $this->seed(60);
        $this->friends(60);
        $this->warrior();
        $t = $this->meet();
        $this->setBeds(function (array &$d, string $npc): void {
            RelDynFacets::setPreferenceOverride($d, 'combat', -0.7);
            RelDynFacets::setPreferenceOverride($d, 'adventure', -0.4);
            RelDynFacets::setPreferenceOverride($d, 'danger', -0.6);
        }, ['Ashe']);
        $t1 = self::at(self::N0 + 1, 12.0);
        $beds = array_keys(self::BEDS);
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $t1 + 30000, self::AELA);
        $got = $weight = [];
        foreach ($beds as $npc) {
            $this->assertSame(1, preg_match('/Shared fight is time together: ' . preg_quote($npc, '/') . ' weight ([0-9.]+) \(\{"quality_time":([0-9.]+)\}\)/', $this->log(), $m), "{$npc}: the fight was time together");
            [$weight[$npc], $got[$npc]] = [floatval($m[1]), floatval($m[2])];
        }
        $this->probe('fight', compact('weight', 'got'));
        foreach ($beds as $npc) $this->assertEqualsWithDelta(0.5 * $weight[$npc], $got[$npc], 6e-3, $npc);   // (the log rounds the weight to two places)
        $this->assertGreaterThan(0.75, $weight[self::AELA], 'Aela likes a fight');
        $this->assertLessThan(0.3, $weight['Ashe'], 'the scholar who dreads it');
        $this->assertLessThan(0.45, $weight['Muiri']);
        $this->assertGreaterThan(2.0 * $got['Ashe'], $got[self::AELA], 'not everyone\'s favourite activity');
        $this->assertGreaterThan($got['Muiri'], $got[self::AELA]);
        $this->assertGreaterThan($got['Ashe'], $got['Muiri']);
        // and it did go into her levels
        $d = $this->dynamics(self::AELA);
        $this->assertGreaterThan(1.5, floatval(RelDynFulfillment::pairState($d)['lv'][RelationshipDynamics::LL_TIME]), 'above where she started, the fight made up more than a day of decay');
        $this->assertClean();
    }
}
