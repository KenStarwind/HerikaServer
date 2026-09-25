<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Personality traits (D:\docs\reldyn-personality-traits-design.md §3): the blend between presets.
 * Phase 1 only ever reads preset points; these pin the math phase 2 will use for NPCs that
 * land in between: Rule I bounded by its table and monotone between presets, Rule R monotone
 * in its owners away from the presets, per-unit clamps (§2.4), the openness rules, the exact
 * maturity-type formula (§3.2) and the design's worked numbers (§4.5, §6.3).
 * Units: traits 0..1; each column in its own unit (RelDynTraits::columns()).
 */
final class RelDynTraitBlendTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);   // shipped config: residual_reach default
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    private static function point(string $preset): array
    {
        return RelDynTraits::points()[$preset];
    }

    /** a + t (b - a) over the 10 traits. */
    private static function lerp(array $a, array $b, float $t): array
    {
        $x = [];
        foreach (RelDynTraits::TRAITS as $code => $_) $x[$code] = $a[$code] + $t * ($b[$code] - $a[$code]);
        return $x;
    }

    private static function vec(array $v): array
    {
        return RelDynTraits::normalizeVector($v);
    }

    /** §3.5: Defiant (+1.0 rage) to Bold (-0.3) under Rule I: monotone (to 1e-3), bounded, still rage at 0.15. */
    public function testDefiantToBoldBleedoutWalkIsMonotoneAndKeepsTheRageNearDefiant(): void
    {
        $def = self::point('Defiant');
        $bold = self::point('Bold');
        $len = RelDynTraits::distance($def, $bold);
        $this->assertEqualsWithDelta(0.515, $len, 0.002);
        $prev = INF;
        $table = RelDynTraits::table('bleedout');
        for ($i = 0; $i <= 40; $i++) {
            $x = self::lerp($def, $bold, $i / 40);
            $v = RelDynTraits::value($x, 'bleedout');
            // monotone up to the pull of the other presets' weights (w = d^-4), well under 0.001 passion points
            $this->assertLessThanOrEqual($prev + 1e-3, $v, "monotone at step {$i}");
            $this->assertGreaterThanOrEqual(min($table) - 1e-12, $v);
            $this->assertLessThanOrEqual(max($table) + 1e-12, $v);
            $prev = $v;
            if ($i / 40 * $len <= 0.15) $this->assertGreaterThan(0.0, $v, sprintf('rage kept %.3f from Defiant', $i / 40 * $len));
        }
        $this->assertSame(1.0, RelDynTraits::value($def, 'bleedout'));
        $this->assertSame(-0.3, RelDynTraits::value($bold, 'bleedout'));
        // design table: +0.92 at 0.15, crossing zero about 0.29 from Defiant
        $this->assertEqualsWithDelta(0.92, RelDynTraits::value(self::lerp($def, $bold, 0.15 / $len), 'bleedout'), 0.02);
        $this->assertEqualsWithDelta(-0.15, RelDynTraits::value(self::lerp($def, $bold, 0.31 / $len), 'bleedout'), 0.03);
    }

    /** Rule I is a convex combination: never outside [min T, max T]; continuous at a preset. */
    public function testRuleIColumnsAreBoundedByTheirTable(): void
    {
        mt_srand(20260924);
        $iCols = array_keys(array_filter(RelDynTraits::columns(), fn($c) => $c['rule'] === 'I'));
        $this->assertContains('bleedout', $iCols);
        $this->assertContains('reunion_mult', $iCols);
        $this->assertContains('baseline_respect', $iCols);
        for ($n = 0; $n < 200; $n++) {
            $x = [];
            foreach (RelDynTraits::TRAITS as $code => $_) $x[$code] = mt_rand(0, 1000) / 1000;
            foreach ($iCols as $col) {
                $t = array_map('floatval', RelDynTraits::table($col));
                $v = RelDynTraits::value($x, $col);
                $this->assertGreaterThanOrEqual(min($t) - 1e-9, $v, $col);
                $this->assertLessThanOrEqual(max($t) + 1e-9, $v, $col);
            }
        }
        foreach ($iCols as $col) {
            foreach (RelDynTraits::points() as $name => $p) {
                $near = $p;
                $near['G'] += 1e-7;
                $this->assertEqualsWithDelta(floatval(RelDynTraits::table($col)[$name]), RelDynTraits::value($near, $col), 1e-6, "{$col} continuous at {$name}");
            }
        }
    }

    /** Rule R is continuous at a preset too (the residual fades in, it does not jump). */
    public function testRuleRColumnsAreContinuousAtThePresets(): void
    {
        foreach (array_keys(array_filter(RelDynTraits::columns(), fn($c) => $c['rule'] === 'R')) as $col) {
            foreach (RelDynTraits::points() as $name => $p) {
                $near = $p;
                $near['W'] = $near['W'] + ($near['W'] < 0.5 ? 1e-7 : -1e-7);
                $this->assertEqualsWithDelta(floatval(RelDynTraits::table($col)[$name]), RelDynTraits::value($near, $col), 1e-4, "{$col} continuous at {$name}");
            }
        }
    }

    /** Away from every preset (beyond rho) Rule R is the pure model: monotone in each owner. */
    public function testRuleRIsMonotoneInItsOwnersAwayFromThePresets(): void
    {
        $base = self::vec(['possessiveness' => 0.325]);   // design §4.5 base vector
        $cases = [
            // column, owner, direction (+1 rises with the owner, -1 falls)
            ['passion_mult', 'E', +1], ['passion_mult', 'D', -1], ['passion_mult', 'G', -1],
            ['jealousy_mult', 'Po', +1], ['baseline_trust', 'G', -1], ['baseline_trust', 'Po', -1],
            ['baseline_comfort', 'C', +1], ['baseline_affinity', 'W', +1], ['tier_retention', 'Po', +1],
            ['warmth_half_life', 'G', +1], ['resist_respect', 'Pd', +1], ['y_maturity_up', 'Rs', +1],
            ['y_maturity_down', 'Rs', -1], ['y_arousal_up', 'L', +1],
        ];
        $rho = RelDynTraits::residualReach();
        foreach ($cases as [$col, $owner, $dir]) {
            $prev = null;
            foreach ([0.44, 0.47, 0.50, 0.53, 0.56] as $v) {
                $x = $base;
                $x[$owner] = $v;
                $near = RelDynTraits::nearestPreset($x);
                $this->assertGreaterThan($rho, $near['distance'], "{$col}/{$owner}={$v} stays off the presets");
                $val = RelDynTraits::value($x, $col);
                if ($prev !== null) {
                    $this->assertGreaterThan(0.0, $dir * ($val - $prev), "{$col} monotone in {$owner} at {$v}");
                }
                $prev = $val;
            }
        }
    }

    /** §2.4: each column is clamped to its unit's legal range, not to its column span. */
    public function testClampsAreByUnit(): void
    {
        $cols = RelDynTraits::columns();
        $extremes = [self::vec(array_fill_keys(array_values(RelDynTraits::TRAITS), 0.0)),
                     self::vec(array_fill_keys(array_values(RelDynTraits::TRAITS), 1.0))];
        foreach ([0.0, 1.0] as $hi) {
            foreach (RelDynTraits::TRAITS as $code => $_) {
                $x = self::vec(array_fill_keys(array_values(RelDynTraits::TRAITS), 1.0 - $hi));
                $x[$code] = $hi;
                $extremes[] = $x;
            }
        }
        foreach ($cols as $col => $spec) {
            [$lo, $up] = RelDynTraits::CLAMPS[$spec['unit']];
            foreach ($extremes as $x) {
                $v = RelDynTraits::value($x, $col);
                $this->assertGreaterThanOrEqual($lo, $v, $col);
                $this->assertLessThanOrEqual($up, $v, $col);
            }
        }
        // clamps that bite: coord_f (W 1 -> model 133 coordinate points), tier retention (Po 1, D 0, Rs 0 -> +4.4),
        // openness (G 0, Pd 0 -> 1.15), and the span is the unit's, not the column's (bleedout table -3..+1)
        $x = self::vec(['warmth' => 1.0, 'expressiveness' => 0.0, 'guard' => 0.0]);
        $this->assertSame(100.0, RelDynTraits::value($x, 'baseline_coord_f'));
        $x = self::vec(['possessiveness' => 1.0, 'restraint' => 0.0, 'resilience' => 0.0]);
        $this->assertSame(0.0, RelDynTraits::value($x, 'tier_retention'));
        $open = RelDynTraits::opennessAt(self::vec(['guard' => 0.0, 'pride' => 0.0]),
            RelDynAttraction::defaults()['temperament_openness'], RelDynAttraction::defaults()['openness_levels']);
        $this->assertSame(0.9, $open['o']);
        $this->assertSame('high', $open['band']);
        $this->assertSame([-5.0, 5.0], RelDynTraits::CLAMPS[$cols['bleedout']['unit']]);
        $this->assertSame([0.3, 1.5], RelDynTraits::CLAMPS[$cols['y_maturity_up']['unit']]);
        $this->assertSame([-100.0, 100.0], RelDynTraits::CLAMPS[$cols['baseline_affinity']['unit']], 'core affinity units (RelDynAffinityUnitsTest)');
    }

    /** §3.2: the six MDD 15.6 corners exactly; Rs sets the direction, L the size. */
    public function testMaturityTypeFormulaHitsTheSixCornersAndIsMonotone(): void
    {
        $corners = [
            'Resilient' => [0.75, 0.35607], 'Growth' => [0.72327, 0.48041], 'Brittle' => [0.27673, 0.48041],
            'Adaptive' => [0.5, 0.5], 'Volatile' => [0.5, 1.0], 'Rigid' => [0.5, 0.0],
        ];
        foreach (RelationshipDynamics::MATURITY_PLASTICITY_VALUES as $type => $y) {
            [$rs, $l] = RelDynTraits::maturityAxesOf($y);
            $this->assertEqualsWithDelta($corners[$type][0], $rs, 1e-5, "{$type} Rs");
            $this->assertEqualsWithDelta($corners[$type][1], $l, 1e-5, "{$type} L");
            [$up, $down] = RelDynTraits::maturityY($rs, $l);
            $this->assertEqualsWithDelta($y['Y_up'], $up, 1e-12, "{$type} Y_up");
            $this->assertEqualsWithDelta($y['Y_down'], $down, 1e-12, "{$type} Y_down");
            $this->assertSame($type, RelDynTraits::maturityCorner(['Rs' => $rs, 'L' => $l]));
        }
        $prev = null;
        foreach ([0.2, 0.35, 0.5, 0.65, 0.8] as $rs) {
            [$up, $down] = RelDynTraits::maturityY($rs, 0.5);
            if ($prev) {
                $this->assertGreaterThan($prev[0], $up, 'resilience raises recovery');
                $this->assertLessThan($prev[1], $down, 'resilience resists collapse');
            }
            $prev = [$up, $down];
        }
        $prev = null;
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $l) {
            [$up, $down] = RelDynTraits::maturityY(0.5, $l);
            if ($prev) $this->assertGreaterThan($prev, $up * $down, 'reactivity widens both swings');
            $prev = $up * $down;
        }
    }

    /** §3.3: band tables piecewise-linear; the ceiling cut by its own rule. */
    public function testOpennessBandsAndCeilingCut(): void
    {
        $cfg = RelDynAttraction::defaults();
        $levels = $cfg['openness_levels'];
        foreach (['openness_margin', 'openness_ick_mult'] as $key) {
            foreach ($levels as $band => $o) {
                $this->assertEqualsWithDelta($cfg[$key][$band], RelDynTraits::opennessBandValue($o, $cfg[$key], $levels), 1e-12, "{$key} {$band}");
            }
            $prev = -INF;
            for ($o = 0.3; $o <= 0.9001; $o += 0.05) {
                $v = RelDynTraits::opennessBandValue($o, $cfg[$key], $levels);
                $this->assertGreaterThanOrEqual($prev, $v, "{$key} monotone");
                $prev = $v;
            }
        }
        $pace = $cfg['advance']['openness_pace'] ?? null;
        if (is_array($pace)) {
            $this->assertEqualsWithDelta(1.25, RelDynTraits::opennessBandValue(0.45, $pace, $levels), 1e-12);
        }
        // ceiling cut: exact at the band points, 0 in the low regime, non-increasing above it
        foreach ($levels as $band => $o) {
            $this->assertEqualsWithDelta($cfg['openness_passion_ceiling_cut'][$band], RelDynTraits::opennessCeilingCut($o), 1e-12, $band);
        }
        $this->assertSame(0.0, RelDynTraits::opennessCeilingCut(0.44));
        $prev = INF;
        for ($o = 0.45; $o <= 0.9001; $o += 0.025) {
            $v = RelDynTraits::opennessCeilingCut($o);
            $this->assertLessThanOrEqual($prev + 1e-12, $v);
            $this->assertGreaterThanOrEqual(0.2 - 1e-12, $v);
            $prev = $v;
        }
        $this->assertSame(0.5, RelDynTraits::opennessCeilingCut(0.45), 'the one step is at the won-over switch');
    }

    /** A22 pointwise: between two presets' curves, inside 0..1. */
    public function testSensitivityBlendsTheCurvesPointwise(): void
    {
        $a = self::point('Stoic');     // inner_circle
        $b = self::point('Nurturing'); // open_heart
        foreach ([0, 25, 50, 75, 100] as $bond) {
            $lo = min(RelationshipDynamics::calculateSocialSensitivity('inner_circle', $bond), RelationshipDynamics::calculateSocialSensitivity('open_heart', $bond));
            foreach ([0.0, 0.5, 1.0] as $t) {
                $v = RelDynTraits::sensitivityAt(self::lerp($a, $b, $t), 'trust', $bond, false);
                $this->assertGreaterThanOrEqual(0.0, $v);
                $this->assertLessThanOrEqual(1.0, $v);
                if ($t === 0.0) $this->assertSame(RelationshipDynamics::calculateSocialSensitivity('inner_circle', $bond), $v);
                if ($t === 1.0) $this->assertSame(RelationshipDynamics::calculateSocialSensitivity('open_heart', $bond), $v);
            }
            $this->assertGreaterThanOrEqual($lo - 0.05, RelDynTraits::sensitivityAt(self::lerp($a, $b, 0.5), 'trust', $bond, false));
        }
    }

    /** §4.5 base vector and §6.3 Aela: the engine reproduces the design's worked numbers. */
    public function testDesignWorkedNumbers(): void
    {
        $base = self::vec(['possessiveness' => 0.325]);
        $near = RelDynTraits::nearestPreset($base);
        $this->assertSame('Gentle', $near['name']);
        $this->assertEqualsWithDelta(0.451, $near['distance'], 0.002);
        $this->assertEqualsWithDelta(0.93, RelDynTraits::value($base, 'passion_mult'), 0.01);
        $this->assertEqualsWithDelta(0.80, RelDynTraits::value($base, 'jealousy_mult'), 0.01);
        $this->assertEqualsWithDelta(35, RelDynTraits::value($base, 'baseline_trust'), 0.5);
        $this->assertEqualsWithDelta(33, RelDynTraits::value($base, 'baseline_comfort'), 0.5);
        $this->assertEqualsWithDelta(35, RelDynTraits::value($base, 'baseline_affinity'), 0.5);
        $open = RelDynTraits::opennessAt($base, RelDynAttraction::defaults()['temperament_openness'], RelDynAttraction::defaults()['openness_levels']);
        $this->assertEqualsWithDelta(0.56, $open['o'], 0.005);

        // Aela (design §6.3 vector; nearest Bold 0.49, beyond rho: the pure model)
        $aela = self::vec(['guard' => 0.66, 'expressiveness' => 0.47, 'confidence' => 0.77, 'pride' => 0.50, 'resilience' => 0.69,
            'reactivity' => 0.45, 'warmth' => 0.43, 'restraint' => 0.53, 'possessiveness' => 0.22, 'protectiveness' => 0.69]);
        $this->assertSame('Bold', RelDynTraits::nearestPreset($aela)['name']);
        $this->assertEqualsWithDelta(0.85, RelDynTraits::value($aela, 'passion_mult'), 0.01);
        $this->assertEqualsWithDelta(0.56, RelDynTraits::value($aela, 'jealousy_mult'), 0.01);
        $this->assertEqualsWithDelta(0.40, RelDynTraits::opennessAt($aela, RelDynAttraction::defaults()['temperament_openness'], RelDynAttraction::defaults()['openness_levels'])['o'], 0.01);
        // (the design's table rounds its own unrounded vector: within one point)
        $this->assertEqualsWithDelta(31, RelDynTraits::value($aela, 'baseline_trust'), 1.0);
        $this->assertEqualsWithDelta(32, RelDynTraits::value($aela, 'baseline_comfort'), 1.0);
        $this->assertEqualsWithDelta(77, RelDynTraits::value($aela, 'baseline_self_confidence'), 0.6);
        $this->assertEqualsWithDelta(43, RelDynTraits::value($aela, 'baseline_warmth'), 0.6);
        $this->assertEqualsWithDelta(31, RelDynTraits::value($aela, 'baseline_affinity'), 0.6);
        $this->assertEqualsWithDelta(-20, RelDynTraits::value($aela, 'tier_retention'), 0.6);
        $this->assertEqualsWithDelta(11.2, RelDynTraits::value($aela, 'warmth_half_life'), 0.1);
        $this->assertEqualsWithDelta(1.23, RelDynTraits::tableAt($aela, RelDynRomance::configDefaults()['momentum_temperament_mult'], 1.0, 'R',
            fn(array $x) => 1.0 + 4.0 * max(0.0, $x['G'] - 0.6), 'mult'), 0.02);
    }
}
