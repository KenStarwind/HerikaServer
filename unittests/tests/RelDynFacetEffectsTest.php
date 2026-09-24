<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php';
require_once __DIR__ . '/RelDynFacetPreferencesTest.php';   // RelDynFacetProfiles, RelDynFacetPrefsDb
require_once __DIR__ . '/RelDynFacetAppraisalTest.php';     // example vectors

/**
 * appraisal-effects / internal-weather / ambient-presence (decisions 2026-09-23 §6, MDD 1.2,
 * 1.5, 4.1): each turn in a place the appraisal nudges comfort and mood, feeds internal
 * weather both ways, builds discomfort in a hated place on the game calendar, holds a
 * passion floor in a loved place, and scales shared-activity passion (0.5x-2.0x, bad date
 * 0.7x). Real engine code throughout; the config row is the only thing the fake DB serves.
 */
final class RelDynFacetEffectsTest extends TestCase
{
    const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    const T0 = 50 * RelationshipDynamics::GAMETS_PER_DAY + 10 * self::HOUR;   // day 50, 10:00

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'RELDYN_NPC_NAME', 'HERIKA_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
        }
        unset($GLOBALS['db'], $GLOBALS['gameRequest'], $GLOBALS['RELDYN_NPC_NAME'], $GLOBALS['HERIKA_NAME']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function config(array $facetAppraisal): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->configValue = ['facet_appraisal' => $facetAppraisal];
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    private static function at(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) $gamets, 'Player: hello'];
    }

    private static function huntressPrefs(): array
    {
        $row = RelDynFacetProfiles::huntressRow();
        return RelDynFacets::derivePreferences($row, RelDynFacetProfiles::profileFor($row))['prefs'];
    }

    private static function huntress(): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['inferred_temperament'] = 'Independent';
        foreach (['comfort', 'trust', 'respect', 'maturity'] as $dim) {
            $d['dimensions'][$dim]['x'] = RelationshipDynamics::getTemperamentBaseline('Independent', $dim);
            $d['dimensions'][$dim]['baseline'] = $d['dimensions'][$dim]['x'];
        }
        return $d;
    }

    private static function dim(array $d, string $id): float
    {
        return floatval($d['dimensions'][$id]['x'] ?? 0);
    }

    // ---------------------------------------------------------------- comfort / mood

    public function testLovedPlaceRaisesComfortAndMoodHatedPlaceLowersThem(): void
    {
        $t1 = self::T0 + self::HOUR;
        $forest = self::huntress();
        $c0 = self::dim($forest, 'comfort');
        self::at(self::T0);
        $r = RelDynFacets::placeTurn('Aela', $forest, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, self::huntressPrefs(), self::T0);
        $this->assertGreaterThan(0.7, $r['appraisal']['valence']);
        $this->assertSame($c0, self::dim($forest, 'comfort'), 'arriving is not yet time spent there');
        self::at($t1);
        RelDynFacets::placeTurn('Aela', $forest, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, self::huntressPrefs(), $t1);
        $this->assertGreaterThan($c0, self::dim($forest, 'comfort'));
        $this->assertGreaterThan(0.0, self::dim($forest, 'valence'), 'mood lifts');

        $library = self::huntress();
        self::at(self::T0);
        RelDynFacets::placeTurn('Aela', $library, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, self::huntressPrefs(), self::T0);
        self::at($t1);
        RelDynFacets::placeTurn('Aela', $library, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, self::huntressPrefs(), $t1);
        $this->assertLessThan($c0, self::dim($library, 'comfort'));
        $this->assertLessThan(0.0, self::dim($library, 'valence'), 'mood sinks');
        $this->assertSame('The Arcanaeum', $library['_place_appraisal']['place']);
        $this->assertSame(-1, $library['_place_appraisal']['dominant_sign']);
    }

    /**
     * internal-weather review 2026-09-24: 30 lines about 5 game seconds apart in the Arcanaeum
     * turned Aela stormy (pressure -0.96) with mood -22.7 and comfort -9.7. Comfort, mood and
     * weather pressure follow the game hours spent in the place, not the lines spoken; so does
     * the weather's emotional gravity.
     */
    public function testTalkingALotWithoutGameTimeMovesNothing(): void
    {
        $this->config(['weather_roll_amplitude' => 0.0]);
        $prefs = self::huntressPrefs();
        $d = self::huntress();
        $d['_internal_weather'] = 'clear';
        $c0 = self::dim($d, 'comfort');
        $gameSecond = RelationshipDynamics::GAMETS_PER_DAY / 86400;
        $t = self::T0;
        for ($i = 0; $i < 30; $i++, $t += 5 * $gameSecond) {
            self::at($t);
            RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t);
            RelationshipDynamics::applyWeatherModifiers('Aela', $d, 'Independent', $t);
        }
        $this->assertSame('clear', RelDynFacets::updateWeather('Aela', $d, $prefs, $t));
        $this->assertGreaterThan(-0.02, $d['_weather_state']['pressure'], '2.4 game minutes of library');
        $this->assertGreaterThan($c0 - 0.1, self::dim($d, 'comfort'));
        $this->assertGreaterThan(-0.3, self::dim($d, 'valence'));
    }

    public function testDimensionEngineOffLeavesComfortAndMoodAlone(): void
    {
        $this->config([]);
        $GLOBALS['db']->configValue += ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'dimension_engine_enabled' => false];
        RelationshipDynamics::clearConfigCache();
        self::at(self::T0);
        $d = self::huntress();
        $c0 = self::dim($d, 'comfort');
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, self::huntressPrefs(), self::T0);
        $this->assertSame($c0, self::dim($d, 'comfort'));
        $this->assertSame(0.0, self::dim($d, 'valence'));
    }

    // ---------------------------------------------------------------- sustained discomfort

    public function testDiscomfortBuildsOnTheGameClockInAHatedPlaceNotPerTurn(): void
    {
        $d = self::huntress();
        $prefs = self::huntressPrefs();
        $t = self::T0;
        self::at($t);
        $r = RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t);
        $v = abs($r['appraisal']['valence']);
        $this->assertSame(0.0, $d['_place_discomfort']['points'], 'the first turn starts the exposure');

        for ($i = 0; $i < 5; $i++) {   // talking a lot without game time passing adds nothing
            RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t);
        }
        $this->assertSame(0.0, $d['_place_discomfort']['points']);

        $t += 2 * self::HOUR;
        self::at($t);
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t);
        $perHour = RelDynFacets::appraisalDefaults()['discomfort_per_game_hour'];
        $this->assertEqualsWithDelta(2 * $perHour * $v, $d['_place_discomfort']['points'], 1e-6);

        // a long gap is credited only up to exposure_max_gap_game_hours
        $before = $d['_place_discomfort']['points'];
        $t += 48 * self::HOUR;
        self::at($t);
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t);
        $maxGap = RelDynFacets::appraisalDefaults()['exposure_max_gap_game_hours'];
        $this->assertEqualsWithDelta(min(100.0, $before + $maxGap * $perHour * $v), $d['_place_discomfort']['points'], 1e-6);
    }

    public function testHighDiscomfortDrainsComfortAndAddsToTheFeltRead(): void
    {
        $d = self::huntress();
        $prefs = self::huntressPrefs();
        $t1 = self::T0 + self::HOUR;
        $plain = self::huntress();
        self::at(self::T0);
        RelDynFacets::placeTurn('Aela', $plain, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);
        $d['_place_discomfort'] = ['place' => 'The Arcanaeum', 'points' => 80.0, 'gamets' => self::T0];
        self::at($t1);
        RelDynFacets::placeTurn('Aela', $plain, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t1);
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, $t1);
        $this->assertLessThan(self::dim($plain, 'comfort'), self::dim($d, 'comfort'), 'discomfort drains comfort on top');

        $text = RelDynFacets::placeFeltText('Aela', $d, $t1);
        $this->assertStringContainsString('restless', $text);
        $this->assertStringContainsString('wearing on them', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);
        $this->assertStringNotContainsString('wearing on them', RelDynFacets::placeFeltText('Aela', $plain, $t1));
    }

    public function testLeavingAHatedPlaceRelievesDiscomfort(): void
    {
        $d = self::huntress();
        $d['_place_discomfort'] = ['place' => 'The Arcanaeum', 'points' => 60.0, 'gamets' => self::T0];
        $t = self::T0 + 2 * self::HOUR;
        self::at($t);
        RelDynFacets::placeTurn('Aela', $d, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, self::huntressPrefs(), $t);
        $relief = RelDynFacets::appraisalDefaults()['discomfort_relief_per_game_hour'];
        $this->assertEqualsWithDelta(max(0.0, 60.0 - 2 * $relief), $d['_place_discomfort']['points'], 1e-6);
        $this->assertSame('Fallowstone Woods', $d['_place_discomfort']['place']);
    }

    // ---------------------------------------------------------------- internal weather

    public function testWeatherIsFedByLovedThingsAndDrainedByHatedOnes(): void
    {
        $this->config(['weather_roll_amplitude' => 0.0]);
        $prefs = self::huntressPrefs();
        $t = self::T0;
        self::at($t);

        // hours spent in a place, one turn an hour (MDD 4.1: a background, daily weather)
        $stay = function (array &$d, string $place, array $facets, int $hours) use (&$t, $prefs) {
            for ($i = 0; $i <= $hours; $i++) {
                if ($i > 0) $t += self::HOUR;
                self::at($t);
                RelDynFacets::placeTurn('Aela', $d, $place, $facets, $prefs, $t);
            }
        };
        $start = $t;
        $happy = self::huntress();
        $stay($happy, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, 12);
        $this->assertSame('sunny', RelDynFacets::updateWeather('Aela', $happy, $prefs, $t), 'half a day in the woods');

        $t = $start;
        $sad = self::huntress();
        $sad['_internal_weather'] = 'stormy';
        $stay($sad, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, 12);
        $weather = RelDynFacets::updateWeather('Aela', $sad, $prefs, $t);
        $this->assertContains($weather, ['overcast', 'stormy'], 'half a day in the library');
        $this->assertLessThan(0.0, $sad['_weather_state']['pressure']);

        // not stuck: loved things lift a stormy NPC, and pressure relaxes over game time
        $stay($sad, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, 18);
        $this->assertSame('sunny', RelDynFacets::updateWeather('Aela', $sad, $prefs, $t));
        $later = $t + 20 * self::HOUR;   // well past the half-life, still inside the deprivation grace
        self::at($later);
        RelDynFacets::placeTurn('Aela', $sad, 'Whiterun', ['crowd' => 0.2], $prefs, $later);   // feeds nothing loved
        $this->assertSame('clear', RelDynFacets::updateWeather('Aela', $sad, $prefs, $later));
    }

    public function testDeprivationSkewsTowardOvercastAndCombatFeedsIt(): void
    {
        $this->config(['weather_roll_amplitude' => 0.0]);
        $prefs = self::huntressPrefs();   // loves nature and combat
        $d = self::huntress();
        $t = self::T0;
        self::at($t);
        $this->assertSame('clear', RelDynFacets::updateWeather('Aela', $d, $prefs, $t), 'fresh NPC: nothing unfed yet');

        $t += 4 * RelationshipDynamics::GAMETS_PER_DAY;   // MDD 4.1: 3+ days without what she loves
        self::at($t);
        $weather = RelDynFacets::updateWeather('Aela', $d, $prefs, $t);
        $this->assertContains($weather, ['overcast', 'stormy']);
        $this->assertEqualsWithDelta(1.0, $d['_weather_state']['deprivation'], 1e-9);

        // a fight together feeds combat (and danger); nature still starves
        RelDynFacets::experienceThing('Aela', $d, 'activity', 'combat', $prefs, $t);
        $this->assertSame($t, $d['_facet_fed']['combat']);
        RelDynFacets::updateWeather('Aela', $d, $prefs, $t);
        $this->assertLessThan(1.0, $d['_weather_state']['deprivation']);
        $this->assertGreaterThan(0.0, $d['_weather_state']['deprivation']);
    }

    public function testDailyRollIsDeterministicPerNpcAndGameDay(): void
    {
        $prefs = RelDynFacets::neutralPreferences();
        $a = self::huntress();
        $b = self::huntress();
        self::at(self::T0);
        RelDynFacets::updateWeather('Aela', $a, $prefs, self::T0);
        RelDynFacets::updateWeather('Aela', $b, $prefs, self::T0 + 3 * self::HOUR);   // same game day
        $this->assertSame($a['_weather_state']['roll'], $b['_weather_state']['roll']);
        $amp = RelDynFacets::appraisalDefaults()['weather_roll_amplitude'];
        $rolls = [];
        for ($day = 0; $day < 20; $day++) {
            $d = self::huntress();
            RelDynFacets::updateWeather('Aela', $d, $prefs, self::T0 + $day * RelationshipDynamics::GAMETS_PER_DAY);
            $rolls[] = $d['_weather_state']['roll'];
            $this->assertLessThanOrEqual($amp, abs($d['_weather_state']['roll']));
        }
        $this->assertGreaterThan(5, count(array_unique(array_map(fn($r) => round($r, 4), $rolls))), 'the roll changes day to day');
    }

    /** Emotional gravity (MDD 4.1: a constant pull) works on the game clock, not per request. */
    public function testWeatherModifiersComeFromConfigAndPullPerGameHour(): void
    {
        $this->config(['weather_modifiers' => ['sunny' => ['comfort' => 10]], 'weather_modifier_per_game_hour' => 1.0]);
        $d = self::huntress();
        $d['_internal_weather'] = 'sunny';
        $c0 = self::dim($d, 'comfort');
        for ($i = 0; $i < 10; $i++) {
            RelationshipDynamics::applyWeatherModifiers('Aela', $d, 'Independent', self::T0);
        }
        $this->assertSame($c0, self::dim($d, 'comfort'), 'requests without game time pull nothing');
        RelationshipDynamics::applyWeatherModifiers('Aela', $d, 'Independent', self::T0 + self::HOUR);
        $oneHour = self::dim($d, 'comfort') - $c0;
        $this->assertGreaterThan(0.0, $oneHour);
        $e = self::huntress();
        $e['_internal_weather'] = 'sunny';
        RelationshipDynamics::applyWeatherModifiers('Aela', $e, 'Independent', self::T0);
        RelationshipDynamics::applyWeatherModifiers('Aela', $e, 'Independent', self::T0 + 50 * self::HOUR);
        $maxGap = RelDynFacets::appraisalDefaults()['exposure_max_gap_game_hours'];
        $this->assertLessThanOrEqual($maxGap * $oneHour + 1e-6, self::dim($e, 'comfort') - $c0, 'a long gap counts only up to the cap');

        $d2 = self::huntress();
        $d2['_internal_weather'] = 'stormy';   // the stored table has no stormy row
        RelationshipDynamics::applyWeatherModifiers('Aela', $d2, 'Independent', self::T0);
        RelationshipDynamics::applyWeatherModifiers('Aela', $d2, 'Independent', self::T0 + self::HOUR);
        $this->assertSame(self::dim(self::huntress(), 'comfort'), self::dim($d2, 'comfort'));
    }

    // ---------------------------------------------------------------- shared-activity passion

    public function testSharedActivityPassionFollowsThePlaceWithinTheMddRange(): void
    {
        $prefs = self::huntressPrefs();
        self::at(self::T0);
        $forest = self::huntress();
        $r = RelDynFacets::placeTurn('Aela', $forest, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0);
        $v = $r['appraisal']['valence'];
        $time = RelDynFacets::sharedActivityPassionMult($forest, RelationshipDynamics::LL_TIME, null, self::T0);
        $this->assertEqualsWithDelta(RelDynFacets::interestMultiplier($v), $time, 1e-9, 'quality time: full weight');
        $gifts = RelDynFacets::sharedActivityPassionMult($forest, RelationshipDynamics::LL_GIFTS, null, self::T0);
        $this->assertEqualsWithDelta(1 + (RelDynFacets::interestMultiplier($v) - 1) * 0.8, $gifts, 1e-9, 'gifts: 80%');

        $library = self::huntress();
        $r = RelDynFacets::placeTurn('Aela', $library, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);
        $bad = RelDynFacets::sharedActivityPassionMult($library, RelationshipDynamics::LL_TIME, null, self::T0);
        $this->assertEqualsWithDelta(RelDynFacets::interestMultiplier($r['appraisal']['valence']) * 0.7, $bad, 1e-9, 'MDD 1.5 bad date 0.7x');
        $this->assertLessThan(0.6, $bad, 'low passion + bad location = near-zero gains');

        // a gift she loves in a place she hates: the item decides the interest, the place the bad date
        $item = RelDynFacets::appraise($prefs, ['nature' => 1.0]);
        $this->assertEqualsWithDelta((1 + (RelDynFacets::interestMultiplier($item['valence']) - 1) * 0.8) * 0.7,
            RelDynFacets::sharedActivityPassionMult($library, RelationshipDynamics::LL_GIFTS, $item, self::T0), 1e-9);

        foreach ([[-1.0, 0.5 * 0.7], [1.0, 2.0]] as [$p, $expect]) {
            $d = self::huntress();
            $one = RelDynFacets::neutralPreferences();
            $one['confined'] = $p;
            RelDynFacets::placeTurn('Aela', $d, 'x', ['confined' => 1.0], $one, self::T0);
            $this->assertEqualsWithDelta($expect, RelDynFacets::sharedActivityPassionMult($d, RelationshipDynamics::LL_TIME, null, self::T0), 1e-9);
        }
    }

    public function testStaleOrMissingPlaceReadIsNeutral(): void
    {
        $d = self::huntress();
        $this->assertSame(1.0, RelDynFacets::sharedActivityPassionMult($d, RelationshipDynamics::LL_TIME, null, self::T0));
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, self::huntressPrefs(), self::T0);
        $later = self::T0 + 3 * self::HOUR;   // past place_appraisal_max_age_game_hours
        $this->assertSame(1.0, RelDynFacets::sharedActivityPassionMult($d, RelationshipDynamics::LL_TIME, null, $later));
        $this->assertNull(RelDynFacets::placeFeltText('Aela', $d, $later));
    }

    public function testCalculatePassionGainAndEvalPassionUseThePlace(): void
    {
        $prefs = self::huntressPrefs();
        self::at(self::T0);
        $forest = self::huntress();
        $library = self::huntress();
        RelDynFacets::placeTurn('Aela', $forest, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0);
        RelDynFacets::placeTurn('Aela', $library, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);

        $gF = RelationshipDynamics::calculatePassionGain($forest, RelationshipDynamics::LL_TIME);
        $gL = RelationshipDynamics::calculatePassionGain($library, RelationshipDynamics::LL_TIME);
        $ratio = RelDynFacets::sharedActivityPassionMult($forest, RelationshipDynamics::LL_TIME, null, self::T0)
               / RelDynFacets::sharedActivityPassionMult($library, RelationshipDynamics::LL_TIME, null, self::T0);
        $this->assertEqualsWithDelta($ratio, $gF / $gL, 1e-6);

        $pF = RelationshipDynamics::applyEvalSignal('Aela', $forest, 'passion', 5.0, ['quality_time'], 1.0);
        $pL = RelationshipDynamics::applyEvalSignal('Aela', $library, 'passion', 5.0, ['quality_time'], 1.0);
        $this->assertGreaterThan($pL['actual'] * 2, $pF['actual'], 'the eval passion gain carries the place too');
        $this->assertStringContainsString('place x', $pL['line']);
    }

    // ---------------------------------------------------------------- ambient presence (MDD 1.5)

    public function testLovedPlaceHoldsAPassionFloorOnThePlayClockAndHaltsDecay(): void
    {
        $prefs = self::huntressPrefs();
        $d = self::huntress();
        $d['_accumulated_play_gamets'] = 1000.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        self::at(self::T0);
        RelDynFacets::placeTurn('Aela', $d, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0);
        $floor = RelDynFacets::appraisalDefaults()['poi_passion_floor'];
        $this->assertSame((float) $floor, RelDynFacets::poiPassionFloor($d, self::T0));
        $this->assertSame(0.0, RelationshipDynamics::getPassion($d), 'no jump: the floor is approached on the play clock');

        // 10 real play minutes later (filtered play clock), still there
        $d['_accumulated_play_gamets'] += 600 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        RelDynFacets::placeTurn('Aela', $d, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0);
        $rate = RelDynFacets::appraisalDefaults()['poi_rise_per_play_minute'];
        $this->assertEqualsWithDelta(min($floor, 10 * $rate), RelationshipDynamics::getPassion($d), 1e-6);

        // a long stay tops out at the floor, never above
        $d['_accumulated_play_gamets'] += 3600 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        RelDynFacets::placeTurn('Aela', $d, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0);
        $this->assertEqualsWithDelta($floor, RelationshipDynamics::getPassion($d), 1e-6);

        // in-contact decay halts while the floor holds
        RelationshipDynamics::setPassion($d, 30.0);
        $d['passion_updated_at'] = RelationshipDynamics::getPlayGamets($d);
        $d['_accumulated_play_gamets'] += 300 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        RelationshipDynamics::decayPassion($d);
        $this->assertSame(30.0, RelationshipDynamics::getPassion($d));

        // leaving for a hated place clears the floor; decay resumes
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);
        $this->assertNull(RelDynFacets::poiPassionFloor($d, self::T0));
        $d['_accumulated_play_gamets'] += 300 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        RelationshipDynamics::decayPassion($d);
        $this->assertLessThan(30.0, RelationshipDynamics::getPassion($d));
    }
}
