<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Roadmap affinity-rot (MDD 6.5 "prolonged low Passion or unresolved Conflict -> permanent
 * Affinity bleeds"; pipeline MDD 6.5: -1 per day after 7+ days with no positive interaction, the
 * stage can regress below its floor). Through the real calendar step (advanceCalendar), which
 * runs RelDynAbsence::rotStep; time never heals it, a positive interaction restarts the clock.
 * No database: defaults.
 */
final class RelDynAffinityRotTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const T0 = 400 * self::DAY;

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

    private function at(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) round($gamets), 'Kaida: hello'];
    }

    /** A bond at T0 (contact now): core type, core affinity (-100..100), passion points, maturity 0..100. */
    private function bond(string $coreType, float $aff, float $passion, float $maturity = 50.0, string $attachment = 'secure', string $temperament = 'Romantic'): array
    {
        $this->at(self::T0);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
            'profile_overrides' => ['attachment_style' => $attachment],
            'traits' => [],
            'context_tier_hwm' => 3,
            'stage' => 'early',
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        $d['dimensions']['maturity']['x'] = $maturity;
        RelationshipDynamics::setPassion($d, $passion);
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        RelationshipDynamics::markContact($d);
        return $d;
    }

    private function conflict(array &$d): void
    {
        $this->at(self::T0);
        RelationshipDynamics::enterConflict($d);
    }

    /** The calendar from $fromDay to $toDay (game days after T0), in $steps equal steps. */
    private function calendar(array &$d, float $fromDay, float $toDay, int $steps = 1): array
    {
        $rot = 0.0;
        $w = ($toDay - $fromDay) / $steps;
        for ($i = 0; $i < $steps; $i++) {
            $a = self::T0 + ($fromDay + $i * $w) * self::DAY;
            $b = self::T0 + ($fromDay + ($i + 1) * $w) * self::DAY;
            $rot += RelationshipDynamics::advanceCalendar($d, $a, $b)['rot']['applied'];
        }
        return ['rot' => $rot, 'aff' => RelationshipDynamics::getCoreAffinity($d)];
    }

    private static function m(array $d, array $tags): float
    {
        return RelationshipDynamics::affinityModifiers($d, -1.0, $tags)['M'];
    }

    public function testOpenConflictRotsAfterAWeekWithoutPositiveContact(): void
    {
        $d = $this->bond('platonic', 50.0, 30.0);
        $this->conflict($d);
        $this->assertEqualsWithDelta(0.0, $this->calendar($d, 0, 6.5)['rot'], 1e-9, 'inside the grace: nothing');
        $m = self::m($d, []);
        $r = $this->calendar($d, 6.5, 10.0);
        $this->assertEqualsWithDelta(-3.0 * $m, $r['rot'], 1e-3, 'three rot days at -1/day x M');
        $this->assertEqualsWithDelta(50.0 - 3.0 * $m, $r['aff'], 1e-3);
        $this->assertSame(['conflict'], RelDynAbsence::jev($d)['rot_conditions']);
    }

    public function testAPositiveInteractionRestartsTheClockTimeAloneNever(): void
    {
        $d = $this->bond('platonic', 50.0, 30.0);
        $this->conflict($d);
        $this->calendar($d, 0, 5.0);
        RelDynAbsence::markPositive($d, self::T0 + 5 * self::DAY);
        $this->assertEqualsWithDelta(0.0, $this->calendar($d, 5.0, 12.0)['rot'], 1e-9, 'a week from the positive exchange');
        $m = self::m($d, []);
        $this->assertEqualsWithDelta(-1.0 * $m, $this->calendar($d, 12.0, 13.0)['rot'], 1e-3);

        // the conflict resolves: rot stops, and what it took stays taken (no healing by time)
        $aff = RelationshipDynamics::getCoreAffinity($d);
        $d['in_conflict'] = false;
        $this->assertEqualsWithDelta(0.0, $this->calendar($d, 13.0, 40.0)['rot'], 1e-9);
        $this->assertEqualsWithDelta($aff, RelationshipDynamics::getCoreAffinity($d), 1e-9, 'permanent: only contact brings it back');
        $this->assertSame([], RelDynAbsence::jev($d)['rot_conditions']);
    }

    public function testLowPassionRotsARomanceNotAFriendship(): void
    {
        $cold = $this->bond('romantic', 60.0, 10.0);
        $warm = $this->bond('romantic', 60.0, 45.0);
        $friend = $this->bond('platonic', 60.0, 10.0);
        foreach (['cold' => &$cold, 'warm' => &$warm, 'friend' => &$friend] as $k => &$d) {
            $d['_last_positive_gamets'] = self::T0;
        }
        unset($d);
        $m = self::m($cold, ['neglect']);
        $this->assertEqualsWithDelta(-3.0 * $m, $this->calendar($cold, 0, 10.0)['rot'], 1e-3, 'a romance gone cold');
        $this->assertSame('low_passion', RelDynAbsence::jev($cold)['rot_conditions'][0]);
        $this->assertEqualsWithDelta(0.0, $this->calendar($warm, 0, 10.0)['rot'], 1e-9, 'passion still there');
        $this->assertEqualsWithDelta(0.0, $this->calendar($friend, 0, 10.0)['rot'], 1e-9, 'a friendship needs no passion');
    }

    public function testRotIsRelativeToWhoTheNpcIs(): void
    {
        $immature = $this->bond('romantic', 60.0, 10.0, 20.0, 'anxious');
        $mature = $this->bond('romantic', 60.0, 10.0, 85.0, 'avoidant');
        $a = $this->calendar($immature, 0, 12.0)['rot'];
        $b = $this->calendar($mature, 0, 12.0)['rot'];
        $this->assertLessThan($b * 3.0, $a, 'the immature anxious partner bleeds far faster (maturity losses, anxious neglect x2)');
        $this->assertLessThan(0.0, $b, 'the mature avoidant one still bleeds, slowly');
    }

    public function testRotGoesPastTheBaselineAndTheFloorAndTheStageRegresses(): void
    {
        $d = $this->bond('romantic', 77.0, 5.0, 80.0);
        $d['_current_tier'] = 'bonded';
        $d['_current_tier_units'] = RelationshipDynamics::TIER_LABEL_UNITS;
        $d['dimensions']['trust']['x'] = 90.0;   // the trust gate would hold absence decay here
        $r = $this->calendar($d, 0, 30.0, 23);
        $this->assertLessThan(76.0, $r['aff']);
        $this->assertSame(RelationshipDynamics::getCurrentTier($r['aff']), $d['_current_tier'], 'the held label regresses with the number');
        $this->assertNotSame('bonded', $d['_current_tier']);
    }

    public function testRotStopsAtTheWalkawayLine(): void
    {
        $d = $this->bond('platonic', -15.0, 30.0, 10.0, 'anxious');
        $this->conflict($d);
        $this->calendar($d, 0, 60.0);
        $this->assertEqualsWithDelta(floatval(RelationshipDynamics::configValue('walkaway_affinity_at')), RelationshipDynamics::getCoreAffinity($d), 1e-6,
            'down to the MDD 6.5 walkaway line, where the walkaway severs it');
    }

    public function testNoRotForStrangersOrWhileAway(): void
    {
        $stranger = $this->bond('platonic', 20.0, 30.0);
        $stranger['context_tier_hwm'] = 1;
        $this->conflict($stranger);
        $this->assertEqualsWithDelta(0.0, $this->calendar($stranger, 0, 20.0)['rot'], 1e-9, 'never a relationship to rot');

        $gone = $this->bond('platonic', 50.0, 30.0);
        $this->conflict($gone);
        $gone['_walkaway_state'] = 'active';
        $this->assertEqualsWithDelta(0.0, $this->calendar($gone, 0, 20.0)['rot'], 1e-9, 'the one who left is not rotting');

        $rival = $this->bond('rival', 30.0, 30.0);
        $this->conflict($rival);
        $this->assertEqualsWithDelta(0.0, $this->calendar($rival, 0, 20.0)['rot'], 1e-9, 'hate is self-sustaining (no_decay)');
    }

    public function testSlicingTheCalendarDoesNotChangeTheRot(): void
    {
        $one = $this->bond('platonic', 50.0, 30.0);
        $many = $this->bond('platonic', 50.0, 30.0);
        $this->conflict($one);
        $this->conflict($many);
        // linear in time; only the mirror's 4-decimal rounding per step differs (core 0.0002 at most)
        $this->assertEqualsWithDelta($this->calendar($one, 0, 20.0)['rot'], $this->calendar($many, 0, 20.0, 37)['rot'], 37 * 0.0002);
    }

    public function testRotWhileApartCountsTowardTheBondBreakOnTheReturn(): void
    {
        // a trusted partner (the trust gate holds absence decay) whose open conflict rots the bond
        // while the player stays away: the return still reads where the absence started from
        $d = $this->bond('romantic', 60.0, 30.0, 45.0, 'anxious', 'Anxious');
        $d['dimensions']['trust']['x'] = 60.0;
        $this->conflict($d);
        $d['_decay_last_game_gamets'] = self::T0;
        $this->calendar($d, 0, 12.0, 12);
        $rot = RelDynAbsence::rotSinceContact($d, self::T0);
        $this->assertLessThan(-4.0, $rot, 'rot took the bond below its threshold while apart');
        $this->assertLessThan(56.0, RelationshipDynamics::getCoreAffinity($d));
        $t = self::T0 + 12 * self::DAY;
        $this->at($t);
        RelationshipDynamics::markContact($d);
        $ticks = RelationshipDynamics::calculateDecayTicks($d);
        $decay = RelationshipDynamics::processAffinityDecay($d, 'Test', 'Anxious', RelationshipDynamics::getRelationshipType('Test', $d), $ticks);
        $break = RelDynAbsence::onAbsenceDecay('Test', $d, $decay, $t);
        $this->assertNotNull($break, 'the bond broke during this absence');
        $this->assertEqualsWithDelta(60.0, $break['affinity_before'], 1e-6);
        $this->assertSame(0.0, RelDynAbsence::rotSinceContact($d, $t), 'a new absence starts clean');
    }

    public function testALoadBringsTheAbsenceClocksBackToTheLoadedTime(): void
    {
        // save-load-rollback: the rot's onsets, its last positive interaction, a conflict's opening and
        // the last break are game-calendar stamps; a load to an earlier time moves any later one to it
        $d = $this->bond('platonic', 50.0, 30.0);
        $this->conflict($d);
        $this->calendar($d, 0, 12.0);
        RelDynAbsence::markPositive($d, self::T0 + 11 * self::DAY);
        $d[RelDynAbsence::BREAK_KEY] = ['since_gamets' => self::T0 + 2 * self::DAY, 'at_gamets' => self::T0 + 9 * self::DAY, 'mode' => 'confront', 'say' => null, 'count' => 1];
        $T = self::T0 + 3 * self::DAY;
        $r = RelDynTimeline::rebaselineDynamics($d, (float) $T, null);
        $this->assertLessThanOrEqual($T, $r['_last_positive_gamets']);
        $this->assertLessThanOrEqual($T, $r['_conflict_entered_gamets']);
        foreach ($r[RelDynAbsence::ROT_KEY]['since'] as $c => $g) $this->assertLessThanOrEqual($T, $g, $c);
        $this->assertLessThanOrEqual($T, $r[RelDynAbsence::ROT_KEY]['last_gamets']);
        $this->assertLessThanOrEqual($T, $r[RelDynAbsence::ROT_KEY]['absence']['contact']);
        $this->assertEqualsWithDelta(self::T0 + 2 * self::DAY, $r[RelDynAbsence::BREAK_KEY]['since_gamets'], 1e-6, 'earlier stamps stay');
        $this->assertLessThanOrEqual($T, $r[RelDynAbsence::BREAK_KEY]['at_gamets']);
    }
}
