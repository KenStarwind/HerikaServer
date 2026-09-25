<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory stand-in for CHIM's $db: answers the eventlog MAX(gamets) probe used by
 * DataLastKnownGameTS() and returns "no row" for config lookups (default config).
 */
final class RelDynClockFakeDb
{
    public float $eventlogMaxGamets = 0.0;
    public array $queries = [];

    public function fetchOne($q) { $this->queries[] = $q; return null; }
    public function fetchAll($q)
    {
        $this->queries[] = $q;
        if (stripos($q, 'MAX(gamets)') !== false) {
            return $this->eventlogMaxGamets > 0 ? [['m_gts' => (string)$this->eventlogMaxGamets]] : [];
        }
        return [];
    }
    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function escape($s) { return str_replace("'", "''", (string)$s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * CHIM 3.4.1 removed the GAMETS / gamets / HERIKA_TIME globals; the live game clock is
 * $gameRequest[2]. Night and full-moon checks must read it (fixed inputs below).
 */
final class RelDynGameClockTest extends TestCase
{
    private const DAY = 10000000;          // core: 1 game day = 1e7 gamets (convert_gamets2days)
    private const HOUR = self::DAY / 24;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['gameRequest', 'db', 'GAMETS', 'gamets', 'HERIKA_TIME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = new RelDynClockFakeDb();
        RelationshipDynamics::clearConfigCache();
        // DataLastKnownGameTS() warns through Logger; keep it off the server log.
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_clock_test.log');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v[0];
            }
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    private static function at(int $day, float $hour): float
    {
        return $day * self::DAY + $hour * self::HOUR;
    }

    private static function setGameRequestGamets(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string)$gamets, 'Player: hello'];
    }

    public function testCurrentGametsReadsGameRequest(): void
    {
        self::setGameRequestGamets(58000000);
        $this->assertSame(58000000.0, RelationshipDynamics::currentGamets());
    }

    public function testCurrentGametsFallsBackToLastEventlogGamets(): void
    {
        $GLOBALS['db']->eventlogMaxGamets = 77000000;
        $this->assertSame(77000000.0, RelationshipDynamics::currentGamets());
    }

    public function testCurrentGametsIsZeroWhenUnknown(): void
    {
        $this->assertSame(0.0, RelationshipDynamics::currentGamets());
        $this->assertNull(RelationshipDynamics::gameHourOfDay());
        $this->assertFalse(RelationshipDynamics::isGameNight());
        $this->assertFalse(RelationshipDynamics::isFullMoon());
    }

    public function testGameHourOfDayMatchesCoreCalendar(): void
    {
        $g = self::at(12, 21.5);
        $this->assertEqualsWithDelta(21.5, RelationshipDynamics::gameHourOfDay($g), 0.001);
        // Same hour the core calendar renders (skyrim_start_date is midnight).
        $this->assertSame('21', date('G', gamets2timestamp($g)));
    }

    public function testNightFromLiveGameRequest(): void
    {
        self::setGameRequestGamets(self::at(3, 21));
        $this->assertTrue(RelationshipDynamics::isGameNight(), '21:00 is night');

        self::setGameRequestGamets(self::at(3, 12));
        $this->assertFalse(RelationshipDynamics::isGameNight(), '12:00 is day');
    }

    public function testNightWindowIsEightPmToFiveAm(): void
    {
        $this->assertTrue(RelationshipDynamics::isGameNight(self::at(1, 20.0)));
        $this->assertTrue(RelationshipDynamics::isGameNight(self::at(1, 0.0) + 1));
        $this->assertTrue(RelationshipDynamics::isGameNight(self::at(1, 4.99)));
        $this->assertFalse(RelationshipDynamics::isGameNight(self::at(1, 5.0)));
        $this->assertFalse(RelationshipDynamics::isGameNight(self::at(1, 19.99)));
    }

    /** Skyrim's 24-day moon cycle (RelDynCreatures::moonPhase), full on cycle days 22, 23, 0. */
    public function testFullMoonFromLiveGameRequest(): void
    {
        self::setGameRequestGamets(self::at(46, 23));
        $this->assertTrue(RelationshipDynamics::isFullMoon(), 'day 46 at 23:00 = cycle day 23');

        self::setGameRequestGamets(self::at(24, 11));
        $this->assertTrue(RelationshipDynamics::isFullMoon(), 'the last full morning: the phase turns at midday');

        self::setGameRequestGamets(self::at(4, 23));
        $this->assertFalse(RelationshipDynamics::isFullMoon(), 'the April "every 5th day" guess, not the game');

        self::setGameRequestGamets(self::at(24, 13));
        $this->assertFalse(RelationshipDynamics::isFullMoon());
    }

    public function testWerewolfGetsFullMoonModifiersFromLiveClock(): void
    {
        $dyn = ['creature_type' => 'werewolf'];

        self::setGameRequestGamets(self::at(46, 22));
        // The design row (no trait vector: the row's own valence sign)
        $this->assertSame(['arousal' => 15.0, 'coord_f' => -5.0, 'coord_m' => 10.0, 'maturity' => -10.0, 'valence' => -10.0],
            RelationshipDynamics::getCreatureModifiers('Aela', $dyn));

        self::setGameRequestGamets(self::at(2, 12));
        $this->assertSame(['arousal' => 3.0, 'maturity' => -2.0], RelationshipDynamics::getCreatureModifiers('Aela', $dyn),
            'by day the beast blood only simmers');
    }

    public function testVampireGetsNightModifiersFromLiveClock(): void
    {
        $dyn = ['creature_type' => 'vampire'];
        self::setGameRequestGamets(self::at(7, 23));
        $this->assertSame(['arousal' => 10.0, 'comfort' => 5.0, 'coord_m' => 5.0, 'self_confidence' => 10.0],
            RelationshipDynamics::getCreatureModifiers('Serana', $dyn));
    }
}
