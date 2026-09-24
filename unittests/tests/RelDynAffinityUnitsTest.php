<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * dimensions.affinity.x is a mirror of core relationships.Player.aff: x = (aff + 100) / 2,
 * and commitPlayerAffinity() pushes x changes to core as (dx * 2) core points.
 *
 * Affinity itself is on CHIM's -100..+100 scale (MDD 6.5: walkaway at affinity -20;
 * MDD 15.1: affinity_delta -30..+30, "+5 genuine kindness", "-10 betrayal"), and so are
 * the temperament affinity baselines ("natural pull toward connection": Independent 0,
 * Stoic 15, Nurturing 40). Read as mirror units those baselines would sit at core -100,
 * -70 and -20, and the rubber band would pull every NPC toward hostility: negative deltas
 * boosted, positive ones resisted. The affinity physics must run in core units.
 */
final class RelDynAffinityUnitsTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);   // getConfig() -> defaults (dimension engine on)
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) {
            $GLOBALS['db'] = $this->savedDb;
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function npcAtCoreAff(float $coreAff, string $temperament = 'Stoic'): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        return $d;
    }

    private static function coreAff(array $d): float
    {
        return floatval($d['dimensions']['affinity']['x']) * 2.0 - 100.0;
    }

    public function testTemperamentBaselineValuesAreUnchanged(): void
    {
        $this->assertSame(15.0, RelationshipDynamics::getTemperamentBaseline('Stoic', 'affinity'));
        $this->assertSame(30.0, RelationshipDynamics::getTemperamentBaseline('Romantic', 'affinity'));
    }

    public function testAtItsBaselineAnNpcMovesTheSameAmountEitherWay(): void
    {
        // Stoic: baseline 15 (core units), symmetric resistance Y = 0.5 (MDD 15.4)
        $down = $this->npcAtCoreAff(15.0);
        $up = $this->npcAtCoreAff(15.0);
        RelationshipDynamics::applyDelta('affinity', $down, -8, 'Stoic');
        RelationshipDynamics::applyDelta('affinity', $up, 8, 'Stoic');

        $this->assertEqualsWithDelta(11.0, self::coreAff($down), 0.01, '-8 raw x Y 0.5 = -4 core points');
        $this->assertEqualsWithDelta(19.0, self::coreAff($up), 0.01, '+8 raw x Y 0.5 = +4 core points');
    }

    public function testNegativeEvalIsNotAmplifiedTowardAHostileBaseline(): void
    {
        // Lydia (Stoic) at core +20; the eval says affinity_delta -8.
        $d = $this->npcAtCoreAff(20.0);
        RelationshipDynamics::processEvalDeltas('Lydia', ['affinity_delta' => -8], $d);
        $down = self::coreAff($d) - 20.0;

        $e = $this->npcAtCoreAff(20.0);
        RelationshipDynamics::processEvalDeltas('Lydia', ['affinity_delta' => 8], $e);
        $up = self::coreAff($e) - 20.0;

        $this->assertLessThan(0, $down);
        $this->assertGreaterThanOrEqual(-8.0, $down, 'a -8 eval moves core by at most 8 points (was -21)');
        $this->assertGreaterThan(0, $up);
        $this->assertLessThan(2.0, abs($down) / $up, 'no hostile pull: loss and gain are of similar size');
    }

    public function testRomanticNpcAboveBaselineIsNotDraggedTowardHostility(): void
    {
        $d = $this->npcAtCoreAff(40.0, 'Romantic');   // baseline 30
        RelationshipDynamics::applyDelta('affinity', $d, -8, 'Romantic');
        // toward baseline: -8 x 1.3 x (1 + 10/25) = -14.56 core (was -45.76)
        $this->assertEqualsWithDelta(40.0 - 14.56, self::coreAff($d), 0.05);
    }
}
