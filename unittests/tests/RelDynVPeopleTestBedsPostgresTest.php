<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynVPeoplePgDb
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
 * Rulings 2026-10-01 §22 and §23 (Ken), the people lane, end to end with the four test beds (standing rule
 * feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector, never read: nothing of her story anywhere),
 * Muiri (toxic) and Lynly Star-Sung (a bard; the bio does not establish shyness, so none is forced on her), on CHIM 3.4.1
 * core-shaped rows and the committed seed's reads, through the real hooks as main.php runs them (prerequest -> core's action
 * list with the ext functions.php -> context_pre -> context -> postrequest), the real eval producer and worker (LLM stubbed at
 * the connector boundary). No LLM call; feelings, never numbers, in front of the LLM; Jev gets the numbers. Gender: every line
 * asserted on is free of a hard-coded pronoun (the names and the NPC's own vars only).
 *   the fight     §23: a fight beside the player is contact for the neglect and the absence and time together by the NPC's
 *                 taste, and for the one who does not enjoy it it also reads as partly unfulfilling.
 *   the morning   §22: after intimacy a fearful NPC pulls back for a while, by how fearful they are; the others do not; no shame.
 *   keeping       §23: the fear of losing the player is answered by who the NPC is (appease, a conflict, withdraw, or the
 *                 NPC's own way), never a refused command, never a verdict on the player.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynVPeopleTestBedsPostgresTest extends TestCase
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
    private RelDynVPeoplePgDb $db;
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
        $this->schema = 'reldyn_vpeople_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynVPeoplePgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdvpeoplebeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_vpeople_beds_test.log');
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
     *   "take my hand" after her fall: care (the player protects her: rescue, reassurance);
     *   "hold you": an embrace (touch, a little passion);
     *   "missed you": a meaningful evening together (quality time);
     *   "Come closer" (the line that opens a scene): a warm exchange (quality time), the way a real eval scores the scene's own exchange;
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
            // the scene's own exchange (the player's line that opens it, Sharmat's OStim stage the request) is scored like
            // the pipeline scores it: a warm, positive exchange, which carries reported_intimacy from the request
            $scene = str_contains($exchange, 'Come closer');
            $warm = str_contains($exchange, 'missed you') || $scene;
            return json_encode([
                'signals' => ['affinity' => ($care || $warm) ? 2 : 0, 'trust' => ($care || $warm) ? 2 : 0, 'comfort' => ($care || $warm) ? 2 : 0,
                              'respect' => 0, 'passion' => $hug ? 2 : 0, 'maturity' => 0],
                'tags' => $care ? ['rescue', 'reassurance'] : ($hug ? ['touch'] : ($warm ? ['quality_time'] : [])),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => ($care || $warm) ? 0.6 : ($hug ? 0.3 : 0.1),
                'summary' => $care ? 'She went down in the fight; the player knelt by her and helped her up.'
                    : ($hug ? 'The player held her.' : ($scene ? 'The player and the NPC were together, and it was tender.'
                        : ($warm ? 'The player said they missed her and stayed a while.' : 'Small talk.'))),
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

    // ------------------------------------------------------------------ §23 the fight

    /**
     * combat x fulfillment x absence (decisions §23). Four days after the evening at home each of them fights beside the player
     * (a bark in the fight, then the fight ends): the fight is CONTACT, so the neglect counts from it, not from the last word,
     * and the day is a day the pair was together; and it is time together by the NPC's taste, and for the one who dreads it
     * (Ashe, made the scholar by the editor's facet overrides) it also takes off what she enjoys: "I wish it was something I
     * enjoy". Aela, who lives for it, loses nothing. The words stay with the player's own: the last contact is still the evening.
     */
    public function testAFightBesideThePlayerIsContactAndTimeTogetherAndWhereItIsDreadedPartlyUnfulfilling(): void
    {
        $this->seed(60);
        $t = $this->meet();
        $this->setBeds(function (array &$d): void {
            RelDynFacets::setPreferenceOverride($d, 'scholarly', 0.8);
            RelDynFacets::setPreferenceOverride($d, 'combat', -0.7);
            RelDynFacets::setPreferenceOverride($d, 'adventure', -0.4);
            RelDynFacets::setPreferenceOverride($d, 'danger', -0.6);
        }, ['Ashe']);
        $this->setBeds(function (array &$d): void {
            RelDynFacets::setPreferenceOverride($d, 'scholarly', 0.5);   // each has something she would rather be doing
        }, [self::AELA, 'Muiri', self::LYNLY]);
        // the fight four days on, with no word from the player in between
        $beds = array_keys(self::BEDS);
        $fightAt = self::at(self::N0 + 4, 12.0) + 30000;
        $before = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $before[$npc] = RelDynFulfillment::levelsAt((array) RelDynFulfillment::pairState($d), $fightAt);
        }
        foreach ($beds as $i => $npc) $this->bark($npc, self::at(self::N0 + 4, 12.0) + 1000 * $i);
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $fightAt, self::SUITOR);   // (a request voiced by one of them is her own word with the player: markContact)
        $rows = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $state = (array) RelDynFulfillment::pairState($d);
            $after = RelDynFulfillment::levelsAt($state, $fightAt);
            $lost = [];
            foreach ($after as $axis => $level) {
                if (RelDynFulfillment::axisKind((string) $axis) === RelDynFulfillment::KIND_FACET && $level < floatval($before[$npc][$axis] ?? $level) - 5e-4) {
                    $lost[$axis] = round($level - floatval($before[$npc][$axis]), 3);
                }
            }
            // the neglect on the real state, with the fight and as if it had never happened: the same days apart, the same NPC
            $from = floatval($d['_last_contact_gamets']);
            $to = $from + 12 * self::DAY;
            $with = $d;
            $without = $d;
            unset($without[RelationshipDynamics::FIGHT_CONTACT_KEY]);
            $a = RelationshipDynamics::advanceCalendar($with, $from, $to);
            $b = RelationshipDynamics::advanceCalendar($without, $from, $to);
            $rows[$npc] = [
                'fight' => floatval($d[RelationshipDynamics::FIGHT_CONTACT_KEY] ?? 0), 'last_word' => $from, 'lost' => $lost,
                'present' => RelDynFulfillment::wasPresentOn($state, RelDynFulfillment::gameDayOf($fightAt)),
                'neglect_with' => round($a['neglect_days'], 3), 'neglect_without' => round($b['neglect_days'], 3),
                'resentment_with' => round($a['resentment_raw'], 4), 'resentment_without' => round($b['resentment_raw'], 4),
                'liking' => round(RelDynFulfillment::sharedFightLiking(RelDynFacets::preferences($d, $npc)), 3),
                'weight' => RelDynFulfillment::sharedFightWeight(RelDynFacets::preferences($d, $npc)),
            ];
        }
        $this->probe('fight contact', $rows);
        $log = $this->log();
        foreach ($beds as $npc) {
            $r = $rows[$npc];
            // contact by the NPC's own weight for fighting (§23): all of the way to the fight for the one who lives for it, a little for the one who dreads it
            $this->assertEqualsWithDelta($r['last_word'] + $r['weight'] * ($fightAt - $r['last_word']), $r['fight'], 1.0, "{$npc}: the fight is the contact, by how much the NPC enjoys it");
            $this->assertEqualsWithDelta(self::at(self::N0, 18.0), $r['last_word'], 3000.0, "{$npc}: the player's last word is still the evening");
            $this->assertTrue($r['present'], "{$npc}: a day the pair was together");
            $this->assertStringContainsString("Shared fight is contact: {$npc}", $log);
            $this->assertGreaterThan($r['neglect_with'], $r['neglect_without'], "{$npc}: the days up to the fight are not neglect");
            $this->assertEqualsWithDelta($r['weight'] * ($fightAt - $r['last_word']) / self::DAY, $r['neglect_without'] - $r['neglect_with'], 0.05, "{$npc}: the share of the days from the last word to the fight that the NPC's liking of it forgives");
        }
        // meaningfully apart: the same four days cost a different NPC different amounts (her own neglect rate)
        $saved = array_map(fn($r) => round($r['resentment_without'] - $r['resentment_with'], 4), $rows);
        $this->assertGreaterThan(0.0, min($saved));
        $this->assertGreaterThan(1.2 * min($saved), max($saved), 'who she is scales it');
        // meaningfully apart: the one who dreads it is forgiven a little, the one who lives for it most of it
        $forgiven = array_map(fn($r) => $r['neglect_without'] - $r['neglect_with'], $rows);
        $this->assertGreaterThan(0.0, $forgiven['Ashe'], 'it still counts a little for Ashe');
        $this->assertGreaterThan(1.5 * $forgiven['Ashe'], $forgiven[self::AELA], 'but far more for Aela, who lives for it');
        $this->assertGreaterThan($rows['Ashe']['fight'], $rows[self::AELA]['fight']);
        // the dread: Ashe's enjoyed things are further off; Aela lost nothing
        $this->assertNotSame([], $rows['Ashe']['lost'], 'a fight she dreads is partly unfulfilling');
        $this->assertArrayHasKey('scholarly', $rows['Ashe']['lost']);
        $this->assertLessThan(0.0, $rows['Ashe']['lost']['scholarly']);
        $this->assertSame([], $rows[self::AELA]['lost'], 'she lives for it: nothing taken');
        $this->assertGreaterThan($rows['Ashe']['liking'], $rows[self::AELA]['liking']);
        $this->assertStringContainsString('Shared fight is partly unfulfilling: Ashe', $log);
        $this->assertStringNotContainsString('Shared fight is partly unfulfilling: ' . self::AELA, $log);
        // it still counts a little for Ashe: the time together did not go down
        $time = (float) RelDynFulfillment::levelsAt((array) RelDynFulfillment::pairState($this->dynamics('Ashe')), $fightAt)[RelationshipDynamics::LL_TIME];
        $this->assertGreaterThanOrEqual($before['Ashe'][RelationshipDynamics::LL_TIME] - 1e-6, $time);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ §22 the fearful morning after

    /** A Sharmat scene stage with the player and $npc (ext_nsfw_sexcene, as Sharmat's OStim handler reports it), alone. */
    private function scene(string $npc, int $gamets, string $label): void
    {
        $data = 'OStimScene/vaginal,romantic/Stage1_A1/' . self::PLAYER . "^dom,vaginal/{$npc}^sub,vaginal";
        $this->people = "|{$npc}|" . self::PLAYER . '|';
        $this->event('inputtext', self::PLAYER . ": Come closer. (Talking to {$npc})", $gamets - 30);
        $this->event('ext_nsfw_sexcene', $data, $gamets);
        $this->request($npc, ['ext_nsfw_sexcene', (string) $this->realTs, (string) $gamets, $data], self::PLAYER, $label);
        $this->people = null;
    }

    /** The pull-back's numbers for $npc now. */
    private function pull(string $npc): array
    {
        $d = $this->dynamics($npc);
        $p = (array) ($d['_pullback'] ?? []);
        return ['active' => !empty($p['active']), 'cause' => $p['cause'] ?? null, 'pressure' => round(floatval($p['pressure'] ?? 0.0), 3),
            'size' => round(floatval($p['aftermath']['size'] ?? 0.0), 3), 'target' => $p['last']['target'] ?? null, 'on' => $p['last']['on'] ?? null,
            'episodes' => intval($p['episodes'] ?? 0), 'let_in' => round(RelDynPullback::letIn($d), 1),
            'fear' => round(RelDynPullback::fearfulness($d), 3)];
    }

    /**
     * post-intimacy x pull-back x attachment (decisions §22). Four partners (core romantic, affinity 60, comfort and trust high:
     * let in) spend an evening with the player, then a scene with each, alone, as Sharmat reports it. The act is the same; what
     * follows is who they are: the fearful Muiri pulls back by the morning (and says so, by how mature she is), Ashe (avoidant)
     * carries a part of the same push and Aela and Lynly, secure, only a trace, which does not pull anyone back; it fades like any
     * pull-back (open again within two days); and nobody is shamed: no resentment toward themselves, no trust cut. A reassuring word
     * the morning after takes some of it off.
     */
    public function testAFearfulNpcPullsBackTheMorningAfterTheOthersDoNotAndNoOneIsShamed(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->floors(30.0);
        $this->setBeds(function (array &$d): void {
            foreach (['comfort' => 82.0, 'trust' => 84.0, 'respect' => 70.0] as $dim => $v) {
                $d['dimensions'][$dim]['x'] = $v;
                $d['dimensions'][$dim]['baseline'] = $v;
            }
            $d['dimensions']['resentment_self']['x'] = 0.0;
        });
        $t = $this->play($t + 600, 10.0);
        $t = $this->alone('I missed you today. Tell me about your evening.', $t + 600, 'evening');
        $beds = array_keys(self::BEDS);
        $pre = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $pre[$npc] = ['trust' => self::x($d, 'trust'), 'resentment_self' => self::x($d, 'resentment_self'), 'pull' => $this->pull($npc)];
            $this->assertFalse($pre[$npc]['pull']['active'], "{$npc}: nothing presses yet");
        }
        $night = $t + 600;
        foreach ($beds as $i => $npc) $this->scene($npc, $night + 1200 * $i, 'scene');
        $sizes = [];
        foreach ($beds as $npc) $sizes[$npc] = $this->pull($npc)['size'];
        // an hour on, a few hours on, the morning (9 game hours after the night), and a day and two on
        $trace = [];
        foreach ([1.0 => 'hour', 4.0 => 'late', 10.0 => 'morning', 15.0 => 'noon', 30.0 => 'day', 60.0 => 'days'] as $hours => $label) {
            $at = $this->play((int) round($night + 1200 * count($beds) + $hours * self::HOUR), 1.0);
            $this->alone($label === 'morning' ? 'Good morning.' : 'Anything on your mind?', $at, $label);
            foreach ($beds as $npc) $trace[$npc][$label] = $this->pull($npc);
        }
        $this->probe('the morning after', ['sizes' => $sizes, 'trace' => $trace]);
        $why = json_encode(['sizes' => $sizes, 'trace' => $trace]);
        // sized by how fearful: the fearful Muiri most, Ashe a part of it, the secure only a trace; and it is never zero
        foreach ($beds as $npc) $this->assertGreaterThan(0.0, $sizes[$npc], "{$npc}: no one is immune {$why}");
        $this->assertGreaterThan($sizes[self::AELA], $sizes['Muiri'], $why);
        $this->assertGreaterThan($sizes[self::LYNLY], $sizes['Muiri'], $why);
        $this->assertGreaterThan(2.0 * max($sizes[self::AELA], $sizes[self::LYNLY]), $sizes['Muiri'], "meaningfully apart {$why}");
        $this->assertGreaterThanOrEqual($sizes[self::AELA], $sizes['Ashe'], $why);
        // the fearful one pulls back for the morning, the secure ones do not, and it is the morning after, not the weather
        $first = null;
        foreach (['hour', 'late', 'morning', 'noon', 'day', 'days'] as $label) {
            if ($trace['Muiri'][$label]['active']) { $first = $first ?? $label; $this->assertSame('aftermath', $trace['Muiri'][$label]['cause'], "{$label} {$why}"); }
        }
        $this->assertContains($first, ['morning', 'noon'], "Muiri pulls back by midday {$why}");
        $this->assertTrue($trace['Muiri']['noon']['active'], $why);
        foreach (['hour', 'late', 'morning', 'noon', 'day', 'days'] as $label) {
            $this->assertFalse($trace[self::AELA][$label]['active'], "Aela {$label} {$why}");
            $this->assertFalse($trace[self::LYNLY][$label]['active'], "Lynly {$label} {$why}");
            $this->assertFalse($trace['Ashe'][$label]['active'], "Ashe: a part of the push does not pull back alone {$label} {$why}");
        }
        $this->assertGreaterThan($trace[self::AELA]['noon']['pressure'], $trace['Muiri']['noon']['pressure'], $why);
        $this->assertGreaterThan($trace[self::LYNLY]['noon']['pressure'], $trace['Ashe']['noon']['pressure'], $why);
        // she says it, once, to the player's face, as the morning after: a distance, never shame, feelings never numbers
        $said = (string) ($this->felt['Muiri'][$first]['pullback_enter'] ?? $this->felt['Muiri'][$first]['enter'] ?? '');
        $this->assertNotSame('', $said, 'Muiri says it ' . json_encode(array_keys($this->felt['Muiri'][$first] ?? [])));
        $this->probe('said', [$said, $this->felt['Muiri'][$first]]);
        $this->assertStringContainsString('Muiri', $said);
        $this->assertDoesNotMatchRegularExpression('/\b(shame|ashamed|regret|mistake|guilt)\b/i', $said);
        $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him)\b/i', $said, 'the NPC\'s own pronoun vars or the name, never a hard-coded one');
        $this->assertStringNotContainsString('missing', $said, 'it is the closeness, not unmet needs');
        // distance for a while: it fades like any pull-back, within two days
        $this->assertFalse($trace['Muiri']['days']['active'], "open again {$why}");
        $this->assertSame(1, $trace['Muiri']['days']['episodes'], $why);
        // no one is shamed: no resentment toward themselves, no trust cut
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertEqualsWithDelta($pre[$npc]['resentment_self'], self::x($d, 'resentment_self'), 0.01, "{$npc}: no shame {$why}");
            $this->assertGreaterThanOrEqual($pre[$npc]['trust'] - 0.5, self::x($d, 'trust'), "{$npc}: no trust cut {$why}");
        }
        $jev = $this->jev('Muiri', $night + 1200 * count($beds) + 600);
        $this->assertGreaterThan(0.4, $jev['pullback']['aftermath']['fearfulness']);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertSame([], $this->db->failures);
    }

    /** Jev's block for $npc now (the numbers; decisions §3). */
    private function jev(string $npc, int $gamets): array
    {
        return RelDynJev::state($npc, $this->dynamics($npc), (float) $gamets);
    }

    // ------------------------------------------------------------------ §23 the fear of losing, answered by who they are

    /** A hand-set trait override on a stored state (the editor's way: profile_overrides.trait_vector, by trait name; codes given here). */
    private static function setTraits(array &$d, array $byCode): void
    {
        $map = (array) ($d['profile_overrides']['trait_vector'] ?? []);
        foreach ($byCode as $code => $v) $map[RelDynTraits::TRAITS[$code]] = $v;
        RelationshipDynamics::setProfileOverride($d, 'trait_vector', $map);
    }

    /** Hand-set dimensions (value and baseline, as the editor sets them). */
    private static function setDims(array &$d, array $dims): void
    {
        foreach ($dims as $k => $v) {
            $d['dimensions'][$k]['x'] = $v;
            if ($k !== 'resentment' && $k !== 'jealousy') $d['dimensions'][$k]['baseline'] = $v;
        }
    }

    /** The keeping lane's numbers for $npc now. */
    private function keep(string $npc): array
    {
        $d = $this->dynamics($npc);
        $r = RelDynKeeping::response($d);
        return ['fear' => round(RelDynKeeping::fear($d), 3), 'band' => RelDynKeeping::band(RelDynKeeping::fear($d)), 'response' => $r['kind'], 'lean' => $r['lean'],
            'maturity' => $r['expression']['band'], 'style' => $r['expression']['style'], 'conflict' => !empty($d['in_conflict']),
            'count' => intval($d['_keeping']['conflict']['count'] ?? 0), 'held_trust' => round(floatval($d['_keeping']['applied']['trust'] ?? 0.0), 2),
            'people_pleaser' => RelationshipDynamics::isPeoplePleaser($d)];
    }

    /** The keeping line the LLM was shown for $npc at the turn $label, or null. */
    private function keepLine(string $npc, string $label): ?string
    {
        foreach (['keeping_uneasy', 'keeping_clinging', 'keeping_controlling'] as $k) {
            if (isset($this->felt[$npc][$label][$k])) return (string) $this->felt[$npc][$label][$k];
        }
        return null;
    }

    /**
     * Does the rendered $felt line say $text? Intensity formatting may set words in caps, add pauses and (low maturity) drop or
     * swap a letter: most of its words must be there.
     */
    private static function reads(string $felt, string $text): bool
    {
        $words = fn(string $s) => array_values(array_filter(preg_split('/[^a-z_]+/', strtolower($s)), fn($w) => strlen($w) >= 4));
        $said = $words($felt);
        $want = $words($text);
        return $want !== [] && count(array_intersect($want, $said)) >= 0.6 * count($want);
    }

    /** No command refused: the action list core would offer is whole (the autonomy filter took nothing off it). */
    private function assertNothingRefused(string $npc, string $label): void
    {
        $this->assertSame(self::CORE_ACTIONS, $this->actions[$npc][$label] ?? null, "{$npc} {$label}: every action still on the list");
    }

    /** The same worried stretch for every bed: a bond to lose (core romantic 75, comfort and trust 70) and a jealous mind. */
    private function worry(float $jealousy = 85.0): void
    {
        $this->setBeds(function (array &$d) use ($jealousy): void {
            self::setDims($d, ['comfort' => 70.0, 'trust' => 70.0, 'jealousy' => $jealousy]);
        });
    }

    /** $line to each bed at $gamets, then the eval worker. */
    private function everyone(string $line, int $gamets, string $label): void
    {
        foreach (array_keys(self::BEDS) as $i => $npc) $this->turn($npc, $line, $gamets + 600 * $i, $label);
        $this->worker();
    }

    /**
     * keeping x character x autonomy (decisions §23), the four as they are. The same stretch for all four, the same bond (core
     * romantic, affinity 75, comfort and trust 70) and the same worry (jealous, two days without a word): the fear of losing the
     * player is everyone's, nobody is immune, and what it makes each of them DO is who they are: Muiri (toxic, immature) accuses
     * on thin evidence and her trust is held down, Ashe (mature) says it plainly, the secure and the unbothered say nothing at all.
     * No one's commands are refused for it (the action list stays whole), no verdict on the player is in any word, no number.
     */
    public function testTheFearOfLosingIsEveryonesAndEachAnswersAsTheyAreWithNoCommandRefused(): void
    {
        $this->seed(75);
        $this->meet();
        $this->worry();
        $beds = array_keys(self::BEDS);
        $this->everyone('I am back.', self::at(self::N0 + 2, 18.0), 'back');
        $this->everyone('Still here.', self::at(self::N0 + 2, 18.0) + (int) round(9 * self::HOUR), 'later');
        $k = $line = [];
        foreach ($beds as $npc) {
            $k[$npc] = $this->keep($npc);
            $line[$npc] = $this->keepLine($npc, 'later');
        }
        $this->probe('keeping, the four as they are', compact('k', 'line'));
        $why = json_encode($k);
        foreach ($beds as $npc) $this->assertGreaterThan(0.0, $k[$npc]['fear'], "{$npc}: nobody is immune {$why}");
        $this->assertGreaterThan(2.0 * max($k[self::AELA]['fear'], $k[self::LYNLY]['fear']), $k['Muiri']['fear'], "meaningfully apart {$why}");
        $this->assertGreaterThan($k['Ashe']['fear'], $k['Muiri']['fear'], $why);
        // each says it in their own way, or nothing at all
        $this->assertNotNull($line['Muiri'], 'Muiri says it');
        $this->assertNotSame('mature', $k['Muiri']['maturity'], $why);
        $this->assertStringContainsString('sharp', (string) $line['Muiri'], 'the one who means to say it evenly and cannot: it comes out sharp');
        $this->assertNotNull($line['Ashe'], 'Ashe says it');
        $this->assertSame('mature', $k['Ashe']['maturity'], $why);
        $this->assertStringContainsString('plainly', (string) $line['Ashe'], 'a mature one says it plainly');
        $this->assertNotSame($line['Muiri'], $line['Ashe']);
        $this->assertNull($line[self::LYNLY], 'a secure one says nothing about it');
        // never a refused command, never a verdict, never a number or a pronoun of RelDyn\'s own
        foreach ($beds as $npc) {
            $this->assertSame('express', $k[$npc]['response'], "{$npc}: the way they show it by maturity and traits {$why}");
            $this->assertFalse($k[$npc]['conflict'], "{$npc}: a fear is not yet a fight {$why}");
            foreach (['back', 'later'] as $label) $this->assertNothingRefused($npc, $label);
            foreach (['back', 'later'] as $label) {
                $text = (string) $this->keepLine($npc, $label);
                $this->assertDoesNotMatchRegularExpression('/\b(abus\w*|toxic|unfair|deserv\w*|cruel|manipulat\w*|refus\w*)\b/i', $text, "{$npc} {$label}");
                $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him)\b/i', $text, "{$npc} {$label}");
            }
        }
        $this->assertLessThan(0.0, $k['Muiri']['held_trust'], 'Muiri: it costs the bond where it is strongest');
        $jev = RelDynJev::state('Muiri', $this->dynamics('Muiri'), (float) RelationshipDynamics::currentGamets());
        $this->assertSame('express', $jev['keeping']['response']);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertSame([], $this->db->failures);
    }

    /**
     * keeping x character x autonomy (decisions §23), hand-set copies (Ken: Lynly's bio does not establish shyness, so nothing
     * is forced on her own read; the copies are made by the editor's overrides) under the same stretch and a worse worry:
     *   Lynly, a people-pleaser copy (low confidence, low maturity, anxious): appeases and complies, with a player who has treated
     *     them badly (trust 10, resentment 80) all the same: no conflict, no action refused, the autonomy design's people-pleaser
     *     stays compliant;
     *   Muiri, an immature, controlling copy: starts a conflict once the fear has held at its worst, in its words and as a
     *     standing conflict, and still no command is refused;
     *   Aela, an avoidant copy: withdraws, quiet and distant, rather than ask;
     *   Ashe as she is: plainly, if she says anything.
     * RelDyn models the person: none of the words judges the player.
     */
    public function testAPeoplePleaserAppeasesAControllingOneStartsAFightAnAvoidantOneWithdrawsAndNoOneRefuses(): void
    {
        $this->seed(75);
        $this->meet();
        $this->worry(95.0);
        $this->setBeds(function (array &$d): void {
            self::setDims($d, ['self_confidence' => 12.0, 'maturity' => 20.0, 'trust' => 10.0, 'respect' => 8.0, 'resentment' => 80.0]);
            $d['profile_overrides']['attachment_axes'] = ['anxiety' => 0.8, 'avoidance' => 0.2];
        }, [self::LYNLY]);
        $this->setBeds(function (array &$d): void {
            self::setDims($d, ['maturity' => 15.0]);
            self::setTraits($d, ['Po' => 0.95, 'C' => 0.5, 'L' => 0.35, 'Pd' => 0.35]);
        }, ['Muiri']);
        $this->setBeds(function (array &$d): void {
            self::setDims($d, ['self_confidence' => 60.0, 'maturity' => 55.0]);
            $d['profile_overrides']['attachment_axes'] = ['anxiety' => 0.2, 'avoidance' => 0.85];
        }, [self::AELA]);
        $beds = array_keys(self::BEDS);
        $t2 = self::at(self::N0 + 2, 18.0);
        $this->everyone('I am back.', $t2, 'back');
        $this->everyone('Still here.', $t2 + (int) round(9 * self::HOUR), 'later');
        $this->everyone('Good morning.', $t2 + (int) round(20 * self::HOUR), 'morning');
        $k = $line = [];
        foreach ($beds as $npc) {
            $k[$npc] = $this->keep($npc);
            $line[$npc] = $this->keepLine($npc, 'morning') ?? $this->keepLine($npc, 'later');
        }
        $this->probe('keeping, by who they are', compact('k', 'line'));
        $why = json_encode($k);
        // the people-pleaser appeases
        $this->assertTrue($k[self::LYNLY]['people_pleaser'], $why);
        $this->assertSame('appease', $k[self::LYNLY]['response'], $why);
        $this->assertContains($k[self::LYNLY]['band'], ['clinging', 'controlling'], "the fear is real {$why}");
        $this->assertFalse($k[self::LYNLY]['conflict'], "a people-pleaser never starts a fight {$why}");
        $this->assertNotNull($line[self::LYNLY]);
        $this->assertMatchesRegularExpression('/keeps? things smooth|easy to keep|agree to anything/', (string) $line[self::LYNLY], 'appeases');
        $auto = RelationshipDynamics::evaluateAutonomyState($this->dynamics(self::LYNLY), 'Stoic');
        $this->assertSame('compliant', $auto['state'], 'complies, though the player has treated them badly');
        $this->assertTrue($auto['swallowed'], 'what the autonomy design says they swallow');
        foreach (['back', 'later', 'morning'] as $label) $this->assertNothingRefused(self::LYNLY, $label);
        // the controlling one starts a conflict
        $this->assertSame('immature', $k['Muiri']['maturity'], $why);
        $this->assertSame('control', $k['Muiri']['style'], $why);
        $this->assertSame('controlling', $k['Muiri']['band'], $why);
        $this->assertTrue($k['Muiri']['conflict'], "the fear has held at its worst: a fight {$why}");
        $this->assertSame(1, $k['Muiri']['count'], $why);
        $this->assertTrue(self::reads((string) $line['Muiri'], 'has started a fight with Kaida to keep Kaida from going: accusations, demands to know where Kaida has been and with whom, ultimatums that are really pleas. Muiri wants Kaida to stay'), (string) $line['Muiri']);
        foreach (['back', 'later', 'morning'] as $label) $this->assertNothingRefused('Muiri', $label);
        // the avoidant one withdraws
        $this->assertSame('withdraw', $k[self::AELA]['response'], $why);
        $this->assertFalse($k[self::AELA]['conflict'], $why);
        $this->assertNotNull($line[self::AELA], 'Aela says it, in her way');
        $this->assertMatchesRegularExpression('/distance|room|shell|cold|quiet|shut/', (string) $line[self::AELA], 'withdraws');
        foreach (['back', 'later', 'morning'] as $label) $this->assertNothingRefused(self::AELA, $label);
        // four characters, four ways, none of them a verdict, a number or a pronoun of RelDyn's own
        $said = array_filter($line, fn($l) => $l !== null);
        $this->assertGreaterThanOrEqual(3, count(array_unique($said)), 'meaningfully apart');
        foreach ($said as $npc => $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(abus\w*|toxic|unfair|deserv\w*|cruel|manipulat\w*|refus\w*)\b/i', $text, $npc);
            $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him)\b/i', $text, $npc);
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, $npc);
        }
        // Jev gets the numbers: the response and the conflict
        $now = (float) RelationshipDynamics::currentGamets();
        $jev = RelDynJev::state('Muiri', $this->dynamics('Muiri'), $now);
        $this->assertSame('express', $jev['keeping']['response']);
        $this->assertSame(true, $jev['keeping']['conflict']['open']);
        $this->assertStringContainsString(' conflict', $jev['text']);
        $this->assertSame('appease', RelDynJev::state(self::LYNLY, $this->dynamics(self::LYNLY), $now)['keeping']['response']);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertSame([], $this->db->failures);
    }
}
