<?php
/**
 * Relationship Dynamics — post-intimacy states (roadmap post-intimacy; dimension design draft
 * "Post-Intimacy State"; audit 2026-03-29 #26 names the outcomes).
 *
 * "Sex is a high-arousal event with dimensional consequences that differ based on the
 * relationship context. The system doesn't judge the act -- it judges the dimensional state
 * surrounding it." RelDyn owns the emotional layer and Sharmat the bedroom (rulings §9): RelDyn
 * only reads the intimacy the plugin reports (RelDynIntimacy::requestKind: a Sharmat / OStim
 * scene with the player named in it) and never writes Sharmat's state. The scene's passion is
 * the existing spike trigger's (RelDynPassion::onExchange, 'intimacy reported'); its needs are
 * the intimacy axes' (RelDynIntimacy::recordRequest). This module adds what follows the scene.
 *
 * An ENCOUNTER is the first scene request after encounter_gap_game_hours without one; later
 * scene requests inside that gap extend it (the afterglow and the sober morning count from its
 * last request). At its start the context is read once and one outcome chosen, first match:
 *
 *   cheating_guilt     she has a partner in core's relationships (partner_core_types) who is
 *                      not the player, and the player is not her partner: the draft's
 *                      "cheating on bonded partner" (comfort +8 -> -25, resentment_self +15; the
 *                      trust -15 is toward the one she cheated on, a pair RelDyn does not keep)
 *   drunk_regret       intoxicated (an intoxicant among her active consumables) and not a deep
 *                      bond: "Bonded, drunk, regret next day" (+10 -> -15, trust -5,
 *                      resentment_self +10) for a romance, "Stranger, drunk, one-night"
 *                      (+10 -> -20, trust 0, resentment_self +12) otherwise
 *   vulnerable_fear    fearful attachment (both axes at fearful_at): she wants the closeness and
 *                      fears it; the draft's "Low maturity, manipulated" row (+5 -> -10, trust
 *                      -8, resentment_self +8). Before the bond's depth: earned security (the
 *                      axes' drift) is what lets the same closeness deepen
 *   avoidant_retreat   attachment avoidance at avoidant_retreat_at: closeness she needs to back
 *                      away from (a small glow, then a little distance)
 *   bonded_deepening   core partner (bonded_core_types) and trust (as it reads toward the player)
 *                      at trust_deep: "Bonded, high trust, sober" (comfort +15 and warmth +10 for
 *                      2 game hours, trust +3)
 *   committed_warmth   a romance (romance_core_types): "Crush, sober, mutual" (comfort +12,
 *                      trust +5)
 *   vulnerable_fear    also outside a romance, trust below trust_low or her own maturity below
 *                      maturity_low: "Low maturity, manipulated"
 *   casual_distance    anything else: sober and casual, a light glow and some distance
 *
 * Each outcome row (config outcomes): 'held' dimension points applied through applyDelta and
 * held on x until held_game_hours after the encounter's last request, then taken back exactly
 * (a temporary state like a drink: heldTemporarySources; a warmth column reaches derived warmth
 * at its value), 'lasting' points applied at once, the horseshoe (arousal_spike for everyone,
 * the row's 'valence': the same physiology, the context decides the sign), and an optional
 * 'correction': the sober self's verdict (draft: "diary eval, hours later"), due
 * correction_after_game_hours after the encounter and only once she is sober: lasting comfort /
 * trust points, resentment_self scaled by her own maturity (x clamp(maturity /
 * resentment_self_maturity_ref, ...), then kept within resentment_self_range: "+5 to +15
 * depending on maturity"), and a 'correction_valence'. A shallow mind (RelDynDiary::depth
 * 'shallow', the bad evaluator of the draft) never corrects.
 *
 * Felt text (feelings, never numbers; RelDynFelt 'post_intimacy', bond scope): the row's glow
 * while the afterglow holds ('moment' for a row with a correction still to come), its 'after'
 * text from the correction for regret_felt_game_hours.
 *
 * Time is the game calendar (raw gamets); a calendar behind the encounter (a load from before
 * it) drops it and takes back what is still held. State: $dynamics['_post_intimacy'].
 *
 * Units: dimension points (0..100; valence -100..100); resentment_self points 0..100; trust
 * points as they read toward the player; maturity points 0..100; attachment axes 0..1; time in
 * game hours (RelationshipDynamics::GAMETS_PER_HOUR raw gamets each).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynPostIntimacy
{
    const KEY = '_post_intimacy';

    const CHEATING = 'cheating_guilt';
    const DRUNK = 'drunk_regret';
    const BONDED = 'bonded_deepening';
    const AVOIDANT = 'avoidant_retreat';
    const COMMITTED = 'committed_warmth';
    const VULNERABLE = 'vulnerable_fear';
    const CASUAL = 'casual_distance';
    const OUTCOMES = [self::CHEATING, self::DRUNK, self::BONDED, self::AVOIDANT, self::COMMITTED, self::VULNERABLE, self::CASUAL];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'post_intimacy' (a stored config replaces whole settings / tables).
     * Draft numbers where the draft gives them (its context table); the thresholds and the
     * avoidant / casual rows are Serene's starting values: tune after playtest.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // RelDynIntimacy::requestKind kinds that are an encounter (a VR touch is not a scene)
            'kinds' => ['scene'],
            'encounter_gap_game_hours' => 1.0,
            // draft: "Post-scene modifiers (temporary, 2 game hours)"
            'held_game_hours' => 2.0,
            // draft: "6 hours pass. Alcohol effects expire" -- the sober self's verdict
            'correction_after_game_hours' => 6.0,
            // how long the sober self's feeling shows after the correction
            'regret_felt_game_hours' => 24.0,
            // CONSUMABLE_EFFECTS keys that make her intoxicated while active
            'intoxicants' => ['skooma', 'sleeping_tree_sap', 'ale', 'generic_drink'],
            // core Player.type rows (lower-case)
            'bonded_core_types' => ['romantic'],
            'romance_core_types' => ['romantic', 'crush'],
            // her own core relationships to someone other than the player that make it cheating
            'partner_core_types' => ['romantic'],
            // trust points as they read toward the player
            'trust_deep' => 50.0,
            'trust_low' => 30.0,
            // her own maturity points (without temporary offsets)
            'maturity_low' => 30.0,
            // attachment axes 0..1: fearful (both high: wants closeness and fears it; the style
            // regions' 0.5) and an avoidance high enough to back away from any closeness
            'fearful_at' => ['anxiety' => 0.5, 'avoidance' => 0.5],
            'avoidant_retreat_at' => 0.6,
            // arousal points every encounter spikes (the horseshoe: the valence is the row's)
            'arousal_spike' => 30.0,
            // resentment_self of a correction x clamp(maturity / ref, min, max) (Aela at 65 is the
            // draft's worked example), kept within the range
            'resentment_self_maturity_ref' => 65.0,
            'resentment_self_maturity_scale' => [0.5, 1.25],
            'resentment_self_range' => [5.0, 15.0],
            'outcomes' => [
                self::BONDED => [
                    'held' => ['comfort' => 15.0, 'warmth' => 10.0], 'lasting' => ['trust' => 3.0], 'valence' => 20.0,
                    'glow' => 'physically at ease, soft smiles, unguarded in a way that is rare, lingers close without needing a reason',
                ],
                self::COMMITTED => [
                    'held' => ['comfort' => 12.0], 'lasting' => ['trust' => 5.0], 'valence' => 15.0,
                    'glow' => 'stays close, easy warmth in every look, a little shy about how much it meant',
                ],
                self::CASUAL => [
                    'held' => ['comfort' => 10.0], 'lasting' => [], 'valence' => 8.0,
                    'glow' => 'keeps it light afterwards, a little awkward, does not linger or make it more than it was',
                ],
                self::AVOIDANT => [
                    'held' => ['comfort' => 5.0], 'lasting' => [], 'valence' => -5.0,
                    'correction' => ['comfort' => -5.0], 'correction_valence' => -5.0,
                    'glow' => 'pulls back soon after, busies the hands, needs a little distance to feel steady again',
                    'after' => 'keeps a careful distance, deflects anything tender with a shrug or a task',
                ],
                self::VULNERABLE => [
                    'held' => ['comfort' => 5.0], 'lasting' => [], 'valence' => -10.0,
                    'correction' => ['comfort' => -10.0, 'trust' => -8.0, 'resentment_self' => 8.0], 'correction_valence' => -15.0,
                    'glow' => 'quiet and watchful afterwards, arms drawn in, unsure it was safe to let them that close',
                    'after' => 'flinches at a casual touch, keeps them at arm\'s length, wary of being used',
                ],
                self::DRUNK => [
                    'held' => ['comfort' => 10.0], 'lasting' => [], 'valence' => 10.0,
                    // romance / otherwise (draft rows "Bonded, drunk" / "Stranger, drunk, one-night")
                    'correction' => ['comfort' => -15.0, 'trust' => -5.0, 'resentment_self' => 10.0],
                    'correction_casual' => ['comfort' => -20.0, 'resentment_self' => 12.0],
                    'correction_valence' => -20.0,
                    'glow' => 'relaxed, laughing easily, guards down in a way the sober self would not allow',
                    'after' => 'avoids their eyes, flinches at a casual touch, hides behind distance and brisk professionalism',
                ],
                self::CHEATING => [
                    'held' => ['comfort' => 8.0], 'lasting' => [], 'valence' => 5.0,
                    'correction' => ['comfort' => -25.0, 'resentment_self' => 15.0], 'correction_valence' => -25.0,
                    'glow' => 'flushed and reckless, pushing away the thought of someone else',
                    'after' => 'cannot meet their eyes, restless with guilt, overcompensates with distance',
                ],
            ],
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('post_intimacy');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function hours(float $h): float
    {
        return $h * RelationshipDynamics::GAMETS_PER_HOUR;
    }

    // =====================================================================
    // THE CONTEXT AND THE OUTCOME (pure)
    // =====================================================================

    /** Is an intoxicant among her active consumables (config intoxicants)? Pure. */
    public static function intoxicated(array $dynamics, ?array $cfg = null): bool
    {
        $keys = array_map('strval', (array) (($cfg ?? self::config())['intoxicants'] ?? []));
        foreach ((array) ($dynamics['_active_consumables'] ?? []) as $c) {
            if (is_array($c) && in_array((string) ($c['key'] ?? ''), $keys, true)) return true;
        }
        return false;
    }

    /**
     * Her partners in core's relationships other than the player ($relationships: the decoded
     * extended_data.relationships of her core row): names whose type is a partner_core_types row.
     */
    public static function otherPartners(array $relationships, ?array $cfg = null): array
    {
        $types = array_map('strtolower', array_map('strval', (array) (($cfg ?? self::config())['partner_core_types'] ?? [])));
        $out = [];
        foreach (RelationshipDynamics::normalizeRelationshipMap($relationships) as $name => $rel) {
            if ($name === RelationshipDynamics::PLAYER_RELATIONSHIP_KEY || !is_array($rel)) continue;
            if (in_array(strtolower(trim((string) ($rel['type'] ?? ''))), $types, true)) $out[] = (string) $name;
        }
        return $out;
    }

    /**
     * The context of an encounter from her state ($otherPartners: otherPartners of her core
     * row). Pure.
     */
    public static function context(array $dynamics, array $otherPartners = [], ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $core = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $trust = RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'trust');
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        return [
            'core_type' => $core,
            'bonded' => $core !== '' && in_array($core, array_map('strtolower', (array) $cfg['bonded_core_types']), true),
            'romance' => $core !== '' && in_array($core, array_map('strtolower', (array) $cfg['romance_core_types']), true),
            'trust' => $trust === null ? 50.0 : round($trust, 2),
            'maturity' => round(RelDynDiary::ownMaturity($dynamics), 2),
            'intoxicated' => self::intoxicated($dynamics, $cfg),
            'anxiety' => round(floatval($axes['anxiety'] ?? 0.0), 3),
            'avoidance' => round(floatval($axes['avoidance'] ?? 0.0), 3),
            'other_partners' => array_values($otherPartners),
        ];
    }

    /** The outcome of an encounter in $ctx (context()), first match of the module doc. Pure. */
    public static function outcome(array $ctx, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $deep = !empty($ctx['bonded']) && floatval($ctx['trust']) >= floatval($cfg['trust_deep']);
        if (!empty($ctx['other_partners']) && empty($ctx['bonded'])) return self::CHEATING;
        if (!empty($ctx['intoxicated']) && !$deep) return self::DRUNK;
        // who she is in closeness comes before how deep the bond runs: earned security lowers the
        // axes (attachment drift), and then the same closeness deepens
        if (floatval($ctx['anxiety']) >= floatval($cfg['fearful_at']['anxiety'])
            && floatval($ctx['avoidance']) >= floatval($cfg['fearful_at']['avoidance'])) return self::VULNERABLE;
        if (floatval($ctx['avoidance']) >= floatval($cfg['avoidant_retreat_at'])) return self::AVOIDANT;
        if ($deep) return self::BONDED;
        if (!empty($ctx['romance'])) return self::COMMITTED;
        if (floatval($ctx['trust']) < floatval($cfg['trust_low']) || floatval($ctx['maturity']) < floatval($cfg['maturity_low'])) {
            return self::VULNERABLE;
        }
        return self::CASUAL;
    }

    /** The resentment_self points of a correction: the row's x her maturity scale, within range. Pure. */
    public static function resentmentSelf(float $row, float $maturity, ?array $cfg = null): float
    {
        if ($row <= 0.0) return 0.0;
        $cfg = $cfg ?? self::config();
        [$lo, $hi] = array_map('floatval', array_values((array) $cfg['resentment_self_maturity_scale']) + [0.5, 1.25]);
        [$min, $max] = array_map('floatval', array_values((array) $cfg['resentment_self_range']) + [5.0, 15.0]);
        $ref = max(1.0, floatval($cfg['resentment_self_maturity_ref']));
        return max($min, min($max, $row * max($lo, min($hi, $maturity / $ref))));
    }

    // =====================================================================
    // THE ENCOUNTER (postrequest) AND ITS AFTERMATH (prerequest)
    // =====================================================================

    /**
     * A request that reports intimacy (postrequest, after RelDynIntimacy::recordRequest). A new
     * encounter reads the context (one core read for her other partners), picks the outcome and
     * applies its held and lasting points and the horseshoe; a request inside an encounter
     * extends it. Returns the new encounter's outcome, or null (no encounter, or an extension).
     */
    public static function onIntimateRequest(string $npcName, array &$dynamics, array $gameRequest, string $playerName, float $now): ?string
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $now <= 0) return null;
        $kind = RelDynIntimacy::requestKind($gameRequest, $playerName);
        if ($kind === null || !in_array($kind, array_map('strval', (array) $cfg['kinds']), true)) return null;

        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : null;
        if ($state !== null && $now >= floatval($state['start'] ?? 0)
            && $now - floatval($state['last'] ?? 0) <= self::hours(floatval($cfg['encounter_gap_game_hours']))) {
            // the same encounter goes on: the afterglow and the morning count from here
            $state['last'] = $now;
            if (!empty($state['held'])) $state['held_until'] = $now + self::hours(floatval($cfg['held_game_hours']));
            if (empty($state['corrected']) && isset($state['correction_due'])) {
                $state['correction_due'] = $now + self::hours(floatval($cfg['correction_after_game_hours']));
            }
            $state['felt_until'] = max(floatval($state['felt_until'] ?? 0), floatval($state['held_until'] ?? 0));
            $dynamics[self::KEY] = $state;
            return null;
        }
        if ($state !== null) self::finish($npcName, $dynamics, $now, $cfg, 'a new encounter');

        $partners = [];
        try {
            $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
            $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
            $partners = self::otherPartners((array) ($ext['relationships'] ?? []), $cfg);
        } catch (Throwable $e) {
            RelationshipDynamics::logError("post-intimacy core relationships of {$npcName}", $e);
        }
        $ctx = self::context($dynamics, $partners, $cfg);
        $outcome = self::outcome($ctx, $cfg);
        $row = (array) (((array) $cfg['outcomes'])[$outcome] ?? []);
        $temperament = $dynamics['inferred_temperament'] ?? null;

        $held = [];
        foreach ((array) ($row['held'] ?? []) as $dim => $p) {
            $a = RelationshipDynamics::applyDelta((string) $dim, $dynamics, floatval($p), $temperament);
            if (abs($a) > 1e-6) $held[(string) $dim] = round($a, 6);
        }
        $lasting = [];
        foreach ((array) ($row['lasting'] ?? []) as $dim => $p) {
            $a = RelationshipDynamics::applyDelta((string) $dim, $dynamics, floatval($p), $temperament);
            if (abs($a) > 1e-6) $lasting[(string) $dim] = round($a, 4);
        }
        // the horseshoe: every encounter is an arousal spike, the context decides its valence
        $mood = [];
        foreach (['arousal' => floatval($cfg['arousal_spike']), 'valence' => floatval($row['valence'] ?? 0.0)] as $dim => $p) {
            if (abs($p) < 1e-6) continue;
            $a = RelationshipDynamics::applyDelta($dim, $dynamics, $p, $temperament);
            if (abs($a) > 1e-6) $mood[$dim] = round($a, 4);
        }
        $heldUntil = $now + self::hours(floatval($cfg['held_game_hours']));
        $correction = is_array($row['correction'] ?? null) && $row['correction'] !== [];
        $dynamics[self::KEY] = [
            'outcome' => $outcome,
            'start' => $now, 'last' => $now,
            'held' => $held, 'held_until' => $heldUntil,
            'correction_due' => $correction ? $now + self::hours(floatval($cfg['correction_after_game_hours'])) : null,
            'corrected' => !$correction,
            'felt_until' => $heldUntil,
            'lasting' => $lasting, 'mood' => $mood,
            'context' => $ctx,
        ];
        RelationshipDynamics::log(sprintf('[POST-INTIMACY] %s: %s (core %s, trust %.1f, maturity %.1f, %s, avoidance %.2f%s) held %s lasting %s mood %s',
            $npcName, $outcome, $ctx['core_type'] !== '' ? $ctx['core_type'] : 'none', $ctx['trust'], $ctx['maturity'],
            $ctx['intoxicated'] ? 'intoxicated' : 'sober', $ctx['avoidance'],
            $partners !== [] ? ', partner ' . implode('/', $partners) : '', json_encode($held), json_encode($lasting), json_encode($mood)));
        return $outcome;
    }

    /**
     * The aftermath at $now (prerequest, every turn): the afterglow ends at held_until (its held
     * points taken back exactly), the sober self's correction lands when due and she is sober,
     * the state ends when nothing is held, pending or felt. A load from before the encounter
     * drops it. Returns what happened: ['released' => dim => points, 'corrected' => dim => points,
     * 'dropped' => bool, 'ended' => bool].
     */
    public static function tick(string $npcName, array &$dynamics, float $now): array
    {
        $out = ['released' => [], 'corrected' => [], 'dropped' => false, 'ended' => false];
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : null;
        if ($state === null || $now <= 0) return $out;
        $cfg = self::config();
        if ($now < floatval($state['start'] ?? 0)) {
            // a save from before the encounter: it never happened in this timeline
            $out['released'] = self::release($dynamics, $state, 'the encounter a load undid');
            unset($dynamics[self::KEY]);
            $out['dropped'] = true;
            RelationshipDynamics::log("[POST-INTIMACY] {$npcName}: dropped (the calendar is before the encounter)");
            return $out;
        }
        if (!empty($state['held']) && $now >= floatval($state['held_until'] ?? 0)) {
            $out['released'] = self::release($dynamics, $state, 'the afterglow');
            $state['held'] = [];
        }
        if (empty($state['corrected']) && $now >= floatval($state['correction_due'] ?? PHP_FLOAT_MAX)
            && !self::intoxicated($dynamics, $cfg)) {
            $out['corrected'] = self::correct($npcName, $dynamics, $state, $now, $cfg);
        }
        if (empty($state['held']) && !empty($state['corrected']) && $now >= floatval($state['felt_until'] ?? 0)) {
            unset($dynamics[self::KEY]);
            $out['ended'] = true;
            return $out;
        }
        $dynamics[self::KEY] = $state;
        return $out;
    }

    /** End an encounter now (a new one begins): what is held is taken back, a pending correction lands. */
    private static function finish(string $npcName, array &$dynamics, float $now, array $cfg, string $why): void
    {
        $state = (array) $dynamics[self::KEY];
        self::release($dynamics, $state, "the afterglow ({$why})");
        $state['held'] = [];
        if (empty($state['corrected'])) self::correct($npcName, $dynamics, $state, $now, $cfg);
        unset($dynamics[self::KEY]);
    }

    /** Take back the held points exactly (RelationshipDynamics::reverseAppliedDeltas). */
    private static function release(array &$dynamics, array $state, string $what): array
    {
        $held = array_map('floatval', array_filter((array) ($state['held'] ?? []), 'is_numeric'));
        if ($held !== []) RelationshipDynamics::reverseAppliedDeltas($dynamics, $held, 'RelDyn-POST-INTIMACY', $what);
        return $held;
    }

    /**
     * The sober self's verdict: the row's correction (the casual table outside a romance for the
     * drunken night), resentment_self by her maturity, the correction valence; nothing for a
     * shallow mind. Marks $state corrected and sets how long the after-text shows.
     */
    private static function correct(string $npcName, array &$dynamics, array &$state, float $now, array $cfg): array
    {
        $state['corrected'] = true;
        $row = (array) (((array) $cfg['outcomes'])[(string) ($state['outcome'] ?? '')] ?? []);
        $maturity = RelDynDiary::ownMaturity($dynamics);
        if (RelDynDiary::depth($maturity) === 'shallow') {
            $state['felt_until'] = floatval($state['held_until'] ?? $now);
            $state['shallow'] = true;
            RelationshipDynamics::log(sprintf('[POST-INTIMACY] %s: a shallow mind (maturity %.1f) does not look back on it', $npcName, $maturity));
            return [];
        }
        $table = (array) ($row['correction'] ?? []);
        if (($state['outcome'] ?? '') === self::DRUNK && empty($state['context']['romance']) && is_array($row['correction_casual'] ?? null)) {
            $table = $row['correction_casual'];
        }
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $applied = [];
        foreach ($table as $dim => $p) {
            $p = floatval($p);
            if ($dim === 'resentment_self') $p = self::resentmentSelf($p, $maturity, $cfg);
            $a = RelationshipDynamics::applyDelta((string) $dim, $dynamics, $p, $temperament);
            if (abs($a) > 1e-6) $applied[(string) $dim] = round($a, 4);
        }
        $v = floatval($row['correction_valence'] ?? 0.0);
        if (abs($v) > 1e-6) {
            $a = RelationshipDynamics::applyDelta('valence', $dynamics, $v, $temperament);
            if (abs($a) > 1e-6) $applied['valence'] = round($a, 4);
        }
        $state['correction'] = $applied;
        $state['felt_until'] = $now + self::hours(floatval($cfg['regret_felt_game_hours']));
        RelationshipDynamics::log("[POST-INTIMACY] {$npcName}: the sober self on the {$state['outcome']} encounter " . json_encode($applied));
        return $applied;
    }

    // =====================================================================
    // FELT TEXT, JEV
    // =====================================================================

    /**
     * The felt read at $now (behavioural keywords, never numbers): the row's glow while the
     * afterglow holds ('glow'; before a pending correction it is the moment), its 'after' text
     * from the correction while felt. Null without an encounter or text.
     */
    public static function feltText(array $dynamics, float $now): ?array
    {
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : null;
        if ($state === null || $now <= 0 || $now < floatval($state['start'] ?? 0)) return null;
        $row = (array) (((array) self::config()['outcomes'])[(string) ($state['outcome'] ?? '')] ?? []);
        $phase = null;
        if (!empty($state['correction']) && $now < floatval($state['felt_until'] ?? 0)) {
            $phase = 'after';
        } elseif ($now < floatval($state['held_until'] ?? 0)) {
            $phase = 'glow';
        }
        $text = $phase !== null ? trim((string) ($row[$phase] ?? '')) : '';
        return $text === '' ? null : ['phase' => $phase, 'text' => $text, 'outcome' => (string) $state['outcome']];
    }

    /** Jev's numbers (decisions §3): outcome, phase times in game hours from now, what was applied. */
    public static function jev(array $dynamics, float $now): ?array
    {
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : null;
        if ($state === null) return null;
        $h = fn($t) => $t === null ? null : round((floatval($t) - $now) / RelationshipDynamics::GAMETS_PER_HOUR, 2);
        return [
            'outcome' => (string) ($state['outcome'] ?? ''),
            'held' => (array) ($state['held'] ?? []),
            'held_ends_in_game_hours' => !empty($state['held']) ? $h($state['held_until'] ?? null) : null,
            'correction_in_game_hours' => empty($state['corrected']) ? $h($state['correction_due'] ?? null) : null,
            'correction' => (array) ($state['correction'] ?? []),
            'lasting' => (array) ($state['lasting'] ?? []),
        ];
    }
}
