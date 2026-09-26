<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Roadmap bond-break-resentment (memory project_bond_break_resentment, decisions §2, rulings §8
 * and §9): the return from an absence that broke the bond. The daily neglect stays the one slow
 * path; the break is the moment the absence turned intentional (the absence decay carried the
 * bond below its type's threshold, past the neglect grace), once per absence, through the same
 * neglect buffer and ceiling, expressed by who the NPC is. Real code paths: the calendar step
 * while away, markContact, calculateDecayTicks and processAffinityDecay on the return, then
 * RelDynAbsence::onAbsenceDecay as the prerequest runs it. No database: defaults.
 */
final class RelDynBondBreakTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const T0 = 300 * self::DAY;                          // last contact: game day 300

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

    /**
     * A bond last seen at T0: core type $coreType, core affinity $aff (-100..100), maturity 0..100.
     * $dims: other dimension x values (points).
     */
    private function bond(string $temperament, string $attachment, float $maturity, string $coreType, float $aff, array $dims = [], array $traits = []): array
    {
        $this->at(self::T0);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
            'profile_overrides' => ['attachment_style' => $attachment],
            'traits' => $traits,
            '_accumulated_play_gamets' => 10.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
            'context_tier_hwm' => 3,
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        foreach ($dims + ['maturity' => $maturity, 'resentment' => 0.0, 'trust' => 50.0, 'comfort' => 50.0] as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        RelationshipDynamics::markContact($d);
        $d['_decay_last_game_gamets'] = self::T0;
        return $d;
    }

    /** Away $days game days (the calendar step), then the return as the prerequest runs it. */
    private function returnAfter(array &$d, float $days): ?array
    {
        $t = self::T0 + $days * self::DAY;
        RelationshipDynamics::advanceCalendar($d, self::T0, $t);
        $this->at($t);
        RelationshipDynamics::markContact($d);
        $ticks = RelationshipDynamics::calculateDecayTicks($d);
        $temperament = $d['inferred_temperament'];
        $decay = RelationshipDynamics::processAffinityDecay($d, 'Test', $temperament, RelationshipDynamics::getRelationshipType('Test', $d), $ticks);
        return RelDynAbsence::onAbsenceDecay('Test', $d, $decay, $t);
    }

    private static function x(array $d, string $dim): float
    {
        return (float) $d['dimensions'][$dim]['x'];
    }

    // ------------------------------------------------------------ the memory's two anchors

    /**
     * "Anxious romantic partner, ghosted 1 week: ~40 resentment + comfort crash + trust hit";
     * "mature independent friend, gone 2 weeks: ~2 resentment. Good to see you."
     */
    public function testAnxiousPartnerGhostedAWeekBreaksHardWhileAMatureFriendBarelyMinds(): void
    {
        // a low-trust anxious partner: the trust gate does not hold her floor
        $anxious = $this->bond('Anxious', 'anxious', 50.0, 'romantic', 80.0, ['trust' => 40.0]);
        $break = $this->returnAfter($anxious, 7.0);
        $this->assertNotNull($break, 'the bond broke');
        $this->assertSame('confront', $break['mode']);
        $this->assertSame('bonded', $break['bond_type']);
        $total = self::x($anxious, 'resentment');
        $this->assertGreaterThanOrEqual(30.0, $total, 'about 40: the daily neglect plus the break');
        $this->assertLessThanOrEqual(55.0, $total);
        $this->assertGreaterThan(0.0, $break['resentment'], 'the break itself added resentment');
        $this->assertLessThan(50.0, self::x($anxious, 'comfort'), 'comfort crash');
        $this->assertLessThan(40.0, self::x($anxious, 'trust'), 'trust hit');

        $friend = $this->bond('Independent', 'secure', 80.0, 'platonic', 45.0, ['comfort' => 60.0]);
        $this->returnAfter($friend, 14.0);
        $this->assertLessThan(5.0, self::x($friend, 'resentment'), 'a mature, independent friend: good to see you');
    }

    public function testShortAbsenceIsLifeHappeningNotABreak(): void
    {
        // anxious: bonded grace 3 game days x grace_mult; one day away is inside it
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 57.0, ['trust' => 40.0]);
        $graceDays = (RelationshipDynamics::neglectGraceEndGamets($d) - self::T0) / self::DAY;
        $this->assertGreaterThan(1.0, $graceDays);
        $this->assertNull($this->returnAfter($d, 1.0), 'inside the grace: no break even though the number fell');
        $this->assertLessThan(56.0, RelationshipDynamics::getCoreAffinity($d), 'the decay did cross the threshold');
    }

    public function testOnceOnlyPerAbsence(): void
    {
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 80.0, ['trust' => 40.0]);
        $first = $this->returnAfter($d, 7.0);
        $this->assertNotNull($first);
        $again = ['skipped' => false, 'old_affinity' => 70.0, 'new_affinity' => 40.0];
        $this->assertNull(RelDynAbsence::onAbsenceDecay('Test', $d, $again, self::T0 + 7 * self::DAY), 'the same absence breaks once');
        $this->assertSame(1, $d[RelDynAbsence::BREAK_KEY]['count']);
        $log = array_values(array_filter($d['dimensions']['resentment']['grievance_log'], fn($g) => ($g['kind'] ?? null) === 'bond_break'));
        $this->assertCount(1, $log, 'one bond_break grievance for the confrontation to name');
        $this->assertSame('neglect', $log[0]['tag']);
    }

    public function testBondTypesWithoutAThresholdNeverBreak(): void
    {
        $this->assertNull(RelDynAbsence::breakThreshold('acquaintance'));
        $this->assertNull(RelDynAbsence::breakThreshold('mercenary'));
        $this->assertEquals(56.0, RelDynAbsence::breakThreshold('bonded'), 'a partner who no longer feels close');
        $this->assertEquals(31.0, RelDynAbsence::breakThreshold('crush'));
        $this->assertEquals(6.0, RelDynAbsence::breakThreshold('friend'));
        $d = $this->bond('Anxious', 'anxious', 35.0, 'professional', 40.0, ['trust' => 40.0]);
        $this->assertNull($this->returnAfter($d, 20.0), 'an acquaintance does not break');
    }

    // ------------------------------------------------------------ the matrix

    public function testTrustGivesTheBenefitOfTheDoubtAndTheBondTypeWeighs(): void
    {
        $low = $this->bond('Romantic', 'secure', 45.0, 'romantic', 80.0, ['trust' => 20.0]);
        $high = $this->bond('Romantic', 'secure', 45.0, 'romantic', 80.0, ['trust' => 90.0]);
        $mLow = RelDynAbsence::magnitudes($low, 'bonded', 7.0);
        $mHigh = RelDynAbsence::magnitudes($high, 'bonded', 7.0);
        $this->assertGreaterThan($mHigh['raw'] * 2.0, $mLow['raw'], 'low trust confirms the suspicion');
        $this->assertEqualsWithDelta(2.0 ** 0.6, $mLow['trust_scale'], 1e-9);

        $friend = RelDynAbsence::magnitudes($low, 'friend', 7.0);
        $this->assertLessThan($mLow['raw'], $friend['raw'], 'a friendship obliges less');
        $this->assertLessThan($mLow['trust'], $friend['trust'], 'the trust hit is proportional to the bond');
        $long = RelDynAbsence::magnitudes($low, 'bonded', 28.0);
        $this->assertEqualsWithDelta(2.0, $long['duration_scale'], 1e-9, 'four weeks: twice a week (sqrt)');
        $this->assertGreaterThan($mLow['raw'], $long['raw'], 'long feels intentional');
    }

    public function testMaturityAndAttachmentScaleTheResentment(): void
    {
        $reactive = RelDynAbsence::magnitudes($this->bond('Anxious', 'anxious', 20.0, 'romantic', 80.0), 'bonded', 7.0);
        $steady = RelDynAbsence::magnitudes($this->bond('Anxious', 'secure', 50.0, 'romantic', 80.0), 'bonded', 7.0);
        $this->assertGreaterThan($steady['raw'], $reactive['raw'], 'immature and anxious assumes the worst');
        $this->assertGreaterThan($steady['comfort'], $reactive['comfort'], 'a codependent NPC had a harder time coping alone');
    }

    public function testResentmentFromTheBreakStopsAtTheNeglectCeiling(): void
    {
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 80.0, ['trust' => 5.0]);
        $ceiling = RelationshipDynamics::getNeglectProfile($d)['ceiling'];
        $d['dimensions']['resentment']['x'] = $ceiling - 0.5;
        $felt = RelationshipDynamics::chargeNeglectResentment($d, 500.0, self::T0);
        $this->assertLessThanOrEqual(0.5 + 1e-6, $felt);
        $this->assertLessThanOrEqual($ceiling + 1e-6, self::x($d, 'resentment'), 'an absence alone takes her only this far');
    }

    // ------------------------------------------------------------ expression

    public function testMatureNpcStatesTheBoundaryNowInsteadOfAGrudge(): void
    {
        $d = $this->bond('Romantic', 'secure', 75.0, 'romantic', 80.0, ['trust' => 40.0]);
        RelDynFulfillment::ensure($d, [], self::T0);
        $this->assertSame('boundary', RelDynAbsence::mode($d));
        $immature = RelDynAbsence::magnitudes($this->bond('Romantic', 'secure', 45.0, 'romantic', 80.0, ['trust' => 40.0]), 'bonded', 14.0);
        $break = $this->returnAfter($d, 14.0);
        $this->assertNotNull($break);
        $this->assertSame('boundary', $break['mode']);
        $this->assertLessThan($immature['raw'], $break['raw'], 'a floor on anger');
        $b = RelDynFulfillment::pairState($d)['boundary'];
        $this->assertSame('pending', $b['state'], 'the §9 statement comes due now');
        $this->assertSame('bond_break', $b['source']);
        $none = RelDynAbsence::takeFeltLines($d, 'Test', 'Kaida', true);
        $this->assertSame([], $none['lines'], 'the §9 statement is the one voice');
        $said = RelDynFulfillment::takeFeltTexts($d, 'Test', 'Kaida', self::T0 + 14 * self::DAY, true);
        $this->assertArrayHasKey('boundary', $said['texts']);
        $this->assertSame('probation', RelDynFulfillment::pairState($d)['boundary']['state']);

        // no fulfillment state (nothing to step back through): the calm repair talk
        $repair = $this->bond('Romantic', 'secure', 75.0, 'romantic', 80.0, ['trust' => 40.0]);
        $this->assertSame('repair', RelDynAbsence::mode($repair));
    }

    public function testGuardedNpcWithdrawsAndTheWallsGoBackUp(): void
    {
        $guarded = $this->bond('Guarded', 'avoidant', 40.0, 'crush', 50.0, ['trust' => 40.0]);
        $this->assertTrue(RelDynAbsence::withdraws($guarded));
        $this->assertSame('withdraw', RelDynAbsence::mode($guarded));
        $open = $this->bond('Romantic', 'secure', 40.0, 'crush', 50.0, ['trust' => 40.0]);
        $this->assertSame('confront', RelDynAbsence::mode($open));
        // an anxious attachment protests however guarded the temperament: fear of abandonment speaks first
        $protest = $this->bond('Guarded', 'anxious', 40.0, 'crush', 50.0, ['trust' => 40.0]);
        $this->assertGreaterThanOrEqual(0.55, RelDynAbsence::guardedness($protest), 'guarded');
        $this->assertFalse(RelDynAbsence::withdraws($protest), 'but anxious');
        $this->assertSame('confront', RelDynAbsence::mode($protest));
        $w = RelDynAbsence::magnitudes($guarded, 'crush', 21.0);
        $this->assertEqualsWithDelta(1.5, $w['comfort'] / (10.0 * 0.7 * (0.5 + RelationshipDynamics::getNeglectProfile($guarded)['codependence'])), 1e-9,
            'withdraw: the comfort drop is x1.5');
    }

    public function testTheLineIsAFeelingSaidOnceToThePlayersFace(): void
    {
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 80.0, ['trust' => 40.0]);
        $this->assertNotNull($this->returnAfter($d, 7.0));
        $this->assertSame([], RelDynAbsence::takeFeltLines($d, 'Test', 'Kaida', false)['lines'], 'not over their head');
        $said = RelDynAbsence::takeFeltLines($d, 'Test', 'Kaida', true);
        $this->assertCount(1, $said['lines']);
        $this->assertSame('confront', $said['lines'][0]['key']);
        $this->assertStringContainsString('Kaida', $said['lines'][0]['text']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $said['lines'][0]['text'], 'feelings, never numbers');
        $this->assertSame([], RelDynAbsence::takeFeltLines($d, 'Test', 'Kaida', true)['lines'], 'said once');

        $e = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 80.0, ['trust' => 40.0]);
        $this->returnAfter($e, 7.0);
        $quiet = RelDynAbsence::takeFeltLines($e, 'Test', 'Kaida', true, true);
        $this->assertTrue($quiet['changed']);
        $this->assertSame([], $quiet['lines'], 'the confrontation said it: one voice');
    }

    public function testJevGetsTheNumbers(): void
    {
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 80.0, ['trust' => 40.0]);
        $this->returnAfter($d, 7.0);
        $j = RelDynAbsence::jev($d);
        $this->assertSame('confront', $j['bond_break']['mode']);
        $this->assertEqualsWithDelta(7.0, $j['bond_break']['absent_game_days'], 0.01);
        $this->assertLessThan(0.0, $j['bond_break']['trust_delta'], 'trust points lost');
        $this->assertLessThanOrEqual(0.0, $j['bond_break']['comfort_delta']);
    }
}
