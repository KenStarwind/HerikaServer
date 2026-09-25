<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynP3pRulingsPgDb
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
 * Ken's rulings of 2026-09-25 (decisions §18 #7-#11) and the continue fix, end to end with the
 * four test beds (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's
 * hand-set vector, never read), Muiri and Lynly Star-Sung, each the player's partner (core
 * Player.type romantic, affinity 60), on CHIM 3.4.1 core-shaped rows and the committed seed's
 * reads, through the real hooks as main.php runs them (prerequest -> core's action list with the
 * ext functions.php -> context_pre -> context -> postrequest), the real eval producer and worker
 * (LLM stubbed at the connector boundary) and core's relationship writes. No LLM call.
 *   #9   Muiri attaches like the toxic test bed from her preset alone (no editor), the other
 *        three as their own reads give; social masking ships off, and switched on it is Muiri,
 *        proud and fearful, who wears the Mask;
 *   #8   a partner's comfort shows its own height: raw 48 and raw 80 read apart (the hard clamp
 *        read both as the top);
 *   #7   four partners in a shame crisis walk away; approaching them is not pursuit; a gentle
 *        approach, her confession (#10 'confessing') and the player's forgiveness (#10) bring
 *        each back early, each at her own pace;
 *   #11  the same flattery, graded charmer by the eval, is the Charmer for all four, and lands
 *        differently on each (her traits), the mature one seeing through it;
 *   continue: a continue after Mikael's line is his exchange with her, no turn of the player pair.
 * Feelings, never numbers, in front of the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynP3pRulingsTestBedsPostgresTest extends TestCase
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
    private const MARE_PEOPLE = '|Hulda|Mikael|Jon Battle-Born|Olfina Gray-Mane|Ysolda|Kaida|';
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynP3pRulingsPgDb $db;
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
        $this->schema = 'reldyn_p3p_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynP3pRulingsPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdp3pbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_p3p_beds_test.log');
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

    /** Core rows (partners: Player romantic, affinity 60), Mikael, voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic'], self::SUITOR => ['aff' => 10, 'type' => 'neutral']]])]);
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

    /**
     * The eval LLM at the connector boundary, by the player's line in THIS EXCHANGE:
     *   "not angry" (the gentle approach): warmth met, quality time and reassurance, calm (rock);
     *   "what happened" (she tells what she is ashamed of, met with care): confessing;
     *   "I forgive you": forgiveness;
     *   "most beautiful" (flattery, eager to please): praise, courting, graded charmer;
     *   anything else: small talk, no particular approach.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            // only the exchange itself (the task text below it says "what happened" too)
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "
TASK:", $from) - $from));
            $gentle = str_contains($exchange, 'not angry');
            $tell = str_contains($exchange, 'what happened');
            $forgive = str_contains($exchange, 'I forgive you');
            $flatter = str_contains($exchange, 'most beautiful');
            $warm = $gentle || $tell || $forgive;
            return json_encode([
                'signals' => ['affinity' => $warm ? 2 : ($flatter ? 1 : 0), 'trust' => $warm ? 2 : 0, 'comfort' => $warm ? 3 : 0,
                              'respect' => 0, 'passion' => $flatter ? 2 : 0, 'maturity' => 0],
                'tags' => $gentle ? ['quality_time', 'reassurance'] : ($tell ? ['confessing'] : ($forgive ? ['forgiveness'] : ($flatter ? ['praise'] : []))),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $warm ? 0.6 : ($flatter ? 0.3 : 0.1),
                'summary' => $gentle ? 'The player found her and spoke to her gently.'
                    : ($tell ? 'She told the player what she had done, and the player listened.'
                    : ($forgive ? 'The player forgave her.' : ($flatter ? 'The player flattered her.' : 'Small talk.'))),
                'romantic_intent' => $flatter ? 2 : 0,
                'charisma' => $flatter ? 'charmer' : ($gentle ? 'rock' : 'none'),
            ]);
        };
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

    // ------------------------------------------------------------------ #9 Muiri fearful, masking off

    /**
     * §18 #9: Muiri attaches like the toxic test bed from her preset alone (no editor, the other
     * beds as their reads give them): the fearful region, the eval hears it in her closeness
     * line. Social masking ships off, so nobody masks among strangers; switched on, it is Muiri,
     * proud and fearful, who wears the Mask, where before the editor had to make her toxic.
     */
    public function testMuiriAttachesFearfulFromHerPresetAndMaskingShipsOff(): void
    {
        $this->hello();
        $styles = [];
        foreach (array_keys(self::BEDS) as $npc) $styles[$npc] = RelationshipDynamics::getAttachmentStyle($this->dynamics($npc));
        $muiri = $this->dynamics('Muiri');
        $this->assertSame('toxic', $styles['Muiri'], json_encode($styles));
        $this->assertSame('preset', RelationshipDynamics::getAttachmentAxes($muiri)['source'], 'her preset, not an editor');
        $this->assertArrayNotHasKey('attachment_style', (array) ($muiri['profile_overrides'] ?? []));
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) $this->assertNotSame('toxic', $styles[$npc], "{$npc}: " . json_encode($styles));
        $closeness = fn(string $npc) => implode("\n", RelDynEval::stateSummary($npc, $this->dynamics($npc)));
        $this->assertStringContainsString('wants closeness and fears it at once', $closeness('Muiri'));
        foreach ([self::AELA, 'Ashe', self::LYNLY] as $npc) $this->assertStringNotContainsString('fears it at once', $closeness($npc), $npc);
        $this->assertEquals(30, RelationshipDynamics::getAttachmentModifier($muiri, 'maturity_floor'), 'the fearful protocol: her maturity floor');

        // Among strangers: masking is off, nobody masks
        $this->people = self::MARE_PEOPLE;
        $t = $this->round('Busy in here tonight.', self::at(self::N0, 21.0), 'mare');
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertArrayNotHasKey('mask', $this->felt[$npc]['mare'], "{$npc}: masking ships off");
            $this->assertEmpty($this->dynamics($npc)['_was_masking'] ?? false, $npc);
        }
        // Switched on (Ken's playtest): Muiri wears the Mask, the others do not
        $this->storeConfig(['social_masking_enabled' => true]);
        $this->round('Busy in here tonight.', $t + 600, 'mare_on');
        $wears = [];
        foreach (array_keys(self::BEDS) as $npc) $wears[$npc] = isset($this->felt[$npc]['mare_on']['mask']);
        $this->assertSame(['Aela the Huntress' => false, 'Ashe' => false, 'Muiri' => true, 'Lynly Star-Sung' => false], $wears);
        $this->people = null;
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ #8 the saturating display

    /**
     * §18 #8: a partner's comfort shows its own height. The editor sets raw comfort 48 on Aela
     * and Ashe and 80 on Muiri and Lynly: through the per-bond multiplier (a partner's, above 2)
     * the hard clamp read all four at the top; the saturating curve reads the two pairs apart,
     * each below the top, and the felt comfort line follows the value shown.
     */
    public function testAPartnersComfortShowsItsOwnHeight(): void
    {
        $this->hello();
        $raw = ['Aela the Huntress' => 48.0, 'Ashe' => 48.0, 'Muiri' => 80.0, 'Lynly Star-Sung' => 80.0];
        foreach ($raw as $npc => $x) {
            $this->editDynamics($npc, function (array &$d) use ($x): void { $d['dimensions']['comfort']['x'] = $x; });
        }
        $shown = [];
        foreach ($raw as $npc => $x) {
            $d = $this->dynamics($npc);
            $own = self::x($d, 'comfort') - RelationshipDynamics::heldTemporaryOffset($d, 'comfort');
            $m = RelationshipDynamics::perBondMultiplier($d, 'comfort');
            $this->assertGreaterThan(2.0, $m, "{$npc}: a partner");
            $this->assertGreaterThanOrEqual(100.0, $own * $m, "{$npc}: the hard clamp read her at the top");
            $shown[$npc] = RelationshipDynamics::getEffectiveDimensionValue($d, 'comfort');
            $this->assertEqualsWithDelta(100.0 * (1.0 - pow(1.0 - $own / 100.0, $m)) + RelationshipDynamics::heldTemporaryOffset($d, 'comfort'),
                $shown[$npc], 1e-6, $npc);
            $this->assertLessThan(100.0, $shown[$npc], "{$npc}: below the top");
        }
        $why = json_encode($shown);
        $this->assertLessThan(min($shown['Muiri'], $shown[self::LYNLY]), max($shown[self::AELA], $shown['Ashe']), "48 and 80 read apart {$why}");
        $this->assertNotSame(RelationshipDynamics::getDimensionBand('comfort', $shown[self::AELA])['label'],
            RelationshipDynamics::getDimensionBand('comfort', $shown[self::LYNLY])['label'], $why);
        // The eval hears the value shown
        foreach ($raw as $npc => $x) {
            $band = RelationshipDynamics::getDimensionBand('comfort', $shown[$npc]);
            $this->assertContains("Comfort with the player: {$band['label']} ({$band['keywords']})", RelDynEval::stateSummary($npc, $this->dynamics($npc)), $npc);
        }
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ #7 + #10 the shame walkaway

    /**
     * §18 #7 and #10: the four partners, ashamed past the crisis (resentment_self 92, the
     * editor's value; resentment toward the player low), each go off alone (a shame walkaway).
     * The player finds them: not pursuit (no follow, no trust penalty, the bond not severed) and
     * the LLM hears that a gentle word might reach her. The gentle approach helps (relief, and it
     * can come out), she tells what she is ashamed of (confessing, -10), the player forgives her
     * (-15), and gentle company brings each below the walkaway's recovery line: she comes back
     * early, each at her own pace (the decay beside it is her maturity's).
     */
    public function testAShameWalkawayIsApproachedGentlyAndSheComesBack(): void
    {
        $this->hello();
        $beds = array_keys(self::BEDS);
        foreach ($beds as $npc) {
            $this->editDynamics($npc, function (array &$d): void {
                $d['dimensions']['resentment_self']['x'] = 92.0;
                $d['dimensions']['resentment']['x'] = 5.0;
                $d['dimensions']['comfort']['x'] = 60.0;
                $d['dimensions']['trust']['x'] = 60.0;
            });
        }
        $t = $this->play(self::at(self::N0 + 1, 9.0), 20.0);
        $t = $this->round('Is something wrong? You seem far away.', $t, 'crisis');
        $trust = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('shame', $d['_walkaway_reason'] ?? null, $npc);
            $this->assertSame('active', $d['_walkaway_state'] ?? null, "{$npc}: she went off alone");
            $trust[$npc] = self::x($d, 'trust');
        }

        // The player finds her: not pursuit; the gentle approach helps
        $t = $this->round("I'm here. I'm not angry with you.", $t + 600, 'found');
        $self = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $why = json_encode(['arc' => $d['_resentment_arc']['self'] ?? null, 'walk' => $d['_walkaway_state'] ?? null]);
            $this->assertFalse($d['_walkaway_player_followed'] ?? true, "{$npc}: approaching her is not following her {$why}");
            $this->assertNotSame('permanent', $d['_walkaway_state'] ?? null, $npc);
            $this->assertStringContainsString('a gentle word might reach them', (string) ($this->felt[$npc]['found']['autonomy'] ?? ''), $npc);
            $this->assertLessThanOrEqual(92.0 - 5.0 + 1e-3, self::x($d, 'resentment_self'), "{$npc}: the gentle approach helped {$why}");
            $this->assertTrue($d['_resentment_arc']['self']['confess_open'] ?? false, "{$npc}: it can come out now {$why}");
            $this->assertGreaterThanOrEqual($trust[$npc], self::x($d, 'trust'), "{$npc}: no trust penalty");
            $self[$npc] = self::x($d, 'resentment_self');
        }

        // She tells; the player forgives her
        $t = $this->round('Tell me what happened. I will listen.', $t + 600, 'tell');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertLessThanOrEqual($self[$npc] - 10.0 + 1e-3, self::x($d, 'resentment_self'), "{$npc}: the confession");
            $this->assertFalse($d['_resentment_arc']['self']['confess_open'], "{$npc}: told");
            $self[$npc] = self::x($d, 'resentment_self');
        }
        $t = $this->round('I forgive you. It is done.', $t + 600, 'forgive');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertLessThanOrEqual($self[$npc] - 15.0 + 1e-3, self::x($d, 'resentment_self'), "{$npc}: forgiven");
            $this->assertTrue($d['_resentment_arc']['self']['forgiven'], $npc);
        }

        // Gentle company, a while apart, until each is below the recovery line; then she is back
        $back = [];
        $trace = [];
        for ($k = 0; $k < 4 && count($back) < count($beds); $k++) {
            $t = $this->play($t + 600, 16.0);
            $t = $this->round("I'm still here. I'm not angry.", $t, "stay{$k}");
            foreach ($beds as $npc) {
                $d = $this->dynamics($npc);
                $trace[$npc][] = [round(self::x($d, 'resentment_self'), 2), $d['_walkaway_state'] ?? 'normal'];
                if (!isset($back[$npc]) && ($d['_walkaway_state'] ?? 'normal') === 'normal') $back[$npc] = $k;
            }
        }
        $why = json_encode($trace);
        $this->assertCount(count($beds), $back, "all four came back early {$why}");
        $final = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $final[$npc] = round(self::x($d, 'resentment_self'), 3);
            $this->assertLessThan(RelationshipDynamics::WALKAWAY_RECOVERY_RESENTMENT_MAX + 5.0, $final[$npc], "{$npc} {$why}");
            $this->assertSame('romantic', $this->coreType($npc), "{$npc}: the bond was never severed");
            $this->assertArrayNotHasKey('_reject_recruitment', $d, $npc);
            $this->assertFalse(RelDynResentment::selfCrisis($d), $npc);
        }
        $this->assertGreaterThanOrEqual(2, count(array_unique($final)), "each at her own pace (her maturity's decay) {$why}");
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertGreaterThan(0, $this->evalCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ #11 charisma graded by the eval

    /**
     * §18 #11: the same flattery, five times, graded charmer by the eval, is the Charmer with all
     * four (a style needs a graded approach: the hello before it was graded none). How it lands is
     * hers (her traits' charisma_charmer): the passion multiplier differs across the beds, and Ashe
     * (maturity 75) sees through the pattern while the younger-hearted do not.
     */
    public function testTheSameFlatteryIsTheCharmerAndLandsHerOwnWay(): void
    {
        $this->hello();
        foreach (array_keys(self::BEDS) as $npc) $this->assertNull(RelationshipDynamics::charismaStyle($this->dynamics($npc)), "{$npc}: no approach yet");
        $t = self::at(self::N0 + 1, 12.0);
        for ($k = 0; $k < 5; $k++) {
            $t = $this->round('You are the most beautiful woman in Skyrim, and I would do anything for you.', $t + 600, "flatter{$k}");
        }
        $t = $this->round('Good evening.', $t + 600, 'after');
        $mult = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('charmer', RelationshipDynamics::charismaStyle($d), "{$npc}: " . json_encode($d['_charisma_tracker'] ?? null));
            $this->assertSame(['none', 'charmer', 'charmer', 'charmer', 'charmer', 'charmer', 'none'], $d['_charisma_tracker']['recent_grades'], $npc);
            $mult[$npc] = RelationshipDynamics::getCharismaEffectiveness('charmer', $d['inferred_temperament'] ?? null,
                self::x($d, 'maturity'), 'passion', $d);
        }
        $why = json_encode($mult);
        $this->assertGreaterThanOrEqual(2, count(array_unique(array_map(fn($m) => round($m, 3), $mult))), "it lands differently {$why}");
        $this->assertArrayHasKey('charisma', $this->felt['Ashe']['after'], 'Ashe sees through it');
        $this->assertArrayNotHasKey('charisma', $this->felt[self::LYNLY]['after'], 'Lynly does not');
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ a continue after Mikael

    /**
     * A continue answering Mikael (core's last speech row is his line to her) reaches the
     * prerequest before core has resolved whom it answers: it is his exchange with her, no turn
     * of the player pair (no contact, reunion or player-directed steering). A continue after the
     * player's own line is the pair's.
     */
    public function testAContinueAfterMikaelIsNoTurnOfThePlayerPair(): void
    {
        $this->hello();
        $t = self::at(self::N0, 20.0);
        $beds = array_keys(self::BEDS);
        foreach ($beds as $k => $npc) {
            $at = $t + 600 * $k;
            $before = $this->dynamics($npc);
            $this->event('chat', self::SUITOR . ": Fine weather for a walk. (talking to {$npc})", $at, $this->home(), 'emitted');
            $this->speech(self::SUITOR, $npc, 'Fine weather for a walk.', $at);
            $this->request($npc, ['continue', (string) $this->realTs, (string) ($at + 20), ''], self::SUITOR, 'continue', self::SUITOR);
            $after = $this->dynamics($npc);
            $this->assertSame($before['_last_contact_gamets'] ?? null, $after['_last_contact_gamets'] ?? null, "{$npc}: no contact of the player pair");
            $this->assertSame(intval($before['interaction_count'] ?? 0), intval($after['interaction_count'] ?? 0), $npc);
            $this->assertSame([], $this->felt[$npc]['continue'], "{$npc}: nothing player-directed");
        }
        // After the player's own line, the continue is the pair's
        $t2 = self::at(self::N0, 22.0);
        foreach ($beds as $k => $npc) {
            $at = $t2 + 600 * $k;
            $before = $this->dynamics($npc);
            $this->event('inputtext', self::PLAYER . ": And then? (Talking to {$npc})", $at, $this->home());
            $this->speech(self::PLAYER, $npc, 'And then?', $at);
            $this->request($npc, ['continue', (string) $this->realTs, (string) ($at + 20), ''], self::PLAYER, 'continue_pair', self::PLAYER);
            $this->assertGreaterThan(floatval($before['_last_contact_gamets'] ?? 0), floatval($this->dynamics($npc)['_last_contact_gamets'] ?? 0), $npc);
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }
}
