<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Passion/jealousy decay, the diminishing-returns clock and reunion absence run on the
 * NPC's filtered play clock (_accumulated_play_gamets, ~2315/real second). That clock
 * passes 1e9 after ~120 real hours with an NPC, so "checkpoint > 1e9 means a legacy
 * Unix timestamp" must not be used to spot legacy values: after 120 h it would reset
 * every valid checkpoint to now and stop all of these timers for good.
 * A legacy wall-clock stamp is recognised the same way playGametsSince() does it:
 * a checkpoint ahead of the play clock.
 */
final class RelDynPlayClockCheckpointTest extends TestCase
{
    private const LONG_PLAY = 1.2e9;     // ~144 real hours of play with this NPC
    private const FIVE_MIN  = 2315 * 300;

    private $savedDb;

    protected function setUp(): void
    {
        // No database: getConfig() falls back to defaultConfig().
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

    private function dyn(array $overrides): array
    {
        return array_merge(RelationshipDynamics::defaultDynamics(), $overrides);
    }

    public function testPassionStillDecaysAfterLongPlay(): void
    {
        $d = $this->dyn(['_accumulated_play_gamets' => self::LONG_PLAY, 'warmth_curve' => 'moderate']);
        RelationshipDynamics::setPassion($d, 50.0);
        $d['passion_updated_at'] = self::LONG_PLAY - self::FIVE_MIN;

        RelationshipDynamics::decayPassion($d);

        $this->assertEqualsWithDelta(49.75, RelationshipDynamics::getPassion($d), 0.01,
            'five play-minutes of moderate decay (3.0/h) must apply at any play age');
        $this->assertSame(self::LONG_PLAY, (float) $d['passion_updated_at']);
    }

    public function testJealousyStillDecaysAfterLongPlay(): void
    {
        $d = $this->dyn(['_accumulated_play_gamets' => self::LONG_PLAY]);
        RelationshipDynamics::setJealousy($d, 30.0);
        $d['jealousy_updated_at'] = self::LONG_PLAY - 2315 * 3600;   // one play hour

        RelationshipDynamics::decayJealousy($d);

        $this->assertEqualsWithDelta(28.5, (float) $d['jealousy_anger'], 0.01, 'jealousy_decay_per_hour 1.5');
    }

    public function testDiminishingReturnsClockKeepsItsCheckpointAfterLongPlay(): void
    {
        $d = $this->dyn([
            '_accumulated_play_gamets' => self::LONG_PLAY,
            'interaction_count' => 4,
            'last_interaction_at' => self::LONG_PLAY - 2315 * 3600 * 10,   // ten play hours ago
        ]);

        // Ten hours of lambda 0.087 decay: 4 * e^-0.87 = 1.68 -> multiplier 1 - 1.68*0.08*0.8 (early stage)
        $this->assertEqualsWithDelta(1.0 - 4 * exp(-0.87) * 0.08 * 0.8,
            RelationshipDynamics::getSessionMultiplier($d), 0.001);

        RelationshipDynamics::recordInteraction($d);
        $this->assertSame(3, $d['interaction_count'], 'count decays over the ten hours before the +1');
    }

    public function testReunionAbsenceIsMeasuredAfterLongPlay(): void
    {
        $d = $this->dyn([
            '_accumulated_play_gamets' => self::LONG_PLAY,
            'last_seen_at' => self::LONG_PLAY - 2315 * 3600 * 20,   // twenty play hours apart
        ]);

        $spike = RelationshipDynamics::checkReunion($d, 80);

        $this->assertEqualsWithDelta(8.0, $spike, 0.001, '16-24 h apart gives the 8.0 spike');
        $this->assertTrue($d['reunion_spike_given']);
    }

    public function testLegacyWallClockStampAheadOfThePlayClockIsStillReArmed(): void
    {
        $play = 5.0e6;
        $d = $this->dyn(['_accumulated_play_gamets' => $play, 'last_seen_at' => 1714000000]);
        RelationshipDynamics::setPassion($d, 50.0);
        $d['passion_updated_at'] = 1714000000;

        RelationshipDynamics::decayPassion($d);
        $this->assertSame(50.0, RelationshipDynamics::getPassion($d), 'no decay from a bogus interval');
        $this->assertSame($play, (float) $d['passion_updated_at']);

        $this->assertSame(0.0, RelationshipDynamics::checkReunion($d, 80));
        $this->assertSame($play, (float) $d['last_seen_at']);
    }
}
