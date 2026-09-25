<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** In-memory $db: the stored RelDyn config row, nothing else (no core rows, no watch). */
final class RelDynCreaturesFakeDb
{
    public array $config = [];
    public array $queries = [];

    public function fetchOne($q, array $params = [])
    {
        $this->queries[] = $q;
        if (str_contains($q, RelationshipDynamics::CONFIG_ROW_ID)) {
            return ['value' => json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA] + $this->config)];
        }
        return [];
    }
    public function fetchAll($q, $log = false) { $this->queries[] = $q; return []; }
    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
}

/**
 * creature-moodifications (feedback_creature_moodifications, decisions §7): Skyrim's real moon
 * cycle, core-data creature detection, the design's rows as temporary offsets on the game clock.
 */
final class RelDynCreaturesTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;

    private array $saved = [];
    private RelDynCreaturesFakeDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynCreaturesFakeDb();
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_creatures_test.log');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    private static function at(int $day, float $hour): float
    {
        return $day * self::DAY + $hour * self::HOUR;
    }

    /**
     * An independent copy of the Creation Kit's GetCurrentMoonphase boundaries (as Devotion
     * 1.5.0e replicates them in PDV__ManagerQuest.psc): ((GameDaysPassed + 0.5) as Int) % 24,
     * full on 22, 23 and 0. Returns the engine phase index (0 = full ... 7 = waxing gibbous).
     */
    private static function ckMoonphase(float $gameDaysPassed): int
    {
        $t = ((int) ($gameDaysPassed + 0.5)) % 24;
        if ($t >= 22 || $t === 0) return 0;
        if ($t < 4) return 1;
        if ($t < 7) return 2;
        if ($t < 10) return 3;
        if ($t < 13) return 4;
        if ($t < 16) return 5;
        if ($t < 19) return 6;
        return 7;
    }

    private function npc(array $extra = []): array
    {
        return RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(),
            ['inferred_temperament' => 'Stoic'], $extra));
    }

    // ------------------------------------------------------------------ moon

    public function testTheMoonFollowsSkyrimsTwentyFourDayCycle(): void
    {
        // CHIM gamets = GameDaysPassed x 1e7: every half day over three cycles matches the CK phase
        for ($half = 2; $half < 3 * 48; $half++) {
            foreach ([0.0, 11.9, 12.0, 23.9] as $hour) {
                $days = intdiv($half, 2);
                $g = self::at($days, $hour);
                $p = RelDynCreatures::moonPhase($g);
                $this->assertSame(self::ckMoonphase($g / self::DAY), $p['index'], "day {$days} {$hour}h");
                $this->assertSame(RelDynCreatures::MOON_PHASES[$p['index']], $p['name']);
            }
        }
        // Full for three days around the wrap, turning at midday; new mid-cycle
        $this->assertTrue(RelDynCreatures::moonPhase(self::at(21, 12.0))['full'], 'cycle day 22 begins at noon of day 21');
        $this->assertFalse(RelDynCreatures::moonPhase(self::at(21, 11.9))['full']);
        $this->assertTrue(RelDynCreatures::moonPhase(self::at(24, 11.9))['full']);
        $this->assertSame('waning_gibbous', RelDynCreatures::moonPhase(self::at(24, 12.0))['name']);
        $this->assertSame('new', RelDynCreatures::moonPhase(self::at(11, 3.0))['name']);
        $this->assertNull(RelDynCreatures::moonPhase(0.0), 'no game clock: unknown');

        $full = 0;
        for ($d = 100; $d < 124; $d++) if (RelDynCreatures::moonPhase(self::at($d, 23.0))['full']) $full++;
        $this->assertSame(3, $full, 'three full-moon nights in every 24 days (the April guess had one in five)');
    }

    public function testIsFullMoonReadsTheLiveGameClock(): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::at(46, 22.0), 'Kaida: look up'];
        $this->assertTrue(RelationshipDynamics::isFullMoon(), 'day 46 = cycle day 22');
        $GLOBALS['gameRequest'][2] = (string) self::at(4, 22.0);
        $this->assertFalse(RelationshipDynamics::isFullMoon(), 'the April "every 5th day" guess said full here');
    }

    // ------------------------------------------------------------------ detection

    public function testCreaturesFromCoreRaceAndFactionEditorIds(): void
    {
        $f = fn(array $names, int $rank = 0) => array_map(fn($n) => ['name' => $n, 'rank' => $rank], $names);
        // Skyrim.esm: the Circle (Aela, Farkas, Vilkas, Skjor) and Kodlak share the beast blood
        $aela = RelDynCreatures::classify('NordRace', $f(['CompanionsCircle', 'CompanionsCirclePlusKodlak', 'CompanionsFaction', 'CurrentFollowerFaction']));
        $this->assertSame(['type' => 'werewolf', 'source' => 'faction'], $aela);
        $this->assertSame('werewolf', RelDynCreatures::classify('NordRace', $f(['CompanionsCirclePlusKodlak', 'CompanionsHarbingerFaction']))['type'], 'Kodlak');
        // Dawnguard.esm: Harkon (NordRace, DLC1VampireFaction), Vingalmo (HighElfRaceVampire)
        $this->assertSame('vampire', RelDynCreatures::classify('NordRace', $f(['DLC1VampireCrimeFaction', 'DLC1VampireFaction']))['type']);
        $this->assertSame(['type' => 'vampire', 'source' => 'race'], RelDynCreatures::classify('HighElfRaceVampire', []));
        $this->assertSame('werewolf', RelDynCreatures::classify('WerewolfBeastRace', [])['type'], 'registered while transformed');
        // Not creatures: thralls, hunters, the would-be turned, the crime faction of the castle, the test beds
        foreach ([['VampireThrallFaction'], ['DLC1ThrallFaction'], ['DLC1HunterFaction', 'DLC1DawnguardFaction'],
                  ['DLC1PotentialVampireFaction'], ['DLC1VampireCrimeFaction'], ['DLC1VampireFeedNoCrimeFaction'],
                  ['CrimeFactionReach', 'MarkarthHagsCureFaction'], ['IvarsteadVilemyrInnFaction', 'JobInnServer'],
                  ['CurrentFollowerFaction', 'PotentialFollowerFaction']] as $names) {
            $this->assertNull(RelDynCreatures::classify('BretonRace', $f($names))['type'], implode(',', $names));
        }
        $this->assertNull(RelDynCreatures::classify('FalmerFrozenVampRace', [])['type'], 'not a vampire race id');
        $this->assertNull(RelDynCreatures::classify('NordRace', $f(['CompanionsCircle'], -1))['type'], 'rank -1: not a member');
        // The plugin's own reports: a form, the party's Vampire keyword
        $this->assertSame(['type' => 'werewolf', 'source' => 'transformation'], RelDynCreatures::classify('NordRace', [], 'werewolf'));
        $this->assertSame('vampire', RelDynCreatures::classify('NordRace', [], 'vampire_lord')['type']);
        $this->assertSame(['type' => 'vampire', 'source' => 'party'], RelDynCreatures::classify('NordRace', [], 'normal', true));
        $this->assertNull(RelDynCreatures::classify('NordRace', [], 'normal', false)['type']);
        // The intimacy need reads the same detection
        $this->assertSame('werewolf', RelDynIntimacy::creatureFromCore('NordRace', $f(['CompanionsCircle'])));
        $this->assertNull(RelDynIntimacy::creatureFromCore('NordRace', $f(['VampireThrallFaction'])), 'a thrall is not a vampire');
    }

    public function testOverridesAndNamedLoreCreatures(): void
    {
        $this->assertSame('vampire', RelDynCreatures::detect('Serana', [])['type'], 'Serana: NordRace, DLC1SeranaFaction; a vampire by script');
        $this->assertSame('name', RelDynCreatures::detect('Serana', [])['source']);
        $this->assertNull(RelDynCreatures::detect('Serana', ['creature_type' => 'none'])['type'], 'the editor can say no');
        $this->assertSame('werewolf', RelDynCreatures::detect('Lynly Star-Sung', ['creature_type' => 'Werewolf'])['type']);
        $this->assertNull(RelDynCreatures::detect('Lynly Star-Sung', [])['type'], 'no core row: unknown, not a creature');
        $GLOBALS['CACHE_PARTY'] = json_encode(['Jenassa' => ['name' => 'Jenassa', 'isVampire' => 'yes'], 'Lydia' => ['name' => 'Lydia', 'isVampire' => 'no']]);
        $this->assertSame(['type' => 'vampire', 'source' => 'party', 'form' => null], RelDynCreatures::detect('Jenassa', []));
        $this->assertNull(RelDynCreatures::detect('Lydia', [])['type']);
    }

    // ------------------------------------------------------------------ rows

    public function testTheDesignsRowsByNightDayAndMoon(): void
    {
        $fullNight = self::at(46, 23.0);    // cycle day 22, 23:00
        $plainNight = self::at(40, 23.0);   // waxing quarter
        $noon = self::at(40, 12.0);
        $fullNoon = self::at(46, 13.0);

        $v = RelDynCreatures::rowFor('vampire', $plainNight, null, null);
        $this->assertSame('vampire_night', $v['state']);
        $this->assertSame(['arousal' => 10.0, 'comfort' => 5.0, 'coord_m' => 5.0, 'self_confidence' => 10.0], $v['effects']);
        $v = RelDynCreatures::rowFor('vampire', $noon, null, null);
        $this->assertSame(['comfort' => -10.0, 'maturity' => -3.0, 'valence' => -15.0], $v['effects'], 'the design day row, not an inversion of the night');

        $w = RelDynCreatures::rowFor('werewolf', $fullNight, null, 0.4);
        $this->assertSame('werewolf_moon', $w['state']);
        $this->assertSame(['arousal' => 15.0, 'coord_f' => -5.0, 'coord_m' => 10.0, 'maturity' => -10.0, 'valence' => 10.0], $w['effects']);
        $this->assertSame('thrill', $w['valence'], 'a fighter feels the moon as thrill');
        $this->assertSame(-10.0, RelDynCreatures::rowFor('werewolf', $fullNight, null, -0.3)['effects']['valence'], 'a fearful one as fear');
        $this->assertSame(-10.0, RelDynCreatures::rowFor('werewolf', $fullNight, null, null)['effects']['valence'], 'no vector: the row');

        $n = RelDynCreatures::rowFor('werewolf', $plainNight, null, 0.4);
        $this->assertSame('werewolf_night', $n['state']);
        $this->assertSame(['arousal' => 7.5, 'coord_f' => -2.5, 'coord_m' => 5.0, 'maturity' => -5.0, 'valence' => 5.0], $n['effects'], 'halved on other nights');
        foreach ([$noon, $fullNoon] as $g) {
            $d = RelDynCreatures::rowFor('werewolf', $g, null, 0.4);
            $this->assertSame('werewolf_day', $d['state']);
            $this->assertSame(['arousal' => 3.0, 'maturity' => -2.0], $d['effects'], 'beast blood passive: arousal a little up, maturity a little down');
        }
        $this->assertSame([], RelDynCreatures::rowFor(null, $fullNight, null, 0.4)['effects']);
        $this->assertSame([], RelDynCreatures::rowFor('werewolf', 0.0, null, 0.4)['effects'], 'no clock: nothing');
    }

    // ------------------------------------------------------------------ offsets on the game clock

    public function testOffsetsAreHeldNotAddedPerRequestAndTakenBackExactly(): void
    {
        $d = $this->npc(['creature_type' => 'vampire']);
        $x = function (string $dim) use (&$d): float { return floatval($d['dimensions'][$dim]['x']); };
        $before = array_map(fn($dim) => $x($dim), ['comfort' => 'comfort', 'arousal' => 'arousal', 'valence' => 'valence',
            'maturity' => 'maturity', 'self_confidence' => 'self_confidence', 'coord_m' => 'coord_m']);

        $night = self::at(40, 22.0);
        RelDynCreatures::update('Serana', $d, 'Stoic', $night);
        $applied = $d['_creature']['applied'];
        $this->assertGreaterThan(0.0, $applied['self_confidence']);
        $this->assertGreaterThan(0.0, $applied['arousal']);
        $snapshot = $d['dimensions'];
        for ($i = 1; $i <= 20; $i++) RelDynCreatures::update('Serana', $d, 'Stoic', $night + $i * 1000);
        $this->assertSame($snapshot, $d['dimensions'], 'twenty more turns the same night move nothing (April added x0.1 each time)');

        // Dawn: the night row is taken back exactly, the day row applied
        RelDynCreatures::update('Serana', $d, 'Stoic', self::at(41, 9.0));
        $this->assertSame('vampire_day', $d['_creature']['state']);
        $day = $d['_creature']['applied'];
        $this->assertEqualsWithDelta($before['self_confidence'], $x('self_confidence'), 1e-9, 'the night confidence is gone by day');
        $this->assertEqualsWithDelta($before['comfort'] + $day['comfort'], $x('comfort'), 1e-9);
        $this->assertLessThan(0.0, $day['valence']);

        // Off: whatever is held is taken back
        $this->db->config = ['creature_moodifications_enabled' => false];
        RelationshipDynamics::clearConfigCache();
        RelDynCreatures::update('Serana', $d, 'Stoic', self::at(41, 10.0));
        foreach ($before as $dim => $v) $this->assertEqualsWithDelta($v, $x($dim), 1e-9, "{$dim} back to where it was");
        $this->assertSame([], $d['_creature']['applied']);
        $this->assertNull($d['_creature']['state']);
    }

    public function testANonCreatureIsNeverTouched(): void
    {
        $d = $this->npc();
        $dims = $d['dimensions'];
        RelDynCreatures::update('Lynly Star-Sung', $d, 'Gentle', self::at(46, 23.0));
        $this->assertSame($dims, $d['dimensions']);
        $this->assertNull($d['_creature']['type']);
        $this->assertSame([], RelationshipDynamics::getCreatureModifiers('Lynly Star-Sung', $d, self::at(46, 23.0)));
        $this->assertNull(RelDynCreatures::jev($d));
    }

    public function testFeltTextIsFeelingNotNumbers(): void
    {
        $vars = ['{NAME}' => 'Serana', '{PLAYER}' => 'Kaida'];
        $night = RelDynCreatures::feltText('Serana', [], $vars, self::at(40, 22.0));
        $day = RelDynCreatures::feltText('Serana', [], $vars, self::at(40, 13.0));
        $this->assertStringContainsString('Serana', $night);
        $this->assertNotSame($night, $day);
        foreach ([$night, $day] as $t) $this->assertDoesNotMatchRegularExpression('/\d/', $t);
        $this->assertNull(RelDynCreatures::feltText('Lynly Star-Sung', [], $vars, self::at(40, 22.0)));
    }
}
