<?php
/**
 * Relationship Dynamics — absence: the bond break, and affinity rot.
 *
 * BOND BREAK (roadmap bond-break-resentment; memory project_bond_break_resentment; decisions
 * 2026-09-23 §2 "neglect is global ... feeds the bond-break resentment design"; rulings §8, §9).
 * Ken: "you don't talk to your wife for a few weeks, you better have a very independent and
 * mature wife otherwise the locks maybe changed."
 *
 * The daily absence path already exists and stays the ONE path for the slow part: global
 * neglect (RelationshipDynamics::advanceCalendar) builds raw resentment per game day past a
 * grace set by bond type x who the NPC is (getNeglectProfile: codependence, maturity, pride),
 * buffered by the fulfillment band (§9), and stops at the NPC's neglect ceiling. The bond break
 * is the moment that absence turns intentional: on the return, when the absence decay of
 * affinity (processAffinityDecay) has carried the bond below its bond type's threshold, after an
 * absence longer than her neglect grace x break_after_grace_mult (short = life happens, long =
 * feels intentional, to her: the grace is who she is). Once per absence, it
 *   - adds resentment through the SAME neglect buffer and ceiling (chargeNeglectResentment):
 *       raw = base_raw x rate_mult (getNeglectProfile: 1/maturity x attachment x pride)
 *             x bond_severity[bond type] x duration_scale x trust_scale
 *       duration_scale = clamp((absent game days / duration_ref_game_days) ^ duration_exp, min, max)
 *       trust_scale    = 2 ^ (trust_log2 x (50 - trust) / 50)       (high trust: benefit of the doubt)
 *     a mature NPC (fulfillment mature_at) x mature_resentment_mult: its anger has a floor;
 *   - drops comfort (they had to cope alone) and trust (bigger for a bigger bond);
 *   - is expressed by who the NPC is:
 *       boundary  mature, a step-back target exists, no boundary running: the §9 boundary comes
 *                 due now (calm statement -> probation -> a deliberate step-back if it goes on);
 *       repair    mature otherwise: a calm "let's talk it through" instead of a grudge;
 *       withdraw  guarded (max(guard trait G, avoidance axis) >= withdraw_at, above the middle)
 *                 and not in the anxious half (anxiety axis < protest_at; Fraley & Shaver axes,
 *                 decisions §12, as they have drifted): the walls go back up, a bigger comfort
 *                 drop, no scene;
 *       confront  everyone else, anxious protest first: it spills out on the return ("where WERE
 *                 you?").
 *     Walkaway and the MDD 15.5 confrontation stay the existing thresholds on resentment: the
 *     break only feeds them. The line is said to the player's face (takeFeltLines), and a
 *     break that hurts (strain_modes) strains the bond until the first warm exchange after it
 *     (strains(): no romantic or social impulse toward him next to her hurt).
 *
 * AFFINITY ROT (roadmap affinity-rot; MDD 6.5 "prolonged low Passion or unresolved Conflict ->
 * permanent Affinity bleeds"; pipeline MDD 6.5: -1 per day after 7+ days with no positive
 * interaction, and the stage can regress below its floor). On the game calendar
 * (rotStep, from advanceCalendar), for a bond that existed (context-tier high-water mark >=
 * walkaway_affinity_min_tier), not walking away:
 *   conditions: 'conflict'    an open conflict (in_conflict), any bond that decays; it rots
 *                             through any absence (time does not heal);
 *               'low_passion' a romance gone cold (core romantic/crush): passion below
 *                             low_passion_below, or held at the tier's governor floor (as cold as
 *                             the tier lets her go); only a partner the Matrix finds attracted
 *                             (MDD 8.3: the political marriage is loveless, not rotting); paused
 *                             while a grief's acute offsets are held (her loss, not the bond); and
 *                             only up to her neglect grace after the last contact (the time apart
 *                             past it is the absence path's: decay, neglect, the bond break);
 *   clock:      a condition's rot starts grace_game_days after the later of its onset and the
 *               last positive interaction (markPositive: an eval-scored positive exchange, a
 *               local positive exchange); time alone never resets it, contact does;
 *   bleed:      core affinity points = core_points_per_game_day x rot game days x the condition's
 *               mult (the larger condition counts, never both) x M_modifiers (decisions §1: a
 *               loss relative to who the NPC is; the low-passion bleed is tagged 'neglect');
 *   floor:      the walkaway line (walkaway_affinity_at): rot can take a bond there and the
 *               existing MDD 6.5 walkaway severs it; the absence-decay baseline, the tier
 *               retention and the floor gates do not hold rot back, and the held tier label
 *               regresses with the number.
 * Rot is permanent in the MDD's sense: nothing but positive contact brings affinity back.
 *
 * Units: resentment / comfort / trust points 0..100, raw resentment points (before applyDelta's
 * physics), core affinity points -100..100, game days on the game calendar (raw gamets /
 * RelationshipDynamics::GAMETS_PER_DAY). No wall clock. Felt text is feelings, never numbers;
 * Jev gets the numbers (jev()).
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynAbsence
{
    const BREAK_KEY = '_bond_break';
    const ROT_KEY = '_affinity_rot';
    const MODES = ['boundary', 'repair', 'withdraw', 'confront'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'bond_break' (a stored config replaces settings/tables, felt_text
     * merges per entry). Serene's starting values for the memory's formula sketch (it gives the
     * shape and two anchors: a mature, independent friend gone two weeks ~2 resentment; an
     * anxious partner ghosted a week ~40 with a comfort crash and a trust hit).
     */
    public static function bondBreakDefaults(): array
    {
        return [
            'enabled' => true,
            // RelDyn bond type (getRelationshipType) => the tier (RELATIONSHIP_TIERS) whose floor
            // the bond must stay at: absence decay carrying core affinity below it breaks the bond.
            // A partner who no longer feels close; a crush or a sworn bond back to plain friendship;
            // a friend back to an acquaintance. Types not listed never break.
            'break_below_tier' => [
                'bonded' => 'close_friend', 'crush' => 'friend', 'sworn' => 'friend',
                'friend' => 'acquaintance', 'friendzone' => 'acquaintance', 'parasite' => 'acquaintance',
            ],
            // bond type => severity (unitless 0..1): what the bond obliged; scales resentment,
            // comfort and trust. A romance obliges most.
            'bond_severity' => [
                'bonded' => 1.0, 'crush' => 0.7, 'sworn' => 0.6, 'friend' => 0.3, 'friendzone' => 0.3, 'parasite' => 0.4,
            ],
            'base_raw' => 3.0,                  // raw resentment points at severity 1, rate_mult 1, a week, trust 50
            'duration_ref_game_days' => 7.0,    // game days at which duration_scale is 1
            'duration_exp' => 0.5,              // unitless: longer feels more intentional, sub-linearly
            'duration_min' => 0.25,
            'duration_max' => 3.0,
            'trust_log2' => 1.0,                // trust 100 halves it, trust 0 doubles it
            'mature_resentment_mult' => 0.25,   // mature NPCs: a floor on anger (rulings §9)
            'comfort_base' => 10.0,             // comfort points at severity 1 and codependence 0.5
            'withdraw_comfort_mult' => 1.5,     // walls back up: comfort falls further
            'trust_base' => 6.0,                // trust points at severity 1 and duration_scale 1
            'withdraw_at' => 0.55,              // guardedness (0..1) from which the walls go back up
            'protest_at' => 0.5,                // anxiety axis (0..1) from which fear of abandonment protests instead
            // Short = life happens, long = feels intentional (memory): the grace is where the daily
            // neglect starts (decisions §2), the break needs the absence past her own grace times
            // this (unitless, >= 1). Serene's starting value: intentional once the absence has run
            // as long again as she would excuse.
            'break_after_grace_mult' => 2.0,
            // Break modes whose hurt strains the bond from the break to the first positive
            // interaction after it (strains(): no romantic or social impulse toward the player,
            // the attraction says nothing, RelDynFelt). The mature modes reach out to talk.
            'strain_modes' => ['confront', 'withdraw'],
            'felt_text' => [
                'confront' => "{NAME} was left alone far too long, and now that {PLAYER} is back it spills out before they can stop it: "
                    . "where were they, did {NAME} even cross their mind? The hurt comes out as accusation, raw and close to the surface.",
                'withdraw' => "{NAME} coped alone while {PLAYER} was gone, and the walls have gone back up: polite, guarded, keeping "
                    . "{PLAYER} at arm's length. They will not say how much it hurt; that closeness has to be earned again.",
                'repair'   => "{NAME} missed {PLAYER} and was hurt by how long they stayed away, but holds no grudge over it. "
                    . "They would rather talk it through calmly and set things right than let it fester between them.",
            ],
        ];
    }

    /** Defaults for config key 'affinity_rot' (a stored config replaces settings/tables). */
    public static function rotDefaults(): array
    {
        return [
            'enabled' => true,
            'grace_game_days' => 7.0,            // pipeline MDD 6.5: 7+ days with no positive interaction
            'core_points_per_game_day' => 1.0,   // pipeline MDD 6.5: -1 affinity per day (core points)
            // condition => its weight on the bleed (unitless); the larger active one counts
            'conditions' => [
                'conflict' => ['mult' => 1.0, 'tags' => []],
                'low_passion' => ['mult' => 1.0, 'tags' => ['neglect']],
            ],
            // passion points: the spark level (decisions §13); passion at or below the tier's
            // governor floor (RelDynGovernors: a committed partner's 20) counts as cold too, it is as
            // cold as her tier lets her go
            'low_passion_below' => 20.0,
            'low_passion_core_types' => ['romantic', 'crush'],   // core Player.type: a romance
            // MDD 8.3: "low passion, high tier" (the political marriage) is a real bond: only a
            // partner the Attraction Matrix finds attracted (or won over) has a romance to go cold.
            // No Matrix summary, or the Matrix off: as before (a romance can go cold).
            'low_passion_needs_attraction' => true,
            // While a grief's acute offsets are held (reldyn_protocols.php, _grief_held) her passion is
            // low for her loss, not for the bond: the cold-romance rot pauses (an open fight still rots)
            'low_passion_grief_pause' => true,
        ];
    }

    public static function breakConfig(): array
    {
        $defaults = self::bondBreakDefaults();
        $stored = RelationshipDynamics::configValue('bond_break');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        $cfg['felt_text'] = array_replace($defaults['felt_text'], is_array($stored['felt_text'] ?? null) ? $stored['felt_text'] : []);
        return $cfg;
    }

    public static function rotConfig(): array
    {
        $defaults = self::rotDefaults();
        $stored = RelationshipDynamics::configValue('affinity_rot');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    // =====================================================================
    // BOND BREAK
    // =====================================================================

    /** Core affinity (-100..100) this bond type must stay at, or null (the type never breaks). */
    public static function breakThreshold(string $bondType, ?array $cfg = null): ?float
    {
        $cfg = $cfg ?? self::breakConfig();
        $tier = ((array) $cfg['break_below_tier'])[$bondType] ?? null;
        if (!is_string($tier) || RelationshipDynamics::tierRank($tier) < 0) return null;
        return floatval(RelationshipDynamics::getTierFloor($tier));
    }

    /**
     * How this NPC expresses a break (see the file comment). Pure.
     * @return string boundary|repair|withdraw|confront
     */
    public static function mode(array $dynamics, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::breakConfig();
        if (RelDynFulfillment::isMature($dynamics)) {
            $boundaryOpen = RelDynFulfillment::stepBackTarget($dynamics) !== null
                && ($dynamics['_walkaway_state'] ?? 'normal') === 'normal'
                && !RelDynFulfillment::boundaryActive($dynamics) && !RelDynConcern::boundaryActive($dynamics)
                && RelDynFulfillment::pairState($dynamics) !== null;
            return $boundaryOpen ? 'boundary' : 'repair';
        }
        return self::withdraws($dynamics, $cfg) ? 'withdraw' : 'confront';
    }

    /**
     * A guarded or avoidant NPC pulls back (guardedness >= withdraw_at); one in the anxious half
     * (anxiety axis >= protest_at) protests however guarded: fear of abandonment speaks first.
     */
    public static function withdraws(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::breakConfig();
        $anxiety = floatval(RelationshipDynamics::getAttachmentAxes($dynamics)['anxiety'] ?? 0.0);   // 0..1
        return self::guardedness($dynamics) >= floatval($cfg['withdraw_at']) && $anxiety < floatval($cfg['protest_at']);
    }

    /** max(guard trait G, attachment avoidance axis), 0..1: how readily the walls go back up. */
    public static function guardedness(array $dynamics): float
    {
        $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $guard = is_array($vector) ? floatval($vector['G'] ?? 0.0) : 0.0;
        $avoidance = floatval(RelationshipDynamics::getAttachmentAxes($dynamics)['avoidance'] ?? 0.0);
        return max(0.0, min(1.0, max($guard, $avoidance)));
    }

    /**
     * The break's magnitudes for an absence of $absentDays game days (pure; no state change).
     * @return array ['raw' => raw resentment points, 'comfort' => comfort points (>= 0, a drop),
     *                'trust' => trust points (>= 0, a drop), 'severity', 'duration_scale', 'trust_scale', 'rate_mult', 'mode']
     */
    public static function magnitudes(array $dynamics, string $bondType, float $absentDays, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::breakConfig();
        $severity = max(0.0, floatval(((array) $cfg['bond_severity'])[$bondType] ?? 0.0));
        $profile = RelationshipDynamics::getNeglectProfile($dynamics);
        $ref = max(0.001, floatval($cfg['duration_ref_game_days']));
        $duration = max(floatval($cfg['duration_min']), min(floatval($cfg['duration_max']),
            (max(0.0, $absentDays) / $ref) ** floatval($cfg['duration_exp'])));
        $trust = max(0.0, min(100.0, floatval($dynamics['dimensions']['trust']['x'] ?? 50.0)));   // points 0..100
        $trustScale = 2.0 ** (floatval($cfg['trust_log2']) * (50.0 - $trust) / 50.0);
        $mode = self::mode($dynamics, $cfg);
        $mature = in_array($mode, ['boundary', 'repair'], true);

        $raw = floatval($cfg['base_raw']) * $profile['rate_mult'] * $severity * $duration * $trustScale
            * ($mature ? floatval($cfg['mature_resentment_mult']) : 1.0);
        $comfort = floatval($cfg['comfort_base']) * $severity * (0.5 + $profile['codependence'])
            * ($mode === 'withdraw' ? floatval($cfg['withdraw_comfort_mult']) : 1.0);
        $trustDrop = floatval($cfg['trust_base']) * $severity * $duration;
        return ['raw' => $raw, 'comfort' => $comfort, 'trust' => $trustDrop, 'severity' => $severity,
                'duration_scale' => $duration, 'trust_scale' => $trustScale, 'rate_mult' => $profile['rate_mult'], 'mode' => $mode];
    }

    /**
     * The return from an absence (prerequest, right after processAffinityDecay applied this
     * request's absence decay, if any; markContact has run, so the absence began at
     * _previous_contact_gamets). Breaks the bond when the absence carried core affinity from at
     * or above the bond type's threshold to below it (the absence decay now, and any affinity
     * rot the calendar applied while they were apart), after an absence longer than the NPC's
     * neglect grace, once per absence. Returns the break record, or null.
     *
     * @param array|null $decay processAffinityDecay() result (null: no decay ticks this request)
     * @param float      $now   raw gamets (game calendar)
     */
    public static function onAbsenceDecay(string $npcName, array &$dynamics, ?array $decay, float $now): ?array
    {
        $cfg = self::breakConfig();
        if (empty($cfg['enabled']) || $now <= 0 || !is_numeric($dynamics['_aff_mirror_x'] ?? null)) return null;
        if (($dynamics['_walkaway_state'] ?? 'normal') !== 'normal') return null;
        $since = floatval($dynamics['_previous_contact_gamets'] ?? 0);   // raw gamets: the absence began
        $last = floatval($dynamics['_last_contact_gamets'] ?? 0);        // raw gamets: this contact
        if ($since <= 0 || $last <= $since) return null;
        $prior = is_array($dynamics[self::BREAK_KEY] ?? null) ? $dynamics[self::BREAK_KEY] : [];
        if (abs(floatval($prior['since_gamets'] ?? -1) - $since) < 0.5) return null;   // this absence already broke it

        $bondType = RelationshipDynamics::getRelationshipType($npcName, $dynamics);
        $threshold = self::breakThreshold($bondType, $cfg);
        if ($threshold === null) return null;
        $new = RelationshipDynamics::getCoreAffinity($dynamics);                                  // core points
        $beforeDecay = (is_array($decay) && is_numeric($decay['old_affinity'] ?? null)) ? floatval($decay['old_affinity']) : $new;
        $old = $beforeDecay - self::rotSinceContact($dynamics, $since);   // core points as the absence began
        if (!($old >= $threshold && $new < $threshold)) return null;

        // Short = life happens, long = feels intentional: only an absence past the NPC's neglect
        // grace (same grace as the daily neglect: bond type x who they are x the fulfillment band)
        // x break_after_grace_mult can break the bond. Who she is and how long he was gone decide,
        // not only how close the number sat to the line. The grace is the one the absence ran
        // under (the band the contact before it left), not one the absence itself shortened.
        $graceDays = RelationshipDynamics::neglectGraceGameDays($dynamics, true);
        if ($graceDays === null) return null;
        $graceGamets = $graceDays * RelationshipDynamics::GAMETS_PER_DAY * max(1.0, floatval($cfg['break_after_grace_mult']));
        $absentGamets = $last - $since;
        if ($absentGamets <= $graceGamets) return null;
        $absentDays = $absentGamets / RelationshipDynamics::GAMETS_PER_DAY;

        $m = self::magnitudes($dynamics, $bondType, $absentDays, $cfg);
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $felt = RelationshipDynamics::chargeNeglectResentment($dynamics, $m['raw'], $since);
        // comfort / trust points actually moved (<= 0)
        $comfort = $m['comfort'] > 0 ? floatval(RelationshipDynamics::applyDelta('comfort', $dynamics, -$m['comfort'], $temperament)) : 0.0;
        $trust = $m['trust'] > 0 ? floatval(RelationshipDynamics::applyDelta('trust', $dynamics, -$m['trust'], $temperament)) : 0.0;
        self::recordGrievance($dynamics, $since, $now, $absentDays, $m['raw']);

        if ($m['mode'] === 'boundary') {
            // Rulings §9: the calm statement comes due now; RelDynFulfillment says it (takeFeltTexts)
            // and runs the probation and the step-back.
            $state = RelDynFulfillment::pairState($dynamics);
            $state['boundary'] = ['state' => 'pending', 'decided_gamets' => $now, 'source' => 'bond_break'];
            RelDynFulfillment::setPairState($dynamics, RelDynFulfillment::PLAYER, $state);
        }

        $record = [
            'since_gamets' => $since, 'at_gamets' => $now, 'absent_game_days' => round($absentDays, 3),
            'bond_type' => $bondType, 'threshold' => $threshold, 'affinity_before' => $old, 'affinity_after' => $new,
            'mode' => $m['mode'], 'raw' => round($m['raw'], 4), 'resentment' => round($felt, 4),
            'comfort_delta' => round($comfort, 4), 'trust_delta' => round($trust, 4),
            'say' => $m['mode'] === 'boundary' ? null : $m['mode'],
            'count' => intval($prior['count'] ?? 0) + 1,
        ];
        $dynamics[self::BREAK_KEY] = $record;
        RelationshipDynamics::log("[ABSENCE] {$npcName}: bond break ({$bondType}, " . round($absentDays, 1) . " game days, affinity "
            . round($old, 1) . ' -> ' . round($new, 1) . " < {$threshold}) mode {$m['mode']}: raw " . round($m['raw'], 2)
            . ', resentment +' . round($felt, 2) . ', comfort ' . round($comfort, 2) . ', trust ' . round($trust, 2));
        return $record;
    }

    /**
     * A bond that broke (a strain_modes break: the hurt of it) is strained from the break until the
     * first positive interaction after it (markPositive later than the break's game time; the
     * return's own exchange is the one she is hurt in). RelDynFelt reads it with open conflict, the
     * Ick and high resentment / jealousy: no romantic or social impulse rises toward the player and
     * the attraction says nothing next to her hurt. Pure.
     */
    public static function strains(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::breakConfig();
        $b = $dynamics[self::BREAK_KEY] ?? null;
        if (!is_array($b) || !in_array((string) ($b['mode'] ?? ''), (array) ($cfg['strain_modes'] ?? []), true)) return false;
        $at = floatval($b['at_gamets'] ?? 0);   // raw gamets of the break
        return $at > 0 && floatval($dynamics['_last_positive_gamets'] ?? 0) <= $at;
    }

    /** One 'neglect' grievance (kind 'bond_break') per break, so the confrontation can name it. */
    private static function recordGrievance(array &$dynamics, float $since, float $now, float $absentDays, float $raw): void
    {
        if (!isset($dynamics['dimensions']['resentment']) || !is_array($dynamics['dimensions']['resentment'])) {
            $dynamics['dimensions']['resentment'] = ['x' => 0, 'baseline' => 0, 'active' => true];
        }
        $log = $dynamics['dimensions']['resentment']['grievance_log'] ?? [];
        if (!is_array($log)) $log = [];
        $log[] = ['text' => sprintf('bond break: no contact for %.1f game days', $absentDays), 'tag' => 'neglect',
                  'kind' => 'bond_break', 'since_gamets' => $since, 'game_days' => $absentDays, 'raw' => round($raw, 4), 'gamets' => $now];
        $dynamics['dimensions']['resentment']['grievance_log'] = array_slice($log, -10);
    }

    /**
     * This turn's line for RelDynFelt, consumed only when the player is speaking to this NPC
     * ($playerAddressed). A 'confront' break is left to the MDD 15.5 confrontation when that is
     * said this same turn ($confrontationSaid): one voice. The 'boundary' mode has no line of its
     * own (the §9 statement is RelDynFulfillment's).
     * @return array ['lines' => [['key', 'text']], 'changed' => bool]
     */
    public static function takeFeltLines(array &$dynamics, string $npcName, string $playerName, bool $playerAddressed, bool $confrontationSaid = false): array
    {
        $out = ['lines' => [], 'changed' => false];
        $b = $dynamics[self::BREAK_KEY] ?? null;
        if (!$playerAddressed || !is_array($b) || !is_string($b['say'] ?? null)) return $out;
        $cfg = self::breakConfig();
        $mode = (string) $b['say'];
        $dynamics[self::BREAK_KEY]['say'] = null;
        $out['changed'] = true;
        if ($mode === 'confront' && $confrontationSaid) return $out;
        $text = ((array) $cfg['felt_text'])[$mode] ?? null;
        if (is_string($text) && $text !== '') {
            $out['lines'][] = ['key' => $mode, 'text' => strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerName])];
        }
        return $out;
    }

    // =====================================================================
    // AFFINITY ROT
    // =====================================================================

    /**
     * A positive interaction at $gamets (raw, game calendar): the rot clock starts over.
     * Called by the eval consumer for a positive exchange it scored and by postrequest for a
     * positive exchange the local classifier scored.
     */
    public static function markPositive(array &$dynamics, float $gamets): void
    {
        if ($gamets <= 0) return;
        $dynamics['_last_positive_gamets'] = max(floatval($dynamics['_last_positive_gamets'] ?? 0), $gamets);
    }

    /**
     * Core affinity points (<= 0) rot took during the absence that began at the contact
     * $contactGamets (raw), 0 when none: the bond break reads where the absence started from.
     */
    public static function rotSinceContact(array $dynamics, float $contactGamets): float
    {
        $a = $dynamics[self::ROT_KEY]['absence'] ?? null;
        if (!is_array($a) || abs(floatval($a['contact'] ?? -1) - $contactGamets) >= 0.5) return 0.0;
        return floatval($a['applied'] ?? 0.0);
    }

    /** The rot conditions that hold now: condition => true. Pure. */
    public static function rotConditions(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::rotConfig();
        $out = [];
        $bondType = RelationshipDynamics::getRelationshipType('', $dynamics);
        $gate = RelationshipDynamics::TIER_FLOOR_GATES[$bondType] ?? 'none';
        if (!empty($dynamics['in_conflict']) && $gate !== 'no_decay') $out['conflict'] = true;
        $core = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $grieving = !empty($cfg['low_passion_grief_pause']) && is_array($dynamics['_grief_held'] ?? null) && $dynamics['_grief_held'] !== [];
        if (!$grieving && in_array($core, (array) $cfg['low_passion_core_types'], true) && self::romanceCanGoCold($dynamics, $cfg)) {
            $passion = RelationshipDynamics::getPassion($dynamics);   // passion points (the floor, no moment)
            $tierFloor = RelDynGovernors::floor($dynamics);          // passion points, 0 without a governor
            if ($passion < floatval($cfg['low_passion_below']) || ($tierFloor > 0.0 && $passion <= $tierFloor + 1e-6)) {
                $out['low_passion'] = true;
            }
        }
        return array_intersect_key($out, (array) $cfg['conditions']);
    }

    /**
     * MDD 8.3 (low_passion_needs_attraction): an unattracted partner (the political marriage) has
     * no romance to go cold. True without a Matrix summary or with the Matrix off. Pure.
     */
    private static function romanceCanGoCold(array $dynamics, array $cfg): bool
    {
        if (empty($cfg['low_passion_needs_attraction'])) return true;
        $a = $dynamics['_attraction'] ?? null;
        if (!is_array($a) || empty($a['enabled'])) return true;
        return !empty($a['attracted']) && empty($a['hard_zero']);
    }

    /**
     * The end (raw gamets) of the time a condition's rot can run in [.., $to]: an open conflict
     * rots through any absence (time does not heal, decisions §2); the cold romance only while the
     * absence is still "life happens", her neglect grace after the last contact (or the contact
     * itself for a bond whose neglect does not matter). Past that the absence path owns the time
     * apart (the decay, the daily neglect, the bond break), and the cold romance is the romance
     * between them, not his absence counted twice.
     */
    private static function rotRunsUntil(string $condition, array $dynamics, float $to): float
    {
        if ($condition !== 'low_passion') return $to;
        $contact = floatval($dynamics['_last_contact_gamets'] ?? 0);   // raw gamets
        if ($contact <= 0) return $to;
        $grace = RelationshipDynamics::neglectGraceGameDays($dynamics) ?? 0.0;   // game days
        return min($to, $contact + $grace * RelationshipDynamics::GAMETS_PER_DAY);
    }

    /**
     * Advance rot over game-calendar time [from, to] (raw gamets). Pure: dynamics only (the
     * affinity moves the core mirror; the calendar step commits it to core).
     *
     * @return array ['applied' => core affinity points (<= 0), 'raw' => before M, 'm' => M_modifiers,
     *                'days' => rot game days charged, 'condition' => ?string, 'tier_regressed' => ?string, 'changed' => bool]
     */
    public static function rotStep(array &$dynamics, float $from, float $to): array
    {
        $out = ['applied' => 0.0, 'raw' => 0.0, 'm' => 1.0, 'days' => 0.0, 'condition' => null, 'tier_regressed' => null, 'changed' => false];
        $cfg = self::rotConfig();
        if (empty($cfg['enabled']) || $from <= 0 || $to <= $from) return $out;
        $before = $dynamics[self::ROT_KEY] ?? null;
        $state = is_array($before) ? $before : [];
        $day = RelationshipDynamics::GAMETS_PER_DAY;

        $away = ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal';
        $bond = intval($dynamics['context_tier_hwm'] ?? 0) >= intval(RelationshipDynamics::configValue('walkaway_affinity_min_tier'));
        $conditions = ($away || !$bond || !is_numeric($dynamics['_aff_mirror_x'] ?? null)) ? [] : self::rotConditions($dynamics, $cfg);

        // Onset per condition (raw gamets): an open conflict from its stamp, else from this step's start
        $since = is_array($state['since'] ?? null) ? $state['since'] : [];
        foreach (array_keys((array) $cfg['conditions']) as $c) {
            if (!isset($conditions[$c])) { unset($since[$c]); continue; }
            if (!isset($since[$c])) {
                $stamp = $c === 'conflict' ? floatval($dynamics['_conflict_entered_gamets'] ?? 0) : 0.0;
                $since[$c] = ($stamp > 0 && $stamp <= $to) ? $stamp : $from;
            }
        }
        $state['since'] = $since;

        $lastPositive = floatval($dynamics['_last_positive_gamets'] ?? 0);   // raw gamets
        $grace = floatval($cfg['grace_game_days']) * $day;
        $best = 0.0;
        foreach ($since as $c => $s) {
            $start = max(floatval($s), $lastPositive) + $grace;
            $days = max(0.0, self::rotRunsUntil((string) $c, $dynamics, $to) - max($from, $start)) / $day;
            $weighted = $days * floatval(((array) $cfg['conditions'][$c])['mult'] ?? 1.0);
            if ($weighted > $best) {
                $best = $weighted;
                $out['condition'] = $c;
                $out['days'] = $days;
            }
        }

        if ($best > 0.0) {
            $floor = floatval(RelationshipDynamics::configValue('walkaway_affinity_at'));   // core points
            $aff = RelationshipDynamics::getCoreAffinity($dynamics);
            $raw = -floatval($cfg['core_points_per_game_day']) * $best;
            $tags = (array) (((array) $cfg['conditions'][$out['condition']])['tags'] ?? []);
            $m = RelationshipDynamics::affinityModifiers($dynamics, $raw, $tags)['M'];
            $new = max($floor, $aff + $raw * $m);
            $out['raw'] = $raw;
            $out['m'] = $m;
            if ($new < $aff) {
                RelationshipDynamics::setCoreAffinityValue($dynamics, $new);
                $out['applied'] = RelationshipDynamics::getCoreAffinity($dynamics) - $aff;
                $state['applied'] = round(floatval($state['applied'] ?? 0) + $out['applied'], 4);
                // per absence (keyed by the contact it counts from): rotSinceContact
                $contact = floatval($dynamics['_last_contact_gamets'] ?? 0);   // raw gamets
                $prev = is_array($state['absence'] ?? null) && abs(floatval($state['absence']['contact'] ?? -1) - $contact) < 0.5
                    ? floatval($state['absence']['applied'] ?? 0) : 0.0;
                $state['absence'] = ['contact' => $contact, 'applied' => round($prev + $out['applied'], 4)];
                $state['last_gamets'] = $to;
                $state['last_condition'] = $out['condition'];
                // The stage regresses below its floor: rot is not held by retention or gates
                $held = $dynamics['_current_tier'] ?? null;
                $tier = RelationshipDynamics::getCurrentTier(RelationshipDynamics::getCoreAffinity($dynamics));
                if (is_string($held) && RelationshipDynamics::tierRank($held) > RelationshipDynamics::tierRank($tier)) {
                    $dynamics['_current_tier'] = $tier;
                    $dynamics['_current_tier_units'] = RelationshipDynamics::TIER_LABEL_UNITS;
                    $out['tier_regressed'] = $tier;
                }
            }
        }
        if ($state === ['since' => []] && $before === null) {
            return $out;   // nothing to track, nothing stored
        }
        $dynamics[self::ROT_KEY] = $state;
        $out['changed'] = $state != $before || $out['applied'] != 0.0;
        return $out;
    }

    // =====================================================================
    // JEV (numbers are fine here, never for the LLM)
    // =====================================================================

    /** Numbers for Jev: the last bond break and the rot running now. */
    public static function jev(array $dynamics): array
    {
        $b = is_array($dynamics[self::BREAK_KEY] ?? null) ? $dynamics[self::BREAK_KEY] : null;
        $r = is_array($dynamics[self::ROT_KEY] ?? null) ? $dynamics[self::ROT_KEY] : [];
        return [
            'bond_break' => $b === null ? null : [
                'mode' => (string) ($b['mode'] ?? ''), 'absent_game_days' => round(floatval($b['absent_game_days'] ?? 0), 1),
                'resentment' => round(floatval($b['resentment'] ?? 0), 2), 'comfort_delta' => round(floatval($b['comfort_delta'] ?? 0), 2),
                'trust_delta' => round(floatval($b['trust_delta'] ?? 0), 2), 'count' => intval($b['count'] ?? 0),
            ],
            'rot_conditions' => array_keys((array) ($r['since'] ?? [])),
            'rot_applied' => round(floatval($r['applied'] ?? 0), 2),
        ];
    }
}
