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
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
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

    // ================================================================ rescue-bonus

    /** An NPC for the combat entry points (the Matrix does not judge: no attraction factor, no raise). */
    private static function fighter(string $temperament, float $anxiety, float $avoidance, float $passion = 30.0): array
    {
        return [
            'inferred_temperament' => $temperament, 'love_language_primary' => RelationshipDynamics::LL_TIME,
            'profile_overrides' => ['attachment_axes' => ['anxiety' => $anxiety, 'avoidance' => $avoidance]],
            '_aff_mirror_x' => 70.0, 'dimensions' => ['affinity' => ['x' => 70.0], 'passion' => ['x' => $passion, 'baseline' => 0]],
            'passion' => $passion, '_core_rel_type' => 'romantic',
            '_attraction' => ['enabled' => false, 'attracted' => true, 'spark' => 20.0, 'passion_mult' => 1.0, 'spark_mult' => 1.0],
        ];
    }

    /**
     * Only the player's exchange answers her fall. An eval item of her own line (request_type
     * 'instruction': core voicing her bleedout comment to the player) neither claims nor decides
     * it; the player's item after it does. An item without request_type (an older producer) is
     * read as before.
     */
    public function testHerOwnLinesItemDoesNotAnswerHerFall(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $fall = 5_000_000_000.0;
        $minute = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        $d = self::fighter('Guarded', 0.15, 0.15);
        RelDynCombat::noteFall($d, $fall);
        $own = ['gamets' => (int) ($fall + 200), 'tags' => [], 'positive_interaction' => false, 'request_type' => 'instruction'];
        $this->assertNull(RelDynCombat::onEvalItem('Ashe', $own, $d), 'her own line decides nothing');
        $this->assertIsArray($d[RelDynCombat::RESCUE_PENDING_KEY] ?? null, 'the fall still waits for the player');
        $this->assertNull($d[RelDynCombat::RESCUE_PENDING_KEY]['claimed_gamets']);
        $player = ['gamets' => (int) ($fall + $minute), 'tags' => ['rescue', 'reassurance'], 'positive_interaction' => true, 'request_type' => 'inputtext'];
        $r = RelDynCombat::onEvalItem('Ashe', $player, $d);
        $this->assertTrue($r['caring'], 'the player answered it');
        $this->assertEqualsWithDelta(4.0, $r['bonus'], 1e-4, 'Guarded: walls crack');

        $old = self::fighter('Guarded', 0.15, 0.15);
        RelDynCombat::noteFall($old, $fall);
        $this->assertTrue(RelDynCombat::onEvalItem('Ashe', ['gamets' => (int) ($fall + $minute), 'tags' => ['rescue'], 'positive_interaction' => true], $old)['caring'],
            'no request_type: read as before');

        // the field is code-written by the producer and normalized on the way in
        $this->assertSame('instruction', RelationshipDynamics::normalizeEvalExtraFields(['request_type' => ' Instruction '], 'Ashe')['request_type']);
        $this->assertArrayNotHasKey('request_type', RelationshipDynamics::normalizeEvalExtraFields(['request_type' => 'not a type!'], 'Ashe'));
    }

    /**
     * One care, paid once: when the eval item is the player's caring answer to her fall, the MDD
     * 3.3 response is that care, and the tags that made it caring (the rescue, the reassurance)
     * trigger no moment on top of it. What else the exchange was still does (time together, her
     * love language here); a rescue that answered no fall of hers is a moment as before.
     */
    public function testTheCareThatAnsweredHerFallIsNotAlsoAMoment(): void
    {
        $n = ['gamets' => 5_000_000_000, 'tags' => ['rescue', 'reassurance'], 'positive_interaction' => true, 'signals' => ['passion' => 0.0]];
        $d = self::fighter('Guarded', 0.15, 0.15);
        $this->assertSame([], RelDynPassion::onEvalItem('Ashe', $n, $d, true), 'the rescue response is the whole of it');
        $this->assertSame(0.0, RelDynPassion::spike($d));
        $e = self::fighter('Guarded', 0.15, 0.15);
        $this->assertGreaterThan(0.0, RelDynPassion::onEvalItem('Ashe', $n, $e, false)['rescue'] ?? 0.0, 'a rescue that answered no fall of hers');
        $f = self::fighter('Guarded', 0.15, 0.15);
        $m = RelDynPassion::onEvalItem('Ashe', ['tags' => ['rescue', 'quality_time']] + $n, $f, true);
        $this->assertArrayNotHasKey('rescue', $m);
        $this->assertGreaterThan(0.0, $m['love_language_primary'] ?? 0.0, 'time together is her love language, not the rescue');
    }
}

