<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Minimal `sql`-compatible adapter over one pg connection (same return conventions as
 * lib/postgresql.class.php: fetchOne gives the first row or [], execQuery the result or false).
 * $afterQuery runs after every statement, so a test can act as another process in between.
 */
final class RelDynPgTestDb
{
    public $link;
    public array $statements = [];
    /** @var callable|null */
    public $afterQuery = null;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) {
            throw new RuntimeException("cannot connect to {$dsn}");
        }
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function done(string $q, $result)
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        if ($this->afterQuery !== null) {
            ($this->afterQuery)(end($this->statements));
        }
        return $result;
    }

    /** @var callable|null When set, the next fetchOne is sent async and this runs while it waits. */
    public $whileWaiting = null;
    public bool $wasBlocked = false;

    public function fetchOne($q, array $params = [])
    {
        if ($this->whileWaiting !== null) {
            $callback = $this->whileWaiting;
            $this->whileWaiting = null;
            $params ? pg_send_query_params($this->link, $q, $params) : pg_send_query($this->link, $q);
            usleep(300000);
            $this->wasBlocked = pg_connection_busy($this->link);   // still waiting on a lock
            $callback();
            $res = pg_get_result($this->link);
            while (pg_get_result($this->link) !== false) {
            }
            $row = ($res && pg_result_status($res) === PGSQL_TUPLES_OK) ? (pg_fetch_assoc($res) ?: []) : [];
            return $this->done($q, $row);
        }
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        $row = $res ? (pg_fetch_assoc($res) ?: []) : [];
        return $this->done($q, $row);
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $this->done($q, $rows);
    }

    public function execQuery($q)
    {
        return $this->done($q, @pg_query($this->link, $q));
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * RelDyn's storage and affinity SQL against a real PostgreSQL (the other RelDyn tests use
 * in-memory fakes that only recognise statement shapes, so they cannot catch SQL that
 * PostgreSQL rejects or evaluates differently).
 *
 * Opt-in: set RELDYN_TEST_PG_DSN to a THROWAWAY database, e.g.
 *   RELDYN_TEST_PG_DSN="host=/tmp port=55433 dbname=reldyn_scratch user=postgres"
 * Never point it at the live dwemer database. Each test works in its own schema and drops it.
 */
final class RelDynPostgresStorageTest extends TestCase
{
    private const NPC = 'Lydia';

    private string $dsn;
    private string $schema;
    private RelDynPgTestDb $db;
    private RelDynPgTestDb $other;
    private int $npcId;
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
        $this->schema = 'reldyn_t' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Same column types as lib/core/database_schema/core_npc_master.sql for what RelDyn touches.
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_close($admin);

        $this->db = new RelDynPgTestDb($dsn, $this->schema);
        $this->other = new RelDynPgTestDb($dsn, $this->schema);

        foreach (['db', 'PLAYER_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['gameRequest']);
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) {
            return;
        }
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        pg_close($this->db->link);
        pg_close($this->other->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function seed(array $extended, ?array $dynamics): void
    {
        $plugin = $dynamics === null ? '{}' : json_encode(['reldyn' => ['dynamics' => $dynamics]], JSON_UNESCAPED_UNICODE);
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, extended_data, plugin_extended_data) VALUES ($1, $2::jsonb, $3::jsonb) RETURNING id',
            [self::NPC, json_encode($extended), $plugin]));
        $this->npcId = (int) $row['id'];
    }

    private function row(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->other->link,
            'SELECT extended_data, plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->npcId]));
        return ['extended' => json_decode($r['extended_data'], true), 'plugin' => json_decode($r['plugin_extended_data'], true)];
    }

    private function casStatements(): array
    {
        return array_values(array_filter($this->db->statements, fn($s) => str_contains($s, 'IS NOT DISTINCT FROM $5::jsonb')));
    }

    public function testMergedSaveRoundTripsAndNeedsNoRetryForAwkwardJson(): void
    {
        // Nested empty object, non-ASCII text, small floats: the compare-and-set must not
        // see a change that is only a difference in JSON spelling.
        $this->seed(['relationships' => ['Player' => ['aff' => 20]]], [
            'love_language_primary' => 'quality_time',
            'jealousy_trigger_npc' => 'Aela the Huntress — Jørvaskr',
            '_interest_satisfaction' => new stdClass(),
            'passion' => 0.1,
            'dimensions' => ['passion' => ['x' => 0.1, 'baseline' => 0], 'arousal' => ['x' => 1.0E-7, 'baseline' => 10]],
            '_dimension_state_version' => RelationshipDynamics::DIMENSION_STATE_VERSION,
        ]);

        $d = RelationshipDynamics::getDynamics(self::NPC);
        $d['total_positive_interactions'] = 4;
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $d));

        $this->assertCount(1, $this->casStatements(), 'first compare-and-set must succeed');
        $stored = $this->row()['plugin']['reldyn']['dynamics'];
        $this->assertSame(4, $stored['total_positive_interactions']);
        $this->assertSame('Aela the Huntress — Jørvaskr', $stored['jealousy_trigger_npc']);
    }

    public function testCompareAndSetRefusesAWriteFromAnotherConnectionAndTheRetryKeepsIt(): void
    {
        $this->seed([], ['jealousy_anger' => 0.0, 'total_positive_interactions' => 10,
            '_dimension_state_version' => RelationshipDynamics::DIMENSION_STATE_VERSION]);
        $d = RelationshipDynamics::getDynamics(self::NPC);
        $d['total_positive_interactions'] = 11;

        $fired = false;
        $this->db->afterQuery = function (string $sql) use (&$fired): void {
            if (!$fired && str_starts_with($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data')) {
                $fired = true;
                pg_query($this->other->link, "UPDATE core_npc_master SET plugin_extended_data =
                    jsonb_set(plugin_extended_data, '{reldyn,dynamics,jealousy_anger}', '7.5') WHERE id = {$this->npcId}");
            }
        };
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $d));
        $this->db->afterQuery = null;

        $this->assertCount(2, $this->casStatements(), 'refused once, then retried after re-merging');
        $stored = $this->row()['plugin']['reldyn']['dynamics'];
        $this->assertEquals(7.5, $stored['jealousy_anger']);
        $this->assertSame(11, $stored['total_positive_interactions']);
    }

    public function testInboxAppendTakeAndLegacyMigrationRunOnPostgres(): void
    {
        $this->seed(['relationship_dynamics' => ['love_language_primary' => 'gifts', 'total_positive_interactions' => 3]], null);

        $d = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertSame('gifts', $d['love_language_primary'], 'migrated from extended_data');
        $this->assertSame('gifts', $this->row()['plugin']['reldyn']['dynamics']['love_language_primary']);

        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::NPC, ['trust_delta' => 3]));
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::NPC, ['respect_delta' => 2]));
        $this->assertCount(2, $this->row()['plugin']['reldyn']['eval_inbox']);

        $results = RelationshipDynamics::processPendingEvalDeltas(self::NPC, $d);
        $this->assertEqualsCanonicalizing(['trust', 'respect'], array_keys($results));
        $this->assertArrayNotHasKey('eval_inbox', $this->row()['plugin']['reldyn']);
        $this->assertSame([], RelationshipDynamics::processPendingEvalDeltas(self::NPC, $d));
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $d));
    }

    public function testTakeBlockedByAnAppendInFlightGetsThatItemExactlyOnce(): void
    {
        $this->seed([], null);
        RelDynStorage::appendItem($this->npcId, RelDynStorage::KEY_EVAL_INBOX, ['eval' => ['trust_delta' => 1]]);

        // Another process is appending (row locked, not committed yet) when the consumer takes.
        pg_query($this->other->link, 'BEGIN');
        pg_query($this->other->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data,
            '{reldyn,eval_inbox}', (plugin_extended_data #> '{reldyn,eval_inbox}') || '[{\"eval\":{\"respect_delta\":2}}]')
            WHERE id = {$this->npcId}");

        $this->db->whileWaiting = fn() => pg_query($this->other->link, 'COMMIT');
        $taken = RelDynStorage::takeItems($this->npcId, RelDynStorage::KEY_EVAL_INBOX);

        $this->assertTrue($this->db->wasBlocked, 'the take waited for the row lock');
        $this->assertSame([['eval' => ['trust_delta' => 1]], ['eval' => ['respect_delta' => 2]]], $taken,
            'the append that committed first is included');
        $this->assertSame([], RelDynStorage::takeItems($this->npcId, RelDynStorage::KEY_EVAL_INBOX), 'nothing taken twice');
    }

    public function testCompareAndSetBlockedByAConcurrentWriterIsRefused(): void
    {
        $this->seed([], ['jealousy_anger' => 0.0]);
        $read = RelDynStorage::readKeyForUpdate($this->npcId, RelDynStorage::KEY_DYNAMICS);

        pg_query($this->other->link, 'BEGIN');
        pg_query($this->other->link, "UPDATE core_npc_master SET plugin_extended_data =
            jsonb_set(plugin_extended_data, '{reldyn,dynamics,jealousy_anger}', '3') WHERE id = {$this->npcId}");

        $this->db->whileWaiting = fn() => pg_query($this->other->link, 'COMMIT');
        $ok = RelDynStorage::setKeyIfUnchanged($this->npcId, RelDynStorage::KEY_DYNAMICS, $read['expected'], ['jealousy_anger' => 0.0, 'x' => 1]);

        $this->assertTrue($this->db->wasBlocked);
        $this->assertFalse($ok, 'the row changed while this write waited: refused, caller re-merges');
        $this->assertEquals(3, $this->row()['plugin']['reldyn']['dynamics']['jealousy_anger']);
    }

    public function testAffinityDeltaWaitsForCoresSessionLockAndHonoursTheEditorLock(): void
    {
        $this->seed(['relationships' => ['Player' => ['aff' => 25, 'type' => 'platonic', 'note' => 'x']]], null);
        $lockKey = RelationshipDynamics::CORE_RELATIONSHIP_LOCK_BASE + $this->npcId;

        // Core's relationship worker holds its session-level lock on this NPC.
        pg_query($this->other->link, "SELECT pg_advisory_lock({$lockKey})");
        pg_query($this->db->link, "SET lock_timeout = '300ms'");
        $this->assertNull(RelationshipDynamics::applyPlayerAffinityDelta(self::NPC, -3), 'must wait for core, not write past it');
        $this->assertSame(25, $this->row()['extended']['relationships']['Player']['aff']);
        pg_query($this->other->link, "SELECT pg_advisory_unlock({$lockKey})");

        $result = RelationshipDynamics::applyPlayerAffinityDelta(self::NPC, -3);
        $this->assertSame(['old' => 25, 'new' => 22, 'delta' => -3], $result);
        $rel = $this->row()['extended']['relationships']['Player'];
        $this->assertSame(22, $rel['aff']);
        $this->assertSame('x', $rel['note']);
        $this->assertSame('t', pg_fetch_result(pg_query($this->other->link, "SELECT pg_try_advisory_lock({$lockKey})"), 0, 0),
            'transaction lock released at COMMIT');
        pg_query($this->other->link, "SELECT pg_advisory_unlock({$lockKey})");

        pg_query($this->other->link, "UPDATE core_npc_master SET extended_data = extended_data || '{\"relationships_locked\": true}' WHERE id = {$this->npcId}");
        $locked = RelationshipDynamics::applyPlayerAffinityDelta(self::NPC, -5);
        $this->assertSame(0, $locked['delta']);
        $this->assertSame(22, $this->row()['extended']['relationships']['Player']['aff']);
        $this->assertSame(PGSQL_TRANSACTION_IDLE, pg_transaction_status($this->db->link), 'no transaction left open');
    }
}
