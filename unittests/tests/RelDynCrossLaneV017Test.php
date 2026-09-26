<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Integration v0.17, where the Phase 4 lanes meet, without a database:
 *   - the impulse short band (MDD 13.1) reads the passion of the moment: the romantic drive is
 *     the effective passion (the earned floor + the spike + the weather's pull, RelDynPassion),
 *     not the floor alone;
 *   - a load rolls back the combat lane's rescue moment the way it rolls back the absence and
 *     impulse clocks: a fall the load discarded never happened, and the felt moment of a rescue
 *     answered after the loaded time is gone.
 */
final class RelDynCrossLaneV017Test extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 300 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
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

    /** A partner alone with the player: the romantic drive at full privacy. */
    private static function aloneEnv(): array
    {
        return ['now' => (float) self::T0, 'present' => true, 'audience' => 0, 'tier' => 3, 'strained' => false, 'platonic' => false];
    }

    public function testTheRomanticUrgeReadsTheMomentOnTopOfTheFloor(): void
    {
        $d = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::setPassion($d, 30.0);
        $x = RelDynImpulse::traitsOf($d);
        $w = floatval(RelDynImpulse::config()['romantic']['passion_weight']);
        $floorOnly = RelDynImpulse::drives($d, self::aloneEnv(), $x)['levels']['romantic'];
        $this->assertEqualsWithDelta(30.0 * $w, $floorOnly, 1e-9);

        // The same floor with a racing heart on top of it: the urge is the moment's
        $d[RelDynPassion::SPIKE_KEY] = 12.0;
        $this->assertEqualsWithDelta(42.0, RelationshipDynamics::getEffectivePassion($d), 1e-9);
        $withSpike = RelDynImpulse::drives($d, self::aloneEnv(), $x);
        $this->assertEqualsWithDelta(42.0 * $w, $withSpike['levels']['romantic'], 1e-9);
        $this->assertSame('passion', $withSpike['sources']['romantic']);

        // ... and the weather's hold on passion (an overcast day's pull) is part of it
        $d['_weather_gravity'] = ['offsets' => ['passion' => -3.0]];
        $held = RelationshipDynamics::weatherGravityOffset($d, 'passion');
        $this->assertEqualsWithDelta(RelationshipDynamics::getEffectivePassion($d) * $w,
            RelDynImpulse::drives($d, self::aloneEnv(), $x)['levels']['romantic'], 1e-9, 'held ' . $held);

        // The glow after a fight turns romantic on the moment too (aftermath_passion_min)
        $e = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::setPassion($e, 12.0);
        $min = floatval(RelDynImpulse::config()['romantic']['aftermath_passion_min']);
        $this->assertLessThan($min, 12.0);
        $env = self::aloneEnv() + ['aftermath' => true];
        $this->assertNotSame('aftermath', RelDynImpulse::drives($e, $env, $x)['sources']['romantic'], 'the floor alone is too cool for the glow');
        $e[RelDynPassion::SPIKE_KEY] = $min;
        $this->assertSame('aftermath', RelDynImpulse::drives($e, $env, $x)['sources']['romantic'], 'the rescue moment warms it');
    }

    public function testALoadDiscardsARescueFromTheFutureItNeverLived(): void
    {
        $T = (float) (self::T0 + 2 * self::DAY);
        $d = RelationshipDynamics::defaultDynamics();
        $d[RelDynCombat::RESCUE_PENDING_KEY] = ['fall_gamets' => $T + 500.0, 'claimed_gamets' => null];
        $d[RelDynCombat::RESCUE_LAST_KEY] = ['gamets' => $T + 800.0, 'bonus' => 3.0, 'gain' => 2.5, 'felt' => 'cling', 'via' => 'eval:1'];
        $r = RelDynTimeline::rebaselineDynamics($d, $T, null);
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $r, 'no rescue waits for a fall the load discarded');
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_LAST_KEY, $r, 'the felt moment of a rescue after the loaded time is gone');
        $this->assertNull(RelDynCombat::jev($r));

        // What happened before the loaded time stays
        $d[RelDynCombat::RESCUE_PENDING_KEY] = ['fall_gamets' => $T - 500.0, 'claimed_gamets' => null];
        $d[RelDynCombat::RESCUE_LAST_KEY] = ['gamets' => $T - 800.0, 'bonus' => 3.0, 'gain' => 2.5, 'felt' => 'cling', 'via' => 'eval:1'];
        $r = RelDynTimeline::rebaselineDynamics($d, $T, null);
        $this->assertSame($d[RelDynCombat::RESCUE_PENDING_KEY], $r[RelDynCombat::RESCUE_PENDING_KEY]);
        $this->assertSame($d[RelDynCombat::RESCUE_LAST_KEY], $r[RelDynCombat::RESCUE_LAST_KEY]);
        // A claim stamped after the loaded time (the exchange the load discarded) is released
        $d[RelDynCombat::RESCUE_PENDING_KEY] = ['fall_gamets' => $T - 500.0, 'claimed_gamets' => $T + 100.0];
        $r = RelDynTimeline::rebaselineDynamics($d, $T, null);
        $this->assertNull($r[RelDynCombat::RESCUE_PENDING_KEY]['claimed_gamets'], 'the exchange that claimed it never happened');
    }
}
