<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Walkaway boundary test (MDD 6.4) and Hoover sleeper (MDD 6.6) under decisions 2026-09-23 §2:
 *  - both run on the GAME CALENDAR (raw gamets; waiting and sleeping count as time apart);
 *  - leaving the NPC alone resolves the boundary test: the walkaway clears, the resentment
 *    behind it does not (time does not heal; no passive resentment decay while away);
 *  - following them during the test is still a permanent departure; talking to them as they
 *    leave a NEGLECT walkaway (the parting conversation on the player's return,
 *    walkaway_parting_game_minutes) is not following (rulings 2026-09-24 §8), while a plea
 *    as they walk out of a fight still is; the test counts from when they left;
 *  - hoover sleeper = 72-96 game-calendar hours; a Toxic sleeper waits for its hoover
 *    instead of resolving through the boundary test.
 * No database ($GLOBALS['db'] unset): return/dismiss commands are no-ops.
 */
final class RelDynWalkawayTimersTest extends TestCase
{
    private const HOUR = RelationshipDynamics::GAMETS_PER_REAL_HOUR;          // play gamets per real hour
    private const GAME_HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;      // raw gamets per game hour
    private const PLAY = 100 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
    private const CALENDAR = 3.0e9;                                          // day 300 of the game calendar

    private $savedDb;
    private $savedRequest;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->savedRequest = $GLOBALS['gameRequest'] ?? null;
        unset($GLOBALS['db']);
        $GLOBALS['gameRequest'] = ['inputtext', time(), self::CALENDAR, 'Kaida: hello'];
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        if ($this->savedRequest !== null) $GLOBALS['gameRequest'] = $this->savedRequest; else unset($GLOBALS['gameRequest']);
        RelationshipDynamics::clearConfigCache();
    }

    private function calendarAdvance(float $gameHours): void
    {
        $GLOBALS['gameRequest'][2] += $gameHours * self::GAME_HOUR;
    }

    private function npc(): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Jealous',
            'profile_overrides' => ['attachment_style' => 'anxious'],
            '_accumulated_play_gamets' => self::PLAY,
        ]));
        $d['dimensions']['resentment']['x'] = 70.0;   // no early recovery
        $d['dimensions']['comfort']['x'] = 20.0;
        $d['dimensions']['maturity']['x'] = 45.0;
        return $d;
    }

    /** Walk an NPC into the boundary test through the state machine (no dialogue). */
    private function inBoundaryTest(array $d = null): array
    {
        $d = $d ?? $this->npc();
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        $d['_walkaway_boundary_test_hours'] = 24.0;
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');   // pending -> active
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');   // active -> boundary_test
        $this->assertSame('boundary_test', $d['_walkaway_state']);
        return $d;
    }

    public function testBoundaryTestRunsOnTheGameCalendarSoSleepingCounts(): void
    {
        $d = $this->inBoundaryTest();
        $this->assertEqualsWithDelta(self::CALENDAR, (float) $d['_boundary_test_started_calendar_gamets'], 0.001);

        $this->calendarAdvance(23);                  // a sleep: no play time at all
        $this->assertNull(RelationshipDynamics::checkBoundaryTest($d), '23 game hours: still testing');

        $this->calendarAdvance(2);                   // 25 game hours > 24
        $this->assertSame('recovery', RelationshipDynamics::checkBoundaryTest($d), 'left alone: resolves');
    }

    public function testLeftAloneTheWalkawayClearsButTheResentmentStays(): void
    {
        $d = $this->inBoundaryTest();
        $before = (float) $d['dimensions']['resentment']['x'];

        for ($i = 0; $i < 5; $i++) {                 // ticks while away: no passive decay
            $this->calendarAdvance(2);
            RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        }
        $this->assertSame('boundary_test', $d['_walkaway_state']);
        $this->assertEqualsWithDelta($before, (float) $d['dimensions']['resentment']['x'], 1e-9, 'time away does not heal');

        $this->calendarAdvance(20);                  // 30 game hours in total
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);

        $this->assertTrue($r['returned']);
        $this->assertSame('normal', $d['_walkaway_state'], 'walkaway cleared');
        $this->assertArrayNotHasKey('_boundary_test_started_calendar_gamets', $d);
        $this->assertEqualsWithDelta($before, (float) $d['dimensions']['resentment']['x'], 1e-9, 'resentment untouched');
    }

    /** Game-calendar hours of the parting conversation (walkaway_parting_game_minutes). */
    private static function partingHours(): float
    {
        return (float) RelationshipDynamics::defaultConfig()['walkaway_parting_game_minutes'] / 60.0;
    }

    public function testFollowingThemDuringTheTestIsPermanent(): void
    {
        $d = $this->inBoundaryTest();
        $this->calendarAdvance(self::partingHours() + 1);                           // they are gone
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // player seeks them out
        $this->assertTrue($d['_walkaway_player_followed']);
        $this->calendarAdvance(1);
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertSame('permanent', $d['_walkaway_state']);
        $this->assertFalse($r['returned']);
    }

    /**
     * MDD 6.4 "Follow them -> penalty doubles, permanent damage": following ends in a permanent
     * departure even when the resentment behind the walkaway was low (a walkaway reached from
     * distrust or disrespect), not in the early-recovery return.
     */
    public function testFollowingALowResentmentWalkawayIsStillPermanent(): void
    {
        $d = $this->npc();
        $d['profile_overrides']['attachment_style'] = 'secure';
        $d['dimensions']['resentment']['x'] = 20.0;   // early-recovery range (< 50) ...
        $d['dimensions']['comfort']['x'] = 50.0;      // ... with comfort > 30
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'autonomy');
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);   // pending -> active
        $this->assertSame('active', $d['_walkaway_state']);

        $this->calendarAdvance(self::partingHours() + 1);
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // player follows
        $this->assertTrue($d['_walkaway_player_followed']);
        $this->calendarAdvance(1);
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);

        $this->assertFalse($r['returned'], 'followed: no early-recovery return');
        $this->assertSame('permanent', $d['_walkaway_state']);
    }

    /**
     * Rulings §8: talking to an NPC on your return is not following a neglect walkaway. The
     * greeting that set the walkaway off and the lines right after it (a plea, a goodbye) are
     * the conversation they walked out of; left alone after that, the boundary test resolves
     * and they come back.
     */
    public function testThePartingConversationOfANeglectWalkawayIsNotPursuit(): void
    {
        $d = $this->npc();
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'neglect');
        $d['_walkaway_boundary_test_hours'] = 24.0;
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // the greeting: pending -> active
        $this->assertSame('active', $d['_walkaway_state']);

        $this->calendarAdvance(self::partingHours() / 4);
        $t = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // "wait, please"
        $this->calendarAdvance(self::partingHours() / 2);
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);        // "I'm sorry"
        $this->assertEmpty($d['_walkaway_player_followed'], 'still the parting conversation');
        $this->assertSame('boundary_test', $d['_walkaway_state']);
        $this->assertEqualsWithDelta(70.0, (float) $d['dimensions']['resentment']['x'], 1e-9, 'no pursuit penalty');

        $this->calendarAdvance(25);                                                     // then left alone
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertTrue($r['returned'], 'resolved: they come back');
        $this->assertSame('normal', $d['_walkaway_state']);
    }

    public static function inPersonReasons(): array
    {
        return ['a fight' => ['resentment'], 'jealousy' => ['jealousy'], 'the ick' => ['ick_comfort'], 'autonomy' => ['autonomy']];
    }

    /**
     * The exemption is the neglect walkaway's alone (rulings §8). An NPC walking out of an
     * argument (or over jealousy, the ick, autonomy) is followed by the first plea after they
     * left, parting window or not: MDD 6.4.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('inPersonReasons')]
    public function testAPleaAsTheyWalkOutInPersonIsPursuit(string $reason): void
    {
        $d = $this->npc();
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', $reason);
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // pending -> active: they leave
        $this->assertSame('active', $d['_walkaway_state']);

        $this->calendarAdvance(self::partingHours() / 4);
        $t = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // "wait, please"
        $this->assertTrue($d['_walkaway_player_followed'], "{$reason}: following them");
        $this->assertArrayNotHasKey('parting', $t);
        $this->assertEqualsWithDelta(100.0, (float) $d['dimensions']['resentment']['x'], 1e-9, 'penalty doubles (70 -> 100 cap)');
    }

    /** Pursuit = still seeking them after they left: the parting window has passed. */
    public function testSeekingThemAfterThePartingWindowIsPursuit(): void
    {
        $d = $this->npc();
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'neglect');
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // pending -> active
        $this->calendarAdvance(self::partingHours() + 0.1);
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);
        $this->assertTrue($d['_walkaway_player_followed']);
        $this->assertEqualsWithDelta(100.0, (float) $d['dimensions']['resentment']['x'], 1e-9, 'penalty doubles (70 -> 100 cap)');
    }

    /**
     * The boundary test counts from when they left, not from the next tick: a player who
     * left them alone and comes back after the test finds them returned, even when no scan
     * ticked in between, so the first hello on that return is not pursuit.
     */
    public function testTheBoundaryTestCountsFromWhenTheyLeft(): void
    {
        $d = $this->npc();
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        $d['_walkaway_boundary_test_hours'] = 24.0;
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);   // pending -> active
        $left = (float) $d['_walkaway_activated_calendar_gamets'];

        $this->calendarAdvance(30);                                                 // away, nobody ticked it
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);   // the return's calendar step
        $this->assertTrue($r['returned'], '30 game hours since they left > the 24 h test');
        $this->assertSame('normal', $d['_walkaway_state']);

        $d2 = $this->npc();
        RelationshipDynamics::initiateWalkaway($d2, 'Lydia', 'resentment');
        RelationshipDynamics::resolveWalkawayTick($d2, 'Lydia', 'Jealous', false);
        $this->calendarAdvance(1);
        RelationshipDynamics::resolveWalkawayTick($d2, 'Lydia', 'Jealous', false);  // active -> boundary_test
        $this->assertEqualsWithDelta((float) $d2['_walkaway_activated_calendar_gamets'],
            (float) $d2['_boundary_test_started_calendar_gamets'], 0.001, 'test clock = departure');
        $this->assertGreaterThan($left, (float) $d2['_walkaway_activated_calendar_gamets']);
    }

    /** MDD 6.6: a Toxic sleeper vanishes until its 72-96 h hoover, whatever its resentment. */
    public function testALowResentmentToxicSleeperWaitsForItsHoover(): void
    {
        $d = $this->npc();
        $d['profile_overrides']['attachment_style'] = 'toxic';
        $d['dimensions']['maturity']['x'] = 20.0;
        $d['dimensions']['resentment']['x'] = 20.0;
        $d['dimensions']['comfort']['x'] = 50.0;
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'autonomy');
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);   // pending -> active
        $this->assertSame('active', $d['_walkaway_state']);

        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);   // 0 game hours later
        $this->assertFalse($r['returned'], 'no return before the hoover');
        $this->calendarAdvance(60);
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertFalse($r['returned']);
        $this->assertSame('boundary_test', $d['_walkaway_state'], 'still gone, waiting for the hoover');
    }

    /** Early recovery still returns a non-sleeper that was left alone. */
    public function testALowResentmentWalkawayLeftAloneRecoversEarly(): void
    {
        $d = $this->npc();
        $d['profile_overrides']['attachment_style'] = 'secure';
        $d['dimensions']['resentment']['x'] = 20.0;
        $d['dimensions']['comfort']['x'] = 50.0;
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'autonomy');
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);   // pending -> active
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertTrue($r['returned'], 'not followed, not a sleeper: early recovery');
        $this->assertSame('normal', $d['_walkaway_state']);
    }

    public function testReturnedNpcDoesNotWalkStraightOutAgain(): void
    {
        $d = $this->inBoundaryTest();
        $this->calendarAdvance(25);
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertSame('normal', $d['_walkaway_state']);

        $grace = (int) RelationshipDynamics::defaultConfig()['walkaway_return_grace_contacts'];
        $this->assertGreaterThan(0, $grace);
        for ($i = 0; $i < $grace; $i++) {            // resentment still > 70: autonomy says walkaway
            RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
            $this->assertSame('normal', $d['_walkaway_state'], "contact {$i} after returning: still here");
        }
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        $this->assertSame('pending', $d['_walkaway_state'], 'grace used up and nothing was repaired');
    }

    public function testHooverSleeperIsSeventyTwoToNinetySixGameCalendarHours(): void
    {
        $d = $this->npc();
        $d['profile_overrides']['attachment_style'] = 'toxic';
        $d['dimensions']['maturity']['x'] = 30.0;
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');   // active
        $this->assertSame('active', $d['_walkaway_state']);
        $start = (float) $d['_walkaway_activated_calendar_gamets'];
        $hooverHours = RelationshipDynamics::HOOVER_MIN_HOURS
            + ((intval($start) % 100) / 100.0) * (RelationshipDynamics::HOOVER_MAX_HOURS - RelationshipDynamics::HOOVER_MIN_HOURS);

        $d['_accumulated_play_gamets'] += 200 * self::HOUR;   // play time is irrelevant
        $this->calendarAdvance($hooverHours - 1);
        $this->assertFalse(RelationshipDynamics::checkHooverEligibility($d));
        $this->calendarAdvance(2);
        $this->assertTrue(RelationshipDynamics::checkHooverEligibility($d));
    }

    public function testToxicSleeperWaitsForItsHooverInsteadOfResolving(): void
    {
        $d = $this->npc();
        $d['profile_overrides']['attachment_style'] = 'toxic';
        $d['dimensions']['maturity']['x'] = 30.0;
        $d = $this->inBoundaryTest($d);

        $this->calendarAdvance(60);                  // past the 24 h test, before the 72 h sleeper
        $this->assertNull(RelationshipDynamics::checkBoundaryTest($d), 'vanished, not resolved');
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertSame('boundary_test', $d['_walkaway_state']);

        $this->calendarAdvance(40);                  // 100 game hours since leaving
        $this->assertTrue(RelationshipDynamics::checkHooverEligibility($d));
        RelationshipDynamics::executeHoover($d, 'Lydia', 'Jealous');
        $this->assertSame('normal', $d['_walkaway_state']);
        $this->assertEqualsWithDelta(0.0, (float) $d['dimensions']['resentment']['x'], 1e-9, 'MDD 6.6 snap');
    }

    public function testHooverContextLastsFortyEightPlayHours(): void
    {
        $d = $this->npc();
        RelationshipDynamics::executeHoover($d, 'Lydia', 'Jealous');

        $this->calendarAdvance(72);
        $d['_accumulated_play_gamets'] += 1 * self::HOUR;
        $this->assertNotNull(RelationshipDynamics::getHooverContext($d, 'Lydia'), 'a 72 h sleep does not end it');

        $d['_accumulated_play_gamets'] += 48 * self::HOUR;
        $this->assertNull(RelationshipDynamics::getHooverContext($d, 'Lydia'));
    }

    public function testPlayClockStampFromTheOlderBuildIsReplacedByACalendarClock(): void
    {
        $d = $this->npc();
        // A boundary test started by the previous build: play-clock stamps only.
        $d['_walkaway_state'] = 'boundary_test';
        $d['_walkaway_boundary_test_hours'] = 24.0;
        $d['_walkaway_activated_gamets'] = self::PLAY - 5 * self::HOUR;
        $d['_boundary_test_started_gamets'] = self::PLAY - 5 * self::HOUR;

        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);

        $this->assertSame('boundary_test', $d['_walkaway_state'], 'not ended by a mixed-clock interval');
        $this->assertEqualsWithDelta(self::CALENDAR, (float) $d['_boundary_test_started_calendar_gamets'], 0.001, 'calendar clock starts now');
        $this->calendarAdvance(25);
        $this->assertSame('recovery', RelationshipDynamics::checkBoundaryTest($d));
    }
}
