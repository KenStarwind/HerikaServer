<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_dry_run.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_ui_charts.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynPagesPgDb
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
 * player-profile-page + debug-tooling end to end, the four test beds (Aela the Huntress, Ashe with
 * Serene's hand-set vector (never read: nothing of her story anywhere), Muiri, Lynly Star-Sung) on
 * CHIM 3.4.1 core-shaped rows and the committed seed's reads. Their state is built by the real eval
 * consumer (queuePendingEval -> applyEvalInbox, contract items as the producer writes them; no LLM),
 * then the pages are served by `php -S` (CHIM's own bootstrap, with the test database pre-seeded
 * as $db through auto_prepend_file) and fetched over HTTP: HTML structure, escaping, security
 * headers, CSRF rejection, the opt-in round trip through the config store, the card exports, the
 * dry run saving nothing and predicting the real apply, and the NPC state dump per bed.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynProfilePagesPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;
    private const ROUNDS = 10;
    private const BEDS = [
        // name => [template key, race, class, factions, skills]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55]],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30]],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20]],
    ];

    private static ?string $dsn = null;
    private static string $schema = '';
    private static ?RelDynPagesPgDb $db = null;
    private static $server = null;
    private static int $port = 0;
    private static string $tmp = '';
    private static array $savedGlobals = [];
    private static $prevErrorLog = null;
    private static int $lastGamets = 0;

    // =====================================================================
    // fixture: schema, beds, their state through the real consumer, the server
    // =====================================================================

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) return;
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) throw new RuntimeException('refusing to run against the live dwemer database');
        self::$dsn = $dsn;
        self::$schema = 'reldyn_pages_' . getmypid() . '_' . bin2hex(random_bytes(3));
        self::$tmp = sys_get_temp_dir() . '/reldyn_pages_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir(self::$tmp . '/sessions', 0700, true);

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, 'CREATE SCHEMA ' . self::$schema);
        pg_query($admin, 'SET search_path TO ' . self::$schema);
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
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        self::$db = new RelDynPagesPgDb($dsn, self::$schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest'] as $key) {
            self::$savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        self::clearReldynGlobals();
        $GLOBALS['db'] = self::$db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        self::$prevErrorLog = ini_set('error_log', self::$tmp . '/error.log');
        Logger::setCustomLog(self::$tmp . '/logger.log');
        self::stubLlm();
        pg_query_params(self::$db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['player_name', self::PLAYER]);
        self::config([]);
        self::seedBeds();
        self::buildState();
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
        @file_put_contents(self::$tmp . '/llm.log', '');
    }

    protected function tearDown(): void
    {
        if (self::$dsn === null) return;
        $this->assertSame('', (string) @file_get_contents(self::$tmp . '/llm.log'), 'no LLM call from the pages');
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

    private static function stubLlm(): void
    {
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { file_put_contents(self::$tmp . '/llm.log', "trait read\n", FILE_APPEND); return null; };
        RelDynEval::$launcher = function (): void {};
    }

    /** The stored RelDyn config: the defaults with the debug log on and no internal weather, plus $over. */
    private static function config(array $over): void
    {
        pg_query_params(self::$db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false], $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    private static function seedBeds(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills]) {
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
            if ($key === 'ashe') continue;   // Serene's hand-set vector, never read
            $e = $seed['reads'][$key];
            pg_query_params(self::$db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query(self::$db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    /** One contract item as the eval producer writes it. */
    private static function item(string $npc, int $gamets, string $kind): array
    {
        $hurt = $kind === 'hurt';
        return [
            'v' => RelationshipDynamics::EVAL_CONTRACT_VERSION, 'source' => RelationshipDynamics::EVAL_CONTRACT_SOURCE,
            'npc' => $npc, 'gamets' => $gamets,
            'signals' => ['affinity' => $hurt ? -4 : 2, 'trust' => $hurt ? -3 : 3, 'comfort' => $hurt ? -4 : 2, 'respect' => $hurt ? -3 : 1,
                'passion' => 0, 'maturity' => 0],
            'tags' => $hurt ? ['criticism'] : ['help'],
            'grievance' => $hurt ? ['flag' => true, 'kind' => 'disrespect', 'severity' => 2] : ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $hurt ? 0.6 : 0.5,
            'positive_interaction' => !$hurt,
            'summary' => $hurt ? 'The player called her useless in front of everyone.' : 'The player kept a promise and helped her mend the fence.',
            'romantic_intent' => 0,
            'charisma' => $hurt ? 'none' : 'rock',
        ];
    }

    /** $item applied through the eval inbox, as the eval worker does. */
    private static function apply(string $npc, array $item): array
    {
        self::assertTrue(RelationshipDynamics::queuePendingEval($npc, $item), "{$npc}: queued");
        $d = RelationshipDynamics::getDynamics($npc);
        $totals = RelationshipDynamics::applyEvalInbox($npc, $d);
        RelationshipDynamics::endRequest();
        self::clearReldynGlobals();
        return $totals;
    }

    /** ROUNDS evenings: a kept promise to each bed, 6 game hours apart per bed. */
    private static function buildState(): void
    {
        $t = self::N0 * self::DAY + 18 * self::HOUR;
        for ($r = 0; $r < self::ROUNDS; $r++) {
            foreach (array_keys(self::BEDS) as $i => $npc) {
                $g = (int) round($t + $r * self::DAY + $i * 1.5 * self::HOUR);
                pg_query_params(self::$db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
                    VALUES ('inputtext', \$1, 'pending', \$2, 0, \$2, '', '')", [self::PLAYER . ": I kept my promise. (Talking to {$npc})", $g]);
                self::apply($npc, self::item($npc, $g, 'kind'));
                self::$lastGamets = max(self::$lastGamets, $g);
            }
        }
        pg_query_params(self::$db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
            VALUES ('request', '', 'web', \$1, 0, \$1, '', '')", [self::$lastGamets + (int) self::HOUR]);
    }

    private static function startServer(): void
    {
        $prepend = self::$tmp . '/prepend.php';
        $engine = realpath(__DIR__ . '/../..');
        $code = <<<'PHP'
<?php
// RelDynProfilePagesPostgresTest: the test database as core's $db, before the page boots
$dsn = getenv('RELDYN_PAGES_DSN');
$schema = (string) getenv('RELDYN_PAGES_SCHEMA');
if (!$dsn || preg_match('/dbname\s*=\s*dwemer\b/', $dsn) || !preg_match('/^reldyn_pages_[0-9a-f_]+$/', $schema)) { http_response_code(500); exit('no throwaway database'); }
final class RelDynPagesServerDb
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
$GLOBALS['db'] = new RelDynPagesServerDb($dsn, $schema);
require_once __ENGINE__ . '/lib/logger.php';
require_once __ENGINE__ . '/ext/relationship_dynamics/relationship_dynamics.php';
Logger::setCustomLog(getenv('RELDYN_PAGES_TMP') . '/server_logger.log');
RelDynTraitRead::$launcher = function () {};
RelDynTraitRead::$llm = function () { file_put_contents(getenv('RELDYN_PAGES_LLM_LOG'), "trait read\n", FILE_APPEND); return null; };
if (class_exists('RelDynEval')) RelDynEval::$launcher = function (): void {};
PHP;
        file_put_contents($prepend, str_replace('__ENGINE__', var_export($engine, true), $code));
        // a free port of our own
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $env = array_merge(getenv(), [
            'RELDYN_PAGES_DSN' => self::$dsn, 'RELDYN_PAGES_SCHEMA' => self::$schema,
            'RELDYN_PAGES_LLM_LOG' => self::$tmp . '/llm.log', 'RELDYN_PAGES_TMP' => self::$tmp,
        ]);
        self::$server = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'session.save_path=' . self::$tmp . '/sessions',
            '-d', 'display_errors=stderr', '-d', 'error_log=' . self::$tmp . '/server_error.log', '-d', 'log_errors=1',
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

    // =====================================================================
    // HTTP
    // =====================================================================

    /** @return array [status, headers (lowercase name => value), body, cookie] */
    private function http(string $method, string $path, array $get = [], array $post = [], ?string $cookie = null): array
    {
        $url = 'http://127.0.0.1:' . self::$port . '/ext/relationship_dynamics/' . $path . ($get ? '?' . http_build_query($get) : '');
        $headers = [];
        if ($cookie !== null) $headers[] = "Cookie: {$cookie}";
        $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 120, 'header' => ''];
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
        $err = (string) @file_get_contents(self::$tmp . '/server.err') . (string) @file_get_contents(self::$tmp . '/server_error.log');
        $this->assertDoesNotMatchRegularExpression('/(Fatal|Parse) error/', $err, 'server errors');
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated):[^\n]*relationship_dynamics/', $err, 'no warning from a RelDyn page');
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

    private static function token(string $html): string
    {
        preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m);
        return $m[1] ?? '';
    }

    /** Everything a page could write: the beds' rows, the player rows, the config, memory, eventlog. */
    private function snapshot(): array
    {
        $out = [];
        foreach (['SELECT id, npc_name, extended_data::text AS e, plugin_extended_data::text AS p FROM core_npc_master ORDER BY id',
                     'SELECT id, value FROM core_player ORDER BY id', 'SELECT id, value FROM conf_opts ORDER BY id',
                     'SELECT count(*) AS n FROM memory', 'SELECT count(*) AS n FROM eventlog', 'SELECT count(*) AS n FROM core_npc_master_history'] as $q) {
            $out[$q] = self::$db->fetchAll($q);
        }
        return $out;
    }

    private static function storedConfig(): array
    {
        $r = pg_fetch_assoc(pg_query_params(self::$db->link, 'SELECT value FROM conf_opts WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID]));
        return json_decode($r['value'], true);
    }

    private static function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params(self::$db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private static function assertSecurityHeaders(array $h): void
    {
        self::assertSame('nosniff', $h['x-content-type-options'] ?? null);
    }

    // =====================================================================
    // the player profile page
    // =====================================================================

    public function testThePlayerPageMirrorsWhatTheFourBedsExperiencedInCoreChrome(): void
    {
        [$status, $h, $html] = $this->http('GET', 'player.php');
        $this->assertSame(200, $status, substr($html, 0, 500));
        $this->assertStringStartsWith('text/html', $h['content-type'] ?? '');
        self::assertSecurityHeaders($h);
        $this->assertSame('SAMEORIGIN', $h['x-frame-options'] ?? null);
        $x = $this->dom($html);
        $this->assertSame('CHIM - Player Profile', trim($x->query('//title')->item(0)->textContent));
        $this->assertGreaterThan(0, $x->query('//link[contains(@href, "/ui/css/main.css")]')->length, 'core UI styles');
        $this->assertSame('Player profile: ' . self::PLAYER, trim($x->query('//header[@class="rd-header"]/h1')->item(0)->textContent));
        $this->assertStringContainsString('Auto-generated from ' . (4 * self::ROUNDS) . ' NPC interactions with 4 people', $html);

        // the design's six dimensions, with bars and bands
        $axes = [];
        foreach ($x->query('//div[@class="rd-dim"]') as $d) $axes[] = $d->getAttribute('data-axis');
        $this->assertSame(RelDynMirror::DIMENSIONS, $axes);
        $trust = RelDynMirror::profile()['dimensions']['trust'];
        $meter = $x->query('//div[@data-axis="trust"]//div[@role="meter"]')->item(0);
        $this->assertSame(RelDynUiCharts::num($trust['score'], 0), $meter->getAttribute('aria-valuenow'));
        $this->assertGreaterThan(55.0, $trust['score'], 'kept promises to all four');
        $this->assertStringContainsString($trust['keywords'], $x->query('//div[@data-axis="trust"]')->item(0)->textContent);

        // style and patterns, prose, trajectory
        $this->assertSame('The Rock', trim($x->query('//div[@id="rd-charisma"]/div[@class="rd-tile-value"]')->item(0)->textContent));
        $this->assertSame('Acts of Service', trim($x->query('//div[@id="rd-love"]/div[@class="rd-tile-value"]')->item(0)->textContent));
        $this->assertSame('Well regarded', trim($x->query('//div[@id="rd-reputation"]/div[@class="rd-tile-value"]')->item(0)->textContent));
        $prose = trim($x->query('//p[@class="rd-prose"]')->item(0)->textContent);
        $this->assertStringContainsString('Steady under pressure', $prose);
        $this->assertDoesNotMatchRegularExpression('/\d/', $prose);
        $this->assertSame(1, $x->query('//section[@id="rd-trajectory"]')->length);

        // the card: inline SVG, no NPC name on it; the opt-in form carries the CSRF token
        $card = $x->query('//section[@id="rd-card"]//*[local-name()="svg"]')->item(0);
        $this->assertNotNull($card);
        $this->assertSame('100%', $card->getAttribute('width'), 'the card scales to its box: a phone shows it whole instead of scrolling sideways');
        $this->assertMatchesRegularExpression('/^0 0 640 \d+$/', $card->getAttribute('viewBox'));
        $this->assertStringNotContainsString(' width="640"', $html, 'no fixed 640px drawing left on the page');
        $cardText = $card->textContent;
        $this->assertStringContainsString(self::PLAYER, $cardText);
        foreach (array_keys(self::BEDS) as $npc) $this->assertStringNotContainsString($npc, $cardText, "{$npc} stays off the card");
        $this->assertSame(64, strlen(self::token($html)));
        $this->assertSame(1, $x->query('//form[@method="post"]//input[@name="action" and @value="prompt_toggle"]')->length);
        $this->assertStringContainsString('Currently: <strong>No</strong>', $html, 'opt-in, off by default');

        // the bonds: the four beds to choose from
        $opts = [];
        foreach ($x->query('//select[@id="rd-npc"]/option[@value!=""]') as $o) $opts[] = $o->getAttribute('value');
        sort($opts);
        $beds = array_keys(self::BEDS);
        sort($beds);
        $this->assertSame($beds, $opts);
    }

    public function testEachBedsFulfillmentSpiderRendersAndAnUnknownNameIsIgnored(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            [$status, , $html] = $this->http('GET', 'player.php', ['npc' => $npc]);
            $this->assertSame(200, $status);
            $x = $this->dom($html);
            $svg = $x->query('//div[@id="rd-fulfillment"]/*[local-name()="svg"]')->item(0);
            $this->assertNotNull($svg, $npc);
            $this->assertSame("{$npc}: needs and how well they are met", $svg->getAttribute('aria-label'));
            $vb = array_map('floatval', explode(' ', $svg->getAttribute('viewBox')));
            $this->assertLessThanOrEqual(440.0, $vb[2], "{$npc}: the spider is drawn small enough for its type to stay legible on a phone");
            $graph = RelationshipDynamics::fulfillmentGraph($npc, floatval(self::$lastGamets + self::HOUR));
            $this->assertNotEmpty($graph['axes'], "{$npc}: her needs");
            $this->assertSame(count($graph['axes']), $x->query('//div[@id="rd-fulfillment"]//*[local-name()="text" and @font-weight="600"]')->length);
            $this->assertSame($npc, $x->query('//select[@id="rd-npc"]/option[@selected]')->item(0)->getAttribute('value'));
        }
        $evil = '<script>alert(1)</script>';
        [$status, , $html] = $this->http('GET', 'player.php', ['npc' => $evil]);
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString($evil, $html);
        $this->assertStringNotContainsString('id="rd-fulfillment"', $html);
    }

    public function testEverythingThePageDrawsFromDataIsEscaped(): void
    {
        $evilName = 'Kaida"><script>alert(1)</script>';
        $evilLabel = '<img src=x onerror=alert(2)>';
        pg_query_params(self::$db->link, 'UPDATE core_player SET value = $2 WHERE id = $1', ['player_name', $evilName]);
        self::config(['player_mirror' => ['labels' => ['trust' => $evilLabel]]]);
        try {
            [$status, , $html] = $this->http('GET', 'player.php');
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString(htmlspecialchars($evilName, ENT_QUOTES), $html);
            $x = $this->dom($html);
            $this->assertSame('Player profile: ' . $evilName, trim($x->query('//header[@class="rd-header"]/h1')->item(0)->textContent));
            $this->assertSame($evilLabel, trim($x->query('//div[@data-axis="trust"]/div[@class="rd-dim-label"]')->item(0)->textContent));
            // the snippet to paste is escaped once, and the SVG inside it stays inert
            $snippet = $x->query('//textarea[@id="rd-snippet"]')->item(0)->textContent;
            $this->assertStringStartsWith('<figure', $snippet);
            $this->assertStringNotContainsString('<script', $snippet);
            $this->assertStringNotContainsString('<img', $snippet);
            $fig = simplexml_load_string($snippet);
            $this->assertNotFalse($fig, 'the snippet is well-formed');
            $this->assertSame([], $fig->xpath('//@*[starts-with(name(), "on")]'), 'no event handler attribute');
            $this->assertStringContainsString($evilLabel, implode(' ', array_map('strval', $fig->xpath('//*[local-name()="text"]'))), 'drawn as text');
            // the file export too
            [$status, , $svg] = $this->http('GET', 'player.php', ['export' => 'svg']);
            $this->assertSame(200, $status);
            $doc = simplexml_load_string($svg);
            $this->assertNotFalse($doc, 'well-formed');
            $this->assertStringNotContainsString('<script', $svg);
            $this->assertStringContainsString($evilName, (string) $doc->text[0]);
        } finally {
            pg_query_params(self::$db->link, 'UPDATE core_player SET value = $2 WHERE id = $1', ['player_name', self::PLAYER]);
            self::config([]);
        }
    }

    public function testTheOptInRejectsAForgedPostAndRoundTripsThroughTheConfigStoreWithItsToken(): void
    {
        $before = $this->snapshot();
        // no token, a wrong token, a token from another session: 403, nothing written
        [$status, , $html] = $this->http('POST', 'player.php', [], ['action' => 'prompt_toggle', 'prompt_enabled' => '1']);
        $this->assertSame(403, $status);
        $this->assertStringContainsString('nothing was changed', $html);
        [, , $page, $cookie] = $this->http('GET', 'player.php');
        $token = self::token($page);
        $this->assertNotNull($cookie);
        [$status] = $this->http('POST', 'player.php', [], ['action' => 'prompt_toggle', 'prompt_enabled' => '1', 'csrf_token' => str_repeat('0', 64)], $cookie);
        $this->assertSame(403, $status);
        [$status] = $this->http('POST', 'player.php', [], ['action' => 'prompt_toggle', 'prompt_enabled' => '1', 'csrf_token' => $token]);
        $this->assertSame(403, $status, 'the token belongs to its session');
        // a GET with the same fields writes nothing
        [$status] = $this->http('GET', 'player.php', ['action' => 'prompt_toggle', 'prompt_enabled' => '1', 'csrf_token' => $token], [], $cookie);
        $this->assertSame(200, $status);
        // an unknown value with a good token: 400
        [$status] = $this->http('POST', 'player.php', [], ['action' => 'prompt_toggle', 'prompt_enabled' => 'yes', 'csrf_token' => $token], $cookie);
        $this->assertSame(400, $status);
        $this->assertEquals($before, $this->snapshot(), 'nothing was written');

        try {
            [$status, , $html] = $this->http('POST', 'player.php', [], ['action' => 'prompt_toggle', 'prompt_enabled' => '1', 'csrf_token' => $token], $cookie);
            $this->assertSame(200, $status);
            $this->assertStringContainsString('NPCs now sense this profile', $html);
            $this->assertStringContainsString('Currently: <strong>Yes</strong>', $html);
            $stored = self::storedConfig();
            $this->assertTrue($stored['player_mirror']['prompt']['enabled']);
            $this->assertSame(RelationshipDynamics::CONFIG_SCHEMA, $stored['config_schema'], 'stamped by saveConfig');
            $this->assertTrue($stored['log_enabled'], 'the rest of the row kept');
            $this->assertFalse($stored['internal_weather_enabled']);
            RelationshipDynamics::clearConfigCache();
            $this->assertTrue(RelDynMirror::config()['prompt']['enabled']);
            $this->assertSame(RelDynMirror::configDefaults()['prompt']['max_bands'], RelDynMirror::config()['prompt']['max_bands'], 'defaults under the choice');
            // the engine now speaks it, in words
            $felt = RelDynMirror::feltText('Aela the Huntress', self::PLAYER, 1);
            $this->assertNotNull($felt);
            $this->assertDoesNotMatchRegularExpression('/\d/', $felt);

            [$status, , $html] = $this->http('POST', 'player.php', [], ['action' => 'prompt_toggle', 'prompt_enabled' => '0', 'csrf_token' => $token], $cookie);
            $this->assertSame(200, $status);
            $this->assertStringContainsString('Currently: <strong>No</strong>', $html);
            $this->assertFalse(self::storedConfig()['player_mirror']['prompt']['enabled']);
            RelationshipDynamics::clearConfigCache();
            $this->assertNull(RelDynMirror::feltText('Aela the Huntress', self::PLAYER, 1));
        } finally {
            self::config([]);
        }
    }

    public function testTheCardExportsAreStandaloneFilesAndTheAnonymousOneCarriesNoName(): void
    {
        [$status, $h, $svg] = $this->http('GET', 'player.php', ['export' => 'svg']);
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('image/svg+xml', $h['content-type'] ?? '');
        $this->assertSame('attachment; filename="reldyn-profile-Kaida.svg"', $h['content-disposition'] ?? null);
        self::assertSecurityHeaders($h);
        $this->assertStringContainsString("default-src 'none'", $h['content-security-policy'] ?? '');
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $doc = simplexml_load_string($svg);
        $this->assertNotFalse($doc);
        $this->assertSame('640', (string) $doc['width']);
        $this->assertStringContainsString(self::PLAYER, $svg);
        foreach (array_keys(self::BEDS) as $npc) $this->assertStringNotContainsString($npc, $svg);
        $this->assertSame(substr_count($svg, '<svg '), substr_count($svg, 'http'), 'no external reference');

        [, $h, $anon] = $this->http('GET', 'player.php', ['export' => 'svg', 'anon' => '1']);
        $this->assertSame('attachment; filename="reldyn-profile.svg"', $h['content-disposition'] ?? null);
        $this->assertStringNotContainsString(self::PLAYER, $anon);
        $this->assertStringContainsString('Anonymous adventurer', $anon);

        [$status, $h, $page] = $this->http('GET', 'player.php', ['export' => 'html']);
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('text/html', $h['content-type'] ?? '');
        $this->assertStringStartsWith('<!DOCTYPE html>', $page);
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringNotContainsString('<link', $page);
        $x = $this->dom($page);
        $this->assertSame(1, $x->query('//figure/*[local-name()="svg"]')->length);
    }

    // =====================================================================
    // debug tooling
    // =====================================================================

    public function testTheDryRunPageShowsWhatAnItemWouldChangeAndSavesNothing(): void
    {
        $npc = 'Aela the Huntress';
        [$status, , $page, $cookie] = $this->http('GET', 'debug_pipeline.php', ['npc' => $npc]);
        $this->assertSame(200, $status);
        $x = $this->dom($page);
        $sample = json_decode($x->query('//textarea[@id="rd-item"]')->item(0)->textContent, true);
        $this->assertSame($npc, $sample['npc'], 'a sample item for her to start from');
        $this->assertGreaterThan(0, $x->query('//select[@id="rd-load"]/option[starts-with(@value, "applied:")]')->length, 'her real applied items');
        $token = self::token($page);
        $item = self::item($npc, self::$lastGamets + (int) self::HOUR, 'hurt');
        $before = $this->snapshot();

        [$status, , $html] = $this->http('POST', 'debug_pipeline.php', [], ['action' => 'dry_run', 'npc' => $npc, 'item' => json_encode($item)]);
        $this->assertSame(403, $status, 'no token');
        $this->assertStringNotContainsString('id="rd-result"', $html);

        [$status, $h, $html] = $this->http('POST', 'debug_pipeline.php', [], ['action' => 'dry_run', 'npc' => $npc, 'item' => json_encode($item), 'csrf_token' => $token, 'as_new' => '1'], $cookie);
        $this->assertSame(200, $status, substr($html, 0, 800));
        self::assertSecurityHeaders($h);
        $x = $this->dom($html);
        $this->assertSame(1, $x->query('//div[@id="rd-nothing-saved"]')->length, $html);
        $totals = [];
        foreach ($x->query('//table[@id="rd-totals"]/tbody/tr') as $tr) {
            $totals[trim($tr->childNodes->item(0)->textContent)] = floatval(trim($tr->childNodes->item(1)->textContent));
        }
        $this->assertLessThan(0.0, $totals['trust'] ?? 0.0, 'the hurtful word costs trust');
        $this->assertLessThan(0.0, $totals['affinity'] ?? 0.0);
        $this->assertGreaterThan(0, $x->query('//table[@id="rd-headline"]/tbody/tr')->length);
        $this->assertGreaterThan(0, $x->query('//table[@id="rd-changes"]/tbody/tr/td/code[text()="dimensions.trust.x"]')->length);
        $this->assertStringContainsString('"fp"', $x->query('//pre[@id="rd-mirror"]')->item(0)->textContent, 'the observation it would record');
        $this->assertStringContainsString('grievance', $x->query('//pre[@id="rd-feelings"]')->item(0)->textContent);
        $this->assertEquals($before, $this->snapshot(), 'the dry run saved nothing');

        // bad input: not JSON, another NPC's item, an NPC RelDyn does not know
        [$status, , $html] = $this->http('POST', 'debug_pipeline.php', [], ['action' => 'dry_run', 'npc' => $npc, 'item' => '{not json', 'csrf_token' => $token], $cookie);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('not a JSON object', $html);
        [$status, , $html] = $this->http('POST', 'debug_pipeline.php', [], ['action' => 'dry_run', 'npc' => $npc, 'item' => json_encode(self::item('Muiri', 1, 'kind')), 'csrf_token' => $token], $cookie);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('addressed to another NPC (Muiri)', $html);
        [$status, , $html] = $this->http('POST', 'debug_pipeline.php', [], ['action' => 'dry_run', 'npc' => '<b>x</b>', 'item' => json_encode($item), 'csrf_token' => $token], $cookie);
        $this->assertSame(400, $status);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertEquals($before, $this->snapshot(), 'still nothing saved');
    }

    public function testTheDryRunPredictsWhatTheRealApplyDoesForEachBed(): void
    {
        $g = self::$lastGamets + 2 * (int) self::HOUR;
        foreach (array_keys(self::BEDS) as $i => $npc) {
            $item = self::item($npc, $g + $i * 600, $i % 2 === 0 ? 'hurt' : 'kind');
            $before = $this->snapshot();
            $dry = RelDynDryRun::run($npc, $item, ['now' => $g]);
            $this->assertTrue($dry['ok'], "{$npc}: " . json_encode($dry['error']));
            $this->assertSame([], $dry['warnings'], $npc);
            $this->assertFalse($dry['saved']);
            $this->assertEquals($before, $this->snapshot(), "{$npc}: the dry run saved nothing");
            $this->assertNotNull($dry['mirror_observation'], "{$npc}: the mirror observation, read inside the rolled-back transaction");

            $real = self::apply($npc, $item);
            foreach ($dry['totals'] as $dim => $v) {
                $this->assertEqualsWithDelta($real[$dim] ?? 0.0, $v, 1e-6, "{$npc} {$dim}: the dry run predicted the real apply");
            }
            $stored = self::dynamics($npc);
            $this->assertEqualsWithDelta(floatval($stored['dimensions']['trust']['x']), floatval($dry['changes']['dimensions.trust.x'][1]), 1e-4, "{$npc}: trust after");
            $mirror = json_decode(pg_fetch_result(pg_query_params(self::$db->link, 'SELECT value FROM core_player WHERE id = $1', [RelDynMirror::ROW_ID]), 0, 0), true);
            $recorded = array_values(array_filter($mirror['obs'], fn($o) => $o['fp'] === $dry['fingerprint']));
            $this->assertCount(1, $recorded, "{$npc}: the real apply recorded the same observation");
            $this->assertSame($dry['mirror_observation']['s'], $recorded[0]['s']);
            $this->assertSame($dry['mirror_observation']['c'], $recorded[0]['c']);

            // the same item again: already applied; run as if new unless asked not to
            $again = RelDynDryRun::run($npc, $item, ['now' => $g]);
            $this->assertTrue($again['already_applied']);
            $not = RelDynDryRun::run($npc, $item, ['now' => $g, 'as_new' => false]);
            $this->assertSame([], $not['totals'], "{$npc}: an applied item changes nothing");
        }
    }

    public function testALegacyEvalResultDryRunsThroughProcessEvalDeltasAndBadItemsAreRefused(): void
    {
        $before = $this->snapshot();
        $dry = RelDynDryRun::run('Muiri', ['trust_delta' => 2, 'trust_reason' => 'The player kept a promise.', 'significance' => 1],
            ['now' => self::$lastGamets]);
        $this->assertTrue($dry['ok'], json_encode($dry['error']));
        $this->assertSame('legacy', $dry['kind']);
        $this->assertGreaterThan(0.0, $dry['totals']['trust'] ?? 0.0);
        $this->assertSame('The player kept a promise.', $dry['changes']['dimensions.trust.last_reason'][1] ?? null);
        $this->assertNull($dry['mirror_observation'], 'the legacy path records no observation');
        $this->assertEquals($before, $this->snapshot());

        $this->assertSame('Unknown NPC.', RelDynDryRun::run('Nobody', self::item('Nobody', 1, 'kind'))['error']);
        $this->assertStringStartsWith('Neither a contract item', (string) RelDynDryRun::run('Muiri', ['hello' => 1])['error']);
        $bad = self::item('Muiri', 1, 'kind');
        $bad['v'] = 99;
        $r = RelDynDryRun::run('Muiri', $bad);
        $this->assertStringStartsWith('The item does not pass the eval contract', (string) $r['error']);
        $this->assertNotEmpty($r['log'], 'why, from the contract check');
        $this->assertEquals($before, $this->snapshot());
    }

    public function testTheStateDumpShowsTheCurrentEngineForEachBed(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            [$status, $h, $text] = $this->http('GET', 'debug_compose.php', ['npc' => $npc]);
            $this->assertSame(200, $status);
            $this->assertStringStartsWith('text/plain', $h['content-type'] ?? '');
            self::assertSecurityHeaders($h);
            $this->assertStringStartsWith("=== Relationship Dynamics Debug: {$npc} ===", $text);
            foreach (['--- Bond and dimensions ---', '--- Traits ---', '--- Attachment (two axes) ---', '--- Attraction curve ---',
                         '--- Fulfillment (needs vs coverage) ---', '--- Concern ---', '--- Exclusivity ---',
                         '--- Gating (what this NPC knows of the player) ---', '--- Jev line ---'] as $s) {
                $this->assertStringContainsString($s, $text, "{$npc}: {$s}");
            }
            $this->assertStringNotContainsString('(unreadable', $text, $npc);
            $this->assertMatchesRegularExpression('/^Stored state:\s+yes$/m', $text);
            $stored = self::dynamics($npc);
            $axes = RelationshipDynamics::getAttachmentAxes($stored);
            $this->assertMatchesRegularExpression('/^anxiety:\s+' . preg_quote(RelDynUiCharts::num(round($axes['anxiety'], 3), 3), '/') . '$/m', $text);
            $this->assertMatchesRegularExpression('/^\s+guard:\s+[0-9.]+$/m', $text, "{$npc}: her trait vector");
            $this->assertMatchesRegularExpression('/^level:\s+(personal|lapsed|met)$/m', $text, "{$npc}: she knows the player");

            [$status, $h, $json] = $this->http('GET', 'debug_compose.php', ['npc' => $npc, 'format' => 'json']);
            $this->assertSame(200, $status);
            $this->assertStringStartsWith('application/json', $h['content-type'] ?? '');
            $d = json_decode($json, true);
            $this->assertSame($npc, $d['state']['npc']);
            $this->assertEqualsCanonicalizing(array_values(RelDynTraits::TRAITS), array_keys($d['traits']['vector']));
            $this->assertEqualsWithDelta($axes['avoidance'], $d['attachment']['avoidance'], 0.001);
            $this->assertNotEmpty($d['fulfillment']['axes']);
            $this->assertArrayHasKey('level', $d['gating']);
        }
    }
}
