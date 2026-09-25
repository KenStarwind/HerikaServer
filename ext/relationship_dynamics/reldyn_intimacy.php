<?php
/**
 * Relationship Dynamics — intimacy need per NPC: physical and emotional axes.
 *
 * Rulings 2026-09-24 §10 (Ken): "Intimacy need is per character, from a trait combo." Going
 * without intimacy weighs on some NPCs strongly (Aela: physical, visceral), barely on others;
 * long-lived races (elves) feel less urgency; Ashe's need is connection (closeness, quality
 * time, non-sexual touch), not sex. Two axes, each a need weight 0..1:
 *
 *   physical_intimacy   being wanted, sex and desire
 *   emotional_intimacy  closeness, being known, quality time, non-sexual touch, confiding
 *
 * Derivation (deterministic, config key 'intimacy_need'): base per axis, plus the rows that
 * match the NPC's race lifespan (core_npc_master.race), creature (vampire / werewolf),
 * temperament, attachment (each style row x the NPC's corner weight, decisions §12), traits,
 * love languages (slot-weighted), the attraction
 * intimacy gate (visceral / bond, RelDynAttraction::gateOf) and maturity (the maturity
 * baseline, 0..100, linear around 50); then the relationship preference's multipliers
 * (asexual: no physical need), then the per-axis floor, clamped 0..1. Per-NPC overrides
 * (config npc_overrides by name, $dynamics['intimacy_need_overrides']) replace an axis.
 *
 * The axes are needs of the fulfillment vector (RelDynFulfillment::needs): an axis whose
 * need reaches axis_min is an axis there, weighted by its need; the physical one only while
 * physical intimacy is in play with the player (a romance core type, or passion at
 * physical_in_play.min_passion, held down to release_passion, while the attraction reads as
 * attracted; never while friendzoned).
 * Deliveries are the fulfillment tag rows (intimacy / touch feed physical; quality time,
 * reassurance, praise, touch, confiding feed emotional) and the intimacy the plugin reports
 * (recordRequest: a Sharmat / OStim scene with the player covers physical in full, a VR touch
 * is half a delivery; PR 13 "OStim/Sharmat events -> fully satisfied"). The axes decay at the
 * fulfillment half-life on the game calendar x the attachment rate (decayRates; PR 13 corners:
 * avoidant 0.5x, anxious 2x, toxic 1.5x, secure 1x, blended at the NPC's attachment axes). The deprived axis the NPC needs most gives
 * <intimacy_state> (feeling text, never numbers) while intimacy is in play with the player and
 * the bond weighs (feltText), and internal weather deprivation (weatherDeprivation).
 *
 * State: $dynamics['_intimacy_need'] (ensureNeed): the core race / creature read once, the
 * stored derivation and the physical in-play latch. Units: need weights and coverage are
 * unitless (0..1, -1..+1); passion and maturity in their dimension points (0..100).
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynIntimacy
{
    const STATE_KEY = '_intimacy_need';
    const VERSION = 1;

    const PHYSICAL = 'physical_intimacy';
    const EMOTIONAL = 'emotional_intimacy';
    const AXES = [self::PHYSICAL, self::EMOTIONAL];

    /** Short keys of the axes in the config tables and overrides. */
    const KEYS = ['physical' => self::PHYSICAL, 'emotional' => self::EMOTIONAL];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'intimacy_need' (a stored config replaces whole settings/tables).
     * Every number is Serene's starting value for Ken's §10 ruling (the MDD gives none); rows
     * add to an axis ('physical' / 'emotional'); tune after playtest.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'base'  => ['physical' => 0.45, 'emotional' => 0.45],
            // Emotional closeness never vanishes (an avoidant NPC expresses less of it, it is still there)
            'floor' => ['physical' => 0.0, 'emotional' => 0.15],

            // core_npc_master.race (e.g. "HighElfRace", "DarkElfRaceVampire"); every matching row adds.
            'race' => [
                // Ken: "an elf who has an extended life span maybe less so"
                ['match' => ['highelf', 'altmer', 'woodelf', 'bosmer', 'darkelf', 'dunmer', 'snowelf', 'falmer'],
                 'lifespan' => 'long', 'axes' => ['physical' => -0.2]],
            ],
            // Creature (dynamics creature_type, else a race / faction name match). MDD creature notes
            // (feedback_creature_moodifications): a vampire is ageless (no mortal urgency; its hunger is
            // for blood), a werewolf's beast blood keeps arousal elevated. The Companions' Circle
            // (CompanionsCircle) share the beast blood in vanilla lore.
            'creature_match' => ['vampire' => ['vampire', 'volkihar'], 'werewolf' => ['werewolf', 'companionscircle']],
            'creature' => [
                'vampire'  => ['physical' => -0.2, 'emotional' => 0.05],
                'werewolf' => ['physical' => 0.2],
            ],
            // MDD 1.3 temperaments. Guarded: slow to let anyone close, and what they want is to be known.
            'temperament' => [
                'Romantic'    => ['physical' => 0.2, 'emotional' => 0.15],
                'Anxious'     => ['emotional' => 0.15],
                'Bold'        => ['physical' => 0.15],
                'Playful'     => ['physical' => 0.15, 'emotional' => -0.05],
                'Humble'      => ['emotional' => 0.05],
                'Nurturing'   => ['emotional' => 0.15],
                'Gentle'      => ['physical' => -0.05, 'emotional' => 0.1],
                'Jealous'     => ['physical' => 0.05, 'emotional' => 0.1],
                'Proud'       => ['physical' => 0.05, 'emotional' => -0.05],
                'Defiant'     => ['physical' => 0.1, 'emotional' => -0.05],
                'Guarded'     => ['physical' => -0.2, 'emotional' => 0.1],
                'Independent' => ['emotional' => -0.1],
                'Stoic'       => ['physical' => -0.1, 'emotional' => -0.05],
            ],
            // MDD 6.1 attachment: anxious needs closeness more; avoidant expresses less of it
            'attachment' => [
                'secure'   => [],
                'anxious'  => ['physical' => 0.05, 'emotional' => 0.2],
                'avoidant' => ['emotional' => -0.1],
                'toxic'    => ['physical' => 0.1, 'emotional' => 0.15],
            ],
            'traits' => [
                'insecure'   => ['emotional' => 0.1],
                'egocentric' => ['physical' => 0.05, 'emotional' => -0.05],
            ],
            // Love language (MDD 1.2) rows x the slot weight: touch leans physical, time and words emotional
            'love_language' => [
                RelationshipDynamics::LL_TOUCH   => ['physical' => 0.2, 'emotional' => 0.05],
                RelationshipDynamics::LL_TIME    => ['emotional' => 0.15],
                RelationshipDynamics::LL_WORDS   => ['emotional' => 0.1],
                RelationshipDynamics::LL_SERVICE => [],
                RelationshipDynamics::LL_GIFTS   => [],
            ],
            'love_language_slot_weight' => ['primary' => 1.0, 'secondary' => 0.6],
            // The attraction intimacy gate (plan §7): visceral = physical first, bond = commitment first
            'gate' => [
                'visceral' => ['physical' => 0.15],
                'bond'     => ['physical' => -0.1, 'emotional' => 0.1],
                'balanced' => [],
            ],
            // Per axis, added x (maturity baseline - 50) / 50 (maturity points 0..100): the immature
            // feel physical urgency more, the mature value connection a little more.
            'maturity' => ['physical' => -0.1, 'emotional' => 0.05],
            // Relationship preference (attraction type filter) multipliers, applied after the rows
            'preference_mult' => [
                'asexual'    => ['physical' => 0.0],
                'demisexual' => ['physical' => 0.6],
            ],
            // Named NPCs (lower-case npc_name) => ['physical' => 0..1, 'emotional' => 0..1]; the
            // per-NPC editor override ($dynamics['intimacy_need_overrides']) beats these.
            'npc_overrides' => [],

            // --- fulfillment (RelDynFulfillment::needs) ---
            'axis_min' => 0.35,   // need weight from which an axis is a need of the fulfillment vector
            // Physical intimacy is in play with the player: a romance core type, or passion
            // (points 0..100) at min_passion; once in play it holds until passion falls below
            // release_passion outside a romance. Never while friendzoned.
            'physical_in_play' => ['core_types' => ['crush', 'romantic'], 'min_passion' => 30.0, 'release_passion' => 20.0],
            // Coverage (-1..+1) at or below which an axis is deprived (<intimacy_state>, the weather)
            'deprived_coverage' => -0.4,
            // PR 13 (pr13-environmental-quirks-plan.md): "Attachment style modifies deprivation
            // rate". x the fulfillment decay of the intimacy axes (the half-life / this).
            'attachment_decay_rate' => ['secure' => 1.0, 'avoidant' => 0.5, 'anxious' => 2.0, 'toxic' => 1.5],
            // Intimacy the plugin reports (an observed fact: fed whether or not the eval scores
            // the exchange). kind => request types (exact), markers (case-insensitive, in the
            // request type or its text), whether the player must be named in the text (the
            // scene's actors: an NPC-only scene is not the player's intimacy), and the delivery
            // units per request (fulfillment target_units = full coverage).
            'requests' => [
                // Sharmat scene stages (ext_nsfw_sexcene: "Scene/tags/Stage/Actor^roles/..."),
                // orgasm events, legacy OStim / SexLab scene speech, any OStim event
                'scene' => ['types' => ['ext_nsfw_sexcene', 'ext_nsfw_scene', 'ext_nsfw_orgasm', 'ext_nsfw_action',
                                        'chatnf_sl', 'chatnf_sl_moan', 'chatnf_sl_climax', 'chatnf_sl_end'],
                            'markers' => ['ostim'], 'requires_player_named' => true,
                            // NPC-to-NPC scene routes (Sharmat canonicalizes a playerless scene to these)
                            'exclude_types' => ['ext_nsfw_npc_scene', 'ext_nsfw_npc_orgasm', 'ext_nsfw_npc_invite'],
                            'units' => [self::PHYSICAL => 3.0, self::EMOTIONAL => 0.25]],
                // Sharmat VR touch / grab of the body (nsfw_physics.php: CBPC / HIGGS)
                'intimate_touch' => ['types' => ['ext_nsfw_physics'], 'markers' => [], 'requires_player_named' => false,
                                     'units' => [self::PHYSICAL => 0.5, self::EMOTIONAL => 0.1]],
            ],
            // Internal weather deprivation of a deprived axis = need x clamp(-coverage, 0, 1) x this
            // (the weather takes the largest deprivation it has)
            'weather_scale' => 0.6,

            // --- felt text (<intimacy_state>, feelings, never numbers) ---
            'felt_text' => [
                // Physical, M/F-aware (PR 13): coord_m / coord_f above m_f_dominant_at and above the other
                'physical' => [
                    'high_m'       => "{NAME} is restless. The tension is physical and they are not the type to suffer in silence. They are considering their options.",
                    'high_f'       => "Something aches quietly beneath the surface for {NAME}. The longing is there but they will not chase -- they will withdraw instead.",
                    'low_maturity' => "The frustration is bleeding into everything for {NAME}. They are snapping at people, picking fights, making impulsive choices.",
                    'balanced'     => "{NAME} is restless in a low, constant way: lingers close, a touch that stays a moment too long, irritable when it goes nowhere.",
                ],
                // Emotional, by attachment style
                'emotional' => [
                    'anxious'  => "{NAME} has been aching for real closeness with {PLAYER}: the quiet talks, being held, being known. The distance feels like a question they are afraid to ask.",
                    'avoidant' => "{NAME} would never say it, but they miss being close to {PLAYER}: the unguarded talks, a hand that stays. They keep it behind a wall, and it shows only in small ways.",
                    'default'  => "{NAME} misses feeling close to {PLAYER}: real talk, time that is only theirs, a touch that means something. It is not about the bed; it is about being known.",
                ],
            ],
            'low_maturity_below' => 30.0,   // maturity points below which the physical text is the low-maturity one
            'm_f_dominant_at'    => 65.0,   // coord_m / coord_f points from which that pole speaks
        ];
    }

    /** The intimacy-need settings: stored config per setting/table, defaults for the rest. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('intimacy_need');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    public static function isAxis(string $axis): bool
    {
        return in_array($axis, self::AXES, true);
    }

    // =====================================================================
    // DERIVATION (pure)
    // =====================================================================

    /** Lower-case letters and digits only ("HighElfRace" -> "highelfrace"). */
    private static function key($value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    /**
     * Creature type from a core row's race and faction names (config creature_match), or null.
     * $factions: extended_data.factions ([['name' => ..., 'rank' => ...], ...]); rank < 0 = not a member.
     */
    public static function creatureFromCore(?string $race, array $factions, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $names = [self::key($race)];
        foreach ($factions as $f) {
            if (is_array($f) && intval($f['rank'] ?? 0) >= 0) $names[] = self::key($f['name'] ?? '');
        }
        foreach ((array) $cfg['creature_match'] as $type => $needles) {
            foreach ((array) $needles as $needle) {
                $needle = self::key($needle);
                foreach ($names as $n) {
                    if ($needle !== '' && $n !== '' && str_contains($n, $needle)) return (string) $type;
                }
            }
        }
        return null;
    }

    /**
     * The derivation's inputs from RelDyn state: temperament, attachment, traits, love languages,
     * maturity baseline, relationship preference, the attraction gate, and the core race /
     * creature ensureNeed() read ($dynamics creature_type beats the read).
     */
    public static function inputs(string $npcName, array $dynamics): array
    {
        $stored = is_array($dynamics[self::STATE_KEY] ?? null) ? $dynamics[self::STATE_KEY] : [];
        $maturity = $dynamics['dimensions']['maturity']['baseline'] ?? $dynamics['dimensions']['maturity']['x'] ?? 50;
        $creature = $dynamics['creature_type'] ?? null;
        return [
            'race'        => is_string($stored['race'] ?? null) ? $stored['race'] : null,
            'creature'    => is_string($creature) && $creature !== '' ? strtolower($creature)
                : (is_string($stored['creature'] ?? null) ? $stored['creature'] : null),
            'temperament' => RelationshipDynamics::validTemperament($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null),
            // read assignment: A26 reads the NPC's own vector (RelDynTraits::readVector)
            'dynamics'    => RelDynTraits::readVector($dynamics) !== null ? $dynamics : null,
            // style corner => weight (RelationshipDynamics::attachmentWeights, sums to 1)
            'attachment'  => RelationshipDynamics::attachmentWeights($dynamics),
            'traits'      => RelationshipDynamics::getTraits($dynamics),
            'love_language_primary'   => $dynamics['love_language_primary'] ?? null,
            'love_language_secondary' => $dynamics['love_language_secondary'] ?? null,
            'maturity'    => is_numeric($maturity) ? floatval($maturity) : 50.0,
            'preference'  => strtolower(trim((string) ($dynamics['relationship_preference'] ?? ''))) ?: null,
            'gate'        => RelDynAttraction::gateOf($npcName, $dynamics),
        ];
    }

    /**
     * The trait-combo derivation (module doc). Pure: the same inputs and config give the
     * same result.
     *
     * @return array ['physical' => 0..1, 'emotional' => 0..1, 'signals' => string[]]
     */
    public static function derive(array $in, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $v = ['physical' => floatval($cfg['base']['physical'] ?? 0.5), 'emotional' => floatval($cfg['base']['emotional'] ?? 0.5)];
        $signals = [];
        $add = function ($row, float $scale, string $signal) use (&$v, &$signals) {
            $used = false;
            foreach ((array) $row as $axis => $d) {
                if (!array_key_exists($axis, $v) || !is_numeric($d) || floatval($d) == 0.0) continue;
                $v[$axis] += floatval($d) * $scale;
                $used = true;
            }
            if ($used) $signals[] = $scale == 1.0 ? $signal : sprintf('%s x%.2f', $signal, $scale);
        };

        $race = self::key($in['race'] ?? '');
        if ($race !== '') {
            foreach ((array) $cfg['race'] as $row) {
                foreach ((array) ($row['match'] ?? []) as $needle) {
                    $needle = self::key($needle);
                    if ($needle !== '' && str_contains($race, $needle)) {
                        $add($row['axes'] ?? [], 1.0, 'race:' . ($row['lifespan'] ?? $needle));
                        break;
                    }
                }
            }
        }
        if (!empty($in['creature'])) $add(((array) $cfg['creature'])[$in['creature']] ?? [], 1.0, "creature:{$in['creature']}");
        // A26 through the trait engine (Rule R: physical E, D; emotional W, G, E)
        if (!empty($in['temperament'])) $add(RelDynTraits::rowParam($in['temperament'], (array) $cfg['temperament'], [
            'physical'  => ['R', [0.02, 'E' => 0.19, 'D' => -0.21]],
            'emotional' => ['R', [-0.64, 'W' => 0.96, 'G' => 0.54, 'E' => 0.18]],
        ], 'offset', is_array($in['dynamics'] ?? null) ? $in['dynamics'] : null), 1.0, "temperament:{$in['temperament']}");
        // attachment: a style name (a textbook NPC of it) or style => corner weight
        $att = $in['attachment'] ?? null;
        foreach (is_array($att) ? $att : (is_string($att) && $att !== '' ? [$att => 1.0] : []) as $style => $w) {
            if (floatval($w) > 0.0) $add(((array) $cfg['attachment'])[$style] ?? [], floatval($w), "attachment:{$style}");
        }
        foreach ((array) ($in['traits'] ?? []) as $trait) {
            $add(((array) $cfg['traits'])[strtolower((string) $trait)] ?? [], 1.0, "trait:{$trait}");
        }
        foreach ((array) $cfg['love_language_slot_weight'] as $slot => $w) {
            $ll = $in['love_language_' . $slot] ?? null;
            if (is_string($ll)) $add(((array) $cfg['love_language'])[$ll] ?? [], floatval($w), "love_language:{$ll}");
        }
        if (!empty($in['gate'])) $add(((array) $cfg['gate'])[$in['gate']] ?? [], 1.0, "gate:{$in['gate']}");
        $m = max(0.0, min(100.0, floatval($in['maturity'] ?? 50)));
        if ($m != 50.0) $add((array) $cfg['maturity'], ($m - 50.0) / 50.0, 'maturity');
        if (!empty($in['preference'])) {
            foreach ((array) (((array) $cfg['preference_mult'])[$in['preference']] ?? []) as $axis => $mult) {
                if (array_key_exists($axis, $v) && is_numeric($mult)) {
                    $v[$axis] *= max(0.0, floatval($mult));
                    $signals[] = "preference:{$in['preference']}";
                }
            }
        }
        foreach ($v as $axis => $x) {
            $v[$axis] = round(max(max(0.0, floatval($cfg['floor'][$axis] ?? 0)), min(1.0, $x)), 2);
        }
        return $v + ['signals' => $signals];
    }

    // =====================================================================
    // THE NPC'S NEED (stored derivation + overrides)
    // =====================================================================

    /**
     * Read the NPC's core race / creature once (core_npc_master), derive, and store in
     * $dynamics['_intimacy_need'] with the physical in-play latch. The retired single intimacy
     * stamp (_intimacy_fed) is dropped. Returns true when it changed $dynamics. A failed core
     * read is logged and retried on the next call; the derivation then runs without the race.
     */
    public static function ensureNeed(string $npcName, array &$dynamics): bool
    {
        $before = [$dynamics[self::STATE_KEY] ?? null, array_key_exists('_intimacy_fed', $dynamics)];
        unset($dynamics['_intimacy_fed']);
        if (!self::enabled()) return $before[1];
        $cfg = self::config();
        $state = is_array($dynamics[self::STATE_KEY] ?? null) ? $dynamics[self::STATE_KEY] : [];
        if (!array_key_exists('race', $state)) {
            try {
                $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
                $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
                $state['race'] = (string) ($row['race'] ?? '');
                $state['creature'] = self::creatureFromCore($state['race'], (array) ($ext['factions'] ?? []), $cfg);
            } catch (Throwable $e) {
                error_log("[RelDyn] intimacy need: core_npc_master read failed for {$npcName}: " . $e->getMessage());
            }
        }
        $dynamics[self::STATE_KEY] = $state;
        $d = self::derive(self::inputs($npcName, $dynamics), $cfg);
        $state['v'] = self::VERSION;
        $state['physical'] = $d['physical'];
        $state['emotional'] = $d['emotional'];
        $state['signals'] = $d['signals'];
        $state['preset'] = self::presetOf($npcName, $cfg);
        $state['physical_in_play'] = self::physicalInPlay($dynamics, $cfg);
        $dynamics[self::STATE_KEY] = $state;
        if ($before[0] !== $state) {
            RelationshipDynamics::log("Intimacy need for {$npcName}: physical={$d['physical']} emotional={$d['emotional']} ["
                . implode(' ', $d['signals']) . ']');
            return true;
        }
        return $before[1];
    }

    /** The named preset (config npc_overrides) of $npcName: axis key => 0..1, only valid entries. */
    private static function presetOf(string $npcName, array $cfg): array
    {
        $row = ((array) $cfg['npc_overrides'])[strtolower(trim($npcName))] ?? [];
        $out = [];
        foreach (self::KEYS as $k => $_) {
            if (is_numeric($row[$k] ?? null)) $out[$k] = max(0.0, min(1.0, floatval($row[$k])));
        }
        return $out;
    }

    /**
     * The NPC's need per axis, 'physical' / 'emotional' => 0..1: the stored derivation
     * (ensureNeed), else derived now from state alone (no race); the named preset stored with
     * it, then $dynamics['intimacy_need_overrides'], replace an axis. Pure.
     */
    public static function need(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $stored = $dynamics[self::STATE_KEY] ?? null;
        if (is_array($stored) && is_numeric($stored['physical'] ?? null) && is_numeric($stored['emotional'] ?? null)) {
            $need = ['physical' => floatval($stored['physical']), 'emotional' => floatval($stored['emotional'])];
        } else {
            $d = self::derive(self::inputs('', $dynamics), $cfg);
            $need = ['physical' => $d['physical'], 'emotional' => $d['emotional']];
        }
        foreach ([(array) ($stored['preset'] ?? []), (array) ($dynamics['intimacy_need_overrides'] ?? [])] as $over) {
            foreach (self::KEYS as $k => $_) {
                if (is_numeric($over[$k] ?? null)) $need[$k] = max(0.0, min(1.0, floatval($over[$k])));
            }
        }
        return $need;
    }

    /**
     * Set (or with null, clear) the per-NPC override of one axis ('physical' / 'emotional',
     * 0..1). Returns false and changes nothing for an unknown axis or a value outside 0..1.
     */
    public static function setNeedOverride(array &$dynamics, string $axis, ?float $value): bool
    {
        if (!array_key_exists($axis, self::KEYS)) return false;
        if ($value !== null && ($value < 0.0 || $value > 1.0 || is_nan($value))) return false;
        $over = (array) ($dynamics['intimacy_need_overrides'] ?? []);
        if ($value === null) unset($over[$axis]); else $over[$axis] = $value;
        $dynamics['intimacy_need_overrides'] = $over;
        return true;
    }

    /**
     * Physical intimacy is in play with the player (module doc): never while friendzoned; a
     * romance core type; else attracted (decisions §13 lets passion climb past 20 on the
     * uphill, but a curve under the friendzone line is not that kind of pull) with passion at
     * min_passion; a latched NPC stays in play down to release_passion. Pure (the latch is
     * the stored physical_in_play).
     */
    public static function physicalInPlay(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        if (!empty($dynamics['_attraction']['friendzoned'])) return false;
        $p = (array) $cfg['physical_in_play'];
        $core = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        if ($core !== '' && in_array($core, array_map('strtolower', (array) ($p['core_types'] ?? [])), true)) return true;
        if (!empty($dynamics['_attraction']['enabled']) && ($dynamics['_attraction']['attracted'] ?? true) === false) return false;
        $passion = RelationshipDynamics::getPassion($dynamics);
        $latched = !empty($dynamics[self::STATE_KEY]['physical_in_play']);
        return $passion >= floatval($p[$latched ? 'release_passion' : 'min_passion'] ?? 30);
    }

    /**
     * The intimacy axes of the fulfillment needs vector: axis => need weight, for each axis
     * whose need reaches axis_min (physical only while in play). Empty when off. Pure.
     */
    public static function fulfillmentNeeds(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return [];
        $need = self::need($dynamics, $cfg);
        $min = floatval($cfg['axis_min']);
        $out = [];
        if ($need['physical'] >= $min && $need['physical'] > 0 && self::physicalInPlay($dynamics, $cfg)) $out[self::PHYSICAL] = $need['physical'];
        if ($need['emotional'] >= $min && $need['emotional'] > 0) $out[self::EMOTIONAL] = $need['emotional'];
        return $out;
    }

    /**
     * Decay rate of each intimacy axis (config attachment_decay_rate, PR 13: the style corners
     * blended at the NPC's attachment axes, decisions §12), axis => rate, only rates other
     * than 1 (the fulfillment default). Rounded to 4 places so slow attachment drift does not
     * rewrite the fulfillment state on every contact. Empty when off.
     */
    public static function decayRates(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return [];
        $rate = round(max(0.0, RelationshipDynamics::attachmentBlend($dynamics, (array) ($cfg['attachment_decay_rate'] ?? []), 1.0)), 4);
        return $rate == 1.0 ? [] : array_fill_keys(self::AXES, $rate);
    }

    // =====================================================================
    // INTIMACY THE PLUGIN REPORTS (config requests)
    // =====================================================================

    /**
     * The kind of intimacy a request reports ('scene', 'intimate_touch'; config requests), or
     * null: its type is listed (or carries a marker, in the type or the text), it is not an
     * excluded type, and when required the player is named in its text (whole word). Pure.
     */
    public static function requestKind(array $gameRequest, string $playerName, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $type = strtolower(trim((string) ($gameRequest[0] ?? '')));
        $text = (string) ($gameRequest[3] ?? '');
        if ($type === '') return null;
        foreach ((array) ($cfg['requests'] ?? []) as $kind => $row) {
            $row = (array) $row;
            if (in_array($type, array_map('strtolower', (array) ($row['exclude_types'] ?? [])), true)) continue;
            $hit = in_array($type, array_map('strtolower', (array) ($row['types'] ?? [])), true);
            foreach ((array) ($row['markers'] ?? []) as $m) {
                $m = strtolower(trim((string) $m));
                if (!$hit && $m !== '' && (str_contains($type, $m) || stripos($text, $m) !== false)) $hit = true;
            }
            if (!$hit) continue;
            if (!empty($row['requires_player_named'])) {
                $player = trim($playerName);
                if ($player === '' || preg_match('/(?<![\p{L}\p{N}])' . preg_quote($player, '/') . '(?![\p{L}\p{N}])/iu', $text) !== 1) continue;
            }
            return (string) $kind;
        }
        return null;
    }

    /**
     * Deliver the intimacy a request reports (requestKind) to the fulfillment axes at $now:
     * that kind's units. Returns axis => units applied ([] for none, or with no state).
     */
    public static function recordRequest(array &$dynamics, array $gameRequest, string $playerName, float $now): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return [];
        $kind = self::requestKind($gameRequest, $playerName, $cfg);
        if ($kind === null) return [];
        $amounts = [];
        foreach ((array) ($cfg['requests'][$kind]['units'] ?? []) as $axis => $u) {
            if (self::isAxis((string) $axis) && is_numeric($u)) $amounts[(string) $axis] = floatval($u);
        }
        return RelDynFulfillment::deliver($dynamics, $amounts, $now);
    }

    // =====================================================================
    // DEPRIVATION (from the fulfillment state)
    // =====================================================================

    /**
     * The intimacy axes of the stored fulfillment state at $now: axis => ['need' => weight,
     * 'coverage' => -1..+1, 'deprived' => bool]. Empty without state or with the module off.
     */
    public static function axesAt(array $dynamics, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $state = $dynamics[RelDynFulfillment::STATE_KEY] ?? null;
        if (empty($cfg['enabled']) || $now <= 0 || !RelDynFulfillment::enabled() || !is_array($state) || !is_array($state['w'] ?? null)) return [];
        $weights = array_intersect_key(array_map('floatval', $state['w']), array_flip(self::AXES));
        if ($weights === []) return [];
        $fcfg = RelDynFulfillment::config();
        $levels = RelDynFulfillment::levelsAt($state, max($now, floatval($state['gamets'] ?? 0)), $fcfg);
        $out = [];
        foreach ($weights as $axis => $w) {
            $c = RelDynFulfillment::coverageOf(floatval($levels[$axis] ?? 0.0), $fcfg);
            $out[$axis] = ['need' => $w, 'coverage' => round($c, 4), 'deprived' => $c <= floatval($cfg['deprived_coverage'])];
        }
        return $out;
    }

    /** The deprived axis the NPC needs most at $now (need x shortfall), or null. */
    public static function deprivedAxis(array $dynamics, float $now, ?array $cfg = null): ?string
    {
        $best = null;
        $bestScore = 0.0;
        foreach (self::axesAt($dynamics, $now, $cfg) as $axis => $a) {
            if (!$a['deprived']) continue;
            $score = $a['need'] * (1.0 - $a['coverage']);
            if ($score > $bestScore) { $bestScore = $score; $best = $axis; }
        }
        return $best;
    }

    /**
     * Internal weather deprivation (0..1) from the intimacy axes at $now: the largest
     * need x clamp(-coverage, 0, 1) x weather_scale over the deprived axes; 0 when none.
     */
    public static function weatherDeprivation(array $dynamics, float $now): float
    {
        $cfg = self::config();
        $out = 0.0;
        foreach (self::axesAt($dynamics, $now, $cfg) as $a) {
            if (!$a['deprived']) continue;
            $out = max($out, $a['need'] * max(0.0, min(1.0, -$a['coverage'])) * floatval($cfg['weather_scale']));
        }
        return max(0.0, min(1.0, $out));
    }

    /**
     * <intimacy_state> text: the deprived axis the NPC needs most, as a feeling. Physical is
     * M/F-aware (coord_m / coord_f) with a low-maturity variant, emotional follows the
     * attachment style. Null when nothing is deprived, when the bond is not one whose neglect
     * weighs (neglectBond; the weather reads 0 then too), and while intimacy is not in play with
     * the player (physicalInPlay: the texts are a romance's; a housecarl, a sister or a friend
     * who misses the closeness says so through the fulfillment text). Never numbers.
     */
    public static function feltText(string $npcName, string $playerName, array $dynamics, float $now): ?string
    {
        $cfg = self::config();
        if (RelationshipDynamics::neglectBond($dynamics) === null || !self::physicalInPlay($dynamics, $cfg)) return null;
        $axis = self::deprivedAxis($dynamics, $now, $cfg);
        if ($axis === null) return null;
        $felt = (array) $cfg['felt_text'];
        $dims = $dynamics['dimensions'] ?? [];
        if ($axis === self::PHYSICAL) {
            $m = floatval($dims['coord_m']['x'] ?? 50);
            $f = floatval($dims['coord_f']['x'] ?? 50);
            $at = floatval($cfg['m_f_dominant_at']);
            if (floatval($dims['maturity']['x'] ?? 50) < floatval($cfg['low_maturity_below'])) $key = 'low_maturity';
            elseif ($m > $at && $m > $f) $key = 'high_m';
            elseif ($f > $at && $f > $m) $key = 'high_f';
            else $key = 'balanced';
            $text = (string) (((array) ($felt['physical'] ?? []))[$key] ?? '');
        } else {
            $style = RelationshipDynamics::getAttachmentStyle($dynamics);
            $table = (array) ($felt['emotional'] ?? []);
            $text = (string) ($table[$style] ?? $table['default'] ?? '');
        }
        return $text === '' ? null : str_replace(['{NAME}', '{PLAYER}'], [$npcName, $playerName], $text);
    }
}
