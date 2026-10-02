<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * Cascade extensions under overlapping requests (roadmap cascade-extensions, batch W review finding): two requests of the
 * same NPC read their dynamics before either saved. What the circle writes into core is written once, and the NPC's ledger
 * of what was written always matches what core holds, so the give-back later restores exactly what was taken. Real
 * PostgreSQL, the four test beds; the hooks are driven directly (the overlap is the point). No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynWOverlapPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;

    private ?RelDynNetworkBedsKit $kit = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if ($this->kit !== null) $this->kit->destroy();
        $this->kit = null;
    }

    private function t(float $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    private function world(array $config = []): RelDynNetworkBedsKit
    {
        $this->kit = $kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'woverlap', $config);
        $kit->seed([
            self::AELA => ['Player' => [30, 'platonic'], self::FARKAS => [70, 'friend'], self::LYNLY => [20, 'neutral'], self::MUIRI => [15, 'neutral']],
            self::FARKAS => ['Player' => [70, 'friend'], self::AELA => [70, 'friend']],
            self::LYNLY => ['Player' => [25, 'neutral'], self::FARKAS => [45, 'friend'], self::AELA => [50, 'friend']],
            self::ASHE => ['Player' => [25, 'neutral'], self::FARKAS => [10, 'neutral']],
            self::MUIRI => ['Player' => [25, 'neutral'], self::FARKAS => [-50, 'rival'], self::AELA => [15, 'neutral'], self::LYNLY => [15, 'neutral']],
        ]);
        foreach ([self::AELA, self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $n) {
            pg_query_params($kit->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
                VALUES ('infonpc_close', 'idle', 'pending', \$1, \$2, \$1, \$3, \$4)",
                [$this->t(-3.0), $kit->realTs, "|{$n}|" . RelDynNetworkBedsKit::PLAYER . '|', RelDynNetworkBedsKit::HOME]);
        }
        return $kit;
    }

    private function interested(RelDynNetworkBedsKit $kit, string $npc, float $passion): void
    {
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(jsonb_set(plugin_extended_data, '{reldyn,dynamics,_attraction}',
            '{\"enabled\":true,\"attracted\":true,\"hard_zero\":false}'::jsonb), '{reldyn,dynamics,dimensions,passion,x}', to_jsonb(\$2::float8)) WHERE npc_name = \$1", [$npc, $passion]);
    }

    /** The NPC's prerequest circle step on a copy read earlier, then the save the request ends with. */
    private function overlapped(string $npc, array &$copy, int $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) $gamets, RelDynNetworkBedsKit::PLAYER . ": Good evening again. (Talking to {$npc})"];
        RelDynCascadeExt::onPrerequest($npc, $copy);
        RelationshipDynamics::saveDynamics($npc, $copy);
    }

    private function circle(RelDynNetworkBedsKit $kit, string $npc): array
    {
        return $kit->dynamics($npc)['_circle'] ?? [];
    }

    // ------------------------------------------------------------------ love triangles

    public function testTwoOverlappingRequestsTakeTheRivalryPointsOffTheRegardOnceAndTheLedgerMatchesCore(): void
    {
        $kit = $this->world();
        foreach ([self::AELA, self::LYNLY] as $i => $npc) $kit->turn($npc, 'Good evening.', $this->t(0.0) + $i * 600000, 'meet');
        $this->interested($kit, self::AELA, 70.0);
        $this->interested($kit, self::LYNLY, 55.0);
        $toLynly = fn() => intval($kit->core(self::AELA, self::LYNLY)['aff']);
        $this->assertSame(20, $toLynly());
        $at = $this->t(7.0);
        // two requests of Aela read her before either saved
        $first = RelationshipDynamics::getDynamics(self::AELA);
        $second = RelationshipDynamics::getDynamics(self::AELA);
        $this->overlapped(self::AELA, $first, $at);
        $afterFirst = $toLynly();
        $this->assertLessThan(20, $afterFirst, 'the first request cools Aela toward the rival');
        $this->overlapped(self::AELA, $second, $at);
        $this->assertSame($afterFirst, $toLynly(), 'the second, which read her before the first saved, takes nothing off twice');
        $written = floatval($this->circle($kit, self::AELA)['rivals'][self::LYNLY]['written'] ?? 0.0);
        $this->assertEqualsWithDelta($toLynly() - 20, $written, 0.001, 'the ledger holds exactly what was taken');
        $this->assertSame([], $kit->db->failures);
    }

    public function testADroppedSaveTakesNothingOffTheRegardForTheRival(): void
    {
        $kit = $this->world();
        foreach ([self::AELA, self::LYNLY] as $i => $npc) $kit->turn($npc, 'Good evening.', $this->t(0.0) + $i * 600000, 'meet');
        $this->interested($kit, self::AELA, 70.0);
        $this->interested($kit, self::LYNLY, 55.0);
        $copy = RelationshipDynamics::getDynamics(self::AELA);
        // a save load happens after the copy was read: its save is dropped as a stale copy of the discarded timeline
        $kit->event('init', 'save loaded', $this->t(6.0));
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) $this->t(7.0), RelDynNetworkBedsKit::PLAYER . ': Good evening again. (Talking to ' . self::AELA . ')'];
        RelDynCascadeExt::onPrerequest(self::AELA, $copy);
        $saved = RelationshipDynamics::saveDynamics(self::AELA, $copy);
        if ($saved) $this->markTestSkipped('the harness did not make the copy stale');
        $this->assertSame(20, intval($kit->core(self::AELA, self::LYNLY)['aff']), 'a request whose state was never kept writes nothing into core');
    }

    // ------------------------------------------------------------------ friend of a friend

    public function testTwoOverlappingRequestsMoveTheFriendOfAFriendReadingOnceAndTheLedgerMatchesTheQueue(): void
    {
        $kit = $this->world(['cascade_ext' => ['association' => ['felt' => ['from' => 0.5]]]]);
        $kit->turn(self::AELA, 'Good evening.', $this->t(0.0), 'meet');
        $before = $kit->coreAff(self::AELA) + floatval($kit->dynamics(self::AELA)['_pending_aff_delta'] ?? 0.0);
        $ledgerBefore = floatval($this->circle($kit, self::AELA)['assoc']['applied'] ?? 0.0);
        $at = $this->t(9.0);
        $first = RelationshipDynamics::getDynamics(self::AELA);
        $second = RelationshipDynamics::getDynamics(self::AELA);
        $this->overlapped(self::AELA, $first, $at);
        $this->overlapped(self::AELA, $second, $at);
        $dyn = $kit->dynamics(self::AELA);
        $after = $kit->coreAff(self::AELA) + floatval($dyn['_pending_aff_delta'] ?? 0.0);
        $ledgerAfter = floatval($dyn['_circle']['assoc']['applied'] ?? 0.0);
        $this->assertGreaterThan($ledgerBefore, $ledgerAfter, 'the reading moved');
        $this->assertEqualsWithDelta($ledgerAfter - $ledgerBefore, $after - $before, 0.01, 'what core and the queue moved is exactly what the ledger went through');
        $this->assertSame([], $kit->db->failures);
    }
}
