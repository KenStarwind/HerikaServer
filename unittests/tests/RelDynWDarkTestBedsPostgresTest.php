<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynWDarkPgDb
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
 * Rulings 2026-10-01 §24 (Ken), batch W, the dark path and the extended bond kinds, end to end with the four test beds
 * (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector, never read: nothing of that story
 * anywhere), Muiri (toxic) and Lynly Star-Sung (a bard; the bio does not establish shyness, so none is forced on Lynly), on CHIM
 * 3.4.1 core-shaped rows and the committed seed's reads, through the real hooks as main.php runs them (prerequest -> core's action
 * list with the ext functions.php -> context_pre -> context -> postrequest), the real eval producer and worker (LLM stubbed at
 * the connector boundary). No LLM call; feelings, never numbers, in front of the LLM; Jev gets the numbers. Gender: every line
 * asserted on is free of a hard-coded pronoun (the names and the NPC's own vars only).
 *   the dark path  the same fall of trust: who walks ("I deserve better"), who leans, who drifts, by who each one is
 *   the fork       one ending, three destinations (ex, conflicted, friends), core's type written under its own names
 *   the way back   a conflicted bond starts again through exchanges that mean something, never through time
 *   the straying   weeks of neglect and a suitor: who drifts, who looks, who strays, who confesses, who leaves
 *   the oath       a sworn NPC serves whatever they feel, until the oath strains and breaks
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynWDarkTestBedsPostgresTest extends TestCase
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
    private RelDynWDarkPgDb $db;
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
    /** npc => label => the action list core would offer the LLM after the ext functions hook (what autonomy left of it) */
    private array $actions = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_wdark_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynWDarkPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdwdarkbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_wdark_beds_test.log');
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
            if ($hook === 'functions.php') $this->actions[$npc][$label] = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
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
     *   "lied to you": caught in a lie (trust, comfort, respect and affinity down, a grievance);
     *   "so sorry": an apology (warm, trust up a little);
     *   "forgive": the player forgives the NPC (warm);
     *   "missed you": a meaningful evening together (quality time);
     *   "hold you": an embrace (touch, a little passion);
     *   anything else: small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "\nTASK:", $from) - $from));
            $lie = str_contains($exchange, 'lied to you');
            $sorry = str_contains($exchange, 'so sorry');
            $forgive = str_contains($exchange, 'forgive');
            $hug = str_contains($exchange, 'hold you');
            $warm = str_contains($exchange, 'missed you');
            $good = $sorry || $forgive || $warm;
            $tags = $lie ? ['lie'] : ($sorry ? ['apology'] : ($forgive ? ['forgiveness'] : ($hug ? ['touch'] : ($warm ? ['quality_time'] : []))));
            return json_encode([
                'signals' => ['affinity' => $lie ? -3 : ($good ? 2 : 0), 'trust' => $lie ? -28 : ($good ? 2 : 0), 'comfort' => $lie ? -8 : ($good ? 2 : 0),
                              'respect' => $lie ? -8 : 0, 'passion' => $hug ? 2 : 0, 'maturity' => 0],
                'tags' => $tags,
                'grievance' => $lie ? ['flag' => true, 'kind' => 'lie', 'severity' => 2] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $lie ? 0.8 : ($good ? 0.6 : ($hug ? 0.3 : 0.1)),
                'summary' => $lie ? 'The player was caught in a lie.' : ($sorry ? 'The player said how sorry they were.'
                    : ($forgive ? 'The player said they forgave the NPC.' : ($hug ? 'The player held the NPC.'
                        : ($warm ? 'The player said they missed the NPC and stayed a while.' : 'Small talk.')))),
                'romantic_intent' => $hug ? 3 : 0,
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
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: their own vector");
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
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode((string) $r['extended_data'], true) ?: [];
        $keys = [];
        foreach (array_keys((array) ($ext['relationships'] ?? [])) as $k) {
            if ($k === self::PLAYER || $k === RelationshipDynamics::PLAYER_RELATIONSHIP_KEY) $keys[] = $k;   // RelDyn's first write may have re-keyed the player's bond
        }
        foreach ($keys ?: [self::PLAYER] as $k) {
            $ext['relationships'][$k] = array_replace((array) ($ext['relationships'][$k] ?? []), ['aff' => $aff, 'type' => $type]);
        }
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
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

    /** The romantic level the last compose left in the short band (impulse points). */
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

    /** The first evening with the player, everyone home. */
    private function meet(): int
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 17.9));
        return $this->round('Well met, love.', self::at(self::N0, 18.0), 'hello');
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private function setBeds(callable $edit, array $only = []): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            if ($only !== [] && !in_array($npc, $only, true)) continue;
            $this->editDynamics($npc, function (array &$d) use ($edit, $npc): void { $edit($d, $npc); });
        }
    }

    /**
     * A lived-in romance, as the editor would set it: trust and comfort up, passion up, a friend once. The evening after the first
     * meeting, so that every bed's own state has met the new numbers (the dark path's peak of trust, the bond kinds' look at core).
     */
    private function establish(float $passion = 50.0): int
    {
        $this->seed(70);
        $this->meet();
        $this->setBeds(function (array &$d) use ($passion): void {
            foreach (['trust' => 72.0, 'comfort' => 72.0, 'respect' => 65.0, 'resentment' => 3.0] as $k => $v) $d['dimensions'][$k]['x'] = $v;
            RelationshipDynamics::setPassion($d, $passion);
            $d['context_tier_hwm'] = 3;
        });
        return $this->round('Good to see you again.', self::at(self::N0 + 1, 18.0), 'day1');
    }

    /** One evening a day from game day N0 + $from to N0 + $to (inclusive), the same line to every bed; returns the next free gamets. */
    private function evenings(int $from, int $to, string $line, string $label = 'evening'): int
    {
        $t = self::at(self::N0 + $from, 18.0);
        for ($day = $from; $day <= $to; $day++) {
            $t = $this->round($line, self::at(self::N0 + $day, 18.0), "{$label}{$day}");
        }
        return $t;
    }

    /** A dimension of a bed as RelDyn stores it. */
    private function dim(string $npc, string $dim): float
    {
        return self::x($this->dynamics($npc), $dim);
    }

    /** The kinds and the state a bed shows now. */
    private function state(string $npc): array
    {
        $d = $this->dynamics($npc);
        $now = self::at(self::N0 + 40, 0.0);
        return [
            'core' => $d['_core_rel_type'] ?? null,
            'trust' => round(self::x($d, 'trust'), 1), 'maturity' => round(self::x($d, 'maturity'), 1), 'passion' => round(RelationshipDynamics::getPassion($d), 1),
            'walk' => $d['_walkaway_state'] ?? 'normal', 'why' => $d['_walkaway_reason'] ?? null,
            'deserve' => round(floatval($d['_dark']['deserve'] ?? 0), 2), 'peak' => round(floatval($d['_dark']['trust_peak'] ?? 0), 1),
            'kinds' => RelDynBonds::kinds($d), 'breakup' => isset($d['_bonds']['breakup']) ? $d['_bonds']['breakup']['fork'] . '/' . $d['_bonds']['breakup']['cause'] : null,
            'override' => $d['_relationship_type_override'] ?? null,
            'aff' => round(RelationshipDynamics::getCoreAffinity($d), 1), 'resent' => round(self::x($d, 'resentment'), 1), 'fear' => round(RelDynKeeping::fear($d), 2),
            'walk_at' => RelDynDark::walkAt($d), 'degree' => RelDynDark::deserveDegree($d), 'adrift' => round(floatval($d['_dark']['adrift'] ?? 0), 2),
            'pullback' => !empty($d['_pullback']['active']) ? 'on:' . ($d['_pullback']['cause'] ?? '-') : 'off', 'land' => round(RelDynDark::trustLanding($d), 2),
        ];
    }

    /** The type core holds for the player now. */
    private function coreType(string $npc): ?string
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode((string) $r['extended_data'], true) ?: []);
        return isset($rel['type']) ? (string) $rel['type'] : null;
    }

    /**
     * The suitor's line to $npc (logged as core logs an NPC line), then the rechat turn through the real hooks (core resolves the rechat's
     * previous speaker after the prerequest hooks, before the context hooks). Returns the exclusivity block the context hook put in front
     * of the LLM (the <subtext> "with Mikael"), or null.
     */
    private function flirt(string $npc, string $line, int $gamets): ?string
    {
        $this->event('chat', self::SUITOR . ": {$line} (talking to {$npc})", $gamets, 'emitted');
        $request = ['rechat', (string) $this->realTs, (string) ($gamets + 20), json_encode(['speaker' => self::SUITOR, 'listener_hint' => $npc])];
        $block = null;
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::SUITOR . ')', $gamets + 20, 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people ?? $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::SUITOR;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook !== 'prerequest.php') $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = self::SUITOR;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                foreach ((array) $GLOBALS['contextDataFull'] as $m) {
                    if (str_contains((string) ($m['content'] ?? ''), 'right now, with ' . self::SUITOR)) $block = (string) $m['content'];
                }
            }
            RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
        return $block;
    }

    // ------------------------------------------------------------------ probes

    /** The turn labels at which $npc's felt text carried a line whose key starts with $prefix. */
    private function saidAt(string $npc, string $prefix): array
    {
        $out = [];
        foreach ((array) ($this->felt[$npc] ?? []) as $label => $lines) {
            foreach ((array) $lines as $key => $text) {
                if (str_starts_with((string) $key, $prefix)) { $out[] = (string) $label; break; }
            }
        }
        return $out;
    }

    /** Whether the action list core would offer $npc's LLM at turn $label still holds $action. */
    private function offers(string $npc, string $label, string $action): bool
    {
        return in_array($action, (array) ($this->actions[$npc][$label] ?? []), true);
    }

    // ------------------------------------------------------------------ the dark path

    /**
     * The same three lies to four people, over three evenings (Muiri set immature by the editor: the seeded read is of a grown adult).
     * What each does with it is who each is:
     *   Aela    a mature NPC whose trust fell and stayed fallen deserves better and walks, through the walkaway system (reason 'deserve'),
     *           and a romance that is walked out of ends through the fork, said once to the player's face;
     *   Lynly   the same, a day later;
     *   Ashe    guarded: the trust fell far, but a guarded NPC's normal is low, so the thought builds and Ashe closes off (the
     *           pull-back, on standards) and says so; Ashe does not go;
     *   Muiri   immature and trusting: the first lie lands at a share of itself (a blind trust), the dark path is not theirs
     *           (no 'deserve'), and a people-pleaser stays compliant with every command on the list.
     */
    public function testTheSameLiesFourPeopleTwoLeaveOneStaysClosedOffAndOneTrustsBlindly(): void
    {
        $this->establish();
        $this->editDynamics('Muiri', function (array &$d): void { $d['dimensions']['maturity']['x'] = 24.0; });
        $before = [];
        foreach (array_keys(self::BEDS) as $npc) $before[$npc] = $this->dim($npc, 'trust');
        $this->round('I lied to you.', self::at(self::N0 + 2, 18.0), 'lie2');
        $lost = [];
        foreach (array_keys(self::BEDS) as $npc) $lost[$npc] = $before[$npc] - $this->dim($npc, 'trust');
        $this->assertGreaterThan(15.0, $lost[self::AELA], 'a lie costs a mature NPC a good part of the trust');
        $this->assertLessThan(0.5 * $lost[self::AELA], $lost['Muiri'], 'trusts blindly: the hit lands as a share of itself');
        $this->assertGreaterThan(3.0, $lost['Muiri'], 'but hurt: never nothing');
        $this->assertNotEmpty($this->saidAt('Muiri', 'dark_codependent'), 'and the NPC leans on the player, in words');
        $this->assertSame([], $this->saidAt(self::AELA, 'dark_codependent'));
        $this->round('I lied to you.', self::at(self::N0 + 3, 18.0), 'lie3');
        $this->round('I lied to you.', self::at(self::N0 + 4, 18.0), 'lie4');
        $why = [];
        for ($day = 5; $day <= 10; $day++) {
            foreach ([self::AELA, 'Ashe', self::LYNLY] as $i => $npc) {
                if (isset($why[$npc])) continue;   // the player lets them go
                $this->turn($npc, 'Nice weather.', self::at(self::N0 + $day, 18.0) + 600 * $i, "d{$day}");
                $d = $this->dynamics($npc);
                if (($d['_walkaway_state'] ?? 'normal') !== 'normal') $why[$npc] = [$d['_walkaway_reason'] ?? null, $d['_walkaway_state'], $day];
            }
            $this->worker();
        }
        $ashe = $this->dynamics('Ashe');
        $muiri = $this->dynamics('Muiri');
        foreach ([self::AELA, self::LYNLY] as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('deserve', $why[$npc][0] ?? null, "{$npc}: leaves because they deserve better");
            $this->assertContains($why[$npc][1], ['pending', 'active'], "{$npc}: the existing walkaway machinery");
            $this->assertSame('ex', $this->coreType($npc), "{$npc}: core holds the type it knows");
            $this->assertSame('standards', $d['_bonds']['breakup']['cause'], $npc);
            $this->assertCount(1, $this->saidAt($npc, 'bonds_breakup'), "{$npc}: said once, to the player's face");
            $this->assertNotEmpty($this->saidAt($npc, 'dark_deserve'), "{$npc}: and the thought was said as it grew");
        }
        // the leaving takes the follow actions off the list, as every walkaway does
        $leftAt = $this->saidAt(self::AELA, 'bonds_breakup')[0];
        $this->assertFalse($this->offers(self::AELA, $leftAt, 'FollowPlayer'), 'a walkaway takes the follow actions off the list');
        // Ashe: the same lies, a different NPC
        $this->assertArrayNotHasKey('Ashe', $why, 'Ashe does not leave');
        $this->assertLessThan($why[self::LYNLY][2] + 1, $why[self::AELA][2] + 1);
        $this->assertGreaterThan(0.5, floatval($ashe['_dark']['deserve']));
        $this->assertLessThan(RelDynDark::walkAt($ashe), floatval($ashe['_dark']['deserve']), 'the thought stops short of their own line');
        $this->assertSame('standards', RelDynPullback::cause($ashe), 'closes off, on standards');
        $this->assertNotEmpty($this->saidAt('Ashe', 'dark_deserve'));
        $this->assertSame([], $this->saidAt('Ashe', 'bonds_breakup'));
        $this->assertSame('romantic', $this->coreType('Ashe'));
        // Muiri: immature and a people-pleaser: no 'deserve', no refusal, no ending of their own
        $this->assertLessThan(0.05, floatval($muiri['_dark']['deserve'] ?? 0));
        $this->assertSame('normal', $muiri['_walkaway_state'] ?? 'normal', 'a people-pleaser complies');
        $this->assertGreaterThan(90.0, self::x($muiri, 'resentment'), 'and swallows it');
        $this->assertTrue($this->offers('Muiri', 'lie4', 'FollowPlayer'), 'nothing is taken off the list');
        $this->assertSame('romantic', $this->coreType('Muiri'));
        $this->assertSame([], $this->saidAt('Muiri', 'bonds_breakup'));
        $this->assertClean();
    }

    /**
     * One day, one set of circumstances (trust fell to a fraction of its peak, the thought of deserving better whole, passion 50): four
     * NPCs leave together, and the fork sends them to two places by who they are. Core's UI sees 'ex' for all four (the only type it
     * knows for a former partner); RelDyn knows which of them is still carrying a torch. The ending is said once, in each NPC's own way,
     * with its cause in the words.
     */
    public function testOneEndingForkedByWhoTheyAreWhileCoreSeesTheOnlyTypeItKnows(): void
    {
        $this->establish();
        $this->setBeds(function (array &$d): void {
            $d['dimensions']['trust']['x'] = 25.0;
            $d['_dark']['deserve'] = 0.97;
        });
        $this->round('Nice weather.', self::at(self::N0 + 2, 18.0), 'leave');
        $forks = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('deserve', $d['_walkaway_reason'], $npc);
            $this->assertSame('ex', $this->coreType($npc), "{$npc}: core's type is core's");
            $forks[$npc] = $d['_bonds']['breakup']['fork'];
        }
        $this->assertSame('ex', $forks['Ashe'], 'guarded and leaning avoidant: cuts clean');
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $npc) $this->assertSame('conflicted', $forks[$npc], "{$npc}: the longing is still there");
        $this->assertSame(['conflicted'], RelDynBonds::kinds($this->dynamics(self::AELA)));
        $this->assertSame([], RelDynBonds::kinds($this->dynamics('Ashe')));
        $this->assertSame('conflicted', RelationshipDynamics::getRelationshipType(self::AELA, $this->dynamics(self::AELA)));
        $this->assertLessThan(0.8 * 50.0, RelationshipDynamics::getPassion($this->dynamics('Ashe')), 'the hard end takes a share of the passion');
        $this->assertGreaterThan(0.9 * 50.0, RelationshipDynamics::getPassion($this->dynamics(self::AELA)), 'the soft one keeps it');
        // said once, each in a way, with the cause
        $texts = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertSame(['leave'], $this->saidAt($npc, 'bonds_breakup'), "{$npc}: said at once, once");
            foreach ($this->felt[$npc]['leave'] as $key => $text) {
                if (str_starts_with((string) $key, 'bonds_breakup')) $texts[$npc] = [$key, (string) $text];
            }
            $this->assertStringContainsString($npc, $texts[$npc][1]);
            $this->assertStringContainsString('deserves better', $texts[$npc][1]);
        }
        $this->assertSame('bonds_breakup_ex', $texts['Ashe'][0]);
        $this->assertSame('bonds_breakup_conflicted', $texts[self::AELA][0]);
        $this->assertGreaterThanOrEqual(3, count(array_unique(array_column($texts, 1))), 'in their own ways');
        // and the standing line afterwards: the soft state and the hard one
        $this->turn(self::AELA, 'Nice weather.', self::at(self::N0 + 3, 18.0), 'after');
        $this->turn('Ashe', 'Nice weather.', self::at(self::N0 + 3, 18.0) + 600, 'after');
        $this->worker();
        $this->assertNotEmpty($this->saidAt(self::AELA, 'bonds_conflicted'));
        $this->assertNotEmpty($this->saidAt('Ashe', 'bonds_ex'));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the way back

    /**
     * Three of the four leave as the soft state. Left alone for days and then a week, nothing changes (time does not heal). Then, a few
     * exchanges a day that mean something (an apology, a missed friend, a forgiveness), and the way back builds, at the pace each one's
     * trust returns: Lynly first, Muiri after, Aela last. At full progress, with the anger gone and trust standing, core goes ex -> crush
     * (and the normal romance ladder takes it from there), said once.
     */
    public function testAConflictedBondStartsAgainThroughExchangesThatMeanSomethingNeverThroughTime(): void
    {
        $this->establish();
        $this->setBeds(function (array &$d): void {
            $d['dimensions']['trust']['x'] = 25.0;
            $d['_dark']['deserve'] = 0.97;
        });
        $this->round('Nice weather.', self::at(self::N0 + 2, 18.0), 'leave');
        $soft = [self::AELA, 'Muiri', self::LYNLY];
        foreach ($soft as $npc) $this->assertSame(['conflicted'], RelDynBonds::kinds($this->dynamics($npc)), $npc);
        // alone: three days (only Ashe is spoken to, so the calendar scan returns the others) and then a week
        for ($day = 3; $day <= 5; $day++) {
            $this->turn('Ashe', 'Nice weather.', self::at(self::N0 + $day, 18.0), "alone{$day}");
            $this->worker();
        }
        foreach ($soft as $npc) $this->assertSame('normal', $this->dynamics($npc)['_walkaway_state'] ?? 'normal', "{$npc}: back, the boundary test over");
        $this->turn('Ashe', 'Nice weather.', self::at(self::N0 + 12, 18.0), 'week');
        $this->worker();
        foreach ($soft as $npc) {
            $this->assertEqualsWithDelta(0.0, floatval($this->dynamics($npc)['_bonds']['breakup']['rekindle']), 1e-9, "{$npc}: a week of silence heals nothing");
            $this->assertSame('ex', $this->coreType($npc));
        }
        // exchanges that mean something, two a day
        $lines = ['I am so sorry.', 'I missed you.', 'I forgive you.', 'I am so sorry.'];
        $rekindledOn = [];
        $progress = array_fill_keys($soft, []);
        for ($day = 13; $day <= 30; $day++) {
            for ($k = 0; $k < 2; $k++) {
                foreach ($soft as $i => $npc) {
                    if (isset($rekindledOn[$npc])) continue;
                    $this->turn($npc, $lines[($day + $k) % 4], self::at(self::N0 + $day, 17.0 + 2 * $k) + 600 * $i, "back{$day}" . ($k ? 'b' : 'a'));
                }
                $this->worker();
            }
            foreach ($soft as $npc) {
                $d = $this->dynamics($npc);
                $progress[$npc][$day] = floatval($d['_bonds']['breakup']['rekindle']);
                if (!isset($rekindledOn[$npc]) && ($d['_bonds']['breakup']['status'] ?? '') === 'rekindled') $rekindledOn[$npc] = $day;
            }
            if (count($rekindledOn) === count($soft)) break;
        }
        foreach ($soft as $npc) {
            $prev = 0.0;
            foreach ($progress[$npc] as $day => $p) {
                $this->assertGreaterThanOrEqual($prev - 1e-9, $p, "{$npc}: the way back only grows with warm exchanges");
                $this->assertLessThanOrEqual($prev + 0.3 + 1e-9, $p, "{$npc}: at most a day's cap");
                $prev = $p;
            }
        }
        $this->assertCount(3, $rekindledOn, 'all three started again: ' . json_encode($rekindledOn));
        $this->assertLessThanOrEqual($rekindledOn['Muiri'], $rekindledOn[self::LYNLY], 'whose trust returns first');
        $this->assertLessThanOrEqual($rekindledOn[self::AELA], $rekindledOn['Muiri']);
        $this->assertGreaterThan($rekindledOn[self::LYNLY], $rekindledOn[self::AELA], 'not all at once');
        foreach ($soft as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('crush', $this->coreType($npc), "{$npc}: a new beginning, one rung of the ladder");
            $this->assertSame(1, RelDynRomance::rung($d['_core_rel_type']));
            $this->assertSame([], RelDynBonds::kinds($d));
            $this->assertGreaterThanOrEqual(40.0, self::x($d, 'trust'), 'trust stands');
            $this->assertLessThanOrEqual(35.0, self::x($d, 'resentment'), 'the anger is gone');
            $this->assertCount(1, $this->saidAt($npc, 'bonds_rekindled'), "{$npc}: said once");
        }
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the straying

    /**
     * The player is away thirty days. Mikael is about and attentive to all four (Mikael's lines are logged as core logs an NPC's,
     * the NPC-to-NPC exchange hook records Mikael's regard). Nobody runs their own turn in an NPC-to-NPC exchange, so the loop is walked by the
     * calendar scan when the player comes back, day by day. Thirty days on: the mature and loyal-ish confess and end it (Aela, Ashe), the
     * bard leaves for Mikael, and Muiri, the fearful one, is keeping both and hiding it.
     */
    public function testWeeksOfNeglectAndASuitorWhoStrayConfessLeaveOrKeepBothByWhoTheyAre(): void
    {
        $this->establish();
        $this->people = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::SUITOR . '|';   // the player has gone; Mikael is about
        for ($day = 3; $day <= 30; $day++) {
            foreach (array_keys(self::BEDS) as $i => $npc) {
                $this->flirt($npc, 'You look lovely tonight, darling. Walk with me?', self::at(self::N0 + $day, 10.0) + 900 * $i);
            }
        }
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertGreaterThan(40.0, RelDynExclusivity::interestAt((array) RelDynExclusivity::suitor($d, self::SUITOR), self::at(self::N0 + 30, 10.0)), "{$npc}: Mikael's regard has built");
            $this->assertNull($d['_bonds']['infidelity']['stage'] ?? null, "{$npc}: nothing runs in an NPC-to-NPC exchange: the player's return walks the days");
        }
        // the player comes back
        $this->people = null;
        $t = self::at(self::N0 + 31, 18.0);
        $this->event('infoloc', self::HOME, $t - 60);
        $this->round('I am back.', $t, 'back');
        $causes = [];
        foreach (array_keys(self::BEDS) as $npc) $causes[$npc] = $this->dynamics($npc)['_bonds']['breakup']['cause'] ?? null;
        $this->assertSame('infidelity_confessed', $causes[self::AELA], 'the mature and loyal-ish confess');
        $this->assertSame('infidelity_confessed', $causes['Ashe']);
        $this->assertSame('left_for_other', $causes[self::LYNLY], 'the one whose way is to go');
        $this->assertNull($causes['Muiri'], 'Muiri has not ended it');
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame(self::SUITOR, $d['_bonds']['breakup']['with'], $npc);
            $this->assertSame('ex', $this->coreType($npc), "{$npc}: core knows 'ex'");
            $this->assertSame('ex', $d['_bonds']['breakup']['fork'], "{$npc}: a month of neglect and someone else: a hard ending");
            $this->assertNotEmpty($this->saidAt($npc, 'bonds_breakup'), "{$npc}: said to the player's face");
            $this->assertLessThan(15.0, RelationshipDynamics::getPassion($d), "{$npc}: the passion went where the attention did");
        }
        $muiri = $this->dynamics('Muiri');
        $this->assertSame('strayed', $muiri['_bonds']['infidelity']['stage'], 'the fearful keep both');
        $this->assertSame('conceal', $muiri['_bonds']['infidelity']['style']);
        $this->assertSame('romantic', $this->coreType('Muiri'));
        $this->assertNotEmpty($this->saidAt('Muiri', 'bonds_strayed_conceal'), 'and it shows in a distance, not in words about Mikael');
        // the reply to Mikael's next move is warm now (the exchange hook reads the stage the player's return set)
        $this->people = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::SUITOR . '|';
        $block = $this->flirt('Muiri', 'You look lovely tonight, darling. Walk with me?', self::at(self::N0 + 32, 10.0));
        $this->assertNotNull($block);
        $this->assertStringContainsString('closer than', $block);
        $this->assertStringContainsString('Mikael', $block);
        $this->assertDoesNotMatchRegularExpression('/\d/', $block);
        $this->assertClean();
    }

    /**
     * The player is away only a week and comes back before anyone has crossed a line: at most a drift. Then the player stays, warm evenings,
     * and the pull toward someone else eases away.
     */
    public function testAWeekAwayLeavesAtMostADriftAndBeingThereBringsItDown(): void
    {
        $this->establish();
        $this->people = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::SUITOR . '|';
        for ($day = 3; $day <= 10; $day++) {
            foreach (array_keys(self::BEDS) as $i => $npc) {
                $this->flirt($npc, 'You look lovely tonight, darling. Walk with me?', self::at(self::N0 + $day, 10.0) + 900 * $i);
            }
        }
        $this->people = null;
        $t = self::at(self::N0 + 11, 18.0);
        $this->event('infoloc', self::HOME, $t - 60);
        $this->round('I am back.', $t, 'back');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertNotSame('strayed', $d['_bonds']['infidelity']['stage'] ?? null, $npc);
            $this->assertArrayNotHasKey('breakup', $d['_bonds'] ?? [], $npc);
            $this->assertSame('romantic', $this->coreType($npc));
        }
        $this->assertNotNull($this->dynamics(self::AELA)['_bonds']['infidelity']['stage'] ?? null, 'a week of it is felt');
        // the player stays: warm evenings, and the loop eases
        $before = [];
        foreach (array_keys(self::BEDS) as $npc) $before[$npc] = floatval($this->dynamics($npc)['_bonds']['infidelity']['pressure'] ?? 0.0);
        for ($day = 12; $day <= 18; $day++) $this->round('I missed you.', self::at(self::N0 + $day, 18.0), "stay{$day}");
        foreach (array_keys(self::BEDS) as $npc) {
            $inf = $this->dynamics($npc)['_bonds']['infidelity'] ?? [];
            $this->assertLessThan(max(0.3, $before[$npc]), floatval($inf['pressure'] ?? 0.0), "{$npc}: being there brings it down");
            $this->assertNull($inf['stage'] ?? null, "{$npc}: no pull toward someone else once the player is back");
        }
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the oath

    /**
     * Aela is sworn (core's own 'servant' type: the oath itself) and Lynly is not; both are treated the same, badly. Without the oath
     * Lynly refuses; with it Aela serves, reluctantly, whatever Aela feels, and the follow actions stay on the list. Respect lost, the oath
     * strains and breaks, and then Aela refuses like anyone: said once. Respect back, it is taken up again.
     */
    public function testASwornNpcServesWhateverTheyFeelUntilTheOathBreaksAndMends(): void
    {
        $this->establish();
        $bad = function (array &$d): void {
            foreach (['trust' => 12.0, 'respect' => 45.0, 'resentment' => 80.0, 'comfort' => 40.0] as $k => $v) $d['dimensions'][$k]['x'] = $v;
            $d['_dark']['deserve'] = 0.0;
        };
        $this->setBeds($bad, [self::AELA, self::LYNLY]);
        $this->setCore(self::AELA, 20, 'servant');
        $this->setCore(self::LYNLY, 20, 'neutral');
        $this->round('Nice weather.', self::at(self::N0 + 2, 18.0), 'sworn');
        $this->assertSame(['sworn'], RelDynBonds::kinds($this->dynamics(self::AELA)));
        $this->assertSame('sworn', RelationshipDynamics::getRelationshipType(self::AELA, $this->dynamics(self::AELA)));
        $this->assertSame([], RelDynBonds::kinds($this->dynamics(self::LYNLY)));
        $this->assertTrue($this->offers(self::AELA, 'sworn', 'FollowPlayer'), 'duty: the follow actions stay');
        $this->assertFalse($this->offers(self::LYNLY, 'sworn', 'FollowPlayer'), 'without the oath, the same treatment is refused');
        $this->assertNotEmpty($this->saidAt(self::AELA, 'bonds_sworn_cold'), 'duty, coolly');
        $this->assertSame('normal', $this->dynamics(self::AELA)['_walkaway_state'] ?? 'normal');
        // respect gone: the strain breaks the oath
        $this->setBeds(function (array &$d): void { $d['dimensions']['respect']['x'] = 3.0; }, [self::AELA]);
        $this->round('Nice weather.', self::at(self::N0 + 3, 18.0), 'broken');
        $this->assertFalse(RelDynBonds::dutyHolds($this->dynamics(self::AELA)));
        $this->assertCount(1, $this->saidAt(self::AELA, 'bonds_oath_broken'));
        $this->assertFalse($this->offers(self::AELA, 'broken', 'FollowPlayer'), 'forsworn: the cap is gone');
        // respect back: it mends
        $this->setBeds(function (array &$d): void { $d['dimensions']['respect']['x'] = 72.0; $d['dimensions']['resentment']['x'] = 5.0; }, [self::AELA]);
        $this->round('Nice weather.', self::at(self::N0 + 4, 18.0), 'mended');
        $this->assertTrue(RelDynBonds::dutyHolds($this->dynamics(self::AELA)));
        $this->assertNotEmpty($this->saidAt(self::AELA, 'bonds_oath_renewed'));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ no floors, no trust

    /**
     * Two immature NPCs with no trust and a lot of intensity (passion 70, an affinity not deep): the same days, the same warmth from the
     * player. Both settle into the transactional bond (the parasite overlay the protocols already have, held by the dark path's own
     * corner, with its own words, whatever is left of the passion the overlay burns off); then weeks of positive treatment: Lynly grows,
     * trust comes back, the floors come on and the overlay lifts;
     * Muiri, in the fearful region, cannot grow past a floor of 30 without an intervention, and stays where Muiri is.
     */
    public function testNoFloorsAndNoTrustSettleIntoATransactionalBondAndPositiveTreatmentLimitsItForSomeAndNotForTheFearful(): void
    {
        $this->establish(70.0);
        foreach (['Muiri', self::LYNLY] as $npc) {
            $this->editDynamics($npc, function (array &$d): void {
                foreach (['maturity' => 18.0, 'trust' => 8.0, 'comfort' => 40.0] as $k => $v) $d['dimensions'][$k]['x'] = $v;
                RelationshipDynamics::setPassion($d, 70.0);
                $d['_dark']['trust_peak'] = 8.0;
            });
            $this->setCore($npc, 15, 'platonic');
        }
        for ($day = 2; $day <= 7; $day++) {
            foreach (['Muiri', self::LYNLY] as $i => $npc) $this->turn($npc, 'Nice weather.', self::at(self::N0 + $day, 18.0) + 600 * $i, "adrift{$day}");
            $this->worker();
        }
        foreach (['Muiri', self::LYNLY] as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('parasite', $d['_relationship_type_override'] ?? null, "{$npc}: no floors and no trust, with intensity: transactional");
            $this->assertTrue(RelDynDark::holdsParasite($d));
            $this->assertNotEmpty($this->saidAt($npc, 'dark_parasite'), "{$npc}: in the dark path's own words");
            $this->assertSame([], $this->saidAt($npc, 'dark_deserve'), 'immature: the other corner');
        }
        // positive treatment, three exchanges a day for weeks (the two are spoken to alike)
        $lines = ['I missed you.', 'I am so sorry.', 'I forgive you.'];
        $liftedOn = null;
        for ($day = 8; $day <= 40 && $liftedOn === null; $day++) {
            for ($k = 0; $k < 3; $k++) {
                foreach (['Muiri', self::LYNLY] as $i => $npc) $this->turn($npc, $lines[$k], self::at(self::N0 + $day, 15.0 + 2 * $k) + 600 * $i, "grow{$day}" . $k);
                $this->worker();
            }
            if (!RelDynDark::holdsParasite($this->dynamics(self::LYNLY))) $liftedOn = $day;
        }
        $lynly = $this->dynamics(self::LYNLY);
        $muiri = $this->dynamics('Muiri');
        $this->assertNotNull($liftedOn, 'the overlay lifted by its own pressure');
        $this->assertGreaterThan(30.0, self::x($lynly, 'maturity'), 'treated well, Lynly grew (from 18) toward the floors, and trust came back with it');
        $this->assertGreaterThan(RelDynDark::trustLine($lynly) - 10.0, self::x($lynly, 'trust'));
        $this->assertGreaterThan(RelDynDark::floorStrength(['dimensions' => ['maturity' => ['x' => 18.0]]]), RelDynDark::floorStrength($lynly), 'and a floor is coming on');
        $this->assertNull($lynly['_relationship_type_override'] ?? null);
        $this->assertLessThanOrEqual(31.0, self::x($muiri, 'maturity'), 'the fearful region\'s floor of 30 holds');
        $this->assertTrue(RelDynDark::holdsParasite($muiri), 'and no amount of warmth is the therapy quest');
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the formal rung

    /**
     * A romance becomes formal after it has held for as long as the NPC needs (the longer for one who commits slowly), with the pull,
     * trust and affinity standing. Core's type stays 'romantic'; RelDyn knows the rung; it is said once, and the days differ by who they are.
     */
    public function testARomanceBecomesFormalAfterAWhileThatDependsOnWhoTheNpcIsAndCoreStaysRomantic(): void
    {
        $this->establish(60.0);
        $needed = [];
        foreach (array_keys(self::BEDS) as $npc) $needed[$npc] = RelDynBonds::commitDays($this->dynamics($npc));
        $this->assertGreaterThan(min($needed), max($needed), 'not the same number of days for everyone');
        $on = [];
        for ($day = 2; $day <= 12; $day++) {
            $this->round('I missed you.', self::at(self::N0 + $day, 18.0), "warm{$day}");
            foreach (array_keys(self::BEDS) as $npc) {
                if (!isset($on[$npc]) && RelDynBonds::has($this->dynamics($npc), RelDynBonds::COMMITTED)) $on[$npc] = $day;
            }
        }
        $this->assertNotEmpty($on, 'warm evenings for ten days: someone is formal');
        foreach ($on as $npc => $day) {
            $d = $this->dynamics($npc);
            $this->assertGreaterThanOrEqual($needed[$npc] - 1.0, $day - 1, "{$npc}: not before the days they need");
            $this->assertSame('romantic', $this->coreType($npc), "{$npc}: core's type is untouched");
            $this->assertSame('bonded', RelationshipDynamics::getRelationshipType($npc, $d), "{$npc}: the bonded row of RelDyn's tables");
            $this->assertSame('committed', RelDynGovernors::tier($d));
            $this->assertCount(1, $this->saidAt($npc, 'bonds_committed'), "{$npc}: said once");
        }
        $this->assertClean();
    }
}
