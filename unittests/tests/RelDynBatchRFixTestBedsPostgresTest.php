<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynBatchRFixBedsPgDb
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
 * Batch-R review fixes end to end on the four test beds (standing rule): Aela the Huntress, Ashe
 * (Serene's hand-set vector, never read; nothing of her story here), Muiri (toxic, nudged fearful)
 * and Lynly Star-Sung (shy bard), on CHIM 3.4.1 core-shaped rows through the real hooks
 * (prerequest -> context_pre -> context -> postrequest), the real eval producer and worker (the eval
 * LLM stubbed at the connector boundary), core's own consume and handover lines, Sharmat's scene
 * line, and core's memory table, memory_v and its own PackIntoSummary. No LLM call.
 *   drunk-state        her drinks are dated when she had them: last night's mead is not this
 *                      morning's drunk
 *   post-intimacy      a second scene in one drunken night brings no early sober verdict; the
 *                      morning judges the night once
 *   gift-delta-formula gold (and any fungible thing) is no recognized re-gift; a distinct thing is
 *   memory-translation the subtext note is packed by core with the exchange it belongs to
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBatchRFixTestBedsPostgresTest extends TestCase
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
    private RelDynBatchRFixBedsPgDb $db;
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
        $this->schema = 'reldyn_batchrfix_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynBatchRFixBedsPgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbatchrbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_batchr_fix_beds_test.log');
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

    /** The stored RelDyn config: the defaults with the debug log on, no internal weather, plus $over (merged per section). */
    private function config(array $over = []): void
    {
        pg_query($this->db->link, "DELETE FROM conf_opts WHERE id = '" . RelationshipDynamics::CONFIG_ROW_ID . "'");
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace_recursive(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false], $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * Core rows (friends of the player, core affinity 40; $known: npc => [name => relationship] of
     * the people she knows besides), voice types, placeholder templates and the seed's reads.
     */
    private function seed(array $config = [], array $known = []): void
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
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => 'friend']] + ($known[$name] ?? [])])]);
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
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', self::PLAYER, $line, 'The Bannered Mare', $npc, $this->realTs, $gamets, $gamets]);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], $label);
    }

    /** A Sharmat scene stage with the player and $npc (ext_nsfw_sexcene, as Sharmat's OStim handler reports it), alone. */
    private function scene(string $npc, int $gamets, string $label): void
    {
        $data = 'OStimScene/vaginal,romantic/Stage1_A1/' . self::PLAYER . "^dom,vaginal/{$npc}^sub,vaginal";
        $this->people = "|{$npc}|" . self::PLAYER . '|';
        $this->event('ext_nsfw_sexcene', $data, $gamets);
        $this->request($npc, ['ext_nsfw_sexcene', (string) $this->realTs, (string) $gamets, $data], $label);
        $this->people = null;
    }

    /** $npc drinks: the Consume action's own line (Commands.cpp), as core logs it. */
    private function consume(string $npc, string $item, int $gamets): void
    {
        $this->event('infoaction', "{$npc} consumes {$item}.", $gamets);
    }

    /** A page in $npc's diary as core's generateFollowerDiary stores it (people = the NPC). */
    private function diary(string $npc, int $gamets, string $content): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO diarylog (ts, gamets, topic, content, tags, people, location, sess, localts)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)',
            [(string) $this->realTs, $gamets, 'Night (Auto-diary: goodnight)', $content, 'Auto-diary,goodnight',
             $npc, 'The Bannered Mare', (string) $this->realTs, $this->realTs]);
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

    /** The shame verdicts on her drinking nights: night key => [asked points in order]. */
    private static function verdicts(array $d): array
    {
        $out = [];
        foreach ((array) ($d[RelDynSubstances::KEY]['shame'] ?? []) as $k => $s) {
            $out[(string) $k] = array_map(fn($v) => floatval($v[1]), (array) ($s['verdicts'] ?? []));
        }
        return $out;
    }

    /**
     * The eval LLM at the connector boundary: flirting is a romantic exchange (touch, intent 2);
     * "missed you" is a warm, meaningful exchange; anything else is small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $exchange = substr($content, (int) strpos($content, 'THIS EXCHANGE'));
            $flirt = str_contains($exchange, 'prettiest');
            $warm = str_contains($exchange, 'missed you');
            return json_encode([
                'signals' => ['affinity' => $flirt ? 3 : ($warm ? 2 : 0), 'trust' => $warm ? 2 : 0, 'comfort' => $flirt ? 4 : ($warm ? 2 : 0),
                              'respect' => 0, 'passion' => $flirt ? 5 : 0, 'maturity' => 0],
                'tags' => $flirt ? ['touch'] : ($warm ? ['quality_time'] : []),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $flirt || $warm ? 0.5 : 0.1,
                'summary' => $flirt ? 'The player flirted over the mead.' : ($warm ? 'The player said they missed her and stayed a while.' : 'Small talk.'),
                'romantic_intent' => $flirt ? 2 : 0,
                'charisma' => 'none',
            ]);
        };
    }

    /** $line to each of $npcs from $gamets (one game minute apart), then the real eval worker. */
    private function round(array $npcs, string $line, int $gamets, string $label): void
    {
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 60 * $i++, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    /** RELDYN_PROBE=1: print a scene's numbers (tuning aid, no effect on the test). */
    private function probe(string $label, array $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "\n=== {$label}\n" . json_encode($data, JSON_PRETTY_PRINT));
    }

    /** Feelings in front of the LLM, never numbers; no LLM call; no failed query; no PHP warning. */
    private function assertClean(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no LLM call (the eval is stubbed at its boundary)');
        $this->assertGreaterThan(0, $this->evalCalls);
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringNotContainsString('PHP Warning', $log);
        $this->assertStringNotContainsString('PHP Fatal', $log);
    }

    private static function ownX(array $d, string $dim): float
    {
        return self::x($d, $dim) - RelationshipDynamics::heldTemporaryOffset($d, $dim);
    }

    /** The felt line reads as $text (most of its words; intensity formatting may garble a few). */
    private static function reads(string $felt, string $text): bool
    {
        $words = fn(string $s) => array_values(array_filter(preg_split('/[^a-z_]+/', strtolower($s)), fn($w) => strlen($w) >= 4));
        $want = $words($text);
        return $want !== [] && count(array_intersect($want, $words($felt))) >= 0.7 * count($want);
    }

    // ------------------------------------------------------------------ drunk-state: the clock

    /**
     * Five meads each on one evening, 19:10 to 19:50, and no one speaks to them in between (her
     * own consume lines only). Aela and Muiri are spoken to at half past eight: the drink is in them
     * (the drinks dated when she had them, not when she is next seen). Ashe and Lynly are first
     * spoken to at nine the next morning: the drink cleared hours ago (about six game hours for
     * five), so they are sober, with no held maturity and no drunk line, and their diary need not
     * wait. Only the use is counted, dated at the last mug.
     */
    public function testLastNightsMeadIsNotThisMorningsDrunk(): void
    {
        $this->seed();
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 18.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        $mugs = [];
        for ($r = 1; $r <= 5; $r++) {
            $mugs[$r] = self::at(self::N0, 19.0) + (int) round($r * self::HOUR / 6);
            foreach ($beds as $npc) $this->consume($npc, 'Nord Mead', $mugs[$r]);
        }
        $evening = [self::AELA, 'Muiri'];
        $morning = ['Ashe', self::LYNLY];

        // Half past eight: the drink is in Aela and Muiri, dated at the mugs
        $this->round($evening, 'Still up?', self::at(self::N0, 20.5), 'evening');
        $this->round($evening, 'One more story?', self::at(self::N0, 20.75), 'evening2');
        foreach ($evening as $npc) {
            $d = $this->dynamics($npc);
            $s = $d[RelDynSubstances::KEY];
            $this->assertEqualsCanonicalizing(array_values($mugs), array_map(fn($x) => (int) $x['g'], $s['drinks']), "{$npc}: dated at the mugs");
            $this->assertGreaterThan(3.0, floatval($s['level']), "{$npc}: most of five meads still in her");
            $this->assertLessThan(-10.0, floatval($s['held']['maturity'] ?? 0), "{$npc}: the drink holds her maturity down");
            $this->assertArrayHasKey('substance_drunk', $this->felt[$npc]['evening2'], "{$npc}: she is felt drunk");
        }

        // The next morning: everyone is sober, whoever was seen last night
        $this->round($beds, 'Morning.', self::at(self::N0 + 1, 9.0), 'morning');
        $this->round($beds, 'Sleep well?', self::at(self::N0 + 1, 10.0), 'late');
        $info = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $s = $d[RelDynSubstances::KEY] ?? [];
            $info[$npc] = ['level' => $s['level'] ?? null, 'stage' => $s['stage'] ?? null, 'held' => $s['held'] ?? null,
                'use' => $s['use']['alcohol'] ?? null, 'felt' => array_keys($this->felt[$npc]['late'] ?? [])];
        }
        $this->probe('the morning after', $info);
        $why = json_encode($info);
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertFalse(RelDynSubstances::intoxicated($d), "{$npc}: sober {$why}");
            $this->assertFalse(RelDynDiary::intoxicated($d), "{$npc}: the diary need not wait {$why}");
            $this->assertNull($d[RelDynSubstances::KEY]['stage'] ?? null, "{$npc}: no stage {$why}");
            $this->assertArrayNotHasKey('held', $d[RelDynSubstances::KEY], "{$npc}: nothing held on her {$why}");
            $this->assertArrayNotHasKey('substance_drunk', $this->felt[$npc]['late'], "{$npc}: not felt drunk {$why}");
            $this->assertSame(5, intval($d[RelDynSubstances::KEY]['use']['alcohol']['uses']), "{$npc}: five uses counted {$why}");
            $this->assertSame($mugs[5], (int) $d[RelDynSubstances::KEY]['use']['alcohol']['last_use'], "{$npc}: the last use is the last mug {$why}");
            $this->assertSame([], array_values(array_filter((array) ($d['_active_consumables'] ?? []), fn($c) => ($c['key'] ?? '') === 'ale')),
                "{$npc}: no mead spike left {$why}");
        }
        // Divergence: who was seen while drinking felt it; the morning ones never had a drunk turn
        foreach ($morning as $npc) $this->assertArrayNotHasKey('evening2', $this->felt[$npc], $npc);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ post-intimacy: two scenes in one night

    /**
     * Five meads (a round with the player after each), a scene at eight, a second at half past
     * nine: a new encounter, and she is still drunk. The first scene's sober verdict does not land
     * then (no shame, no crash, the glow still shows); it waits for the sober morning, where the
     * night is judged once: the second scene's verdict adds nothing past the first. The shame is
     * her own size (maturity), so the four diverge.
     */
    public function testASecondSceneWaitsForTheSoberMorning(): void
    {
        $this->seed();
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 18.5);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        for ($r = 1; $r <= 5; $r++) {
            $m = self::at(self::N0, 19.0) + (int) round($r * self::HOUR / 6);
            foreach ($beds as $npc) $this->consume($npc, 'Nord Mead', $m);
            $this->round($beds, 'Another round!', $m + 30, "mead_{$r}");
        }
        $second = $before = [];
        foreach ($beds as $i => $npc) {
            $this->scene($npc, self::at(self::N0, 20.0) + (int) round($i * self::HOUR / 12), 'scene1');
            $d = $this->dynamics($npc);
            $this->assertSame(RelDynPostIntimacy::DRUNK, $d[RelDynPostIntimacy::KEY]['outcome'] ?? null, "{$npc}: the drunken night");
        }
        foreach ($beds as $i => $npc) {
            $before[$npc] = $this->dynamics($npc);
            $this->scene($npc, self::at(self::N0, 21.5) + (int) round($i * self::HOUR / 12), 'scene2');
            $second[$npc] = $this->dynamics($npc);
        }
        $info = [];
        foreach ($beds as $npc) {
            $d = $second[$npc];
            $info[$npc] = ['level' => $d[RelDynSubstances::KEY]['level'] ?? null, 'stage' => $d[RelDynSubstances::KEY]['stage'] ?? null,
                'rs' => [self::x($before[$npc], 'resentment_self'), self::x($d, 'resentment_self')],
                'comfort' => [self::ownX($before[$npc], 'comfort'), self::ownX($d, 'comfort')],
                'state' => $d[RelDynPostIntimacy::KEY] ?? null, 'felt' => $this->felt[$npc]['scene2']['post_intimacy'] ?? null];
        }
        $why = json_encode($info);
        $glow = RelDynPostIntimacy::config()['outcomes'][RelDynPostIntimacy::DRUNK]['glow'];
        foreach ($beds as $npc) {
            $d = $second[$npc];
            $this->assertTrue(RelDynSubstances::intoxicated($d), "{$npc}: still drunk at the second scene {$why}");
            $this->assertSame(RelDynPostIntimacy::DRUNK, $d[RelDynPostIntimacy::KEY]['outcome'], "{$npc}: drunk again {$why}");
            $this->assertCount(1, $d[RelDynPostIntimacy::KEY]['deferred'] ?? [], "{$npc}: the first verdict waits {$why}");
            $this->assertEqualsWithDelta(self::x($before[$npc], 'resentment_self'), self::x($d, 'resentment_self'), 1e-6, "{$npc}: no shame while drunk {$why}");
            $this->assertGreaterThan(self::ownX($before[$npc], 'comfort') - 2.0, self::ownX($d, 'comfort'), "{$npc}: no crash while drunk {$why}");
            $this->assertArrayNotHasKey('shame', (array) $d[RelDynSubstances::KEY], "{$npc}: no verdict yet {$why}");
            $this->assertTrue(self::reads((string) $info[$npc]['felt'], $glow), "{$npc}: the glow still shows {$why}");
        }

        // The sober morning: both verdicts are due; the night is judged once
        $this->round($beds, 'Morning. About last night...', self::at(self::N0 + 1, 9.0), 'morning');
        $asked = $morningInfo = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $s = $d[RelDynPostIntimacy::KEY] ?? [];
            $shame = array_values((array) ($d[RelDynSubstances::KEY]['shame'] ?? []));
            $morningInfo[$npc] = ['state' => $s, 'shame' => $shame, 'rs' => self::x($d, 'resentment_self'), 'comfort' => self::ownX($d, 'comfort'),
                'maturity' => RelDynDiary::ownMaturity($d), 'felt' => $this->felt[$npc]['morning']['post_intimacy'] ?? null];
        }
        $this->probe('two scenes, one night', $morningInfo);
        $why = json_encode($morningInfo);
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $m = $morningInfo[$npc];
            $this->assertFalse(RelDynSubstances::intoxicated($d), "{$npc}: sober {$why}");
            $this->assertSame([], $m['state']['deferred'] ?? [], "{$npc}: nothing waits {$why}");
            $this->assertTrue($m['state']['corrected'] ?? false, "{$npc}: the second verdict landed too {$why}");
            if (RelDynDiary::depth($m['maturity']) === 'shallow') continue;   // a shallow mind never looks back
            $this->assertCount(1, $m['shame'], "{$npc}: one night {$why}");
            $rs = array_map(fn($v) => floatval($v[1]), $m['shame'][0]['verdicts']);
            $this->assertCount(2, $rs, "{$npc}: both scenes asked {$why}");
            $this->assertEqualsWithDelta($rs[0], $rs[1], 1e-6, "{$npc}: the same ask {$why}");
            $asked[$npc] = $rs[0];
            $comfort = array_map(fn($v) => floatval($v[1]), $m['shame'][0]['dims']['comfort'] ?? []);
            $this->assertCount(2, $comfort, "{$npc}: both scenes' comfort on the night's record {$why}");
            // one shame: the rise is one verdict's, not two
            $once = $before[$npc];
            $wantRs = RelationshipDynamics::applyDelta('resentment_self', $once, $rs[0], $once['inferred_temperament'] ?? null);
            $rise = $m['rs'] - self::x($before[$npc], 'resentment_self');
            $this->assertLessThan(1.5 * $wantRs, $rise, "{$npc}: not two shames {$why}");
            $this->assertGreaterThan(0.5 * $wantRs, $rise, "{$npc}: one shame {$why}");
            $this->assertIsString($m['felt'], "{$npc}: the sober self shows in the morning {$why}");
        }
        $this->assertGreaterThanOrEqual(2, count($asked), "most of them look back {$why}");
        $this->assertGreaterThanOrEqual(2, count(array_unique(array_map(fn($v) => round($v, 2), $asked))), "each her own size of shame {$why}");
        $this->assertClean();
    }

    // ------------------------------------------------------------------ gift-delta-formula: fungible

    /**
     * Farengar paid the player 500 gold for a job; Hulda gave him two meads; Aela gave him a
     * silver necklace. All four beds know Farengar and Hulda; Lynly and Ashe also know Aela. The
     * player tips each of them twenty gold (and Muiri a mead): gold is gold and mead is mead, so it
     * is an ordinary gift for all four, never "she knows whose it was". The necklace he hands
     * Lynly is a thing she can recognize: that is the re-gift (trust and respect fall, no gift).
     */
    public function testGoldIsNoRecognizedRegiftButANecklaceIs(): void
    {
        $knows = ['Farengar Secret-Fire' => ['aff' => 10, 'type' => 'acquaintance'], 'Hulda' => ['aff' => 20, 'type' => 'acquaintance']];
        $aela = [self::AELA => ['aff' => 30, 'type' => 'friend']];
        $this->seed([], [self::AELA => $knows, 'Ashe' => $knows + $aela, 'Muiri' => $knows, self::LYNLY => $knows + $aela]);
        $beds = array_keys(self::BEDS);
        $this->event('itemfound', 'Farengar Secret-Fire gave 500 gold to ' . self::PLAYER, self::at(self::N0 - 2, 12.0));
        $this->event('itemfound', 'Hulda gave 2 Nord Mead to ' . self::PLAYER, self::at(self::N0 - 2, 20.0));
        $this->event('itemfound', self::AELA . ' gave 1 Silver Necklace to ' . self::PLAYER, self::at(self::N0 - 1, 12.0));
        $t = self::at(self::N0, 12.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Good day.', $t, 'hello');

        $give = [self::AELA => ['20 Gold'], 'Ashe' => ['20 Gold'], 'Muiri' => ['20 Gold', '1 Nord Mead'], self::LYNLY => ['20 Gold', '1 Silver Necklace']];
        $pre = $after = $felt = [];
        $i = 0;
        foreach ($give as $npc => $items) {
            foreach ($items as $j => $item) {
                $d = $this->dynamics($npc);
                $pre[$npc][$j] = ['trust' => self::x($d, 'trust'), 'respect' => self::x($d, 'respect')];
                $at = $t + 600 + 1200 * $i++;
                $this->event('itemfound', self::PLAYER . " gave {$item} to {$npc}", $at);
                $this->people = "|{$npc}|" . self::PLAYER . '|';
                $this->turn($npc, 'This is for you.', $at + 60, "gift{$j}");
                $this->people = null;
                $d = $this->dynamics($npc);
                $after[$npc][$j] = ['trust' => self::x($d, 'trust'), 'respect' => self::x($d, 'respect')];
                $felt[$npc][$j] = $d['_last_gift_felt'] ?? null;
            }
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $why = json_encode(['pre' => $pre, 'after' => $after, 'felt' => $felt]);
        $this->probe('gifts', ['pre' => $pre, 'after' => $after, 'felt' => $felt]);
        foreach ($give as $npc => $items) {
            foreach ($items as $j => $item) {
                if ($item === '1 Silver Necklace') continue;
                $this->assertGreaterThan($pre[$npc][$j]['trust'] - 1.0, $after[$npc][$j]['trust'], "{$npc}: {$item} costs no trust {$why}");
                $this->assertGreaterThan($pre[$npc][$j]['respect'] - 1.0, $after[$npc][$j]['respect'], "{$npc}: {$item} costs no respect {$why}");
                $this->assertStringNotContainsString('knows whose', (string) $felt[$npc][$j], "{$npc}: {$item} {$why}");
            }
        }
        // The necklace: Lynly knows whose it was
        $this->assertLessThan($pre[self::LYNLY][1]['trust'] - 2.0, $after[self::LYNLY][1]['trust'], "Lynly: the re-gift {$why}");
        $this->assertLessThan($pre[self::LYNLY][1]['respect'] - 2.0, $after[self::LYNLY][1]['respect'], "Lynly: the re-gift {$why}");
        $this->assertStringContainsString('knows whose Silver Necklace', (string) $felt[self::LYNLY][1], $why);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ memory: packed by core

    /**
     * With commit on, the subtext note of each bed's warm exchange sits in core's memory table
     * beside the exchange. Core's own PackIntoSummary (lib/data_functions.php) packs memory_v into
     * memory_summary in queues cut by location; the note carries the exchange's location as the
     * speech rows do, so the whole evening at the Mare is one packed memory with all four notes in
     * it, none cut off on its own and lost.
     */
    public function testTheSubtextNotesArePackedWithTheirExchange(): void
    {
        $this->seed(['memory_translation' => ['commit' => ['enabled' => true]]]);
        $beds = array_keys(self::BEDS);
        $t0 = self::at(self::N0, 19.0);
        $this->event('infoloc', self::MARE, $t0 - 3000);
        foreach ($beds as $i => $npc) {
            $at = $t0 + (int) round($i * self::HOUR / 12);
            $this->people = "|{$npc}|" . self::PLAYER . '|';
            $this->turn($npc, 'I missed you. Tell me about your day.', $at, 'warm');
            // her answer, as core logs the NPC's spoken line
            pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', $npc, 'A long one. Sit, I will tell you.', 'The Bannered Mare', self::PLAYER, $this->realTs, $at + 30, $at + 30]);
        }
        $this->people = null;
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $notes = array_column(pg_fetch_all(pg_query_params($this->db->link, 'SELECT message FROM memory WHERE event = $1 ORDER BY gamets',
            [RelDynMemory::NOTE_EVENT])) ?: [], 'message');
        $this->assertCount(4, $notes, 'one note per exchange');
        foreach ($notes as $n) $this->assertStringStartsWith('(Context Location:The Bannered Mare) (Beneath this moment with Kaida, ', $n);

        // Core packs when the game clock is well past it (its own guard), with its default settings
        $this->event('infoloc', self::MARE, self::at(self::N0 + 2, 9.0));
        $GLOBALS['FEATURES'] = ['MEMORY_EMBEDDING' => ['AUTO_CREATE_SUMMARY_INTERVAL' => 10, 'AUTO_CREATE_SUMMARY_MIN_EVENTS' => 5]];
        PackIntoSummary();
        $this->assertSame([], $this->db->failures);
        $packed = pg_fetch_all(pg_query($this->db->link, "SELECT n, packed_message FROM memory_summary WHERE classifier = 'dialogue' ORDER BY gamets_truncated")) ?: [];
        $this->probe('packed', $packed);
        $this->assertCount(1, $packed, 'the evening at the Mare is one memory ' . json_encode($packed));
        $this->assertSame(12, intval($packed[0]['n']), 'four lines of his, four of theirs, four notes');
        foreach ($notes as $n) $this->assertStringContainsString($n, $packed[0]['packed_message']);
        foreach ($beds as $npc) $this->assertStringContainsString("(Context Location:The Bannered Mare) {$npc}: A long one.", $packed[0]['packed_message']);
        $this->assertClean();
    }
}
