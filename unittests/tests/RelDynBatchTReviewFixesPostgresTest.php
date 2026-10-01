<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * Batch T review fixes that need a real PostgreSQL, on the standing test beds (Aela the Huntress,
 * Ashe (hand-set, spoiler-free vector, never read), Muiri (toxic), Lynly Star-Sung (the shy bard)).
 *
 * cascade-network: two overlapping prerequests for the same target never apply the same ripple twice.
 * One request at a time applies a target's ripple inbox (an advisory lock of its own, like the eval
 * inbox's), and the ripple ids another request already applied are read from the stored state after
 * the lock is held, so a stale copy skips them.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBatchTReviewFixesPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const YSOLDA = RelDynNetworkBedsKit::YSOLDA;
    private const SVEN = RelDynNetworkBedsKit::SVEN;
    private const INSULT = 'You call that a hunt? I mock your whole pack of hunters.';

    private RelDynNetworkBedsKit $kit;
    private ?RelDynNetworkPgDb $other = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) { pg_close($this->other->link); $this->other = null; }
        if (isset($this->kit)) $this->kit->destroy();
    }

    private function world(array $config = []): RelDynNetworkBedsKit
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'trevfix', $config);
        $this->kit->seed([
            self::AELA => ['Player' => [30, 'platonic'], self::FARKAS => [70, 'friend'], self::LYNLY => [20, 'neutral'],
                           self::ASHE => [10, 'neutral'], self::MUIRI => [-30, 'rival']],
            self::FARKAS => ['Player' => [40, 'friend'], self::AELA => [60, 'friend']],
            self::LYNLY => ['Player' => [40, 'friend'], self::AELA => [45, 'friend']],
            self::ASHE => ['Player' => [40, 'friend'], self::AELA => [35, 'neutral']],
            self::MUIRI => ['Player' => [40, 'friend'], self::AELA => [-50, 'rival']],
            self::YSOLDA => ['Player' => [40, 'friend']],
            self::SVEN => ['Player' => [40, 'friend'], self::AELA => [20, 'neutral']],
        ]);
        $this->kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        return $this->kit;
    }

    private function t(int $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    private function insultAela(int $gamets): void
    {
        $this->kit->evalReply = function (string $exchange): ?array {
            if (str_contains($exchange, 'mock your whole pack')) {
                return ['signals' => ['affinity' => -30, 'trust' => -4], 'tags' => ['insult'], 'significance' => 1.0,
                        'summary' => 'The player mocked her hunt in front of her shield-siblings.',
                        'grievance' => ['flag' => true, 'kind' => 'disrespect', 'severity' => 2]];
            }
            return null;
        };
        $this->kit->turn(self::AELA, self::INSULT, $gamets, 'insult');
        $this->kit->worker();
    }

    private function inbox(string $npc): array
    {
        $v = $this->kit->pluginKey($npc, RelDynCascade::INBOX_KEY);
        return is_array($v) ? $v : [];
    }

    private function assertClean(): void
    {
        $this->assertSame([], $this->kit->db->failures, 'failed SQL statements');
        $this->assertSame(0, $this->kit->llmCalls, 'no trait read');
        $log = $this->kit->errorLog();
        $this->assertStringNotContainsString('ERROR', $log, $log);
    }

    // ------------------------------------------------------------------ cascade-network: overlapping prerequests

    public function testARequestThatReadTheInboxBeforeTheOtherTrimmedItDoesNotApplyTheRippleAgain(): void
    {
        $this->world();
        $this->insultAela($this->t(0));
        $item = $this->inbox(self::FARKAS)[0];
        $reference = $this->kit->coreAff(self::FARKAS);

        // request B loaded Farkas's state before request A (his whole turn) applied the ripple and trimmed the inbox
        $copyB = RelationshipDynamics::getDynamics(self::FARKAS);
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $afterA = $this->kit->coreAff(self::FARKAS);
        $pendingAfterA = (float) ($this->kit->dynamics(self::FARKAS)['_pending_aff_delta'] ?? 0);   // the fraction A's commit left
        $this->assertLessThan($reference, $afterA, 'request A applied the ripple');
        $this->assertSame([], $this->inbox(self::FARKAS));

        // ... and B's read of the inbox is the same item, still there for it
        RelDynStorage::appendItem($this->kit->id(self::FARKAS), RelDynCascade::INBOX_KEY, $item);
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) $this->t(2), 'Kaida: Well met. (Talking to Farkas)'];
        $results = RelDynCascade::onPrerequest(self::FARKAS, $copyB);
        $this->assertSame([], $results, 'the ripple B read was already applied by A');
        $this->assertSame($afterA, $this->kit->coreAff(self::FARKAS), 'core affinity moved once');
        $this->assertEqualsWithDelta($pendingAfterA, (float) ($copyB['_pending_aff_delta'] ?? 0), 0.0001, 'no second ripple queued');
        $this->assertEqualsWithDelta($pendingAfterA, (float) ($this->kit->dynamics(self::FARKAS)['_pending_aff_delta'] ?? 0), 0.0001, 'none stored either');
        $this->assertSame([], $this->inbox(self::FARKAS), 'the item B read left the inbox');
        $this->assertCount(1, $this->kit->dynamics(self::FARKAS)[RelDynCascade::APPLIED_KEY], 'one id');
        $this->assertClean();
    }

    public function testWhileAnotherRequestHoldsTheInboxTheRippleWaitsAndLandsAfterwards(): void
    {
        $this->world();
        $this->insultAela($this->t(0));
        $reference = $this->kit->coreAff(self::FARKAS);
        $id = $this->kit->id(self::FARKAS);

        $this->other = new RelDynNetworkPgDb($this->kit->dsn, $this->kit->schema);
        $got = $this->other->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [RelDynStorage::CASCADE_LOCK_CLASS, $id]);
        $this->assertContains($got['got'] ?? null, ['t', true], 'the other request took the lock');

        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'busy');
        $this->assertSame($reference, $this->kit->coreAff(self::FARKAS), 'nothing applied while the other request holds the inbox');
        $this->assertCount(1, $this->inbox(self::FARKAS), 'the ripple waits in the inbox');
        $this->assertSame([], $this->kit->dynamics(self::FARKAS)[RelDynCascade::APPLIED_KEY] ?? []);

        $this->other->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int) AS released', [RelDynStorage::CASCADE_LOCK_CLASS, $id]);
        $this->kit->turn(self::FARKAS, 'Well met again.', $this->t(3), 'free');
        $this->assertLessThan($reference, $this->kit->coreAff(self::FARKAS), 'it lands on the next request');
        $this->assertSame([], $this->inbox(self::FARKAS));
        $this->assertClean();
    }

    public function testTheLockIsReleasedAfterEveryOnPrerequest(): void
    {
        $this->world();
        $this->insultAela($this->t(0));
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $id = $this->kit->id(self::FARKAS);
        $this->other = new RelDynNetworkPgDb($this->kit->dsn, $this->kit->schema);
        $got = $this->other->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [RelDynStorage::CASCADE_LOCK_CLASS, $id]);
        $this->assertContains($got['got'] ?? null, ['t', true], 'the kit connection left the cascade lock free');
        $this->other->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int)', [RelDynStorage::CASCADE_LOCK_CLASS, $id]);
        $this->assertClean();
    }
}
