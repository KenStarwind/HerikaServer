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
 *          skills): score = (1 - share) x the generic pillar + share x fit, fit = the player's
 *          archetype MIX through the lens (lens weight x the player's MAGNITUDE as each archetype:
 *          RelDynPlayer's raw archetype score, not the identity that reads 1.0 for whatever the
 *          player mostly is), from the best one toward their soft-or (lens_blend; decisions §10:
 *          archetypes blend, a bard with nature-heavy deeds and spells carries a druid share). Aela
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
 *      fail that takes twice the effort to advance. These bars drive the type / depth filter
 *      (MDD 2.6: visceral / sociological pass, the tier ceilings), not passion.
 *   3. What may grow? Passion (decisions §13, "an uphill, not a wall"; supersedes the §11 gates):
 *        below the spark (20 points): gain = raw x attachment            (open to anyone)
 *        from the spark:              gain = raw x curve x attachment [x prebond]
 *      curve: each of the NPC's passion pillars (MDD 8.3: archetype passion_pillars, else the
 *      intimacy gate's gate_pillars) has a floor in pillar points (0..100, from the NPC's
 *      openness; Aela's martial floor 68). Below it the multiplier is < 1 and steep (about 0.1
 *      far below, 1.0 at the floor, config exponent); above it +1% per point, capped at 1.25;
 *      several units combine with the weakest setting the scale (passionCurve, combineUnits).
 *      Speech lifts every pillar score (MDD 2.5), so a charming bard climbs a little faster.
 *      Hard zero, spark included, only for the non-negotiable: orientation, a passion-free or
 *      romance-free preference (asexual, aromantic, not interested) and a rigid passion pillar
 *      at 0 ("100 x 0 is still 0"). A balanced NPC's bond eases the visceral hill (1.0 at the
 *      bonded tier; sociological units keep theirs); a visceral NPC gets no relief. Every
 *      passion writer (legacy, eval signal, reunion, combat, repair, hoover, place floor, the
 *      stage floor) goes through the same factor (gainFactor via
 *      RelationshipDynamics::attractionPassionFactor, passionStageFloor). There is no passion
 *      cap for attraction reasons: the MDD 6.2 hard cap of 20 is retired (decisions §13).
 *      Outcomes: attracted (curve at least friendzone_below) = drawn (sociological pass too) or
 *      hookup; not attracted with the sociological pillars met = friendzone (a label),
 *      otherwise unattracted. The relationship preference (demisexual, asexual, aromantic, ...)
 *      filters the romance axis.
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
            // share x fit, fit over the archetypes of lens weight x the player's magnitude as
            // that archetype (profile archetype_raw; a profile without it: identity x generic),
            // blended by lens_blend (lensFit).
            'lens_share' => ['beauty' => 0.0, 'strength' => 0.8, 'status' => 0.0, 'competence' => 0.5],
            // Decisions §10 (archetypes blend): how far the fit reads the player's whole archetype
            // mix. 0 = the best single archetype; 1 = the soft-or of every valued archetype
            // (lensFit). Between: a secondary valued archetype (a bard's druid side) adds to it.
            'lens_blend' => 0.5,
            // Contribution (lens weight x magnitude, 0..1) a secondary archetype needs to add to the
            // blend: a formed side of the player, not the trace every build has (a bard's few
            // points of alchemy and archery must not add up to a hunter-druid)
            'lens_blend_min_contribution' => 0.15,
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
            // pillar rigidity, intimacy gate (plan §7), pillar weights and (optional) the pillars
            // that gate passion (passion_pillars, else passion.gate_pillars[gate]). The plan §3 presets:
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
                // MDD 8.2 C (Ysolda): status is what her passion answers to, strength irrelevant
                'Merchant'  => ['rigidity' => ['beauty' => 'soft', 'strength' => 'irrelevant', 'status' => 'rigid', 'competence' => 'soft'], 'gate' => 'balanced',
                                'passion_pillars' => ['beauty', 'status']],
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
            // (MDD 1.4); medium / high tolerate near misses only. The bars and this margin drive
            // the type / depth filter (MDD 2.6, tier ceilings); passion follows the curve
            // (config 'curve', decisions §13), whose floors carry MDD 1.4's openness instead
            'openness_margin' => ['low' => 0.0, 'medium' => 0.25, 'high' => 0.5],
            // MDD 1.4: low openness triggers the Ick faster when the player pushes past a failed
            // check (multiplier on the Ick threshold; lower = faster)
            'openness_ick_mult' => ['low' => 0.6, 'medium' => 1.0, 'high' => 1.3],
            // MDD 1.4: a tolerated fail needs this many times the interactions to advance
            'tolerated_pace_mult' => 2.0,
            // Which pillars move PASSION, and the gain speeds that are not the curve (config
            // 'curve' holds the decisions §13 uphill; passionGain in the file comment).
            'passion' => [
                // Which pillars the passion curve reads, by intimacy gate, when the NPC's
                // archetype profile names none (passion_pillars; MDD 8.3 decoupling: the Matrix
                // decides per NPC which pillars gate which axis). A visceral / balanced NPC's
                // sociological pillars gate the depth axis (MDD 2.6), not passion (MDD 8.2 B:
                // Aela's passion before any Companions standing); a bond-gated NPC needs all four
                // (MDD 8.2 A: Ashe).
                'gate_pillars' => [
                    'visceral' => ['beauty', 'strength'],
                    'balanced' => ['beauty', 'strength'],
                    'bond'     => ['beauty', 'strength', 'status', 'competence'],
                ],
                // Bond-gated NPC before the bond: above-spark passion gain multiplier (plan §7
                // "max 0.3 until bonded"); the spark stays open (decisions §13)
                'bond_prebond_mult' => 0.3,
                // Attachment style (MDD 6.1) -> passion gain speed: anxious attaches fast,
                // avoidant slow (rulings §9 "passion considers attachment style"). Style corners:
                // an NPC reads them blended at its attachment axes (decisions §12). Attachment is
                // who the NPC is, not attraction: it applies to the spark too.
                'attachment_mult' => ['anxious' => 1.3, 'secure' => 1.0, 'avoidant' => 0.7, 'toxic' => 1.2],
            ],
            // Decisions §13, "an uphill, not a wall" (supersedes the §11 gates). Units: passion
            // points (0..passion_max) for spark; pillar points (the NPC's lens score x 100,
            // 0..100) for floors and surplus; the rest are unitless multipliers.
            //   passion below spark: gain = raw x attachment (anyone; no attraction factor)
            //   passion from spark:  gain = raw x curve x attachment [x bond_prebond_mult]
            //   curve = min(1, min_u m_u) x mean_u max(1, m_u) over the NPC's passion units
            //   (pillarMult); no unit: 1.0
            //   m_u(s) below its floor F = m_min + (1 - m_min) x (s / F) ^ steepness
            //          at / above F      = min(surplus_max, 1 + surplus_per_point x (s - F))
            // Units: each rigid passion pillar alone; the flexible (and the soft) passion pillars
            // as one group per axis (visceral, sociological) on the weighted mean score against
            // the weighted mean floor ("distribution doesn't matter"). Unknown and irrelevant
            // pillars are no unit. A rigid pillar at 0 is the non-negotiable hard zero (with the
            // orientation and the passion-free preferences): no spark, no gain at all.
            'curve' => [
                // Passion points open to anyone at the normal rate (decisions §13: "a spark is
                // open to anyone"; MDD 8.1 Unknown / Acquaintance ceiling 20)
                'spark' => 20.0,
                // Pillar points (0..100): the NPC's floor on a required pillar, by openness (MDD
                // 1.4 attraction tolerance: the pickier the NPC, the higher the hill). Medium 68:
                // Ken "Aela ... her floor is high 60s or so in martial" (Aela: medium openness).
                // Per NPC / pillar: 'floors' in npc_overrides or attraction_overrides, or the
                // editor's attraction_profile.pillar_floors.
                'floor_by_openness' => ['low' => 75.0, 'medium' => 68.0, 'high' => 55.0],
                // Multiplier far below the floor (Ken: "about 0.1"), for flexible units
                'm_min' => 0.1,
                // ... for soft units (MDD / memory "soft: contributes, compensated by other
                // pillars"): a gentle hill that never drops below half
                'soft_m_min' => 0.5,
                // ... for rigid units: the hill falls to exactly 0 at a score of 0 (non-negotiable,
                // "100 x 0 = 0") and is continuous above it
                'rigid_m_min' => 0.0,
                // Exponent of the hill: 3 fits Ken's examples at F = 68: s 20 -> 0.12,
                // s 45 -> 0.36, s 60 -> 0.72 ("steep and far below")
                'steepness' => 3.0,
                // Above the floor: +1% per pillar point of surplus (Ken: "at 45 you get 1x, at 50
                // 1.05, 55 1.10")
                'surplus_per_point' => 0.01,
                // Cap on the surplus multiplier (reached 25 points above the floor). With the
                // fastest attachment (anxious 1.3) the most attraction ever speeds passion is
                // 1.25 x 1.3 = 1.63, under the MDD 1.1 passion drive redline (2.0x), so attraction
                // stacked on attachment never outruns what full passion itself does to affinity
                'surplus_max' => 1.25,
                // Label only (no cap, decisions §13): the curve below this reads as not attracted
                // ("friendzoned" when the sociological pillars are met, MDD 2.6 / 6.2). 0.15 = a
                // flexible pillar at ~43% of its floor (29 of Aela's 68): the plain bard (~0.10)
                // and a green sellsword (~0.11) read as not attracted, a bard with a formed druid
                // side (~0.19, decisions §10: "can pass Aela's test") as drawn
                'friendzone_below' => 0.15,
                // Balanced NPCs (decisions §13): the bond eases the visceral units' penalty,
                // linearly in core affinity from this tier's floor to the bond_gate_tier's floor
                // (met there: 1.0); sociological units keep their hill; other gates: no relief
                'bond_relief_from_tier' => 'friend',
            ],
            // Plan §4: respect rate = (competence + status) / 2 on this NPC's pillar scores
            // (memory: competence -> respect). While on, the eval respect signal's GAINS are
            // multiplied by respect_mult = clamp(rate / respect_mult_neutral, respect_mult_range):
            // a player at the Matrix's neutral pillar score (unknown_pillar_score, 0.5) earns
            // respect at the raw rate, a nobody at half, a legend up to twice (MDD 1.2's
            // interaction-multiplier range 0.5x..2.0x). Losses are not scaled. false = off.
            'respect_mult_enabled' => true,
            'respect_mult_neutral' => 0.5,
            'respect_mult_range' => [0.5, 2.0],
            // Felt text (decisions §3, feelings not numbers): how strong the pull reads, by the
            // passion curve (unitless multiplier): passing glances below faint_below_curve,
            // lingering looks and eager answers from strong_from_curve (her floor met)
            'felt_pull' => ['faint_below_curve' => 0.5, 'strong_from_curve' => 1.0],
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
            // passion false: the preference type is a non-negotiable hard zero on passion
            // (decisions §13: no spark, no gain), whatever the pillars say.
            'preferences' => [
                'monogamous'     => ['romance_max' => 2, 'intimacy' => true],
                'polyamorous'    => ['romance_max' => 2, 'intimacy' => true],
                'uncommitted'    => ['romance_max' => 1, 'intimacy' => true],
                'not_interested' => ['romance_max' => 0, 'intimacy' => false, 'passion' => false],
                'aromantic'      => ['romance_max' => 0, 'intimacy' => false, 'passion' => false],
                'asexual'        => ['romance_max' => 2, 'intimacy' => false, 'passion' => false],
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
            // status_share, beauty_keywords, floors (pillar => pillar points 0..100).
            'npc_overrides' => [
                // Memory (attraction design): "Aela is not high openness ... medium to medium-low"
                // MDD 2.3: "Aela: only Companions rank"
                'aela the huntress' => ['openness' => 'medium', 'status_share' => 1.0],
                // Ken (rulings §10): "Ashe is less about the sex and more about the connection":
                // commitment first, whatever class core registered her with
                'ashe' => ['gate' => 'bond'],
            ],
        ];
    }

    /**
     * The NPC's intimacy gate (plan §7: visceral / bond / balanced) and where it came from:
     * the archetype profile (from _profile_autogen.archetype), overridden by the named preset
     * (npc_overrides), the NPC editor's attraction_profile.intimacy_gate, then
     * attraction_overrides.gate. Pure (no database).
     *
     * @return array [gate, source: 'archetype'|'preset'|'editor'|'override']
     */
    private static function gateWithSource(string $npcName, array $dynamics, array $cfg): array
    {
        $profiles = (array) $cfg['archetype_profiles'];
        $arch = $dynamics['_profile_autogen']['archetype'] ?? null;
        $base = (array) ((is_string($arch) ? ($profiles[$arch] ?? null) : null) ?? $profiles['default'] ?? []);
        $preset = (array) (((array) $cfg['npc_overrides'])[strtolower(trim($npcName))] ?? []);
        $editor = is_array($dynamics['attraction_profile'] ?? null) ? $dynamics['attraction_profile'] : [];
        $over = is_array($dynamics['attraction_overrides'] ?? null) ? $dynamics['attraction_overrides'] : [];
        $gate = in_array($base['gate'] ?? null, self::GATES, true) ? $base['gate'] : 'balanced';
        $source = 'archetype';
        foreach ([['preset', $preset['gate'] ?? null], ['editor', $editor['intimacy_gate'] ?? null], ['override', $over['gate'] ?? null]] as [$src, $g]) {
            if (in_array($g, self::GATES, true)) { $gate = $g; $source = $src; }
        }
        return [$gate, $source];
    }

    /** The NPC's intimacy gate (gateWithSource), for the intimacy need derivation. */
    public static function gateOf(string $npcName, array $dynamics): string
    {
        return self::gateWithSource($npcName, $dynamics, self::config())[0];
    }

    /** Stored tables replace defaults table by table (like the other RelDyn config tables). */
    public static function config(): array
    {
        $defaults = self::defaults();
        $stored = RelationshipDynamics::getConfig()['attraction'] ?? null;
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** The 'curve' table (decisions §13); a table stored before a key existed falls back to its default. */
    public static function curveConfig(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        return array_replace(self::defaults()['curve'], (array) ($cfg['curve'] ?? []));
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
     * attraction_profile (pillar_rigidity, intimacy_gate, gender_pref, beauty_keywords,
     * pillar_floors), then $dynamics['attraction_overrides'] (rigidity, gate, weights, lens,
     * lens_share, openness, gender_pref, status_markers, status_share, beauty_keywords, floors).
     * $dynamics['openness'] (editor dropdown) beats the temperament default. Passion floors
     * (pillar points 0..100, decisions §13) default to the openness band's curve.floor_by_openness.
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
        [$gate, $gateSource] = self::gateWithSource($npcName, $dynamics, $cfg);
        if ($gateSource !== 'archetype') $sources['gate'] = $gateSource;

        // Pillars that gate passion (MDD 8.3: per NPC): the archetype's passion_pillars, else
        // the intimacy gate's default (passion.gate_pillars); named preset, editor, override win
        $pc = array_replace(self::defaults()['passion'], (array) ($cfg['passion'] ?? []));
        $passionPillars = self::validPillars($base['passion_pillars'] ?? null)
            ?? self::validPillars(((array) $pc['gate_pillars'])[$gate] ?? null) ?? self::PILLARS;
        $sources['passion_pillars'] = isset($base['passion_pillars']) ? 'archetype' : "gate:{$gate}";
        if ($gateSource !== 'archetype' && !isset($preset['passion_pillars']) && !isset($editor['passion_pillars']) && !isset($over['passion_pillars'])) {
            // a gate chosen above the archetype brings its own default pillars
            $passionPillars = self::validPillars(((array) $pc['gate_pillars'])[$gate] ?? null) ?? self::PILLARS;
            $sources['passion_pillars'] = "gate:{$gate}";
        }
        foreach ([['preset', $preset['passion_pillars'] ?? null], ['editor', $editor['passion_pillars'] ?? null], ['override', $over['passion_pillars'] ?? null]] as [$src, $list]) {
            $v = self::validPillars($list);
            if ($v !== null) { $passionPillars = $v; $sources['passion_pillars'] = $src; }
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

        // Passion floors (decisions §13), pillar points 0..100: the openness band's floor on every
        // pillar, then per pillar the named preset, the editor's pillar_floors, the override
        $cc = self::curveConfig($cfg);
        $bandFloor = floatval(((array) $cc['floor_by_openness'])[$band] ?? 68.0);
        $floors = array_fill_keys(self::PILLARS, max(1.0, min(100.0, $bandFloor)));
        $sources['floors'] = "openness:{$band}";
        foreach ([['preset', $preset['floors'] ?? null], ['editor', $editor['pillar_floors'] ?? null], ['override', $over['floors'] ?? null]] as [$src, $table]) {
            if (!is_array($table)) continue;
            foreach (self::PILLARS as $p) {
                if (is_numeric($table[$p] ?? null)) {
                    $floors[$p] = max(1.0, min(100.0, floatval($table[$p])));
                    $sources["floors.{$p}"] = $src;
                }
            }
        }

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
            'passion_pillars' => $passionPillars,
            'weights'    => $weights,
            'lens'       => $lens,
            'lens_share' => $lensShare,
            'openness'   => $band,
            'floors'     => $floors,
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
     * a passion-free preference (the row's passion false: aromantic, asexual, not interested)
     * is the non-negotiable hard zero on passion (decisions §13: no spark, no gain).
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
            $r['attracted'] = false;   // no romance at all: nobody reads as attractive
        }
        if (($row['passion'] ?? true) === false) {
            $r['hard_zero'] = "preference:{$pref}";
            $r['passion_mult'] = 0.0;
            $r['spark_mult'] = 0.0;
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

    /** A list of pillar names (unknown names dropped), or null when not a list of pillars. */
    private static function validPillars($list): ?array
    {
        if (!is_array($list)) return null;
        $out = array_values(array_unique(array_filter(array_map('strval', $list), fn($p) => in_array($p, self::PILLARS, true))));
        return ($out === [] && $list !== []) ? null : $out;
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
            'tolerated' => false, 'failed' => false, 'passion_mult' => 1.0,
            'spark' => floatval(self::curveConfig()['spark']), 'spark_mult' => 1.0, 'hard_zero' => null,
            'attracted' => true, 'below_floor' => false,
            'passion' => null, 'respect_mult' => 1.0, 'respect_rate' => null,
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
            $mix = null;
            if (!$known) {
                $score = max(0.0, min(1.0, floatval($p === 'beauty' ? $cfg['beauty_unknown_score'] : $cfg['unknown_pillar_score'])));
            } else {
                $score = max(0.0, min(1.0, $raw));
                $lens = $def['lens'][$p] ?? null;
                if (is_array($lens) && max(array_values($lens) ?: [0.0]) > 0) {
                    // How much of the player's magnitude is in forms this NPC values, read over
                    // the player's whole archetype mix (decisions §10: archetypes blend)
                    [$fit, $top, $mix] = self::lensFit($lens, $profile, $score, $cfg);
                    // What she values in the player: a formed side (lens_blend_min_contribution),
                    // not the trace of herb lore every bard has
                    if ($p === 'strength' && $top !== null && $mix[$top] >= floatval($cfg['lens_blend_min_contribution'] ?? 0.0)) {
                        $valued = $top;
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
            if ($mix !== null) $pillars[$p]['mix'] = $mix;   // archetype => lens x magnitude (Jev / logs)
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
        if ($def['preference'] === 'demisexual') {
            $gate = 'bond';
            $bonded = $coreAff >= floatval($prefRow['bond_core_aff'] ?? 60);
            if (!$bonded) $romanceMax = self::ROMANCE_NONE;
        }
        $romanceCapable = $genderPass && intval($prefRow['romance_max'] ?? self::ROMANCE_FULL) > self::ROMANCE_NONE;

        // ---- Passion (decisions §13: an uphill, not a wall; rulings §9: Aela warms to a warrior,
        // a bard climbs a long hill). The curve over the NPC's passion units (passionCurve)
        // scales gains above the spark; the spark is open to anyone; non-negotiables zero both.
        $style = RelationshipDynamics::getAttachmentStyle($dynamics);   // for the log line only
        $pc = array_replace(self::defaults()['passion'], (array) ($cfg['passion'] ?? []));
        $cc = self::curveConfig($cfg);
        // attachment_mult's style corners read at the NPC's axes (decisions §12)
        $attachmentMult = RelationshipDynamics::attachmentBlend($dynamics, (array) $pc['attachment_mult'], 1.0);
        // A balanced NPC's bond eases the visceral hill (decisions §13; plan §7 "visceral pass OR
        // bonded tier"): 0 below bond_relief_from_tier, 1 at the bond tier
        $relief = $gate === 'balanced' ? self::bondRelief($coreAff, $tierEff, $bondTier, $cc) : 0.0;
        $curve = self::passionCurve($pillars, $def, $cc, $relief);
        // Non-negotiables (decisions §13): orientation, a rigid passion pillar at 0, a preference
        // with no romance (aromantic, not interested) or no passion (asexual): no spark, no gain
        $hardZero = !$genderPass ? 'orientation' : $curve['hard_zero'];
        if ($hardZero === null && !$romanceCapable) $hardZero = 'preference:' . ($def['preference'] ?? 'none');
        if ($hardZero === null && ($prefRow['passion'] ?? true) === false) $hardZero = "preference:{$def['preference']}";
        // Attracted: the curve reads as more than "very low" (friendzone_below, a label, no cap),
        // for someone this NPC can feel romantically about at all. A passion-free preference
        // (asexual) still judges the player; only passion is zero.
        $attracted = $romanceCapable && $curve['hard_zero'] === null && $curve['m'] >= floatval($cc['friendzone_below']);
        // Bond-gated, attracted, the bond not there yet: a slow burn, not a friendzone
        $prebond = $attracted && $gate === 'bond' && !$bonded;
        $passes = $attracted && !$prebond;
        // The label (MDD 6.2 / 2.6): not attracted, the player's standing still valued
        $friendzoned = !$attracted && $sociological;
        // Plan §7: a bond-gated NPC's passion above the spark is slow until the bond
        $bondFactor = ($gate === 'bond' && !$bonded) ? floatval($pc['bond_prebond_mult']) : 1.0;
        $sparkMult = $hardZero !== null ? 0.0 : $attachmentMult;
        $mult = $hardZero !== null ? 0.0 : $curve['m'] * $attachmentMult * $bondFactor;

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
        $reason = sprintf('%s: %s%s (gate %s, openness %s, %s attachment%s, curve x%.2f%s)%s',
            $npcName, $outcome,
            $failedNames ? ' - fails ' . implode(', ', $failedNames) : '',
            $gate, $def['openness'], $style,
            $def['preference'] !== null ? ", {$def['preference']}" : '',
            $curve['m'], $hardZero !== null ? ", hard zero: {$hardZero}" : '',
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
            // decisions §13 (gainFactor): below the spark (passion points) gains run at
            // spark_mult (attachment), from it at passion_mult (curve x attachment [x prebond]);
            // a hard zero (its reason) makes both 0
            'passion_mult'      => round($mult, 6),
            'spark'             => floatval($cc['spark']),
            'spark_mult'        => round($sparkMult, 6),
            'hard_zero'         => $hardZero,
            'attracted'         => $attracted,
            // attracted, but below this NPC's floor: the uphill (felt text)
            'below_floor'       => $attracted && $curve['m'] < 1.0,
            // the factors (Jev / logs): the curve and its units, attachment, prebond, bond relief
            'passion'           => ['curve' => round($curve['m'], 4), 'units' => $curve['units'],
                                    'attachment' => round($attachmentMult, 6), 'bond' => $bondFactor,
                                    'relief' => round($relief, 4), 'pillars' => $def['passion_pillars'] ?? self::PILLARS],
            'respect_rate'      => self::respectRate($pillars),
            'respect_mult'      => self::respectMult(self::respectRate($pillars), $cfg),
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
     * Decisions §13: the passion curve of one evaluation (config 'curve'; see defaults()).
     * Units over the NPC's passion pillars (definition passion_pillars), known pillars only:
     *   - each rigid pillar alone (m_min rigid_m_min; a score of 0 is the hard zero);
     *   - the flexible pillars as one group per axis (visceral: beauty, strength; sociological:
     *     status, competence), m_min m_min; the soft ones likewise, m_min soft_m_min. A group
     *     reads its weighted mean score against its weighted mean floor (equal weights when
     *     every pillar in it weighs 0): "distribution doesn't matter";
     *   - irrelevant and unknown pillars are no unit (unknown is not absent).
     * Scores and floors in pillar points (0..100). $relief (0..1, bondRelief) eases a visceral
     * unit's penalty: m + (1 - m) x relief below the floor, nothing above it, never a hard zero.
     * curve = combineUnits(m of every unit); no unit: 1.0.
     *
     * @return array ['m' => curve, 'units' => [key => ['pillars', 'rigidity', 'axis', 'score', 'floor',
     *   'm_hill' (before relief), 'm']], 'hard_zero' => ?string 'rigid:<pillar>']
     */
    private static function passionCurve(array $pillars, array $def, array $cc, float $relief): array
    {
        $units = [];
        $groups = [];
        foreach ((array) ($def['passion_pillars'] ?? self::PILLARS) as $p) {
            $row = $pillars[$p] ?? null;
            if ($row === null || !$row['known'] || !in_array($row['rigidity'], ['rigid', 'flexible', 'soft'], true)) continue;
            $axis = in_array($p, self::VISCERAL, true) ? 'visceral' : 'sociological';
            $points = 100.0 * floatval($row['score']);
            $floor = floatval($def['floors'][$p] ?? ((array) $cc['floor_by_openness'])['medium'] ?? 68.0);
            if ($row['rigidity'] === 'rigid') {
                $units[$p] = ['pillars' => [$p], 'rigidity' => 'rigid', 'axis' => $axis, 'score' => $points, 'floor' => $floor];
                continue;
            }
            $key = "{$row['rigidity']}:{$axis}";
            $w = max(0.0, floatval($row['weight']));
            $g = $groups[$key] ?? ['pillars' => [], 'rigidity' => $row['rigidity'], 'axis' => $axis,
                'ws' => 0.0, 'wf' => 0.0, 'w' => 0.0, 's' => 0.0, 'f' => 0.0];
            $g['pillars'][] = $p;
            $g['ws'] += $w * $points;
            $g['wf'] += $w * $floor;
            $g['w'] += $w;
            $g['s'] += $points;
            $g['f'] += $floor;
            $groups[$key] = $g;
        }
        foreach ($groups as $key => $g) {
            $n = count($g['pillars']);
            [$score, $floor] = $g['w'] > 0 ? [$g['ws'] / $g['w'], $g['wf'] / $g['w']] : [$g['s'] / $n, $g['f'] / $n];
            $units[$key] = ['pillars' => $g['pillars'], 'rigidity' => $g['rigidity'], 'axis' => $g['axis'], 'score' => $score, 'floor' => $floor];
        }
        $hardZero = null;
        $ms = [];
        foreach ($units as $key => $u) {
            $mMin = floatval($cc[['rigid' => 'rigid_m_min', 'flexible' => 'm_min', 'soft' => 'soft_m_min'][$u['rigidity']]]);
            $hill = self::pillarMult($u['score'], $u['floor'], $mMin, $cc);
            if ($u['rigidity'] === 'rigid' && $u['score'] <= 0.0) {
                $hill = 0.0;   // "100 x 0 is still 0", whatever rigid_m_min says
                $hardZero = $hardZero ?? "rigid:{$key}";
            }
            $m = $hill;
            if ($u['axis'] === 'visceral' && $relief > 0.0 && $hill > 0.0 && $hill < 1.0) {
                $m = $hill + (1.0 - $hill) * min(1.0, $relief);
            }
            $units[$key]['score'] = round($u['score'], 2);
            $units[$key]['floor'] = round($u['floor'], 2);
            $units[$key]['m_hill'] = round($hill, 4);
            $units[$key]['m'] = round($m, 4);
            $ms[] = $m;
        }
        return ['m' => $hardZero !== null ? 0.0 : self::combineUnits($ms), 'units' => $units, 'hard_zero' => $hardZero];
    }

    /**
     * Decisions §13, one pillar (or group) on its hill (unitless). $points and $floor in pillar
     * points (0..100):
     *   below the floor: m_min + (1 - m_min) x (points / floor) ^ steepness  (m_min at 0, 1 at the floor)
     *   from the floor:  min(surplus_max, 1 + surplus_per_point x (points - floor))
     * Continuous and non-decreasing in $points. $cc: the curve table (curveConfig()).
     */
    public static function pillarMult(float $points, float $floor, ?float $mMin = null, ?array $cc = null): float
    {
        $cc = $cc ?? self::curveConfig();
        $mMin = max(0.0, min(1.0, $mMin ?? floatval($cc['m_min'])));
        $floor = max(1.0, $floor);
        $points = max(0.0, $points);
        if ($points < $floor) {
            return $mMin + (1.0 - $mMin) * pow($points / $floor, max(0.0001, floatval($cc['steepness'])));
        }
        $cap = max(1.0, floatval($cc['surplus_max']));
        return min($cap, 1.0 + max(0.0, floatval($cc['surplus_per_point'])) * ($points - $floor));
    }

    /**
     * Decisions §13, several units into one curve (unitless):
     *   curve = min(1, min_u m_u) x mean_u max(1, m_u)
     * Every unit at or above its floor: the mean of their surplus multipliers. Any below: the
     * weakest sets the scale (a far-off unit keeps the whole curve low), the others' surplus
     * lifts it by at most surplus_max. Continuous as the last unit crosses its floor. No unit: 1.0.
     */
    public static function combineUnits(array $ms): float
    {
        if ($ms === []) return 1.0;
        $lowest = 1.0;
        $surplus = 0.0;
        foreach ($ms as $m) {
            $m = max(0.0, floatval($m));
            $lowest = min($lowest, $m);
            $surplus += max(1.0, $m);
        }
        return $lowest * $surplus / count($ms);
    }

    /**
     * Decisions §13 bond relief for a balanced NPC (0..1): linear in core affinity from the
     * floor of bond_relief_from_tier (0) to the floor of the bond tier (1), core affinity held
     * within the tier the attraction lets the bond reach ($tierEff), 1 once that tier is the
     * bond tier or deeper.
     */
    private static function bondRelief(float $coreAff, string $tierEff, string $bondTier, array $cc): float
    {
        $tiers = RelationshipDynamics::RELATIONSHIP_TIERS;
        if (!isset($tiers[$bondTier])) return 0.0;
        if (RelationshipDynamics::tierRank($tierEff) >= RelationshipDynamics::tierRank($bondTier)) return 1.0;
        $from = (string) ($cc['bond_relief_from_tier'] ?? 'friend');
        $lo = floatval($tiers[$from]['min'] ?? $tiers['friend']['min']);
        $hi = floatval($tiers[$bondTier]['min']);
        if ($hi <= $lo) return 0.0;
        $aff = min($coreAff, floatval($tiers[$tierEff]['max'] ?? $coreAff));
        return max(0.0, min(1.0, ($aff - $lo) / ($hi - $lo)));
    }

    /**
     * The attraction factor (unitless) for a passion GAIN of $raw points at current passion
     * $passion (points), from an attraction summary (evaluate / _attraction; decisions §13):
     * the part of the gain that stays below the spark runs at spark_mult, the rest at
     * passion_mult, so a gain that crosses the spark is split there:
     *   to_spark = spark - passion; raw it takes = to_spark / spark_mult
     *   factor = (to_spark + (raw - raw it takes) x passion_mult) / raw   (crossing)
     * A hard zero (spark_mult 0) is 0 everywhere. A summary from before the spark existed
     * reads as passion_mult everywhere. Every passion writer uses this one factor
     * (RelationshipDynamics::attractionPassionFactor).
     */
    public static function gainFactor(array $summary, float $passion, float $raw): float
    {
        $above = is_numeric($summary['passion_mult'] ?? null) ? max(0.0, floatval($summary['passion_mult'])) : 1.0;
        if (!is_numeric($summary['spark_mult'] ?? null)) return $above;
        $below = max(0.0, floatval($summary['spark_mult']));
        $toSpark = max(0.0, floatval($summary['spark'] ?? 0.0) - $passion);
        if ($below <= 0.0) return 0.0;
        if ($toSpark <= 0.0 || $raw <= 0.0) return $above;
        $rawToSpark = $toSpark / $below;
        if ($raw <= $rawToSpark) return $below;
        return ($toSpark + ($raw - $rawToSpark) * $above) / $raw;
    }

    /** Plan §4: the respect rate = (competence + status) / 2 on this NPC's pillar scores (0..1). */
    private static function respectRate(array $pillars): float
    {
        return round((floatval($pillars['competence']['score'] ?? 0.0) + floatval($pillars['status']['score'] ?? 0.0)) / 2.0, 4);
    }

    /**
     * The respect-gain multiplier: the plan §4 rate against the Matrix's neutral pillar score,
     * clamped to respect_mult_range (config; see defaults()). 1.0 at the neutral score.
     */
    public static function respectMult(float $rate, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $d = self::defaults();
        $neutral = max(0.0001, floatval($cfg['respect_mult_neutral'] ?? $d['respect_mult_neutral']));
        $range = array_values((array) ($cfg['respect_mult_range'] ?? $d['respect_mult_range']));
        $lo = floatval($range[0] ?? 0.5);
        $hi = max($lo, floatval($range[1] ?? 2.0));
        return round(max($lo, min($hi, $rate / $neutral)), 4);
    }

    /**
     * The lens fit over the player's archetype MIX (decisions §10: archetypes blend). Each
     * archetype contributes c_a = lens weight x the player's magnitude as it; the fit runs from
     * the best single contribution toward their soft-or by lens_blend:
     *   fit = best + lens_blend x (1 - prod(1 - c_a) - best)
     * over the contributions of at least lens_blend_min_contribution (the best one always
     * counts), so a player with two formed sides the NPC values (a hunter who is also a druid)
     * reads as more than either, one strong archetype still reads as itself, and a handful of
     * traces does not add up to a side the player does not have.
     *
     * @return array [fit 0..1, archetype with the largest contribution (null when none), contributions > 0]
     */
    private static function lensFit(array $lens, array $profile, float $generic, array $cfg): array
    {
        $blend = max(0.0, min(1.0, floatval($cfg['lens_blend'] ?? 0.0)));
        $min = max(0.0, floatval($cfg['lens_blend_min_contribution'] ?? 0.0));
        $best = 0.0;
        $top = null;
        $contrib = [];
        foreach ($lens as $a => $w) {
            $c = max(0.0, min(1.0, floatval($w) * self::archetypeMagnitude($profile, (string) $a, $generic)));
            if ($c <= 0.0) continue;
            $contrib[(string) $a] = $c;
            if ($c > $best) {
                $best = $c;
                $top = (string) $a;
            }
        }
        $none = 1.0 - $best;
        foreach ($contrib as $a => $c) {
            if ($a !== $top && $c >= $min) $none *= 1.0 - $c;
        }
        arsort($contrib);
        return [$best + $blend * ((1.0 - $none) - $best), $top, array_map(fn($c) => round($c, 4), $contrib)];
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
            * RelationshipDynamics::attachmentBlend($dynamics, (array) $a['attachment_pace'], 1.0)   // at the NPC's axes
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
     * _attraction_tier_ceiling, _attraction_passion_mult). Passion itself is never capped here:
     * the MDD 6.2 hard cap of 20 is retired in favour of the uphill (decisions §13).
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
            'passion_mult' => $r['passion_mult'], 'spark' => $r['spark'], 'spark_mult' => $r['spark_mult'],
            'hard_zero' => $r['hard_zero'], 'attracted' => $r['attracted'], 'below_floor' => $r['below_floor'],
            'passion' => $r['passion'] ?? null, 'respect_mult' => $r['respect_mult'] ?? 1.0,
            'respect_rate' => $r['respect_rate'] ?? null,
            'ceiling_tier' => $r['ceiling_tier'], 'allowed_tier' => $r['allowed_tier'],
            'romance' => $r['romance'], 'blocked_types' => $r['blocked_types'],
            'pending' => $r['pending'] !== null,
            // the pending lift would change what they are to each other (a romance level), not only how close
            'pending_romance' => $r['pending'] !== null && intval($r['pending']['romance_to'] ?? 0) > intval($r['romance']['earned'] ?? 0),
            'intimacy_allowed' => $r['intimacy_allowed'], 'valued' => $r['valued'],
            'gating' => $r['gating'] ?? false,
        ];
        $dynamics['_attraction_friendzoned'] = (bool) $r['friendzoned'];
        $dynamics['_attraction_tier_ceiling'] = $r['ceiling_tier'] ?? 'devoted';
        $dynamics['_attraction_passion_mult'] = $r['passion_mult'];
        if ($wasFz !== (bool) $r['friendzoned']) {
            RelationshipDynamics::log("[ATTRACTION] {$npcName}: friendzone " . ($r['friendzoned'] ? 'begins' : 'ends') . " ({$r['reason']})");
        }
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
        $sum['pending_romance'] = !empty($state['pending']) && intval($state['pending']['romance_to'] ?? 0) > intval($state['romance'] ?? 0);
        $dynamics['_attraction'] = $sum;
        $dynamics['_attraction_tier_ceiling'] = $sum['ceiling_tier'] ?? 'devoted';
    }

    // =====================================================================
    // FELT TEXT (LLM context: feelings, never numbers)
    // =====================================================================

    /**
     * The attraction read as felt prose (decisions §3: behavior, never numbers or verdicts).
     * $ctx (RelDynFelt::compose passes it; every key optional):
     *   player   string  how the text names the player (RelDynFelt::playerRef), default 'them'
     *   tier     int     context tier 0..3 (default 2)
     *   passion  float   current passion points (default 0)
     *   strained bool    open conflict, an active ick, resentment or jealousy past the felt
     *                    strain bands: the pull is not what drives this bond right now
     *   romantic bool    core already holds a romance type with the player
     *   flirt_min_tier int, flirt_passion_min float: below both (and not romantic / an earned
     *                    crush) the pull shows only as looks, never as answered flirtation
     *                    (MDD 8.1: at Unknown / Acquaintance romantic gestures meet the Ick)
     * Null when there is nothing to say (switched off, or strained).
     */
    public static function feltText(string $npcName, array $summary, array $ctx = []): ?string
    {
        if (empty($summary['enabled'])) return null;
        if (!empty($ctx['strained'])) return null;
        $P = trim((string) ($ctx['player'] ?? '')) !== '' ? (string) $ctx['player'] : 'them';
        $possessive = $P === 'them' ? 'their' : "{$P}'s";
        $tier = intval($ctx['tier'] ?? 2);
        $romanceEff = intval($summary['romance']['effective'] ?? 0);
        $flirtOk = $tier >= intval($ctx['flirt_min_tier'] ?? 2)
            || floatval($ctx['passion'] ?? 0.0) >= floatval($ctx['flirt_passion_min'] ?? 40.0)
            || !empty($ctx['romantic']) || $romanceEff >= self::ROMANCE_CRUSH;
        $lines = [];
        $valuedWords = [
            'warrior' => "the way {$P} fights", 'hunter' => "{$possessive} hunter's instincts",
            'druid' => "{$possessive} bond with the wild", 'mage' => "{$possessive} command of magic",
            'scholar' => "{$possessive} learning", 'thief' => "{$possessive} nerve and quick hands",
            'bard' => "{$possessive} voice and wit", 'smith' => "{$possessive} craft", 'healer' => "{$possessive} healing hands",
            'noble' => "{$possessive} bearing",
        ];
        $valued = $valuedWords[$summary['valued'] ?? ''] ?? null;
        // How strong the pull reads: the passion curve (decisions §13; the hill below her
        // floor, the surplus above it), shown as behavior, never the number.
        $strength = 'plain';
        if (is_numeric($summary['passion']['curve'] ?? null)) {
            $fp = array_replace(self::defaults()['felt_pull'], (array) (self::config()['felt_pull'] ?? []));
            $curve = floatval($summary['passion']['curve']);
            if ($curve < floatval($fp['faint_below_curve'])) $strength = 'faint';
            elseif ($curve >= floatval($fp['strong_from_curve'])) $strength = 'strong';
        }
        switch ($summary['outcome'] ?? null) {
            case 'drawn':
                $glance = ['faint' => ', now and then, in a passing glance', 'plain' => '', 'strong' => ' and linger there'][$strength];
                $lead = "{$npcName}'s eyes keep finding {$P}{$glance}" . ($valued ? "; {$valued} holds {$npcName}'s attention" : '');
                if ($flirtOk) {
                    $answer = ['faint' => 'a warm but light answer', 'plain' => 'a warm answer', 'strong' => 'an eager answer'][$strength];
                    $lines[] = "{$lead}, and flirtation gets {$answer}.";
                } else {
                    $lines[] = "{$lead}; {$npcName} lets it show no further than a look.";
                }
                break;
            case 'hookup':
                if ($flirtOk) {
                    $flirt = ['faint' => 'flirts back lightly', 'plain' => 'flirts back', 'strong' => 'flirts back boldly'][$strength];
                    $lines[] = "{$npcName} {$flirt} and lets {$P} close" . ($valued ? " ({$valued} holds {$npcName}'s eye)" : '')
                        . ", but turns any talk of commitment aside.";
                } else {
                    $look = ['faint' => 'a passing, appraising look', 'plain' => 'an appraising look', 'strong' => 'a long, appraising look'][$strength];
                    $lines[] = "{$npcName} gives {$P} {$look}" . ($valued ? " ({$valued} holds {$npcName}'s eye)" : '')
                        . ", and keeps it at that for now.";
                }
                break;
            case 'prebond':
                $lines[] = "{$npcName} is not swayed by looks or deeds alone; warms slowly, and anything romantic waits on a deep, proven bond.";
                break;
            case 'friendzone':
                $lines[] = $tier >= 2
                    ? "{$npcName} treats {$P} as a trusted friend and meets flirtation with warm, kind deflection, never cruelty."
                    : "{$npcName} meets flirtation from {$P} with warm, kind deflection; the interest is not that kind.";
                break;
            default:
                $lines[] = $tier >= 2
                    ? "{$npcName} lets flirtation from {$P} pass without an answer; what is between them is not that kind."
                    : "{$npcName} keeps a polite distance from {$P}; flirtation is let pass without an answer.";
        }
        // The uphill climbed (decisions §13: no wall at the spark): not the kind she is drawn
        // to, yet passion has grown past the spark all the same (never for a hard zero)
        if (in_array($summary['outcome'] ?? null, ['friendzone', 'unattracted'], true) && empty($summary['hard_zero'])
            && is_numeric($summary['spark'] ?? null) && floatval($ctx['passion'] ?? 0.0) > floatval($summary['spark'])) {
            $lines[] = "Lately {$npcName}'s deflections come a beat slower; something about {$P} has begun to get through.";
        }
        // The uphill (decisions §13): drawn, but below what this NPC usually wants
        if (!empty($summary['below_floor']) && in_array($summary['outcome'] ?? null, ['drawn', 'hookup', 'prebond'], true)) {
            $lines[] = "Something about {$P} falls short of what {$npcName} usually wants; {$npcName} chooses to look past it.";
        }
        $prefLines = [
            'demisexual' => "{$npcName} bonds slowly; romance waits on trust built over time, and physical closeness before that is turned aside.",
            'asexual' => "{$npcName} steers away from anything physical; closeness, for {$npcName}, is talk, time and loyalty.",
            'aromantic' => "{$npcName} deflects romantic framing, uneasy with it, and offers deep, loyal friendship instead.",
            'not_interested' => "{$npcName} brushes off romance with anyone right now.",
            'uncommitted' => "{$npcName} enjoys closeness but changes the subject when talk turns to commitment.",
        ];
        if (isset($prefLines[$summary['preference'] ?? ''])) {
            $lines[] = $prefLines[$summary['preference']];
        }
        // A lift waiting on significant interactions: never at first sight (tier 0)
        if (!empty($summary['pending']) && $tier >= 1) {
            $lines[] = !empty($summary['pending_romance'])
                ? "{$npcName} has started looking at {$P} differently; one truly meaningful moment together could change what they are to each other."
                : "{$npcName} has started to see more in {$P}; one truly meaningful moment together could bring them closer.";
        }
        return implode(' ', $lines);
    }
}
