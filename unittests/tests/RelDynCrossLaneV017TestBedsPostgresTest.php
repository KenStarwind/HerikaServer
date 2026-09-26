<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCrossLaneV017PgDb
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
 * Integration v0.17: where the Phase 4 lanes meet (absence, combat, passion, impulse), end to end
 * with the four test beds (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe
 * (Serene's hand-set vector, never read: nothing of her story anywhere), Muiri (toxic, nudged
 * fearful) and Lynly Star-Sung (the shy bard), on CHIM 3.4.1 core-shaped rows and the committed
 * seed's reads, through the real hooks as main.php runs them (prerequest -> core's action list
 * with the ext functions.php -> context_pre -> context -> postrequest), the real eval producer and
 * worker (LLM stubbed at the connector boundary). No LLM call; feelings, never numbers, in front
 * of the LLM; Jev gets the numbers.
 *   the rescue      combat x passion x impulse: the player answering her fall with care is both
 *                   a floor (the rescue bonus, who she is by attachment) and a moment (the spike,
 *                   who she is by temperament); alone with him afterwards her urge reads the two
 *                   together.
 *   the ex          combat x passion: the tier's governor holds an ex's passion where it is, and
 *                   the moment is no way around it: the same embrace that races her partners'
 *                   hearts gives the ex none, and no urge from it.
 *   the absence     absence x passion x impulse: two weeks without a word; the moment is long
 *                   gone for everyone; a bond that broke costs her comfort and so how open she is
 *                   with him (derived warmth); a strained return reaches for nothing.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCrossLaneV017TestBedsPostgresTest extends TestCase
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
    private RelDynCrossLaneV017PgDb $db;
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
        $this->schema = 'reldyn_x17_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynCrossLaneV017PgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdx17beds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_x17_beds_test.log');
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

    // ------------------------------------------------------------------ the rescue

    /**
     * combat x passion x impulse. Four partners with the same earned passion go down in a fight;
     * the player's next word to each is care ("take my hand"), scored by the eval. The rescue
     * response lands on the floor (MDD 3.3, by attachment: Aela, who does not need saving, least)
     * and is paid once: the care that answered her fall is no moment on top of it (batch-Q
     * review). He holds each of them after: that is the moment, on top of the floor (a spike by
     * temperament and arousal). The floor keeps exactly what the rescue gave it; the moment is not
     * floor. Alone with him right after, her romantic urge (MDD 13.1, passion x privacy) reads the
     * two together, the effective passion, not the floor alone.
     */
    public function testTheRescueIsAFloorAndAMomentAndTheUrgeReadsBoth(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->floors(30.0);
        $t = $this->play($t + 600, 10.0);
        $beds = array_keys(self::BEDS);

        // All four go down (core logs the falls; RelDyn routes them on its next turn)
        $t1 = $t + 600;
        foreach ($beds as $i => $npc) $this->event('bleedout', "{$npc} falls to the ground almost unconscious", $t1 + 1000 * $i);
        // The player's next exchange with each: care; the eval scores it (and claims the fall)
        $t2 = $t1 + 2 * self::MINUTE;
        foreach ($beds as $i => $npc) $this->turn($npc, 'Are you hurt? Here, take my hand.', $t2 + 600 * $i, 'rescue');
        $pre = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertIsArray($d[RelDynCombat::RESCUE_PENDING_KEY] ?? null, "{$npc}: her fall waits for the eval of the exchange");
            $pre[$npc] = RelationshipDynamics::getPassion($d);
        }
        $this->worker();
        $t = $t2 + 600 * count($beds);

        $bonus = $gain = $spike = $floor = $rescued = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $last = $d[RelDynCombat::RESCUE_LAST_KEY] ?? null;
            $this->assertIsArray($last, "{$npc}: the rescue was answered with care");
            $bonus[$npc] = floatval($last['bonus']);
            $gain[$npc] = floatval($last['gain']);
            $rescued[$npc] = RelDynPassion::spike($d);
            // The floor is the rescue's: the eval asked no passion of its own
            $this->assertEqualsWithDelta($pre[$npc] + $gain[$npc], RelationshipDynamics::getPassion($d), 0.05, "{$npc}: the floor keeps what the rescue gave it");
        }
        // He holds each of them: the moment, on top of the floor the rescue left
        $t = $this->round('Come here, let me hold you.', $t + 600, 'hug');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $spike[$npc] = RelDynPassion::spike($d);
            $floor[$npc] = RelationshipDynamics::getPassion($d);
        }
        $why = json_encode(compact('bonus', 'gain', 'rescued', 'spike', 'floor', 'pre'));
        $this->probe('rescue', compact('bonus', 'gain', 'rescued', 'spike', 'floor', 'pre'));
        foreach ($beds as $npc) {
            $this->assertGreaterThan(0.0, $gain[$npc], "{$npc}: the rescue lands on the floor {$why}");
            $this->assertSame(0.0, $rescued[$npc], "{$npc}: paid once, no moment on top of the care {$why}");
            $this->assertGreaterThan(0.0, $spike[$npc], "{$npc}: the embrace is the moment {$why}");
        }
        // Two different measures of who she is: the floor by attachment (Aela least, MDD 3.3), the moment by temperament
        foreach (['Ashe', 'Muiri', self::LYNLY] as $npc) $this->assertGreaterThan($bonus[self::AELA], $bonus[$npc], "{$npc} {$why}");
        $this->assertCount(4, array_unique(array_map(fn($v) => round($v, 3), $spike)), "four women, four moments {$why}");

        // Alone with him a minute later: the urge is the moment's (effective passion x full privacy)
        $expect = $floorOnly = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $expect[$npc] = RelationshipDynamics::getEffectivePassion($d) * floatval(RelDynImpulse::config()['romantic']['passion_weight']);
            $floorOnly[$npc] = RelationshipDynamics::getPassion($d) * floatval(RelDynImpulse::config()['romantic']['passion_weight']);
        }
        $this->alone('Just us now. Rest a moment.', $t + self::MINUTE, 'alone');
        $urge = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $urge[$npc] = self::urge($d);
            $this->assertGreaterThanOrEqual($expect[$npc] - 0.02, $urge[$npc], "{$npc}: the urge reads floor + moment " . json_encode($d[RelDynImpulse::KEY] ?? null));
            $this->assertGreaterThan($floorOnly[$npc] + 0.5 * ($expect[$npc] - $floorOnly[$npc]), $urge[$npc], "{$npc}: more than the floor alone");
            $jev = RelationshipDynamics::jevStateBlock($npc);
            $this->assertEqualsWithDelta(RelationshipDynamics::getEffectivePassion($d), $jev['passion_effective'], 0.01, $npc);
            $this->assertArrayHasKey('rescue', $jev, "{$npc}: Jev gets the rescue's numbers");
        }
        $this->probe('alone', compact('urge', 'expect', 'floorOnly'));
        // The rescue is felt in words on the turn after it, never as a number
        foreach ($beds as $npc) $this->assertIsString($this->felt[$npc]['alone']['rescue'] ?? null, "{$npc}: " . json_encode(array_keys($this->felt[$npc]['alone'])));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the ex

    /**
     * combat x passion (x impulse). Core moves on: Muiri is now the player's ex (the Divorced /
     * Hostile row of MDD 8: nothing grows, no floor), the other three are still his partners, all
     * four with the same earned passion. He holds each of them. The partners' hearts race (a spike
     * each, bounded by their tier's ceiling); the ex's does not: the governor holds her where she
     * is and the moment is no way around it (the spike goes through the same passion factor as
     * every gain). Nothing is cut: her floor stays where it was. Alone with him afterwards, the
     * partners' urge carries the moment; the ex's carries none.
     */
    public function testAnExsHeartDoesNotRaceWhereTheTierHoldsIt(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->setCore('Muiri', 40, 'ex');
        $this->floors(30.0);
        $t = $this->play($t + 600, 10.0);
        $t = $this->round('Good evening.', $t + 600, 'evening');
        $this->assertSame('hostile', RelDynGovernors::governor($this->dynamics('Muiri'))['tier'], 'core ex: the Divorced / Hostile row');
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) {
            $this->assertSame('committed', RelDynGovernors::governor($this->dynamics($npc))['tier'], $npc);
        }
        $this->floors(30.0);

        $t = $this->round('Come here, let me hold you.', $t + 600, 'hug');
        $spike = $floor = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $spike[$npc] = RelDynPassion::spike($d);
            $floor[$npc] = RelationshipDynamics::getPassion($d);
            $ceiling = floatval(RelDynGovernors::governor($d)['ceiling']);
            $this->assertLessThanOrEqual(max($ceiling, 30.0) + 1e-6, RelationshipDynamics::getEffectivePassion($d), "{$npc}: never past her tier's ceiling");
        }
        $why = json_encode(compact('spike', 'floor'));
        $this->probe('hug', compact('spike', 'floor'));
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) $this->assertGreaterThan(0.0, $spike[$npc], "{$npc}: her heart races {$why}");
        $this->assertSame(0.0, $spike['Muiri'], "the ex: the tier holds her {$why}");
        $this->assertEqualsWithDelta(30.0, $floor['Muiri'], 0.01, "the ex: nothing is cut {$why}");
        $this->assertLessThanOrEqual(30.0, $floor['Muiri'], "the ex: nothing grows {$why}");
        $this->assertMatchesRegularExpression("/\[ATTRACTION\] Muiri: spike:[a-z_]+ passion .* \((bounded by|at) the hostile tier's passion ceiling 0\)/", file_get_contents($this->errorLog));

        $this->alone('Stay a while.', $t + self::MINUTE, 'alone');
        $w = floatval(RelDynImpulse::config()['romantic']['passion_weight']);
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) {
            $this->assertGreaterThan($floor[$npc] * $w + 0.5 * $spike[$npc] * $w, self::urge($this->dynamics($npc)), "{$npc}: the moment is in her urge {$why}");
        }
        $this->assertLessThanOrEqual($floor['Muiri'] * $w + 1e-6, self::urge($this->dynamics('Muiri')), "the ex: no moment in it {$why}");
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the absence

    /**
     * absence x passion x impulse. Four partners (core affinity 80) with the same earned passion;
     * the player holds each of them, spends a meaningful evening with them, and leaves without a
     * word for two game weeks. On the return: the moment is long gone for everyone (the spike
     * fades with absence); a bond the absence broke took comfort with it (bond-break-resentment),
     * so how open she is with him (derived warmth, sqrt(passion x comfort)) falls further than for
     * a partner whose bond held. And every return is strained (the absence decay opens a conflict,
     * see below): the loneliness timer ran since the meaningful evening, but no one reaches for
     * him (the social urge stays quiet while a bond is strained).
     */
    public function testTheBondThatBrokeWhileHeWasAwayClosesHerWarmth(): void
    {
        $this->seed(80);
        $t = $this->hello();
        // A cool romance: enough of a floor for a moment (10), below the cold-romance line (20)
        $this->floors(12.0);
        $t = $this->play($t + 600, 10.0);
        $t = $this->round('Come here, let me hold you.', $t + 600, 'hug');
        $t = $this->round('I missed you today. Tell me about your evening.', $t + 600, 'evening');
        $beds = array_keys(self::BEDS);
        $warm0 = $spike0 = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $warm0[$npc] = RelDynPassion::warmth($d);
            $spike0[$npc] = RelDynPassion::spike($d);
            $this->assertGreaterThan(0.0, $spike0[$npc], "{$npc}: a moment on the floor when he leaves");
            $this->assertIsNumeric($d[RelDynImpulse::KEY]['last_meaningful_gamets'] ?? null, "{$npc}: the evening was meaningful");
        }

        // ---- two game weeks later the player walks back in and greets each of them
        $t = $this->play(self::at(self::N0 + 14, 18.0), 20.0);
        $this->round('I am back.', $t, 'return');
        $d = $warm1 = $broke = $strained = $social = [];
        foreach ($beds as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $warm1[$npc] = RelDynPassion::warmth($d[$npc]);
            $broke[$npc] = is_array($d[$npc][RelDynAbsence::BREAK_KEY] ?? null);
            $strained[$npc] = !empty($d[$npc]['in_conflict']) || self::x($d[$npc], 'resentment') >= 51.0;
            $social[$npc] = [self::urge($d[$npc], 'social'), $d[$npc][RelDynImpulse::KEY]['sources']['social'] ?? null];
        }
        $info = ['warm0' => $warm0, 'warm1' => $warm1, 'spike0' => $spike0, 'broke' => $broke, 'strained' => $strained, 'social' => $social,
            'break' => array_map(fn($x) => $x[RelDynAbsence::BREAK_KEY] ?? null, $d), 'resentment' => array_map(fn($x) => self::x($x, 'resentment'), $d),
            'comfort' => array_map(fn($x) => self::x($x, 'comfort'), $d), 'aff' => array_map(fn($x) => RelationshipDynamics::getCoreAffinity($x), $d)];
        $this->probe('return', $info);
        $why = json_encode($info);
        foreach ($beds as $npc) $this->assertLessThan(0.01, RelDynPassion::spike($d[$npc]), "{$npc}: the moment did not wait two weeks {$why}");

        // Who broke is the absence lane's (Muiri breaks; Aela, Ashe and Lynly hold: the cold
        // romance does not rot while he is away, batch-Q review, and Lynly's decay alone leaves
        // her just above the line)
        $this->assertSame([self::AELA => false, 'Ashe' => false, 'Muiri' => true, self::LYNLY => false], $broke, $why);
        // A broken bond cost her comfort, and so warmth: her openness fell further than a held bond's
        foreach (['Muiri'] as $npc) {
            $this->assertLessThan(0.0, $d[$npc][RelDynAbsence::BREAK_KEY]['comfort_delta'], $npc);
            $restored = $d[$npc];
            $restored['dimensions']['comfort']['x'] = self::x($restored, 'comfort') - floatval($d[$npc][RelDynAbsence::BREAK_KEY]['comfort_delta']);
            $this->assertLessThan(RelDynPassion::warmth($restored), $warm1[$npc], "{$npc}: the break's comfort is warmth she no longer shows {$why}");
            foreach ([self::AELA, 'Ashe', self::LYNLY] as $held) {
                $this->assertLessThan($warm1[$held] / $warm0[$held], $warm1[$npc] / $warm0[$npc], "{$npc} vs {$held} {$why}");
            }
        }
        // The return itself: the absence decay's drop in core affinity opens a conflict for every
        // partner (observeCoreAffinity: a drop of ten or more in one session), held bond or broken
        // (an open question of integration v0.17 for the conflict lane). A strained bond reaches
        // for nothing: no social urge toward him, however long the loneliness timer ran
        $this->assertSame(array_fill_keys($beds, true), array_map(fn($x) => !empty($x['in_conflict']), $d), $why);
        foreach ($beds as $npc) {
            $this->assertTrue($strained[$npc], $npc);
            $this->assertSame(0.0, $social[$npc][0], "{$npc}: strained, no social urge toward him {$why}");
            $this->assertIsNumeric($d[$npc][RelDynImpulse::KEY]['last_meaningful_gamets'] ?? null, "{$npc}: the timer ran all the while");
        }
        $this->assertClean();
    }
}
