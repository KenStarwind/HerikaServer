<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** In-memory $db: the stored RelDyn config row, nothing else. */
final class RelDynSubstancesFakeDb
{
    public array $config = [];

    public function fetchOne($q, array $params = [])
    {
        if (str_contains($q, RelationshipDynamics::CONFIG_ROW_ID)) {
            return ['value' => json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA] + $this->config)];
        }
        return [];
    }
    public function fetchAll($q, $log = false) { return []; }
    public function execQuery($q) { return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
}

/**
 * drunk-state and addiction (dimension draft: Item -> Dimension Modifiers, the Skooma Addict
 * Cycle, the Drunken One-Night Stand; audit Step 18): the design's numbers, the held offsets
 * (neutral to physics, taken back exactly), the sober correction, dependence / tolerance /
 * craving / withdrawal / harm reduction, the maturity ceiling and its intervention, the recovery
 * goal. Pure state, no LLM, no core rows.
 */
final class RelDynSubstancesTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;

    private array $saved = [];
    private RelDynSubstancesFakeDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynSubstancesFakeDb();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_substances_test.log');
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

    private function npc(float $maturity, string $temperament = 'Bold', array $extra = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(),
            ['inferred_temperament' => $temperament], $extra));
        $d['dimensions']['maturity']['x'] = $maturity;
        $d['dimensions']['maturity']['baseline'] = $maturity;
        return $d;
    }

    private static function first(array $out): ?array
    {
        return $out === [] ? null : array_values($out)[0];
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x']);
    }

    private function drink(array &$d, float $t, int $n, string $key = 'ale', string $npc = 'Aela the Huntress'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = RelDynSubstances::onConsume($npc, $d, $key, $t + $i);
        return $out;
    }

    // ------------------------------------------------------------------ the drunk state

    /** The design's worked night: 65 -> 64 -> 61 -> 53 -> 43 -> 33 over five ales; about six game hours clear it. */
    public function testFiveAlesTakeHerFromSixtyFiveToThirtyThreeAndSixHoursClearIt(): void
    {
        $d = $this->npc(65.0);
        $t0 = self::at(30, 20.0);
        $expect = [64.0, 61.0, 53.0, 43.0, 33.0];
        $stages = ['tipsy', 'tipsy', 'merry', 'drunk', 'drunk'];
        for ($i = 0; $i < 5; $i++) {
            RelDynSubstances::onConsume('Aela the Huntress', $d, 'ale', $t0 + $i);
            $this->assertEqualsWithDelta($expect[$i], self::x($d, 'maturity'), 0.01, 'ale #' . ($i + 1));
            $this->assertSame($stages[$i], $d['_substances']['stage'], 'ale #' . ($i + 1));
            $this->assertEqualsWithDelta(65.0, RelationshipDynamics::driftSampleValue($d, 'maturity'), 1e-9, 'her own maturity stays');
        }
        $this->assertTrue(RelDynSubstances::intoxicated($d));
        $this->assertTrue(RelDynDiary::intoxicated($d), 'the diary waits for the sober self');
        // Below maturity forty the floors are off (MATURITY_FLOOR_THRESHOLD reads maturity as it is now)
        $floor = floatval(RelationshipDynamics::getTierFloor('friend'));
        $slipping = function (array $dyn) use ($floor): array {
            $dyn['_aff_mirror_x'] = ($floor - 40.0 + 100.0) / 2.0;
            $dyn['dimensions']['affinity']['x'] = $dyn['_aff_mirror_x'];
            return RelationshipDynamics::checkTierDemotion($dyn, 'Bold', 'friend', 'friend');
        };
        $this->assertFalse($slipping($d)['floor_active'], 'drunk at thirty-three: floors off');
        $this->assertTrue($slipping($this->npc(65.0))['floor_active'], 'sober at sixty-five: floors on');

        RelDynSubstances::update('Aela the Huntress', $d, $t0 + 5.9 * self::HOUR);
        $this->assertTrue(RelDynSubstances::intoxicated($d), 'still in her at five hours and fifty-four minutes');
        $this->assertLessThan(65.0, self::x($d, 'maturity'));
        RelDynSubstances::update('Aela the Huntress', $d, $t0 + 6.01 * self::HOUR);
        $this->assertFalse(RelDynSubstances::intoxicated($d), 'sober after about six game hours');
        $this->assertEqualsWithDelta(65.0, self::x($d, 'maturity'), 1e-9, 'the drink is taken back exactly');
        $this->assertArrayNotHasKey('held', $d['_substances']);
        $this->assertNull($d['_substances']['stage']);
        $this->assertFalse(RelDynDiary::intoxicated($d));
    }

    /** One ale is a light, short thing: tipsy, a point of maturity, gone in about an hour and twelve minutes. */
    public function testOneAleWearsOffOnTheGameClock(): void
    {
        $d = $this->npc(50.0);
        $t0 = self::at(31, 19.0);
        $this->drink($d, $t0, 1);
        $this->assertEqualsWithDelta(49.0, self::x($d, 'maturity'), 1e-3);
        RelDynSubstances::update('Lynly Star-Sung', $d, $t0 + 0.6 * self::HOUR);
        $this->assertEqualsWithDelta(49.5, self::x($d, 'maturity'), 0.01, 'half cleared: half the step (read between whole drinks)');
        $this->assertNull($d['_substances']['stage'], 'under one drink: not even tipsy');
        RelDynSubstances::update('Lynly Star-Sung', $d, $t0 + 1.21 * self::HOUR);
        $this->assertEqualsWithDelta(50.0, self::x($d, 'maturity'), 1e-9);
        $this->assertFalse(RelDynSubstances::intoxicated($d));
    }

    /** The held drink is not who she is: her own maturity's physics runs as if sober, and sobering lands where sober-her is. */
    public function testTheHeldDrinkIsNeutralToPhysicsAndLiftsExactly(): void
    {
        $sober = $this->npc(65.0);
        $drunk = $sober;
        $t0 = self::at(32, 21.0);
        $this->drink($drunk, $t0, 5);
        $a = RelationshipDynamics::applyDelta('maturity', $sober, 3.0, 'Bold', ['Y_up' => 1.0, 'Y_down' => 1.0]);
        $b = RelationshipDynamics::applyDelta('maturity', $drunk, 3.0, 'Bold', ['Y_up' => 1.0, 'Y_down' => 1.0]);
        $this->assertEqualsWithDelta($a, $b, 1e-9, 'the same experience moves her own maturity the same, drunk or sober');
        $this->assertEqualsWithDelta(RelationshipDynamics::driftSampleValue($sober, 'maturity'), RelationshipDynamics::driftSampleValue($drunk, 'maturity'), 1e-9);
        // Twenty more turns the same hour hold, never add
        $held = $drunk['_substances']['held'];
        for ($i = 1; $i <= 20; $i++) RelDynSubstances::update('Aela the Huntress', $drunk, $t0 + 5 + $i);
        $this->assertEqualsWithDelta($held['maturity'], $drunk['_substances']['held']['maturity'], 0.01);
        RelDynSubstances::update('Aela the Huntress', $drunk, $t0 + 7 * self::HOUR);
        $this->assertEqualsWithDelta(self::x($sober, 'maturity'), self::x($drunk, 'maturity'), 1e-9, 'sober again: exactly where sober-her is');
    }

    /** Ale #3 leans toward Uniform, ale #4 loosens openness, the floors and the Ick; the Ick reads her sober maturity. */
    public function testTheStagesShiftSensitivityOpennessFloorsAndTheIck(): void
    {
        $d = $this->npc(65.0);
        $t0 = self::at(33, 20.0);
        $this->drink($d, $t0, 2);
        $sh = RelDynSubstances::shifts($d);
        $this->assertSame(0.0, $sh['uniform_blend']);
        $this->assertSame(0.0, $sh['openness_bonus']);
        $this->drink($d, $t0 + 10, 1);
        $sh = RelDynSubstances::shifts($d);
        $this->assertEqualsWithDelta(1 / 3, $sh['uniform_blend'], 1e-3, 'ale #3: toward Uniform');
        $this->assertSame(0.0, $sh['floor_drop']);
        $this->drink($d, $t0 + 20, 1);
        $sh = RelDynSubstances::shifts($d);
        $this->assertEqualsWithDelta(2 / 3, $sh['uniform_blend'], 1e-3);
        $this->assertSame([0.2, 15.0, 1.3], [$sh['openness_bonus'], $sh['floor_drop'], $sh['ick_mult']], 'ale #4: the design numbers');
        $this->drink($d, $t0 + 30, 1);
        $this->assertEqualsWithDelta(1.0, RelDynSubstances::shifts($d)['uniform_blend'], 1e-3);
        $this->assertEqualsWithDelta(1.0, RelDynSubstances::sensitivityShift($d, 0.25), 1e-9, 'everyone is her packmate tonight');
        $ick = RelDynSubstances::ickShift($d);
        $this->assertEqualsWithDelta(65.0, $ick['maturity'], 1e-9, 'the Ick threshold reads her sober maturity, not the drunk 33');
        $this->assertSame(1.3, $ick['mult']);

        // The social sensitivity factor itself (an inner-circle curve: a stranger's words barely land)
        $sober = $this->npc(65.0, 'Bold', ['social_sensitivity_curve' => 'inner_circle']);
        $drunk = $sober;
        $this->drink($drunk, $t0, 5);
        $s = RelationshipDynamics::socialSensitivityFactor($sober, 'trust', false, 'Bold', 10.0);
        $this->assertEqualsWithDelta(0.01, $s, 1e-9);
        $this->assertEqualsWithDelta(1.0, RelationshipDynamics::socialSensitivityFactor($drunk, 'trust', false, 'Bold', 10.0), 1e-9);
        $this->assertNull(RelDynSubstances::shifts($sober));
        $this->assertNull(RelDynSubstances::ickShift($sober));
    }

    /** A hardened drinker: tolerance cuts the drop, and every drink hardens her a little more. */
    public function testToleranceSoftensTheDrop(): void
    {
        $d = $this->npc(60.0, 'Playful', ['_substances' => ['use' => ['alcohol' => ['tolerance' => 0.5, 'dependence' => 0.0]]]]);
        $t0 = self::at(34, 20.0);
        $this->drink($d, $t0, 5, 'ale', 'Muiri');
        // the night is drunk at the tolerance she brought to it; every drink hardens her for the next
        $e = floatval($d['_substances']['effective']);
        $this->assertEqualsWithDelta(5 * (1 - 0.5 * 0.6), $e, 1e-3, 'effective = drinks x (1 - tolerance x cut)');
        $this->assertEqualsWithDelta(60.0 - RelDynSubstances::maturityDrop($e, [1, 3, 8, 10, 10]), self::x($d, 'maturity'), 1e-3);
        $this->assertGreaterThan(40.0, self::x($d, 'maturity'), 'far less than the novice drop of thirty-two');
        $this->assertSame('merry', $d['_substances']['stage'], 'five drinks leave a hardened drinker merry, not drunk');
        $this->assertEqualsWithDelta(0.7, RelDynSubstances::tolerance($d, 'alcohol'), 1e-9);
        $this->assertEqualsWithDelta(0.15, RelDynSubstances::dependence($d, 'alcohol'), 1e-9);
    }

    public function testTheDropIsReadBetweenWholeDrinks(): void
    {
        $steps = [1, 3, 8, 10, 10];
        $this->assertSame(0.0, RelDynSubstances::maturityDrop(0.0, $steps));
        $this->assertEqualsWithDelta(4.0, RelDynSubstances::maturityDrop(2.0, $steps), 1e-9);
        $this->assertEqualsWithDelta(8.0, RelDynSubstances::maturityDrop(2.5, $steps), 1e-9);
        $this->assertEqualsWithDelta(32.0, RelDynSubstances::maturityDrop(5.0, $steps), 1e-9);
        $this->assertEqualsWithDelta(42.0, RelDynSubstances::maturityDrop(6.0, $steps), 1e-9, 'past the list, the last step per drink');
        $this->assertEqualsWithDelta(0.0, RelDynSubstances::levelAt([['g' => 100.0, 'u' => 1.0]], 50.0, 1.0), 1e-9, 'a drink not yet drunk');
    }

    // ------------------------------------------------------------------ the sober correction

    /** A romantic night past her standards: the sober diary regrets the share she does not stand behind. */
    public function testTheSoberDiaryCorrectsTheDrunkGains(): void
    {
        $t0 = self::at(35, 20.0);
        $night = function (array $att, bool $romantic = true) use ($t0): array {
            $d = $this->npc(65.0, 'Bold', ['_attraction' => $att]);
            $d['dimensions']['affinity']['x'] = 60.0;   // the mirror of core affinity 20
            $d['_aff_mirror_x'] = 60.0;
            RelationshipDynamics::setPassion($d, 30.0);
            $this->drink($d, $t0, 2);
            // two drinks: not yet the drunk self's (ledger_from 3)
            RelDynSubstances::afterEvalItem('Aela the Huntress', ['tags' => ['touch']], ['affinity' => 1.0, 'passion' => 2.0], $d, $t0 + 100);
            $this->assertArrayNotHasKey('nights', $d['_substances']);
            $this->drink($d, $t0 + 200, 3);
            RelDynSubstances::afterEvalItem('Aela the Huntress', ['tags' => $romantic ? ['touch'] : ['quality_time']],
                ['affinity' => 2.5, 'comfort' => 5.0, 'passion' => $romantic ? 4.0 : 0.0], $d, $t0 + 600);
            $key = array_key_first($d['_substances']['nights']);
            $this->assertEqualsWithDelta(5.0, $d['_substances']['nights'][$key]['gains']['affinity'], 1e-9, 'affinity in core points');
            $this->assertSame($romantic, $d['_substances']['nights'][$key]['romantic']);
            RelDynSubstances::update('Aela the Huntress', $d, $t0 + 8 * self::HOUR);
            $this->assertFalse(RelDynSubstances::intoxicated($d));
            $this->assertTrue($d['_substances']['nights'][$key]['closed']);
            return $d;
        };

        // A bard she barely tolerates (her sober curve 0.25): three quarters of it is regretted
        $d = $night(['enabled' => true, 'attracted' => false, 'won_over' => false, 'passion' => ['curve' => 0.25]]);
        $this->assertContains('drunk_night', RelDynDiary::momentKinds($d['_diary_moments'] ?? []), 'the night waits for the diary');
        $before = ['affinity' => RelationshipDynamics::getCoreAffinity($d), 'comfort' => self::x($d, 'comfort'),
                   'resentment_self' => self::x($d, 'resentment_self')];
        $out = RelDynSubstances::soberReflection('Aela the Huntress', $d, 'examination');
        $r = reset($out);
        $this->assertEqualsWithDelta(0.25, $r['endorsed'], 1e-9);
        foreach (['affinity', 'comfort', 'passion'] as $sig) $this->assertLessThan(0.0, $r['applied'][$sig], $sig);
        $this->assertLessThan($before['comfort'], self::x($d, 'comfort'));
        $this->assertGreaterThan($before['resentment_self'], self::x($d, 'resentment_self'), 'the shame of it');
        $this->assertGreaterThan(0.0, $r['applied']['resentment_self']);
        $this->assertArrayNotHasKey('nights', $d['_substances'], 'the night is spent');
        $this->assertSame([], RelDynSubstances::soberReflection('Aela the Huntress', $d, 'examination'), 'never twice');

        // A player she is drawn to soberly: nothing to regret
        $d = $night(['enabled' => true, 'attracted' => true, 'passion' => ['curve' => 1.0]]);
        $r = self::first(RelDynSubstances::soberReflection('Aela the Huntress', $d, 'examination'));
        $this->assertSame(1.0, $r['endorsed']);
        $this->assertSame([], $r['applied']);

        // A friendly night (nothing romantic): nothing to regret, whoever the player is
        $d = $night(['enabled' => true, 'attracted' => false, 'passion' => ['curve' => 0.1]], false);
        $this->assertNotContains('drunk_night', RelDynDiary::momentKinds($d['_diary_moments'] ?? []));
        $r = self::first(RelDynSubstances::soberReflection('Aela the Huntress', $d, 'pattern'));
        $this->assertSame(1.0, $r['endorsed']);

        // A shallow diary (maturity at or below 20): no meaningful self-reflection, the night is just gone
        $d = $night(['enabled' => true, 'attracted' => false, 'passion' => ['curve' => 0.1]]);
        $comfort = self::x($d, 'comfort');
        $r = self::first(RelDynSubstances::soberReflection('Mikael', $d, 'shallow'));
        $this->assertNull($r['endorsed']);
        $this->assertSame($comfort, self::x($d, 'comfort'));
    }

    /** The more mature she is, the more she asks of herself (the draft: +5 to +15 by maturity; Aela, 65: +12). */
    public function testRegretScalesWithHerOwnMaturity(): void
    {
        $t0 = self::at(36, 20.0);
        $shame = [];
        foreach ([30.0, 80.0] as $m) {
            $d = $this->npc($m, 'Bold', ['_attraction' => ['enabled' => true, 'attracted' => false, 'passion' => ['curve' => 0.0]]]);
            $this->drink($d, $t0, 4);
            RelDynSubstances::afterEvalItem('X', ['tags' => ['intimacy']], ['passion' => 3.0], $d, $t0 + 100);
            RelDynSubstances::update('X', $d, $t0 + 8 * self::HOUR);
            $r = self::first(RelDynSubstances::soberReflection('X', $d, 'examination'));
            $shame[(string) $m] = $r['asked']['resentment_self'];
        }
        // what she asks of herself (before the physics, where an immature NPC internalizes shame worse, MDD 15.5)
        $this->assertEqualsWithDelta(8.0, $shame['30'], 1e-9);
        $this->assertEqualsWithDelta(13.0, $shame['80'], 1e-9);
    }

    // ------------------------------------------------------------------ addiction

    /** The Addict Cycle: daily skooma builds dependence and tolerance; craving builds, withdrawal holds, the next use lifts it exactly. */
    public function testSkoomaBuildsDependenceCravingAndWithdrawal(): void
    {
        $d = $this->npc(28.0, 'Playful');
        $mults = [];
        for ($day = 0; $day < 5; $day++) {
            $mults[] = RelDynSubstances::onConsume('Muiri', $d, 'skooma', self::at(40 + $day, 20.0))['spike_mult'];
        }
        $this->assertSame(1.0, $mults[0], 'the first high is the full table');
        for ($i = 1; $i < 5; $i++) $this->assertLessThan($mults[$i - 1], $mults[$i], 'tolerance: each high a little less');
        $dep = RelDynSubstances::dependence($d, 'skooma');
        $this->assertGreaterThanOrEqual(0.5, $dep, 'five days of it: dependent');
        $this->assertTrue(RelDynSubstances::addicted($d));

        $last = self::at(44, 20.0);
        $comfortOwn = self::x($d, 'comfort');
        RelDynSubstances::update('Muiri', $d, $last + 6 * self::HOUR);
        $c6 = $d['_substances']['craving'];
        $this->assertGreaterThan(0.0, $c6);
        $this->assertFalse($d['_substances']['withdrawal'], 'not yet');
        RelDynSubstances::update('Muiri', $d, $last + 13 * self::HOUR);
        $this->assertGreaterThan($c6, $d['_substances']['craving'], 'craving builds with the hours');
        $this->assertTrue($d['_substances']['withdrawal']);
        $w = $d['_substances']['held']['comfort'];
        $this->assertLessThan(-9.0, $w);
        $this->assertEqualsWithDelta($comfortOwn + $w, self::x($d, 'comfort'), 1e-6);
        $this->assertEqualsWithDelta($comfortOwn, RelationshipDynamics::driftSampleValue($d, 'comfort'), 1e-6, 'withdrawal is a state, not who she is');

        // Harm reduction: a healing potion instead eases it by half, and is no use (the clock runs on)
        $c13 = $d['_substances']['craving'];
        RelDynSubstances::onConsume('Muiri', $d, 'healing_potion', $last + 14 * self::HOUR);
        $this->assertEqualsWithDelta($w / 2, $d['_substances']['held']['comfort'], 0.5);
        $this->assertLessThan($c13, $d['_substances']['craving']);
        $this->assertSame(5, $d['_substances']['use']['skooma']['uses']);
        $this->assertEqualsWithDelta($last, $d['_substances']['use']['skooma']['last_use'], 1e-9);

        // The relief wears off; the next skooma lifts the withdrawal exactly
        RelDynSubstances::update('Muiri', $d, $last + 21 * self::HOUR);
        $this->assertArrayNotHasKey('relief_until', $d['_substances']);
        RelDynSubstances::onConsume('Muiri', $d, 'skooma', $last + 22 * self::HOUR);
        $this->assertArrayNotHasKey('held', $d['_substances']);
        $this->assertEqualsWithDelta($comfortOwn, self::x($d, 'comfort'), 1e-6);
    }

    /** Abstinence: dependence and tolerance fall on the game calendar. */
    public function testAbstinenceLowersDependenceAndTolerance(): void
    {
        $d = $this->npc(40.0, 'Playful', ['_substances' => ['use' => ['skooma' => ['dependence' => 0.6, 'tolerance' => 0.5, 'last_use' => self::at(50, 0.0)]],
            'last_tick' => self::at(50, 0.0)]]);
        RelDynSubstances::update('Muiri', $d, self::at(60, 0.0));
        $this->assertEqualsWithDelta(0.4, RelDynSubstances::dependence($d, 'skooma'), 1e-9);
        $this->assertEqualsWithDelta(0.0, RelDynSubstances::tolerance($d, 'skooma'), 1e-9);
        $this->assertFalse(RelDynSubstances::addicted($d));
    }

    /** Addicted, maturity stops at the ceiling; an intervention that names her substance passes it. */
    public function testTheMaturityCeilingAndTheIntervention(): void
    {
        $d = $this->npc(22.0, 'Playful', ['_substances' => ['use' => ['skooma' => ['dependence' => 0.7, 'tolerance' => 0.3, 'last_use' => self::at(70, 8.0)]],
            'last_tick' => self::at(70, 8.0)]]);
        $this->assertSame(25.0, RelDynSubstances::maturityCeiling($d));
        $a = RelationshipDynamics::applyDelta('maturity', $d, 8.0, 'Playful', ['Y_up' => 1.0, 'Y_down' => 1.0, 'Z' => 1000.0]);
        $this->assertEqualsWithDelta(3.0, $a, 1e-6, 'the gain stops at the ceiling');
        $this->assertSame(0.0, RelationshipDynamics::applyDelta('maturity', $d, 2.0, 'Playful'));
        $this->assertLessThan(0.0, RelationshipDynamics::applyDelta('maturity', $d, -1.0, 'Playful'), 'losses are not held');

        $this->assertFalse(RelDynSubstances::beforeEvalItem('Muiri', ['tags' => ['help'], 'summary' => 'They talked about the weather.'], $d, self::at(70, 9.0)));
        $this->assertFalse(RelDynSubstances::beforeEvalItem('Muiri', ['tags' => ['quality_time'], 'summary' => 'Kaida asked about the skooma.'], $d, self::at(70, 9.0)));
        $m = self::x($d, 'maturity');
        $this->assertTrue(RelDynSubstances::beforeEvalItem('Muiri', ['tags' => ['help'], 'summary' => 'Kaida begged Muiri to put the skooma down.'], $d, self::at(70, 9.0)));
        $this->assertNull(RelDynSubstances::maturityCeiling($d), 'while the intervention is applied');
        $this->assertGreaterThan($m, self::x($d, 'maturity'), 'the outside pressure lands');
        $this->assertGreaterThan(0.0, RelationshipDynamics::applyDelta('maturity', $d, 2.0, 'Playful'), "the exchange's own maturity passes the ceiling");
        $this->assertGreaterThan(25.0, RelationshipDynamics::driftSampleValue($d, 'maturity'));
        RelDynSubstances::afterEvalItem('Muiri', ['tags' => ['help']], [], $d, self::at(70, 9.0));
        $this->assertSame(25.0, RelDynSubstances::maturityCeiling($d), 'after it, the ceiling holds again');
        $this->assertSame(1, $d['_substances']['interventions']);

        $clean = $this->npc(22.0);
        $this->assertNull(RelDynSubstances::maturityCeiling($clean), 'not addicted: no ceiling');
    }

    /** "I need to stop" needs her own maturity above 20; at 25 it is "Stay clean"; a relapse shames her; clean days end it. */
    public function testTheRecoveryGoal(): void
    {
        $sub = fn(float $dep) => ['use' => ['skooma' => ['dependence' => $dep, 'tolerance' => 0.3, 'last_use' => self::at(80, 8.0)]], 'last_tick' => self::at(80, 8.0)];
        $d = $this->npc(18.0, 'Playful', ['_substances' => $sub(0.7)]);
        RelDynSubstances::update('Muiri', $d, self::at(80, 10.0));
        $this->assertSame([], array_filter(RelDynGoals::active($d), fn($g) => $g['type'] === 'recovery'), 'below twenty: no such goal');

        $d = $this->npc(22.0, 'Playful', ['_substances' => $sub(0.7)]);
        RelDynSubstances::update('Muiri', $d, self::at(80, 10.0));
        $goal = RelDynGoals::active($d)[0];
        $this->assertSame(['recovery', 'stop', 'skooma'], [$goal['type'], $goal['phase'], $goal['substance']]);
        $text = RelDynGoals::feltText('Muiri', 'Kaida', $d);
        $this->assertStringContainsString('wants to stop', $text);
        $this->assertStringContainsString('skooma', $text);

        // Her own maturity reaches twenty-five: she governs herself
        $d['dimensions']['maturity']['x'] = 26.0;
        $d['dimensions']['maturity']['baseline'] = 26.0;
        RelDynSubstances::update('Muiri', $d, self::at(81, 10.0));
        $this->assertSame('clean', RelDynGoals::active($d)[0]['phase']);
        $this->assertStringContainsString('staying away from skooma', RelDynGoals::feltText('Muiri', 'Kaida', $d));
        $this->assertTrue(RelDynSubstances::governing($d));

        // A relapse: the shame of it, the clean days start over
        $rs = self::x($d, 'resentment_self');
        RelDynSubstances::onConsume('Muiri', $d, 'skooma', self::at(82, 20.0));
        $this->assertGreaterThan($rs, self::x($d, 'resentment_self'));
        $g = RelDynGoals::active($d)[0];
        $this->assertSame(1, $g['relapses']);
        $this->assertEqualsWithDelta(0.0, $g['progress'], 1e-9);

        // Weeks clean (her dependence falls under clean_below): achieved, her maturity baseline rises
        $base = floatval($d['dimensions']['maturity']['baseline']);
        RelDynSubstances::update('Muiri', $d, self::at(90, 20.0));
        $this->assertEqualsWithDelta(0.8, RelDynGoals::active($d)[0]['progress'], 1e-3, 'eight clean days of ten');
        RelDynSubstances::update('Muiri', $d, self::at(125, 20.0));
        $this->assertSame([], array_filter(RelDynGoals::active($d), fn($g) => $g['type'] === 'recovery'));
        $this->assertSame('achieved', $d[RelDynGoals::HISTORY_KEY][0]['outcome']);
        $this->assertEqualsWithDelta($base + 2.0, floatval($d['dimensions']['maturity']['baseline']), 1e-9);
    }

    // ------------------------------------------------------------------ output, load, off

    public function testFeltLinesAreFeelingsAndJevGetsNumbers(): void
    {
        $vars = ['{NAME}' => 'Lynly Star-Sung', '{PLAYER}' => 'Kaida'];
        $d = $this->npc(45.0, 'Gentle', ['_substances' => ['use' => ['alcohol' => ['dependence' => 0.6, 'tolerance' => 0.2, 'last_use' => self::at(90, 0.0)]],
            'last_tick' => self::at(90, 0.0)]]);
        RelDynSubstances::update('Lynly Star-Sung', $d, self::at(90, 20.0));
        $lines = RelDynSubstances::feltLines('Lynly Star-Sung', $d, $vars);
        $keys = array_column($lines, 'key');
        $this->assertContains('withdrawal', $keys);
        $this->drink($d, self::at(90, 21.0), 5, 'ale', 'Lynly Star-Sung');
        $lines = RelDynSubstances::feltLines('Lynly Star-Sung', $d, $vars);
        $this->assertContains('drunk', array_column($lines, 'key'));
        foreach ($lines as $l) {
            $this->assertStringContainsString('Lynly Star-Sung', $l['text']);
            $this->assertDoesNotMatchRegularExpression('/\d/', $l['text']);
        }
        $j = RelDynSubstances::jev($d, self::at(90, 21.0));
        $this->assertSame('drunk', $j['stage']);
        $this->assertGreaterThan(3.0, $j['effective_drinks']);
        $this->assertLessThan(0.0, $j['held']['maturity']);
        $this->assertTrue($j['addicted']);
        $this->assertGreaterThan(0.6, $j['uses']['alcohol']['dependence']);
        $this->assertSame(25.0, $j['maturity_ceiling']);
        $this->assertStringContainsString('substances=drunk', RelDynJev::render(['substances' => $j] + $this->jevSkeleton()));
        $this->assertNull(RelDynSubstances::jev($this->npc(50.0), self::at(90, 21.0)), 'nothing of it: null');
    }

    /** The minimal Jev state render() reads besides the substances block. */
    private function jevSkeleton(): array
    {
        $d = $this->npc(50.0);
        $s = RelDynJev::state('Probe', $d, self::at(90, 21.0));
        unset($s['substances']);
        return $s;
    }

    public function testASaveLoadForgetsTheDrinksItNeverLived(): void
    {
        $d = $this->npc(65.0);
        $t0 = self::at(95, 20.0);
        $this->drink($d, $t0, 2);
        $this->drink($d, $t0 + 2 * self::HOUR, 3);
        $d['_substances'] = RelDynSubstances::rebaseline($d['_substances'], $t0 + self::HOUR);
        $this->assertCount(2, $d['_substances']['drinks']);
        RelDynSubstances::update('Aela the Huntress', $d, $t0 + self::HOUR);
        $this->assertEqualsWithDelta(65.0 - RelDynSubstances::maturityDrop(2 - 5 / 6, [1, 3, 8, 10, 10]), self::x($d, 'maturity'), 0.01,
            'the held drink re-held from what is left');
        $this->assertLessThanOrEqual($t0 + self::HOUR, $d['_substances']['use']['alcohol']['last_use']);
    }

    public function testOffTakesBackWhatIsHeld(): void
    {
        $d = $this->npc(65.0);
        $this->drink($d, self::at(96, 20.0), 5);
        $this->assertLessThan(40.0, self::x($d, 'maturity'));
        $this->db->config = ['substances' => ['enabled' => false]];
        RelationshipDynamics::clearConfigCache();
        RelDynSubstances::update('Aela the Huntress', $d, self::at(96, 20.5));
        $this->assertEqualsWithDelta(65.0, self::x($d, 'maturity'), 1e-9);
        $this->assertNull(RelDynSubstances::shifts($d));
        $this->assertSame(['spike_mult' => 1.0, 'substance' => null, 'drinks' => 0.0], RelDynSubstances::onConsume('Aela the Huntress', $d, 'ale', self::at(96, 21.0)));
    }
}
