<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Absence decay of affinity (design draft "Affinity Decay System", PR 5): the raw number
 * decays from ABSENCE, the tier label holds until the number drops past the temperament's
 * retention threshold below the tier floor AND the relationship type's floor gate fails.
 * Since A2 the decayed number is pushed into core relationships.Player.aff, so:
 *  - the held tier must actually hold the number (it could free-fall to 0 while the label
 *    stayed close_friend, because demotion was judged on the tier of the NEW number,
 *    which is always 'above_floor');
 *  - a turn a few minutes after the previous one is not absence.
 */
final class RelDynAffinityDecayTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) {
            $GLOBALS['db'] = $this->savedDb;
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** Stoic NPC at CORE affinity $coreAff (close_friend = core 56-75) with the given gate values. */
    private function npc(float $coreAff, array $dims = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Stoic',
            'attachment_style' => 'secure',   // absence mult 1.0, no absence comfort drift
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        foreach ($dims as $dim => $value) {
            $d['dimensions'][$dim]['x'] = $value;
        }
        return $d;
    }

    public function testHeldTierStopsTheNumberAtTheRetentionThreshold(): void
    {
        // Friend-type bond, comfort gate holds (70 >= 50), maturity 55 (floors active).
        $d = $this->npc(70.0, ['comfort' => 70.0, 'maturity' => 55.0]);

        // Stoic -0.3 core/tick x 100 ticks = -30 (70 -> 40), then another -30.
        RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'friend', 100);
        $r = RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'friend', 100);

        $this->assertSame('close_friend', $r['new_tier'], 'comfort gate holds the tier');
        // close_friend floor core 56 + Stoic retention -25 = core 31: the number cannot pass it while the floor holds
        $this->assertEqualsWithDelta(31.0, RelationshipDynamics::getCoreAffinity($d), 0.001);
        $this->assertSame('close_friend', $d['_current_tier']);
    }

    public function testFailedGateDemotesAndDecayContinues(): void
    {
        $d = $this->npc(70.0, ['comfort' => 30.0, 'maturity' => 55.0]);

        $r = RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'friend', 150);

        // past the retention threshold (56 - 25 = 31)
        $this->assertEqualsWithDelta(25.0, RelationshipDynamics::getCoreAffinity($d), 0.001, '70 - 0.3 x 150');
        $this->assertTrue($r['tier_changed']);
        $this->assertSame('acquaintance', $r['new_tier'], 'comfort 30 < 50: floor unlocked, demoted to the tier of core 25');
    }

    public function testWithinRetentionTheNumberDecaysAndTheLabelHolds(): void
    {
        $d = $this->npc(70.0, ['comfort' => 70.0, 'maturity' => 55.0]);

        $r = RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'friend', 60);

        $this->assertEqualsWithDelta(52.0, RelationshipDynamics::getCoreAffinity($d), 0.001, '70 - 0.3 x 60, below the close_friend floor 56');
        $this->assertSame('close_friend', $r['new_tier']);
        $this->assertSame('within_retention', $r['demotion_info']['reason'],
            'judged against the held tier close_friend, not the tier of the decayed number');
    }

    public function testShortGapBetweenTurnsIsNotAbsence(): void
    {
        $tick = RelationshipDynamics::GAMETS_PER_DECAY_TICK;
        $d = $this->npc(60.0);
        $d['_accumulated_play_gamets'] = 100.0 * $tick;
        $d['_decay_last_play_gamets'] = 100.0 * $tick;

        // Next turn three play-minutes later: still talking, no decay, checkpoint moves on.
        $d['_accumulated_play_gamets'] += 0.3 * $tick;
        $this->assertSame(0.0, RelationshipDynamics::calculateDecayTicks($d));
        $this->assertEqualsWithDelta(100.3 * $tick, (float) $d['_decay_last_play_gamets'], 0.001);

        // A dozen such turns add up to more than a tick, and still are not absence.
        for ($i = 0; $i < 12; $i++) {
            $d['_accumulated_play_gamets'] += 0.3 * $tick;
            $this->assertSame(0.0, RelationshipDynamics::calculateDecayTicks($d));
        }

        // Then the player leaves for two ticks' worth of play: that is absence.
        $d['_accumulated_play_gamets'] += 2.0 * $tick;
        $this->assertEqualsWithDelta(2.0, RelationshipDynamics::calculateDecayTicks($d), 0.0001);
    }
}
