<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynMemMirBedsPgDb
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
 * memory-translation-layer + player-profile-mirror end to end with the four test beds (standing
 * rule feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector, never read:
 * nothing of her story anywhere), Muiri (toxic, nudged fearful) and Lynly Star-Sung (the shy bard),
 * on CHIM 3.4.1 core-shaped rows and the committed seed's reads, through the real hooks as main.php
 * runs them (prerequest -> core's action list with the ext functions.php -> context_pre -> context
 * -> postrequest), the real eval producer and worker (LLM stubbed at the connector boundary), and
 * core's memory table with the memory_v view its summary packer reads. No LLM call beyond the
 * stubbed eval; feelings, never numbers, in front of the LLM.
 *   the subtext     one hurtful word to all four: each memory of it carries who she is in words
 *                   (MDD §12), four different subtexts of the same event, packed beside it.
 *   the anchor      a first gift to each at home; after an evening elsewhere, coming home brings it
 *                   back to each of them (Addendum 12), and a moment of passion her own size.
 *   the mirror      a reliable player seen by all four: one profile of the player (not four), a
 *                   dependable rock who helps; a stranger has heard of it; opt-in, the NPCs sense it.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynMemoryMirrorTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const STRANGER = 'Mikael';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const MINUTE = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    private const N0 = 210;
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
    private const INN = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 8:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynMemMirBedsPgDb $db;
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
    private ?string $people = null;
    /** the eventlog location of the next turns */
    private string $place = self::HOME;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_mmbeds_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
        // data/database_default.sql memory, and debug/db_updates.php memory_v: what core's summary packer reads
        pg_query($admin, "CREATE SEQUENCE memory_rowid_seq");
        pg_query($admin, "CREATE TABLE memory (speaker text, message text, session text, uid serial NOT NULL, listener text,
            localts bigint, gamets bigint NOT NULL, momentum text, rowid bigint NOT NULL DEFAULT nextval('memory_rowid_seq'),
            event character varying(64), ts bigint)");
        pg_query($admin, "CREATE VIEW memory_v AS SELECT message, uid, gamets, speaker, listener, ts FROM (
              SELECT memory.message, memory.uid, memory.gamets, '-'::text AS speaker, '-'::text AS listener, memory.ts FROM memory
               WHERE memory.message !~~ 'Dear Diary%'::text AND memory.message <> ''::text AND event <> 'backgroundlife_diary'::text
            UNION
              SELECT '(Context Location:' || speech.location || ') ' || speech.speaker || ': ' || speech.speech,
                speech.rowid::integer, speech.gamets, speech.speaker, speech.listener, speech.ts FROM speech WHERE speech.speech <> ''::text
            UNION
              SELECT eventlog.data, eventlog.rowid::integer, eventlog.gamets, '-'::text, '-'::text, eventlog.ts FROM eventlog
               WHERE eventlog.type::text = ANY (ARRAY['death'::character varying::text, 'location'::character varying::text])) subquery
            ORDER BY gamets, ts");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
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

        $this->db = new RelDynMemMirBedsPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdmmbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_mmbeds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        $this->config([]);
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

    /** The stored RelDyn config: the defaults with the debug log on, no internal weather, plus $over. */
    private function config(array $over): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false], $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows (the beds: Player romantic at core affinity $aff), the stranger Mikael, voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => ['aff' => $aff, 'type' => 'romantic']]])]);
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
        // A stranger: no relationship to the player at all
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
            [self::STRANGER, 'male', 'ImperialRace', '', 'Roleplay as Mikael', '', json_encode(['skills' => $all]),
             json_encode(['class' => ['name' => 'Bard', 'formid' => '0x0001317f'], 'factions' => [], 'relationships' => []])]);
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld'),
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld')");
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

    private function speech(string $speaker, string $listener, string $text, int $gamets): void
    {
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', $speaker, $text, $this->placeName(), $listener, $this->realTs, $gamets, $gamets]);
    }

    private function placeName(): string
    {
        return $this->place === self::HOME ? 'Breezehome' : 'The Bannered Mare';
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

    /** One player line to $npc at $gamets, logged as core logs it (location, input row, spoken line, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('infoloc', $this->place, $gamets - 1);
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        $this->speech(self::PLAYER, $npc, $line, $gamets);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], self::PLAYER, $label);
    }

    /** The same line to all four, 10 game minutes apart from $t, then the eval worker. Returns the next free gamets. */
    private function round(string $line, int $t, string $label): int
    {
        foreach (array_keys(self::BEDS) as $i => $npc) $this->turn($npc, $line, $t + 600 * $i, $label);
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
     *   "useless": a hurtful word in front of everyone (a grievance);
     *   "brought you": a gift;
     *   "kept my promise": a promise kept, help given, in a calm, steady way (the rock);
     *   anything else: small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "\nTASK:", $from) - $from));
            $hurt = str_contains($exchange, 'useless');
            $gift = str_contains($exchange, 'brought you');
            $kept = str_contains($exchange, 'kept my promise');
            return json_encode([
                'signals' => [
                    'affinity' => $hurt ? -4 : ($gift || $kept ? 2 : 0), 'trust' => $hurt ? -3 : ($kept ? 3 : 0),
                    'comfort' => $hurt ? -4 : ($gift ? 1 : ($kept ? 2 : 0)), 'respect' => $hurt ? -3 : ($kept ? 1 : 0),
                    'passion' => 0, 'maturity' => 0],
                'tags' => $hurt ? ['criticism'] : ($gift ? ['gift'] : ($kept ? ['help'] : [])),
                'grievance' => $hurt ? ['flag' => true, 'kind' => 'disrespect', 'severity' => 2] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $hurt ? 0.6 : ($gift || $kept ? 0.5 : 0.1),
                'summary' => $hurt ? 'The player called her useless in front of everyone.'
                    : ($gift ? 'The player gave her a carved wooden bird.'
                    : ($kept ? 'The player kept a promise and helped her mend the fence.' : 'Small talk.')),
                'romantic_intent' => 0,
                'charisma' => $kept ? 'rock' : 'none',
            ]);
        };
    }

    /** Evening at home: everyone with the player. */
    private function hello(): int
    {
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

    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
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

    private function probe(string $label, array $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "\n=== {$label}\n" . json_encode($data, JSON_PRETTY_PRINT));
    }

    /** Every test ends the same way: feelings in front of the LLM, no LLM call but the eval, no failed query. */
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

    // ------------------------------------------------------------------ the subtext

    /**
     * MDD §12. One hurtful word, the same to each of the four, in front of everyone. Before it
     * becomes memory, each exchange is wrapped in who she is right then, in words: four memories of
     * one event with four different subtexts (her attachment, her mood, what she holds), each packed
     * by core right after the exchange's own line. The small talk before it is not worth a note.
     */
    public function testOneHurtfulWordIsFourDifferentMemories(): void
    {
        $this->config(['memory_translation' => ['commit' => ['enabled' => true]]]);
        $this->seed(40);
        $t = $this->hello();
        $this->assertSame('0', pg_fetch_result(pg_query($this->db->link, 'SELECT count(*) FROM memory'), 0, 0), 'small talk: no note');
        $t = $this->play($t + 600, 5.0);
        $t0 = $t + 600;
        $this->round('Honestly, you are useless.', $t0, 'hurt');

        $notes = $moved = [];
        foreach (array_keys(self::BEDS) as $i => $npc) {
            $rows = pg_fetch_all(pg_query_params($this->db->link, "SELECT message, gamets, listener FROM memory WHERE event = 'reldyn_subtext' AND speaker = $1", [$npc]));
            $this->assertCount(1, $rows, "{$npc}: one note for the one exchange");
            $this->assertSame((string) ($t0 + 600 * $i + 1), $rows[0]['gamets'], "{$npc}: right after the exchange (one gamets)");
            $this->assertSame(self::PLAYER, $rows[0]['listener']);
            $notes[$npc] = $rows[0]['message'];
            // the exchange's location as core's speech rows carry it (batch-R: PackIntoSummary cuts a queue at a row without one)
            $this->assertStringStartsWith("(Context Location:Breezehome) (Beneath this moment with Kaida, {$npc} was ", $notes[$npc]);
            $this->assertStringEndsWith('What happened: The player called her useless in front of everyone.)', $notes[$npc]);
            $this->assertDoesNotMatchRegularExpression('/\d/', $notes[$npc]);
            // How the word landed on her, through her own filters: the applied change of the exchange
            // (each moved dimension's last_delta), in words
            $d = $this->dynamics($npc);
            $applied = [];
            foreach (['affinity', 'trust', 'comfort', 'respect'] as $dim) {
                if (is_numeric($d['dimensions'][$dim]['last_delta'] ?? null)) $applied[$dim] = floatval($d['dimensions'][$dim]['last_delta']);
            }
            $moved[$npc] = RelDynMemory::moved($applied);
            $clauses = RelDynMemory::clauses($d, $npc, self::PLAYER, ['moved' => $moved[$npc]]);
            $this->assertArrayHasKey('moment', $clauses, "{$npc}: the word hurt " . json_encode($applied));
            $this->assertStringContainsString($npc . ' was ' . $clauses['moment'], $notes[$npc], "{$npc}: the moment leads");
            // what she is now, in words: her own attachment among them when it speaks
            $style = RelationshipDynamics::getAttachmentStyle($this->dynamics($npc));
            $attText = RelDynMemory::config()['text']['clauses']['attachment'][$style] ?? null;
            if ($attText !== null && isset($clauses['attachment'])) $this->assertStringContainsString($attText, $notes[$npc], "{$npc}: {$style}");
            // Packed right after the exchange's own line (core's memory_v, by game time)
            $packed = array_column(pg_fetch_all(pg_query_params($this->db->link,
                'SELECT message FROM memory_v WHERE gamets BETWEEN $1 AND $2 ORDER BY gamets, ts', [$t0 + 600 * $i - 5, $t0 + 600 * $i + 5])), 'message');
            $this->assertSame(['(Context Location:Breezehome) Kaida: Honestly, you are useless.', $notes[$npc]], $packed, $npc);
        }
        $this->probe('notes', $notes + ['moved' => $moved]);
        $this->assertCount(4, array_unique($notes), 'four women, four subtexts: ' . json_encode($notes));
        // Who they are: the resilient, Stoic-leaning Ashe takes the word far more lightly than the shy
        // bard, and the memory says so
        $this->assertGreaterThan($moved[self::LYNLY], $moved['Ashe'], json_encode($moved));
        $this->assertStringContainsString('Ashe was stung by it', $notes['Ashe']);
        $this->assertStringContainsString('Lynly Star-Sung was cut deeply by it', $notes[self::LYNLY]);
        // Muiri's fearful attachment is in hers, and in no one else's
        $this->assertSame(['Muiri'], array_keys(array_filter($notes, fn($n) => str_contains($n, 'wanting closeness while bracing against it'))));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the anchor

    /**
     * Addendum 12. A first gift to each of them at home is an anchor of the bond, kept with the place.
     * An evening at the inn, then home again: arriving where it happened brings it back, felt in
     * words for a few turns (never a number), and a moment of passion her own size (who she is by
     * temperament); a second evening home the same day stirs nothing new (the cooldown), and staying
     * home is no arrival.
     */
    public function testComingHomeBringsBackTheFirstGiftEachHerOwnWay(): void
    {
        $this->seed(60);
        $t = $this->hello();
        $this->floors(30.0);
        $t = $this->play($t + 600, 5.0);
        $t = $this->round('I brought you something from the market.', $t + 600, 'gift');
        foreach (array_keys(self::BEDS) as $npc) {
            $a = RelDynMemory::anchors($this->dynamics($npc))['first_gift'] ?? null;
            $this->assertIsArray($a, "{$npc}: the first gift is an anchor");
            $this->assertEquals(['name' => 'Breezehome', 'hold' => 'Whiterun'], $a['place'], $npc);
            $this->assertSame('The player gave her a carved wooden bird', $a['event']);
            $this->assertArrayNotHasKey('first_meeting', RelDynMemory::anchors($this->dynamics($npc)), "{$npc}: partners long before: no first meeting");
        }
        // Staying home: no arrival, nothing brought back
        $t = $this->round('Nice evening.', $t + 600, 'stay');
        foreach (array_keys(self::BEDS) as $npc) $this->assertArrayNotHasKey('anchor', $this->felt[$npc]['stay'], $npc);

        // An evening at the inn
        $this->place = self::INN;
        $t = $this->round('Another round?', $t + 2 * (int) self::HOUR, 'inn');
        foreach (array_keys(self::BEDS) as $npc) $this->assertArrayNotHasKey('anchor', $this->felt[$npc]['inn'], $npc);
        $this->floors(30.0);   // the same floor for everyone, no moment left on it

        // Home again
        $this->place = self::HOME;
        $t = $this->round('Home at last.', $t + 2 * (int) self::HOUR, 'home');
        preg_match_all('/\[RelDyn-MEMORY\] (.+?): back at breezehome \(first_gift\) passion moment \+([\d.]+)/', (string) file_get_contents($this->errorLog), $m, PREG_SET_ORDER);
        $moment = [];
        foreach ($m as $hit) $moment[$hit[1]] = floatval($hit[2]);
        $this->probe('moments', $moment);
        foreach (array_keys(self::BEDS) as $npc) {
            $line = $this->felt[$npc]['home']['anchor'] ?? null;
            $this->assertSame("This place brings it back to {$npc}: the first gift from Kaida, right here.", $line, $npc);
            $this->assertGreaterThan(0.0, $moment[$npc] ?? 0.0, "{$npc}: a moment of passion " . json_encode($moment));
        }
        $this->assertCount(4, array_unique(array_map(fn($v) => round($v, 3), $moment)), 'four women, four moments ' . json_encode($moment));
        // The next turn at home still remembers; it is no new arrival and no new moment
        $t = $this->round('Sit with me.', $t + 600, 'home2');
        foreach (array_keys(self::BEDS) as $npc) $this->assertArrayHasKey('anchor', $this->felt[$npc]['home2'], $npc);
        preg_match_all('/back at breezehome/', (string) file_get_contents($this->errorLog), $all);
        $this->assertCount(4, $all[0], 'one arrival each');
        // Out and back the same evening: remembered again, but no second moment within the cooldown
        $this->place = self::INN;
        $t = $this->round('One more at the inn.', $t + (int) self::HOUR, 'inn2');
        $this->place = self::HOME;
        $this->round('Home again.', $t + (int) self::HOUR, 'home3');
        foreach (array_keys(self::BEDS) as $npc) $this->assertArrayHasKey('anchor', $this->felt[$npc]['home3'], $npc);
        preg_match_all('/back at breezehome \(first_gift\)(.*)/', (string) file_get_contents($this->errorLog), $arrivals);
        $this->assertCount(8, $arrivals[0]);
        $this->assertSame(4, count(array_filter($arrivals[1], fn($rest) => str_contains($rest, 'passion moment'))), 'the cooldown: one moment per evening');
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the mirror

    /**
     * The player profile design: every exchange with any of them is an observation of the player.
     * A reliable, steady player who keeps promises and helps, seen by all four, is ONE profile (the
     * player's, whoever saw it): dependable (trust 61-80), a rock, who shows love by doing things.
     * A stranger meeting the player for the first time has heard of it (trust up, a felt line of
     * it, no number) and it is her first meeting (an anchor). Opt-in, the beds sense it too: the
     * same words about the player for each of them, never a number.
     */
    public function testAReliablePlayerIsOneProfileSeenByAllFourAndHeardOfByAStranger(): void
    {
        $this->seed(60);
        $t = $this->hello();
        for ($i = 0; $i < 6; $i++) $t = $this->round('I kept my promise, the fence is mended.', $t + (int) (3 * self::HOUR), "kept{$i}");
        $p = RelDynMirror::profile();
        $this->probe('mirror', $p);
        $this->assertSame(28, $p['observations'], 'seven rounds of four: every exchange, once');
        $this->assertSame(4, $p['npcs']);
        $this->assertSame(4, $p['dimensions']['trust']['band'], 'dependable: ' . $p['dimensions']['trust']['score']);
        $this->assertSame('dependable, follows through, good to their word', $p['dimensions']['trust']['keywords']);
        $this->assertGreaterThan(50.0, $p['dimensions']['warmth']['score']);
        $this->assertSame('rock', $p['charisma']['primary']);
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $p['love_language']['primary']);
        $this->assertSame(25, $p['attachment']['at_count'], 'the pattern stands at the 25th observation');
        $this->assertSame('internal', $p['validation_locus']['locus']);
        $offset = RelDynMirror::reputationTrustOffset($p);
        $this->assertGreaterThanOrEqual(5.0, $offset);
        $this->assertLessThanOrEqual(15.0, $offset);
        // Off by default: nothing about the player's profile in their prompts
        foreach (array_keys(self::BEDS) as $npc) $this->assertArrayNotHasKey('player_mirror', $this->felt[$npc]['kept5'], $npc);

        // A stranger: word travels (the first impression's trust), and it is her first meeting
        $this->people = '|' . self::STRANGER . '|' . self::PLAYER . '|';
        $this->turn(self::STRANGER, 'Hello there.', $t + 600, 'stranger');
        $this->people = null;
        $m = $this->dynamics(self::STRANGER);
        $rep = RelDynReputation::jev($m);
        $this->probe('stranger', ['rep' => $rep, 'trust' => $m['dimensions']['trust'] ?? null, 'anchors' => $m[RelDynMemory::ANCHORS_KEY] ?? null]);
        $this->assertEqualsWithDelta(min($offset, RelationshipDynamics::REPUTATION_CAPS['trust']['max']), $rep['mirror_trust'] ?? 0.0, 0.01);
        $this->assertGreaterThan(0.0, floatval($rep['offsets']['trust'] ?? 0), 'held on his trust');
        $this->assertArrayHasKey('first_meeting', RelDynMemory::anchors($m), 'meeting a stranger is a first meeting');
        $this->assertSame('Breezehome', RelDynMemory::anchors($m)['first_meeting']['place']['name']);
        $heard = $this->felt[self::STRANGER]['stranger']['reputation'] ?? null;
        // (core's relationship block names the player to everyone: prompt gating's stranger_named)
        $this->assertSame('Mikael has heard that Kaida keeps their word, and meets them with an easy, open trust', $heard);

        // Opt in: each of them senses the same player, in words
        $this->config(['player_mirror' => ['prompt' => ['enabled' => true]]]);
        $this->round('Evening, all.', $t + (int) (2 * self::HOUR), 'sensed');
        $lines = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $line = $this->felt[$npc]['sensed']['player_mirror'] ?? null;
            $this->assertIsString($line, "{$npc}: " . json_encode(array_keys($this->felt[$npc]['sensed'])));
            $this->assertStringContainsString('dependable, follows through, good to their word', $line);
            $lines[$npc] = str_replace($npc, '{NAME}', $line);
        }
        $this->assertCount(1, array_unique($lines), 'one player, one mirror: ' . json_encode($lines));
        // The P5 spider graph: numbers for the page
        $spider = RelDynMirror::spider();
        $this->assertSame(['maturity', 'trust', 'warmth', 'respect', 'comfort', 'confidence'], array_column($spider['axes'], 'axis'));
        $this->assertSame(33, $spider['interactions']);
        $this->assertClean();
    }
}
