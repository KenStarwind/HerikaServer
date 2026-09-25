<?php
/**
 * RelDyn personality traits: the trait engine (D:\docs\reldyn-personality-traits-design.md,
 * decisions 2026-09-23 §14-16).
 *
 * Ten continuous traits, each 0..1 (design §1): G guard, E expressiveness, C confidence,
 * Pd pride, Rs resilience, L reactivity, W warmth, D restraint/duty, Po possessiveness,
 * Pr protectiveness; plus the per-NPC level maturity_start (0..100), which is not a trait.
 * The 13 MDD 1.3 temperaments are named presets: points in trait space (§3.1).
 *
 * Every temperament-keyed parameter is a column. A column reads its owning traits through a
 * model f_P and is made exact at the 13 presets by one of two rules (§3.3):
 *   Rule R (well-explained columns): clamp( f(x) + sum_p k(|x-p|/rho) (T[p] - f(p)) ),
 *          k(u) = (1-u^2)^2 for u < 1 (zero slope at the preset: residualKernel(); the
 *          design's (1-u)^2 made cones), rho = min(residual_reach, d_min); the residual is computed
 *          at runtime from today's table, so rounding the coefficients cannot break exactness.
 *   Rule I (low R^2 columns): inverse-distance blend of the table, w_p = |x-p|^-4; a convex
 *          combination, bounded by the table.
 * At a preset point both rules equal the table value exactly; the engine returns the table
 * value itself there (the same number the formula gives, without floating-point noise).
 * Label-valued surfaces (reunion text, love language, curve names, tags) use the nearest preset.
 * Clamps are per unit (§2.4), never to a column's own span.
 *
 * PHASE 1 (this file's contract): assignment is unchanged. The consumer's own temperament
 * label (auto-derived or overridden, with its own 'Stoic' fallback or null handling, §3.6) is
 * mapped to its preset point by EXACT name; any other label (null, '', a lower-case or
 * unknown name) gets the consumer's current default or legacy table lookup, unchanged. So at
 * every textbook preset every parameter equals the old table value, and NPCs without a
 * temperament behave as before. $dynamics['trait_vector'] mirrors the label's preset point
 * (storage for phase 2); profile_overrides.trait_vector is accepted and stored but not read
 * by assignment until phase 2 (traits.assignment = 'read').
 *
 * Units: traits 0..1 (unitless); every column's unit is listed in columns() and §2.4.
 */

final class RelDynTraits
{
    /** Bump when the stored vector's shape changes. */
    const VERSION = 1;

    /** Phase 1: the vector is the preset point of the consumer's temperament label. */
    const ASSIGNMENT = 'label';

    /** Trait code => storage name (design §1, §4.2 output keys). Order is the vector order. */
    const TRAITS = [
        'G' => 'guard', 'E' => 'expressiveness', 'C' => 'confidence', 'Pd' => 'pride',
        'Rs' => 'resilience', 'L' => 'reactivity', 'W' => 'warmth', 'D' => 'restraint',
        'Po' => 'possessiveness', 'Pr' => 'protectiveness',
    ];

    /**
     * Design §3.1: the 13 presets. C = self-confidence baseline / 100 and W = warmth baseline /
     * 100 (A14, A8 identities). Rs and L are not listed: they are the exact inverse of the
     * preset's maturity type corner (§3.2, RelationshipDynamics::TEMPERAMENT_MATURITY_PLASTICITY),
     * computed at full precision in points(). maturity_start = today's maturity baseline (A9).
     */
    const PRESET_TRAITS = [
        'Romantic'    => ['G' => 0.30, 'E' => 0.80, 'C' => 0.35, 'Pd' => 0.35, 'W' => 0.50, 'D' => 0.30, 'Po' => 0.55, 'Pr' => 0.50],
        'Anxious'     => ['G' => 0.40, 'E' => 0.75, 'C' => 0.25, 'Pd' => 0.25, 'W' => 0.35, 'D' => 0.30, 'Po' => 0.60, 'Pr' => 0.50],
        'Bold'        => ['G' => 0.30, 'E' => 0.60, 'C' => 0.65, 'Pd' => 0.55, 'W' => 0.35, 'D' => 0.35, 'Po' => 0.30, 'Pr' => 0.55],
        'Playful'     => ['G' => 0.20, 'E' => 0.85, 'C' => 0.50, 'Pd' => 0.35, 'W' => 0.45, 'D' => 0.15, 'Po' => 0.15, 'Pr' => 0.30],
        'Humble'      => ['G' => 0.35, 'E' => 0.50, 'C' => 0.40, 'Pd' => 0.10, 'W' => 0.40, 'D' => 0.60, 'Po' => 0.20, 'Pr' => 0.45],
        'Nurturing'   => ['G' => 0.20, 'E' => 0.60, 'C' => 0.45, 'Pd' => 0.25, 'W' => 0.60, 'D' => 0.55, 'Po' => 0.25, 'Pr' => 0.85],
        'Gentle'      => ['G' => 0.30, 'E' => 0.50, 'C' => 0.35, 'Pd' => 0.20, 'W' => 0.50, 'D' => 0.60, 'Po' => 0.15, 'Pr' => 0.60],
        'Jealous'     => ['G' => 0.65, 'E' => 0.60, 'C' => 0.20, 'Pd' => 0.50, 'W' => 0.25, 'D' => 0.30, 'Po' => 0.90, 'Pr' => 0.45],
        'Proud'       => ['G' => 0.70, 'E' => 0.40, 'C' => 0.70, 'Pd' => 0.90, 'W' => 0.20, 'D' => 0.50, 'Po' => 0.55, 'Pr' => 0.30],
        'Defiant'     => ['G' => 0.60, 'E' => 0.70, 'C' => 0.55, 'Pd' => 0.60, 'W' => 0.15, 'D' => 0.10, 'Po' => 0.40, 'Pr' => 0.35],
        'Guarded'     => ['G' => 0.85, 'E' => 0.30, 'C' => 0.40, 'Pd' => 0.45, 'W' => 0.25, 'D' => 0.65, 'Po' => 0.30, 'Pr' => 0.45],
        'Independent' => ['G' => 0.70, 'E' => 0.30, 'C' => 0.75, 'Pd' => 0.50, 'W' => 0.15, 'D' => 0.50, 'Po' => 0.10, 'Pr' => 0.40],
        'Stoic'       => ['G' => 0.65, 'E' => 0.15, 'C' => 0.60, 'Pd' => 0.40, 'W' => 0.20, 'D' => 0.90, 'Po' => 0.10, 'Pr' => 0.70],
    ];

    /** Rule R reach (trait-space distance, unitless); rho = min(this or config traits.residual_reach, d_min). */
    const RESIDUAL_REACH = 0.317;

    /** Distance under which a point IS a preset (the rules' value there is the table value). */
    const PRESET_EPS = 1e-12;

    /** Per-unit clamps (design §2.4). [lo, hi] in each unit. */
    const CLAMPS = [
        'core_affinity'   => [-100.0, 100.0],   // core affinity points
        'coordinate'      => [-100.0, 100.0],   // coordinate points (DIMENSION_DEFS)
        'dimension'       => [0.0, 100.0],      // dimension points 0..100
        'mult'            => [0.1, 3.0],        // unitless multiplier
        'maturity_y'      => [0.3, 1.5],        // maturity-type Y (MDD 15.6 span)
        'openness'        => [0.3, 0.9],        // MDD 1.4 openness scale
        'half_life'       => [4.0, 24.0],       // hours
        'decay_rate'      => [0.04, 0.20],      // per hour
        'lambda'          => [M_LN2 / 24.0, M_LN2 / 4.0],   // ln2 / half_life at the half-life bounds, per hour
        'passion_decay'   => [1.0, 8.0],        // passion points per hour
        'retention'       => [-40.0, 0.0],      // core affinity points (<= 0)
        'absence_decay'   => [-10.0, 0.0],      // core affinity points per decay tick (<= 0)
        'bleedout'        => [-5.0, 5.0],       // passion points per event
        'trust_event'     => [-3.0, 3.0],       // trust points per event
        'curve_exp'       => [0.25, 4.0],       // sensitivity curve exponent
        'unit01'          => [0.0, 1.0],        // unitless 0..1
        'offset'          => [-1.0, 1.0],       // signed 0..1-scale offset (intimacy / attachment / facet rows)
        'effect'          => [-1.0, 1.0],       // charisma effectiveness: +1 effective .. -1 ineffective
    ];

    /** Sentinel: read the label from $dynamics['inferred_temperament']. */
    const FROM_DYNAMICS = "\0from_dynamics";

    private static $points = null;
    private static $dMin = null;
    private static $tables = [];

    // =========================================================================
    // PRESETS AND VECTORS
    // =========================================================================

    /** The 13 preset points: name => [code => value 0..1, 'maturity_start' => 0..100]. */
    public static function points(): array
    {
        if (self::$points !== null) return self::$points;
        $out = [];
        foreach (self::PRESET_TRAITS as $name => $t) {
            $type = RelationshipDynamics::TEMPERAMENT_MATURITY_PLASTICITY[$name];
            [$rs, $l] = self::maturityAxesOf(RelationshipDynamics::MATURITY_PLASTICITY_VALUES[$type]);
            $x = [];
            foreach (self::TRAITS as $code => $_) {
                $x[$code] = $code === 'Rs' ? $rs : ($code === 'L' ? $l : (float) $t[$code]);
            }
            $x['maturity_start'] = (float) RelationshipDynamics::TEMPERAMENT_BASELINES['maturity'][$name];
            $out[$name] = $x;
        }
        return self::$points = $out;
    }

    /** True for one of the 13 preset names, matched EXACTLY (as the old tables were keyed). */
    public static function isPreset($label): bool
    {
        return is_string($label) && isset(self::PRESET_TRAITS[$label]);
    }

    /** The preset point of an exact preset name, else null. */
    public static function presetPoint($label): ?array
    {
        return self::isPreset($label) ? self::points()[$label] : null;
    }

    /**
     * The trait vector a consumer reads. Phase 1 (ASSIGNMENT 'label'): the preset point of the
     * consumer's own label; the stored trait_vector mirrors it and is not read. $label defaults
     * to $dynamics['inferred_temperament'].
     */
    public static function vectorFor($label = self::FROM_DYNAMICS, ?array $dynamics = null): ?array
    {
        if ($label === self::FROM_DYNAMICS) $label = $dynamics['inferred_temperament'] ?? null;
        return self::presetPoint($label);
    }

    /** Euclidean distance over the 10 traits (maturity_start is not a trait). */
    public static function distance(array $a, array $b): float
    {
        $s = 0.0;
        foreach (self::TRAITS as $code => $_) {
            $d = floatval($a[$code] ?? 0.5) - floatval($b[$code] ?? 0.5);
            $s += $d * $d;
        }
        return sqrt($s);
    }

    /** Smallest distance between two presets (design §3.1: Humble-Gentle 0.317). */
    public static function minPresetDistance(): float
    {
        if (self::$dMin !== null) return self::$dMin;
        $pts = array_values(self::points());
        $min = INF;
        for ($i = 0; $i < count($pts); $i++) {
            for ($j = $i + 1; $j < count($pts); $j++) {
                $min = min($min, self::distance($pts[$i], $pts[$j]));
            }
        }
        return self::$dMin = $min;
    }

    /** rho = min(config traits.residual_reach (default RESIDUAL_REACH), d_min); 0 = pure model. */
    public static function residualReach(): float
    {
        $reach = self::RESIDUAL_REACH;
        $cfg = RelationshipDynamics::getConfig()['traits'] ?? null;   // stored config key 'traits' (optional)
        if (is_array($cfg) && is_numeric($cfg['residual_reach'] ?? null)) $reach = floatval($cfg['residual_reach']);
        return max(0.0, min($reach, self::minPresetDistance()));
    }

    /** The preset a point sits on (distance < PRESET_EPS), else null. */
    public static function presetAt(array $x): ?string
    {
        foreach (self::points() as $name => $p) {
            if (self::distance($x, $p) < self::PRESET_EPS) return $name;
        }
        return null;
    }

    /** Nearest preset: ['name' => preset, 'distance' => trait-space distance]. Ties: MDD 1.3 order. */
    public static function nearestPreset(array $x): array
    {
        $best = null;
        $bestD = INF;
        foreach (self::points() as $name => $p) {
            $d = self::distance($x, $p);
            if ($d < $bestD) { $bestD = $d; $best = $name; }
        }
        return ['name' => $best, 'distance' => $bestD];
    }

    /** A partial or full vector (codes or storage names) completed with 0.5 and clamped to 0..1. */
    public static function normalizeVector(array $v): array
    {
        $x = [];
        foreach (self::TRAITS as $code => $name) {
            $raw = $v[$code] ?? $v[$name] ?? 0.5;
            $x[$code] = max(0.0, min(1.0, floatval($raw)));
        }
        if (isset($v['maturity_start']) && is_numeric($v['maturity_start'])) {
            $x['maturity_start'] = max(0.0, min(100.0, floatval($v['maturity_start'])));
        }
        return $x;
    }

    // =========================================================================
    // MATH: RULE R, RULE I, CLAMPS
    // =========================================================================

    private static function clampUnit(float $v, ?string $unit): float
    {
        if ($unit === null || !isset(self::CLAMPS[$unit])) return $v;
        [$lo, $hi] = self::CLAMPS[$unit];
        return max($lo, min($hi, $v));
    }

    /** Linear model: [intercept, code => coefficient, ...] at x. */
    private static function linear(array $coef, array $x): float
    {
        $v = floatval($coef[0] ?? 0.0);
        foreach ($coef as $k => $c) {
            if ($k === 0) continue;
            $v += floatval($c) * floatval($x[$k] ?? 0.5);
        }
        return $v;
    }

    private static function evalModel($model, array $x): float
    {
        return is_callable($model) ? floatval($model($x)) : self::linear((array) $model, $x);
    }

    /**
     * Rule R residual kernel, u = distance / rho in [0, 1): k(u) = (1 - u^2)^2.
     * k(0) = 1 and k(u >= 1) = 0 keep every preset exact (the next preset is at least d_min >= rho
     * away), and k'(0) = 0 means the residual adds no kink at its preset. The design's first
     * kernel, (1 - u)^2 (design §3.3), has slope -2 there: a column whose residual r is large
     * against its model slope became a cone (Humble's A1 passion fell as expressiveness rose,
     * 2r/rho = 1.02 > 0.81). Phase 2 fix (review, 2026-09-24): with (1 - u^2)^2 the largest
     * residual pull is 1.54 r / rho at u = 1/sqrt(3), k'(1) = 0 as well (the fade-out is smooth),
     * and values at the presets are unchanged.
     */
    public static function residualKernel(float $u): float
    {
        if ($u >= 1.0) return 0.0;
        $w = 1.0 - $u * $u;
        return $w * $w;
    }

    /**
     * A column's value at $x from its per-preset table (every preset present).
     * rule 'R' needs $model; rule 'I' ignores it. At a preset: the table value itself.
     */
    public static function blend(array $x, array $table, string $rule, $model = null, ?string $unit = null)
    {
        $on = self::presetAt($x);
        if ($on !== null) return $table[$on];
        $pts = self::points();
        if ($rule === 'R' && $model !== null) {
            $rho = self::residualReach();
            $v = self::evalModel($model, $x);
            if ($rho > 0) {
                foreach ($pts as $name => $p) {
                    $u = self::distance($x, $p) / $rho;
                    if ($u >= 1.0) continue;
                    $v += self::residualKernel($u) * (floatval($table[$name]) - self::evalModel($model, $p));
                }
            }
            return self::clampUnit($v, $unit);
        }
        $num = 0.0;
        $den = 0.0;
        foreach ($pts as $name => $p) {
            $w = self::distance($x, $p) ** -4;
            $num += $w * floatval($table[$name]);
            $den += $w;
        }
        return self::clampUnit($num / $den, $unit);
    }

    /** Label-valued column: the value of the nearest preset. */
    public static function labelAt(array $x, array $table, $default = null)
    {
        $name = self::presetAt($x) ?? self::nearestPreset($x)['name'];
        return array_key_exists($name, $table) ? $table[$name] : $default;
    }

    /** smoothstep(v; a, b): 0 below a, 1 above b, 3t^2 - 2t^3 between. */
    public static function smoothstep(float $v, float $a, float $b): float
    {
        $t = max(0.0, min(1.0, ($v - $a) / ($b - $a)));
        return $t * $t * (3.0 - 2.0 * $t);
    }

    /** C1: egocentric tag strength from pride (0..1). */
    public static function egocentric(float $pd): float
    {
        return self::smoothstep($pd, 0.70, 0.90);
    }

    /** C1: insecure tag strength from confidence (0..1). */
    public static function insecure(float $c): float
    {
        return 1.0 - self::smoothstep($c, 0.25, 0.35);
    }

    // =========================================================================
    // A17: MATURITY TYPE AS AN EXACT TWO-AXIS FORMULA (design §3.2)
    // =========================================================================

    /** [Y_up, Y_down] from resilience and reactivity (unitless). */
    public static function maturityY(float $rs, float $l): array
    {
        $a = M_LN2 * ($rs - 0.5) / 0.25;
        $m = $l < 0.5 ? 0.3 ** (1.0 - 2.0 * $l) : 1.5 ** (2.0 * $l - 1.0);
        return [$m * exp($a / 2.0), $m * exp(-$a / 2.0)];
    }

    /** The exact inverse: [Rs, L] of a ['Y_up', 'Y_down'] corner. */
    public static function maturityAxesOf(array $y): array
    {
        $up = floatval($y['Y_up']);
        $down = floatval($y['Y_down']);
        $m = sqrt($up * $down);
        $rs = 0.5 + 0.25 * log($up / $down) / M_LN2;
        $l = $m < 1.0 ? (1.0 - log($m) / log(0.3)) / 2.0 : (1.0 + log($m) / log(1.5)) / 2.0;
        return [$rs, $l];
    }

    /** The MDD 15.6 corner nearest to the point's (Y_up, Y_down) (display label). */
    public static function maturityCorner(array $x): string
    {
        [$up, $down] = self::maturityY(floatval($x['Rs'] ?? 0.5), floatval($x['L'] ?? 0.5));
        $best = 'Adaptive';
        $bestD = INF;
        foreach (RelationshipDynamics::MATURITY_PLASTICITY_VALUES as $type => $c) {
            $d = hypot($up - $c['Y_up'], $down - $c['Y_down']);
            if ($d < $bestD - 1e-12) { $bestD = $d; $best = $type; }
        }
        return $best;
    }

    // =========================================================================
    // A5: OPENNESS (design §3.3)
    // =========================================================================

    /**
     * A monotone openness band table (openness_margin, openness_ick_mult, openness_pace), read
     * piecewise-linearly between the band levels (0.3 / 0.6 / 0.9 by default).
     */
    public static function opennessBandValue(float $o, array $bandTable, array $levels = ['low' => 0.3, 'medium' => 0.6, 'high' => 0.9]): float
    {
        $pts = [];
        foreach ($levels as $band => $lvl) {
            if (isset($bandTable[$band])) $pts[] = [floatval($lvl), floatval($bandTable[$band])];
        }
        usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
        if (!$pts) return 0.0;
        if ($o <= $pts[0][0]) return $pts[0][1];
        for ($i = 1; $i < count($pts); $i++) {
            if ($o <= $pts[$i][0]) {
                [$x0, $y0] = $pts[$i - 1];
                [$x1, $y1] = $pts[$i];
                return $y0 + ($y1 - $y0) * ($o - $x0) / ($x1 - $x0);
            }
        }
        return $pts[count($pts) - 1][1];
    }

    /** Won-over switch (MDD 1.4 hard block): below 0.45 is the low regime. */
    const OPENNESS_LOW_REGIME = 0.45;

    /**
     * openness_passion_ceiling_cut by its own rule (the band table 0 / .5 / .2 is not monotone):
     * 0 in the low regime, else 0.5 - 0.3 smoothstep(o; 0.6, 0.9). Exact at 0.3 / 0.6 / 0.9.
     */
    public static function opennessCeilingCut(float $o): float
    {
        if ($o < self::OPENNESS_LOW_REGIME) return 0.0;
        return 0.5 - 0.3 * self::smoothstep($o, 0.6, 0.9);
    }

    /**
     * A5 at a vector: ['o' => MDD 1.4 value, 'band' => low|medium|high]. $bandByPreset is the
     * config temperament_openness table (preset => band), $levels its openness_levels.
     * At a preset: that preset's band (missing: $default) and its level.
     */
    public static function opennessAt(array $x, array $bandByPreset, array $levels, string $default = 'medium'): array
    {
        $on = self::presetAt($x);
        if ($on !== null) {
            $band = $bandByPreset[$on] ?? $default;
            return ['o' => is_numeric($levels[$band] ?? null) ? floatval($levels[$band]) : null, 'band' => $band];
        }
        $table = [];
        foreach (self::points() as $name => $_) {
            $b = $bandByPreset[$name] ?? $default;
            $table[$name] = floatval($levels[$b] ?? ($levels[$default] ?? 0.6));
        }
        $o = self::blend($x, $table, 'R', [1.15, 'G' => -1.02, 'Pd' => -0.16], 'openness');
        $band = $default;
        $bestD = INF;
        foreach ($levels as $b => $lvl) {
            $d = abs($o - floatval($lvl));
            if ($d < $bestD) { $bestD = $d; $band = $b; }
        }
        return ['o' => $o, 'band' => $band];
    }

    // =========================================================================
    // THE COLUMN REGISTRY (design §2.1). Tables are today's constants, per preset.
    // =========================================================================

    /** Old table filled for all 13 presets; a preset without a row gets the consumer's default. */
    private static function fill(array $table, $default): array
    {
        $out = [];
        foreach (self::PRESET_TRAITS as $name => $_) {
            $out[$name] = array_key_exists($name, $table) ? $table[$name] : $default;
        }
        return $out;
    }

    /**
     * Registered columns: id => ['ref' (design id), 'rule' (R|I), 'owners' (trait codes),
     * 'model' (linear [intercept, code => coef] or callable, for R), 'unit' (§2.4 clamp),
     * 'table' (callable: preset => today's value), 'legacy' (callable: label => today's lookup
     * result for a label that is not a preset, or null for "the consumer's default")].
     * Coefficients: design §2.1 (least squares against today's tables, rounded to 2 places);
     * A15g valence up and A24 catalyst / charmer are not printed there and were fitted the same
     * way over the 13 presets with the design's owners (R^2 .65 / .76 / .76).
     */
    public static function columns(): array
    {
        static $cols = null;
        if ($cols !== null) return $cols;
        $RD = 'RelationshipDynamics';
        $cols = [];
        $add = function (string $id, string $ref, string $rule, array $owners, $model, string $unit, callable $table, ?callable $legacy = null) use (&$cols) {
            $cols[$id] = compact('ref', 'rule', 'owners', 'model', 'unit', 'table', 'legacy');
        };

        // A1-A4 (MDD 1.3 multipliers; A2 passion points per bleedout event)
        $add('passion_mult', 'A1', 'R', ['E', 'D', 'G'], [0.80, 'E' => 0.81, 'D' => -0.28, 'G' => -0.28], 'mult',
            fn() => self::fill($RD::TEMPERAMENT_PASSION_MULT, 1.0));
        $add('bleedout', 'A2', 'I', ['C', 'L', 'Pd'], null, 'bleedout',
            fn() => self::fill($RD::TEMPERAMENT_BLEEDOUT_DRAIN, -1.5));
        $add('reunion_mult', 'A3', 'I', ['E', 'D'], null, 'mult',
            fn() => self::fill($RD::TEMPERAMENT_REUNION_MULT, 1.0));
        $add('jealousy_mult', 'A4', 'R', ['Po', 'Pd', 'D'], [0.08, 'Po' => 2.2, 'Pd' => 0.17, 'D' => -0.16], 'mult',
            fn() => self::fill($RD::TEMPERAMENT_JEALOUSY_MULT, 1.0));

        // A7-A14: temperament baselines (seeded dimensions), each in its dimension's unit
        $baselineModels = [
            'affinity'        => ['A7', 'R', ['W', 'G'], [-9.2, 'W' => 80.2, 'G' => 8.7], 'core_affinity'],
            'warmth'          => ['A8', 'R', ['W'], [0.0, 'W' => 100.0], 'dimension'],
            'maturity'        => ['A9', 'R', [], fn(array $x) => floatval($x['maturity_start'] ?? (17 + 26 * ($x['D'] ?? 0.5) + 30 * ($x['Rs'] ?? 0.5))), 'dimension'],
            'coord_m'         => ['A10', 'R', ['C', 'D', 'Pr'], [-42.0, 'C' => 130.0, 'D' => -3.0, 'Pr' => -5.0], 'coordinate'],
            'coord_f'         => ['A10', 'R', ['W', 'E'], [-52.0, 'W' => 190.0, 'E' => -5.0], 'coordinate'],
            'trust'           => ['A11', 'R', ['G', 'Po'], [55.8, 'G' => -34.8, 'Po' => -11.0], 'dimension'],
            'comfort'         => ['A12', 'R', ['G', 'C'], [47.9, 'G' => -54.0, 'C' => 24.8], 'dimension'],
            'respect'         => ['A13', 'I', ['Pd', 'W'], null, 'dimension'],
            'self_confidence' => ['A14', 'R', ['C'], [0.0, 'C' => 100.0], 'dimension'],
        ];
        foreach ($baselineModels as $dim => [$ref, $rule, $owners, $model, $unit]) {
            $add("baseline_{$dim}", $ref, $rule, $owners, $model, $unit,
                fn() => self::fill($RD::TEMPERAMENT_BASELINES[$dim], $RD::DIMENSION_DEFS[$dim]['default_baseline']));
        }

        // A15 + A17: plasticity Y per dimension and direction (unitless)
        $mat = [fn(array $x) => self::maturityY(floatval($x['Rs']), floatval($x['L']))[0],
                fn(array $x) => self::maturityY(floatval($x['Rs']), floatval($x['L']))[1]];
        $yModels = [
            // dim => [ref, [rule_up, owners_up, model_up], [rule_down, owners_down, model_down], unit]
            'affinity' => ['A15a', ['R', ['E', 'L', 'G'], [0.70, 'E' => 0.35, 'L' => 0.63, 'G' => -0.65]],
                                   ['R', ['E', 'L', 'G'], [0.70, 'E' => 0.35, 'L' => 0.63, 'G' => -0.65]], 'mult'],
            'passion'  => ['A15b', ['I', ['E'], null], ['I', ['E'], null], 'mult'],
            'warmth'   => ['A15c', ['R', ['G', 'W'], [0.78, 'G' => -0.80, 'W' => 1.06]],
                                   ['R', ['G', 'W'], [0.78, 'G' => -0.80, 'W' => 1.06]], 'mult'],
            'maturity' => ['A15d/A17', ['R', ['Rs', 'L'], $mat[0]], ['R', ['Rs', 'L'], $mat[1]], 'maturity_y'],
            'coord_m'  => ['A15e', ['I', ['C', 'D', 'Pr'], null], ['I', ['C', 'D', 'Pr'], null], 'mult'],
            'coord_f'  => ['A15e', ['I', ['W', 'E'], null], ['I', ['W', 'E'], null], 'mult'],
            'arousal'  => ['A15f', ['R', ['L', 'D'], [0.79, 'L' => 0.78, 'D' => -0.63]],
                                   ['R', ['L', 'D'], [0.79, 'L' => 0.78, 'D' => -0.63]], 'mult'],
            'valence'  => ['A15g', ['R', ['Rs', 'C', 'D'], [0.77, 'Rs' => 0.34, 'C' => 0.43, 'D' => -0.74]],
                                   ['I', ['Rs', 'C', 'D'], null], 'mult'],
            'trust'    => ['A15h', ['I', ['G'], null], ['R', ['Po', 'Pd'], [0.87, 'Po' => 0.92, 'Pd' => 0.28]], 'mult'],
            'comfort'  => ['A15i', ['R', ['G'], [1.52, 'G' => -1.46]], ['I', ['G'], null], 'mult'],
            'respect'  => ['A15j', ['R', ['G'], [0.97, 'G' => -0.59]], ['I', ['Pd'], null], 'mult'],
            'self_confidence' => ['A15k', ['I', ['C'], null], ['I', ['Rs', 'Pd'], null], 'mult'],
        ];
        foreach ($yModels as $dim => [$ref, $up, $down, $unit]) {
            foreach (['up' => $up, 'down' => $down] as $dir => [$rule, $owners, $model]) {
                $key = $dir === 'up' ? 'Y_up' : 'Y_down';
                $add("y_{$dim}_{$dir}", $ref, $rule, $owners, $model, $unit, function () use ($RD, $dim, $key) {
                    $rows = self::fill($RD::PLASTICITY_PROFILES[$dim], ['Y_up' => 1.0, 'Y_down' => 1.0]);
                    return array_map(fn($r) => $r[$key], $rows);
                });
            }
        }

        // A16: MDD 15.4 signal resistance (unitless). Humble has no row: 1.0 (decided, Q6).
        // A label that is not a preset keeps today's lookup (the unreachable 'Volatile' row).
        $resist = [
            'affinity' => [['E', 'L', 'G'], [0.29, 'E' => 0.99, 'L' => 0.42, 'G' => -0.10]],
            'trust'    => [['G', 'Po'], [1.28, 'G' => -0.79, 'Po' => -0.36]],
            'comfort'  => [['G', 'C'], [1.51, 'G' => -1.78, 'C' => 0.29]],
            'respect'  => [['Pd', 'C'], [0.34, 'Pd' => 0.88, 'C' => 0.41]],
            'maturity' => [['L', 'Rs'], [0.34, 'L' => 0.71, 'Rs' => 0.32]],
        ];
        foreach ($resist as $signal => [$owners, $model]) {
            $add("resist_{$signal}", 'A16', 'R', $owners, $model, 'mult',
                fn() => self::fill(array_map(fn($row) => $row[$signal] ?? 1.0, $RD::TEMPERAMENT_SIGNAL_RESISTANCE), 1.0),
                fn($label) => $RD::TEMPERAMENT_SIGNAL_RESISTANCE[$label][$signal] ?? null);
        }

        // A18 absence decay (core affinity points per tick; leaves temperament in phase 3), A19 tier retention
        $add('absence_decay', 'A18', 'I', ['Po', 'W', 'G'], null, 'absence_decay',
            fn() => self::fill($RD::TEMPERAMENT_DECAY_RATES, -0.5));
        $add('tier_retention', 'A19', 'R', ['D', 'Rs', 'Po'], [-11.7, 'D' => -10.8, 'Rs' => -9.1, 'Po' => 16.1], 'retention',
            fn() => self::fill($RD::TEMPERAMENT_TIER_RETENTION, -15));

        // A6 warmth curve numbers (the curve the temperament names, CURVE_PARAMS)
        $curve = function (string $key) use ($RD) {
            return function () use ($RD, $key) {
                $names = self::fill($RD::TEMPERAMENT_WARMTH_CURVES, $RD::CURVE_MODERATE);
                return array_map(fn($c) => $RD::CURVE_PARAMS[$c][$key], $names);
            };
        };
        $hl = [5.76, 'G' => 8.25];
        $add('warmth_half_life', 'A6', 'R', ['G'], $hl, 'half_life', $curve('half_life'));
        $add('warmth_decay_rate', 'A6', 'R', ['G'], fn(array $x) => self::linear($hl, $x) / 100.0, 'decay_rate', $curve('decay_rate'));
        $add('warmth_lambda', 'A6', 'R', ['G'], fn(array $x) => M_LN2 / self::linear($hl, $x), 'lambda', $curve('lambda'));
        $add('warmth_passion_decay', 'A6', 'I', ['G', 'E'], null, 'passion_decay', $curve('passion_decay'));

        // A24 charisma effectiveness per style: +1 effective, -1 ineffective, 0 neither
        $charisma = [
            'rock'     => ['I', ['C', 'W'], null],
            'catalyst' => ['R', ['E', 'D', 'G'], [1.09, 'E' => 0.26, 'D' => -1.28, 'G' => -1.55]],
            'charmer'  => ['R', ['W', 'Pd'], [-0.03, 'W' => 2.38, 'Pd' => -1.47]],
        ];
        foreach ($charisma as $style => [$rule, $owners, $model]) {
            $add("charisma_{$style}", 'A24', $rule, $owners, $model, 'effect', function () use ($RD, $style) {
                $p = $RD::CHARISMA_EFFECTIVENESS[$style];
                $out = [];
                foreach (self::PRESET_TRAITS as $name => $_) {
                    $out[$name] = in_array($name, $p['effective'] ?? [], true) ? 1.0 : (in_array($name, $p['ineffective'] ?? [], true) ? -1.0 : 0.0);
                }
                return $out;
            });
        }

        // A23 physical-state gates (healer: trust from 'injured'; warrior: 'bloody'), membership 0..1
        $add('healer_gate', 'A23', 'I', ['Pr'], null, 'unit01',
            fn() => array_map(fn($n) => in_array($n, $RD::PHYSICAL_HEALER_TEMPERAMENTS, true) ? 1.0 : 0.0, array_combine(array_keys(self::PRESET_TRAITS), array_keys(self::PRESET_TRAITS))));
        $add('warrior_gate', 'A23', 'I', ['C', 'Pd'], null, 'unit01',
            fn() => array_map(fn($n) => in_array($n, $RD::PHYSICAL_WARRIOR_TEMPERAMENTS, true) ? 1.0 : 0.0, array_combine(array_keys(self::PRESET_TRAITS), array_keys(self::PRESET_TRAITS))));

        return $cols;
    }

    public static function hasColumn(string $col): bool
    {
        return isset(self::columns()[$col]);
    }

    /** A registered column's per-preset table (today's values). */
    public static function table(string $col): array
    {
        if (!isset(self::$tables[$col])) {
            $spec = self::columns()[$col] ?? null;
            if ($spec === null) throw new \InvalidArgumentException("RelDynTraits: unknown column {$col}");
            self::$tables[$col] = ($spec['table'])();
        }
        return self::$tables[$col];
    }

    /** A registered column at a vector. */
    public static function value(array $x, string $col)
    {
        $spec = self::columns()[$col] ?? null;
        if ($spec === null) throw new \InvalidArgumentException("RelDynTraits: unknown column {$col}");
        return self::blend($x, self::table($col), $spec['rule'], $spec['model'], $spec['unit']);
    }

    /**
     * A registered column for a consumer's temperament label: through the label's preset point
     * (vectorFor), else today's lookup for a label that is not a preset (legacy table key or the
     * consumer's $default). $dynamics is for phase 2 (vector source); unused in phase 1.
     */
    public static function param($label, string $col, $default, ?array $dynamics = null)
    {
        $x = self::vectorFor($label, $dynamics);
        if ($x !== null) return self::value($x, $col);
        $legacy = self::columns()[$col]['legacy'] ?? null;
        if ($legacy !== null && (is_string($label) || is_int($label))) {
            $v = $legacy($label);
            if ($v !== null) return $v;
        }
        return $default;
    }

    // =========================================================================
    // CONFIG-KEYED TABLES (tables that live in the RelDyn config: preset name => value)
    // =========================================================================

    /** Old-style lookup for a label that is not a preset: $table[$label] ?? $default. */
    private static function legacyLookup(array $table, $label, $default)
    {
        if (!is_string($label) && !is_int($label)) {
            if ($label !== null) return $default;
            $label = '';
        }
        return array_key_exists($label, $table) && $table[$label] !== null ? $table[$label] : $default;
    }

    /**
     * A numeric config table (preset => number) for a label. At a preset: the table value as
     * stored (or $default); in between (phase 2): Rule R with $model, or Rule I.
     */
    public static function tableParam($label, array $table, $default, string $rule = 'I', $model = null, ?string $unit = null)
    {
        $x = self::vectorFor($label);
        if ($x === null) return self::legacyLookup($table, $label, $default);
        return self::tableAt($x, $table, $default, $rule, $model, $unit);
    }

    /** tableParam at a vector. */
    public static function tableAt(array $x, array $table, $default, string $rule = 'I', $model = null, ?string $unit = null)
    {
        $filled = [];
        foreach (self::PRESET_TRAITS as $name => $_) {
            $filled[$name] = (array_key_exists($name, $table) && $table[$name] !== null) ? $table[$name] : $default;
        }
        $on = self::presetAt($x);
        if ($on !== null) return $filled[$on];
        return self::blend($x, array_map('floatval', $filled), $rule, $model, $unit);
    }

    /**
     * A row-valued config table (preset => [key => number]; a missing key or row is 0, as the
     * additive consumers read it) for a label. At a preset: the stored row itself (or []);
     * in between: each key by its rule ($rules key => [rule, model]; default Rule I).
     */
    public static function rowParam($label, array $table, array $rules = [], string $unit = 'offset'): array
    {
        $x = self::vectorFor($label);
        if ($x === null) return (array) self::legacyLookup($table, $label, []);
        return self::rowAt($x, $table, $rules, $unit);
    }

    public static function rowAt(array $x, array $table, array $rules = [], string $unit = 'offset'): array
    {
        $on = self::presetAt($x);
        if ($on !== null) return (array) ($table[$on] ?? []);
        $keys = [];
        foreach (self::PRESET_TRAITS as $name => $_) {
            foreach ((array) ($table[$name] ?? []) as $k => $v) {
                if (is_numeric($v)) $keys[$k] = true;
            }
        }
        $out = [];
        foreach (array_keys($keys) as $k) {
            $col = [];
            foreach (self::PRESET_TRAITS as $name => $_) {
                $col[$name] = floatval(((array) ($table[$name] ?? []))[$k] ?? 0.0);
            }
            [$rule, $model] = $rules[$k] ?? ['I', null];
            $out[$k] = self::blend($x, $col, $rule, $model, $unit);
        }
        return $out;
    }

    /** A label-valued table (preset => label/text/list) for a label: nearest preset's value. */
    public static function labelParam($label, array $table, $default = null)
    {
        $x = self::vectorFor($label);
        if ($x === null) return self::legacyLookup($table, $label, $default);
        return self::labelAt($x, $table, $default);
    }

    /**
     * Membership of a label in a set of preset names, 0..1: exact 1 / 0 at a preset (Rule I of
     * the indicator in between); for a label that is not a preset, today's strict in_array.
     */
    public static function membership($label, array $names): float
    {
        $x = self::vectorFor($label);
        if ($x === null) return in_array($label, $names, true) ? 1.0 : 0.0;
        $ind = [];
        foreach (self::PRESET_TRAITS as $name => $_) $ind[$name] = in_array($name, $names, true) ? 1.0 : 0.0;
        return floatval(self::blend($x, $ind, 'I', null, 'unit01'));
    }

    // =========================================================================
    // A22: SOCIAL SENSITIVITY (curve-valued, Rule I pointwise over the preset curves)
    // =========================================================================

    /** The curve a preset uses on a dimension, with the Proud / Jealous exceptions (label). */
    public static function presetCurve(string $preset, string $dimensionId): string
    {
        $curve = RelationshipDynamics::TEMPERAMENT_SENSITIVITY_CURVES[$preset] ?? 'open_heart';
        if ($preset === 'Proud' && $dimensionId === 'respect') $curve = 'open_heart';
        if ($preset === 'Jealous' && in_array($dimensionId, ['passion', 'comfort'], true)) $curve = 'open_heart';
        return $curve;
    }

    /** Sensitivity multiplier (0..1) at a vector, bond level b (0..100), for a delta's sign. */
    public static function sensitivityAt(array $x, string $dimensionId, $bondLevel, bool $isNegative): float
    {
        $values = [];
        foreach (self::PRESET_TRAITS as $name => $_) {
            $values[$name] = RelationshipDynamics::calculateSocialSensitivity(self::presetCurve($name, $dimensionId), $bondLevel, $isNegative);
        }
        return floatval(self::blend($x, $values, 'I', null, 'unit01'));
    }

    // =========================================================================
    // STORAGE (design §4.6): trait_vector mirrors the label in phase 1
    // =========================================================================

    /** A vector in storage form: storage names => 0..1, plus maturity_start. */
    public static function toStored(array $x): array
    {
        $out = [];
        foreach (self::TRAITS as $code => $name) $out[$name] = $x[$code];
        if (isset($x['maturity_start'])) $out['maturity_start'] = $x['maturity_start'];
        return $out;
    }

    /** A stored vector back to codes. */
    public static function fromStored(array $v): array
    {
        return self::normalizeVector($v);
    }

    /**
     * Write trait_vector, _trait_vector_src, trait_preset and trait_vector_version from the
     * NPC's temperament label (phase 1: the label's preset point, or null without one). The
     * 'traits' tag list is not touched. Returns the updated state.
     */
    public static function syncStored(array $dynamics): array
    {
        $label = $dynamics['inferred_temperament'] ?? null;
        $x = self::presetPoint($label);
        if ($x === null) {
            $dynamics['trait_vector'] = null;
            $dynamics['_trait_vector_src'] = null;
            $dynamics['trait_preset'] = null;
        } else {
            $dynamics['trait_vector'] = self::toStored($x);
            $dynamics['_trait_vector_src'] = ['source' => 'preset', 'preset' => $label, 'assignment' => self::ASSIGNMENT];
            $dynamics['trait_preset'] = ['nearest' => $label, 'distance' => 0.0];
        }
        $dynamics['trait_vector_version'] = self::VERSION;
        return $dynamics;
    }

    /**
     * profile_overrides.trait_vector (editor per-trait override, precedence 1 in §4.1): a
     * partial map of trait storage names (or codes) => 0..1. Null if empty, any key unknown or
     * any value not a number in 0..1. Stored in phase 1, not read by assignment.
     */
    public static function validOverride($value): ?array
    {
        if (!is_array($value) || !$value) return null;
        $byName = array_flip(self::TRAITS);
        $out = [];
        foreach ($value as $k => $v) {
            $name = isset(self::TRAITS[$k]) ? self::TRAITS[$k] : (isset($byName[$k]) ? $k : null);
            if ($name === null || !is_numeric($v)) return null;
            $v = floatval($v);
            if ($v < 0.0 || $v > 1.0) return null;
            $out[$name] = $v;
        }
        $ordered = [];
        foreach (self::TRAITS as $name) {
            if (array_key_exists($name, $out)) $ordered[$name] = $out[$name];
        }
        return $ordered;
    }
}
