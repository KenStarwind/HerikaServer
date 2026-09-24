<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Decisions 2026-09-23 §2 "time does not heal, contact does", on the engine's clocks:
 *  - filtered play clock (_accumulated_play_gamets): real play time only;
 *  - game calendar (raw gamets, waiting and sleeping count): absence and world timers.
 * No database: $GLOBALS['db'] is unset, so config is the defaults and nothing is stored.
 */
final class RelDynCalendarTimeTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const GAME_HOUR = self::DAY / 24;
    private const REAL_SECOND = RelationshipDynamics::GAMETS_PER_REAL_SECOND;

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

    // ------------------------------------------------------------ play clock filter

    public function testGapOfPlayPlusSleepCreditsOnlyThePlayedTime(): void
    {
        // One real hour of play, then a 24 h sleep, before this NPC's next request. The
        // average rate (~5100 gamets/s) is under the old wait/sleep threshold, so the old
        // filter counted the whole sleep as play.
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 3600, '_accumulated_play_gamets' => 1.0e6];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 3600 * self::REAL_SECOND + self::DAY);

        $this->assertEqualsWithDelta(3600 * self::REAL_SECOND, $credited, 2 * self::REAL_SECOND, 'one real hour, not 25 game hours');
        $this->assertEqualsWithDelta(1.0e6 + $credited, $d['_accumulated_play_gamets'], 0.001);
    }

    public function testPureWaitCreditsAtMostTheRealSecondsItTook(): void
    {
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 10, '_accumulated_play_gamets' => 1.0e6];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + self::DAY);
        $this->assertLessThanOrEqual(11 * self::REAL_SECOND, $credited);
    }

    /**
     * The per-gap cap (real seconds x GAMETS_PER_REAL_SECOND) cannot tell play from real time
     * with the game clock stopped (quit overnight, menus). The global play heartbeat (played
     * gamets across all requests, offline gaps capped) bounds the credit: a sleep after a real
     * break is not play.
     */
    public function testSleepAfterAnOvernightBreakIsNotPlay(): void
    {
        $g0 = 100 * self::DAY;
        $globalAtLastTurn = 5.0e8;                            // global play gamets at the NPC's last turn
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 12 * 3600, '_accumulated_play_gamets' => 1.0e6,
              '_last_global_play_gamets' => $globalAtLastTurn];
        // Back after 12 real hours offline: the heartbeat credited one capped gap (300 real s),
        // then the player slept 9 game hours and talked.
        $globalNow = $globalAtLastTurn + 300 * self::REAL_SECOND;

        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 9 * self::GAME_HOUR, $globalNow);

        $this->assertEqualsWithDelta(300 * self::REAL_SECOND, $credited, 0.001, 'only what the heartbeat saw as play');
        $this->assertEqualsWithDelta($globalNow, (float) $d['_last_global_play_gamets'], 0.001);
    }

    public function testWithTheHeartbeatNormalPlayIsStillCreditedInFull(): void
    {
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 600, '_accumulated_play_gamets' => 0.0,
              '_last_global_play_gamets' => 1.0e6];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 600 * 2000, 1.0e6 + 600 * 2000);
        $this->assertEqualsWithDelta(600 * 2000, $credited, 0.001);
    }

    public function testFirstTurnSeenByTheHeartbeatCreditsNothingUnproven(): void
    {
        // Stored before the heartbeat existed: no global mark, so the gap cannot be proven play.
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 12 * 3600, '_accumulated_play_gamets' => 1.0e6];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 9 * self::GAME_HOUR, 7.0e8);
        $this->assertSame(0.0, $credited);
        $this->assertEqualsWithDelta(7.0e8, (float) $d['_last_global_play_gamets'], 0.001, 'counted from here on');
    }

    /**
     * Jealousy (0..100) decays in contact, on the play clock. Time the player spends playing
     * elsewhere is on that clock too, so only one in-contact window per turn counts: a long
     * gap between turns with this NPC is absence, and time does not heal (decisions §2).
     */
    public function testJealousyDoesNotCoolWhileThePlayerIsAway(): void
    {
        $g0 = 100 * self::DAY;
        $rate = (float) RelationshipDynamics::defaultConfig()['jealousy_decay_per_hour'];   // jealousy points per real play hour
        $play = 20 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 10 * 3600, '_accumulated_play_gamets' => $play,
              '_last_global_play_gamets' => 1.0e9, 'jealousy_anger' => 60.0, 'jealousy_updated_at' => $play];

        // 30 game days and 10 real hours of play elsewhere before the next turn with this NPC
        RelationshipDynamics::updatePlayTime($d, $g0 + 30 * self::DAY, 1.0e9 + 10 * RelationshipDynamics::GAMETS_PER_REAL_HOUR);
        RelationshipDynamics::decayJealousy($d);

        $window = RelationshipDynamics::IN_CONTACT_DECAY_MAX_HOURS;             // real play hours
        $this->assertEqualsWithDelta(60.0 - $rate * $window, (float) $d['jealousy_anger'], 1e-6,
            'one in-contact window, not 10 hours apart');
    }

    public function testJealousyCoolsInContact(): void
    {
        $rate = (float) RelationshipDynamics::defaultConfig()['jealousy_decay_per_hour'];
        $play = 20 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $d = ['_accumulated_play_gamets' => $play + 5 * 60 * self::REAL_SECOND,   // 5 real minutes later
              'jealousy_anger' => 60.0, 'jealousy_updated_at' => $play];
        RelationshipDynamics::decayJealousy($d);
        $this->assertEqualsWithDelta(60.0 - $rate * 5 / 60, (float) $d['jealousy_anger'], 1e-6);
    }

    // ------------------------------------------------------------ contact + reunion

    private function setCalendar(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) $gamets, 'Kaida: hi'];
    }

    /** An NPC last in contact at game day 100 08:00, play clock at 50 real hours. */
    private function contactedNpc(): array
    {
        $this->setCalendar(100 * self::DAY + 8 * self::GAME_HOUR);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            '_accumulated_play_gamets' => 50.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
        ]));
        RelationshipDynamics::markContact($d);
        return $d;
    }

    public function testMarkContactStampsTheCalendarAndThePlayClock(): void
    {
        $d = $this->contactedNpc();
        $this->assertEqualsWithDelta(100 * self::DAY + 8 * self::GAME_HOUR, (float) $d['_last_contact_gamets'], 0.001);
        $this->assertEqualsWithDelta(50.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR, (float) $d['_last_contact_play_gamets'], 0.001);
    }

    public function testReunionCountsGameCalendarHoursApart(): void
    {
        $d = $this->contactedNpc();
        // 30 game hours apart, 25 real minutes of it spent playing (the rest slept at an inn)
        $this->setCalendar(100 * self::DAY + 38 * self::GAME_HOUR);
        $d['_accumulated_play_gamets'] += 25 * 60 * self::REAL_SECOND;

        $spike = RelationshipDynamics::checkReunion($d, 60);

        $this->assertEqualsWithDelta(12.0, $spike, 1e-9, '24-48 game hours apart');
        $this->assertEqualsWithDelta(30.0, (float) $d['_reunion_hours_apart'], 0.01);
        $this->assertSame(0.0, RelationshipDynamics::checkReunion($d, 60), 'once per reunion');
    }

    public function testWaitingOutAReunionEarnsNothing(): void
    {
        $d = $this->contactedNpc();
        $this->setCalendar(100 * self::DAY + 80 * self::GAME_HOUR);   // a 72 h wait, no play
        $this->assertSame(0.0, RelationshipDynamics::checkReunion($d, 60));
    }

    public function testReunionNeedsTheMinimumGameHoursApart(): void
    {
        $d = $this->contactedNpc();
        $this->setCalendar(100 * self::DAY + 15 * self::GAME_HOUR);   // 7 game hours < 8
        $d['_accumulated_play_gamets'] += 21 * 60 * self::REAL_SECOND;
        $this->assertSame(0.0, RelationshipDynamics::checkReunion($d, 60));
    }

    // ------------------------------------------------------------ calendar step

    private const T0 = 200 * self::DAY;   // last contact: game day 200, midnight

    /** @param array $extra top-level overrides; dims: dimension => x */
    private function npc(array $extra = [], array $dims = []): array
    {
        $this->setCalendar(self::T0);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Stoic',
            'attachment_style' => 'secure',
            '_accumulated_play_gamets' => 10.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
        ], $extra));
        foreach ($dims + ['maturity' => 60.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        RelationshipDynamics::markContact($d);
        return $d;
    }

    private static function resentment(array $d): float
    {
        return (float) $d['dimensions']['resentment']['x'];
    }

    private static function neglectEntries(array $d): array
    {
        return array_values(array_filter($d['dimensions']['resentment']['grievance_log'] ?? [],
            fn($g) => is_array($g) && ($g['tag'] ?? null) === 'neglect'));
    }

    public function testNegativeStatesDoNotDecayWithTimeAlone(): void
    {
        // Not bonded, no open conflict: a month passes and nothing negative moves.
        $d = $this->npc(['jealousy_anger' => 35.0], ['resentment' => 40.0, 'resentment_self' => 12.0]);
        $d['dimensions']['resentment']['grievance_log'] = [['text' => 'insulted', 'amount' => 5]];

        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 30 * self::DAY);

        $this->assertSame(40.0, self::resentment($d));
        $this->assertSame(12.0, (float) $d['dimensions']['resentment_self']['x']);
        $this->assertCount(1, $d['dimensions']['resentment']['grievance_log']);
    }

    public function testImmatureNpcFestersWhileTheConflictIsOpen(): void
    {
        $rate = (float) RelationshipDynamics::defaultConfig()['fester_resentment_per_game_day'];
        $this->assertGreaterThan(0.0, $rate);

        $d = $this->npc(['in_conflict' => true], ['maturity' => 30.0, 'resentment' => 10.0]);
        $expected = $d;
        for ($i = 0; $i < (int) round(3 * $rate); $i++) {   // 3 game days of raw festering, 1-point quanta
            RelationshipDynamics::applyDelta('resentment', $expected, 1.0, 'Stoic');
        }

        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 3 * self::DAY);

        $this->assertGreaterThan(10.0 + 3 * $rate * 1.5 - 1e-6, self::resentment($d), 'the accumulator physics (maturity-derived Y, inverted rubber band)');
        $this->assertEqualsWithDelta(self::resentment($expected), self::resentment($d), 1e-6);
        $this->assertEqualsWithDelta(3 * $rate, $r['resentment_raw'], 1e-9);
    }

    public function testMatureNpcOrClosedConflictDoesNotFester(): void
    {
        $mature = $this->npc(['in_conflict' => true], ['maturity' => 60.0, 'resentment' => 10.0]);
        RelationshipDynamics::advanceCalendar($mature, self::T0, self::T0 + 10 * self::DAY);
        $this->assertSame(10.0, self::resentment($mature));

        $noConflict = $this->npc([], ['maturity' => 20.0, 'resentment' => 10.0]);
        RelationshipDynamics::advanceCalendar($noConflict, self::T0, self::T0 + 10 * self::DAY);
        $this->assertSame(10.0, self::resentment($noConflict));
    }

    public function testFesterDoesNotDependOnHowTheTimeIsSliced(): void
    {
        $once = $this->npc(['in_conflict' => true], ['maturity' => 30.0, 'resentment' => 10.0]);
        $sliced = $once;
        RelationshipDynamics::advanceCalendar($once, self::T0, self::T0 + 5 * self::DAY);
        for ($h = 0; $h < 5 * 24; $h++) {                       // scanned every game hour instead
            RelationshipDynamics::advanceCalendar($sliced, self::T0 + $h * self::GAME_HOUR, self::T0 + ($h + 1) * self::GAME_HOUR);
        }
        $this->assertEqualsWithDelta(self::resentment($once), self::resentment($sliced), 1e-6);
    }

    public function testPassionFadesWithAbsenceScaledByAttachment(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $rate = (float) $cfg['passion_absence_fade_per_game_day'];
        $graceDays = (float) $cfg['passion_absence_grace_game_hours'] / 24.0;
        $absent = 3.0 - $graceDays;                              // absent days past the grace

        $fade = [];
        foreach (['secure' => 1.0, 'anxious' => 2.0, 'avoidant' => 0.5] as $style => $mult) {
            $d = $this->npc(['attachment_style' => $style]);
            RelationshipDynamics::setPassion($d, 60.0);
            $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 3 * self::DAY);
            $fade[$style] = 60.0 - RelationshipDynamics::getPassion($d);
            $this->assertEqualsWithDelta($rate * $absent * $mult, $fade[$style], 1e-6, "{$style} x{$mult}");
            $this->assertEqualsWithDelta($fade[$style], $r['passion_fade'], 1e-6);
        }
        $this->assertEqualsWithDelta(2.0, $fade['anxious'] / $fade['secure'], 1e-9, 'Anxious x2');
        $this->assertEqualsWithDelta(0.5, $fade['avoidant'] / $fade['secure'], 1e-9, 'Avoidant x0.5');
    }

    public function testPassionFadeStopsAtTheStageFloorAndNotWithinTheGrace(): void
    {
        $d = $this->npc(['stage' => RelationshipDynamics::STAGE_DEEP]);
        RelationshipDynamics::setPassion($d, 30.0);
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 12 * self::GAME_HOUR);
        $this->assertSame(30.0, RelationshipDynamics::getPassion($d), 'within the grace');

        RelationshipDynamics::advanceCalendar($d, self::T0 + 12 * self::GAME_HOUR, self::T0 + 60 * self::DAY);
        $this->assertEqualsWithDelta((float) RelationshipDynamics::STAGE_PARAMS['deep']['floor'], RelationshipDynamics::getPassion($d), 1e-9);
    }

    /** Decisions §2: positive states (passion spikes, warmth) fade with absence, by attachment. */
    public function testWarmthFadesWithAbsenceScaledByAttachment(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $rate = (float) $cfg['warmth_absence_fade_per_game_day'];     // warmth points (0..100) per game day
        $graceDays = (float) $cfg['warmth_absence_grace_game_hours'] / 24.0;
        $this->assertGreaterThan(0.0, $rate);
        $absent = 5.0 - $graceDays;                                   // absent game days past the grace

        $fade = [];
        foreach (['secure' => 1.0, 'anxious' => 2.0, 'avoidant' => 0.5] as $style => $mult) {
            $d = $this->npc(['attachment_style' => $style], ['warmth' => 80.0]);
            $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 5 * self::DAY);
            $fade[$style] = 80.0 - (float) $d['dimensions']['warmth']['x'];
            $this->assertEqualsWithDelta($rate * $absent * $mult, $fade[$style], 1e-6, "{$style} x{$mult}");
            $this->assertEqualsWithDelta($fade[$style], $r['warmth_fade'], 1e-6);
        }
        $this->assertEqualsWithDelta(2.0, $fade['anxious'] / $fade['secure'], 1e-9, 'Anxious x2');
        $this->assertEqualsWithDelta(0.5, $fade['avoidant'] / $fade['secure'], 1e-9, 'Avoidant x0.5');
    }

    public function testWarmthFadeStopsAtItsBaselineAndNeverRaisesLowWarmth(): void
    {
        $d = $this->npc([], ['warmth' => 70.0]);
        $baseline = RelationshipDynamics::getTemperamentBaseline('Stoic', 'warmth');   // warmth points (0..100)
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 12 * self::GAME_HOUR);
        $this->assertSame(70.0, (float) $d['dimensions']['warmth']['x'], 'within the grace');
        RelationshipDynamics::advanceCalendar($d, self::T0 + 12 * self::GAME_HOUR, self::T0 + 365 * self::DAY);
        $this->assertEqualsWithDelta($baseline, (float) $d['dimensions']['warmth']['x'], 1e-9, 'fades to the baseline, not below');

        $cold = $this->npc([], ['warmth' => 5.0]);   // below the baseline: absence neither heals nor deepens it
        RelationshipDynamics::advanceCalendar($cold, self::T0, self::T0 + 30 * self::DAY);
        $this->assertSame(5.0, (float) $cold['dimensions']['warmth']['x']);
    }

    public function testNeglectAccruesForABondedNpcPastItsGrace(): void
    {
        $bond = RelationshipDynamics::defaultConfig()['neglect_bond_types']['bonded'];
        $d = $this->npc(['relationship_type' => 'bonded']);

        // Inside the grace: nothing.
        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + ($bond['grace_game_days'] - 0.5) * self::DAY);
        $this->assertSame(0.0, $r['neglect_days']);
        $this->assertSame(0.0, self::resentment($d));

        // A month: every day past the grace is neglect.
        $r = RelationshipDynamics::advanceCalendar($d, self::T0 + ($bond['grace_game_days'] - 0.5) * self::DAY, self::T0 + 30 * self::DAY);
        $this->assertEqualsWithDelta(30 - $bond['grace_game_days'], $r['neglect_days'], 1e-9);
        $this->assertEqualsWithDelta((30 - $bond['grace_game_days']) * $bond['resentment_per_game_day'], $r['resentment_raw'], 1e-9);
        $this->assertGreaterThan(0.0, self::resentment($d));

        $log = self::neglectEntries($d);
        $this->assertCount(1, $log, 'one neglect grievance for this absence');
        $this->assertEqualsWithDelta(30.0, $log[0]['game_days'], 1e-9);
        $this->assertEqualsWithDelta(self::T0, (float) $log[0]['since_gamets'], 0.001);
    }

    public function testOneNeglectGrievancePerAbsenceEvenWithOtherGrievancesBetween(): void
    {
        $d = $this->npc(['relationship_type' => 'bonded']);
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 5 * self::DAY);
        $d['dimensions']['resentment']['grievance_log'][] = ['text' => 'insulted my cooking', 'amount' => 5];
        RelationshipDynamics::advanceCalendar($d, self::T0 + 5 * self::DAY, self::T0 + 9 * self::DAY);

        $log = self::neglectEntries($d);
        $this->assertCount(1, $log);
        $this->assertEqualsWithDelta(9.0, $log[0]['game_days'], 1e-9);
        $this->assertCount(2, $d['dimensions']['resentment']['grievance_log']);
    }

    public function testNeglectGraceDependsOnBondTypeAndAttachment(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $days = 10.0;
        $neglect = function (string $type, string $style) use ($days): float {
            $d = $this->npc(['relationship_type' => $type, 'attachment_style' => $style]);
            return RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + $days * self::DAY)['neglect_days'];
        };
        $graceMult = $cfg['neglect_attachment_grace_mult'];

        $this->assertEqualsWithDelta($days - $cfg['neglect_bond_types']['bonded']['grace_game_days'], $neglect('bonded', 'secure'), 1e-9);
        $this->assertEqualsWithDelta($days - $cfg['neglect_bond_types']['bonded']['grace_game_days'] * $graceMult['anxious'], $neglect('bonded', 'anxious'), 1e-9);
        $this->assertEqualsWithDelta($days - $cfg['neglect_bond_types']['friend']['grace_game_days'] * $graceMult['secure'], $neglect('friend', 'secure'), 1e-9);
        $this->assertLessThan($neglect('bonded', 'secure'), $neglect('bonded', 'avoidant'), 'avoidant waits longer');
        $this->assertSame(0.0, $neglect('acquaintance', 'anxious'), 'not a bond: no neglect');
    }

    public function testContactStopsNeglectButDoesNotHealIt(): void
    {
        $d = $this->npc(['relationship_type' => 'bonded']);
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 10 * self::DAY);
        $after = self::resentment($d);
        $this->assertGreaterThan(0.0, $after);

        $this->setCalendar(self::T0 + 10 * self::DAY);
        RelationshipDynamics::markContact($d);
        $r = RelationshipDynamics::advanceCalendar($d, self::T0 + 10 * self::DAY, self::T0 + 11 * self::DAY);

        $this->assertSame(0.0, $r['neglect_days'], 'fresh contact, inside the grace again');
        $this->assertEqualsWithDelta($after, self::resentment($d), 1e-9, 'the neglect already felt stays');
    }

    // ------------------------------------------------------------ neglect bond type from core (affinity lane seam)

    /** Core affinity (-100..100) as prerequest leaves it: mirror x and its core snapshot agree. */
    private static function withCoreAffinity(array $d, float $coreAff): array
    {
        $mirror = ($coreAff + 100.0) / 2.0;   // mirror scale 0..100
        $d['dimensions']['affinity']['x'] = $mirror;
        $d['_aff_mirror_x'] = $mirror;
        return $d;
    }

    public function testCoreRomanticSpouseIsNeglectedAsABond(): void
    {
        // Core Player.type is the relationship type: a spouse with few RelDyn interactions
        // (stage early, no explicit type) is still a bond that feels neglect.
        $d = self::withCoreAffinity($this->npc(), 40.0);
        RelationshipDynamics::setCoreRelationshipType($d, 'romantic');
        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 10 * self::DAY);

        $bond = RelationshipDynamics::defaultConfig()['neglect_bond_types']['bonded'];
        $this->assertSame('bonded', $r['bond_type']);
        $this->assertEqualsWithDelta(10 - $bond['grace_game_days'], $r['neglect_days'], 1e-9);
        $this->assertGreaterThan(0.0, self::resentment($d));
    }

    public function testNeutralCoreTypeTakesItsNeglectBondFromTheCoreAffinityTier(): void
    {
        // Core aff 85 (-100..100) is the bonded tier; core aff 0 is a neutral stranger.
        $close = self::withCoreAffinity($this->npc(), 85.0);
        RelationshipDynamics::setCoreRelationshipType($close, 'neutral');
        $r = RelationshipDynamics::advanceCalendar($close, self::T0, self::T0 + 10 * self::DAY);
        $this->assertSame('bonded', $r['bond_type']);
        $this->assertGreaterThan(0.0, $r['neglect_days']);

        $stranger = self::withCoreAffinity($this->npc(), 0.0);
        RelationshipDynamics::setCoreRelationshipType($stranger, 'neutral');
        $r = RelationshipDynamics::advanceCalendar($stranger, self::T0, self::T0 + 30 * self::DAY);
        $this->assertSame(0.0, $r['neglect_days'], 'a neutral stranger is not neglected');
        $this->assertSame(0.0, self::resentment($stranger));
    }

    public function testCoreEnemyIsNotNeglected(): void
    {
        // Even with a stored RelDyn 'bonded' type, core hostility wins (decisions: core is the source).
        $d = self::withCoreAffinity($this->npc(['relationship_type' => 'bonded']), -60.0);
        RelationshipDynamics::setCoreRelationshipType($d, 'enemy');
        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 30 * self::DAY);
        $this->assertSame('hostile', $r['bond_type']);
        $this->assertSame(0.0, $r['neglect_days']);
    }

    public function testNoNeglectOrFadeWhileTheyAreTheOneWhoLeft(): void
    {
        $d = $this->npc(['relationship_type' => 'bonded', '_walkaway_state' => 'boundary_test'], ['warmth' => 80.0]);
        RelationshipDynamics::setPassion($d, 50.0);
        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 10 * self::DAY);
        $this->assertSame(0.0, $r['neglect_days']);
        $this->assertSame(50.0, RelationshipDynamics::getPassion($d));
        $this->assertSame(80.0, (float) $d['dimensions']['warmth']['x']);
        $this->assertSame(0.0, self::resentment($d));
    }

    public function testNormalPlayIsCreditedInFull(): void
    {
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 600, '_accumulated_play_gamets' => 0.0];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 600 * 2000);   // a little slower than timescale 20
        $this->assertEqualsWithDelta(600 * 2000, $credited, 0.001);
    }
}
