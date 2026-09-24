<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Resentment accumulator (MDD 15.5, decisions 2026-09-23 §5), fed by contract-shaped eval
 * items (the shared eval contract v1; fixtures here, not the eval lane's producer).
 * No database: $GLOBALS['db'] is unset, so config is the defaults and nothing is stored.
 *
 * Expected values come from applyDelta() on a copy of the same state: the accumulator
 * physics (inverted rubber band, maturity-derived Y) is the engine's, the tests check what
 * raw amount each rule hands to it.
 */
final class RelDynResentmentAccumulatorTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** Minimal NPC state; $dims maps dimension => x (0..100). */
    private function npc(array $extra = [], array $dims = []): array
    {
        $d = $extra + [
            'inferred_temperament' => 'Stoic',
            'attachment_style' => 'secure',
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
        ];
        $dims += ['maturity' => 60.0, 'self_confidence' => 50.0, 'resentment' => 0.0, 'resentment_self' => 0.0];
        foreach ($dims as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => in_array($dim, ['resentment', 'resentment_self'], true) ? 0 : $x];
        }
        return $d;
    }

    private static function res(array $d, string $dim = 'resentment'): float
    {
        return (float) ($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** A contract v1 inbox item with neutral defaults. */
    private function item(array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => 'Lydia', 'npc_id' => 7, 'gamets' => 1000000, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5,
            'positive_interaction' => false,
            'summary' => 'fixture',
        ], $over);
    }

    // ------------------------------------------------------------ suppressed buildup (MDD 15.5)

    /**
     * "If resentment > 30 and NPC maturity < 50, resentment gains +50%." Y is pinned so
     * maturity only acts through this rule.
     */
    public function testSuppressedBuildupNeedsResentmentAbove30AndMaturityBelow50(): void
    {
        $pin = ['Y_up' => 1.0, 'Y_down' => 1.0];
        $gain = function (float $resentment, float $maturity) use ($pin): float {
            $d = $this->npc([], ['resentment' => $resentment, 'maturity' => $maturity]);
            return RelationshipDynamics::applyDelta('resentment', $d, 2.0, 'Stoic', $pin);
        };

        $this->assertEqualsWithDelta(1.5, $gain(40.0, 45.0) / $gain(40.0, 55.0), 1e-9, 'resentment 40, maturity 45: +50%');
        $this->assertEqualsWithDelta(1.0, $gain(20.0, 45.0) / $gain(20.0, 55.0), 1e-9, 'resentment 20: not yet bottled up');
        $this->assertEqualsWithDelta(1.0, $gain(40.0, 50.0) / $gain(40.0, 55.0), 1e-9, 'maturity 50 is not below 50');
    }

    /** processGrievances (legacy pending list) no longer stacks its own x1.5 on the rule. */
    public function testLegacyPendingGrievanceIsFivePointsThroughThePhysicsOnce(): void
    {
        $d = $this->npc([], ['resentment' => 40.0, 'maturity' => 30.0]);
        $expected = $d;
        RelationshipDynamics::applyDelta('resentment', $expected, 5.0, 'Stoic');

        $d['dimensions']['resentment']['pending_grievances'] = ['changed the subject when she was hurt'];
        $this->assertSame(1, RelationshipDynamics::processGrievances($d, 'Stoic'));

        $this->assertEqualsWithDelta(self::res($expected), self::res($d), 1e-9);
        $this->assertSame([], $d['dimensions']['resentment']['pending_grievances']);
    }
}
