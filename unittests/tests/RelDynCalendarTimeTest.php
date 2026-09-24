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

    public function testNormalPlayIsCreditedInFull(): void
    {
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 600, '_accumulated_play_gamets' => 0.0];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 600 * 2000);   // a little slower than timescale 20
        $this->assertEqualsWithDelta(600 * 2000, $credited, 0.001);
    }
}
