<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
// Production requests have core's game-clock helpers loaded (the timeline stamp and the
// worker read DataLastKnownGameTS()).
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions:
 * fetchOne returns [] on a failed statement, fetchAll throws). Core writes some statements
 * against public.* (restoreNPC's relationship restore, background-life resets); production
 * runs in public, this test in its own schema, so "public." is read as that schema.
 */
final class RelDynSaveLoadRacePgDb
{
    public $link;
    public array $failures = [];
    private string $schema;

    public function __construct(string $dsn, string $schema)
    {
        $this->schema = $schema;
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function sql($q): string
    {
        return str_replace('public.', $this->schema . '.', (string) $q);
    }

    private function record(string $q): void
    {
        $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $q = $this->sql($q);
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) {
            $this->record($q);
            return [];
        }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $q = $this->sql($q);
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }

    public function execQuery($q)
    {
        $q = $this->sql($q);
        $res = @pg_query($this->link, $q);
        if (!$res) $this->record($q);
        return $res;
    }

    public function insert($table, $data)
    {
        $ph = [];
        foreach (array_keys($data) as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->record("insert {$table}");
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * save-load-rollback review findings, on a real PostgreSQL with core's own save / load code:
 *   - core writes its 'init' eventlog row BEFORE NpcMaster::restoreNPC (a soundcache sweep in
 *     between); a RelDyn request or the eval worker that lands in that window (its own database
 *     session, as every request has) must not reconcile the pre-restore rows: it defers, and
 *     the reconcile runs on the first entry after the restore;
 *   - a copy of dynamics read before a load never pushes its affinity into core's restored
 *     Player.aff (commitPlayerAffinity, like saveDynamics);
 *   - following core's restore, an eval item applied after core's snapshot for an exchange the
 *     load keeps applies again (exactly once across the load).
 * The load runs as main.php + comm.php run it, the init request on its OWN connection (its
 * session ends when the request does, as in production); everything else on another.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynSaveLoadRacePostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;          // raw gamets per game day
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;    // raw gamets per game hour
    private const T0 = 300 * self::DAY;                                // game day 300: the session starts
    private const S = self::T0 + self::DAY;                            // the save point
    private const PLAYER = 'Kaida';

    private const INSULT_REPLY = '{"signals": {"affinity": -10, "trust": -6, "comfort": -4, "respect": -3, "passion": 0, "maturity": 0},
        "tags": ["insult"], "grievance": {"flag": true, "kind": "insulted", "severity": 2},
        "jealousy": {"flag": false, "rival": null, "intensity": 0}, "significance": 0.8,
        "summary": "The player mocked her."}';
    private const NEUTRAL_REPLY = '{"signals": {"affinity": 3, "trust": 2, "comfort": 1, "respect": 0, "passion": 0, "maturity": 0},
        "tags": ["quality_time"], "grievance": {"flag": false, "kind": null, "severity": 0},
        "jealousy": {"flag": false, "rival": null, "intensity": 0}, "significance": 0.4,
        "summary": "A pleasant chat."}';

    private string $dsn;
    private string $schema;
    private RelDynSaveLoadRacePgDb $db;
    private array $ids = [];
    private array $savedGlobals = [];
    private string $logFile;
    private $prevLog = null;
    private int $ts = 1727000000;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_slrace' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and core_npc_master_history.sql.
        $columns = "npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text";
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, {$columns}, CONSTRAINT npc_master_npc_name_key UNIQUE (npc_name))");
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(), " . str_replace(['npc_name text NOT NULL', 'npc_favorite integer DEFAULT 0', 'lock_profile integer DEFAULT 0'],
            ['npc_name text', 'npc_favorite integer', 'lock_profile integer'], $columns) . ")");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // data/database_default.sql eventlog, with debug/db_updates.php's type index
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE INDEX event_log_type ON eventlog USING btree (type)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        // ext/relationship_system/async_queue.php: core clears both on a load
        pg_query($admin, "CREATE TABLE relationship_eval_queue (id SERIAL PRIMARY KEY, npc_id INTEGER NOT NULL UNIQUE,
            eval_data JSONB NOT NULL, created_at TIMESTAMP DEFAULT NOW(), retry_count INTEGER DEFAULT 0, last_error TEXT)");
        pg_query($admin, "CREATE TABLE relationship_init_queue (id SERIAL PRIMARY KEY, npc_id INTEGER NOT NULL, created_at TIMESTAMP DEFAULT NOW())");
        pg_close($admin);

        $this->db = new RelDynSaveLoadRacePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE',
                     'SCRIPTLINE_LISTENER_ATOMIC', 'SCRIPTLINE_LISTENER', 'NEVER_CLEAR_RELATIONSHIP_DATA', 'CACHE_PARTY'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured (the call itself is stubbed)
        $this->config([]);
        RelDynEval::$launcher = function (): void {};
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdslrace');
        $this->prevLog = ini_set('error_log', $this->logFile);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_saveload_race_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        RelDynEval::$launcher = null;
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixture

    private function config(array $overrides): void
    {
        // Neglect off: Aela's resentment moves only by the jealousy conversion (1 point per game day).
        $cfg = array_replace_recursive(RelationshipDynamics::defaultConfig(), ['neglect_enabled' => false], $overrides);
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['relationship_dynamics_config', json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
    }

    private function seed(string $name, ?int $coreAff, string $coreType, array $dims = [], array $dynamics = []): void
    {
        $ext = $coreAff === null ? [] : ['relationships' => ['Player' => ['aff' => $coreAff, 'type' => $coreType]]];
        $plugin = [];
        if ($dims !== []) {
            $dimensions = [];
            foreach ($dims as $dim => $x) {
                $dimensions[$dim] = ['x' => $x, 'baseline' => in_array($dim, ['resentment', 'resentment_self'], true) ? 0 : $x];
            }
            $dimensions['affinity'] = ['x' => ($coreAff + 100) / 2, 'baseline' => null];
            $dynamics += [
                'inferred_temperament' => 'Stoic',
                'profile_overrides' => ['attachment_style' => 'secure'],
                'love_language_primary' => RelationshipDynamics::LL_TIME,
                'love_language_secondary' => RelationshipDynamics::LL_WORDS,
                '_interest_vector' => [0.1, 0.2, 0.3],
                '_profile_autogen' => ['version' => 999],
                '_core_rel_type' => $coreType,
                '_aff_mirror_x' => ($coreAff + 100) / 2,
                '_last_contact_gamets' => self::T0,
                '_decay_last_game_gamets' => self::T0,
                'dimensions' => $dimensions,
            ];
            $plugin = ['reldyn' => ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]]];
        }
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, (string) (100 + count($this->ids)), json_encode((object) $ext), json_encode((object) $plugin)]));
        $this->ids[$name] = (int) $row['id'];
    }

    /**
     * The NPCs as a game saved at T0 has them (core's infosave snapshot exists: a row core
     * never snapshotted is one core's restore treats as created after any save).
     */
    private function world(): void
    {
        $this->seed('Lydia', 30, 'platonic', ['maturity' => 60.0, 'trust' => 40.0, 'comfort' => 40.0, 'respect' => 40.0, 'resentment' => 5.0]);
        $this->seed('Aela', 60, 'romantic', ['maturity' => 60.0, 'resentment' => 5.0], ['jealousy_anger' => 65.0]);
        $this->seed('Farkas', null, 'neutral');
        $this->infosave(self::T0);
    }

    private function ns(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, "SELECT plugin_extended_data -> 'reldyn' AS ns FROM core_npc_master WHERE id = $1", [$this->ids[$name]]));
        return $r && $r['ns'] !== null ? json_decode($r['ns'], true) : [];
    }

    private function dyn(string $name): array
    {
        return $this->ns($name)['dynamics'] ?? [];
    }

    private function coreRel(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, "SELECT extended_data -> 'relationships' -> 'Player' AS p FROM core_npc_master WHERE id = $1", [$this->ids[$name]]));
        return json_decode((string) $r['p'], true) ?? [];
    }

    private function x(array $dyn, string $dim): float
    {
        return (float) $dyn['dimensions'][$dim]['x'];
    }

    private function event(string $type, string $data, float $gamets, string $people = '|Lydia|Kaida|'): int
    {
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8) RETURNING rowid',
            [$type, $data, 'pending', (int) $gamets, $this->ts++, (int) $gamets, $people, 'Dragonsreach']));
        return (int) $row['rowid'];
    }

    /** A request to Lydia: the real prerequest, the exchange as core logs it (input, prechat copy, chat), the real postrequest (producer). */
    private function exchange(string $said, string $reply, float $gamets): void
    {
        $this->prerequest('Lydia', $gamets);
        $this->event('inputtext', "Kaida: {$said} (Talking to Lydia)", $gamets);
        $this->event('prechat', "Lydia: {$reply} (talking to Kaida)", $gamets + 1);
        $this->event('chat', "Lydia: {$reply} (talking to Kaida)", $gamets + 2);
        $GLOBALS['gameRequest'] = ['inputtext', (string) (int) $gamets, (string) (int) $gamets, "Kaida: {$said} (Talking to Lydia)"];
        $GLOBALS['RELDYN_NPC_NAME'] = 'Lydia';
        $GLOBALS['HERIKA_NAME'] = 'Lydia';
        $GLOBALS['CACHE_PEOPLE'] = '|Lydia|Kaida|';
        $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /** The real postrequest hook of a Lydia request with nothing new to queue (applies her eval inbox). */
    private function postrequestLydia(float $gamets): void
    {
        $this->config(['eval_producer' => ['enabled' => true, 'chance' => 0.0]]);
        $GLOBALS['gameRequest'] = ['inputtext', (string) (int) $gamets, (string) (int) $gamets, 'Kaida: hi'];
        $GLOBALS['RELDYN_NPC_NAME'] = 'Lydia';
        $GLOBALS['HERIKA_NAME'] = 'Lydia';
        $GLOBALS['CACHE_PEOPLE'] = '|Lydia|Kaida|';
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
        $this->config([]);
    }

    /** The real prerequest hook for a request of $npc at raw game time $gamets. */
    private function prerequest(string $npc, float $gamets, string $type = 'inputtext'): void
    {
        $GLOBALS['gameRequest'] = [$type, (string) (int) $gamets, (string) (int) $gamets, 'Kaida: hello'];
        $GLOBALS['HERIKA_NAME'] = $npc;
        $GLOBALS['CACHE_PEOPLE'] = "|{$npc}|Kaida|";
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    private function worker(string $reply, bool $applyInWorker = true, ?array &$calls = null): array
    {
        $this->config(['eval_producer' => ['apply_in_worker' => $applyInWorker]]);
        $calls = [];
        $stats = RelDynEval::runWorker(function (array $messages, array $params) use ($reply, &$calls) {
            $calls[] = $messages;
            return $reply;
        });
        $this->config([]);
        return $stats;
    }

    /** comm.php 'infosave': the player saved; core snapshots every NPC at the save's game time. */
    private function infosave(float $gamets): void
    {
        $this->event('infosave', '', $gamets);
        (new NpcMaster())->backupAllNpcs((int) $gamets);
    }

    /**
     * Loading a save, as main.php + comm.php do it: ext prerequest hooks first ('init'), then
     * comm.php's prune of later rows, the 'init' eventlog row, restoreNPC and the relationship
     * queue clear.
     */
    private function load(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['init', (string) (int) $gamets, (string) (int) $gamets, '3.4.1'];
        $GLOBALS['HERIKA_NAME'] = 'The Narrator';
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();

        $now = time();
        $g = (int) $gamets;
        pg_query($this->db->link, "DELETE FROM eventlog WHERE gamets>={$g}");
        pg_query($this->db->link, "DELETE FROM eventlog WHERE localts>{$now}");
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people) VALUES ($1, $2, $3, $4, $5, $6, $7)',
            [$g, $g, 'init', '3.4.1', 'pending', $now, '']);
        $this->assertTrue((new NpcMaster())->restoreNPC($g));
        pg_query($this->db->link, 'DELETE FROM relationship_eval_queue WHERE 1=1');
        pg_query($this->db->link, 'DELETE FROM relationship_init_queue WHERE 1=1');
        // The init request ends: its database session, with the loading lock, ends in
        // production; this harness runs it on the shared connection, so it lets the lock go.
        pg_query_params($this->db->link, 'SELECT pg_advisory_unlock($1::int, $2::int)',
            [RelDynTimeline::LOCK_CLASS, RelDynTimeline::LOCK_KEY_LOADING]);
    }

    private function pendingJobs(): array
    {
        $res = pg_query($this->db->link, "SELECT id, job::text AS job FROM reldyn_eval_queue WHERE status = 'pending' ORDER BY id");
        $rows = [];
        while ($r = pg_fetch_assoc($res)) $rows[] = json_decode($r['job'], true);
        return $rows;
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    /**
     * Play: session start (a load at T0), the save at S, then after the save an insult the
     * worker scores (grievance, core affinity down), two game days of calendar, a scored
     * exchange left in the inbox, and a job still queued.
     *
     * @return array ['S' => state at the save point, 'F' => state just before the load]
     */
    private function playPastTheSave(): array
    {
        $this->world();
        $this->load(self::T0);                                   // the session starts with a load
        $this->prerequest('Farkas', self::T0 + self::HOUR);      // first load seen: adopted
        $this->prerequest('Farkas', self::S);                    // Aela: 5 + 1 day x 1
        $this->infosave(self::S);
        $state = ['S' => ['lydia' => $this->dyn('Lydia'), 'aela' => $this->dyn('Aela'), 'aff' => $this->coreRel('Lydia')['aff']]];

        $this->exchange('You are a useless housecarl.', 'I... understand, my Thane.', self::S + self::HOUR);
        $this->assertCount(1, $this->pendingJobs(), 'the producer queued the exchange');
        $stats = $this->worker(self::INSULT_REPLY);
        $this->assertSame(1, $stats['queued']);
        $this->prerequest('Farkas', self::S + 2 * self::DAY);    // Aela's calendar: two more days
        $this->exchange('Nice weather.', 'It is, my Thane.', self::S + 2 * self::DAY + self::HOUR);
        $this->exchange('Carry my burdens.', 'As you wish.', self::S + 2 * self::DAY + 2 * self::HOUR);
        // The worker scores the first (left in the inbox for Lydia's next request); the eval
        // connector fails on the second, which stays queued for a retry.
        $this->config(['eval_producer' => ['apply_in_worker' => false]]);
        $n = 0;
        RelDynEval::runWorker(function (array $messages, array $params) use (&$n) {
            if ($n++ > 0) throw new RuntimeException('connection refused');
            return self::NEUTRAL_REPLY;
        });
        $this->config([]);

        $state['F'] = ['lydia' => $this->dyn('Lydia'), 'aela' => $this->dyn('Aela'), 'aff' => $this->coreRel('Lydia')['aff'],
                       'inbox' => $this->ns('Lydia')['eval_inbox'] ?? []];
        // The play after the save really changed things
        $this->assertLessThan($this->x($state['S']['lydia'], 'trust'), $this->x($state['F']['lydia'], 'trust'));
        $this->assertGreaterThan($this->x($state['S']['lydia'], 'resentment'), $this->x($state['F']['lydia'], 'resentment'));
        $this->assertLessThan($state['S']['aff'], $state['F']['aff'], 'RelDyn moved core Player.aff');
        $this->assertCount(1, $state['F']['inbox']);
        $this->assertCount(1, $this->pendingJobs());
        $this->assertEqualsWithDelta(8.0 + 2 / 24, $this->x($state['F']['aela'], 'resentment'), 1e-6, '5 + 3 days 2 hours x 1 per day');
        return $state;
    }

    /**
     * Loading a save with another request inside core's window: RelDyn's init prerequest, the
     * prune and core's 'init' row on the init request's own connection; then $inWindow runs on
     * the main connection (another request / the worker); then restoreNPC and the queue clear
     * on the init connection, whose session then ends.
     */
    private function loadWithAnotherRequestInTheWindow(float $gamets, callable $inWindow): void
    {
        $main = $this->db;
        $init = new RelDynSaveLoadRacePgDb($this->dsn, $this->schema);
        $GLOBALS['db'] = $init;
        $GLOBALS['gameRequest'] = ['init', (string) (int) $gamets, (string) (int) $gamets, '3.4.1'];
        $GLOBALS['HERIKA_NAME'] = 'The Narrator';
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
        $now = time();
        $g = (int) $gamets;
        pg_query($init->link, "DELETE FROM eventlog WHERE gamets>={$g}");
        pg_query($init->link, "DELETE FROM eventlog WHERE localts>{$now}");
        pg_query_params($init->link,
            'INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people) VALUES ($1, $2, $3, $4, $5, $6, $7)',
            [$g, $g, 'init', '3.4.1', 'pending', $now, '']);

        $GLOBALS['db'] = $main;               // another request, while core is still sweeping the soundcache
        $inWindow();

        $GLOBALS['db'] = $init;
        $this->assertTrue((new NpcMaster())->restoreNPC($g));
        pg_query($init->link, 'DELETE FROM relationship_eval_queue WHERE 1=1');
        pg_query($init->link, 'DELETE FROM relationship_init_queue WHERE 1=1');
        $this->assertSame([], $init->failures, 'init request SQL: ' . implode(' | ', $init->failures));
        pg_close($init->link);                // the init request ends: its session (and lock) with it
        $GLOBALS['db'] = $main;
    }

    private function marker(): ?array
    {
        $r = pg_query($this->db->link, "SELECT value FROM conf_opts WHERE id = 'relationship_dynamics_timeline'");
        $row = pg_fetch_row($r);
        return $row ? json_decode((string) $row[0], true) : null;
    }

    /**
     * NEVER_CLEAR on: a request for Farkas and the eval worker hit the window. Neither
     * reconciles; after the restore the first request does, and Lydia keeps her pre-load state
     * with a mirror that agrees with core's kept Player.aff.
     */
    public function testARequestInsideCoresRestoreWindowDoesNotReconcileThePreRestoreRows(): void
    {
        $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
        $st = $this->playPastTheSave();
        $markerBefore = $this->marker();

        $this->loadWithAnotherRequestInTheWindow(self::S, function () use ($markerBefore): void {
            $this->prerequest('Farkas', self::S + self::HOUR);
            $stats = $this->worker(self::INSULT_REPLY, true, $calls);
            $this->assertSame([], $calls, 'the worker scores nothing while core restores');
            $this->assertTrue($stats['paused'], 'the worker pauses');
            $this->assertSame($markerBefore, $this->marker(), 'nothing reconciled inside the window');
        });
        $this->prerequest('Farkas', self::S + self::HOUR);   // the first request after the restore

        $lydia = $this->dyn('Lydia');
        foreach (['trust', 'comfort', 'respect', 'resentment'] as $dim) {
            $this->assertSame($this->x($st['F']['lydia'], $dim), $this->x($lydia, $dim), "{$dim} kept from before the load");
        }
        $aff = (int) $this->coreRel('Lydia')['aff'];
        $this->assertSame((int) $st['F']['aff'], $aff, 'core kept Player.aff');
        $this->assertEqualsWithDelta(($aff + 100) / 2, $this->x($lydia, 'affinity'), 1e-9, 'the mirror agrees with core');
        $this->assertSame([], $this->pendingJobs(), 'the job of the discarded exchange was dropped by the reconcile');
        $this->assertSame('keep', $this->marker()['policy']);
        $this->assertStringContainsString('reconcile deferred', $this->log());
    }

    /**
     * NEVER_CLEAR off: an eval of an exchange before the save sits in the inbox after core's
     * snapshot; a request inside the window must not consume the stash that rescues it.
     */
    public function testAnEvalPendingAtTheLoadIsRescuedEvenWithARequestInTheWindow(): void
    {
        $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
        $this->world();
        $this->load(self::T0);
        $this->prerequest('Farkas', self::T0 + self::HOUR);
        $this->event('inputtext', 'Kaida: Before the save. (Talking to Lydia)', self::S - self::HOUR);
        $this->event('chat', 'Lydia: Indeed. (talking to Kaida)', self::S - self::HOUR + 2);
        $this->infosave(self::S);
        RelationshipDynamics::queuePendingEval('Lydia', $this->praise(self::S - self::HOUR, 'before the save'));

        $this->loadWithAnotherRequestInTheWindow(self::S, function (): void {
            $this->prerequest('Farkas', self::S + self::HOUR);
        });
        $this->prerequest('Farkas', self::S + self::HOUR);

        $inbox = $this->ns('Lydia')['eval_inbox'] ?? [];
        $this->assertCount(1, $inbox, 'the eval of the exchange before the save survives');
        $this->assertSame('before the save', $inbox[0]['eval']['summary']);
        $this->assertSame('follow', $this->marker()['policy']);
    }

    private function praise(float $gamets, string $summary, float $trust = 5.0): array
    {
        return ['v' => 1, 'npc' => 'Lydia', 'npc_id' => $this->ids['Lydia'], 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => $trust, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['praise'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0], 'significance' => 0.5,
            'positive_interaction' => true, 'summary' => $summary];
    }

    /**
     * An in-flight request read Lydia before the load; after the load and the reconcile its
     * postrequest commits affinity and saves. Neither lands: no stale mirror drift or pending
     * delta reaches core's restored Player.aff.
     */
    public function testAStaleCopyNeverPushesItsAffinityIntoCore(): void
    {
        $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
        $this->playPastTheSave();

        RelationshipDynamics::beginRequest();
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) (self::S + 3 * self::DAY), ''];
        $stale = RelationshipDynamics::getDynamics('Lydia');
        RelationshipDynamics::endRequest();
        $stale['dimensions']['affinity']['x'] = floatval($stale['_aff_mirror_x']) + 5.0;   // drift: +10 core points
        $stale['_pending_aff_delta'] = 3.0;

        $this->load(self::S);
        $this->prerequest('Farkas', self::S + self::HOUR);
        $restored = (int) $this->coreRel('Lydia')['aff'];
        $this->assertSame(30, $restored, "core restored the save point's Player.aff");

        RelationshipDynamics::beginRequest();
        $this->assertNull(RelationshipDynamics::commitPlayerAffinity('Lydia', $stale));
        $this->assertFalse(RelationshipDynamics::saveDynamics('Lydia', $stale));
        RelationshipDynamics::endRequest();
        $this->assertSame($restored, (int) $this->coreRel('Lydia')['aff'], 'nothing of the stale copy reached core');
        $this->assertEqualsWithDelta(65.0, (float) $this->dyn('Lydia')['_aff_mirror_x'], 1e-9, 'the mirror still agrees with core');
        $this->assertStringContainsString('nothing is pushed into core', $this->log());
    }

    /**
     * Exactly once across a load (follow): an eval of an exchange before the save reached the
     * inbox after core's snapshot and was applied before the load. The restore takes its effect
     * back; it is re-queued and applies again, once.
     */
    public function testAnEvalAppliedAfterTheSnapshotAppliesAgainAfterTheLoad(): void
    {
        $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
        $this->world();
        $this->load(self::T0);
        $this->prerequest('Farkas', self::T0 + self::HOUR);
        $this->event('inputtext', 'Kaida: Before the save. (Talking to Lydia)', self::S - self::HOUR);
        $this->event('chat', 'Lydia: Indeed. (talking to Kaida)', self::S - self::HOUR + 2);
        $this->infosave(self::S);
        $trustAtSave = $this->x($this->dyn('Lydia'), 'trust');

        RelationshipDynamics::queuePendingEval('Lydia', $this->praise(self::S - self::HOUR, 'before the save'));
        $this->postrequestLydia(self::S + self::HOUR);                // applied after the snapshot
        $applied = $this->x($this->dyn('Lydia'), 'trust');
        $this->assertGreaterThan($trustAtSave, $applied);
        $this->assertArrayNotHasKey('eval_inbox', $this->ns('Lydia'));

        $this->load(self::S);
        $this->prerequest('Farkas', self::S + self::HOUR);
        $this->assertCount(1, $this->ns('Lydia')['eval_inbox'] ?? [], 're-queued: its exchange is before the save');
        $this->postrequestLydia(self::S + 2 * self::HOUR);
        $this->assertEqualsWithDelta($applied, $this->x($this->dyn('Lydia'), 'trust'), 1e-6, 'applied again, once');
        $this->assertArrayNotHasKey('eval_inbox', $this->ns('Lydia'));
        $this->postrequestLydia(self::S + 3 * self::HOUR);
        $this->assertEqualsWithDelta($applied, $this->x($this->dyn('Lydia'), 'trust'), 1e-6, 'never twice');

        // An item for an exchange AFTER the save, applied before the load, stays discarded
        $this->event('inputtext', 'Kaida: After the save. (Talking to Lydia)', self::S + 4 * self::HOUR);
        RelationshipDynamics::queuePendingEval('Lydia', $this->praise(self::S + 4 * self::HOUR, 'after the save', 9.0));
        $this->postrequestLydia(self::S + 5 * self::HOUR);
        $this->assertGreaterThan($applied, $this->x($this->dyn('Lydia'), 'trust'));
        $this->infosave(self::S + 6 * self::HOUR);
        $this->load(self::S + 2 * self::HOUR);
        $this->prerequest('Farkas', self::S + 3 * self::HOUR);
        // core restored the snapshot at S again (the newest at or before the loaded time): the
        // item before the save is re-queued once more, the one after the loaded time is not
        $inbox = $this->ns('Lydia')['eval_inbox'] ?? [];
        $this->assertSame(['before the save'], array_map(fn($i) => $i['eval']['summary'], $inbox),
            'an exchange the load discarded is not re-queued');
        $this->postrequestLydia(self::S + 3 * self::HOUR);
        $this->assertEqualsWithDelta($applied, $this->x($this->dyn('Lydia'), 'trust'), 1e-6, 'the save point plus the kept exchange, once');
    }
}
