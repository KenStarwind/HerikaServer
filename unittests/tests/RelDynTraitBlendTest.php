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

    /**
     * The phase-1/2 bleedout table (MDD 1.3 drain, passion points). Phase 3 retired it (the fall is
     * a trait outcome, RelDynTraits::bleedout); it stays here as §3.5's Rule I example.
     */
    private const BLEEDOUT_TABLE = ['Romantic' => -1.0, 'Anxious' => -3.0, 'Bold' => -0.3, 'Playful' => -0.5, 'Humble' => -0.8,
        'Nurturing' => -1.0, 'Gentle' => -1.5, 'Jealous' => -1.5, 'Proud' => -2.0, 'Defiant' => 1.0, 'Guarded' => -2.5,
        'Independent' => -2.0, 'Stoic' => -0.5];

    private static function bleedoutI(array $x): float
    {
        return RelDynTraits::blend($x, self::BLEEDOUT_TABLE, 'I', null, 'bleedout');
    }

    /** §3.5: Defiant (+1.0 rage) to Bold (-0.3) under Rule I: monotone (to 1e-3), bounded, still rage at 0.15. */
    public function testDefiantToBoldBleedoutWalkIsMonotoneAndKeepsTheRageNearDefiant(): void
    {
        $def = self::point('Defiant');
        $bold = self::point('Bold');
        $len = RelDynTraits::distance($def, $bold);
        $this->assertEqualsWithDelta(0.515, $len, 0.002);
        $prev = INF;
        $table = self::BLEEDOUT_TABLE;
        for ($i = 0; $i <= 40; $i++) {
            $x = self::lerp($def, $bold, $i / 40);
            $v = self::bleedoutI($x);
            // monotone up to the pull of the other presets' weights (w = d^-4), well under 0.001 passion points
            $this->assertLessThanOrEqual($prev + 1e-3, $v, "monotone at step {$i}");
            $this->assertGreaterThanOrEqual(min($table) - 1e-12, $v);
            $this->assertLessThanOrEqual(max($table) + 1e-12, $v);
            $prev = $v;
            if ($i / 40 * $len <= 0.15) $this->assertGreaterThan(0.0, $v, sprintf('rage kept %.3f from Defiant', $i / 40 * $len));
        }
        $this->assertSame(1.0, self::bleedoutI($def));
        $this->assertSame(-0.3, self::bleedoutI($bold));
        // design table: +0.92 at 0.15, crossing zero about 0.29 from Defiant
        $this->assertEqualsWithDelta(0.92, self::bleedoutI(self::lerp($def, $bold, 0.15 / $len)), 0.02);
        $this->assertEqualsWithDelta(-0.15, self::bleedoutI(self::lerp($def, $bold, 0.31 / $len)), 0.03);
    }

    /** Rule I is a convex combination: never outside [min T, max T]; continuous at a preset. */
    public function testRuleIColumnsAreBoundedByTheirTable(): void
    {
        mt_srand(20260924);
        $iCols = array_keys(array_filter(RelDynTraits::columns(), fn($c) => $c['rule'] === 'I'));
        $this->assertNotContains('bleedout', $iCols, 'retired in phase 3 (RelDynTraits::bleedout)');
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

    /**
     * Rule R's residual kernel has zero slope at its preset (reviewed issue, phase 2): the
     * RESIDUAL adds no kink. The one-sided slopes along +e and -e sum to the model's own kink
     * (D+ + D- = 0 for a linear model); with the old (1-u)^2 kernel they differed by -4 r / rho
     * (r = the preset's residual), a cone at the preset. Along a trait the column does not own,
     * the slope at the preset is 0 (flat, not a cone).
     * NOT claimed (review 2026-09-25): that every column is smooth at every preset. Kinks that
     * are not the kernel's remain, exactly at presets (the model's own crease and the per-unit
     * clamps; skipped here, pinned in testTheKinksLeftAtThePresetsAreTheModelsAndTheClamps).
     */
    public function testRuleRKernelHasZeroSlopeAtEveryPreset(): void
    {
        $h = 1e-6;   // small enough that the curvature term (k''(0) r h) is far under the tolerance
        $checked = 0;
        foreach (RelDynTraits::columns() as $col => $spec) {
            if ($spec['rule'] !== 'R') continue;
            foreach (RelDynTraits::points() as $name => $p) {
                $at = floatval(RelDynTraits::value($p, $col));
                foreach (RelDynTraits::TRAITS as $code => $_) {
                    if ($p[$code] - $h < 0.0 || $p[$code] + $h > 1.0) continue;
                    $up = $p; $up[$code] += $h;
                    $dn = $p; $dn[$code] -= $h;
                    $vUp = floatval(RelDynTraits::value($up, $col));
                    $vDn = floatval(RelDynTraits::value($dn, $col));
                    [$lo, $hi] = RelDynTraits::CLAMPS[$spec['unit']];
                    if (min($vUp, $vDn, $at) <= $lo + 1e-9 || max($vUp, $vDn, $at) >= $hi - 1e-9) continue;   // clamp edge
                    $dPlus = ($vUp - $at) / $h;
                    $dMinus = ($vDn - $at) / $h;
                    $scale = max(1.0, abs($at));
                    // the model's own kink (the exact A17 formula switches base at L = 0.5) is not the residual's
                    $m = $spec['model'];
                    $f = is_callable($m) ? fn(array $x) => floatval($m($x)) : function (array $x) use ($m) {
                        $v = floatval($m[0] ?? 0.0);
                        foreach ($m as $k => $c) if ($k !== 0) $v += floatval($c) * floatval($x[$k]);
                        return $v;
                    };
                    $modelKink = ($f($up) + $f($dn) - 2.0 * $f($p)) / $h;
                    $this->assertEqualsWithDelta($modelKink, $dPlus + $dMinus, 1e-2 * $scale, "{$col} has a kink at {$name} along {$code}");
                    if (!in_array($code, $spec['owners'], true) && !is_callable($spec['model'])) {
                        $this->assertEqualsWithDelta(0.0, $dPlus, 1e-2 * $scale, "{$col} is flat at {$name} along non-owner {$code}");
                    }
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(1000, $checked);
        // still exact at every preset (the engine's own value there, and the formula's limit)
        foreach (RelDynTraits::points() as $name => $p) {
            $near = $p;
            $near['W'] += ($near['W'] < 0.5 ? 1e-9 : -1e-9);
            $this->assertEqualsWithDelta(floatval(RelDynTraits::table('jealousy_mult')[$name]), RelDynTraits::value($near, 'jealousy_mult'), 1e-6, $name);
        }
    }

    /**
     * The reviewed case: Humble's A1 residual (+0.16 passion mult) against the E slope 0.81.
     * The old (1-u)^2 kernel falls 2r/rho = 1.02 per unit E just past Humble, so passion
     * dropped as expressiveness rose (a cone); the zero-slope kernel's largest pull,
     * 1.54 r / rho = 0.78, stays under 0.81. This holds for THIS preset and owner only: where a
     * residual is large against its model slope times rho, the column still turns the wrong
     * way inside the reach (testRuleRWrongWayNearAPresetIsBoundedByTheResiduals).
     */
    public function testRuleRIsMonotoneThroughAPresetAlongItsOwner(): void
    {
        $h = self::point('Humble');
        $prev = null;
        for ($t = -0.25; $t <= 0.2501; $t += 0.01) {
            $x = $h;
            $x['E'] = $h['E'] + $t;
            $v = RelDynTraits::value($x, 'passion_mult');
            if ($prev !== null) $this->assertGreaterThan($prev, $v, sprintf('passion rises with E at %+.2f from Humble', $t));
            $prev = $v;
        }
    }

    /**
     * The kinks left at presets (review 2026-09-25), pinned so the report can name them:
     *   - the A17 maturity Y model itself switches base at L = 0.5 (0.3^(1-2L) below, 1.5^(2L-1)
     *     above): a crease at Gentle and Playful, the presets at L = 0.5, along L;
     *   - per-unit clamps where a preset's table value IS the clamp bound (charisma +-1, maturity
     *     Y 0.3 / 1.5): half the neighbourhood is clamped flat, the other half moves (a half-cone).
     *     The charisma consumers only threshold at +-0.5, so those kinks change no behaviour.
     * Nothing else: no other (column, preset) pair has one-sided slopes that disagree.
     */
    public function testTheKinksLeftAtThePresetsAreTheModelsAndTheClamps(): void
    {
        $h = 1e-5;
        $found = ['model' => [], 'clamp' => []];
        foreach (RelDynTraits::columns() as $col => $spec) {
            if (!in_array($spec['rule'], ['R', 'RI'], true)) continue;   // RI: flat residual at a preset too
            $m = $spec['model'];
            $f = is_callable($m) ? fn(array $x) => floatval($m($x)) : function (array $x) use ($m) {
                $v = floatval($m[0] ?? 0.0);
                foreach ($m as $k => $c) if ($k !== 0) $v += floatval($c) * floatval($x[$k]);
                return $v;
            };
            foreach (RelDynTraits::points() as $name => $p) {
                $at = floatval(RelDynTraits::value($p, $col));
                foreach (RelDynTraits::TRAITS as $code => $_) {
                    if ($p[$code] - $h < 0.0 || $p[$code] + $h > 1.0) continue;
                    $up = $p; $up[$code] += $h;
                    $dn = $p; $dn[$code] -= $h;
                    $kink = (floatval(RelDynTraits::value($up, $col)) + floatval(RelDynTraits::value($dn, $col)) - 2.0 * $at) / $h;
                    if (abs($kink) <= 1e-2 * max(1.0, abs($at))) continue;
                    $modelKink = ($f($up) + $f($dn) - 2.0 * $f($p)) / $h;
                    $found[abs($modelKink) > 1e-2 * max(1.0, abs($at)) ? 'model' : 'clamp']["{$col}@{$name}"][] = $code;
                }
            }
        }
        $model = [];
        foreach (['up', 'down'] as $dir) foreach (['Playful', 'Gentle'] as $n) $model["y_maturity_{$dir}@{$n}"] = ['L'];
        ksort($model);
        ksort($found['model']);
        $this->assertEquals($model, $found['model']);
        $clamp = [];
        foreach (['Anxious', 'Jealous', 'Independent'] as $n) {
            $clamp["y_maturity_up@{$n}"] = ['Rs'];
            $clamp["y_maturity_down@{$n}"] = ['Rs'];
        }
        foreach (['Romantic', 'Playful', 'Proud', 'Guarded', 'Stoic'] as $n) $clamp["charisma_catalyst@{$n}"] = ['G', 'E', 'D'];
        foreach (['Romantic', 'Humble', 'Nurturing', 'Gentle', 'Proud', 'Independent'] as $n) $clamp["charisma_charmer@{$n}"] = ['Pd', 'W'];
        ksort($clamp);
        ksort($found['clamp']);
        $this->assertEquals($clamp, $found['clamp']);
        foreach (array_keys($found['clamp']) as $k) {
            [$col, $name] = explode('@', $k);
            [$lo, $hi] = RelDynTraits::CLAMPS[RelDynTraits::columns()[$col]['unit']];
            $v = floatval(RelDynTraits::table($col)[$name]);
            $this->assertTrue(abs($v - $lo) < 1e-9 || abs($v - $hi) < 1e-9, "{$k}: the table value is the clamp bound");
        }
    }

    /**
     * Rule R is exact at the presets and adds no kink, but it is NOT monotone near every
     * preset (review 2026-09-25). Where a preset's residual r is large against its model slope
     * times rho, the column turns the wrong way inside the reach: the residual fades from r at
     * the preset to 0 at rho, and that fade outruns the model (coord_m at Bold along D: about
     * 20 coordinate points; tier_retention at Independent along Rs: about 7; passion_mult at
     * Humble along G / D: 0.08). No exactly-interpolating local residual can avoid this when
     * the table itself runs against the model. The guarantee, tested here for every linear
     * Rule-R column, preset and owner, both ways: the wrong-way excursion within rho never
     * exceeds the sum of the |residuals| whose kernels reach the path (in practice the
     * preset's own |r|).
     */
    public function testRuleRWrongWayNearAPresetIsBoundedByTheResiduals(): void
    {
        $rho = RelDynTraits::residualReach();
        $pts = RelDynTraits::points();
        $checked = 0;
        $worst = [];
        foreach (RelDynTraits::columns() as $col => $spec) {
            if ($spec['rule'] !== 'R' || is_callable($spec['model'])) continue;
            $m = $spec['model'];
            $f = function (array $x) use ($m) {
                $v = floatval($m[0] ?? 0.0);
                foreach ($m as $k => $c) if ($k !== 0) $v += floatval($c) * floatval($x[$k]);
                return $v;
            };
            $r = [];
            foreach ($pts as $n => $q) $r[$n] = floatval(RelDynTraits::table($col)[$n]) - $f($q);
            foreach ($pts as $name => $p) {
                $at = floatval(RelDynTraits::value($p, $col));
                foreach ($spec['owners'] as $code) {
                    if (abs(floatval($m[$code] ?? 0.0)) < 1e-12) continue;
                    foreach ([1.0, -1.0] as $dir) {
                        $against = 0.0;
                        $reach = [];
                        for ($t = 0.005; $t <= $rho + 1e-12; $t += 0.005) {
                            $x = $p;
                            $x[$code] += $dir * $t;
                            if ($x[$code] < 0.0 || $x[$code] > 1.0) break;
                            foreach ($pts as $n => $q) if (RelDynTraits::distance($x, $q) < $rho) $reach[$n] = abs($r[$n]);
                            $sign = $f($x) > $f($p) ? 1.0 : -1.0;
                            $against = max($against, -$sign * (floatval(RelDynTraits::value($x, $col)) - $at));
                        }
                        if (!$reach) continue;
                        $this->assertLessThanOrEqual(array_sum($reach) + 1e-9, $against,
                            sprintf('%s at %s along %s%s', $col, $name, $dir > 0 ? '+' : '-', $code));
                        $worst[$col] = max($worst[$col] ?? 0.0, $against);
                        $checked++;
                    }
                }
            }
        }
        $this->assertGreaterThan(250, $checked);
        // the reviewed magnitudes (units: multiplier); coord_m (20.35 coordinate points) and
        // tier_retention (7.13 core points) moved to Rule RI in phase 3 (next test)
        $this->assertEqualsWithDelta(0.076, $worst['passion_mult'], 0.005);
        $this->assertEqualsWithDelta(0.163, $worst['jealousy_mult'], 0.005);
        // every Rule-R column left turns the wrong way by less than 20% of its table span
        foreach ($worst as $col => $w) {
            $t = array_map('floatval', RelDynTraits::table($col));
            $this->assertLessThan(0.20 * (max($t) - min($t)), $w, $col);
        }
    }

    /**
     * Phase 3, the preset-quirk columns (Rule RI: the model plus the residuals blended by inverse
     * distance): exact at every preset, and near a preset they follow their model. Walking each
     * owner half an axis away from each preset, the excursion against the model stays under 20%
     * of the table span (Rule R turned coord_m 25%, coord_f 22%, tier_retention 29% within rho).
     */
    public function testPresetQuirkColumnsFollowTheirModelNearThePresets(): void
    {
        $pts = RelDynTraits::points();
        $ri = array_keys(array_filter(RelDynTraits::columns(), fn($c) => $c['rule'] === 'RI'));
        sort($ri);
        $want = RelDynTraits::RI_COLUMNS;
        sort($want);
        $this->assertSame($want, $ri);
        foreach ($ri as $col) {
            $spec = RelDynTraits::columns()[$col];
            $m = $spec['model'];
            $f = function (array $x) use ($m) {
                $v = floatval($m[0] ?? 0.0);
                foreach ($m as $k => $c) if ($k !== 0) $v += floatval($c) * floatval($x[$k]);
                return $v;
            };
            $t = array_map('floatval', RelDynTraits::table($col));
            $span = max($t) - min($t);
            $worst = 0.0;
            foreach ($pts as $name => $p) {
                $at = floatval(RelDynTraits::value($p, $col));
                $this->assertSame($t[$name], $at, "{$col} exact at {$name}");
                foreach ($spec['owners'] as $code) {
                    foreach ([1.0, -1.0] as $dir) {
                        for ($s = 0.01; $s <= 0.5; $s += 0.01) {
                            $x = $p;
                            $x[$code] += $dir * $s;
                            if ($x[$code] < 0.0 || $x[$code] > 1.0) break;
                            $sign = $f($x) > $f($p) ? 1.0 : -1.0;
                            $worst = max($worst, -$sign * (floatval(RelDynTraits::value($x, $col)) - $at));
                        }
                    }
                }
            }
            $this->assertLessThan(0.20 * $span, $worst, $col);
        }
        // coord_m at Bold along restraint, the reviewed case: as restraint drops the model rises
        // (-3 D) but Rule R fell 20.35 coordinate points inside the reach (60 -> 39.7). Rule RI
        // follows the model near Bold (a flat residual) and drifts gently toward the neighbouring
        // presets' values over the whole way (7 points by D = 0)
        $bold = $pts['Bold'];
        $vals = [];
        for ($s = 0.0; $s <= $bold['D'] + 1e-9; $s += 0.01) {
            $x = $bold;
            $x['D'] = $bold['D'] - $s;
            $vals[] = RelDynTraits::value($x, 'baseline_coord_m');
        }
        $this->assertGreaterThanOrEqual($vals[0], $vals[5], 'within 0.05 of Bold it moves with its model');
        $this->assertLessThan(7.5, $vals[0] - min($vals));
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
        $this->assertSame([-5.0, 5.0], RelDynTraits::CLAMPS['bleedout'], 'the bleedout passion (RelDynTraits::bleedout)');
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
