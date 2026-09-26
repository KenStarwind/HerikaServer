<?php
/**
 * Relationship Dynamics — the two-axis dimensions as live state: the M/F behavioural envelope
 * (MDD 3.1, dimension draft Dimension 5, roadmap mf-coordinates) and arousal / valence, the
 * horseshoe (MDD 3.2, dimension draft Dimension 6, roadmap arousal-valence).
 *
 * M/F, derived at display time like derived warmth (session recap 2026-03-31 Fix 6):
 *   coord  = stored x + signal x Y (+ the optional heart terms below), clamped -100..100
 *     stored x  the personality anchor the traits engine seeds (A10 baseline_coord_m /
 *               baseline_coord_f through getTemperamentBaseline) plus the states held on it
 *               (a creature row, RelDynCreatures); this module never writes it
 *     signal    (mean of the drivers - neutral) x maturity / 100
 *               coord_m: respect + self_confidence   (assertive, protective / cold, dismissive)
 *               coord_f: trust + comfort             (nurturing, open / needy, insecure)
 *               a per-bond driver as it reads toward the player (getEffectiveDimensionValue),
 *               a global one (self_confidence) raw; maturity the live x (a drunk or frightened
 *               NPC holds her shape less)
 *     Y         the traits engine's directional plasticity for the coordinate (A15e,
 *               y_coord_*_up / y_coord_*_down via getPlasticityProfile): "the limits (arrows)
 *               are set by personality" -- how readily she goes toward each pole
 *   The heart terms (MDD 3.1 "real-time coordinates driven by Passion, Jealousy, Conflict";
 *   magnitudes unspecified, so 0 = off by default: open question): jealousy_reach /
 *   conflict_reach coordinate points at jealousy / resentment 100, split between the poles by
 *   who she is. Jealousy: a hard character (confidence, little warmth) slides to -M
 *   (aggressive), a soft one to -F (insecure, icy). Conflict: by her attachment axes, avoidance
 *   to -M (cold), anxiety to -F (needy) (draft: "felt dismissed").
 *   The quadrant keywords (MF_QUADRANT_BANDS) read the derived coordinates; the felt line speaks
 *   when they sit away from the anchor (the stored baseline).
 *
 * Arousal / valence: event-driven (combat, bleedout, rescue, places, creatures, drinks, the
 * scene the plugin reports), never scored by the eval; the eval READS the band (evalLine).
 *   settle  "arousal decays fast, it's momentary" (Z 8), valence "fast decay toward neutral"
 *           (Z 12): what events left on x (x - baseline - the states held on it) halves every
 *           half_life_play_minutes of play, on the play clock or the game calendar, whichever
 *           moved more (the passion spike's clock: "a short half-life, like arousal"). Held
 *           states (a creature row, the place, a drink, the weather's pull, an afterglow) stay
 *           until their state ends. A calendar behind the stamp (a load) restarts the clock.
 *   social  (default off: open question) an applied eval item's grievance, jealousy and tags as
 *           an arousal spike whose valence the context decides (the horseshoe), x (0.5 +
 *           significance).
 *
 * Units: coordinates -100..100 points; arousal 0..100 points; valence -100..100 points; drivers
 * 0..100 points; jealousy / resentment 0..100 points; half-lives in play minutes (60 x
 * GAMETS_PER_REAL_SECOND gamets each); time in raw gamets.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynMoodAxes
{
    /** When arousal / valence last settled: ['play' => play gamets, 'gamets' => raw game calendar]. */
    const CLOCK_KEY = '_mood_settle_clock';
    const COORDS = ['coord_m', 'coord_f'];
    const MOOD_DIMS = ['arousal', 'valence'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'mood_axes' (a stored config replaces whole settings). */
    public static function configDefaults(): array
    {
        return [
            'derived_coords' => [
                'enabled' => true,
                // Fix 6 (2026-03-31): M from respect + self-confidence, F from trust + comfort
                'drivers' => ['coord_m' => ['respect', 'self_confidence'], 'coord_f' => ['trust', 'comfort']],
                // driver points at which the drivers push neither way (Fix 6: 50)
                'neutral' => 50.0,
                // MDD 3.1 heart terms, coordinate points at jealousy / resentment 100 (0 = off)
                'jealousy_reach' => 0.0,
                'conflict_reach' => 0.0,
            ],
            'settle' => [
                'enabled' => true,
                // play minutes in which what events left on x halves (arousal Z 8 < valence Z 12:
                // the valence half-life is the arousal one x 12 / 8)
                'half_life_play_minutes' => ['arousal' => 5.0, 'valence' => 7.5],
            ],
            'social' => [
                'enabled' => false,
                // arousal / valence points per level (1..3) of the item's grievance / jealousy
                'grievance_per_level' => ['arousal' => 8.0, 'valence' => -6.0],
                'jealousy_per_level'  => ['arousal' => 8.0, 'valence' => -6.0],
                // arousal / valence points per tag of the item
                'tags' => [
                    'rescue'   => ['arousal' => 12.0, 'valence' => 8.0],
                    'intimacy' => ['arousal' => 10.0, 'valence' => 6.0],
                    'touch'    => ['arousal' => 5.0, 'valence' => 3.0],
                    'insult'   => ['arousal' => 8.0, 'valence' => -8.0],
                    'betrayal' => ['arousal' => 12.0, 'valence' => -12.0],
                    'lie'      => ['arousal' => 6.0, 'valence' => -6.0],
                    'praise'   => ['valence' => 4.0],
                    'quality_time' => ['valence' => 3.0],
                    'reassurance'  => ['arousal' => -4.0, 'valence' => 4.0],
                ],
            ],
            // the eval sees the arousal / valence band as input (evalLine)
            'eval_input' => true,
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('mood_axes');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    // =====================================================================
    // M/F: THE DERIVED COORDINATES (pure)
    // =====================================================================

    /**
     * The coordinate as it reads now (module doc): the stored x moved by the live state. Null
     * without a stored x. With derived_coords off: the stored x. Pure.
     */
    public static function derivedCoord(array $dynamics, string $coord, ?array $cfg = null): ?float
    {
        $x = $dynamics['dimensions'][$coord]['x'] ?? null;
        if (!is_numeric($x) || !in_array($coord, self::COORDS, true)) return null;
        $d = (array) (($cfg ?? self::config())['derived_coords'] ?? []);
        if (empty($d['enabled'])) return floatval($x);
        return max(-100.0, min(100.0, floatval($x) + self::coordShift($dynamics, $coord, $d)));
    }

    /** Both coordinates ('coord_m' / 'coord_f' => ?float) and their anchors ('anchor_m' / 'anchor_f', the stored baselines). Pure. */
    public static function derivedCoords(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $out = [];
        foreach (self::COORDS as $coord) {
            $out[$coord] = self::derivedCoord($dynamics, $coord, $cfg);
            $b = $dynamics['dimensions'][$coord]['baseline'] ?? null;
            $out[$coord === 'coord_m' ? 'anchor_m' : 'anchor_f'] = is_numeric($b) ? floatval($b) : 0.0;
        }
        return $out;
    }

    /**
     * Points the live state moves $coord away from its stored x: the drivers' signal x the
     * directional Y, plus the heart terms when configured. Pure.
     */
    public static function coordShift(array $dynamics, string $coord, array $d): float
    {
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $vals = [];
        foreach ((array) (((array) ($d['drivers'] ?? []))[$coord] ?? []) as $dim) {
            $dim = (string) $dim;
            $v = in_array($dim, RelationshipDynamics::GLOBAL_DIMENSIONS, true)
                ? ($dims[$dim]['x'] ?? null)
                : (isset($dims[$dim]['x']) ? RelationshipDynamics::getEffectiveDimensionValue($dynamics, $dim) : null);
            if (is_numeric($v)) $vals[] = floatval($v);
        }
        $shift = 0.0;
        if ($vals !== []) {
            $maturity = max(0.0, min(100.0, floatval($dims['maturity']['x'] ?? 50.0)));
            $signal = (array_sum($vals) / count($vals) - floatval($d['neutral'] ?? 50.0)) * $maturity / 100.0;
            $shift += $signal * self::y($dynamics, $coord, $signal);
        }
        $shift += self::heartShift($dynamics, $coord, $d);
        return $shift;
    }

    /** The traits engine's plasticity for $coord in the direction of $delta (1.0 when unknown). */
    private static function y(array $dynamics, string $coord, float $delta): float
    {
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        $p = RelationshipDynamics::getPlasticityProfile($temperament, $coord, [], $dynamics);
        $y = $delta >= 0 ? ($p['Y_up'] ?? 1.0) : ($p['Y_down'] ?? 1.0);
        return is_numeric($y) ? max(0.0, floatval($y)) : 1.0;
    }

    /**
     * MDD 3.1's heart: jealousy and conflict pull toward the negative poles, split by who she
     * is (module doc). 0 while both reaches are 0 (the default). Pure.
     */
    private static function heartShift(array $dynamics, string $coord, array $d): float
    {
        $jr = floatval($d['jealousy_reach'] ?? 0.0);
        $cr = floatval($d['conflict_reach'] ?? 0.0);
        if ($jr == 0.0 && $cr == 0.0) return 0.0;
        $isM = $coord === 'coord_m';
        $shift = 0.0;
        if ($jr != 0.0) {
            $j = max(0.0, min(100.0, floatval($dynamics['jealousy_anger'] ?? 0.0)));
            $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
            // hard: confident and cool; soft: the rest (0.5 without a vector)
            $hard = $x !== null ? max(0.0, min(1.0, (floatval($x['C'] ?? 0.5) + 1.0 - floatval($x['W'] ?? 0.5)) / 2.0)) : 0.5;
            $shift -= $jr * $j / 100.0 * ($isM ? $hard : 1.0 - $hard);
        }
        if ($cr != 0.0) {
            $r = max(0.0, min(100.0, floatval($dynamics['dimensions']['resentment']['x'] ?? 0.0)));
            $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
            $u = max(0.0, floatval($axes['anxiety'] ?? 0.0));
            $v = max(0.0, floatval($axes['avoidance'] ?? 0.0));
            $cold = ($u + $v) > 0 ? $v / ($u + $v) : 0.5;
            $shift -= $cr * $r / 100.0 * ($isM ? $cold : 1.0 - $cold);
        }
        return $shift;
    }

    // =====================================================================
    // AROUSAL / VALENCE: SETTLE (time), SOCIAL (eval items), EVAL INPUT
    // =====================================================================

    /**
     * What events left on arousal and valence halves with time (module doc). Run once per
     * prerequest of the NPC. Returns dimension => points settled (signed; empty when nothing
     * moved). The first call only stamps the clock.
     */
    public static function settle(array &$dynamics, float $now): array
    {
        $cfg = (array) (self::config()['settle'] ?? []);
        if (empty($cfg['enabled'])) return [];
        $clock = is_array($dynamics[self::CLOCK_KEY] ?? null) ? $dynamics[self::CLOCK_KEY] : null;
        $play = floatval(RelationshipDynamics::getPlayGamets($dynamics));
        $out = [];
        if ($clock !== null) {
            $last = floatval($clock['gamets'] ?? 0);   // raw gamets
            $dt = max(0.0, $play - floatval($clock['play'] ?? $play));   // play gamets
            if ($now > 0 && $last > 0 && $now >= $last) $dt = max($dt, $now - $last);
            if ($dt > 0.0) {
                foreach (self::MOOD_DIMS as $dim) {
                    $moved = self::settleDim($dynamics, $dim, $dt, $cfg);
                    if ($moved !== 0.0) $out[$dim] = $moved;
                }
            }
        }
        $dynamics[self::CLOCK_KEY] = ['play' => $play, 'gamets' => $now > 0 ? $now : floatval($clock['gamets'] ?? 0)];
        if ($out !== []) {
            RelationshipDynamics::log('[MOOD] settled ' . json_encode(array_map(fn($v) => round($v, 3), $out)));
        }
        return $out;
    }

    /** One dimension's event residue after $dt gamets (points moved, signed). */
    private static function settleDim(array &$dynamics, string $dim, float $dt, array $cfg): float
    {
        $x = $dynamics['dimensions'][$dim]['x'] ?? null;
        $half = floatval(((array) ($cfg['half_life_play_minutes'] ?? []))[$dim] ?? 0.0) * 60.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        $def = RelationshipDynamics::getDimensionDefinition($dim);
        if (!is_numeric($x) || $half <= 0.0 || !$def) return 0.0;
        $base = is_numeric($dynamics['dimensions'][$dim]['baseline'] ?? null)
            ? floatval($dynamics['dimensions'][$dim]['baseline']) : floatval($def['default_baseline']);
        $held = RelationshipDynamics::heldTemporaryOffset($dynamics, $dim);
        $residue = floatval($x) - $base - $held;
        if (abs($residue) < 0.01) return 0.0;
        $left = $residue * 0.5 ** ($dt / $half);
        if (abs($left) < 0.01) $left = 0.0;
        $new = max(floatval($def['range_min']), min(floatval($def['range_max']), $base + $held + $left));
        $dynamics['dimensions'][$dim]['x'] = round($new, 4);
        return $new - floatval($x);
    }

    /**
     * The social feed (default off): an applied eval item's grievance, jealousy and tags as
     * arousal / valence deltas through applyDelta, x (0.5 + significance). Returns dimension =>
     * actual points applied.
     */
    public static function onEvalItem(array &$dynamics, array $item, ?string $temperament = null): array
    {
        $cfg = (array) (self::config()['social'] ?? []);
        if (empty($cfg['enabled'])) return [];
        $raw = ['arousal' => 0.0, 'valence' => 0.0];
        $add = function ($row, float $k) use (&$raw): void {
            foreach ((array) $row as $dim => $p) {
                if (isset($raw[$dim]) && is_numeric($p)) $raw[$dim] += floatval($p) * $k;
            }
        };
        if (!empty($item['grievance']['flag'])) $add($cfg['grievance_per_level'] ?? [], max(1.0, floatval($item['grievance']['severity'] ?? 1)));
        if (!empty($item['jealousy']['flag'])) $add($cfg['jealousy_per_level'] ?? [], max(1.0, floatval($item['jealousy']['intensity'] ?? 1)));
        foreach ((array) ($item['tags'] ?? []) as $tag) $add(((array) ($cfg['tags'] ?? []))[(string) $tag] ?? [], 1.0);
        $k = 0.5 + max(0.0, min(1.0, floatval($item['significance'] ?? 0.33)));
        $out = [];
        foreach ($raw as $dim => $p) {
            if (abs($p) < 1e-6) continue;
            $applied = RelationshipDynamics::applyDelta($dim, $dynamics, $p * $k, $temperament);
            if (abs($applied) > 1e-6) $out[$dim] = $applied;
        }
        return $out;
    }

    /**
     * The arousal / valence band as the eval sees it (an input, never scored), or null while
     * she is settled (the felt line's own condition: arousal above resting or valence away from
     * neutral, and not the Settled zone).
     */
    public static function evalLine(array $dynamics): ?string
    {
        if (empty(self::config()['eval_input'])) return null;
        $a = $dynamics['dimensions']['arousal']['x'] ?? null;
        $v = $dynamics['dimensions']['valence']['x'] ?? null;
        if (!is_numeric($a) || !is_numeric($v)) return null;
        if (floatval($a) <= 10.0 && abs(floatval($v)) <= 15.0) return null;
        $band = RelationshipDynamics::getArousalValenceBand($a, $v);
        if (($band['label'] ?? '') === 'Settled') return null;
        return "Nervous state: {$band['label']} ({$band['keywords']})";
    }

    /** Jev's numbers (decisions §3): the derived coordinates and their anchors, coordinate points. */
    public static function jev(array $dynamics): array
    {
        $c = self::derivedCoords($dynamics);
        $r = fn($v) => $v === null ? null : round(floatval($v), 2);
        return ['m' => $r($c['coord_m']), 'f' => $r($c['coord_f']), 'anchor_m' => $r($c['anchor_m']), 'anchor_f' => $r($c['anchor_f'])];
    }
}
