<?php
/**
 * Relationship Dynamics — fulfillment coverage and the mature boundary.
 *
 * Rulings 2026-09-24 §9: neglect is the absence of fulfillment, not only of the player. Each
 * NPC has a needs vector (the spider graph's axes): the facets it loves (signed preferences,
 * reldyn_facets.php), its love languages (MDD 1.2) and trait-driven needs (egocentric wants
 * admiration, insecure / anxious want reassurance and time). The relationship delivers
 * against those axes over game time (eval source tags, places and things experienced
 * together); each axis keeps a level that decays on the game calendar, so recent matters
 * more. Coverage per axis and the weighted band are -1..+1. A low band is neglect even when
 * the player is around; a high band buffers absence (RelationshipDynamics::advanceCalendar);
 * the band feeds internal weather's deprivation (RelDynFacets::updateWeather).
 *
 * The mature boundary (§9): a mature NPC whose band stays low states a calm boundary once,
 * then watches a probation window in which coverage must hold above a recovery threshold day
 * after day; success clears it, failure is a deliberate step-back of core's relationship type
 * (RelationshipDynamics::advanceFulfillment / changeCoreRelationshipType).
 *
 * State: $dynamics['_fulfillment'] (compact, see ensure()). Everything here is pure: no
 * database, no clock reads. The integration (neglect resentment, the core write) lives in
 * RelationshipDynamics.
 *
 * Units: levels are "delivery units" (one full-significance delivery of a tag = its table
 * amount); coverage and band -1..+1; trend band per game day; game days = raw gamets /
 * RelationshipDynamics::GAMETS_PER_DAY on the game calendar.
 */

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/reldyn_facets.php';

class RelDynFulfillment
{
    const STATE_KEY = '_fulfillment';
    const VERSION = 1;

    /** Kinds of axes: facets (FACETS), love languages (LL_*), trait-driven needs (the rest). */
    const KIND_FACET = 'facet';
    const KIND_LOVE_LANGUAGE = 'love_language';
    const KIND_NEED = 'need';

    const LOVE_LANGUAGES = [
        RelationshipDynamics::LL_WORDS, RelationshipDynamics::LL_TIME, RelationshipDynamics::LL_TOUCH,
        RelationshipDynamics::LL_SERVICE, RelationshipDynamics::LL_GIFTS,
    ];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'fulfillment' (a stored config replaces whole settings/tables).
     * Every number here is Serene's starting value for Ken's §9 ruling (the MDD gives none);
     * tune after playtest.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,

            // --- needs (the spider graph's axes) ---
            'facet_need_min'   => 0.3,   // signed preference (-1..+1) from which a facet is a need; weight = preference
            'love_language_weights' => ['primary' => 1.0, 'secondary' => 0.6],   // need weight per love-language slot (MDD 1.2)
            // Trait / attachment / temperament driven needs: each matching rule adds its axis weights
            // (an axis weight is capped at 1). Axes: a facet, a love language (LL_* value) or a need name.
            'need_rules' => [
                ['trait' => 'egocentric', 'axes' => ['admiration' => 0.8]],
                ['trait' => 'insecure', 'axes' => ['reassurance' => 0.8]],
                ['attachment' => 'anxious', 'axes' => [RelationshipDynamics::LL_TIME => 0.6, 'reassurance' => 0.6]],
                ['attachment' => 'toxic', 'axes' => ['reassurance' => 0.4]],
                ['temperament' => 'Proud', 'axes' => ['admiration' => 0.4]],
            ],

            // --- deliveries (what the relationship gives, in delivery units) ---
            // Eval source tag => axis => units at full significance. Negative units take coverage away.
            'tag_delivery' => [
                'gift'             => [RelationshipDynamics::LL_GIFTS => 1.0, 'admiration' => 0.4],
                'praise'           => [RelationshipDynamics::LL_WORDS => 1.0, 'admiration' => 1.0],
                'reassurance'      => [RelationshipDynamics::LL_WORDS => 0.6, 'reassurance' => 1.0],
                'apology'          => [RelationshipDynamics::LL_WORDS => 0.3, 'reassurance' => 0.6],
                'quality_time'     => [RelationshipDynamics::LL_TIME => 1.0, 'reassurance' => 0.4],
                'touch'            => [RelationshipDynamics::LL_TOUCH => 1.0],
                'intimacy'         => [RelationshipDynamics::LL_TOUCH => 1.0, 'reassurance' => 0.3],
                'help'             => [RelationshipDynamics::LL_SERVICE => 1.0],
                'rescue'           => [RelationshipDynamics::LL_SERVICE => 1.0, 'reassurance' => 0.3],
                'insult'           => [RelationshipDynamics::LL_WORDS => -1.0, 'admiration' => -1.0],
                'criticism'        => [RelationshipDynamics::LL_WORDS => -0.5, 'admiration' => -0.6],
                'neglect'          => [RelationshipDynamics::LL_TIME => -1.0, 'reassurance' => -0.6],
                'jealousy_trigger' => ['reassurance' => -1.0],
                'betrayal'         => ['reassurance' => -1.5, RelationshipDynamics::LL_TIME => -0.5],
                'lie'              => ['reassurance' => -0.6],
                'command'          => ['admiration' => -0.3],
            ],
            // A positive exchange the eval scored is some time together even without a tag.
            'positive_interaction_delivery' => [RelationshipDynamics::LL_TIME => 0.3],
            // Units scale with the exchange's significance s (0..1): x (floor + (1 - floor) x s).
            'significance_floor' => 0.3,
            // A local-classifier love-language exchange (the eval did not score it): units to that LL.
            'legacy_love_language_units' => 0.5,
            // Places and things experienced together (facet weight 0..1 x units):
            'place_units_per_game_hour' => 0.5,   // per game hour spent in a place (placeTurn exposure)
            'experience_units'          => 0.5,   // per discrete experience (a fight, a gift's facets)

            // --- levels -> coverage ---
            'target_units'        => 3.0,   // level at which an axis is fully covered (coverage +1); 0 = -1
            'start_units'         => 1.5,   // a new axis starts here (coverage 0)
            // Level cap: full coverage, no banked surplus, so one binge carries a few days at most
            // (a probation needs consistent_game_days of change, not one good day).
            'max_units'           => 3.0,
            'half_life_game_days' => 3.0,   // levels halve every this many game days without delivery

            // --- band history, trend ---
            'trend_game_days'      => 7,    // daily samples the trend (least-squares slope) reads
            'max_catchup_game_days' => 60,  // at most this many missed day-ends are sampled in one tick

            // --- low fulfillment = neglect even when present ---
            'low_band' => -0.25,            // band below this is low fulfillment
            // RAW resentment per game day while present and low, at band -1 (linear from low_band):
            // bond rate (neglect_bond_types) x this x the NPC's neglect rate_mult. Absence is 1x.
            'unfulfilled_rate_mult' => 0.5,
            // High band buffers absence: grace x 2^(grace x band), absence rate x 2^(rate x band)
            // with band = the band at the last contact.
            'absence_band_log2' => ['grace' => 1.0, 'rate' => -1.0],
            // Internal weather: relationship deprivation = clamp(-band, 0, 1) x this; the weather
            // takes the larger of it and the facet deprivation.
            'weather_deprivation_scale' => 1.0,

            // --- mature boundary (§9) ---
            'mature_at'                 => 60.0,  // maturity (0..100) from which the NPC enforces a boundary
            'boundary_sustain_game_days' => 5.0,  // band below low_band this long -> the boundary is stated
            'probation_game_days'       => 7.0,   // window after the statement
            'recovery_band'             => 0.1,   // a day counts as changed when its day-end band is at least this
            'consistent_game_days'      => 4,     // consecutive changed days that clear the boundary
            // Core relationships.Player.type => the type a failed probation steps back to. Types
            // not listed have no boundary (nothing to step back from).
            'step_back_types' => [
                'romantic'   => 'platonic',
                'crush'      => 'platonic',
                'platonic'   => 'professional',
                'protective' => 'professional',
                'admirer'    => 'professional',
                'familial'   => 'estranged',
            ],

            // --- felt text (feelings, never numbers) ---
            'felt_text' => [
                'unmet'     => "{NAME} feels something missing between them and {PLAYER} lately: {NEEDS}.",
                'boundary'  => "{NAME} has thought about this calmly and is ready to say it plainly: they need {NEEDS} from {PLAYER}, "
                    . "and it has not been there. They understand life gets in the way, but this is not what they want from the two of them. "
                    . "No anger, no ultimatum shouted: if it is going to change, it has to change consistently, not for a day.",
                'probation' => "{NAME} said what they need from {PLAYER}. Now they are quietly watching whether it really changes, day after day.",
                'resolved'  => "{NAME} has noticed the change in {PLAYER} and it has held. The worry they carried has eased.",
                'step_back' => "{NAME} has made a decision and is at peace with it: what they asked of {PLAYER} did not change, "
                    . "so they are stepping back from {FROM} to {TO}. No scene, no bitterness; they will be kind, but that closeness is over.",
            ],
            // Axis => how the NPC would name missing it (prose). Facets without a line use their name.
            'need_phrases' => [
                RelationshipDynamics::LL_WORDS   => 'hearing that they matter',
                RelationshipDynamics::LL_TIME    => 'real time together',
                RelationshipDynamics::LL_TOUCH   => 'closeness and touch',
                RelationshipDynamics::LL_SERVICE => 'being looked after',
                RelationshipDynamics::LL_GIFTS   => 'small signs of thought',
                'admiration'  => 'being admired and respected',
                'reassurance' => 'reassurance that they are wanted',
                'combat'      => 'a real fight side by side',
                'nature'      => 'time out in the wilds',
                'wild'        => 'open country',
                'scholarly'   => 'books and learning shared',
                'adventure'   => 'adventure together',
                'social'      => 'company and good talk',
                'crafting'    => 'making things together',
                'alchemy'     => 'the craft of alchemy',
                'enchanting'  => 'the study of enchanting',
                'domestic'    => 'a home life',
                'spiritual'   => 'faith and the sacred',
                'wealth'      => 'prosperity',
                'luxury'      => 'comfort and fine things',
                'quiet'       => 'quiet moments',
            ],
            // Core type => prose for the step-back text.
            'type_phrases' => [
                'romantic' => 'a romance', 'crush' => 'something romantic', 'platonic' => 'friendship',
                'professional' => 'a polite acquaintance', 'protective' => 'a close bond', 'admirer' => 'admiration',
                'familial' => 'family closeness', 'estranged' => 'distance',
            ],
        ];
    }

    /** The fulfillment settings: stored config per setting/table, defaults for the rest. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('fulfillment');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function day(): float
    {
        return (float) RelationshipDynamics::GAMETS_PER_DAY;
    }

    // =====================================================================
    // NEEDS
    // =====================================================================

    /** Kind of an axis name. */
    public static function axisKind(string $axis): string
    {
        if (in_array($axis, RelDynFacets::FACETS, true)) return self::KIND_FACET;
        if (in_array($axis, self::LOVE_LANGUAGES, true)) return self::KIND_LOVE_LANGUAGE;
        return self::KIND_NEED;
    }

    /**
     * The NPC's needs vector: axis => weight 0..1 (sorted, heaviest first). Sources:
     *   facets whose signed preference is at least facet_need_min (weight = preference);
     *   love_language_primary / _secondary (love_language_weights);
     *   need_rules matching the NPC's traits, attachment style or temperament.
     * Pure: $prefs from RelDynFacets::preferences().
     */
    public static function needs(array $dynamics, array $prefs, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $w = [];
        $add = function (string $axis, float $v) use (&$w) {
            if ($v <= 0) return;
            $w[$axis] = min(1.0, ($w[$axis] ?? 0.0) + $v);
        };
        $min = floatval($cfg['facet_need_min']);
        foreach (RelDynFacets::FACETS as $facet) {
            $p = floatval($prefs[$facet] ?? 0);
            if ($p >= $min && $p > 0) $add($facet, $p);
        }
        foreach ((array) $cfg['love_language_weights'] as $slot => $weight) {
            $ll = $dynamics['love_language_' . $slot] ?? null;
            if (is_string($ll) && in_array($ll, self::LOVE_LANGUAGES, true)) $add($ll, floatval($weight));
        }
        $traits = RelationshipDynamics::getTraits($dynamics);
        $attachment = RelationshipDynamics::getAttachmentStyle($dynamics);
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        foreach ((array) $cfg['need_rules'] as $rule) {
            if (!is_array($rule)) continue;
            $match = (isset($rule['trait']) && in_array(strtolower((string) $rule['trait']), $traits, true))
                || (isset($rule['attachment']) && $rule['attachment'] === $attachment)
                || (isset($rule['temperament']) && $rule['temperament'] === $temperament);
            if (!$match) continue;
            foreach ((array) ($rule['axes'] ?? []) as $axis => $v) {
                if (is_numeric($v)) $add((string) $axis, floatval($v));
            }
        }
        arsort($w);
        return array_map(fn($v) => round($v, 4), $w);
    }

    // =====================================================================
    // LEVELS, COVERAGE, BAND
    // =====================================================================

    /** Axis levels (delivery units) at $at: the stored levels decayed on the game calendar. */
    public static function levelsAt(array $state, float $at, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $from = floatval($state['gamets'] ?? 0);
        $days = max(0.0, $at - $from) / self::day();
        $half = floatval($cfg['half_life_game_days']);
        $f = ($half > 0 && $days > 0) ? pow(0.5, $days / $half) : 1.0;
        $out = [];
        foreach ((array) ($state['lv'] ?? []) as $axis => $level) {
            $out[$axis] = floatval($level) * $f;
        }
        return $out;
    }

    /** Coverage (-1..+1) of a level: 0 units = -1, target_units = +1, linear between. */
    public static function coverageOf(float $level, array $cfg): float
    {
        $target = max(0.001, floatval($cfg['target_units']));
        return max(-1.0, min(1.0, 2.0 * $level / $target - 1.0));
    }

    /**
     * Coverage per need and the band (need-weighted mean coverage) from $weights (axis =>
     * weight) and $levels (axis => units). An axis without a level is uncovered (-1).
     *
     * @return array ['coverage' => axis => -1..1, 'band' => -1..1]
     */
    public static function evaluate(array $weights, array $levels, array $cfg): array
    {
        $coverage = [];
        $sum = 0.0;
        $wSum = 0.0;
        foreach ($weights as $axis => $w) {
            $c = self::coverageOf(floatval($levels[$axis] ?? 0.0), $cfg);
            $coverage[$axis] = round($c, 4);
            $sum += floatval($w) * $c;
            $wSum += floatval($w);
        }
        return ['coverage' => $coverage, 'band' => $wSum > 0 ? round($sum / $wSum, 4) : 0.0];
    }

    /** Band at $at from the stored state (weights and levels as stored). */
    public static function bandAt(array $state, float $at, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        return self::evaluate((array) ($state['w'] ?? []), self::levelsAt($state, $at, $cfg), $cfg)['band'];
    }

    /**
     * Internal weather's relationship deprivation (0..1) at $now: clamp(-band, 0, 1) x
     * weather_deprivation_scale; null with no state or fulfillment off (the weather then reads
     * facet deprivation alone), 0 once the bond is not one whose neglect matters (neglectBond).
     */
    public static function weatherDeprivation(array $dynamics, float $now): ?float
    {
        $state = $dynamics[self::STATE_KEY] ?? null;
        if ($now <= 0 || !self::enabled() || !is_array($state) || !is_array($state['w'] ?? null)) return null;
        if (RelationshipDynamics::neglectBond($dynamics) === null) return 0.0;   // no bond whose needs weigh on her
        $cfg = self::config();
        $band = self::bandAt($state, max($now, floatval($state['gamets'] ?? 0)), $cfg);
        return max(0.0, min(1.0, -$band * floatval($cfg['weather_deprivation_scale'])));
    }

    /** Least-squares slope of the last trend_game_days daily samples (band per game day); 0 below two. */
    public static function trend(array $days, array $cfg): float
    {
        $days = array_slice(array_values(array_filter($days, 'is_array')), -max(2, intval($cfg['trend_game_days'])));
        $n = count($days);
        if ($n < 2) return 0.0;
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($days as [$d, $b]) {
            $sx += $d; $sy += $b; $sxx += $d * $d; $sxy += $d * $b;
        }
        $den = $n * $sxx - $sx * $sx;
        return $den == 0.0 ? 0.0 : round(($n * $sxy - $sx * $sy) / $den, 5);
    }

    /**
     * The fulfillment read (shared contract): needs, coverage, band, trend at $now. With no
     * stored state yet (or fulfillment off) every need reads neutral (coverage 0, band 0) and
     * 'known' is false. Pure.
     *
     * @return array ['needs' => axis => 0..1, 'coverage' => axis => -1..1, 'band' => -1..1,
     *                'trend' => band per game day, 'known' => bool, 'low_band' => bool]
     */
    public static function compute(array $dynamics, array $prefs, float $now): array
    {
        $cfg = self::config();
        $state = $dynamics[self::STATE_KEY] ?? null;
        if (!self::enabled() || !is_array($state) || !is_array($state['w'] ?? null)) {
            $needs = self::needs($dynamics, $prefs, $cfg);
            return ['needs' => $needs, 'coverage' => array_map(fn() => 0.0, $needs), 'band' => 0.0, 'trend' => 0.0,
                    'known' => false, 'low_band' => false];
        }
        $needs = array_map('floatval', $state['w']);
        arsort($needs);   // heaviest first (jsonb storage does not keep key order)
        $e = self::evaluate($needs, self::levelsAt($state, max($now, floatval($state['gamets'] ?? 0)), $cfg), $cfg);
        return ['needs' => $needs, 'coverage' => $e['coverage'], 'band' => $e['band'],
                'trend' => self::trend((array) ($state['days'] ?? []), $cfg), 'known' => true,
                'low_band' => $e['band'] < floatval($cfg['low_band'])];
    }

    // =====================================================================
    // STATE
    // =====================================================================

    /**
     * Create the state on first contact, or bring its needs up to date (a need that appeared
     * starts neutral at $now, a need that went away is dropped). Stored:
     *   'v', 'since' (gamets), 'gamets' (levels as of), 'w' (axis => need weight),
     *   'lv' (axis => units at 'gamets'), 'sampled_gamets' (last day-end sampled), 'days'
     *   ([game day, band] day-end samples), 'low_since_gamets', 'contact_band', 'boundary'.
     * Returns true when it changed $dynamics.
     */
    public static function ensure(array &$dynamics, array $prefs, float $now): bool
    {
        if ($now <= 0 || !self::enabled()) return false;
        $cfg = self::config();
        $needs = self::needs($dynamics, $prefs, $cfg);
        $state = $dynamics[self::STATE_KEY] ?? null;
        if (!is_array($state) || !is_array($state['lv'] ?? null)) {
            $start = floatval($cfg['start_units']);
            $dynamics[self::STATE_KEY] = [
                'v' => self::VERSION, 'since' => $now, 'gamets' => $now,
                'w' => $needs, 'lv' => array_map(fn() => $start, $needs),
                'sampled_gamets' => floor($now / self::day()) * self::day(),
                'days' => [], 'boundary' => ['state' => 'none'],
            ];
            return true;
        }
        $stored = array_map('floatval', (array) ($state['w'] ?? []));
        if ($stored == $needs && array_keys((array) $state['lv']) == array_keys($needs)) {
            return false;
        }
        self::tick($dynamics, $now);   // day-ends before the change are sampled with the old needs
        $state = $dynamics[self::STATE_KEY];
        $levels = self::levelsAt($state, max($now, floatval($state['gamets'] ?? 0)), $cfg);
        $lv = [];
        foreach ($needs as $axis => $_) {
            $lv[$axis] = round($levels[$axis] ?? floatval($cfg['start_units']), 4);
        }
        $state['w'] = $needs;
        $state['lv'] = $lv;
        $state['gamets'] = max($now, floatval($state['gamets'] ?? 0));
        $dynamics[self::STATE_KEY] = $state;
        return true;
    }

    /**
     * Deliver $amounts (axis => units, signed) at game time $at. Only current need axes take a
     * delivery. The levels are brought to max(stored, $at) first; a delivery older than that
     * (an eval item applied late) lands already decayed. Levels stay in 0..max_units.
     *
     * @return array axis => units actually added (after decay-to-now and clamping)
     */
    public static function deliver(array &$dynamics, array $amounts, float $at): array
    {
        if ($at <= 0 || !self::enabled() || !is_array($dynamics[self::STATE_KEY]['lv'] ?? null)) return [];
        self::tick($dynamics, $at);   // day-ends before this delivery are sampled with the levels before it
        $state = $dynamics[self::STATE_KEY];
        $cfg = self::config();
        $stamp = max($at, floatval($state['gamets'] ?? 0));
        $levels = self::levelsAt($state, $stamp, $cfg);
        $lag = max(0.0, $stamp - $at) / self::day();
        $half = floatval($cfg['half_life_game_days']);
        $late = ($half > 0 && $lag > 0) ? pow(0.5, $lag / $half) : 1.0;
        $max = floatval($cfg['max_units']);
        $applied = [];
        foreach ($amounts as $axis => $units) {
            if (!array_key_exists($axis, $levels) || abs(floatval($units)) < 1e-9) continue;
            $before = $levels[$axis];
            $levels[$axis] = max(0.0, min($max, $before + floatval($units) * $late));
            $applied[$axis] = round($levels[$axis] - $before, 4);
        }
        $state['lv'] = array_map(fn($v) => round($v, 4), $levels);
        $state['gamets'] = $stamp;
        $dynamics[self::STATE_KEY] = $state;
        return $applied;
    }

    /**
     * Delivery units of one normalized eval contract item (RelationshipDynamics::
     * normalizeEvalContractItem): tag_delivery rows of its tags, plus
     * positive_interaction_delivery for a positive exchange, x (floor + (1 - floor) x
     * significance). A positive tag counts only when the exchange was not a negative one (a
     * grievance, or an affinity loss not judged positive): a gift thrown at someone is not a gift.
     */
    public static function evalItemAmounts(array $item, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $positiveOk = !empty($item['positive_interaction'])
            || (floatval($item['signals']['affinity'] ?? 0) >= 0 && empty($item['grievance']['flag']));
        $rows = [];
        foreach ((array) ($item['tags'] ?? []) as $tag) {
            $row = ((array) $cfg['tag_delivery'])[strtolower((string) $tag)] ?? null;
            if (is_array($row)) $rows[] = $row;
        }
        if (!empty($item['positive_interaction'])) {
            $rows[] = (array) $cfg['positive_interaction_delivery'];
        }
        $floor = max(0.0, min(1.0, floatval($cfg['significance_floor'])));
        $scale = $floor + (1.0 - $floor) * max(0.0, min(1.0, floatval($item['significance'] ?? 0)));
        $out = [];
        foreach ($rows as $row) {
            foreach ($row as $axis => $units) {
                $units = floatval($units);
                if ($units > 0 && !$positiveOk) continue;
                $out[$axis] = ($out[$axis] ?? 0.0) + $units * $scale;
            }
        }
        return $out;
    }

    /** Facet vector (facet => weight 0..1) as delivery units: weight x $units. */
    public static function facetAmounts(array $facets, float $units): array
    {
        $out = [];
        foreach ($facets as $facet => $w) {
            if (!in_array($facet, RelDynFacets::FACETS, true) || !is_numeric($w)) continue;
            $v = max(0.0, min(1.0, floatval($w))) * $units;
            if ($v > 0) $out[$facet] = $v;
        }
        return $out;
    }

    /** Places / things experienced together: deliver $facets x $units at $at (no-op without state). */
    public static function recordFacets(array &$dynamics, array $facets, float $units, float $at): array
    {
        return $units > 0 ? self::deliver($dynamics, self::facetAmounts($facets, $units), $at) : [];
    }

    // =====================================================================
    // TICK: day-end samples, low stretch, the mature boundary machine
    // =====================================================================

    /** Maturity (dimensions.maturity.x, 0..100) at or above mature_at. */
    public static function isMature(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return floatval($dynamics['dimensions']['maturity']['x'] ?? 50) >= floatval($cfg['mature_at']);
    }

    /** The core type a failed probation steps back to, from core's Player.type, or null (no boundary). */
    public static function stepBackTarget(array $dynamics, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $core = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $to = ((array) $cfg['step_back_types'])[$core] ?? null;
        return is_string($to) && $to !== '' ? $to : null;
    }

    /**
     * Advance the state to $now: sample the band at every game-day end since the last sample
     * (pure decay between deliveries, so a missed day is computed exactly), track the low
     * stretch, and run the mature boundary machine:
     *   none      -> pending   mature, a step-back target exists, not walking away, band below
     *                          low_band for boundary_sustain_game_days;
     *   pending   -> probation when the statement is delivered (takeFeltTexts);
     *   probation -> none      consistent_game_days consecutive day-ends at recovery_band or
     *                          better ('resolved');
     *   probation -> failed    probation_game_days passed without that ('step_back_due').
     *
     * The machine's state is stored ('pending', 'probation', 'failed'), so whoever ticks (a
     * delivery, the calendar scan, the prerequest) moves it the same way; the core write for
     * 'failed' is RelationshipDynamics::advanceFulfillment()'s. Each sampled day-end also
     * charges unfulfilled neglect (RelationshipDynamics::chargeUnfulfilledNeglect).
     *
     * @return array ['samples' => [[gamets, band], ...] day-ends sampled now,
     *                'events' => list of 'boundary_due'|'resolved'|'step_back_due'|'boundary_dropped',
     *                'changed' => bool, 'neglect' => chargeUnfulfilledNeglect() result when charged]
     */
    public static function tick(array &$dynamics, float $now): array
    {
        $out = ['samples' => [], 'events' => [], 'changed' => false];
        $state = $dynamics[self::STATE_KEY] ?? null;
        if ($now <= 0 || !self::enabled() || !is_array($state) || !is_array($state['lv'] ?? null)) return $out;
        $cfg = self::config();
        $day = self::day();

        $points = [];
        $last = floatval($state['sampled_gamets'] ?? 0);
        $next = (floor($last / $day) + 1) * $day;
        $cap = max(1, intval($cfg['max_catchup_game_days']));
        if ($next <= $now && ($now - $next) / $day >= $cap) {
            $next = (floor($now / $day) - $cap + 1) * $day;   // a long gap: only the last $cap day-ends
        }
        for ($t = $next; $t <= $now; $t += $day) {
            $points[] = [$t, true];
        }
        $points[] = [$now, false];

        $days = array_values(array_filter((array) ($state['days'] ?? []), 'is_array'));
        foreach ($points as [$t, $isDayEnd]) {
            $band = self::bandAt($state, max($t, floatval($state['gamets'] ?? 0)), $cfg);
            if ($isDayEnd) {
                $days[] = [intval(round($t / $day)) - 1, $band];   // the game day that just ended
                $out['samples'][] = [$t, $band];
                $state['sampled_gamets'] = $t;
            }
            self::boundaryStep($state, $dynamics, $t, $band, $isDayEnd, $cfg, $out['events']);
        }
        $state['days'] = array_slice($days, -max(2, 2 * intval($cfg['trend_game_days'])));
        $out['changed'] = $out['samples'] !== [] || $out['events'] !== [] || $state != $dynamics[self::STATE_KEY];
        $dynamics[self::STATE_KEY] = $state;
        // Low fulfillment while present is neglect (rulings §9): charged per sampled day-end.
        if ($out['samples'] !== []) {
            $out['neglect'] = RelationshipDynamics::chargeUnfulfilledNeglect($dynamics, $out['samples']);
        }
        return $out;
    }

    private static function boundaryStep(array &$state, array $dynamics, float $t, float $band, bool $isDayEnd, array $cfg, array &$events): void
    {
        $day = self::day();
        if ($band < floatval($cfg['low_band'])) {
            $state['low_since_gamets'] = $state['low_since_gamets'] ?? $t;
        } else {
            unset($state['low_since_gamets']);
        }
        $b = is_array($state['boundary'] ?? null) ? $state['boundary'] : ['state' => 'none'];
        $eligible = self::isMature($dynamics, $cfg) && self::stepBackTarget($dynamics, $cfg) !== null
            && ($dynamics['_walkaway_state'] ?? 'normal') === 'normal';

        switch ($b['state'] ?? 'none') {
            case 'none':
                $low = $state['low_since_gamets'] ?? null;
                if ($eligible && $low !== null && ($t - floatval($low)) / $day >= floatval($cfg['boundary_sustain_game_days'])) {
                    $b = ['state' => 'pending', 'decided_gamets' => $t];
                    $events[] = 'boundary_due';
                }
                break;
            case 'pending':
                if (!$eligible) {
                    $b = ['state' => 'none', 'dropped_gamets' => $t];
                    $events[] = 'boundary_dropped';
                }
                break;
            case 'probation':
                if ($isDayEnd && $t > floatval($b['started_gamets'] ?? 0)) {
                    $b['streak'] = $band >= floatval($cfg['recovery_band']) ? intval($b['streak'] ?? 0) + 1 : 0;
                    if ($b['streak'] >= max(1, intval($cfg['consistent_game_days']))) {
                        $b = ['state' => 'none', 'resolved_gamets' => $t, 'say' => 'resolved'];
                        unset($state['low_since_gamets']);
                        $events[] = 'resolved';
                        break;
                    }
                }
                if ($t >= floatval($b['until_gamets'] ?? INF)) {
                    $b['state'] = 'failed';
                    $b['failed_gamets'] = $t;
                    $events[] = 'step_back_due';
                }
                break;
        }
        $state['boundary'] = $b;
    }

    // =====================================================================
    // FELT TEXT (feelings for the LLM, never numbers)
    // =====================================================================

    /** The $n least covered needs (weight x shortfall), as the NPC would name them. */
    public static function unmetPhrases(array $state, float $now, int $n = 2, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $e = self::evaluate((array) ($state['w'] ?? []), self::levelsAt($state, max($now, floatval($state['gamets'] ?? 0)), $cfg), $cfg);
        $gap = [];
        foreach ($e['coverage'] as $axis => $c) {
            if ($c < 0) $gap[$axis] = floatval($state['w'][$axis] ?? 0) * (1.0 - $c);
        }
        arsort($gap);
        $phrases = (array) $cfg['need_phrases'];
        return array_map(fn($axis) => (string) ($phrases[$axis] ?? str_replace('_', ' ', $axis)), array_slice(array_keys($gap), 0, $n));
    }

    private static function joinPhrases(array $phrases): string
    {
        if (count($phrases) <= 1) return (string) ($phrases[0] ?? 'more from them');
        $last = array_pop($phrases);
        return implode(', ', $phrases) . ' and ' . $last;
    }

    private static function fill(string $text, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $text);
    }

    /**
     * This turn's felt lines for the context hook, consuming the one-shot ones: the boundary
     * statement (starts the probation window at $now), the resolved relief, the step-back
     * decision; else the probation watchfulness while it lasts, else the unmet-needs line while
     * the band is low. Returns ['texts' => kind => text (boundary|resolved|step_back|probation|unmet),
     * 'changed' => bool].
     */
    public static function takeFeltTexts(array &$dynamics, string $npcName, string $playerName, float $now): array
    {
        $out = ['texts' => [], 'changed' => false];
        $state = $dynamics[self::STATE_KEY] ?? null;
        if (!self::enabled() || !is_array($state) || !is_array($state['lv'] ?? null) || $now <= 0) return $out;
        $cfg = self::config();
        $felt = (array) $cfg['felt_text'];
        $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerName,
                 '{NEEDS}' => self::joinPhrases(self::unmetPhrases($state, $now, 2, $cfg))];
        $b = is_array($state['boundary'] ?? null) ? $state['boundary'] : ['state' => 'none'];

        if (($b['state'] ?? 'none') === 'pending') {
            $out['texts']['boundary'] = self::fill((string) $felt['boundary'], $vars);
            $state['boundary'] = ['state' => 'probation', 'decided_gamets' => $b['decided_gamets'] ?? $now,
                'started_gamets' => $now, 'until_gamets' => $now + floatval($cfg['probation_game_days']) * self::day(), 'streak' => 0];
            $out['changed'] = true;
            RelationshipDynamics::log("[FULFILL] {$npcName}: boundary stated, probation " . $cfg['probation_game_days'] . " game days");
        } elseif (isset($b['say'])) {
            $key = (string) $b['say'];
            $types = (array) $cfg['type_phrases'];
            $vars['{FROM}'] = (string) ($types[$b['from'] ?? ''] ?? 'what they had');
            $vars['{TO}'] = (string) ($types[$b['to'] ?? ''] ?? 'something more distant');
            if (isset($felt[$key])) $out['texts'][$key] = self::fill((string) $felt[$key], $vars);
            unset($b['say']);
            $state['boundary'] = $b;
            $out['changed'] = true;
        } elseif (($b['state'] ?? 'none') === 'probation') {
            $out['texts']['probation'] = self::fill((string) $felt['probation'], $vars);
        } elseif (RelationshipDynamics::neglectBond($dynamics) !== null
            && self::bandAt($state, max($now, floatval($state['gamets'] ?? 0)), $cfg) < floatval($cfg['low_band'])) {
            $out['texts']['unmet'] = self::fill((string) $felt['unmet'], $vars);
        }
        if ($out['changed']) $dynamics[self::STATE_KEY] = $state;
        return $out;
    }

    // =====================================================================
    // SPIDER GRAPH (read API for the P5 UI; numbers are fine here, never for the LLM)
    // =====================================================================

    /**
     * JSON-ready spider graph: one axis per need (axis, kind, label, need weight, coverage),
     * band, trend and the boundary state. Pure.
     */
    public static function graph(string $npcName, array $dynamics, array $prefs, float $now): array
    {
        $cfg = self::config();
        $f = self::compute($dynamics, $prefs, $now);
        $phrases = (array) $cfg['need_phrases'];
        $axes = [];
        foreach ($f['needs'] as $axis => $w) {
            $axes[] = [
                'axis' => (string) $axis,
                'kind' => self::axisKind((string) $axis),
                'label' => (string) ($phrases[$axis] ?? str_replace('_', ' ', (string) $axis)),
                'need' => $w,
                'coverage' => $f['coverage'][$axis] ?? 0.0,
            ];
        }
        $state = $dynamics[self::STATE_KEY] ?? [];
        $b = is_array($state['boundary'] ?? null) ? $state['boundary'] : ['state' => 'none'];
        $boundary = ['state' => (string) ($b['state'] ?? 'none')];
        if (isset($b['until_gamets'])) {
            $boundary['game_days_left'] = round(max(0.0, floatval($b['until_gamets']) - $now) / self::day(), 2);
            $boundary['streak_game_days'] = intval($b['streak'] ?? 0);
        }
        return [
            'npc' => $npcName, 'known' => $f['known'], 'gamets' => $now,
            'band' => $f['band'], 'trend' => $f['trend'], 'low' => $f['low_band'],
            'axes' => $axes, 'boundary' => $boundary,
        ];
    }
}
