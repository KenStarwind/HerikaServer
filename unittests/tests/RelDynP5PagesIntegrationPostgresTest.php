<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynP5IntegPgDb
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
 * v0.19 integration of the three P5 lanes (hub, per-NPC editor, player profile + debug pages): every
 * RelDyn page served by `php -S` against a throwaway schema (CHIM's own bootstrap, the test database
 * pre-seeded as $db through auto_prepend_file), the four test beds (Aela the Huntress, Ashe with
 * Serene's hand-set vector (never read: nothing of her story anywhere), Muiri, Lynly Star-Sung)
 * built through the real eval consumer, plus an NPC with no trait read yet and one with a hostile
 * name. Checks: every page answers 200 with no PHP warning, notice or deprecation; every link and
 * GET form between the pages resolves (the file exists, the target answers, an in-page anchor has
 * its id); the pages link one another; names stay escaped; a page view writes nothing and queues
 * no trait read; a POST without the session's token is refused by every page; the player page's
 * opt-in is what the hub then shows. No LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynP5PagesIntegrationPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 190;
    private const ROUNDS = 4;
    private const WANDERER = 'Test Wanderer';
    private const EVIL = '<b onmouseover="alert(1)">Eve & \'Co\'</b>';
    private const BEDS = [
        // name => [template key, race, class, factions, skills]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55]],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30]],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20]],
    ];
    /** The RelDyn pages a viewer can open (debug_compose is text/plain, the rest HTML in core chrome). */
    private const HTML_PAGES = ['settings.php', 'npc.php', 'player.php', 'debug_pipeline.php'];

    private static ?string $dsn = null;
    private static string $schema = '';
    private static ?RelDynP5IntegPgDb $db = null;
    private static $server = null;
    private static int $port = 0;
    private static string $tmp = '';
    private static array $savedGlobals = [];
    private static $prevErrorLog = null;
    private static int $errOffset = 0;

    // =====================================================================
    // fixture
    // =====================================================================

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) return;
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) throw new RuntimeException('refusing to run against the live dwemer database');
        self::$dsn = $dsn;
        self::$schema = 'reldyn_p5integ_' . getmypid() . '_' . bin2hex(random_bytes(3));
        self::$tmp = sys_get_temp_dir() . '/reldyn_p5integ_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir(self::$tmp . '/sessions', 0700, true);

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, 'CREATE SCHEMA ' . self::$schema);
        pg_query($admin, 'SET search_path TO ' . self::$schema);
        self::createSchema($admin);
        pg_close($admin);

        self::$db = new RelDynP5IntegPgDb($dsn, self::$schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest'] as $key) {
            self::$savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        self::clearReldynGlobals();
        $GLOBALS['db'] = self::$db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        self::$prevErrorLog = ini_set('error_log', self::$tmp . '/error.log');
        Logger::setCustomLog(self::$tmp . '/logger.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { file_put_contents(self::$tmp . '/llm.log', "trait read (fixture)\n", FILE_APPEND); return null; };
        RelDynEval::$launcher = function (): void {};
        pg_query_params(self::$db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['player_name', self::PLAYER]);
        pg_query_params(self::$db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['internal_weather_enabled' => false]))]);
        RelationshipDynamics::clearConfigCache();
        self::seed();
        self::buildState();
        // the wanderer's first turns queued her trait read; clear it so a page view that queues one shows
        pg_query(self::$db->link, "DELETE FROM reldyn_trait_reads WHERE status = 'pending'");
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dsn === null) return;
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);   // our own php -S only
            proc_close(self::$server);
        }
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        ini_set('error_log', self::$prevErrorLog === false ? '' : (string) self::$prevErrorLog);
        foreach (self::$savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        self::clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close(self::$db->link);
        $admin = pg_connect(self::$dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, 'DROP SCHEMA ' . self::$schema . ' CASCADE');
        pg_close($admin);
        self::rmTree(self::$tmp);
    }

    protected function setUp(): void
    {
        if (self::$dsn === null) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        $GLOBALS['db'] = self::$db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        RelationshipDynamics::clearConfigCache();
        @file_put_contents(self::$tmp . '/server_llm.log', '');
    }

    protected function tearDown(): void
    {
        if (self::$dsn === null) return;
        $this->assertSame('', (string) @file_get_contents(self::$tmp . '/server_llm.log'), 'no LLM call and no worker launch from any page');
    }

    /** Production-shaped tables (lib/core/database_schema/core_npc_master.sql, data/database_default.sql). */
    private static function createSchema($admin): void
    {
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
        pg_query($admin, "CREATE SEQUENCE memory_rowid_seq");
        pg_query($admin, "CREATE TABLE memory (speaker text, message text, session text, uid serial NOT NULL, listener text,
            localts bigint, gamets bigint NOT NULL, momentum text, rowid bigint NOT NULL DEFAULT nextval('memory_rowid_seq'),
            event character varying(64), ts bigint)");
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
    }

    /** The beds (core rows, placeholder templates, the seed's reads; Ashe never read), the wanderer (no read yet), the hostile name. */
    private static function seed(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $rows = self::BEDS + [self::WANDERER => ['test_wanderer', 'ImperialRace', 'Pilgrim', [], []]];
        foreach ($rows as $name => [$key, $race, $class, $factions, $skills]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params(self::$db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params(self::$db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($key === 'ashe' || $key === 'test_wanderer') continue;   // Ashe: hand-set, never read; the wanderer: no read yet
            $e = $seed['reads'][$key];
            pg_query_params(self::$db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query_params(self::$db->link, 'INSERT INTO core_npc_master (npc_name, gender, race, extended_data) VALUES ($1, $2, $3, $4::jsonb)',
            [self::EVIL, 'female', 'NordRace', json_encode(['class' => ['name' => 'Citizen'], 'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'acquaintance']]])]);
        pg_query(self::$db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    /** One contract item as the eval producer writes it (a kept promise). */
    private static function item(string $npc, int $gamets): array
    {
        return [
            'v' => RelationshipDynamics::EVAL_CONTRACT_VERSION, 'source' => RelationshipDynamics::EVAL_CONTRACT_SOURCE,
            'npc' => $npc, 'gamets' => $gamets,
            'signals' => ['affinity' => 2, 'trust' => 3, 'comfort' => 2, 'respect' => 1, 'passion' => 0, 'maturity' => 0],
            'tags' => ['help'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5, 'positive_interaction' => true,
            'summary' => 'The player kept a promise and helped her mend the fence.',
            'romantic_intent' => 0, 'charisma' => 'rock',
        ];
    }

    /** ROUNDS evenings through the eval inbox (as the eval worker applies them) for every tracked NPC. */
    private static function buildState(): void
    {
        $t = self::N0 * self::DAY + 18 * self::HOUR;
        $last = 0;
        $npcs = array_merge(array_keys(self::BEDS), [self::WANDERER, self::EVIL]);
        for ($r = 0; $r < self::ROUNDS; $r++) {
            foreach ($npcs as $i => $npc) {
                $g = (int) round($t + $r * self::DAY + $i * 1.5 * self::HOUR);
                pg_query_params(self::$db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
                    VALUES ('inputtext', \$1, 'pending', \$2, 0, \$2, '', '')", [self::PLAYER . ": I kept my promise. (Talking to {$npc})", $g]);
                self::assertTrue(RelationshipDynamics::queuePendingEval($npc, self::item($npc, $g)), "{$npc}: queued");
                $d = RelationshipDynamics::getDynamics($npc);
                RelationshipDynamics::applyEvalInbox($npc, $d);
                RelationshipDynamics::endRequest();
                self::clearReldynGlobals();
                $last = max($last, $g);
            }
        }
        pg_query_params(self::$db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
            VALUES ('request', '', 'web', \$1, 0, \$1, '', '')", [$last + (int) self::HOUR]);
    }

    private static function startServer(): void
    {
        $prepend = self::$tmp . '/prepend.php';
        $engine = realpath(__DIR__ . '/../..');
        $code = <<<'PHP'
<?php
// RelDynP5PagesIntegrationPostgresTest: the test database as core's $db, before the page boots
$dsn = getenv('RELDYN_P5I_DSN');
$schema = (string) getenv('RELDYN_P5I_SCHEMA');
if (!$dsn || preg_match('/dbname\s*=\s*dwemer\b/', $dsn) || !preg_match('/^reldyn_p5integ_[0-9a-f_]+$/', $schema)) { http_response_code(500); exit('no throwaway database'); }
final class RelDynP5IntegServerDb
{
    public $link;
    public function __construct(string $dsn, string $schema) { $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW); pg_query($this->link, "SET search_path TO {$schema}"); }
    public function fetchOne($q, array $params = []) { $r = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q); if (!$r) { error_log('[test db] ' . pg_last_error($this->link)); return []; } return pg_fetch_assoc($r) ?: []; }
    public function fetchAll($q, $log = false) { $r = @pg_query($this->link, $q); if (!$r) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link)); $rows = []; while ($row = pg_fetch_assoc($r)) $rows[] = $row; return $rows; }
    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q) { $r = @pg_query($this->link, $q); if (!$r) error_log('[test db] ' . pg_last_error($this->link)); return $r; }
    public function insert($table, $data) { $c = array_keys($data); $p = []; foreach ($c as $i => $_) $p[] = '$' . ($i + 1); return @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $c) . ') VALUES (' . implode(', ', $p) . ')', array_values($data)); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
    public function close() {}
}
$GLOBALS['chim_interaction_generation'] = 0;
$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = new RelDynP5IntegServerDb($dsn, $schema);
// core's navbar reads $GLOBALS["TTS"]["ZONOS_GRADIO"]["endpoint"] (inside an HTML comment, ui/tmpl/navbar.php);
// a real install's TTS profile defines it, this throwaway one has none
$GLOBALS['TTS'] = ['ZONOS_GRADIO' => ['endpoint' => '']];
require_once __ENGINE__ . '/lib/logger.php';
require_once __ENGINE__ . '/ext/relationship_dynamics/relationship_dynamics.php';
Logger::setCustomLog(getenv('RELDYN_P5I_TMP') . '/server_logger.log');
$rdP5iLog = getenv('RELDYN_P5I_LLM_LOG');
RelDynTraitRead::$launcher = function () use ($rdP5iLog) { file_put_contents($rdP5iLog, "trait-read worker launch\n", FILE_APPEND); };
RelDynTraitRead::$llm = function () use ($rdP5iLog) { file_put_contents($rdP5iLog, "trait read LLM\n", FILE_APPEND); return null; };
if (class_exists('RelDynEval')) RelDynEval::$launcher = function () use ($rdP5iLog): void { file_put_contents($rdP5iLog, "eval worker launch\n", FILE_APPEND); };
PHP;
        file_put_contents($prepend, str_replace('__ENGINE__', var_export($engine, true), $code));
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $env = array_merge(getenv(), [
            'RELDYN_P5I_DSN' => self::$dsn, 'RELDYN_P5I_SCHEMA' => self::$schema,
            'RELDYN_P5I_LLM_LOG' => self::$tmp . '/server_llm.log', 'RELDYN_P5I_TMP' => self::$tmp,
        ]);
        self::$server = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'session.save_path=' . self::$tmp . '/sessions',
            '-d', 'display_errors=stderr', '-d', 'error_reporting=' . E_ALL, '-d', 'error_log=' . self::$tmp . '/server_error.log', '-d', 'log_errors=1',
            '-S', '127.0.0.1:' . self::$port, '-t', $engine],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', self::$tmp . '/server.out', 'a'], 2 => ['file', self::$tmp . '/server.err', 'a']],
            $pipes, $engine, $env);
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($s) { fclose($s); return; }
            usleep(100000);
        }
        throw new RuntimeException('php -S did not start: ' . @file_get_contents(self::$tmp . '/server.err'));
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private static function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    // =====================================================================
    // HTTP
    // =====================================================================

    /** Everything the server logged since the last call (PHP diagnostics go to stderr and the error log). */
    private static function newServerErrors(): string
    {
        $all = (string) @file_get_contents(self::$tmp . '/server.err') . "\n--\n" . (string) @file_get_contents(self::$tmp . '/server_error.log');
        $new = substr($all, min(self::$errOffset, strlen($all)));
        self::$errOffset = strlen($all);
        return $new;
    }

    /**
     * One request to a RelDyn page ($path relative to ext/relationship_dynamics/, query included).
     * @return array [status, headers (lowercase name => value), body, cookie]
     */
    private function http(string $method, string $path, array $post = [], ?string $cookie = null): array
    {
        self::newServerErrors();
        $url = 'http://127.0.0.1:' . self::$port . '/ext/relationship_dynamics/' . $path;
        $headers = [];
        if ($cookie !== null) $headers[] = "Cookie: {$cookie}";
        $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 180, 'follow_location' => 0];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['content'] = http_build_query($post);
        }
        $opts['header'] = implode("\r\n", $headers);
        $body = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
        $this->assertNotFalse($body, "no response from {$url}");
        $status = 0;
        $h = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int) $m[1]; $h = []; continue; }
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = strtolower(trim($k));
                $h[$k] = isset($h[$k]) ? $h[$k] . "\n" . trim($v) : trim($v);
            }
        }
        $set = null;
        if (isset($h['set-cookie']) && preg_match('/(PHPSESSID=[^;\s]+)/', $h['set-cookie'], $m)) $set = $m[1];
        $err = self::newServerErrors();
        $this->assertDoesNotMatchRegularExpression('/(Fatal|Parse) error/i', $err, "{$method} {$path}: server errors");
        $this->assertDoesNotMatchRegularExpression('/\b(Warning|Notice|Deprecated)\b\s*:/', $err, "{$method} {$path}: no PHP warning, notice or deprecation");
        $this->assertDoesNotMatchRegularExpression('/\b(Warning|Notice|Deprecated|Fatal error)(<\/b>)?:\s+.*? in (<b>)?\/\S+?(<\/b>)? on line/', (string) $body, "{$method} {$path}: no PHP diagnostic in the page");
        return [$status, $h, (string) $body, $set ?? $cookie];
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return new DOMXPath($doc);
    }

    /** Everything a page could write: NPC rows, player rows, the config, trait reads, memory, eventlog, history. */
    private function snapshot(): array
    {
        $out = [];
        foreach (['SELECT id, npc_name, extended_data::text AS e, plugin_extended_data::text AS p FROM core_npc_master ORDER BY id',
                     'SELECT id, value FROM core_player ORDER BY id', 'SELECT id, value FROM conf_opts ORDER BY id',
                     'SELECT template_key, src_hash, status FROM reldyn_trait_reads ORDER BY template_key, src_hash',
                     'SELECT count(*) AS n FROM memory', 'SELECT count(*) AS n FROM eventlog', 'SELECT count(*) AS n FROM core_npc_master_history'] as $q) {
            $out[$q] = self::$db->fetchAll($q);
        }
        return $out;
    }

    /** The pages a viewer lands on: every hub tab, the editor list and each NPC, the profile, the dry run, the dump. */
    private static function seedUrls(): array
    {
        $npcs = array_merge(array_keys(self::BEDS), [self::WANDERER, self::EVIL]);
        $urls = ['settings.php', 'settings.php?tab=gating', 'settings.php?tab=people', 'settings.php?tab=reference',
            'npc.php', 'player.php', 'debug_pipeline.php'];
        foreach ($npcs as $n) {
            $q = rawurlencode($n);
            array_push($urls, "npc.php?npc={$q}", "player.php?npc={$q}", "debug_pipeline.php?npc={$q}",
                "debug_compose.php?npc={$q}", "settings.php?tab=gating&npc={$q}");
        }
        $urls[] = 'debug_compose.php?npc=' . rawurlencode('Aela the Huntress') . '&format=json';
        return $urls;
    }

    /**
     * Links and GET forms of one HTML page that point inside the plugin: [href => kind]. Absolute
     * core links (navbar, /ui/...) are core's; javascript:, mailto: and external links are not followed.
     */
    private static function localLinks(DOMXPath $x): array
    {
        $out = [];
        foreach ($x->query('//a[@href]') as $a) $out[] = [$a->getAttribute('href'), 'a'];
        foreach ($x->query('//form[@action]') as $f) {
            if (strtolower($f->getAttribute('method') ?: 'get') === 'get') $out[] = [$f->getAttribute('action'), 'get-form'];
            else $out[] = [$f->getAttribute('action'), 'post-form'];
        }
        $local = [];
        foreach ($out as [$href, $kind]) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $href)) continue;
            if (str_starts_with($href, '/')) {
                if (!str_starts_with($href, '/ext/relationship_dynamics/') && !str_starts_with($href, '/HerikaServer/ext/relationship_dynamics/')) continue;
                $href = substr($href, strpos($href, '/ext/relationship_dynamics/') + strlen('/ext/relationship_dynamics/'));
            }
            $local[] = [$href, $kind];
        }
        return $local;
    }

    // =====================================================================
    // tests
    // =====================================================================

    public function testEveryPageRendersCleanlyAndEveryLinkBetweenThemResolves(): void
    {
        $dir = realpath(__DIR__ . '/../../ext/relationship_dynamics');
        $before = $this->snapshot();
        $queue = self::seedUrls();
        $seen = [];
        $followed = 0;
        $depth = array_fill_keys($queue, 0);
        while ($queue) {
            $url = array_shift($queue);
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            [$status, $h, $body] = $this->http('GET', $url);
            $this->assertSame(200, $status, "GET {$url}: " . substr(strip_tags($body), 0, 300));
            $type = strtolower($h['content-type'] ?? 'text/html');
            if (!str_starts_with($type, 'text/html') || isset($h['content-disposition'])) continue;
            $x = $this->dom($body);
            $this->assertGreaterThan(0, $x->query('//link[contains(@href, "/ui/css/main.css")]')->length, "{$url}: core UI styles");
            // no hostile markup ever reaches the DOM as markup
            $this->assertStringNotContainsString('<b onmouseover=', $body, "{$url}: the hostile name stays escaped");
            $this->assertSame(0, $x->query('//*[@onmouseover]')->length, "{$url}: no injected handler");
            $page = strtok($url, '?');
            foreach (self::localLinks($x) as [$href, $kind]) {
                $frag = null;
                if (($p = strpos($href, '#')) !== false) { $frag = substr($href, $p + 1); $href = substr($href, 0, $p); }
                if ($href === '') {
                    // an in-page anchor: its target id is on this page
                    if ($frag !== null && $frag !== '') $this->assertGreaterThan(0, $x->query('//*[@id="' . $frag . '"]')->length, "{$url}: #{$frag} has a target");
                    continue;
                }
                if (str_starts_with($href, '?')) $href = $page . $href;
                $file = strtok($href, '?');
                $this->assertFileExists($dir . '/' . $file, "{$url} links {$href}");
                if ($kind === 'post-form') continue;
                if ($frag !== null && $frag !== '' && $href === $url) {
                    $this->assertGreaterThan(0, $x->query('//*[@id="' . $frag . '"]')->length, "{$url}: #{$frag} has a target");
                }
                // follow each distinct link target once, one level deep from the landing pages
                if ($kind === 'a' && !isset($seen[$href]) && !isset($depth[$href]) && ($depth[$url] ?? 1) < 1) {
                    $depth[$href] = 1;
                    $queue[] = $href;
                    $followed++;
                }
            }
        }
        $this->assertGreaterThan(count(self::seedUrls()), count($seen), 'links were followed');
        $this->assertSame($before, $this->snapshot(), 'a page view writes nothing (no trait read queued either)');
        $this->assertSame(0, (int) self::$db->fetchOne("SELECT count(*) AS n FROM reldyn_trait_reads WHERE status = 'pending'")['n'],
            'no trait read queued for the NPC without one');
    }

    public function testThePagesLinkOneAnother(): void
    {
        $q = rawurlencode('Aela the Huntress');
        $has = function (string $url, array $xpaths): void {
            [$status, , $html] = $this->http('GET', $url);
            $this->assertSame(200, $status, $url);
            $x = $this->dom($html);
            foreach ($xpaths as $xp) $this->assertGreaterThan(0, $x->query($xp)->length, "{$url}: {$xp}");
        };
        // the hub (the manifest's config page) reaches every other page
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/manifest.json'), true);
        $this->assertSame('settings.php', basename((string) $manifest['config_url']));
        $has('settings.php?tab=people', ['//a[@href="player.php"]', '//form[@action="npc.php"]', '//a[@href="npc.php?npc=' . $q . '"]',
            '//a[@href="debug_pipeline.php"]', '//a[@href="debug_compose.php"]']);
        // the editor: back to the hub, the list, and this NPC's dry run and state dump
        $has('npc.php', ['//a[@href="settings.php"]', '//a[@href="player.php"]', '//a[@href="debug_pipeline.php"]', '//a[@href="npc.php?npc=' . $q . '"]']);
        $has("npc.php?npc={$q}", ['//a[@href="settings.php"]', '//a[@href="npc.php"]',
            '//a[@href="debug_pipeline.php?npc=' . $q . '"]', '//a[@href="debug_compose.php?npc=' . $q . '"]']);
        // the profile and debug pages share one nav: the hub, the editor and each other
        foreach (['player.php', 'debug_pipeline.php'] as $page) {
            $nav = [];
            foreach (['settings.php', 'npc.php', 'player.php', 'debug_pipeline.php', 'debug_compose.php'] as $target) {
                if ($target !== $page) $nav[] = '//nav[@class="rd-nav"]/a[@href="' . $target . '"]';
            }
            $has($page, $nav);
        }
        // the dump is text, never sniffed into HTML
        [$status, $h, $text] = $this->http('GET', "debug_compose.php?npc={$q}");
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('text/plain', $h['content-type'] ?? '');
        $this->assertStringContainsString('Aela the Huntress', $text);
    }

    public function testNamesAreEscapedWhereverTheyAppear(): void
    {
        $esc = htmlspecialchars(self::EVIL, ENT_QUOTES, 'UTF-8');
        $q = rawurlencode(self::EVIL);
        foreach (['settings.php?tab=people', 'npc.php', "npc.php?npc={$q}", "player.php?npc={$q}", "settings.php?tab=gating&npc={$q}",
                  "debug_pipeline.php?npc={$q}"] as $url) {
            [$status, , $html] = $this->http('GET', $url);
            $this->assertSame(200, $status, $url);
            $this->assertStringNotContainsString(self::EVIL, $html, "{$url}: never raw");
            $this->assertStringNotContainsString('<b onmouseover=', $html, $url);
            $x = $this->dom($html);
            $this->assertSame(0, $x->query('//*[@onmouseover]')->length, $url);
            // it is there, as text
            $this->assertTrue(str_contains($html, $esc) || str_contains($html, htmlspecialchars(self::EVIL, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                || str_contains($html, str_replace('&#039;', '&#39;', $esc)), "{$url}: shown escaped");
        }
    }

    public function testEveryPageRefusesAPostWithoutTheSessionsToken(): void
    {
        $before = $this->snapshot();
        $aela = 'Aela the Huntress';
        $posts = [
            'settings.php' => ['_complete' => '1', 'save' => '1', 'f' => [RelDynSettings::encodePath(['player_mirror', 'prompt', 'enabled']) => '1']],
            'npc.php' => ['npc' => $aela, 'op' => 'reset_npc', 'confirm' => '1'],
            'player.php' => ['action' => 'prompt_toggle', 'prompt_enabled' => '1'],
            'debug_pipeline.php' => ['action' => 'dry_run', 'npc' => $aela, 'item' => json_encode(self::item($aela, 1)), 'as_new' => '1'],
        ];
        $this->assertSame(self::HTML_PAGES, array_keys($posts), 'every HTML page with a form');
        foreach ($posts as $page => $fields) {
            // no token at all; then a forged token in a real session
            [$status, , $body] = $this->http('POST', $page, $fields);
            $this->assertSame(403, $status, "{$page}: no token");
            $this->assertStringNotContainsString('<b onmouseover=', $body);
            [, , , $cookie] = $this->http('GET', $page);
            $this->assertNotNull($cookie, "{$page} starts a session");
            [$status] = $this->http('POST', $page, $fields + ['csrf_token' => str_repeat('a', 64)], $cookie);
            $this->assertSame(403, $status, "{$page}: forged token");
        }
        $this->assertSame($before, $this->snapshot(), 'nothing written by a refused POST');
    }

    public function testThePlayerPagesOptInIsWhatTheHubShows(): void
    {
        $code = RelDynSettings::encodePath(['player_mirror', 'prompt', 'enabled']);
        $hubSwitch = function (): bool {
            $found = null;
            foreach (['settings.php', 'settings.php?tab=settings&group=player'] as $url) {
                [$status, , $html] = $this->http('GET', $url);
                $this->assertSame(200, $status, $url);
                $x = $this->dom($html);
                $box = $x->query('//input[@type="checkbox" and @name="f[' . RelDynSettings::encodePath(['player_mirror', 'prompt', 'enabled']) . ']"]');
                foreach ($box as $b) {
                    $on = $b->hasAttribute('checked');
                    if ($found !== null) $this->assertSame($found, $on, 'every copy of the switch agrees');
                    $found = $on;
                }
            }
            $this->assertNotNull($found, 'the hub shows the player-profile prompt switch');
            return $found;
        };
        $this->assertNotSame('', $code);
        $this->assertFalse($hubSwitch(), 'off by default');
        [, , $page, $cookie] = $this->http('GET', 'player.php');
        preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page, $m);
        $this->assertNotEmpty($m[1] ?? '');
        try {
            [$status] = $this->http('POST', 'player.php', ['action' => 'prompt_toggle', 'prompt_enabled' => '1', 'csrf_token' => $m[1]], $cookie);
            $this->assertSame(200, $status);
            $this->assertTrue($hubSwitch(), 'the hub reads the same stamped config row the player page wrote');
            $stored = json_decode((string) self::$db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID])['value'], true);
            $this->assertSame(RelationshipDynamics::CONFIG_SCHEMA, $stored['config_schema'] ?? null, 'the schema stamp');
        } finally {
            [$status] = $this->http('POST', 'player.php', ['action' => 'prompt_toggle', 'prompt_enabled' => '0', 'csrf_token' => $m[1]], $cookie);
            $this->assertSame(200, $status);
        }
        $this->assertFalse($hubSwitch());
    }
}
