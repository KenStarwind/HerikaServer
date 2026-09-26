<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynP4rIntegrationBedsPgDb
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
 * Where the p4r lanes meet (reldyn-v0.18), end to end on the four test beds (standing rule):
 * Aela the Huntress, Ashe (Serene's hand-set vector, never read; nothing of her story here),
 * Muiri (toxic, nudged fearful) and Lynly Star-Sung (shy bard), on CHIM 3.4.1 core-shaped rows
 * through the real hooks (prerequest -> context_pre -> context -> postrequest), the real eval
 * producer and worker (the eval LLM stubbed at the connector boundary), core's own consume lines,
 * Sharmat's scene line, core's diarylog and core's memory table with the memory_v view. No LLM call.
 *   drunk-state x post-intimacy x diary   one drunken night: five meads (her own drink), flirting
 *                                         (the ledger), then a scene when the mead's consumable
 *                                         window has long closed but the drink is still in her. The
 *                                         sober self judges it ONCE for its shame, whichever of the
 *                                         two verdicts (the scene's morning, the diary's page) comes
 *                                         first; each bed's own attraction decides whether the diary
 *                                         has anything to add.
 *   memory x mf-coordinates               the subtext note in core's memory reads the envelope as
 *                                         each bed carries herself toward the player now.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynP4rIntegrationTestBedsPostgresTest extends TestCase
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
    private RelDynP4rIntegrationBedsPgDb $db;
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
        $this->schema = 'reldyn_p4rint_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynP4rIntegrationBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the calls themselves are stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdp4rintbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_p4r_integration_beds_test.log');
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

    /** Core rows (friends of the player, core affinity 40), voice types, placeholder templates and the seed's reads. */
    private function seed(array $config = []): void
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
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => 'friend']]])]);
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

    // ------------------------------------------------------------------ drunk-state x post-intimacy x diary

    /**
     * One drunken night on every bed. Five meads at the Mare (her own consume lines), the player
     * flirting from the third (the drunk self's ledger), then, an hour and a half after the last
     * mug, a scene with each, alone: the mead's consumable window closed long ago, the drink is still
     * in her, so it is the drunken night of the draft for all four. The sober self then judges it:
     * Aela and Muiri write their diary page in the small hours, before the scene's morning verdict
     * is due; Ashe and Lynly write theirs after breakfast, so the morning comes first. Whatever the
     * order, one night is one shame: the heavier verdict counts, never both. Each verdict keeps the
     * rest of what it says: the scene's comfort crash for all four, the flirting's gains taken back
     * by the diary of a bed who is not drawn to the player (Aela, Ashe); the diary of one who is
     * (Muiri, Lynly) has nothing to add.
     */
    public function testOneDrunkenNightIsOneShameWhoeverJudgesFirst(): void
    {
        $this->seed();
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 19.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        for ($r = 1; $r <= 5; $r++) {
            $t = self::at(self::N0, 19.0) + (int) round($r * self::HOUR / 6);   // a mug every ten game minutes
            foreach ($beds as $npc) $this->consume($npc, 'Nord Mead', $t);
            $this->round($beds, $r >= 3 ? 'You are the prettiest thing in Whiterun tonight.' : 'Another round!', $t + 30, "mead_{$r}");
        }

        // The scenes, an hour and a half after the last mug
        $scene = [];
        foreach ($beds as $i => $npc) {
            $scene[$npc] = self::at(self::N0, 21.5) + (int) round($i * self::HOUR / 6);
            $this->scene($npc, $scene[$npc], 'scene');
            $d = $this->dynamics($npc);
            $window = array_filter((array) ($d['_active_consumables'] ?? []),
                fn($c) => in_array((string) ($c['key'] ?? ''), (array) RelDynPostIntimacy::config()['intoxicants'], true));
            $this->assertSame([], array_values($window), "{$npc}: the mead's consumable window has closed");
            $this->assertTrue(RelDynSubstances::intoxicated($d), "{$npc}: the drink is still in her");
            $this->assertSame(RelDynPostIntimacy::DRUNK, $d[RelDynPostIntimacy::KEY]['outcome'] ?? null,
                "{$npc}: the drunken night (her own drink) " . json_encode($d[RelDynPostIntimacy::KEY] ?? null));
            $this->assertCount(1, $d[RelDynSubstances::KEY]['nights'] ?? [], "{$npc}: the flirting is in the night's ledger");
        }
        $night = $this->all();

        // The small hours: sober; Aela and Muiri write their page before the scene's verdict is due
        $this->diary(self::AELA, self::at(self::N0 + 1, 2.5), 'The Mare. The mead. Him. My head hurts.');
        $this->diary('Muiri', self::at(self::N0 + 1, 2.5), 'I stayed out far too late at the Mare.');
        $this->round($beds, 'Can\'t sleep either?', self::at(self::N0 + 1, 2.75), 'late');
        $late = $this->all();
        foreach ($beds as $npc) {
            $this->assertFalse(RelDynSubstances::intoxicated($late[$npc]), "{$npc}: sober");
            $this->assertEmpty($late[$npc][RelDynPostIntimacy::KEY]['corrected'] ?? false, "{$npc}: the scene's verdict is not due yet");
        }
        foreach ([self::AELA, 'Muiri'] as $npc) {
            $this->assertArrayHasKey('last_sober', $late[$npc][RelDynSubstances::KEY], "{$npc}: her page judged the night");
        }

        // The morning: every scene's verdict is due; Ashe and Lynly write their page after it
        $this->diary('Ashe', self::at(self::N0 + 1, 8.0), 'A night at the Mare I had better think about.');
        $this->diary(self::LYNLY, self::at(self::N0 + 1, 8.0), 'I sang at the Mare, then... oh.');
        $this->round($beds, 'Morning. About last night...', self::at(self::N0 + 1, 9.0), 'morning');
        $after = $this->all();

        $info = [];
        foreach ($beds as $npc) {
            $s = $after[$npc][RelDynPostIntimacy::KEY] ?? null;
            $info[$npc] = ['verdicts' => self::verdicts($after[$npc]), 'endorsed' => $after[$npc][RelDynSubstances::KEY]['last_sober']['endorsed'] ?? 'none',
                'correction' => $s['correction'] ?? null, 'maturity' => RelDynDiary::ownMaturity($after[$npc]),
                'rs' => ['night' => self::x($night[$npc], 'resentment_self'), 'late' => self::x($late[$npc], 'resentment_self'),
                         'after' => self::x($after[$npc], 'resentment_self')],
                'comfort' => ['night' => self::x($night[$npc], 'comfort') - RelationshipDynamics::heldTemporaryOffset($night[$npc], 'comfort'),
                              'after' => self::x($after[$npc], 'comfort') - RelationshipDynamics::heldTemporaryOffset($after[$npc], 'comfort')],
                'felt' => array_keys($this->felt[$npc]['morning'] ?? [])];
        }
        $this->probe('one night, one shame', $info);
        $why = json_encode($info);

        foreach ($beds as $npc) {
            $this->assertNotSame('shallow', RelDynDiary::depth($info[$npc]['maturity']), "{$npc}: a diary that looks back {$why}");
            $s = $after[$npc][RelDynPostIntimacy::KEY];
            $this->assertTrue($s['corrected'], "{$npc}: the scene's verdict landed {$why}");
            $this->assertArrayHasKey('comfort', $s['correction'], "{$npc}: the scene's comfort crash is whole {$why}");
            $this->assertArrayHasKey('post_intimacy', $this->felt[$npc]['morning'], "{$npc}: the sober self shows {$why}");
            $this->assertGreaterThan($info[$npc]['rs']['night'], $info[$npc]['rs']['after'], "{$npc}: ashamed of the night {$why}");
        }

        // Aela, diary first, not drawn to him: the page asked its share, the morning only the excess
        $this->assertLessThan(0.5, $info[self::AELA]['endorsed'], $why);
        $v = array_values($info[self::AELA]['verdicts'])[0] ?? [];
        $this->assertCount(2, $v, "Aela: two verdicts on one night {$why}");
        [$S, $P] = $v;
        $this->assertGreaterThan($info[self::AELA]['rs']['night'], $info[self::AELA]['rs']['late'], "Aela: the page's shame first {$why}");
        $excess = $late[self::AELA];
        $wantExcess = RelationshipDynamics::applyDelta('resentment_self', $excess, max(0.0, $P - $S), $late[self::AELA]['inferred_temperament'] ?? null);
        $whole = $late[self::AELA];
        $wantWhole = RelationshipDynamics::applyDelta('resentment_self', $whole, $P, $late[self::AELA]['inferred_temperament'] ?? null);
        $this->assertEqualsWithDelta($wantExcess, floatval($info[self::AELA]['correction']['resentment_self'] ?? 0.0), 0.25,
            "Aela: the morning adds only what exceeds the page {$why}");
        $this->assertLessThan($wantWhole - 0.5, floatval($info[self::AELA]['correction']['resentment_self'] ?? 0.0), "Aela: not a second whole shame {$why}");

        // Ashe, the morning first, not drawn to him: the page takes back the flirting, adds no shame past the morning's
        $this->assertLessThan(0.5, $info['Ashe']['endorsed'], $why);
        $v = array_values($info['Ashe']['verdicts'])[0] ?? [];
        $this->assertCount(2, $v, "Ashe: two verdicts on one night {$why}");
        [$P, $S] = $v;
        $morningRise = $info['Ashe']['rs']['after'] - $info['Ashe']['rs']['late'];
        $pageOnTop = $morningRise - floatval($info['Ashe']['correction']['resentment_self']);
        $onTop = $after['Ashe'];
        $wantOnTop = $S > $P ? RelationshipDynamics::applyDelta('resentment_self', $onTop, $S - $P, $after['Ashe']['inferred_temperament'] ?? null) : 0.0;
        $this->assertEqualsWithDelta($wantOnTop, $pageOnTop, 0.25, "Ashe: the page adds only what exceeds the morning {$why}");
        $this->assertLessThan(RelationshipDynamics::getCoreAffinity($night['Ashe']), RelationshipDynamics::getCoreAffinity($after['Ashe']),
            "Ashe: the flirting's gains are taken back {$why}");

        // Muiri and Lynly are drawn to him: their page stands behind the night and adds nothing; the scene's verdict is whole
        foreach (['Muiri', self::LYNLY] as $npc) {
            $this->assertEqualsWithDelta(1.0, $info[$npc]['endorsed'], 1e-9, "{$npc}: drawn to him soberly {$why}");
            foreach ($info[$npc]['verdicts'] as $list) $this->assertCount(1, $list, "{$npc}: only the scene's verdict {$why}");
        }
        $this->assertEqualsWithDelta($info['Muiri']['rs']['night'], $info['Muiri']['rs']['late'], 0.5, "Muiri: her page added no shame {$why}");

        // Divergence: who she is and when she writes decide which verdict speaks first and whether the page adds anything
        $this->assertNotEquals(array_map('count', array_map(fn($v) => array_values($v)[0] ?? [], array_column($info, 'verdicts'))),
            [2, 2, 2, 2], $why);
        // Jev has the numbers of both
        foreach ($beds as $npc) {
            $j = RelDynJev::state($npc, $after[$npc], (float) self::at(self::N0 + 1, 9.0));
            $this->assertStringContainsString('post_intimacy=' . RelDynPostIntimacy::DRUNK, $j['text'], $npc);
            $this->assertArrayHasKey('substances', $j, $npc);
        }
        $this->assertClean();
    }

    // ------------------------------------------------------------------ memory x mf-coordinates

    /**
     * The subtext note core's summary packer reads beside an exchange (memory translation layer,
     * commit on) and how each bed carries herself toward the player (the derived envelope, Fix 6)
     * are the same read: Ashe has come to trust him and be at ease (as the editor would set it),
     * Muiri has neither trust, ease nor respect for him. After a warm exchange with each, every
     * bed's note is written, holds no number, and its envelope clause is the quadrant she is in
     * NOW, not the anchor her traits set; the four notes differ.
     */
    public function testTheMemoryNoteCarriesTheEnvelopeAsEachBedFeelsIt(): void
    {
        $this->seed(['memory_translation' => ['commit' => ['enabled' => true]]]);
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 18.0);
        $this->event('infoloc', self::MARE, $t - 100);
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        $this->editDynamics('Ashe', function (array &$d): void {
            $d['dimensions']['trust']['x'] = 90.0;
            $d['dimensions']['comfort']['x'] = 90.0;
        });
        $this->editDynamics('Muiri', function (array &$d): void {
            foreach (['trust', 'comfort', 'respect'] as $dim) $d['dimensions'][$dim]['x'] = 5.0;
        });
        $t0 = self::at(self::N0, 19.0);
        foreach ($beds as $i => $npc) {
            $this->people = "|{$npc}|" . self::PLAYER . '|';
            $this->turn($npc, 'I missed you. Tell me about your day.', $t0 + (int) round($i * self::HOUR / 6), 'warm');
        }
        $this->people = null;
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));

        $cfg = RelDynMemory::config();
        $open = $cfg;
        $open['translation']['max_clauses'] = 20;
        $open['translation']['token_budget'] = 1000;
        $notes = $info = [];
        foreach ($beds as $i => $npc) {
            $d = $this->dynamics($npc);
            $mf = RelDynMoodAxes::derivedCoords($d);
            $at = $t0 + (int) round($i * self::HOUR / 6);
            $rows = pg_fetch_all(pg_query_params($this->db->link,
                'SELECT message FROM memory WHERE event = $1 AND gamets BETWEEN $2 AND $3', [RelDynMemory::NOTE_EVENT, $at - 5, $at + 5])) ?: [];
            $this->assertCount(1, $rows, "{$npc}: one subtext note beside the exchange");
            $notes[$npc] = (string) $rows[0]['message'];
            $clauses = RelDynMemory::clauses($d, $npc, self::PLAYER, [], $open);
            $info[$npc] = ['mf' => $mf, 'stored' => ['m' => self::x($d, 'coord_m'), 'f' => self::x($d, 'coord_f')],
                'note' => $notes[$npc], 'mf_clause' => $clauses['mf'] ?? null];
        }
        $this->probe('notes', $info);
        $why = json_encode($info);
        foreach ($beds as $npc) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $notes[$npc], "{$npc}: words, never numbers {$why}");
            $mf = $info[$npc]['mf'];
            $q = RelationshipDynamics::getMFQuadrantBand($mf['coord_m'], $mf['coord_f'])['quadrant'];
            if (max(abs($mf['coord_m']), abs($mf['coord_f'])) >= floatval($cfg['translation']['mf_min_magnitude'])) {
                $this->assertSame($cfg['text']['clauses']['mf'][$q], $info[$npc]['mf_clause'], "{$npc}: the envelope she is in now {$why}");
            }
            // whatever envelope text the note carries is the live one
            foreach ($cfg['text']['clauses']['mf'] as $quadrant => $text) {
                if ($quadrant !== $q) $this->assertStringNotContainsString($text, $notes[$npc], "{$npc}: not the anchor's {$why}");
            }
        }
        // the live envelope is not the anchor: trust and ease moved Ashe toward +F, their absence moved Muiri away
        $this->assertGreaterThan($info['Ashe']['stored']['f'] + 10.0, $info['Ashe']['mf']['coord_f'], $why);
        $this->assertLessThan($info['Muiri']['stored']['f'] - 10.0, $info['Muiri']['mf']['coord_f'], $why);
        $this->assertCount(4, array_unique($notes), "four beds, four memories {$why}");
        $this->assertClean();
    }
}
