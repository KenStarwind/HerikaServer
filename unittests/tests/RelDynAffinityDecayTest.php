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

        // Stoic -0.3 core/tick x friend 0.7 x 100 ticks = -21 (70 -> 49), then another -21.
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

        $r = RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'friend', 250);

        // past the retention threshold (56 - 25 = 31), above the Stoic baseline 15
        $this->assertEqualsWithDelta(17.5, RelationshipDynamics::getCoreAffinity($d), 0.001, '70 - 0.3 x 0.7 x 250');
        $this->assertTrue($r['tier_changed']);
        $this->assertSame('acquaintance', $r['new_tier'], 'comfort 30 < 50: floor unlocked, demoted to the tier of core 17.5');
    }

    public function testWithinRetentionTheNumberDecaysAndTheLabelHolds(): void
    {
        $d = $this->npc(70.0, ['comfort' => 70.0, 'maturity' => 55.0]);

        $r = RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'friend', 80);

        $this->assertEqualsWithDelta(53.2, RelationshipDynamics::getCoreAffinity($d), 0.001, '70 - 0.3 x 0.7 x 80, below the close_friend floor 56');
        $this->assertSame('close_friend', $r['new_tier']);
        $this->assertSame('within_retention', $r['demotion_info']['reason'],
            'judged against the held tier close_friend, not the tier of the decayed number');
    }

    /** NPC at CORE affinity with a given temperament and attachment, floors active. */
    private function npcWith(float $coreAff, string $temperament, string $attachment = 'secure'): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
            'attachment_style' => $attachment,
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        $d['dimensions']['maturity']['x'] = 55.0;
        return $d;
    }

    public function testAStrangerDoesNotDecayTowardHostility(): void
    {
        // Core types 'neutral' and 'enemy' have no floor gate: the old code pushed every
        // stranger toward core -100 on absence alone, and committed it to core.
        foreach (['neutral', 'enemy', 'stranger'] as $type) {
            $d = $this->npcWith(0.0, 'Stoic');
            $r = RelationshipDynamics::processAffinityDecay($d, 'Nazeem', 'Stoic', $type, 500);
            $this->assertSame(0.0, RelationshipDynamics::getCoreAffinity($d), "type {$type}");
            $this->assertEqualsWithDelta(0.0, $r['decay_amount'], 0.0001, "type {$type}");
        }
    }

    public function testDecayStopsAtTheTemperamentBaseline(): void
    {
        // Stoic affinity baseline = core 15 (natural pull toward connection)
        $d = $this->npcWith(40.0, 'Stoic');
        RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'neutral', 1000);
        $this->assertEqualsWithDelta(15.0, RelationshipDynamics::getCoreAffinity($d), 0.001);

        // A per-NPC baseline (core units) wins over the temperament default
        $d = $this->npcWith(40.0, 'Stoic');
        $d['dimensions']['affinity']['baseline'] = 25.0;
        RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'neutral', 1000);
        $this->assertEqualsWithDelta(25.0, RelationshipDynamics::getCoreAffinity($d), 0.001);
    }

    public function testAbsenceNeverManufacturesOrHealsNegativeAffinity(): void
    {
        // Independent baseline is core 0: decay stops at 0, never below
        $d = $this->npcWith(20.0, 'Independent');
        RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Independent', 'neutral', 100000);
        $this->assertEqualsWithDelta(0.0, RelationshipDynamics::getCoreAffinity($d), 0.001);

        // A negative per-NPC baseline does not pull a stranger below 0 either
        $d = $this->npcWith(10.0, 'Stoic');
        $d['dimensions']['affinity']['baseline'] = -30.0;
        RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'neutral', 100000);
        $this->assertEqualsWithDelta(0.0, RelationshipDynamics::getCoreAffinity($d), 0.001);

        // Time alone neither deepens nor heals a grudge (decisions 2026-09-23 section 2)
        $d = $this->npcWith(-40.0, 'Stoic');
        RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'neutral', 500);
        $this->assertEqualsWithDelta(-40.0, RelationshipDynamics::getCoreAffinity($d), 0.001);
    }

    public function testAttachmentScalesAbsence(): void
    {
        // Decisions section 2: positive feelings fade with absence x attachment (Anxious x2, Avoidant x0.5)
        $expected = ['secure' => 67.0, 'anxious' => 64.0, 'avoidant' => 68.5];   // Stoic -0.3 x 10 ticks = -3
        foreach ($expected as $style => $aff) {
            $d = $this->npcWith(70.0, 'Stoic', $style);
            RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', 'neutral', 10);
            $this->assertEqualsWithDelta($aff, RelationshipDynamics::getCoreAffinity($d), 0.001, $style);
        }
    }

    public function testRelationshipTypeScalesDecay(): void
    {
        // Design draft: decay_per_tick = base_rate x type_decay_modifier (x attachment)
        $expected = ['stranger' => 34.0, 'acquaintance' => 35.5, 'friend' => 37.9, 'bonded' => 39.1];
        foreach ($expected as $type => $aff) {
            $d = $this->npcWith(40.0, 'Stoic');
            RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', $type, 10);
            $this->assertEqualsWithDelta($aff, RelationshipDynamics::getCoreAffinity($d), 0.001, $type);
        }

        // Hate is self-sustaining: hostile / rival do not decay
        foreach (['hostile', 'rival'] as $type) {
            $d = $this->npcWith(40.0, 'Stoic');
            $r = RelationshipDynamics::processAffinityDecay($d, 'Lydia', 'Stoic', $type, 10);
            $this->assertSame(40.0, RelationshipDynamics::getCoreAffinity($d), $type);
            $this->assertSame('no_decay_type', $r['skip_reason'], $type);
        }
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
