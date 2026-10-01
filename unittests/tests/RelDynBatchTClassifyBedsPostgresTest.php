<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynBatchTClassifyBedsPgDb
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
 * Batch T, lane "classify", end to end on the four test beds (standing rule): Aela the Huntress, Ashe
 * (Serene's hand-set vector, never read; nothing of her story here), Muiri (toxic, nudged fearful)
 * and Lynly Star-Sung (shy bard), on CHIM 3.4.1 core-shaped rows through the real hooks
 * (prerequest -> context_pre -> context -> postrequest), the real eval producer and worker (the eval
 * LLM stubbed at the connector boundary), core's own handover lines and Sharmat's scene line.
 * No LLM call.
 *   interaction-classification  a ring and a potion handed over are gifts and service: each bed's
 *                               own needs decide who is fulfilled; once per handover, eval on or off
 *   conflict-repair             the 1.5x on the eval's passion gain; three positive eval items
 *                               through the worker close the conflict with the +20 burst
 *   jealousy-core               open flirting with Lynly in front of the romantic beds; a Sharmat
 *                               scene makes its witnesses jealous without the eval
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBatchTClassifyBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 170;   // game day of the evening
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
    private const MARE = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Fredas, 8:00 PM, 17th of Last Seed, 4E 201, current weather: indoors)';

    private string $dsn;
    private string $schema;
    private RelDynBatchTClassifyBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** CACHE_PEOPLE for the next requests (everyone at the Mare, by default) */
    private ?string $people = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_tclassify_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog and diarylog
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
        // core's speech table (data/database_default.sql plus the live 3.4.1 mood / emotion columns)
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
        // data/database_default.sql memory_summary (+ db_updates tags / scope / native_vec; the vector columns left out)
        pg_query($admin, "CREATE TABLE memory_summary (gamets_truncated bigint NOT NULL, n integer, packed_message text, summary text,
            classifier text, uid integer NOT NULL, rowid serial NOT NULL, companions text, tags text, scope text, native_vec tsvector)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
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
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynBatchTClassifyBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'FEATURES'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the calls themselves are stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdtclassifybeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_tclassify_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        RelDynDiary::$launcher = function (): void {};
        RelDynDiary::$llm = function () { $this->llmCalls++; return null; };
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        RelDynDiary::$launcher = null;
        RelDynDiary::$llm = null;
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

    // ------------------------------------------------------------------ fixture

    private function config(array $over = []): void
    {
        pg_query($this->db->link, "DELETE FROM conf_opts WHERE id = '" . RelationshipDynamics::CONFIG_ROW_ID . "'");
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace_recursive(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false], $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * Core rows (friends of the player unless $types says otherwise, core affinity 40; $known: npc => [name => relationship] of
     * the people she knows besides), voice types, placeholder templates and the seed's reads.
     */
    private function seed(array $config = [], array $known = [], array $types = []): void
    {
        $this->config($config);
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
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => $types[$name] ?? 'friend']] + ($known[$name] ?? [])])]);
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
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld')");
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode(['level' => 20])]);
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $this->people(), '', $state]);
    }

    private function people(): string
    {
        return $this->people ?? '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /** $npc's request through the real hooks as main.php runs them (the reply logged as core logs it). */
    private function request(string $npc, array $request, string $label): void
    {
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', (int) $request[2], 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, 'default', self::PLAYER, $this->realTs, (int) $request[2], (int) $request[2]]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
        }
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** One player line to $npc at $gamets, logged as core logs it (input row, spoken line, then the reply). */
    private function turn(string $npc, string $line, int|float $gamets, string $label): void
    {
        $gamets = (int) $gamets;
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', self::PLAYER, $line, 'The Bannered Mare', $npc, $this->realTs, $gamets, $gamets]);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], $label);
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

    private function all(): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) $out[$npc] = $this->dynamics($npc);
        return $out;
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }


    /** RELDYN_PROBE=1: print a scene's numbers (tuning aid, no effect on the test). */
    private function probe(string $label, array $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "\n=== {$label}\n" . json_encode($data, JSON_PRETTY_PRINT));
    }

    /** Feelings in front of the LLM, never numbers; no LLM call; no failed query; no PHP warning. */
    private function assertClean(bool $evalRan = true): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no LLM call (the eval is stubbed at its boundary)');
        if ($evalRan) $this->assertGreaterThan(0, $this->evalCalls);
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringNotContainsString('PHP Warning', $log);
        $this->assertStringNotContainsString('PHP Fatal', $log);
    }

    /** The felt line reads as $text (most of its words; intensity formatting may garble a few). */
    private static function reads(string $felt, string $text): bool
    {
        $words = fn(string $s) => array_values(array_filter(preg_split('/[^a-z_]+/', strtolower($s)), fn($w) => strlen($w) >= 4));
        $want = $words($text);
        return $want !== [] && count(array_intersect($want, $words($felt))) >= 0.7 * count($want);
    }

    // ------------------------------------------------------------------ helpers of this lane

    /** The eval stub's script: callable(string $exchange): array of reply fields over the small-talk default. */
    private $script = null;

    /**
     * The eval LLM at the connector boundary: only THIS EXCHANGE's lines reach the script (not the
     * task text), which answers from what the player said.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            preg_match('/THIS EXCHANGE \(score only this\):\R(.*?)\RTASK:/s', $content, $m);
            $exchange = (string) ($m[1] ?? '');
            if (getenv('RELDYN_DUMPEX')) fwrite(STDERR, "
--- exchange: " . json_encode($exchange) . "
");
            $reply = [
                'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => [],
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => 0.1,
                'summary' => 'Small talk.',
                'romantic_intent' => 0,
                'charisma' => 'none',
            ];
            $over = $this->script !== null ? ($this->script)($exchange) : [];
            if (isset($over['tags'])) { $reply['tags'] = $over['tags']; unset($over['tags']); }
            return json_encode(array_replace_recursive($reply, $over));
        };
    }

    private function worker(): void
    {
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    /** $line to each of $npcs from $gamets (one game minute apart), then the real eval worker. */
    private function round(array $npcs, string $line, int|float $gamets, string $label): void
    {
        $gamets = (int) $gamets;
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 60 * $i++, $label);
        $this->worker();
    }

    /** A positive exchange of the given kind (the eval scores what it reads). */
    private static function warm(array $extra = []): array
    {
        return array_replace_recursive(['signals' => ['affinity' => 3, 'trust' => 1, 'comfort' => 2, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'significance' => 0.5, 'summary' => 'Kind words.'], $extra);
    }

    /** $axis level of $npc's fulfillment state with the player, at raw game time $at (null: she has no such need). */
    private function level(string $npc, string $axis, float $at): ?float
    {
        $s = RelDynFulfillment::pairState($this->dynamics($npc));
        if (!is_array($s) || !array_key_exists($axis, (array) ($s['lv'] ?? []))) return null;
        return RelDynFulfillment::levelsAt($s, $at)[$axis];
    }

    /** The player hands $npc one $item: core's own itemfound row, then the player speaks. */
    private function give(string $npc, string $item, string $line, int $at, string $label): void
    {
        $this->event('itemfound', self::PLAYER . " gave 1 {$item} to {$npc}", $at);
        $this->turn($npc, $line, $at + 60, $label);
    }

    private function coreType(string $npc, string $type): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][self::PLAYER]['type'] = $type;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
    }

    // ------------------------------------------------------------------ interaction-classification

    /** units of one handover: the 'gift' / 'help' row of tag_delivery at handover_significance */
    private static function handoverUnits(string $tag): float
    {
        $fc = RelDynFulfillment::config();
        $floor = floatval($fc['significance_floor']);
        $sig = floatval(RelDynGifts::config()['handover_significance']);
        return floatval($fc['tag_delivery'][$tag][$tag === 'gift' ? RelationshipDynamics::LL_GIFTS : RelationshipDynamics::LL_SERVICE]) * ($floor + (1 - $floor) * $sig);
    }

    /**
     * A ruby ring, then a potion of healing, handed to each bed (core's itemfound row, then the
     * player speaks). The ring is a gift: it fulfills a gifts need once; the potion looks after her
     * (service). Each bed has the need her own read gave her (Lynly's gifts, Aela's service pinned),
     * so the same two handovers fulfill different beds: no need, no delivery. Once per handover:
     * with the eval off, with it on and silent about the handover, and with it tagging exactly what
     * it reads (gift / help), the delivery is the same one.
     */
    private function runHandovers(bool $evalOn, bool $evalTags): array
    {
        $this->seed(['eval_producer' => ['enabled' => $evalOn]]);
        $this->script = $evalTags
            ? function (string $ex): array {
                $tags = str_contains($ex, 'ruby ring') ? ['gift'] : (str_contains($ex, 'potion') ? ['help'] : []);
                return self::warm(['tags' => $tags]);
            }
            : null;
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 18.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        // The needs each bed's own read gave her, pinned where the test needs a known one
        $this->editDynamics(self::LYNLY, function (array &$d) {
            $d['love_language_primary'] = RelationshipDynamics::LL_GIFTS;
            $d['love_language_secondary'] = RelationshipDynamics::LL_WORDS;
        });
        $this->editDynamics(self::AELA, function (array &$d) {
            $d['love_language_primary'] = RelationshipDynamics::LL_SERVICE;
            $d['love_language_secondary'] = RelationshipDynamics::LL_TOUCH;
        });
        $this->round($beds, 'Anything on your mind?', $t + 2 * self::HOUR, 'pin');

        $gifts = RelationshipDynamics::LL_GIFTS;
        $service = RelationshipDynamics::LL_SERVICE;
        $seen = [];
        $i = 0;
        foreach ($beds as $npc) {
            foreach ([['Ruby Ring', 'This ruby ring is for you.', $gifts], ['Potion of Healing', 'Take this potion, you will need it.', $service]] as [$item, $line, $axis]) {
                $at = $t + (int) ((4 + 3 * $i++) * self::HOUR);
                $before = $this->level($npc, $axis, $at + 60);
                $others = [$gifts => $this->level($npc, $gifts, $at + 60), $service => $this->level($npc, $service, $at + 60)];
                $this->give($npc, $item, $line, $at, "give{$i}");
                if ($evalOn) $this->worker();
                $after = $this->level($npc, $axis, $at + 60);
                $seen[$npc][$item] = ['axis' => $axis, 'before' => $before, 'after' => $after,
                    'delta' => $before === null || $after === null ? null : round($after - $before, 4)];
                // the other love language is not touched by this handover
                $otherAxis = $axis === $gifts ? $service : $gifts;
                $seen[$npc][$item]['other_delta'] = $others[$otherAxis] === null ? null
                    : round($this->level($npc, $otherAxis, $at + 60) - $others[$otherAxis], 4);
            }
        }
        $this->probe("handovers evalOn={$evalOn} tags={$evalTags}", $seen);
        return $seen;
    }

    private function assertHandoversFulfilledOnce(array $seen, string $why): void
    {
        $ring = self::handoverUnits('gift');
        $potion = self::handoverUnits('help');
        $this->assertEqualsWithDelta(0.65, $ring, 1e-9);
        $this->assertEqualsWithDelta(0.65, $potion, 1e-9);
        $j = json_encode($seen);
        // Lynly (gifts primary): the ring is delivered once; the potion has no need to meet
        $this->assertNotNull($seen[self::LYNLY]['Ruby Ring']['after'], "{$why}: Lynly has a gifts need {$j}");
        $this->assertEqualsWithDelta($ring, $seen[self::LYNLY]['Ruby Ring']['delta'], 0.04, "{$why}: Lynly's ring, once {$j}");
        $this->assertNull($seen[self::LYNLY]['Potion of Healing']['after'], "{$why}: Lynly has no need of being looked after {$j}");
        // Aela (service primary): the potion is delivered once; the ring has no need to meet
        $this->assertNotNull($seen[self::AELA]['Potion of Healing']['after'], "{$why}: Aela has a service need {$j}");
        $this->assertEqualsWithDelta($potion, $seen[self::AELA]['Potion of Healing']['delta'], 0.04, "{$why}: Aela's potion, once {$j}");
        $this->assertNull($seen[self::AELA]['Ruby Ring']['after'], "{$why}: Aela has no need of gifts {$j}");
        // every bed: a need is fulfilled by exactly its kind of handover, one unit each
        foreach ($seen as $npc => $items) {
            foreach ($items as $item => $s) {
                if ($s['delta'] === null) continue;   // no such need: nothing to deliver to
                $this->assertEqualsWithDelta(0.65, $s['delta'], 0.04, "{$why}: {$npc} {$item} {$j}");
                $this->assertEqualsWithDelta(0.0, (float) $s['other_delta'], 0.04, "{$why}: {$npc} {$item} touches only its own language {$j}");
            }
        }
    }

    public function testARingAndAPotionFulfillEachBedsOwnNeedsOnceWithTheEvalOff(): void
    {
        $seen = $this->runHandovers(false, false);
        $this->assertHandoversFulfilledOnce($seen, 'eval off');
        $this->assertClean(false);
    }

    public function testARingAndAPotionFulfillEachBedsOwnNeedsOnceWithTheEvalSilentAboutThem(): void
    {
        $seen = $this->runHandovers(true, false);
        $this->assertHandoversFulfilledOnce($seen, 'eval on, untagged');
        $this->assertClean();
    }

    /** The eval tags what it reads, a gift and help: the same handover, delivered once, not twice. */
    public function testTheEvalTaggingTheSameHandoverDoesNotDeliverItTwice(): void
    {
        $seen = $this->runHandovers(true, true);
        $this->assertHandoversFulfilledOnce($seen, 'eval on, tagging gift / help');
        // the ledger paired them (one row, one tag of the eval: one handover)
        $ledger = $this->dynamics(self::LYNLY)[RelDynGifts::LEDGER_KEY] ?? [];
        $this->assertNotSame([], $ledger);
        $this->assertContains(true, array_column($ledger, 'paired'), json_encode($ledger));

        // Words alone (no itemfound row): the eval's tag delivers the gift, once
        $at = self::at(self::N0, 18.0) + (int) (40 * self::HOUR);
        $before = $this->level(self::LYNLY, RelationshipDynamics::LL_GIFTS, $at + 60);
        $this->turn(self::LYNLY, 'Here, a ruby ring I found, for you.', $at, 'words');
        $this->worker();
        $this->assertEqualsWithDelta(0.65, $this->level(self::LYNLY, RelationshipDynamics::LL_GIFTS, $at + 60) - $before, 0.04);
        $this->assertClean();
    }

    /**
     * Review fix (interaction-classification): the eval's 'help' (or 'gift') tag is one delivery with a
     * handover row only when that exchange held the handover. An unrelated help exchange (the player
     * helped her with a wolf, nothing changed hands) within the pairing hour of a potion handover is its
     * own delivery, and so is the potion: neither drops the other, in either order. Aela, service
     * primary. Each phase sits a day and a half after the last, and a phase alone is its own control.
     */
    public function testAnUnrelatedHelpExchangeNextToAPotionHandoverKeepsBothDeliveries(): void
    {
        $this->seed(['eval_producer' => ['enabled' => true]]);
        // the eval is silent about the potion line and tags the wolf as help
        $this->script = fn(string $ex): array => str_contains($ex, 'wolf') ? self::warm(['tags' => ['help']]) : self::warm();
        $t = self::at(self::N0, 18.0);
        $service = RelationshipDynamics::LL_SERVICE;
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round([self::AELA], 'Evening.', $t, 'hello');
        $this->editDynamics(self::AELA, function (array &$d) {
            $d['love_language_primary'] = RelationshipDynamics::LL_SERVICE;
            $d['love_language_secondary'] = RelationshipDynamics::LL_TOUCH;
        });
        $this->round([self::AELA], 'Anything on your mind?', $t + 2 * self::HOUR, 'pin');

        $delta = function (int $at, callable $act) use ($service): float {
            // her service need starts empty each phase (the level is capped: deliveries must have room), and
            // both levels are read at one moment after it, so only what was delivered differs
            $this->editDynamics(self::AELA, function (array &$d) use ($service) {
                $s = RelDynFulfillment::pairState($d);
                $s['lv'][$service] = 0.0;
                RelDynFulfillment::setPairState($d, RelDynFulfillment::PLAYER, $s);
            });
            $m = $at + (int) self::HOUR;
            $before = $this->level(self::AELA, $service, $m);
            $act();
            $this->worker();
            return round($this->level(self::AELA, $service, $m) - $before, 4);
        };
        // each exchange is evaluated as it happens (the worker runs after every reply)
        $potion = function (int $at) {
            $this->give(self::AELA, 'Potion of Healing', 'Take this potion, you will need it.', $at, "potion{$at}");
            $this->worker();
        };
        $wolf = function (int $at) {
            $this->turn(self::AELA, 'I drove off that wolf that was after you.', $at, "wolf{$at}");
            $this->worker();
        };

        $day = (int) self::DAY;
        $p1 = $t + (int) (3 * $day / 2);
        $p2 = $p1 + (int) (3 * $day / 2);
        $p3 = $p2 + (int) (3 * $day / 2);
        $p4 = $p3 + (int) (3 * $day / 2);
        $alonePotion = $delta($p1, fn() => $potion($p1));
        $aloneWolf = $delta($p2, fn() => $wolf($p2));
        // the wolf, twenty game minutes after a potion handover of a different exchange
        $potionThenWolf = $delta($p3, function () use ($potion, $wolf, $p3) { $potion($p3); $wolf($p3 + (int) (self::HOUR / 3)); });
        // the potion, twenty game minutes after an unrelated wolf exchange
        $wolfThenPotion = $delta($p4, function () use ($potion, $wolf, $p4) { $wolf($p4); $potion($p4 + (int) (self::HOUR / 3)); });
        $this->probe('help next to a potion', compact('alonePotion', 'aloneWolf', 'potionThenWolf', 'wolfThenPotion'));

        $this->assertEqualsWithDelta(0.65, $alonePotion, 0.04, 'the potion alone is one handover');
        $this->assertGreaterThan(0.2, $aloneWolf, 'the eval tag alone is a delivery');
        $why = json_encode(compact('alonePotion', 'aloneWolf', 'potionThenWolf', 'wolfThenPotion'));
        $this->assertEqualsWithDelta($alonePotion + $aloneWolf, $potionThenWolf, 0.06, "both delivered, potion first {$why}");
        $this->assertEqualsWithDelta($alonePotion + $aloneWolf, $wolfThenPotion, 0.06, "both delivered, wolf first {$why}");
        $this->assertClean();
    }

    // ------------------------------------------------------------------ conflict-repair

    /**
     * A positive eval passion gain while a conflict is open lands 1.5x (conflict_repair_passion_mult),
     * on every bed's own state: the same gain on the same NPC, the conflict the only difference. Then
     * three positive eval items through the worker close it: the felt line goes none, one, two, and
     * the +20 burst lands with the third.
     */
    public function testAnOpenConflictLiftsTheEvalsPassionGainAndThreePositiveItemsRepairIt(): void
    {
        $this->seed(['eval_producer' => ['enabled' => true]]);
        $this->script = fn(string $ex): array => self::warm(['signals' => ['passion' => 3], 'tags' => ['apology']]);
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 18.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        $mult = (float) RelationshipDynamics::getConfig()['conflict_repair_passion_mult'];
        $this->assertSame(1.5, $mult);

        $report = [];
        $k = 0;
        foreach ($beds as $npc) {
            // (1) the multiplier: one passion signal on two copies of her state, only the conflict differs
            $calm = RelationshipDynamics::getDynamics($npc);
            $calm['in_conflict'] = false;
            $hot = $calm;
            $hot['in_conflict'] = true;
            $gain = fn(array $d) => RelationshipDynamics::applyEvalSignal($npc, $d, 'passion', 4.0, ['apology'], 0.9)['actual'];
            $g0 = $gain($calm);
            $g1 = $gain($hot);
            RelationshipDynamics::endRequest();
            $report[$npc]['gain'] = [$g0, $g1];
            $this->assertGreaterThan(0.0, $g0, "{$npc}: a passion gain to lift");
            $this->assertEqualsWithDelta($mult * $g0, $g1, 0.02 * $g0 + 1e-6, "{$npc}: 1.5x with the conflict open");

            // (2) the repair, through the worker
            $this->editDynamics($npc, function (array &$d) {
                $d['in_conflict'] = true;
                $d['conflict_positive_count'] = 0;
                $d['jealousy_anger'] = 0.0;
            });
            $stages = [];
            $passion = [];
            foreach ([1, 2, 3, 4] as $n) {
                $at = $t + (int) ((3 + 2 * $k++) * self::HOUR);
                $this->turn($npc, "I am sorry about earlier, really. ({$n})", $at, "repair{$n}");
                $this->worker();
                $d = $this->dynamics($npc);
                $stages[$n] = ['conflict_text' => $this->felt[$npc]["repair{$n}"]['conflict'] ?? null,
                    'in_conflict' => !empty($d['in_conflict']), 'count' => (int) ($d['conflict_positive_count'] ?? 0)];
                $passion[$n] = round(RelationshipDynamics::getPassion($d), 2);
            }
            $report[$npc]['stages'] = $stages;
            $report[$npc]['passion'] = $passion;
            $why = json_encode($report[$npc]);
            $this->assertNotNull($stages[1]['conflict_text'], "{$npc}: the felt line at 0 positives {$why}");
            $this->assertNotNull($stages[2]['conflict_text'], "{$npc}: the felt line at 1 positive {$why}");
            $this->assertNotNull($stages[3]['conflict_text'], "{$npc}: the felt line at 2 positives {$why}");
            $this->assertNotSame($stages[1]['conflict_text'], $stages[2]['conflict_text'], "none -> one {$why}");
            $this->assertNotSame($stages[2]['conflict_text'], $stages[3]['conflict_text'], "one -> two {$why}");
            $this->assertNull($stages[4]['conflict_text'], "{$npc}: repaired, no conflict line {$why}");
            $this->assertTrue($stages[3]['in_conflict'] === false, "{$npc}: three positives resolve it {$why}");
            $this->assertGreaterThan($passion[2] + 5.0, $passion[3], "{$npc}: the repair burst lands with the third item {$why}");
            // the burst itself (config conflict_repair_passion_burst, 20 raw, through her own attraction): once, with the third item
            preg_match_all('/\[EVAL\] ' . preg_quote($npc, '/') . ' item .*?"repair_burst":([0-9.]+)/', (string) file_get_contents($this->errorLog), $mm);
            $bursts = array_values(array_filter(array_map('floatval', $mm[1]), fn($v) => $v > 0));
            $report[$npc]['burst'] = $bursts;
            $this->assertCount(1, $bursts, "{$npc}: the burst lands once " . json_encode($report[$npc]));
            $this->assertGreaterThan(5.0, $bursts[0], "{$npc}: " . json_encode($report[$npc]));
        }
        $this->probe('repair', $report);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ jealousy-core

    private function jealousy(string $npc): float
    {
        return floatval($this->dynamics($npc)['jealousy_anger'] ?? 0);
    }

    /**
     * The player flirts openly with Lynly in the Mare (words only: no touch, no intimacy tag; the
     * eval reads romantic_intent 2) in front of Aela, Ashe and Muiri, who are all romantic with the
     * player. Through the real producer and worker each of them gains jealousy with Lynly as the
     * rival, by who she is (temperament, attachment, trust); her next turn carries the felt band.
     * Lynly, the one flirted with, is not jealous of herself.
     */
    public function testOpenFlirtingInFrontOfThePartnersMakesThemJealousThroughTheWorker(): void
    {
        $partners = [self::AELA, 'Ashe', 'Muiri'];
        $this->seed(['eval_producer' => ['enabled' => true]], [], array_fill_keys($partners, 'romantic'));
        $this->script = fn(string $ex): array => str_contains($ex, 'prettiest')
            ? self::warm(['signals' => ['passion' => 3], 'romantic_intent' => 2, 'summary' => 'The player flirted openly.'])
            : [];
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 20.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        foreach ($partners as $p) $this->assertSame(0.0, $this->jealousy($p), "{$p}: nobody is jealous yet");

        $trace = [];
        for ($i = 1; $i <= 6; $i++) {
            $at = $t + (int) ($i * 0.3 * self::HOUR);
            $this->round([self::LYNLY], 'You are the prettiest bard in all Whiterun, and I mean it.', $at, "flirt{$i}");
            foreach ($partners as $p) {
                $this->turn($p, 'Hm?', $at + 1000, "next{$i}");
                $d = $this->dynamics($p);
                $trace[$i][$p] = ['jealousy' => round(floatval($d['jealousy_anger'] ?? 0), 2), 'rival' => $d['jealousy_trigger_npc'] ?? null,
                    'band' => RelationshipDynamics::getJealousyBand(floatval($d['jealousy_anger'] ?? 0)),
                    'line' => $this->felt[$p]["next{$i}"]['jealousy'] ?? null, 'conflict' => !empty($d['in_conflict'])];
            }
            $trace[$i][self::LYNLY] = $this->jealousy(self::LYNLY);
        }
        $this->probe('flirting', $trace);
        if (getenv('RELDYN_DUMPLOG')) fwrite(STDERR, "
=== errorlog
" . substr((string) file_get_contents($this->errorLog), -6000));
        $why = json_encode($trace);

        foreach ($partners as $p) {
            $this->assertGreaterThan(0.0, $trace[1][$p]['jealousy'], "{$p}: open flirting is seen by a partner, no tag needed {$why}");
            $this->assertSame(self::LYNLY, $trace[6][$p]['rival'], "{$p}: her rival is Lynly {$why}");
            $this->assertGreaterThan($trace[1][$p]['jealousy'], $trace[6][$p]['jealousy'], "{$p}: it builds {$why}");
        }
        $this->assertSame(0.0, $trace[6][self::LYNLY], 'the one flirted with is not jealous');
        // who she is decides how much: the toxic bed more than the Stoic-leaning one
        $this->assertGreaterThan($trace[6]['Ashe']['jealousy'], $trace[6]['Muiri']['jealousy'], "Muiri (toxic) is jealous well before Ashe {$why}");
        // Aela end to end: six open flirts, and her next turn carries the edgy line (the band's text names no
        // rival: the template names one from 'unsettled'; the rival is hers in her state)
        $this->assertSame('edgy', $trace[6][self::AELA]['band'], "Aela's jealousy reached the edgy band {$why}");
        $this->assertNotNull($trace[6][self::AELA]['line'], "Aela's next turn carries the edgy line {$why}");
        // the toxic bed's reaches 'unsettled' and opens a conflict; the secure-leaning ones' do not yet
        $this->assertTrue($trace[6]['Muiri']['conflict'], "Muiri's jealousy opens a conflict {$why}");
        $this->assertFalse($trace[6][self::AELA]['conflict'], $why);
        // the band reaches her next turn as a feeling: the edgy line from jealousy 20, the rival named from 'unsettled' (40)
        foreach ($partners as $p) {
            for ($i = 1; $i <= 6; $i++) {
                $band = $trace[$i][$p]['band'];
                if ($band === 'none') { $this->assertNull($trace[$i][$p]['line'], "{$p} round {$i}: below the bands, no jealousy line"); continue; }
                // the line shows the NEXT turn after the item applied (the worker ran before it)
                $this->assertNotNull($trace[$i][$p]['line'], "{$p} round {$i}: band {$band} {$why}");
                if ($band !== 'edgy') $this->assertStringContainsString(self::LYNLY, (string) $trace[$i][$p]['line'], "{$p} round {$i}: the {$band} line names the rival {$why}");
            }
        }
        $this->assertClean();
    }

    /** A Sharmat scene stage with the player and $npc, witnesses as CHIM's people list has them. */
    private function sceneStage(string $npc, array $witnesses, int|float $gamets, string $label): void
    {
        $gamets = (int) $gamets;
        $data = 'OStimScene/vaginal,romantic/Stage1_A1/' . self::PLAYER . "^dom,vaginal/{$npc}^sub,vaginal";
        $this->people = '|' . implode('|', array_merge([$npc], $witnesses, [self::PLAYER])) . '|';
        $this->event('ext_nsfw_sexcene', $data, $gamets);
        $this->request($npc, ['ext_nsfw_sexcene', (string) $this->realTs, (string) $gamets, $data], $label);
        $this->people = null;
    }

    /**
     * Intimacy the plugin reports is an observed fact: the committed witnesses in the room are
     * jealous at the request itself, whether or not the eval scores it. Aela (romantic) stands in the
     * room, Muiri (romantic) is elsewhere. The scene's later stages are one scene (no second scan inside
     * the cooldown); the eval's own item for it does not scan again; a new scene later does.
     */
    private function runSceneWitnesses(bool $evalOn): array
    {
        $this->seed(['eval_producer' => ['enabled' => $evalOn]], [], [self::AELA => 'romantic', 'Muiri' => 'romantic', 'Ashe' => 'platonic']);
        $sceneOn = false;   // the scene's exchange reads as touch; the evening's small talk before it does not
        $this->script = function (string $ex) use (&$sceneOn): array {
            return $sceneOn ? self::warm(['tags' => ['touch'], 'significance' => 0.6, 'romantic_intent' => 1]) : [];
        };
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 21.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        $cool = floatval(RelationshipDynamics::getConfig()['jealousy_scene_cooldown_game_minutes']);
        $this->assertSame(30.0, $cool);
        $minute = self::HOUR / 60;

        $j = fn() => ['Aela' => $this->jealousy(self::AELA), 'Muiri' => $this->jealousy('Muiri'), 'Ashe' => $this->jealousy('Ashe')];
        $log = ['start' => $j()];
        $sceneOn = true;
        // stage 1 with Aela watching: she is jealous the moment the request is handled, before any eval runs
        $this->sceneStage(self::LYNLY, [self::AELA, 'Ashe'], (int) ($t + 30 * $minute), 'stage1');
        $log['stage1_request'] = $j();
        if ($evalOn) { $this->worker(); $log['stage1_worker'] = $j(); }
        // stage 2, five game minutes on: the same scene
        $this->sceneStage(self::LYNLY, [self::AELA, 'Ashe'], (int) ($t + 35 * $minute), 'stage2');
        if ($evalOn) $this->worker();
        $log['stage2'] = $j();
        // a new scene two hours later
        $this->sceneStage(self::LYNLY, [self::AELA, 'Ashe'], (int) ($t + 150 * $minute), 'scene2');
        if ($evalOn) $this->worker();
        $log['scene2'] = $j();
        $this->probe("scene witnesses evalOn={$evalOn}", $log);
        return $log;
    }

    private function assertSceneWitnesses(array $log): void
    {
        $why = json_encode($log);
        $this->assertSame(0.0, $log['start']['Aela']);
        $this->assertGreaterThan(0.0, $log['stage1_request']['Aela'], "Aela saw it: jealous at the request {$why}");
        $this->assertSame($log['start']['Muiri'], $log['stage1_request']['Muiri'], "Muiri was not there {$why}");
        $this->assertSame($log['start']['Ashe'], $log['stage1_request']['Ashe'], "Ashe saw it but is not committed (platonic) {$why}");
        if (isset($log['stage1_worker'])) {
            $this->assertEqualsWithDelta($log['stage1_request']['Aela'], $log['stage1_worker']['Aela'], 1e-6, "the eval's item for the scene does not scan again {$why}");
        }
        $this->assertEqualsWithDelta($log['stage1_request']['Aela'], $log['stage2']['Aela'], 1e-6, "stage 2 is the same scene {$why}");
        $this->assertGreaterThan($log['stage2']['Aela'], $log['scene2']['Aela'], "a new scene: she is jealous again {$why}");
        $this->assertEqualsWithDelta($log['scene2']['Aela'] - $log['stage2']['Aela'], $log['stage1_request']['Aela'], 0.5, "one scan's worth each time {$why}");
        $this->assertSame($log['start']['Muiri'], $log['scene2']['Muiri'], $why);
    }

    public function testASharmatSceneMakesItsWitnessesJealousAtTheRequestWithTheEvalOn(): void
    {
        $this->assertSceneWitnesses($this->runSceneWitnesses(true));
        $this->assertClean();
    }

    /** No eval at all: the plugin's report alone is enough. */
    public function testASharmatSceneMakesItsWitnessesJealousWithTheEvalOff(): void
    {
        $this->assertSceneWitnesses($this->runSceneWitnesses(false));
        $this->assertClean(false);
    }
}
