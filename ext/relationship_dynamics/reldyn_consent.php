<?php
/**
 * Relationship Dynamics — consent: whether intimacy happens at all.
 *
 * Ken, 2026-10-01 (decisions §21): "Sharmat handles the 'during'; RelDyn decides whether it gets there.
 * RelDyn's consent decision supersedes Sharmat's. When RelDyn says no, Sharmat refuses." Direction, not a
 * spec: so this is one decision API, dynamic and scaled by who the NPC is, with no permanent gate and no
 * one immune to a feeling.
 *
 *   RelDynConsent::decide($npcName, $dynamics)  ->  allow, or refuse with reasons
 *
 * THE DECISION. Three layers, from who the NPC is to how they feel today:
 *
 *   CLOSED (the answer is no, whatever the mood). The NPC's own identity and the bond's standing: an
 *     asexual NPC, an aromantic one (and "not interested"), a demisexual one before the bond, a bond-gated
 *     one before the bond (the attraction preference filter: intimacy_allowed false), a friendzoned NPC
 *     (they care, and not that way), one who has walked away. These are states, not permanent verdicts:
 *     a preference is changed in the editor, a friendzone ends when the attraction climbs, a walkaway
 *     ends with its boundary test. They are not appeased: nobody is argued out of who they are.
 *
 *   WILLINGNESS (what the NPC wants, 0..1, less what weighs on it). The want is the closeness of the bond
 *     (passion, core affinity, how far they have let the player in, the romance, whether they are drawn),
 *     weighted by the NPC's intimacy gate (a visceral one by passion, a bond one by the bond). What
 *     weighs on it, each by who they are (traits, maturity), soft-or'ed so nothing is a switch and
 *     nothing is zero: an open conflict (reactive and proud NPCs most), the ick, being withdrawn (resentment),
 *     pulling back (the mood: an immature NPC is driven by it, a mature one little), not being let in
 *     enough (a guarded NPC needs more), and fear (fear of losing the player, or fear of them: fear is
 *     not wanting). The bar it must clear is the NPC's own (guarded: higher; visceral: lower; a mature
 *     one deliberates), held against flicker (hysteresis on the last answer).
 *
 *   APPEASEMENT (an NPC who says yes when they should not). Short of the bar, a person with little
 *     confidence and maturity, anxious attachment, a lot of fear of losing the player, no pride, and
 *     warmth that gives in, may comply anyway: the larger the shortfall, the more it takes. This is a model
 *     of the person, not a verdict on the player: nothing here reads how the player treats them.
 *
 * STANCES: willing | hesitant (yes, unsure) | appeasing (yes, unwilling) | declines (no) | closed (no).
 * allow is true for the first three. The reasons are codes (conflict, ick, withdrawn, resentful, pulled_back,
 * not_let_in, fear; for closed: asexual, aromantic, not_interested, not_bonded_yet, intimacy_not_allowed,
 * friendzoned, walked_away), strongest first. A felt line (feelings, the NPC's name, no pronoun, no
 * digits) says it in words.
 *
 * PUBLISHED to core_npc_master.plugin_extended_data.reldyn.consent (publish(): only when it changed;
 * 'gamets' stamps when) for Sharmat, which reads it and defers (its aiagentNsfwRelDynConsentDecision, a
 * local hook that does nothing without RelDyn). The older reldyn.romance state keeps its blunt
 * consent_block flag for what already reads it; this key is the decision that supersedes it. RelDyn never
 * writes Sharmat's store.
 *
 * State: $dynamics['_consent'] = ['v', 'allow', 'stance', 'gamets'] (the last answer, for the hysteresis).
 * Units: want / willingness / bar / appeasement / intensities 0..1 (unitless); dimension points 0..100;
 * core affinity -100..100; passion points 0..100; game time raw gamets. No wall clock.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynConsent
{
    const KEY = '_consent';
    const STORAGE_KEY = 'consent';
    const VERSION = 1;

    const STANCES = ['willing', 'hesitant', 'appeasing', 'declines', 'closed'];
    /** The stances that say yes. */
    const ALLOWING = ['willing', 'hesitant', 'appeasing'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'consent' (nested tables merge per entry). Serene's starting values for Ken's §21
     * ruling (the MDD gives none); tune after playtest.
     */
    public static function configDefaults(): array
    {
        return [
            // Off: RelDyn publishes no decision and Sharmat keeps its own consent exactly as before.
            'enabled' => true,

            // --- the want: how much the bond wants this, 0..1, a weighted mean of five readings. The weights
            // follow the NPC's intimacy gate (RelDynAttraction::gateOf): visceral = physical first, bond =
            // commitment first. ---
            'want' => [
                // each reading between its from and full
                'passion' => ['from' => 10.0, 'full' => 70.0],    // effective passion points
                'bond'    => ['from' => 10.0, 'full' => 76.0],    // core affinity points
                'let_in'  => ['from' => 25.0, 'full' => 70.0],    // sqrt(comfort x trust) points
                // the romance reading by core Player.type (anything else 'other')
                'romance' => ['romantic' => 1.0, 'crush' => 0.6, 'admirer' => 0.6, 'obsessed' => 0.6, 'ex' => 0.4, 'other' => 0.0],
                // drawn to the player (the attraction passes); not drawn reads this much
                'not_drawn' => 0.3,
                'weights' => [
                    'balanced' => ['passion' => 0.30, 'bond' => 0.20, 'let_in' => 0.15, 'romance' => 0.20, 'drawn' => 0.15],
                    'visceral' => ['passion' => 0.50, 'bond' => 0.10, 'let_in' => 0.05, 'romance' => 0.15, 'drawn' => 0.20],
                    'bond'     => ['passion' => 0.15, 'bond' => 0.30, 'let_in' => 0.25, 'romance' => 0.30, 'drawn' => 0.00],
                ],
            ],

            // --- what weighs on the want. Each factor: intensity 0..1 (its state), 'base' (how hard it weighs on
            // an NPC with no leanings), 'traits' => trait code => slope on (trait - 0.5): the multiplier is
            // clamp(1 + sum slope x (trait - 0.5), mult_min, mult_max); 'mood' => true takes the multiplier
            // from the NPC's maturity instead (RelDynPullback::moodGain). Trait codes: G guard, E expressiveness,
            // C confidence, Pd pride, Rs resilience, L reactivity, W warmth, D restraint, Po possessiveness,
            // Pr protectiveness. The effect is base x multiplier x intensity, soft-or'ed over the factors, so
            // none is a switch and none is ever nothing. ---
            'factors' => [
                'conflict'    => ['base' => 0.70, 'traits' => ['L' => 0.7, 'Pd' => 0.5, 'Rs' => -0.4]],
                'ick'         => ['base' => 0.90, 'traits' => ['G' => 0.3]],
                'withdrawn'   => ['base' => 0.85, 'traits' => ['Pd' => 0.5, 'W' => -0.4, 'L' => 0.3]],
                'pulled_back' => ['base' => 0.60, 'mood' => true],
                'not_let_in'  => ['base' => 0.60, 'traits' => ['G' => 1.0]],
                'fear'        => ['base' => 0.45, 'traits' => ['C' => -0.6]],
            ],
            'mult_min' => 0.4, 'mult_max' => 1.6,
            // Resentment (points) where it starts to weigh and where it weighs in full; at or above the
            // withdrawal line (RelationshipDynamics::RESENTMENT_WITHDRAWAL_AT) the reason is 'withdrawn', below it 'resentful'.
            'resentment' => ['from' => 45.0, 'full' => 90.0],
            // Not let in: the let-in (points) an NPC wants for intimacy = need x (1 + guard_shift x (G - 0.5)).
            'let_in_need' => ['need' => 55.0, 'guard_shift' => 0.8],
            // Core Player.types that are fear of the player (weigh as full fear), with the fear of losing them (keeping)
            'fear_core_types' => ['fearful'],
            // a factor at or above this (its effect) is named in the reasons
            'reason_min' => 0.12,

            // --- the bar the willingness must clear: base + guard x (G - 0.5) + the gate + maturity x (m - 50) / 50 ---
            'bar' => [
                'base' => 0.42, 'guard' => 0.25, 'maturity' => 0.06,
                'gate' => ['balanced' => 0.0, 'visceral' => -0.10, 'bond' => 0.12],
                'min' => 0.20, 'max' => 0.80,
                // willing from bar + willing_margin; between is hesitant (yes, unsure)
                'willing_margin' => 0.12,
                // held against flicker: after a yes the bar is lower by this, after a no higher
                'hysteresis' => 0.03,
            ],

            // --- appeasement: who says yes though they should not. weights over readings 0..1 (normalised);
            // short of the bar by s, it takes appeasement >= yield_bar + yield_slope x s. The people-pleaser
            // of the autonomy lane (low self-confidence and low maturity) adds people_pleaser_boost. ---
            'appeasement' => [
                'enabled' => true,
                'weights' => ['low_confidence' => 0.30, 'low_maturity' => 0.25, 'anxious' => 0.20, 'fear' => 0.15, 'low_pride' => 0.10, 'warmth' => 0.10],
                // attachment corner => how much it gives in (blended at the NPC's axes)
                'attachment' => ['secure' => 0.0, 'avoidant' => 0.1, 'anxious' => 1.0, 'toxic' => 0.9],
                'people_pleaser_boost' => 0.15,
                'yield_bar' => 0.50, 'yield_slope' => 1.6,
            ],

            // --- felt text (feelings, never numbers). {NAME} the NPC, {PLAYER} the player. No pronoun for the
            // NPC (they are every gender): the name only. Key: stance, then the strongest reason, then 'default'. ---
            'felt_text' => [
                'closed' => [
                    'asexual'    => "Sex is not something {NAME} wants, with {PLAYER} or with anyone. That is who {NAME} is, and the closeness {NAME} gives is of another kind.",
                    'aromantic'  => "{NAME} does not want romance, nor the intimacy that comes with it. A no here is nothing against {PLAYER}.",
                    'not_interested' => "{NAME} is not interested in {PLAYER} that way and does not want to be pushed toward it.",
                    'not_bonded_yet' => "{NAME} needs far more closeness and time with {PLAYER} before intimacy is even a question.",
                    'intimacy_not_allowed' => "{NAME} is not open to intimacy with {PLAYER}, and that is not about to change tonight.",
                    'friendzoned' => "{NAME} cares for {PLAYER} as a friend, and not in the way that would make this right. The answer is no.",
                    'walked_away' => "{NAME} has walked away from {PLAYER} and is not open to any closeness at all right now.",
                    'default'    => "{NAME} is not open to intimacy with {PLAYER}. The answer is no.",
                ],
                'declines' => [
                    'conflict'    => "{NAME} and {PLAYER} are in the middle of a quarrel, and wanting {PLAYER} close is the last thing on {NAME}'s mind. The answer is no, for now.",
                    'ick'         => "Something about {PLAYER} has turned {NAME} off, and the thought of intimacy is unwelcome.",
                    'withdrawn'   => "{NAME} has pulled away inside, hurt and wary, and will not be intimate with {PLAYER} like this.",
                    'resentful'   => "Old hurt sits between {NAME} and {PLAYER}, and intimacy now would feel false.",
                    'pulled_back' => "{NAME} is closed off at the moment, in a mood to be left alone, not to be touched.",
                    'not_let_in'  => "{NAME} has not let {PLAYER} in far enough for this. It is too soon, and the answer is not yet.",
                    'fear'        => "{NAME} is afraid, and fear is not wanting. The answer is no.",
                    'default'     => "{NAME} does not want this, here and now.",
                ],
                'hesitant' => [
                    'default' => "{NAME} is torn: open to this, and unsure. Gentleness and patience from {PLAYER} could tip it either way; rushing would not.",
                ],
                'appeasing' => [
                    'fear'    => "{NAME} is afraid of upsetting {PLAYER}, or of losing {PLAYER}, and says yes out of that fear and not out of want. There is little of {NAME} in it.",
                    'default' => "{NAME} does not really want this and is going along with it anyway, to keep {PLAYER} happy and to avoid a fight or being left. There is little of {NAME} in it.",
                ],
            ],
        ];
    }

    private const MERGED_TABLES = ['want', 'factors', 'resentment', 'let_in_need', 'bar', 'appeasement'];

    /** The consent settings: stored config per setting, its nested tables merged per entry ('felt_text' per stance and reason). */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('consent');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (self::MERGED_TABLES as $t) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
        }
        foreach (['want' => ['passion', 'bond', 'let_in', 'romance', 'weights'], 'bar' => ['gate'], 'appeasement' => ['weights', 'attachment']] as $t => $subs) {
            foreach ($subs as $s) {
                $cfg[$t][$s] = array_replace((array) $defaults[$t][$s], is_array($stored[$t][$s] ?? null) ? $stored[$t][$s] : []);
            }
        }
        foreach (['balanced', 'visceral', 'bond'] as $g) {
            $cfg['want']['weights'][$g] = array_replace((array) $defaults['want']['weights'][$g], is_array($stored['want']['weights'][$g] ?? null) ? $stored['want']['weights'][$g] : []);
        }
        foreach (array_keys((array) $defaults['factors']) as $f) {
            $cfg['factors'][$f] = array_replace((array) $defaults['factors'][$f], is_array($stored['factors'][$f] ?? null) ? $stored['factors'][$f] : []);
        }
        $cfg['felt_text'] = $defaults['felt_text'];
        foreach ((array) ($stored['felt_text'] ?? []) as $stance => $row) {
            if (is_array($row) && isset($cfg['felt_text'][$stance])) $cfg['felt_text'][$stance] = array_replace($cfg['felt_text'][$stance], $row);
        }
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /** $v between $from (0) and $full (1). */
    private static function between(float $v, float $from, float $full): float
    {
        return $full > $from ? self::clamp01(($v - $from) / ($full - $from)) : ($v >= $full ? 1.0 : 0.0);
    }

    private static function dim(array $dynamics, string $dimension, float $default = 50.0): float
    {
        return is_numeric($dynamics['dimensions'][$dimension]['x'] ?? null) ? floatval($dynamics['dimensions'][$dimension]['x']) : $default;
    }

    // =====================================================================
    // CLOSED: who the NPC is, and where the bond stands (pure)
    // =====================================================================

    /**
     * The reasons the answer is no whatever the mood, strongest first: the attraction preference filter
     * (asexual, aromantic, not interested, demisexual or a bond-gated NPC before the bond), a friendzone, a
     * walkaway. [] when none. The preference reads the attraction summary the prerequest stored
     * ($dynamics['_attraction']): with the settings page's type filter off there is none, as everywhere.
     */
    public static function closedReasons(array $dynamics): array
    {
        $out = [];
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $friendzoned = !empty($dynamics['_attraction_friendzoned']);
        $pref = isset($att['preference']) && is_string($att['preference']) ? $att['preference'] : null;
        $hasSummary = !empty($att['enabled']) || $pref !== null;
        // the NPC's own preference holds whether or not the Matrix judges the player (RelDynRomance::buildState)
        if ($hasSummary && array_key_exists('intimacy_allowed', $att) && !$att['intimacy_allowed']) {
            if ($pref === 'asexual') $out[] = 'asexual';
            elseif ($pref === 'aromantic') $out[] = 'aromantic';
            elseif ($pref === 'not_interested') $out[] = 'not_interested';
            elseif ($pref === 'demisexual') $out[] = 'not_bonded_yet';
            elseif (!$friendzoned) $out[] = 'intimacy_not_allowed';
        }
        if ($friendzoned) $out[] = 'friendzoned';
        $walk = $dynamics['_walkaway_state'] ?? 'normal';
        if (is_string($walk) && $walk !== '' && $walk !== 'normal') $out[] = 'walked_away';
        return $out;
    }

    // =====================================================================
    // THE WANT (pure)
    // =====================================================================

    /** The five readings (0..1) the want weighs: passion, bond, let_in, romance, drawn. */
    public static function readings(array $dynamics, ?array $cfg = null): array
    {
        $w = (array) (($cfg ?? self::config())['want']);
        $type = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? 'neutral')));
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $drawn = $att === [] || empty($att['enabled']) ? 1.0 : (!empty($att['passes']) || !empty($att['attracted']) ? 1.0 : floatval($w['not_drawn']));
        $romance = (array) $w['romance'];
        return [
            'passion' => self::between(RelationshipDynamics::getEffectivePassion($dynamics), floatval($w['passion']['from']), floatval($w['passion']['full'])),
            'bond'    => self::between(RelationshipDynamics::getCoreAffinity($dynamics), floatval($w['bond']['from']), floatval($w['bond']['full'])),
            'let_in'  => self::between(RelDynPullback::letIn($dynamics), floatval($w['let_in']['from']), floatval($w['let_in']['full'])),
            'romance' => self::clamp01(floatval($romance[$type] ?? ($romance['other'] ?? 0.0))),
            'drawn'   => $drawn,
        ];
    }

    /** What the bond wants this (0..1): the readings weighted by the NPC's intimacy gate (balanced | visceral | bond). */
    public static function want(array $readings, string $gate, ?array $cfg = null): float
    {
        $weights = (array) ((array) (($cfg ?? self::config())['want']['weights']))[in_array($gate, ['visceral', 'bond'], true) ? $gate : 'balanced'];
        $sum = 0.0;
        $total = 0.0;
        foreach ($readings as $k => $v) {
            $wt = max(0.0, floatval($weights[$k] ?? 0.0));
            $sum += $wt * $v;
            $total += $wt;
        }
        return $total > 0.0 ? self::clamp01($sum / $total) : 0.0;
    }

    // =====================================================================
    // WHAT WEIGHS ON IT (pure)
    // =====================================================================

    /**
     * The states that weigh on the want, each 0..1 (intensity): conflict, ick, withdrawn (resentment), pulled_back,
     * not_let_in (the let-in short of what this NPC wants), fear (of losing the player, or of them).
     * Also 'resentful' => bool: the withdrawn factor is below the withdrawal line (a named hurt, not a withdrawal).
     */
    public static function states(array $dynamics, array $traits, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $res = self::dim($dynamics, 'resentment', 0.0);
        $r = (array) $cfg['resentment'];
        $need = (array) $cfg['let_in_need'];
        $needPts = max(1.0, floatval($need['need']) * (1.0 + floatval($need['guard_shift']) * (floatval($traits['G'] ?? 0.5) - 0.5)));
        $letIn = RelDynPullback::letIn($dynamics);
        $type = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $fear = RelDynKeeping::fear($dynamics);
        if (in_array($type, array_map('strtolower', (array) $cfg['fear_core_types']), true)) $fear = 1.0;
        return [
            'conflict'    => !empty($dynamics['in_conflict']) ? 1.0 : 0.0,
            'ick'         => !empty($dynamics['_ick_tracker']['ick_active']) ? 1.0 : 0.0,
            'withdrawn'   => self::between($res, floatval($r['from']), floatval($r['full'])),
            'pulled_back' => RelDynPullback::active($dynamics) ? max(0.5, self::clamp01(RelDynPullback::pressure($dynamics))) : 0.0,
            'not_let_in'  => self::clamp01(($needPts - $letIn) / $needPts),
            'fear'        => self::clamp01($fear),
            'resentful'   => $res < RelationshipDynamics::RESENTMENT_WITHDRAWAL_AT,
        ];
    }

    /** How hard one factor weighs on this NPC (the multiplier on its base), from who they are: traits, or maturity. */
    public static function multiplier(string $factor, array $traits, array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $row = (array) (((array) $cfg['factors'])[$factor] ?? []);
        $lo = floatval($cfg['mult_min']);
        $hi = max($lo, floatval($cfg['mult_max']));
        if (!empty($row['mood'])) {
            $w = RelDynConcern::expression($dynamics, $traits)['w'];
            return max($lo, min($hi, RelDynPullback::moodGain(floatval($w))));
        }
        $m = 1.0;
        foreach ((array) ($row['traits'] ?? []) as $code => $slope) {
            $m += floatval($slope) * (floatval($traits[$code] ?? 0.5) - 0.5);
        }
        return max($lo, min($hi, $m));
    }

    /**
     * The effect of each factor on this NPC's willingness (0..1) and their soft-or ('weight'): base x multiplier x
     * intensity, so no factor is ever a switch and a stronger state always weighs more.
     *
     * @return array{effects: array<string, float>, weight: float}
     */
    public static function weight(array $states, array $traits, array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $effects = [];
        $keep = 1.0;
        foreach ((array) $cfg['factors'] as $factor => $row) {
            $e = self::clamp01(floatval($row['base'] ?? 0.0) * self::multiplier((string) $factor, $traits, $dynamics, $cfg) * floatval($states[$factor] ?? 0.0));
            $effects[$factor] = round($e, 4);
            $keep *= 1.0 - $e;
        }
        return ['effects' => $effects, 'weight' => round(1.0 - $keep, 4)];
    }

    // =====================================================================
    // THE BAR AND THE APPEASEMENT (pure)
    // =====================================================================

    /** The willingness this NPC needs for a yes: its own bar, held against flicker after a yes (lower) or a no (higher). */
    public static function bar(array $dynamics, array $traits, string $gate, ?bool $previousAllow = null, ?array $cfg = null): float
    {
        $b = (array) (($cfg ?? self::config())['bar']);
        $bar = floatval($b['base'])
            + floatval($b['guard']) * (floatval($traits['G'] ?? 0.5) - 0.5)
            + floatval(((array) $b['gate'])[$gate] ?? 0.0)
            + floatval($b['maturity']) * (self::dim($dynamics, 'maturity') - 50.0) / 50.0;
        if ($previousAllow !== null) {
            $bar += $previousAllow ? -abs(floatval($b['hysteresis'])) : abs(floatval($b['hysteresis']));
        }
        return max(floatval($b['min']), min(floatval($b['max']), $bar));
    }

    /**
     * How readily this NPC gives in against their own want (0..1): low confidence, low maturity, anxious attachment,
     * the fear of losing the player, low pride, and warmth that accommodates (weighted mean), and a little more for
     * the autonomy lane's people-pleaser. Who the NPC is; nothing in it reads how the player behaves.
     */
    public static function appeasement(array $dynamics, array $traits, ?array $cfg = null): float
    {
        $a = (array) (($cfg ?? self::config())['appeasement']);
        $r = [
            'low_confidence' => self::clamp01(1.0 - self::dim($dynamics, 'self_confidence') / 100.0),
            'low_maturity'   => self::clamp01(1.0 - self::dim($dynamics, 'maturity') / 100.0),
            'anxious'        => self::clamp01(RelationshipDynamics::attachmentBlend($dynamics, (array) $a['attachment'], 0.0)),
            'fear'           => self::clamp01(RelDynKeeping::fear($dynamics)),
            'low_pride'      => self::clamp01(1.0 - floatval($traits['Pd'] ?? 0.5)),
            'warmth'         => self::clamp01(floatval($traits['W'] ?? 0.5)),
        ];
        $sum = 0.0;
        $total = 0.0;
        foreach ($r as $k => $v) {
            $wt = max(0.0, floatval(((array) $a['weights'])[$k] ?? 0.0));
            $sum += $wt * $v;
            $total += $wt;
        }
        $v = $total > 0.0 ? $sum / $total : 0.0;
        if (RelationshipDynamics::isPeoplePleaser($dynamics)) $v += floatval($a['people_pleaser_boost']);
        return round(self::clamp01($v), 4);
    }

    // =====================================================================
    // THE DECISION
    // =====================================================================

    /**
     * The NPC's answer to intimacy with the player, now. Pure on the state ($dynamics) and the config.
     *
     * @return array{v: int, allow: bool, stance: string, closed: bool, reasons: list<string>, willingness: float, bar: float,
     *               want: float, weight: float, appeasing: bool, appeasement: float, gate: string, effects: array, felt: ?string}
     */
    public static function decide(string $npcName, array $dynamics, ?array $cfg = null, string $playerName = 'the player'): array
    {
        $cfg = $cfg ?? self::config();
        $traits = RelDynConcern::traitsOf($dynamics);
        $gate = RelDynAttraction::gateOf($npcName, $dynamics);
        $prev = is_array($dynamics[self::KEY] ?? null) && array_key_exists('allow', $dynamics[self::KEY]) ? (bool) $dynamics[self::KEY]['allow'] : null;

        $closed = self::closedReasons($dynamics);
        $readings = self::readings($dynamics, $cfg);
        $want = self::want($readings, $gate, $cfg);
        $states = self::states($dynamics, $traits, $cfg);
        $w = self::weight($states, $traits, $dynamics, $cfg);
        $willingness = self::clamp01($want * (1.0 - $w['weight']));
        $bar = self::bar($dynamics, $traits, $gate, $prev, $cfg);
        $appeasement = self::appeasement($dynamics, $traits, $cfg);

        // the factors that weigh, strongest first; resentment under the withdrawal line is a named hurt
        $min = floatval($cfg['reason_min']);
        $soft = [];
        foreach ($w['effects'] as $factor => $e) {
            if ($e >= $min) $soft[$factor === 'withdrawn' && !empty($states['resentful']) ? 'resentful' : $factor] = $e;
        }
        arsort($soft);
        $soft = array_keys($soft);

        $out = [
            'v' => self::VERSION, 'allow' => false, 'stance' => 'declines', 'closed' => false, 'reasons' => $soft,
            'willingness' => round($willingness, 4), 'bar' => round($bar, 4), 'want' => round($want, 4), 'weight' => $w['weight'],
            'appeasing' => false, 'appeasement' => $appeasement, 'gate' => $gate, 'effects' => $w['effects'], 'felt' => null,
        ];
        if ($closed !== []) {
            $out['stance'] = 'closed';
            $out['closed'] = true;
            $out['reasons'] = $closed;
            $out['willingness'] = 0.0;
        } elseif ($willingness >= $bar + floatval(((array) $cfg['bar'])['willing_margin'])) {
            $out['stance'] = 'willing';
            $out['allow'] = true;
        } elseif ($willingness >= $bar) {
            $out['stance'] = 'hesitant';
            $out['allow'] = true;
        } elseif (!empty(((array) $cfg['appeasement'])['enabled'])
            && $appeasement >= floatval($cfg['appeasement']['yield_bar']) + floatval($cfg['appeasement']['yield_slope']) * ($bar - $willingness)) {
            $out['stance'] = 'appeasing';
            $out['allow'] = true;
            $out['appeasing'] = true;
        }
        $out['felt'] = self::feltText($npcName, $playerName, $out, $cfg);
        return $out;
    }

    /**
     * The feeling in words (no digits, the name only, no pronoun for the NPC): by stance, then the strongest reason
     * with a line of its own, then the stance's default. null for a willing NPC (nothing to say).
     */
    public static function feltText(string $npcName, string $playerName, array $decision, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $stance = (string) ($decision['stance'] ?? '');
        $table = (array) (((array) $cfg['felt_text'])[$stance] ?? []);
        if ($table === []) return null;
        $text = null;
        foreach ((array) ($decision['reasons'] ?? []) as $reason) {
            if (isset($table[$reason]) && is_string($table[$reason])) { $text = $table[$reason]; break; }
        }
        $text = $text ?? ($table['default'] ?? null);
        return is_string($text) ? str_replace(['{NAME}', '{PLAYER}'], [$npcName, $playerName], $text) : null;
    }

    // =====================================================================
    // PUBLISH (the prerequest hook)
    // =====================================================================

    /** What Sharmat reads: the decision without the working numbers it does not need. */
    public static function payload(array $decision): array
    {
        return [
            'v' => self::VERSION, 'enabled' => true,
            'allow' => (bool) $decision['allow'], 'stance' => (string) $decision['stance'], 'closed' => (bool) $decision['closed'],
            'reasons' => array_values((array) $decision['reasons']), 'appeasing' => (bool) $decision['appeasing'],
            'willingness' => $decision['willingness'], 'bar' => $decision['bar'],
            'felt' => $decision['felt'],
        ];
    }

    /**
     * Decide and publish to plugin_extended_data.reldyn.consent (only when it changed; 'gamets' stamps when), and keep the
     * last answer in $dynamics['_consent'] for the hysteresis. With the switch off it publishes {enabled: false} once
     * (Sharmat reads that as no decision) and forgets its state. Never writes Sharmat's store. Returns the decision, or
     * null when it was switched off.
     */
    public static function publish(string $npcName, array &$dynamics, string $playerName = 'the player'): ?array
    {
        $npcId = RelDynStorage::resolveNpcId($npcName);
        $cfg = self::config();
        $stored = $npcId === null ? null : (RelDynStorage::getAll($npcId)[self::STORAGE_KEY] ?? null);
        if (empty($cfg['enabled'])) {
            unset($dynamics[self::KEY]);
            if ($npcId !== null && (!is_array($stored) || !empty($stored['enabled']))) {
                RelDynStorage::setKey($npcId, self::STORAGE_KEY, ['v' => self::VERSION, 'enabled' => false, 'gamets' => RelationshipDynamics::currentGamets()]);
            }
            return null;
        }
        $decision = self::decide($npcName, $dynamics, $cfg, $playerName);
        $dynamics[self::KEY] = ['v' => self::VERSION, 'allow' => $decision['allow'], 'stance' => $decision['stance'], 'gamets' => RelationshipDynamics::currentGamets()];
        if ($npcId === null) return $decision;
        $payload = self::payload($decision);
        if (is_array($stored)) {
            $cmp = $stored;
            unset($cmp['gamets']);
            if ($cmp == $payload) return $decision;
        }
        $payload['gamets'] = RelationshipDynamics::currentGamets();
        if (!RelDynStorage::setKey($npcId, self::STORAGE_KEY, $payload)) {
            error_log("[RelDyn-CONSENT] ERROR {$npcName}: publishing the consent decision failed");
        } elseif (!is_array($stored) || ($stored['stance'] ?? null) !== $decision['stance']) {
            error_log("[RelDyn-CONSENT] {$npcName}: " . ($stored['stance'] ?? 'none') . " -> {$decision['stance']} (" . implode(',', (array) $decision['reasons']) . ')');
        }
        return $decision;
    }

    /** Jev / editor view: the numbers of the last decision (the working numbers, not what Sharmat reads). */
    public static function jev(string $npcName, array $dynamics): array
    {
        $d = self::decide($npcName, $dynamics);
        return ['enabled' => self::enabled(), 'stance' => $d['stance'], 'allow' => $d['allow'], 'reasons' => $d['reasons'],
            'willingness' => $d['willingness'], 'bar' => $d['bar'], 'want' => $d['want'], 'weight' => $d['weight'],
            'appeasement' => $d['appeasement'], 'gate' => $d['gate'], 'effects' => $d['effects']];
    }
}
