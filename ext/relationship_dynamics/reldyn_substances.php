<?php
/**
 * Relationship Dynamics — the NPC's own drinking and addiction (roadmap drunk-state, addiction).
 *
 * Sources: dimension design draft "Item -> Dimension Modifiers" (consumables, the Skooma Addict
 * Cycle, the Drunken One-Night Stand, Casual/Drunk/Regret Intimacy), audit 2026-03-29 Step 18
 * (tolerance, craving, withdrawal, a maturity ceiling while addicted), decisions 2026-09-23 §3
 * (feelings for the LLM, numbers for Jev).
 *
 * Signals: core's own eventlog lines of HER consuming (RelationshipDynamics::detectItemEvents:
 * the Consume action's infoaction "<NPC> consumes <item>." and her itemfound drank / ate line),
 * classified by CONSUMABLE_EFFECTS, reach processConsumable, which calls onConsume. No MinAI. The
 * player's own drinking is not hers: she sees it only through protective concern (RelDynConcern,
 * the eval's exposure 'vice').
 *
 * DRUNK STATE (stacking drinks, a temporary state):
 *   Her drinks this session (units per drink, drunk.units) are metabolized at
 *   drunk.cleared_per_game_hour on the game calendar (five drinks clear in about six game hours:
 *   "6 hours pass. Alcohol effects expire."). The level L (drinks in her now) through the alcohol
 *   tolerance she brought to the night is the effective level e = L x (1 - tolerance x
 *   addiction.tolerance_cut).
 *   - Maturity drops by the design's steps per drink (65 -> 64 -> 61 -> 53 -> 43 -> 33 over five
 *     ales: drunk.maturity_drop_steps), read piecewise-linearly at e. It is a HELD temporary
 *     offset on maturity x (heldTemporarySources): the physics, the drift samples and her "own
 *     maturity" readers see her without it, everything that reads maturity x sees her drunk (the
 *     tier floors off below 40, degraded text, plasticity), and it is taken back exactly.
 *   - From drunk.uniform_from drinks her social sensitivity shifts toward Uniform ("everyone's my
 *     packmate tonight"), fully at drunk.uniform_full (sensitivityShift).
 *   - From drunk.disinhibited_from drinks: openness +openness_bonus, every attraction floor lowered
 *     by attraction_floor_drop pillar points, and the Ick threshold raised (her sober maturity x
 *     ick_threshold_mult: the drink cannot tell desperation) (shifts, ickShift).
 *   - While any drink is in her the diary waits for the sober self (RelDynDiary::intoxicated).
 *   - Gains an eval item scores toward the player while she is at or past drunk.ledger_from
 *     drinks go to the night's ledger. When the session ends (level back to 0) a romantic night
 *     is marked as a diary moment; her first sober diary entry corrects it (soberReflection):
 *     the share of the night her sober self does not endorse (1 - E) is regretted:
 *       each ledger gain g:  -(sober.regret_mult x (1 - E) x g) through applyDelta
 *       resentment_self:     +(1 - E) x lerp(resentment_self_min, _max, her own maturity / 100)
 *     E = 1 for a night with nothing romantic in it, for a player she is attracted to soberly
 *     (the Attraction Matrix's summary, or a romance core already holds); otherwise her sober
 *     passion curve (0..1, the uphill's multiplier below her floors). A shallow diary (her own
 *     maturity at or below diary shallow_at) corrects nothing: no meaningful self-reflection.
 *
 * ADDICTION (per substance: skooma, sap, alcohol; addiction.substances maps consumables):
 *   - Each use raises dependence and tolerance (per substance); abstinence lowers them per game
 *     day. Tolerance cuts the next high (the spike of processConsumable, and the drunk level).
 *     The permanent maturity bleed of the table stays whatever the tolerance.
 *   - Dependent (dependence >= addicted_at): craving builds with the game hours since the last
 *     use; past withdrawal_onset_game_hours, withdrawal is a held comfort offset
 *     (withdrawal_comfort x dependence) until the next use, taken back exactly.
 *   - Harm reduction: a substitute (a healing potion) while dependent eases craving and
 *     withdrawal by substitute_relief for substitute_relief_game_hours, and it is no use: the
 *     abstinence clock keeps running, so dependence keeps falling.
 *   - Maturity ceiling while addicted: maturity gains stop at maturity_ceiling (her own maturity,
 *     without held offsets), except an intervention: an eval item while she is addicted whose
 *     summary names her substance and carries a caring or confronting tag. Its maturity gain
 *     passes the ceiling and it adds intervention.maturity (the design's outside pressure).
 *   - The 'I need to stop' goal (RelDynGoals type 'recovery'): formed while addicted when her own
 *     maturity is above goal_generation_min; at self_governance_min it becomes 'Stay clean'
 *     (phase clean, self-governing). Clean game days since the last use are its progress; clean
 *     for clean_game_days with dependence under clean_below: achieved (her maturity baseline +
 *     clean_success_baseline). A use while 'clean' is a relapse: back to 'stop', progress 0,
 *     resentment_self + relapse_shame.
 *
 * The LLM gets feelings (the drunk stage, craving, withdrawal, the goal's line); Jev gets numbers.
 * Units: gamets = raw game-calendar gamets; drinks = drink units; dependence / tolerance 0..1;
 * craving 0..100 craving points; dimension points 0..100 (affinity in core points -100..100).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynSubstances
{
    const KEY = '_substances';
    const STAGES = ['tipsy', 'merry', 'drunk'];
    /** Drink units a stage line is read with (a sip: two drinks a game second apart are two drinks). */
    const SIP = 1e-3;

    /** An intervention eval item is being applied: its maturity gain passes the addiction ceiling. */
    private static bool $intervention = false;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'substances' (a stored section replaces that section's keys). */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'drunk' => [
                // Drink units per consumable (CONSUMABLE_EFFECTS key): one ale / mead / wine = 1
                'units' => ['ale' => 1.0, 'generic_drink' => 1.0],
                // Dimension draft (Drunken One-Night Stand): maturity points lost with each drink,
                // 65 -> 64 -> 61 -> 53 -> 43 -> 33; past the list each drink adds the last step
                'maturity_drop_steps' => [1.0, 3.0, 8.0, 10.0, 10.0],
                // Drink units cleared per game hour: five ales clear in about six game hours (RelDyn's
                // reading of "6 hours pass. Alcohol effects expire"; one ale lasts 1.2 game hours)
                'cleared_per_game_hour' => 5.0 / 6.0,
                // Effective drinks: from ale #1 tipsy; #3 social sensitivity toward Uniform (full at
                // uniform_full); #4 disinhibited (openness, attraction floors, the Ick)
                'tipsy_from' => 1.0,
                'uniform_from' => 3.0,
                'uniform_full' => 5.0,
                'disinhibited_from' => 4.0,
                'openness_bonus' => 0.2,            // openness units 0..1 (MDD 1.4 scale)
                'attraction_floor_drop' => 15.0,    // pillar points 0..100 off every passion floor
                'ick_threshold_mult' => 1.3,        // x her sober Ick threshold (RelDyn's pick: MDD 1.4's high-openness Ick mult)
                // Eval gains toward the player at or past this many effective drinks are the drunk
                // self's (the night's ledger); RelDyn's pick: the 'merry' stage
                'ledger_from' => 3.0,
                'felt_text' => [
                    'tipsy' => '{NAME} has had a drink or two and is loosening up: quicker to laugh, a little louder than usual',
                    'merry' => '{NAME} is merry with drink: laughing easily, treating everyone around like old friends',
                    'drunk' => "{NAME} is drunk: guards down, saying and doing what {NAME} would normally keep to {NAME}'s self",
                ],
                'salience' => ['tipsy' => 0.4, 'merry' => 0.6, 'drunk' => 0.8],
            ],
            'sober' => [
                // Ledger signals (eval contract signals) and the romantic markers of a night
                'signals' => ['affinity', 'trust', 'comfort', 'respect', 'passion'],
                'romantic_tags' => ['touch', 'intimacy'],
                'romantic_intent_min' => 2,
                // Dimension draft: the drunk +5 affinity comes back as -10 at the sober diary, the
                // +10 comfort as -20: x2 of the gain her sober self does not endorse
                'regret_mult' => 2.0,
                // Dimension draft: resentment_self "+5 to +15 depending on maturity" (Aela, 65: +12)
                'resentment_self_min' => 5.0,
                'resentment_self_max' => 15.0,
                'nights_kept' => 5,
            ],
            'addiction' => [
                // Consumable (CONSUMABLE_EFFECTS key) => substance; substitutes are harm reduction
                'substances' => ['skooma' => 'skooma', 'sleeping_tree_sap' => 'sap', 'ale' => 'alcohol', 'generic_drink' => 'alcohol'],
                'substitutes' => ['healing_potion'],
                // Per use (0..1 each), per substance (RelDyn's picks: skooma's five uses over five
                // days of the Addict Cycle reach dependence; a drink now and then never does)
                'dependence_per_use' => ['skooma' => 0.12, 'sap' => 0.08, 'alcohol' => 0.03],
                'tolerance_per_use' => ['skooma' => 0.10, 'sap' => 0.08, 'alcohol' => 0.04],
                // Abstinence, per game day (0..1 per day)
                'dependence_decay_per_game_day' => 0.02,
                'tolerance_decay_per_game_day' => 0.05,
                // At tolerance 1 the high (spike, drunk level) is (1 - tolerance_cut) of the table's
                'tolerance_cut' => 0.6,
                'addicted_at' => 0.5,
                // Craving (0..100) = 100 x dependence x min(1, hours since use / craving_full_game_hours),
                // felt from craving_felt_min, for a dependence of at least craving_from
                'craving_from' => 0.25,
                'craving_full_game_hours' => 24.0,
                'craving_felt_min' => 30.0,
                'withdrawal_onset_game_hours' => 12.0,
                'withdrawal_comfort' => -20.0,          // comfort points x dependence, held
                'substitute_relief' => 0.5,             // share of craving / withdrawal eased
                'substitute_relief_game_hours' => 6.0,
                // Dimension draft: "threshold for 'I need to stop' (maturity 20+)", "self-governance
                // threshold (25+)"; the ceiling (audit Step 18) at the self-governance line
                'goal_generation_min' => 20.0,
                'self_governance_min' => 25.0,
                'maturity_ceiling' => 25.0,
                'goal_priority' => ['stop' => 0.6, 'clean' => 0.7],
                'clean_game_days' => 10,
                'clean_below' => 0.1,
                'clean_success_baseline' => 2.0,        // maturity baseline points on success
                'relapse_shame' => 5.0,                 // resentment_self points on a relapse
                'intervention' => [
                    'tags' => ['help', 'criticism', 'reassurance'],
                    'maturity' => 1.0,                  // maturity points (the draft's outside pressure +1)
                    'words' => [
                        'skooma' => ['skooma', 'moon sugar', 'sugar'],
                        'sap' => ['sap', 'sleeping tree'],
                        'alcohol' => ['drink', 'drinking', 'drunk', 'ale', 'mead', 'wine', 'bottle'],
                    ],
                ],
                // The substance in felt text
                'word' => ['skooma' => 'skooma', 'sap' => 'the sap', 'alcohol' => 'drink'],
                'felt_text' => [
                    'craving' => '{NAME} is craving {SUBSTANCE}: restless, distracted, thoughts circling back to it',
                    'craving_governed' => '{NAME} is craving {SUBSTANCE} and holding the line against it, jaw set, keeping busy',
                    'withdrawal' => '{NAME} is sick from going without {SUBSTANCE}: shaky, short-tempered, everything grates',
                    'eased' => 'the edge of {NAME}\'s craving for {SUBSTANCE} is dulled for now',
                ],
                'salience' => ['craving' => 0.6, 'withdrawal' => 0.85, 'eased' => 0.4],
            ],
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('substances');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['drunk', 'sober', 'addiction'] as $section) {
            $cfg[$section] = array_replace($defaults[$section], is_array($stored[$section] ?? null) ? $stored[$section] : []);
        }
        return $cfg;
    }

    public static function enabled(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return !empty($cfg['enabled']) && !empty(RelationshipDynamics::configValue('dimension_engine_enabled'));
    }

    private static function hour(): float
    {
        return RelationshipDynamics::GAMETS_PER_DAY / 24.0;
    }

    public static function state(array $dynamics): array
    {
        return is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
    }

    // =====================================================================
    // PURE: the drunk level, the maturity drop, the stage
    // =====================================================================

    /**
     * Drink units in her at $t: each drink ([g => gamets, u => units]) adds its units, the level
     * falls by $rate units per game hour in between, never below 0. Drinks after $t are not yet.
     */
    public static function levelAt(array $drinks, float $t, float $rate): float
    {
        usort($drinks, fn($a, $b) => floatval($a['g'] ?? 0) <=> floatval($b['g'] ?? 0));
        $level = 0.0;
        $prev = null;
        foreach ($drinks as $d) {
            $g = floatval($d['g'] ?? 0);
            if ($g > $t) break;
            if ($prev !== null) $level = max(0.0, $level - $rate * ($g - $prev) / self::hour());
            $level += max(0.0, floatval($d['u'] ?? 0));
            $prev = $g;
        }
        if ($prev === null) return 0.0;
        return max(0.0, $level - $rate * ($t - $prev) / self::hour());
    }

    /** Maturity points lost at $effective drinks: the steps summed, read linearly between whole drinks. */
    public static function maturityDrop(float $effective, array $steps): float
    {
        $steps = array_values(array_map('floatval', $steps));
        if ($effective <= 0.0 || $steps === []) return 0.0;
        $whole = (int) floor($effective);
        $drop = 0.0;
        for ($i = 0; $i < $whole; $i++) {
            $drop += $steps[min($i, count($steps) - 1)];
        }
        return $drop + ($effective - $whole) * $steps[min($whole, count($steps) - 1)];
    }

    /** null | tipsy | merry | drunk at $effective drinks. */
    public static function stageOf(float $effective, ?array $drunk = null): ?string
    {
        $drunk = $drunk ?? self::config()['drunk'];
        $e = $effective + self::SIP;
        if ($e >= floatval($drunk['disinhibited_from'])) return 'drunk';
        if ($e >= floatval($drunk['uniform_from'])) return 'merry';
        if ($e >= floatval($drunk['tipsy_from'])) return 'tipsy';
        return null;
    }

    /** Her tolerance of $substance now (0..1, as of the last tick). */
    public static function tolerance(array $dynamics, string $substance): float
    {
        return max(0.0, min(1.0, floatval(self::state($dynamics)['use'][$substance]['tolerance'] ?? 0.0)));
    }

    public static function dependence(array $dynamics, string $substance): float
    {
        return max(0.0, min(1.0, floatval(self::state($dynamics)['use'][$substance]['dependence'] ?? 0.0)));
    }

    /** The substance she depends on most, with its dependence, or null. */
    public static function strongest(array $dynamics): ?array
    {
        $best = null;
        foreach ((array) (self::state($dynamics)['use'] ?? []) as $s => $u) {
            $dep = floatval(is_array($u) ? ($u['dependence'] ?? 0) : 0);
            if ($dep > 0.0 && ($best === null || $dep > $best['dependence'])) $best = ['substance' => (string) $s, 'dependence' => $dep];
        }
        return $best;
    }

    public static function addicted(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        $s = self::strongest($dynamics);
        return $s !== null && $s['dependence'] >= floatval($cfg['addiction']['addicted_at']);
    }

    /** Alcohol is still in her (as of the last update): the diary waits for the sober self. */
    public static function intoxicated(array $dynamics): bool
    {
        return !empty(self::state($dynamics)['drinks']);
    }

    // =====================================================================
    // THE STATE'S READERS (attraction, social sensitivity, the Ick, the addiction ceiling)
    // =====================================================================

    /**
     * The drunk shifts on her judgment as of the last update, or null sober: uniform_blend (0..1
     * toward Uniform), openness_bonus (0..1 units), floor_drop (pillar points), ick_mult.
     */
    public static function shifts(array $dynamics, ?array $cfg = null): ?array
    {
        $s = self::state($dynamics);
        $e = floatval($s['effective'] ?? 0.0);
        if ($e <= 0.0 || empty($s['drinks'])) return null;
        $cfg = $cfg ?? self::config();
        if (!self::enabled($cfg)) return null;
        $d = (array) $cfg['drunk'];
        $from = floatval($d['uniform_from']);
        $full = max($from, floatval($d['uniform_full']));
        $es = $e + self::SIP;
        $blend = $es < $from ? 0.0 : ($full <= $from ? 1.0 : min(1.0, ($e - $from + 1.0) / ($full - $from + 1.0)));
        if ($es >= $full) $blend = 1.0;
        $dis = $es >= floatval($d['disinhibited_from']);
        return [
            'effective' => $e,
            'uniform_blend' => $blend,
            'openness_bonus' => $dis ? floatval($d['openness_bonus']) : 0.0,
            'floor_drop' => $dis ? floatval($d['attraction_floor_drop']) : 0.0,
            'ick_mult' => $dis ? floatval($d['ick_threshold_mult']) : 1.0,
        ];
    }

    /** A social sensitivity factor (0..1) shifted toward Uniform (1.0) by the drink. */
    public static function sensitivityShift(array $dynamics, float $factor): float
    {
        $sh = self::shifts($dynamics);
        if ($sh === null || $sh['uniform_blend'] <= 0.0) return $factor;
        return $factor + (1.0 - $factor) * $sh['uniform_blend'];
    }

    /**
     * The Ick while drunk (the drink cannot tell desperation): ['maturity' => her own sober
     * maturity (the drunk maturity would lower the threshold, the opposite of the design),
     * 'mult' => ick_threshold_mult once disinhibited, else 1]; null sober.
     */
    public static function ickShift(array $dynamics): ?array
    {
        $sh = self::shifts($dynamics);
        if ($sh === null) return null;
        return ['maturity' => RelationshipDynamics::driftSampleValue($dynamics, 'maturity') ?? 50.0, 'mult' => $sh['ick_mult']];
    }

    /**
     * The maturity ceiling (maturity points) while she is addicted, or null (not addicted, the
     * feature off, or an intervention being applied).
     */
    public static function maturityCeiling(array $dynamics): ?float
    {
        if (self::$intervention) return null;
        $cfg = self::config();
        if (!self::enabled($cfg) || !self::addicted($dynamics, $cfg)) return null;
        $c = $cfg['addiction']['maturity_ceiling'] ?? null;
        return is_numeric($c) ? floatval($c) : null;
    }

    // =====================================================================
    // CONSUMPTION (processConsumable)
    // =====================================================================

    /**
     * She consumed $key (a CONSUMABLE_EFFECTS key) at $now: the tolerance before this use scales
     * the high ('spike_mult' for the table's immediate effects); the use raises dependence and
     * tolerance; a drink joins the session; a substitute while dependent brings relief; a use in
     * the 'clean' phase is a relapse. The held offsets are re-held at once (update).
     * Returns ['spike_mult' => 0..1, 'substance' => ?string, 'drinks' => level after].
     */
    public static function onConsume(string $npcName, array &$dynamics, string $key, float $now): array
    {
        $out = ['spike_mult' => 1.0, 'substance' => null, 'drinks' => 0.0];
        $cfg = self::config();
        if (!self::enabled($cfg)) return $out;
        if ($now <= 0) {
            RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: consumed {$key} with no game clock; not counted");
            return $out;
        }
        $a = (array) $cfg['addiction'];
        // Her state brought to now first: a session that already ended closes before a new drink
        self::update($npcName, $dynamics, $now);
        $state = self::state($dynamics);
        $priorAlcoholTolerance = round(floatval($state['use']['alcohol']['tolerance'] ?? 0.0), 4);
        $substance = ((array) $a['substances'])[$key] ?? null;
        if (is_string($substance) && $substance !== '') {
            $out['substance'] = $substance;
            $u = is_array($state['use'][$substance] ?? null) ? $state['use'][$substance] : [];
            $tol = max(0.0, min(1.0, floatval($u['tolerance'] ?? 0.0)));
            $out['spike_mult'] = round(1.0 - $tol * max(0.0, min(1.0, floatval($a['tolerance_cut']))), 4);
            $u['dependence'] = round(min(1.0, floatval($u['dependence'] ?? 0.0) + floatval(((array) $a['dependence_per_use'])[$substance] ?? 0.0)), 4);
            $u['tolerance'] = round(min(1.0, $tol + floatval(((array) $a['tolerance_per_use'])[$substance] ?? 0.0)), 4);
            $u['uses'] = intval($u['uses'] ?? 0) + 1;
            $u['last_use'] = $now;
            $state['use'][$substance] = $u;
            unset($state['relief_until']);   // using again, not easing off
            if (($state['recovering'] ?? null) === $substance) {
                self::relapse($npcName, $dynamics, $substance, $now, $a);
            }
            RelationshipDynamics::log(sprintf('[RelDyn-SUBSTANCE] %s: %s (%s) dependence %.3f tolerance %.3f, high x%.2f',
                $npcName, $key, $substance, $u['dependence'], $u['tolerance'], $out['spike_mult']));
        } elseif (in_array($key, (array) $a['substitutes'], true)) {
            $s = self::strongest(['_substances' => $state]);
            if ($s !== null && $s['dependence'] >= floatval($a['craving_from'])) {
                $state['relief_until'] = $now + floatval($a['substitute_relief_game_hours']) * self::hour();
                RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: {$key} instead of {$s['substance']}: the craving eases for a while");
            }
        }
        $units = floatval(((array) $cfg['drunk']['units'])[$key] ?? 0.0);
        if ($units > 0.0) {
            $drinks = is_array($state['drinks'] ?? null) ? $state['drinks'] : [];
            if ($drinks === []) {
                // a night is drunk at the tolerance she brings to it (this night's drinks harden the next)
                $state['session_start'] = $now;
                $state['session_tolerance'] = $priorAlcoholTolerance;
            }
            $drinks[] = ['g' => $now, 'u' => $units];
            $state['drinks'] = $drinks;
        }
        $dynamics[self::KEY] = $state;
        $after = self::update($npcName, $dynamics, $now);
        $out['drinks'] = $after['drinks'];
        return $out;
    }

    /** Abstinence since the last tick: dependence and tolerance fall per game day (linear, floored at 0). */
    private static function tickUse(array &$state, float $now, array $a): void
    {
        $last = floatval($state['last_tick'] ?? 0.0);
        if ($last > 0.0 && $now > $last) {
            $days = ($now - $last) / RelationshipDynamics::GAMETS_PER_DAY;
            foreach ((array) ($state['use'] ?? []) as $s => $u) {
                if (!is_array($u)) continue;
                $u['dependence'] = round(max(0.0, floatval($u['dependence'] ?? 0) - floatval($a['dependence_decay_per_game_day']) * $days), 4);
                $u['tolerance'] = round(max(0.0, floatval($u['tolerance'] ?? 0) - floatval($a['tolerance_decay_per_game_day']) * $days), 4);
                $state['use'][$s] = $u;
            }
        }
        if ($now > $last) $state['last_tick'] = $now;
    }

    // =====================================================================
    // THE TICK (prerequest, and after every consumption)
    // =====================================================================

    /**
     * Bring her state to $now: abstinence, the drunk level and stage, craving and withdrawal; the
     * held offsets (drunk maturity, withdrawal comfort) taken back exactly and held again at their
     * new size; a session that ended closes its night (a romantic one marks a diary moment); the
     * recovery goal. Off: whatever is held is taken back. Returns ['drinks', 'effective', 'stage',
     * 'held', 'craving', 'withdrawal'].
     */
    public static function update(string $npcName, array &$dynamics, float $now): array
    {
        $cfg = self::config();
        $state = self::state($dynamics);
        $out = ['drinks' => 0.0, 'effective' => 0.0, 'stage' => null, 'held' => [], 'craving' => 0.0, 'withdrawal' => false];
        $on = self::enabled($cfg);
        if (!$on || $now <= 0) {
            if (!$on && !empty($state['held'])) {
                RelationshipDynamics::reverseAppliedDeltas($dynamics, (array) $state['held'], 'RelDyn-SUBSTANCE', 'substance offsets (off)');
                $state['held'] = [];
                unset($state['drinks'], $state['effective'], $state['stage']);
                $dynamics[self::KEY] = $state;
            }
            return $out;
        }
        $d = (array) $cfg['drunk'];
        $a = (array) $cfg['addiction'];
        self::tickUse($state, $now, $a);

        // --- The drink ---
        $drinks = is_array($state['drinks'] ?? null) ? $state['drinks'] : [];
        $level = $drinks === [] ? 0.0 : self::levelAt($drinks, $now, floatval($d['cleared_per_game_hour']));
        $tol = max(0.0, min(1.0, floatval($state['session_tolerance'] ?? 0.0)));
        $effective = $level * (1.0 - $tol * max(0.0, min(1.0, floatval($a['tolerance_cut']))));
        if ($drinks !== [] && $level <= 1e-9) {
            self::closeNight($npcName, $dynamics, $state, $now, $cfg);
            $drinks = [];
            $effective = 0.0;
            unset($state['drinks'], $state['session_start'], $state['session_tolerance']);
        }
        $stage = self::stageOf($effective, $d);
        $state['effective'] = round($effective, 4);
        $state['level'] = round($level, 4);
        $state['stage'] = $stage;
        if ($drinks === []) unset($state['drinks']);

        // --- Craving and withdrawal (the substance she depends on most) ---
        $strong = self::strongest(['_substances' => $state]);
        $craving = 0.0;
        $withdrawal = 0.0;
        $relief = floatval($state['relief_until'] ?? 0.0) > $now;
        if ($strong !== null && $strong['dependence'] >= floatval($a['craving_from'])) {
            $lastUse = floatval($state['use'][$strong['substance']]['last_use'] ?? $now);
            $hours = max(0.0, ($now - $lastUse) / self::hour());
            $craving = 100.0 * $strong['dependence'] * min(1.0, $hours / max(1e-6, floatval($a['craving_full_game_hours'])));
            if ($strong['dependence'] >= floatval($a['addicted_at']) && $hours >= floatval($a['withdrawal_onset_game_hours'])) {
                $withdrawal = floatval($a['withdrawal_comfort']) * $strong['dependence'];
            }
            if ($relief) {
                $craving *= 1.0 - floatval($a['substitute_relief']);
                $withdrawal *= 1.0 - floatval($a['substitute_relief']);
            }
        }
        $state['craving'] = round($craving, 2);
        $state['withdrawal'] = $withdrawal < 0.0;
        if (!$relief) unset($state['relief_until']);

        // --- The held offsets, taken back exactly and held again ---
        $targets = [];
        $drop = self::maturityDrop($effective, (array) $d['maturity_drop_steps']);
        if ($drop > 0.0) $targets['maturity'] = -$drop;
        if ($withdrawal < 0.0) $targets['comfort'] = $withdrawal;
        $held = (array) ($state['held'] ?? []);
        // Re-held only when the target moved (a clamp at the range edge holds less than its target)
        if (!self::sameTargets((array) ($state['targets'] ?? []), $targets) || ($targets === [] && $held !== [])) {
            if ($held !== []) {
                RelationshipDynamics::reverseAppliedDeltas($dynamics, $held, '', '');
                $state['held'] = [];
                $dynamics[self::KEY] = $state;   // taken back: no longer held while the new ones go on
            }
            $applied = [];
            foreach ($targets as $dim => $v) {
                $def = RelationshipDynamics::getDimensionDefinition($dim);
                if (!$def || !isset($dynamics['dimensions'][$dim]) || !is_numeric($dynamics['dimensions'][$dim]['x'] ?? null)) continue;
                $x = floatval($dynamics['dimensions'][$dim]['x']);
                $nx = max((float) $def['range_min'], min((float) $def['range_max'], $x + $v));
                $dynamics['dimensions'][$dim]['x'] = round($nx, 4);
                if (abs($nx - $x) > 1e-9) $applied[$dim] = round($nx - $x, 4);
            }
            $state['held'] = $applied;
            $state['targets'] = array_map(fn($v) => round($v, 4), $targets);
            if ($applied !== [] || $held !== []) {
                RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: held " . json_encode($held) . ' -> ' . json_encode($applied)
                    . sprintf(' (drinks %.2f, effective %.2f%s)', $level, $effective, $withdrawal < 0 ? ', withdrawal' : ''));
            }
        }
        if (empty($state['held'])) unset($state['held'], $state['targets']);
        $dynamics[self::KEY] = $state;

        self::recoveryGoal($npcName, $dynamics, $now, $a);

        $out['drinks'] = $level;
        $out['effective'] = $effective;
        $out['stage'] = $stage;
        $out['held'] = (array) ($dynamics[self::KEY]['held'] ?? []);
        $out['craving'] = $craving;
        $out['withdrawal'] = $withdrawal < 0.0;
        return $out;
    }

    private static function sameTargets(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        foreach ($b as $dim => $v) {
            if (!isset($a[$dim]) || abs(floatval($a[$dim]) - floatval($v)) > 1e-4) return false;
        }
        return true;
    }

    // =====================================================================
    // THE NIGHT'S LEDGER AND THE SOBER CORRECTION
    // =====================================================================

    /**
     * An eval item is about to be applied: while she is addicted, a caring or confronting exchange
     * that names her substance is an intervention; its maturity passes the ceiling and adds the
     * outside pressure (intervention.maturity). Returns true for an intervention.
     */
    public static function beforeEvalItem(string $npcName, array $n, array &$dynamics, float $gamets): bool
    {
        self::$intervention = false;
        $cfg = self::config();
        if (!self::enabled($cfg) || !self::addicted($dynamics, $cfg)) return false;
        $iv = (array) $cfg['addiction']['intervention'];
        if (array_intersect((array) ($iv['tags'] ?? []), (array) ($n['tags'] ?? [])) === []) return false;
        $s = self::strongest($dynamics)['substance'];
        $summary = strtolower((string) ($n['summary'] ?? ''));
        $named = false;
        foreach ((array) (((array) ($iv['words'] ?? []))[$s] ?? []) as $w) {
            $w = strtolower(trim((string) $w));
            if ($w !== '' && preg_match('/\b' . preg_quote($w, '/') . '\b/u', $summary)) { $named = true; break; }
        }
        if (!$named) return false;
        self::$intervention = true;
        $state = self::state($dynamics);
        $state['interventions'] = intval($state['interventions'] ?? 0) + 1;
        $state['last_intervention'] = $gamets;
        $dynamics[self::KEY] = $state;
        $m = floatval($iv['maturity'] ?? 0.0);
        $applied = $m > 0.0 ? RelationshipDynamics::applyDelta('maturity', $dynamics, $m, $dynamics['inferred_temperament'] ?? null) : 0.0;
        RelationshipDynamics::log(sprintf('[RelDyn-SUBSTANCE] %s: an intervention about %s (maturity %+.3f, past the ceiling)', $npcName, $s, $applied));
        return true;
    }

    /**
     * After an eval item's signals ($totals: signal => change of x, affinity in mirror units): the
     * intervention ends; gains toward the player made at or past ledger_from drinks join the
     * night's ledger (affinity in core points), with the night's romantic markers.
     */
    public static function afterEvalItem(string $npcName, array $n, array $totals, array &$dynamics, float $gamets): void
    {
        self::$intervention = false;
        $cfg = self::config();
        if (!self::enabled($cfg)) return;
        $state = self::state($dynamics);
        $d = (array) $cfg['drunk'];
        $sober = (array) $cfg['sober'];
        $nights = is_array($state['nights'] ?? null) ? $state['nights'] : [];
        $key = null;
        $drinks = [];
        $tol = 0.0;
        if (!empty($state['drinks']) && floatval($state['session_start'] ?? INF) <= $gamets) {
            $key = (string) (int) $state['session_start'];
            $drinks = (array) $state['drinks'];
            $tol = floatval($state['session_tolerance'] ?? 0.0);
        } else {
            foreach ($nights as $k => $night) {
                if (is_array($night) && floatval($night['start']) <= $gamets && $gamets <= floatval($night['end'] ?? INF)) {
                    $key = (string) $k;
                    $drinks = (array) ($night['drinks'] ?? []);
                    $tol = floatval($night['tolerance'] ?? 0.0);
                    break;
                }
            }
        }
        if ($key === null) return;
        $tol = max(0.0, min(1.0, $tol));
        $cut = max(0.0, min(1.0, floatval($cfg['addiction']['tolerance_cut'])));
        $effective = self::levelAt($drinks, $gamets, floatval($d['cleared_per_game_hour'])) * (1.0 - $tol * $cut);
        if ($effective < floatval($d['ledger_from'])) return;
        $night = is_array($nights[$key] ?? null) ? $nights[$key] : ['start' => floatval($key), 'gains' => [], 'romantic' => false];
        $gains = [];
        foreach ((array) $sober['signals'] as $signal) {
            $v = floatval($totals[$signal] ?? 0.0) * ($signal === 'affinity' ? 2.0 : 1.0);   // affinity: core points
            if ($v > 1e-6) {
                $night['gains'][$signal] = round(floatval($night['gains'][$signal] ?? 0.0) + $v, 4);
                $gains[$signal] = round($v, 3);
            }
        }
        $romantic = floatval($totals['passion'] ?? 0.0) > 1e-6
            || array_intersect((array) $sober['romantic_tags'], (array) ($n['tags'] ?? [])) !== []
            || intval($n['romantic_intent'] ?? 0) >= intval($sober['romantic_intent_min']);
        $night['romantic'] = !empty($night['romantic']) || $romantic;
        if ($gains === [] && !$romantic) return;
        $nights[$key] = $night;
        $state['nights'] = array_slice($nights, -max(1, intval($sober['nights_kept'])), null, true);
        $dynamics[self::KEY] = $state;
        RelationshipDynamics::log(sprintf('[RelDyn-SUBSTANCE] %s: the drunk self (%.2f drinks) gained %s%s', $npcName, $effective,
            json_encode($gains), $romantic ? ' (romantic)' : ''));
    }

    /** The session ended: its night (if it has a ledger) closes; a romantic night waits for the diary as a moment. */
    private static function closeNight(string $npcName, array &$dynamics, array &$state, float $now, array $cfg): void
    {
        $start = floatval($state['session_start'] ?? 0.0);
        $key = (string) (int) $start;
        $rate = floatval($cfg['drunk']['cleared_per_game_hour']);
        // The end: when the last drink cleared (never later than now)
        $drinks = (array) ($state['drinks'] ?? []);
        $last = 0.0;
        foreach ($drinks as $dr) $last = max($last, floatval($dr['g'] ?? 0));
        $end = min($now, $last + ($rate > 0 ? self::levelAt($drinks, $last, $rate) / $rate * self::hour() : 0.0));
        if (is_array($state['nights'][$key] ?? null)) {
            $state['nights'][$key]['end'] = $end;
            $state['nights'][$key]['drinks'] = $drinks;
            $state['nights'][$key]['tolerance'] = floatval($state['session_tolerance'] ?? 0.0);
            $state['nights'][$key]['closed'] = true;
            if (!empty($state['nights'][$key]['romantic'])) {
                RelDynDiary::keepMoments($dynamics, ['drunk_night'], $end);
            }
        }
        RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: sober again (a session of " . count($drinks) . ' drink(s))');
    }

    /**
     * Her first sober diary entry ($depth: RelDynDiary::depth of her own maturity): every closed
     * night of the ledger is judged by her sober self and the share she does not endorse is
     * regretted (see the file comment). A shallow diary corrects nothing. The nights are spent.
     * Returns night key => ['endorsed' => E, 'asked' => dimension => raw points (before the physics),
     * 'applied' => dimension => points (affinity in core points)].
     */
    public static function soberReflection(string $npcName, array &$dynamics, string $depth): array
    {
        $cfg = self::config();
        $state = self::state($dynamics);
        $nights = is_array($state['nights'] ?? null) ? $state['nights'] : [];
        $closed = array_filter($nights, fn($n) => is_array($n) && !empty($n['closed']));
        if ($closed === [] || !self::enabled($cfg)) return [];
        $out = [];
        $sober = (array) $cfg['sober'];
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        $own = RelationshipDynamics::driftSampleValue($dynamics, 'maturity') ?? 50.0;
        foreach ($closed as $key => $night) {
            unset($nights[$key]);
            if ($depth === 'shallow') {
                RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: the night of {$key} is not looked at (a shallow diary)");
                $out[$key] = ['endorsed' => null, 'asked' => [], 'applied' => []];
                continue;
            }
            $E = self::endorsement($dynamics, $night);
            $applied = [];
            $asked = [];
            if ($E < 1.0) {
                $share = 1.0 - $E;
                foreach ((array) ($night['gains'] ?? []) as $signal => $gain) {
                    $v = -floatval($sober['regret_mult']) * $share * floatval($gain);
                    if (abs($v) < 1e-6) continue;
                    $asked[(string) $signal] = round($v, 4);
                    $a = RelationshipDynamics::applyDelta((string) $signal, $dynamics, $v, $temperament);
                    $applied[(string) $signal] = round($signal === 'affinity' ? $a * 2.0 : $a, 4);
                }
                $lo = floatval($sober['resentment_self_min']);
                $hi = floatval($sober['resentment_self_max']);
                $rs = $share * ($lo + ($hi - $lo) * max(0.0, min(1.0, $own / 100.0)));
                if ($rs > 1e-6) {
                    $asked['resentment_self'] = round($rs, 4);
                    $applied['resentment_self'] = RelationshipDynamics::applyDelta('resentment_self', $dynamics, $rs, $temperament);
                }
            }
            $out[$key] = ['endorsed' => round($E, 4), 'asked' => $asked, 'applied' => $applied];
            RelationshipDynamics::log(sprintf('[RelDyn-SUBSTANCE] %s: sober, the night of %s (endorsed %.2f): %s', $npcName, $key, $E, json_encode($applied)));
        }
        $state['nights'] = $nights;
        if ($nights === []) unset($state['nights']);
        $dynamics[self::KEY] = $state;
        return $out;
    }

    /**
     * How much of a night her sober self stands behind (0..1): all of a night with nothing
     * romantic in it, of a player she is drawn to soberly (attracted / won over, the Matrix off)
     * or already in a romance with; otherwise her sober passion curve.
     */
    public static function endorsement(array $dynamics, array $night): float
    {
        if (empty($night['romantic'])) return 1.0;
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        if (empty($att['enabled']) || !empty($att['attracted']) || !empty($att['won_over'])) return 1.0;
        if (in_array((string) ($dynamics['_core_rel_type'] ?? ''), (array) (RelDynFelt::config()['romantic_types'] ?? []), true)) return 1.0;
        if (!empty($att['hard_zero'])) return 0.0;
        $curve = $att['passion']['curve'] ?? $att['passion_mult'] ?? 1.0;
        return max(0.0, min(1.0, floatval($curve)));
    }

    // =====================================================================
    // RECOVERY GOAL (RelDynGoals type 'recovery')
    // =====================================================================

    private static function recoveryGoal(string $npcName, array &$dynamics, float $now, array $a): void
    {
        $state = self::state($dynamics);
        $strong = self::strongest($dynamics);
        $own = RelationshipDynamics::driftSampleValue($dynamics, 'maturity') ?? 50.0;
        $goal = null;
        foreach (RelDynGoals::active($dynamics) as $g) {
            if (($g['type'] ?? null) === 'recovery') { $goal = $g; break; }
        }
        $addictedNow = $strong !== null && $strong['dependence'] >= floatval($a['addicted_at']);
        if ($goal === null) {
            if ($addictedNow && $own > floatval($a['goal_generation_min'])) {
                $word = (string) (((array) $a['word'])[$strong['substance']] ?? $strong['substance']);
                $id = RelDynGoals::form($dynamics, 'recovery', floatval($a['goal_priority']['stop'] ?? 0.6), 'addiction', $now,
                    ['phase' => 'stop', 'substance' => $strong['substance'], 'substance_word' => $word]);
                if ($id !== null) {
                    $state = self::state($dynamics);
                    $state['recovering'] = $strong['substance'];
                    $dynamics[self::KEY] = $state;
                    RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: 'I need to stop' ({$strong['substance']}, maturity " . round($own, 1) . ')');
                }
            }
            return;
        }
        $substance = (string) ($goal['substance'] ?? ($state['recovering'] ?? ''));
        $dep = self::dependence($dynamics, $substance);
        $lastUse = floatval($state['use'][$substance]['last_use'] ?? $now);
        $cleanDays = max(0.0, ($now - $lastUse) / RelationshipDynamics::GAMETS_PER_DAY);
        $phase = $own >= floatval($a['self_governance_min']) ? 'clean' : (string) ($goal['phase'] ?? 'stop');
        $fields = ['phase' => $phase,
            'priority' => round(floatval($a['goal_priority'][$phase] ?? $goal['priority'] ?? 0.6), 4),
            'progress' => round(min(1.0, $cleanDays / max(1, intval($a['clean_game_days']))), 4)];
        if ($phase !== ($goal['phase'] ?? 'stop')) {
            RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: 'Stay clean' (maturity " . round($own, 1) . ')');
        }
        RelDynGoals::setFields($dynamics, 'recovery', $fields);
        if ($cleanDays >= intval($a['clean_game_days']) && $dep < floatval($a['clean_below'])) {
            RelDynGoals::endGoal($dynamics, 'recovery', 'achieved', $now);
            $base = floatval($dynamics['dimensions']['maturity']['baseline'] ?? $own);
            $dynamics['dimensions']['maturity']['baseline'] = min(100.0, $base + floatval($a['clean_success_baseline']));
            $state = self::state($dynamics);
            unset($state['recovering']);
            $dynamics[self::KEY] = $state;
            RelationshipDynamics::log("[RelDyn-SUBSTANCE] {$npcName}: clean of {$substance}; maturity baseline " . round($base, 1) . ' -> '
                . round($dynamics['dimensions']['maturity']['baseline'], 1));
        }
    }

    /** A use while the goal is 'Stay clean': back to 'I need to stop', the shame of it. */
    private static function relapse(string $npcName, array &$dynamics, string $substance, float $now, array $a): void
    {
        $goal = null;
        foreach (RelDynGoals::active($dynamics) as $g) {
            if (($g['type'] ?? null) === 'recovery') { $goal = $g; break; }
        }
        if ($goal === null || ($goal['phase'] ?? 'stop') !== 'clean') return;
        RelDynGoals::setFields($dynamics, 'recovery', ['phase' => 'stop', 'progress' => 0.0, 'relapses' => intval($goal['relapses'] ?? 0) + 1]);
        $shame = RelationshipDynamics::applyDelta('resentment_self', $dynamics, floatval($a['relapse_shame']), $dynamics['inferred_temperament'] ?? null);
        RelationshipDynamics::log(sprintf('[RelDyn-SUBSTANCE] %s: relapsed (%s): resentment_self %+.2f', $npcName, $substance, $shame));
    }

    // =====================================================================
    // OUTPUT: felt lines (feelings), Jev (numbers), the save load
    // =====================================================================

    /**
     * Felt lines now: the drunk stage; craving / withdrawal (or the relief of a substitute).
     * Each ['key', 'text', 'salience']; feelings, never numbers.
     */
    public static function feltLines(string $npcName, array $dynamics, array $vars): array
    {
        $cfg = self::config();
        if (!self::enabled($cfg)) return [];
        $state = self::state($dynamics);
        $lines = [];
        $stage = $state['stage'] ?? null;
        $d = (array) $cfg['drunk'];
        if (is_string($stage) && !empty($state['drinks']) && isset($d['felt_text'][$stage])) {
            $lines[] = ['key' => 'drunk', 'text' => strtr((string) $d['felt_text'][$stage], $vars),
                'salience' => floatval($d['salience'][$stage] ?? 0.5)];
        }
        $a = (array) $cfg['addiction'];
        $strong = self::strongest($dynamics);
        if ($strong !== null && $strong['dependence'] >= floatval($a['craving_from'])) {
            $v = $vars + ['{SUBSTANCE}' => (string) (((array) $a['word'])[$strong['substance']] ?? $strong['substance'])];
            $t = (array) $a['felt_text'];
            $key = null;
            if (!empty($state['withdrawal'])) $key = 'withdrawal';
            elseif (floatval($state['craving'] ?? 0) >= floatval($a['craving_felt_min'])) {
                $key = self::governing($dynamics, $cfg) ? 'craving_governed' : 'craving';
            } elseif (isset($state['relief_until'])) $key = 'eased';
            if ($key !== null && isset($t[$key])) {
                $lines[] = ['key' => $key === 'craving_governed' ? 'craving' : $key, 'text' => strtr((string) $t[$key], $v),
                    'salience' => floatval($a['salience'][$key === 'craving_governed' ? 'craving' : $key] ?? 0.5)];
            }
        }
        return $lines;
    }

    /** Dependent and her own maturity at the self-governance line: she can hold herself back. */
    public static function governing(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        $a = (array) $cfg['addiction'];
        $s = self::strongest($dynamics);
        return $s !== null && $s['dependence'] >= floatval($a['craving_from'])
            && (RelationshipDynamics::driftSampleValue($dynamics, 'maturity') ?? 50.0) >= floatval($a['self_governance_min']);
    }

    /** Jev's substances block (numbers), null when she has none of it. */
    public static function jev(array $dynamics, float $now): ?array
    {
        $state = self::state($dynamics);
        if ($state === [] || !self::enabled()) return null;
        $cfg = self::config();
        $a = (array) $cfg['addiction'];
        $uses = [];
        foreach ((array) ($state['use'] ?? []) as $s => $u) {
            if (!is_array($u)) continue;
            $last = floatval($u['last_use'] ?? 0);
            $uses[(string) $s] = ['dependence' => round(floatval($u['dependence'] ?? 0), 3), 'tolerance' => round(floatval($u['tolerance'] ?? 0), 3),
                'uses' => intval($u['uses'] ?? 0), 'hours_since_use' => $last > 0 && $now >= $last ? round(($now - $last) / self::hour(), 2) : null];
        }
        $strong = self::strongest($dynamics);
        return [
            'drinks' => round(floatval($state['level'] ?? 0), 3),
            'effective_drinks' => round(floatval($state['effective'] ?? 0), 3),
            'stage' => isset($state['stage']) ? (string) $state['stage'] : null,
            'held' => array_map(fn($v) => round(floatval($v), 2), (array) ($state['held'] ?? [])),
            'uses' => $uses,
            'addicted' => $strong !== null && $strong['dependence'] >= floatval($a['addicted_at']),
            'craving' => round(floatval($state['craving'] ?? 0), 2),
            'withdrawal' => !empty($state['withdrawal']),
            'relief' => isset($state['relief_until']) && floatval($state['relief_until']) > $now,
            'governing' => self::governing($dynamics, $cfg),
            'maturity_ceiling' => self::maturityCeiling($dynamics),
            'interventions' => intval($state['interventions'] ?? 0),
            'open_nights' => count((array) ($state['nights'] ?? [])),
        ];
    }

    /**
     * A save load at $T: drinks, uses and nights the loaded game never lived are gone, stamps come
     * back to $T (RelDynTimeline::rebaselineDynamics). The held offsets are re-held at the next
     * update from what is left.
     */
    public static function rebaseline(array $state, float $T): array
    {
        if (is_array($state['drinks'] ?? null)) {
            $state['drinks'] = array_values(array_filter($state['drinks'], fn($d) => !(is_array($d) && floatval($d['g'] ?? 0) > $T)));
            if ($state['drinks'] === []) unset($state['drinks'], $state['session_start']);
        }
        if (is_array($state['nights'] ?? null)) {
            $state['nights'] = array_filter($state['nights'], fn($n) => !(is_array($n) && floatval($n['start'] ?? 0) > $T));
            foreach ($state['nights'] as $k => $n) {
                if (is_numeric($n['end'] ?? null) && floatval($n['end']) > $T) unset($state['nights'][$k]['end'], $state['nights'][$k]['closed']);
            }
        }
        foreach ((array) ($state['use'] ?? []) as $s => $u) {
            if (is_array($u) && floatval($u['last_use'] ?? 0) > $T) $state['use'][$s]['last_use'] = $T;
        }
        foreach (['last_tick', 'relief_until', 'last_intervention'] as $k) {
            if (is_numeric($state[$k] ?? null) && floatval($state[$k]) > $T) $state[$k] = $T;
        }
        return $state;
    }
}
