<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * The maturity gate and the dark path (decisions 2026-10-01 §24, roadmap maturity-gate-dark-path), the pure halves: no
 * database, fixed game timestamps. The draft's four corners are degrees, not switches: floors only while maturity is above 40
 * (as a curve), "I deserve better" for a mature NPC with little trust (the walkaway system, the pull-back), the codependent lean
 * for a low-maturity NPC with high trust, no floors and a transactional bond for low and low, and positive treatment lifting
 * maturity toward the floors. The four test beds through the real hooks are RelDynWDarkTestBedsPostgresTest.
 */
final class RelDynWDarkPathTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY + 12 * self::HOUR;

    private array $saved = [];
    private string $errorLog = '';
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
        RelDynBonds::$coreWriter = null;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdwdark');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        RelDynBonds::$coreWriter = null;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function at(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) round($gamets), 'Kaida: hello'];
    }

    private const SECURE = [0.15, 0.15];
    private const ANXIOUS = [0.85, 0.15];
    private const AVOIDANT = [0.15, 0.85];
    private const FEARFUL = [0.85, 0.85];

    /**
     * A partner: attachment axes, maturity, trust and the rest as given; core affinity 65 (a deep bond), a friend once (context
     * tier high-water mark 3), core type 'romantic' unless given.
     */
    private function npc(array $axes = self::SECURE, float $maturity = 75.0, float $trust = 25.0, array $o = []): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], (array) ($o['traits'] ?? []));
        $x['maturity_start'] = $maturity;
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src'] = ['assignment' => 'read'];
        $d['profile_overrides'] = ['attachment_axes' => ['anxiety' => $axes[0], 'avoidance' => $axes[1]]];
        $d['_aff_mirror_x'] = 50.0;
        RelationshipDynamics::setCoreAffinityValue($d, floatval($o['aff'] ?? 65.0));
        $dims = array_merge(['comfort' => 60.0, 'trust' => $trust, 'maturity' => $maturity, 'self_confidence' => 55.0, 'resentment' => 5.0, 'respect' => 60.0], (array) ($o['dims'] ?? []));
        foreach ($dims as $k => $v) $d['dimensions'][$k]['x'] = $v;
        RelationshipDynamics::setPassion($d, floatval($o['passion'] ?? 40.0));
        $d['_core_rel_type'] = (string) ($o['core'] ?? 'romantic');
        $d['context_tier_hwm'] = intval($o['hwm'] ?? 3);
        // the trust it fell from: a betrayed bond by default (a state with no peak has fallen from nothing; see the fresh-start test)
        if (!array_key_exists('peak', $o) || $o['peak'] !== null) {
            $d[RelDynDark::KEY] = ['v' => 1, 'deserve' => 0.0, 'adrift' => 0.0, 'walks' => 0, 'trust_peak' => floatval($o['peak'] ?? 90.0)];
        }
        return $d;
    }

    private function config(array $dark): void
    {
        $GLOBALS['db'] = new RelDynWDarkConfigDb(['dark_path' => $dark]);
        RelationshipDynamics::clearConfigCache();
    }

    /** The NPC's next turn at $t: what the exchanges earned is given. */
    private function settle(array &$d, float $t): void
    {
        $this->at($t);
        RelDynDark::advance('Rowan', $d, $t);
    }

    /** Run the dark path's calendar for $hours game hours in $step-hour steps from $from; returns the time reached. */
    private function drive(array &$d, float $from, float $hours, float $step = 6.0): float
    {
        $t = $from;
        $this->at($t);
        RelDynDark::advance('Rowan', $d, $t);
        for ($h = 0.0; $h < $hours; $h += $step) {
            $t += min($step, $hours - $h) * self::HOUR;
            $this->at($t);
            RelDynDark::advance('Rowan', $d, $t);
        }
        return $t;
    }

    // =====================================================================
    // the maturity gate is a curve, not a switch
    // =====================================================================

    public function testAFloorHasAStrengthThatGrowsWithMaturityAndNeverReachesEitherEnd(): void
    {
        $s = fn(float $m) => RelDynDark::floorStrength($this->npc(self::SECURE, $m));
        $prev = -1.0;
        foreach ([0.0, 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 80.0, 100.0] as $m) {
            $v = $s($m);
            $this->assertGreaterThan($prev, $v, "maturity {$m}: stronger than the one before");
            $this->assertGreaterThan(0.0, $v, "maturity {$m}: a floor is never exactly gone");
            $this->assertLessThan(1.0, $v, "maturity {$m}: nor ever quite whole");
            $prev = $v;
        }
        $this->assertEqualsWithDelta(0.5, $s(40.0), 1e-6, 'centred on the draft\'s 40');
        $this->assertLessThan(0.1, $s(28.0));
        $this->assertGreaterThan(0.9, $s(52.0));
    }

    public function testTheGateLineIsTheOldLineAtDefaultMaturityAndAboveAndRisesBelow(): void
    {
        $line = fn(float $m) => RelDynDark::gateRequired(RelDynDark::floorStrength($this->npc(self::SECURE, $m)));
        $this->assertSame(50.0, $line(50.0), 'maturity 50 (the default): the old line, exactly');
        $this->assertSame(50.0, $line(75.0));
        $this->assertSame(50.0, $line(100.0));
        $this->assertGreaterThan(50.0, $line(45.0), 'a little below the default: a little more is asked of the gate');
        $this->assertLessThan(60.0, $line(45.0));
        $this->assertGreaterThan($line(45.0), $line(38.0));
        $this->assertGreaterThan($line(38.0), $line(30.0));
        $this->assertGreaterThan(95.0, $line(22.0), 'barely a floor left');
        $this->assertLessThanOrEqual(100.0, $line(0.0));
    }

    private function tierSlip(array $d, string $gate, float $gateValue, float $floorMinus = 20.0): array
    {
        $floor = floatval(RelationshipDynamics::getTierFloor('friend'));
        RelationshipDynamics::setCoreAffinityValue($d, $floor - $floorMinus);
        $d['dimensions'][$gate]['x'] = $gateValue;
        return RelationshipDynamics::checkTierDemotion($d, 'Bold', 'friend', 'friend');
    }

    public function testTheTierFloorHoldsByHowMuchMaturityThereIsNotBySwitch(): void
    {
        // the friend floor's gate is comfort; the same comfort (60) at four maturities
        $at = fn(float $m, float $comfort) => $this->tierSlip($this->npc(self::SECURE, $m), 'comfort', $comfort);
        $this->assertFalse($at(75.0, 60.0)['should_demote'], 'mature: the floor holds on comfort 60');
        $this->assertSame('gate_holds', $at(75.0, 60.0)['reason']);
        $this->assertTrue($at(75.0, 40.0)['should_demote'], 'mature, comfort failed: demoted, as before');
        $this->assertFalse($at(45.0, 60.0)['should_demote'], 'just under the default: comfort 60 still holds');
        $this->assertTrue($at(35.0, 60.0)['should_demote'], 'immature: comfort 60 no longer holds the floor');
        $this->assertFalse($at(35.0, 95.0)['should_demote'], 'but a floor is not nothing: very high comfort still does');
        $r = $at(15.0, 100.0);
        $this->assertTrue($r['should_demote'], 'no floor at all: not even a full gate holds');
        $this->assertSame('immature_no_floor', $r['reason']);
        $this->assertSame('bypassed_low_maturity', $r['gate_status']);
        // the reported flag is the draft's: maturity above 40
        $this->assertTrue($at(41.0, 60.0)['floor_active']);
        $this->assertFalse($at(39.0, 60.0)['floor_active']);
    }

    public function testWithTheDarkPathOffTheGateIsTheOldSwitchAtForty(): void
    {
        $this->config(['enabled' => false]);
        $this->assertSame(0.0, RelDynDark::floorStrength($this->npc(self::SECURE, 38.0)));
        $this->assertSame(1.0, RelDynDark::floorStrength($this->npc(self::SECURE, 42.0)));
        $this->assertSame(50.0, RelDynDark::gateRequired(1.0));
        $at = fn(float $m, float $comfort) => $this->tierSlip($this->npc(self::SECURE, $m), 'comfort', $comfort);
        $this->assertTrue($at(38.0, 100.0)['should_demote']);
        $this->assertFalse($at(42.0, 55.0)['should_demote']);
        $this->assertSame(0.0, RelDynDark::deserveDegree($this->npc(self::SECURE, 90.0, 5.0)));
        $this->assertSame(0.0, RelDynDark::lean($this->npc(self::SECURE, 10.0, 95.0)));
        $d = $this->npc(self::SECURE, 90.0, 5.0);
        $this->assertSame(0.0, RelDynDark::advance('Rowan', $d, self::T0)['deserve']);
        $this->assertArrayNotHasKey(RelDynDark::KEY, $d, 'nothing stored while it is off');
    }

    // =====================================================================
    // the four corners
    // =====================================================================

    public function testTheFourCornersAreDegreesThatSumToOneAndEachHasItsPerson(): void
    {
        $corners = fn(array $d) => RelDynDark::corners($d);
        $healthy = $corners($this->npc(self::SECURE, 80.0, 70.0));
        $deserve = $corners($this->npc(self::SECURE, 80.0, 15.0));
        $codep = $corners($this->npc(self::ANXIOUS, 15.0, 80.0));
        $adrift = $corners($this->npc(self::FEARFUL, 15.0, 15.0));
        $this->assertSame(RelDynDark::HEALTHY, $healthy['dominant']);
        $this->assertSame(RelDynDark::DESERVE, $deserve['dominant']);
        $this->assertSame(RelDynDark::CODEPENDENT, $codep['dominant']);
        $this->assertSame(RelDynDark::ADRIFT, $adrift['dominant']);
        foreach ([$healthy, $deserve, $codep, $adrift] as $c) {
            $this->assertEqualsWithDelta(1.0, $c['healthy'] + $c['deserve'] + $c['codependent'] + $c['adrift'], 0.002);
            $this->assertGreaterThan(0.0, min($c['healthy'], $c['deserve'], $c['codependent'], $c['adrift']), 'a degree, not a switch: no corner is ever exactly zero');
        }
        // a middling NPC is a bit of each
        $mid = $corners($this->npc(self::SECURE, 40.0, 45.0));
        $this->assertGreaterThan(0.1, min($mid['healthy'], $mid['deserve'], $mid['codependent'], $mid['adrift']));
    }

    public function testLowTrustIsLowForThisNpcNotForEveryoneAGuardedOnesNormalIsNotADistrust(): void
    {
        $trusting = $this->npc(self::SECURE, 75.0, 40.0);
        $trusting['dimensions']['trust']['baseline'] = 50.0;
        $guarded = $this->npc(self::SECURE, 75.0, 40.0);
        $guarded['dimensions']['trust']['baseline'] = 25.0;
        $this->assertSame(50.0, RelDynDark::trustLine($trusting), 'a trusting NPC: the draft\'s 50');
        $this->assertSame(35.0, RelDynDark::trustLine($guarded), 'a guarded one: their baseline and a little');
        $this->assertGreaterThan(RelDynDark::trustSense($trusting), RelDynDark::trustSense($guarded), 'the same 40 is high for the guarded');
        $this->assertGreaterThan(RelDynDark::deserveDegree($guarded), RelDynDark::deserveDegree($trusting));
    }

    // =====================================================================
    // I deserve better
    // =====================================================================

    public function testTheDeserveBetterDegreeNeedsMaturityLowTrustAndABondWorthDeservingMoreOf(): void
    {
        $deg = fn(array $d) => RelDynDark::deserveDegree($d);
        $full = $deg($this->npc(self::SECURE, 80.0, 10.0));
        $this->assertGreaterThan(0.85, $full);
        $this->assertLessThan($full, $deg($this->npc(self::SECURE, 80.0, 10.0, ['aff' => 35.0])), 'a shallow bond: not much to deserve more of');
        $this->assertLessThan(0.05, $deg($this->npc(self::SECURE, 80.0, 10.0, ['aff' => 5.0])));
        $this->assertLessThan(0.05, $deg($this->npc(self::SECURE, 20.0, 10.0)), 'immature: that is the codependent / adrift side');
        $this->assertLessThan(0.05, $deg($this->npc(self::SECURE, 80.0, 90.0)), 'a trusted bond has nothing to leave over');
        $this->assertSame(0.0, $deg($this->npc(self::SECURE, 80.0, 10.0, ['core' => 'servant'])), 'sworn: duty, not standards');
        $this->assertLessThan($full, $deg($this->npc(self::SECURE, 80.0, 10.0, ['core' => 'platonic'])), 'a friendship expects less than a romance');
        $this->assertGreaterThan(0.0, $deg($this->npc(self::SECURE, 80.0, 10.0, ['core' => 'platonic'])));
    }

    public function testATrustThatNeverWasOrAStateThatBeginsFreshOverADeepBondIsNothingToLeaveOverButAFallIs(): void
    {
        // the start of a save: core's affinity is high, RelDyn's own state begins fresh (trust at the NPC's baseline). Nobody has been betrayed.
        $fresh = $this->npc(self::SECURE, 80.0, 25.0, ['peak' => null]);
        $this->assertSame(0.0, RelDynDark::trustFall($fresh), 'no peak yet');
        $this->assertSame(0.0, RelDynDark::deserveDegree($fresh));
        $t = $this->drive($fresh, self::T0, 24 * 6.0, 12.0);
        $this->assertSame(0.0, RelDynDark::deserveDegree($fresh), 'six days of it: still nothing to leave over');
        $this->assertEqualsWithDelta(25.0, $fresh[RelDynDark::KEY]['trust_peak'], 1e-9);
        $this->assertFalse(RelDynDark::walkawayDue($fresh));
        // trust built up (the peak follows), then fell: that is a fall
        $fresh['dimensions']['trust']['x'] = 75.0;
        $t = $this->drive($fresh, $t, 12.0, 12.0);
        $this->assertEqualsWithDelta(75.0, $fresh[RelDynDark::KEY]['trust_peak'], 1e-9);
        $fresh['dimensions']['trust']['x'] = 30.0;
        $this->assertEqualsWithDelta(45.0, RelDynDark::trustFall($fresh), 1e-9);
        $this->assertGreaterThan(0.8, RelDynDark::deserveDegree($fresh));
        $t = $this->drive($fresh, $t, 24 * 4.0, 12.0);
        $this->assertTrue(RelDynDark::walkawayDue($fresh), 'a mature NPC whose trust fell and stayed fallen');
        // a small dip is nothing; a fall begins to count from a few points
        $small = $this->npc(self::SECURE, 80.0, 40.0, ['peak' => 44.0]);
        $this->assertSame(0.0, RelDynDark::deserveDegree($small));
        $some = $this->npc(self::SECURE, 80.0, 30.0, ['peak' => 45.0]);
        $this->assertGreaterThan(0.1, RelDynDark::deserveDegree($some));
        $this->assertLessThan(RelDynDark::deserveDegree($this->npc(self::SECURE, 80.0, 30.0, ['peak' => 70.0])), RelDynDark::deserveDegree($some));
        // trust back at the NPC's line after the fall: a clean slate, and the next dip is measured from there
        $fresh['dimensions']['trust']['x'] = 60.0;
        $this->drive($fresh, $t, 12.0, 12.0);
        $this->assertEqualsWithDelta(60.0, $fresh[RelDynDark::KEY]['trust_peak'], 1e-9, 'forgiven: the old high is forgotten');
        $this->assertSame(0.0, RelDynDark::deserveDegree($fresh));
    }

    public function testThePressureBuildsOverAGameDayOrTwoAndDoesNotFlicker(): void
    {
        $d = $this->npc(self::SECURE, 80.0, 10.0);
        $t = $this->drive($d, self::T0, 0.0);
        $first = $d[RelDynDark::KEY]['deserve'];
        $this->assertEqualsWithDelta(RelDynDark::deserveDegree($d), $first, 1e-6, 'first sight: the state of the world is what it is');
        // from a calm start it builds
        $d = $this->npc(self::SECURE, 80.0, 90.0);
        $t = $this->drive($d, self::T0, 0.0);
        $d['dimensions']['trust']['x'] = 10.0;
        $this->at($t + 6 * self::HOUR);
        RelDynDark::advance('Rowan', $d, $t + 6 * self::HOUR);
        $six = $d[RelDynDark::KEY]['deserve'];
        RelDynDark::advance('Rowan', $d, $t + 24 * self::HOUR);
        $day = $d[RelDynDark::KEY]['deserve'];
        RelDynDark::advance('Rowan', $d, $t + 72 * self::HOUR);
        $three = $d[RelDynDark::KEY]['deserve'];
        $this->assertGreaterThan(0.1, $six);
        $this->assertGreaterThan($six, $day);
        $this->assertGreaterThan($day, $three);
        $this->assertLessThan(0.7, $day, 'not within a day');
        // it falls slowly once trust is back
        $d['dimensions']['trust']['x'] = 90.0;
        RelDynDark::advance('Rowan', $d, $t + 96 * self::HOUR);
        $this->assertGreaterThan(0.5 * $three, $d[RelDynDark::KEY]['deserve'], 'eased slowly: a conclusion reached is not dropped overnight');
    }

    public function testWhereTheNpcLeavesIsWhoTheyAreAndNeverAWall(): void
    {
        $at = fn(array $d) => RelDynDark::walkAt($d);
        $secure = $this->npc(self::SECURE, 80.0, 10.0);
        $this->assertEqualsWithDelta(0.85 - 0.05 * 0.15, $at($secure), 0.03, 'a plain mature NPC: the base line, a little under');
        $proud = $this->npc(self::SECURE, 80.0, 10.0, ['traits' => ['Pd' => 0.95]]);
        $this->assertLessThan($at($secure), $at($proud), 'pride expects better and leaves sooner');
        $avoidant = $this->npc(self::AVOIDANT, 80.0, 10.0);
        $this->assertLessThan($at($secure), $at($avoidant), 'avoidance leaves sooner');
        $clinging = $this->npc(self::FEARFUL, 80.0, 10.0);
        $clinging['_keeping'] = ['v' => 1, 'fear' => 1.0, 'applied' => ['trust' => 0.0, 'comfort' => 0.0]];
        $this->assertGreaterThan($at($avoidant), $at($clinging), 'the fear of losing the player holds the NPC');
        $this->assertLessThanOrEqual(0.98, $at($clinging), 'never a wall: the pressure can always reach it');
        $this->assertGreaterThanOrEqual(0.5, $at($proud));
    }

    public function testAMatureNpcWithLittleTrustLeavesThroughTheExistingWalkawayAndTheClingingOneLater(): void
    {
        $leaveAfter = function (array $d): ?float {
            $t = $this->drive($d, self::T0, 0.0);
            $d['dimensions']['trust']['x'] = 8.0;   // the trust fell
            $reached = null;
            for ($h = 6; $h <= 24 * 12; $h += 6) {
                $this->at($t + $h * self::HOUR);
                RelDynDark::advance('Rowan', $d, $t + $h * self::HOUR);
                if (RelDynDark::walkawayDue($d)) { $reached = $h / 24; break; }
            }
            return $reached;
        };
        $secure = $leaveAfter($this->npc(self::SECURE, 80.0, 80.0));
        $proud = $leaveAfter($this->npc(self::SECURE, 80.0, 80.0, ['traits' => ['Pd' => 0.95]]));
        $avoidant = $leaveAfter($this->npc(self::AVOIDANT, 80.0, 80.0));
        $fearfulD = $this->npc(self::FEARFUL, 80.0, 80.0);
        $fearfulD['_keeping'] = ['v' => 1, 'fear' => 1.0, 'applied' => ['trust' => 0.0, 'comfort' => 0.0]];
        $fearful = $leaveAfter($fearfulD);
        $this->assertNotNull($secure, 'a mature NPC whose trust fell does leave');
        $this->assertLessThan(5.0, $secure);
        $this->assertGreaterThan(0.5, $secure, 'not within the hour: it takes a day or two');
        $this->assertLessThanOrEqual($secure, $proud);
        $this->assertLessThanOrEqual($secure, $avoidant);
        $this->assertNotNull($fearful, 'even the one who fears losing the player leaves in the end: no one is immune');
        $this->assertGreaterThan($secure, $fearful, 'the fear of losing the player delays it');
        // immature: no such walk
        $this->assertNull($leaveAfter($this->npc(self::SECURE, 15.0, 80.0)));
        // a trusted partner: none
        $this->assertNull((function () { $d = $this->npc(self::SECURE, 80.0, 80.0); $this->drive($d, self::T0, 24 * 10.0); return RelDynDark::walkawayDue($d) ? 1.0 : null; })());
    }

    public function testTheWalkawayNeedsABondThatExisted(): void
    {
        $d = $this->npc(self::SECURE, 80.0, 8.0, ['hwm' => 1]);
        $d[RelDynDark::KEY] = ['v' => 1, 'deserve' => 1.0, 'adrift' => 0.0, 'walks' => 0];
        $this->assertFalse(RelDynDark::walkawayDue($d), 'never a friend: nothing to deserve more of');
        $d['context_tier_hwm'] = 2;
        $this->assertTrue(RelDynDark::walkawayDue($d));
    }

    public function testAutonomyAsksForTheWalkawayWithTheReasonDeserveAndTheExistingMachineryRunsIt(): void
    {
        $d = $this->npc(self::SECURE, 80.0, 8.0);
        $d[RelDynDark::KEY] = ['v' => 1, 'deserve' => 0.95, 'adrift' => 0.0, 'walks' => 0];
        $eval = RelationshipDynamics::evaluateAutonomyState($d, 'Stoic');
        $this->assertSame('walkaway', $eval['state']);
        $this->assertTrue($eval['walkaway_due']);
        $this->assertSame('deserve', RelationshipDynamics::walkawayReason($d));
        $this->at(self::T0);
        RelDynBonds::$coreWriter = fn() => true;   // the romance it walks out of ends through the fork; the write is not the point here
        RelationshipDynamics::initiateWalkaway($d, 'Rowan', 'deserve');
        $this->assertSame('pending', $d['_walkaway_state'], 'the walkaway state machine, not a second one');
        $this->assertSame('deserve', $d['_walkaway_reason']);
        $this->assertEqualsWithDelta(0.95 * 0.6, $d[RelDynDark::KEY]['deserve'], 1e-6, 'the NPC acted on it: it keeps a share');
        $this->assertSame(1, $d[RelDynDark::KEY]['walks']);
        // what the LLM hears: calm, the NPC named, no pronoun, no number
        $text = RelationshipDynamics::getAutonomyContext($d, 'Rowan', 'Stoic');
        $this->assertStringContainsString('deserves better', $text);
        $this->assertStringContainsString('Rowan', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|him)\b/i', $text);
        // a trusted partner at the same moment: nothing
        $ok = $this->npc(self::SECURE, 80.0, 80.0);
        $this->assertNotSame('walkaway', RelationshipDynamics::evaluateAutonomyState($ok, 'Stoic')['state']);
    }

    public function testTheSameLowTrustPullsAMatureNpcBackBeforeTheyLeave(): void
    {
        $quiet = $this->npc(self::SECURE, 80.0, 90.0);
        $stand = $this->npc(self::SECURE, 80.0, 25.0, ['dims' => ['comfort' => 90.0]]);   // let in (comfort and trust), trust below this NPC's line
        $stand[RelDynDark::KEY] = ['v' => 1, 'deserve' => 0.7, 'adrift' => 0.0, 'walks' => 0];
        $in = RelDynPullback::inputs($stand, self::T0);
        $this->assertEqualsWithDelta(0.7, $in['standards'], 1e-6);
        $this->assertSame(0.0, RelDynPullback::inputs($quiet, self::T0)['standards']);
        $moodGain = RelDynPullback::moodGain(1.0);
        $with = RelDynPullback::target($in, $moodGain);
        $without = RelDynPullback::target(array_replace($in, ['standards' => 0.0]), $moodGain);
        $this->assertGreaterThan($without + 0.5, $with, 'the thought of deserving better presses the NPC to close off');
        // advancing the pull-back with it: a mature NPC enters it, says what it is about, in their words
        $d = $stand;
        $t = self::T0;
        $out = null;
        for ($i = 0; $i < 6; $i++) {
            $this->at($t);
            $out = RelDynPullback::advance('Rowan', $d, $t);
            $t += 6 * self::HOUR;
        }
        $this->assertTrue($out['active'], 'pulling back on standards alone');
        $this->assertSame('standards', RelDynPullback::cause($d));
        $text = RelDynPullback::standingText('Rowan', 'Kaida', $d);
        $this->assertStringContainsString('trust', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|him)\b/i', $text);
        $lines = RelDynPullback::takeFeltLines($d, 'Rowan', 'Kaida', $t)['lines'];
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('trust', $lines[0]['text']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $lines[0]['text']);
    }

    // =====================================================================
    // codependent
    // =====================================================================

    public function testTheCodependentLeanLetsNegativeTrustLandSofterNeverToNothing(): void
    {
        $codep = $this->npc(self::ANXIOUS, 15.0, 85.0);
        $healthy = $this->npc(self::SECURE, 80.0, 85.0);
        $adrift = $this->npc(self::FEARFUL, 15.0, 10.0);
        $this->assertSame(-5.0, RelDynDark::trustSignal($healthy, -5.0), 'a mature, trusting NPC: the hurt lands as it is');
        $this->assertSame(-5.0, RelDynDark::trustSignal($adrift, -5.0), 'no trust to hold on to');
        $soft = RelDynDark::trustSignal($codep, -5.0);
        $this->assertGreaterThan(-5.0, $soft);
        $this->assertLessThan(-1.4, $soft, 'never below the floor of 30 percent: they can still be hurt');
        $this->assertSame(3.0, RelDynDark::trustSignal($codep, 3.0), 'a positive signal is not touched');
        // continuous: a middling NPC lands somewhere in between
        $mid = $this->npc(self::ANXIOUS, 38.0, 56.0);
        $midLands = RelDynDark::trustSignal($mid, -5.0);
        $this->assertGreaterThan(-5.0, $midLands, 'a little codependent: a little softer');
        $this->assertLessThan($soft, $midLands, 'the more codependent, the softer');
    }

    public function testTheCodependentEvalItemIsDampedThroughTheRealConsumer(): void
    {
        $this->at(self::T0);
        $lands = function (array $d): float {
            $item = ['v' => 1, 'npc' => 'Rowan', 'npc_id' => 7, 'gamets' => (int) self::T0, 'source' => 'reldyn_eval',
                'signals' => ['affinity' => 0, 'trust' => -10, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => ['broken_promise'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0], 'significance' => 0.5, 'positive_interaction' => false,
                'summary' => 'The player broke a promise.'];
            $before = $d['dimensions']['trust']['x'];
            RelationshipDynamics::processEvalContractItem('Rowan', $item, $d);
            return $d['dimensions']['trust']['x'] - $before;
        };
        $hurt = $lands($this->npc(self::SECURE, 80.0, 85.0));
        $codepHurt = $lands($this->npc(self::ANXIOUS, 15.0, 85.0));
        $this->assertLessThan(0.0, $codepHurt, 'still hurt');
        $this->assertGreaterThan($hurt, $codepHurt, 'but less than a healthy NPC');
    }

    public function testTheCodependentNpcsAutonomyLinesLiftAndTheForcedTriggersDoNot(): void
    {
        $bad = ['resentment' => 40.0, 'respect' => 30.0];
        $codep = $this->npc(self::ANXIOUS, 15.0, 85.0, ['dims' => $bad, 'traits' => []]);
        $codep['dimensions']['self_confidence']['x'] = 45.0;   // not a people-pleaser (confidence over 30)
        $firm = $this->npc(self::ANXIOUS, 55.0, 85.0, ['dims' => $bad]);
        $firm['dimensions']['self_confidence']['x'] = 45.0;
        $this->assertGreaterThan(5.0, RelDynDark::autonomyLift($codep));
        $this->assertSame(0.0, RelDynDark::autonomyLift($firm));
        $order = ['compliant' => 0, 'resistant' => 1, 'refusing' => 2, 'walkaway' => 3];
        $a = RelationshipDynamics::evaluateAutonomyState($codep, 'Stoic');
        $b = RelationshipDynamics::evaluateAutonomyState($firm, 'Stoic');
        $this->assertLessThanOrEqual($order[$b['state']], $order[$a['state']] + 0, 'the lean never makes the NPC refuse sooner');
        // resentment at the walkaway line still sends anyone away: no one is immune
        $codep['dimensions']['resentment']['x'] = 95.0;
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($codep, 'Stoic')['state']);
    }

    // =====================================================================
    // adrift: no floors, the parasite overlay, and the way out
    // =====================================================================

    public function testNoFloorsAndNoTrustWithIntensitySettlesIntoTheTransactionalBondAndHoldsIt(): void
    {
        $d = $this->npc(self::FEARFUL, 15.0, 10.0, ['passion' => 70.0, 'aff' => 20.0]);
        $this->assertNull($d['_relationship_type_override'] ?? null);
        $t = $this->drive($d, self::T0, 24 * 5.0, 12.0);
        $this->assertSame('parasite', $d['_relationship_type_override'] ?? null, 'the existing overlay, set by this path');
        $this->assertTrue(RelDynDark::holdsParasite($d));
        $this->assertSame('parasite', RelationshipDynamics::getRelationshipType('Rowan', $d));
        $history = end($d['_relationship_type_history']);
        $this->assertStringStartsWith('dark_path', (string) $history['reason']);
        // the gift ledger's recovery does not lift it
        $d['_interaction_pattern'] = ['total_window' => 20, 'gift_count' => 1, 'genuine_count' => 19];
        $this->assertNull(RelationshipDynamics::checkParasiteRecovery('Rowan', $d), 'only its own pressure lifts it');
        $this->assertSame('parasite', $d['_relationship_type_override']);
        // its words are its own, not the gift ledger's
        $lines = array_column(RelDynDark::feltLines($d, 'Rowan', 'Kaida'), 'text', 'key');
        $this->assertArrayHasKey('parasite', $lines);
        $this->assertStringContainsString('Rowan', $lines['parasite']);
        $this->assertDoesNotMatchRegularExpression('/\d|\b(he|she|his|her|him)\b/i', $lines['parasite']);
    }

    public function testNoIntensityNoParasiteAndATrustedOrMatureNpcNeverDriftsInto()
    {
        $calm = $this->npc(self::FEARFUL, 15.0, 10.0, ['passion' => 2.0, 'aff' => 20.0]);
        $this->drive($calm, self::T0, 24 * 6.0, 12.0);
        $this->assertNull($calm['_relationship_type_override'] ?? null, 'no passion to burn: a stranger-ish bond is not a parasite');
        $mature = $this->npc(self::FEARFUL, 85.0, 10.0, ['passion' => 70.0]);
        $this->drive($mature, self::T0, 24 * 6.0, 12.0);
        $this->assertNull($mature['_relationship_type_override'] ?? null, 'a mature NPC with no trust walks; they do not drift');
        $trusting = $this->npc(self::FEARFUL, 15.0, 90.0, ['passion' => 70.0]);
        $this->drive($trusting, self::T0, 24 * 6.0, 12.0);
        $this->assertNull($trusting['_relationship_type_override'] ?? null, 'trust is the other corner');
    }

    public function testBeingTreatedWellLiftsMaturityUntilTheFloorsComeOnAndTheOverlayLifts(): void
    {
        $d = $this->npc(self::SECURE, 15.0, 10.0, ['passion' => 70.0, 'aff' => 20.0]);
        $t = $this->drive($d, self::T0, 24 * 5.0, 12.0);
        $this->assertTrue(RelDynDark::holdsParasite($d));
        $m0 = $d['dimensions']['maturity']['x'];
        $kind = fn(float $sig) => ['npc' => 'Rowan', 'gamets' => (int) $t, 'positive_interaction' => true, 'significance' => $sig, 'tags' => ['quality_time'], 'signals' => []];
        $gained = RelDynDark::onEvalItem('Rowan', $kind(0.8), $d, $t);
        $this->assertGreaterThan(0.0, $gained, 'treated well, the NPC earns growth');
        $this->assertEqualsWithDelta($m0, $d['dimensions']['maturity']['x'], 1e-9, 'the evaluation does not move maturity by itself');
        $this->settle($d, $t + self::HOUR);
        $this->assertGreaterThan($m0, $d['dimensions']['maturity']['x'], 'and the NPC\'s next turn gives it');
        // a daily cap: farming does not buy a character
        for ($i = 0; $i < 20; $i++) RelDynDark::onEvalItem('Rowan', $kind(1.0), $d, $t);
        $this->settle($d, $t + 2 * self::HOUR);
        $this->assertLessThanOrEqual(1.2 + 1e-6, $d[RelDynDark::KEY]['growth']['pts'], 'at most 1.2 points asked a game day');
        $this->assertLessThan(5.0, $d['dimensions']['maturity']['x'] - $m0, 'a day of it is a few points, not a character');
        // an insignificant or unkind exchange grows nothing
        $this->assertSame(0.0, RelDynDark::onEvalItem('Rowan', ['positive_interaction' => false, 'significance' => 0.9], $d, $t + self::DAY));
        $this->assertSame(0.0, RelDynDark::onEvalItem('Rowan', ['positive_interaction' => true, 'significance' => 0.1], $d, $t + self::DAY));
        // days of it: maturity reaches a floor, trust rebuilds, and the overlay lifts by its own pressure
        for ($day = 1; $day <= 30; $day++) {
            for ($i = 0; $i < 4; $i++) RelDynDark::onEvalItem('Rowan', $kind(1.0), $d, $t + $day * self::DAY);
            $this->settle($d, $t + $day * self::DAY + self::HOUR);
        }
        $this->assertGreaterThan(40.0, $d['dimensions']['maturity']['x'], 'the floors switch on');
        $d['dimensions']['trust']['x'] = 70.0;
        $t = $this->drive($d, $t + 41 * self::DAY, 24 * 6.0, 12.0);
        $this->assertFalse(RelDynDark::holdsParasite($d), 'positive treatment is the way out');
        $this->assertNull($d['_relationship_type_override'] ?? null);
    }

    public function testTheFearfulRegionsMaturityFloorHoldsTheDarkPathInPlaceWithoutOutsideHelp(): void
    {
        // the existing protocol (MDD 15.4: a toxic / disorganized NPC cannot grow past 30 without an intervention): treated well, a
        // fearful NPC still stays adrift. Warmth alone is not the therapy quest.
        $d = $this->npc(self::FEARFUL, 15.0, 10.0, ['passion' => 70.0, 'aff' => 20.0]);
        $t = $this->drive($d, self::T0, 24 * 5.0, 12.0);
        $this->assertTrue(RelDynDark::holdsParasite($d));
        $kind = ['npc' => 'Rowan', 'gamets' => (int) $t, 'positive_interaction' => true, 'significance' => 1.0, 'tags' => [], 'signals' => []];
        for ($day = 1; $day <= 40; $day++) {
            for ($i = 0; $i < 4; $i++) RelDynDark::onEvalItem('Rowan', $kind, $d, $t + $day * self::DAY);
            $this->settle($d, $t + $day * self::DAY + self::HOUR);
        }
        $this->assertLessThan(40.0, $d['dimensions']['maturity']['x'], 'the floor of 30 holds: no amount of daily warmth takes them across');
        $this->assertGreaterThanOrEqual(29.0, $d['dimensions']['maturity']['x']);
        $this->assertTrue(RelDynDark::holdsParasite($d));
    }

    public function testGrowthGoesThroughThePlasticityOfWhoTheNpcIsAndFadesAsFloorsComeOn(): void
    {
        $t = self::T0;
        $item = ['npc' => 'Rowan', 'gamets' => (int) $t, 'positive_interaction' => true, 'significance' => 1.0, 'tags' => [], 'signals' => []];
        $low = $this->npc(self::SECURE, 20.0, 60.0);
        $mid = $this->npc(self::SECURE, 42.0, 60.0);
        $high = $this->npc(self::SECURE, 80.0, 60.0);
        $a = RelDynDark::onEvalItem('Rowan', $item, $low, $t);
        $b = RelDynDark::onEvalItem('Rowan', $item, $mid, $t);
        $c = RelDynDark::onEvalItem('Rowan', $item, $high, $t);
        $this->assertGreaterThan($b, $a, 'the least mature grow most');
        $this->assertGreaterThan($c, $b);
        $this->assertLessThan(0.05, $c, 'a mature NPC has nothing to grow toward here');
        $this->config(['growth' => ['enabled' => false]]);
        $this->assertSame(0.0, RelDynDark::onEvalItem('Rowan', $item, $low, $t + self::DAY));
    }

    // =====================================================================
    // words and numbers
    // =====================================================================

    public function testTheStandingLinesAreFeelingsWithNamesAndNoNumbersOrPronouns(): void
    {
        $d = $this->npc(self::SECURE, 80.0, 8.0);
        $d[RelDynDark::KEY] = ['v' => 1, 'deserve' => 0.5, 'adrift' => 0.0, 'walks' => 0];
        $building = array_column(RelDynDark::feltLines($d, 'Rowan', 'Kaida'), null, 'key');
        $this->assertArrayHasKey('deserve_building', $building);
        $d[RelDynDark::KEY]['deserve'] = 0.8;
        $firm = array_column(RelDynDark::feltLines($d, 'Rowan', 'Kaida'), null, 'key');
        $this->assertArrayHasKey('deserve_firm', $firm);
        $this->assertGreaterThan($building['deserve_building']['salience'], $firm['deserve_firm']['salience']);
        $codep = $this->npc(self::ANXIOUS, 15.0, 85.0);
        $all = array_merge($building, $firm, array_column(RelDynDark::feltLines($codep, 'Rowan', 'Kaida'), null, 'key'));
        $this->assertArrayHasKey('codependent', $all);
        foreach ($all as $key => $l) {
            $this->assertStringContainsString('Rowan', $l['text'], $key);
            $this->assertDoesNotMatchRegularExpression('/\d/', $l['text'], "{$key}: feelings, never numbers");
            $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him|himself|herself)\b/i', $l['text'], "{$key}: no hard-coded pronoun");
            $this->assertDoesNotMatchRegularExpression('/\b(abus\w*|toxic|manipulat\w*|deserv\w+ it)\b/i', $l['text'], "{$key}: no verdict on the player");
        }
        // not from a stranger's tier; not once the walkaway is under way (its own words say it)
        $this->assertSame([], RelDynDark::feltLines($d, 'Rowan', 'Kaida', 0));
        $d['_walkaway_state'] = 'active';
        $this->assertArrayNotHasKey('deserve_firm', array_column(RelDynDark::feltLines($d, 'Rowan', 'Kaida'), null, 'key'));
    }

    public function testJevCarriesTheCornersAndThePressure(): void
    {
        $d = $this->npc(self::SECURE, 80.0, 8.0);
        $this->drive($d, self::T0, 48.0);
        $j = RelDynDark::jev($d);
        $this->assertTrue($j['enabled']);
        $this->assertSame('deserve', $j['dominant']);
        $this->assertEqualsWithDelta(1.0, array_sum($j['corners']), 0.002);
        $this->assertSame(50.0, $j['gate_required']);
        $this->assertGreaterThan(0.5, $j['deserve']);
        $this->assertGreaterThan(0.5, $j['walk_at']);
        $this->assertFalse($j['parasite_held']);
        $this->assertSame(0, $j['walks']);
    }

    public function testTheSettingsMergePerEntryAndTheDefaultsAreWhatTheDraftSays(): void
    {
        $c = RelDynDark::config();
        $this->assertSame(RelationshipDynamics::MATURITY_FLOOR_THRESHOLD, (int) $c['maturity']['centre'], 'the draft\'s 40');
        $this->assertSame(RelationshipDynamics::TIER_GATE_THRESHOLD, (int) $c['trust']['gate_line'], 'the floors\' gate line');
        $this->config(['maturity' => ['centre' => 30.0], 'deserve' => ['walk_at' => 0.7, 'types' => ['friend' => 0.1]]]);
        $c = RelDynDark::config();
        $this->assertEqualsWithDelta(30.0, $c['maturity']['centre'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $c['maturity']['width'], 1e-9, 'the rest of the table stays');
        $this->assertEqualsWithDelta(0.7, $c['deserve']['walk_at'], 1e-9);
        $this->assertEqualsWithDelta(0.1, $c['deserve']['types']['friend'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $c['deserve']['types']['bonded'], 1e-9, 'a nested table merges per entry');
        $this->assertEqualsWithDelta(0.04, $c['deserve']['rates']['rise_per_game_hour'], 1e-9);
        $this->assertEqualsWithDelta(0.5, RelDynDark::floorStrength($this->npc(self::SECURE, 30.0)), 1e-6);
    }
}

/** Just enough of `sql` for a stored RelDyn config row (the stored settings over the defaults). */
final class RelDynWDarkConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode(array_merge(RelationshipDynamics::defaultConfig(), $this->config))] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}
