<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * The fearful morning after (rulings 2026-10-01 §22, Ken): after intimacy an NPC who fears closeness (anxious and avoidant, the
 * fearful corner of the attachment axes) pulls back for a while, sized by how fearful they are: an input to the pull-back
 * (reldyn_pullback.php), holding for a night and then fading like any pull-back, a distance and never shame. The pure halves, no
 * database: the defaults, fixed game timestamps. The four test beds through the real hooks are RelDynVPeopleTestBedsPostgresTest.
 */
final class RelDynVPeopleAftermathTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY + 22 * self::HOUR;   // the night, 10 PM

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** A devoted partner (comfort and trust high: let in) with the attachment axes given, the maturity and traits given. */
    private function npc(float $anxiety, float $avoidance, float $maturity = 55.0, array $traits = []): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], $traits);
        $x['maturity_start'] = $maturity;
        $d = [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            '_core_rel_type' => 'romantic',
            '_aff_mirror_x' => 85.0,
            'profile_overrides' => ['attachment_axes' => ['anxiety' => $anxiety, 'avoidance' => $avoidance]],
            'jealousy_anger' => 0.0, 'in_conflict' => false, 'dimensions' => [],
        ];
        foreach (['affinity' => 85.0, 'trust' => 80.0, 'maturity' => $maturity, 'comfort' => 80.0, 'respect' => 60.0, 'self_confidence' => 50.0, 'resentment' => 0.0,
                  'resentment_self' => 0.0, 'passion' => 40.0] as $dim => $v) {
            $d['dimensions'][$dim] = ['x' => $v, 'baseline' => $dim === 'resentment' || $dim === 'resentment_self' ? 0.0 : $v];
        }
        // clear weather, the needs met: nothing but the morning after presses
        $d['_internal_weather'] = 'clear';
        $d['_weather_state'] = ['pressure' => 0.0, 'relationship_deprivation' => 0.0];
        return $d;
    }

    private const FEARFUL = [0.85, 0.85];
    private const ANXIOUS = [0.85, 0.15];
    private const AVOIDANT = [0.15, 0.85];
    private const SECURE = [0.15, 0.15];

    private function bed(array $axes, float $maturity = 55.0, array $traits = []): array
    {
        return $this->npc($axes[0], $axes[1], $maturity, $traits);
    }

    // =====================================================================
    // how fearful: the corners blended at the NPC's own axes
    // =====================================================================

    public function testHowFearfulIsTheCornersBlendedAtTheNpcsAxesNeverALabel(): void
    {
        $f = fn(array $axes) => RelDynPullback::fearfulness($this->bed($axes));
        $this->assertEqualsWithDelta(1.0, $f(self::FEARFUL), 1e-9, 'the fearful corner in full');
        $this->assertGreaterThan($f(self::ANXIOUS), $f(self::AVOIDANT), 'the avoidant a part of it: closeness is what it backs from');
        $this->assertGreaterThan($f(self::SECURE), $f(self::ANXIOUS));
        $this->assertGreaterThan(0.0, $f(self::SECURE), 'no one is immune: secure is small, never zero');
        $this->assertLessThan(0.1, $f(self::SECURE));
        $this->assertGreaterThan(2.0 * $f(self::AVOIDANT), $f(self::FEARFUL), 'meaningfully apart');
        // continuous in the axes: half-way between the avoidant and the fearful corner is half-way between their fear
        $mid = $f([0.5, 0.85]);
        $this->assertGreaterThan($f(self::AVOIDANT), $mid);
        $this->assertLessThan($f(self::FEARFUL), $mid);
        // the knob: a stored table replaces one corner and keeps the others
        $GLOBALS['db'] = new RelDynVPeopleConfigDb(['pullback' => ['aftermath' => ['fearful' => ['avoidant' => 0.9]]]]);
        RelationshipDynamics::clearConfigCache();
        $this->assertEqualsWithDelta(0.9, RelDynPullback::fearfulness($this->bed(self::AVOIDANT)), 1e-9);
        $this->assertEqualsWithDelta(1.0, RelDynPullback::fearfulness($this->bed(self::FEARFUL)), 1e-9);
    }

    // =====================================================================
    // the input: held for a night, then fading
    // =====================================================================

    public function testTheInputHoldsForANightThenFadesToNothing(): void
    {
        $at = ['size' => 0.8, 'last' => self::T0];
        $h = fn(float $hours) => RelDynPullback::aftermathInput($at, self::T0 + $hours * self::HOUR);
        $this->assertEqualsWithDelta(0.8, $h(0.0), 1e-9);
        $this->assertEqualsWithDelta(0.8, $h(12.0), 1e-9, 'held through the night');
        $this->assertLessThan(0.8, $h(13.0));
        $this->assertGreaterThan($h(30.0), $h(20.0), 'fading');
        $this->assertEqualsWithDelta(0.4, $h(12.0 + 12.0), 1e-9, 'half-way through the fade');
        $this->assertSame(0.0, $h(12.0 + 24.0));
        $this->assertSame(0.0, $h(100.0));
        $this->assertSame(0.0, RelDynPullback::aftermathInput(null, self::T0));
        $this->assertSame(0.0, RelDynPullback::aftermathInput($at, 0.0), 'no game clock: nothing');
        // it is an input of the pull-back like the others, and counted in its target by its weight
        $d = $this->bed(self::FEARFUL);
        $d['_pullback'] = ['v' => 1, 'pressure' => 0.0, 'active' => false, 'say' => [], 'episodes' => 0, 'aftermath' => $at];
        $inputs = RelDynPullback::inputs($d, self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta(0.8, $inputs['aftermath'], 1e-4);
        $this->assertEqualsWithDelta(1.0, RelDynPullback::target($inputs, 1.0), 1e-6, 'weighted 2: past half the push it presses in full');
        $this->assertEqualsWithDelta(0.6, RelDynPullback::target(['aftermath' => 0.3], 1.0), 1e-6, 'and below it, by how much');
        $this->assertSame(0.0, RelDynPullback::inputs($this->bed(self::FEARFUL), self::T0)['aftermath'], 'no encounter, no push');
    }

    // =====================================================================
    // sized by how fearful they are, and held from the last request
    // =====================================================================

    public function testIntimacyHandsTheFearOfClosenessToThePullbackSizedByWhoTheNpcIs(): void
    {
        $sizes = [];
        foreach (['fearful' => self::FEARFUL, 'avoidant' => self::AVOIDANT, 'anxious' => self::ANXIOUS, 'secure' => self::SECURE] as $name => $axes) {
            $d = $this->bed($axes);
            $sizes[$name] = RelDynPullback::onIntimacy('Test', $d, self::T0);
            $this->assertEqualsWithDelta($sizes[$name], $d['_pullback']['aftermath']['size'], 1e-9);
            $this->assertEqualsWithDelta(self::T0, $d['_pullback']['aftermath']['last'], 1e-9);
        }
        $this->assertEqualsWithDelta(1.0, $sizes['fearful'], 1e-9, 'the push at full fearfulness');
        $this->assertGreaterThan($sizes['avoidant'], $sizes['fearful']);
        $this->assertGreaterThan($sizes['anxious'], $sizes['avoidant']);
        $this->assertGreaterThan($sizes['secure'], $sizes['anxious']);
        $this->assertGreaterThan(0.0, $sizes['secure'], 'never zero');
        // an encounter goes on: the morning counts from its last request, and a lower size never lowers what is there
        $d = $this->bed(self::FEARFUL);
        RelDynPullback::onIntimacy('Test', $d, self::T0);
        $d['_pullback']['aftermath']['size'] = 0.9;
        RelDynPullback::onIntimacy('Test', $d, self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta(self::T0 + 2 * self::HOUR, $d['_pullback']['aftermath']['last'], 1e-9);
        $this->assertGreaterThanOrEqual(0.9 - 1e-9, $d['_pullback']['aftermath']['size']);
        // the switch
        $GLOBALS['db'] = new RelDynVPeopleConfigDb(['pullback' => ['aftermath' => ['enabled' => false]]]);
        RelationshipDynamics::clearConfigCache();
        $off = $this->bed(self::FEARFUL);
        $this->assertSame(0.0, RelDynPullback::onIntimacy('Test', $off, self::T0));
        $this->assertArrayNotHasKey('aftermath', $off['_pullback'] ?? []);
        $this->assertSame(0.0, RelDynPullback::aftermathInput(['size' => 0.9, 'last' => self::T0], self::T0));
    }

    // =====================================================================
    // through the state: the fearful pull back for a while, the others do not, nobody is shamed
    // =====================================================================

    /** Hourly steps from the night through $hours game hours; returns [hour => active] and the last result. */
    private function hours(array &$d, float $hours, float $step = 1.0): array
    {
        $trace = [];
        for ($h = $step; $h <= $hours + 1e-9; $h += $step) {
            $r = RelDynPullback::advance('Test', $d, self::T0 + $h * self::HOUR);
            $trace[(string) $h] = $r['active'];
        }
        return $trace;
    }

    public function testTheFearfulPullBackTheMorningAfterAndItFadesLikeAnyPullBack(): void
    {
        $d = $this->bed(self::FEARFUL);
        RelDynPullback::advance('Test', $d, self::T0);   // the evening: nothing presses
        $this->assertFalse(RelDynPullback::active($d));
        RelDynPullback::onIntimacy('Test', $d, self::T0);
        $dimsBefore = array_map(fn($v) => $v['x'], $d['dimensions']);
        $trace = $this->hours($d, 20.0);
        $this->assertFalse($trace['1'], 'not at once: it builds over the night');
        $this->assertTrue($trace['12'], 'pulled back by the morning');
        $this->assertTrue($trace['20'], json_encode($trace));
        $this->assertSame('aftermath', $d['_pullback']['say'][0]['cause'], 'said as the morning after');
        $this->assertSame('aftermath', RelDynPullback::cause($d));
        $later = [];
        for ($h = 21.0; $h <= 60.0; $h += 1.0) $later[(string) $h] = RelDynPullback::advance('Test', $d, self::T0 + $h * self::HOUR)['active'];
        $this->assertFalse($later['60'], 'and open again once it has faded: ' . json_encode($later));
        $this->assertNull(RelDynPullback::cause($d));
        $this->assertSame(1, $d['_pullback']['episodes']);
        // distance for a while, not shame: nothing about the NPC themselves or the bond is cut
        $dimsAfter = array_map(fn($v) => $v['x'], $d['dimensions']);
        $this->assertEquals($dimsBefore, $dimsAfter, 'no shame: no resentment toward self, no trust cut, no comfort crash');
        // sized by how fearful: the same night for the others
        $sized = [];
        foreach (['avoidant' => self::AVOIDANT, 'anxious' => self::ANXIOUS, 'secure' => self::SECURE] as $name => $axes) {
            $o = $this->bed($axes);
            RelDynPullback::advance('Test', $o, self::T0);
            RelDynPullback::onIntimacy('Test', $o, self::T0);
            $t = $this->hours($o, 24.0);
            $sized[$name] = ['ever' => in_array(true, $t, true), 'pressure' => RelDynPullback::pressure($o)];
        }
        $this->assertFalse($sized['secure']['ever'], 'a secure NPC does not pull back for the closeness');
        $this->assertFalse($sized['anxious']['ever']);
        $this->assertFalse($sized['avoidant']['ever'], 'a third of the push does not pull back alone, though it is felt');
        $this->assertGreaterThan($sized['secure']['pressure'], $sized['avoidant']['pressure'], 'but it is felt, by how fearful they are');
    }

    public function testWhatElsePressesAddsToItAndKindWordsTakeItOff(): void
    {
        // an avoidant NPC on a cold, unfulfilled stretch: the push tips it over where neither alone would
        $cold = $this->bed(self::AVOIDANT);
        $cold['_internal_weather'] = 'overcast';
        $cold['_weather_state'] = ['pressure' => 0.0, 'relationship_deprivation' => 0.45];
        $alone = $cold;
        RelDynPullback::advance('Test', $alone, self::T0);
        $this->assertFalse(in_array(true, $this->hours($alone, 24.0), true), 'the stretch alone: not pulled back');
        $both = $cold;
        RelDynPullback::advance('Test', $both, self::T0);
        RelDynPullback::onIntimacy('Test', $both, self::T0);
        $this->assertTrue(in_array(true, $this->hours($both, 24.0), true), 'with the night before it: pulled back');
        // met: a reassuring exchange takes half of what is left of the push, pulled back or not
        $d = $this->bed(self::FEARFUL);
        RelDynPullback::advance('Test', $d, self::T0);
        RelDynPullback::onIntimacy('Test', $d, self::T0);
        $this->hours($d, 6.0);
        $before = $d['_pullback']['aftermath']['size'];
        $relief = RelDynPullback::onEvalItem('Test', ['tags' => ['reassurance'], 'positive_interaction' => true, 'significance' => 0.8], $d, self::T0 + 6 * self::HOUR);
        $this->assertEqualsWithDelta(0.5 * $before, $d['_pullback']['aftermath']['size'], 1e-3, 'half of the push left');
        $this->assertSame(0.0, $relief, 'not yet pulled back: no pressure to take off');
        $x = $this->bed(self::FEARFUL);
        RelDynPullback::advance('Test', $x, self::T0);
        RelDynPullback::onIntimacy('Test', $x, self::T0);
        $this->hours($x, 12.0);
        $this->assertTrue(RelDynPullback::active($x));
        $p = RelDynPullback::pressure($x);
        $rel = RelDynPullback::onEvalItem('Test', ['tags' => ['reassurance'], 'positive_interaction' => true, 'significance' => 0.8], $x, self::T0 + 12 * self::HOUR);
        $this->assertGreaterThan(0.0, $rel);
        $this->assertLessThan($p, RelDynPullback::pressure($x));
        $small = $this->bed(self::FEARFUL);
        RelDynPullback::onIntimacy('Test', $small, self::T0);
        $s0 = $small['_pullback']['aftermath']['size'];
        RelDynPullback::onEvalItem('Test', ['tags' => ['insult'], 'significance' => 0.8], $small, self::T0 + self::HOUR);
        $this->assertSame($s0, $small['_pullback']['aftermath']['size'], 'only an exchange that meets them eases it');
    }

    // =====================================================================
    // how it is said: a distance, never shame, never a pronoun of its own
    // =====================================================================

    public function testItIsSaidAsADistanceByHowMatureTheNpcIsAndNeverAsShame(): void
    {
        $said = [];
        foreach (['mature' => [80.0, []], 'mixed' => [50.0, []], 'quarrelsome' => [15.0, ['L' => 0.9, 'Pd' => 0.8]], 'sulky' => [15.0, ['L' => 0.1, 'E' => 0.1, 'C' => 0.1]]] as $name => [$m, $traits]) {
            $d = $this->bed(self::FEARFUL, $m, $traits);
            RelDynPullback::advance('Test', $d, self::T0);
            RelDynPullback::onIntimacy('Test', $d, self::T0);
            $this->hours($d, 14.0);
            $this->assertTrue(RelDynPullback::active($d), $name);
            $this->assertSame('aftermath', RelDynPullback::cause($d), $name);
            $standing = RelDynPullback::standingText('Rowan', 'Kaida', $d);
            $line = RelDynPullback::takeFeltLines($d, 'Rowan', 'Kaida', self::T0 + 14 * self::HOUR)['lines'];
            $this->assertCount(1, $line, $name);
            $said[$name] = ['standing' => $standing, 'enter' => $line[0]['text']];
            foreach ($said[$name] as $which => $text) {
                $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$name} {$which}: feelings, never numbers");
                $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him|himself|herself)\b/i', $text, "{$name} {$which}: no hard-coded pronoun");
                $this->assertDoesNotMatchRegularExpression('/\b(shame|ashamed|regret|mistake|guilt|dirty|used)\b/i', $text, "{$name} {$which}: distance, never shame");
                $this->assertDoesNotMatchRegularExpression('/\{[A-Z]+\}/', $text, "{$name} {$which}: every var filled");
                $this->assertStringNotContainsString('missing', $text, "{$name} {$which}: it is not the weather's missing needs");
            }
            $this->assertStringContainsString('Rowan', $said[$name]['enter'], $name);
            $this->assertStringContainsString('Kaida', $said[$name]['enter'], $name);
        }
        $this->assertCount(4, array_unique(array_column($said, 'enter')), 'four ways of saying it, by who they are');
        $this->assertStringContainsString('plainly', $said['mature']['enter']);
        $this->assertStringContainsString('distance', $said['mature']['enter']);
        // a pull-back that is not the morning after says what it always did
        $w = $this->bed(self::FEARFUL, 80.0);
        $w['_internal_weather'] = 'stormy';
        $w['_weather_state'] = ['pressure' => 0.0, 'relationship_deprivation' => 0.9];
        $w['_weather_gravity'] = ['weather' => 'stormy', 'offsets' => ['valence' => -15.0], 'applied' => []];
        $this->hours($w, 24.0);
        $this->assertTrue(RelDynPullback::active($w));
        $this->assertNull(RelDynPullback::cause($w));
        $this->assertStringContainsString('missing', RelDynPullback::standingText('Rowan', 'Kaida', $w));
    }

    public function testJevCarriesTheNumbers(): void
    {
        $d = $this->bed(self::FEARFUL);
        RelDynPullback::advance('Test', $d, self::T0);
        RelDynPullback::onIntimacy('Test', $d, self::T0);
        $this->hours($d, 14.0);
        $jev = RelDynPullback::jev($d, self::T0 + 14 * self::HOUR);
        $this->assertSame('aftermath', $jev['cause']);
        $this->assertEqualsWithDelta(1.0, $jev['aftermath']['fearfulness'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $jev['aftermath']['size'], 1e-9);
        $this->assertEqualsWithDelta(1.0 * (1.0 - 2.0 / 24.0), $jev['aftermath']['input'], 1e-3, 'two hours into the fade');
        $this->assertEqualsWithDelta($jev['aftermath']['input'], $jev['inputs']['aftermath'], 1e-3);
    }
}

/** Just enough of `sql` for a stored RelDyn config row (the stored settings over the defaults). */
final class RelDynVPeopleConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode(array_merge(RelationshipDynamics::defaultConfig(), $this->config))] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}
