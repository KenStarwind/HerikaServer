<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynWeatherGravityConfigDb
{
    public function __construct(public array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Weather emotional gravity (roadmap weather-gravity-pull; MDD 4.1 "Weather sets a Target Node,
 * current mood experiences constant pull"; chunk9 plan Feature 3): the weather no longer pushes
 * dimensions by a fixed amount every game hour. It sets a target offset per dimension (sunny
 * {warmth +5, valence +10}, overcast {warmth -3, valence -8, passion -3}, stormy {warmth -8,
 * valence -15, arousal +10}; comfort stripped) and the held offset closes 10% of its gap per game
 * hour, capped at 20: self-limiting, and it relaxes when the weather turns. Mood dimensions carry
 * it in x as a held temporary offset (taken back exactly, out of the physics and the drift);
 * passion (the effective passion) and warmth (derived) read it at display time.
 * Units: points; game hours on the game calendar.
 */
final class RelDynWeatherGravityTest extends TestCase
{
    const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    const T0 = 80 * RelationshipDynamics::GAMETS_PER_DAY + 9 * self::HOUR;

    private array $saved = [];
    private RelDynWeatherGravityConfigDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynWeatherGravityConfigDb(RelationshipDynamics::defaultConfig());
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function npc(string $weather): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['inferred_temperament' => 'Bold']));
        RelationshipDynamics::setPassion($d, 30.0);
        $d['dimensions']['comfort']['x'] = 50.0;
        $d['dimensions']['valence']['x'] = 0.0;
        $d['dimensions']['arousal']['x'] = 10.0;
        $d['_internal_weather'] = $weather;
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0);   // starts the clock
        return $d;
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x']);
    }

    public function testAStormyDayPullsTowardItsNodeAndStaysThere(): void
    {
        $d = $this->npc('stormy');
        $this->assertSame(0.0, self::x($d, 'valence'), 'the first request only starts the clock');
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + self::HOUR);
        $this->assertEqualsWithDelta(-1.5, self::x($d, 'valence'), 1e-6, '10% of the gap to -15 in one game hour');
        $this->assertEqualsWithDelta(11.0, self::x($d, 'arousal'), 1e-6);
        $this->assertEqualsWithDelta(-0.8, RelationshipDynamics::weatherGravityOffset($d, 'warmth'), 1e-6, 'warmth: read at display time');
        // A whole stormy day and then some: at the node, no further (no accumulation)
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 200 * self::HOUR);
        $this->assertEqualsWithDelta(-15.0, self::x($d, 'valence'), 1e-6);
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 400 * self::HOUR);
        $this->assertEqualsWithDelta(-15.0, self::x($d, 'valence'), 1e-6);
        $this->assertEqualsWithDelta(20.0, self::x($d, 'arousal'), 1e-6);
        $this->assertSame(50.0, self::x($d, 'comfort'), 'comfort is physical, not the weather\'s');
        // Held, not who she is: the physics and the drift read her without it
        $this->assertEqualsWithDelta(-15.0, RelationshipDynamics::heldTemporaryOffset($d, 'valence'), 1e-6);
        $this->assertEqualsWithDelta(0.0, RelationshipDynamics::driftSampleValue($d, 'valence'), 1e-6);
        // An event on top of the pull moves her from there, and the pull is still taken back exactly
        RelationshipDynamics::applyDelta('valence', $d, 10.0, 'Bold');
        $afterEvent = self::x($d, 'valence');
        $this->assertGreaterThan(-15.0, $afterEvent);
        $d['_internal_weather'] = 'clear';
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 800 * self::HOUR);
        $this->assertEqualsWithDelta($afterEvent + 15.0, self::x($d, 'valence'), 1e-3, 'the sky clears: what the storm held is given back');
        $this->assertSame([], $d['_weather_gravity']['offsets']);
        $this->assertSame([], $d['_weather_gravity']['applied']);
    }

    public function testOvercastWeighsOnTheEffectivePassionNotTheFloor(): void
    {
        $d = $this->npc('overcast');
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 100 * self::HOUR);
        $this->assertEqualsWithDelta(-3.0, RelationshipDynamics::weatherGravityOffset($d, 'passion'), 1e-3);
        $this->assertSame(30.0, RelationshipDynamics::getPassion($d), 'the earned floor is not the weather\'s');
        $this->assertEqualsWithDelta(27.0, RelationshipDynamics::getEffectivePassion($d), 1e-3);
        // Derived warmth: the overcast node's warmth column (-3) stands for the weather, once; its
        // pull on passion is not counted into the root as well (batch-Q review): sqrt(30 x 50) - 3
        $this->assertEqualsWithDelta(sqrt(30.0 * 50.0) - 3.0, RelDynPassion::warmth($d, false), 1e-3);
        // A sunny day lifts it
        $s = $this->npc('sunny');
        RelationshipDynamics::applyWeatherGravity('Tester', $s, self::T0 + 100 * self::HOUR);
        $this->assertEqualsWithDelta(sqrt(30.0 * 50.0) + 5.0, RelDynPassion::warmth($s, false), 1e-3);
        $this->assertEqualsWithDelta(10.0, self::x($s, 'valence'), 1e-3);
    }

    public function testNoTimeNoPullAndTheCapHolds(): void
    {
        $d = $this->npc('stormy');
        for ($i = 0; $i < 20; $i++) RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0);
        $this->assertSame(0.0, self::x($d, 'valence'), 'requests without game time pull nothing');
        // A table past the cap stops at max_offset
        $this->db->config['facet_appraisal']['weather_gravity'] = ['targets' => ['stormy' => ['valence' => -60.0]],
            'pull_per_game_hour' => 0.1, 'max_offset' => 20.0];
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 500 * self::HOUR);
        $this->assertEqualsWithDelta(-20.0, self::x($d, 'valence'), 1e-6);
        // At the edge of the range, x takes only what fits, and gives back only what it took
        $e = $this->npc('stormy');
        $e['dimensions']['valence']['x'] = -95.0;
        RelationshipDynamics::applyWeatherGravity('Tester', $e, self::T0 + 500 * self::HOUR);
        $this->assertEqualsWithDelta(-100.0, self::x($e, 'valence'), 1e-6);
        $this->assertEqualsWithDelta(-5.0, $e['_weather_gravity']['applied']['valence'], 1e-6);
        $e['_internal_weather'] = 'sunny';
        $this->db->config['facet_appraisal']['weather_gravity']['targets'] = ['sunny' => []];
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::applyWeatherGravity('Tester', $e, self::T0 + 1000 * self::HOUR);
        $this->assertEqualsWithDelta(-95.0, self::x($e, 'valence'), 1e-6);
    }

    public function testInternalWeatherOffLetsThePullGo(): void
    {
        $d = $this->npc('stormy');
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 100 * self::HOUR);
        $this->assertEqualsWithDelta(-15.0, self::x($d, 'valence'), 1e-3);
        $this->db->config['internal_weather_enabled'] = false;
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::applyWeatherGravity('Tester', $d, self::T0 + 300 * self::HOUR);
        $this->assertEqualsWithDelta(0.0, self::x($d, 'valence'), 1e-3, 'no weather, no pull');
        $this->assertSame([], $d['_weather_gravity']['offsets']);
        // The push table is gone: the defaults name no weather_modifiers
        $this->assertArrayNotHasKey('weather_modifiers', RelDynFacets::appraisalDefaults());
    }
}
