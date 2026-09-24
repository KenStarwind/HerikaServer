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

    public function testNormalPlayIsCreditedInFull(): void
    {
        $g0 = 100 * self::DAY;
        $d = ['_last_gamets' => $g0, '_last_real_ts' => time() - 600, '_accumulated_play_gamets' => 0.0];
        $credited = RelationshipDynamics::updatePlayTime($d, $g0 + 600 * 2000);   // a little slower than timescale 20
        $this->assertEqualsWithDelta(600 * 2000, $credited, 0.001);
    }
}
