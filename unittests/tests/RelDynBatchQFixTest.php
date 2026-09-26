<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Batch-Q review fixes, unit level (no database: shipped defaults). Real code paths: the calendar
 * step (advanceCalendar), markContact, processAffinityDecay and RelDynAbsence::onAbsenceDecay as
 * the prerequest runs them; the passion, warmth, governor and combat entry points as their hooks
 * call them.
 */
final class RelDynBatchQFixTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const T0 = 500 * self::DAY;                          // last contact: game day 500

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
     * A bond last seen at T0: core type $coreType, core affinity $aff (-100..100), maturity 0..100,
     * passion points; $dims: other dimension x values (points).
     */
    private function bond(string $temperament, string $attachment, float $maturity, string $coreType, float $aff, float $passion = 30.0, array $dims = []): array
    {
        $this->at(self::T0);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
            'profile_overrides' => ['attachment_style' => $attachment],
            'traits' => [],
            '_accumulated_play_gamets' => 10.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
            'context_tier_hwm' => 3,
            'stage' => 'early',
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        foreach ($dims + ['maturity' => $maturity, 'resentment' => 0.0, 'trust' => 50.0, 'comfort' => 50.0] as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        RelationshipDynamics::setPassion($d, $passion);
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
        $decay = RelationshipDynamics::processAffinityDecay($d, 'Test', $d['inferred_temperament'],
            RelationshipDynamics::getRelationshipType('Test', $d), $ticks);
        return RelDynAbsence::onAbsenceDecay('Test', $d, $decay, $t);
    }

    /** Game days of this bond's neglect grace, measured from its last contact. */
    private static function graceDays(array $d): float
    {
        return (RelationshipDynamics::neglectGraceEndGamets($d) - floatval($d['_last_contact_gamets'])) / self::DAY;
    }

    // ================================================================ bond-break-resentment

    /**
     * The break is the moment the absence turned intentional TO HER: past her own neglect grace x
     * break_after_grace_mult (who she is: codependence, maturity, pride; the fulfillment band), not
     * the moment a number crossed a line. The same absence, the same drop across the threshold:
     * the anxious partner breaks, the secure, mature one does not yet.
     */
    public function testTheBreakWaitsUntilTheAbsenceFeelsIntentionalToHer(): void
    {
        $mult = floatval(RelDynAbsence::breakConfig()['break_after_grace_mult']);
        $this->assertGreaterThan(1.0, $mult, 'intentional comes after "life happens"');

        $anxious = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 57.0, 30.0, ['trust' => 40.0]);
        $secure = $this->bond('Independent', 'secure', 70.0, 'romantic', 57.0, 30.0, ['trust' => 40.0]);
        $ga = self::graceDays($anxious);
        $gs = self::graceDays($secure);
        $this->assertLessThan($gs, $ga, 'the codependent one excuses less');
        $days = 0.5 * ($mult * $ga + $mult * $gs);   // past the anxious partner's point, short of the secure one's
        $this->assertGreaterThan($gs, $days, 'past the secure partner\'s grace too: the daily neglect runs for both');

        $a = $this->returnAfter($anxious, $days);
        $s = $this->returnAfter($secure, $days);
        $this->assertLessThan(56.0, RelationshipDynamics::getCoreAffinity($secure), 'the number crossed the line for her as well');
        $this->assertNotNull($a, 'intentional to the anxious partner');
        $this->assertNull($s, 'not yet intentional to the secure, mature one');

        // past her own point it is intentional to her as well
        $later = $this->bond('Independent', 'secure', 70.0, 'romantic', 57.0, 30.0, ['trust' => 40.0]);
        $this->assertNotNull($this->returnAfter($later, $mult * self::graceDays($later) + 0.5));
    }

    /** Short of break_after_grace_mult x her grace (though past the grace itself) the bond holds. */
    public function testPastTheGraceButShortOfIntentionalIsStillLifeHappening(): void
    {
        $mult = floatval(RelDynAbsence::breakConfig()['break_after_grace_mult']);
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 57.0, 30.0, ['trust' => 40.0]);
        $g = self::graceDays($d);
        $this->assertNull($this->returnAfter($d, 0.5 * (1.0 + $mult) * $g));
        $this->assertLessThan(56.0, RelationshipDynamics::getCoreAffinity($d), 'the decay did cross the threshold');
        $this->assertGreaterThan(0.0, floatval($d['dimensions']['resentment']['x']), 'the daily neglect past the grace still counts');
    }

    /**
     * A fresh break strains the bond (the felt compose: no romantic or social impulse toward him,
     * the attraction says nothing) for the modes that carry hurt (confront, withdraw), until the
     * first positive interaction after it. The return's own exchange does not lift it.
     */
    public function testAFreshBreakStrainsTheBondUntilTheFirstWarmExchangeAfterIt(): void
    {
        $d = $this->bond('Anxious', 'anxious', 35.0, 'romantic', 80.0, 30.0, ['trust' => 40.0]);
        $this->assertFalse(RelDynAbsence::strains($d), 'nothing broken');
        $break = $this->returnAfter($d, 7.0);
        $this->assertNotNull($break);
        $this->assertSame('confront', $break['mode']);
        $this->assertTrue(RelDynAbsence::strains($d), 'the hurt of the break');
        RelDynAbsence::markPositive($d, $break['at_gamets']);
        $this->assertTrue(RelDynAbsence::strains($d), 'the return exchange itself is the one she is hurt in');
        RelDynAbsence::markPositive($d, $break['at_gamets'] + 600.0);
        $this->assertFalse(RelDynAbsence::strains($d), 'a warm exchange after it');

        foreach (['withdraw' => true, 'confront' => true, 'repair' => false, 'boundary' => false] as $mode => $strains) {
            $e = $d;
            $e[RelDynAbsence::BREAK_KEY]['mode'] = $mode;
            $e['_last_positive_gamets'] = 0.0;
            $this->assertSame($strains, RelDynAbsence::strains($e), $mode);
        }
    }
}
