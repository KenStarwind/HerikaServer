<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynFeltLanePgDb
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
 * Felt lane (batch T): what the NPC's feelings put in front of the LLM, end to end with the four
 * test beds (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set
 * vector, never read: nothing of her story anywhere), Muiri (toxic, nudged fearful) and Lynly
 * Star-Sung (the shy bard), on CHIM 3.4.1 core-shaped rows and the committed seed's reads, through
 * the real hooks as main.php runs them (prerequest -> core's action list with the ext functions.php
 * -> context_pre -> context -> postrequest), the real eval producer and worker (LLM stubbed at the
 * connector boundary). No LLM call; feelings, never numbers, in front of the LLM.
 *   love-language hints  ll-discovery-hints: a hug lands by who she is. The request that classified
 *                        it keeps it (core runs the context hook before ext postrequest), the next
 *                        word of the player finds her reacting: the blush by the size of the
 *                        moment, scaled (not gated) by the language, the hint once, a primary
 *                        match's flush held a turn. Eval-scored and local-classifier paths agree.
 *   reunion              reunion-spike: a played absence; the return turn carries the prose of the
 *                        NPC's nearest preset and the passion it earned, no number, once.
 *   emergent emotions    emergent-emotions: the composites, by who she is: at most two lines, the
 *                        romantic ones only inside a romance.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynFeltLaneTestBedsPostgresTest extends TestCase
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
    private RelDynFeltLanePgDb $db;
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

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_flt_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynFeltLanePgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdfltbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_felt_lane_beds_test.log');
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
                    ['pending', $npc, 'default', $listener, $this->realTs, (int) $request[2], (int) $request[2]]);
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
    private function assertClean(bool $evalRan = true): void
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
        if ($evalRan) $this->assertGreaterThan(0, $this->evalCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the emergent emotions

    /** Set the same circumstance on every bed's stored dimensions (as the editor would): dim => points. */
    private function circumstance(array $dims): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d) use ($dims): void {
                foreach ($dims as $dim => $x) $d['dimensions'][$dim]['x'] = $x;
            });
        }
    }

    /** The emergent emotions whose line is in $text (the player named however her tier names them). */
    private function spoken(string $npc, ?string $text): array
    {
        $ids = [];
        foreach (RelationshipDynamics::EMERGENT_EMOTIONS as $id => $spec) {
            $re = preg_quote(str_replace('{NAME}', $npc, $spec['context']), '/');
            $re = str_replace(preg_quote('the player', '/'), '(?:Kaida|the player|this stranger|this person)', $re);
            if ($text !== null && preg_match('/' . $re . '/', $text)) $ids[] = $id;
        }
        return $ids;
    }

    private function emergent(string $npc, string $label): ?string
    {
        return $this->felt[$npc][$label]['emergent'] ?? null;
    }

    /**
     * emergent-emotions, the absence. Two game weeks without a word, then the player is back: the
     * return finds those whose nature is to want company (a high affinity baseline) and whose warmth
     * has fallen lonely; Ashe, who rests lower and keeps to herself, is steady instead. The
     * absence is on them (the contact that ends a long one) without any held fade being needed.
     */
    public function testAnAbsenceFindsTheLonelyOnesByWhoTheyAre(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->floors(12.0);
        $t = $this->play($t + 600, 10.0);
        $t = $this->round('Come here, let me hold you.', $t + 600, 'hug');
        $t = $this->play(self::at(self::N0 + 14, 18.0), 20.0);
        $this->round('I am back.', $t, 'return');
        $lonely = [];
        foreach (array_keys(self::BEDS) as $npc) $lonely[$npc] = in_array('loneliness', $this->spoken($npc, $this->emergent($npc, 'return')), true);
        $this->probe('absence', $lonely);
        foreach (['Muiri', self::LYNLY] as $npc) {
            $d = $this->dynamics($npc);
            $this->assertGreaterThanOrEqual(30.0, floatval(RelationshipDynamics::getTemperamentBaseline($d['inferred_temperament'] ?? null, 'affinity', $d)), "{$npc}: wants company by nature");
            $this->assertTrue($lonely[$npc], "{$npc}: the return finds her lonely " . json_encode($lonely));
        }
        $this->assertFalse($lonely['Ashe'], 'Ashe rests lower and keeps to herself: ' . json_encode($lonely));
        $this->assertContains('quiet_devotion', $this->spoken('Ashe', $this->emergent('Ashe', 'return')), 'Ashe is steady, not lonely');
        // said once and by the return: the next word, the contact is no absence any more
        $this->round('Stay a while.', $t + 600, 'stay');
        foreach (['Muiri', self::LYNLY] as $npc) $this->assertNotContains('loneliness', $this->spoken($npc, $this->emergent($npc, 'stay')), "{$npc}: it was the return's");
        $this->assertClean();
    }

    /**
     * emergent-emotions, earned security. The same long good run (trust and comfort high, a
     * committed bond) is peace for the one whose nature can hold it, mature and sure of herself
     * (Ashe's maturity 75 and self-confidence 65), and not yet for the three whose maturity or
     * confidence is not there; the three do not get the composite by the circumstance alone.
     */
    public function testTheLongGoodRunSettlesOnlyThoseWhoseNatureCanHoldIt(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->circumstance(['trust' => 80.0, 'comfort' => 80.0]);
        $this->round('Good morning.', $t + 600, 'good');
        $this->assertContains('earned_security', $this->spoken('Ashe', $this->emergent('Ashe', 'good')), 'Ashe is at peace');
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $npc) {
            $this->assertNotContains('earned_security', $this->spoken($npc, $this->emergent($npc, 'good')), "{$npc}: not yet");
        }
        $this->assertClean();
    }

    /**
     * emergent-emotions, suffocation fixed to the draft: high affinity + LOW comfort + RISING
     * resentment. At ease (comfort 85, the old rule's 80+) with resentment climbing is not it for
     * any of them; the same climb against low comfort is, for all four.
     */
    public function testSuffocationIsLowComfortWithResentmentRisingNotAtEase(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $beds = array_keys(self::BEDS);
        $this->circumstance(['comfort' => 85.0, 'resentment' => 20.0]);
        $t = $this->round('Good morning.', $t + 600, 'a');
        $this->circumstance(['comfort' => 85.0, 'resentment' => 40.0]);
        $t = $this->round('Well?', $t + 600, 'b');
        foreach ($beds as $npc) {
            $this->assertNotContains('suffocation', $this->spoken($npc, $this->emergent($npc, 'b')), "{$npc}: at ease is not suffocating, whatever the resentment does");
        }
        $this->circumstance(['comfort' => 30.0, 'resentment' => 46.0, 'respect' => 50.0]);
        $t = $this->round('We need to talk.', $t + 600, 'c');
        foreach ($beds as $npc) {
            $this->assertContains('suffocation', $this->spoken($npc, $this->emergent($npc, 'c')), "{$npc}: low comfort, resentment rising: " . json_encode($this->emergent($npc, 'c')));
        }
        $this->assertClean();
    }

    /**
     * emergent-emotions, at most two lines. A fall-out the same for all four (comfort and respect
     * low, resentment climbing across two turns, still close): insecurity and suffocation. For Ashe,
     * the mature one, contempt matches too (maturity 56+): three matched, two spoken, the shallowest
     * stays quiet.
     */
    public function testAtMostTwoSpeakAndTheShallowestStaysQuiet(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->circumstance(['trust' => 30.0, 'comfort' => 25.0, 'respect' => 20.0, 'resentment' => 30.0]);
        $t = $this->round('We need to talk.', $t + 600, 'talk1');
        $this->circumstance(['resentment' => 55.0, 'respect' => 5.0, 'comfort' => 25.0]);
        $matched = [];
        foreach (array_keys(self::BEDS) as $npc) $matched[$npc] = count(RelationshipDynamics::detectEmergentEmotions($this->dynamics($npc)));
        $t = $this->round('Well?', $t + 600, 'talk2');
        foreach (array_keys(self::BEDS) as $npc) {
            $spoken = $this->spoken($npc, $this->emergent($npc, 'talk2'));
            $this->assertCount(2, $spoken, "{$npc}: two speak " . json_encode([$spoken, $this->emergent($npc, 'talk2')]));
            $this->assertContains('insecurity', $spoken, $npc);
        }
        $this->assertGreaterThanOrEqual(3, $matched['Ashe'], 'Ashe matches three: ' . json_encode($matched));
        $this->assertNotContains('contempt', $this->spoken('Ashe', $this->emergent('Ashe', 'talk2')), 'the shallowest of her three stays quiet');
        $this->assertClean();
    }

    /**
     * emergent-emotions, contempt re-aligned to the draft (low respect + high resentment + HIGH
     * maturity; no affinity input). The same cold turn, comfort untouched: the mature one (Ashe)
     * has seen who the player is and is done; the three whose maturity is not there have no
     * composite for it.
     */
    public function testContemptIsTheMatureOnesAndNoOneElses(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->circumstance(['comfort' => 55.0, 'respect' => 5.0, 'resentment' => 55.0]);
        $this->round('Well?', $t + 600, 'cold');
        $this->assertSame(['contempt'], $this->spoken('Ashe', $this->emergent('Ashe', 'cold')));
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $npc) {
            $this->assertNotContains('contempt', $this->spoken($npc, $this->emergent($npc, 'cold')), "{$npc}: maturity " . round(floatval($this->dynamics($npc)['dimensions']['maturity']['x']), 1));
        }
        $this->assertClean();
    }

    /**
     * emergent-emotions, the romantic ones inside a romance only. The same state for Aela (her
     * core bond is the romance) and Muiri (core says platonic, her passion under the line): both
     * match longing by the dimensions; only Aela, in a romance, says it.
     */
    public function testTheRomanticOnesSpeakOnlyInsideARomance(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->setCore('Muiri', 80, 'platonic');
        foreach ([self::AELA, 'Muiri'] as $npc) {
            $this->editDynamics($npc, function (array &$d): void {
                RelationshipDynamics::setPassion($d, 10.0);
                RelDynPassion::storeSpike($d, 25.0, 'touch');
                $d['dimensions']['comfort']['x'] = 90.0;
            });
            $this->assertContains('longing', RelationshipDynamics::detectEmergentEmotions($this->dynamics($npc)), "{$npc}: longing by the dimensions");
        }
        $this->round('Good morning.', $t + 600, 'l');
        $this->assertContains('longing', $this->spoken(self::AELA, $this->emergent(self::AELA, 'l')), 'in a romance');
        $this->assertNull($this->emergent('Muiri', 'l'), 'between friends it is not said: ' . json_encode($this->emergent('Muiri', 'l')));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the love-language hints

    /** The languages each bed infers today (a story pin: these tests are about the hint, not the inference). */
    private function pinLanguages(): void
    {
        foreach ([self::AELA => ['physical_touch', 'quality_time'], 'Ashe' => ['quality_time', 'acts_of_service'],
                     'Muiri' => ['words_of_affirmation', 'physical_touch'], self::LYNLY => ['physical_touch', 'words_of_affirmation']] as $npc => [$primary, $secondary]) {
            $this->editDynamics($npc, function (array &$d) use ($primary, $secondary): void {
                $d['love_language_primary'] = $primary;
                $d['love_language_secondary'] = $secondary;
            });
        }
    }

    /** 0 none, 1 faint, 2 mild, 3 strong: how hard the blush line hit. */
    private static function blushRank(?string $text): int
    {
        if ($text === null) return 0;
        if (preg_match('/^heat floods/i', $text)) return 3;
        if (preg_match('/^colour rises/i', $text)) return 2;
        if (preg_match('/^a faint warmth/i', $text)) return 1;
        return -1;
    }

    private function hintText(string $sub, string $npc): string
    {
        return str_replace('{NAME}', $npc, (string) RelDynFelt::config()['text']['ll_reaction'][$sub]);
    }

    /**
     * ll-discovery-hints, the eval path. The player holds each of the four (the eval scores the hug:
     * tag 'touch', a little passion); the next word finds each of them reacting by what a hug is to
     * her: Aela and Lynly (touch is their language) are undone and the flush stays a turn; Muiri
     * (touch is her second) is warmly appreciative; Ashe (time and deeds are hers) gets a polite
     * smile and her blush is the milder band. Each hint is said once, plain conversation after it
     * is no gesture, and the blush holds only where the language matched.
     */
    public function testAHugLandsByWhoSheIsAndHerNextWordSaysSoOnce(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->pinLanguages();
        $this->floors(30.0);
        $beds = array_keys(self::BEDS);

        $t = $this->round('Come here, let me hold you.', $t + 600, 'hug');
        $wantMult = [self::AELA => 2.0, 'Ashe' => 1.0, 'Muiri' => 1.5, self::LYNLY => 2.0];
        $after = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $after[$npc] = ['ll' => $d['_last_interaction_ll'] ?? null, 'delta' => $d['_last_passion_delta'] ?? null, 'mult' => $d['pending_blush_mult'] ?? 1.0];
            $this->assertSame('physical_touch', $d['_last_interaction_ll'] ?? null, "{$npc}: the hug is how it landed");
            $this->assertGreaterThanOrEqual(2.0, floatval($d['_last_passion_delta'] ?? 0), "{$npc}: a moment to blush at " . json_encode($after));
            $this->assertEquals($wantMult[$npc], floatval($d['pending_blush_mult'] ?? 1.0), "{$npc}: the blush multiplier is who she is " . json_encode($after));
        }
        $this->probe('hug', $after);

        // her next word: the blush, the hint
        $t = $this->round('How are you feeling?', $t + 600, 'after');
        $reaction = [self::AELA => 'physical_touch', 'Ashe' => 'miss', 'Muiri' => 'secondary', self::LYNLY => 'physical_touch'];
        $rank = [];
        foreach ($beds as $npc) {
            $felt = $this->felt[$npc]['after'];
            $this->assertSame($this->hintText($reaction[$npc], $npc), $felt['ll_reaction'] ?? null, "{$npc}: how the hug landed");
            $rank[$npc] = self::blushRank($felt['blush'] ?? null);
            $this->assertGreaterThan(0, $rank[$npc], "{$npc}: blushes " . json_encode($felt));
            $d = $this->dynamics($npc);
            $this->assertArrayNotHasKey('_last_interaction_ll', $d, "{$npc}: spent");
            $this->assertSame(1, intval($d['love_language_hints_given'] ?? 0), "{$npc}: one hint given");
        }
        $this->assertGreaterThan($rank['Ashe'], $rank[self::AELA], 'the same hug is a stronger flush for the one whose language it is: ' . json_encode($rank));
        $this->assertNotSame($this->felt['Ashe']['after']['ll_reaction'], $this->felt[self::AELA]['after']['ll_reaction']);
        // the hold: a primary match only
        foreach ([self::AELA, self::LYNLY] as $npc) $this->assertSame(1, intval($this->dynamics($npc)[RelDynFelt::BLUSH_HOLD_KEY] ?? 0), "{$npc}: the flush stays a turn");
        foreach (['Ashe', 'Muiri'] as $npc) $this->assertArrayNotHasKey(RelDynFelt::BLUSH_HOLD_KEY, $this->dynamics($npc), "{$npc}: no hold");

        // the word after: nothing new to react to (plain conversation is no gesture); the held flush is still there
        $t = $this->round('Shall we go on?', $t + 600, 'third');
        foreach ($beds as $npc) {
            $felt = $this->felt[$npc]['third'];
            $this->assertArrayNotHasKey('ll_reaction', $felt, "{$npc}: the hint is said once");
            $this->assertArrayNotHasKey('blush', $felt, "{$npc}: and so is the blush");
            $this->assertSame(in_array($npc, [self::AELA, self::LYNLY], true), isset($felt['blush_hold']), "{$npc}: the flush a primary match held");
        }
        $t = $this->round('Lead the way.', $t + 600, 'fourth');
        foreach ($beds as $npc) {
            $this->assertSame([], array_intersect_key($this->felt[$npc]['fourth'], array_flip(['blush', 'blush_hold', 'll_reaction'])), "{$npc}: gone");
        }
        $this->assertClean();
    }

    /**
     * The same hug reaches the same reading when no eval scores the exchange (no connector): the
     * local classifier read a touch (a VR touch the plugin reports), the request that classified it
     * keeps the gesture and the blush multiplier, and the next word finds her reacting. A line of
     * conversation after it is not a gesture.
     */
    public function testALocalTouchReadsTheSameAndPlainConversationIsNoGesture(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $this->pinLanguages();
        $this->floors(30.0);
        unset($GLOBALS['RELLLM_CONNECTOR']);
        $beds = array_keys(self::BEDS);
        $before = $this->evalCalls;

        foreach ($beds as $i => $npc) {
            $this->request($npc, ['ext_nsfw_physics', (string) $this->realTs, (string) ($t + 600 * $i), 'Kaida puts an arm around ' . $npc], self::PLAYER, 'touch');
            $d = $this->dynamics($npc);
            $this->assertSame('physical_touch', $d['_last_interaction_ll'] ?? null, "{$npc}: the touch is how it landed");
        }
        $t += 600 * count($beds);
        $reaction = [self::AELA => 'physical_touch', 'Ashe' => 'miss', 'Muiri' => 'secondary', self::LYNLY => 'physical_touch'];
        foreach ($beds as $i => $npc) {
            $this->turn($npc, 'How are you feeling?', $t + 600 * $i, 'after');
            $this->assertSame($this->hintText($reaction[$npc], $npc), $this->felt[$npc]['after']['ll_reaction'] ?? null, $npc);
            $this->assertGreaterThan(0, self::blushRank($this->felt[$npc]['after']['blush'] ?? null), "{$npc}: blushes");
        }
        $t += 600 * count($beds);
        foreach ($beds as $i => $npc) {
            $this->turn($npc, 'Shall we go on?', $t + 600 * $i, 'third');
            $this->assertArrayNotHasKey('ll_reaction', $this->felt[$npc]['third'], "{$npc}: plain conversation is no gesture, and the hint was said once");
            $this->assertArrayNotHasKey('_last_interaction_ll', $this->dynamics($npc), $npc);
        }
        $this->assertSame($before, $this->evalCalls, 'no eval ran: the local classifier read it');
        $this->assertClean(false);
    }

    // ------------------------------------------------------------------ the reunion

    /** The prose getReunionText gives this NPC from her stored state, the player named by $playerToken. */
    private function reunionProse(string $npc, string $playerToken): string
    {
        $d = $this->dynamics($npc);
        return (string) RelationshipDynamics::getReunionText($npc, (string) ($d['inferred_temperament'] ?? ''),
            floatval($d['_reunion_hours_apart'] ?? 0), $playerToken, $d);
    }

    /**
     * reunion-spike. Four partners (core affinity 80), then ten game hours of real play apart, then
     * the player is back: the return turn's felt block carries the short-return prose of the NPC's
     * nearest preset (Ashe, Stoic, reads differently from the three Bold ones), no digit, the
     * passion the reunion earned (by who she is: Ashe's the quietest) and nothing is said on the
     * next word. A day and a half apart on the next return is the medium text.
     */
    public function testTheReunionTurnCarriesHerOwnProseOnce(): void
    {
        $this->seed(80);
        $t = $this->hello();
        $t = $this->play($t + 600, 2.0);
        $t = $this->round('Stay a while.', $t + 600, 'stay');   // contact on a played clock
        $beds = array_keys(self::BEDS);

        $g = $this->play($t + 600, 30.0);   // 30 real minutes = 10 game hours
        $g = $this->round('Good to see you.', $g + 600, 'return');
        $earned = [];
        foreach ($beds as $npc) {
            $text = $this->felt[$npc]['return']['reunion'] ?? null;
            $this->assertNotNull($text, "{$npc}: the return is felt");
            $this->assertStringContainsString(explode(' ', $npc)[0], $text, "{$npc}: named, not a pronoun");
            $this->assertStringContainsString(self::PLAYER, $text);
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$npc}: feelings, not numbers");
            $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|herself|he|him|his|himself)\b/i', $text, "{$npc}: no gendered pronoun");
            $this->assertSame($this->reunionProse($npc, '{{P}}'), str_replace(self::PLAYER, '{{P}}', $text), "{$npc}: the nearest preset's short text");
            $d = $this->dynamics($npc);
            $this->assertEmpty($d['reunion_spike_given'] ?? null, "{$npc}: once per visit");
            $this->assertEqualsWithDelta(10.0, floatval($d['_reunion_hours_apart']), 0.5, "{$npc}: game hours apart");
            $earned[$npc] = floatval($d['passion_sources']['reunion'] ?? 0);
            $this->assertGreaterThan(0.0, $earned[$npc], "{$npc}: the reunion earned passion");
        }
        $this->probe('reunion', $earned);
        $this->assertNotSame(str_replace('Ashe', 'X', $this->felt['Ashe']['return']['reunion']), str_replace('Aela', 'X', $this->felt[self::AELA]['return']['reunion']),
            'Ashe (Stoic) and Aela (Bold) do not greet the same way');
        $this->assertSame(str_replace('Aela the Huntress', 'X', $this->felt[self::AELA]['return']['reunion']), str_replace('Muiri', 'X', $this->felt['Muiri']['return']['reunion']),
            'the same preset, the same prose');
        $this->assertLessThan($earned[self::AELA], $earned['Ashe'], 'the quietest return is the Stoic one: ' . json_encode($earned));

        // the next word: nothing of the return is said again
        $g = $this->round('Shall we go on?', $g + 600, 'next');
        foreach ($beds as $npc) $this->assertArrayNotHasKey('reunion', $this->felt[$npc]['next'], "{$npc}: said once");

        // a day and a half of play apart reads as the medium return
        $h = $this->play($g + 600, 90.0);   // 90 real minutes = 30 game hours
        $this->round('I am back.', $h + 600, 'again');
        foreach ($beds as $npc) {
            $text = $this->felt[$npc]['again']['reunion'] ?? null;
            $this->assertNotNull($text, "{$npc}: the second return is felt");
            $this->assertSame($this->reunionProse($npc, '{{P}}'), str_replace(self::PLAYER, '{{P}}', $text), "{$npc}: the medium text");
            $this->assertGreaterThan(24.0, floatval($this->dynamics($npc)['_reunion_hours_apart']), "{$npc}: a day and more");
            $this->assertNotSame($this->felt[$npc]['return']['reunion'], $text, "{$npc}: the longer the absence, the more it says");
        }
        $this->assertClean();
    }
}
