<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynCalendarPgDb
{
    public $link;
    public array $statements = [];
    /** @var callable|null runs after every statement with its SQL */
    public $afterQuery = null;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function done(string $q, $result)
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        if ($this->afterQuery !== null) ($this->afterQuery)(end($this->statements));
        return $result;
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        return $this->done($q, $res ? (pg_fetch_assoc($res) ?: []) : []);
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) $rows[] = $row;
        return $this->done($q, $res ? $rows : false);
    }

    public function execQuery($q) { return $this->done($q, @pg_query($this->link, $q)); }

    public function insert($table, $data)
    {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(fn($v) => pg_escape_literal($this->link, (string) $v), array_values($data)));
        return $this->done("INSERT INTO {$table}", @pg_query($this->link, "INSERT INTO {$table} ({$cols}) VALUES ({$vals})"));
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * The game-calendar scan (decisions 2026-09-23 §2) against a real PostgreSQL: any request
 * advances every NPC whose calendar step is due, so neglect, fester, passion fade and
 * walkaway timers move without the player talking to that NPC.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynCalendarScanPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 300 * self::DAY;   // game day 300: the player's last contact with everyone

    private string $dsn;
    private string $schema;
    private RelDynCalendarPgDb $db;
    private RelDynCalendarPgDb $other;
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
        $this->schema = 'reldyn_cal' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Column types as lib/core/database_schema/core_npc_master.sql for what RelDyn touches.
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            refid text,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))"); // prerequest gold ledger + profile the Attraction Matrix reads (RelDynPlayer)
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_close($admin);

        $this->db = new RelDynCalendarPgDb($dsn, $this->schema);
        $this->other = new RelDynCalendarPgDb($dsn, $this->schema);

        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['gameRequest'], $GLOBALS['HERIKA_NAME']);
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_calendar_scan_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        pg_close($this->other->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function clock(float $gamets): float
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, 'Kaida: hello'];
        return (float) (int) $gamets;
    }

    /** Stored dynamics as a scan would find them: last contact and last step at T0. */
    private function seed(string $name, array $dynamics, ?float $checked = self::T0): int
    {
        $dynamics += [
            'inferred_temperament' => 'Stoic',
            'attachment_style' => 'secure',
            '_last_contact_gamets' => self::T0,
            'dimensions' => ['maturity' => ['x' => 60.0], 'resentment' => ['x' => 0.0, 'baseline' => 0]],
        ];
        $ns = ['dynamics' => $dynamics];
        if ($checked !== null) $ns['calendar'] = ['checked_gamets' => $checked];
        $row = pg_fetch_assoc(pg_query_params($this->other->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, (string) (100 + count($this->ids)), '{}', json_encode(['reldyn' => $ns])]));
        return $this->ids[$name] = (int) $row['id'];
    }

    private function stored(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->other->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'];
    }

    private static function neglectLog(array $dyn): array
    {
        return array_values(array_filter($dyn['dimensions']['resentment']['grievance_log'] ?? [],
            fn($g) => ($g['tag'] ?? null) === 'neglect'));
    }

    /** What the pure calendar step does to the stored state (no DB), for comparison. */
    private function expectedAfter(string $name, float $from, float $to): array
    {
        $GLOBALS['db'] = null;
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), $this->stored($name)['dynamics']));
        RelationshipDynamics::advanceCalendar($d, $from, $to);
        $GLOBALS['db'] = $this->db;
        return $d;
    }

    public function testAMonthOfWaitingReachesEveryBondWithoutTalkingToThem(): void
    {
        $this->seed('Serana', ['relationship_type' => 'bonded']);
        $this->seed('Lydia', ['relationship_type' => 'acquaintance']);
        $now = $this->clock(self::T0 + 30 * self::DAY);
        $expected = $this->expectedAfter('Serana', self::T0, $now);

        $done = RelationshipDynamics::runCalendarScan();

        $this->assertEqualsCanonicalizing(['Serana', 'Lydia'], array_keys($done));
        $serana = $this->stored('Serana');
        $this->assertEqualsWithDelta($now, $serana['calendar']['checked_gamets'], 0.5);
        $this->assertGreaterThan(20.0, $serana['dynamics']['dimensions']['resentment']['x'], 'a month of silence hurts the bond');
        $this->assertEqualsWithDelta($expected['dimensions']['resentment']['x'], $serana['dynamics']['dimensions']['resentment']['x'], 1e-6);
        $log = self::neglectLog($serana['dynamics']);
        $this->assertCount(1, $log);
        $this->assertEqualsWithDelta(30.0, $log[0]['game_days'], 1e-6);

        $lydia = $this->stored('Lydia');
        $this->assertEqualsWithDelta($now, $lydia['calendar']['checked_gamets'], 0.5);
        $this->assertEquals(0, $lydia['dynamics']['dimensions']['resentment']['x'], 'not a bond: no neglect');
    }

    public function testNothingDueCostsOneQuery(): void
    {
        $this->seed('Serana', ['relationship_type' => 'bonded']);
        RelationshipDynamics::beginRequest();   // hook request scope: config read once, cached
        RelationshipDynamics::getConfig();
        $this->clock(self::T0 + 2 * self::DAY);
        RelationshipDynamics::runCalendarScan();

        $this->db->statements = [];
        $this->clock(self::T0 + 2 * self::DAY + 0.5 * self::DAY / 24);   // half a game hour later
        $this->assertSame([], RelationshipDynamics::runCalendarScan());
        $this->assertCount(1, $this->db->statements, 'one SELECT, nothing written');
        RelationshipDynamics::endRequest();
    }

    public function testFirstSightStartsTheClocksWithoutBackdatedNeglect(): void
    {
        // Stored by an older build: no calendar step yet, no calendar contact.
        $id = $this->seed('Serana', ['relationship_type' => 'bonded', '_last_contact_gamets' => null], null);
        $now = $this->clock(self::T0 + 90 * self::DAY);

        RelationshipDynamics::runCalendarScan();

        $s = $this->stored('Serana');
        $this->assertEqualsWithDelta($now, $s['calendar']['checked_gamets'], 0.5);
        $this->assertEqualsWithDelta($now, $s['dynamics']['_last_contact_gamets'], 0.5, 'absence counts from now');
        $this->assertEquals(0, $s['dynamics']['dimensions']['resentment']['x']);
        $this->assertGreaterThan(0, $id);
    }

    public function testOverlappingRequestsApplyAnIntervalOnce(): void
    {
        $this->seed('Serana', ['relationship_type' => 'bonded']);
        $now = $this->clock(self::T0 + 20 * self::DAY);
        $expected = $this->expectedAfter('Serana', self::T0, $now);

        // Request 1 has read Serana's calendar step when request 2 runs its whole scan.
        $fired = false;
        $this->db->afterQuery = function (string $sql) use (&$fired): void {
            if (!$fired && str_starts_with($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data')) {
                $fired = true;
                $GLOBALS['db'] = $this->other;
                RelationshipDynamics::runCalendarScan();
                $GLOBALS['db'] = $this->db;
            }
        };
        RelationshipDynamics::runCalendarScan();
        $this->db->afterQuery = null;

        $this->assertTrue($fired);
        $s = $this->stored('Serana');
        $this->assertEqualsWithDelta($expected['dimensions']['resentment']['x'], $s['dynamics']['dimensions']['resentment']['x'], 1e-6, 'not twice');
        $this->assertEqualsWithDelta(self::neglectLog($expected)[0]['raw'], self::neglectLog($s['dynamics'])[0]['raw'], 1e-6);
    }

    public function testTheNpcBeingTalkedToIsSteppedEvenPastTheLimit(): void
    {
        pg_query_params($this->other->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', ['relationship_dynamics_config',
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['calendar_scan_max_npcs' => 1]))]);
        $this->seed('Lydia', ['relationship_type' => 'friend'], self::T0 - self::DAY);   // oldest step
        $this->seed('Aela', ['relationship_type' => 'friend'], self::T0 - self::DAY);
        $this->seed('Serana', ['relationship_type' => 'bonded']);
        $now = $this->clock(self::T0 + 30 * self::DAY);

        $done = RelationshipDynamics::runCalendarScan('Serana');

        $this->assertArrayHasKey('Serana', $done, 'the speaker first, so contact is marked after its absence is felt');
        $this->assertCount(2, $done, 'plus calendar_scan_max_npcs others');
        $this->assertEqualsWithDelta($now, $this->stored('Serana')['calendar']['checked_gamets'], 0.5);
        $this->assertGreaterThan(0, $this->stored('Serana')['dynamics']['dimensions']['resentment']['x']);
    }

    public function testPrerequestForOneNpcMovesTheOtherBondsAndStampsContact(): void
    {
        $this->seed('Serana', ['relationship_type' => 'bonded']);
        $this->seed('Lydia', ['relationship_type' => 'friend', 'love_language_primary' => 'acts_of_service',
            'love_language_secondary' => 'quality_time']);
        $now = $this->clock(self::T0 + 30 * self::DAY);
        $GLOBALS['HERIKA_NAME'] = 'Lydia';

        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();

        $serana = $this->stored('Serana')['dynamics'];
        $this->assertGreaterThan(20.0, $serana['dimensions']['resentment']['x'], 'Serana was not talked to and still felt the month');
        $this->assertCount(1, self::neglectLog($serana));

        $lydia = $this->stored('Lydia')['dynamics'];
        $this->assertEqualsWithDelta($now, $lydia['_last_contact_gamets'], 0.5, 'contact stamped for the speaker');
        $this->assertCount(1, self::neglectLog($lydia), 'her own month was felt before contact was stamped');
        $this->assertEqualsWithDelta(30.0, self::neglectLog($lydia)[0]['game_days'], 1e-6);
    }

    public function testAWalkawayResolvesAndTheNpcReturnsWhileThePlayerIsElsewhere(): void
    {
        $this->seed('Aela', [
            'relationship_type' => 'friend',
            'attachment_style' => 'anxious',
            '_walkaway_state' => 'boundary_test',
            '_walkaway_boundary_test_hours' => 24.0,
            '_walkaway_activated_calendar_gamets' => self::T0,
            '_walkaway_started_calendar_gamets' => self::T0,
            '_boundary_test_started_calendar_gamets' => self::T0,
            'dimensions' => ['maturity' => ['x' => 45.0], 'resentment' => ['x' => 72.0, 'baseline' => 0], 'comfort' => ['x' => 20.0]],
        ]);
        $this->clock(self::T0 + 26 * self::DAY / 24);

        RelationshipDynamics::runCalendarScan('Lydia');   // the player is talking to someone else

        $a = $this->stored('Aela')['dynamics'];
        $this->assertSame('normal', $a['_walkaway_state'], 'boundary test resolved');
        $this->assertEquals(72.0, $a['dimensions']['resentment']['x'], 'the resentment behind it stays');
        $cmd = pg_fetch_result(pg_query($this->other->link, 'SELECT action FROM responselog'), 0, 0);
        $this->assertStringContainsString('MoveToPlayer', (string) $cmd);
    }
}
