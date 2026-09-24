<?php
/**
 * Relationship Dynamics — Attraction Matrix (MDD §2, §1.4, §6.2, §8.2-8.3; decisions
 * 2026-09-24 §9; attraction design memory: "a bouncer, not an emotion").
 *
 * The Matrix is a sociological pre-filter on the player, read through THIS NPC's eyes. It adds
 * no affinity track, no decay and no rubber band. It answers four questions per NPC:
 *   1. How does the player score on the four pillars (beauty, strength, status, competence)
 *      as this NPC defines them? Pillars are NPC-subjective (MDD 2.1-2.4):
 *        - strength and competence are read through a "lens" of the player archetypes the NPC
 *          values, auto-derived from its signed facet preferences (MDD 2.2: NPC-weighted
 *          skills): score = (1 - share) x the generic pillar + share x fit, fit = the best
 *          (lens weight x the player's MAGNITUDE as that archetype: RelDynPlayer's raw archetype
 *          score, not the identity that reads 1.0 for whatever the player mostly is). Aela
 *          values warrior / hunter / druid strength; a bard's or scholar's barely counts, and a
 *          weak warrior is still weak ("she compromises on WHERE points are, not on WHETHER you
 *          have them"); Farengar reads a scholar's magic as strength;
 *        - status is the NPC's own markers (MDD 2.3 "Aela: only Companions rank"): the standing
 *          of the factions the NPC belongs to (core_npc_master factions -> questline evidence),
 *          blended with the generic economic footprint / property / standing by status_share;
 *        - beauty is keyword overlap of the player's appearance text with the NPC's beauty
 *          keywords (MDD 2.1, auto-generated from class); no appearance text = unknown.
 *      Speech lifts every pillar up to ~15% (MDD 2.5).
 *   2. Does the player pass? Each pillar has a rigidity (rigid / flexible / soft / irrelevant).
 *      Openness (MDD 1.4) tolerates near misses: low = hard block, medium / high = a tolerated
 *      fail with a lower passion ceiling and twice the effort to advance.
 *   3. What may grow? Visceral pass (beauty + strength) unlocks passion per the NPC's intimacy
 *      gate; sociological pass (status + competence) unlocks commitment and depth past Fond.
 *      Sociological-only = friendzone (passion hard-capped at 20, MDD 6.2); visceral-only =
 *      hookup (crush, no commitment). The relationship preference (demisexual, asexual,
 *      aromantic, ...) filters the romance axis.
 *   4. How high can it go now? The tier ceiling (MDD 8, plan §5): a newly met threshold LIFTS
 *      the ceiling, but advancing to it takes significant interactions (eval significance);
 *      openness, attachment and maturity set how many. Only a bouncer can hold a lift: an NPC
 *      none of whose pillars gates (all soft / irrelevant / unknown) lets the relationship
 *      grow with affinity. What the relationship already was when the Matrix first saw it
 *      (core affinity tier, core romance type) is grandfathered: the Matrix never takes it
 *      away, for as long as core still holds it.
 *
 * Units: pillar scores, lens weights, archetype scores, openness are 0..1; passion caps are
 * passion points (0..passion_max); affinity is CORE affinity (-100..+100); significance is the
 * eval contract's 0..1. Every tunable lives in config key 'attraction' (defaults()).
 * The LLM gets felt prose from feltText(), never numbers.
 */

require_once __DIR__ . '/relationship_dynamics.php';
if (!class_exists('RelDynPlayer')) {
    require_once __DIR__ . '/reldyn_player.php';
}

class RelDynAttraction
{
    const PILLARS = ['beauty', 'strength', 'status', 'competence'];
    const VISCERAL = ['beauty', 'strength'];
    const SOCIOLOGICAL = ['status', 'competence'];
    const RIGIDITIES = ['rigid', 'flexible', 'soft', 'irrelevant'];
    const GATES = ['visceral', 'bond', 'balanced'];
    /** The player profile contract's archetypes (RelDynPlayer::profile()['archetypes']). */
    const PLAYER_ARCHETYPES = ['warrior', 'mage', 'druid', 'thief', 'bard', 'scholar', 'smith', 'hunter', 'healer', 'noble'];
    /** Depth axis: RelDyn tiers on core affinity (RelationshipDynamics::RELATIONSHIP_TIERS), lowest reachable first. */
    const DEPTH_TIERS = ['acquaintance', 'friend', 'close_friend', 'bonded', 'devoted'];
    /** Romance axis levels. */
    const ROMANCE_NONE = 0;
    const ROMANCE_CRUSH = 1;
    const ROMANCE_FULL = 2;
    const OPENNESS_BANDS = ['low', 'medium', 'high'];
    const PREFERENCES = ['monogamous', 'polyamorous', 'uncommitted', 'not_interested', 'demisexual', 'asexual', 'aromantic'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Default tables (config key 'attraction'). Values from the MDD / plan / rulings are marked;
     * the rest are RelDyn's starting values (listed in the batch report).
     */
    public static function defaults(): array
    {
        return [
            // Pillar score (0..1) a rigid pillar must reach (MDD 2 / plan §4: 0.4)
            'pass_threshold' => 0.4,
            // A flexible pillar's bar = pass_threshold x this (plan §4: 0.6)
            'flexible_factor' => 0.6,
            // Effective tolerance (maturity x attachment, 0..1) lowers every bar by up to this
            // fraction (plan §8: "slightly lower", live code 20%)
            'tolerance_threshold_cut' => 0.2,
            // MDD 2.5: Speech 100 lifts every pillar score by up to ~15%
            'speech_boost_max' => 0.15,
            // Pillar score used for beauty while the profile has no appearance read (null):
            // neutral; an unknown beauty never blocks a pass or a tier
            'beauty_unknown_score' => 0.5,
            // Same for strength / status / competence when the profile has no data for them
            // (RelDynPlayer::profile() returns null, not 0, for an unread pillar)
            'unknown_pillar_score' => 0.5,
            // Share of a pillar read through the NPC's lens: score = (1 - share) x generic +
            // share x fit, fit = max over archetypes of lens weight x the player's magnitude as
            // that archetype (profile archetype_raw; a profile without it: identity x generic).
            'lens_share' => ['beauty' => 0.0, 'strength' => 0.8, 'status' => 0.0, 'competence' => 0.5],
            // MDD 2.3 status markers: an NPC in a faction (core_npc_master extended_data.factions
            // name, case-insensitive substring) measures standing by that faction's deeds, as
            // RelDynPlayer evidence tables (key => [half, weight]).
            'status_markers' => [
                'companions'          => ['stat:The Companions Quests Completed' => [3, 1.0], 'questline:companions' => [3, 0.8]],
                'collegeofwinterhold' => ['stat:College of Winterhold Quests Completed' => [3, 1.0], 'questline:college' => [3, 0.8]],
                'thievesguild'        => ["stat:Thieves' Guild Quests Completed" => [3, 1.0], 'questline:thieves_guild' => [3, 0.8]],
                'darkbrotherhood'     => ['stat:The Dark Brotherhood Quests Completed' => [3, 1.0], 'questline:dark_brotherhood' => [3, 0.8]],
                'dawnguard'           => ['questline:dawnguard' => [3, 1.0]],
                'cwimperial'          => ['stat:Civil War Quests Completed' => [4, 1.0], 'questline:civil_war' => [4, 0.8]],
                'cwsons'              => ['stat:Civil War Quests Completed' => [4, 1.0], 'questline:civil_war' => [4, 0.8]],
                'jobjarl'             => ['stat:Questlines Completed' => [2, 1.0], 'stat:Houses Owned' => [1, 0.6], 'ledger:moved' => [20000, 0.5]],
            ],
            // Share of the status pillar read from the NPC's own markers when it has any (rest:
            // the generic footprint / property / standing); npc_overrides may set 1.0
            'status_share' => 0.7,
            // MDD 2.1 beauty: NPC keywords per archetype profile (auto-gen from class). Score =
            // min(1, base + per_hit x keywords found in the appearance text); base = a described
            // player with none of this NPC's words; no appearance text = unknown (never gates).
            'beauty' => [
                'base' => 0.3,
                'per_hit' => 0.25,
                'keywords' => [
                    'Warrior'   => ['muscular', 'rugged', 'warrior', 'strong', 'scarred', 'scar', 'battle-worn', 'broad-shouldered', 'powerful', 'athletic', 'war paint'],
                    'Guard'     => ['strong', 'rugged', 'tall', 'broad-shouldered', 'disciplined', 'scarred', 'steady'],
                    'Barbarian' => ['muscular', 'rugged', 'wild', 'strong', 'scarred', 'scar', 'war paint', 'powerful', 'fierce', 'weathered'],
                    'Ranger'    => ['rugged', 'athletic', 'lean', 'strong', 'scarred', 'scar', 'weathered', 'war paint', 'wild', 'fierce', 'muscular'],
                    'Mage'      => ['intense eyes', 'piercing', 'mysterious', 'sharp features', 'ethereal', 'scholarly', 'thoughtful', 'striking'],
                    'Thief'     => ['lithe', 'lean', 'sly', 'sharp', 'nimble', 'dark', 'mysterious', 'clever'],
                    'Assassin'  => ['lithe', 'lean', 'dark', 'mysterious', 'sharp', 'cold', 'pale', 'striking'],
                    'Healer'    => ['kind', 'gentle', 'warm', 'soft-spoken', 'calm', 'clean', 'bright eyes'],
                    'Noble'     => ['refined', 'elegant', 'well-dressed', 'clean', 'tall', 'commanding', 'noble', 'graceful', 'poised'],
                    'Merchant'  => ['well-dressed', 'clean', 'handsome', 'beautiful', 'confident', 'refined', 'fine clothes'],
                    'Bard'      => ['charming', 'beautiful', 'handsome', 'graceful', 'expressive', 'bright eyes', 'striking', 'elegant'],
                    'default'   => ['handsome', 'beautiful', 'pretty', 'attractive', 'striking', 'fair', 'comely', 'kind'],
                ],
            ],
            // sum(facet preference x facet_archetypes) at which the NPC fully values an archetype
            'lens_full_at' => 0.6,
            // Facet (RelDynFacets::FACETS) -> player archetypes it speaks for (0..1)
            'facet_archetypes' => [
                'combat'     => ['warrior' => 1.0, 'hunter' => 0.6, 'druid' => 0.3, 'mage' => 0.2, 'thief' => 0.1],
                'nature'     => ['druid' => 1.0, 'hunter' => 0.8, 'healer' => 0.2],
                'wild'       => ['druid' => 0.5, 'hunter' => 0.5],
                'adventure'  => ['hunter' => 0.4, 'thief' => 0.4, 'warrior' => 0.3, 'mage' => 0.2],
                'danger'     => ['warrior' => 0.3, 'hunter' => 0.3],
                'scholarly'  => ['scholar' => 1.0, 'mage' => 0.7, 'bard' => 0.2],
                'enchanting' => ['mage' => 0.8, 'scholar' => 0.4, 'smith' => 0.3],
                'alchemy'    => ['healer' => 0.6, 'druid' => 0.4, 'mage' => 0.2],
                'crafting'   => ['smith' => 1.0],
                'social'     => ['bard' => 1.0, 'noble' => 0.5],
                'crowd'      => ['bard' => 0.3],
                'wealth'     => ['noble' => 0.8, 'thief' => 0.5],
                'luxury'     => ['noble' => 0.6],
                'spiritual'  => ['healer' => 0.8, 'druid' => 0.3],
                'sacred'     => ['healer' => 0.4],
                'dark'       => ['thief' => 0.5],
                'domestic'   => ['healer' => 0.2, 'smith' => 0.2],
                'quiet'      => ['scholar' => 0.2],
            ],
            // Per NPC archetype (the profile auto-generation's class/faction/skill archetype):
            // pillar rigidity, intimacy gate (plan §7) and pillar weights. The plan §3 presets:
            // Warrior; Primal (Ranger, Barbarian: Aela = Primal); Scholar (Mage); Rogue (Thief,
            // Assassin); Priest (Healer); Noble; Bard. Merchant, Guard and default are RelDyn's.
            'archetype_profiles' => [
                'Warrior'   => ['rigidity' => ['beauty' => 'rigid', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'rigid'], 'gate' => 'visceral'],
                'Guard'     => ['rigidity' => ['beauty' => 'soft', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'rigid'], 'gate' => 'balanced'],
                'Barbarian' => ['rigidity' => ['beauty' => 'rigid', 'strength' => 'flexible', 'status' => 'rigid', 'competence' => 'rigid'], 'gate' => 'visceral'],
                'Ranger'    => ['rigidity' => ['beauty' => 'rigid', 'strength' => 'flexible', 'status' => 'rigid', 'competence' => 'rigid'], 'gate' => 'visceral'],
                'Mage'      => ['rigidity' => ['beauty' => 'soft', 'strength' => 'flexible', 'status' => 'rigid', 'competence' => 'rigid'], 'gate' => 'bond'],
                'Thief'     => ['rigidity' => ['beauty' => 'flexible', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'soft'], 'gate' => 'balanced'],
                'Assassin'  => ['rigidity' => ['beauty' => 'flexible', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'soft'], 'gate' => 'balanced'],
                'Healer'    => ['rigidity' => ['beauty' => 'soft', 'strength' => 'irrelevant', 'status' => 'soft', 'competence' => 'soft'], 'gate' => 'bond'],
                'Noble'     => ['rigidity' => ['beauty' => 'soft', 'strength' => 'irrelevant', 'status' => 'rigid', 'competence' => 'rigid'], 'gate' => 'bond'],
                'Merchant'  => ['rigidity' => ['beauty' => 'soft', 'strength' => 'irrelevant', 'status' => 'rigid', 'competence' => 'soft'], 'gate' => 'balanced'],
                'Bard'      => ['rigidity' => ['beauty' => 'rigid', 'strength' => 'irrelevant', 'status' => 'soft', 'competence' => 'irrelevant'], 'gate' => 'visceral'],
                'default'   => ['rigidity' => ['beauty' => 'soft', 'strength' => 'soft', 'status' => 'soft', 'competence' => 'soft'], 'gate' => 'balanced'],
            ],
            // Trait tag (decisions §1) -> pillar weight multipliers (egocentric: standing matters more)
            'trait_weights' => [
                'egocentric' => ['status' => 1.5],
            ],
            // MDD 1.3 Openness column: temperament -> band
            'temperament_openness' => [
                'Romantic' => 'high', 'Anxious' => 'high', 'Bold' => 'medium', 'Playful' => 'high',
                'Humble' => 'medium', 'Nurturing' => 'high', 'Gentle' => 'high', 'Jealous' => 'low',
                'Proud' => 'low', 'Defiant' => 'medium', 'Guarded' => 'low', 'Independent' => 'low', 'Stoic' => 'low',
            ],
            // MDD 1.4 openness values (0..1) per band; a numeric openness maps to the nearest band
            'openness_levels' => ['low' => 0.3, 'medium' => 0.6, 'high' => 0.9],
            // How far below a pillar's bar a fail is still tolerated, as a FRACTION of that bar
            // (near is relative: a flexible bar is lower than a rigid one). Low = hard block
            // (MDD 1.4); medium / high tolerate near misses only, so a player far off the mark
            // stays unattractive (rulings §9: Aela tolerates a bard, no passion)
            'openness_margin' => ['low' => 0.0, 'medium' => 0.25, 'high' => 0.5],
            // MDD 1.4: a tolerated fail cuts the passion ceiling by this fraction
            'openness_passion_ceiling_cut' => ['low' => 1.0, 'medium' => 0.5, 'high' => 0.2],
            // MDD 1.4: low openness triggers the Ick faster when the player pushes past a failed
            // check (multiplier on the Ick threshold; lower = faster)
            'openness_ick_mult' => ['low' => 0.6, 'medium' => 1.0, 'high' => 1.3],
            // MDD 1.4: a tolerated fail needs this many times the interactions to advance
            'tolerated_pace_mult' => 2.0,
            'passion' => [
                // Passing: passion gain x lerp(passing_min_mult, 1, attraction score)
                'passing_min_mult' => 0.5,
                // Visceral fail: passion gain multiplier (plan §7 visceral gate 0.1)
                'unattracted_mult' => 0.1,
                // Bond-gated NPC before the bond: passion gain multiplier (plan §7 "max 0.3 until bonded")
                'bond_prebond_mult' => 0.3,
                // Passion points: MDD 6.2 friendzone hard cap
                'friendzone_cap' => 20,
                // Passion points: not attracted at all (MDD 8.1 Unknown/Acquaintance passion ceiling)
                'unattracted_cap' => 20,
                // Attachment style (MDD 6.1) -> passion gain speed: anxious attaches fast,
                // avoidant slow (rulings §9 "passion considers attachment style")
                'attachment_mult' => ['anxious' => 1.3, 'secure' => 1.0, 'avoidant' => 0.7, 'toxic' => 1.2],
            ],
            // Bond gate (plan §7): passion opens at this RelDyn tier on core affinity
            'bond_gate_tier' => 'bonded',
            // Pillar scores (0..1) each depth tier needs (plan §5 / roadmap: friend status .3,
            // close beauty .4 + strength .3, bonded all .5, sworn = devoted .6/.6/.7/.7), and
            // the romance axis's crush (beauty .3). Only this NPC's rigid / flexible pillars gate;
            // soft and irrelevant pillars and an unknown beauty count as met.
            'tier_requirements' => [
                'friend'       => ['status' => 0.3],
                'close_friend' => ['beauty' => 0.4, 'strength' => 0.3],
                'bonded'       => ['beauty' => 0.5, 'strength' => 0.5, 'status' => 0.5, 'competence' => 0.5],
                'devoted'      => ['beauty' => 0.6, 'strength' => 0.6, 'status' => 0.7, 'competence' => 0.7],
            ],
            'romance_requirements' => ['beauty' => 0.3],
            // MDD 2.6 filter on the depth axis: sociological fail = no advancement past Fond
            // (close_friend); friendzone ceiling close_friend (plan §6); neither = acquaintance
            'depth_cap_without_sociological' => 'close_friend',
            'depth_cap_friendzone' => 'close_friend',
            'depth_cap_neither' => 'acquaintance',
            // Core relationships.Player.type -> romance level it needs (1 crush, 2 full)
            'romance_types' => ['crush' => 1, 'romantic' => 2],
            // Relationship preference filter (roadmap relationship-preference-type-filter):
            // romance_max = highest romance level; intimacy = handoff to Sharmat possible.
            // Demisexual (pipeline doc Phase 3): romance and passion wait for core affinity
            // bond_core_aff; intimacy needs peak core affinity intimacy_peak_core_aff and the
            // intimacy_min_tier maintained (memory: peak 100 AND Fond+).
            'preferences' => [
                'monogamous'     => ['romance_max' => 2, 'intimacy' => true],
                'polyamorous'    => ['romance_max' => 2, 'intimacy' => true],
                'uncommitted'    => ['romance_max' => 1, 'intimacy' => true],
                'not_interested' => ['romance_max' => 0, 'intimacy' => false],
                'aromantic'      => ['romance_max' => 0, 'intimacy' => false],
                'asexual'        => ['romance_max' => 2, 'intimacy' => false],
                'demisexual'     => ['romance_max' => 2, 'intimacy' => true, 'bond_core_aff' => 60,
                                     'intimacy_peak_core_aff' => 100, 'intimacy_min_tier' => 'close_friend'],
            ],
            // Tier advancement after a lift: significant interactions needed =
            //   round(base_by_gate x openness_pace x attachment_pace x maturity_pace [x tolerated_pace_mult])
            // clamped to 1..max_needed. maturity_pace runs linearly from maturity_pace_min at
            // maturity 0 to maturity_pace_max at 100 (mature = deliberate). Memory: Aela 2-3,
            // a cautious noble 10-15.
            'advance' => [
                'significance_min' => 0.6,   // eval contract significance (0..1) that counts
                'base_by_gate' => ['visceral' => 2, 'balanced' => 4, 'bond' => 6],
                'openness_pace' => ['low' => 1.5, 'medium' => 1.0, 'high' => 0.8],
                'attachment_pace' => ['anxious' => 0.6, 'secure' => 1.0, 'avoidant' => 1.4, 'toxic' => 0.8],
                'maturity_pace_min' => 0.8,
                'maturity_pace_max' => 1.2,
                'max_needed' => 30,
            ],
            // Named NPCs (lower-case npc_name): presets for that NPC only. Keys: openness,
            // rigidity, gate, weights, lens, lens_share, gender_pref, status_markers,
            // status_share, beauty_keywords.
            'npc_overrides' => [
                // Memory (attraction design): "Aela is not high openness ... medium to medium-low"
                // MDD 2.3: "Aela: only Companions rank"
                'aela the huntress' => ['openness' => 'medium', 'status_share' => 1.0],
            ],
        ];
    }

    /** Stored tables replace defaults table by table (like the other RelDyn config tables). */
    public static function config(): array
    {
        $defaults = self::defaults();
        $stored = RelationshipDynamics::getConfig()['attraction'] ?? null;
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    // =====================================================================
    // PER-NPC DEFINITION
    // =====================================================================

    /**
     * The NPC's attraction definition, auto-derived and deterministic:
     *   archetype profile (from _profile_autogen.archetype) -> rigidity, gate
     *   traits -> pillar weights
     *   signed facet preferences -> the strength / competence lens over player archetypes
     *   temperament -> openness band (MDD 1.3)
     *   the NPC's core factions -> status markers (status_markers), archetype -> beauty keywords
     * Overrides, lowest to highest: named preset (config npc_overrides), the NPC editor's PR 11
     * attraction_profile (pillar_rigidity, intimacy_gate, gender_pref, beauty_keywords), then
     * $dynamics['attraction_overrides'] (rigidity, gate, weights, lens, lens_share, openness,
     * gender_pref, status_markers, status_share, beauty_keywords). $dynamics['openness']
     * (editor dropdown) beats the temperament default.
     */
    public static function definition(string $npcName, array $dynamics, ?array $prefs = null): array
    {
        $cfg = self::config();
        $arch = $dynamics['_profile_autogen']['archetype'] ?? null;
        $profiles = (array) $cfg['archetype_profiles'];
        $base = (array) ($profiles[$arch] ?? $profiles['default'] ?? []);
        $sources = ['archetype' => $arch ?? 'default'];
        $preset = (array) (((array) $cfg['npc_overrides'])[strtolower(trim($npcName))] ?? []);
        $editor = is_array($dynamics['attraction_profile'] ?? null) ? $dynamics['attraction_profile'] : [];
        $over = is_array($dynamics['attraction_overrides'] ?? null) ? $dynamics['attraction_overrides'] : [];

        // Rigidity per pillar
        $rigidity = [];
        foreach (self::PILLARS as $p) {
            $rigidity[$p] = self::validRigidity($base['rigidity'][$p] ?? null) ?? 'soft';
            foreach ([['preset', $preset['rigidity'] ?? null], ['editor', $editor['pillar_rigidity'] ?? null], ['override', $over['rigidity'] ?? null]] as [$src, $table]) {
                $v = is_array($table) ? self::validRigidity($table[$p] ?? null) : null;
                if ($v !== null) { $rigidity[$p] = $v; $sources["rigidity.{$p}"] = $src; }
            }
        }

        // Intimacy gate
        $gate = in_array($base['gate'] ?? null, self::GATES, true) ? $base['gate'] : 'balanced';
        foreach ([['preset', $preset['gate'] ?? null], ['editor', $editor['intimacy_gate'] ?? null], ['override', $over['gate'] ?? null]] as [$src, $g]) {
            if (in_array($g, self::GATES, true)) { $gate = $g; $sources['gate'] = $src; }
        }

        // Pillar weights: 1 each, traits scale, irrelevant = 0
        $weights = array_fill_keys(self::PILLARS, 1.0);
        foreach ((array) ($base['weights'] ?? []) as $p => $w) {
            if (isset($weights[$p]) && is_numeric($w)) $weights[$p] = max(0.0, floatval($w));
        }
        foreach (RelationshipDynamics::getTraits($dynamics) as $trait) {
            foreach ((array) (((array) $cfg['trait_weights'])[$trait] ?? []) as $p => $m) {
                if (isset($weights[$p]) && is_numeric($m)) $weights[$p] *= max(0.0, floatval($m));
            }
        }
        foreach ([(array) ($preset['weights'] ?? []), (array) ($over['weights'] ?? [])] as $table) {
            foreach ($table as $p => $w) {
                if (isset($weights[$p]) && is_numeric($w)) $weights[$p] = max(0.0, floatval($w));
            }
        }
        foreach (self::PILLARS as $p) {
            if ($rigidity[$p] === 'irrelevant') $weights[$p] = 0.0;
        }

        // Lens: which player archetypes this NPC values, from its signed facet preferences
        $lensShare = [];
        foreach (self::PILLARS as $p) {
            $lensShare[$p] = max(0.0, min(1.0, floatval(((array) $cfg['lens_share'])[$p] ?? 0.0)));
            foreach ([(array) ($preset['lens_share'] ?? []), (array) ($over['lens_share'] ?? [])] as $table) {
                if (is_numeric($table[$p] ?? null)) $lensShare[$p] = max(0.0, min(1.0, floatval($table[$p])));
            }
        }
        if ($prefs === null && max($lensShare) > 0) {
            $prefs = RelDynFacets::preferences($dynamics, $npcName);
        }
        $derivedLens = self::lensFromPreferences((array) $prefs, $cfg);
        $lens = [];
        foreach (self::PILLARS as $p) {
            if ($lensShare[$p] <= 0) continue;
            $lens[$p] = $derivedLens;
            foreach ([['preset', $preset['lens'][$p] ?? null], ['override', $over['lens'][$p] ?? null]] as [$src, $table]) {
                if (is_array($table)) {
                    $lens[$p] = self::cleanLens($table);
                    $sources["lens.{$p}"] = $src;
                }
            }
        }

        // Openness band
        $temperament = RelationshipDynamics::validTemperament($dynamics['inferred_temperament'] ?? null);
        $band = ((array) $cfg['temperament_openness'])[$temperament ?? ''] ?? 'medium';
        $sources['openness'] = $temperament !== null ? "temperament:{$temperament}" : 'fallback';
        foreach ([['preset', $preset['openness'] ?? null], ['editor', $dynamics['openness'] ?? null], ['override', $over['openness'] ?? null]] as [$src, $o]) {
            $b = self::opennessBand($o, $cfg);
            if ($b !== null) { $band = $b; $sources['openness'] = $src; }
        }
        if (!in_array($band, self::OPENNESS_BANDS, true)) $band = 'medium';

        $genderPref = 'bisexual';
        foreach ([$preset['gender_pref'] ?? null, $editor['gender_pref'] ?? null, $over['gender_pref'] ?? null] as $g) {
            if (in_array($g, ['heterosexual', 'homosexual', 'bisexual'], true)) $genderPref = $g;
        }

        // Status markers (MDD 2.3): the standing of the factions this NPC belongs to
        $markers = [];
        foreach (self::npcFactions($npcName) as $faction) {
            foreach ((array) $cfg['status_markers'] as $pattern => $table) {
                if ($pattern !== '' && str_contains($faction, strtolower((string) $pattern))) {
                    foreach ((array) $table as $key => $spec) $markers[$key] = $spec;
                }
            }
        }
        $sources['status_markers'] = $markers ? 'factions' : 'none';
        foreach ([['preset', $preset['status_markers'] ?? null], ['override', $over['status_markers'] ?? null]] as [$src, $table]) {
            if (is_array($table)) { $markers = $table; $sources['status_markers'] = $src; }
        }
        $statusShare = max(0.0, min(1.0, floatval($cfg['status_share'])));
        foreach ([$preset['status_share'] ?? null, $over['status_share'] ?? null] as $s) {
            if (is_numeric($s)) $statusShare = max(0.0, min(1.0, floatval($s)));
        }

        // Beauty keywords (MDD 2.1), from the archetype profile
        $kwTable = (array) (((array) $cfg['beauty'])['keywords'] ?? []);
        $beautyKeywords = (array) ($kwTable[$arch] ?? $kwTable['default'] ?? []);
        foreach ([['preset', $preset['beauty_keywords'] ?? null], ['editor', $editor['beauty_keywords'] ?? null],
                     ['override', $over['beauty_keywords'] ?? null]] as [$src, $kw]) {
            if (is_array($kw)) { $beautyKeywords = $kw; $sources['beauty_keywords'] = $src; }
        }
        $beautyKeywords = array_values(array_filter(array_map(fn($k) => strtolower(trim((string) $k)), $beautyKeywords), fn($k) => $k !== ''));

        $pref = self::preferenceOf($dynamics);
        return [
            'archetype'  => $arch,
            'rigidity'   => $rigidity,
            'gate'       => $gate,
            'weights'    => $weights,
            'lens'       => $lens,
            'lens_share' => $lensShare,
            'openness'   => $band,
            'gender_pref'=> $genderPref,
            'preference' => $pref,
            'status_markers' => $markers,
            'status_share'   => $statusShare,
            'beauty_keywords'=> $beautyKeywords,
            'sources'    => $sources,
        ];
    }

    /** The NPC's faction names (core_npc_master extended_data.factions[].name), lower-case. */
    private static function npcFactions(string $npcName): array
    {
        try {
            $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
        } catch (Throwable $e) {
            error_log("[RelDyn] attraction: core_npc_master read failed for {$npcName}, no status markers: " . $e->getMessage());
            return [];
        }
        $ext = is_string($row['extended_data'] ?? null) ? json_decode($row['extended_data'], true) : ($row['extended_data'] ?? null);
        $out = [];
        foreach ((array) (is_array($ext) ? ($ext['factions'] ?? []) : []) as $f) {
            $name = is_array($f) ? (string) ($f['name'] ?? '') : (string) $f;
            if (trim($name) !== '') $out[] = strtolower(trim($name));
        }
        return $out;
    }

    /**
     * MDD 2.1 beauty through this NPC's eyes: min(1, base + per_hit x keywords found in the
     * appearance text), whole-word prefix match, hyphens read as spaces. null = no text.
     */
    public static function beautyScore(?string $appearance, array $keywords, ?array $cfg = null): ?float
    {
        $text = strtolower(trim((string) $appearance));
        if ($text === '') return null;
        $cfg = $cfg ?? self::config();
        $b = (array) $cfg['beauty'];
        $text = ' ' . preg_replace('/[^a-z0-9]+/', ' ', str_replace('-', ' ', $text)) . ' ';
        $hits = 0;
        foreach ($keywords as $kw) {
            $kw = trim(preg_replace('/[^a-z0-9]+/', ' ', str_replace('-', ' ', strtolower((string) $kw))));
            if ($kw !== '' && preg_match('/ ' . preg_quote($kw, '/') . '/', $text)) $hits++;
        }
        return max(0.0, min(1.0, floatval($b['base'] ?? 0.3) + floatval($b['per_hit'] ?? 0.25) * $hits));
    }

    /**
     * The NPC's relationship preference; the filter runs while the settings page's type
     * filter is on. It is the NPC's own trait, so it holds with or without player data.
     */
    private static function preferenceOf(array $dynamics): ?string
    {
        if (empty(RelationshipDynamics::getConfig()['type_filter_enabled'])) return null;
        $pref = strtolower(trim((string) ($dynamics['relationship_preference'] ?? '')));
        return in_array($pref, self::PREFERENCES, true) ? $pref : null;
    }

    /**
     * An open result (the Matrix does not judge the player) still carries the NPC's own
     * relationship preference: romance types above its romance_max are blocked (demisexual:
     * all of them until core affinity reaches bond_core_aff), intimacy follows the row, and
     * a romance_max of 0 caps passion like a friendzone (aromantic / not interested).
     */
    private static function withPreference(array $r, array $dynamics): array
    {
        $pref = self::preferenceOf($dynamics);
        if ($pref === null) return $r;
        $cfg = self::config();
        $row = (array) (((array) $cfg['preferences'])[$pref] ?? []);
        $max = intval($row['romance_max'] ?? self::ROMANCE_FULL);
        if ($pref === 'demisexual' && RelationshipDynamics::getCoreAffinity($dynamics) < floatval($row['bond_core_aff'] ?? 60)) {
            $max = self::ROMANCE_NONE;
        }
        $blocked = [];
        foreach ((array) $cfg['romance_types'] as $type => $level) {
            if (intval($level) > $max) $blocked[] = (string) $type;
        }
        $r['preference'] = $pref;
        $r['blocked_types'] = $blocked;
        $r['romance'] = ['allowed' => $max, 'earned' => $max, 'effective' => $max];
        $r['intimacy_allowed'] = !empty($row['intimacy'] ?? true) && $max > self::ROMANCE_NONE;
        if (intval($row['romance_max'] ?? self::ROMANCE_FULL) === self::ROMANCE_NONE) {
            $r['passion_cap'] = floatval(((array) $cfg['passion'])['friendzone_cap']);
        }
        $r['reason'] .= ", {$pref}";
        return $r;
    }

    /**
     * Player archetype => how much this NPC values it (0..1):
     * clamp(sum over facets of preference x facet_archetypes / lens_full_at, 0, 1).
     * A disliked facet (negative preference) subtracts: Aela's scholarly -0.6 zeroes scholars.
     */
    public static function lensFromPreferences(array $prefs, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $fullAt = max(0.0001, floatval($cfg['lens_full_at']));
        $lens = [];
        foreach (self::PLAYER_ARCHETYPES as $a) {
            $sum = 0.0;
            foreach ((array) $cfg['facet_archetypes'] as $facet => $row) {
                $p = $prefs[$facet] ?? 0;
                if (!is_numeric($p)) continue;
                $sum += floatval($p) * floatval(((array) $row)[$a] ?? 0.0);
            }
            $lens[$a] = round(max(0.0, min(1.0, $sum / $fullAt)), 4);
        }
        return $lens;
    }

    private static function cleanLens(array $table): array
    {
        $lens = array_fill_keys(self::PLAYER_ARCHETYPES, 0.0);
        foreach ($table as $a => $w) {
            if (array_key_exists($a, $lens) && is_numeric($w)) $lens[$a] = max(0.0, min(1.0, floatval($w)));
        }
        return $lens;
    }

    private static function validRigidity($r): ?string
    {
        return in_array($r, self::RIGIDITIES, true) ? $r : null;
    }

    /** 'low' / 'medium' / 'high', or a number (nearest band's openness_levels value); null if unusable. */
    public static function opennessBand($o, ?array $cfg = null): ?string
    {
        if (is_string($o)) {
            $o = strtolower(trim($o));
            if (in_array($o, self::OPENNESS_BANDS, true)) return $o;
            if (!is_numeric($o)) return null;
        }
        if (!is_numeric($o)) return null;
        $cfg = $cfg ?? self::config();
        $best = null;
        $bestDist = INF;
        foreach ((array) $cfg['openness_levels'] as $band => $level) {
            $d = abs(floatval($o) - floatval($level));
            if ($d < $bestDist) { $bestDist = $d; $best = $band; }
        }
        return $best;
    }

    // =====================================================================
    // EVALUATION (the shared contract: RelationshipDynamics::attractionFor)
    // =====================================================================

    /**
     * Speech as 0..1 from the profile: 'speech' (0..1), else the skill level (0..100) from
     * facts (RelDynPlayer: facts.skills.value.speechcraft, core's gamedata key).
     */
    public static function speechLevel(array $profile): float
    {
        if (is_numeric($profile['speech'] ?? null)) {
            return max(0.0, min(1.0, floatval($profile['speech'])));
        }
        $facts = (array) ($profile['facts'] ?? []);
        $candidates = [$facts['speech'] ?? null, $facts['skills']['value']['speechcraft'] ?? null,
            $facts['skills']['value']['speech'] ?? null, $facts['skills']['speech'] ?? null];
        foreach ($candidates as $c) {
            if (is_array($c)) $c = $c['value'] ?? null;
            if (is_numeric($c)) {
                $v = floatval($c);
                return max(0.0, min(1.0, $v > 1.0 ? $v / 100.0 : $v));
            }
        }
        return 0.0;
    }

    /** Result when the Matrix does not judge (switched off, or no player data): nothing gated. */
    private static function openResult(string $reason): array
    {
        $pillars = [];
        foreach (self::PILLARS as $p) {
            $pillars[$p] = ['score' => 1.0, 'weight' => 1.0, 'rigidity' => 'soft', 'pass' => true, 'known' => false, 'tolerated' => false];
        }
        return [
            'score' => 1.0, 'passes' => true, 'friendzoned' => false, 'pillars' => $pillars,
            'ceiling_tier' => null, 'reason' => $reason,
            'enabled' => false, 'visceral_pass' => true, 'sociological_pass' => true, 'gender_pass' => true,
            'gate' => 'balanced', 'openness' => 'medium', 'preference' => null, 'prebond' => false,
            'tolerated' => false, 'failed' => false, 'passion_mult' => 1.0, 'passion_cap' => null,
            'allowed_tier' => null, 'romance' => ['allowed' => self::ROMANCE_FULL, 'earned' => self::ROMANCE_FULL, 'effective' => self::ROMANCE_FULL],
            'blocked_types' => [], 'pending' => null, 'intimacy_allowed' => true, 'valued' => null,
        ];
    }

    /**
     * The Attraction Matrix for one NPC and one player profile. Pure: reads $dynamics (profile
     * autogen, preferences, traits, core affinity mirror, _attraction_state) and config; writes
     * nothing. Contract fields: score, passes, friendzoned, pillars, ceiling_tier, reason.
     */
    public static function evaluate(string $npcName, array $dynamics, array $profile, ?array $def = null): array
    {
        if (empty(RelationshipDynamics::getConfig()['attraction_matrix_enabled'])) {
            return self::withPreference(self::openResult('attraction matrix off'), $dynamics);
        }
        if (empty($profile['known'])) {
            return self::withPreference(self::openResult('no player data'), $dynamics);
        }
        $cfg = self::config();
        $def = $def ?? self::definition($npcName, $dynamics);
        $archetypes = (array) ($profile['archetypes'] ?? []);
        $generic = (array) ($profile['pillars'] ?? []);

        $boost = 1.0 + max(0.0, floatval($cfg['speech_boost_max'])) * self::speechLevel($profile);
        $tolerance = RelationshipDynamics::calculateEffectiveTolerance($dynamics);
        $bar = floatval($cfg['pass_threshold']) * (1.0 - floatval($cfg['tolerance_threshold_cut']) * $tolerance);
        $margin = max(0.0, floatval(((array) $cfg['openness_margin'])[$def['openness']] ?? 0.0));

        $pillars = [];
        $valued = null;
        foreach (self::PILLARS as $p) {
            $rig = $def['rigidity'][$p];
            // The pillar as THIS NPC reads it; null = unknown (neutral, never gates)
            $raw = match ($p) {
                'beauty' => is_numeric($generic['beauty'] ?? null) ? floatval($generic['beauty'])
                    : self::beautyScore(self::appearanceOf($profile), (array) ($def['beauty_keywords'] ?? []), $cfg),
                'status' => self::npcStatus($profile, $def),
                default  => is_numeric($generic[$p] ?? null) ? floatval($generic[$p]) : null,
            };
            $known = $raw !== null;
            if (!$known) {
                $score = max(0.0, min(1.0, floatval($p === 'beauty' ? $cfg['beauty_unknown_score'] : $cfg['unknown_pillar_score'])));
            } else {
                $score = max(0.0, min(1.0, $raw));
                $lens = $def['lens'][$p] ?? null;
                if (is_array($lens) && max(array_values($lens) ?: [0.0]) > 0) {
                    // How much of the player's magnitude is in a form this NPC values
                    $fit = 0.0;
                    foreach ($lens as $a => $w) {
                        $v = $w * self::archetypeMagnitude($profile, $a, $score);
                        if ($v > $fit) {
                            $fit = $v;
                            if ($p === 'strength') $valued = $a;
                        }
                    }
                    $share = $def['lens_share'][$p];
                    $score = (1.0 - $share) * $score + $share * $fit;
                }
                $score = min(1.0, $score * $boost);
            }
            $pillarBar = ($rig === 'flexible') ? $bar * floatval($cfg['flexible_factor']) : $bar;
            $pass = true;
            $tolerated = false;
            if ($known && ($rig === 'rigid' || $rig === 'flexible') && $score < $pillarBar) {
                if ($margin > 0 && $score >= $pillarBar * (1.0 - min(1.0, $margin))) {
                    $tolerated = true;
                } else {
                    $pass = false;
                }
            }
            $pillars[$p] = [
                'score' => round($score, 4), 'weight' => round($def['weights'][$p], 4), 'rigidity' => $rig,
                'pass' => $pass, 'known' => $known, 'tolerated' => $tolerated, 'bar' => round($pillarBar, 4),
            ];
        }

        $wSum = 0.0;
        $sSum = 0.0;
        foreach ($pillars as $row) {
            $wSum += $row['weight'];
            $sSum += $row['weight'] * $row['score'];
        }
        $score = $wSum > 0 ? $sSum / $wSum : 1.0;

        $visceral = $pillars['beauty']['pass'] && $pillars['strength']['pass'];
        $sociological = $pillars['status']['pass'] && $pillars['competence']['pass'];
        $tolerated = false;
        $failed = false;
        foreach ($pillars as $row) {
            $tolerated = $tolerated || $row['tolerated'];
            $failed = $failed || !$row['pass'] || $row['tolerated'];
        }

        $genderPass = self::genderPass($npcName, $def['gender_pref'], $profile);
        $prefRow = $def['preference'] !== null ? (array) (((array) $cfg['preferences'])[$def['preference']] ?? []) : [];
        $romanceMax = intval($prefRow['romance_max'] ?? self::ROMANCE_FULL);
        $coreAff = RelationshipDynamics::getCoreAffinity($dynamics);
        $tierNow = RelationshipDynamics::getCurrentTier($coreAff);
        $state = is_array($dynamics['_attraction_state'] ?? null) ? $dynamics['_attraction_state'] : null;

        // ---- Depth axis (allowed): pillar walk capped by the MDD 2.6 filter
        $walk = 'acquaintance';
        foreach (array_reverse(self::DEPTH_TIERS) as $tier) {
            $req = ((array) $cfg['tier_requirements'])[$tier] ?? null;
            if ($tier === 'acquaintance' || ($req !== null && self::meetsRequirements((array) $req, $pillars))) { $walk = $tier; break; }
        }
        if ($visceral && $sociological) {
            $cap = null;
        } elseif ($sociological) {
            $cap = (string) $cfg['depth_cap_friendzone'];
        } elseif ($visceral) {
            $cap = (string) $cfg['depth_cap_without_sociological'];
        } else {
            $cap = (string) $cfg['depth_cap_neither'];
        }
        $depthAllowed = ($cap !== null && self::depthRank($cap) < self::depthRank($walk)) ? $cap : $walk;
        // Grandfathered: what the relationship was when the Matrix first saw it, while core still holds it
        [$floorDepth, $floorRomance] = self::grandfatherFloor($dynamics, $state);
        if (self::depthRank($floorDepth) > self::depthRank($depthAllowed)) $depthAllowed = $floorDepth;
        // Only a bouncer holds a lift: an NPC none of whose pillars gates has no lift to earn
        $gating = false;
        foreach ($pillars as $row) {
            $gating = $gating || ($row['known'] && in_array($row['rigidity'], ['rigid', 'flexible'], true));
        }
        // Earned (tier advancement state): a lifted ceiling is reached only through significant interactions
        [$earnedDepth, $earnedRomance] = $state !== null
            ? [self::validDepth($state['depth'] ?? null) ?? 'acquaintance', max(0, min(2, intval($state['romance'] ?? 0)))]
            : self::initialEarned($dynamics);
        if (self::depthRank($floorDepth) > self::depthRank($earnedDepth)) $earnedDepth = $floorDepth;
        $earnedRomance = max($earnedRomance, $floorRomance);
        if (!$gating) $earnedDepth = 'devoted';   // follows the allowed ceiling (effective = allowed)
        $depthEff = self::depthRank($earnedDepth) < self::depthRank($depthAllowed) ? $earnedDepth : $depthAllowed;

        // ---- Gate and bond (plan §7). The bond is the tier on core affinity within the
        // ceiling; demisexual: the bond comes first whatever the archetype, at bond_core_aff.
        $gate = $def['gate'];
        $bondTier = (string) $cfg['bond_gate_tier'];
        $tierEff = RelationshipDynamics::tierRank($tierNow) > RelationshipDynamics::tierRank($depthEff) ? $depthEff : $tierNow;
        $bonded = RelationshipDynamics::tierRank($tierEff) >= RelationshipDynamics::tierRank($bondTier);
        $bondReachable = self::depthRank($depthAllowed) >= self::depthRank($bondTier);
        if ($def['preference'] === 'demisexual') {
            $gate = 'bond';
            $bonded = $coreAff >= floatval($prefRow['bond_core_aff'] ?? 60);
            $bondReachable = true;
            if (!$bonded) $romanceMax = self::ROMANCE_NONE;
        }
        $romanceCapable = $genderPass && intval($prefRow['romance_max'] ?? self::ROMANCE_FULL) > self::ROMANCE_NONE;

        // ---- Passion (rulings §9: attraction x attachment)
        $passionUnlocked = match ($gate) {
            'visceral' => $visceral,
            'bond'     => $visceral && $bonded,
            default    => $visceral || $bonded,
        };
        $passes = $romanceCapable && $romanceMax > self::ROMANCE_NONE && $passionUnlocked;
        // Bond-gated and attracted, the bond not there yet but reachable: a slow burn, not a friendzone
        $prebond = !$passes && $gate === 'bond' && $romanceCapable && $visceral && !$bonded && $bondReachable;
        $friendzoned = !$passes && !$prebond && $sociological;

        $pc = (array) $cfg['passion'];
        $style = RelationshipDynamics::getAttachmentStyle($dynamics);
        $attach = floatval(((array) ($pc['attachment_mult'] ?? []))[$style] ?? 1.0);
        $lerp = floatval($pc['passing_min_mult']) + (1.0 - floatval($pc['passing_min_mult'])) * $score;
        if ($passes) {
            $mult = $attach * $lerp;
        } elseif ($prebond) {
            $mult = $attach * floatval($pc['bond_prebond_mult']) * $lerp;
        } else {
            $mult = $attach * floatval($pc['unattracted_mult']);
        }
        $passionMax = floatval(RelationshipDynamics::getConfig()['passion_max'] ?? 100.0);
        if ($passes) {
            $cut = $tolerated ? floatval(((array) $cfg['openness_passion_ceiling_cut'])[$def['openness']] ?? 0.0) : 0.0;
            $passionCap = $cut > 0 ? round($passionMax * (1.0 - min(1.0, $cut)), 4) : null;
        } elseif ($prebond) {
            $passionCap = null;
        } elseif ($friendzoned) {
            $passionCap = floatval($pc['friendzone_cap']);
        } else {
            $passionCap = floatval($pc['unattracted_cap']);
        }

        // ---- Romance axis: open only to someone the NPC can feel passion for (now, or after
        // the bond); crush with the visceral pass, full romance (commitment) with the
        // sociological pass too (MDD 2.6), then the preference filter.
        $romanceAllowed = self::ROMANCE_NONE;
        if (($passes || $prebond) && $visceral && self::meetsRequirements((array) $cfg['romance_requirements'], $pillars)) {
            $romanceAllowed = $sociological ? self::ROMANCE_FULL : self::ROMANCE_CRUSH;
        }
        $romanceAllowed = min(max($romanceAllowed, $floorRomance), $romanceMax);
        if (!$gating) $earnedRomance = max($earnedRomance, $romanceAllowed);
        $romanceEff = min($earnedRomance, $romanceAllowed);
        $blocked = [];
        foreach ((array) $cfg['romance_types'] as $type => $level) {
            if (intval($level) > $romanceEff) $blocked[] = (string) $type;
        }

        $pending = null;
        if ($gating && (self::depthRank($depthAllowed) > self::depthRank($earnedDepth) || $romanceAllowed > $earnedRomance)) {
            $pending = [
                'depth_to' => self::depthRank($depthAllowed) > self::depthRank($earnedDepth) ? $depthAllowed : $earnedDepth,
                'romance_to' => max($romanceAllowed, $earnedRomance),
                'need' => self::interactionsNeeded($dynamics, $gate, $def['openness'], $tolerated, $cfg),
                'have' => intval($state['pending']['have'] ?? 0),
            ];
        }

        // ---- Intimacy handoff (Sharmat) hint for the romance lane
        $intimacy = $passes && ($prefRow['intimacy'] ?? true) && !$friendzoned;
        if ($intimacy && $gate === 'bond' && !$bonded) $intimacy = false;
        if ($intimacy && $def['preference'] === 'demisexual') {
            $peak = max($coreAff, floatval($state['peak_core_aff'] ?? -100));
            $intimacy = $peak >= floatval($prefRow['intimacy_peak_core_aff'] ?? 100)
                && RelationshipDynamics::tierRank($tierNow) >= RelationshipDynamics::tierRank((string) ($prefRow['intimacy_min_tier'] ?? 'close_friend'));
        }

        $outcome = $passes ? ($sociological ? 'drawn' : 'hookup')
            : ($prebond ? 'prebond' : ($friendzoned ? 'friendzone' : 'unattracted'));
        $failedNames = array_keys(array_filter($pillars, fn($r) => !$r['pass']));
        $reason = sprintf('%s: %s%s (gate %s, openness %s, %s attachment%s)%s',
            $npcName, $outcome,
            $failedNames ? ' - fails ' . implode(', ', $failedNames) : '',
            $gate, $def['openness'], $style,
            $def['preference'] !== null ? ", {$def['preference']}" : '',
            $genderPass ? '' : '; gender preference not met');

        return [
            'score'        => round($score, 4),
            'passes'       => $passes,
            'friendzoned'  => $friendzoned,
            'pillars'      => $pillars,
            'ceiling_tier' => $depthEff === 'devoted' ? null : $depthEff,
            'reason'       => $reason,
            // --- extras (Jev / romance lane / logs; never raw into the LLM context)
            'enabled'           => true,
            'outcome'           => $outcome,
            'visceral_pass'     => $visceral,
            'sociological_pass' => $sociological,
            'gender_pass'       => $genderPass,
            'gate'              => $gate,
            'openness'          => $def['openness'],
            'preference'        => $def['preference'],
            'prebond'           => $prebond,
            'tolerated'         => $tolerated,
            'failed'            => $failed,
            'passion_mult'      => round($mult, 4),
            'passion_cap'       => $passionCap,
            'allowed_tier'      => $depthAllowed,
            'romance'           => ['allowed' => $romanceAllowed, 'earned' => $earnedRomance, 'effective' => $romanceEff],
            'blocked_types'     => $blocked,
            'pending'           => $pending,
            'intimacy_allowed'  => $intimacy,
            'valued'            => $pillars['strength']['known'] && $valued !== null ? $valued : null,
            'gating'            => $gating,
            'grandfathered'     => ['depth' => $floorDepth, 'romance' => $floorRomance],
        ];
    }

    /**
     * The player's magnitude as archetype $a (0..1): RelDynPlayer's raw archetype score
     * (profile archetype_raw: skills / deeds / gear, before the identity normalisation), else
     * identity x the generic pillar for a profile that carries no raw scores.
     */
    private static function archetypeMagnitude(array $profile, string $a, float $generic): float
    {
        $raw = $profile['archetype_raw'][$a] ?? null;
        if (is_numeric($raw)) return max(0.0, min(1.0, floatval($raw)));
        return max(0.0, min(1.0, floatval($profile['archetypes'][$a] ?? 0.0))) * $generic;
    }

    /** The player's appearance text from the profile facts (RelDynPlayer: facts.appearance.value). */
    private static function appearanceOf(array $profile): ?string
    {
        $a = $profile['facts']['appearance'] ?? null;
        if (is_array($a)) $a = $a['value'] ?? null;
        return is_string($a) && trim($a) !== '' ? $a : null;
    }

    /**
     * MDD 2.3 status through this NPC's eyes: the weighted known mean of the generic status
     * pillar (1 - status_share) and the NPC's own markers (status_share). null when neither
     * is known (a share of 1.0 with no marker evidence: unknown, whatever the wallet says).
     */
    private static function npcStatus(array $profile, array $def): ?float
    {
        $generic = $profile['pillars']['status'] ?? null;
        $generic = is_numeric($generic) ? max(0.0, min(1.0, floatval($generic))) : null;
        $markers = (array) ($def['status_markers'] ?? []);
        if ($markers === []) return $generic;
        $own = class_exists('RelDynPlayer') ? RelDynPlayer::evidenceScore($profile, $markers) : null;
        $share = floatval($def['status_share'] ?? 0.0);
        $sum = 0.0;
        $w = 0.0;
        foreach ([[$generic, 1.0 - $share], [$own, $share]] as [$v, $weight]) {
            if ($v === null || $weight <= 0) continue;
            $sum += $weight * $v;
            $w += $weight;
        }
        return $w > 0 ? max(0.0, min(1.0, $sum / $w)) : null;
    }

    /**
     * Grandfathered depth / romance (see the file comment): the state recorded when the Matrix
     * first tracked this bond (initialEarned, before the first evaluation), bounded by what
     * core holds now (core affinity tier, core Player.type's romance level), so a relationship
     * that core steps back or lets fall loses its protection.
     *
     * @return array [depth tier ('' = none), romance level]
     */
    private static function grandfatherFloor(array $dynamics, ?array $state): array
    {
        [$nowDepth, $nowRomance] = self::initialEarned($dynamics);
        $g = $state === null ? ['depth' => $nowDepth, 'romance' => $nowRomance]
            : (is_array($state['grandfathered'] ?? null) ? $state['grandfathered'] : null);
        if ($g === null) return ['', 0];
        $depth = self::validDepth($g['depth'] ?? null);
        if ($depth !== null && self::depthRank($depth) > self::depthRank($nowDepth)) $depth = $nowDepth;
        $romance = min(max(0, min(2, intval($g['romance'] ?? 0))), $nowRomance);
        // A stranger / acquaintance start protects nothing (it is the floor anyway)
        return [self::depthRank($depth) > 0 ? $depth : '', $romance];
    }

    /** Every requirement is met by a pillar that does not gate (soft / irrelevant / unknown) or scores enough. */
    private static function meetsRequirements(array $req, array $pillars): bool
    {
        foreach ($req as $p => $min) {
            $row = $pillars[$p] ?? null;
            if ($row === null || !$row['known'] || !in_array($row['rigidity'], ['rigid', 'flexible'], true)) continue;
            if ($row['score'] < floatval($min)) return false;
        }
        return true;
    }

    public static function depthRank(?string $tier): int
    {
        $i = array_search($tier, self::DEPTH_TIERS, true);
        return $i === false ? -1 : $i;
    }

    private static function validDepth($tier): ?string
    {
        return in_array($tier, self::DEPTH_TIERS, true) ? $tier : null;
    }

    /**
     * What counts as earned before the Matrix first tracked this bond: where the relationship
     * already is (core affinity tier, core Player.type), so an existing bond is not demoted by
     * the first evaluation. Stranger / hostile start at acquaintance.
     */
    private static function initialEarned(array $dynamics): array
    {
        $tier = RelationshipDynamics::getCurrentTier(RelationshipDynamics::getCoreAffinity($dynamics));
        $depth = self::depthRank($tier) >= 0 ? $tier : 'acquaintance';
        $coreType = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $romance = intval(((array) self::config()['romance_types'])[$coreType] ?? self::ROMANCE_NONE);
        return [$depth, max(0, min(2, $romance))];
    }

    /** Significant interactions one lift needs (see config 'advance'). */
    public static function interactionsNeeded(array $dynamics, string $gate, string $openness, bool $tolerated, ?array $cfg = null): int
    {
        $cfg = $cfg ?? self::config();
        $a = (array) $cfg['advance'];
        $maturity = max(0.0, min(100.0, floatval($dynamics['dimensions']['maturity']['x'] ?? 50)));
        $maturityPace = floatval($a['maturity_pace_min']) + (floatval($a['maturity_pace_max']) - floatval($a['maturity_pace_min'])) * $maturity / 100.0;
        $n = floatval(((array) $a['base_by_gate'])[$gate] ?? 4)
            * floatval(((array) $a['openness_pace'])[$openness] ?? 1.0)
            * floatval(((array) $a['attachment_pace'])[RelationshipDynamics::getAttachmentStyle($dynamics)] ?? 1.0)
            * $maturityPace
            * ($tolerated ? floatval($cfg['tolerated_pace_mult']) : 1.0);
        return max(1, min(intval($a['max_needed']), (int) round($n)));
    }

    /**
     * Gender preference (plan §3): heterosexual / homosexual against the NPC's core gender and
     * the player's gender from the profile facts. Unknown either way, or bisexual: passes.
     */
    private static function genderPass(string $npcName, string $pref, array $profile): bool
    {
        if ($pref === 'bisexual') return true;
        $pg = $profile['facts']['gender'] ?? null;
        if (is_array($pg)) $pg = $pg['value'] ?? null;
        $pg = is_string($pg) ? strtolower(trim($pg)) : '';
        if ($pg === '') return true;
        try {
            $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
        } catch (Throwable $e) {
            error_log("[RelDyn] attraction: core_npc_master read failed for {$npcName}, gender preference not applied: " . $e->getMessage());
            return true;
        }
        $ng = strtolower(trim((string) ($row['gender'] ?? '')));
        if ($ng === '') return true;
        return $pref === 'heterosexual' ? $ng !== $pg : $ng === $pg;
    }

    // =====================================================================
    // STATE (lift -> significant interactions -> advance)
    // =====================================================================

    /**
     * Evaluate and record: keep _attraction_state (earned depth / romance, the pending lift and
     * its significant-interaction count, peak core affinity), store the compact summary in
     * $dynamics['_attraction'] (plus the legacy mirrors _attraction_friendzoned,
     * _attraction_tier_ceiling, _attraction_passion_mult) and hard-cap passion (MDD 6.2).
     * A ceiling that falls applies at once; one that rises waits for significant interactions.
     */
    public static function update(string $npcName, array &$dynamics, array $profile): array
    {
        $def = self::definition($npcName, $dynamics);
        $r = self::evaluate($npcName, $dynamics, $profile, $def);
        if (empty($r['enabled'])) {
            self::storeSummary($npcName, $dynamics, $r);
            return $r;
        }
        $prev = is_array($dynamics['_attraction_state'] ?? null) ? $dynamics['_attraction_state'] : null;
        $state = $prev;
        if ($state === null) {
            [$d, $rom] = self::initialEarned($dynamics);
            $state = ['depth' => $d, 'romance' => $rom, 'pending' => null, 'peak_core_aff' => -100.0];
        }
        // Grandfathered state only ever shrinks with core (a step-back or a fall is not undone
        // by core later writing a romance type again)
        [$gd, $gr] = self::grandfatherFloor($dynamics, $prev);
        $state['grandfathered'] = ['depth' => $gd, 'romance' => $gr];
        if (empty($r['gating'])) {
            // No bouncer: the earned state follows the allowed ceiling
            $state['depth'] = $r['allowed_tier'];
            $state['romance'] = $r['romance']['allowed'];
        }
        $state['peak_core_aff'] = max(floatval($state['peak_core_aff'] ?? -100), RelationshipDynamics::getCoreAffinity($dynamics));
        // Falls apply at once
        if (self::depthRank($r['allowed_tier']) < self::depthRank($state['depth'])) {
            RelationshipDynamics::log("[ATTRACTION] {$npcName}: ceiling falls {$state['depth']} -> {$r['allowed_tier']} ({$r['reason']})");
            $state['depth'] = $r['allowed_tier'];
        }
        if ($r['romance']['allowed'] < intval($state['romance'])) {
            $state['romance'] = $r['romance']['allowed'];
        }
        // Lifts wait (the count so far is kept when the target moves)
        if ($r['pending'] !== null) {
            $p = $r['pending'];
            if (empty($state['pending'])) {
                RelationshipDynamics::log("[ATTRACTION] {$npcName}: ceiling lifts to {$p['depth_to']} / romance {$p['romance_to']}; "
                    . "{$p['need']} significant interaction(s) to advance ({$r['reason']})");
            }
            $state['pending'] = ['depth_to' => $p['depth_to'], 'romance_to' => $p['romance_to'], 'need' => $p['need'],
                'have' => intval($state['pending']['have'] ?? 0)];
        } else {
            $state['pending'] = null;
        }
        $dynamics['_attraction_state'] = $state;
        $r = self::evaluate($npcName, $dynamics, $profile, $def);
        self::storeSummary($npcName, $dynamics, $r);
        return $r;
    }

    private static function storeSummary(string $npcName, array &$dynamics, array $r): void
    {
        $wasFz = !empty($dynamics['_attraction_friendzoned']);
        $dynamics['_attraction'] = [
            'enabled' => $r['enabled'], 'outcome' => $r['outcome'] ?? null, 'score' => $r['score'],
            'passes' => $r['passes'], 'friendzoned' => $r['friendzoned'], 'prebond' => $r['prebond'],
            'tolerated' => $r['tolerated'], 'failed' => $r['failed'], 'openness' => $r['openness'],
            'preference' => $r['preference'], 'gate' => $r['gate'],
            'passion_mult' => $r['passion_mult'], 'passion_cap' => $r['passion_cap'],
            'ceiling_tier' => $r['ceiling_tier'], 'allowed_tier' => $r['allowed_tier'],
            'romance' => $r['romance'], 'blocked_types' => $r['blocked_types'],
            'pending' => $r['pending'] !== null, 'intimacy_allowed' => $r['intimacy_allowed'], 'valued' => $r['valued'],
            'gating' => $r['gating'] ?? false,
        ];
        $dynamics['_attraction_friendzoned'] = (bool) $r['friendzoned'];
        $dynamics['_attraction_tier_ceiling'] = $r['ceiling_tier'] ?? 'devoted';
        $dynamics['_attraction_passion_mult'] = $r['passion_mult'];
        if ($wasFz !== (bool) $r['friendzoned']) {
            RelationshipDynamics::log("[ATTRACTION] {$npcName}: friendzone " . ($r['friendzoned'] ? 'begins' : 'ends') . " ({$r['reason']})");
        }
        self::enforcePassionCap($dynamics);
    }

    /** Hard cap (MDD 6.2): passion above the attraction cap drops to it. Returns the points removed. */
    public static function enforcePassionCap(array &$dynamics): float
    {
        $cap = $dynamics['_attraction']['passion_cap'] ?? null;
        if (!is_numeric($cap)) return 0.0;
        $p = RelationshipDynamics::getPassion($dynamics);
        if ($p <= floatval($cap)) return 0.0;
        RelationshipDynamics::setPassion($dynamics, floatval($cap));
        return $p - floatval($cap);
    }

    /**
     * An eval item's significance toward a pending lift. Counts when positive and at least
     * advance.significance_min; when the count reaches the need, the lift is earned and the
     * summary's ceiling / blocked types follow at once. Returns true when a lift completed.
     */
    public static function recordSignificance(string $npcName, array &$dynamics, float $significance, bool $positive): bool
    {
        $state = $dynamics['_attraction_state'] ?? null;
        if (!is_array($state) || empty($state['pending']) || !$positive) return false;
        $min = floatval(((array) self::config()['advance'])['significance_min']);
        if ($significance < $min) return false;
        $state['pending']['have'] = intval($state['pending']['have'] ?? 0) + 1;
        $p = $state['pending'];
        if ($p['have'] >= intval($p['need'])) {
            if (self::depthRank($p['depth_to']) > self::depthRank($state['depth'] ?? 'acquaintance')) $state['depth'] = $p['depth_to'];
            $state['romance'] = max(intval($state['romance'] ?? 0), intval($p['romance_to']));
            $state['pending'] = null;
            $dynamics['_attraction_state'] = $state;
            self::refreshEarned($dynamics);
            RelationshipDynamics::log("[ATTRACTION] {$npcName}: advanced to {$state['depth']} / romance {$state['romance']} after {$p['have']} significant interaction(s)");
            return true;
        }
        $dynamics['_attraction_state'] = $state;
        RelationshipDynamics::log("[ATTRACTION] {$npcName}: significant interaction {$p['have']}/{$p['need']} toward {$p['depth_to']} / romance {$p['romance_to']}");
        return false;
    }

    /**
     * After a lift is earned mid-request (eval consumer): the summary's effective ceiling,
     * romance level and blocked types from the new earned state and the summary's allowed
     * levels, without re-reading the player profile.
     */
    private static function refreshEarned(array &$dynamics): void
    {
        $sum = $dynamics['_attraction'] ?? null;
        $state = $dynamics['_attraction_state'] ?? null;
        if (!is_array($sum) || !is_array($state) || empty($sum['enabled'])) return;
        $allowed = self::validDepth($sum['allowed_tier'] ?? null) ?? 'acquaintance';
        $earned = self::validDepth($state['depth'] ?? null) ?? 'acquaintance';
        $eff = self::depthRank($earned) < self::depthRank($allowed) ? $earned : $allowed;
        $romAllowed = intval($sum['romance']['allowed'] ?? 0);
        $romEff = min(intval($state['romance'] ?? 0), $romAllowed);
        $blocked = [];
        foreach ((array) self::config()['romance_types'] as $type => $level) {
            if (intval($level) > $romEff) $blocked[] = (string) $type;
        }
        $sum['ceiling_tier'] = $eff === 'devoted' ? null : $eff;
        $sum['romance']['earned'] = intval($state['romance'] ?? 0);
        $sum['romance']['effective'] = $romEff;
        $sum['blocked_types'] = $blocked;
        $sum['pending'] = !empty($state['pending']);
        $dynamics['_attraction'] = $sum;
        $dynamics['_attraction_tier_ceiling'] = $sum['ceiling_tier'] ?? 'devoted';
    }

    // =====================================================================
    // FELT TEXT (LLM context: feelings, never numbers)
    // =====================================================================

    public static function feltText(string $npcName, array $summary): ?string
    {
        if (empty($summary['enabled'])) return null;
        $lines = [];
        $valuedWords = [
            'warrior' => 'the way the player fights', 'hunter' => 'the player\'s hunter\'s instincts',
            'druid' => 'the player\'s bond with the wild', 'mage' => 'the player\'s command of magic',
            'scholar' => 'the player\'s learning', 'thief' => 'the player\'s nerve and quick hands',
            'bard' => 'the player\'s voice and wit', 'smith' => 'the player\'s craft', 'healer' => 'the player\'s healing hands',
            'noble' => 'the player\'s bearing',
        ];
        $valued = $valuedWords[$summary['valued'] ?? ''] ?? null;
        switch ($summary['outcome'] ?? null) {
            case 'drawn':
                $lines[] = "{$npcName} is drawn to the player" . ($valued ? ", and {$valued} is exactly what {$npcName} finds compelling." : '.');
                break;
            case 'hookup':
                $lines[] = "{$npcName} is physically drawn to the player" . ($valued ? " ({$valued} stirs something)" : '')
                    . ", but the player has not yet proven themselves worth more than a flirtation. Commitment is not on the table yet.";
                break;
            case 'prebond':
                $lines[] = "{$npcName} is not moved by looks or deeds alone; any romantic feeling can only grow out of a deep, proven bond.";
                break;
            case 'friendzone':
                $lines[] = "{$npcName} values the player as a trusted friend but feels no romantic pull toward them. Flirtation is met with warm deflection, never cruelty.";
                break;
            default:
                $lines[] = "{$npcName} feels no particular pull toward the player and keeps a polite distance.";
        }
        if (!empty($summary['tolerated'])) {
            $lines[] = "Something about the player falls short of what {$npcName} usually wants, but {$npcName} is willing to look past it.";
        }
        $prefLines = [
            'demisexual' => "{$npcName} forms deep bonds slowly; romantic feeling needs genuine trust built over time, and physical intimacy without that foundation feels wrong.",
            'asexual' => "{$npcName} does not experience sexual attraction. Deep emotional bonds are possible, but physical intimacy is not something they seek.",
            'aromantic' => "{$npcName} does not experience romantic attraction. Deep, loyal friendship is possible; romantic framing feels foreign and uncomfortable.",
            'not_interested' => "{$npcName} is not looking for romance with anyone right now.",
            'uncommitted' => "{$npcName} enjoys closeness but shies away from anything that sounds like commitment.",
        ];
        if (isset($prefLines[$summary['preference'] ?? ''])) {
            $lines[] = $prefLines[$summary['preference']];
        }
        if (!empty($summary['pending'])) {
            $lines[] = "{$npcName} has begun to see the player differently; only a truly meaningful moment together could change what they are to each other.";
        }
        return implode(' ', $lines);
    }
}
