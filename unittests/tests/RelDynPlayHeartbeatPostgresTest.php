<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynHeartbeatPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) $rows[] = $row;
        return $res ? $rows : false;
    }

    public function execQuery($q) { return @pg_query($this->link, $q); }

    public function insert($table, $data)
    {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(fn($v) => pg_escape_literal($this->link, (string) $v), array_values($data)));
        return @pg_query($this->link, "INSERT INTO {$table} ({$cols}) VALUES ({$vals})");
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * timer-model: the filtered play clock must not count a wait or sleep as play, also when the
 * gap before it holds real time with the game clock stopped (game quit overnight, menus).
 * The global play heartbeat (conf_opts relationship_dynamics_play_clock) counts played gamets
 * across all requests with offline gaps capped, and bounds each NPC's credit.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynPlayHeartbeatPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;             // raw gamets per game day
    private const GAME_HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;  // raw gamets per game hour
    private const RATE = RelationshipDynamics::GAMETS_PER_REAL_SECOND;    // play gamets per real second
    private const T0 = 300 * self::DAY;

    private string $dsn;
    private string $schema;
    private RelDynHeartbeatPgDb $db;
    private array $ids = [];
    private array $savedGlobals = [];

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
        $this->schema = 'reldyn_beat' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            refid text,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        // Same shape as data/database_default.sql: conf_opts(id text PRIMARY KEY, value text).
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))"); // prerequest gold ledger + profile the Attraction Matrix reads (RelDynPlayer)
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_close($admin);

        $this->db = new RelDynHeartbeatPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_heartbeat_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function heartbeatRow(int $realTs, float $gamets, float $play): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID, json_encode(['real_ts' => $realTs, 'gamets' => $gamets, 'play' => $play])]);
    }

    private function storedHeartbeat(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID]));
        return $r ? json_decode($r['value'], true) : [];
    }

    public function testTheHeartbeatCountsPlayButNotOfflineTimeOrWaits(): void
    {
        $now = time();
        $this->assertSame(0.0, RelationshipDynamics::beatPlayClock(self::T0, $now - 3600), 'first beat starts the row');

        // 4 real minutes of play between two requests: credited in full
        $p = RelationshipDynamics::beatPlayClock(self::T0 + 240 * self::RATE, $now - 3360);
        $this->assertEqualsWithDelta(240 * self::RATE, $p, 0.001);

        // A 24 h sleep that took 8 real seconds: 8 seconds of play
        $p2 = RelationshipDynamics::beatPlayClock(self::T0 + 240 * self::RATE + self::DAY, $now - 3352);
        $this->assertEqualsWithDelta($p + 8 * self::RATE, $p2, 0.001);

        // Quit, back 40 real minutes later: one capped gap, whatever the game clock did
        $p3 = RelationshipDynamics::beatPlayClock(self::T0 + 2 * self::DAY, $now - 592);
        $this->assertEqualsWithDelta($p2 + RelationshipDynamics::PLAY_HEARTBEAT_GAP_CAP_S * self::RATE, $p3, 0.001);

        // A request 2 real seconds later does not write; its gap is counted by the next one
        $this->assertEqualsWithDelta($p3, RelationshipDynamics::beatPlayClock(self::T0 + 2 * self::DAY + 2 * self::RATE, $now - 590), 0.001);
        $this->assertSame($now - 592, (int) $this->storedHeartbeat()['real_ts']);
        $p4 = RelationshipDynamics::beatPlayClock(self::T0 + 2 * self::DAY + 10 * self::RATE, $now - 582);
        $this->assertEqualsWithDelta($p3 + 10 * self::RATE, $p4, 0.001);

        // A reload (game clock back) credits nothing
        $p5 = RelationshipDynamics::beatPlayClock(self::T0, $now - 500);
        $this->assertEqualsWithDelta($p4, $p5, 0.001);
    }

    /** An NPC last talked to at T0, whose last turn the heartbeat saw at global play $globalPlay. */
    private function seedRomantic(string $name, int $lastRealTs, float $globalPlay): void
    {
        $npcPlay = 50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;   // this NPC's play clock
        $dynamics = [
            'inferred_temperament' => 'Romantic',
            'attachment_style' => 'secure',
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_interest_vector' => [0.1, 0.2, 0.3],
            '_last_contact_gamets' => self::T0,
            '_decay_last_game_gamets' => self::T0,
            '_last_gamets' => self::T0,
            '_last_real_ts' => $lastRealTs,
            '_accumulated_play_gamets' => $npcPlay,
            '_last_contact_play_gamets' => $npcPlay,
            '_last_global_play_gamets' => $globalPlay,
        ];
        $ext = ['relationships' => ['Player' => ['aff' => 60, 'type' => 'romantic']]];   // core aff (-100..100)
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, '101', json_encode($ext), json_encode(['reldyn' => ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]]])]));
        $this->ids[$name] = (int) $row['id'];
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    private function talkTo(string $name, float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) $gamets, 'Kaida: hello'];
        $GLOBALS['HERIKA_NAME'] = $name;
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /** Scenario r2/S6b: quit overnight, load, sleep 9 game hours, talk. No reunion from the sleep. */
    public function testASleepAfterAnOvernightBreakEarnsNoReunionThroughPrerequest(): void
    {
        $overnight = time() - 12 * 3600;
        $this->heartbeatRow($overnight, self::T0, 1.0e8);        // last request before quitting
        $this->seedRomantic('Serana', $overnight, 1.0e8);

        $this->talkTo('Serana', self::T0 + 9 * self::GAME_HOUR);

        $d = $this->dynamics('Serana');
        $gained = (float) $d['_accumulated_play_gamets'] - 50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $this->assertLessThanOrEqual(RelationshipDynamics::PLAY_HEARTBEAT_GAP_CAP_S * self::RATE + 0.001, $gained,
            'at most one capped offline gap, not 27 real minutes of sleep');
        $this->assertEmpty($d['reunion_spike_given'] ?? null, 'a sleep is not time apart played');
    }

    /** Control: the same 9 game hours apart, but 30 real minutes of them played elsewhere. */
    public function testPlayedTimeApartStillEarnsTheReunion(): void
    {
        $lastTurn = time() - 31 * 60;
        // Other requests kept the heartbeat going: 30 real minutes of play since her last turn
        $this->heartbeatRow(time() - 60, self::T0 + 8 * self::GAME_HOUR, 1.0e8 + 30 * 60 * self::RATE);
        $this->seedRomantic('Serana', $lastTurn, 1.0e8);

        $this->talkTo('Serana', self::T0 + 9 * self::GAME_HOUR);

        $d = $this->dynamics('Serana');
        $this->assertNotEmpty($d['reunion_spike_given'] ?? null, 'real play apart: reunion');
        $this->assertEqualsWithDelta(9.0, (float) $d['_reunion_hours_apart'], 0.01);
    }
}
