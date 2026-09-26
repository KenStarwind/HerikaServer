<?php
/**
 * Passion as a floor and a spike, the desire loop, derived warmth (roadmap passion-floor-spike,
 * desire-loop, derived-warmth; memory feedback_passion_spikes, session 2026-03-30).
 *
 * Passion is two things (feedback_passion_spikes):
 *   - the FLOOR: the slow passion earned through play. It is the stored passion
 *     (dimensions.passion.x, RelationshipDynamics::getPassion) with every writer it always had:
 *     the love-language gain and its multiplicative suppressors, the eval signal, decay, fade.
 *   - the SPIKE: the heart-racing moment (a flirt, a touch, a rescue). Event-driven, it bypasses
 *     the multiplicative suppressors (session, stage, love language, place), hits hard and keeps
 *     spike.retention_per_interaction of itself per interaction of the player pair, so it is gone
 *     in about five exchanges. It never forms on a floor below spike.min_floor (no racing heart
 *     for a stranger); temperament scales it (the trait engine's passion_mult: Guarded 0.6), a
 *     higher floor makes it bigger, arousal amplifies it. Every spike is a passion gain: it goes
 *     through RelationshipDynamics::gainPassion (the attraction factor at the effective passion).
 *   effective passion = floor + spike (+ the weather's pull on passion, weather-gravity-pull):
 *   what display, desire and the context read. The affinity drive, the stage floor, the
 *   attraction's won-over and emergent emotions keep reading the floor (lasting state).
 *
 * The desire loop (session 2026-03-30):
 *   - arousal amplifies passion gain: 1.0x at desire.arousal_amp_from arousal points, rising
 *     linearly to desire.arousal_amp_max at desire.arousal_amp_to (floor gains and spikes);
 *   - desire (the effective sex disposition, RelationshipDynamics::getEffectiveDisposition) reads
 *     the effective passion and the mood: +- desire.valence_max disposition points at
 *     +- desire.valence_full_at valence points;
 *   - the bond filters how a flirt feels (the valence back-filter): the player's romantic move
 *     moves her mood by desire.flirt_valence[bond type] valence points at full intent (a crush
 *     warms to it, a stranger's reads "eww", an acquaintance's is shrugged off).
 *
 * Derived warmth ("how emotionally open am I", dimension draft Dimension 3): no longer a stored
 * dimension the display reads. Per bond, warmth = sqrt(effective passion x effective comfort)
 * (both as they read toward the player: getEffectiveDimensionValue), plus the states held on
 * warmth (a drink, dusk, acute grief: heldTemporaryOffset; resentment_self's shame and worn gear:
 * heldBaselineOffset) and the weather's pull on warmth, each state once: one with a warmth column
 * of its own (a grief's -10, the overcast node's -3) reaches warmth at that value and its comfort
 * / passion part stays out of the root. The absence fades it by who she is (rulings §8,
 * fadeWarmth; a warm exchange gives some back) and Divine Intervention's breaking arc closes it
 * toward anyone but her bonded partner, opening again over the arc (breakingOpenness). Its bands
 * and keywords stay. The raw reading (no per-bond multiplier) is what tension checks use, as for
 * every dimension.
 *
 * Units: passion, spike, warmth, comfort 0..100 points; valence -100..100 points; arousal 0..100
 * points; disposition 0..30 points (Sharmat's sex_disposal scale); multipliers unitless.
 */
final class RelDynPassion
{
    /** The spike (passion points) on the NPC's dynamics. */
    const SPIKE_KEY = '_passion_spike';
    /** The last trigger that fed the spike, for the log and the editor. */
    const SPIKE_TRIGGER_KEY = '_passion_spike_trigger';

    public static function configDefaults(): array
    {
        return [
            'spike' => [
                'enabled' => true,
                // passion points: no spike on a floor below this (feedback_passion_spikes: "can't
                // spike on stranger")
                'min_floor' => 10.0,
                // the share of the spike kept per interaction of the player pair (55%: gone in
                // about five exchanges)
                'retention_per_interaction' => 0.55,
                // spike points; floor + spike never passes passion_max either
                'max' => 40.0,
                // spike points per trigger before the NPC's scaling (session 2026-03-30)
                'triggers' => [
                    'love_language_primary'   => 8.0,
                    'love_language_secondary' => 5.0,
                    'flirty_mood'             => 6.0,
                    'touch'                   => 10.0,
                    'topic_match'             => 3.0,
                    'rescue'                  => 7.0,
                ],
                // spike points per raw eval passion point (the eval's passion signal of the
                // exchange, when positive)
                'eval_passion_scale' => 1.0,
                // "higher floor = bigger spike": floor / floor_reference (passion points), at most
                // floor_scale_max (unitless)
                'floor_reference' => 40.0,
                'floor_scale_max' => 1.5,
                // decisions §2: a positive state fades with absence. Past the passion absence grace,
                // spike points lost per game-calendar day x the attachment multiplier
                // (passion_absence_attachment_mult).
                'absence_fade_per_game_day' => 40.0,
                // the NPC's reply moods that are a flirty moment (the flirt bonus reads them too)
                'flirty_moods' => ['flirty', 'romantic', 'playful', 'teasing', 'amused', 'charmed',
                    'smitten', 'coy', 'seductive', 'affectionate', 'bashful', 'flustered'],
            ],
            'desire' => [
                // arousal points from which arousal amplifies passion gain, to which it reaches
                // arousal_amp_max (unitless)
                'arousal_amp_from' => 15.0,
                'arousal_amp_to'   => 100.0,
                'arousal_amp_max'  => 1.8,
                // disposition points the mood adds (+) or takes (-) at +- valence_full_at valence points
                'valence_max'      => 3.0,
                'valence_full_at'  => 50.0,
                // The valence back-filter: valence points a romantic move of the player's gives her
                // mood at full intent (eval romantic_intent 3), by RelDyn bond type
                // (getRelationshipType). Unlisted types: flirt_valence_default.
                'flirt_valence' => [
                    'crush' => 6.0, 'bonded' => 6.0, 'sworn' => 2.0, 'friend' => 2.0,
                    'acquaintance' => 0.0, 'friendzone' => -2.0, 'parasite' => -2.0, 'mercenary' => -3.0,
                    'grieving' => -4.0, 'stranger' => -6.0, 'rival' => -6.0, 'hostile' => -10.0,
                ],
                'flirt_valence_default' => 0.0,
                // the eval's romantic_intent scale (0..3); a touch the local classifier read when no
                // eval scores the exchange counts as intent legacy_touch_intent
                'romantic_intent_max' => 3,
                'legacy_touch_intent' => 2,
            ],
            // Derived warmth (display / steering). Off: the stored warmth dimension is read as before.
            'derived_warmth_enabled' => true,
            // Rulings §8 warmth fade on derived warmth (the calendar step, fadeWarmth): warmth points a
            // positive interaction gives back of what the absence faded (contact heals, decisions §2).
            // Serene's starting value.
            'warmth_fade_regain_per_positive' => 2.0,
            // Divine Intervention's breaking arc, "zero warmth toward non-bonded": closed at the arc,
            // open again linearly over this many game days (the arc's own Brittle window, 30).
            'breaking_warmth_reopen_game_days' => 30.0,
        ];
    }

    public static function config(): array
    {
        $d = self::configDefaults();
        $stored = RelationshipDynamics::configValue('passion_dynamics');
        if (!is_array($stored)) return $d;
        $cfg = array_replace($d, $stored);
        foreach (['spike', 'desire'] as $k) {
            $cfg[$k] = array_replace($d[$k], is_array($stored[$k] ?? null) ? $stored[$k] : []);
        }
        return $cfg;
    }

    // =====================================================================
    // THE SPIKE
    // =====================================================================

    /** The spike (passion points, >= 0). */
    public static function spike(array $dynamics): float
    {
        $s = $dynamics[self::SPIKE_KEY] ?? 0.0;
        return is_numeric($s) ? max(0.0, floatval($s)) : 0.0;
    }

    /**
     * Effective passion (points): floor + spike + the weather's pull on passion, within
     * 0..passion_max. What display, desire and the context read.
     */
    public static function effective(array $dynamics): float
    {
        $max = floatval(RelationshipDynamics::configValue('passion_max') ?? 100.0);
        $e = RelationshipDynamics::getPassion($dynamics) + self::spike($dynamics)
            + RelationshipDynamics::weatherGravityOffset($dynamics, 'passion');
        return max(0.0, min($max, $e));
    }

    /**
     * Arousal's amplification of passion gain (unitless): 1.0 up to arousal_amp_from arousal
     * points, linear to arousal_amp_max at arousal_amp_to.
     */
    public static function arousalAmp(array $dynamics, ?array $cfg = null): float
    {
        $c = ($cfg ?? self::config())['desire'];
        $x = $dynamics['dimensions']['arousal']['x'] ?? null;
        if (!is_numeric($x)) return 1.0;
        $from = floatval($c['arousal_amp_from']);
        $to = max($from + 1e-6, floatval($c['arousal_amp_to']));
        $t = max(0.0, min(1.0, (floatval($x) - $from) / ($to - $from)));
        return 1.0 + (max(1.0, floatval($c['arousal_amp_max'])) - 1.0) * $t;
    }

    /**
     * The raw spike (points, before the attraction factor) $points of trigger make on this NPC:
     * $points x temperament (trait engine passion_mult) x floor scale x arousal amplification;
     * 0 below spike.min_floor or with the spike off.
     */
    public static function spikeSize(array $dynamics, float $points, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $s = $cfg['spike'];
        if (empty($s['enabled']) || $points <= 0.0) return 0.0;
        $floor = RelationshipDynamics::getPassion($dynamics);
        if ($floor < floatval($s['min_floor'])) return 0.0;
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $temper = max(0.0, floatval(RelDynTraits::param($temperament, 'passion_mult', 1.0, $dynamics)));
        $floorScale = min(floatval($s['floor_scale_max']), $floor / max(1e-6, floatval($s['floor_reference'])));
        return $points * $temper * $floorScale * self::arousalAmp($dynamics, $cfg);
    }

    /**
     * A spike from $trigger (a key of spike.triggers) of this exchange; $tags: the gain's eval
     * tags (its channel, decisions §15). Through gainPassion (the attraction factor). Returns the
     * spike points added.
     */
    public static function addTrigger(string $npcName, array &$dynamics, string $trigger, ?array $tags = null): float
    {
        $cfg = self::config();
        $points = floatval(((array) $cfg['spike']['triggers'])[$trigger] ?? 0.0);
        return self::addSpike($npcName, $dynamics, $points, $trigger, $tags, $cfg);
    }

    /** A spike of $points trigger points (see spikeSize) from $source. Returns the spike points added. */
    public static function addSpike(string $npcName, array &$dynamics, float $points, string $source, ?array $tags = null, ?array $cfg = null): float
    {
        $raw = self::spikeSize($dynamics, $points, $cfg);
        if ($raw <= 0.0) return 0.0;
        return RelationshipDynamics::gainPassion($npcName, $dynamics, $raw, "spike:{$source}", $tags, true);
    }

    /**
     * Add $gain spike points (gainPassion's spike path, after the attraction factor): at most
     * spike.max, and floor + spike at most passion_max. Returns the points actually added.
     */
    public static function storeSpike(array &$dynamics, float $gain, string $source): float
    {
        if ($gain <= 0.0) return 0.0;
        $cfg = self::config();
        $max = floatval(RelationshipDynamics::configValue('passion_max') ?? 100.0);
        $room = max(0.0, min(floatval($cfg['spike']['max']), $max - RelationshipDynamics::getPassion($dynamics)));
        $before = self::spike($dynamics);
        $after = min($room, $before + $gain);
        if ($after <= $before) return 0.0;
        $dynamics[self::SPIKE_KEY] = round($after, 4);
        $dynamics[self::SPIKE_TRIGGER_KEY] = $source;
        return $after - $before;
    }

    /**
     * One interaction of the player pair: the spike keeps spike.retention_per_interaction of
     * itself (below 0.01 points it is gone). Returns the spike after.
     */
    public static function decayInteraction(array &$dynamics): float
    {
        $s = self::spike($dynamics);
        if ($s <= 0.0) return 0.0;
        $s *= max(0.0, min(1.0, floatval(self::config()['spike']['retention_per_interaction'])));
        if ($s < 0.01) {
            unset($dynamics[self::SPIKE_KEY], $dynamics[self::SPIKE_TRIGGER_KEY]);
            return 0.0;
        }
        $dynamics[self::SPIKE_KEY] = round($s, 4);
        return $s;
    }

    /**
     * Absence (decisions §2, the calendar step): $absentDays game days past the passion absence
     * grace take spike.absence_fade_per_game_day x $mult (the attachment multiplier) spike points
     * each. Returns the points faded.
     */
    public static function fadeWithAbsence(array &$dynamics, float $absentDays, float $mult): float
    {
        $s = self::spike($dynamics);
        if ($s <= 0.0 || $absentDays <= 0.0) return 0.0;
        $fade = min($s, floatval(self::config()['spike']['absence_fade_per_game_day']) * $absentDays * max(0.0, $mult));
        if ($s - $fade < 0.01) {
            unset($dynamics[self::SPIKE_KEY], $dynamics[self::SPIKE_TRIGGER_KEY]);
            return $s;
        }
        $dynamics[self::SPIKE_KEY] = round($s - $fade, 6);
        return $fade;
    }

    /** Is $mood (the NPC's reply mood) a flirty moment (spike.flirty_moods)? */
    public static function isFlirtyMood(?string $mood): bool
    {
        if ($mood === null || trim($mood) === '') return false;
        return in_array(strtolower(trim($mood)), array_map('strtolower', (array) self::config()['spike']['flirty_moods']), true);
    }

    /**
     * The postrequest's spikes of one exchange with the player (the love-language gain of the
     * local classifier is separate: it feeds the floor). Observed facts spike whoever scores the
     * exchange: a flirty reply mood, a topic she warms to, intimacy the plugin reports. What the
     * local classifier judged (her primary / secondary love language, a touch) spikes only when
     * the eval does not score the exchange ($evalOwns): its item's tags spike then (onEvalItem).
     * Returns trigger => spike points added.
     */
    public static function onExchange(string $npcName, array &$dynamics, ?string $interactionLL, ?string $mood, bool $topicMatch,
                                      bool $reportedIntimacy, bool $evalOwns): array
    {
        $out = [];
        $add = function (string $trigger, ?array $tags) use ($npcName, &$dynamics, &$out): void {
            $g = self::addTrigger($npcName, $dynamics, $trigger, $tags);
            if ($g > 0.0) $out[$trigger] = round($g, 4);
        };
        if (self::isFlirtyMood($mood)) $add('flirty_mood', null);
        if ($topicMatch) $add('topic_match', null);
        $touched = $reportedIntimacy;
        if ($reportedIntimacy) $add('touch', ['touch']);
        if (!$evalOwns && $interactionLL !== null) {
            $tags = RelDynAttraction::loveLanguageChannelTags($interactionLL);
            if ($interactionLL === ($dynamics['love_language_primary'] ?? null)) $add('love_language_primary', $tags);
            elseif ($interactionLL === ($dynamics['love_language_secondary'] ?? null)) $add('love_language_secondary', $tags);
            if ($interactionLL === RelationshipDynamics::LL_TOUCH && !$touched) $add('touch', ['touch']);
        }
        return $out;
    }

    /**
     * The spikes of one applied eval item ($n normalized): the love language its tags stand for
     * when it is hers (primary / secondary), touch or intimacy (unless the game reported that
     * intimacy: the postrequest spiked it already), a rescue, and its positive passion signal x
     * spike.eval_passion_scale. $rescued: the item was the player's caring answer to her fall
     * (RelDynCombat::onEvalItem paid the MDD 3.3 rescue response for it): the tags that made it
     * caring (combat.rescue caring_tags: the rescue, the help, the reassurance, the touch) are that
     * response and trigger no moment on top of it (one care, paid once); its other tags and its
     * passion signal still do. Returns trigger => spike points added.
     */
    public static function onEvalItem(string $npcName, array $n, array &$dynamics, bool $rescued = false): array
    {
        $cfg = self::config();
        $out = [];
        $tags = array_map(fn($t) => strtolower((string) $t), (array) ($n['tags'] ?? []));
        $add = function (string $trigger, float $points) use ($npcName, &$dynamics, &$out, $tags, $cfg): void {
            $g = self::addSpike($npcName, $dynamics, $points, $trigger, $tags, $cfg);
            if ($g > 0.0) $out[$trigger] = round(($out[$trigger] ?? 0.0) + $g, 4);
        };
        $triggers = (array) $cfg['spike']['triggers'];
        // the tags that trigger a moment (the caring ones of a rescue already paid: none of them)
        $moment = $rescued ? array_values(array_diff($tags, array_map(fn($t) => strtolower((string) $t),
            (array) (RelDynCombat::rescueConfig()['caring_tags'] ?? [])))) : $tags;
        $tagLL = (array) RelationshipDynamics::configValue('affinity_tag_love_language');
        $lls = [];
        foreach ($moment as $t) {
            if (isset($tagLL[$t])) $lls[(string) $tagLL[$t]] = true;
        }
        if (isset($lls[(string) ($dynamics['love_language_primary'] ?? '')])) {
            $add('love_language_primary', floatval($triggers['love_language_primary'] ?? 0));
        } elseif (isset($lls[(string) ($dynamics['love_language_secondary'] ?? '')])) {
            $add('love_language_secondary', floatval($triggers['love_language_secondary'] ?? 0));
        }
        $reported = is_string($n['reported_intimacy'] ?? null) && $n['reported_intimacy'] !== '';
        if (!$reported && array_intersect($moment, ['touch', 'intimacy']) !== []) $add('touch', floatval($triggers['touch'] ?? 0));
        if (in_array('rescue', $moment, true)) $add('rescue', floatval($triggers['rescue'] ?? 0));
        $p = floatval($n['signals']['passion'] ?? 0);
        if ($p > 0.0) $add('eval', $p * floatval($cfg['spike']['eval_passion_scale']));
        return $out;
    }

    // =====================================================================
    // THE DESIRE LOOP
    // =====================================================================

    /** The mood's part of desire (disposition points, +- desire.valence_max). */
    public static function desireValenceTerm(array $dynamics, ?array $cfg = null): float
    {
        $c = ($cfg ?? self::config())['desire'];
        $v = $dynamics['dimensions']['valence']['x'] ?? null;
        if (!is_numeric($v)) return 0.0;
        $t = max(-1.0, min(1.0, floatval($v) / max(1e-6, floatval($c['valence_full_at']))));
        return floatval($c['valence_max']) * $t;
    }

    /**
     * The valence back-filter: a romantic move of the player's at $intent (0..romantic_intent_max)
     * moves her mood by flirt_valence[bond type] x intent / max valence points, through
     * applyDelta. Returns the valence points applied.
     */
    public static function flirtValence(string $npcName, array &$dynamics, float $intent): float
    {
        $c = self::config()['desire'];
        $max = max(1.0, floatval($c['romantic_intent_max']));
        $intent = max(0.0, min($max, $intent));
        if ($intent <= 0.0) return 0.0;
        $type = (string) RelationshipDynamics::getRelationshipType($npcName, $dynamics);
        $table = (array) $c['flirt_valence'];
        $points = floatval(array_key_exists($type, $table) ? $table[$type] : $c['flirt_valence_default']) * $intent / $max;
        // While the Ick lasts (MDD 6.3) a romantic move warms nothing, whatever the bond
        if ($points > 0.0 && !empty($dynamics['_ick_tracker']['ick_active']) && !empty(RelationshipDynamics::configValue('ick_system_enabled'))) {
            RelationshipDynamics::log(sprintf('[DESIRE] %s: a flirt from the player while the Ick lasts warms nothing', $npcName));
            return 0.0;
        }
        if (abs($points) < 1e-6) {
            RelationshipDynamics::log(sprintf('[DESIRE] %s: a flirt from the player (%s bond) is shrugged off', $npcName, $type));
            return 0.0;
        }
        $applied = RelationshipDynamics::applyDelta('valence', $dynamics, $points, $dynamics['inferred_temperament'] ?? null);
        RelationshipDynamics::log(sprintf('[DESIRE] %s: a flirt from the player (%s bond, intent %.1f) moves her mood %+.2f (applied %+.2f)',
            $npcName, $type, $intent, $points, $applied));
        return $applied;
    }

    // =====================================================================
    // DERIVED WARMTH
    // =====================================================================

    public static function derivedWarmthEnabled(): bool
    {
        return !empty(self::config()['derived_warmth_enabled']);
    }

    /** dynamics key: the rulings §8 absence fade held on derived warmth (warmth points, <= 0). */
    const WARMTH_FADE_KEY = '_warmth_fade';
    /** dynamics key: the breaking arc's closure of derived warmth ['start_gamets', 'until_gamets'] (raw gamets). */
    const BREAKING_WARMTH_KEY = '_breaking_warmth';
    /** RelDyn bond types the breaking arc does not shut out (applyBreakingArc: "toward non-bonded"). */
    const BREAKING_EXEMPT_TYPES = ['bonded', 'sworn'];

    /**
     * Warmth (0..100 points): with derived warmth on, sqrt(passion x comfort) from the effective
     * passion and comfort, per bond ($perBond: as they read toward the player,
     * getEffectiveDimensionValue; else raw), plus the states held on warmth and the weather's
     * pull, each state once (warmthColumnShadow); less the absence fade (rulings §8, fadeWarmth);
     * closed by a breaking arc toward a player who is not her bonded partner (breakingOpenness).
     * Off: the stored warmth x (null when unset).
     */
    public static function warmth(array $dynamics, bool $perBond = true, ?string $relationshipType = null): ?float
    {
        if (!self::derivedWarmthEnabled()) {
            $x = $dynamics['dimensions']['warmth']['x'] ?? null;
            if (!is_numeric($x)) return null;
            return $perBond ? RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'warmth', null, $relationshipType) : floatval($x);
        }
        $w = self::openness($dynamics, $perBond, $relationshipType) + floatval($dynamics[self::WARMTH_FADE_KEY] ?? 0.0);
        return max(0.0, min(100.0, $w)) * self::breakingOpenness($dynamics);
    }

    /**
     * Derived warmth before the absence fade and the breaking arc (points 0..100): the root of the
     * passion and comfort she has with the held states that carry no warmth column of their own,
     * plus the warmth columns of those that do (the tables were written for a stand-alone warmth:
     * a grief's -10, the weather's -3 reach warmth at that value, once).
     */
    private static function openness(array $dynamics, bool $perBond, ?string $relationshipType): float
    {
        [$shadowComfort, $shadowPassion] = self::warmthColumnShadow($dynamics);
        $max = floatval(RelationshipDynamics::configValue('passion_max') ?? 100.0);
        $comfort = self::comfortOwn($dynamics);
        $passion = max(0.0, min($max, RelationshipDynamics::getPassion($dynamics) + self::spike($dynamics)
            + RelationshipDynamics::weatherGravityOffset($dynamics, 'passion') - $shadowPassion));
        if ($perBond) {
            $passion = RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'passion', $passion, $relationshipType) ?? $passion;
            $comfort = RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'comfort', $comfort, $relationshipType) ?? $comfort;
        }
        $comfort += RelationshipDynamics::heldTemporaryOffset($dynamics, 'comfort') - $shadowComfort;
        return self::derive($dynamics, $passion, $comfort, true);
    }

    /**
     * The comfort and passion points held by states that carry a warmth column of their own
     * (heldTemporarySources; the weather's pull when its node names warmth): [comfort, passion].
     * Derived warmth leaves them out of the root, the warmth column stands for them.
     */
    private static function warmthColumnShadow(array $dynamics): array
    {
        $comfort = 0.0;
        $passion = 0.0;
        foreach (RelationshipDynamics::heldTemporarySources($dynamics) as $source) {
            if (abs(floatval($source['warmth'] ?? 0.0)) < 1e-9) continue;
            $comfort += floatval($source['comfort'] ?? 0.0);
            $passion += floatval($source['passion'] ?? 0.0);
        }
        if (abs(RelationshipDynamics::weatherGravityOffset($dynamics, 'warmth')) >= 1e-9) {
            $passion += RelationshipDynamics::weatherGravityOffset($dynamics, 'passion');
        }
        return [$comfort, $passion];
    }

    /**
     * The breaking arc's closure (0..1, unitless): 0 at the arc toward a player who is not her
     * bonded / sworn partner, opening linearly to 1 over breaking_warmth_reopen_game_days of the
     * game calendar; 1 without an arc, for her bonded partner, or before the arc (a load).
     */
    public static function breakingOpenness(array $dynamics): float
    {
        $b = $dynamics[self::BREAKING_WARMTH_KEY] ?? null;
        if (!is_array($b)) return 1.0;
        if (in_array(RelationshipDynamics::getRelationshipType('', $dynamics), self::BREAKING_EXEMPT_TYPES, true)) return 1.0;
        $start = floatval($b['start_gamets'] ?? 0);   // raw gamets
        $until = floatval($b['until_gamets'] ?? 0);   // raw gamets
        $now = RelDynProtocols::calendarNow($dynamics);
        if ($start <= 0 || $until <= $start || $now < $start) return 1.0;
        return max(0.0, min(1.0, ($now - $start) / ($until - $start)));
    }

    /** The breaking arc at raw gamets $now closes derived warmth (applyBreakingArc; read by breakingOpenness). */
    public static function closeWarmthForBreaking(array &$dynamics, float $now): void
    {
        if ($now <= 0) return;
        $days = max(0.0, floatval(self::config()['breaking_warmth_reopen_game_days']));
        $dynamics[self::BREAKING_WARMTH_KEY] = ['start_gamets' => $now, 'until_gamets' => $now + $days * RelationshipDynamics::GAMETS_PER_DAY];
    }

    /**
     * Rulings §8 on derived warmth (the calendar step): $absentDays game days past the warmth
     * absence grace (x the NPC's grace_mult) fade what she shows by at least
     * warmth_absence_fade_per_game_day x $absentDays x $rateMult (getNeglectProfile's rate_mult):
     * the passion fade of the same step already closed the root by ($before - now), the rest is
     * held as the fade (WARMTH_FADE_KEY), never taking her below where her warmth rests
     * (warmthBaseline). $before: rawOpenness before this step's passion fade. Returns the warmth
     * points newly held.
     */
    public static function fadeWarmth(array &$dynamics, float $before, float $absentDays, float $rateMult): float
    {
        if (!self::derivedWarmthEnabled() || $absentDays <= 0.0) return 0.0;
        $now = self::openness($dynamics, false, null);
        $target = floatval(RelationshipDynamics::configValue('warmth_absence_fade_per_game_day')) * $absentDays * max(0.0, $rateMult);
        $extra = max(0.0, $target - max(0.0, $before - $now));
        $old = min(0.0, floatval($dynamics[self::WARMTH_FADE_KEY] ?? 0.0));
        $floor = min(0.0, floatval(self::warmthBaseline($dynamics, false)) - $now);   // never below where she rests
        $new = min($old, max($floor, $old - $extra));
        if ($new > -1e-6) {
            unset($dynamics[self::WARMTH_FADE_KEY]);
        } else {
            $dynamics[self::WARMTH_FADE_KEY] = round($new, 6);
        }
        return $old - $new;
    }

    /** Raw openness now (points, no per-bond reading, no fade, no arc): fadeWarmth's reference before a calendar step. */
    public static function rawOpenness(array $dynamics): float
    {
        return self::openness($dynamics, false, null);
    }

    /** A positive interaction gives back warmth_fade_regain_per_positive of the absence fade (contact heals, decisions §2). */
    public static function regainWarmthFade(array &$dynamics): void
    {
        $old = floatval($dynamics[self::WARMTH_FADE_KEY] ?? 0.0);
        if ($old >= 0.0) return;
        $new = min(0.0, $old + max(0.0, floatval(self::config()['warmth_fade_regain_per_positive'])));
        if ($new > -1e-6) unset($dynamics[self::WARMTH_FADE_KEY]); else $dynamics[self::WARMTH_FADE_KEY] = round($new, 6);
    }

    /**
     * Where derived warmth rests (0..100 points): the same formula at the passion stage floor and
     * the comfort baseline, without the states held on warmth or the weather's pull (they are now,
     * not who she is). The felt bands measure how far warmth sits from it.
     */
    public static function warmthBaseline(array $dynamics, bool $perBond = true, ?string $relationshipType = null): ?float
    {
        if (!self::derivedWarmthEnabled()) {
            $b = $dynamics['dimensions']['warmth']['baseline'] ?? null;
            if (!is_numeric($b)) return null;
            return $perBond ? RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'warmth', floatval($b), $relationshipType) : floatval($b);
        }
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $cb = $dynamics['dimensions']['comfort']['baseline'] ?? null;
        $comfort = is_numeric($cb) ? floatval($cb) : RelationshipDynamics::getTemperamentBaseline($temperament, 'comfort', $dynamics);
        $passion = RelationshipDynamics::passionStageFloor($dynamics);
        if ($perBond) {
            $passion = RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'passion', $passion, $relationshipType) ?? $passion;
            $comfort = RelationshipDynamics::getEffectiveDimensionValue($dynamics, 'comfort', $comfort, $relationshipType) ?? $comfort;
        }
        return self::derive($dynamics, $passion, $comfort, false);
    }

    /** Her comfort without the states held on it (points; the baseline when unset). */
    private static function comfortOwn(array $dynamics): float
    {
        $x = $dynamics['dimensions']['comfort']['x'] ?? null;
        if (!is_numeric($x)) {
            $cb = $dynamics['dimensions']['comfort']['baseline'] ?? null;
            return is_numeric($cb) ? floatval($cb)
                : RelationshipDynamics::getTemperamentBaseline($dynamics['inferred_temperament'] ?? null, 'comfort', $dynamics);
        }
        return floatval($x) - RelationshipDynamics::heldTemporaryOffset($dynamics, 'comfort');
    }

    /** sqrt(passion x comfort) (+ the states held on warmth and the weather's pull: $withHeld), 0..100. */
    private static function derive(array $dynamics, float $passion, float $comfort, bool $withHeld): float
    {
        $core = sqrt(max(0.0, $passion) * max(0.0, $comfort));
        if (!$withHeld) return max(0.0, min(100.0, $core));
        $held = RelationshipDynamics::heldTemporaryOffset($dynamics, 'warmth') + RelationshipDynamics::heldBaselineOffset($dynamics, 'warmth')
            + RelationshipDynamics::weatherGravityOffset($dynamics, 'warmth');
        return max(0.0, min(100.0, $core + $held));
    }
}
