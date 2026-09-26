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

    // ================================================================ affinity-rot

    /** Rot core points (<= 0) of the calendar [fromDay, toDay] (game days after T0), one step per game day, no contact. */
    private function apart(array &$d, float $fromDay, float $toDay): float
    {
        $rot = 0.0;
        for ($day = $fromDay; $day < $toDay - 1e-9; $day += 1.0) {
            $rot += RelationshipDynamics::advanceCalendar($d, self::T0 + $day * self::DAY, self::T0 + min($toDay, $day + 1.0) * self::DAY)['rot']['applied'];
        }
        return $rot;
    }

    /** The same, with the player there every game day (contact, never a positive exchange). */
    private function together(array &$d, float $fromDay, float $toDay): float
    {
        $rot = 0.0;
        for ($day = $fromDay; $day < $toDay - 1e-9; $day += 1.0) {
            $t = self::T0 + min($toDay, $day + 1.0) * self::DAY;
            $rot += RelationshipDynamics::advanceCalendar($d, self::T0 + $day * self::DAY, $t)['rot']['applied'];
            $this->at($t);
            RelationshipDynamics::markContact($d);
        }
        return $rot;
    }

    /**
     * MDD 6.5 "prolonged low Passion" is the romance gone cold between them, not the absence: time
     * apart is the absence path's (the decay, the daily neglect, the bond break), so the cold
     * romance rots only while the absence is still "life happens" (up to her neglect grace after
     * the last contact). The player there every day without one warm exchange: it rots.
     */
    public function testTheColdRomanceRotsBetweenThemNotWhileHeIsAway(): void
    {
        $away = $this->bond('Romantic', 'secure', 50.0, 'romantic', 60.0, 10.0);
        $away['_last_positive_gamets'] = self::T0;
        $this->assertSame(['low_passion' => true], RelDynAbsence::rotConditions($away));
        $this->assertEqualsWithDelta(0.0, $this->apart($away, 0, 20.0), 1e-9, 'two weeks and more apart: the absence path owns that time');

        $there = $this->bond('Romantic', 'secure', 50.0, 'romantic', 60.0, 10.0);
        $there['_last_positive_gamets'] = self::T0;
        $m = RelationshipDynamics::affinityModifiers($there, -1.0, ['neglect'])['M'];
        $this->assertEqualsWithDelta(-5.0 * $m, $this->together($there, 0, 12.0), 1e-3, 'together every day, cold: five rot days past the week');

        // an open fight still rots through the absence (time does not heal, decisions §2)
        $fight = $this->bond('Romantic', 'secure', 50.0, 'platonic', 60.0, 30.0);
        $fight['_last_positive_gamets'] = self::T0;
        RelationshipDynamics::enterConflict($fight);
        $this->assertLessThan(0.0, $this->apart($fight, 0, 12.0));
    }

    /**
     * A committed partner's passion cools to her tier's floor (MDD 8.1: 20) and stays there: that
     * is as cold as the committed tier lets her go, and it rots like any cold romance (MDD 6.5).
     * Before this, the governor's floor equalled the rot's line (20, strict <) and a committed
     * partner who had ever reached 20 never rotted.
     */
    public function testACommittedRomanceHeldAtItsTierFloorHasGoneCold(): void
    {
        $d = $this->bond('Romantic', 'secure', 50.0, 'romantic', 60.0, 35.0);
        $d['_last_positive_gamets'] = self::T0;
        $gov = RelDynGovernors::governor($d);
        $this->assertSame('committed', $gov['tier']);
        $this->assertEqualsWithDelta(20.0, $gov['floor'], 1e-9);
        $this->apart($d, 0, 30.0);   // the calendar fade takes her to the floor and holds her there
        $this->assertEqualsWithDelta(20.0, RelationshipDynamics::getPassion($d), 1e-6);
        $this->assertArrayHasKey('low_passion', RelDynAbsence::rotConditions($d), 'at her tier floor: gone cold');
        $this->at(self::T0 + 30 * self::DAY);
        RelationshipDynamics::markContact($d);
        RelDynAbsence::markPositive($d, self::T0 + 30 * self::DAY);
        $this->assertLessThan(0.0, $this->together($d, 30.0, 40.0), 'the player there, no warmth: it rots');
        $warm = $this->bond('Romantic', 'secure', 50.0, 'romantic', 60.0, 25.0);
        $this->assertArrayNotHasKey('low_passion', RelDynAbsence::rotConditions($warm), 'above her floor: not cold');
    }

    /**
     * MDD 8.3: "low passion, high tier" (the political marriage: committed but loveless) is a real
     * bond, gated by different pillars. A partner the Matrix finds unattracted was never going to
     * burn; her low passion is who they are together, not a romance gone cold, and it does not rot.
     * An open fight in it still does.
     */
    public function testAPoliticalMarriageIsLovelessNotRotting(): void
    {
        $d = $this->bond('Romantic', 'secure', 50.0, 'romantic', 60.0, 10.0);
        $d['_attraction'] = ['enabled' => true, 'attracted' => false, 'spark' => 20.0, 'passion_mult' => 0.2, 'spark_mult' => 1.0];
        $d['_last_positive_gamets'] = self::T0;
        $this->assertArrayNotHasKey('low_passion', RelDynAbsence::rotConditions($d));
        $this->assertEqualsWithDelta(0.0, $this->together($d, 0, 12.0), 1e-9);
        $this->at(self::T0 + 12 * self::DAY);
        RelationshipDynamics::enterConflict($d);
        $this->assertLessThan(0.0, $this->together($d, 12.0, 24.0), 'a fight left open still wears it down');
        $d['_attraction']['attracted'] = true;
        $this->assertArrayHasKey('low_passion', RelDynAbsence::rotConditions($d), 'an attracted partner gone cold does rot');
    }

    // ================================================================ derived-warmth

    /** A bond whose derived warmth reads sqrt(passion x comfort) plainly: passion and comfort points, no moment. */
    private function open(float $passion, float $comfort, string $coreType = 'platonic', float $aff = 40.0, string $temperament = 'Romantic',
                          string $attachment = 'secure', float $maturity = 50.0): array
    {
        $d = $this->bond($temperament, $attachment, $maturity, $coreType, $aff, $passion, ['comfort' => $comfort]);
        unset($d[RelDynPassion::SPIKE_KEY], $d['_weather_gravity']);
        return $d;
    }

    /**
     * Derived warmth is sqrt(passion x comfort), so a held state that moves comfort (or the
     * weather's pull on passion) already moves it. The offset tables were written for a stand-alone
     * warmth dimension, with a warmth column of their own: a state that has one reaches warmth at
     * that value, once (its comfort / passion part is not counted into the root as well); a state
     * with no warmth column reaches it through the root.
     */
    public function testAHeldStateReachesWarmthOnceAtItsDesignedValue(): void
    {
        $base = RelDynPassion::warmth($this->open(40.0, 50.0), false);
        $this->assertEqualsWithDelta(sqrt(40.0 * 50.0), $base, 1e-6);

        // acute grief (RelDynProtocols: comfort -15 held in x, warmth -10): -10, not -17
        $grief = $this->open(40.0, 35.0);
        $grief[RelDynProtocols::GRIEF_HELD_KEY] = ['comfort' => -15.0, 'warmth' => -10.0];
        $this->assertEqualsWithDelta($base - 10.0, RelDynPassion::warmth($grief, false), 1e-6, 'acute grief');

        // an overcast mood (the weather's node: warmth -3, passion -3 read at display time): -3, not -4.7
        $overcast = $this->open(40.0, 50.0);
        $overcast['_weather_gravity'] = ['weather' => 'overcast', 'offsets' => ['warmth' => -3.0, 'passion' => -3.0], 'applied' => []];
        $this->assertEqualsWithDelta($base - 3.0, RelDynPassion::warmth($overcast, false), 1e-6, 'overcast');
        $this->assertEqualsWithDelta(37.0, RelDynPassion::effective($overcast), 1e-6, 'the pull on passion itself stays');

        // a fire (comfort +10, warmth +5, passion +5, all held in x): +5
        $fire = $this->open(45.0, 60.0);
        $fire['_applied_physical_deltas'] = ['warm_fire' => ['comfort' => 10.0, 'warmth' => 5.0, 'passion' => 5.0]];
        $this->assertEqualsWithDelta($base + 5.0, RelDynPassion::warmth($fire, false), 1e-6, 'a fire');

        // the cold (comfort -10 held in x, no warmth column): through the root, she closes a little
        $cold = $this->open(40.0, 40.0);
        $cold['_applied_physical_deltas'] = ['cold' => ['comfort' => -10.0]];
        $this->assertEqualsWithDelta(sqrt(40.0 * 40.0), RelDynPassion::warmth($cold, false), 1e-6, 'the cold');
    }

    /**
     * Divine Intervention's breaking arc: "zero warmth toward non-bonded". Warmth is derived, so the
     * arc closes it where it is read: toward a player who is not her bonded / sworn partner it
     * reads nothing at the arc and opens again across the arc's own Brittle window (30 game days).
     * A bonded partner is not shut out.
     */
    public function testTheBreakingArcClosesHerWarmthAndItOpensAgainOverTheArc(): void
    {
        $arc = new ReflectionMethod(RelationshipDynamics::class, 'applyBreakingArc');
        $arc->setAccessible(true);
        $d = $this->open(30.0, 60.0, 'platonic', 40.0, 'Guarded');
        $this->assertNotContains(RelationshipDynamics::getRelationshipType('Test', $d), ['bonded', 'sworn']);
        $before = RelDynPassion::warmth($d, false);
        $this->assertGreaterThan(20.0, $before);
        $this->at(self::T0);
        $arc->invokeArgs(null, ['Test', 3, &$d]);
        $this->assertEqualsWithDelta(0.0, RelDynPassion::warmth($d, false), 1e-9, 'walled at the arc');
        $this->assertEqualsWithDelta(0.0, (float) RelDynPassion::warmth($d), 1e-9, 'toward him too');
        $this->at(self::T0 + 15 * self::DAY);
        $half = RelDynPassion::warmth($d, false);
        $this->assertGreaterThan(0.0, $half);
        $this->assertLessThan(0.75 * $before, $half, 'half way through the arc: still closed off');
        $this->at(self::T0 + 31 * self::DAY);
        $this->assertEqualsWithDelta(RelDynPassion::warmth(array_diff_key($d, [RelDynPassion::BREAKING_WARMTH_START_KEY => 1]), false), RelDynPassion::warmth($d, false), 1e-9, 'open again');

        $partner = $this->open(30.0, 60.0, 'romantic', 80.0, 'Guarded');
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType('Test', $partner));
        $this->at(self::T0);
        $arc->invokeArgs(null, ['Test', 3, &$partner]);
        $this->assertGreaterThan(0.0, RelDynPassion::warmth($partner, false), 'her bonded partner is not shut out');
    }

    /**
     * Rulings §8: "the same scaling applies to warmth fade" (neglect severity per NPC: codependence,
     * maturity, pride). Warmth is derived; the absence fades what she shows by at least the §8
     * warmth fade (its grace and rate x getNeglectProfile), never below where her warmth rests,
     * and a warm exchange gives some of it back (contact heals, decisions §2).
     */
    public function testWarmthFadesWithAbsenceByWhoSheIs(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $rate = (float) $cfg['warmth_absence_fade_per_game_day'];   // warmth points per game day
        $grace = (float) $cfg['warmth_absence_grace_game_hours'] / 24.0;
        $drop = $target = [];
        foreach (['mature' => ['Independent', 'secure', 80.0], 'anxious' => ['Anxious', 'anxious', 30.0]] as $who => [$t, $a, $m]) {
            // a partner at her tier's floor (committed: 20), so the passion fade moves nothing: the
            // warmth fade is the rulings §8 fade alone
            $d = $this->open(20.0, 90.0, 'romantic', 70.0, $t, $a, $m);
            $prof = RelationshipDynamics::getNeglectProfile($d);
            $w0 = RelDynPassion::warmth($d, false);
            $rest = RelDynPassion::warmthBaseline($d, false);
            RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 6 * self::DAY);
            $this->assertEqualsWithDelta(20.0, RelationshipDynamics::getPassion($d), 1e-6, "{$who}: passion held at her floor");
            $this->assertEqualsWithDelta(90.0, (float) $d['dimensions']['comfort']['x'], 1e-6, "{$who}: her comfort as it was");
            $drop[$who] = $w0 - RelDynPassion::warmth($d, false);
            $target[$who] = min($w0 - $rest, $rate * $prof['rate_mult'] * max(0.0, 6.0 - $grace * $prof['grace_mult']));
            $this->assertEqualsWithDelta($target[$who], $drop[$who], 1e-6, "{$who}: the rulings 8 warmth fade");
            $this->assertGreaterThanOrEqual($rest - 1e-6, RelDynPassion::warmth($d, false), "{$who}: never below where she rests");
            if ($who === 'anxious') {
                $faded = RelDynPassion::warmth($d, false);
                RelDynAbsence::markPositive($d, self::T0 + 6 * self::DAY);
                $this->assertGreaterThan($faded, RelDynPassion::warmth($d, false), 'a warm exchange gives some back');
            }
        }
        $this->assertGreaterThan($drop['mature'], $drop['anxious'], 'the less mature, anxious one loses more ' . json_encode(compact('drop', 'target')));
        $this->assertGreaterThan(0.0, $drop['mature'], 'the mature one still misses him');
    }
}
