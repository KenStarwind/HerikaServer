<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Walkaway boundary test (MDD 6.4) and Hoover sleeper (MDD 6.6) under decisions 2026-09-23 §2:
 *  - both run on the GAME CALENDAR (raw gamets; waiting and sleeping count as time apart);
 *  - leaving the NPC alone resolves the boundary test: the walkaway clears, the resentment
 *    behind it does not (time does not heal; no passive resentment decay while away);
 *  - following them during the test is still a permanent departure;
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
            'attachment_style' => 'anxious',
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

    public function testFollowingThemDuringTheTestIsPermanent(): void
    {
        $d = $this->inBoundaryTest();
        RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', true);   // player talks to them
        $this->assertTrue($d['_walkaway_player_followed']);
        $this->calendarAdvance(1);
        $r = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Jealous', false);
        $this->assertSame('permanent', $d['_walkaway_state']);
        $this->assertFalse($r['returned']);
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
        $d['attachment_style'] = 'toxic';
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
        $d['attachment_style'] = 'toxic';
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
