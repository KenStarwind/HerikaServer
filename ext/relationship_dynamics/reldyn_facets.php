<?php
/**
 * Relationship Dynamics — Facets (decisions 2026-09-23 §6: facets -> appraisal -> feeling)
 *
 * One model for everything an NPC experiences (place, item, topic, creature, activity):
 *   - a FACET VECTOR: facet => weight 0..1 over the 11 MDD 1.2 interests plus the
 *     situational facets (danger, crowd, wild, confined, dark, sacred, luxury, quiet);
 *   - the NPC's signed PREFERENCES: facet => -1 (hates) .. 0 (indifferent) .. +1 (loves);
 *   - the APPRAISAL: valence = normalised sum(facet x preference), intensity, and the
 *     dominant facet (biggest |contribution|, sign kept) that decides what the NPC *sees*;
 *   - the FELT TEXT: prose for the LLM from the dominant facet, sign and intensity, never
 *     numbers (decisions §3; Jev gets the numbers).
 *
 * Shared API (lane ownership, 2026-09-24 batch):
 *   placeFacets / currentPlaceContext  places lane (CHIM core locations + eventlog)
 *   thingFacets                        classifier lane (Oghma / keyword tables)
 *   preferences / appraise / feltText  appraisal lane
 * All mapping tables live in editable config, not in code.
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynFacets
{
    /** MDD 1.2: the 11 interest categories. */
    const INTERESTS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
    ];

    /** Decisions §6: situational facets (what a place or thing is like, not what it is about). */
    const SITUATIONAL = ['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'];

    /** Every facet, interests first. A facet vector is facet => weight 0..1. */
    const FACETS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
        'danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet',
    ];

    /**
     * Facet vector (facet => 0..1) for a place. Places lane.
     *
     * @param array $placeContext ['name', 'hold', 'tags', 'is_interior', 'time_of_day', 'weather']
     *                            as currentPlaceContext() reads it from CHIM core
     */
    public static function placeFacets(array $placeContext): array
    {
        return [];
    }

    /** The NPC's current place context from CHIM core data only. Places lane. */
    public static function currentPlaceContext(string $npcName): array
    {
        return [];
    }

    /**
     * Facet vector for a thing. Classifier lane.
     *
     * @param string $kind item|topic|creature|place|activity
     * @return array facet => 0..1, [] when unknown
     */
    public static function thingFacets(string $kind, string $name): array
    {
        return [];
    }

    // =====================================================================
    // SIGNED PREFERENCES (appraisal lane; decisions §6, replaces MDD 1.2's 0.5-2.0 interests)
    // =====================================================================

    /** Bump when the derivation itself changes, so stored preferences are derived again. */
    const PREFS_VERSION = 1;

    /**
     * Default tables for the preference auto-derivation (config key 'facet_preferences'; a
     * stored config replaces whole tables, tables it leaves out keep these). Every number
     * is a signed preference contribution on the -1 (hates) .. +1 (loves) scale; the sum
     * of all contributions per facet is clamped to -1..+1.
     *
     *   archetype_prefs    class archetype (the temperament auto-generation's class_archetypes
     *                      / faction_archetypes resolve it) => facet => contribution
     *   class_weight       multiplier for the class archetype's row
     *   faction_weight     multiplier for each distinct faction archetype's row
     *   skill_facets       metadata.skills name => facet => contribution at skill level 100;
     *                      a skill counts from skills_min_level (Skyrim skill level 0-100)
     *                      and scales linearly to 1 at level 100
     *   temperament_prefs  MDD 1.3 temperament => facet => contribution
     *   trait_prefs        trait tag (decisions §1) => facet => contribution
     */
    public static function preferenceDefaults(): array
    {
        return [
            'class_weight'   => 1.0,
            'faction_weight' => 0.5,
            'skills_min_level' => 25,
            'archetype_prefs' => [
                'Warrior'   => ['combat' => 0.6, 'crafting' => 0.2, 'adventure' => 0.2, 'danger' => 0.2, 'social' => 0.1,
                                'scholarly' => -0.2, 'quiet' => -0.1],
                'Barbarian' => ['combat' => 0.7, 'adventure' => 0.4, 'nature' => 0.3, 'wild' => 0.3, 'danger' => 0.3,
                                'scholarly' => -0.4, 'luxury' => -0.3, 'confined' => -0.2],
                'Ranger'    => ['nature' => 0.7, 'wild' => 0.5, 'combat' => 0.2, 'adventure' => 0.1, 'danger' => 0.1,
                                'scholarly' => -0.5, 'confined' => -0.4, 'crowd' => -0.3, 'luxury' => -0.2],
                'Mage'      => ['scholarly' => 0.6, 'enchanting' => 0.4, 'alchemy' => 0.2, 'adventure' => 0.2, 'quiet' => 0.3,
                                'crowd' => -0.2, 'wild' => -0.2, 'combat' => -0.1],
                'Thief'     => ['adventure' => 0.5, 'wealth' => 0.5, 'dark' => 0.3, 'social' => 0.2, 'crowd' => 0.1,
                                'sacred' => -0.2, 'spiritual' => -0.1],
                'Assassin'  => ['combat' => 0.5, 'adventure' => 0.3, 'dark' => 0.5, 'quiet' => 0.2, 'crowd' => -0.3, 'sacred' => -0.2],
                'Healer'    => ['alchemy' => 0.5, 'spiritual' => 0.5, 'sacred' => 0.4, 'domestic' => 0.2, 'nature' => 0.2,
                                'quiet' => 0.2, 'combat' => -0.3, 'danger' => -0.3],
                'Noble'     => ['social' => 0.4, 'wealth' => 0.6, 'luxury' => 0.6, 'scholarly' => 0.1,
                                'wild' => -0.4, 'dark' => -0.2, 'danger' => -0.2],
                'Merchant'  => ['wealth' => 0.6, 'social' => 0.5, 'crowd' => 0.3, 'domestic' => 0.2, 'crafting' => 0.1,
                                'danger' => -0.4, 'wild' => -0.3],
                'Guard'     => ['combat' => 0.5, 'social' => 0.1, 'danger' => 0.1, 'domestic' => 0.1],
                'Bard'      => ['social' => 0.7, 'crowd' => 0.5, 'scholarly' => 0.2, 'luxury' => 0.2, 'quiet' => -0.2, 'confined' => -0.1],
            ],
            'skill_facets' => [
                'archery'     => ['combat' => 0.3, 'nature' => 0.3],   // hunting
                'onehanded'   => ['combat' => 0.4],
                'twohanded'   => ['combat' => 0.4],
                'block'       => ['combat' => 0.3],
                'heavyarmor'  => ['combat' => 0.3],
                'lightarmor'  => ['adventure' => 0.2, 'combat' => 0.1],
                'sneak'       => ['adventure' => 0.2, 'dark' => 0.2],
                'lockpicking' => ['adventure' => 0.2, 'wealth' => 0.1],
                'pickpocket'  => ['wealth' => 0.2, 'crowd' => 0.1],
                'speech'      => ['social' => 0.4, 'crowd' => 0.2],
                'smithing'    => ['crafting' => 0.5],
                'alchemy'     => ['alchemy' => 0.5, 'nature' => 0.1],
                'enchanting'  => ['enchanting' => 0.5, 'scholarly' => 0.1],
                'destruction' => ['scholarly' => 0.2, 'combat' => 0.2, 'adventure' => 0.1],
                'conjuration' => ['scholarly' => 0.3, 'dark' => 0.1],
                'alteration'  => ['scholarly' => 0.3, 'adventure' => 0.2],
                'illusion'    => ['scholarly' => 0.2, 'social' => 0.1],
                'restoration' => ['spiritual' => 0.3, 'scholarly' => 0.1],
            ],
            // MDD 1.3 characters, read as where each temperament is at ease or not
            'temperament_prefs' => [
                'Romantic'    => ['social' => 0.2, 'luxury' => 0.2],
                'Anxious'     => ['danger' => -0.4, 'crowd' => -0.2, 'dark' => -0.2, 'confined' => -0.1],
                'Bold'        => ['danger' => 0.3, 'combat' => 0.1, 'adventure' => 0.1],
                'Playful'     => ['social' => 0.3, 'crowd' => 0.2, 'quiet' => -0.2],
                'Humble'      => ['domestic' => 0.2, 'luxury' => -0.1],
                'Nurturing'   => ['domestic' => 0.3, 'social' => 0.1, 'danger' => -0.2],
                'Gentle'      => ['quiet' => 0.2, 'nature' => 0.1, 'danger' => -0.3, 'combat' => -0.2],
                'Jealous'     => ['crowd' => -0.1],
                'Proud'       => ['luxury' => 0.2, 'wealth' => 0.1],
                'Defiant'     => ['danger' => 0.2, 'sacred' => -0.1],
                'Guarded'     => ['crowd' => -0.1, 'quiet' => 0.1, 'danger' => -0.1],
                'Independent' => ['nature' => 0.1, 'wild' => 0.2, 'crowd' => -0.1, 'confined' => -0.1],
                'Stoic'       => ['quiet' => 0.1],
            ],
            'trait_prefs' => [
                'egocentric' => ['luxury' => 0.3, 'wealth' => 0.2],
                'insecure'   => ['crowd' => -0.2],
            ],
        ];
    }

    /** The derivation tables: stored config per table, defaults for the rest. */
    public static function getPreferenceConfig(): array
    {
        $defaults = self::preferenceDefaults();
        $stored = RelationshipDynamics::getConfig()['facet_preferences'] ?? null;
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** A zero preference for every facet, in FACETS order. */
    public static function neutralPreferences(): array
    {
        return array_fill_keys(self::FACETS, 0.0);
    }

    /**
     * Derive signed preferences from a core_npc_master row (as PostgreSQL returns it, jsonb
     * as text; [] when the NPC has none) and the NPC's profile ['temperament', 'traits'].
     * Pure: the same row, profile and config give the same result.
     *
     * @return array ['prefs' => facet => -1..+1 (every facet, FACETS order), 'signals' => string[]]
     */
    public static function derivePreferences(array $row, array $profile, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getPreferenceConfig();
        $sum = self::neutralPreferences();
        $signals = [];
        $add = function (array $contrib, float $weight, string $signal) use (&$sum, &$signals) {
            if ($weight <= 0) return;
            $used = false;
            foreach ($contrib as $facet => $v) {
                if (!array_key_exists($facet, $sum) || !is_numeric($v)) continue;
                $sum[$facet] += floatval($v) * $weight;
                $used = true;
            }
            if ($used) $signals[] = $weight == 1.0 ? $signal : sprintf('%s x%.2f', $signal, $weight);
        };

        $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
        $meta = RelationshipDynamics::decodeProfileJson($row['metadata'] ?? null);
        [$classArch, $factionArchs] = RelationshipDynamics::profileArchetypes($ext, $meta, RelationshipDynamics::getTemperamentAutogenConfig());
        $archPrefs = (array) ($cfg['archetype_prefs'] ?? []);
        if ($classArch !== null) {
            $add((array) ($archPrefs[$classArch] ?? []), floatval($cfg['class_weight'] ?? 1.0), "class:{$classArch}");
        }
        foreach ($factionArchs as $fa) {
            if ($fa === $classArch) continue;   // the class already speaks for this archetype
            $add((array) ($archPrefs[$fa] ?? []), floatval($cfg['faction_weight'] ?? 0.5), "faction:{$fa}");
        }

        // Skill levels are Skyrim skill levels (0-100) sent as strings.
        $skills = array_change_key_case((array) ($meta['skills'] ?? []), CASE_LOWER);
        $minLevel = floatval($cfg['skills_min_level'] ?? 25);
        foreach ((array) ($cfg['skill_facets'] ?? []) as $skill => $contrib) {
            $level = floatval($skills[strtolower((string) $skill)] ?? 0);
            if ($level <= $minLevel || $minLevel >= 100) continue;
            $add((array) $contrib, min(1.0, ($level - $minLevel) / (100.0 - $minLevel)), "skill:{$skill}");
        }

        $temperament = RelationshipDynamics::validTemperament($profile['temperament'] ?? null);
        if ($temperament !== null) {
            $add((array) (((array) ($cfg['temperament_prefs'] ?? []))[$temperament] ?? []), 1.0, "temperament:{$temperament}");
        }
        foreach ((array) ($profile['traits'] ?? []) as $trait) {
            $trait = strtolower(trim((string) $trait));
            $add((array) (((array) ($cfg['trait_prefs'] ?? []))[$trait] ?? []), 1.0, "trait:{$trait}");
        }

        foreach ($sum as $facet => $v) {
            $sum[$facet] = round(max(-1.0, min(1.0, $v)), 3);
        }
        return ['prefs' => $sum, 'signals' => $signals];
    }

    /** What the stored derivation depends on besides the core row: profile and config. */
    private static function preferenceBasis(array $dynamics, array $cfg): string
    {
        return md5(json_encode([
            self::PREFS_VERSION,
            RelationshipDynamics::validTemperament($dynamics['inferred_temperament'] ?? null),
            RelationshipDynamics::getTraits($dynamics),
            $cfg,
        ]));
    }

    /** The profile the derivation reads from RelDyn state. */
    private static function preferenceProfile(array $dynamics): array
    {
        return ['temperament' => $dynamics['inferred_temperament'] ?? null, 'traits' => RelationshipDynamics::getTraits($dynamics)];
    }

    /**
     * Derive the NPC's automatic preferences and store them in $dynamics['_facet_prefs']
     * (version, basis, prefs, signals) when missing or out of date (temperament, traits or
     * config changed). Returns true when it changed $dynamics. A failed core read is logged
     * and nothing is stored, so the next call tries again.
     */
    public static function ensurePreferences(string $npcName, array &$dynamics): bool
    {
        $cfg = self::getPreferenceConfig();
        $basis = self::preferenceBasis($dynamics, $cfg);
        if (($dynamics['_facet_prefs']['basis'] ?? null) === $basis && is_array($dynamics['_facet_prefs']['prefs'] ?? null)) {
            return false;
        }
        try {
            $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
        } catch (Throwable $e) {
            error_log("[RelDyn] facet preferences: core_npc_master read failed for {$npcName}: " . $e->getMessage());
            return false;
        }
        $d = self::derivePreferences($row, self::preferenceProfile($dynamics), $cfg);
        $dynamics['_facet_prefs'] = ['version' => self::PREFS_VERSION, 'basis' => $basis, 'prefs' => $d['prefs'], 'signals' => $d['signals']];
        RelationshipDynamics::log("Facet preferences for {$npcName}: [" . implode(' ', $d['signals']) . ']');
        return true;
    }

    /**
     * The NPC's signed preferences, facet => -1..+1: auto-derived from class, skills,
     * temperament and traits, with the per-NPC override on top. Appraisal lane.
     *
     * Uses the stored derivation when it is current (ensurePreferences), else derives now
     * (one core_npc_master read; a failed read is logged and the derivation uses the
     * profile alone). Overrides: $dynamics['facet_pref_overrides'] facet => -1..+1.
     */
    public static function preferences(array $dynamics, string $npcName): array
    {
        $cfg = self::getPreferenceConfig();
        $stored = $dynamics['_facet_prefs'] ?? null;
        if (is_array($stored) && ($stored['basis'] ?? null) === self::preferenceBasis($dynamics, $cfg) && is_array($stored['prefs'] ?? null)) {
            $prefs = array_replace(self::neutralPreferences(), array_intersect_key(array_map('floatval', $stored['prefs']), self::neutralPreferences()));
        } else {
            $row = [];
            try {
                $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
            } catch (Throwable $e) {
                error_log("[RelDyn] facet preferences: core_npc_master read failed for {$npcName}, deriving from the profile only: " . $e->getMessage());
            }
            $prefs = self::derivePreferences($row, self::preferenceProfile($dynamics), $cfg)['prefs'];
        }
        foreach ((array) ($dynamics['facet_pref_overrides'] ?? []) as $facet => $v) {
            if (array_key_exists($facet, $prefs) && is_numeric($v)) {
                $prefs[$facet] = max(-1.0, min(1.0, floatval($v)));
            }
        }
        return $prefs;
    }

    /**
     * Set (or with null, clear) one per-NPC preference override. Returns false and changes
     * nothing for an unknown facet or a value outside -1..+1.
     */
    public static function setPreferenceOverride(array &$dynamics, string $facet, ?float $value): bool
    {
        if (!in_array($facet, self::FACETS, true)) return false;
        if ($value !== null && ($value < -1.0 || $value > 1.0 || is_nan($value))) return false;
        $overrides = (array) ($dynamics['facet_pref_overrides'] ?? []);
        if ($value === null) unset($overrides[$facet]); else $overrides[$facet] = $value;
        $dynamics['facet_pref_overrides'] = $overrides;
        return true;
    }

    /**
     * MDD 1.2 interest multiplier (0.5x .. 2.0x, 1.0 = indifferent) from a signed preference
     * or appraisal valence p (-1..+1). The documented mapping, piecewise linear:
     *   p >= 0: 1 + p        (+1 -> 2.0x, +0.5 -> 1.5x)
     *   p <  0: 1 + p / 2    (-1 -> 0.5x, -0.6 -> 0.7x)
     */
    public static function interestMultiplier(float $p): float
    {
        $p = max(-1.0, min(1.0, $p));
        return $p >= 0 ? 1.0 + $p : 1.0 + $p / 2.0;
    }

    /**
     * Appraise a facet vector against preferences. Appraisal lane.
     *
     * @return array ['valence' => -1..1, 'intensity' => 0..1, 'dominant' => facet|null,
     *                'dominant_sign' => 1|-1, 'contributions' => facet => number]
     */
    public static function appraise(array $prefs, array $facets): array
    {
        // contribution = facet weight (clamped 0..1) x preference (clamped -1..+1)
        // valence   = sum(contributions) / sum(facet weights)   -> -1..+1 (weighted mean preference)
        // intensity = sum(|contributions|) / sum(facet weights) ->  0..1  (how strongly it is felt:
        //             a place that is both loved and hated is intense even when valence is ~0)
        // dominant  = facet with the biggest |contribution|, ties to the earlier FACETS entry
        $contributions = [];
        $weight = 0.0;
        $sum = 0.0;
        $abs = 0.0;
        $dominant = null;
        $best = 0.0;
        foreach (self::FACETS as $facet) {
            if (!isset($facets[$facet]) || !is_numeric($facets[$facet])) continue;
            $w = max(0.0, min(1.0, floatval($facets[$facet])));
            if ($w <= 0.0) continue;
            $p = max(-1.0, min(1.0, floatval($prefs[$facet] ?? 0.0)));
            $c = $w * $p;
            $contributions[$facet] = $c;
            $weight += $w;
            $sum += $c;
            $abs += abs($c);
            if (abs($c) > $best + 1e-12) {
                $best = abs($c);
                $dominant = $facet;
            }
        }
        if ($weight <= 0.0) {
            return ['valence' => 0.0, 'intensity' => 0.0, 'dominant' => null, 'dominant_sign' => 1, 'contributions' => []];
        }
        return [
            'valence'       => max(-1.0, min(1.0, $sum / $weight)),
            'intensity'     => min(1.0, $abs / $weight),
            'dominant'      => $dominant,
            'dominant_sign' => ($dominant !== null && $contributions[$dominant] < 0) ? -1 : 1,
            'contributions' => $contributions,
        ];
    }

    /**
     * Prose for the LLM (a feeling, never numbers) from the appraisal's dominant facet,
     * sign and intensity; null when there is nothing worth saying. Appraisal lane.
     *
     * @param string $kind item|topic|creature|place|activity
     */
    public static function feltText(string $npcName, array $appraisal, string $kind, string $name): ?string
    {
        return null;
    }
}
