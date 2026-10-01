<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory $db: the stored RelDyn config row, the newest location row, and an eventlog of rows the
 * feeding-signal query can read (type IN (...), data LIKE '%x%', gamets bounds); nothing else.
 */
final class RelDynVampireScheduleFakeDb
{
    public array $config = [];
    public ?array $location = null;
    /** @var array<int, array{type: string, data: string, gamets: int}> */
    public array $events = [];
    public array $queries = [];

    public function fetchOne($q, array $params = [])
    {
        $this->queries[] = $q;
        if (str_contains($q, RelationshipDynamics::CONFIG_ROW_ID)) {
            return ['value' => json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA] + $this->config)];
        }
        if (str_contains($q, "type IN ('infoloc','location','request')")) return $this->location ?? [];
        return [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->queries[] = $q;
        if (!preg_match("/FROM eventlog WHERE type IN \\((.+?)\\) AND data LIKE '%(.*)%' ESCAPE '[^']*' AND gamets > (\\d+) AND gamets <= (\\d+)/s", $q, $m)) return [];
        preg_match_all("/'([^']*)'/", $m[1], $t);
        $needle = str_replace(["\\\\", "''"], ['\\', "'"], $m[2]);
        $needle = preg_replace('/\\\\([%_\\\\])/', '$1', $needle);
        $rows = [];
        foreach ($this->events as $e) {
            if (!in_array($e['type'], $t[1], true) || !str_contains($e['data'], $needle)) continue;
            if ($e['gamets'] <= (int) $m[3] || $e['gamets'] > (int) $m[4]) continue;
            $rows[] = ['gamets' => (string) $e['gamets']];
        }
        usort($rows, fn($a, $b) => intval($b['gamets']) <=> intval($a['gamets']));
        return $rows;
    }

    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * The vampire's inverted schedule, thirst and sunlight (Ken, 2026-10-01 §21): cranky from about dawn (6:00)
 * to dusk (18:00) wherever they are; worse when unfed; worse again in direct sunlight (outdoors, day, clear
 * sky). Thirst grows per game day on a pluggable feeding signal, by default a night kill. No LLM, no network.
 */
final class RelDynVampireScheduleTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;

    private array $saved = [];
    private RelDynVampireScheduleFakeDb $db;
    private $prevErrorLog;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynVampireScheduleFakeDb();
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        $this->prevErrorLog = ini_set('error_log', sys_get_temp_dir() . '/reldyn_vampire_schedule_test.log');
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_vampire_schedule_test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    private static function at(int $day, float $hour): float
    {
        return (float) round($day * self::DAY + $hour * self::HOUR);   // whole gamets, as the eventlog holds them
    }

    private function npc(array $extra = []): array
    {
        return RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(),
            ['inferred_temperament' => 'Stoic', 'creature_type' => 'vampire'], $extra));
    }

    private function row(float $gamets, ?bool $interior = null, ?array $ctx = null, string $type = 'vampire'): array
    {
        return RelDynCreatures::rowFor($type, $gamets, $interior, null, null, $ctx);
    }

    // ------------------------------------------------------------------ the inverted schedule

    public function testTheVampiresDayIsFromDawnToDuskAndTheNightIsTheRest(): void
    {
        $cfg = RelDynCreatures::vampireConfig();
        $this->assertSame([6.0, 18.0], [$cfg['dawn_hour'], $cfg['dusk_hour']]);
        foreach ([[5.9, 'vampire_night'], [6.0, 'vampire_day'], [9.0, 'vampire_day'], [12.0, 'vampire_day'], [17.9, 'vampire_day'],
                     [18.0, 'vampire_night'], [19.0, 'vampire_night'], [23.0, 'vampire_night'], [0.0, 'vampire_night'], [3.0, 'vampire_night'], [5.5, 'vampire_night']] as [$hour, $state]) {
            $this->assertSame($state, $this->row(self::at(40, $hour))['state'], "{$hour}:00");
        }
        // 19:00 and 5:30 were the day under the old 20:00-5:00 night; the inversion is the ruling now
        $this->assertTrue(RelDynCreatures::vampireDay(self::at(40, 6.0)));
        $this->assertFalse(RelDynCreatures::vampireDay(self::at(40, 18.0)));
        $this->assertFalse(RelDynCreatures::vampireDay(0.0), 'no clock: neither');
        // the window is config
        $this->db->config = ['creatures' => ['vampire' => ['dawn_hour' => 7.0, 'dusk_hour' => 17.0]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertSame('vampire_night', $this->row(self::at(40, 6.5))['state']);
        $this->assertSame('vampire_day', $this->row(self::at(40, 7.1))['state']);
        $this->assertSame('vampire_day', $this->row(self::at(40, 16.9))['state']);
        $this->assertSame('vampire_night', $this->row(self::at(40, 17.1))['state']);
    }

    public function testTheWerewolfsNightIsItsOwnWindowAndUnchanged(): void
    {
        foreach ([[19.0, 'werewolf_day'], [19.9, 'werewolf_day'], [20.1, 'werewolf_night'], [4.9, 'werewolf_night'], [5.1, 'werewolf_day'], [5.5, 'werewolf_day']] as [$hour, $state]) {
            $this->assertSame($state, $this->row(self::at(40, $hour), null, null, 'werewolf')['state'], "{$hour}:00");
        }
    }

    public function testTheDayRowStaysTheDesignsUntilSunOrThirstAddToIt(): void
    {
        $noon = self::at(40, 12.0);
        foreach ([null, ['thirst' => 0.0], ['thirst' => 0.0, 'sky_clear' => true], ['sky_clear' => false]] as $ctx) {
            foreach ([null, true, false] as $interior) {
                if ($interior === false && ($ctx['sky_clear'] ?? null) === true) continue;   // that one is the sun's (next test)
                $r = $this->row($noon, $interior, $ctx);
                $this->assertSame(['comfort' => -10.0, 'maturity' => -3.0, 'valence' => -15.0], $r['effects'], 'the design day row: ' . json_encode([$ctx, $interior]));
                $this->assertFalse($r['sun']);
            }
        }
    }

    // ------------------------------------------------------------------ direct sunlight

    public function testDirectSunlightIsOutdoorsByDayUnderAClearSkyOnly(): void
    {
        $noon = self::at(40, 12.0);
        $night = self::at(40, 23.0);
        $dusk = self::at(40, 18.5);
        $base = $this->row($noon, true, ['sky_clear' => true])['effects'];
        $sun = $this->row($noon, false, ['sky_clear' => true]);
        $this->assertTrue($sun['sun']);
        $this->assertSame('vampire_day', $sun['state']);
        // worse: every dimension of the day row is further down, and the sun adds nothing the day row lacked
        $this->assertSame(array_keys($base), array_keys($sun['effects']));
        foreach ($base as $dim => $v) $this->assertLessThan($v, $sun['effects'][$dim], $dim);
        // not in the sun: under a roof, unknown place, a cloudy / rainy / unknown sky, or after dusk
        foreach ([[true, true], [null, true], [false, false], [false, null]] as [$interior, $clear]) {
            $this->assertFalse($this->row($noon, $interior, ['sky_clear' => $clear])['sun'], json_encode([$interior, $clear]));
        }
        $this->assertFalse($this->row($night, false, ['sky_clear' => true])['sun'], 'no sun at night');
        $this->assertFalse($this->row($dusk, false, ['sky_clear' => true])['sun'], 'after 18:00 the sun is down (their night)');
        $this->assertSame(['arousal' => 10.0, 'comfort' => 5.0, 'coord_m' => 5.0, 'self_confidence' => 10.0], $this->row($night, false, ['sky_clear' => true])['effects']);
        // the sky reads clear from the facets' weather keys
        $this->assertTrue(RelDynFacets::skyIsClear(['clear']));
        $this->assertTrue(RelDynFacets::skyIsClear(['pleasant']));
        foreach ([[], ['cloudy'], ['rain'], ['snow'], ['fog'], ['clear', 'fog']] as $w) $this->assertFalse(RelDynFacets::skyIsClear($w), json_encode($w));
    }

    public function testTheSunSwitchStillMeansOnlyInTheSun(): void
    {
        $this->db->config = ['creatures' => ['vampire_sun_outdoors_only' => true]];
        RelationshipDynamics::clearConfigCache();
        $noon = self::at(40, 12.0);
        $this->assertNull($this->row($noon, true, ['sky_clear' => true, 'thirst' => 1.0])['state'], 'under a roof the switch spares them');
        $r = $this->row($noon, false, ['sky_clear' => true, 'thirst' => 0.5]);
        $this->assertSame('vampire_day', $r['state']);
        $this->assertTrue($r['sun']);
    }

    // ------------------------------------------------------------------ unfed

    public function testNotFeedingMakesTheDayWorseAndTheNightRestless(): void
    {
        $noon = self::at(40, 12.0);
        $night = self::at(40, 23.0);
        $fedDay = $this->row($noon, true, ['thirst' => 0.0])['effects'];
        $halfDay = $this->row($noon, true, ['thirst' => 0.5])['effects'];
        $fullDay = $this->row($noon, true, ['thirst' => 1.0])['effects'];
        foreach (['comfort', 'valence', 'maturity'] as $dim) {
            $this->assertLessThan($fedDay[$dim], $halfDay[$dim], "half-thirsty {$dim}");
            $this->assertLessThan($halfDay[$dim], $fullDay[$dim], "thirsty {$dim}");
        }
        $unfed = RelDynCreatures::vampireConfig()['rows']['unfed'];
        $this->assertEqualsWithDelta($fedDay['comfort'] + $unfed['comfort'], $fullDay['comfort'], 0.011);
        $this->assertEqualsWithDelta($unfed['arousal'], $fullDay['arousal'], 0.011, 'a day row has no arousal; unfed adds it');
        // by night the thirst is half as sharp (unfed_night_scale)
        $fedNight = $this->row($night, true, ['thirst' => 0.0])['effects'];
        $fullNight = $this->row($night, true, ['thirst' => 1.0])['effects'];
        $this->assertSame('vampire_night', $this->row($night, true, ['thirst' => 1.0])['state']);
        $this->assertEqualsWithDelta($fedNight['comfort'] + $unfed['comfort'] * 0.5, $fullNight['comfort'], 0.011);
        $this->assertEqualsWithDelta($fedNight['arousal'] + $unfed['arousal'] * 0.5, $fullNight['arousal'], 0.011);
        $this->assertLessThan(0.0, $fullNight['valence'], 'the hungry night is edgy, not serene');
        // a thirst is clamped to a full one
        $this->assertSame($fullDay, $this->row($noon, true, ['thirst' => 7.0])['effects']);
    }

    public function testTheOrderOfSeverityDayThenUnfedThenSunlit(): void
    {
        $noon = self::at(40, 12.0);
        $day = $this->row($noon, true, ['thirst' => 0.0])['effects'];
        $unfed = $this->row($noon, true, ['thirst' => 1.0])['effects'];
        $sunUnfed = $this->row($noon, false, ['thirst' => 1.0, 'sky_clear' => true])['effects'];
        $sun = $this->row($noon, false, ['thirst' => 0.0, 'sky_clear' => true])['effects'];
        foreach (['comfort', 'valence', 'maturity'] as $dim) {
            $this->assertLessThan($day[$dim], $unfed[$dim], "unfed is worse than the plain day: {$dim}");
            $this->assertLessThan($unfed[$dim], $sunUnfed[$dim], "the sun is worse again: {$dim}");
            $this->assertLessThan($day[$dim], $sun[$dim], "the sun alone is worse than the plain day: {$dim}");
        }
    }

    // ------------------------------------------------------------------ thirst

    public function testThirstGrowsPerGameDayHeldInStepsAndCapsAtFull(): void
    {
        $fed = self::at(40, 20.0);
        $state = ['fed_at' => $fed, 'seen' => $fed];
        $this->assertSame(0.0, RelDynCreatures::thirstLevel($state, $fed));
        $this->assertSame(0.0, RelDynCreatures::thirstLevel($state, $fed + 3 * self::HOUR), 'three hours: under a step');
        $this->assertEqualsWithDelta(0.25, RelDynCreatures::thirstLevel($state, $fed + self::DAY), 1e-9, 'one game day: a quarter');
        $this->assertEqualsWithDelta(0.5, RelDynCreatures::thirstLevel($state, $fed + 2 * self::DAY), 1e-9);
        $this->assertEqualsWithDelta(0.75, RelDynCreatures::thirstLevel($state, $fed + 3 * self::DAY), 1e-9);
        $this->assertSame(1.0, RelDynCreatures::thirstLevel($state, $fed + 4 * self::DAY));
        $this->assertSame(1.0, RelDynCreatures::thirstLevel($state, $fed + 40 * self::DAY), 'capped');
        $this->assertEqualsWithDelta(0.1, RelDynCreatures::thirstLevel($state, $fed + 0.4 * self::DAY), 1e-9, 'held in steps of 0.05');
        $this->assertSame(0.0, RelDynCreatures::thirstLevel($state, $fed - self::DAY), 'a clock behind the feeding is not thirst');
        $this->assertSame(0.0, RelDynCreatures::thirstLevel(null, $fed + 9 * self::DAY), 'never seen: unknown, no thirst');
        // the rate and the switch are config
        $this->db->config = ['creatures' => ['vampire' => ['thirst' => ['per_game_day' => 0.5]]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertEqualsWithDelta(0.5, RelDynCreatures::thirstLevel($state, $fed + self::DAY), 1e-9);
        $this->db->config = ['creatures' => ['vampire' => ['thirst' => ['enabled' => false]]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(0.0, RelDynCreatures::thirstLevel($state, $fed + 9 * self::DAY));
        $this->assertSame(0.25, RelDynCreatures::vampireConfig()['thirst']['per_game_day'], 'a stored thirst table keeps the rest of its defaults');
    }

    // ------------------------------------------------------------------ feeding signals

    private function death(string $data, float $gamets): void
    {
        $this->db->events[] = ['type' => 'death', 'data' => $data, 'gamets' => (int) $gamets];
    }

    public function testTheDefaultSignalIsANightKillByTheVampire(): void
    {
        $cfg = RelDynCreatures::vampireConfig()['thirst']['signals'];
        $this->assertSame([['name' => 'night_kill', 'event_types' => ['death'], 'match' => '{NAME} has defeated', 'start_hour' => 18.0, 'end_hour' => 6.0]], $cfg);
        $this->death('Serana has defeated Bandit Thug with Dagger', self::at(40, 22.0));      // a night kill
        $this->death('Serana has defeated Frost Troll in an awesome move', self::at(41, 2.0)); // 2 am: still the night
        $this->death('Serana has defeated Mudcrab', self::at(41, 12.0));                       // noon: a day kill does not feed
        $this->death('Aela the Huntress has defeated Wolf', self::at(41, 23.0));               // someone else's kill
        $this->death('Kaida has defeated Draugr', self::at(41, 23.5));
        $times = RelDynCreatures::feedingTimes('Serana', self::at(40, 0.0), self::at(42, 0.0));
        $this->assertSame([self::at(41, 2.0), self::at(40, 22.0)], $times, 'newest first, the night kills only');
        // only what lies in the window
        $this->assertSame([self::at(40, 22.0)], RelDynCreatures::feedingTimes('Serana', self::at(40, 0.0), self::at(41, 0.0)));
        $this->assertSame([], RelDynCreatures::feedingTimes('Serana', self::at(41, 2.0), self::at(42, 0.0)), 'a kill at the window start was already seen');
        $this->assertSame([], RelDynCreatures::feedingTimes('Harkon', self::at(40, 0.0), self::at(42, 0.0)), 'nobody else fed Harkon');
    }

    public function testAFeedingModCanBeWiredInByConfigAlone(): void
    {
        $this->db->events[] = ['type' => 'infoaction', 'data' => 'Serana drinks deeply from a sleeping bandit', 'gamets' => (int) self::at(40, 14.0)];
        $this->db->events[] = ['type' => 'infoaction', 'data' => 'Serana shouts during combat', 'gamets' => (int) self::at(40, 15.0)];
        $this->db->config = ['creatures' => ['vampire' => ['thirst' => ['signals' => [
            ['name' => 'feeding_mod', 'event_types' => ['infoaction'], 'match' => '{NAME} drinks deeply'],        // any hour
            ['name' => 'night_kill', 'event_types' => ['death'], 'match' => '{NAME} has defeated', 'start_hour' => 18.0, 'end_hour' => 6.0],
        ]]]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertSame([self::at(40, 14.0)], RelDynCreatures::feedingTimes('Serana', self::at(40, 0.0), self::at(41, 0.0)), 'a daytime feeding counts: that signal names no hours');
        // an event type is a plain word: nothing else reaches the query
        $this->db->config = ['creatures' => ['vampire' => ['thirst' => ['signals' => [
            ['event_types' => ["death'); DROP TABLE eventlog; --"], 'match' => '{NAME} has defeated'],
        ]]]]];
        RelationshipDynamics::clearConfigCache();
        RelDynCreatures::feedingTimes('Serana', self::at(40, 0.0), self::at(41, 0.0));
        $last = end($this->db->queries);
        $this->assertStringNotContainsString('DROP TABLE', (string) $last);
        $this->assertStringContainsString("'deathDROPTABLEeventlog'", (string) $last);
    }

    public function testANameWithAQuoteIsEscapedInTheSignalQuery(): void
    {
        $this->death("O'Brien has defeated Wolf", self::at(40, 22.0));
        $this->assertSame([self::at(40, 22.0)], RelDynCreatures::feedingTimes("O'Brien", self::at(40, 0.0), self::at(41, 0.0)));
        $this->assertSame([], RelDynCreatures::feedingTimes("100%_sure", self::at(40, 0.0), self::at(41, 0.0)), 'LIKE wildcards in a name are literal');
    }

    public function testObservingFeedingAnchorsFirstSightAndFollowsTheSignals(): void
    {
        $t0 = self::at(40, 12.0);
        $s = RelDynCreatures::observeFeeding('Serana', null, $t0);
        $this->assertSame(['fed_at' => $t0, 'seen' => $t0], $s, 'first sight: fed (history is not replayed)');
        $this->death('Serana has defeated Wolf', self::at(39, 22.0));   // before she was ever watched
        $s = RelDynCreatures::observeFeeding('Serana', $s, $t0 + self::HOUR);
        $this->assertSame($t0, $s['fed_at'], 'the earlier kill counts for nothing');
        $this->assertSame($t0 + self::HOUR, $s['seen']);
        // two days on: no feeding, the marks move only for the clock
        $s = RelDynCreatures::observeFeeding('Serana', $s, $t0 + 2 * self::DAY);
        $this->assertSame($t0, $s['fed_at']);
        $this->assertEqualsWithDelta(0.5, RelDynCreatures::thirstLevel($s, $t0 + 2 * self::DAY), 1e-9);
        // a night kill: sated at the kill
        $kill = self::at(42, 21.0);
        $this->death('Serana has defeated Bandit Chief', $kill);
        $s = RelDynCreatures::observeFeeding('Serana', $s, self::at(42, 22.0));
        $this->assertSame($kill, $s['fed_at']);
        $this->assertSame(0.0, RelDynCreatures::thirstLevel($s, $kill));
        // a loaded save: the clock went back, both marks go back with it
        $s = RelDynCreatures::observeFeeding('Serana', $s, $t0 + self::DAY);
        $this->assertSame($t0 + self::DAY, $s['fed_at']);
        $this->assertSame($t0 + self::DAY, $s['seen']);
        // thirst switched off: no signal is read at all
        $this->db->config = ['creatures' => ['vampire' => ['thirst' => ['enabled' => false]]]];
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(0.0, RelDynCreatures::thirstLevel($s, $t0 + 9 * self::DAY));
    }

    // ------------------------------------------------------------------ held offsets

    public function testTheUnfedOffsetsAreHeldAndTakenBackWhenTheVampireFeeds(): void
    {
        $d = $this->npc();
        $x = fn(string $dim): float => floatval($d['dimensions'][$dim]['x']);
        $dims = ['comfort', 'valence', 'maturity', 'arousal', 'self_confidence', 'coord_m'];
        $before = array_combine($dims, array_map($x, $dims));

        // First sight, a day: anchored as fed, only the day row
        $t0 = self::at(40, 12.0);
        RelDynCreatures::update('Serana', $d, 'Stoic', $t0);
        $this->assertSame('vampire_day', $d['_creature']['state']);
        $this->assertSame(0.0, $d['_creature']['thirst_level']);
        $this->assertSame(['fed_at' => $t0, 'seen' => $t0], $d['_creature']['thirst']);
        $fedApplied = $d['_creature']['applied'];

        // Two game days without feeding: half a thirst, and the day is worse
        RelDynCreatures::update('Serana', $d, 'Stoic', $t0 + 2 * self::DAY);
        $this->assertSame(0.5, $d['_creature']['thirst_level']);
        $thirsty = $d['_creature']['applied'];
        $this->assertLessThan($fedApplied['comfort'], $thirsty['comfort']);
        $this->assertLessThan($fedApplied['valence'], $thirsty['valence']);
        $this->assertArrayHasKey('arousal', $thirsty, 'unfed adds an edge the day row lacks');
        // held, not added per turn
        $snapshot = $d['dimensions'];
        for ($i = 1; $i <= 5; $i++) RelDynCreatures::update('Serana', $d, 'Stoic', $t0 + 2 * self::DAY + $i * 100);
        $this->assertSame($snapshot, $d['dimensions'], 'five more turns in the same step move nothing');

        // A night kill that night: sated, the thirsty offsets are taken back to the plain night row
        $kill = self::at(42, 21.0);
        $this->db->events[] = ['type' => 'death', 'data' => 'Serana has defeated Bandit Chief', 'gamets' => (int) $kill];
        RelDynCreatures::update('Serana', $d, 'Stoic', $kill + 600);
        $this->assertSame('vampire_night', $d['_creature']['state']);
        $this->assertSame(0.0, $d['_creature']['thirst_level']);
        $this->assertSame($kill, $d['_creature']['thirst']['fed_at']);
        // and off: everything is back where it was
        $this->db->config = ['creature_moodifications_enabled' => false];
        RelationshipDynamics::clearConfigCache();
        RelDynCreatures::update('Serana', $d, 'Stoic', $kill + 1200);
        foreach ($before as $dim => $v) $this->assertEqualsWithDelta($v, $x($dim), 1e-9, "{$dim} back to where it was");
    }

    public function testANonVampireNeverGainsAThirstOrASun(): void
    {
        foreach (['werewolf', 'none'] as $type) {
            $d = $this->npc(['creature_type' => $type]);
            RelDynCreatures::update('Aela the Huntress', $d, 'Stoic', self::at(40, 12.0));
            $this->assertArrayNotHasKey('thirst', $d['_creature'], $type);
            $this->assertFalse($d['_creature']['sun']);
        }
    }

    // ------------------------------------------------------------------ words

    public function testFeltLinesForTheSunAndTheThirstNameNoPronouns(): void
    {
        $text = RelDynCreatures::configDefaults()['felt_text'];
        foreach (['vampire_day', 'vampire_night', 'vampire_sun', 'vampire_unfed_day', 'vampire_unfed_night'] as $key) {
            $this->assertArrayHasKey($key, $text);
            $this->assertStringContainsString('{NAME}', $text[$key], $key);
        }
        foreach ($text as $key => $line) {
            $this->assertSame(0, preg_match('/\b(she|her|hers|he|his|him|herself|himself)\b/i', $line), "{$key}: no gendered pronoun");
        }
        $src = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/reldyn_survival.php');
        $this->assertSame(0, preg_match('/\b(she|her|hers|he|his|him)\b/i', $src));
    }

    public function testFeltTextAddsTheSunAndTheThirstToTheVampiresLine(): void
    {
        $vars = ['{NAME}' => 'Harkon', '{PLAYER}' => 'Kaida'];
        $noon = self::at(40, 12.0);
        $d = $this->npc();
        // outside under a clear sky, unfed for three days
        $this->db->location = ['data' => '(Context location: Dayspring Canyon outdoors ,Hold: The Rift, current weather: Pleasant)', 'gamets' => (int) $noon - 100];
        $d['_creature'] = ['thirst' => ['fed_at' => $noon - 3 * self::DAY, 'seen' => $noon - 10]];
        $line = (string) RelDynCreatures::feltText('Harkon', $d, $vars, $noon);
        $this->assertStringContainsString('the day weighs on Harkon', $line, 'the vampire\'s own day line is still there');
        $this->assertStringContainsString('the open sun is worse', $line);
        $this->assertStringContainsString('too long since Harkon fed', $line);
        // indoors and fed: just the day
        $this->db->location = ['data' => '(Context location: Volkihar Keep ,Hold: Haafingar, current weather: indoors)', 'gamets' => (int) $noon - 100];
        $d['_creature'] = ['thirst' => ['fed_at' => $noon - 10, 'seen' => $noon - 10]];
        $plain = (string) RelDynCreatures::feltText('Harkon', $d, $vars, $noon);
        $this->assertStringContainsString('the day weighs on Harkon', $plain);
        $this->assertStringNotContainsString('sun', $plain);
        $this->assertStringNotContainsString('fed', $plain);
        // a stored felt table that predates the keys still gets the default words
        $this->db->config = ['creatures' => ['felt_text' => ['vampire_day' => 'the day weighs on {NAME}']]];
        RelationshipDynamics::clearConfigCache();
        $this->db->location = ['data' => '(Context location: Dayspring Canyon outdoors ,Hold: The Rift, current weather: Pleasant)', 'gamets' => (int) $noon - 100];
        $d['_creature'] = ['thirst' => ['fed_at' => $noon - 3 * self::DAY, 'seen' => $noon - 10]];
        $this->assertStringContainsString('the open sun is worse', (string) RelDynCreatures::feltText('Harkon', $d, $vars, $noon));
    }
}
