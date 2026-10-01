<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** In-memory $db: the config row, the newest info_survival row, the newest location row; nothing else. */
final class RelDynSurvivalReportFakeDb
{
    public array $config = [];
    public ?array $report = null;      // ['data' => json, 'gamets' => int]
    public ?array $location = null;    // ['data' => '(Context location: ...)', 'gamets' => int]
    public array $queries = [];

    public function fetchOne($q, array $params = [])
    {
        $this->queries[] = $q;
        if (str_contains($q, RelationshipDynamics::CONFIG_ROW_ID)) {
            return ['value' => json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA] + $this->config)];
        }
        if (str_contains($q, "type = 'info_survival'")) return $this->report ?? [];
        if (str_contains($q, "type IN ('infoloc','location','request')")) return $this->location ?? [];
        return [];
    }
    public function fetchAll($q, $log = false) { $this->queries[] = $q; return []; }
    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * The survival reporter's consumer (RelDynSurvival, review queue 2026-09-30 answered by Ken 2026-10-01 §21):
 * the inert physical-state rows (hungry, warm fire, rested, exhausted, dirty, bloody) become live when the
 * AIAgent fork's info_survival report is present and fresh, and stay inert otherwise. No LLM, no network.
 */
final class RelDynSurvivalReportTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;

    private array $saved = [];
    private RelDynSurvivalReportFakeDb $db;
    private $prevErrorLog;
    private $savedAssignment;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynSurvivalReportFakeDb();
        $GLOBALS['db'] = $this->db;
        $this->savedAssignment = RelDynTraits::$assignmentOverride;
        RelDynTraits::$assignmentOverride = 'label';
        RelationshipDynamics::clearConfigCache();
        $this->prevErrorLog = ini_set('error_log', sys_get_temp_dir() . '/reldyn_survival_report_test.log');
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_survival_report_test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        RelDynTraits::$assignmentOverride = $this->savedAssignment;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    /** A report the way the plugin writes it (SurvivalReportPolicy::BuildReport), with $over laid over a quiet default. */
    private function report(array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1,
            'mods' => ['lastseed', 'frostfall', 'campfire', 'dirtandblood'],
            'player' => ['hunger' => 0, 'thirst' => 0, 'fatigue' => 1, 'exposure' => 1, 'wet' => 0, 'dirt' => 0, 'blood' => 0],
            'fire' => ['near' => false, 'heat' => 0, 'builder' => 'other'],
            'actors' => [
                'Aela the Huntress' => ['follower' => true, 'd' => 150],
                'Hadvar' => ['follower' => false, 'd' => 400],
            ],
        ], $over);
    }

    private function send(array $report, int $gamets): void
    {
        $this->db->report = ['data' => json_encode($report), 'gamets' => $gamets];
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) $gamets, 'Kaida: hello'];
    }

    private function states(string $npc, array $over = []): array
    {
        return RelDynSurvival::statesFor($npc, $this->report($over));
    }

    // ------------------------------------------------------------------ inert without a report

    public function testNoReportIsUnknownNeverAssumed(): void
    {
        $this->assertSame([], RelDynSurvival::statesFor('Aela the Huntress', null));
        $this->assertNull(RelDynSurvival::latest());
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::at(60, 12.0), 'Kaida: hello'];
        $GLOBALS['CACHE_PEOPLE'] = '|Aela the Huntress|Kaida|';
        $this->assertNotContains('hungry', RelationshipDynamics::detectPhysicalStates('Aela the Huntress', 'Kaida'));
        foreach (['hungry', 'warm_fire', 'warm_fire_other', 'well_rested', 'exhausted', 'dirty', 'bloody', 'wet'] as $s) {
            $this->assertNotContains($s, RelationshipDynamics::detectPhysicalStates('Aela the Huntress', 'Kaida'), $s);
        }
    }

    public function testTheInertListStaysTheStatesCoreCannotSee(): void
    {
        $inert = RelationshipDynamics::defaultConfig()['physical_states']['inert'];
        $this->assertEqualsCanonicalizing(['hungry', 'warm_fire', 'well_rested', 'exhausted', 'dirty', 'bloody'], $inert);
        foreach (['hungry', 'warm_fire', 'warm_fire_other', 'well_rested', 'exhausted', 'dirty', 'bloody', 'wet'] as $state) {
            $this->assertArrayHasKey($state, RelationshipDynamics::PHYSICAL_STATE_MODIFIERS, "{$state} has a row");
        }
        $cfg = RelationshipDynamics::defaultConfig()['physical_states']['survival'];
        $this->assertSame(RelDynSurvival::configDefaults(), $cfg);
        foreach (['max_age_game_hours', 'hungry_level', 'thirsty_level', 'exhausted_level', 'well_rested_level', 'cold_level',
                     'wet_level', 'dirty_level', 'bloody_level', 'fire_max_distance'] as $key) {
            $this->assertArrayHasKey($key, $cfg, $key);
        }
        // a saved physical_states that predates the survival table still gets its defaults
        $this->db->config = ['physical_states' => ['injured_health_ratio' => 0.4]];
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(RelDynSurvival::configDefaults(), RelDynSurvival::config());
        // and one that stores a key of it keeps the rest
        $this->db->config = ['physical_states' => ['survival' => ['hungry_level' => 3]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(3, RelDynSurvival::config()['hungry_level']);
        $this->assertSame(RelDynSurvival::configDefaults()['dirty_level'], RelDynSurvival::config()['dirty_level']);
    }

    // ------------------------------------------------------------------ hunger and thirst

    public function testHungerIsTheirOwnWhenTrackedElseTheFollowersPartys(): void
    {
        // the party is Hungry (Last Seed level 2): a follower is hungry, a bystander is not (the player's hunger is not theirs)
        $over = ['player' => ['hunger' => 2]];
        $this->assertContains('hungry', $this->states('Aela the Huntress', $over));
        $this->assertNotContains('hungry', $this->states('Hadvar', $over));
        $this->assertNotContains('hungry', $this->states('Nobody In The Report', $over));
        // Satisfied is not hungry
        $this->assertNotContains('hungry', $this->states('Aela the Huntress', ['player' => ['hunger' => 1]]));
        // their own level wins over the party's, both ways
        $this->assertNotContains('hungry', $this->states('Aela the Huntress', ['player' => ['hunger' => 5],
            'actors' => ['Aela the Huntress' => ['hunger' => 0]]]));
        $this->assertContains('hungry', $this->states('Hadvar', ['player' => ['hunger' => 0],
            'actors' => ['Hadvar' => ['hunger' => 3]]]), 'a tracked bystander is hungry by their own level');
        // thirst alone is enough
        $this->assertContains('hungry', $this->states('Aela the Huntress', ['player' => ['thirst' => 3]]));
        $this->assertContains('hungry', $this->states('Hadvar', ['actors' => ['Hadvar' => ['thirst' => 2]]]));
        // the CC's hunger stage counts for the party while the mode is on, and not while it is off
        $this->assertContains('hungry', $this->states('Aela the Huntress', ['player' => ['cc' => ['on' => true, 'hunger' => 3]]]));
        $this->assertNotContains('hungry', $this->states('Aela the Huntress', ['player' => ['cc' => ['on' => false, 'hunger' => 3]]]));
        // a mod that is not there says nothing: no hunger key is no hunger state
        $quiet = $this->report();
        unset($quiet['player']['hunger'], $quiet['player']['thirst']);
        $this->assertNotContains('hungry', RelDynSurvival::statesFor('Aela the Huntress', $quiet));
        // the threshold is config
        $cfg = ['hungry_level' => 4] + RelDynSurvival::configDefaults();
        $this->assertNotContains('hungry', RelDynSurvival::statesFor('Aela the Huntress', $this->report(['player' => ['hunger' => 3]]), $cfg));
        $this->assertContains('hungry', RelDynSurvival::statesFor('Aela the Huntress', $this->report(['player' => ['hunger' => 4]]), $cfg));
    }

    public function testHungerIsTheDesignsMaturityDropAndSomeDiscomfort(): void
    {
        $row = RelationshipDynamics::PHYSICAL_STATE_MODIFIERS['hungry'];
        $this->assertSame(-3, $row['maturity'], 'the design: maturity -3');
        $this->assertLessThan(0, $row['comfort']);
        $this->assertArrayNotHasKey('warmth', $row);
    }

    // ------------------------------------------------------------------ rest

    public function testRestAndExhaustionAreThePartysForAFollower(): void
    {
        $this->assertContains('exhausted', $this->states('Aela the Huntress', ['player' => ['fatigue' => 3]]));
        $this->assertContains('exhausted', $this->states('Aela the Huntress', ['player' => ['fatigue' => 5]]));
        $this->assertNotContains('exhausted', $this->states('Aela the Huntress', ['player' => ['fatigue' => 2]]), 'Tired is not yet exhausted');
        $this->assertContains('well_rested', $this->states('Aela the Huntress', ['player' => ['fatigue' => 0]]));
        $this->assertNotContains('well_rested', $this->states('Aela the Huntress', ['player' => ['fatigue' => 1]]));
        $this->assertNotContains('exhausted', $this->states('Aela the Huntress', ['player' => ['fatigue' => 0]]));
        // the player's tiredness is not a bystander's
        $this->assertNotContains('exhausted', $this->states('Hadvar', ['player' => ['fatigue' => 5]]));
        $this->assertNotContains('well_rested', $this->states('Hadvar', ['player' => ['fatigue' => 0]]));
        // the CC: an exhaustion stage counts as tired, but stage 0 is only "no need", never "rested"
        $this->assertContains('exhausted', $this->states('Aela the Huntress', ['player' => ['fatigue' => 0, 'cc' => ['on' => true, 'exhaustion' => 4]]]));
        $noLastSeed = $this->report(['player' => ['cc' => ['on' => true, 'exhaustion' => 0]]]);
        unset($noLastSeed['player']['fatigue']);
        $this->assertNotContains('well_rested', RelDynSurvival::statesFor('Aela the Huntress', $noLastSeed));
        // rested needs the CC to agree when both speak
        $this->assertNotContains('well_rested', $this->states('Aela the Huntress', ['player' => ['fatigue' => 0, 'cc' => ['on' => true, 'exhaustion' => 2]]]));
    }

    public function testColdAndWetFromFrostfallForTheParty(): void
    {
        $this->assertContains('cold', $this->states('Aela the Huntress', ['player' => ['exposure' => 2]]));
        $this->assertNotContains('cold', $this->states('Aela the Huntress', ['player' => ['exposure' => 1]]));
        $this->assertNotContains('cold', $this->states('Hadvar', ['player' => ['exposure' => 5]]), 'the player\'s chill is not a bystander\'s');
        $this->assertContains('cold', $this->states('Aela the Huntress', ['player' => ['exposure' => 0, 'cc' => ['on' => true, 'cold' => 3]]]));
        $this->assertContains('wet', $this->states('Aela the Huntress', ['player' => ['wet' => 2]]));
        $this->assertNotContains('wet', $this->states('Aela the Huntress', ['player' => ['wet' => 1]]), 'Damp is not wet');
    }

    // ------------------------------------------------------------------ fire

    public function testTheFiresWarmthGoesToWhoeverBuiltIt(): void
    {
        $lit = fn(string $builder) => ['fire' => ['near' => true, 'heat' => 2, 'dist' => 200, 'builder' => $builder]];
        $this->assertContains('warm_fire', $this->states('Aela the Huntress', $lit('player')));
        $this->assertNotContains('warm_fire_other', $this->states('Aela the Huntress', $lit('player')));
        $this->assertContains('warm_fire_other', $this->states('Aela the Huntress', $lit('other')));
        $this->assertNotContains('warm_fire', $this->states('Aela the Huntress', $lit('other')));
        // not lit / not near the player: nothing
        $this->assertSame([], array_intersect(['warm_fire', 'warm_fire_other'], $this->states('Aela the Huntress', ['fire' => ['near' => false]])));
        // an NPC out of reach of the fire (the report's distance from the player), or unlisted: unknown
        $far = $this->states('Hadvar', $lit('player') + ['actors' => ['Hadvar' => ['d' => 900]]]);
        $this->assertNotContains('warm_fire', $far);
        $this->assertNotContains('warm_fire', $this->states('Nobody In The Report', $lit('player')));
        // a bystander in reach is warmed too (anyone at the fire)
        $this->assertContains('warm_fire', $this->states('Hadvar', $lit('player')));
        // no fire block at all (no Frostfall): unknown
        $none = $this->report();
        unset($none['fire']);
        $this->assertSame([], array_intersect(['warm_fire', 'warm_fire_other'], RelDynSurvival::statesFor('Aela the Huntress', $none)));

        // The rows: the player's fire warms the bond (warmth and passion), someone else's only the room
        $full = RelationshipDynamics::PHYSICAL_STATE_MODIFIERS['warm_fire'];
        $other = RelationshipDynamics::PHYSICAL_STATE_MODIFIERS['warm_fire_other'];
        $this->assertArrayHasKey('warmth', $full);
        $this->assertArrayHasKey('passion', $full);
        $this->assertSame(['comfort'], array_keys($other));
        $this->assertSame($full['comfort'], $other['comfort']);
    }

    // ------------------------------------------------------------------ dirt and blood

    public function testDirtAndBloodAsTheNpcSeesThePlayerOrTheirOwn(): void
    {
        $this->assertContains('dirty', $this->states('Hadvar', ['player' => ['dirt' => 3]]), 'anyone sees the player\'s grime');
        $this->assertNotContains('dirty', $this->states('Hadvar', ['player' => ['dirt' => 2]]));
        $this->assertContains('dirty', $this->states('Hadvar', ['actors' => ['Hadvar' => ['dirty' => true]]]), 'their own mark');
        $this->assertNotContains('dirty', $this->states('Hadvar', ['actors' => ['Hadvar' => ['dirty' => false]]]));
        $this->assertContains('bloody', $this->states('Hadvar', ['player' => ['blood' => 2]]));
        $this->assertNotContains('bloody', $this->states('Hadvar', ['player' => ['blood' => 1]]));
        $this->assertContains('bloody', $this->states('Aela the Huntress', ['actors' => ['Aela the Huntress' => ['bloody' => true]]]));
        // no Dirt and Blood: no key, no state
        $none = $this->report();
        unset($none['player']['dirt'], $none['player']['blood']);
        $this->assertSame([], array_intersect(['dirty', 'bloody'], RelDynSurvival::statesFor('Hadvar', $none)));
    }

    public function testDirtyCostsRespectOnlyWhereThereIsPride(): void
    {
        // pride is the NPC's own trait (Pd 0.5 or more), not a label: the existing gate, now reachable
        $hit = [];
        foreach (['Proud', 'Humble'] as $label) {
            $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
            $d['inferred_temperament'] = $label;
            RelationshipDynamics::applyPhysicalStateModifiers($d, ['dirty'], $label);
            $applied = $d['_applied_physical_deltas']['dirty'] ?? [];
            $this->assertArrayHasKey('comfort', $applied, "{$label}: grime is uncomfortable for anyone");
            $hit[$label] = isset($applied['respect']);
        }
        $this->assertTrue($hit['Proud']);
        $this->assertFalse($hit['Humble']);
    }

    // ------------------------------------------------------------------ the report in the eventlog

    public function testTheLatestReportIsReadFromTheEventlogAndGoesStaleOnTheGameClock(): void
    {
        $t = self::at(60, 12.0);
        $this->send($this->report(['player' => ['hunger' => 3]]), $t);
        $r = RelDynSurvival::latest();
        $this->assertIsArray($r);
        $this->assertSame(3, $r['player']['hunger']);
        // three game hours on is the limit (config max_age_game_hours); past it the report is unknown
        $GLOBALS['gameRequest'][2] = (string) ($t + 3 * (int) self::HOUR);
        $this->assertIsArray(RelDynSurvival::latest());
        $GLOBALS['gameRequest'][2] = (string) ($t + 3 * (int) self::HOUR + 1000);
        $this->assertNull(RelDynSurvival::latest());
        $this->assertSame([], RelDynSurvival::statesFor('Aela the Huntress', RelDynSurvival::latest()));
        // the limit is config
        $this->db->config = ['physical_states' => ['survival' => ['max_age_game_hours' => 12.0]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertIsArray(RelDynSurvival::latest());
    }

    public function testAnUnreadableReportIsUnknownAndLoud(): void
    {
        $log = sys_get_temp_dir() . '/reldyn_survival_report_bad.log';
        @unlink($log);
        $prev = ini_set('error_log', $log);
        $this->db->report = ['data' => 'not json', 'gamets' => self::at(60, 12.0)];
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::at(60, 12.0), 'Kaida: hello'];
        $this->assertNull(RelDynSurvival::latest());
        $this->assertStringContainsString('ERROR survival report', (string) file_get_contents($log));
        ini_set('error_log', $prev === false ? '' : (string) $prev);
        @unlink($log);
    }

    public function testEveryStateReachesDetectPhysicalStatesOnceAndWetYieldsToRain(): void
    {
        $t = self::at(70, 14.0);
        $this->send($this->report([
            'player' => ['hunger' => 3, 'fatigue' => 4, 'exposure' => 3, 'wet' => 3, 'dirt' => 4, 'blood' => 3],
            'fire' => ['near' => true, 'heat' => 2, 'dist' => 120, 'builder' => 'player'],
        ]), $t);
        // no place known: no weather states, the survival ones all there
        $states = RelationshipDynamics::detectPhysicalStates('Aela the Huntress', 'Kaida');
        foreach (['hungry', 'exhausted', 'cold', 'wet', 'warm_fire', 'dirty', 'bloody'] as $s) $this->assertContains($s, $states, $s);
        $this->assertSame($states, array_values(array_unique($states)), 'each state once');
        $this->assertNotContains('well_rested', $states);

        // outside in the rain: the weather's own state covers the drenching
        $this->db->location = ['data' => '(Context location: Whiterun outdoors ,Hold: Whiterun, current weather: Raining)', 'gamets' => $t - 100];
        $states = RelationshipDynamics::detectPhysicalStates('Aela the Huntress', 'Kaida');
        $this->assertContains('raining', $states);
        $this->assertNotContains('wet', $states, 'not counted twice');
        $this->assertContains('hungry', $states);
        // snow gives 'cold' and the exposure gives it too: once
        $this->db->location = ['data' => '(Context location: Windhelm outdoors ,Hold: Eastmarch, current weather: Snowing)', 'gamets' => $t - 100];
        $states = RelationshipDynamics::detectPhysicalStates('Aela the Huntress', 'Kaida');
        $this->assertSame(1, count(array_keys($states, 'cold', true)));
        $this->assertContains('snowing', $states);
    }

    public function testTheStatesApplyAndClearExactlyThroughTheExistingMachinery(): void
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['inferred_temperament'] = 'Stoic';
        $before = array_map(fn($x) => floatval($x['x']), $d['dimensions']);
        RelationshipDynamics::applyPhysicalStateModifiers($d, ['hungry', 'warm_fire', 'exhausted'], 'Stoic');
        $this->assertSame(['hungry', 'warm_fire', 'exhausted'], $d['_active_physical_states']);
        $this->assertLessThan(0.0, $d['_applied_physical_deltas']['hungry']['maturity']);
        $this->assertGreaterThan(0.0, $d['_applied_physical_deltas']['warm_fire']['comfort']);
        // fed and rested by the next report: what was applied goes back exactly
        RelationshipDynamics::clearPhysicalStateModifiers($d, [], 'Stoic');
        $this->assertSame([], $d['_active_physical_states']);
        foreach ($d['dimensions'] as $dim => $x) $this->assertEqualsWithDelta($before[$dim], floatval($x['x']), 1e-9, $dim);
    }

    // ------------------------------------------------------------------ words

    public function testTheSurvivalReaderNamesNoPronouns(): void
    {
        // RelDyn is for every character (Ken, §21): nothing here may assume she or he
        $src = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/reldyn_survival.php');
        $this->assertSame(0, preg_match('/\b(she|her|hers|he|his|him|herself|himself)\b/i', $src), 'no gendered pronoun in the survival reader');
    }
}
