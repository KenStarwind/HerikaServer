<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Fixes from the batch W review (the pure halves, no database): "I deserve better" is reachable by every kind of bond that has
 * standards (nobody who is mature, deeply bonded and betrayed is immune, friend or partner; Ken §24), the circle's ledgers merge
 * as sums when two requests of the NPC overlap, the duty channel's sworn role follows the oath that makes an NPC sworn, and a
 * walkaway that ends a romance on standards leaves the way back to the friends it forked to.
 */
final class RelDynWReviewFixesTest extends TestCase
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdwrev');
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

    /** A mature, secure NPC whose trust fell hard from where it stood, deep in a bond of the given core type. */
    private function npc(string $core, float $maturity = 95.0, float $trust = 0.0, float $peak = 60.0, float $aff = 90.0): array
    {
        $x = ['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5, 'maturity_start' => $maturity];
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src'] = ['assignment' => 'read'];
        $d['profile_overrides'] = ['attachment_axes' => ['anxiety' => 0.15, 'avoidance' => 0.15]];
        $d['_aff_mirror_x'] = 50.0;
        RelationshipDynamics::setCoreAffinityValue($d, $aff);
        foreach (['comfort' => 60.0, 'trust' => $trust, 'maturity' => $maturity, 'self_confidence' => 55.0, 'resentment' => 5.0, 'respect' => 60.0] as $k => $v) $d['dimensions'][$k]['x'] = $v;
        RelationshipDynamics::setPassion($d, 40.0);
        $d['_core_rel_type'] = $core;
        $d['context_tier_hwm'] = 3;
        $d[RelDynDark::KEY] = ['v' => 1, 'deserve' => 0.0, 'adrift' => 0.0, 'walks' => 0, 'trust_peak' => $peak];
        return $d;
    }

    // =====================================================================
    // I deserve better: no kind of bond with standards is immune
    // =====================================================================

    public function testAMatureFriendDeeplyBetrayedCanLeaveOnStandardsLikeAnyoneElse(): void
    {
        $cases = [
            'friend' => $this->npc('platonic'),
            'mentor' => $this->npc('mentor'),
            'other' => $this->npc('professional'),
            'friendzone' => ($this->npc('platonic') + ['_attraction_friendzoned' => true]),
            'bonded' => $this->npc('romantic'),
        ];
        $degree = [];
        foreach ($cases as $kind => $d) {
            $degree[$kind] = RelDynDark::deserveDegree($d);
            $line = RelDynDark::walkAt($d);
            $this->assertGreaterThan($line, $degree[$kind], "{$kind}: the most that bond can feel is past the line at which they leave");
            $d[RelDynDark::KEY]['deserve'] = $degree[$kind];   // the pressure, converged
            $this->assertTrue(RelDynDark::walkawayDue($d), "{$kind}: at the converged pressure the walkaway is due");
        }
        // what the kind of bond expects still matters: the same betrayal is felt less where less was expected
        $this->assertLessThan($degree['bonded'], $degree['friend']);
        $this->assertLessThan($degree['friend'], $degree['mentor']);
    }

    public function testAFriendWhoseTrustMerelyDippedOrWhoseBondIsShallowStillDoesNotLeave(): void
    {
        $dip = $this->npc('platonic', 95.0, 35.0, 45.0);
        $this->assertLessThan(0.3, RelDynDark::deserveDegree($dip), 'a small dip is nothing to leave over');
        $shallow = $this->npc('platonic', 95.0, 0.0, 60.0, 8.0);
        $this->assertLessThan(0.05, RelDynDark::deserveDegree($shallow), 'a shallow bond has nothing to deserve more of');
        $young = $this->npc('platonic', 20.0, 0.0, 60.0, 90.0);
        $this->assertLessThan(0.1, RelDynDark::deserveDegree($young), 'an immature one is on the other side of the draft');
    }

    public function testAFriendLeavesOnStandardsOverTheCalendarAndNoSoonerThanAPartnerWouldDo(): void
    {
        $friend = $this->npc('platonic');
        $partner = $this->npc('romantic');
        $days = ['friend' => null, 'partner' => null];
        $this->at(self::T0);
        RelDynDark::advance('Rowan', $friend, self::T0);
        RelDynDark::advance('Rowan', $partner, self::T0);
        $friend[RelDynDark::KEY]['deserve'] = 0.0;
        $partner[RelDynDark::KEY]['deserve'] = 0.0;
        for ($h = 6; $h <= 24 * 12; $h += 6) {
            $this->at(self::T0 + $h * self::HOUR);
            RelDynDark::advance('Rowan', $friend, self::T0 + $h * self::HOUR);
            RelDynDark::advance('Rowan', $partner, self::T0 + $h * self::HOUR);
            if ($days['friend'] === null && RelDynDark::walkawayDue($friend)) $days['friend'] = $h / 24;
            if ($days['partner'] === null && RelDynDark::walkawayDue($partner)) $days['partner'] = $h / 24;
        }
        $this->assertNotNull($days['friend'], 'the friend does leave');
        $this->assertNotNull($days['partner']);
        $this->assertGreaterThanOrEqual($days['partner'], $days['friend'], 'but the bond that expects less takes at least as long');
    }

    public function testKindsOfBondWithNoStandardsAtAllStayWithoutThem(): void
    {
        foreach (['servant' => 'sworn', 'transactional' => 'mercenary', 'rival' => 'rival', 'enemy' => 'hostile'] as $core => $type) {
            $d = $this->npc($core);
            $this->assertSame(0.0, RelDynDark::deserveDegree($d), "{$type}: duty, a deal or a feud is not a bond measured by standards");
        }
    }

    // =====================================================================
    // duty: the sworn role is the oath the bonds lane keeps
    // =====================================================================

    public function testAnOathBoundNpcIsBoundForDutyWhateverCoreCallsTheBond(): void
    {
        // core's own oath types (fanatical, servant): the bonds lane's oath is in force, and so is the duty
        foreach (['servant', 'fanatical'] as $core) {
            $d = ['_core_rel_type' => $core, 'inferred_temperament' => 'Stoic'];
            $this->assertTrue(RelDynBonds::oathActive($d), $core);
            $role = RelDynDuty::roleFor('Lydia', $d);
            $this->assertSame('sworn', $role['role'], "{$core}: sworn for duty");
            $this->assertSame(1.0, $role['strength']);
            $this->assertSame('oath', $role['source']);
        }
        // the oath the editor sets, on an NPC whose core type carries nothing of it
        $d = ['_core_rel_type' => 'friend', 'inferred_temperament' => 'Stoic'];
        $this->assertNull(RelDynDuty::roleFor('Lydia', $d), 'no oath, no party: not bound');
        RelDynBonds::setOath($d, true, self::T0);
        $this->assertSame('sworn', RelDynDuty::roleFor('Lydia', $d)['role']);
        // a hand-set duty role still outranks the derivation, and none never binds
        $d[RelDynDuty::ROLE_OVERRIDE_KEY] = 'none';
        $this->assertNull(RelDynDuty::roleFor('Lydia', $d));
        unset($d[RelDynDuty::ROLE_OVERRIDE_KEY]);
        // the oath breaks: the NPC is forsworn, and no longer bound by it
        $d[RelDynBonds::KEY]['oath']['broken'] = true;
        $this->assertFalse(RelDynBonds::oathActive($d));
        $this->assertNull(RelDynDuty::roleFor('Lydia', $d), 'a broken oath binds no one');
        // and it mends
        $d[RelDynBonds::KEY]['oath']['broken'] = false;
        $this->assertSame('sworn', RelDynDuty::roleFor('Lydia', $d)['role']);
    }

    public function testAnOathBoundNpcEarnsDutyByServingSwornOrNot(): void
    {
        $d = ['_core_rel_type' => 'servant', 'inferred_temperament' => 'Stoic', 'dimensions' => ['affinity' => ['x' => 50.0, 'baseline' => null]], '_aff_mirror_x' => 50.0];
        RelDynDuty::advance('Lydia', $d, self::T0);
        $r = RelDynDuty::advance('Lydia', $d, self::T0 + 3 * self::HOUR);
        $this->assertGreaterThan(0.0, $r['service'], 'serving under an oath earns duty');
        $this->assertSame('sworn', $d[RelDynDuty::KEY]['role']);
        $this->assertTrue($d[RelDynDuty::KEY]['bound']);
    }

    // =====================================================================
    // the breakup fork: when the walkaway ends the romance (disclosed)
    // =====================================================================

    public function testAWalkawayOnStandardsOrAGoneFeelingEndsTheRomanceWhenTheNpcLeavesAndTheBoundaryTestDoesNotRestoreIt(): void
    {
        foreach (['deserve', 'affinity'] as $reason) {
            $writes = [];
            RelDynBonds::$coreWriter = function (string $npc, string $to, string $why, ?string $from) use (&$writes): bool {
                $writes[] = [$npc, $to, $from];
                return true;
            };
            $d = $this->npc('romantic', 80.0, 45.0, 60.0, 65.0);
            $this->at(self::T0);
            $this->assertSame('romantic', $d['_core_rel_type']);
            RelationshipDynamics::initiateWalkaway($d, 'Rowan', $reason);
            $this->assertSame('pending', $d['_walkaway_state'], $reason);
            $this->assertCount(1, $writes, "{$reason}: the ending is written as the NPC leaves, not only if the leaving turns permanent");
            $this->assertSame('romantic', $writes[0][2]);
            $this->assertContains($writes[0][1], ['ex', 'platonic'], 'core only ever sees a type it knows: ex for a hard or conflicted ending, platonic for friends');
            $this->assertSame($writes[0][1], $d['_core_rel_type']);
            // the boundary test resolves with the NPC back (the player left them alone): the walkaway clears, the ending does not
            $d['_walkaway_state'] = 'normal';
            $this->assertSame($writes[0][1], $d['_core_rel_type'], "{$reason}: a walkaway that resolves does not restore the romance");
            $this->assertNotSame('romantic', $d['_core_rel_type']);
        }
        RelDynBonds::$coreWriter = null;
        // the other reasons end nothing: the boundary test may still undo them
        foreach (['jealousy', 'resentment', 'autonomy', 'neglect'] as $reason) {
            $writes = [];
            RelDynBonds::$coreWriter = function (string $npc, string $to, string $why, ?string $from) use (&$writes): bool { $writes[] = $to; return true; };
            $d = $this->npc('romantic', 80.0, 45.0, 60.0, 65.0);
            RelationshipDynamics::initiateWalkaway($d, 'Rowan', $reason);
            $this->assertSame([], $writes, "{$reason}: the romance stands until the leaving is permanent");
            $this->assertSame('romantic', $d['_core_rel_type']);
        }
        RelDynBonds::$coreWriter = null;
    }

    public function testTheChangelogDisclosesWhatTheWalkawayDoesToARomance(): void
    {
        $log = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/CHANGELOG.md');
        $this->assertStringContainsString('when the NPC leaves', $log, 'the walkaway on standards or a gone feeling ends the romance at the leaving');
        $this->assertStringContainsString('feeling has gone', $log);
        $this->assertStringContainsString('does not bring the romance back', $log);
    }

    // =====================================================================
    // the circle's ledgers merge as sums
    // =====================================================================

    public function testTheCirclesLedgersAreSumsWhenTwoRequestsClaimTheSameStepAndOtherCircleNumbersAreNot(): void
    {
        $base = ['passion' => 0.0];
        $mine = $base + ['_circle' => ['assoc' => ['applied' => 0.5, 'target' => 1.0], 'rivals' => ['Lynly' => ['written' => -9.0, 'applied' => -9.4]]]];
        $theirs = $base + ['_circle' => ['assoc' => ['applied' => 0.5, 'target' => 1.0], 'rivals' => ['Lynly' => ['written' => -9.0, 'applied' => -9.2]]]];
        $m = RelationshipDynamics::mergeDynamics($base, $mine, $theirs);
        $this->assertEqualsWithDelta(1.0, $m['_circle']['assoc']['applied'], 1e-9, 'two first claims of the reading are two');
        $this->assertEqualsWithDelta(-18.0, $m['_circle']['rivals']['Lynly']['written'], 1e-9, 'and so are two first claims of the cooling (signed, unclamped)');
        $this->assertEqualsWithDelta(-9.4, $m['_circle']['rivals']['Lynly']['applied'], 1e-9, "the settled value is this copy's own");
        $this->assertEqualsWithDelta(1.0, $m['_circle']['assoc']['target'], 1e-9);
        // with a ledger already in the base the sum is the other's plus this copy's change
        $base2 = ['_circle' => ['assoc' => ['applied' => 2.0], 'rivals' => ['Lynly' => ['written' => -9.0]]]];
        $mine2 = ['_circle' => ['assoc' => ['applied' => 2.5], 'rivals' => ['Lynly' => ['written' => -11.0]]]];
        $theirs2 = ['_circle' => ['assoc' => ['applied' => 2.25], 'rivals' => ['Lynly' => ['written' => -10.0]]]];
        $m2 = RelationshipDynamics::mergeDynamics($base2, $mine2, $theirs2);
        $this->assertEqualsWithDelta(2.75, $m2['_circle']['assoc']['applied'], 1e-9);
        $this->assertEqualsWithDelta(-12.0, $m2['_circle']['rivals']['Lynly']['written'], 1e-9);
        // nobody else changed it: this copy's value stands (a sequential request is not doubled)
        $m3 = RelationshipDynamics::mergeDynamics($base2, $mine2, $base2);
        $this->assertEqualsWithDelta(2.5, $m3['_circle']['assoc']['applied'], 1e-9);
        $this->assertEqualsWithDelta(-11.0, $m3['_circle']['rivals']['Lynly']['written'], 1e-9);
    }
}
