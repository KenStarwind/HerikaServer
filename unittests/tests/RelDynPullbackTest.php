<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/** Stand-in for $db holding core's conf_opts rows in memory (the config store's SELECT and upsert). */
final class RelDynPullbackRowDb
{
    public array $rows = [];

    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (stripos($sql, 'INSERT INTO conf_opts') !== false) {
            $this->rows[(string) $params[0]] = (string) $params[1];
            return ['id' => (string) $params[0]];
        }
        if (stripos($sql, 'FROM conf_opts') !== false) {
            $id = is_array($params) ? (string) $params[0] : (preg_match("/id = '([^']+)'/", $sql, $m) ? $m[1] : '');
            return isset($this->rows[$id]) ? ['value' => $this->rows[$id]] : [];
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * Let in, and pulling back (Ken 2026-10-01: "closed off" is neither a hard pass / fail nor permanent;
 * reldyn_pullback.php). No database but a config stand-in: the defaults and nothing stored. The test beds
 * through the real hooks are RelDynPullbackTestBedsPostgresTest.
 *
 * Units: let-in and dimensions points 0..100, pressure / thresholds / inputs 0..1, raw gamets (1 game day =
 * GAMETS_PER_DAY).
 */
final class RelDynPullbackTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;

    private array $saved = [];
    private string $logFile;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdpullback');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function storeConfig(array $row): void
    {
        $db = new RelDynPullbackRowDb();
        $db->rows[RelationshipDynamics::CONFIG_ROW_ID] = json_encode($row + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * An NPC toward the player: its own trait vector, maturity, the let-in's comfort and trust, core affinity and type,
     * attachment style; the weather and the relationship deprivation (the fallback the fulfillment state would give).
     */
    private function npc(array $traits, float $maturity, float $comfort = 70.0, float $trust = 75.0, array $extra = [], string $attachment = 'secure',
                         float $coreAff = 70.0, string $coreType = 'platonic'): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], $traits);
        $x['maturity_start'] = $maturity;
        $mirror = ($coreAff + 100.0) / 2.0;
        $d = $extra + [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            '_core_rel_type' => $coreType,
            '_aff_mirror_x' => $mirror,
            'profile_overrides' => ['attachment_style' => $attachment],
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
        ];
        foreach (['affinity' => $mirror, 'trust' => $trust, 'maturity' => $maturity, 'comfort' => $comfort, 'respect' => 50.0,
                  'self_confidence' => 50.0, 'resentment' => 0.0] as $dim => $v) {
            $d['dimensions'][$dim] = ['x' => $v, 'baseline' => $dim === 'resentment' ? 0.0 : $v];
        }
        return $d;
    }

    /** The weather and how unfulfilled she is, as the weather update leaves them. */
    private static function weather(array &$d, string $weather, float $deprivation, float $valenceHeld = 0.0): void
    {
        $d['_internal_weather'] = $weather;
        $d['_weather_state'] = ['pressure' => 0.0, 'relationship_deprivation' => $deprivation];
        $d['_weather_gravity'] = ['weather' => $weather, 'offsets' => $valenceHeld != 0.0 ? ['valence' => $valenceHeld] : [], 'applied' => []];
    }

    private static function at(float $day, float $hour = 0.0): float
    {
        return $day * self::DAY + $hour * self::HOUR;
    }

    // ------------------------------------------------------------------ let in

    public function testLetInIsComfortAndTrustInAnyBondAndNeverPassion(): void
    {
        $this->assertEqualsWithDelta(72.0, RelDynPullback::letInOf(64.0, 81.0), 1e-9, 'sqrt(comfort x trust)');
        $this->assertSame(0.0, RelDynPullback::letInOf(0.0, 90.0));
        // a devoted friend with no passion at all: let in
        $friend = $this->npc([], 55.0, 70.0, 80.0, [], 'secure', 70.0, 'platonic');
        $friend['dimensions']['passion'] = ['x' => 0.0, 'baseline' => 0.0];
        $this->assertEqualsWithDelta(sqrt(70.0 * 80.0), RelDynPullback::letIn($friend), 0.01);
        $this->assertFalse(RelDynPullback::notLetInYet($friend));
        // the same comfort and trust at any passion: the same let-in, and in any bond type
        $lover = $friend;
        $lover['dimensions']['passion']['x'] = 90.0;
        $lover['_core_rel_type'] = 'romantic';
        $this->assertSame(RelDynPullback::letIn($friend), RelDynPullback::letIn($lover));
        // the derived warmth (passion x comfort) of the same friend is nothing: the old rule's reading, "closed" for good
        $this->assertLessThan(1.0, RelDynPassion::warmth($friend, false) ?? 0.0);
        // a newly met NPC, at her temperament's baseline comfort and trust, has not let them in yet
        $new = $this->npc([], 55.0, 35.0, 35.0);
        $this->assertTrue(RelDynPullback::notLetInYet($new));
        // the dimensions missing: the middle
        $this->assertEqualsWithDelta(50.0, RelDynPullback::letIn(['dimensions' => []]), 1e-9);
    }

    // ------------------------------------------------------------------ hysteresis

    public function testHysteresisEntersAboveOnLeavesBelowOffAndHoldsBetween(): void
    {
        $thr = ['on' => 0.6, 'off' => 0.35];
        $this->assertFalse(RelDynPullback::decide(false, 0.5, $thr, 70.0), 'between the thresholds, not pulled back: stays open');
        $this->assertTrue(RelDynPullback::decide(false, 0.61, $thr, 70.0), 'above on: pulls back');
        $this->assertTrue(RelDynPullback::decide(true, 0.5, $thr, 70.0), 'between the thresholds, pulled back: stays pulled back');
        $this->assertTrue(RelDynPullback::decide(true, 0.36, $thr, 70.0));
        $this->assertFalse(RelDynPullback::decide(true, 0.35, $thr, 70.0), 'at or below off: opens up');
        $this->assertFalse(RelDynPullback::decide(false, 0.9, $thr, 20.0), 'she has not let the player in: nothing to pull back from');
    }

    public function testAnInputThatFlickersAroundOnDoesNotFlickerTheState(): void
    {
        $d = $this->npc(['G' => 0.5], 80.0);   // mature: mood gain 0.4
        $now = self::at(300, 8.0);
        $trace = [];
        $transitions = 0;
        $was = false;
        // a stormy stretch whose unmet needs wobble every few game hours across the on-threshold (about 0.6 at her let-in)
        // and then across the off-threshold (0.35): the state enters once and leaves once
        $wobble = [0.2, 0.95, 0.5, 0.95, 0.5, 0.95, 0.5, 0.95, 0.5, 0.95, 0.5, 0.95, 0.5, 0.95, 0.5,
                   0.25, 0.05, 0.25, 0.05, 0.25, 0.05, 0.25, 0.05, 0.25, 0.05, 0.25, 0.05, 0.25, 0.05, 0.25, 0.05, 0.25, 0.05];
        foreach ($wobble as $i => $deficit) {
            self::weather($d, 'stormy', $deficit, -15.0);
            $r = RelDynPullback::advance('Ysgerd', $d, $now + $i * 3 * self::HOUR);
            $trace[] = [$deficit, $r['pressure'], $r['active']];
            if ($r['active'] !== $was) $transitions++;
            $was = $r['active'];
        }
        $this->assertSame(2, $transitions, 'one in, one out: ' . json_encode($trace));
        $this->assertFalse($was, json_encode($trace));
    }

    // ------------------------------------------------------------------ maturity

    public function testMaturityScalesHowMuchTheWeatherDrivesItNeverToZero(): void
    {
        $g = fn(float $w) => RelDynPullback::moodGain($w);
        $this->assertGreaterThan($g(0.5), $g(0.0));
        $this->assertGreaterThan($g(1.0), $g(0.5));
        $this->assertGreaterThan(0.0, $g(1.0), 'high maturity damps it, never to zero');
        $stormy = ['weather' => 1.0, 'gravity' => 1.0, 'deficit' => 0.0, 'grievance' => 0.0];
        $on = RelDynPullback::thresholds(50.0, 0.5)['on'];
        $this->assertGreaterThanOrEqual($on, RelDynPullback::target($stormy, $g(0.0)), 'an immature NPC lets a storm decide on its own');
        $this->assertLessThan($on, RelDynPullback::target($stormy, $g(1.0)), 'a mature one does not');
        $this->assertGreaterThan(0.0, RelDynPullback::target($stormy, $g(1.0)));
        // and through the state: the same weather alone, two maturities
        $out = [];
        foreach ([80.0 => 'mature', 25.0 => 'immature'] as $m => $label) {
            $d = $this->npc([], $m);
            self::weather($d, 'stormy', 0.0, -15.0);
            RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
            $r = RelDynPullback::advance('Ysgerd', $d, self::at(300, 20.0));
            $out[$label] = $r;
        }
        $this->assertTrue($out['immature']['active'], 'the immature one pulls back on weather alone');
        $this->assertFalse($out['mature']['active']);
        $this->assertGreaterThan($out['mature']['pressure'], $out['immature']['pressure']);
    }

    public function testAMatureNPCStillPullsBackWhenTheWeatherAndUnmetNeedsMeet(): void
    {
        $d = $this->npc([], 80.0);
        self::weather($d, 'stormy', 0.85, -15.0);
        RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
        $r = RelDynPullback::advance('Ysgerd', $d, self::at(301, 8.0));
        $this->assertTrue($r['active'], json_encode($d['_pullback']));
        $e = RelDynPullback::expression($d);
        $this->assertSame('mature', $e['band']);
    }

    // ------------------------------------------------------------------ guard and let-in

    public function testGuardLowersTheThresholdAndADeeperLetInRaisesIt(): void
    {
        $at = fn(float $letIn, float $g) => RelDynPullback::thresholds($letIn, $g)['on'];
        $this->assertLessThan($at(60.0, 0.2), $at(60.0, 0.9), 'a guarded NPC pulls back sooner');
        $this->assertGreaterThan($at(50.0, 0.5), $at(80.0, 0.5), 'a deeper let-in takes more');
        $this->assertLessThan($at(50.0, 0.5), $at(40.0, 0.5));
        foreach ([[40.0, 0.9], [90.0, 0.1], [60.0, 0.5]] as [$l, $g]) {
            $t = RelDynPullback::thresholds($l, $g);
            $this->assertLessThan($t['on'], $t['off'], 'off sits under on');
            $this->assertGreaterThan(0.0, $t['off']);
        }
        // the same pressure on two NPCs, one guarded and one open: only the guarded one pulls back
        $in = [];
        foreach ([[0.9, 'guarded'], [0.1, 'open']] as [$G, $label]) {
            $d = $this->npc(['G' => $G], 80.0);
            self::weather($d, 'clear', 0.85);   // a deficit and nothing else: target 0.51 for a mature NPC
            RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
            $in[$label] = RelDynPullback::advance('Ysgerd', $d, self::at(300, 22.0));
        }
        $this->assertTrue($in['guarded']['active'], json_encode($in));
        $this->assertFalse($in['open']['active'], json_encode($in));
    }

    // ------------------------------------------------------------------ the state across turns

    public function testItEntersPersistsAndFadesAsTheInputsEase(): void
    {
        $d = $this->npc([], 80.0);
        $t0 = self::at(300, 8.0);
        self::weather($d, 'clear', 0.0);
        $r = RelDynPullback::advance('Ysgerd', $d, $t0);
        $this->assertFalse($r['active']);
        // a stormy, unfulfilled stretch
        self::weather($d, 'stormy', 0.9, -15.0);
        $states = [];
        foreach ([6, 12, 24, 36, 48] as $h) $states[$h] = RelDynPullback::advance('Ysgerd', $d, $t0 + $h * self::HOUR);
        $this->assertTrue($states[48]['active'], json_encode($states));
        $since = floatval($d['_pullback']['since_gamets']);
        $this->assertGreaterThan($t0, $since);
        $this->assertSame(1, $d['_pullback']['episodes']);
        $this->assertSame('enter', $d['_pullback']['say'][0]['key'], 'entering is queued to be said');
        // it persists across turns while the stretch goes on, and keeps its since stamp
        foreach ([60, 72] as $h) $this->assertTrue(RelDynPullback::advance('Ysgerd', $d, $t0 + $h * self::HOUR)['active']);
        $this->assertSame($since, floatval($d['_pullback']['since_gamets']));
        // the weather clears and her needs are covered: it eases, no single turn flips it
        self::weather($d, 'sunny', 0.0, 0.0);
        $active = [];
        foreach ([76, 80, 88, 100, 124, 160, 200] as $h) $active[$h] = RelDynPullback::advance('Ysgerd', $d, $t0 + $h * self::HOUR)['active'];
        $this->assertTrue($active[76], 'a few hours of sun do not undo it: ' . json_encode($active));
        $this->assertFalse($active[200], 'it fades: ' . json_encode($active));
        $this->assertGreaterThan($since, floatval($d['_pullback']['ended_gamets']));
        $this->assertFalse(RelDynPullback::active($d));
    }

    public function testNoGameClockOrTheSwitchOffChangesNothing(): void
    {
        $d = $this->npc([], 25.0);
        self::weather($d, 'stormy', 1.0, -15.0);
        $this->assertSame(['entered' => false, 'left' => false, 'pressure' => 0.0, 'active' => false], RelDynPullback::advance('Ysgerd', $d, 0.0));
        $this->assertArrayNotHasKey('_pullback', $d);
        $this->storeConfig(['pullback' => ['enabled' => false]]);
        $r = RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
        $this->assertFalse($r['active']);
        $this->assertArrayNotHasKey('_pullback', $d);
    }

    // ------------------------------------------------------------------ being met

    public function testAMatureNPCWhoVoicedItReopensFasterWhenMet(): void
    {
        $relief = [];
        foreach (['mature voiced' => [80.0, true], 'mature unvoiced' => [80.0, false], 'immature' => [25.0, false]] as $label => [$m, $voiced]) {
            $d = $this->npc([], $m);
            self::weather($d, 'stormy', 0.9, -15.0);
            RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
            $this->assertTrue(RelDynPullback::advance('Ysgerd', $d, self::at(301, 8.0))['active'], $label);
            if ($voiced) {
                $said = RelDynPullback::takeFeltLines($d, 'Ysgerd', 'Kaida', self::at(301, 8.0), true);
                $this->assertSame('enter', $said['lines'][0]['key']);
                $this->assertTrue($d['_pullback']['voiced']);
            }
            $before = $d['_pullback']['pressure'];
            $item = ['tags' => ['reassurance'], 'positive_interaction' => true, 'significance' => 0.6, 'goal_addressed' => false, 'gamets' => self::at(301, 9.0)];
            $relief[$label] = RelDynPullback::onEvalItem('Ysgerd', $item, $d, self::at(301, 9.0));
            $this->assertEqualsWithDelta($before - $relief[$label], $d['_pullback']['pressure'], 1e-3, $label);
        }
        $this->assertGreaterThan($relief['mature unvoiced'], $relief['mature voiced']);
        $this->assertGreaterThan($relief['immature'], $relief['mature unvoiced']);
        $this->assertGreaterThan(0.0, $relief['immature'], 'an immature NPC is met a little too');
        // not met: a neutral exchange, nothing; not pulled back: nothing
        $d = $this->npc([], 80.0);
        self::weather($d, 'stormy', 0.9, -15.0);
        RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
        RelDynPullback::advance('Ysgerd', $d, self::at(301, 8.0));
        $this->assertSame(0.0, RelDynPullback::onEvalItem('Ysgerd', ['tags' => ['help'], 'positive_interaction' => false, 'significance' => 0.5], $d, self::at(301, 9.0)));
        $this->assertGreaterThan(0.0, RelDynPullback::onEvalItem('Ysgerd', ['tags' => [], 'positive_interaction' => false, 'goal_addressed' => true, 'significance' => 0.5], $d, self::at(301, 9.0)));
        $calm = $this->npc([], 80.0);
        $this->assertSame(0.0, RelDynPullback::onEvalItem('Ysgerd', ['tags' => ['reassurance'], 'positive_interaction' => true, 'significance' => 0.5], $calm, self::at(301, 9.0)));
    }

    // ------------------------------------------------------------------ expression: one mechanism

    public function testHowItShowsFollowsMaturityTraitsAndTheAttachmentCorner(): void
    {
        $mk = function (float $m, array $traits, string $attach = 'secure') {
            $d = $this->npc($traits, $m, 70.0, 75.0, [], $attach);
            self::weather($d, 'stormy', 0.95, -15.0);
            RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
            RelDynPullback::advance('Ysgerd', $d, self::at(301, 8.0));
            return $d;
        };
        $text = fn(array $d) => RelDynPullback::standingText('Ysgerd', 'Kaida', $d);

        $mature = $mk(80.0, []);
        $this->assertSame('mature', RelDynPullback::expression($mature)['band']);
        $this->assertStringContainsString('has said so', $text($mature), 'she voices it plainly');
        $this->assertStringContainsString('the ease there used to be between them', $text($mature), 'and names what is missing');

        $mixed = $mk(50.0, ['L' => 0.8]);
        $this->assertSame('mixed', RelDynPullback::expression($mixed)['band']);
        $this->assertStringContainsString('comes out sharp', $text($mixed));
        $this->assertStringContainsString('as an accusation', $text($mixed));

        $fighter = $mk(25.0, ['L' => 0.9, 'Pd' => 0.7, 'E' => 0.6]);
        $this->assertSame('immature', RelDynPullback::expression($fighter)['band']);
        $this->assertSame('accusation', RelDynPullback::expression($fighter)['style']);
        $this->assertStringContainsString('Spoiling for a fight', $text($fighter));
        $pouter = $mk(25.0, ['L' => 0.3, 'E' => 0.2, 'C' => 0.3]);
        $this->assertSame('sulking', RelDynPullback::expression($pouter)['style']);
        $this->assertStringContainsString('Pouting', $text($pouter));

        // the attachment corner colours it, mature or not
        $anxious = $mk(25.0, ['L' => 0.3, 'E' => 0.2], 'anxious');
        $avoidant = $mk(25.0, ['L' => 0.3, 'E' => 0.2], 'avoidant');
        $this->assertSame('anxious', RelDynPullback::expression($anxious)['attachment']);
        $this->assertSame('avoidant', RelDynPullback::expression($avoidant)['attachment']);
        $this->assertStringContainsString('needing to be told', $text($anxious));
        $this->assertStringContainsString('quiet and distant', $text($avoidant));
        $this->assertStringNotContainsString('quiet and distant', $text($pouter));
        $matureAvoidant = $mk(80.0, [], 'avoidant');
        $this->assertStringContainsString('quietly and briefly', $text($matureAvoidant), 'mature and avoidant: says it, quietly');
        $matureAnxious = $mk(80.0, [], 'anxious');
        $this->assertStringContainsString('asks to be reassured', $text($matureAnxious));

        // felt text only: no digits, no hard-coded pronoun for her, her name only sparingly
        foreach ([$mature, $mixed, $fighter, $pouter, $anxious, $avoidant, $matureAvoidant, $matureAnxious] as $d) {
            $t = $text($d);
            $this->assertDoesNotMatchRegularExpression('/\d/', $t);
            $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|he|him|his)\b/i', $t);
            $this->assertLessThanOrEqual(2, substr_count($t, 'Ysgerd'), $t);
        }
    }

    public function testTheEnteringAndTheReopeningAreSaidOnceToThePlayersFaceByHowSheIs(): void
    {
        $mk = function (float $m, array $traits, string $attach = 'secure') {
            $d = $this->npc($traits, $m, 70.0, 75.0, [], $attach);
            self::weather($d, 'stormy', 0.95, -15.0);
            RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
            RelDynPullback::advance('Ysgerd', $d, self::at(301, 8.0));
            return $d;
        };
        $said = function (array &$d, bool $addressed = true) {
            $out = [];
            foreach (RelDynPullback::takeFeltLines($d, 'Ysgerd', 'Kaida', self::at(301, 9.0), $addressed)['lines'] as $l) $out[$l['key']] = $l['text'];
            return $out;
        };
        $mature = $mk(80.0, []);
        $this->assertSame([], $said($mature, false), 'not said over the player\'s head: it waits for them to speak to her');
        $line = $said($mature)['enter'];
        $this->assertStringContainsString('tells Kaida plainly', $line);
        $this->assertSame([], $said($mature), 'once');
        $this->assertTrue($mature['_pullback']['voiced']);

        $mixed = $mk(50.0, ['L' => 0.8]);
        $enter = $said($mixed)['enter'];
        $this->assertStringContainsString('means to say evenly', $enter);
        $this->assertStringContainsString('as an accusation', $enter);
        $this->assertTrue($mixed['_pullback']['voiced'], 'the one who meant to say it evenly has still said it');
        $fighter = $mk(25.0, ['L' => 0.9]);
        $this->assertStringContainsString('picks at Kaida', $said($fighter)['enter']);
        $this->assertFalse($fighter['_pullback']['voiced']);
        $pouter = $mk(25.0, ['L' => 0.3, 'E' => 0.2, 'C' => 0.3]);
        $this->assertStringContainsString('withdraws from Kaida', $said($pouter)['enter']);

        // she opens up again: said, once, by how mature she is
        self::weather($mature, 'sunny', 0.0, 0.0);
        self::weather($pouter, 'sunny', 0.0, 0.0);
        foreach ([4, 10, 20] as $day) {
            RelDynPullback::advance('Ysgerd', $mature, self::at(301 + $day, 8.0));
            RelDynPullback::advance('Ysgerd', $pouter, self::at(301 + $day, 8.0));
        }
        $this->assertFalse(RelDynPullback::active($mature));
        $this->assertStringContainsString('what was missing has been given', $said($mature)['reopen']);
        $this->assertStringContainsString('grudgingly', $said($pouter)['reopen']);
        // a pull-back she never showed has no reopening to say
        $quiet = $mk(80.0, []);
        self::weather($quiet, 'sunny', 0.0, 0.0);
        RelDynPullback::advance('Ysgerd', $quiet, self::at(330, 8.0));
        $this->assertFalse(RelDynPullback::active($quiet));
        $this->assertSame([], $said($quiet));
    }

    // ------------------------------------------------------------------ the bridge in knowledge_of_player

    private function atCore(float $coreAff, array $dims = [], int $hwm = 0): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $mirror = ($coreAff + 100) / 2;
        $d['dimensions']['affinity']['x'] = $mirror;
        $d['_aff_mirror_x'] = $mirror;
        $d['context_tier_hwm'] = $hwm;
        foreach ($dims as $k => $v) $d['dimensions'][$k]['x'] = $v;
        return $d;
    }

    public function testADevotedBondWithNoPassionIsNotClosedAndANewOneIsNotLetInYet(): void
    {
        // the old rule: no passion, so warmth 0: "cares, closed" for good, at any comfort and trust
        $devoted = RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(80, ['passion' => 0.0, 'comfort' => 70.0, 'trust' => 80.0]));
        $this->assertStringNotContainsString('let in', $devoted);
        $this->assertStringNotContainsString('lets show', $devoted);
        $this->assertStringContainsString('deeply', $devoted);
        // not let in yet, any tier: an acquaintance, and a friend
        foreach ([15 => 'by name', 45 => 'well'] as $aff => $level) {
            $t = RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore($aff, ['passion' => 0.0, 'comfort' => 30.0, 'trust' => 40.0]));
            $this->assertStringContainsString($level, $t);
            $this->assertStringContainsString('has not let Kaida in yet', $t, "aff {$aff}");
            $this->assertStringContainsString('has to be earned', $t, 'not "never"');
        }
        // let in: the bridge is gone, and so is it at the same comfort and trust with a lot of passion
        $this->assertStringNotContainsString('let Kaida in', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(45, ['passion' => 60.0, 'comfort' => 60.0, 'trust' => 60.0])));
    }

    public function testDrawnButGuardedReadsLetInNotPassionDerivedWarmth(): void
    {
        // passion 70 with comfort 20 and trust 50: let-in 31.6 (guarded)
        $this->assertStringContainsString('fighting it',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(45, ['passion' => 70.0, 'comfort' => 20.0, 'trust' => 50.0])));
        // the same passion with comfort and trust that have let them in: no longer guarded (old rule: warmth
        // sqrt(70 x 45) = 56, also not guarded; this one is the case the new reading changes: comfort 30, trust 90)
        $d = $this->atCore(45, ['passion' => 70.0, 'comfort' => 30.0, 'trust' => 90.0]);   // warmth 45.8, let-in 51.9
        $this->assertStringNotContainsString('fighting it', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $d));
        // and low passion-derived warmth no longer reads guarded: passion 56, comfort 45, trust 90: warmth 50, let-in 63
        $this->assertStringNotContainsString('fighting it',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(45, ['passion' => 56.0, 'comfort' => 45.0, 'trust' => 90.0])));
    }

    public function testPullingBackTakesOverTheBridgeOnlyOnceLetIn(): void
    {
        $d = $this->atCore(80, ['passion' => 0.0, 'comfort' => 70.0, 'trust' => 80.0, 'maturity' => 80.0]);
        $d['_pullback'] = ['v' => 1, 'pressure' => 0.8, 'active' => true, 'since_gamets' => 1000.0, 'gamets' => 1000.0, 'say' => []];
        $t = RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $d);
        $this->assertStringContainsString('pulled back from Kaida for now', $t);
        $this->assertStringNotContainsString('has not let Kaida in yet', $t);
        // pulled back, but she has not let them in: that is the not-yet line (nothing to pull back from)
        $new = $this->atCore(45, ['passion' => 0.0, 'comfort' => 30.0, 'trust' => 40.0]);
        $new['_pullback'] = $d['_pullback'];
        $nt = RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $new);
        $this->assertStringContainsString('has not let Kaida in yet', $nt);
        $this->assertStringNotContainsString('pulled back', $nt);
        // the state over, the line is gone
        $d['_pullback']['active'] = false;
        $this->assertStringNotContainsString('pulled back', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $d));
    }

    public function testTheOtherBridgesStandAsTheyWere(): void
    {
        $k = fn(float $aff, array $dims) => RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore($aff, $dims));
        $this->assertStringContainsString('one eye open', $k(45, ['passion' => 45.0, 'comfort' => 50.0, 'trust' => 20.0]), 'a distrusting friend still reads wary');
        $this->assertStringContainsString('worn thin', $k(60, ['passion' => 45.0, 'comfort' => 50.0, 'resentment' => 70.0]));
        $this->assertStringContainsString('never quite at ease', $k(60, ['passion' => 55.0, 'trust' => 60.0, 'comfort' => 30.0]));
        $this->assertStringContainsString('nothing left to prove', $k(85, ['passion' => 40.0, 'comfort' => 60.0, 'trust' => 80.0, 'resentment' => 0.0]));
    }

    public function testSwitchedOffTheBridgeIsTheOldRuleAndTheOldTextExactly(): void
    {
        $this->storeConfig(['pullback' => ['enabled' => false]]);
        $closed = RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(80, ['passion' => 0.0, 'comfort' => 50.0]));
        $this->assertStringEndsWith(' Cares more than Serana lets show; the feeling is there, the openness is not.', $closed);
        // the old rule is permanent and passion-bound: a devoted bond with no passion reads closed whatever comfort and trust
        $devoted = RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(80, ['passion' => 0.0, 'comfort' => 70.0, 'trust' => 80.0]));
        $this->assertStringContainsString('lets show', $devoted);
        // ... and tier 1 reads no bridge, and a stored pull-back state is ignored
        $this->assertStringNotContainsString('let Kaida in', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(15, ['passion' => 0.0, 'comfort' => 30.0, 'trust' => 30.0])));
        $d = $this->atCore(80, ['passion' => 50.0, 'comfort' => 70.0, 'trust' => 80.0]);
        $d['_pullback'] = ['v' => 1, 'pressure' => 0.9, 'active' => true, 'since_gamets' => 1.0, 'gamets' => 1.0, 'say' => []];
        $this->assertStringNotContainsString('pulled back', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $d));
        // the old guarded half: derived warmth
        $this->assertStringContainsString('fighting it', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(45, ['passion' => 70.0, 'comfort' => 20.0])));
        // switched back on (the shipped default): the same bond is not closed
        $this->storeConfig([]);
        $this->assertStringNotContainsString('lets show', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(80, ['passion' => 0.0, 'comfort' => 70.0, 'trust' => 80.0])));
    }

    // ------------------------------------------------------------------ Jev, settings

    public function testJevCarriesLetInAndThePullBackState(): void
    {
        $d = $this->npc(['L' => 0.3, 'E' => 0.2], 25.0, 70.0, 75.0, [], 'avoidant');
        self::weather($d, 'stormy', 0.95, -15.0);
        RelDynPullback::advance('Ysgerd', $d, self::at(300, 8.0));
        RelDynPullback::advance('Ysgerd', $d, self::at(301, 8.0));
        $j = RelDynPullback::jev($d, self::at(301, 20.0));
        $this->assertTrue($j['active']);
        $this->assertEqualsWithDelta(round(sqrt(70.0 * 75.0), 2), $j['let_in'], 0.01);
        $this->assertEqualsWithDelta(36.0, $j['since_game_hours'], 0.1, 'since the first sight of the stormy, unfulfilled NPC (day 300, 08:00)');
        $this->assertSame(['immature', 'sulking', 'avoidant'], [$j['band'], $j['style'], $j['attachment']]);
        foreach (['weather', 'gravity', 'deficit', 'grievance'] as $k) $this->assertArrayHasKey($k, $j['inputs']);
        $this->assertGreaterThan($j['off'], $j['pressure']);
        $s = RelDynJev::state('Ysgerd', $d, self::at(301, 20.0));
        $this->assertEqualsWithDelta($j['let_in'], $s['let_in'], 1e-9);
        $this->assertSame($j, $s['pullback']);
        $this->assertMatchesRegularExpression('/pullback=on \d\.\d\d\/0\.\d\d let_in=72\.5 immature\/sulking\/avoidant \d+h/', $s['text']);
        // not spent on every prompt: the compact text says nothing of it while she is not pulled back (the structured fields do)
        $calm = $this->npc([], 80.0);
        self::weather($calm, 'clear', 0.0);
        RelDynPullback::advance('Ysgerd', $calm, self::at(300, 8.0));
        $c = RelDynJev::state('Ysgerd', $calm, self::at(300, 9.0));
        $this->assertStringNotContainsString('pullback', $c['text']);
        $this->assertFalse($c['pullback']['active']);
        $this->assertGreaterThan(40.0, $c['let_in']);
        $this->assertArrayHasKey('pullback.pressure', RelDynJev::UNITS);
        $this->assertArrayHasKey('let_in', RelDynJev::UNITS);
    }

    public function testTheSettingsHubKnowsTheNewSectionAndSavesItPartially(): void
    {
        $this->assertArrayHasKey('pullback', RelDynSettings::defaults());
        $this->assertArrayHasKey('pullback', RelDynSettings::readers());
        $fields = RelDynSettings::fields();
        $by = [];
        foreach ($fields as $f) $by[$f['dotted']] = $f;
        foreach (['pullback.enabled', 'pullback.let_in.low', 'pullback.threshold.on', 'pullback.threshold.gap', 'pullback.mood_gain.immature',
                     'pullback.rates.rise_per_game_hour', 'pullback.met.relief', 'pullback.weights.deficit'] as $dotted) {
            $this->assertArrayHasKey($dotted, $by);
            $this->assertNotSame('', trim((string) $by[$dotted]['hint']), $dotted);
        }
        $this->assertStringContainsString('inclement', RelDynSettingsText::SECTION_HELP['pullback']);
        $this->assertSame('inner', RelDynSettings::GROUP_KEYS['pullback']);
        // every number is a config key: nothing hard-coded to tune
        $cfg = RelDynPullback::configDefaults();
        foreach (['let_in', 'weights', 'weather_push', 'gravity', 'grievance', 'mood_gain', 'threshold', 'rates', 'met'] as $table) {
            $this->assertNotSame([], $cfg[$table], $table);
        }
        // a stored partial row reads as the whole
        $this->storeConfig(['pullback' => ['threshold' => ['on' => 0.7], 'let_in' => ['low' => 45.0]]]);
        $c = RelDynPullback::config();
        $this->assertSame(0.7, $c['threshold']['on']);
        $this->assertSame($cfg['threshold']['gap'], $c['threshold']['gap']);
        $this->assertEquals(45.0, $c['let_in']['low']);
        $this->assertSame($cfg['felt_text'], $c['felt_text']);
    }
}
