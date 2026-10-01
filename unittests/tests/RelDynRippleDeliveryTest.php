<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Cascade ripples, rulings 2026-10-01 §20 #13 and #14, the parts that need no database:
 *   #13 the strength leans to the MDD §10 example (Farkas at 80 loses about 8 of a 10 drop), the hearsay
 *       damping (cascade_decay) stays for what is told rather than seen;
 *   #14 no telepathic ripples: a witness hears at once, anyone else by word of mouth when she and the source
 *       actually talk, otherwise after a delay set by hold distance and bond (sooner the closer); a pending
 *       ripple carries its earliest-deliverable gamets.
 * (The story on PostgreSQL with the four test beds is RelDynRippleDeliveryTestBedsPostgresTest.)
 */
final class RelDynRippleDeliveryTest extends TestCase
{
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 210 * RelationshipDynamics::GAMETS_PER_DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'CACHE_PEOPLE', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ #13: strength

    public function testFarkasAtEightyLosesAboutEightOfATenDrop(): void
    {
        // the MDD's own example (section 10.1): Drop 10 with Aela, Farkas (80 affinity to Aela) loses 8
        $this->assertEqualsWithDelta(-8.0, RelDynCascade::rippleFor(-10.0, 80.0), 1e-9, 'seen with his own eyes: the whole of the MDD figure');
        $this->assertEqualsWithDelta(8.0, RelDynCascade::rippleFor(10.0, 80.0), 1e-9, 'good news the same');
        // the old retune (x 0.3) gave -2.4: well short of the example
        $this->assertLessThan(-6.0, RelDynCascade::rippleFor(-10.0, 80.0, null, 'told'), 'and told, still most of it');
    }

    public function testHearsayDampingStaysForWhatIsToldRatherThanSeen(): void
    {
        $decay = (float) RelationshipDynamics::defaultConfig()['cascade_decay'];
        $this->assertGreaterThan(0.8, $decay, 'leans to the MDD: little is lost in the telling');
        $this->assertLessThan(1.0, $decay, 'but hearsay is still damped');
        $seen = RelDynCascade::rippleFor(-20.0, 60.0, null, 'witnessed');
        foreach (['told', 'delayed'] as $via) {
            $this->assertEqualsWithDelta($seen * $decay, RelDynCascade::rippleFor(-20.0, 60.0, null, $via), 1e-9, $via);
        }
        $this->assertSame(RelDynCascade::rippleFor(-20.0, 60.0), $seen, 'no channel named = what a witness takes in');
    }

    public function testTheEnemyInversionAndTheBondFilterAreAsBefore(): void
    {
        $ally = RelDynCascade::rippleFor(-20.0, 60.0);
        $enemy = RelDynCascade::rippleFor(-20.0, -60.0);
        $this->assertLessThan(0.0, $ally);
        $this->assertGreaterThan(0.0, $enemy);
        $this->assertEqualsWithDelta(abs($ally) * 0.5, $enemy, 1e-9);
        $this->assertNull(RelDynCascade::rippleFor(-30.0, 30.0));
        $this->assertNotNull(RelDynCascade::rippleFor(-30.0, 31.0));
        $this->assertSame(RelDynCascade::rippleFor(-20.0, 100.0), RelDynCascade::rippleFor(-20.0, 250.0), 'bond capped at 100');
    }

    // ------------------------------------------------------------------ #14: who was there

    private static function entry(array $over = []): array
    {
        return $over + ['fp' => 'x', 'delta' => -17.0, 'gamets' => self::T0, 'anchor' => null, 'defining' => false,
                        'witnesses' => ['Farkas', 'Ysolda'], 'hold' => 'Whiterun'];
    }

    public function testAWitnessIsAnyoneTheEventlogHadPresentAtTheExchange(): void
    {
        $e = self::entry();
        $this->assertTrue(RelDynCascade::isWitness('Farkas', $e, null, self::T0));
        $this->assertTrue(RelDynCascade::isWitness('farkas', $e, null, self::T0), 'names compare without case');
        $this->assertFalse(RelDynCascade::isWitness('Lynly Star-Sung', $e, null, self::T0));
        $this->assertFalse(RelDynCascade::isWitness('Farkas', self::entry(['witnesses' => []]), null, self::T0));
    }

    public function testCachePeopleCountsOnlyForTheExchangeThatIsHappeningNow(): void
    {
        $people = '|Aela the Huntress|Lynly Star-Sung|Kaida|';
        $e = self::entry(['witnesses' => null]);   // the item recorded no witnesses
        $this->assertTrue(RelDynCascade::isWitness('Lynly Star-Sung', $e, $people, self::T0 + 10 * self::HOUR / 60), 'ten game minutes on: she is still in the room');
        $this->assertFalse(RelDynCascade::isWitness('Lynly Star-Sung', $e, $people, self::T0 + 5 * self::HOUR), 'five game hours on: whoever is around now was not there then');
        $this->assertFalse(RelDynCascade::isWitness('Lynly Star-Sung', $e, $people, 0.0), 'no game clock: nothing is assumed');
        // the recorded witnesses are the record: the people around now do not add to them
        $this->assertFalse(RelDynCascade::isWitness('Lynly Star-Sung', self::entry(['witnesses' => ['Farkas']]), $people, self::T0));
        // the people column carries states: "|Farkas (sitting)|"
        $this->assertTrue(RelDynCascade::isWitness('Farkas', $e, '|Farkas (sitting)|Kaida|', self::T0));
        $this->assertFalse(RelDynCascade::isWitness('Kaida', $e, '|Farkas|Kaida|', self::T0), 'the player is no target');
    }

    // ------------------------------------------------------------------ #14: how long the word takes

    public function testHoldDistanceIsTheGatingGraph(): void
    {
        $this->assertSame(0, RelDynCascade::holdSteps('Whiterun', 'Whiterun Hold'));
        $this->assertSame(1, RelDynCascade::holdSteps('Whiterun', 'The Pale'));
        $this->assertSame(2, RelDynCascade::holdSteps('Whiterun', 'Winterhold'), 'the hold graph, as prompt gating reads it');
        $this->assertSame((int) RelDynGating::config()['unknown_hold_distance'], RelDynCascade::holdSteps('', 'Whiterun'), 'unknown is far');
        $this->assertSame((int) RelDynGating::config()['unknown_hold_distance'], RelDynCascade::holdSteps('Whiterun', 'Sovngarde'));
    }

    public function testTheDelayShortensWithDistanceAndWithBond(): void
    {
        $cfg = RelDynCascade::config();
        $d = fn(int $steps, float $bond) => RelDynCascade::delayGameHours($steps, $bond, $cfg);
        // sooner the closer, by place ...
        $this->assertLessThan($d(1, 60.0), $d(0, 60.0));
        $this->assertLessThan($d(2, 60.0), $d(1, 60.0));
        $this->assertLessThan($d(4, 60.0), $d(2, 60.0));
        // ... and by bond (an enemy's bond counts by its strength: she is as keen to hear)
        $this->assertLessThan($d(2, 40.0), $d(2, 90.0));
        $this->assertEqualsWithDelta($d(2, 70.0), $d(2, -70.0), 1e-9);
        // the same hold, a close friend: a matter of the hour or so; a stranger's gossip across the map: days
        $this->assertLessThan(2.0, $d(0, 80.0));
        $this->assertGreaterThan(24.0, $d(RelDynCascade::holdSteps('', ''), 35.0));
        // never instant, never beyond the cap
        $this->assertGreaterThan(0.0, $d(0, 100.0));
        $this->assertLessThanOrEqual((float) $cfg['delivery']['max_game_hours'], $d(99, 31.0));
    }

    public function testADistantPlaceTakesLongerThanTheSameHoldAtTheSameBond(): void
    {
        $cfg = RelDynCascade::config();
        $near = RelDynCascade::deliveryAt(self::entry(), 'Whiterun', 80.0, self::T0, $cfg);
        $far = RelDynCascade::deliveryAt(self::entry(), 'Winterhold', 80.0, self::T0, $cfg);
        $unknown = RelDynCascade::deliveryAt(self::entry(['hold' => '']), '', 80.0, self::T0, $cfg);
        $this->assertSame('delayed', $near['via']);
        $this->assertGreaterThan(self::T0, $near['ready_at'], 'a pending ripple carries its earliest-deliverable gamets, after the news');
        $this->assertGreaterThan($near['ready_at'], $far['ready_at']);
        $this->assertGreaterThan($far['ready_at'], $unknown['ready_at']);
        $this->assertSame(0, $near['steps']);
        $this->assertSame(2, $far['steps']);
        // the same place, a stronger bond: sooner
        $this->assertLessThan($near['ready_at'], RelDynCascade::deliveryAt(self::entry(), 'Whiterun', 100.0, self::T0, $cfg)['ready_at']);
    }

    // ------------------------------------------------------------------ #14: when she gets it

    private static function item(array $over = []): array
    {
        return $over + ['id' => 'r1', 'source' => 'Aela the Huntress', 'delta' => -5.0, 'kind' => 'ally_hurt', 'gamets' => self::T0,
                        'anchor' => null, 'defining' => false, 'via' => 'delayed', 'ready_at' => self::T0 + 6 * self::HOUR];
    }

    public function testAWitnessedRippleIsDeliverableAtOnce(): void
    {
        $this->assertSame('witnessed', RelDynCascade::deliverVia(self::item(['via' => 'witnessed', 'ready_at' => self::T0]), self::T0, []));
    }

    public function testAPendingRippleWaitsUntilItsGametsAndThenComesWithoutBeingTold(): void
    {
        $item = self::item();
        $this->assertNull(RelDynCascade::deliverVia($item, self::T0 + 1 * self::HOUR, []), 'not yet: Farkas is not told by telepathy');
        $this->assertNull(RelDynCascade::deliverVia($item, self::T0 + 5.9 * self::HOUR, []));
        $this->assertSame('delayed', RelDynCascade::deliverVia($item, self::T0 + 6 * self::HOUR, []), 'it reaches her by then');
        $this->assertSame('delayed', RelDynCascade::deliverVia($item, self::T0 + 40 * self::HOUR, []));
    }

    public function testWordOfMouthDeliversItWhenSheAndTheSourceActuallyTalk(): void
    {
        $item = self::item();
        $now = self::T0 + 1 * self::HOUR;
        $talk = fn(string $with, float $at) => [['with' => $with, 'gamets' => $at]];
        $this->assertSame('told', RelDynCascade::deliverVia($item, $now, $talk('Aela the Huntress', self::T0 + 0.5 * self::HOUR)));
        $this->assertSame('told', RelDynCascade::deliverVia($item, $now, $talk('aela the huntress', self::T0 + 0.5 * self::HOUR)), 'without case');
        $this->assertNull(RelDynCascade::deliverVia($item, $now, $talk('Ysolda', self::T0 + 0.5 * self::HOUR)), 'a talk with someone else carries no news of her');
        $this->assertNull(RelDynCascade::deliverVia($item, $now, $talk('Aela the Huntress', self::T0 - 2 * self::HOUR)), 'a talk from before it happened cannot have carried it');
    }

    public function testNoGameClockAndOldItemsAreNeverHeldForever(): void
    {
        $this->assertSame('delayed', RelDynCascade::deliverVia(self::item(), 0.0, []), 'no clock to wait on: the old behaviour');
        $legacy = ['id' => 'old', 'source' => 'Aela the Huntress', 'delta' => -5.0, 'kind' => 'ally_hurt', 'gamets' => 1.0];
        $this->assertSame('delayed', RelDynCascade::deliverVia($legacy, self::T0, []), 'a ripple queued before this version has no gamets to wait for');
    }

    public function testTheFeltLineSaysSeenForAWitnessAndHeardForTheRest(): void
    {
        $d = [RelDynCascade::FELT_KEY => [
            ['source' => 'Aela the Huntress', 'kind' => 'ally_hurt', 'reason' => 'The player mocked her hunt', 'gamets' => 1.0, 'via' => 'witnessed'],
            ['source' => 'Aela the Huntress', 'kind' => 'ally_hurt', 'reason' => null, 'gamets' => 2.0, 'via' => 'told'],
            ['source' => 'Aela the Huntress', 'kind' => 'ally_hurt', 'reason' => null, 'gamets' => 3.0],   // older entry
        ]];
        $out = RelDynCascade::takeFeltLines($d, 'Farkas', 'Kaida', true);
        $this->assertStringContainsString('Farkas has seen what Kaida did to Aela the Huntress', $out['lines'][0]['text']);
        $this->assertStringContainsString('Farkas has heard what Kaida did to Aela the Huntress', $out['lines'][1]['text']);
        $this->assertStringContainsString('has heard what', $out['lines'][2]['text']);
    }
}
