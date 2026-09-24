<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Walkaway boundary test (MDD 6.4 "hidden real-time timer", 24-48 h) and the Hoover
 * Protocol (MDD 6.6 "72-96 hour IRL sleeper timer") are real-time timers. Per the timer
 * rules, "real time" is filtered play time (_accumulated_play_gamets, wait/sleep removed,
 * GAMETS_PER_REAL_HOUR per real hour), never the wall clock and never the raw game
 * calendar: at timescale 20, 72 calendar hours pass in 3.6 real hours, and one 48 h
 * wait or sleep would end a boundary test (permanent departure) or unlock a hoover.
 */
final class RelDynWalkawayTimersTest extends TestCase
{
    private const HOUR = RelationshipDynamics::GAMETS_PER_REAL_HOUR;
    private const GAME_HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const PLAY = 100 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;   // divisible by 100, below CALENDAR
    private const CALENDAR = 3.0e9;

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
            '_accumulated_play_gamets' => self::PLAY,
        ]));
        $d['dimensions']['resentment']['x'] = 70.0;   // no early recovery
        $d['dimensions']['comfort']['x'] = 20.0;
        return $d;
    }

    /** Walk an NPC into the boundary test through the state machine (no dialogue). */
    private function inBoundaryTest(): array
    {
        $d = $this->npc();
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        $d['_walkaway_boundary_test_hours'] = 24.0;
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');   // pending -> active
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');   // active -> boundary_test
        $this->assertSame('boundary_test', $d['_walkaway_state']);
        return $d;
    }

    public function testSleepingThroughTheBoundaryTestDoesNotEndIt(): void
    {
        $d = $this->inBoundaryTest();

        $this->calendarAdvance(48);                   // one long sleep: no play time passes
        $this->assertNull(RelationshipDynamics::checkBoundaryTest($d), 'still testing after a 48 h sleep');

        $d['_accumulated_play_gamets'] += 23 * self::HOUR;
        $this->assertNull(RelationshipDynamics::checkBoundaryTest($d));

        $d['_accumulated_play_gamets'] += 2 * self::HOUR;   // 25 real play hours > 24
        $this->assertSame('permanent', RelationshipDynamics::checkBoundaryTest($d));
    }

    public function testHooverWaitsForRealPlayHoursNotTheGameCalendar(): void
    {
        $d = $this->npc();
        $d['attachment_style'] = 'toxic';
        $d['dimensions']['maturity']['x'] = 30.0;
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');   // active
        $this->assertSame('active', $d['_walkaway_state']);

        $this->calendarAdvance(100);                  // 100 calendar hours = 5 real hours at timescale 20
        $d['_accumulated_play_gamets'] += 5 * self::HOUR;
        $this->assertFalse(RelationshipDynamics::checkHooverEligibility($d), '72-96 h is IRL, not calendar');

        $d['_accumulated_play_gamets'] += 92 * self::HOUR;   // 97 real play hours since leaving
        $this->assertTrue(RelationshipDynamics::checkHooverEligibility($d));
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

    public function testCalendarStampFromAnEarlierBuildIsReArmedNotTrusted(): void
    {
        $d = $this->inBoundaryTest();
        // A boundary test started by the previous build stored a raw calendar gamets value,
        // far ahead of this NPC's play clock.
        $d['_boundary_test_started_gamets'] = self::CALENDAR;

        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Jealous');

        $this->assertSame('boundary_test', $d['_walkaway_state'], 'not ended by a bogus interval');
        $this->assertEqualsWithDelta(self::PLAY, (float) $d['_boundary_test_started_gamets'], 0.001, 're-armed on the play clock');
    }
}
