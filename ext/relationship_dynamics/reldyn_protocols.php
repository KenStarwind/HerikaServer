<?php
/**
 * Relationship Dynamics — the rare and the corrosive protocols (roadmap P3 "wire existing"):
 * Divine Intervention, death and grief with the widow's lock, the Ick's tuning, the Parasite.
 *
 * DIVINE INTERVENTION (dimension design "Divine Intervention"): a catastrophic event bypasses
 * applyDelta. The fork (RelationshipDynamics::calculateAnchorStatus) reads the NPC's bonds at
 * the moment of the event; the dead never anchor anyone (the deceased of a companion death and
 * every bond the NPC grieves are left out). Anchored -> redemption, alone -> breaking, neither
 * -> the unstable window: divine.unstable_window_game_hours of the GAME CALENDAR (the design's
 * "24 game-hour unstable state"; decisions §2: waiting is time passing in the world, the same
 * ruling that moved the hoover's IRL timer onto the calendar). Whoever shows up with a bond
 * strong enough anchors her: the player on their own turn (prerequest), or any NPC around when
 * she speaks (core's CACHE_PEOPLE, read by the context hook: "presence, not words"). Nobody by
 * the end of the window: breaking, applied by the calendar step even while the player stays
 * away. The resolution is said once (a one-shot felt line); the arc's own keywords stand while
 * its plasticity override (30 game days) holds. Betrayal (the design's "trust drops 50+ in one
 * event") is an applied eval item tagged betrayal by a bonded partner (divine.betrayal): the
 * eval contract's trust signal tops out at 30, so the old "trust <= -50" test never fired. The
 * partner who betrayed her anchors nothing: the player is left out of that fork and window.
 *
 * DEATH, GRIEF, WIDOW'S LOCK (dimension design "Death, Grief, and Moving On"). A core 'death'
 * row (RelDynCombat: "X has defeated Y") -> every RelDyn NPC around (the row's people and
 * party) whose core relationship to the deceased is at least grief.witness_min_core_affinity
 * (core units) grieves. Once per survivor and deceased. Phases on the game calendar
 * (grief.phase_game_hours: the design's 48 h / 1 week / 4 weeks) x the bond-duration weight
 * min(2, hours_bonded / 100) (floored at weight_min), hours_bonded = the play hours the bond has
 * been in the player's world (bondHours). Effects:
 *   acute (1)        comfort -15 and warmth -10 toward every other bond, held (the held
 *                    temporary offsets: lifted exactly, never who she is) through bargaining,
 *                    lifting across integration; valence locked at or below acute.valence_max;
 *   bargaining (2)   trust -5 once ("the world takes people from me"); the memory of the deceased
 *                    idealized (memory warmth to its peak);
 *   integration (3)  the memory's warmth settles from the peak toward its memorial level, other
 *                    bonds recover (the held offsets lift);
 *   carrying (4)     a memorial bond: memory warmth held at 40..60 by how close they were.
 * The widow's lock only for a partner (grief.widow_lock: the RelDyn bond types of a spouse,
 * family, sworn, or core affinity at the bonded tier): new bonds are capped at
 * 100 - weight x 20 CORE affinity points (the design's tiers: a long-bond widow tops out at
 * close_friend, never bonded or devoted), enforced where RelDyn writes Player.aff.
 * How grief shows is hers: maturity (quiet grief / public breakdown, the design) and the
 * attachment axes (avoidance copes through action and will not talk about it, decisions §12's
 * Aela; anxiety holds on harder to whoever is still here). Felt text only.
 *
 * THE ICK (MDD 6.3; RelationshipDynamics ICK section): tuning read from config 'protocols.ick'.
 * Personality: the trigger threshold is lowered by the avoidance axis (dimension design
 * Attachment table: avoidant "suffocation threshold (ick) lowered"), on top of the maturity term.
 * Effects per continued attempt: comfort -3 and resentment +5 (roadmap), passion gains invert
 * (every channel, MDD "passion multiplier INVERTS"). Recovery: she is at ease again (comfort above
 * the floor), passion has stopped being pushed (at or above its floor, or the player stopped
 * pressing for ick.recovery_quiet_interactions interactions) and resentment is low or was said
 * calmly. The trigger is stamped with the exchange's game time and the play clock, never the wall
 * clock.
 *
 * PARASITE (MDD 6.2 "Transactional. Accelerated Passion decay (2-hour half-life). Must bleed
 * resources constantly."; dimension design: "gifts without genuine interaction"). A rolling
 * ledger of the last parasite.window exchanges with the player, one entry per exchange (its game
 * time): 'gift' (a gift seen by core's eventlog or the request, or an eval item tagged gift with
 * nothing genuine), 'genuine' (an eval item with a genuine tag, or a positive exchange the eval
 * did not score and no gift), 'other'. Gifts above the NPC's ratio with fewer than genuine_below
 * genuine exchanges -> the parasite type (RelationshipDynamics::getRelationshipType override):
 * passion halves every parasite.passion_half_life_game_hours of game time, gifts keep her warm
 * only while they flow. Who turns transactional is hers: warmth (W) raises the ratio it takes,
 * egocentric pride lowers it (bounded, Serene's slopes). Recovery: genuine exchanges back.
 *
 * Units: affinity in CORE points (-100..100) unless named mirror (0..100 = (core + 100) / 2);
 * dimensions in points 0..100 (valence -100..100); game time in raw gamets (1 game day =
 * RelationshipDynamics::GAMETS_PER_DAY, 1 game hour = a 24th of it); play time on the play clock
 * (_accumulated_play_gamets; _accumulated_time = play seconds). No wall clock.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynProtocols
{
    /** Held grief offsets: dimension => points applied now (heldTemporaryOffset). */
    const GRIEF_HELD_KEY = '_grief_held';
    /** One-shot: how the crisis window ended, said on the player's next turn. */
    const CRISIS_SAY_KEY = '_crisis_say';
    /** Parasite exchange ledger (in the April key, reshaped): ['v', 'recent' => [[gamets, kind]], counts]. */
    const LEDGER_KEY = '_interaction_pattern';
    const LEDGER_VERSION = 2;
    /** Parasite passion half-life checkpoint (raw gamets). */
    const PARASITE_CLOCK_KEY = '_parasite_passion_gamets';

    const KIND_GIFT = 'gift';
    const KIND_GENUINE = 'genuine';
    const KIND_OTHER = 'other';

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'protocols' (a stored config replaces settings per section).
     * Design numbers unless marked Serene's.
     */
    public static function configDefaults(): array
    {
        return [
            'divine' => [
                // Dimension design: "a 24 game-hour unstable state" (game calendar, decisions §2)
                'unstable_window_game_hours' => 24.0,
                // The fork (dimension design), mirror affinity 0..100; trust points: the player's
                // bond counts the NPC's trust, an NPC bond counts mirror affinity x npc_trust_per_affinity
                'anchor_total_trust_above' => 100.0,
                'anchor_max_affinity_above' => 60.0,
                'alone_total_trust_below' => 50.0,
                'alone_max_affinity_below' => 40.0,
                'npc_trust_per_affinity' => 0.5,
                // Who can anchor the window (dimension design: "affinity > 50 AND trust > 40")
                'window_anchor_affinity_above' => 50.0,
                'window_anchor_trust_above' => 40.0,
                // Betrayal by a bonded partner: an applied eval item carrying the tag, at least this
                // significant, its trust signal at or below trust_raw_at_most (eval contract points,
                // -30..30), in a bond of one of these RelDyn types (Serene's reading of "trust drops
                // 50+ in one event", which the contract cannot express)
                'betrayal_tag' => 'betrayal',
                'betrayal_min_significance' => 0.6,
                'betrayal_trust_raw_at_most' => -10.0,
                'betrayal_bond_types' => ['bonded', 'sworn', 'crush'],
                'betrayal_severity' => 4,
            ],
            'grief' => [
                // Witnesses grieve at this core affinity toward the deceased or more (Serene: the
                // design's "bond above 30", read on core's scale = RelDyn's friend tier)
                'witness_min_core_affinity' => 31,
                // Dimension design phases (48 h, 1 week, 4 weeks) in game hours from the death
                'phase_game_hours' => [2 => 48.0, 3 => 168.0, 4 => 672.0],
                // bond_duration_weight = clamp(hours_bonded / weight_hours, weight_min, weight_max)
                'weight_hours' => 100.0,
                'weight_min' => 0.1,
                'weight_max' => 2.0,
                // Acute grief toward every other bond (points), held through bargaining
                'acute_comfort' => -15.0,
                'acute_warmth' => -10.0,
                // "Arousal locked to negative valence": valence points held at or below this
                'acute_valence_max' => -30.0,
                // Bargaining: "trust -5 globally"
                'bargaining_trust' => -5.0,
                // The memory: idealized to the peak in bargaining, a memorial held at memorial_min..max
                // (by how close they were at the death: core affinity 0..100 across the band)
                'memory_warmth_peak' => 100.0,
                'memorial_warmth_min' => 40.0,
                'memorial_warmth_max' => 60.0,
                // Widow's lock: ceiling = 100 - weight x lock_per_weight core points, for a partner:
                // the bond's RelDyn type is one of these, or core affinity at least min_core_affinity
                'lock_per_weight' => 20.0,
                'widow_lock_types' => ['bonded', 'sworn'],
                'widow_lock_min_core_affinity' => 76,
                // How grief shows (felt text): maturity at or above quiet_maturity grieves quietly;
                // an attachment axis at or above coping_axis_at (and the higher of the two) colours
                // it (Serene's numbers)
                'quiet_maturity' => 60.0,
                'coping_axis_at' => 0.35,
                'felt' => [
                    1 => [
                        'quiet'  => '{NAME} carries the loss of {DECEASED} in silence: still water over something devastating, withdrawn, numbly going through the motions',
                        'public' => '{NAME} is shattered by the loss of {DECEASED}: raw, visibly breaking down, unable to hold her composure, flinching when the name is spoken',
                    ],
                    2 => [
                        'quiet'  => '{NAME} speaks of {DECEASED} as if they might walk back in, remembering only the good, a quiet bargaining with fate',
                        'public' => '{NAME} swings between desperate hope and crushing reality about {DECEASED}, talks about them constantly, keeps their things exactly as they left them',
                    ],
                    3 => [
                        'quiet'  => '{NAME} is making peace with the absence of {DECEASED}; past tense more often, a bittersweet warmth when the name comes up',
                        'public' => '{NAME} is slowly finding her footing after losing {DECEASED}; good moments broken by sudden waves of loss',
                    ],
                    4 => [
                        'quiet'  => '{NAME} carries the memory of {DECEASED} as part of who she is now: a memorial, not a wound, a quiet smile instead of tears',
                        'public' => '{NAME} carries the memory of {DECEASED} as part of who she is now: a memorial, not a wound, a quiet smile instead of tears',
                    ],
                    'coping' => [
                        'action' => 'she copes by doing, not talking: throws herself into the hunt and the work and turns aside any question about it',
                        'cling'  => 'she holds on harder to the people still here, afraid of losing one more',
                    ],
                ],
            ],
            'ick' => [
                // MDD 6.3 receptivity floors (dimension design): comfort < 40 AND (passion < 20 OR warmth < 30)
                'comfort_floor' => (float) RelationshipDynamics::ICK_COMFORT_FLOOR,
                'passion_floor' => (float) RelationshipDynamics::ICK_PASSION_FLOOR,
                'warmth_floor' => (float) RelationshipDynamics::ICK_WARMTH_FLOOR,
                // Roadmap: per continued attempt comfort -3, resentment +5 (points, through applyDelta)
                'comfort_per_attempt' => (float) RelationshipDynamics::ICK_COMFORT_OVERRIDE,
                'resentment_per_attempt' => (float) RelationshipDynamics::ICK_RESENTMENT_PER_ATTEMPT,
                // Dimension design attachment table: avoidant "suffocation threshold lowered".
                // threshold x (1 - drop x avoidance share), share = 0 at prototype low .. 1 at high
                // (Serene's number)
                'avoidance_threshold_drop' => 0.3,
                // Recovery (dimension design): comfort above the floor again, passion stabilized (at
                // or above passion_floor, or this many counted interactions without an attempt:
                // Serene's), resentment below 20 or a calm confrontation
                'recovery_comfort_above' => (float) RelationshipDynamics::ICK_RECOVERY['comfort'],
                'recovery_quiet_interactions' => (int) RelationshipDynamics::ICK_RECOVERY['quiet'],
                'recovery_resentment_below' => (float) RelationshipDynamics::ICK_RECOVERY['resentment'],
            ],
            'parasite' => [
                // Rolling ledger of the last `window` exchanges; judged from min_exchanges on
                // (the April numbers)
                'window' => 20,
                'min_exchanges' => 10,
                'gift_ratio_above' => 0.7,
                'genuine_below' => 3,
                'recover_genuine_at_least' => 3,
                // MDD 6.2: 2-hour half-life (game time)
                'passion_half_life_game_hours' => 2.0,
                // Eval tags that make an exchange genuine (a gift given in a real moment is not a purchase)
                'genuine_tags' => ['quality_time', 'praise', 'help', 'rescue', 'confiding', 'confession',
                                   'reassurance', 'apology', 'touch', 'intimacy', 'competence'],
                // Personality (Serene's): ratio + warmth_slope x (W - 0.5) - egocentric_slope x
                // egocentric(Pd), clamped ratio_min..ratio_max; no trait vector = the plain ratio
                'warmth_slope' => 0.2,
                'egocentric_slope' => 0.15,
                'ratio_min' => 0.5,
                'ratio_max' => 0.9,
                // (its felt line is felt_steering.text.parasite, reldyn_felt.php)
            ],
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('protocols');
        if (!is_array($stored)) return $defaults;
        foreach ($defaults as $section => $values) {
            if (is_array($stored[$section] ?? null)) $defaults[$section] = array_replace($values, $stored[$section]);
        }
        return $defaults;
    }

    /** Raw game time now: the game clock, else the NPC's last game stamp (raw gamets). */
    public static function calendarNow(array $dynamics): float
    {
        $now = RelationshipDynamics::currentGamets();
        return $now > 0 ? $now : floatval($dynamics['_last_gamets'] ?? 0);
    }

    private static function gametsPerGameHour(): float
    {
        return RelationshipDynamics::GAMETS_PER_DAY / 24.0;
    }

    /** The key of $name in a bond map, case-insensitively; null when absent. */
    public static function bondKey(array $bonds, string $name): ?string
    {
        if (isset($bonds[$name])) return $name;
        foreach (array_keys($bonds) as $k) {
            if (strcasecmp((string) $k, trim($name)) === 0) return (string) $k;
        }
        return null;
    }

    /** Names this NPC grieves (the dead: they anchor no one). */
    public static function deadNames(array $dynamics): array
    {
        return is_array($dynamics['_grief_bonds'] ?? null) ? array_map('strval', array_keys($dynamics['_grief_bonds'])) : [];
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    // =====================================================================
    // DEATH AND GRIEF
    // =====================================================================

    /**
     * A core death row (RelDynCombat::route): every RelDyn NPC among $candidates (the row's people
     * and party) with a core bond of at least witness_min_core_affinity to $deceased grieves, once.
     * $at: the death's raw game time. Returns the survivors registered now.
     */
    public static function onDeath(string $deceased, array $candidates, float $at, string $player): array
    {
        $deceased = trim($deceased);
        if ($deceased === '' || strcasecmp($deceased, $player) === 0 || !RelationshipDynamics::configValue('grief_system_enabled')) return [];
        $min = floatval(self::config()['grief']['witness_min_core_affinity']);
        $out = [];
        foreach (array_unique($candidates) as $survivor) {
            $survivor = trim((string) $survivor);
            if ($survivor === '' || strcasecmp($survivor, $deceased) === 0 || strcasecmp($survivor, $player) === 0) continue;
            $bonds = RelationshipDynamics::getAllBondsForNpc($survivor);
            $key = self::bondKey($bonds, $deceased);
            if ($key === null) continue;
            if (floatval($bonds[$key]['aff']) < $min) {
                RelationshipDynamics::log("[GRIEF] {$survivor}: bond to {$deceased} below the witness threshold, no grief");
                continue;
            }
            $d = RelationshipDynamics::getDynamics($survivor);
            if (empty($d['love_language_primary'])) continue;   // not a RelDyn NPC yet
            if (isset($d['_grief_bonds'][$key])) {
                RelationshipDynamics::log("[GRIEF] {$survivor}: the death of {$key} is already registered");
                continue;
            }
            RelationshipDynamics::onNpcDeath($key, $survivor, $d, $at);
            RelationshipDynamics::saveDynamics($survivor, $d);
            $out[] = $survivor;
        }
        return $out;
    }

    /**
     * Play hours the bond between $survivor and $deceased has been in the player's world: the
     * shorter of the times the player has known each (_accumulated_time, play seconds), or the
     * survivor's alone when the deceased was never a RelDyn NPC. A bond from before the player
     * met either is not seen (open question: lore bonds).
     */
    public static function bondHours(array $survivorDynamics, string $deceased): float
    {
        $mine = max(0.0, floatval($survivorDynamics['_accumulated_time'] ?? 0)) / 3600.0;
        $theirs = RelationshipDynamics::loadStoredDynamics($deceased);
        if (is_array($theirs) && is_numeric($theirs['_accumulated_time'] ?? null)) {
            return min($mine, max(0.0, floatval($theirs['_accumulated_time'])) / 3600.0);
        }
        return $mine;
    }

    /** bond_duration_weight (dimension design), bounded by weight_min..weight_max. */
    public static function griefWeight(float $bondHours, ?array $cfg = null): float
    {
        $g = ($cfg ?? self::config())['grief'];
        return self::clamp($bondHours / max(1e-9, floatval($g['weight_hours'])), floatval($g['weight_min']), floatval($g['weight_max']));
    }

    /** Does the widow's lock apply to a bond ['aff' => core, 'type' => core type]? */
    public static function widowLockApplies(array $bond, ?array $cfg = null): bool
    {
        $g = ($cfg ?? self::config())['grief'];
        $mapped = RelationshipDynamics::CORE_TYPE_TO_RELDYN_TYPE[strtolower((string) ($bond['type'] ?? ''))] ?? null;
        return ($mapped !== null && in_array($mapped, (array) $g['widow_lock_types'], true))
            || floatval($bond['aff'] ?? 0) >= floatval($g['widow_lock_min_core_affinity']);
    }

    /** Game hours at which $phase (2..4) starts for a bond of weight $weight. */
    public static function phaseStartHours(int $phase, float $weight, ?array $cfg = null): float
    {
        if ($phase <= 1) return 0.0;
        $hours = (array) (($cfg ?? self::config())['grief']['phase_game_hours']);
        return floatval($hours[$phase] ?? $hours[(string) $phase] ?? 0.0) * $weight;
    }

    /** The phase at $hours game hours after the death, and 0..1 through it (phase 4: 1). */
    public static function phaseAt(float $hours, float $weight, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $phase = 1;
        foreach ([2, 3, 4] as $p) {
            if ($hours >= self::phaseStartHours($p, $weight, $cfg)) $phase = $p;
        }
        if ($phase === 4) return [4, 1.0];
        $a = self::phaseStartHours($phase, $weight, $cfg);
        $b = self::phaseStartHours($phase + 1, $weight, $cfg);
        return [$phase, $b > $a ? self::clamp(($hours - $a) / ($b - $a), 0.0, 1.0) : 1.0];
    }

    /** The memorial warmth (points) of a bond as close as $coreAffAtDeath (core points). */
    public static function memorialWarmth(float $coreAffAtDeath, ?array $cfg = null): float
    {
        $g = ($cfg ?? self::config())['grief'];
        $lo = floatval($g['memorial_warmth_min']);
        $hi = floatval($g['memorial_warmth_max']);
        return $lo + ($hi - $lo) * self::clamp($coreAffAtDeath / 100.0, 0.0, 1.0);
    }

    /**
     * Move every grief bond to its phase at game time $now (raw gamets; one-shots applied in
     * order), the memory's warmth along it, the acute offsets toward their target and the valence
     * lock. Nothing when the clock is unknown or $now is before a death (an earlier save).
     * Returns true when anything changed.
     */
    public static function tickGrief(string $npc, array &$dynamics, float $now): bool
    {
        $griefs = is_array($dynamics['_grief_bonds'] ?? null) ? $dynamics['_grief_bonds'] : [];
        if (($griefs === [] && empty($dynamics[self::GRIEF_HELD_KEY])) || $now <= 0) return false;
        $cfg = self::config();
        $g = $cfg['grief'];
        $perHour = self::gametsPerGameHour();
        $changed = false;
        $lift = 0.0;   // 0..1 of the acute offsets held now (the strongest grief)
        $acute = false;
        foreach ($griefs as $deceased => $grief) {
            if (!is_array($grief)) continue;
            // Legacy entries (April play-clock stamps): start fresh on the calendar at their phase
            if (($grief['clock'] ?? null) !== 'calendar') {
                $weight = self::griefWeight(floatval($grief['bond_duration_hours'] ?? 0), $cfg);
                $grief['clock'] = 'calendar';
                $grief['death_gamets'] = $now - self::phaseStartHours(intval($grief['phase'] ?? 1), $weight, $cfg) * $perHour;
                $changed = true;
            }
            $death = floatval($grief['death_gamets'] ?? 0);
            if ($now < $death) continue;   // an earlier save: this death has not happened here yet
            $weight = self::griefWeight(floatval($grief['bond_duration_hours'] ?? 0), $cfg);
            [$phase, $progress] = self::phaseAt(($now - $death) / $perHour, $weight, $cfg);
            $current = intval($grief['phase'] ?? 1);
            $dynamics['_grief_bonds'][$deceased] = $grief;
            for ($p = $current + 1; $p <= $phase; $p++) {
                $dynamics['_grief_bonds'][$deceased]['phase'] = $p;
                $dynamics['_grief_bonds'][$deceased]['phase_transitions'][$p] = $now;
                RelationshipDynamics::applyGriefPhaseOnce($npc, (string) $deceased, $p, $dynamics);
                $changed = true;
            }
            $grief = $dynamics['_grief_bonds'][$deceased];
            // The memory: idealized, then settling to its memorial level
            $peak = floatval($g['memory_warmth_peak']);
            $memorial = self::memorialWarmth(floatval($grief['core_affinity_at_death'] ?? (floatval($grief['bond_affinity_at_death'] ?? 50) * 2 - 100)), $cfg);
            $memory = $phase <= 1 ? null : ($phase === 2 ? $peak : ($phase === 3 ? $peak - ($peak - $memorial) * $progress : $memorial));
            if ($memory !== null && abs(floatval($grief['memory_warmth'] ?? -1) - $memory) > 1e-6) {
                $dynamics['_grief_bonds'][$deceased]['memory_warmth'] = round($memory, 4);
                $dynamics['_grief_bonds'][$deceased]['bond_type'] = $phase === 4 ? 'memorial' : 'grieving';
                $changed = true;
            }
            $lift = max($lift, $phase <= 2 ? 1.0 : ($phase === 3 ? 1.0 - $progress : 0.0));
            if ($phase === 1) $acute = true;
        }
        // Acute offsets toward every other bond, held (never who she is), lifted exactly
        $held = is_array($dynamics[self::GRIEF_HELD_KEY] ?? null) ? $dynamics[self::GRIEF_HELD_KEY] : [];
        foreach (['comfort' => floatval($g['acute_comfort']), 'warmth' => floatval($g['acute_warmth'])] as $dim => $full) {
            if (!is_numeric($dynamics['dimensions'][$dim]['x'] ?? null)) continue;
            $target = $full * $lift;
            $applied = floatval($held[$dim] ?? 0.0);
            $delta = $target - $applied;
            if (abs($delta) < 1e-6) continue;
            $x = floatval($dynamics['dimensions'][$dim]['x']);
            $new = self::clamp($x + $delta, 0.0, 100.0);
            $dynamics['dimensions'][$dim]['x'] = round($new, 4);
            $held[$dim] = round($applied + ($new - $x), 6);
            if ($lift <= 0.0 && abs($held[$dim]) < 1e-4) $held[$dim] = 0.0;
            $changed = true;
        }
        $held = array_filter($held, fn($v) => abs(floatval($v)) > 0.0);
        if ($held === []) unset($dynamics[self::GRIEF_HELD_KEY]); else $dynamics[self::GRIEF_HELD_KEY] = $held;
        // "Arousal locked to negative valence" while acute
        if ($acute && is_numeric($dynamics['dimensions']['valence']['x'] ?? null)
            && floatval($dynamics['dimensions']['valence']['x']) > floatval($g['acute_valence_max'])) {
            $dynamics['dimensions']['valence']['x'] = floatval($g['acute_valence_max']);
            $changed = true;
        }
        if ($changed) RelationshipDynamics::log("[GRIEF] {$npc}: " . json_encode(array_map(fn($x) => is_array($x)
            ? ['phase' => $x['phase'] ?? null, 'memory' => $x['memory_warmth'] ?? null] : null, (array) ($dynamics['_grief_bonds'] ?? [])))
            . ' held ' . json_encode($dynamics[self::GRIEF_HELD_KEY] ?? []));
        return $changed;
    }

    /** How the NPC copes (felt text): 'action' (avoidance), 'cling' (anxiety) or 'plain'. */
    public static function copingStyle(array $dynamics, ?array $cfg = null): string
    {
        $at = floatval((($cfg ?? self::config())['grief'])['coping_axis_at']);
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        $av = floatval($axes['avoidance']);
        $an = floatval($axes['anxiety']);
        if ($av >= $at && $av >= $an) return 'action';
        if ($an >= $at && $an > $av) return 'cling';
        return 'plain';
    }

    /**
     * Felt lines for the two strongest losses: ['key' => "grief_<deceased>", 'text' => ...], by
     * phase, maturity (quiet / public) and coping style. Feelings, never numbers.
     */
    public static function griefFeltLines(string $npc, array $dynamics): array
    {
        $griefs = is_array($dynamics['_grief_bonds'] ?? null) ? $dynamics['_grief_bonds'] : [];
        if ($griefs === []) return [];
        $cfg = self::config();
        $g = $cfg['grief'];
        uasort($griefs, fn($a, $b) => floatval($b['bond_affinity_at_death'] ?? 0) <=> floatval($a['bond_affinity_at_death'] ?? 0));
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $voice = $maturity >= floatval($g['quiet_maturity']) ? 'quiet' : 'public';
        $coping = self::copingStyle($dynamics, $cfg);
        $out = [];
        foreach (array_slice($griefs, 0, 2, true) as $deceased => $grief) {
            $out[] = ['key' => "grief_{$deceased}", 'text' => self::griefText($npc, (string) $deceased, intval($grief['phase'] ?? 1), $voice, $coping, $cfg)];
        }
        return $out;
    }

    /** One grief line (phase 1..4, voice quiet|public, coping action|cling|plain). */
    public static function griefText(string $npc, string $deceased, int $phase, string $voice, string $coping, ?array $cfg = null): string
    {
        $felt = (array) (($cfg ?? self::config())['grief']['felt']);
        $row = (array) ($felt[$phase] ?? $felt[(string) $phase] ?? []);
        $text = (string) ($row[$voice] ?? '');
        if ($text === '') return '';
        $extra = $phase < 4 ? (string) (((array) ($felt['coping'] ?? []))[$coping] ?? '') : '';
        if ($extra !== '') $text .= '; ' . $extra;
        return strtr($text, ['{NAME}' => $npc, '{DECEASED}' => $deceased]);
    }

    // =====================================================================
    // DIVINE INTERVENTION: THE WINDOW, THE ARC, BETRAYAL
    // =====================================================================

    /**
     * The context hook's crisis turn: an open window may be resolved by who is around ($people:
     * core's CACHE_PEOPLE names, the player among them when present); then the felt lines: the
     * crisis narration while the window is open, the resolution once (on the player's turn), the
     * arc's standing keywords while its override holds. Lines: ['key', 'text', 'turn' => bool].
     */
    public static function crisisTurn(string $npc, array &$dynamics, array $people, bool $playerAddressed): array
    {
        $rd = RelationshipDynamics::getConfig();
        if (empty($rd['divine_intervention_enabled'])) return ['lines' => [], 'changed' => false];
        $lines = [];
        $changed = false;
        $window = $dynamics['_unstable_window'] ?? null;
        if (is_array($window) && empty($window['resolved'])) {
            $r = RelationshipDynamics::checkUnstableWindow($npc, $dynamics, null, $people);
            if ($r !== null && $r !== 'active') $changed = true;
            $window = $dynamics['_unstable_window'];
            if (empty($window['resolved'])) {
                $now = self::calendarNow($dynamics);
                $duration = floatval($window['duration_gamets'] ?? 0);
                $window['_elapsed_fraction'] = $duration > 0 ? self::clamp(($now - floatval($window['start_gamets'] ?? $now)) / $duration, 0.0, 1.0) : 0.0;
                $text = RelationshipDynamics::generateCrisisNarration($npc, $window);
                if ($text !== '') $lines[] = ['key' => 'crisis', 'text' => $text, 'turn' => false];
            }
        }
        $say = $dynamics[self::CRISIS_SAY_KEY] ?? null;
        if (is_array($say) && $playerAddressed) {
            $text = self::resolutionText($npc, $say);
            if ($text !== '') $lines[] = ['key' => 'crisis_resolved', 'text' => $text, 'turn' => true];
            unset($dynamics[self::CRISIS_SAY_KEY]);
            $changed = true;
        }
        $arc = self::arcText($npc, $dynamics);
        if ($arc !== '') $lines[] = ['key' => 'arc', 'text' => $arc, 'turn' => false];
        return ['lines' => $lines, 'changed' => $changed];
    }

    /** The one-shot line of how the window ended (dimension design keywords). */
    public static function resolutionText(string $npc, array $say): string
    {
        $by = trim((string) ($say['by'] ?? ''));
        if (($say['kind'] ?? null) === 'redemption') {
            return $by !== ''
                ? "{$npc} looked up when {$by} came; the first sign of life in her since, holding on to that presence like a lifeline"
                : "{$npc} found something to hold on to; the first sign of life in her since";
        }
        if (($say['kind'] ?? null) === 'breaking') {
            return "Something closed behind {$npc}'s expression while nobody came; she has decided she is on her own, and the warmth is gone";
        }
        return '';
    }

    /** Standing keywords of an arc while its plasticity override holds (dimension design). */
    public static function arcText(string $npc, array $dynamics): string
    {
        $override = $dynamics['_plasticity_override'] ?? null;
        $type = $dynamics['_divine_intervention_last_type'] ?? null;
        if (!is_string($override) || !is_string($type)) return '';
        $expires = floatval($dynamics['_plasticity_override_expires_gamets'] ?? 0);
        $now = self::calendarNow($dynamics);
        if ($now > 0 && $now >= $expires) return '';
        if ($type === 'redemption') {
            return "Something fundamental shifted in {$npc}: she speaks with a quiet clarity, her priorities rearranged, haunted but purposeful, and treats small moments with a weight they did not have before";
        }
        if ($type === 'breaking') {
            return "Something broke behind {$npc}'s eyes: a thousand-yard stare, going through the motions, flinching at kindness; the lights are on but dimmer";
        }
        return '';
    }

    /**
     * An applied eval item's part in these protocols: betrayal by a bonded partner (Divine
     * Intervention) and the exchange's kind in the parasite ledger. $n: a normalized contract item.
     */
    public static function onEvalItem(string $npc, array $n, array &$dynamics): void
    {
        $cfg = self::config();
        $rd = RelationshipDynamics::getConfig();
        $d = $cfg['divine'];
        $tags = (array) ($n['tags'] ?? []);
        if (!empty($rd['divine_intervention_enabled']) && in_array((string) $d['betrayal_tag'], $tags, true)
            && floatval($n['significance'] ?? 0) >= floatval($d['betrayal_min_significance'])
            && floatval($n['signals']['trust'] ?? 0) <= floatval($d['betrayal_trust_raw_at_most'])) {
            $type = RelationshipDynamics::getRelationshipType($npc, $dynamics);
            if (in_array($type, (array) $d['betrayal_bond_types'], true)) {
                RelationshipDynamics::log("[DIVINE] {$npc}: betrayal by a bonded partner ({$type}): " . ($n['summary'] ?? ''));
                // The one who betrayed her is no anchor: not in the fork, not in the window
                RelationshipDynamics::triggerDivineIntervention($npc, 'betrayal', intval($d['betrayal_severity']), $dynamics,
                    [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY]);
            } else {
                RelationshipDynamics::log("[DIVINE] {$npc}: a betrayal outside a bonded partnership ({$type}) is not a catastrophe");
            }
        }
        if (!empty($rd['parasite_detection_enabled']) && floatval($n['gamets'] ?? 0) > 0) {
            self::recordExchange($dynamics, floatval($n['gamets']), self::exchangeKindOfEval($n, $cfg));
            RelationshipDynamics::checkParasitePattern($npc, $dynamics);
            RelationshipDynamics::checkParasiteRecovery($npc, $dynamics);
        }
    }

    /**
     * The game calendar's step for these protocols (every NPC, talked to or not): grief phases,
     * an unstable window nobody came to by its end (breaking), the parasite's passion half-life.
     * Returns true when anything changed.
     */
    public static function calendarTick(string $npc, array &$dynamics, float $now): bool
    {
        $changed = false;
        if (RelationshipDynamics::configValue('grief_system_enabled')) {
            $changed = self::tickGrief($npc, $dynamics, $now) || $changed;
        }
        $window = $dynamics['_unstable_window'] ?? null;
        if (is_array($window) && empty($window['resolved'])) {
            $r = RelationshipDynamics::checkUnstableWindow($npc, $dynamics, null, []);
            $changed = ($r !== null && $r !== 'active') || $changed;
        }
        return abs(self::parasitePassionDecay($dynamics, $now)) > 0.0 || $changed;
    }

    // =====================================================================
    // THE ICK (tuning)
    // =====================================================================

    /** The Ick threshold's attachment multiplier: 1 - drop x the avoidance share (0..1). */
    public static function ickAvoidanceMult(array $dynamics, ?array $cfg = null): float
    {
        $drop = self::clamp(floatval((($cfg ?? self::config())['ick'])['avoidance_threshold_drop']), 0.0, 0.9);
        $acfg = RelationshipDynamics::getAttachmentConfig();
        $lo = floatval($acfg['prototype']['low'] ?? 0.15);
        $hi = floatval($acfg['prototype']['high'] ?? 0.85);
        $share = self::clamp((floatval(RelationshipDynamics::getAttachmentAxes($dynamics)['avoidance']) - $lo) / max(1e-9, $hi - $lo), 0.0, 1.0);
        return 1.0 - $drop * $share;
    }

    // =====================================================================
    // PARASITE
    // =====================================================================

    /** The exchange kind of an applied eval item: genuine tags > gift > a positive exchange > other. */
    public static function exchangeKindOfEval(array $n, ?array $cfg = null): string
    {
        $p = ($cfg ?? self::config())['parasite'];
        $tags = (array) ($n['tags'] ?? []);
        if (array_intersect($tags, (array) $p['genuine_tags']) !== []) return self::KIND_GENUINE;
        if (in_array('gift', $tags, true)) return self::KIND_GIFT;
        return !empty($n['positive_interaction']) ? self::KIND_GENUINE : self::KIND_OTHER;
    }

    /** Rank for merging two reads of one exchange (the stronger read wins). */
    private static function kindRank(string $kind): int
    {
        return ['other' => 0, 'gift' => 1, 'genuine' => 2][$kind] ?? 0;
    }

    /**
     * Record one exchange with the player (raw game time $gamets) as $kind in the rolling ledger;
     * a second read of the same exchange (the postrequest, then its eval item) keeps the stronger
     * kind. The counts (gift_count, genuine_count, total_window) are the ledger's summary.
     */
    public static function recordExchange(array &$dynamics, float $gamets, string $kind): void
    {
        $cfg = self::config()['parasite'];
        $ledger = is_array($dynamics[self::LEDGER_KEY] ?? null) ? $dynamics[self::LEDGER_KEY] : [];
        if (intval($ledger['v'] ?? 0) !== self::LEDGER_VERSION) $ledger = ['v' => self::LEDGER_VERSION, 'recent' => []];   // start fresh
        $g = (int) round($gamets);
        $recent = (array) $ledger['recent'];
        $found = false;
        foreach ($recent as $i => $e) {
            if (intval($e[0] ?? -1) === $g) {
                if (self::kindRank($kind) > self::kindRank((string) ($e[1] ?? 'other'))) $recent[$i][1] = $kind;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $recent[] = [$g, $kind];
            usort($recent, fn($a, $b) => intval($a[0]) <=> intval($b[0]));
        }
        $recent = array_slice(array_values($recent), -max(1, intval($cfg['window'])));
        $ledger['recent'] = $recent;
        $ledger['total_window'] = count($recent);
        $ledger['gift_count'] = count(array_filter($recent, fn($e) => ($e[1] ?? null) === self::KIND_GIFT));
        $ledger['genuine_count'] = count(array_filter($recent, fn($e) => ($e[1] ?? null) === self::KIND_GENUINE));
        $ledger['last_interaction_type'] = $kind;
        $dynamics[self::LEDGER_KEY] = $ledger;
    }

    /** The gift share above which this NPC turns transactional (personality, bounded). */
    public static function parasiteRatioThreshold(array $dynamics, ?array $cfg = null): float
    {
        $p = ($cfg ?? self::config())['parasite'];
        $ratio = floatval($p['gift_ratio_above']);
        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        if ($x === null) return $ratio;
        $ratio += floatval($p['warmth_slope']) * (floatval($x['W']) - 0.5)
            - floatval($p['egocentric_slope']) * RelDynTraits::egocentric(floatval($x['Pd']));
        return self::clamp($ratio, floatval($p['ratio_min']), floatval($p['ratio_max']));
    }

    /**
     * MDD 6.2's accelerated decay while the bond is transactional: passion halves every
     * passion_half_life_game_hours of game time since the checkpoint, down to the stage floor.
     * The checkpoint follows the clock whether or not the bond is a parasite. Returns the change.
     */
    public static function parasitePassionDecay(array &$dynamics, float $now): float
    {
        if ($now <= 0) return 0.0;
        $last = floatval($dynamics[self::PARASITE_CLOCK_KEY] ?? 0);
        $parasite = ($dynamics['_relationship_type_override'] ?? null) === 'parasite'
            && RelationshipDynamics::configValue('parasite_detection_enabled');
        if (!$parasite) {
            if (isset($dynamics[self::PARASITE_CLOCK_KEY])) unset($dynamics[self::PARASITE_CLOCK_KEY]);
            return 0.0;
        }
        $dynamics[self::PARASITE_CLOCK_KEY] = $now;
        if ($last <= 0 || $now <= $last) return 0.0;
        $halfLife = max(1e-6, floatval(self::config()['parasite']['passion_half_life_game_hours']));
        $hours = ($now - $last) / self::gametsPerGameHour();
        $passion = RelationshipDynamics::getPassion($dynamics);
        $floor = min($passion, RelationshipDynamics::passionStageFloor($dynamics));
        $new = $floor + ($passion - $floor) * (0.5 ** ($hours / $halfLife));
        if (abs($new - $passion) < 1e-6) return 0.0;
        RelationshipDynamics::setPassion($dynamics, round($new, 4));
        RelationshipDynamics::log(sprintf('[PARASITE] passion %.2f -> %.2f over %.2f game hours (half-life %.2f)', $passion, $new, $hours, $halfLife));
        return $new - $passion;
    }

    // =====================================================================
    // JEV (numbers)
    // =====================================================================

    /**
     * Jev's block: ['grief' => [deceased => ['phase', 'memory_warmth', 'widow_lock']], 'widow_ceiling'
     * => core points (null = none), 'crisis' => null | ['event', 'fraction' 0..1], 'arc' => ?string,
     * 'ick' => bool, 'parasite' => bool, 'gift_share' => 0..1].
     */
    public static function jev(array $dynamics): array
    {
        $grief = [];
        foreach ((array) ($dynamics['_grief_bonds'] ?? []) as $name => $g) {
            if (!is_array($g)) continue;
            $grief[(string) $name] = ['phase' => intval($g['phase'] ?? 1), 'memory_warmth' => isset($g['memory_warmth']) ? round(floatval($g['memory_warmth']), 1) : null,
                'widow_lock' => !empty($g['widow_lock'])];
        }
        $ceiling = floatval($dynamics['_widow_lock_ceiling'] ?? 100);
        $crisis = null;
        $w = $dynamics['_unstable_window'] ?? null;
        if (is_array($w) && empty($w['resolved'])) {
            $now = self::calendarNow($dynamics);
            $dur = floatval($w['duration_gamets'] ?? 0);
            $crisis = ['event' => (string) ($w['event_type'] ?? 'unknown'),
                'fraction' => $dur > 0 ? round(self::clamp(($now - floatval($w['start_gamets'] ?? $now)) / $dur, 0.0, 1.0), 3) : 0.0];
        }
        $arc = self::arcText('', $dynamics) !== '' ? (string) $dynamics['_divine_intervention_last_type'] : null;
        $ledger = (array) ($dynamics[self::LEDGER_KEY] ?? []);
        $total = intval($ledger['total_window'] ?? 0);
        return [
            'grief' => $grief,
            'widow_ceiling' => $ceiling < 100 ? round($ceiling, 1) : null,
            'crisis' => $crisis,
            'arc' => $arc,
            'ick' => !empty($dynamics['_ick_tracker']['ick_active']),
            'parasite' => ($dynamics['_relationship_type_override'] ?? null) === 'parasite',
            'gift_share' => $total > 0 ? round(intval($ledger['gift_count'] ?? 0) / $total, 3) : 0.0,
        ];
    }
}
