<?php
/**
 * Relationship Dynamics — Core Class
 *
 * Organic relationship mechanics: love languages, diminishing returns,
 * passion (RPM→Speed), reunion spikes, jealousy, repair cycles, stages.
 *
 * Architecture: CHIM + Sharmat drive, MARAS rides along.
 * Works without MARAS — degrades gracefully.
 *
 * All state in core_npc_master.plugin_extended_data.reldyn (see reldyn_storage.php);
 * fresh start on 3.4.1: April data (extended_data.relationship_dynamics) is not carried over.
 * Config in conf_opts key 'relationship_dynamics_config'.
 *
 * No process-level caches: NPC state is read from the database on every load, and
 * config/bond caches only live inside an explicit request scope (beginRequest()), so a
 * long-lived process (relationship worker daemon) never acts on stale data.
 */

require_once __DIR__ . '/reldyn_storage.php';
require_once __DIR__ . '/reldyn_facets.php';

class RelationshipDynamics
{
    // Request-scoped caches: only used between beginRequest() and endRequest() in the
    // process that opened the scope (a forked child never inherits a valid scope).
    private static $config = null;
    private static $bondCache = [];
    private static $requestScopePid = null;
    private static $requestScopeStartedAt = null;

    // A scope nobody closed (a hook that returned early, a long-lived process that ran
    // a hook once) stops caching after this long, so config changes are always picked up.
    const REQUEST_SCOPE_MAX_SECONDS = 30;

    // Love language types
    const LL_WORDS   = 'words_of_affirmation';
    const LL_TIME    = 'quality_time';
    const LL_TOUCH   = 'physical_touch';
    const LL_SERVICE = 'acts_of_service';
    const LL_GIFTS   = 'gifts';

    // Warmth curve presets
    const CURVE_SLOW_BURN   = 'slow_burn';
    const CURVE_MODERATE    = 'moderate';
    const CURVE_QUICK       = 'quick_warmth';
    const CURVE_GUARDED     = 'guarded';

    // Relationship stages
    const STAGE_EARLY       = 'early';
    const STAGE_ESTABLISHED = 'established';
    const STAGE_DEEP        = 'deep';

    // Warmth curve parameters: [decay_rate, half_life_hours, lambda, passion_decay_per_hour]
    const CURVE_PARAMS = [
        'slow_burn'    => ['decay_rate' => 0.10, 'half_life' => 10.0, 'lambda' => 0.069, 'passion_decay' => 2.5],
        'moderate'     => ['decay_rate' => 0.08, 'half_life' =>  8.0, 'lambda' => 0.087, 'passion_decay' => 3.0],
        'quick_warmth' => ['decay_rate' => 0.06, 'half_life' =>  6.0, 'lambda' => 0.116, 'passion_decay' => 4.0],
        'guarded'      => ['decay_rate' => 0.12, 'half_life' => 12.0, 'lambda' => 0.058, 'passion_decay' => 5.0],
    ];

    // Stage properties: [passion_floor, passion_ceiling, gain_mult, dr_rate_mult]
    const STAGE_PARAMS = [
        'early'       => ['floor' => 0,  'ceiling' => 100, 'gain_mult' => 1.3, 'dr_mult' => 0.8],
        'established' => ['floor' => 5,  'ceiling' => 70,  'gain_mult' => 1.0, 'dr_mult' => 1.2],
        'deep'        => ['floor' => 15, 'ceiling' => 50,  'gain_mult' => 0.8, 'dr_mult' => 1.0],
    ];

    // Temperament → passion gain multiplier: MDD 1.3 Passion column (relationship-dynamics-mdd.md)
    const TEMPERAMENT_PASSION_MULT = [
        'Romantic'    => 1.3,
        'Anxious'     => 1.2,
        'Bold'        => 1.1,
        'Playful'     => 1.4,
        'Humble'      => 1.1,
        'Nurturing'   => 1.0,
        'Gentle'      => 0.9,
        'Jealous'     => 1.0,
        'Proud'       => 0.8,
        'Defiant'     => 1.2,
        'Guarded'     => 0.6,
        'Independent' => 0.7,
        'Stoic'       => 0.5,
    ];

    // Temperament -> bleedout passion drain (negative: how much passion is lost when NPC falls)
    // Guarded/Stoic NPCs lose more (they see vulnerability as weakness)
    // Nurturing/Anxious NPCs lose less (they bond through shared danger)
    const TEMPERAMENT_BLEEDOUT_DRAIN = [
        'Anxious'     => -3.0,  // Spirals into panic, abandonment terror
        'Guarded'     => -2.5,  // Walls slam up instantly
        'Independent' => -2.0,  // Vulnerability is intolerable
        'Proud'       => -2.0,  // Humiliation of helplessness
        'Jealous'     => -1.5,  // Fear of being replaced while weak
        'Gentle'      => -1.5,  // Deeply shaken by violence
        'Romantic'    => -1.0,  // Scared but trusts their partner
        'Nurturing'   => -1.0,  // Worried about others, not self
        'Humble'      => -0.8,  // Accepts it quietly
        'Playful'     => -0.5,  // Shakes it off with humor
        'Stoic'       => -0.5,  // Barely registers externally
        'Bold'        => -0.3,  // Rage fuel, not fear
        'Defiant'     =>  1.0,  // Fights HARDER when cornered — passion UP
    ];


    // Temperament → reunion multiplier: MDD 1.3 Reunion column
    const TEMPERAMENT_REUNION_MULT = [
        'Romantic'    => 1.5,
        'Anxious'     => 1.8,
        'Bold'        => 1.0,
        'Playful'     => 0.8,
        'Humble'      => 1.0,
        'Nurturing'   => 1.2,
        'Gentle'      => 1.3,
        'Jealous'     => 1.2,
        'Proud'       => 0.8,
        'Defiant'     => 0.6,
        'Guarded'     => 0.7,
        'Independent' => 0.5,
        'Stoic'       => 0.3,
    ];

    // Temperament → jealousy multiplier: MDD 1.3 Jealousy column
    const TEMPERAMENT_JEALOUSY_MULT = [
        'Romantic'    => 1.3,
        'Anxious'     => 1.5,
        'Bold'        => 0.8,
        'Playful'     => 0.4,
        'Humble'      => 0.5,
        'Nurturing'   => 0.6,
        'Gentle'      => 0.4,
        'Jealous'     => 2.0,
        'Proud'       => 1.5,
        'Defiant'     => 1.0,
        'Guarded'     => 0.5,
        'Independent' => 0.3,
        'Stoic'       => 0.2,
    ];

    // =========================================================================
    // INTERESTS SYSTEM (modulates ALL love languages)
    // =========================================================================

    // The 11 MDD 1.2 interests and every preference/appraisal live in RelDynFacets
    // (reldyn_facets.php, decisions 2026-09-23 §6).


    // ========== ITEM DIMENSION MODIFIERS (PR 8) ==========
    //
    // Consumables: temporary spike + permanent cost (addiction loop).
    // Equipped:    persistent baseline modifiers while worn.
    // Gifts:       relational — WHO gives and HOW matters.
    //
    // Game-time conversion: 1 game hour = 1 / 0.0000024 ≈ 416667 gamets units.
    // =====================================================

    /** Gamets units per game hour (for consumable expiry math). */
    const GAMETS_PER_HOUR = 416667;

    // ========== GAMETS PLAY TIME TRACKING ==========
    //
    // Normal gameplay at 20:1 time compression: ~2315 gamets per real second.
    // Wait/sleep produces enormous spikes (100K+ gamets/sec).
    // Threshold of 10,000 = ~4.3x normal -- filters wait/sleep while tolerating
    // minor fluctuations (timescale changes, brief pauses, etc.).
    //
    // GAMETS_PER_DECAY_TICK = 10 real minutes of normal gameplay:
    //   600 real seconds * 2315 gamets/sec = 1,389,000 gamets.

    /** Gamets/real-second ratio above which a gap is logged as containing a wait/sleep. */
    const GAMETS_WAIT_SLEEP_THRESHOLD = 10000;

    /** Accumulated play gamets per decay tick (~10 real minutes at 20:1 game speed). */
    const GAMETS_PER_DECAY_TICK = 1389000;

    /** Accumulated play gamets for resentment decay cooldown (~15 real minutes). */
    const GAMETS_RESENTMENT_COOLDOWN = 2083500;

    // ========== TIMING CONSTANTS (PR 10) ==========
    /** Gamets per real second at 20:1 time compression. */
    const GAMETS_PER_REAL_SECOND = 2315;

    /** Gamets per real hour of play time. */
    const GAMETS_PER_REAL_HOUR = 8334000; // 2315 * 3600

    /**
     * Real play hours of in-contact decay one turn can apply (jealousy; passion uses the same
     * 10 minutes when decay_max_hours is 0): a longer gap between two turns with the NPC is
     * time apart, not time together.
     */
    const IN_CONTACT_DECAY_MAX_HOURS = 0.167;

    /** 30 game days in gamets (for plasticity override expiry, uses raw game clock). */
    const THIRTY_GAME_DAYS_GAMETS = 300000048; // 30 * 24 * 416667 (GAMETS_PER_HOUR)

    /** Kill-streak window on the eventlog game clock: 5 min of real play. */
    const COMBAT_KILL_STREAK_WINDOW_GAMETS = 694500; // 300 * GAMETS_PER_REAL_SECOND

    /** A fight is still on if its newest core combat row is this recent: 1 min of real play. */
    const COMBAT_ACTIVE_WINDOW_GAMETS = 138900; // 60 * GAMETS_PER_REAL_SECOND

    /** Recent gift/consume window on the eventlog game clock: 30 s of real play. */
    const ITEM_EVENT_WINDOW_GAMETS = 69450; // 30 * GAMETS_PER_REAL_SECOND

    // ========== DIVINE INTERVENTION CONSTANTS (PR 10) ==========
    /** Minimum play gamets between DI checks (one decay tick = ~10 real min). */
    const DI_COOLDOWN_GAMETS = 1389000; // same as GAMETS_PER_DECAY_TICK

    /** Unstable window duration: 24 real hours of play time. */
    const UNSTABLE_WINDOW_GAMETS = 200016000; // 24 * GAMETS_PER_REAL_HOUR

    // ========== GRIEF CONSTANTS (PR 10) ==========
    const GRIEF_PHASE_HOURS = [1 => 0, 2 => 2, 3 => 5, 4 => 15];

    // ========== ATTACHMENT STYLE CONSTANTS (PR 10) ==========
    const TEMPERAMENT_ATTACHMENT_DEFAULTS = [
        'Romantic'    => 'secure',
        'Anxious'     => 'anxious',
        'Playful'     => 'secure',
        'Humble'      => 'secure',
        'Nurturing'   => 'secure',
        'Gentle'      => 'secure',
        'Jealous'     => 'anxious',
        'Stoic'       => 'avoidant',
        'Proud'       => 'avoidant',
        'Bold'        => 'secure',
        'Independent' => 'avoidant',
        'Defiant'     => 'avoidant',
        'Guarded'     => 'avoidant',
    ];

    const ATTACHMENT_MODIFIERS = [
        'secure' => [
            'comfort_decay_mult'      => 0.7,
            'trust_decay_mult'        => 0.7,
            'resentment_gain_mult'    => 1.0,
            'confrontation_threshold' => 50,
            'absence_comfort_delta'   => 0.0,
            'affinity_absence_mult'   => 1.0,
            'jealousy_mult'           => 1.0,
            'maturity_floor'          => null,
            'conflict_passion_gain'   => 0.0,
            'suffocation_threshold'   => null,
        ],
        'avoidant' => [
            'comfort_decay_mult'      => 1.5,
            'trust_decay_mult'        => 1.0,
            'resentment_gain_mult'    => 1.0,
            'confrontation_threshold' => 70,
            'absence_comfort_delta'   => +0.5,
            'affinity_absence_mult'   => 0.5,   // decisions 2026-09-23 section 2: Avoidant x0.5
            'jealousy_mult'           => 0.5,
            'maturity_floor'          => null,
            'conflict_passion_gain'   => 0.0,
            'suffocation_threshold'   => 60,
        ],
        'anxious' => [
            'comfort_decay_mult'      => 1.0,
            'trust_decay_mult'        => 1.3,
            'resentment_gain_mult'    => 1.5,
            'confrontation_threshold' => 30,
            'absence_comfort_delta'   => -1.0,
            'affinity_absence_mult'   => 2.0,
            'jealousy_mult'           => 2.0,
            'maturity_floor'          => null,
            'conflict_passion_gain'   => 0.0,
            'suffocation_threshold'   => null,
        ],
        'toxic' => [
            'comfort_decay_mult'      => 1.0,
            'trust_decay_mult'        => 1.0,
            'resentment_gain_mult'    => 1.3,
            'confrontation_threshold' => 50,
            'absence_comfort_delta'   => 0.0,
            'affinity_absence_mult'   => 1.0,
            'jealousy_mult'           => 1.5,
            'maturity_floor'          => 30,
            'conflict_passion_gain'   => 5.0,
            'suffocation_threshold'   => null,
        ],
    ];

    const ATTACHMENT_REDEMPTION_SHIFTS = [
        'toxic'    => 'anxious',
        'anxious'  => 'secure',
        'avoidant' => 'secure',
        'secure'   => 'secure',
    ];

    const ATTACHMENT_BREAKING_SHIFTS = [
        'secure'   => null, // determined by self_confidence at runtime
        'anxious'  => 'toxic',
        'avoidant' => 'avoidant',
        'toxic'    => 'toxic',
    ];

    // ========== ATTRACTION MATRIX CONSTANTS (PR 11) ==========

    /** Default pass threshold for pillar scoring. */
    const ATTRACTION_PASS_THRESHOLD = 0.4;

    /** Default interaction interval between full matrix re-evaluations. */
    const ATTRACTION_EVAL_INTERVAL = 10;

    /** Archetype presets for NPC attraction profiles. */
    const ATTRACTION_ARCHETYPES = [
        'Warrior' => [
            'beauty_keywords' => ['rugged', 'strong', 'battle-worn', 'scarred', 'muscular', 'commanding', 'fierce'],
            'strength_skills' => ['OneHanded', 'TwoHanded', 'Archery', 'Block', 'HeavyArmor', 'LightArmor'],
            'strength_mode' => 'flexible_total',
            'strength_threshold' => 200,
            'status_metrics' => [
                ['type' => 'faction_rank', 'faction' => 'Companions', 'min' => 1],
            ],
            'competence_metrics' => [
                ['type' => 'kill_category', 'category' => 'people', 'min' => 50],
            ],
            'pillar_rigidity' => ['beauty' => 'rigid', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'rigid'],
            'intimacy_gate' => 'visceral',
            'gender_pref' => 'heterosexual',
            'gender_fluidity' => 0.2,
        ],
        'Noble' => [
            'beauty_keywords' => ['refined', 'regal', 'well-dressed', 'noble', 'commanding', 'clean', 'elegant'],
            'strength_skills' => ['Speech', 'Enchanting', 'Restoration'],
            'strength_mode' => 'irrelevant',
            'strength_threshold' => 100,
            'status_metrics' => [
                ['type' => 'thane_count', 'min' => 2],
                ['type' => 'lifetime_wealth', 'min' => 50000],
            ],
            'competence_metrics' => [
                ['type' => 'quest_count', 'min' => 20],
            ],
            'pillar_rigidity' => ['beauty' => 'soft', 'strength' => 'irrelevant', 'status' => 'rigid', 'competence' => 'rigid'],
            'intimacy_gate' => 'bond',
            'gender_pref' => 'heterosexual',
            'gender_fluidity' => 0.3,
        ],
        'Scholar' => [
            'beauty_keywords' => ['intelligent', 'composed', 'curious', 'bookish', 'sharp-eyed', 'thoughtful'],
            'strength_skills' => ['Destruction', 'Conjuration', 'Alteration', 'Enchanting', 'Restoration', 'Illusion'],
            'strength_mode' => 'flexible_total',
            'strength_threshold' => 180,
            'status_metrics' => [
                ['type' => 'faction_rank', 'faction' => 'College', 'min' => 1],
            ],
            'competence_metrics' => [
                ['type' => 'quest_count', 'min' => 15],
            ],
            'pillar_rigidity' => ['beauty' => 'soft', 'strength' => 'flexible', 'status' => 'rigid', 'competence' => 'rigid'],
            'intimacy_gate' => 'bond',
            'gender_pref' => 'bisexual',
            'gender_fluidity' => 0.5,
        ],
        'Rogue' => [
            'beauty_keywords' => ['dangerous', 'quick', 'sharp-eyed', 'sly', 'lithe', 'shadowy', 'charming'],
            'strength_skills' => ['Sneak', 'Lockpicking', 'Pickpocket', 'Speech', 'LightArmor', 'OneHanded'],
            'strength_mode' => 'flexible_total',
            'strength_threshold' => 180,
            'status_metrics' => [
                ['type' => 'faction_rank', 'faction' => 'ThievesGuild', 'min' => 1],
                ['type' => 'lifetime_wealth', 'min' => 20000],
            ],
            'competence_metrics' => [
                ['type' => 'quest_count', 'min' => 10],
            ],
            'pillar_rigidity' => ['beauty' => 'flexible', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'soft'],
            'intimacy_gate' => 'balanced',
            'gender_pref' => 'bisexual',
            'gender_fluidity' => 0.6,
        ],
        'Priest' => [
            'beauty_keywords' => ['serene', 'kind', 'gentle', 'compassionate', 'radiant', 'pure', 'humble'],
            'strength_skills' => ['Restoration', 'Alteration', 'Speech'],
            'strength_mode' => 'irrelevant',
            'strength_threshold' => 100,
            'status_metrics' => [
                ['type' => 'faction_rank', 'faction' => 'Dawnguard', 'min' => 0],
            ],
            'competence_metrics' => [
                ['type' => 'quest_count', 'min' => 10],
            ],
            'pillar_rigidity' => ['beauty' => 'soft', 'strength' => 'irrelevant', 'status' => 'soft', 'competence' => 'soft'],
            'intimacy_gate' => 'bond',
            'gender_pref' => 'heterosexual',
            'gender_fluidity' => 0.1,
        ],
        'Primal' => [
            'beauty_keywords' => ['wild', 'feral', 'untamed', 'muscular', 'scarred', 'war-paint', 'weathered', 'rugged'],
            'strength_skills' => ['OneHanded', 'TwoHanded', 'Archery', 'LightArmor', 'Sneak', 'Block'],
            'strength_mode' => 'flexible_total',
            'strength_threshold' => 220,
            'status_metrics' => [
                ['type' => 'faction_rank', 'faction' => 'Companions', 'min' => 2],
            ],
            'competence_metrics' => [
                ['type' => 'kill_category', 'category' => 'animals', 'min' => 30],
                ['type' => 'kill_category', 'category' => 'creatures', 'min' => 50],
            ],
            'pillar_rigidity' => ['beauty' => 'rigid', 'strength' => 'flexible', 'status' => 'rigid', 'competence' => 'rigid'],
            'intimacy_gate' => 'visceral',
            'gender_pref' => 'heterosexual',
            'gender_fluidity' => 0.1,
        ],
        'Bard' => [
            'beauty_keywords' => ['attractive', 'charming', 'expressive', 'vibrant', 'captivating', 'well-spoken'],
            'strength_skills' => ['Speech', 'Illusion', 'Sneak'],
            'strength_mode' => 'irrelevant',
            'strength_threshold' => 100,
            'status_metrics' => [
                ['type' => 'lifetime_wealth', 'min' => 10000],
            ],
            'competence_metrics' => [],
            'pillar_rigidity' => ['beauty' => 'rigid', 'strength' => 'irrelevant', 'status' => 'soft', 'competence' => 'irrelevant'],
            'intimacy_gate' => 'visceral',
            'gender_pref' => 'bisexual',
            'gender_fluidity' => 0.7,
        ],
    ];

    /** Default tier thresholds (pillar scores needed to unlock each tier). */
    const DEFAULT_TIER_THRESHOLDS = [
        'crush'  => ['beauty' => 0.3],
        'friend' => ['status' => 0.3],
        'close'  => ['beauty' => 0.4, 'strength' => 0.3],
        'bonded' => ['beauty' => 0.5, 'strength' => 0.5, 'status' => 0.5, 'competence' => 0.5],
        'sworn'  => ['beauty' => 0.6, 'strength' => 0.6, 'status' => 0.7, 'competence' => 0.7],
    ];

    // ========== CASCADING AFFINITY NETWORK (PR 12) ==========

    /** Minimum |affinity delta| to trigger cascade to bonded NPCs. */
    const CASCADE_THRESHOLD = 15;

    /** Propagation decay per hop (30% of original delta reaches neighbors). */
    const CASCADE_DECAY = 0.3;

    /** Maximum number of NPCs affected by a single cascade event. */
    const CASCADE_MAX_TARGETS = 10;

    // ========== ENVIRONMENTAL QUIRKS CONSTANTS (PR 13) ==========

    /** Baseline drift rate: 5% of gap per diary check. */
    const BASELINE_DRIFT_RATE = 0.05;

    /** Maximum baseline drift from temperament default (±20). */
    const BASELINE_DRIFT_MAX = 20;

    /** Minimum consistent samples before drift triggers. */
    const BASELINE_DRIFT_MIN_SAMPLES = 3;

    /** Tolerance band — X must be at least this far from baseline consistently. */
    const BASELINE_DRIFT_TOLERANCE = 5;

    // ========== INTERNAL WEATHER / DEPRIVATION ==========
    // Weather, its modifiers and deprivation live in RelDynFacets (config facet_appraisal).

    const INTIMACY_DEPRIVATION_CONTEXT = [
        'high_m'       => "{NAME} is restless. The tension is physical and they are not the type to suffer in silence. They are considering their options.",
        'high_f'       => "Something aches quietly beneath the surface for {NAME}. The longing is there but they will not chase -- they will withdraw instead.",
        'low_maturity' => "The frustration is bleeding into everything for {NAME}. They are snapping at people, picking fights, making impulsive choices.",
        'balanced'     => "{NAME} has unmet physical needs. It is not urgent yet, but it is there -- a low hum of dissatisfaction that colors their mood.",
    ];

    // ========== VAMPIRE/WEREWOLF MOODIFICATIONS (PR 13) ==========

    const VAMPIRE_NIGHT_MODIFIERS = [
        'arousal' => 10, 'comfort' => -5, 'maturity' => -3, 'passion' => 5, 'coord_m' => 5,
    ];

    const WEREWOLF_MOON_MODIFIERS = [
        'arousal' => 15, 'valence' => -10, 'maturity' => -8,
        'comfort' => -10, 'self_confidence' => 5, 'coord_m' => 10, 'coord_f' => -5,
    ];

    const CREATURE_DAY_INVERSION = 0.3;

    /**
     * Consumable effect definitions.
     *
     * Each entry:
     *   'keywords'            => strings to match in item name (case-insensitive)
     *   'immediate'           => dimension => delta to spike immediately
     *   'duration_game_hours' => how long the spike persists (game time)
     *   'permanent'           => dimension_baseline => permanent bleed (applied once, never reversed)
     */
    const CONSUMABLE_EFFECTS = [
        'skooma' => [
            'keywords' => ['skooma', 'moon sugar', 'redwater', 'balmora blue'],
            'immediate' => ['comfort' => 30, 'passion' => 15, 'arousal' => 40],
            'duration_game_hours' => 1,
            'permanent' => ['maturity' => -2],
        ],
        'sleeping_tree_sap' => [
            'keywords' => ['sleeping tree sap'],
            'immediate' => ['comfort' => 20, 'arousal' => 30],
            'duration_game_hours' => 2,
            'permanent' => ['maturity' => -1, 'trust' => -1],
        ],
        'ale' => [
            'keywords' => ['ale', 'mead', 'wine', 'honningbrew', 'black-briar', 'spiced wine',
                           'argonian ale', 'colovian brandy', 'firebrand wine', 'sujamma', 'mazte',
                           'nord mead', 'alto wine', 'ashfire mead', 'velvet lechance', 'white-gold tower',
                           'cliff racer', 'dragon breath'],
            'immediate' => ['comfort' => 10, 'warmth' => 10],
            'duration_game_hours' => 0.5,
            'permanent' => ['maturity' => -0.5],
        ],
        'healing_potion' => [
            'keywords' => ['potion of healing', 'potion of plentiful healing', 'potion of vigorous healing',
                           'potion of extreme healing', 'potion of ultimate healing', 'healing potion',
                           'restore health', 'cure disease', 'cure poison'],
            'immediate' => ['comfort' => 10],
            'duration_game_hours' => 0.5,
            'permanent' => [],
        ],
        'meal' => [
            'keywords' => ['home-cooked meal', 'homecooked', 'stew', 'soup', 'pie', 'roast',
                           'sweet roll', 'elsweyr fondue', 'horker stew', 'vegetable soup',
                           'venison stew', 'beef stew', 'cabbage soup', 'apple cabbage stew',
                           'tomato soup', 'clam chowder', 'grilled'],
            'immediate' => ['comfort' => 15, 'warmth' => 5],
            'duration_game_hours' => 1,
            'permanent' => [],
        ],
        // --- Generic fallbacks (lowest priority — matched only if nothing specific hit) ---
        'generic_potion' => [
            'keywords' => ['potion', 'elixir', 'philter', 'draught'],
            'immediate' => ['comfort' => 5],
            'duration_game_hours' => 0.5,
            'permanent' => [],
        ],
        'generic_food' => [
            'keywords' => ['food', 'bread', 'cheese', 'meat', 'apple', 'cabbage', 'potato',
                           'tomato', 'leek', 'salmon', 'venison', 'pheasant', 'rabbit',
                           'horker', 'mammoth', 'goat', 'charred', 'cooked'],
            'immediate' => ['comfort' => 8, 'warmth' => 3],
            'duration_game_hours' => 0.5,
            'permanent' => [],
        ],
        'generic_drink' => [
            'keywords' => ['drink', 'brew', 'grog', 'lager', 'spirits', 'flask'],
            'immediate' => ['comfort' => 8, 'warmth' => 5],
            'duration_game_hours' => 0.5,
            'permanent' => ['maturity' => -0.3],
        ],
    ];

    /**
     * Equipped item modifier definitions.
     *
     * Each entry:
     *   'keywords'       => strings to match in item name (case-insensitive)
     *   'while_equipped' => dimension => baseline modifier applied while worn
     *   'on_removal'     => dimension => spike applied on unequip
     */
    const EQUIPPED_EFFECTS = [
        'amulet_of_mara' => [
            'keywords' => ['amulet of mara'],
            'while_equipped' => ['comfort' => 5, 'warmth' => 5],
            'on_removal' => ['comfort' => -10, 'trust' => -3],
        ],
        'wedding_ring' => [
            'keywords' => ['wedding ring', 'bond of matrimony'],
            'while_equipped' => ['comfort' => 8, 'trust' => 5],
            'on_removal' => ['comfort' => -15, 'trust' => -8],
        ],
        'fine_clothes' => [
            'keywords' => ['fine clothes', 'fine raiment', 'noble clothes',
                           'party clothes', 'wedding dress', 'radiant raiment'],
            'while_equipped' => ['respect' => 3, 'warmth' => 2],
            'on_removal' => [],
        ],
        'heavy_armor' => [
            'keywords' => ['iron armor', 'steel armor', 'dwarven armor', 'orcish armor',
                           'ebony armor', 'daedric armor', 'dragonplate', 'plate armor',
                           'heavy armor'],
            'while_equipped' => ['respect' => 5, 'comfort' => -3],
            'on_removal' => [],
        ],
        'daedric_artifact' => [
            'keywords' => ['volendrung', 'mace of molag bal', 'wabbajack', 'sanguine rose',
                           'skull of corruption', 'ebony blade', 'oghma infinium',
                           'ring of namira', 'ring of hircine', 'masque of clavicus',
                           'dawnbreaker', "azura's star", 'skeleton key'],
            'while_equipped' => ['respect' => 10, 'trust' => -5],
            'on_removal' => ['respect' => -5],
        ],
    ];

    // ========== END ITEM DIMENSION MODIFIER CONSTANTS ==========

    // ========== REPUTATION LAYER (PR 9) ==========
    //
    // Pre-contact baseline modifier from fame, infamy, faction rank, and rumors.
    // Shifts where the Stranger baseline STARTS. First impressions from gossip.
    //
    // Formula: effective_stranger_baseline = global_baseline * stranger_type_modifier * reputation_modifier
    //
    // Reputation fades with direct experience:
    //   decay_factor = max(0, 1 - (interaction_count / 15))
    //   At 0 interactions: 1.0 (full reputation effect)
    //   At 5 interactions: 0.67 (reputation < 70%)
    //   At 15+ interactions: 0.0 (reputation irrelevant, personal experience dominates)
    // =============================================================

    /**
     * Reputation source definitions: what data maps to what dimension modifiers.
     *
     * Per-source modifiers are PER UNIT (per kill, per quest, per crime, etc.)
     * and are capped by REPUTATION_CAPS to prevent runaway values.
     *
     * 'dragon_kills':       Each dragon killed earns respect + slight trust. Dragonborn fame.
     * 'quests_completed':   General competence/helpfulness signal. Mild per-quest.
     * 'crimes_committed':   Each crime erodes trust and comfort. Infamy.
     * 'murders':            Each murder is a heavy trust/comfort penalty but earns fear-respect.
     * 'thane':              Per-hold title: comfort + respect (only in that hold).
     * 'faction_rank':       Selective per-NPC -- NPC must share or respect the faction.
     * 'player_level':       Raw power signal -- slight respect from level alone.
     */
    const REPUTATION_SOURCES = [
        'dragon_kills'      => ['respect' => 2.0, 'trust' => 1.0],
        'quests_completed'  => ['respect' => 0.5, 'trust' => 0.2],
        'crimes_committed'  => ['trust' => -1.0, 'comfort' => -0.5],
        'murders'           => ['trust' => -5.0, 'comfort' => -3.0, 'respect' => 2.0],
        'thane'             => ['respect' => 5.0, 'comfort' => 5.0],
        'faction_rank'      => ['respect' => 8.0],
        'player_level'      => ['respect' => 0.3],
    ];

    /**
     * Per-dimension caps for reputation modifiers.
     * No single source or combination can push a dimension beyond these bounds.
     */
    const REPUTATION_CAPS = [
        'trust'   => ['min' => -20, 'max' => 10],
        'respect' => ['min' => -10, 'max' => 20],
        'comfort' => ['min' => -15, 'max' => 10],
    ];

    /**
     * Number of meaningful interactions at which reputation becomes negligible.
     * Used for decay curve: decay_factor = max(0, 1 - (interactions / REPUTATION_DECAY_INTERACTIONS))
     */
    const REPUTATION_DECAY_INTERACTIONS = 15;

    /**
     * Faction affinity mapping: NPC faction -> player factions they respect.
     *
     * If an NPC belongs to a listed faction (key) AND the player belongs to
     * one of the 'respects' factions, the player gets a faction respect bonus.
     * The 'disdains' list inverts it -- belonging to those COSTS respect.
     */
    const FACTION_REPUTATION_MAP = [
        'The Companions' => [
            'respects' => ['The Companions', 'The Circle'],
            'disdains' => ['Dark Brotherhood', "Thieves' Guild"],
        ],
        'The Circle' => [
            'respects' => ['The Companions', 'The Circle'],
            'disdains' => ['Dark Brotherhood', 'Volkihar Vampire Clan'],
        ],
        'College of Winterhold' => [
            'respects' => ['College of Winterhold', 'College of Winterhold Arch-Mage Faction'],
            'disdains' => [],
        ],
        "Thieves' Guild" => [
            'respects' => ["Thieves' Guild", 'Nightingales'],
            'disdains' => ['Imperial Legion'],
        ],
        'Nightingales' => [
            'respects' => ["Thieves' Guild", 'Nightingales'],
            'disdains' => [],
        ],
        'Dark Brotherhood' => [
            'respects' => ['Dark Brotherhood'],
            'disdains' => ['The Companions', 'Imperial Legion'],
        ],
        'Imperial Legion' => [
            'respects' => ['Imperial Legion'],
            'disdains' => ['Stormcloaks'],
        ],
        'Stormcloaks' => [
            'respects' => ['Stormcloaks'],
            'disdains' => ['Imperial Legion'],
        ],
        'Greybeards' => [
            'respects' => ['Greybeards'],
            'disdains' => ['Blades'],
        ],
        'Blades' => [
            'respects' => ['Blades', 'Greybeards'],
            'disdains' => [],
        ],
        'The Dawnguard' => [
            'respects' => ['The Dawnguard'],
            'disdains' => ['Volkihar Vampire Clan'],
        ],
        'Volkihar Vampire Clan' => [
            'respects' => ['Volkihar Vampire Clan'],
            'disdains' => ['The Dawnguard', 'Vigilant of Stendarr For Player'],
        ],
    ];

    /**
     * NPC-to-hold mapping for thane reputation checks.
     *
     * Maps location keywords (from NPC faction strings) to the canonical hold
     * name used in the thane_achievement actor value. The thane_achievement
     * value uses tilde-delimited hold names like "Whiterun~Rift".
     */
    const NPC_HOLD_KEYWORDS = [
        'whiterun'      => 'Whiterun',
        'dragonsreach'  => 'Whiterun',
        'riverwood'     => 'Whiterun',
        'rorikstead'    => 'Whiterun',
        'riften'        => 'Rift',
        'the rift'      => 'Rift',
        'windhelm'      => 'Eastmarch',
        'eastmarch'     => 'Eastmarch',
        'solitude'      => 'Haafingar',
        'haafingar'     => 'Haafingar',
        'markarth'      => 'Reach',
        'the reach'     => 'Reach',
        'falkreath'     => 'Falkreath',
        'morthal'       => 'Hjaalmarch',
        'hjaalmarch'    => 'Hjaalmarch',
        'dawnstar'      => 'Pale',
        'the pale'      => 'Pale',
        'winterhold'    => 'Winterhold',
    ];

    // ========== END REPUTATION LAYER CONSTANTS (PR 9) ==========

    // =========================================================================
    // REQUEST SCOPE (A3: no process-level caches)
    // =========================================================================

    /**
     * Open a request/job scope: drops every cache and lets config/bond lookups be cached
     * until endRequest() or the next beginRequest(). Hooks call this on entry; a
     * long-lived worker calls it per job (or never, and simply reads fresh every time).
     */
    public static function beginRequest()
    {
        self::$config = null;
        self::$bondCache = [];
        self::$requestScopePid = getmypid();
        self::$requestScopeStartedAt = microtime(true);
    }

    public static function endRequest()
    {
        self::$config = null;
        self::$bondCache = [];
        self::$requestScopePid = null;
        self::$requestScopeStartedAt = null;
    }

    private static function inRequestScope()
    {
        if (self::$requestScopePid === null || self::$requestScopePid !== getmypid()) {
            return false;
        }
        if (self::$requestScopeStartedAt === null
            || microtime(true) - self::$requestScopeStartedAt > self::REQUEST_SCOPE_MAX_SECONDS) {
            self::endRequest();
            return false;
        }
        return true;
    }

    // =========================================================================
    // CONFIG
    // =========================================================================

    public static function getConfig()
    {
        $cacheable = self::inRequestScope();
        if ($cacheable && self::$config !== null) {
            return self::$config;
        }

        // Stored row merged over the defaults: a key the row lacks (older settings page,
        // key added since) takes its current default instead of disappearing.
        $config = array_merge(self::defaultConfig(), self::loadStoredConfig());

        self::$config = $cacheable ? $config : null;
        return $config;
    }

    const CONFIG_ROW_ID = 'relationship_dynamics_config';

    /**
     * Stamp saveConfig() writes into the row as 'config_schema'. Rows without it (or older)
     * were saved by the settings page before 2026-09-23, which stored every checkbox with
     * isset() next to its hidden "" twin, so every toggle in them is true whatever was
     * ticked. loadStoredConfig() drops those toggles so the current defaults apply.
     */
    const CONFIG_SCHEMA = 2;

    /** The stored conf_opts row as saved ([] when absent or unreadable). */
    public static function loadStoredConfig(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [];
        try {
            $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id = '" . self::CONFIG_ROW_ID . "' LIMIT 1");
        } catch (Throwable $e) {
            self::logError('loadStoredConfig', $e);
            return [];
        }
        if (!is_array($row) || !isset($row['value']) || $row['value'] === '') return [];
        $stored = json_decode($row['value'], true);
        if (!is_array($stored)) {
            error_log("[RelDyn] ERROR loadStoredConfig: conf_opts " . self::CONFIG_ROW_ID . " is not a JSON object; using defaults");
            return [];
        }
        if (intval($stored['config_schema'] ?? 1) < self::CONFIG_SCHEMA) {
            // Pre-fix row: its toggles are all true by the isset() bug, not by choice.
            $stored = array_diff_key($stored, array_flip(self::CONFIG_FORM_TOGGLES));
        }
        return $stored;
    }

    /** Settings-page checkboxes (each rendered as a hidden "" input followed by the checkbox). */
    const CONFIG_FORM_TOGGLES = [
        'enabled', 'log_enabled',
        'passion_enabled', 'ambient_enabled', 'combat_enabled', 'jealousy_enabled', 'reunion_enabled',
        'conflict_enabled', 'topic_bonus_enabled', 'flirt_bonus_enabled', 'type_filter_enabled',
        'dimension_engine_enabled', 'dimension_context_enabled', 'dimension_debug_logging',
        'divine_intervention_enabled', 'grief_system_enabled', 'attachment_style_enabled',
        'attraction_matrix_enabled',
        'cascade_network_enabled', 'duty_override_enabled', 'parasite_detection_enabled',
        'significance_scaling_enabled', 'baseline_drift_enabled', 'internal_weather_enabled',
        'creature_moodifications_enabled', 'emergent_emotions_enabled',
        'social_masking_enabled', 'autonomous_diary_enabled',
        'social_sensitivity_enabled', 'ick_system_enabled', 'charisma_detection_enabled',
        'autonomy_enabled', 'walkaway_enabled', 'hoover_enabled',
        'director_goals_enabled',
    ];

    /** Settings-page number fields: key => [int|float, min, max] (units: see defaultConfig()). */
    const CONFIG_FORM_NUMBERS = [
        'base_passion_gain'                  => ['float', 0.1, 10.0],
        'passion_max'                        => ['float', 10, 200],
        'decay_max_hours'                    => ['float', 0, 168],
        'jealousy_max'                       => ['float', 10, 200],
        'jealousy_decay_per_hour'            => ['float', 0.1, 10.0],
        'conflict_threshold_affinity_drop'   => ['int', 1, 50],
        'conflict_threshold_jealousy'        => ['int', 5, 100],
        'conflict_resolution_positive_count' => ['int', 1, 20],
        'conflict_repair_passion_burst'      => ['float', 1.0, 50.0],
        'conflict_repair_passion_mult'       => ['float', 1.0, 3.0],
        'reunion_min_hours'                  => ['int', 1, 48],
        'reunion_min_affection'              => ['int', -100, 100],
        'stage_established_threshold'        => ['int', 10, 500],
        'stage_deep_threshold'               => ['int', 50, 2000],
        'attraction_eval_interval'           => ['int', 1, 50],
        'diary_interaction_gap'              => ['int', 5, 50],
        'mask_maturity_cost'                 => ['float', 0.0, 1.0],
        'ick_base_threshold'                 => ['float', 0.2, 0.9],
    ];

    /**
     * Config row to store from a settings-form POST.
     *
     * Each checkbox comes after a hidden input of the same name with value "", so PHP sees
     * "" when unticked and the checkbox value ("on") when ticked: the value decides, not
     * isset(). A field missing from the POST keeps its stored value. Only keys of
     * defaultConfig() are kept, so a known key the form does not show and that was never
     * stored stays absent and follows its default.
     */
    public static function configFromForm(array $post, array $stored): array
    {
        $known = self::defaultConfig();
        $config = array_intersect_key($stored, $known);

        foreach (self::CONFIG_FORM_TOGGLES as $key) {
            if (array_key_exists($key, $post)) {
                $v = is_array($post[$key]) ? end($post[$key]) : $post[$key];
                $config[$key] = !in_array(strtolower(trim((string)$v)), ['', '0', 'off', 'false', 'no'], true);
            }
        }
        foreach (self::CONFIG_FORM_NUMBERS as $key => [$type, $min, $max]) {
            if (!array_key_exists($key, $post) || !is_scalar($post[$key]) || trim((string)$post[$key]) === ''
                || !is_numeric(trim((string)$post[$key]))) {
                continue;
            }
            $v = max($min, min($max, floatval($post[$key])));
            $config[$key] = ($type === 'int') ? intval(round($v)) : floatval($v);
        }
        if (array_key_exists('diary_reflection_mode', $post)) {
            $mode = (string)$post['diary_reflection_mode'];
            $config['diary_reflection_mode'] = in_array($mode, ['baseline', 'trajectory'], true) ? $mode : 'baseline';
        }

        return array_intersect_key($config, $known);
    }

    /** Store a config row (known keys only) and drop the cached config. */
    public static function saveConfig(array $config): bool
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return false;
        $config = array_intersect_key($config, self::defaultConfig());
        $config['config_schema'] = self::CONFIG_SCHEMA;   // this row's toggles are real choices
        try {
            $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row = $db->fetchOne(
                'INSERT INTO conf_opts (id, value) VALUES ($1, $2)
                 ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
                 RETURNING id',
                [self::CONFIG_ROW_ID, $json]
            );
        } catch (Throwable $e) {
            self::logError('saveConfig', $e);
            return false;
        }
        self::clearConfigCache();
        if (!isset($row['id'])) {
            error_log("[RelDyn] ERROR saveConfig: conf_opts " . self::CONFIG_ROW_ID . " was not written");
            return false;
        }
        return true;
    }

    /** Settings page save: merge the POSTed form onto the stored row and store it. */
    public static function saveConfigFromForm(array $post): bool
    {
        return self::saveConfig(self::configFromForm($post, self::loadStoredConfig()));
    }

    /**
     * Every config key RelDyn reads, with its default. Units are given per key; affinity
     * thresholds are core affinity (extended_data.relationships.Player.aff, -100..100).
     */
    public static function defaultConfig()
    {
        return [
            'config_schema' => self::CONFIG_SCHEMA,  // row stamp (see CONFIG_SCHEMA), set by saveConfig()
            'enabled' => true,
            'base_passion_gain' => 2.0,              // passion points (0..passion_max) per interaction
            'passion_max' => 100.0,                  // passion points
            'decay_max_hours' => 0,                  // real play hours of between-session passion decay; 0 = none
            'jealousy_max' => 100.0,                 // jealousy points
            'jealousy_decay_per_hour' => 1.5,        // jealousy points per real play hour
            'conflict_threshold_affinity_drop' => 10, // core affinity points lost in one commit
            'conflict_threshold_jealousy' => 40,     // jealousy points
            'conflict_resolution_positive_count' => 3, // positive interactions
            'conflict_repair_passion_burst' => 20.0, // passion points
            'conflict_repair_passion_mult' => 1.5,   // multiplier
            'reunion_min_hours' => 8,                // game-calendar hours since last contact
            'reunion_min_affection' => 40,           // core affinity (-100..100)
            'stage_established_threshold' => 50,     // positive interactions
            'stage_deep_threshold' => 200,           // positive interactions
            'log_enabled' => false,                  // debug log (errors are always logged)
            // Subsystem toggles (the hooks' former '?? true' fallbacks)
            'passion_enabled'    => true,
            'ambient_enabled'    => true,
            'combat_enabled'     => true,
            'jealousy_enabled'   => true,
            'reunion_enabled'    => true,
            'conflict_enabled'   => true,
            'topic_bonus_enabled' => true,
            'flirt_bonus_enabled' => true,
            'type_filter_enabled' => true,           // not read by the engine yet (relationship-preference-type-filter)
            // XYZ Dimension Engine — on by default: the MDD section 15 eval signals,
            // modifier pipeline and resentment accumulator all run through it.
            'dimension_engine_enabled' => true,
            // Dimension state reaches the LLM as band keywords (felt steering, never numbers;
            // decisions 2026-09-23 section 3).
            'dimension_context_enabled' => true,
            'dimension_debug_logging' => false,
            'dimension_max_context_lines' => 10,
            // Diary reflection mode: 'baseline' (math-only) or 'trajectory' (LLM-scored)
            'diary_reflection_mode' => 'baseline',
            // PR 10: Behavioral system toggles
            'divine_intervention_enabled' => true,
            'grief_system_enabled'        => true,
            'attachment_style_enabled'    => true,
            // PR 11: Attraction Matrix toggles
            'attraction_matrix_enabled' => true,
            'attraction_eval_interval' => 10,
            'attraction_beauty_weight' => 1.0,
            'attraction_strength_weight' => 1.0,
            'attraction_status_weight' => 1.0,
            'attraction_competence_weight' => 1.0,
            // PR 12: Affinity Network + Relationship Types
            'cascade_network_enabled' => true,
            'cascade_threshold' => 15,               // core affinity points (|delta| that ripples)
            'cascade_decay' => 0.3,                  // fraction
            'duty_override_enabled' => true,
            'parasite_detection_enabled' => true,
            // PR 13: Environmental Quirks
            'baseline_drift_enabled' => true,
            'internal_weather_enabled' => true,
            'creature_moodifications_enabled' => true,
            'emergent_emotions_enabled' => true,
            'significance_scaling_enabled' => true,
            // PR 14: Social Masking + Autonomous Diary
            // Off by default: <social_mask> can only state comfort/resentment/warmth as numbers
            // (generateMaskingContext) until its felt-steering rewrite.
            'social_masking_enabled' => false,
            'autonomous_diary_enabled' => true,
            'diary_interaction_gap' => 15,           // interactions
            'mask_maturity_cost' => 0.15,            // maturity points per masked interaction
            // PR 15: Social Sensitivity + Ick + Charisma
            'social_sensitivity_enabled' => true,
            'ick_system_enabled' => true,
            'ick_base_threshold' => 0.5,             // fraction of romantic attempts in the window
            'charisma_detection_enabled' => true,
            // PR 16: Autonomy Override + Walkaway + Hoover
            'autonomy_enabled' => true,
            'walkaway_enabled' => true,              // not read by the engine yet (walkaway-boundary)
            'hoover_enabled' => true,
            // PR 39: Director-Assigned Goals (the hooks ran them unless switched off)
            'director_goals_enabled' => true,
            // Temperament / attachment / maturity-type / trait auto-generation tables
            'temperament_autogen' => self::temperamentAutogenDefaults(),
            // Signed facet preferences (-1..+1) auto-derivation tables (decisions §6, reldyn_facets.php)
            'facet_preferences' => RelDynFacets::preferenceDefaults(),
            // Appraisal: felt read, effects, internal weather (decisions §6, reldyn_facets.php)
            'facet_appraisal' => RelDynFacets::appraisalDefaults(),
            // ===== Time (decisions 2026-09-23 §2: time does not heal, contact does) =====
            // Contacts (this NPC's requests) an NPC back from a resolved boundary test
            // waits before walking away again while its resentment is still high.
            'walkaway_return_grace_contacts' => 5,
            // Reunion: reunion_min_hours is GAME-CALENDAR hours since the last contact; the
            // time apart must also hold this many real minutes of filtered play (no reunion
            // from a wait or sleep alone).
            'reunion_min_play_minutes' => 10,
            // Fester (MDD 15.5 + decisions §2): while a conflict is open, an NPC with
            // maturity (0..100) below fester_maturity_below gains this much RAW resentment
            // (0..100 points) per game-calendar day. Raw goes through applyDelta, which adds
            // the 15.5 +50% (resentment > 30, maturity < 50) and the accumulator physics (inverted rubber
            // band, attachment gain mult), so the felt rate is roughly 2-4x the raw rate.
            // Starting value 1.0 raw: with that physics about the felt rate of the §5 jealousy
            // conversion at k=2 and jealousy 65 (1.0/day, which is added as is).
            'fester_resentment_per_game_day' => 1.0,
            'fester_maturity_below' => 50,
            // Positive-state fade with absence (decisions §2), passion (warmth below): after
            // passion_absence_grace_game_hours without contact, passion (0..100) loses
            // passion_absence_fade_per_game_day per game-calendar day x attachment multiplier,
            // down to the stage floor. In-contact decay stays decayPassion() on the play clock.
            'passion_absence_grace_game_hours' => 24,
            'passion_absence_fade_per_game_day' => 3.0,
            'passion_absence_attachment_mult' => ['anxious' => 2.0, 'avoidant' => 0.5, 'secure' => 1.0, 'toxic' => 1.0],
            // Warmth fades with absence too (decisions §2): after warmth_absence_grace_game_hours
            // without contact, warmth (0..100) above its baseline loses
            // warmth_absence_fade_per_game_day per game-calendar day x attachment multiplier,
            // down to the baseline (never below: absence does not deepen coldness either).
            // Starting value 1.0: warmth is the slow, deep state, a third of passion's rate.
            'warmth_absence_grace_game_hours' => 24,
            'warmth_absence_fade_per_game_day' => 1.0,
            'warmth_absence_attachment_mult' => ['anxious' => 2.0, 'avoidant' => 0.5, 'secure' => 1.0, 'toxic' => 1.0],
            // Global neglect (decisions §2): per RelDyn bond type (getRelationshipType), game days
            // without contact before neglect starts, and RAW resentment (0..100 points, through
            // applyDelta like fester) per game day after that. Types not listed never accrue.
            'neglect_enabled' => true,
            'neglect_bond_types' => [
                'bonded'     => ['grace_game_days' => 3, 'resentment_per_game_day' => 1.0],
                'crush'      => ['grace_game_days' => 4, 'resentment_per_game_day' => 0.75],
                'sworn'      => ['grace_game_days' => 5, 'resentment_per_game_day' => 0.5],
                'friend'     => ['grace_game_days' => 7, 'resentment_per_game_day' => 0.25],
                'friendzone' => ['grace_game_days' => 7, 'resentment_per_game_day' => 0.25],
                'parasite'   => ['grace_game_days' => 2, 'resentment_per_game_day' => 0.5],
            ],
            // Grace multiplier by attachment style: Anxious feels it sooner, Avoidant later.
            'neglect_attachment_grace_mult' => ['anxious' => 0.5, 'avoidant' => 2.0, 'secure' => 1.0, 'toxic' => 0.75],
            // Calendar scan: NPCs whose calendar step is at least this many game hours old are
            // advanced on any request; at most calendar_scan_max_npcs per request.
            'calendar_scan_interval_game_hours' => 1,
            'calendar_scan_max_npcs' => 10,
            // ===== Eval consumer (MDD 15.4, decisions 2026-09-23 §1) =====
            // Significance clamp: one eval item moves a dimension by at most this many points x
            // significance (0..1). Dimension points; for affinity, core points (-100..100 scale).
            'eval_significance_clamp' => 30,
            // M_modifiers = clamp(product of every matching row, min, max) (unitless multipliers)
            'affinity_modifier_min' => 0.25,
            'affinity_modifier_max' => 3.0,
            'affinity_modifiers' => self::affinityModifierDefaults(),
            // Eval source tag -> love language (LL_* ids) for the love-language rows. The one
            // table: the producer's RelDynEval::TAG_LOVE_LANGUAGE is this constant.
            'affinity_tag_love_language' => self::EVAL_TAG_LOVE_LANGUAGE,
            // ===== Resentment / jealousy / conflict (decisions 2026-09-23 §5, MDD 15.5 / 6.5) =====
            // RAW resentment points (0..100 scale, before applyDelta's physics) per flagged
            // grievance (MDD 15.5: +5), times grievance_severity_mult[eval severity 0..3].
            // Severity 0 (flagged, unrated) and 1 are the MDD's one grade; 2 and 3 scale up.
            'grievance_resentment_raw' => 5.0,
            'grievance_severity_mult' => [1.0, 1.0, 1.5, 2.0],
            // RAW resentment points removed per positive interaction (MDD 15.5: -1).
            'resentment_positive_decay' => 1.0,
            // Sustained jealousy converts into resentment (decisions §5): while jealousy (0..100)
            // is above jealousy_resentment_above, resentment (0..100 points, added as is, not
            // through applyDelta) += k x (jealousy - 30) / 70 per game-calendar day
            // (k = jealousy_resentment_k, start 2).
            'jealousy_resentment_k' => 2.0,
            'jealousy_resentment_above' => 30,
            // Jealousy (0..100 points) from an eval jealousy event at intensity 0/1, before the
            // temperament (MDD 1.3), attachment and preference multipliers;
            // x jealousy_intensity_mult[intensity 0..3].
            'jealousy_eval_gain' => 10.0,
            'jealousy_intensity_mult' => [1.0, 1.0, 1.5, 2.0],
            // Eval grievance kinds that are jealousy (a rival), not a grievance: they raise
            // jealousy, which feeds resentment only through the conversion above (§5).
            'jealousy_grievance_kinds' => ['jealousy', 'jealous', 'rival', 'jealousy_trigger', 'envy'],
            // Bystander jealousy: when an eval says the player was intimate with this NPC
            // (tags below, positive interaction), NPCs nearby whose core Player.type is listed
            // gain jealousy_eval_gain x this commitment multiplier (x the same multipliers).
            'jealousy_bystander_tags' => ['intimacy', 'touch'],
            'jealousy_bystander_commitment' => ['romantic' => 1.0, 'obsessed' => 1.5, 'crush' => 0.8],
            // MDD 6.5: jealousy (0..100) at or above this -> walkaway.
            'jealousy_walkaway_at' => 100,
            // Power gap (decisions §5), 0..1: how little the NPC can leave. The largest matching
            // source counts. Core Player.type -> gap; in the player's current party (commanded
            // follower, CHIM CurrentParty) -> gap; core_npc_master faction editor ids (member,
            // rank >= 0, letters/digits substring match) -> gap.
            'power_gap_core_types' => ['servant' => 1.0, 'fanatical' => 0.8, 'indebted' => 0.5, 'fearful' => 0.5],
            'power_gap_in_party' => 0.5,
            'power_gap_factions' => [
                ['match' => ['thrall'], 'gap' => 1.0],
                ['match' => ['housecarl'], 'gap' => 0.8],
                ['match' => ['servant', 'steward'], 'gap' => 0.6],
            ],
            // Conflict from an affinity drop (MDD conflict/repair): a "session" is contact with no
            // game-calendar gap longer than this; a drop of conflict_threshold_affinity_drop core
            // affinity points below the session's high opens a conflict.
            'conflict_session_gap_game_hours' => 6,
            // ===== Place facets (decisions 2026-09-23 §6) =====
            // Core location tags / name keywords / inside-outside / time of day / weather ->
            // facet vector (facet => weight 0..1). See RelDynFacets::placeFacets().
            'place_facets' => RelDynFacets::placeFacetDefaults(),
            // Environmental modifiers (applyEnvironmentalModifiers, dimension engine on): what a
            // place and the hour do to anyone, whatever they like (liking is the appraisal's).
            // Raw dimension deltas (applyDelta); facet rows scale with the facet weight 0..1.
            // April values: danger = the dungeon arousal (+15), dark = the night row
            // (arousal +5, comfort -3), dawn / dusk rows as April TIME_MODIFIERS.
            'environment_modifiers_enabled' => true,
            'environment_facet_effects' => [
                'danger' => ['arousal' => 15],
                'dark'   => ['arousal' => 5, 'comfort' => -3],
            ],
            'environment_time_effects' => [
                'dawn' => ['valence' => 5, 'comfort' => 2],
                'dusk' => ['passion' => 3, 'warmth' => 2],
            ],
            // ===== Preference matching (decisions 2026-09-23 §6) =====
            // Facet classifier tables: anchors, embedding parameters, knowledge_class / category
            // / tag priors, item / creature / activity keywords (reldyn_facet_classifier.php).
            'facet_classifier' => RelDynFacetClassifier::configDefaults(),
            // What a topic / gift appraisal does: MDD 1.2 interest multiplier range, match threshold.
            'thing_appraisal' => RelDynFacetClassifier::appraisalDefaults(),
        ];
    }

    /**
     * Decisions 2026-09-23 §1 (PROPOSED math): the initial M_modifiers table for affinity.
     *
     * A row matches an affinity delta on (sign, tags, when):
     *   'sign'     'gain' (delta > 0), 'loss' (delta < 0) or 'any'
     *   'tags'     the delta must carry at least one of these eval source tags; [] = any delta
     *   'when'     every condition must hold (AND):
     *                ['state' => S, 'op' => '<'|'<='|'>'|'>=', 'value' => number]  (S below)
     *                ['attachment' => secure|avoidant|anxious|toxic]   (MDD 6.1, getAttachmentStyle)
     *                ['trait' => tag]                                  (hasTrait, profile overrides first)
     *                ['love_language' => 'primary'|'secondary']        (a tag maps to that LL)
     *                ['weather' => clear|cloudy|stormy|...]            (_internal_weather)
     *   'requires' config toggle that must be on (state the subsystem keeps; off = row ignored)
     *   'mult'     a number, or linear in a state: at_ref + per_point x (state - ref), clamped
     *              to optional min/max.
     * States (units): maturity 0..100 (dimensions.maturity.x), jealousy 0..jealousy_max jealousy
     * points (jealousy_anger), resentment 0..100 (dimensions.resentment.x), passion 0..100
     * (dimensions.passion.x), comfort 0..100 (dimensions.comfort.x), trust 0..100, respect 0..100.
     */
    public static function affinityModifierDefaults(): array
    {
        return [
            // Current maturity m: losses x (1 + (50 - m)/100): m=0 1.5, m=50 1.0, m=100 0.5
            ['id' => 'maturity_losses', 'sign' => 'loss', 'tags' => [], 'when' => [],
             'mult' => ['state' => 'maturity', 'ref' => 50, 'at_ref' => 1.0, 'per_point' => -0.01]],
            // Jealousy j > 30: losses x (1 + (j - 30)/70), up to 2.0
            ['id' => 'jealousy_losses', 'sign' => 'loss', 'tags' => [], 'requires' => 'jealousy_enabled',
             'when' => [['state' => 'jealousy', 'op' => '>', 'value' => 30]],
             'mult' => ['state' => 'jealousy', 'ref' => 30, 'at_ref' => 1.0, 'per_point' => 1 / 70, 'max' => 2.0]],
            // Jealousy j > 30: reassurance gains x1.2
            ['id' => 'jealousy_reassurance', 'sign' => 'gain', 'tags' => ['quality_time', 'praise', 'reassurance'],
             'requires' => 'jealousy_enabled', 'when' => [['state' => 'jealousy', 'op' => '>', 'value' => 30]], 'mult' => 1.2],
            ['id' => 'anxious_abandonment', 'sign' => 'loss', 'tags' => ['neglect', 'jealousy_trigger'],
             'when' => [['attachment' => 'anxious']], 'mult' => 2.0],
            ['id' => 'anxious_reassurance', 'sign' => 'gain', 'tags' => ['quality_time', 'praise'],
             'when' => [['attachment' => 'anxious']], 'mult' => 1.3],
            ['id' => 'avoidant_closeness', 'sign' => 'gain', 'tags' => ['touch', 'intimacy'],
             'when' => [['attachment' => 'avoidant'], ['state' => 'comfort', 'op' => '<', 'value' => 50]], 'mult' => 0.6],
            ['id' => 'avoidant_neglect', 'sign' => 'loss', 'tags' => ['neglect'],
             'when' => [['attachment' => 'avoidant']], 'mult' => 0.5],
            ['id' => 'toxic_all', 'sign' => 'any', 'tags' => [], 'when' => [['attachment' => 'toxic']], 'mult' => 1.4],
            ['id' => 'egocentric_flattery', 'sign' => 'gain', 'tags' => ['gift', 'praise'],
             'when' => [['trait' => 'egocentric']], 'mult' => 1.5],
            ['id' => 'egocentric_slight', 'sign' => 'loss', 'tags' => ['criticism', 'insult', 'neglect'],
             'when' => [['trait' => 'egocentric']], 'mult' => 1.5],
            // The player outshining them stings
            ['id' => 'egocentric_outshone', 'sign' => 'gain', 'tags' => ['help', 'competence'],
             'when' => [['trait' => 'egocentric']], 'mult' => 0.8],
            // MDD 1.2: primary x2.0 / secondary x1.5
            ['id' => 'love_language_primary', 'sign' => 'gain', 'tags' => [], 'when' => [['love_language' => 'primary']], 'mult' => 2.0],
            ['id' => 'love_language_secondary', 'sign' => 'gain', 'tags' => [], 'when' => [['love_language' => 'secondary']], 'mult' => 1.5],
            // MDD 15.4 stage 3 / 15.5: resentment above 50 halves gains
            ['id' => 'resentment_blocks_gains', 'sign' => 'gain', 'tags' => [],
             'when' => [['state' => 'resentment', 'op' => '>', 'value' => 50]], 'mult' => 0.5],
            // MDD 1.1: passion 0 -> x0.3 (idling), 100 -> x2.0 (redline)
            ['id' => 'passion_drives_gains', 'sign' => 'gain', 'tags' => [], 'requires' => 'passion_enabled', 'when' => [],
             'mult' => ['state' => 'passion', 'ref' => 0, 'at_ref' => 0.3, 'per_point' => 0.017]],
            ['id' => 'stormy_losses', 'sign' => 'loss', 'tags' => [], 'requires' => 'internal_weather_enabled',
             'when' => [['weather' => 'stormy']], 'mult' => 1.2],
            // Self-confidence (0..100, only once the dimension is active) below 30: no internal
            // counterweight to criticism, losses x1.5 (RelDyn cross-signal rule, inside M's
            // clamp here; applyCrossSignalCaps skips it for eval affinity)
            ['id' => 'low_self_confidence_losses', 'sign' => 'loss', 'tags' => [],
             'when' => [['state' => 'self_confidence', 'op' => '<', 'value' => 30]], 'mult' => 1.5],
        ];
    }

    /**
     * One config value, falling back to the current default when neither the stored row
     * nor defaultConfig() has the key (getConfig() already lays the row over the defaults).
     */
    public static function configValue(string $key)
    {
        $cfg = self::getConfig();
        return array_key_exists($key, $cfg) ? $cfg[$key] : (self::defaultConfig()[$key] ?? null);
    }

    /** Drop cached config and close any open request scope, so nothing stays cached. */
    public static function clearConfigCache()
    {
        self::endRequest();
    }

    public static function isEnabled()
    {
        $cfg = self::getConfig();
        return !empty($cfg['enabled']);
    }

    // =========================================================================
    // NPC DYNAMICS DATA (plugin_extended_data.reldyn.dynamics, see reldyn_storage.php)
    // =========================================================================

    /**
     * Raw stored dynamics for an NPC (no defaults merged), or null when none exist.
     * Always reads the database. Fresh start: April data in extended_data is not read.
     */
    public static function loadStoredDynamics($npcName)
    {
        if (empty($npcName) || empty($GLOBALS['db'])) return null;

        $npcId = RelDynStorage::resolveNpcId($npcName);
        if ($npcId === null) return null;

        return RelDynStorage::loadDynamics($npcId);
    }

    public static function getDynamics($npcName)
    {
        if (empty($npcName)) return self::defaultDynamics();

        // Primary: core_npc_master.plugin_extended_data.reldyn.dynamics (fresh read, no cache)
        try {
            $rd = self::loadStoredDynamics($npcName);
            if (is_array($rd) && !empty($rd)) {
                $base = self::normalizeStoredDynamics($rd);
                $merged = $base;
                // Resolved into the copy (not the base) so the next save persists it.
                self::ensureTemperamentProfile($npcName, $merged);
                // ========== REPUTATION LAYER (PR 9) ==========
                if (isset($GLOBALS['PLAYER_NAME']) && !empty($GLOBALS['PLAYER_NAME'])) {
                    $temperament = $merged['inferred_temperament'] ?? $merged['temperament'] ?? 'Stoic';
                    self::applyReputationModifiers($merged, $GLOBALS['PLAYER_NAME'], $npcName, $temperament);
                }
                // saveDynamics() merges this copy's changes onto whatever is stored by then
                $merged[self::LOAD_TOKEN_KEY] = self::rememberLoadedBase($base);
                return $merged;
            }
            if (!empty($GLOBALS['db']) && RelDynStorage::resolveNpcId($npcName) !== null) {
                $defaults = self::defaultDynamics();
                $defaults[self::LOAD_TOKEN_KEY] = self::rememberLoadedBase(self::defaultDynamics());
                self::ensureTemperamentProfile($npcName, $defaults);
                return $defaults;
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn] getDynamics storage error for {$npcName}: " . $e->getMessage());
        }

        // Fallback: try nsfw_npc_data (legacy, pre-storage-pivot)
        if (class_exists('NsfwNpcData')) {
            $rd = NsfwNpcData::getKey($npcName, 'relationship_dynamics');
            if (is_array($rd) && !empty($rd)) {
                $merged = array_merge(self::defaultDynamics(), $rd);
                $merged = self::migrateDimensions($merged);
                // ========== REPUTATION LAYER (PR 9) ==========
                if (isset($GLOBALS['PLAYER_NAME']) && !empty($GLOBALS['PLAYER_NAME'])) {
                    $temperament = $merged['inferred_temperament'] ?? $merged['temperament'] ?? 'Stoic';
                    self::applyReputationModifiers($merged, $GLOBALS['PLAYER_NAME'], $npcName, $temperament);
                }
                return $merged;
            }
        }

        // No data found: return defaults
        return self::defaultDynamics();
    }

    /**
     * Persist a copy obtained from getDynamics().
     *
     * Hooks, the eval consumer and the editor each load a copy, change it and save it,
     * and requests for the same NPC overlap. So the save is a three-way merge, not an
     * overwrite: only what THIS copy changed since it was loaded is applied on top of
     * what is stored now (see mergeDynamics()), written with a compare-and-set that
     * retries when another writer got in between. On success $dynamics is replaced by
     * the merged state, so the caller can keep working on it and save again.
     */
    public static function saveDynamics($npcName, &$dynamics)
    {
        if (empty($npcName)) return false;

        $token = $dynamics[self::LOAD_TOKEN_KEY] ?? null;
        $mine = $dynamics;
        unset($mine[self::LOAD_TOKEN_KEY]);

        // XYZ shim: sync legacy keys from dimensions before persisting
        $mine = self::syncLegacyFromDimensions($mine);

        // Primary: write only the 'dynamics' key of plugin_extended_data.reldyn, so a save
        // never clobbers the eval inbox or any other key written concurrently.
        try {
            if (!empty($GLOBALS['db'])) {
                $npcId = RelDynStorage::resolveNpcId($npcName);
                if ($npcId !== null) {
                    $base = ($token !== null) ? (self::$loadedBases[$token] ?? null) : null;
                    if ($base === null) {
                        error_log("[RelDyn] saveDynamics for {$npcName}: copy has no load snapshot (not from getDynamics() in this process); it overwrites the stored state");
                    }
                    for ($attempt = 1; $attempt <= self::SAVE_MERGE_ATTEMPTS; $attempt++) {
                        $current = RelDynStorage::readKeyForUpdate($npcId, RelDynStorage::KEY_DYNAMICS);
                        if ($current === null) {
                            error_log("[RelDyn] saveDynamics for {$npcName}: core_npc_master row {$npcId} is gone");
                            return false;
                        }
                        $toWrite = $mine;
                        if ($base !== null) {
                            $theirs = self::normalizeStoredDynamics(is_array($current['value']) ? $current['value'] : null);
                            $toWrite = self::syncLegacyFromDimensions(self::mergeDynamics($base, $mine, $theirs));
                        }
                        if (RelDynStorage::setKeyIfUnchanged($npcId, RelDynStorage::KEY_DYNAMICS, $current['expected'], $toWrite)) {
                            $toWrite[self::LOAD_TOKEN_KEY] = self::rememberLoadedBase($toWrite);
                            $dynamics = $toWrite;
                            return true;
                        }
                    }
                    error_log("[RelDyn] saveDynamics for {$npcName}: state changed under every one of " . self::SAVE_MERGE_ATTEMPTS . " merge attempts; this save was dropped");
                    return false;
                }
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn] saveDynamics storage error for {$npcName}: " . $e->getMessage());
        }

        // Fallback: try nsfw_npc_data (legacy)
        if (class_exists('NsfwNpcData')) {
            return NsfwNpcData::setKey($npcName, 'relationship_dynamics', $mine);
        }

        return false;
    }

    // ---- Lost-update protection for saveDynamics() -------------------------------------

    /** Hidden key carrying a loaded copy's snapshot token (never stored). */
    const LOAD_TOKEN_KEY = '_rd_load_token';
    const LOADED_BASES_MAX = 32;
    const SAVE_MERGE_ATTEMPTS = 5;

    /** token => the stored state (normalized) a loaded copy was derived from, this process only. */
    private static $loadedBases = [];

    /** Stored blob in the shape getDynamics() hands out (defaults filled, dimensions migrated). */
    private static function normalizeStoredDynamics(?array $stored): array
    {
        $d = self::migrateDimensions(array_merge(self::defaultDynamics(), $stored ?? []));
        unset($d[self::LOAD_TOKEN_KEY]);
        return self::syncLegacyFromDimensions($d);
    }

    private static function rememberLoadedBase(array $base): string
    {
        $token = md5(serialize($base));
        unset(self::$loadedBases[$token]);
        self::$loadedBases[$token] = $base;
        while (count(self::$loadedBases) > self::LOADED_BASES_MAX) {
            unset(self::$loadedBases[array_key_first(self::$loadedBases)]);
        }
        return $token;
    }

    /**
     * Three-way merge: apply what $mine changed relative to $base on top of $theirs (the
     * state stored now). A key only one side changed takes that side's value. A key both
     * sides changed:
     *   - nested maps merge key by key;
     *   - clocks/checkpoints (…_at, …_ts, …gamets, last…, accumulated…, …_hwm) take the max,
     *     so a stale copy never rewinds a clock and never re-counts an interval;
     *   - accumulators (dimension x, passion, jealousy_anger, _pending_aff_delta, counters)
     *     add both changes: theirs + (mine - base), clamped to the value's range;
     *   - anything else (strings, flags, lists, set-once numbers) takes this copy's value.
     * The affinity mirror is merged as a pair so the uncommitted part (x - _aff_mirror_x)
     * of both sides survives and nothing already committed to core is queued again.
     */
    public static function mergeDynamics(array $base, array $mine, array $theirs): array
    {
        $out = self::mergeDynamicsLevel($base, $mine, $theirs, '');

        $uncommitted = static function (array $d): ?float {
            $x = $d['dimensions']['affinity']['x'] ?? null;
            $mark = $d['_aff_mirror_x'] ?? null;
            return (self::isMergeNumber($x) && self::isMergeNumber($mark)) ? floatval($x) - floatval($mark) : null;
        };
        $uBase = $uncommitted($base);
        $uMine = $uncommitted($mine);
        $uTheirs = $uncommitted($theirs);
        $mark = $out['_aff_mirror_x'] ?? null;
        if ($uBase !== null && $uMine !== null && $uTheirs !== null && self::isMergeNumber($mark)
            && isset($out['dimensions']['affinity']) && is_array($out['dimensions']['affinity'])) {
            $out['dimensions']['affinity']['x'] = round(floatval($mark) + $uTheirs + $uMine - $uBase, 4);
        }
        return $out;
    }

    private static function mergeDynamicsLevel(array $base, array $mine, array $theirs, string $path): array
    {
        $out = [];
        foreach (array_keys($mine + $theirs + $base) as $k) {
            $inB = array_key_exists($k, $base);
            $inM = array_key_exists($k, $mine);
            $inT = array_key_exists($k, $theirs);
            $b = $base[$k] ?? null;
            $m = $mine[$k] ?? null;
            $t = $theirs[$k] ?? null;

            $mineChanged = ($inM !== $inB) || ($inM && !self::sameMergeValue($m, $b));
            if (!$mineChanged) {
                if ($inT) $out[$k] = $t;
                continue;
            }
            $theirsChanged = ($inT !== $inB) || ($inT && !self::sameMergeValue($t, $b));
            if (!$theirsChanged) {
                if ($inM) $out[$k] = $m;
                continue;
            }

            // Both sides changed this key. Even an identical change is two events for an
            // accumulator (two requests each adding 0.5), so numbers go through the rules.
            if (!$inM) continue;                   // this copy removed it (e.g. consumed)
            if (!$inT) { $out[$k] = $m; continue; }

            $childPath = ($path === '') ? (string) $k : $path . '.' . $k;
            if (self::isMergeMap($m) && self::isMergeMap($t)) {
                $out[$k] = self::mergeDynamicsLevel(self::isMergeMap($b) ? $b : [], $m, $t, $childPath);
            } elseif ($inB && self::isMergeNumber($b) && self::isMergeNumber($m) && self::isMergeNumber($t)) {
                $out[$k] = self::mergeDynamicsNumber($childPath, (string) $k, $b, $m, $t);
            } else {
                $out[$k] = $m;
            }
        }
        return $out;
    }

    private static function mergeDynamicsNumber(string $path, string $key, $b, $m, $t)
    {
        // Merged as a pair in mergeDynamics()
        if ($path === 'dimensions.affinity.x' || $key === '_aff_mirror_x') {
            return $m;
        }
        if (preg_match('/(^|_)(at|ts|gamets|tick|start|hwm)$|(^|_)last(_|$)|(^|_)accumulated(_|$)/', $key)) {
            return max($m, $t);
        }
        $additive = in_array($key, ['x', 'passion', 'jealousy_anger', '_pending_aff_delta'], true)
            || preg_match('/(_count|_interactions|_given|_score|_window)$/', $key)
            || preg_match('/^(passion_sources|_interest_satisfaction|_interaction_pattern)\./', $path);
        if (!$additive) {
            return $m;
        }

        $v = $t + ($m - $b);
        if ($key === 'x' && preg_match('/^dimensions\.([^.]+)\.x$/', $path, $dm)) {
            $def = self::getDimensionDefinition($dm[1]);
            if ($def) {
                $v = max((float) $def['range_min'], min((float) $def['range_max'], $v));
            }
        } elseif ($key === 'passion' || $key === 'jealousy_anger') {
            $v = max(0.0, min(100.0, $v));
        } elseif ($key !== '_pending_aff_delta') {
            $v = max(0, $v);
        }
        return (is_int($b) && is_int($m) && is_int($t)) ? (int) $v : round($v, 6);
    }

    private static function isMergeNumber($v): bool
    {
        return is_int($v) || is_float($v);
    }

    private static function isMergeMap($v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    private static function sameMergeValue($a, $b): bool
    {
        if (self::isMergeNumber($a) && self::isMergeNumber($b)) {
            return abs(floatval($a) - floatval($b)) <= 1e-9 * max(1.0, abs(floatval($a)));
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) return false;
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::sameMergeValue($v, $b[$k])) return false;
            }
            return true;
        }
        return $a === $b;
    }

    public static function defaultDynamics()
    {
        return [
            'love_language_primary'   => null,
            'love_language_secondary' => null,
            'warmth_curve'            => null,

            'passion'                 => 0.0,
            'passion_updated_at'      => 0,
            'passion_sources'         => ['love_match' => 0, 'reunion' => 0, 'dramatic' => 0, 'repair' => 0],

            'jealousy_anger'          => 0.0,
            'jealousy_updated_at'     => 0,
            'jealousy_trigger_npc'    => null,

            'in_conflict'             => false,
            'conflict_entered_at'     => 0,
            'conflict_positive_count' => 0,

            'interaction_count'       => 0,
            'last_interaction_at'     => 0,

            'last_seen_at'            => 0,
            'reunion_spike_given'     => false,

            'total_positive_interactions' => 0,
            'stage'                   => self::STAGE_EARLY,

            'love_language_hints_given' => 0,

            'inferred_temperament'    => null,

            // Tiered context injection — high water mark (0-3)
            'context_tier_hwm' => 0,

            // ========== DIMENSIONAL MEMORY (PR 9) ==========
            'dimensional_memory' => [],

            // XYZ dimension sub-object — mirrors legacy keys, future authority
            'dimensions' => [
                'affinity'        => ['x' => 0, 'baseline' => null],   // baseline filled from temperament at runtime
                'passion'         => ['x' => 0, 'baseline' => 0],
                'warmth'          => ['x' => 0, 'baseline' => null],
                // ========== MATURITY DIMENSION (PR 3) ==========
                'maturity'        => ['x' => null, 'baseline' => null, 'active' => true, 'plasticity_type' => null],
                // ========== TRUST DIMENSION (PR 4) ==========
                'trust'           => ['x' => null, 'baseline' => null, 'active' => true],
                // ========== COMFORT DIMENSION (PR 4) ==========
                'comfort'         => ['x' => null, 'baseline' => null, 'active' => true],
                // ========== RESPECT DIMENSION (PR 4) ==========
                'respect'         => ['x' => null, 'baseline' => null, 'active' => true],
                // ========== RESENTMENT DIMENSION (PR 7) ==========
                'resentment'      => ['x' => 0, 'baseline' => 0, 'active' => true, 'pending_grievances' => [], 'grievance_log' => [], 'last_decay_tick' => 0],
                // ========== RESENTMENT_SELF (PR 7) ==========
                'resentment_self' => ['x' => 0, 'baseline' => 0, 'active' => true],
                // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========
                'self_confidence' => ['x' => null, 'baseline' => null, 'active' => true],
                // ========== M/F COORDINATES (PR 6) ==========
                'coord_m'         => ['x' => null, 'baseline' => null, 'active' => true],
                'coord_f'         => ['x' => null, 'baseline' => null, 'active' => true],
                // ========== AROUSAL/VALENCE (PR 6) ==========
                'arousal'         => ['x' => 0, 'baseline' => 10, 'active' => true],
                'valence'         => ['x' => 0, 'baseline' => 0, 'active' => true],
            ],

            // ========== DIARY SELF-EVAL TRACKING (PR 9) ==========
            '_last_diary_reflection' => 0,
            '_intrinsic_goals'       => [],
            '_director_goal'         => null,    // Active director-assigned goal {text, source, created_gamets, max_age_gamets, priority, active}
            '_director_goal_history' => [],      // Recent completed/expired goals (cap 5)
            '_diary_snapshots'       => [],

            // ========== ACCUMULATED TIME TRACKING ==========
            '_accumulated_time'       => 0,    // total seconds of actual play time with this NPC
            '_last_interaction_ts'    => 0,    // real timestamp of last interaction (for delta calc)
            '_decay_last_accumulated' => 0,    // accumulated time at last affinity decay
            '_resentment_last_accumulated' => 0, // accumulated time at last resentment decay
            '_diary_last_accumulated' => 0,    // accumulated time at last diary reflection

            // ========== GAMETS PLAY TIME TRACKING ==========
            '_last_gamets'              => 0,     // last seen gamets value from game clock
            '_last_real_ts'             => 0,     // real timestamp at last gamets sample
            '_accumulated_play_gamets'  => 0,     // filtered game time (excludes wait/sleep)
            '_decay_last_game_gamets'   => 0,     // game-calendar gamets at the last absence-decay check
            '_resentment_last_play_gamets' => 0,  // accumulated play gamets at last resentment decay

            // ========== DIVINE INTERVENTION (PR 10) ==========
            '_divine_intervention_last' => 0,
            '_divine_intervention_count' => 0,
            '_divine_intervention_last_type' => null,

            // ========== UNSTABLE WINDOW (PR 10) ==========
            '_unstable_window' => null,

            // ========== DEATH/GRIEF SYSTEM (PR 10) ==========
            '_grief_bonds' => [],
            '_widow_lock_ceiling' => 100,

            // ========== PLASTICITY OVERRIDE (PR 10) ==========
            '_plasticity_override' => null,
            '_plasticity_override_start_gamets' => 0,
            '_plasticity_override_expires_gamets' => 0,

            // ========== ATTACHMENT STYLE (PR 10) ==========
            'attachment_style' => null,
            '_attachment_shift_available' => false,
            '_attachment_drift_last_check' => 0,
            '_attachment_drift_score' => 0,

            // ========== ATTRACTION MATRIX (PR 11) ==========
            'attraction_profile' => null,              // null = derive from archetype
            '_attraction_matrix_cache' => null,        // Cached matrix result
            '_attraction_matrix_last_eval' => 0,       // Interaction count at last full eval
            '_attraction_tier_ceiling' => 'sworn',     // Current max tier (default: unrestricted)
            '_attraction_friendzoned' => false,        // Friendzone flag
            '_attraction_passion_mult' => 1.0,         // Cached passion modifier

            // ========== INTERACTION PATTERNS (PR 12) ==========
            '_interaction_pattern' => [
                'gift_count' => 0,
                'genuine_count' => 0,
                'total_window' => 0,
                'window_start' => 0,
                'last_interaction_type' => null,
            ],
            '_relationship_type_override' => null,
            '_relationship_type_history' => [],

            // ========== ENVIRONMENTAL QUIRKS (PR 13) ==========
            '_baseline_drift_samples' => [],
            '_interest_satisfaction' => [],
            '_interest_last_satisfied' => [],
            '_internal_weather' => 'clear',
            '_intimacy_last_satisfied' => 0,
            'creature_type' => null,

            // ========== SOCIAL MASKING + AUTONOMOUS DIARY (PR 14) ==========
            '_was_masking' => false,
            '_mask_interactions_count' => 0,
            '_performed_state_cache' => null,
            '_diary_last_interaction' => 0,
            '_diary_last_di_count' => 0,
            '_diary_last_attachment' => null,
            '_diary_last_emotions' => [],
            '_diary_last_tier_ceiling' => 'sworn',
            '_diary_last_grief_phases' => [],
            '_diary_pending_triggers' => [],
            '_diary_trigger_source' => null,
        ];
    }

    // ========== ACCUMULATED TIME TRACKING ==========

    /**
     * Update accumulated play time for this NPC.
     *
     * Called at the start of every prerequest cycle. Measures real wall-clock
     * delta since the last interaction, caps it at 300 seconds (5 min) to
     * prevent AFK/session-break inflation, and adds the capped delta to the
     * running total.
     *
     * First interaction (last_ts = 0) initializes the timestamp without
     * adding any time.
     *
     * @param array &$dynamics  NPC dynamics blob (modified in place)
     * @return int  The capped delta (seconds) that was added this call
     */
    public static function updateAccumulatedTime(&$dynamics)
    {
        $now = time();
        $last = intval($dynamics['_last_interaction_ts'] ?? 0);

        if ($last === 0) {
            // First interaction -- initialize, don't add time
            $dynamics['_last_interaction_ts'] = $now;
            return 0;
        }

        $delta = max(0, $now - $last);
        $capped = min($delta, 300); // 5-minute cap per gap

        $dynamics['_accumulated_time'] = intval($dynamics['_accumulated_time'] ?? 0) + $capped;
        $dynamics['_last_interaction_ts'] = $now;

        return $capped;
    }

    // ========== GAMETS PLAY TIME TRACKING ==========

    /**
     * Update filtered play time using gamets (Skyrim internal game clock).
     *
     * Compares gamets delta against real-time delta to drop wait/sleep.
     * Normal gameplay at 20:1 time compression produces ~2315 gamets/real-sec.
     * Wait/sleep produces 100K+ gamets/real-sec. The credit is capped at
     * real seconds x GAMETS_PER_REAL_SECOND, so a wait or sleep anywhere in the
     * gap adds nothing beyond the real seconds that passed.
     *
     * First call (last_gamets = 0) initializes without adding time.
     *
     * The per-gap cap cannot tell play from real time with the game clock stopped (quit
     * overnight, menus, alt-tab): after a real break, a wait or sleep fits under it. With
     * $globalPlayGamets (the global play heartbeat, beatPlayClock(): played gamets across
     * all requests, offline gaps capped) the credit is also bounded by the play the
     * heartbeat saw since this NPC's last turn (_last_global_play_gamets). A gap with no
     * heartbeat mark (stored before it existed) credits nothing: it cannot be proven play.
     *
     * @param array &$dynamics       NPC dynamics blob (modified in place)
     * @param float|null $currentGamets  Current gamets value (from $gameRequest[2] or DB fallback)
     * @param float|null $globalPlayGamets  Global play heartbeat now (play gamets), null = unavailable
     * @return float  The gamets delta that was actually counted (0 if filtered or first call)
     */
    public static function updatePlayTime(&$dynamics, $currentGamets = null, ?float $globalPlayGamets = null)
    {
        // Heartbeat bound for this gap (play gamets), read before the mark moves to now.
        $globalBound = null;
        if ($globalPlayGamets !== null) {
            $lastGlobal = $dynamics['_last_global_play_gamets'] ?? null;
            $globalBound = (is_numeric($lastGlobal) && floatval($lastGlobal) <= $globalPlayGamets)
                ? $globalPlayGamets - floatval($lastGlobal)
                : 0.0;   // no mark yet, or a mark from a reset heartbeat: nothing proven
            $dynamics['_last_global_play_gamets'] = $globalPlayGamets;
        }

        // Resolve current gamets: parameter > gameRequest > DB fallback
        if ($currentGamets === null) {
            $currentGamets = self::currentGamets();
        } else {
            $currentGamets = floatval($currentGamets);
        }

        if ($currentGamets <= 0) {
            return 0.0;
        }

        $now = time();
        $lastGamets = floatval($dynamics['_last_gamets'] ?? 0);
        $lastRealTs = intval($dynamics['_last_real_ts'] ?? 0);

        // First call -- initialize, don't add time
        if ($lastGamets <= 0 || $lastRealTs <= 0) {
            $dynamics['_last_gamets'] = $currentGamets;
            $dynamics['_last_real_ts'] = $now;
            return 0.0;
        }

        $gametsDelta = $currentGamets - $lastGamets;
        $realDelta = $now - $lastRealTs;

        // Update tracking timestamps
        $dynamics['_last_gamets'] = $currentGamets;
        $dynamics['_last_real_ts'] = $now;

        // Skip: same request, clock issue, or gamets went backward (reload)
        if ($realDelta <= 0 || $gametsDelta <= 0) {
            return 0.0;
        }

        // Credit at most what normal play (timescale 20, GAMETS_PER_REAL_SECOND) produces in
        // the real seconds that passed. A gap can mix play with a wait or sleep (1 h of play
        // + a 24 h sleep averages ~5100 gamets/s, under GAMETS_WAIT_SLEEP_THRESHOLD), so a
        // ratio test over the whole gap cannot drop the sleep; the cap drops it. A pure wait
        // or sleep is credited only the few real seconds it took.
        $credited = min($gametsDelta, $realDelta * self::GAMETS_PER_REAL_SECOND);
        if ($credited < $gametsDelta && ($gametsDelta / $realDelta) > self::GAMETS_WAIT_SLEEP_THRESHOLD) {
            self::log("[RelDyn-GAMETS] wait/sleep in gap: delta={$gametsDelta} gamets in {$realDelta}s, credited {$credited}");
        }
        if ($globalBound !== null && $globalBound < $credited) {
            self::log("[RelDyn-GAMETS] heartbeat saw {$globalBound} play gamets in the gap; credited {$globalBound} of {$credited}");
            $credited = $globalBound;
        }

        $dynamics['_accumulated_play_gamets'] = floatval($dynamics['_accumulated_play_gamets'] ?? 0) + $credited;

        return (float) $credited;
    }

    /**
     * Get accumulated play time in minutes.
     *
     * @param array $dynamics  NPC dynamics blob
     * @return float  Accumulated minutes
     */
    public static function getAccumulatedMinutes($dynamics)
    {
        return intval($dynamics['_accumulated_time'] ?? 0) / 60.0;
    }

    // ---------- Global play heartbeat ----------
    // One conf_opts row counts played gamets across every request RelDyn's prerequest sees,
    // whatever NPC it is for. Each gap between two requests credits
    // min(game gamets passed, min(real seconds, PLAY_HEARTBEAT_GAP_CAP_S) x GAMETS_PER_REAL_SECOND):
    // a wait or sleep adds only the real seconds it took, and an offline gap (quit, menus,
    // alt-tab) adds at most the cap. updatePlayTime() bounds each NPC's play credit by it.

    const PLAY_HEARTBEAT_ROW_ID = 'relationship_dynamics_play_clock';
    /** Real seconds: the longest gap between two requests still counted as play (clock 3's cap). */
    const PLAY_HEARTBEAT_GAP_CAP_S = 300;
    /** Real seconds: requests closer than this do not write (the gap is counted by the next write). */
    const PLAY_HEARTBEAT_MIN_WRITE_S = 5;

    /**
     * Advance the global play heartbeat to now and return its total (play gamets), or null
     * when there is no database or no game clock. Compare-and-set: of two concurrent requests
     * one writes; the other returns the stored total (its gap is counted by the next beat).
     */
    public static function beatPlayClock(?float $gamets = null, ?int $nowReal = null): ?float
    {
        $db = $GLOBALS['db'] ?? null;
        $gamets = $gamets ?? self::currentGamets();       // raw game-calendar gamets
        $nowReal = $nowReal ?? time();                     // real seconds (unix)
        if (!$db || $gamets <= 0) {
            return null;
        }
        try {
            $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::PLAY_HEARTBEAT_ROW_ID]);
            $raw = is_array($row) ? ($row['value'] ?? null) : null;
            $cur = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($cur) || !is_numeric($cur['real_ts'] ?? null) || !is_numeric($cur['gamets'] ?? null)
                || !is_numeric($cur['play'] ?? null)) {
                $fresh = json_encode(['real_ts' => $nowReal, 'gamets' => $gamets, 'play' => 0.0]);
                if ($raw === null) {
                    $db->fetchOne('INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO NOTHING RETURNING id',
                        [self::PLAY_HEARTBEAT_ROW_ID, $fresh]);
                } else {
                    error_log("[RelDyn] ERROR beatPlayClock: conf_opts " . self::PLAY_HEARTBEAT_ROW_ID . " unreadable; restarting it");
                    $db->fetchOne('UPDATE conf_opts SET value = $2 WHERE id = $1 AND value = $3 RETURNING id',
                        [self::PLAY_HEARTBEAT_ROW_ID, $fresh, $raw]);
                }
                return 0.0;
            }
            $play = floatval($cur['play']);
            $realDelta = $nowReal - intval($cur['real_ts']);
            if ($realDelta < self::PLAY_HEARTBEAT_MIN_WRITE_S) {
                return $play;   // also a clock that went backwards: nothing to credit
            }
            $gametsDelta = $gamets - floatval($cur['gamets']);   // negative after a reload: no credit
            $credit = $gametsDelta > 0
                ? min($gametsDelta, min($realDelta, self::PLAY_HEARTBEAT_GAP_CAP_S) * self::GAMETS_PER_REAL_SECOND)
                : 0.0;
            $next = json_encode(['real_ts' => $nowReal, 'gamets' => $gamets, 'play' => $play + $credit]);
            $won = $db->fetchOne('UPDATE conf_opts SET value = $2 WHERE id = $1 AND value = $3 RETURNING id',
                [self::PLAY_HEARTBEAT_ROW_ID, $next, $raw]);
            if (isset($won['id'])) {
                return $play + $credit;
            }
            $again = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::PLAY_HEARTBEAT_ROW_ID]);
            $other = json_decode((string)($again['value'] ?? ''), true);
            return is_array($other) && is_numeric($other['play'] ?? null) ? floatval($other['play']) : $play;
        } catch (Throwable $e) {
            self::logError('beatPlayClock', $e);
            return null;
        }
    }

    /**
     * Get current accumulated play gamets from dynamics blob.
     */
    public static function getPlayGamets($dynamics) {
        return floatval($dynamics['_accumulated_play_gamets'] ?? 0);
    }

    // ========== GAME CLOCK (CHIM 3.4.1) ==========
    //
    // 3.4.1 no longer defines GAMETS / gamets / HERIKA_TIME. The live game clock is
    // $gameRequest[2] (raw gamets, 1 game day = 1e7, day starts at midnight — same
    // math as lib/utils_game_timestamp.php). Outside a game request (worker, pages)
    // fall back to the newest eventlog gamets via core DataLastKnownGameTS().

    /** Raw gamets per game day (core convert_gamets2days: gamets * 0.0000001). */
    const GAMETS_PER_DAY = 10000000;

    /**
     * Current raw game timestamp, or 0.0 when no game clock is available.
     */
    public static function currentGamets(): float
    {
        $gameRequest = $GLOBALS['gameRequest'] ?? null;
        if (is_array($gameRequest) && isset($gameRequest[2]) && floatval($gameRequest[2]) > 0) {
            return floatval($gameRequest[2]);
        }
        if (function_exists('DataLastKnownGameTS') && isset($GLOBALS['db'])) {
            return max(0.0, floatval(DataLastKnownGameTS()));
        }
        return 0.0;
    }

    /**
     * In-game hour of day (0 <= h < 24) for a gamets value (default: current clock).
     * Returns null when the game clock is unknown.
     */
    public static function gameHourOfDay(?float $gamets = null): ?float
    {
        $gamets = $gamets ?? self::currentGamets();
        if ($gamets <= 0) return null;
        return fmod($gamets / self::GAMETS_PER_DAY, 1.0) * 24.0;
    }

    // ===================== CLOCK MODEL (decisions 2026-09-23 §2) =====================
    // Never the IRL wall clock for anything that changes how an NPC feels.
    //
    // 1. GAME CALENDAR: raw gamets from the game ($gameRequest[2]); 1 game day =
    //    GAMETS_PER_DAY, 1 game hour = GAMETS_PER_DAY/24. Waiting and sleeping count,
    //    because they are time passing in the world. Used for everything that is about
    //    time APART or time in the WORLD:
    //      - contact stamp _last_contact_gamets (markContact) and the reunion spike
    //        (checkReunion: game hours apart, see its wait-scum guard below);
    //      - the calendar step (advanceCalendar via runCalendarScan, checkpoint in
    //        plugin_extended_data.reldyn.calendar): fester, global neglect, passion
    //        absence fade;
    //      - affinity absence decay (calculateDecayTicks, checkpoint _decay_last_game_gamets,
    //        consumed each prerequest; floored at the baseline, never below core 0);
    //      - walkaway boundary test (24-48 h) and hoover sleeper (72-96 h);
    //      - consumable expiry, plasticity override (30 game days), night/moon.
    //    Rule: negative states never go DOWN on this clock (time does not heal); they
    //    only go down through positive contact. Positive states fade on it.
    // 2. FILTERED PLAY CLOCK: _accumulated_play_gamets, per NPC, advanced by
    //    updatePlayTime() on that NPC's requests and capped at real seconds x
    //    GAMETS_PER_REAL_SECOND, so waits and sleeps add nothing. Unit: play gamets;
    //    GAMETS_PER_REAL_HOUR = one real hour of play. Used for what happens WHILE the
    //    player is playing: in-contact passion and jealousy decay, the diminishing-
    //    returns session multiplier, cooldowns (resentment -1 debounce, ick, divine
    //    intervention, ambient trickle), the hoover's 48 h after-glow context, and
    //    reunion's check that the time apart held real play (no reunion from a wait).
    //    A wait or sleep must never be able to trigger or clear these.
    // 3. ACCUMULATED REAL SECONDS: _accumulated_time, capped at 300 s per gap. Only for
    //    positive cooldowns that must not be farmable (diary reflection). Legacy users
    //    still on it: attachment drift (18000 s) and grief bond duration, and the
    //    resentment-decay debounce fallback before the play clock has a value.
    //
    // A checkpoint that is unset, or ahead of its clock (a value from another clock or
    // an older build, or an earlier save loaded), reads as null so callers can re-arm
    // it instead of trusting it.

    /** Record the current filtered play clock under $key. */
    public static function markPlayCheckpoint(array &$dynamics, string $key): void
    {
        $dynamics[$key] = self::getPlayGamets($dynamics);
    }

    /** Filtered play gamets elapsed since the checkpoint under $key, or null. */
    public static function playGametsSince(array $dynamics, string $key): ?float
    {
        $mark = floatval($dynamics[$key] ?? 0);
        if ($mark <= 0) return null;
        $elapsed = self::getPlayGamets($dynamics) - $mark;
        return $elapsed >= 0 ? $elapsed : null;
    }

    /** Record the current game calendar time (raw gamets) under $key, if known. */
    public static function markGameClock(array &$dynamics, string $key): void
    {
        $now = self::currentGamets();
        if ($now > 0) {
            $dynamics[$key] = $now;
        }
    }

    /** Real hours of filtered play elapsed since the play checkpoint under $key, or null. */
    public static function playHoursSince(array $dynamics, string $key): ?float
    {
        $elapsed = self::playGametsSince($dynamics, $key);
        return $elapsed === null ? null : $elapsed / self::GAMETS_PER_REAL_HOUR;
    }

    /** Re-arm a play checkpoint that is ahead of the play clock (another clock's value). */
    public static function rearmPlayCheckpoint(array &$dynamics, string $key): void
    {
        if (floatval($dynamics[$key] ?? 0) > 0 && self::playGametsSince($dynamics, $key) === null) {
            self::markPlayCheckpoint($dynamics, $key);
        }
    }

    /** Game-calendar hours elapsed since the gamets stored under $key, or null. */
    public static function gameHoursSince(array $dynamics, string $key): ?float
    {
        $mark = floatval($dynamics[$key] ?? 0);
        $now = self::currentGamets();
        if ($mark <= 0 || $now <= 0 || $now < $mark) return null;
        return ($now - $mark) / (self::GAMETS_PER_DAY / 24.0);
    }

    /**
     * Format accumulated seconds into human-readable string.
     *
     * @param int $seconds  Total accumulated seconds
     * @return string  e.g. "2h 15m", "0h 3m", "0h 0m"
     */
    public static function formatAccumulatedTime($seconds)
    {
        $seconds = max(0, intval($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return "{$hours}h {$minutes}m";
    }

    // =========================================================================
    // NPC PROFILE AUTO-GENERATION
    // Temperament (MDD 1.3), attachment style (MDD 6.1), maturity type (MDD 15.6)
    // and trait tags (design decisions 2026-09-23 §1), derived from CHIM core data.
    // =========================================================================

    /** Bump when the derivation changes so that stored NPCs are resolved again. */
    const PROFILE_AUTOGEN_VERSION = 1;

    /** The 13 temperaments of MDD 1.3, in table order. The order also breaks vote ties. */
    const TEMPERAMENT_TYPES = [
        'Romantic', 'Anxious', 'Bold', 'Playful', 'Humble', 'Nurturing', 'Gentle',
        'Jealous', 'Proud', 'Defiant', 'Guarded', 'Independent', 'Stoic',
    ];

    /** MDD 6.1. 'toxic' is only ever set by hand (pipeline doc, MDD 6.1). */
    const ATTACHMENT_STYLE_TYPES = ['secure', 'avoidant', 'anxious', 'toxic'];

    /** Profile fields a per-NPC override can set (stored in $dynamics['profile_overrides']). */
    const PROFILE_OVERRIDE_FIELDS = ['temperament', 'attachment_style', 'maturity_type', 'traits'];

    /** Dimensions whose x/baseline migrateDimensions() seeds from the temperament baseline. */
    const TEMPERAMENT_SEEDED_DIMENSIONS = ['maturity', 'trust', 'comfort', 'respect', 'coord_m', 'coord_f', 'self_confidence'];

    /**
     * Default mapping tables for the profile auto-generation. They live in the RelDyn config
     * (key 'temperament_autogen'), so every table can be retuned without code changes; a
     * stored config replaces whole tables, tables it leaves out keep these defaults.
     *
     * Temperament is a vote: each core signal adds points (unitless vote points, 'weights')
     * to one temperament; the most points win, ties go to the earlier row of MDD 1.3.
     * Class, faction and skills first resolve to an archetype (the MDD 1.3 class presets),
     * voice type, race and profile text vote for a temperament directly.
     * Matching is case-insensitive on letters and digits only, so the class FULL name
     * ("Spell Vendor") and editor id ("VendorSpells") both match.
     */
    public static function temperamentAutogenDefaults(): array
    {
        return [
            'weights' => [
                'class'   => 3,   // points for the class archetype's temperament
                'text'    => 2,   // points per distinct keyword found in the profile text
                'voice'   => 2,   // points for the voice type's temperament
                'faction' => 2,   // points per distinct faction archetype
                'skills'  => 1,   // points for the skill archetype's temperament
                'race'    => 1,   // points for the race's temperament
            ],
            'text_max_hits'  => 3,    // distinct keywords counted per temperament
            'skills_min_level' => 25, // Skyrim skill level (0-100) a skill needs to name an archetype
            'fallback_temperament' => 'Stoic', // only when no signal votes at all
            'text_fields' => ['personality', 'speechstyle', 'core', 'npc_static_bio'],

            // Skyrim.esm CLAS names (FULL and editor id). First match wins, so the more
            // specific entries come first ("Spell Vendor" is a mage, not a merchant).
            'class_archetypes' => [
                ['match' => ['spellvendor', 'vendorspells'], 'archetype' => 'Mage'],
                ['match' => ['barbarian', 'orcwarrior', 'guardorc'], 'archetype' => 'Barbarian'],
                ['match' => ['assassin', 'nightblade'], 'archetype' => 'Assassin'],
                ['match' => ['thief', 'rogue', 'pickpocket'], 'archetype' => 'Thief'],
                ['match' => ['ranger', 'scout', 'archer', 'hunter'], 'archetype' => 'Ranger'],
                ['match' => ['mage', 'sorcerer', 'conjurer', 'wizard', 'warlock', 'mystic', 'spellsword', 'witchblade', 'necro'], 'archetype' => 'Mage'],
                ['match' => ['priest', 'monk', 'healer', 'apothecary'], 'archetype' => 'Healer'],
                ['match' => ['bard'], 'archetype' => 'Bard'],
                ['match' => ['guard', 'soldier', 'jailor', 'blade', 'penitus', 'vigilant', 'housecarl'], 'archetype' => 'Guard'],
                ['match' => ['noble', 'jarl', 'steward'], 'archetype' => 'Noble'],
                ['match' => ['vendor', 'merchant', 'blacksmith', 'pawnbroker', 'tailor', 'fletcher', 'innkeeper', 'trader'], 'archetype' => 'Merchant'],
                ['match' => ['warrior'], 'archetype' => 'Warrior'],
            ],
            // Faction names as the plugin sends them (editor ids such as "JobJarlFaction").
            'faction_archetypes' => [
                ['match' => ['jarl', 'steward', 'thane', 'noble'], 'archetype' => 'Noble'],
                ['match' => ['courtwizard', 'collegeofwinterhold', 'winterholdcollege'], 'archetype' => 'Mage'],
                ['match' => ['companions'], 'archetype' => 'Warrior'],
                ['match' => ['thievesguild'], 'archetype' => 'Thief'],
                ['match' => ['darkbrotherhood'], 'archetype' => 'Assassin'],
                ['match' => ['bardscollege', 'bardsinger', 'jobbard'], 'archetype' => 'Bard'],
                ['match' => ['housecarl', 'guard', 'penitus', 'vigilant', 'dawnguard'], 'archetype' => 'Guard'],
                ['match' => ['priest', 'healer', 'temple'], 'archetype' => 'Healer'],
                ['match' => ['merchant', 'innkeeper', 'vendor', 'blacksmith', 'apothecary'], 'archetype' => 'Merchant'],
            ],
            // metadata.skills names; the archetype whose best skill is highest (and at least
            // skills_min_level) wins, a tie names none.
            'skill_archetypes' => [
                'Warrior' => ['onehanded', 'twohanded', 'block', 'heavyarmor'],
                'Ranger'  => ['archery'],
                'Mage'    => ['destruction', 'conjuration', 'alteration', 'illusion', 'enchanting'],
                'Thief'   => ['sneak', 'lockpicking', 'pickpocket', 'lightarmor'],
                'Healer'  => ['restoration', 'alchemy'],
                'Merchant'=> ['speech'],
            ],
            // MDD 1.3 class defaults (Warrior/Barbarian Bold, Ranger Independent, Mage Guarded,
            // Thief Playful, Noble Proud, Merchant Humble, Healer Nurturing) plus RelDyn's
            // own archetypes for vanilla classes the MDD does not name.
            'archetype_temperament' => [
                'Warrior' => 'Bold', 'Barbarian' => 'Bold', 'Ranger' => 'Independent',
                'Mage' => 'Guarded', 'Thief' => 'Playful', 'Noble' => 'Proud',
                'Merchant' => 'Humble', 'Healer' => 'Nurturing',
                'Guard' => 'Stoic',       // "duty-first" (MDD 1.3 Stoic)
                'Bard' => 'Playful',
                'Assassin' => 'Stoic',
            ],
            // core_npc_master.voiceid (e.g. "sk_malecommander"); first match wins.
            'voice_temperament' => [
                ['match' => ['darkelfcynical'], 'temperament' => 'Guarded'],
                ['match' => ['condescending', 'haughty', 'arrogant'], 'temperament' => 'Proud'],
                ['match' => ['commander', 'brute'], 'temperament' => 'Bold'],
                ['match' => ['coward', 'shrill'], 'temperament' => 'Anxious'],
                ['match' => ['youngeager', 'slycynical', 'drunk'], 'temperament' => 'Playful'],
                ['match' => ['sultry'], 'temperament' => 'Romantic'],
                ['match' => ['soldier', 'guard'], 'temperament' => 'Stoic'],
                ['match' => ['oldkindly'], 'temperament' => 'Nurturing'],
                ['match' => ['warlock'], 'temperament' => 'Guarded'],
                ['match' => ['bandit'], 'temperament' => 'Defiant'],
            ],
            // core_npc_master.race (e.g. "Nord", "NordRace", "DarkElfRaceVampire"); first match wins.
            'race_temperament' => [
                ['match' => ['darkelf', 'dunmer'], 'temperament' => 'Guarded'],
                ['match' => ['highelf', 'altmer'], 'temperament' => 'Proud'],
                ['match' => ['woodelf', 'bosmer'], 'temperament' => 'Playful'],
                ['match' => ['nord'], 'temperament' => 'Bold'],
                ['match' => ['orc', 'orsimer'], 'temperament' => 'Proud'],
                ['match' => ['redguard'], 'temperament' => 'Independent'],
                ['match' => ['imperial'], 'temperament' => 'Humble'],
                ['match' => ['breton'], 'temperament' => 'Guarded'],
                ['match' => ['khajiit'], 'temperament' => 'Playful'],
                ['match' => ['argonian'], 'temperament' => 'Stoic'],
            ],
            // Word stems searched in the profile text fields (word start, any ending). Stems that
            // hit common lore phrases are left out ("in vain", "the rebellion").
            'text_keywords' => [
                'Romantic'    => ['romantic', 'passionate', 'affectionate', 'amorous', 'lovesick'],
                'Anxious'     => ['anxious', 'nervous', 'insecure', 'timid', 'shy', 'fearful', 'worrie', 'clingy', 'skittish'],
                'Bold'        => ['bold', 'confident', 'brash', 'fearless', 'daring', 'courageous', 'brave', 'headstrong'],
                'Playful'     => ['playful', 'flirt', 'mischiev', 'cheeky', 'lighthearted', 'carefree', 'teasing', 'charming'],
                'Humble'      => ['humble', 'modest', 'unassuming', 'hardworking', 'hard-working'],
                'Nurturing'   => ['nurturing', 'caring', 'motherly', 'fatherly', 'compassionate', 'maternal'],
                'Gentle'      => ['gentle', 'soft-spoken', 'calm', 'peaceful', 'serene', 'empathetic'],
                'Jealous'     => ['jealous', 'possessive', 'envious', 'controlling'],
                'Proud'       => ['proud', 'arrogant', 'haughty', 'vanity', 'pompous', 'condescending', 'smug', 'conceited', 'snob'],
                'Defiant'     => ['defiant', 'rebellious', 'stubborn', 'hot-tempered', 'hotheaded'],
                'Guarded'     => ['guarded', 'wary', 'distrustful', 'suspicious', 'cautious', 'secretive', 'reserved'],
                'Independent' => ['independent', 'self-reliant', 'self-sufficient', 'loner', 'solitary', 'aloof'],
                'Stoic'       => ['stoic', 'dutiful', 'disciplined', 'taciturn', 'stern', 'quiet', 'unflappable'],
            ],

            // Attachment (MDD 6.1) = temperament default, shifted by the warmth curve.
            'temperament_attachment' => self::TEMPERAMENT_ATTACHMENT_DEFAULTS,
            'attachment_curve_shift' => [
                self::CURVE_QUICK   => ['avoidant' => 'secure'],   // warms fast: not avoidant
                self::CURVE_GUARDED => ['secure' => 'avoidant'],   // hardest to crack
            ],
            // Maturity type (MDD 15.6) = class archetype first, then temperament.
            'archetype_maturity_type' => [
                'Bard' => 'Volatile',     // MDD 15.6: "dramatic bard -> big swings both ways"
            ],
            'temperament_maturity_type' => self::TEMPERAMENT_MATURITY_PLASTICITY,
            // Trait tags (decisions §1): the vocabulary, and what temperament/class imply.
            'trait_vocabulary' => ['egocentric', 'insecure'],
            'temperament_traits' => [
                'Proud' => ['egocentric'],   // decisions §1: "Egocentric (default for Proud)"
                'Anxious' => ['insecure'],
                'Jealous' => ['insecure'],   // MDD 1.3: "Possessive, insecure, controlling"
            ],
            'archetype_traits' => [
                'Noble' => ['egocentric'],
            ],

            // Named NPCs from the MDD are presets for those NPCs, not rules for their class.
            // Keyed by lower-case npc_name. A per-NPC override in RelDyn state beats these.
            'npc_overrides' => [
                'ashe'   => ['temperament' => 'Stoic', 'maturity_type' => 'Resilient'],  // MDD 15.6
                'mikael' => ['maturity_type' => 'Volatile'],                            // MDD 15.6
                'serana' => ['maturity_type' => 'Growth'],                              // MDD 15.6
                'nazeem' => ['maturity_type' => 'Rigid'],                               // MDD 15.6
                'ysolda' => ['temperament' => 'Anxious'],                               // MDD 8.2 C
            ],
        ];
    }

    /** The auto-generation tables: stored config per table, defaults for the rest. */
    public static function getTemperamentAutogenConfig(): array
    {
        $defaults = self::temperamentAutogenDefaults();
        $stored = self::getConfig()['temperament_autogen'] ?? null;
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** Lower-case letters and digits only, so "Spell Vendor", "VendorSpells" and "sk_Vendor" compare alike. */
    private static function profileMatchKey($value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    /** First entry of a [['match' => [...], $field => value], ...] table whose keyword occurs in $haystack. */
    private static function firstProfileMatch(array $table, string $haystack, string $field): ?string
    {
        if ($haystack === '') return null;
        foreach ($table as $entry) {
            foreach ((array) ($entry['match'] ?? []) as $needle) {
                $needle = self::profileMatchKey($needle);
                if ($needle !== '' && strpos($haystack, $needle) !== false) {
                    return $entry[$field] ?? null;
                }
            }
        }
        return null;
    }

    /** A jsonb column as PostgreSQL returns it (JSON text) or already decoded. */
    public static function decodeProfileJson($value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public static function validTemperament($t): ?string
    {
        if (!is_string($t)) return null;
        foreach (self::TEMPERAMENT_TYPES as $name) {
            if (strcasecmp($name, trim($t)) === 0) return $name;
        }
        return null;
    }

    private static function validAttachmentStyle($s): ?string
    {
        $s = is_string($s) ? strtolower(trim($s)) : null;
        return in_array($s, self::ATTACHMENT_STYLE_TYPES, true) ? $s : null;
    }

    private static function validMaturityType($m): ?string
    {
        if (!is_string($m)) return null;
        foreach (array_keys(self::MATURITY_PLASTICITY_VALUES) as $name) {
            if (strcasecmp($name, trim($m)) === 0) return $name;
        }
        return null;
    }

    /** Known trait tags only, lower-case, de-duplicated, in vocabulary order; null if any is unknown. */
    private static function normalizeTraits($traits, array $cfg): ?array
    {
        if (!is_array($traits)) return null;
        $vocab = array_map('strtolower', (array) ($cfg['trait_vocabulary'] ?? []));
        $wanted = [];
        foreach ($traits as $t) {
            $t = strtolower(trim((string) $t));
            if (!in_array($t, $vocab, true)) return null;
            $wanted[$t] = true;
        }
        return array_values(array_filter($vocab, fn($v) => isset($wanted[$v])));
    }

    /**
     * Archetype (MDD 1.3 class preset) from class, then faction, then skills.
     * Returns [classArchetype, factionArchetypes[], skillArchetype].
     */
    public static function profileArchetypes(array $ext, array $meta, array $cfg): array
    {
        $class = $ext['class'] ?? null;
        $classKey = self::profileMatchKey(is_array($class) ? ($class['name'] ?? '') : $class);
        $classArch = self::firstProfileMatch((array) ($cfg['class_archetypes'] ?? []), $classKey, 'archetype');

        $factionArchs = [];
        foreach ((array) ($ext['factions'] ?? []) as $faction) {
            if (!is_array($faction) || intval($faction['rank'] ?? 0) < 0) continue;   // rank -1: not a member
            $arch = self::firstProfileMatch((array) ($cfg['faction_archetypes'] ?? []),
                self::profileMatchKey($faction['name'] ?? ''), 'archetype');
            if ($arch !== null && !in_array($arch, $factionArchs, true)) $factionArchs[] = $arch;
        }

        // Skill levels are Skyrim skill levels (0-100) sent as strings.
        $skills = array_change_key_case((array) ($meta['skills'] ?? []), CASE_LOWER);
        $best = [];
        foreach ((array) ($cfg['skill_archetypes'] ?? []) as $arch => $names) {
            $level = 0.0;
            foreach ((array) $names as $n) $level = max($level, floatval($skills[strtolower($n)] ?? 0));
            $best[$arch] = $level;
        }
        $skillArch = null;
        if ($best) {
            arsort($best);
            $levels = array_values($best);
            $top = $levels[0];
            if ($top >= floatval($cfg['skills_min_level'] ?? 25) && (count($levels) < 2 || $levels[1] < $top)) {
                $skillArch = array_key_first($best);
            }
        }
        return [$classArch, $factionArchs, $skillArch];
    }

    /**
     * Derive an NPC's profile from its core_npc_master row. Pure: same row, options and
     * config give the same result. $row is the row as PostgreSQL returns it (jsonb as text).
     *
     * $options: 'config' (auto-generation tables, default getTemperamentAutogenConfig()),
     *           'overrides' (per-NPC state overrides, PROFILE_OVERRIDE_FIELDS),
     *           'sharmat_style' (Sharmat sex_speech_style when Sharmat is installed),
     *           'warmth_curve' (the NPC's curve, else the temperament default).
     *
     * Priority per field: per-NPC override > named preset (config npc_overrides) > derived.
     * Temperament is derived from MARAS, then Sharmat (pipeline §5.1), then the core-data vote.
     */
    public static function deriveNpcProfile(string $npcName, array $row, array $options = []): array
    {
        $cfg = $options['config'] ?? self::getTemperamentAutogenConfig();
        $overrides = (array) ($options['overrides'] ?? []);
        $preset = (array) (((array) ($cfg['npc_overrides'] ?? []))[strtolower(trim($npcName))] ?? []);
        $ext = self::decodeProfileJson($row['extended_data'] ?? null);
        $meta = self::decodeProfileJson($row['metadata'] ?? null);
        $weights = (array) ($cfg['weights'] ?? []);

        [$classArch, $factionArchs, $skillArch] = self::profileArchetypes($ext, $meta, $cfg);
        $archetype = $classArch ?? ($factionArchs[0] ?? $skillArch);
        $archTemp = (array) ($cfg['archetype_temperament'] ?? []);

        // --- core-data vote (points are unitless) ---
        $votes = [];
        $signals = [];
        $vote = function (?string $temperament, $points, string $signal) use (&$votes, &$signals) {
            $temperament = self::validTemperament($temperament);
            $points = floatval($points);
            if ($temperament === null || $points <= 0) return;
            $votes[$temperament] = ($votes[$temperament] ?? 0) + $points;
            $signals[] = "{$signal}->{$temperament}+{$points}";
        };
        if ($classArch !== null) $vote($archTemp[$classArch] ?? null, $weights['class'] ?? 0, "class:{$classArch}");
        foreach ($factionArchs as $fa) $vote($archTemp[$fa] ?? null, $weights['faction'] ?? 0, "faction:{$fa}");
        if ($skillArch !== null) $vote($archTemp[$skillArch] ?? null, $weights['skills'] ?? 0, "skills:{$skillArch}");
        $vote(self::firstProfileMatch((array) ($cfg['voice_temperament'] ?? []), self::profileMatchKey($row['voiceid'] ?? ''), 'temperament'),
            $weights['voice'] ?? 0, 'voice');
        $vote(self::firstProfileMatch((array) ($cfg['race_temperament'] ?? []), self::profileMatchKey($row['race'] ?? ''), 'temperament'),
            $weights['race'] ?? 0, 'race');

        $text = '';
        foreach ((array) ($cfg['text_fields'] ?? []) as $field) {
            if (!empty($row[$field]) && is_string($row[$field])) $text .= ' ' . $row[$field];
        }
        if ($text !== '') {
            $maxHits = max(0, intval($cfg['text_max_hits'] ?? 3));
            foreach ((array) ($cfg['text_keywords'] ?? []) as $temperament => $stems) {
                $hits = 0;
                foreach ((array) $stems as $stem) {
                    if ($hits >= $maxHits) break;
                    if (preg_match('/\b' . preg_quote((string) $stem, '/') . '/iu', $text)) $hits++;
                }
                if ($hits > 0) $vote($temperament, $hits * floatval($weights['text'] ?? 0), "text:{$hits}");
            }
        }

        $voted = null;
        $bestPoints = 0;
        foreach (self::TEMPERAMENT_TYPES as $t) {          // MDD 1.3 order breaks ties
            if (($votes[$t] ?? 0) > $bestPoints) { $bestPoints = $votes[$t]; $voted = $t; }
        }

        // --- temperament ---
        $sources = [];
        $marasTemp = self::validTemperament(self::getPlayerRelationshipFromExtended($ext)['maras']['temperament'] ?? null);
        $sharmatTemp = !empty($options['sharmat_style'])
            ? self::validTemperament(self::speechStyleToTemperament($options['sharmat_style'])) : null;
        if ($marasTemp !== null) {
            $autoTemp = $marasTemp; $autoSource = 'maras';
        } elseif ($sharmatTemp !== null) {
            $autoTemp = $sharmatTemp; $autoSource = 'sharmat';
        } elseif ($voted !== null) {
            $autoTemp = $voted; $autoSource = 'core';
        } else {
            $autoTemp = self::validTemperament($cfg['fallback_temperament'] ?? null) ?? 'Stoic'; $autoSource = 'fallback';
        }
        $preset = [
            'temperament'      => self::validTemperament($preset['temperament'] ?? null),
            'attachment_style' => self::validAttachmentStyle($preset['attachment_style'] ?? null),
            'maturity_type'    => self::validMaturityType($preset['maturity_type'] ?? null),
            'traits'           => isset($preset['traits']) ? self::normalizeTraits($preset['traits'], $cfg) : null,
        ];
        $clean = [
            'temperament'      => self::validTemperament($overrides['temperament'] ?? null),
            'attachment_style' => self::validAttachmentStyle($overrides['attachment_style'] ?? null),
            'maturity_type'    => self::validMaturityType($overrides['maturity_type'] ?? null),
            'traits'           => isset($overrides['traits']) ? self::normalizeTraits($overrides['traits'], $cfg) : null,
        ];

        [$temperament, $sources['temperament']] = self::pickProfileValue($clean['temperament'], $preset['temperament'], $autoTemp, $autoSource);
        // What each dependent is without a per-NPC override: preset, else derived from the temperament.
        $auto = self::profileDefaultDependents($temperament, $archetype, $options['warmth_curve'] ?? null, $cfg, $preset);
        $profile = ['temperament' => $temperament];
        foreach (['attachment_style', 'maturity_type', 'traits'] as $dep) {
            [$profile[$dep], $sources[$dep]] = self::pickProfileValue($clean[$dep], $preset[$dep], $auto[$dep], 'derived');
        }

        return $profile + [
            'archetype'        => $archetype,
            'sources'          => $sources,
            'base_temperament' => $preset['temperament'] ?? $autoTemp,   // temperament without a per-NPC override
            'preset'           => array_filter($preset, fn($v) => $v !== null),
            'auto'             => $auto,
            'votes'            => $votes,
            'signals'          => $signals,
        ];
    }

    /** [value, source]: override, else preset, else the automatic value. */
    private static function pickProfileValue($override, $preset, $auto, string $autoSource): array
    {
        if ($override !== null) return [$override, 'override'];
        if ($preset !== null) return [$preset, 'preset'];
        return [$auto, $autoSource];
    }

    /** Dependents without a per-NPC override: the named preset's value, else derived. */
    private static function profileDefaultDependents(string $temperament, ?string $archetype, ?string $warmthCurve, array $cfg, array $preset): array
    {
        $auto = self::deriveProfileDependents($temperament, $archetype, $warmthCurve, $cfg);
        foreach (['attachment_style', 'maturity_type', 'traits'] as $dep) {
            if (isset($preset[$dep])) $auto[$dep] = $preset[$dep];
        }
        return $auto;
    }

    /** Attachment style, maturity type and traits implied by a temperament and archetype. */
    private static function deriveProfileDependents(string $temperament, ?string $archetype, ?string $warmthCurve, array $cfg): array
    {
        $curve = $warmthCurve ?: self::temperamentToWarmthCurve($temperament);
        $attachment = self::validAttachmentStyle(((array) ($cfg['temperament_attachment'] ?? []))[$temperament] ?? null) ?? 'secure';
        $shift = ((array) ($cfg['attachment_curve_shift'] ?? []))[$curve][$attachment] ?? null;
        $attachment = self::validAttachmentStyle($shift) ?? $attachment;
        if ($attachment === 'toxic') {
            $attachment = 'secure';   // Toxic is never auto-assigned (MDD 6.1 pipeline notes)
        }

        $maturityType = ($archetype !== null ? self::validMaturityType(((array) ($cfg['archetype_maturity_type'] ?? []))[$archetype] ?? null) : null)
            ?? self::validMaturityType(((array) ($cfg['temperament_maturity_type'] ?? []))[$temperament] ?? null)
            ?? 'Adaptive';

        $traits = array_merge(
            (array) (((array) ($cfg['temperament_traits'] ?? []))[$temperament] ?? []),
            $archetype !== null ? (array) (((array) ($cfg['archetype_traits'] ?? []))[$archetype] ?? []) : []
        );
        $traits = self::normalizeTraits($traits, $cfg) ?? [];

        return ['attachment_style' => $attachment, 'maturity_type' => $maturityType, 'traits' => $traits];
    }

    /** The core_npc_master columns the derivation reads; [] when the NPC has no row. Throws on DB errors. */
    public static function fetchCoreProfileRow(string $npcName): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [];
        $row = $db->fetchOne(
            'SELECT npc_name, gender, race, voiceid, personality, speechstyle, core, npc_static_bio, metadata, extended_data'
            . ' FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1',
            [$npcName]
        );
        return is_array($row) ? $row : [];
    }

    /**
     * Resolve temperament, attachment style, maturity type and traits once per NPC and store
     * them in RelDyn state (inferred_temperament, attachment_style,
     * dimensions.maturity.plasticity_type, traits; provenance in _profile_autogen).
     * Values an NPC already carries (set by the editor, Sharmat, or an arc) are kept.
     * Returns true when it changed $dynamics. A failed core read is logged and retried
     * on the next call rather than stored as a fallback.
     */
    public static function ensureTemperamentProfile($npcName, &$dynamics): bool
    {
        if (intval($dynamics['_profile_autogen']['version'] ?? 0) >= self::PROFILE_AUTOGEN_VERSION) {
            return false;
        }
        $npcName = (string) $npcName;
        try {
            $row = self::fetchCoreProfileRow($npcName);
        } catch (Throwable $e) {
            error_log("[RelDyn] temperament auto-generation: core_npc_master read failed for {$npcName}: " . $e->getMessage());
            return false;
        }

        $prevTemp = self::validTemperament($dynamics['inferred_temperament'] ?? null);
        $prevAttachment = self::validAttachmentStyle($dynamics['attachment_style'] ?? null);
        $prevMaturityType = self::validMaturityType($dynamics['dimensions']['maturity']['plasticity_type'] ?? null);
        $prevTraits = is_array($dynamics['traits'] ?? null) ? $dynamics['traits'] : null;
        // After a PROFILE_AUTOGEN_VERSION bump, values that were automatic last time are derived again.
        $prevGen = (array) ($dynamics['_profile_autogen'] ?? []);
        $wasAuto = fn(string $key, $value) => $value !== null
            && ($key === 'temperament' ? ($prevGen['base_temperament'] ?? null) : ($prevGen['auto'][$key] ?? null)) === $value;

        // Values already on the NPC (editor, Sharmat, an arc) count as overrides for this
        // resolution without being written to profile_overrides. A plasticity type seeded from
        // the null-temperament fallback ('Stoic') was never a choice, so it is derived again.
        $overrides = (array) ($dynamics['profile_overrides'] ?? []);
        $kept = [];
        if ($prevTemp !== null && !isset($overrides['temperament']) && !$wasAuto('temperament', $prevTemp)) {
            $overrides['temperament'] = $prevTemp; $kept['temperament'] = true;
        }
        if ($prevAttachment !== null && !isset($overrides['attachment_style']) && !$wasAuto('attachment_style', $prevAttachment)) {
            $overrides['attachment_style'] = $prevAttachment; $kept['attachment_style'] = true;
        }
        if ($prevTemp !== null && $prevMaturityType !== null && !isset($overrides['maturity_type']) && !$wasAuto('maturity_type', $prevMaturityType)) {
            $overrides['maturity_type'] = $prevMaturityType; $kept['maturity_type'] = true;
        }
        if ($prevTraits !== null && !isset($overrides['traits']) && !$wasAuto('traits', $prevTraits)) {
            $overrides['traits'] = $prevTraits; $kept['traits'] = true;
        }

        $sharmatStyle = self::getSharmatSpeechStyle($npcName);
        $profile = self::deriveNpcProfile($npcName, $row, [
            'overrides' => $overrides,
            'sharmat_style' => is_string($sharmatStyle) ? $sharmatStyle : null,
            'warmth_curve' => $dynamics['warmth_curve'] ?? null,
        ]);
        foreach ($kept as $field => $_) {
            if ($profile['sources'][$field] === 'override') $profile['sources'][$field] = 'stored';
        }

        $dynamics['inferred_temperament'] = $profile['temperament'];
        $dynamics['attachment_style'] = $profile['attachment_style'];
        $dynamics['dimensions']['maturity']['plasticity_type'] = $profile['maturity_type'];
        $dynamics['traits'] = $profile['traits'];

        // Seed the temperament-based dimensions here, as migrateDimensions() would: a first
        // save merges this copy onto a normalized empty state, which is seeded from the
        // null-temperament fallback ('Stoic'). Dimensions already seeded from that fallback
        // and untouched since (x and baseline still equal the seed) are seeded again.
        foreach (self::TEMPERAMENT_SEEDED_DIMENSIONS as $dim) {
            if (!is_array($dynamics['dimensions'][$dim] ?? null)) continue;
            $x = $dynamics['dimensions'][$dim]['x'] ?? null;
            $base = $dynamics['dimensions'][$dim]['baseline'] ?? null;
            $new = self::getTemperamentBaseline($profile['temperament'], $dim);
            $stoicSeed = self::getTemperamentBaseline('Stoic', $dim);
            $untouchedFallbackSeed = $prevTemp === null && $x !== null && $base !== null
                && abs(floatval($x) - $stoicSeed) < 1e-9 && abs(floatval($base) - $stoicSeed) < 1e-9;
            if ($x === null || $untouchedFallbackSeed) {
                $dynamics['dimensions'][$dim]['x'] = $new;
                $dynamics['dimensions'][$dim]['baseline'] = $new;
            }
        }

        $dynamics['_profile_autogen'] = [
            'version'          => self::PROFILE_AUTOGEN_VERSION,
            'archetype'        => $profile['archetype'],
            'base_temperament' => $profile['base_temperament'],
            'preset'           => $profile['preset'],
            'temperament_source'      => $profile['sources']['temperament'],
            'attachment_style_source' => $profile['sources']['attachment_style'],
            'maturity_type_source'    => $profile['sources']['maturity_type'],
            'traits_source'           => $profile['sources']['traits'],
            'auto'             => $profile['auto'],
            'signals'          => $profile['signals'],
        ];
        self::log("Profile auto-gen for {$npcName}: temperament={$profile['temperament']} ({$profile['sources']['temperament']}), "
            . "attachment={$profile['attachment_style']}, maturity_type={$profile['maturity_type']}, traits=" . implode(',', $profile['traits'])
            . ' [' . implode(' ', $profile['signals']) . ']');
        return true;
    }

    /** Effective trait tags (lower-case). */
    public static function getTraits(array $dynamics): array
    {
        $traits = $dynamics['profile_overrides']['traits'] ?? $dynamics['traits'] ?? [];
        return is_array($traits) ? array_values(array_map('strtolower', array_map('strval', $traits))) : [];
    }

    public static function hasTrait(array $dynamics, string $trait): bool
    {
        return in_array(strtolower(trim($trait)), self::getTraits($dynamics), true);
    }

    /**
     * Set (or with null, clear) a per-NPC override for temperament, attachment_style,
     * maturity_type or traits, and apply it. Changing the temperament re-derives the
     * dependents that still hold their automatic value (an arc-shifted attachment stays).
     * Returns false and changes nothing for an unknown field or value.
     */
    public static function setProfileOverride(array &$dynamics, string $field, $value): bool
    {
        if (!in_array($field, self::PROFILE_OVERRIDE_FIELDS, true)) return false;
        $cfg = self::getTemperamentAutogenConfig();
        if ($value !== null) {
            $value = match ($field) {
                'temperament'      => self::validTemperament($value),
                'attachment_style' => self::validAttachmentStyle($value),
                'maturity_type'    => self::validMaturityType($value),
                'traits'           => self::normalizeTraits($value, $cfg),
            };
            if ($value === null) return false;
        }

        $overrides = (array) ($dynamics['profile_overrides'] ?? []);
        if ($value === null) unset($overrides[$field]); else $overrides[$field] = $value;
        $dynamics['profile_overrides'] = $overrides;

        $autogen = (array) ($dynamics['_profile_autogen'] ?? []);
        $prevAuto = (array) ($autogen['auto'] ?? []);
        $temperament = $overrides['temperament'] ?? self::validTemperament($autogen['base_temperament'] ?? null)
            ?? self::validTemperament($dynamics['inferred_temperament'] ?? null) ?? 'Stoic';
        $auto = self::profileDefaultDependents($temperament, $autogen['archetype'] ?? null, $dynamics['warmth_curve'] ?? null,
            $cfg, (array) ($autogen['preset'] ?? []));

        $current = [
            'attachment_style' => $dynamics['attachment_style'] ?? null,
            'maturity_type'    => $dynamics['dimensions']['maturity']['plasticity_type'] ?? null,
            'traits'           => $dynamics['traits'] ?? null,
        ];
        $effective = ['temperament' => $temperament];
        foreach (['attachment_style', 'maturity_type', 'traits'] as $dep) {
            if (array_key_exists($dep, $overrides)) {
                $effective[$dep] = $overrides[$dep];
            } elseif ($current[$dep] === null || $current[$dep] === ($prevAuto[$dep] ?? null) || $dep === $field) {
                $effective[$dep] = $auto[$dep];    // still automatic (or its override was just cleared)
            } else {
                $effective[$dep] = $current[$dep]; // changed since by something else: keep
            }
        }

        $dynamics['inferred_temperament'] = $effective['temperament'];
        $dynamics['attachment_style'] = $effective['attachment_style'];
        $dynamics['dimensions']['maturity']['plasticity_type'] = $effective['maturity_type'];
        $dynamics['traits'] = $effective['traits'];
        $autogen['auto'] = $auto;
        $dynamics['_profile_autogen'] = $autogen;
        return true;
    }

    // =========================================================================
    // LOVE LANGUAGE AUTO-GENERATION
    // =========================================================================

    /**
     * Ensure NPC has love languages assigned. Auto-generates if missing.
     * Temperament comes from ensureTemperamentProfile() (override > MARAS > Sharmat >
     * core data); primary LL: MARAS temperament > Sharmat profile > CHIM race.
     */
    public static function ensureLoveLanguage($npcName, &$dynamics)
    {
        self::ensureTemperamentProfile($npcName, $dynamics);

        if (!empty($dynamics['love_language_primary'])) {
            return; // Already set
        }

        $primary = null;
        $secondary = null;
        $temperament = self::validTemperament($dynamics['inferred_temperament'] ?? null);
        $warmthCurve = $temperament !== null ? self::temperamentToWarmthCurve($temperament) : null;

        // Priority 1: MARAS temperament
        $marasTemp = self::getMarasTemperament($npcName);
        if ($marasTemp) {
            $primary = self::temperamentToLoveLanguage($marasTemp);
        }

        // Priority 2: Sharmat profile inference
        if (!$primary) {
            $speechStyle = self::getSharmatSpeechStyle($npcName);
            if ($speechStyle) {
                $primary = self::speechStyleToLoveLanguage($speechStyle);
            }
        }

        // Priority 3: CHIM race fallback
        if (!$primary) {
            $race = self::getNpcRace($npcName);
            $primary = self::raceToLoveLanguage($race);
        }

        // Secondary from social context
        $socialClass = self::getSocialClass($npcName);
        $secondary = self::socialClassToLoveLanguage($socialClass);

        // If secondary == primary, rotate
        if ($secondary === $primary) {
            $secondary = self::rotateLoveLanguage($primary);
        }

        $dynamics['love_language_primary'] = $primary ?: self::LL_TIME;
        $dynamics['love_language_secondary'] = $secondary ?: self::LL_WORDS;
        $dynamics['warmth_curve'] = $warmthCurve ?: self::CURVE_MODERATE;

        self::log("Auto-gen LL for {$npcName}: primary={$dynamics['love_language_primary']}, secondary={$dynamics['love_language_secondary']}, curve={$dynamics['warmth_curve']}, temp={$temperament}");
    }

    // ---- Love language mapping helpers ----

    private static function temperamentToLoveLanguage($temperament)
    {
        $map = [
            'Romantic'    => self::LL_WORDS,
            'Jealous'     => self::LL_TIME,
            'Proud'       => self::LL_SERVICE,
            'Humble'      => self::LL_GIFTS,
            'Independent' => self::LL_TIME,
        ];
        return $map[$temperament] ?? self::LL_TIME;
    }

    private static function speechStyleToLoveLanguage($style)
    {
        $style = strtolower(trim($style));
        $map = [
            'passionate' => self::LL_WORDS, 'romantic' => self::LL_WORDS, 'seductive' => self::LL_WORDS,
            'submissive' => self::LL_TOUCH, 'shy' => self::LL_TOUCH, 'gentle' => self::LL_TOUCH,
            'dominant' => self::LL_SERVICE, 'aggressive' => self::LL_SERVICE, 'bratty' => self::LL_SERVICE,
            'playful' => self::LL_TIME, 'teasing' => self::LL_TIME, 'flirty' => self::LL_TIME,
            'reserved' => self::LL_GIFTS, 'cold' => self::LL_GIFTS, 'formal' => self::LL_GIFTS,
        ];
        return $map[$style] ?? null;
    }

    private static function speechStyleToTemperament($style)
    {
        $style = strtolower(trim($style));
        $map = [
            'passionate' => 'Romantic', 'romantic' => 'Romantic', 'seductive' => 'Romantic',
            'shy' => 'Anxious', 'submissive' => 'Gentle', 'gentle' => 'Gentle',
            'dominant' => 'Bold', 'aggressive' => 'Defiant', 'bratty' => 'Defiant',
            'playful' => 'Playful', 'teasing' => 'Playful', 'flirty' => 'Playful',
            'reserved' => 'Guarded', 'cold' => 'Stoic', 'formal' => 'Proud',
            'nurturing' => 'Nurturing', 'motherly' => 'Nurturing', 'caring' => 'Nurturing',
            'measured' => 'Guarded', 'cautious' => 'Guarded', 'stoic' => 'Stoic',
            'bold' => 'Bold', 'confident' => 'Bold', 'commanding' => 'Bold',
            'anxious' => 'Anxious', 'nervous' => 'Anxious', 'clingy' => 'Anxious',
            'jealous' => 'Jealous', 'possessive' => 'Jealous',
            'humble' => 'Humble', 'modest' => 'Humble',
            'independent' => 'Independent', 'aloof' => 'Independent',
            'defiant' => 'Defiant', 'rebellious' => 'Defiant',
        ];
        return $map[$style] ?? null;
    }

    private static function raceToLoveLanguage($race)
    {
        $race = strtolower(trim($race ?? ''));
        $map = [
            'khajiit' => self::LL_TOUCH, 'woodelf' => self::LL_TOUCH, 'bosmer' => self::LL_TOUCH,
            'highelf' => self::LL_GIFTS, 'altmer' => self::LL_GIFTS, 'imperial' => self::LL_GIFTS,
            'nord' => self::LL_SERVICE, 'orc' => self::LL_SERVICE, 'orsimer' => self::LL_SERVICE,
            'breton' => self::LL_WORDS, 'darkelf' => self::LL_WORDS, 'dunmer' => self::LL_WORDS,
            'redguard' => self::LL_SERVICE, 'argonian' => self::LL_TIME,
        ];
        return $map[$race] ?? self::LL_TIME;
    }

    private static function socialClassToLoveLanguage($socialClass)
    {
        $class = strtolower(trim($socialClass ?? ''));
        $map = [
            'nobles' => self::LL_GIFTS, 'rulers' => self::LL_GIFTS,
            'wealthy' => self::LL_TIME, 'middle' => self::LL_TIME,
            'working' => self::LL_SERVICE, 'poverty' => self::LL_SERVICE,
            'religious' => self::LL_WORDS,
            'outcast' => self::LL_TOUCH,
        ];
        return $map[$class] ?? self::LL_WORDS;
    }

    private static function rotateLoveLanguage($ll)
    {
        $rotation = [
            self::LL_WORDS   => self::LL_TIME,
            self::LL_TIME    => self::LL_TOUCH,
            self::LL_TOUCH   => self::LL_WORDS,
            self::LL_SERVICE => self::LL_WORDS,
            self::LL_GIFTS   => self::LL_TIME,
        ];
        return $rotation[$ll] ?? self::LL_WORDS;
    }

    private static function temperamentToWarmthCurve($temperament)
    {
        $map = [
            'Romantic'    => self::CURVE_SLOW_BURN,
            'Anxious'     => self::CURVE_QUICK,
            'Playful'     => self::CURVE_QUICK,
            'Bold'        => self::CURVE_MODERATE,
            'Humble'      => self::CURVE_MODERATE,
            'Nurturing'   => self::CURVE_MODERATE,
            'Gentle'      => self::CURVE_SLOW_BURN,
            'Jealous'     => self::CURVE_SLOW_BURN,
            'Defiant'     => self::CURVE_GUARDED,
            'Stoic'       => self::CURVE_GUARDED,
            'Proud'       => self::CURVE_GUARDED,
            'Guarded'     => self::CURVE_GUARDED,
            'Independent' => self::CURVE_GUARDED,
        ];
        return $map[$temperament] ?? self::CURVE_MODERATE;
    }

    // ---- Data source helpers ----

    private static function getMarasTemperament($npcName)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;

            $escaped = $db->escape($npcName);
            $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            if (!is_array($row) || empty($row['extended_data'])) return null;

            $ext = json_decode($row['extended_data'], true) ?: [];
            $maras = self::getPlayerRelationshipFromExtended($ext)['maras'] ?? null;
            return $maras['temperament'] ?? null;
        } catch (Throwable $e) {
            error_log("[RelDyn] getMarasTemperament failed for {$npcName}: " . $e->getMessage());
            return null;
        }
    }

    private static function getSharmatSpeechStyle($npcName)
    {
        if (!class_exists('NsfwNpcData')) return null;
        return NsfwNpcData::getKey($npcName, 'sex_speech_style');
    }

    private static function getNpcRace($npcName)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;

            $escaped = $db->escape($npcName);
            $row = $db->fetchOne("SELECT race FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            return $row['race'] ?? null;
        } catch (Throwable $e) {
            self::logError('getNpcRace', $e);
            return null;
        }
    }

    private static function getSocialClass($npcName)
    {
        // Try MARAS social_class first
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;

            $escaped = $db->escape($npcName);
            $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            if (!is_array($row) || empty($row['extended_data'])) return null;

            $ext = json_decode($row['extended_data'], true) ?: [];
            $maras = self::getPlayerRelationshipFromExtended($ext)['maras'] ?? null;
            return $maras['socialClass'] ?? null;
        } catch (Throwable $e) {
            error_log("[RelDyn] getSocialClass failed for {$npcName}: " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // PASSION CALCULATIONS
    // =========================================================================

    /**
     * Apply in-contact passion decay on the play clock. Call at prerequest time.
     * Fade across absences is advanceCalendar()'s (game calendar, attachment-scaled).
     */
    public static function decayPassion(&$dynamics)
    {
        $now = self::getPlayGamets($dynamics);
        $lastUpdate = floatval($dynamics['passion_updated_at'] ?? 0);
        if ($lastUpdate <= 0) {
            $dynamics['passion_updated_at'] = $now;
            return;
        }

        // Migration: a checkpoint ahead of the play clock is a legacy wall-clock stamp
        // (or comes from an earlier save): re-arm it. Not "> 1e9": the play clock
        // itself passes 1e9 after ~120 real hours with an NPC.
        if ($lastUpdate > $now) {
            $lastUpdate = $now;
            $dynamics['passion_updated_at'] = $now;
        }

        $hoursSince = ($now - $lastUpdate) / self::GAMETS_PER_REAL_HOUR;
        if ($hoursSince <= 0) return;

        // MDD 1.5 Point of Interest: in a place the NPC loves, in-contact decay halts
        // (the floor itself is held by RelDynFacets::placeTurn)
        if (RelDynFacets::poiPassionFloor($dynamics, self::currentGamets()) !== null) {
            $dynamics['passion_updated_at'] = $now;
            return;
        }

        // Cap decay hours — 0 means no between-session decay (passion frozen when offline)
        $cfg = self::getConfig();
        $maxDecayHours = floatval($cfg['decay_max_hours'] ?? 0);
        if ($maxDecayHours > 0) {
            $hoursSince = min($hoursSince, $maxDecayHours);
        } elseif ($maxDecayHours == 0) {
            // Between-session decay disabled — only decay within active play sessions
            // Cap at 10 minutes to handle normal in-session gaps
            $hoursSince = min($hoursSince, 0.167);
        }

        $curve = $dynamics['warmth_curve'] ?? self::CURVE_MODERATE;
        $params = self::CURVE_PARAMS[$curve] ?? self::CURVE_PARAMS[self::CURVE_MODERATE];
        $decayRate = $params['passion_decay'];

        $decay = $decayRate * $hoursSince;
        $stage = $dynamics['stage'] ?? self::STAGE_EARLY;
        $floor = self::STAGE_PARAMS[$stage]['floor'] ?? 0;

        self::setPassion($dynamics, max($floor, self::getPassion($dynamics) - $decay));
        $dynamics['passion_updated_at'] = $now;
    }

    /**
     * Current passion. dimensions.passion.x is the single source of truth;
     * the flat 'passion' key is only a mirror for legacy readers.
     */
    public static function getPassion($dynamics)
    {
        $x = $dynamics['dimensions']['passion']['x'] ?? null;
        if ($x !== null) {
            return floatval($x);
        }
        return floatval($dynamics['passion'] ?? 0);
    }

    /**
     * The one writer for passion: sets dimensions.passion.x and derives the
     * legacy 'passion' mirror from it. Never assign $dynamics['passion'] directly.
     */
    public static function setPassion(&$dynamics, $value)
    {
        $value = floatval($value);
        if (!isset($dynamics['dimensions']) || !is_array($dynamics['dimensions'])) {
            $dynamics['dimensions'] = [];
        }
        if (!isset($dynamics['dimensions']['passion']) || !is_array($dynamics['dimensions']['passion'])) {
            $dynamics['dimensions']['passion'] = ['x' => 0, 'baseline' => 0];
        }
        $dynamics['dimensions']['passion']['x'] = $value;
        $dynamics['passion'] = $value;
    }

    /**
     * Apply time-based jealousy decay. Call at prerequest time.
     */
    public static function decayJealousy(&$dynamics)
    {
        $now = self::getPlayGamets($dynamics);
        $lastUpdate = floatval($dynamics['jealousy_updated_at'] ?? 0);
        if ($lastUpdate <= 0 || floatval($dynamics['jealousy_anger'] ?? 0) <= 0) return;

        // Migration: a checkpoint ahead of the play clock is a legacy wall-clock stamp
        // (or comes from an earlier save): re-arm it. Not "> 1e9": the play clock
        // itself passes 1e9 after ~120 real hours with an NPC.
        if ($lastUpdate > $now) {
            $lastUpdate = $now;
            $dynamics['jealousy_updated_at'] = $now;
        }

        $hoursSince = ($now - $lastUpdate) / self::GAMETS_PER_REAL_HOUR;
        if ($hoursSince <= 0) return;
        // In contact only: the play clock also runs while the player plays elsewhere, and
        // that is absence, which does not heal (decisions §2). One window per turn counts.
        $hoursSince = min($hoursSince, self::IN_CONTACT_DECAY_MAX_HOURS);

        $cfg = self::getConfig();
        $decayRate = floatval($cfg['jealousy_decay_per_hour'] ?? 1.5);
        $decay = $decayRate * $hoursSince;

        self::setJealousy($dynamics, max(0.0, floatval($dynamics['jealousy_anger']) - $decay));
        $dynamics['jealousy_updated_at'] = $now;
    }

    /**
     * The one writer for jealousy. jealousy_anger is jealousy's single source of
     * truth; it is NOT resentment (dimensions.resentment.x) and is never mirrored.
     */
    public static function setJealousy(&$dynamics, $value)
    {
        $dynamics['jealousy_anger'] = floatval($value);
    }

    /**
     * Calculate passion gain from an interaction.
     * Returns the passion gain amount (before adding to pool).
     * $activityAppraisal: the appraisal of what is shared when it is not the place (a fight,
     * a gift; RelDynFacets::appraise), see getInterestMultiplier().
     */
    public static function calculatePassionGain($dynamics, $interactionLoveLanguage, ?array $activityAppraisal = null)
    {
        $cfg = self::getConfig();
        $baseGain = floatval($cfg['base_passion_gain'] ?? 2.0);

        // Love language multiplier
        $llMult = 1.0;
        if ($interactionLoveLanguage) {
            if ($interactionLoveLanguage === ($dynamics['love_language_primary'] ?? null)) {
                $llMult = 2.0;
            } elseif ($interactionLoveLanguage === ($dynamics['love_language_secondary'] ?? null)) {
                $llMult = 1.5;
            }
        }

        // Diminishing returns multiplier (exponential decay)
        $sessionMult = self::getSessionMultiplier($dynamics);

        // Stage multiplier
        $stage = $dynamics['stage'] ?? self::STAGE_EARLY;
        $stageMult = self::STAGE_PARAMS[$stage]['gain_mult'] ?? 1.0;

        // Temperament multiplier
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $tempMult = self::TEMPERAMENT_PASSION_MULT[$temperament] ?? 1.0;

        // Shared-activity multiplier (facet appraisal of the place / activity, MDD 1.2 + 1.5)
        $interestMult = self::getInterestMultiplier($dynamics, $interactionLoveLanguage, $activityAppraisal);

        // Conflict repair bonus
        $repairMult = 1.0;
        if (!empty($dynamics['in_conflict'])) {
            $cfg2 = self::getConfig();
            $repairMult = floatval($cfg2['conflict_repair_passion_mult'] ?? 1.5);
        }

        $gain = $baseGain * $llMult * $sessionMult * $stageMult * $tempMult * $interestMult * $repairMult;

        return max(0.0, $gain);
    }

    /**
     * Add passion to pool, respecting stage ceiling.
     */
    public static function addPassion(&$dynamics, $amount, $source = 'love_match')
    {
        $stage = $dynamics['stage'] ?? self::STAGE_EARLY;
        $ceiling = self::STAGE_PARAMS[$stage]['ceiling'] ?? 100;
        $cfg = self::getConfig();
        $max = min(floatval($cfg['passion_max'] ?? 100.0), $ceiling);

        self::setPassion($dynamics, min($max, self::getPassion($dynamics) + $amount));
        $dynamics['passion_updated_at'] = self::getPlayGamets($dynamics);

        // Track source
        if (isset($dynamics['passion_sources'][$source])) {
            $dynamics['passion_sources'][$source] += $amount;
        }
    }

    // =========================================================================
    // DIMINISHING RETURNS
    // =========================================================================

    /**
     * Get the current session multiplier based on exponential decay of interaction count.
     */
    public static function getSessionMultiplier($dynamics)
    {
        $now = self::getPlayGamets($dynamics);
        $lastInteraction = floatval($dynamics['last_interaction_at'] ?? 0);
        $rawCount = intval($dynamics['interaction_count'] ?? 0);

        if ($rawCount <= 0 || $lastInteraction <= 0) {
            return 1.0;
        }

        // Migration: a checkpoint ahead of the play clock is a legacy wall-clock stamp
        // (or comes from an earlier save): re-arm it. Not "> 1e9": the play clock
        // itself passes 1e9 after ~120 real hours with an NPC.
        if ($lastInteraction > $now) {
            $lastInteraction = $now;
            // Note: read-only function, caller must persist if needed
        }

        $curve = $dynamics['warmth_curve'] ?? self::CURVE_MODERATE;
        $params = self::CURVE_PARAMS[$curve] ?? self::CURVE_PARAMS[self::CURVE_MODERATE];
        $decayRate = $params['decay_rate'];
        $lambda = $params['lambda'];

        // Stage modifier on decay_rate
        $stage = $dynamics['stage'] ?? self::STAGE_EARLY;
        $drMult = self::STAGE_PARAMS[$stage]['dr_mult'] ?? 1.0;
        $effectiveDecayRate = $decayRate * $drMult;

        // Exponential decay of interaction count over play gamets
        $hoursSince = ($now - $lastInteraction) / self::GAMETS_PER_REAL_HOUR;
        $effectiveCount = $rawCount * exp(-$hoursSince * $lambda);

        $multiplier = 1.0 - ($effectiveCount * $effectiveDecayRate);

        return max(0.05, $multiplier);
    }

    /**
     * Increment interaction count after an interaction.
     */
    public static function recordInteraction(&$dynamics)
    {
        $now = self::getPlayGamets($dynamics);
        $lastInteraction = floatval($dynamics['last_interaction_at'] ?? 0);
        $rawCount = intval($dynamics['interaction_count'] ?? 0);

        // Migration: a checkpoint ahead of the play clock is a legacy wall-clock stamp
        // (or comes from an earlier save): re-arm it. Not "> 1e9": the play clock
        // itself passes 1e9 after ~120 real hours with an NPC.
        if ($lastInteraction > $now) {
            $lastInteraction = $now;
            $dynamics['last_interaction_at'] = $now;
        }

        // Apply exponential decay to existing count before incrementing
        if ($lastInteraction > 0 && $rawCount > 0) {
            $curve = $dynamics['warmth_curve'] ?? self::CURVE_MODERATE;
            $params = self::CURVE_PARAMS[$curve] ?? self::CURVE_PARAMS[self::CURVE_MODERATE];
            $lambda = $params['lambda'];
            $hoursSince = ($now - $lastInteraction) / self::GAMETS_PER_REAL_HOUR;
            $rawCount = $rawCount * exp(-$hoursSince * $lambda);
        }

        $dynamics['interaction_count'] = intval(ceil($rawCount)) + 1;
        $dynamics['last_interaction_at'] = $now;
        $dynamics['last_seen_at'] = $now;
        $dynamics['reunion_spike_given'] = false;
    }

    // =========================================================================
    // AFFINITY GAIN MULTIPLIER (RPM → Speed)
    // =========================================================================

    /**
     * Calculate the affinity gain multiplier from current passion.
     * passion 0 → 0.3x, passion 50 → 1.15x, passion 100 → 2.0x
     */
    public static function getAffinityGainMultiplier($dynamics)
    {
        $passion = floatval($dynamics['passion'] ?? 0);
        return 0.3 + ($passion / 100.0) * 1.7;
    }

    // =========================================================================
    // REUNION SPIKE
    // =========================================================================

    /**
     * Check for reunion spike. Call at prerequest time.
     * Returns the passion amount added (0 if no reunion).
     */
    public static function checkReunion(&$dynamics, $npcAffection = 0)
    {
        $cfg = self::getConfig();
        $minHours = floatval(self::configValue('reunion_min_hours'));             // game-calendar hours
        $minAff = intval($cfg['reunion_min_affection'] ?? 40);                    // core affinity, -100..100
        $minPlayMinutes = floatval(self::configValue('reunion_min_play_minutes')); // real minutes of play

        // Already spiked this visit
        if (!empty($dynamics['reunion_spike_given'])) return 0.0;

        // Time apart is game-calendar hours since the last contact (waiting and sleeping
        // count). Null: no calendar contact yet (markContact starts it) or an earlier save.
        $hoursApart = self::gameHoursSince($dynamics, '_last_contact_gamets');
        if ($hoursApart === null) return 0.0;

        // Check affection threshold
        if ($npcAffection < $minAff) return 0.0;

        if ($hoursApart < $minHours) return 0.0;

        // No wait-scumming: the separation must hold real play, not only a wait or sleep.
        $playApart = self::playGametsSince($dynamics, '_last_contact_play_gamets') ?? 0.0;
        if ($playApart / (self::GAMETS_PER_REAL_SECOND * 60.0) < $minPlayMinutes) return 0.0;

        // Calculate spike (tiers in game-calendar hours)
        $spike = 0.0;
        if ($hoursApart >= 72) {
            $spike = 25.0;
        } elseif ($hoursApart >= 48) {
            $spike = 18.0;
        } elseif ($hoursApart >= 24) {
            $spike = 12.0;
        } elseif ($hoursApart >= 16) {
            $spike = 8.0;
        } else {
            $spike = 5.0;
        }

        // Temperament modifier
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $tempMult = self::TEMPERAMENT_REUNION_MULT[$temperament] ?? 1.0;
        $spike *= $tempMult;

        $dynamics['reunion_spike_given'] = true;
        $dynamics['_reunion_hours_apart'] = round($hoursApart, 2);   // game-calendar hours, for context.php

        self::log("Reunion spike for NPC: +{$spike} passion (game_hours_apart={$hoursApart}, temp_mult={$tempMult})");

        return $spike;
    }

    /**
     * Record contact with the player now: this NPC's request is being handled. Stamps the
     * game calendar (absence, reunion, neglect) and the play clock (reunion's check that
     * the time apart held real play). Call after checkReunion() and after the calendar
     * step for this NPC, which both measure the time since the previous contact.
     */
    public static function markContact(array &$dynamics): void
    {
        self::markGameClock($dynamics, '_last_contact_gamets');
        self::markPlayCheckpoint($dynamics, '_last_contact_play_gamets');
    }

    // =========================================================================
    // GAME-CALENDAR STEP (decisions 2026-09-23 §2: time does not heal, contact does)
    // =========================================================================

    /**
     * Raw resentment (0..100 points) handed to applyDelta per call. Calendar resentment
     * accrues linearly into _calendar_resentment_raw and is applied in these fixed quanta,
     * so the result does not depend on how often the calendar is stepped (applyDelta's
     * inverted rubber band makes one big delta and many small ones differ).
     */
    const CALENDAR_RESENTMENT_QUANTUM = 1.0;

    /** Game days (raw gamets / GAMETS_PER_DAY) of [from, to] at or after $start. */
    private static function calendarDaysFrom(float $from, float $to, float $start): float
    {
        return max(0.0, $to - max($from, $start)) / self::GAMETS_PER_DAY;
    }

    /**
     * Advance one NPC through game-calendar time [from, to] (raw gamets) with no contact:
     *  - fester: open conflict + maturity below fester_maturity_below -> raw resentment per day;
     *  - jealousy: jealousy above jealousy_resentment_above -> raw resentment per day (§5);
     *  - neglect: bonded NPC past its grace since _last_contact_gamets -> raw resentment per
     *    day, logged as one 'neglect' grievance per absence;
     *  - passion fade: past the absence grace, passion fades per day x attachment multiplier
     *    down to the stage floor;
     *  - warmth fade: the same for warmth above its baseline, at its own rate.
     * Nothing negative is ever reduced here. Neglect and fade are skipped while the NPC is
     * the one who left (walkaway). Pure: no database, no clock reads.
     *
     * @return array ['game_days', 'resentment_raw', 'resentment', 'jealousy_resentment_raw', 'neglect_days', 'passion_fade', 'warmth_fade', 'bond_type']
     */
    public static function advanceCalendar(array &$dynamics, float $fromGamets, float $toGamets): array
    {
        $out = ['game_days' => 0.0, 'resentment_raw' => 0.0, 'resentment' => 0.0, 'jealousy_resentment_raw' => 0.0,
                'neglect_days' => 0.0, 'passion_fade' => 0.0, 'warmth_fade' => 0.0, 'bond_type' => null];
        if ($fromGamets <= 0 || $toGamets <= $fromGamets) {
            return $out;
        }
        $days = ($toGamets - $fromGamets) / self::GAMETS_PER_DAY;
        $out['game_days'] = $days;

        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);   // 0..100
        $attachment = self::getAttachmentStyle($dynamics);
        $away = ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal';
        $lastContact = floatval($dynamics['_last_contact_gamets'] ?? 0);       // raw gamets
        $raw = 0.0;                                                            // raw resentment points

        // Fester: an open conflict in an immature NPC grows every game day.
        if (!empty($dynamics['in_conflict']) && $maturity < floatval(self::configValue('fester_maturity_below'))) {
            $raw += floatval(self::configValue('fester_resentment_per_game_day')) * $days;
        }

        // Sustained jealousy converts into resentment (decisions §5): while jealousy (0..100)
        // is above the threshold, resentment (0..100 points) += k x (jealousy - 30) / 70 per
        // game day. That is the decided resentment itself, so it is added as is, not through
        // applyDelta's physics (maturity Y, attachment gain, suppressed +50%), which would make
        // it 0.9x..8x the decided rate depending on the NPC. Linear, so slicing never matters.
        // Jealousy only cools in contact (decayJealousy), so it is constant over this interval.
        $jealousy = floatval($dynamics['jealousy_anger'] ?? 0);
        $jAbove = floatval(self::configValue('jealousy_resentment_above'));
        if ($jealousy > $jAbove && self::configValue('jealousy_enabled')) {
            $jRaw = floatval(self::configValue('jealousy_resentment_k'))
                * ($jealousy - $jAbove) / (self::JEALOUSY_SCALE_MAX - $jAbove) * $days;
            if (!isset($dynamics['dimensions']['resentment']) || !is_array($dynamics['dimensions']['resentment'])) {
                $dynamics['dimensions']['resentment'] = ['x' => 0, 'baseline' => 0, 'active' => true];
            }
            $max = floatval(self::getDimensionDefinition('resentment')['range_max'] ?? 100);
            $before = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);
            $after = min($max, $before + $jRaw);
            $dynamics['dimensions']['resentment']['x'] = $after;   // unrounded: slicing must not drift
            $out['resentment'] += $after - $before;
            self::enforceResentmentWithdrawal($dynamics);
            $out['jealousy_resentment_raw'] = $jRaw;
        }

        // Neglect: game days past the bond's grace since the player's last contact.
        if (!$away && $lastContact > 0 && self::configValue('neglect_enabled')) {
            $bondType = self::getRelationshipType('', $dynamics);
            $out['bond_type'] = $bondType;
            $bond = (self::configValue('neglect_bond_types') ?? [])[$bondType] ?? null;
            if (is_array($bond)) {
                $graceMult = floatval((self::configValue('neglect_attachment_grace_mult') ?? [])[$attachment] ?? 1.0);
                $graceDays = floatval($bond['grace_game_days'] ?? 0) * $graceMult;
                $neglectDays = self::calendarDaysFrom($fromGamets, $toGamets, $lastContact + $graceDays * self::GAMETS_PER_DAY);
                if ($neglectDays > 0) {
                    $neglectRaw = floatval($bond['resentment_per_game_day'] ?? 0) * $neglectDays;
                    $raw += $neglectRaw;
                    $out['neglect_days'] = $neglectDays;
                    self::recordNeglectGrievance($dynamics, $lastContact, $toGamets, $neglectRaw);
                }
            }
        }

        // Positive states fade with absence (passion), scaled by attachment.
        if (!$away && $lastContact > 0) {
            $graceGamets = floatval(self::configValue('passion_absence_grace_game_hours')) * self::GAMETS_PER_DAY / 24.0;
            $absentDays = self::calendarDaysFrom($fromGamets, $toGamets, $lastContact + $graceGamets);
            if ($absentDays > 0) {
                $mult = floatval((self::configValue('passion_absence_attachment_mult') ?? [])[$attachment] ?? 1.0);
                $stage = $dynamics['stage'] ?? self::STAGE_EARLY;
                $floor = floatval(self::STAGE_PARAMS[$stage]['floor'] ?? 0);
                $passion = self::getPassion($dynamics);
                if ($passion > $floor) {
                    $fade = floatval(self::configValue('passion_absence_fade_per_game_day')) * $absentDays * $mult;
                    $new = max($floor, $passion - $fade);
                    self::setPassion($dynamics, $new);
                    $out['passion_fade'] = $passion - $new;
                }
            }

            // Warmth (0..100) above its baseline fades the same way, at its own rate.
            $warmth = $dynamics['dimensions']['warmth']['x'] ?? null;
            $graceGamets = floatval(self::configValue('warmth_absence_grace_game_hours')) * self::GAMETS_PER_DAY / 24.0;
            $absentDays = self::calendarDaysFrom($fromGamets, $toGamets, $lastContact + $graceGamets);
            if (is_numeric($warmth) && $absentDays > 0) {
                $baseline = $dynamics['dimensions']['warmth']['baseline'] ?? null;
                $baseline = is_numeric($baseline) ? floatval($baseline) : self::getTemperamentBaseline($temperament, 'warmth');
                if (floatval($warmth) > $baseline) {
                    $mult = floatval((self::configValue('warmth_absence_attachment_mult') ?? [])[$attachment] ?? 1.0);
                    $fade = floatval(self::configValue('warmth_absence_fade_per_game_day')) * $absentDays * $mult;
                    $new = max($baseline, floatval($warmth) - $fade);
                    $dynamics['dimensions']['warmth']['x'] = round($new, 6);
                    $out['warmth_fade'] = floatval($warmth) - $new;
                }
            }
        }

        // Feed the resentment accumulator in fixed quanta (see CALENDAR_RESENTMENT_QUANTUM).
        $out['resentment_raw'] = $raw;
        $buffer = floatval($dynamics['_calendar_resentment_raw'] ?? 0) + $raw;
        $max = floatval(self::getDimensionDefinition('resentment')['range_max'] ?? 100);
        while ($buffer >= self::CALENDAR_RESENTMENT_QUANTUM - 1e-9) {
            if (floatval($dynamics['dimensions']['resentment']['x'] ?? 0) >= $max) {
                $buffer = 0.0;   // already at the ceiling: nothing left to feel
                break;
            }
            $out['resentment'] += self::applyDelta('resentment', $dynamics, self::CALENDAR_RESENTMENT_QUANTUM, $temperament);
            $buffer -= self::CALENDAR_RESENTMENT_QUANTUM;
        }
        $dynamics['_calendar_resentment_raw'] = round(max(0.0, $buffer), 9);

        return $out;
    }

    /** One 'neglect' grievance per absence (keyed by the contact it counts from), kept current. */
    private static function recordNeglectGrievance(array &$dynamics, float $sinceGamets, float $nowGamets, float $raw): void
    {
        if (!isset($dynamics['dimensions']['resentment']) || !is_array($dynamics['dimensions']['resentment'])) {
            $dynamics['dimensions']['resentment'] = ['x' => 0, 'baseline' => 0, 'active' => true];
        }
        $log = $dynamics['dimensions']['resentment']['grievance_log'] ?? [];
        if (!is_array($log)) $log = [];
        $gameDays = ($nowGamets - $sinceGamets) / self::GAMETS_PER_DAY;
        $found = null;
        foreach ($log as $i => $entry) {
            if (is_array($entry) && ($entry['tag'] ?? null) === 'neglect'
                && abs(floatval($entry['since_gamets'] ?? -1) - $sinceGamets) < 0.5) {
                $found = $i;
            }
        }
        if ($found !== null) {
            $log[$found]['raw'] = round(floatval($log[$found]['raw'] ?? 0) + $raw, 4);
            $log[$found]['game_days'] = $gameDays;
            $log[$found]['gamets'] = $nowGamets;
            $log[$found]['text'] = sprintf('neglect: no contact for %.1f game days', $gameDays);
        } else {
            $log[] = [
                'text' => sprintf('neglect: no contact for %.1f game days', $gameDays),
                'tag' => 'neglect',
                'since_gamets' => $sinceGamets,
                'game_days' => $gameDays,
                'raw' => round($raw, 4),
                'gamets' => $nowGamets,
            ];
        }
        $dynamics['dimensions']['resentment']['grievance_log'] = array_slice($log, -10);
    }

    /**
     * Advance the game calendar for NPCs whose calendar step is due (at least
     * calendar_scan_interval_game_hours old), so time moves for every bond on any request,
     * not only for the NPC being talked to. $priorityNpc (the NPC of this request) goes
     * first when due, so its absence is felt before contact is marked; then at most
     * calendar_scan_max_npcs others, oldest step first. Cost when nothing is due: one SELECT
     * (plus two small reads for $priorityNpc).
     *
     * @return array npcName => ['calendar' => advanceCalendar() result|null, 'walkaway' => state|null, 'hoover' => bool]
     */
    public static function runCalendarScan(?string $priorityNpc = null): array
    {
        $now = self::currentGamets();   // raw game-calendar gamets
        if ($now <= 0 || empty($GLOBALS['db'])) {
            return [];
        }
        $dueBefore = $now - floatval(self::configValue('calendar_scan_interval_game_hours')) * self::GAMETS_PER_DAY / 24.0;
        $limit = max(0, intval(self::configValue('calendar_scan_max_npcs')));

        $done = [];
        try {
            if ($priorityNpc !== null && $priorityNpc !== '') {
                $id = RelDynStorage::resolveNpcId($priorityNpc);
                if ($id !== null) {
                    $r = self::advanceNpcCalendar($id, $priorityNpc, $now, $dueBefore);
                    if ($r !== null) $done[$priorityNpc] = $r;
                }
            }
            if ($limit > 0) {
                $others = 0;
                foreach (RelDynStorage::dueForCalendar($dueBefore, $limit + count($done)) as $row) {
                    if ($others >= $limit) break;
                    if (isset($done[$row['npc_name']])) continue;   // the priority NPC, already stepped
                    $r = self::advanceNpcCalendar($row['id'], $row['npc_name'], $now, $dueBefore);
                    if ($r !== null) {
                        $done[$row['npc_name']] = $r;
                        $others++;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn-CAL] calendar scan failed: " . $e->getMessage());
        }
        return $done;
    }

    /**
     * One NPC's calendar step from its stored checkpoint to $now (raw gamets).
     *
     * The interval is claimed first with a compare-and-set on the 'calendar' key: of two
     * overlapping requests only the one whose write lands applies it, the other skips
     * (a crash after the claim loses the interval rather than applying it twice). The
     * effects are then saved with the usual merge, so a concurrent save of this NPC keeps
     * both. First sight (no step yet, or a save loaded from before the step) starts the
     * clocks at $now without backdated effects.
     *
     * @return array|null null when not due or another request took the interval
     */
    public static function advanceNpcCalendar(int $npcId, string $npcName, float $now, float $dueBefore): ?array
    {
        $cur = RelDynStorage::readKeyForUpdate($npcId, RelDynStorage::KEY_CALENDAR);
        if ($cur === null) {
            return null;
        }
        $from = floatval($cur['value']['checked_gamets'] ?? 0);
        $firstSight = ($from <= 0 || $from > $now);
        if (!$firstSight && $from > $dueBefore) {
            return null;
        }
        if (!RelDynStorage::setKeyIfUnchanged($npcId, RelDynStorage::KEY_CALENDAR, $cur['expected'], ['checked_gamets' => $now])) {
            return null;
        }

        $dyn = self::getDynamics($npcName);
        $changed = false;
        $result = ['calendar' => null, 'walkaway' => null, 'hoover' => false];

        if (floatval($dyn['_last_contact_gamets'] ?? 0) <= 0 || floatval($dyn['_last_contact_gamets']) > $now) {
            $dyn['_last_contact_gamets'] = $now;   // absence counts from the first time we see them
            $changed = true;
        }
        if (!$firstSight) {
            $step = self::advanceCalendar($dyn, $from, $now);
            $result['calendar'] = $step;
            $changed = $changed || $step['resentment_raw'] > 0 || $step['jealousy_resentment_raw'] > 0
                || $step['passion_fade'] > 0 || $step['warmth_fade'] > 0;
        }

        // A walkaway resolves (or a Toxic sleeper hoovers back) while the player is elsewhere.
        // 'pending' waits for the NPC's own next request, when they physically leave.
        $cfg = self::getConfig();
        $temperament = $dyn['inferred_temperament'] ?? $dyn['temperament'] ?? 'Stoic';
        $walkState = $dyn['_walkaway_state'] ?? 'normal';
        if (($cfg['autonomy_enabled'] ?? true) && in_array($walkState, ['active', 'boundary_test'], true)) {
            $walkKeys = fn(array $d) => array_filter($d, fn($k) => is_string($k)
                && (str_starts_with($k, '_walkaway_') || str_starts_with($k, '_boundary_test_')), ARRAY_FILTER_USE_KEY);
            $before = $walkKeys($dyn);
            $tick = self::resolveWalkawayTick($dyn, $npcName, $temperament, false);
            $result['walkaway'] = $tick['state'];
            // Save any state change, and clocks a first tick started (older builds' walkaways)
            $changed = $changed || $tick['returned'] || $walkKeys($dyn) != $before;
        }
        if (($cfg['hoover_enabled'] ?? true) && self::checkHooverEligibility($dyn)) {
            self::executeHoover($dyn, $npcName, $temperament);
            $result['hoover'] = true;
            $changed = true;
        }

        if ($changed) {
            self::saveDynamics($npcName, $dyn);
        }
        return $result;
    }

    // =========================================================================
    // JEALOUSY
    // =========================================================================

    // Jealousy sources (no MARAS on 3.4.1): contract eval jealousy events and the bystander
    // scan, see jealousyEventGain() / bystanderJealousyGain() in the EVAL FEELINGS section.

    /**
     * Add jealousy to an NPC's dynamics.
     */
    public static function addJealousy(&$dynamics, $amount, $triggerNpc = null)
    {
        $cfg = self::getConfig();
        $max = floatval($cfg['jealousy_max'] ?? 100.0);

        self::setJealousy($dynamics, min($max, floatval($dynamics['jealousy_anger']) + $amount));
        $dynamics['jealousy_updated_at'] = self::getPlayGamets($dynamics);
        if ($triggerNpc) {
            $dynamics['jealousy_trigger_npc'] = $triggerNpc;
        }

        // Check if this triggers conflict
        $threshold = floatval($cfg['conflict_threshold_jealousy'] ?? 40);
        if ($dynamics['jealousy_anger'] >= $threshold && empty($dynamics['in_conflict'])) {
            self::enterConflict($dynamics);
        }
    }

    // =========================================================================
    // CONFLICT / REPAIR
    // =========================================================================

    public static function enterConflict(&$dynamics)
    {
        $dynamics['in_conflict'] = true;
        $dynamics['conflict_entered_at'] = self::getPlayGamets($dynamics);
        $dynamics['conflict_positive_count'] = 0;
        self::log("Entered conflict state");
    }

    /**
     * Record a positive interaction during conflict. Check for resolution.
     * Returns passion burst if conflict resolved, 0 otherwise.
     */
    public static function recordConflictPositive(&$dynamics)
    {
        if (empty($dynamics['in_conflict'])) return 0.0;

        $dynamics['conflict_positive_count'] = intval($dynamics['conflict_positive_count']) + 1;

        $cfg = self::getConfig();
        $neededCount = intval($cfg['conflict_resolution_positive_count'] ?? 3);
        $jealousyThreshold = floatval($cfg['conflict_threshold_jealousy'] ?? 40) * 0.5;

        if ($dynamics['conflict_positive_count'] >= $neededCount
            && floatval($dynamics['jealousy_anger']) < $jealousyThreshold) {

            // Conflict resolved
            $dynamics['in_conflict'] = false;
            $dynamics['conflict_positive_count'] = 0;

            $burst = floatval($cfg['conflict_repair_passion_burst'] ?? 20.0);
            self::log("Conflict resolved! Passion burst: +{$burst}");
            return $burst;
        }

        return 0.0;
    }

    /**
     * Check if affinity drop triggers conflict.
     */
    public static function checkAffinityDropConflict(&$dynamics, $affinityDelta)
    {
        if ($affinityDelta >= 0) return;
        if (!empty($dynamics['in_conflict'])) return;

        $cfg = self::getConfig();
        $threshold = intval($cfg['conflict_threshold_affinity_drop'] ?? 10);

        if (abs($affinityDelta) >= $threshold) {
            self::enterConflict($dynamics);
        }
    }

    // =========================================================================
    // RELATIONSHIP STAGES
    // =========================================================================

    /**
     * Check and advance relationship stage if threshold crossed.
     */
    public static function checkStageAdvancement(&$dynamics)
    {
        $cfg = self::getConfig();
        $total = intval($dynamics['total_positive_interactions'] ?? 0);
        $currentStage = $dynamics['stage'] ?? self::STAGE_EARLY;

        $deepThreshold = intval($cfg['stage_deep_threshold'] ?? 200);
        $estThreshold = intval($cfg['stage_established_threshold'] ?? 50);

        $newStage = $currentStage;
        if ($total >= $deepThreshold) {
            $newStage = self::STAGE_DEEP;
        } elseif ($total >= $estThreshold) {
            $newStage = self::STAGE_ESTABLISHED;
        }

        if ($newStage !== $currentStage) {
            self::log("Stage advanced: {$currentStage} → {$newStage} (interactions: {$total})");
            $dynamics['stage'] = $newStage;
        }
    }

    // =========================================================================
    // INTERACTION CLASSIFICATION
    // =========================================================================

    /**
     * Classify the current interaction into a love language category.
     *
     * @param array $gameRequest The game request array
     * @param string|null $npcMood The NPC's mood after this interaction
     * @return string|null Love language constant or null if unclassifiable
     */
    public static function classifyInteraction($gameRequest, $npcMood = null)
    {
        $type = $gameRequest[0] ?? '';
        $action = $gameRequest[3] ?? '';

        // Physical touch
        if (in_array($type, ['ext_nsfw_physics', 'ext_nsfw_physics_raw'])) {
            return self::LL_TOUCH;
        }
        if (stripos($action, 'ExtCmdHug') !== false || stripos($action, 'ExtCmdKiss') !== false) {
            return self::LL_TOUCH;
        }
        if (stripos($action, 'ExtCmdStartMassage') !== false) {
            return self::LL_TOUCH;
        }
        // OStim scene events
        if (stripos($action, 'OStim') !== false || stripos($type, 'ostim') !== false) {
            return self::LL_TOUCH;
        }

        // Gifts (MARAS gift sync)
        if ($type === 'maras_sync' && stripos($action, 'gift') !== false) {
            return self::LL_GIFTS;
        }

        // Words of affirmation (flirty/loving mood)
        $romanticMoods = ['flirty', 'loving', 'lovely', 'playful', 'seductive', 'aroused', 'charming', 'affectionate'];
        if ($npcMood && in_array(strtolower($npcMood), $romanticMoods)) {
            return self::LL_WORDS;
        }

        // Acts of service (combat/quest/protective context)
        if ($type === 'maras_sync' && stripos($action, 'promotion') !== false) {
            return self::LL_SERVICE;
        }
        // Combat-together events — fighting alongside = acts of service
        $combatTypes = ['combatend', 'combatendmighty', 'bleedout'];
        if (in_array($type, $combatTypes)) {
            return self::LL_SERVICE;
        }
        // Give/trade item actions — providing for someone = acts of service
        if (stripos($action, 'ExtCmdGiveItem') !== false || stripos($action, 'ExtCmdTradeItem') !== false) {
            return self::LL_SERVICE;
        }
        // Post-combat dialogue — talking after fighting together
        if (in_array($type, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat'])) {
            if (self::isNpcInCombatRecently($gameRequest)) {
                return self::LL_SERVICE;
            }
        }
        // Service-oriented moods — NPC feels protected/grateful
        $serviceMoods = ['grateful', 'protective', 'loyal', 'admiring', 'relieved', 'safe'];
        if ($npcMood && in_array(strtolower($npcMood), $serviceMoods)) {
            return self::LL_SERVICE;
        }

        // Quality time (regular conversation)
        $dialogueTypes = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat'];
        if (in_array($type, $dialogueTypes)) {
            return self::LL_TIME;
        }

        return null;
    }

    /**
     * Was the NPC in (or just out of) a fight: any core combat event within the last
     * COMBAT_KILL_STREAK_WINDOW_GAMETS (5 min of real play on the game clock). See
     * coreCombatState(); the current request being a combat event counts too.
     */
    private static function isNpcInCombatRecently($gameRequest)
    {
        $npcName = $GLOBALS['RELDYN_NPC_NAME'] ?? $GLOBALS['HERIKA_NAME'] ?? '';
        if (empty($npcName)) return false;
        if (in_array($gameRequest[0] ?? '', self::CORE_COMBAT_REQUEST_TYPES, true)) return true;
        return self::coreCombatState($npcName)['recent'];
    }

    // =========================================================================
    // INTEREST-WEIGHTED PASSION — Detection, Classification & Preferences
    // =========================================================================

    /**
     * The interest category (one of the 11 MDD 1.2 interests) an item speaks to most, for the
     * interest-string callers (detectGiftInterest -> getInterestMultiplier): the strongest
     * interest facet of RelDynFacets::thingFacets('item', name) -- Oghma first (precomputed
     * facets or the live knowledge_class/category/tags prior), then the item keyword table.
     * Null when the item has no interest facet.
     */
    public static function classifyItemInterest($itemName)
    {
        if (!is_string($itemName) || trim($itemName) === '') return null;
        return RelDynFacets::dominantInterest(RelDynFacets::thingFacets('item', $itemName));
    }

    /**
     * The item of a gift/item interaction: the LLM response's item field (connectors set
     * LAST_LLM_RESPONSE), else the gameRequest action ExtCmdGiveItem@Name / ExtCmdTradeItem@Name.
     */
    public static function detectGiftItemName(): ?string
    {
        $llmResponse = $GLOBALS['LAST_LLM_RESPONSE'] ?? null;
        if (is_array($llmResponse) && is_string($llmResponse['item'] ?? null) && trim($llmResponse['item']) !== '') {
            return trim($llmResponse['item']);
        }
        $action = $GLOBALS['gameRequest'][3] ?? '';
        if (is_string($action) && preg_match('/ExtCmd(?:Give|Trade)Item@([^:\r\n]+)/i', $action, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * The 11 MDD 1.2 interests as 0.5x-2.0x multipliers, for consumers that still think in
     * interest multipliers (topic bonus, attraction archetype, the editor). One source of
     * truth: the NPC's signed facet preferences, through the documented mapping
     * RelDynFacets::interestMultiplier() (-1 -> 0.5x, 0 -> 1.0x, +1 -> 2.0x).
     * $npcName defaults to the NPC of the current request.
     */
    public static function getInterests($dynamics, ?string $npcName = null)
    {
        $npcName = $npcName ?? (string) ($GLOBALS['RELDYN_NPC_NAME'] ?? $GLOBALS['HERIKA_NAME'] ?? '');
        $prefs = RelDynFacets::preferences(is_array($dynamics) ? $dynamics : [], $npcName);
        $out = [];
        foreach (RelDynFacets::INTERESTS as $interest) {
            $out[$interest] = RelDynFacets::interestMultiplier(floatval($prefs[$interest] ?? 0.0));
        }
        return $out;
    }

    /**
     * Shared-activity passion multiplier for the given love language (MDD 1.2 interests
     * 0.5x-2.0x, MDD 1.5 bad date 0.7x; decisions §6): the facet appraisal of what is shared,
     * see RelDynFacets::sharedActivityPassionMult(). For a gift the item's facets decide the
     * interest when they are known (thingFacets), else the place being shared does.
     */
    public static function getInterestMultiplier($dynamics, $interactionLL = null, ?array $activityAppraisal = null)
    {
        if ($interactionLL === null) return 1.0;

        $activity = $activityAppraisal;
        if ($activity === null && $interactionLL === self::LL_GIFTS) {
            $item = self::detectGiftItemName();
            $facets = $item !== null ? RelDynFacets::thingFacets('item', $item) : [];
            if ($facets) {
                $npcName = (string) ($GLOBALS['RELDYN_NPC_NAME'] ?? $GLOBALS['HERIKA_NAME'] ?? '');
                $activity = RelDynFacets::appraise(RelDynFacets::preferences($dynamics, $npcName), $facets);
            }
        }
        return RelDynFacets::sharedActivityPassionMult($dynamics, $interactionLL, $activity);
    }

    // =========================================================================
    // REUNION TEXT (temperament-aware)
    // =========================================================================

    /**
     * Generate temperament-appropriate reunion narrative text.
     *
     * @param string $npcName NPC name
     * @param string $temperament Inferred temperament (Romantic, Independent, etc.)
     * @param float $hoursApart Real-time hours since last seen
     * @param string $player Player name
     * @return string|null Context text or null if no reunion
     */
    public static function getReunionText($npcName, $temperament, $hoursApart, $player)
    {
        if ($hoursApart < 8) return null;

        // Time tier: long (48h+), medium (24h+), short (8h+)
        if ($hoursApart >= 48) {
            $tier = 'long';
        } elseif ($hoursApart >= 24) {
            $tier = 'medium';
        } else {
            $tier = 'short';
        }

        $texts = [
            'Romantic' => [
                'long'   => "{$npcName} hasn't seen {$player} in far too long — there's a rush of emotion, relief and warmth flooding back at seeing them again. The urge to close the distance is overwhelming.",
                'medium' => "{$npcName} missed {$player} — seeing them again brings a wave of warmth and the urge to close the distance between them.",
                'short'  => "{$npcName} is genuinely glad to see {$player} again — a warmth that shows in her eyes before she can hide it.",
            ],
            'Independent' => [
                'long'   => "{$npcName} notes {$player}'s return with a measured look. Something eases in her posture — barely perceptible — though her expression gives nothing away. She noticed the absence more than she expected to.",
                'medium' => "{$npcName} registers {$player}'s presence. A pause — almost imperceptible — before she continues what she was doing. The silence between them is slightly warmer than it was before.",
                'short'  => "{$npcName} acknowledges {$player}'s return with a slight nod. If she is pleased to see them, it shows only in the fact that she looked up at all.",
            ],
            'Proud' => [
                'long'   => "{$npcName} composes herself at seeing {$player} again after so long. Something flickers behind her eyes — quickly mastered. She would never admit how much the absence weighed on her.",
                'medium' => "{$npcName} carries herself with deliberate poise as {$player} returns. She has things to say about the absence, but they will keep. For now, she allows a measured warmth.",
                'short'  => "{$npcName} greets {$player}'s return with composure and the faintest warming of her tone.",
            ],
            'Jealous' => [
                'long'   => "{$npcName} stares at {$player} with an intensity that holds both relief and accusation. Where have they been? Who were they with? The questions burn behind her eyes even as warmth floods back.",
                'medium' => "{$npcName} is clearly relieved to see {$player}, but an edge of anxiety lingers — a need to know where they were and why they stayed away.",
                'short'  => "{$npcName} watches {$player} return with sharp eyes. Glad, yes — but watchful. Already cataloguing whether anything has changed.",
            ],
            'Humble' => [
                'long'   => "{$npcName} quietly brightens at {$player}'s return, like a hearth rekindled after a long cold. She doesn't demand explanations — she's simply, genuinely glad they came back.",
                'medium' => "{$npcName} greets {$player} with a warm, unguarded smile. The relief is honest and unhidden. She doesn't try to make it more or less than it is.",
                'short'  => "{$npcName} looks up at {$player}'s return with quiet pleasure. A small, genuine warmth.",
            ],
            'Anxious' => [
                'long'   => "{$npcName} freezes at the sight of {$player}. Relief crashes into hurt crashes into desperate gladness. The words come too fast — 'Where were you? I thought — never mind. You're here.'",
                'medium' => "{$npcName} visibly exhales seeing {$player}. The tension she's been carrying dissolves into nervous warmth. She moves closer almost involuntarily.",
                'short'  => "{$npcName} brightens immediately at {$player}'s return, then catches herself — tries to play it cool. Fails.",
            ],
            'Bold' => [
                'long'   => "{$npcName} strides toward {$player} without hesitation. 'About time.' The directness masks the depth of what she felt during the absence.",
                'medium' => "{$npcName} greets {$player} with confident warmth. No games, no pretense. She's glad they're here and she shows it plainly.",
                'short'  => "{$npcName} acknowledges {$player}'s return with a firm nod and the ghost of a smile. 'Missed the action.'",
            ],
            'Playful' => [
                'long'   => "{$npcName} greets {$player} with an exaggerated pout. 'Oh, you're alive. I was about to give away your things.' The lightness barely hides how much she missed them.",
                'medium' => "{$npcName} flashes a grin at {$player}. 'Couldn't stay away, could you?' There's genuine warmth under the teasing.",
                'short'  => "{$npcName} gives {$player} a playful look. 'Back already? I was just getting comfortable.'",
            ],
            'Nurturing' => [
                'long'   => "{$npcName} searches {$player}'s face with quiet concern — are they hurt? Tired? Hungry? The questions are gentle but thorough. She's been worrying.",
                'medium' => "{$npcName} greets {$player} with a warm, steady presence. 'You look tired. Come sit.' Caretaking first, everything else after.",
                'short'  => "{$npcName} smiles warmly at {$player}'s return. A quiet check — eyes scanning for injury — before relaxing.",
            ],
            'Gentle' => [
                'long'   => "{$npcName} looks at {$player} for a long moment without speaking. When the words come, they're soft. 'I'm glad.' That's all. It's enough.",
                'medium' => "{$npcName} greets {$player} with a soft warmth that radiates without effort. Her presence says what her words don't.",
                'short'  => "{$npcName} offers {$player} a gentle smile. Understated, sincere.",
            ],
            'Guarded' => [
                'long'   => "{$npcName} studies {$player} from across the room. Something shifts behind her eyes — a wall lowering a fraction. She doesn't approach. But she doesn't look away either.",
                'medium' => "{$npcName} notes {$player}'s return with careful neutrality. Only the slight easing of her shoulders betrays that she noticed the absence.",
                'short'  => "{$npcName} glances at {$player}. A beat longer than necessary. Then back to what she was doing.",
            ],
            'Stoic' => [
                'long'   => "{$npcName} stands still as {$player} approaches. Her expression is unreadable. But she turns to face them fully — and that, from her, is a declaration.",
                'medium' => "{$npcName} acknowledges {$player} with the barest inclination of her head. The silence that follows is not cold. It's loaded.",
                'short'  => "{$npcName} meets {$player}'s eyes briefly. Says nothing. The corner of her mouth moves — not quite a smile.",
            ],
            'Defiant' => [
                'long'   => "{$npcName} looks {$player} up and down. 'You look like hell. Good — means you were doing something.' The defiance is the affection.",
                'medium' => "{$npcName} gives {$player} a sharp grin. 'Didn't think you'd come crawling back this fast.' She's pleased. She'd never say so.",
                'short'  => "{$npcName} smirks at {$player}. 'Back for more?' Challenge as greeting — her native tongue.",
            ],
        ];

        // Default fallback (original text for unknown temperaments)
        $default = [
            'long'   => "{$npcName} hasn't seen {$player} in a long time — there's a rush of emotion, relief and warmth flooding back at seeing them again.",
            'medium' => "{$npcName} missed {$player} — seeing them again brings a wave of warmth and the urge to close the distance between them.",
            'short'  => "{$npcName} is glad to see {$player} again — a pleasant warmth at their return.",
        ];

        $set = $texts[$temperament] ?? $default;
        return $set[$tier] ?? null;
    }

    // =========================================================================
    // EFFECTIVE DISPOSITION
    // =========================================================================

    /**
     * Calculate effective sex_disposal with passion and jealousy overlay.
     */
    public static function getEffectiveDisposition($baseDisposal, $dynamics)
    {
        $passion = floatval($dynamics['passion'] ?? 0);
        $jealousy = floatval($dynamics['jealousy_anger'] ?? 0);

        $effective = $baseDisposal + ($passion * 0.3) - ($jealousy * 0.3);
        return max(0, min(30, intval(round($effective))));
    }

    /**
     * Apply friendzone sex_disposal cap (PR 12).
     * Returns disposition capped at 15 if relationship type is friendzone.
     */
    public static function applyFriendzoneSexCap(string $npcName, array $dynamics, float $disposition): float
    {
        $relType = self::getRelationshipType($npcName, $dynamics);
        if ($relType === 'friendzone') {
            return min($disposition, 15.0);
        }
        return $disposition;
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    public static function log($message)
    {
        $cfg = self::getConfig();
        if (!empty($cfg['log_enabled'])) {
            error_log("[RelDyn] " . $message);
        }
    }

    /**
     * Log a caught error. Always on: not gated by log_enabled (that is the debug log),
     * so a failure the code recovers from is still visible in the server log.
     */
    public static function logError(string $where, \Throwable $e): void
    {
        error_log("[RelDyn] ERROR {$where}: " . get_class($e) . ': ' . $e->getMessage());
    }

    /**
     * Escape LIKE wildcards (% _ and the escape character itself) so a name matches literally.
     * Use with ESCAPE '\' in the query; apply this first, then the database's own quoting.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Get a human-readable passion band label.
     */
    public static function getPassionBand($passion)
    {
        if ($passion >= 80) return 'burning';
        if ($passion >= 60) return 'intense';
        if ($passion >= 40) return 'warm';
        if ($passion >= 20) return 'stirring';
        if ($passion > 0)   return 'faint';
        return 'none';
    }

    /**
     * Get a human-readable jealousy band label.
     */
    public static function getJealousyBand($jealousy)
    {
        if ($jealousy >= 80) return 'seething';
        if ($jealousy >= 60) return 'hurt';
        if ($jealousy >= 40) return 'unsettled';
        if ($jealousy >= 20) return 'edgy';
        return 'none';
    }

    // =========================================================================
    // CACHE MANAGEMENT
    // =========================================================================

    /**
     * Kept for callers from before A3: NPC state is no longer cached (every getDynamics()
     * reads the database), so there is nothing to clear.
     */
    public static function clearNpcCache($npcName = null)
    {
    }

    // =========================================================================
    // VECTOR OPERATIONS
    // =========================================================================

    public static function cosineSimilarity($vecA, $vecB)
    {
        if (empty($vecA) || empty($vecB)) return 0.0;
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($vecA), count($vecB));
        for ($i = 0; $i < $len; $i++) {
            $dot += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }
        $denom = sqrt($normA) * sqrt($normB);
        return ($denom > 0) ? ($dot / $denom) : 0.0;
    }

    // =========================================================================
    // COMBAT SYSTEM
    // =========================================================================

    /** Request types that are combat events in CHIM 3.4.1 core (Plugin.cpp / main.php). */
    const CORE_COMBAT_REQUEST_TYPES = ['radiantcombatfriend', 'combatend', 'combatendmighty', 'death', 'bleedout'];

    /**
     * Fight around the player from CHIM 3.4.1 core eventlog rows (no MinAI):
     *   infoaction "... (X shouts during combat)"  core logs each combatbark this way (main.php)
     *   death "X has defeated Y ..."               a kill in the player's scene
     *   bleedout "X falls to the ground almost unconscious" / instruction "X has lost combat
     *   and is wounded bleedingout."                 X is down
     *   combatend / combatendmighty                the player's fight is over
     * RelDyn's hooks run for the NPC in the player's scene, so a fight there is its fight.
     *
     * in_combat:    the newest combat row is a fighting row (not a combat end) and is within
     *               COMBAT_ACTIVE_WINDOW_GAMETS (1 min of real play on the game clock)
     * recent:       any combat row within COMBAT_KILL_STREAK_WINDOW_GAMETS (5 min of play)
     * bleeding_out: this NPC went down (bleedout / lost combat) since the last combat end,
     *               within the active window
     *
     * @return array{in_combat: bool, recent: bool, bleeding_out: bool, source: string}
     */
    public static function coreCombatState(string $npcName): array
    {
        $state = ['in_combat' => false, 'recent' => false, 'bleeding_out' => false, 'source' => 'none'];
        $db = $GLOBALS['db'] ?? null;
        $now = self::currentGamets();
        if (!$db || $now <= 0 || trim($npcName) === '') return $state;

        $since = intval($now - self::COMBAT_KILL_STREAK_WINDOW_GAMETS);
        try {
            $rows = $db->fetchAll("SELECT type, data, gamets FROM eventlog WHERE gamets > {$since} AND ("
                . "type IN ('combatend', 'combatendmighty', 'death', 'bleedout') "
                . "OR (type = 'infoaction' AND data LIKE '%shouts during combat%') "
                . "OR (type = 'instruction' AND data LIKE '%has lost combat and is wounded bleedingout%')"
                . ") ORDER BY gamets DESC, ts DESC LIMIT 20");
        } catch (\Throwable $e) {
            self::logError('coreCombatState', $e);
            return $state;
        }
        if (!is_array($rows) || empty($rows)) return $state;

        $state['recent'] = true;
        $state['source'] = 'eventlog';
        $activeSince = $now - self::COMBAT_ACTIVE_WINDOW_GAMETS;
        $name = trim($npcName);
        foreach ($rows as $i => $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type === 'combatend' || $type === 'combatendmighty') break;   // fight over
            if (floatval($row['gamets'] ?? 0) <= $activeSince) break;           // too old to be now
            if ($i === 0) $state['in_combat'] = true;
            $data = (string) ($row['data'] ?? '');
            if (($type === 'bleedout' && stripos($data, $name . ' falls to the ground') !== false)
                || ($type === 'instruction' && stripos($data, $name . ' has lost combat') !== false)) {
                $state['bleeding_out'] = true;
            }
        }
        return $state;
    }

    public static function getCombatContext($npcName)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;

            // Core 3.4.1 combat events (coreCombatState). Health is not reported by core for
            // NPCs: null = unknown (never assumed healthy or hurt).
            $state = self::coreCombatState($npcName);
            $inCombat = $state['in_combat'];
            $healthPct = null;
            $bleedingOut = $state['bleeding_out'];
            $recentKills = 0;
            $source = $state['source'];

            // The current request is itself a combat event
            $reqType = $GLOBALS['gameRequest'][0] ?? '';
            if (in_array($reqType, self::CORE_COMBAT_REQUEST_TYPES, true)) {
                $inCombat = true;
                $source = 'event';
            }

            // Count recent kills from eventlog (last 5 minutes of play on the game clock)
            $nowGamets = self::currentGamets();
            if ($nowGamets > 0) {
                $sinceGamets = intval($nowGamets - self::COMBAT_KILL_STREAK_WINDOW_GAMETS);
                try {
                    $namePattern = $db->escape(self::escapeLike($npcName));
                    $rows = $db->fetchAll("SELECT COUNT(*) as cnt FROM eventlog WHERE type = 'death' AND people LIKE '%{$namePattern}%' ESCAPE '\\' AND gamets > {$sinceGamets}");
                    $recentKills = intval($rows[0]['cnt'] ?? 0);
                } catch (\Throwable $e) {
                    self::logError('getCombatContext kill count', $e);
                }
            }

            if (!$inCombat && $recentKills === 0 && !$bleedingOut) return null;

            return [
                'in_combat' => $inCombat,
                'health_pct' => $healthPct,
                'recent_kills' => $recentKills,
                'bleeding_out' => $bleedingOut,
                'source' => $source,
            ];
        } catch (\Throwable $e) {
            self::logError('getCombatContext', $e);
            return null;
        }
    }

    public static function getRecentCombatSummary($npcName)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;

            $player = $GLOBALS['PLAYER_NAME'] ?? 'the player';
            $combatTypes = "'death','bleedout','combatend','combatendmighty'";

            // Check last 10 combat events, filter to recent ones
            $rows = $db->fetchAll(
                "SELECT type, data, people FROM eventlog WHERE type IN ({$combatTypes}) ORDER BY rowid DESC LIMIT 10"
            );

            if (empty($rows)) return null;

            $npcInvolved = false;
            $killCount = 0;
            $wasBleedout = false;
            $sharedCombat = false;

            foreach ($rows as $row) {
                $people = strtolower($row['people'] ?? '');
                $npcLower = strtolower($npcName);
                $playerLower = strtolower($player);

                if (strpos($people, $npcLower) !== false) {
                    $npcInvolved = true;
                    if ($row['type'] === 'death') $killCount++;
                    if ($row['type'] === 'bleedout') $wasBleedout = true;
                }
                if (strpos($people, $playerLower) !== false && strpos($people, $npcLower) !== false) {
                    $sharedCombat = true;
                }
            }

            if (!$npcInvolved && !$sharedCombat) return null;

            // Build narrative
            $parts = [];
            if ($sharedCombat) {
                $parts[] = "Survived combat alongside {$player}. Bond forged under pressure.";
            }
            if ($killCount > 0) {
                $parts[] = "Witnessed {$killCount} kill" . ($killCount > 1 ? 's' : '') . " — the violence is fresh.";
            }
            if ($wasBleedout) {
                $parts[] = "Nearly died in the fighting. The vulnerability lingers.";
            }
            if (empty($parts)) {
                $parts[] = "Recent combat still echoes. Adrenaline fading but the tension remains.";
            }

            return implode(' ', $parts);
        } catch (\Throwable $e) {
            self::logError('getRecentCombatSummary', $e);
            return null;
        }
    }

    // ========== XYZ MIGRATION SHIM (PR 2) ==========
    //
    // The 'dimensions' sub-object lives ALONGSIDE legacy keys (passion,
    // jealousy_anger, etc.) — it does NOT replace them.  Legacy keys remain
    // authoritative for all existing code paths.  The dimensions object
    // mirrors their values today and becomes the future authority once the
    // full XYZ system lands.
    // ==================================================

    /**
     * Ensure a dynamics blob has the 'dimensions' sub-object.
     * Called on every getDynamics() load to migrate old data forward.
     *
     * - If dimensions key is missing, create it from defaultDynamics().
     * - Sync existing legacy values INTO the dimensions object.
     * - Affinity: managed externally (core_npc_master), left at default.
     * - Warmth: stored as curve preset, not numeric — leave derived for now.
     *
     * @param array $dynamics  The loaded dynamics blob
     * @return array            The dynamics blob with dimensions guaranteed
     */
    public static function migrateDimensions($dynamics)
    {
        // Bootstrap: create dimensions from defaults if missing entirely
        if (!isset($dynamics['dimensions']) || !is_array($dynamics['dimensions'])) {
            $dynamics['dimensions'] = self::defaultDynamics()['dimensions'];
        } else {
            // Ensure all dimension keys exist (forward-compat when new dims are added)
            $defaults = self::defaultDynamics()['dimensions'];
            foreach ($defaults as $dim => $shape) {
                if (!isset($dynamics['dimensions'][$dim])) {
                    $dynamics['dimensions'][$dim] = $shape;
                }
            }
        }

        // One source of truth per value: dimensions.passion.x is canonical for passion;
        // jealousy_anger (MDD 6.5) and dimensions.resentment.x (MDD 15.5) are separate.
        // Fresh start: no April-blob conversion, stored values are taken as they are.
        // Legacy mirror is derived from the canonical value, never the other way round.
        $dynamics['passion'] = self::getPassion($dynamics);

        // Affinity: managed by core_npc_master.extended_data.relationships[player].aff
        // — intentionally NOT synced here; affinity.x stays at its current value
        // and will be populated by the affinity bridge in a future PR.

        // Warmth: stored as a curve preset string (slow_burn, etc.), not numeric.
        // warmth.x will be derived by the warmth calculator in a future PR.

        // ========== MATURITY DIMENSION (PR 3) ==========
        // Initialize maturity from temperament baseline if not yet set
        if (($dynamics['dimensions']['maturity']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['maturity']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'maturity');
            $dynamics['dimensions']['maturity']['baseline'] = $dynamics['dimensions']['maturity']['x'];

            // Assign plasticity type from temperament if not already set
            if (empty($dynamics['dimensions']['maturity']['plasticity_type'])) {
                $dynamics['dimensions']['maturity']['plasticity_type'] = RelationshipDynamics::getMaturityPlasticityType($temperament);
            }
        }
        // Ensure plasticity_type is populated even if x was already set (backfill)
        if (empty($dynamics['dimensions']['maturity']['plasticity_type'])) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['maturity']['plasticity_type'] = RelationshipDynamics::getMaturityPlasticityType($temperament);
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['maturity']['active'])) {
            $dynamics['dimensions']['maturity']['active'] = true;
        }


        // ========== TRUST DIMENSION (PR 4) ==========
        // Initialize trust from temperament baseline if not yet set
        if (($dynamics['dimensions']['trust']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['trust']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'trust');
            $dynamics['dimensions']['trust']['baseline'] = $dynamics['dimensions']['trust']['x'];
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['trust']['active'])) {
            $dynamics['dimensions']['trust']['active'] = true;
        }

        // ========== COMFORT DIMENSION (PR 4) ==========
        // Initialize comfort from temperament baseline if not yet set
        if (($dynamics['dimensions']['comfort']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['comfort']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'comfort');
            $dynamics['dimensions']['comfort']['baseline'] = $dynamics['dimensions']['comfort']['x'];
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['comfort']['active'])) {
            $dynamics['dimensions']['comfort']['active'] = true;
        }

        // ========== RESPECT DIMENSION (PR 4) ==========
        // Initialize respect from temperament baseline if not yet set
        if (($dynamics['dimensions']['respect']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['respect']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'respect');
            $dynamics['dimensions']['respect']['baseline'] = $dynamics['dimensions']['respect']['x'];
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['respect']['active'])) {
            $dynamics['dimensions']['respect']['active'] = true;
        }

        // ========== MASCULINE COORDINATE (PR 6) ==========
        // Initialize coord_m from temperament baseline if not yet set
        if (($dynamics['dimensions']['coord_m']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['coord_m']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'coord_m');
            $dynamics['dimensions']['coord_m']['baseline'] = $dynamics['dimensions']['coord_m']['x'];
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['coord_m']['active'])) {
            $dynamics['dimensions']['coord_m']['active'] = true;
        }
        // ========== FEMININE COORDINATE (PR 6) ==========
        // Initialize coord_f from temperament baseline if not yet set
        if (($dynamics['dimensions']['coord_f']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['coord_f']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'coord_f');
            $dynamics['dimensions']['coord_f']['baseline'] = $dynamics['dimensions']['coord_f']['x'];
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['coord_f']['active'])) {
            $dynamics['dimensions']['coord_f']['active'] = true;
        }
        // ========== AROUSAL/VALENCE (PR 6) ==========
        // Arousal -- already initialized at x=0, just ensure active
        if (!isset($dynamics['dimensions']['arousal']['active'])) {
            $dynamics['dimensions']['arousal']['active'] = true;
            $dynamics['dimensions']['arousal']['baseline'] = $dynamics['dimensions']['arousal']['baseline'] ?? 10;
        }
        // Valence -- same
        if (!isset($dynamics['dimensions']['valence']['active'])) {
            $dynamics['dimensions']['valence']['active'] = true;
            $dynamics['dimensions']['valence']['baseline'] = $dynamics['dimensions']['valence']['baseline'] ?? 0;
        }

        // ========== RESENTMENT DIMENSION (PR 7) ==========
        // Ensure resentment is active with proper sub-fields
        if (!isset($dynamics['dimensions']['resentment']['active'])) {
            $dynamics['dimensions']['resentment']['active'] = true;
        }
        // Ensure resentment starts at 0 if null (no temperament baseline needed)
        if ($dynamics['dimensions']['resentment']['x'] === null) {
            $dynamics['dimensions']['resentment']['x'] = 0;
            $dynamics['dimensions']['resentment']['baseline'] = 0;
        }
        // Bootstrap sub-fields for existing data (forward-compat)
        if (!isset($dynamics['dimensions']['resentment']['pending_grievances'])) {
            $dynamics['dimensions']['resentment']['pending_grievances'] = [];
        }
        if (!isset($dynamics['dimensions']['resentment']['grievance_log'])) {
            $dynamics['dimensions']['resentment']['grievance_log'] = [];
        }
        if (!isset($dynamics['dimensions']['resentment']['last_decay_tick'])) {
            $dynamics['dimensions']['resentment']['last_decay_tick'] = 0;
        }

        // ========== RESENTMENT_SELF (PR 7) ==========
        // Self-directed shame: fixed baseline=0, ensure active
        if (!isset($dynamics['dimensions']['resentment_self']['active'])) {
            $dynamics['dimensions']['resentment_self']['active'] = true;
        }
        if (($dynamics['dimensions']['resentment_self']['x'] ?? null) === null) {
            $dynamics['dimensions']['resentment_self']['x'] = 0;
            $dynamics['dimensions']['resentment_self']['baseline'] = 0;
        }


        // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========
        // Initialize self-confidence from temperament baseline if not yet set
        if (($dynamics['dimensions']['self_confidence']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['self_confidence']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'self_confidence');
            $dynamics['dimensions']['self_confidence']['baseline'] = $dynamics['dimensions']['self_confidence']['x'];
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['self_confidence']['active'])) {
            $dynamics['dimensions']['self_confidence']['active'] = true;
        }


        // ========== REPUTATION LAYER (PR 9) ==========
        // Apply reputation modifiers on first load for NPCs with no prior interactions.
        // Reputation is re-evaluated on every load (decay factor changes with interaction count),
        // but raw values are cached after first calculation.
        // The player name is read from GLOBALS if available.
        if (isset($GLOBALS['PLAYER_NAME']) && !empty($GLOBALS['PLAYER_NAME'])) {
            $playerName = $GLOBALS['PLAYER_NAME'];
            // We need the NPC name -- it's not passed to migrateDimensions,
            // so we store it as a hint in the dynamics blob for the reputation system.
            // The actual call is deferred to getDynamics() which has the NPC name.
            // Here we just ensure the reputation sub-fields exist.
            if (!isset($dynamics['_reputation_applied'])) {
                $dynamics['_reputation_applied'] = false;
            }
            if (!isset($dynamics['_reputation_raw'])) {
                $dynamics['_reputation_raw'] = [];
            }
            if (!isset($dynamics['_reputation_effective'])) {
                $dynamics['_reputation_effective'] = ['trust' => 0, 'respect' => 0, 'comfort' => 0];
            }
        }

        // ========== PR 10 MIGRATION ==========
        $dynamics = self::migratePR10($dynamics);

        // ========== PR 11 MIGRATION ==========
        $dynamics = self::migratePR11($dynamics);

        // ========== PR 12 MIGRATION ==========
        $dynamics = self::migratePR12($dynamics);

        // ========== PR 13 MIGRATION ==========
        $dynamics = self::migratePR13($dynamics);

        // ========== PR 14 MIGRATION ==========
        $dynamics = self::migratePR14($dynamics);

        return $dynamics;
    }

    /**
     * PR 10 migration: initialize new fields if missing.
     */
    public static function migratePR10($dynamics)
    {
        $pr10Fields = [
            '_divine_intervention_last' => 0,
            '_divine_intervention_count' => 0,
            '_divine_intervention_last_type' => null,
            '_unstable_window' => null,
            '_grief_bonds' => [],
            '_widow_lock_ceiling' => 100,
            '_plasticity_override' => null,
            '_plasticity_override_start_gamets' => 0,
            '_plasticity_override_expires_gamets' => 0,
            'attachment_style' => null,
            '_attachment_shift_available' => false,
            '_attachment_drift_last_check' => 0,
            '_attachment_drift_score' => 0,
        ];

        foreach ($pr10Fields as $key => $default) {
            if (!array_key_exists($key, $dynamics)) {
                $dynamics[$key] = $default;
            }
        }

        return $dynamics;
    }

    /**
     * PR 11 migration: initialize attraction matrix fields if missing.
     */
    public static function migratePR11($dynamics)
    {
        $pr11Fields = [
            'attraction_profile' => null,
            '_attraction_matrix_cache' => null,
            '_attraction_matrix_last_eval' => 0,
            '_attraction_tier_ceiling' => 'sworn',
            '_attraction_friendzoned' => false,
            '_attraction_passion_mult' => 1.0,
        ];

        foreach ($pr11Fields as $key => $default) {
            if (!array_key_exists($key, $dynamics)) {
                $dynamics[$key] = $default;
            }
        }

        return $dynamics;
    }

    /**
     * PR 12 migration: initialize interaction pattern and relationship type fields if missing.
     */
    public static function migratePR12($dynamics)
    {
        $pr12Fields = [
            '_interaction_pattern' => [
                'gift_count' => 0, 'genuine_count' => 0,
                'total_window' => 0, 'window_start' => 0,
                'last_interaction_type' => null,
            ],
            '_relationship_type_override' => null,
            '_relationship_type_history' => [],
        ];

        foreach ($pr12Fields as $key => $default) {
            if (!array_key_exists($key, $dynamics)) {
                $dynamics[$key] = $default;
            }
        }

        return $dynamics;
    }

    /**
     * PR 13 migration: initialize environmental quirks fields if missing.
     */
    public static function migratePR13($dynamics)
    {
        $pr13Fields = [
            '_baseline_drift_samples' => [],
            '_interest_satisfaction' => [],
            '_interest_last_satisfied' => [],
            '_internal_weather' => 'clear',
            '_intimacy_last_satisfied' => 0,
            'creature_type' => null,
        ];

        foreach ($pr13Fields as $key => $default) {
            if (!array_key_exists($key, $dynamics)) {
                $dynamics[$key] = $default;
            }
        }

        return $dynamics;
    }

    /**
     * PR 14 migration: initialize social masking + autonomous diary fields if missing.
     */
    public static function migratePR14($dynamics)
    {
        $pr14Fields = [
            '_was_masking' => false,
            '_mask_interactions_count' => 0,
            '_performed_state_cache' => null,
            '_diary_last_interaction' => 0,
            '_diary_last_di_count' => 0,
            '_diary_last_attachment' => null,
            '_diary_last_emotions' => [],
            '_diary_last_tier_ceiling' => 'sworn',
            '_diary_last_grief_phases' => [],
            '_diary_pending_triggers' => [],
            '_diary_trigger_source' => null,
        ];
        foreach ($pr14Fields as $key => $default) {
            if (!array_key_exists($key, $dynamics)) {
                $dynamics[$key] = $default;
            }
        }
        return $dynamics;
    }

    /**
     * Sync legacy flat keys FROM the dimensions sub-object.
     * Called on every saveDynamics() to keep legacy keys in sync when new
     * code writes to dimensions directly.
     *
     * Guarded: if dimensions is missing or a dimension.x is null, the
     * legacy key is left untouched (null = 'not yet active').
     *
     * @param array $dynamics  The dynamics blob about to be saved
     * @return array            The dynamics blob with legacy keys synced
     */
    public static function syncLegacyFromDimensions($dynamics)
    {
        if (!isset($dynamics['dimensions']) || !is_array($dynamics['dimensions'])) {
            return $dynamics;
        }

        $dim = $dynamics['dimensions'];

        // passion mirror ← dimensions.passion.x (canonical, written only via setPassion)
        if (isset($dim['passion']['x']) && $dim['passion']['x'] !== null) {
            $dynamics['passion'] = floatval($dim['passion']['x']);
        }

        // jealousy_anger is its own value (written via setJealousy) and resentment lives
        // only in dimensions.resentment.x — no mirror between them.

        // Future syncs (arousal, valence, etc.) will be added as those
        // dimensions become active in later PRs.

        return $dynamics;
    }

    // ========== END XYZ MIGRATION SHIM ==========

    // =========================================================================
    // TYPE CONSTRAINT FUNCTIONS
    // =========================================================================

    public static function getBlockedTypes($dynamics, $chimAffinity = null)
    {
        $pref = strtolower(trim($dynamics['relationship_preference'] ?? ''));
        if (empty($pref) || $pref === 'default') return [];

        $blocked = [];
        // Use CHIM affinity if provided (from relationship_system), fall back to dynamics blob
        $aff = ($chimAffinity !== null) ? floatval($chimAffinity) : floatval($dynamics['affinity'] ?? 0);

        switch ($pref) {
            case 'demisexual':
                if ($aff < 60) $blocked[] = 'romantic';
                if ($aff < 80) $blocked[] = 'committed';
                $blocked[] = 'sworn'; // always requires deep bond
                break;
            case 'asexual':
                $blocked[] = 'romantic';
                $blocked[] = 'committed';
                $blocked[] = 'sworn';
                break;
            case 'aromantic':
                $blocked[] = 'romantic';
                $blocked[] = 'committed';
                $blocked[] = 'sworn';
                $blocked[] = 'crush';
                break;
        }

        return $blocked;
    }

    public static function getTypeConstraintPrompt($dynamics, $npcName, $chimAffinity = null)
    {
        $pref = strtolower(trim($dynamics['relationship_preference'] ?? ''));
        if (empty($pref) || $pref === 'default') return '';

        $blocked = self::getBlockedTypes($dynamics, $chimAffinity);
        if (empty($blocked)) return '';

        $prompts = [
            'demisexual' => "{$npcName} forms deep bonds slowly — romantic connection requires genuine trust built over time. Physical intimacy without emotional foundation feels wrong to them.",
            'asexual'    => "{$npcName} does not experience sexual attraction. Deep emotional bonds are possible, but physical intimacy is not something they seek or welcome.",
            'aromantic'  => "{$npcName} does not experience romantic attraction. They can form deep platonic bonds and loyal friendships, but romantic framing feels foreign and uncomfortable.",
        ];

        return $prompts[$pref] ?? '';
    }

    // [CHUNK3-BANDS-START]
    // ========== XYZ BAND KEYWORD SYSTEM (PR 2) ==========

    /**
     * Band definitions for all 11 dimensions.
     * Each dimension maps to ordered bands with [min, max] ranges and keyword strings.
     * Keywords are injected verbatim into the NPC's LLM prompt — do not paraphrase.
     *
     * Special dimensions (mf, arousal_valence) use separate helper methods
     * because they combine two axes into a single behavioral descriptor.
     */
    const DIMENSION_BANDS = [
        'affinity' => [
            ['label' => 'Hostile',  'range' => [0, 10],   'keywords' => 'despises, hostile, seeks to avoid, contemptuous'],
            ['label' => 'Cold',     'range' => [11, 25],  'keywords' => 'dismissive, indifferent, curt, keeps distance'],
            ['label' => 'Neutral',  'range' => [26, 40],  'keywords' => 'polite, professional, reserved, no strong feelings'],
            ['label' => 'Warm',     'range' => [41, 55],  'keywords' => 'friendly, approachable, enjoys company, mild fondness'],
            ['label' => 'Fond',     'range' => [56, 70],  'keywords' => 'genuinely likes, seeks out conversation, protective instinct'],
            ['label' => 'Close',    'range' => [71, 85],  'keywords' => 'deeply bonded, confides freely, prioritizes this person'],
            ['label' => 'Devoted',  'range' => [86, 100], 'keywords' => 'unshakable loyalty, would sacrifice for, profound connection'],
        ],
        'passion' => [
            ['label' => 'Cold',     'range' => [0, 15],   'keywords' => 'emotionally flat, going through the motions, disengaged'],
            ['label' => 'Tepid',    'range' => [16, 35],  'keywords' => 'mild interest, slightly warmed, casually engaged'],
            ['label' => 'Warm',     'range' => [36, 55],  'keywords' => 'noticeably engaged, laughs easier, steals glances'],
            ['label' => 'Heated',   'range' => [56, 75],  'keywords' => 'flushed, heightened awareness, charged silences, leaning in'],
            ['label' => 'Burning',  'range' => [76, 90],  'keywords' => 'electric tension, can barely focus, pulse racing, magnetic pull'],
            ['label' => 'Redline',  'range' => [91, 100], 'keywords' => 'overwhelming desire, trembling restraint, all-consuming focus'],
        ],
        'warmth' => [
            ['label' => 'Walled',      'range' => [0, 20],   'keywords' => 'closed off, monosyllabic, avoids eye contact, physically distant'],
            ['label' => 'Guarded',     'range' => [21, 40],  'keywords' => 'polite but measured, reveals nothing personal, formal tone'],
            ['label' => 'Cautious',    'range' => [41, 55],  'keywords' => 'slightly more open, occasional genuine smile, testing the waters'],
            ['label' => 'Comfortable', 'range' => [56, 70],  'keywords' => 'relaxed posture, shares opinions freely, comfortable silences'],
            ['label' => 'Open',        'range' => [71, 85],  'keywords' => 'emotionally available, initiates vulnerability, laughs freely'],
            ['label' => 'Intimate',    'range' => [86, 100], 'keywords' => 'completely unguarded, shares fears and hopes, physical ease'],
        ],
        'maturity' => [
            ['label' => 'Chaotic',    'range' => [0, 20],   'keywords' => 'impulsive, reactive, no emotional regulation, tantrum-prone, confuses intensity for depth'],
            ['label' => 'Immature',   'range' => [21, 40],  'keywords' => 'avoidant of hard conversations, deflects with humor or anger, blames others'],
            ['label' => 'Developing', 'range' => [41, 55],  'keywords' => 'starting to recognize patterns, occasionally self-aware, inconsistent follow-through'],
            ['label' => 'Grounded',   'range' => [56, 70],  'keywords' => 'communicates directly most of the time, handles conflict with measured responses'],
            ['label' => 'Mature',     'range' => [71, 85],  'keywords' => "emotionally intelligent, holds space for others' feelings, secure in self"],
            ['label' => 'Wise',       'range' => [86, 100], 'keywords' => 'mentors others naturally, transforms conflict into growth, deep self-knowledge'],
        ],
        'trust' => [
            ['label' => 'Distrustful', 'range' => [0, 15],   'keywords' => 'suspicious, watches for deception, guards secrets, expects betrayal'],
            ['label' => 'Wary',        'range' => [16, 35],  'keywords' => 'cautious, tests intentions, reveals little, hedges commitments'],
            ['label' => 'Uncertain',   'range' => [36, 50],  'keywords' => 'wants to trust but hesitates, occasionally takes small risks'],
            ['label' => 'Established', 'range' => [51, 70],  'keywords' => 'confides personal matters, relies on in danger, assumes good intent'],
            ['label' => 'Deep',        'range' => [71, 85],  'keywords' => 'unquestioning faith in intent, shares fears, depends on emotionally'],
            ['label' => 'Absolute',    'range' => [86, 100], 'keywords' => 'would trust with life, shares everything, no secrets, complete faith'],
        ],
        'comfort' => [
            ['label' => 'Tense',    'range' => [0, 20],   'keywords' => 'stiff, formal, chooses words carefully, on guard, physically distant'],
            ['label' => 'Uneasy',   'range' => [21, 40],  'keywords' => 'polite but measured, avoids prolonged interaction, escapes when possible'],
            ['label' => 'Neutral',  'range' => [41, 55],  'keywords' => 'neither relaxed nor tense, socially appropriate, surface-level pleasant'],
            ['label' => 'At ease',  'range' => [56, 70],  'keywords' => 'genuine smiles, drops formality, comfortable silences, relaxed posture'],
            ['label' => 'Familiar', 'range' => [71, 85],  'keywords' => 'teases freely, shares embarrassing stories, casual physical contact'],
            ['label' => 'Home',     'range' => [86, 100], 'keywords' => 'completely unmasked, messy and real, falls asleep around you, no performance'],
        ],
        'respect' => [
            ['label' => 'Contempt',      'range' => [0, 15],   'keywords' => 'looks down on, dismisses input, barely tolerates, openly mocking'],
            ['label' => 'Unimpressed',   'range' => [16, 35],  'keywords' => "skeptical of ability, doesn't seek opinion, humors but ignores"],
            ['label' => 'Neutral',       'range' => [36, 50],  'keywords' => 'acknowledges existence, no strong feelings about capability'],
            ['label' => 'Appreciates',   'range' => [51, 65],  'keywords' => 'values input on known strengths, defers in their domain'],
            ['label' => 'Admires',       'range' => [66, 80],  'keywords' => 'seeks counsel, brags about to others, takes lead from in key areas'],
            ['label' => 'Reveres',       'range' => [81, 100], 'keywords' => 'considers a role model, aspires to match, deepest professional regard'],
        ],
        'resentment' => [
            ['label' => 'Clean',       'range' => [0, 15],   'keywords' => ''],
            ['label' => 'Simmering',   'range' => [16, 30],  'keywords' => 'occasionally bites tongue, small things bother more than they should'],
            ['label' => 'Edged',       'range' => [31, 50],  'keywords' => 'passive-aggressive edge creeping in, sighs instead of speaking up, shorter patience'],
            ['label' => 'Frustrated',  'range' => [51, 70],  'keywords' => 'visibly frustrated, withdrawing emotionally, affinity gains frozen'],
            ['label' => 'Withdrawn',   'range' => [71, 90],  'keywords' => 'cold, distant, stopped trying, no longer at ease around them, considering leaving'],
            ['label' => 'Done',        'range' => [91, 100], 'keywords' => 'walkaway imminent, done, emotionally checked out'],
        ],
        'self_confidence' => [
            ['label' => 'Hollow',     'range' => [0, 15],   'keywords' => "cannot make decisions alone, paralyzed without validation, defines self entirely through others' eyes"],
            ['label' => 'Dependent',  'range' => [16, 30],  'keywords' => 'seeks validation before acting, offloads self-assessment to trusted others'],
            ['label' => 'Uncertain',  'range' => [31, 45],  'keywords' => 'functional but shaky, second-guesses after deciding, compares self to others'],
            ['label' => 'Grounded',   'range' => [46, 60],  'keywords' => 'generally trusts own judgment, checks with others after not before'],
            ['label' => 'Assured',    'range' => [61, 75],  'keywords' => 'generates internal assessment confidently, external input appreciated but not required'],
            ['label' => 'Sovereign',  'range' => [76, 90],  'keywords' => 'fully self-directed, creates own value framework, quiet certainty'],
            ['label' => 'Ubermensch', 'range' => [91, 100], 'keywords' => 'absolute internal authority, unmoved by external assessment'],
        ],
        // ========== RESENTMENT_SELF (PR 7) ==========
        'resentment_self' => [
            ['label' => 'Clean',        'range' => [0, 15],   'keywords' => ''],
            ['label' => 'Self-doubt',   'range' => [16, 30],  'keywords' => 'quiet self-doubt, replays mistakes, harder on self than others'],
            ['label' => 'Self-critical', 'range' => [31, 50],  'keywords' => "visible self-criticism, apologizes for things that aren't their fault, shrinking"],
            ['label' => 'Withdrawing',  'range' => [51, 70],  'keywords' => "withdrawing from everyone, can't accept compliments, punishing self"],
            ['label' => 'Shutdown',     'range' => [71, 90],  'keywords' => "emotional shutdown, believes they deserve bad things, can't look people in the eye"],
            ['label' => 'Crisis',       'range' => [91, 100], 'keywords' => 'self-loathing crisis, isolating completely, considering drastic action'],
        ],
    ];

    /**
     * M/F quadrant keyword definitions.
     * Quadrant is determined by the sign of M and F axis values.
     * Threshold: 0 (positive = > 0, negative = <= 0).
     */
    const MF_QUADRANT_BANDS = [
        '+M/+F' => [
            'label'    => 'Protective Warmth',
            'keywords' => 'protective warmth, steady presence, nurturing strength, calm authority',
        ],
        '+M/-F' => [
            'label'    => 'Stoic Distance',
            'keywords' => 'stoic distance, dutiful but cold, suppressed emotion, ice wall',
        ],
        '-M/+F' => [
            'label'    => 'Soft Vulnerability',
            'keywords' => 'soft vulnerability, yielding, passive, needs reassurance, pleading',
        ],
        '-M/-F' => [
            'label'    => 'Bitter Withdrawal',
            'keywords' => 'bitter, resentful, passive-aggressive, manipulative, withdrawing',
        ],
    ];

    /**
     * Arousal/Valence combination keyword definitions.
     * Thresholds: Arousal > 50 = High, <= 50 = Low; Valence > 0 = Positive, <= 0 = Negative.
     */
    const AROUSAL_VALENCE_BANDS = [
        'high_positive' => [
            'label'    => 'Electrified',
            'keywords' => 'thrilled, adrenaline high, grinning, alive',
        ],
        'high_negative' => [
            'label'    => 'Panicked',
            'keywords' => 'panicked, desperate, heart pounding, fight-or-flight',
        ],
        'low_positive' => [
            'label'    => 'Content',
            'keywords' => 'content, peaceful, warm glow, satisfied',
        ],
        'low_negative' => [
            'label'    => 'Numb',
            'keywords' => 'numb, hollow, empty, dissociated, flatlined',
        ],
    ];

    /**
     * Human-readable display labels for dimension IDs.
     */
    const DIMENSION_LABELS = [
        'affinity'        => 'Affinity',
        'passion'         => 'Passion',
        'warmth'          => 'Warmth',
        'maturity'        => 'Maturity',
        'trust'           => 'Trust',
        'comfort'         => 'Comfort',
        'respect'         => 'Respect',
        'resentment'      => 'Resentment',
        'resentment_self' => 'Resentment (Self)',
        'self_confidence' => 'Self-Confidence',
        'coord_m'         => 'M/F Behavioral Mode',
        'coord_f'         => 'M/F Behavioral Mode',
        'arousal'         => 'Arousal/Valence',
        'valence'         => 'Arousal/Valence',
    ];

    /**
     * Get the band for a standard dimension given its ID and current X value.
     *
     * For M/F coordinates, use getMFQuadrantBand() instead.
     * For Arousal/Valence, use getArousalValenceBand() instead.
     *
     * @param string $dimensionId  One of the keys in DIMENSION_BANDS
     * @param float  $xValue       Current X value for the dimension
     * @return array|null  ['label' => string, 'keywords' => string, 'range' => [int, int]] or null if not found
     */
    public static function getDimensionBand($dimensionId, $xValue)
    {
        if (!isset(self::DIMENSION_BANDS[$dimensionId])) {
            return null;
        }

        $x = round(floatval($xValue));
        $bands = self::DIMENSION_BANDS[$dimensionId];

        foreach ($bands as $band) {
            if ($x >= $band['range'][0] && $x <= $band['range'][1]) {
                return $band;
            }
        }

        // Edge case: value outside defined ranges — clamp to nearest band
        if ($x < $bands[0]['range'][0]) {
            return $bands[0];
        }
        return $bands[count($bands) - 1];
    }

    /**
     * Get the M/F quadrant band from masculine and feminine axis values.
     *
     * Quadrant is determined by sign: positive = > 0, negative/zero = <= 0.
     *
     * @param float $mValue  Current masculine axis X value (-100 to +100)
     * @param float $fValue  Current feminine axis X value (-100 to +100)
     * @return array  ['label' => string, 'keywords' => string, 'quadrant' => string]
     */
    public static function getMFQuadrantBand($mValue, $fValue)
    {
        $m = floatval($mValue);
        $f = floatval($fValue);

        $quadrant = ($m > 0 ? '+M' : '-M') . '/' . ($f > 0 ? '+F' : '-F');
        $band = self::MF_QUADRANT_BANDS[$quadrant];

        return [
            'label'    => $band['label'],
            'keywords' => $band['keywords'],
            'quadrant' => $quadrant,
        ];
    }

    /**
     * Get the arousal/valence combination band.
     *
     * Thresholds: Arousal > 50 = High, <= 50 = Low; Valence > 0 = Positive, <= 0 = Negative.
     *
     * @param float $arousal  Current arousal X value (0-100)
     * @param float $valence  Current valence X value (-100 to +100)
     * @return array  ['label' => string, 'keywords' => string, 'arousal_level' => string, 'valence_level' => string]
     */
    public static function getArousalValenceBand($arousal, $valence)
    {
        $a = floatval($arousal);
        $v = floatval($valence);

        // Neutral zone: both near baseline → "Settled" (not "Numb")
        if ($a <= 25 && abs($v) <= 15) {
            return [
                'label'         => 'Settled',
                'keywords'      => 'calm, composed, at ease, even-tempered',
                'arousal_level' => 'low',
                'valence_level' => 'neutral',
            ];
        }

        $arousalLevel = $a > 50 ? 'high' : 'low';
        $valenceLevel = $v > 0 ? 'positive' : 'negative';

        $key = $arousalLevel . '_' . $valenceLevel;
        $band = self::AROUSAL_VALENCE_BANDS[$key];

        return [
            'label'         => $band['label'],
            'keywords'      => $band['keywords'],
            'arousal_level' => $arousalLevel,
            'valence_level' => $valenceLevel,
        ];
    }

    /**
     * Build a formatted context block of all active dimension bands for LLM injection.
     *
     * Iterates the dynamics['dimensions'] sub-object. For each dimension where
     * x is not null and not at default baseline, gets the band and formats it
     * as a narrative line. Special-cases M/F and Arousal/Valence combos.
     *
     * Resentment is injected as NPC behavioral instruction, NOT labeled as
     * a visible emotional state (the player should never know the number).
     *
     * @param array  $dynamics    Full dynamics blob (must contain 'dimensions' key)
     * @param string $npcName     NPC display name
     * @param string $playerName  Player display name
     * @return string  Formatted block for LLM context, or empty string if no active dimensions
     */
    public static function buildDimensionContext($dynamics, $npcName, $playerName)
    {
        if (empty($dynamics['dimensions']) || !is_array($dynamics['dimensions'])) {
            return '';
        }

        $dims = $dynamics['dimensions'];
        $lines = [];

        // Track whether we have already handled M/F and Arousal/Valence
        // (they combine two sub-dimensions into one line each)
        $mfHandled = false;
        $avHandled = false;

        foreach ($dims as $dimId => $dimData) {
            // Skip inactive dimensions (x is null)
            if (!isset($dimData['x']) || $dimData['x'] === null) {
                continue;
            }

            $x = floatval($dimData['x']);

            // --- M/F Coordinates: combine coord_m + coord_f into one quadrant line ---
            if ($dimId === 'coord_m' || $dimId === 'coord_f') {
                if ($mfHandled) {
                    continue;
                }
                $mfHandled = true;

                $mData = $dims['coord_m'] ?? null;
                $fData = $dims['coord_f'] ?? null;

                if ($mData === null || !isset($mData['x']) || $mData['x'] === null
                    || $fData === null || !isset($fData['x']) || $fData['x'] === null) {
                    continue;
                }

                $band = self::getMFQuadrantBand($mData['x'], $fData['x']);
                $lines[] = "Behavioral Mode: {$band['label']} — {$band['keywords']}";
                continue;
            }

            // --- Arousal/Valence: combine arousal + valence into one combo line ---
            if ($dimId === 'arousal' || $dimId === 'valence') {
                if ($avHandled) {
                    continue;
                }
                $avHandled = true;

                $aData = $dims['arousal'] ?? null;
                $vData = $dims['valence'] ?? null;

                if ($aData === null || !isset($aData['x']) || $aData['x'] === null
                    || $vData === null || !isset($vData['x']) || $vData['x'] === null) {
                    continue;
                }

                // Inject if EITHER arousal is above resting baseline (10)
                // OR valence is significantly displaced from baseline (0)
                $arousalDisplaced = floatval($aData['x']) > 10;
                $valenceDisplaced = abs(floatval($vData['x'])) > 15;
                if (!$arousalDisplaced && !$valenceDisplaced) {
                    continue;
                }

                $band = self::getArousalValenceBand($aData['x'], $vData['x']);
                $lines[] = "Emotional State: {$band['label']} — {$band['keywords']}";
                continue;
            }

            // --- Standard single-axis dimensions ---
            $band = self::getDimensionBand($dimId, $x);
            if ($band === null) {
                continue;
            }

            // Skip resentment / resentment_self clean-slate band (0-15, no keywords)
            if (($dimId === 'resentment' || $dimId === 'resentment_self') && empty($band['keywords'])) {
                continue;
            }

            $label = self::DIMENSION_LABELS[$dimId] ?? ucfirst(str_replace('_', ' ', $dimId));

            // Resentment is injected as hidden behavioral instruction
            if ($dimId === 'resentment') {
                $lines[] = "{$npcName} internal state (not visible to {$playerName}): {$band['keywords']}";
            // ========== RESENTMENT_SELF (PR 7) ==========
            // Self-directed shame: hidden behavioral instruction affecting all bonds
            } elseif ($dimId === 'resentment_self') {
                $lines[] = "{$npcName} self-directed shame (not visible to {$playerName}): {$band['keywords']}";
            } else {
                $lines[] = "{$label}: {$band['label']} — {$band['keywords']}";
            }
        }

        if (empty($lines)) {
            return '';
        }

        // --- Text Intensity post-processing ---
        // Transform each keyword line through the intensity engine at render time.
        // Only applies if arousal, passion, or maturity have non-default values.
        $intensityActive = false;
        $dims = $dynamics['dimensions'] ?? [];
        $iArousal  = floatval($dims['arousal']['x'] ?? 10);
        $iPassion  = floatval($dims['passion']['x'] ?? 0);
        $iMaturity = floatval($dims['maturity']['x'] ?? 60);
        if ($iArousal > 10 || $iPassion > 15 || $iMaturity < 56 || ($iArousal < 10 && $iPassion < 10)) {
            $intensityActive = true;
        }

        if ($intensityActive) {
            foreach ($lines as &$line) {
                // Extract the keywords portion after the em-dash or colon
                // Pattern: "Label: Band â keywords" or "NPC internal state (...): keywords"
                if (preg_match('/^(.+?\xe2\x80\x94\s*)(.+)$/', $line, $m)) {
                    $m[2] = self::applyTextIntensity($m[2], $dynamics);
                    $line = $m[1] . $m[2];
                } elseif (preg_match('/^(.+?\):\s*)(.+)$/', $line, $m)) {
                    // Resentment format: "NPC internal state (not visible to Player): keywords"
                    $m[2] = self::applyTextIntensity($m[2], $dynamics);
                    $line = $m[1] . $m[2];
                }
            }
            unset($line);
        }

        return implode("\n", $lines);
    }

    // [CHUNK3-BANDS-END]


    // ========== XYZ DIMENSION ENGINE (PR 2) ==========
    //
    // Universal (X, Y, Z) coordinate system for all RelDyn dimensions.
    //   X = current value
    //   Y = resistance to change (temperament-derived, asymmetric up/down)
    //   Z = rubber band scale (hyperbolic decay from baseline)
    //
    // Formula: actual_delta = rawDelta * Y_effective * Z_decay
    // All methods are pure math -- no database calls.
    // ======================================================

    /** Maximum acceleration toward baseline (caps recovery boost) */
    const XYZ_MAX_TOWARD_MULT = 3.0;

    /**
     * Static dimension definitions: range, default baseline, default Z, flags.
     * "baseline varies" dimensions use getTemperamentBaseline() for the default.
     */
    const DIMENSION_DEFS = [
        'affinity' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 25, 'default_Z' => 25,
            'flags' => [],
        ],
        'passion' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 0, 'default_Z' => 10,
            'flags' => [],
        ],
        'warmth' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 35, 'default_Z' => 20,
            'flags' => [],
        ],
        'maturity' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 50, 'default_Z' => 20,
            'flags' => [],
        ],
        'coord_m' => [
            'range_min' => -100, 'range_max' => 100,
            'default_baseline' => 0, 'default_Z' => 15,
            'flags' => [],
        ],
        'coord_f' => [
            'range_min' => -100, 'range_max' => 100,
            'default_baseline' => 0, 'default_Z' => 15,
            'flags' => [],
        ],
        'arousal' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 10, 'default_Z' => 8,
            'flags' => [],
        ],
        'valence' => [
            'range_min' => -100, 'range_max' => 100,
            'default_baseline' => 0, 'default_Z' => 12,
            'flags' => [],
        ],
        'trust' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 35, 'default_Z' => 30,
            'flags' => [],
        ],
        'comfort' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 35, 'default_Z' => 20,
            'flags' => [],
        ],
        'respect' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 40, 'default_Z' => 25,
            'flags' => [],
        ],
        'resentment' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 0, 'default_Z' => 8,
            'flags' => ['invert_rubber_band'],
        ],
        // ========== RESENTMENT_SELF (PR 7) ==========
        'resentment_self' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 0, 'default_Z' => 8,
            'flags' => ['invert_rubber_band'],
        ],
        'self_confidence' => [
            'range_min' => 0, 'range_max' => 100,
            'default_baseline' => 45, 'default_Z' => 25,
            'flags' => [],
        ],
    ];

    /**
     * Temperament baseline X values per dimension.
     * Only dimensions whose baseline varies by temperament are listed.
     * Passion, arousal, valence, resentment, and resentment_self have fixed baselines
     * (0, 10, 0, 0 respectively) that don't vary by temperament.
     */
    const TEMPERAMENT_BASELINES = [
        // Affinity: natural pull toward connection
        'affinity' => [
            'Romantic'    => 30, 'Anxious'     => 25, 'Playful'     => 30,
            'Humble'      => 25, 'Nurturing'   => 40, 'Gentle'      => 35,
            'Jealous'     => 20, 'Stoic'       => 15, 'Proud'       => 15,
            'Bold'        => 20, 'Independent' =>  0, 'Defiant'     => 10,
            'Guarded'     => 18,
        ],
        // Warmth: emotional openness baseline
        'warmth' => [
            'Romantic'    => 50, 'Anxious'     => 35, 'Playful'     => 45,
            'Humble'      => 40, 'Nurturing'   => 60, 'Gentle'      => 50,
            'Jealous'     => 25, 'Stoic'       => 20, 'Proud'       => 20,
            'Bold'        => 35, 'Independent' => 15, 'Defiant'     => 15,
            'Guarded'     => 25,  // "Wants to open up but afraid" — inner warmth exists
        ],
        // Maturity: emotional development starting point
        'maturity' => [
            'Romantic'    => 50, 'Anxious'     => 35, 'Playful'     => 40,
            'Humble'      => 55, 'Nurturing'   => 60, 'Gentle'      => 55,
            'Jealous'     => 30, 'Stoic'       => 55, 'Proud'       => 35,
            'Bold'        => 50, 'Independent' => 50, 'Defiant'     => 40,
            'Guarded'     => 45,
        ],
        // M/F coordinate baselines: personality anchor
        'coord_m' => [
            'Romantic'    =>  10, 'Anxious'     => -20, 'Playful'     =>  10,
            'Humble'      =>   0, 'Nurturing'   => -10, 'Gentle'      => -20,
            'Jealous'     => -10, 'Stoic'       =>  50, 'Proud'       =>  30,
            'Bold'        =>  60, 'Independent' =>  40, 'Defiant'     =>  40,
            'Guarded'     =>  20,
        ],
        'coord_f' => [
            'Romantic'    =>  40, 'Anxious'     =>  20, 'Playful'     =>  20,
            'Humble'      =>  20, 'Nurturing'   =>  60, 'Gentle'      =>  50,
            'Jealous'     => -10, 'Stoic'       => -20, 'Proud'       => -10,
            'Bold'        => -10, 'Independent' => -30, 'Defiant'     => -20,
            'Guarded'     =>   0,
        ],
        // Trust: can I rely on you?
        'trust' => [
            'Romantic'    => 40, 'Anxious'     => 30, 'Playful'     => 40,
            'Humble'      => 45, 'Nurturing'   => 50, 'Gentle'      => 45,
            'Jealous'     => 25, 'Stoic'       => 35, 'Proud'       => 30,
            'Bold'        => 45, 'Independent' => 30, 'Defiant'     => 25,
            'Guarded'     => 20,
        ],
        // Comfort: can I be myself around you?
        'comfort' => [
            'Romantic'    => 40, 'Anxious'     => 25, 'Playful'     => 55,
            'Humble'      => 40, 'Nurturing'   => 50, 'Gentle'      => 40,
            'Jealous'     => 20, 'Stoic'       => 25, 'Proud'       => 25,
            'Bold'        => 45, 'Independent' => 30, 'Defiant'     => 30,
            'Guarded'     => 15,
        ],
        // Respect: do I value what you bring?
        'respect' => [
            'Romantic'    => 40, 'Anxious'     => 35, 'Playful'     => 30,
            'Humble'      => 60, 'Nurturing'   => 55, 'Gentle'      => 45,
            'Jealous'     => 30, 'Stoic'       => 40, 'Proud'       => 25,
            'Bold'        => 45, 'Independent' => 35, 'Defiant'     => 30,
            'Guarded'     => 35,
        ],
        // Self-confidence: internal locus of evaluation (from design doc)
        'self_confidence' => [
            'Romantic'    => 35, 'Anxious'     => 25, 'Playful'     => 50,
            'Humble'      => 40, 'Nurturing'   => 45, 'Gentle'      => 35,
            'Jealous'     => 20, 'Stoic'       => 60, 'Proud'       => 70,
            'Bold'        => 65, 'Independent' => 75, 'Defiant'     => 55,
            'Guarded'     => 40,
        ],
    ];

    /**
     * Plasticity profiles: Y_up / Y_down per temperament per dimension.
     *
     * For existing dimensions (affinity, passion, warmth), Y values are derived
     * from the existing TEMPERAMENT_PASSION_MULT and related constants so that
     * behavior matches the legacy system. New dimensions use the design doc specs.
     *
     * Affinity Y: from design doc -- Stoic=0.5, Romantic=1.3, Anxious=1.5, Guarded=0.6
     *   (symmetric: Y_up == Y_down for affinity)
     * Passion Y: from existing TEMPERAMENT_PASSION_MULT (used as symmetric Y)
     * Warmth Y: from design doc -- Guarded=0.3, Nurturing=1.3, Stoic=0.4
     *   (derived from openness; symmetric)
     */
    const PLASTICITY_PROFILES = [
        // --- Affinity: symmetric resistance (design doc Section 1.1) ---
        'affinity' => [
            'Romantic'    => ['Y_up' => 1.3,  'Y_down' => 1.3],
            'Anxious'     => ['Y_up' => 1.5,  'Y_down' => 1.5],
            'Playful'     => ['Y_up' => 1.1,  'Y_down' => 1.1],
            'Humble'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Nurturing'   => ['Y_up' => 1.1,  'Y_down' => 1.1],
            'Gentle'      => ['Y_up' => 0.9,  'Y_down' => 0.9],
            'Jealous'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Stoic'       => ['Y_up' => 0.5,  'Y_down' => 0.5],
            'Proud'       => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Bold'        => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Independent' => ['Y_up' => 0.5,  'Y_down' => 0.5],
            'Defiant'     => ['Y_up' => 0.6,  'Y_down' => 0.6],
            'Guarded'     => ['Y_up' => 0.6,  'Y_down' => 0.6],
        ],
        // --- Passion: derived from TEMPERAMENT_PASSION_MULT (symmetric) ---
        'passion' => [
            'Romantic'    => ['Y_up' => 1.3,  'Y_down' => 1.3],
            'Anxious'     => ['Y_up' => 1.2,  'Y_down' => 1.2],
            'Playful'     => ['Y_up' => 1.15, 'Y_down' => 1.15],
            'Humble'      => ['Y_up' => 1.1,  'Y_down' => 1.1],
            'Nurturing'   => ['Y_up' => 1.05, 'Y_down' => 1.05],
            'Gentle'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Jealous'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Stoic'       => ['Y_up' => 0.85, 'Y_down' => 0.85],
            'Proud'       => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Bold'        => ['Y_up' => 0.75, 'Y_down' => 0.75],
            'Independent' => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Defiant'     => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Guarded'     => ['Y_up' => 0.6,  'Y_down' => 0.6],
        ],
        // --- Warmth: from openness column (symmetric) ---
        'warmth' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 1.2,  'Y_down' => 1.2],
            'Playful'     => ['Y_up' => 1.2,  'Y_down' => 1.2],
            'Humble'      => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Nurturing'   => ['Y_up' => 1.3,  'Y_down' => 1.3],
            'Gentle'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Jealous'     => ['Y_up' => 0.6,  'Y_down' => 0.6],
            'Stoic'       => ['Y_up' => 0.4,  'Y_down' => 0.4],
            'Proud'       => ['Y_up' => 0.5,  'Y_down' => 0.5],
            'Bold'        => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Independent' => ['Y_up' => 0.4,  'Y_down' => 0.4],
            'Defiant'     => ['Y_up' => 0.4,  'Y_down' => 0.4],
            'Guarded'     => ['Y_up' => 0.3,  'Y_down' => 0.3],
        ],
        // --- Maturity: 6 plasticity types mapped to temperaments (PR 3) ---
        // Resilient(Y_down=0.5/Y_up=1.0), Growth(0.7/1.3), Brittle(1.3/0.7),
        // Volatile(1.5/1.5), Rigid(0.3/0.3), Adaptive(1.0/1.0)
        // NOTE: When plasticity_type is stored on the NPC, getPlasticityProfile()
        // uses MATURITY_PLASTICITY_VALUES instead of these temperament lookups.
        // These remain as fallbacks for NPCs without a stored plasticity_type.
        'maturity' => [
            'Stoic'       => ['Y_up' => 1.0,  'Y_down' => 0.5],   // Resilient
            'Bold'        => ['Y_up' => 1.0,  'Y_down' => 0.5],   // Resilient
            'Defiant'     => ['Y_up' => 1.0,  'Y_down' => 0.5],   // Resilient
            'Independent' => ['Y_up' => 0.3,  'Y_down' => 0.3],   // Rigid
            'Guarded'     => ['Y_up' => 0.7,  'Y_down' => 1.3],   // Brittle
            'Anxious'     => ['Y_up' => 1.5,  'Y_down' => 1.5],   // Volatile
            'Jealous'     => ['Y_up' => 1.5,  'Y_down' => 1.5],   // Volatile
            'Nurturing'   => ['Y_up' => 1.3,  'Y_down' => 0.7],   // Growth
            'Romantic'    => ['Y_up' => 1.3,  'Y_down' => 0.7],   // Growth
            'Humble'      => ['Y_up' => 1.3,  'Y_down' => 0.7],   // Growth
            'Gentle'      => ['Y_up' => 1.0,  'Y_down' => 1.0],   // Adaptive
            'Playful'     => ['Y_up' => 1.0,  'Y_down' => 1.0],   // Adaptive
            'Proud'       => ['Y_up' => 0.7,  'Y_down' => 1.3],   // Brittle
        ],
        // --- M/F Coordinates: directional plasticity ---
        // Y_up = ease of moving toward positive, Y_down = ease of moving toward negative
        'coord_m' => [
            'Romantic'    => ['Y_up' => 0.8,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 1.2,  'Y_down' => 1.3],
            'Playful'     => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Humble'      => ['Y_up' => 0.9,  'Y_down' => 0.9],
            'Nurturing'   => ['Y_up' => 1.0,  'Y_down' => 0.8],
            'Gentle'      => ['Y_up' => 1.2,  'Y_down' => 0.8],
            'Jealous'     => ['Y_up' => 0.8,  'Y_down' => 1.2],
            'Stoic'       => ['Y_up' => 0.6,  'Y_down' => 0.6],
            'Proud'       => ['Y_up' => 0.7,  'Y_down' => 1.0],
            'Bold'        => ['Y_up' => 0.8,  'Y_down' => 1.2],
            'Independent' => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Defiant'     => ['Y_up' => 0.9,  'Y_down' => 1.0],
            'Guarded'     => ['Y_up' => 0.7,  'Y_down' => 0.7],
        ],
        'coord_f' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 0.8],
            'Anxious'     => ['Y_up' => 1.0,  'Y_down' => 1.3],
            'Playful'     => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Humble'      => ['Y_up' => 1.0,  'Y_down' => 0.9],
            'Nurturing'   => ['Y_up' => 0.8,  'Y_down' => 1.3],
            'Gentle'      => ['Y_up' => 0.9,  'Y_down' => 1.0],
            'Jealous'     => ['Y_up' => 0.8,  'Y_down' => 1.3],
            'Stoic'       => ['Y_up' => 0.5,  'Y_down' => 0.5],
            'Proud'       => ['Y_up' => 1.3,  'Y_down' => 0.8],
            'Bold'        => ['Y_up' => 1.0,  'Y_down' => 0.7],
            'Independent' => ['Y_up' => 0.6,  'Y_down' => 0.6],
            'Defiant'     => ['Y_up' => 0.8,  'Y_down' => 0.9],
            'Guarded'     => ['Y_up' => 0.6,  'Y_down' => 0.6],
        ],
        // --- Arousal: symmetric, temperament scales overall reactivity ---
        'arousal' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 1.5,  'Y_down' => 1.5],
            'Playful'     => ['Y_up' => 1.1,  'Y_down' => 1.1],
            'Humble'      => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Nurturing'   => ['Y_up' => 0.9,  'Y_down' => 0.9],
            'Gentle'      => ['Y_up' => 1.2,  'Y_down' => 1.2],
            'Jealous'     => ['Y_up' => 1.3,  'Y_down' => 1.3],
            'Stoic'       => ['Y_up' => 0.4,  'Y_down' => 0.4],
            'Proud'       => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Bold'        => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Independent' => ['Y_up' => 0.6,  'Y_down' => 0.6],
            'Defiant'     => ['Y_up' => 0.9,  'Y_down' => 0.9],
            'Guarded'     => ['Y_up' => 0.5,  'Y_down' => 0.5],
        ],
        // --- Valence: directional bias (positive=thrill, negative=fear) ---
        'valence' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 0.7,  'Y_down' => 1.3],
            'Playful'     => ['Y_up' => 1.2,  'Y_down' => 0.7],
            'Humble'      => ['Y_up' => 0.8,  'Y_down' => 0.8],
            'Nurturing'   => ['Y_up' => 0.9,  'Y_down' => 1.0],
            'Gentle'      => ['Y_up' => 0.8,  'Y_down' => 1.2],
            'Jealous'     => ['Y_up' => 0.7,  'Y_down' => 1.2],
            'Stoic'       => ['Y_up' => 0.5,  'Y_down' => 0.5],
            'Proud'       => ['Y_up' => 0.9,  'Y_down' => 0.8],
            'Bold'        => ['Y_up' => 1.2,  'Y_down' => 0.7],
            'Independent' => ['Y_up' => 0.7,  'Y_down' => 0.7],
            'Defiant'     => ['Y_up' => 1.0,  'Y_down' => 0.8],
            'Guarded'     => ['Y_up' => 0.5,  'Y_down' => 0.6],
        ],
        // --- Trust: slow gain, fast loss (Y_up=0.7, Y_down=1.5 base) ---
        'trust' => [
            'Romantic'    => ['Y_up' => 0.8,  'Y_down' => 1.3],
            'Anxious'     => ['Y_up' => 1.5,  'Y_down' => 1.5],   // Volatile -- cross-signal with maturity
            'Playful'     => ['Y_up' => 0.8,  'Y_down' => 1.0],
            'Humble'      => ['Y_up' => 0.9,  'Y_down' => 1.2],
            'Nurturing'   => ['Y_up' => 1.1,  'Y_down' => 1.1],
            'Gentle'      => ['Y_up' => 0.8,  'Y_down' => 1.3],
            'Jealous'     => ['Y_up' => 0.6,  'Y_down' => 1.8],
            'Stoic'       => ['Y_up' => 0.5,  'Y_down' => 1.0],
            'Proud'       => ['Y_up' => 0.5,  'Y_down' => 1.8],
            'Bold'        => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Independent' => ['Y_up' => 0.5,  'Y_down' => 1.0],
            'Defiant'     => ['Y_up' => 0.6,  'Y_down' => 1.5],
            'Guarded'     => ['Y_up' => 0.4,  'Y_down' => 1.5],
        ],
        // --- Comfort: moderate symmetric, temperament-adjusted ---
        'comfort' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 1.2,  'Y_down' => 1.3],
            'Playful'     => ['Y_up' => 1.4,  'Y_down' => 1.4],
            'Humble'      => ['Y_up' => 1.0,  'Y_down' => 0.8],
            'Nurturing'   => ['Y_up' => 1.2,  'Y_down' => 0.8],
            'Gentle'      => ['Y_up' => 1.0,  'Y_down' => 1.1],
            'Jealous'     => ['Y_up' => 0.7,  'Y_down' => 1.3],
            'Stoic'       => ['Y_up' => 0.4,  'Y_down' => 0.4],
            'Proud'       => ['Y_up' => 0.5,  'Y_down' => 1.0],
            'Bold'        => ['Y_up' => 0.9,  'Y_down' => 0.7],
            'Independent' => ['Y_up' => 0.5,  'Y_down' => 0.5],
            'Defiant'     => ['Y_up' => 0.6,  'Y_down' => 0.8],
            'Guarded'     => ['Y_up' => 0.3,  'Y_down' => 0.3],
        ],
        // --- Respect: earned slowly, lost fast (Y_up=0.7, Y_down=1.5 base) ---
        'respect' => [
            'Romantic'    => ['Y_up' => 0.7,  'Y_down' => 1.3],
            'Anxious'     => ['Y_up' => 0.8,  'Y_down' => 1.5],
            'Playful'     => ['Y_up' => 0.7,  'Y_down' => 1.0],
            'Humble'      => ['Y_up' => 1.0,  'Y_down' => 1.2],   // Generous with respect
            'Nurturing'   => ['Y_up' => 0.9,  'Y_down' => 1.2],
            'Gentle'      => ['Y_up' => 0.8,  'Y_down' => 1.3],
            'Jealous'     => ['Y_up' => 0.6,  'Y_down' => 1.5],
            'Stoic'       => ['Y_up' => 0.5,  'Y_down' => 1.2],
            'Proud'       => ['Y_up' => 0.5,  'Y_down' => 2.0],   // Disrespect hits HARD
            'Bold'        => ['Y_up' => 0.8,  'Y_down' => 1.3],
            'Independent' => ['Y_up' => 0.6,  'Y_down' => 1.0],
            'Defiant'     => ['Y_up' => 0.6,  'Y_down' => 1.5],
            'Guarded'     => ['Y_up' => 0.5,  'Y_down' => 1.5],
        ],
        // --- Resentment: Y derived from maturity at runtime, these are fallback defaults ---
        // Y = 1.5 - (maturity / 100). These are computed in getPlasticityProfile().
        // Static fallback assumes maturity ~50: Y = 1.0
        'resentment' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Playful'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Humble'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Nurturing'   => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Gentle'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Jealous'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Stoic'       => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Proud'       => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Bold'        => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Independent' => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Defiant'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Guarded'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
        ],
        // --- Resentment_self: Y derived from maturity at runtime, same as resentment ---
        // ========== RESENTMENT_SELF (PR 7) ==========
        'resentment_self' => [
            'Romantic'    => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Anxious'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Playful'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Humble'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Nurturing'   => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Gentle'      => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Jealous'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Stoic'       => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Proud'       => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Bold'        => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Independent' => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Defiant'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
            'Guarded'     => ['Y_up' => 1.0,  'Y_down' => 1.0],
        ],
        // --- Self-confidence: slow to build (Y_up=0.5), fast to lose (Y_down=1.3) ---
        'self_confidence' => [
            'Romantic'    => ['Y_up' => 0.5,  'Y_down' => 1.3],
            'Anxious'     => ['Y_up' => 0.4,  'Y_down' => 1.5],
            'Playful'     => ['Y_up' => 0.6,  'Y_down' => 1.0],
            'Humble'      => ['Y_up' => 0.5,  'Y_down' => 1.0],
            'Nurturing'   => ['Y_up' => 0.6,  'Y_down' => 1.1],
            'Gentle'      => ['Y_up' => 0.5,  'Y_down' => 1.3],
            'Jealous'     => ['Y_up' => 0.4,  'Y_down' => 1.5],
            'Stoic'       => ['Y_up' => 0.5,  'Y_down' => 0.8],
            'Proud'       => ['Y_up' => 0.3,  'Y_down' => 2.0],   // Brittle: crashes hard
            'Bold'        => ['Y_up' => 0.6,  'Y_down' => 1.0],
            'Independent' => ['Y_up' => 0.6,  'Y_down' => 0.8],
            'Defiant'     => ['Y_up' => 0.5,  'Y_down' => 1.1],
            'Guarded'     => ['Y_up' => 0.4,  'Y_down' => 1.3],
        ],
    ];

    // ---- XYZ Engine: Core Methods ----

    /**
     * Get the static definition for a dimension.
     *
     * @param string $dimensionId  One of the DIMENSION_DEFS keys
     * @return array|null  Definition array or null if unknown dimension
     */
    public static function getDimensionDefinition($dimensionId)
    {
        return self::DIMENSION_DEFS[$dimensionId] ?? null;
    }

    /**
     * Get the temperament-derived baseline X value for a dimension.
     *
     * @param string|null $temperament  MARAS or inferred temperament name
     * @param string      $dimensionId  Dimension key
     * @return float  Baseline value
     */
    public static function getTemperamentBaseline($temperament, $dimensionId)
    {
        $def = self::getDimensionDefinition($dimensionId);
        if (!$def) return 0.0;

        // Fixed-baseline dimensions always return their constant
        $fixedBaselines = [
            'passion'    => 0,
            'arousal'    => 10,
            'valence'    => 0,
            'resentment' => 0,
            'resentment_self' => 0,  // ========== RESENTMENT_SELF (PR 7) ==========
        ];
        if (isset($fixedBaselines[$dimensionId])) {
            return (float) $fixedBaselines[$dimensionId];
        }

        // Temperament-specific baseline
        if ($temperament && isset(self::TEMPERAMENT_BASELINES[$dimensionId][$temperament])) {
            return (float) self::TEMPERAMENT_BASELINES[$dimensionId][$temperament];
        }

        // Fallback to dimension definition default
        return (float) $def['default_baseline'];
    }

    /**
     * Get the plasticity profile (Y_up, Y_down) for a temperament + dimension.
     *
     * For resentment, Y is dynamically derived from maturity:
     *   Y = 1.5 - (maturity / 100)
     * Pass maturity via $context['maturity'] when calling for resentment.
     *
     * @param string|null $temperament  Temperament name
     * @param string      $dimensionId  Dimension key
     * @param array       $context      Optional context (e.g., ['maturity' => 72])
     * @return array  ['Y_up' => float, 'Y_down' => float]
     */
    public static function getPlasticityProfile($temperament, $dimensionId, $context = [])
    {
        // Special case: resentment Y is maturity-derived
        if ($dimensionId === 'resentment') {
            $maturity = floatval($context['maturity'] ?? 50);
            $y = max(0.1, 1.5 - ($maturity / 100.0));
            return ['Y_up' => $y, 'Y_down' => $y];
        }

        // ========== RESENTMENT_SELF (PR 7) ==========
        // Same maturity-derived Y as resentment: sticky once accumulated
        if ($dimensionId === 'resentment_self') {
            $maturity = floatval($context['maturity'] ?? 50);
            $y = max(0.1, 1.5 - ($maturity / 100.0));
            return ['Y_up' => $y, 'Y_down' => $y];
        }

        // ========== MATURITY DIMENSION (PR 3) ==========
        // Maturity uses a plasticity TYPE (Resilient, Growth, etc.) instead of
        // per-temperament profiles. The type is stored on the NPC's dynamics
        // and determines which row in the plasticity table is used.
        if ($dimensionId === 'maturity') {
            $plasticityType = $context['plasticity_type'] ?? null;
            if ($plasticityType && isset(self::MATURITY_PLASTICITY_VALUES[$plasticityType])) {
                return self::MATURITY_PLASTICITY_VALUES[$plasticityType];
            }
            // Fall through to temperament-based lookup below
        }

        // Temperament-specific lookup
        if ($temperament && isset(self::PLASTICITY_PROFILES[$dimensionId][$temperament])) {
            return self::PLASTICITY_PROFILES[$dimensionId][$temperament];
        }

        // Fallback: neutral plasticity
        return ['Y_up' => 1.0, 'Y_down' => 1.0];
    }

    // ========== MATURITY DIMENSION (PR 3) ==========

    /**
     * Maturity plasticity type values.
     * Each type defines asymmetric Y_up (recovery/improvement) and Y_down (destabilizing/decline).
     */
    const MATURITY_PLASTICITY_VALUES = [
        'Resilient' => ['Y_up' => 1.0,  'Y_down' => 0.5],  // Hard to break, normal rebuild
        'Growth'    => ['Y_up' => 1.3,  'Y_down' => 0.7],  // Resists collapse, amplifies improvement
        'Brittle'   => ['Y_up' => 0.7,  'Y_down' => 1.3],  // Easy to break, hard to rebuild
        'Volatile'  => ['Y_up' => 1.5,  'Y_down' => 1.5],  // Big swings both ways
        'Rigid'     => ['Y_up' => 0.3,  'Y_down' => 0.3],  // Barely moves — set in ways
        'Adaptive'  => ['Y_up' => 1.0,  'Y_down' => 1.0],  // Symmetric default
    ];

    /**
     * Temperament → default maturity plasticity type mapping.
     * Determines how an NPC's maturity responds to pressure.
     */
    const TEMPERAMENT_MATURITY_PLASTICITY = [
        'Stoic'       => 'Resilient',  // Hard to break
        'Bold'        => 'Resilient',
        'Defiant'     => 'Resilient',
        'Independent' => 'Rigid',      // Set in ways
        'Guarded'     => 'Brittle',    // Easy to break, hard to rebuild
        'Anxious'     => 'Volatile',   // Big swings
        'Jealous'     => 'Volatile',
        'Nurturing'   => 'Growth',     // Resists collapse, amplifies improvement
        'Romantic'    => 'Growth',
        'Humble'      => 'Growth',
        'Gentle'      => 'Adaptive',   // Symmetric
        'Playful'     => 'Adaptive',
        'Proud'       => 'Brittle',    // Confident facade, crashes hard
    ];

    /**
     * Get the default maturity plasticity type for a temperament.
     *
     * @param string $temperament  Temperament name
     * @return string  Plasticity type (Resilient, Growth, Brittle, Volatile, Rigid, Adaptive)
     */
    public static function getMaturityPlasticityType($temperament)
    {
        return self::TEMPERAMENT_MATURITY_PLASTICITY[$temperament] ?? 'Adaptive';
    }

    // ========== END MATURITY DIMENSION HELPERS ==========

    // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========

    /**
     * Derive the confidence input signal from other dimension values.
     *
     * Self-confidence is NOT directly eval-scored. It is derived from sustained
     * signals over time:
     *   confidence_input = (
     *       avg_respect_received x 0.3
     *     + maturity x 0.3
     *     - resentment_self x 0.3
     *     + goal_completion_rate x 0.1
     *   )
     *
     * This value is used for BASELINE DRIFT in a future PR: if confidence_input
     * consistently exceeds current X, baseline drifts up (and vice versa).
     *
     * @param array $dynamics  NPC dynamics array with dimensions sub-object
     * @return float  Computed confidence input (0-100 range, unclamped)
     */
    public static function deriveConfidenceInput($dynamics)
    {
        $dims = $dynamics['dimensions'] ?? [];

        // Read respect (default 50 = neutral)
        $respect = floatval($dims['respect']['x'] ?? 50);

        // Read maturity (default 50 = neutral)
        $maturity = floatval($dims['maturity']['x'] ?? 50);

        // resentment_self not yet built -- use 0 for now
        $resentmentSelf = 0.0;

        // goal_completion_rate not yet built -- use 0.5 for now (neutral)
        $goalCompletionRate = 0.5;

        // Normalize respect and maturity to 0-1 scale for weighting
        $respectNorm = $respect / 100.0;
        $maturityNorm = $maturity / 100.0;

        // Weighted sum (result in 0-1 range, then scale to 0-100)
        $input = (
            $respectNorm * 0.3
          + $maturityNorm * 0.3
          - $resentmentSelf * 0.3
          + $goalCompletionRate * 0.1
        );

        // Scale to 0-100 range
        return round($input * 100.0, 2);
    }

    // ========== END SELF-CONFIDENCE DIMENSION HELPERS ==========

    /**
     * Calculate Z-distance decay factor.
     *
     * AWAY from baseline:  decay = 1 / (1 + |X - baseline| / Z)
     *   Harder to push further from baseline.
     *
     * TOWARD baseline:  decay = min(MAX_TOWARD, 1 + |X - baseline| / Z)
     *   Easier to return, capped at 3x.
     *
     * @param float $x         Current X value
     * @param float $baseline  Baseline (rubber band center)
     * @param float $z         Z scale (higher = weaker rubber band)
     * @param bool  $isToward  True if moving toward baseline
     * @return float  Decay multiplier (always > 0)
     */
    private static function calcZDecay($x, $baseline, $z, $isToward)
    {
        $z = max(0.01, $z); // Prevent division by zero
        $dist = abs($x - $baseline);

        if ($isToward) {
            return min(self::XYZ_MAX_TOWARD_MULT, 1.0 + $dist / $z);
        } else {
            return 1.0 / (1.0 + $dist / $z);
        }
    }


    // ========== RESENTMENT_SELF (PR 7) ==========

    /**
     * Calculate guilt bleed: how much resentment_self bleeds into per-bond comfort.
     *
     * Higher bond strength with the affected party = more guilt.
     * Formula: bleed = resentment_self * (affinity / 100), capped at -15.
     *
     * @param array $dynamics             NPC dynamics blob
     * @param float $affectedBondAffinity Affinity (0-100) with the affected party
     * @return float  Comfort reduction (negative value, capped at -15)
     */
    public static function calculateGuiltBleed($dynamics, $affectedBondAffinity)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);

        if ($resentmentSelf < 0.01) {
            return 0.0;
        }

        $affinity = max(0.0, min(100.0, floatval($affectedBondAffinity)));
        $bleed = $resentmentSelf * ($affinity / 100.0);

        // Cap at -15 gradual application
        $bleed = min($bleed, 15.0);

        return -1.0 * round($bleed, 4);
    }

    /**
     * Check resentment_self thresholds and return triggered effects.
     *
     * Thresholds:
     *   >30: comfort_baseline -5 toward ALL bonds
     *   >50: Director triggers self-reflection scene
     *   >70: warmth_baseline -10 globally
     *   >90: crisis — NPC seeks isolation
     *
     * @param array $dynamics  NPC dynamics blob
     * @return array  List of triggered thresholds with effects
     */
    public static function checkResentmentSelfThresholds($dynamics)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $x = floatval($dims['resentment_self']['x'] ?? 0);
        $triggered = [];

        if ($x > 30) {
            $triggered[] = [
                'threshold' => 30,
                'effect'    => 'comfort_baseline_penalty',
                'value'     => -5,
                'desc'      => 'Self-doubt bleeds into all bonds: comfort baseline -5 globally',
            ];
        }

        if ($x > 50) {
            $triggered[] = [
                'threshold' => 50,
                'effect'    => 'director_self_reflection',
                'value'     => null,
                'desc'      => 'Director trigger: NPC initiates self-reflection scene',
            ];
        }

        if ($x > 70) {
            $triggered[] = [
                'threshold' => 70,
                'effect'    => 'warmth_baseline_penalty',
                'value'     => -10,
                'desc'      => 'Shame-driven withdrawal: warmth baseline -10 globally',
            ];
        }

        if ($x > 90) {
            $triggered[] = [
                'threshold' => 90,
                'effect'    => 'isolation_crisis',
                'value'     => null,
                'desc'      => 'Self-loathing crisis: NPC seeks isolation from all bonds',
            ];
        }

        return $triggered;
    }


    // ========== XYZ CROSS-SIGNAL CAPS (PR 3) ==========

    /**
     * Apply cross-signal caps: one dimension's state modifies another's delta.
     *
     * Called BEFORE applyDelta physics. This is a pre-filter that modifies the
     * raw delta based on the current state of OTHER dimensions. Caps stack
     * multiplicatively (e.g., low respect + high resentment = 0.25x affinity gain).
     *
     * Only fires when the source dimension is ACTIVE (x !== null). If a dimension
     * hasn't been activated yet, its cap doesn't apply.
     *
     * @param array  &$dynamics     NPC dynamics array (read-only for source dims)
     * @param string $dimensionId   Target dimension receiving the delta
     * @param float  $rawDelta      Raw delta before physics
     * @return float Modified delta after cross-signal caps
     */
    public static function applyCrossSignalCaps(&$dynamics, $dimensionId, $rawDelta, array $skip = [])
    {
        $dims = $dynamics['dimensions'] ?? [];
        $modifiedDelta = $rawDelta;

        // --- Respect < 30 → halve affinity gains above 60 ---
        // "Can't deeply love what you don't respect"
        if ($dimensionId === 'affinity' && $rawDelta > 0) {
            $respect = $dims['respect']['x'] ?? null;
            $affinity = $dims['affinity']['x'] ?? 0;
            if ($respect !== null && $respect < 30 && $affinity > 60) {
                $modifiedDelta *= 0.5;
                error_log("[RelDyn-CAP] Low respect ({$respect}) halving affinity gain above 60");
            }
        }

        // --- Maturity < 30 → halve trust gains ---
        // "Immature behavior erodes reliability"
        if ($dimensionId === 'trust' && $rawDelta > 0) {
            $maturity = $dims['maturity']['x'] ?? null;
            if ($maturity !== null && $maturity < 30) {
                $modifiedDelta *= 0.5;
                error_log("[RelDyn-CAP] Low maturity ({$maturity}) halving trust gain");
            }
        }

        // --- MDD 15.5 suppressed buildup: resentment > 30 and maturity < 50 → gains +50% ---
        // "Immature NPCs bottle things up worse" (the one place this rule is applied)
        if ($dimensionId === 'resentment' && $rawDelta > 0) {
            $maturity = $dims['maturity']['x'] ?? null;
            $resentment = floatval($dims['resentment']['x'] ?? 0);
            if ($maturity !== null && $maturity < self::RESENTMENT_SUPPRESSED_MATURITY_BELOW
                && $resentment > self::RESENTMENT_SUPPRESSED_ABOVE) {
                $modifiedDelta *= self::RESENTMENT_SUPPRESSED_MULT;
                error_log("[RelDyn-CAP] Suppressed buildup (resentment {$resentment}, maturity {$maturity}) amplifying resentment gain");
            }
        }

        // ========== RESENTMENT_SELF (PR 7) ==========
        // --- Maturity < 50 → amplify resentment_self buildup +50% ---
        // "Immature NPCs internalize shame worse"
        if ($dimensionId === 'resentment_self' && $rawDelta > 0) {
            $maturity = $dims['maturity']['x'] ?? null;
            if ($maturity !== null && $maturity < 50) {
                $modifiedDelta *= 1.5;
                error_log("[RelDyn-CAP] Low maturity ({$maturity}) amplifying resentment_self buildup");
            }
        }

        // --- Trust < 30 → halve comfort gains ---
        // "Trust is the prerequisite for vulnerability"
        if ($dimensionId === 'comfort' && $rawDelta > 0) {
            $trust = $dims['trust']['x'] ?? null;
            if ($trust !== null && $trust < 30) {
                $modifiedDelta *= 0.5;
                error_log("[RelDyn-CAP] Low trust ({$trust}) halving comfort gain");
            }
        }

        // --- Comfort < 30 → halve maturity gains ---
        // "Can't grow emotionally in an uncomfortable environment"
        if ($dimensionId === 'maturity' && $rawDelta > 0) {
            $comfort = $dims['comfort']['x'] ?? null;
            if ($comfort !== null && $comfort < 30) {
                $modifiedDelta *= 0.5;
                error_log("[RelDyn-CAP] Low comfort ({$comfort}) halving maturity gain");
            }
        }

        // --- Resentment > 50 → halve affinity gains; >= 70 → frozen (MDD 15.4 / 15.5) ---
        // "Grievances block bonding". The multiplier is getResentmentEffects()'s, the one accessor.
        // The eval consumer skips 'resentment_affinity_gain' because its M_modifiers row
        // resentment_blocks_gains already halves above 50; on that path only the 70+ freeze
        // (which M's 0.25 floor cannot express) applies here. Halved exactly once either way.
        if ($dimensionId === 'affinity' && $rawDelta > 0) {
            $gainMult = self::getResentmentEffects($dynamics)['affinity_gain_mult'];
            if (in_array('resentment_affinity_gain', $skip, true) && $gainMult > 0.0) {
                $gainMult = 1.0;
            }
            if ($gainMult < 1.0) {
                $modifiedDelta *= $gainMult;
                error_log("[RelDyn-CAP] High resentment (" . floatval($dims['resentment']['x'] ?? 0) . ") affinity gain x{$gainMult}");
            }
        }

        // --- Self-confidence < 30 → amplify social sensitivity 1.5x ---
        // "No internal counterweight to external input"
        // Only amplify NEGATIVE deltas (vulnerability to criticism). Not on the accumulators:
        // a negative resentment delta is relief (MDD 15.5 decay), not a hurt. The eval's
        // affinity skips it: there it is the M row low_self_confidence_losses (clamped).
        if ($rawDelta < 0 && !in_array($dimensionId, ['resentment', 'resentment_self'], true)
            && !in_array('low_self_confidence_losses', $skip, true)) {
            $selfConf = $dims['self_confidence']['x'] ?? null;
            if ($selfConf !== null && $selfConf < 30) {
                $modifiedDelta *= 1.5;
                error_log("[RelDyn-CAP] Low self-confidence ({$selfConf}) amplifying negative delta on {$dimensionId}");
            }
        }

        // ========== ATTACHMENT STYLE MODIFIERS (PR 10) ==========
        $attachmentStyle = self::getAttachmentStyle($dynamics);
        $attachMods = self::ATTACHMENT_MODIFIERS[$attachmentStyle] ?? [];

        // Resentment buildup amplification from attachment
        if ($dimensionId === 'resentment' && $rawDelta > 0 && isset($attachMods['resentment_gain_mult'])) {
            $modifiedDelta *= $attachMods['resentment_gain_mult'];
        }

        // Maturity floor for toxic attachment
        if ($dimensionId === 'maturity' && $rawDelta > 0 && $attachMods['maturity_floor'] !== null) {
            $currentMaturity = floatval($dims['maturity']['x'] ?? 50);
            $floor = $attachMods['maturity_floor'];
            if ($currentMaturity >= $floor) {
                $modifiedDelta = 0;
            } elseif (($currentMaturity + $modifiedDelta) > $floor) {
                $modifiedDelta = max(0, $floor - $currentMaturity);
            }
        }

        // Toxic conflict passion: resentment gains queue passion
        if ($dimensionId === 'resentment' && $rawDelta > 0 && $attachMods['conflict_passion_gain'] > 0) {
            $GLOBALS['RELDYN_ATTACHMENT_CONFLICT_PASSION'] =
                ($GLOBALS['RELDYN_ATTACHMENT_CONFLICT_PASSION'] ?? 0) + $attachMods['conflict_passion_gain'];
        }

        // Widow's Lock: cap affinity gains for grieving NPCs
        if ($dimensionId === 'affinity' && $rawDelta > 0) {
            $ceiling = floatval($dynamics['_widow_lock_ceiling'] ?? 100);
            if ($ceiling < 100) {
                $currentAff = floatval($dims['affinity']['x'] ?? 0);
                if ($currentAff >= $ceiling) {
                    $modifiedDelta = 0;
                } elseif (($currentAff + $modifiedDelta) > $ceiling) {
                    $modifiedDelta = max(0, $ceiling - $currentAff);
                }
            }
        }

        return $modifiedDelta;
    }

    /**
     * Apply a raw delta to a dimension using the full (X, Y, Z) physics.
     *
     * Pure math -- NO database calls. Reads and writes to $dynamics by reference.
     *
     * Resolution order for Y and Z:
     *   1. $overrides (caller-supplied, highest priority)
     *   2. $dynamics['dimensions'][$dimensionId] (per-NPC stored state)
     *   3. Temperament lookup via getPlasticityProfile()
     *   4. Dimension definition defaults
     *
     * Handles overshoot: if the delta would cross the baseline, it splits
     * at the crossing point and applies each portion with the correct decay
     * direction (AWAY vs TOWARD).
     *
     * @param string     $dimensionId  Dimension key (e.g., 'trust', 'affinity')
     * @param array      &$dynamics    NPC dynamics array (modified in place)
     * @param float      $rawDelta     Raw delta from eval or event (positive or negative)
     * @param string|null $temperament Temperament name (null = use defaults)
     * @param array      $overrides    Optional overrides: Y_up, Y_down, Z, maturity,
     *                                 max_abs (clamp on |actual delta|, physics units),
     *                                 skip_caps (applyCrossSignalCaps rules to skip)
     * @return float     The actual delta applied (after all physics)
     */
    public static function applyDelta($dimensionId, &$dynamics, $rawDelta, $temperament = null, $overrides = [])
    {
        $rawDelta = floatval($rawDelta);
        if (abs($rawDelta) < 0.0001) {
            return 0.0;
        }

        // Apply cross-signal caps (other dimensions modify this delta)
        $config = self::getConfig();
        if (!empty($config['dimension_engine_enabled'])) {
            $rawDelta = self::applyCrossSignalCaps($dynamics, $dimensionId, $rawDelta, (array) ($overrides['skip_caps'] ?? []));
        }


        // --- Get dimension definition ---
        $def = self::getDimensionDefinition($dimensionId);
        if (!$def) {
            error_log("[RelDyn-XYZ] applyDelta: unknown dimension '{$dimensionId}'");
            return 0.0;
        }

        // --- Ensure dimensions sub-array exists ---
        if (!isset($dynamics['dimensions'])) {
            $dynamics['dimensions'] = [];
        }
        if (!isset($dynamics['dimensions'][$dimensionId])) {
            $dynamics['dimensions'][$dimensionId] = [];
        }
        $dimState = &$dynamics['dimensions'][$dimensionId];

        // --- Read current X (default 0) ---
        $x = floatval($dimState['x'] ?? 0.0);

        // --- Resolve baseline ---
        $baseline = $dimState['baseline'] ?? null;
        if ($baseline === null) {
            $baseline = self::getTemperamentBaseline($temperament, $dimensionId);
        }
        $baseline = floatval($baseline);

        // --- Resolve Z scale ---
        $z = $overrides['Z'] ?? ($dimState['Z'] ?? $def['default_Z']);
        $z = floatval(max(0.01, $z));

        // --- Resolve Y resistance (up/down) ---
        $plasticityContext = [];
        if (isset($overrides['maturity'])) {
            $plasticityContext['maturity'] = $overrides['maturity'];
        } elseif (isset($dynamics['dimensions']['maturity']['x'])) {
            $plasticityContext['maturity'] = $dynamics['dimensions']['maturity']['x'];
        }

        // PR 3: Pass maturity plasticity_type for type-based profile lookup
        if ($dimensionId === 'maturity') {
            $plasticityContext['plasticity_type'] = $overrides['plasticity_type']
                ?? ($dimState['plasticity_type'] ?? null);
        }

        // ========== PLASTICITY OVERRIDE (PR 10) ==========
        // Override affects maturity dimension ONLY.
        // Uses raw _last_gamets for both start and expiry — "30 game days" is calendar time.
        if ($dimensionId === 'maturity') {
            $plasticityOverride = $dynamics['_plasticity_override'] ?? null;
            if ($plasticityOverride !== null) {
                $overrideExpiry = floatval($dynamics['_plasticity_override_expires_gamets'] ?? 0);
                $currentRawGamets = floatval($dynamics['_last_gamets'] ?? 0);
                if ($currentRawGamets > 0 && $currentRawGamets >= $overrideExpiry) {
                    // Expired — clear override
                    $dynamics['_plasticity_override'] = null;
                    $dynamics['_plasticity_override_start_gamets'] = 0;
                    $dynamics['_plasticity_override_expires_gamets'] = 0;
                } else {
                    // Active — override the plasticity type for maturity
                    $plasticityContext['plasticity_type'] = $plasticityOverride;
                }
            }
        }

        $profile = self::getPlasticityProfile($temperament, $dimensionId, $plasticityContext);

        // Override priority: explicit overrides > stored per-NPC > temperament profile
        $yUp   = floatval($overrides['Y_up']   ?? ($dimState['Y_up']   ?? $profile['Y_up']));
        $yDown = floatval($overrides['Y_down'] ?? ($dimState['Y_down'] ?? $profile['Y_down']));

        // --- Check for invert_rubber_band flag ---
        $flags = $def['flags'] ?? [];
        $invertRubberBand = in_array('invert_rubber_band', $flags, true);

        // --- Range limits ---
        $rangeMin = floatval($def['range_min']);
        $rangeMax = floatval($def['range_max']);

        // Affinity: x is only a mirror of core relationships.Player.aff (x = (aff + 100) / 2),
        // while the deltas (eval affinity_delta, gifts, cascade), the temperament baselines
        // and Z are on CHIM's -100..+100 affinity scale (MDD 6.5, 15.1). Run the physics in
        // those units; reading the baselines as mirror units put every NPC's rest point at
        // core -100..-20 and dragged all deltas toward hostility.
        $mirrorScale = ($dimensionId === 'affinity');
        if ($mirrorScale) {
            $x = $x * 2.0 - 100.0;
            $rangeMin = -100.0;
            $rangeMax = 100.0;
        }

        // --- Core physics application ---
        // Determine if the delta would cross the baseline (overshoot handling)
        $xAfterRaw = $x + $rawDelta; // hypothetical end position without physics
        $crossesBaseline = (($x >= $baseline && $xAfterRaw < $baseline) ||
                            ($x <= $baseline && $xAfterRaw > $baseline)) &&
                           abs($x - $baseline) > 0.0001;

        $actualDelta = 0.0;

        if ($crossesBaseline) {
            // --- SPLIT at baseline crossing ---
            // Portion 1: from X to baseline
            $deltaToBaseline = $baseline - $x;
            $remainingRaw = $rawDelta - $deltaToBaseline;

            // Portion 1 is always TOWARD baseline
            $actual1 = self::applyPortionDelta($x, $baseline, $deltaToBaseline, $z, $yUp, $yDown, $invertRubberBand);

            // After applying portion 1, X is at (or very near) baseline
            $xAtBaseline = $x + $actual1;

            // Portion 2: from baseline onward (AWAY from baseline)
            $actual2 = self::applyPortionDelta($xAtBaseline, $baseline, $remainingRaw, $z, $yUp, $yDown, $invertRubberBand);

            $actualDelta = $actual1 + $actual2;
        } else {
            // --- No crossing: single application ---
            $actualDelta = self::applyPortionDelta($x, $baseline, $rawDelta, $z, $yUp, $yDown, $invertRubberBand);
        }

        // --- Significance clamp (eval consumer): |delta| <= max_abs, in physics units
        // (dimension points; core affinity points for affinity) ---
        if (isset($overrides['max_abs'])) {
            $maxAbs = max(0.0, floatval($overrides['max_abs']));
            $actualDelta = max(-$maxAbs, min($maxAbs, $actualDelta));
        }

        // --- Clamp to range ---
        $newX = max($rangeMin, min($rangeMax, $x + $actualDelta));
        $actualDelta = $newX - $x;

        // Back to mirror units: callers get the change of dimensions.affinity.x as before
        if ($mirrorScale) {
            $x = ($x + 100.0) / 2.0;
            $newX = ($newX + 100.0) / 2.0;
            $actualDelta = $newX - $x;
        }

        // --- Write back ---
        if ($dimensionId === 'passion') {
            self::setPassion($dynamics, round($newX, 4));
        } else {
            $dimState['x'] = round($newX, 4);
        }
        unset($dimState);
        if ($dimensionId === 'resentment' || $dimensionId === 'comfort') {
            self::enforceResentmentWithdrawal($dynamics);
        }

        // Debug logging
        error_log("[RelDyn-XYZ] applyDelta: dim={$dimensionId} X={$x}=>{$newX} "
            . "raw={$rawDelta} actual=" . round($actualDelta, 4)
            . " baseline={$baseline} Z={$z} Y_up={$yUp} Y_down={$yDown}"
            . ($invertRubberBand ? ' [INVERT_RB]' : '')
            . ($mirrorScale ? ' [X=mirror, physics/baseline in core aff units]' : ''));

        return round($actualDelta, 4);
    }

    /**
     * Apply physics to a single portion of delta (no baseline crossing).
     *
     * Determines direction (toward/away from baseline), selects the correct
     * Y resistance and Z decay, and returns the physics-adjusted delta.
     *
     * @param float $x         Current X before this portion
     * @param float $baseline  Rubber band center
     * @param float $rawPortion Raw delta for this portion
     * @param float $z         Z scale
     * @param float $yUp       Y resistance for positive deltas
     * @param float $yDown     Y resistance for negative deltas
     * @param bool  $invertRB  If true, swap toward/away decay logic
     * @return float  Physics-adjusted delta
     */
    private static function applyPortionDelta($x, $baseline, $rawPortion, $z, $yUp, $yDown, $invertRB)
    {
        if (abs($rawPortion) < 0.0001) {
            return 0.0;
        }

        // Direction: is this portion moving X toward or away from baseline?
        $xAfter = $x + $rawPortion;
        $distBefore = abs($x - $baseline);
        $distAfter  = abs($xAfter - $baseline);
        $isTowardBaseline = ($distAfter < $distBefore);

        // Invert rubber band: swap the decay logic
        // Normal: AWAY gets resistance (decay < 1), TOWARD gets boost (decay > 1)
        // Inverted: TOWARD gets resistance, AWAY gets boost (resentment: sticky)
        $decayIsToward = $invertRB ? !$isTowardBaseline : $isTowardBaseline;

        // Calculate Z decay
        $decay = self::calcZDecay($x, $baseline, $z, $decayIsToward);

        // Select Y based on delta direction (positive delta = Y_up, negative = Y_down)
        $yEffective = ($rawPortion > 0) ? $yUp : $yDown;

        return $rawPortion * $yEffective * $decay;
    }

    /**
     * Batch apply multiple deltas at once.
     *
     * Convenience method for applying eval output. Returns a map of
     * dimension => actual_delta for logging.
     *
     * @param array  &$dynamics    NPC dynamics array
     * @param array  $deltas       Map of dimensionId => rawDelta
     * @param string|null $temperament  Temperament name
     * @param array  $overrides    Per-dimension overrides: ['trust' => ['Y_up' => 0.3], ...]
     * @return array  Map of dimensionId => actual_delta
     */
    public static function applyDeltas(&$dynamics, $deltas, $temperament = null, $overrides = [])
    {
        $results = [];
        foreach ($deltas as $dimId => $rawDelta) {
            $dimOverrides = $overrides[$dimId] ?? [];
            $results[$dimId] = self::applyDelta($dimId, $dynamics, $rawDelta, $temperament, $dimOverrides);
        }
        return $results;
    }


    // ========== XYZ EVAL PROCESSING (PR 3) ==========

    /**
     * Mapping from LLM eval JSON field names to XYZ dimension IDs.
     */
    const EVAL_DELTA_MAP = [
        'affinity_delta' => 'affinity',
        'maturity_delta' => 'maturity',
        'trust_delta'    => 'trust',
        'comfort_delta'  => 'comfort',
        'respect_delta'  => 'respect',
        'passion_delta'  => 'passion',
        'warmth_delta'   => 'warmth',
        // ========== AROUSAL/VALENCE (PR 6) ==========
        'arousal_delta'  => 'arousal',
        'valence_delta'  => 'valence',
        // ========== RESENTMENT_SELF (PR 7) ==========
        'resentment_self_delta' => 'resentment_self',
    ];

    /**
     * Process eval deltas from an already-decoded LLM eval result.
     *
     * Each recognised *_delta key is clamped to [-30, +30], fed through
     * applyDelta(), and any matching *_reason is stored on the dimension.
     * Grievances are appended to the resentment pending list.
     *
     * Gated behind the dimension_engine_enabled config toggle.
     *
     * @param string $npcName    NPC name
     * @param array  $evalResult Decoded eval JSON (assoc array)
     * @param array  &$dynamics  NPC dynamics blob (by reference)
     * @return array  Map of dimensionId => actual_delta applied
     */
    public static function processEvalDeltas($npcName, $evalResult, &$dynamics)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }

        if (!is_array($evalResult)) {
            return [];
        }

        $results = [];
        $temperament = $dynamics['inferred_temperament'] ?? null;

        // ========== SIGNIFICANCE SCALING (PR 13) ==========
        $significance = intval($evalResult['significance'] ?? 1);
        $significance = max(1, min(3, $significance)); // Clamp 1-3
        if (empty($config['significance_scaling_enabled'])) {
            $significance = 1;
        }

        foreach (self::EVAL_DELTA_MAP as $jsonKey => $dimId) {
            if (!isset($evalResult[$jsonKey])) {
                continue;
            }

            $raw = floatval($evalResult[$jsonKey]);
            if (abs($raw) < 0.0001) {
                continue;
            }

            // Scale delta by significance, clamp to effective band
            $raw = $raw * $significance;
            $maxBand = 10.0 * $significance;
            $clamped = max(-$maxBand, min($maxBand, $raw));

            // ========== ICK EFFECTS (PR 15) ==========
            // If ick is active, invert passion gains and override comfort
            self::applyIckEffects($dynamics, $dimId, $clamped);

            // ========== CHARISMA EFFECTIVENESS (PR 15) ==========
            // Apply charisma style multiplier to affinity/passion
            $charismaStyle = $dynamics['_charisma_tracker']['detected_style'] ?? null;
            if ($charismaStyle !== null && in_array($dimId, ['affinity', 'passion'], true)) {
                $matForCharisma = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
                $charismaMult = self::getCharismaEffectiveness($charismaStyle, $temperament, $matForCharisma, $dimId);
                if (abs($charismaMult - 1.0) > 0.001) {
                    $clamped *= $charismaMult;
                    error_log("[RelDyn-CHARISMA] {$dimId} multiplied by {$charismaMult} (style={$charismaStyle})");
                }
            }

            // Apply through the XYZ physics engine
            $actual = self::applyDelta($dimId, $dynamics, $clamped, $temperament);
            $results[$dimId] = $actual;

            // Store reason if provided
            $reasonKey = str_replace('_delta', '_reason', $jsonKey);
            if (!empty($evalResult[$reasonKey])) {
                if (!isset($dynamics['dimensions'][$dimId])) {
                    $dynamics['dimensions'][$dimId] = [];
                }
                $dynamics['dimensions'][$dimId]['last_reason'] = $evalResult[$reasonKey];

                // ========== DIMENSIONAL MEMORY (PR 9) ==========
                // Store in rolling memory window for confrontation fuel / diary
                $bondName = $GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player';
                self::storeDimensionalMemory($dynamics, $dimId, $actual, $evalResult[$reasonKey], $bondName);
            }

            error_log("[RelDyn-EVAL] npc={$npcName} {$dimId}={$raw}->{$actual} reason=" . ($evalResult[$reasonKey] ?? '(none)'));
        }

        // Store significance for downstream (tier advancement gating)
        $GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] = $significance;

        // Handle grievance if present (legacy string form; a contract v1 grievance object is
        // applyEvalFeelings()'s)
        if (!empty($evalResult['grievance']) && !self::isEvalContractItem($evalResult)) {
            if (!isset($dynamics['dimensions']['resentment'])) {
                $dynamics['dimensions']['resentment'] = [];
            }
            if (!isset($dynamics['dimensions']['resentment']['pending_grievances'])) {
                $dynamics['dimensions']['resentment']['pending_grievances'] = [];
            }
            $dynamics['dimensions']['resentment']['pending_grievances'][] = $evalResult['grievance'];
            error_log("[RelDyn-EVAL] npc={$npcName} grievance stored: " . $evalResult['grievance']);
        }

        return $results;
    }

    /**
     * Convenience wrapper: decode a JSON string and process eval deltas.
     *
     * @param string $npcName    NPC name
     * @param string $jsonString Raw JSON string from eval worker
     * @param array  &$dynamics  NPC dynamics blob (by reference)
     * @return array  Map of dimensionId => actual_delta applied
     */
    public static function processEvalFromJson($npcName, $jsonString, &$dynamics)
    {
        $decoded = json_decode($jsonString, true);
        if ($decoded === null || !is_array($decoded)) {
            error_log("[RelDyn-EVAL] processEvalFromJson: JSON decode failed for npc={$npcName} error=" . json_last_error_msg());
            return [];
        }

        return self::processEvalDeltas($npcName, $decoded, $dynamics);
    }

    /**
     * Queue an eval result for this NPC (producer side, e.g. an eval worker).
     *
     * Appends to plugin_extended_data.reldyn.eval_inbox in one statement, separate from
     * the dynamics key, so a request saving its dynamics can never clobber it and two
     * producers never lose each other's results.
     *
     * @return bool true when queued
     */
    public static function queuePendingEval($npcName, array $evalResult)
    {
        try {
            $npcId = RelDynStorage::resolveNpcId($npcName);
            if ($npcId === null) {
                error_log("[RelDyn-EVAL] queuePendingEval: unknown NPC '{$npcName}', eval dropped");
                return false;
            }
            return RelDynStorage::appendItem($npcId, RelDynStorage::KEY_EVAL_INBOX, self::evalInboxEntry($evalResult));
        } catch (\Throwable $e) {
            error_log("[RelDyn-EVAL] queuePendingEval failed for {$npcName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * One eval inbox entry: {queued_at, eval}. queued_at is a unix timestamp kept for the
     * logs / editor only; nothing measures a duration with it.
     */
    public static function evalInboxEntry(array $evalResult): array
    {
        return ['queued_at' => time(), 'eval' => $evalResult];
    }

    /**
     * Most recent pending eval without consuming it (inbox first, then the legacy
     * _pending_xyz_eval / _pending_eval keys inside a migrated blob). [] when none.
     */
    public static function peekPendingEval($npcName, $dynamics = [])
    {
        try {
            $npcId = RelDynStorage::resolveNpcId($npcName);
            if ($npcId !== null) {
                $items = RelDynStorage::peekItems($npcId, RelDynStorage::KEY_EVAL_INBOX);
                for ($i = count($items) - 1; $i >= 0; $i--) {
                    if (is_array($items[$i]['eval'] ?? null)) {
                        return $items[$i]['eval'];
                    }
                    if (self::isEvalContractItem($items[$i])) {
                        return $items[$i];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn-EVAL] peekPendingEval failed for {$npcName}: " . $e->getMessage());
        }
        $legacy = $dynamics['_pending_xyz_eval'] ?? $dynamics['_pending_eval'] ?? [];
        return is_array($legacy) ? $legacy : [];
    }

    /** Times one inbox item may fail to apply (throw) before it is dead-lettered (count). */
    const EVAL_ITEM_MAX_FAILURES = 3;

    /**
     * Apply the pending evals queued for this NPC, and SAVE $dynamics.
     *
     * At-least-once with idempotent apply, so no item is ever lost or applied twice:
     *   1. one request at a time applies an NPC's inbox (RelDynStorage::tryLockInbox; a
     *      request that dies drops the lock with its connection);
     *   2. the items are read, not taken, and applied in order to a copy: an item that
     *      throws is not half-applied; it stops the run (later items wait, in order) and is
     *      dead-lettered to eval_inbox_dead after EVAL_ITEM_MAX_FAILURES tries;
     *   3. $dynamics (with the applied items' fingerprints in _eval_applied) is saved;
     *   4. only then are the handled items removed from the inbox. A save that fails
     *      leaves $dynamics as it was and every item in the inbox; a request that dies
     *      after the save leaves items whose fingerprints are stored, skipped next time.
     * A legacy _pending_xyz_eval left in a migrated blob is applied as well (saved with it).
     *
     * @param string $npcName   NPC name
     * @param array  &$dynamics NPC dynamics blob (by reference); saved when anything was handled
     * @return array  Map of dimensionId => summed actual_delta applied, or empty array
     */
    public static function processPendingEvalDeltas($npcName, &$dynamics)
    {
        $GLOBALS['RELDYN_EVAL_FEELINGS'] = [];
        $before = $dynamics;
        $pendingList = [];
        if (isset($dynamics['_pending_xyz_eval'])) {
            if (is_array($dynamics['_pending_xyz_eval'])) {
                $pendingList[] = ['eval' => $dynamics['_pending_xyz_eval'], 'raw' => null];
            }
            unset($dynamics['_pending_xyz_eval']);
        }

        $npcId = RelDynStorage::resolveNpcId($npcName);
        $locked = false;
        if ($npcId !== null) {
            $locked = RelDynStorage::tryLockInbox($npcId);
            if (!$locked) {
                self::log("[EVAL] {$npcName}: another request is applying the eval inbox; left for the next request");
            }
        }
        try {
            $handled = 0;      // inbox items (from the head) that leave the inbox after the save
            $dead = [];        // [{failed_at, error, eval}] to eval_inbox_dead
            if ($locked) {
                // Fingerprints already stored by another request (this copy may be older)
                $stored = RelDynStorage::loadDynamics($npcId);
                if (is_array($stored['_eval_applied'] ?? null)) {
                    $dynamics['_eval_applied'] = array_slice(array_values(array_unique(array_merge(
                        array_values($stored['_eval_applied']), array_values((array) ($dynamics['_eval_applied'] ?? []))))), -self::EVAL_APPLIED_KEEP);
                }
                foreach (RelDynStorage::peekItems($npcId, RelDynStorage::KEY_EVAL_INBOX) as $item) {
                    $pendingList[] = ['eval' => $item, 'raw' => $item];
                }
            }

            $totals = [];
            $feelings = [];
            foreach ($pendingList as $entry) {
                $item = $entry['eval'];
                $fromInbox = $entry['raw'] !== null;
                // {queued_at, eval} from queuePendingEval(), or a bare shared-contract item
                $pending = !$fromInbox ? $item
                    : (is_array($item['eval'] ?? null) ? $item['eval'] : (self::isEvalContractItem($item) ? $item : null));
                if ($pending === null) {
                    error_log("[RelDyn-EVAL] processPendingEvalDeltas: unrecognised inbox item for {$npcName} dropped: " . substr((string) json_encode($item), 0, 300));
                    $handled++;
                    continue;
                }
                if ($fromInbox && self::isEvalContractItem($pending) && is_numeric($pending['npc_id'] ?? null) && intval($pending['npc_id']) !== $npcId) {
                    error_log("[RelDyn-EVAL] processPendingEvalDeltas: item for npc_id {$pending['npc_id']} in the inbox of {$npcName} (id {$npcId}) dropped");
                    $handled++;
                    continue;
                }

                $copy = $dynamics;
                $f = [];
                try {
                    // Contract v1: signals, then grievance / jealousy / positive interaction, once per item
                    $applied = self::isEvalContractItem($pending)
                        ? self::processEvalContractItem($npcName, $pending, $copy, $f)
                        : self::processEvalDeltas($npcName, $pending, $copy);
                } catch (\Throwable $e) {
                    $key = sha1((string) json_encode($item));
                    $fails = is_array($dynamics['_eval_item_failures'] ?? null) ? $dynamics['_eval_item_failures'] : [];
                    $fails[$key] = intval($fails[$key] ?? 0) + 1;
                    $msg = get_class($e) . ': ' . $e->getMessage();
                    error_log("[RelDyn-EVAL] ERROR applying eval item for {$npcName} (failure {$fails[$key]}/" . self::EVAL_ITEM_MAX_FAILURES . "): {$msg}");
                    if (!$fromInbox) {
                        $dynamics['_eval_item_failures'] = array_slice($fails, -self::EVAL_APPLIED_KEEP, null, true);
                        continue;   // legacy blob key: nothing to keep it in
                    }
                    if ($fails[$key] < self::EVAL_ITEM_MAX_FAILURES) {
                        $dynamics['_eval_item_failures'] = array_slice($fails, -self::EVAL_APPLIED_KEEP, null, true);
                        break;      // this item and the ones after it wait for the next request, in order
                    }
                    unset($fails[$key]);
                    $dynamics['_eval_item_failures'] = $fails;
                    $dead[] = ['failed_at' => time(), 'error' => substr($msg, 0, 500), 'eval' => $item];
                    $handled++;
                    continue;
                }
                $dynamics = $copy;
                if ($fromInbox) $handled++;
                foreach ($applied as $dimId => $actual) {
                    $totals[$dimId] = ($totals[$dimId] ?? 0) + $actual;
                }
                if ($f !== []) $feelings[] = $f;
            }

            if ($pendingList === []) {
                return [];
            }
            if (!self::saveDynamics($npcName, $dynamics)) {
                error_log("[RelDyn-EVAL] ERROR {$npcName}: saving the applied eval items failed; they stay in the inbox for the next request");
                $dynamics = $before;
                return [];
            }
            if ($locked) {
                foreach ($dead as $d) {
                    if (!RelDynStorage::appendItem($npcId, RelDynStorage::KEY_EVAL_DEAD, $d)) {
                        error_log("[RelDyn-EVAL] ERROR {$npcName}: could not store a dead-lettered eval item: " . substr((string) json_encode($d), 0, 300));
                    }
                }
                if (!RelDynStorage::dropFirstItems($npcId, RelDynStorage::KEY_EVAL_INBOX, $handled)) {
                    error_log("[RelDyn-EVAL] ERROR {$npcName}: removing {$handled} applied eval item(s) from the inbox failed; their fingerprints skip them next time");
                }
            }
            // For the hook's bystander jealousy scan (romantic_exposure), after the save
            $GLOBALS['RELDYN_EVAL_FEELINGS'] = $feelings;
            return $totals;
        } finally {
            if ($locked) {
                RelDynStorage::unlockInbox($npcId);
            }
        }
    }

    /**
     * The pending eval that belongs to THIS request, for readers that run before
     * processPendingEvalDeltas() (ick, charisma, director goal).
     *
     * Only processPendingEvalDeltas() consumes the eval inbox (and a legacy
     * _pending_xyz_eval left in a migrated blob), and it runs only with the dimension
     * engine enabled. With the engine off the same evals would be re-read on every
     * request and the inbox would grow forever, so they are dropped here instead. The
     * legacy '_pending_eval' key has no consumer at all and is always dropped.
     *
     * @param string $npcName   NPC name
     * @param array  &$dynamics NPC dynamics blob (by reference)
     * @return array  The latest pending eval (not consumed), or [] when there is none / engine is off
     */
    public static function pendingEvalForRequest($npcName, &$dynamics)
    {
        if (array_key_exists('_pending_eval', $dynamics)) {
            unset($dynamics['_pending_eval']);
        }

        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            if (array_key_exists('_pending_xyz_eval', $dynamics)) {
                unset($dynamics['_pending_xyz_eval']);
                self::log("Dimension engine disabled: dropped unprocessed _pending_xyz_eval");
            }
            try {
                $npcId = RelDynStorage::resolveNpcId($npcName);
                if ($npcId !== null) {
                    $dropped = RelDynStorage::takeItems($npcId, RelDynStorage::KEY_EVAL_INBOX);
                    if (!empty($dropped)) {
                        self::log("Dimension engine disabled: dropped " . count($dropped) . " queued eval(s) for {$npcName}");
                    }
                }
            } catch (\Throwable $e) {
                error_log("[RelDyn-EVAL] pendingEvalForRequest: inbox drop failed for {$npcName}: " . $e->getMessage());
            }
            return [];
        }

        return self::peekPendingEval($npcName, $dynamics);
    }


    // ========== EVAL CONTRACT CONSUMER (shared eval contract v1) ==========
    // Every signal of an eval item runs the MDD 15.4 pipeline, with the relative affinity
    // multipliers of decisions 2026-09-23 §1 on affinity:
    //   delta = raw x R_temperament[signal] x P_maturity_type[direction] x M_modifiers (affinity only)
    //         x distance_decay (applyDelta rubber band + MDD 15.4 stage 3 cross-signal caps),
    //   then |delta| <= eval_significance_clamp x significance.
    // Signals are raw: dimension points, affinity in CORE points (-100..100 scale). The affinity
    // result moves the core mirror; commitPlayerAffinity() pushes it to core as a locked delta.

    const EVAL_CONTRACT_VERSION = 1;
    const EVAL_CONTRACT_SOURCE = 'reldyn_eval';

    /**
     * Eval source tag => the love language (LL_* value) it feeds; unlisted tags feed none.
     * The single tag/love-language table: config default affinity_tag_love_language and the
     * producer's RelDynEval::TAG_LOVE_LANGUAGE are both this.
     */
    const EVAL_TAG_LOVE_LANGUAGE = [
        'gift'         => self::LL_GIFTS,
        'praise'       => self::LL_WORDS,
        'reassurance'  => self::LL_WORDS,
        'apology'      => self::LL_WORDS,
        'quality_time' => self::LL_TIME,
        'touch'        => self::LL_TOUCH,
        'intimacy'     => self::LL_TOUCH,
        'help'         => self::LL_SERVICE,
        'rescue'       => self::LL_SERVICE,
    ];

    /**
     * Contract signals in application order => raw range (+-; dimension points, affinity in core
     * points). Affinity goes first so its M_modifiers read maturity, passion and comfort as they
     * were before this item.
     */
    const EVAL_CONTRACT_SIGNALS = [
        'affinity' => 30, 'trust' => 30, 'comfort' => 30, 'respect' => 30, 'passion' => 30, 'maturity' => 10,
    ];

    const EVAL_CONTRACT_TAGS = [
        'gift', 'praise', 'help', 'rescue', 'quality_time', 'touch', 'intimacy', 'insult', 'criticism',
        'neglect', 'jealousy_trigger', 'command', 'betrayal', 'lie', 'competence', 'reassurance', 'apology',
    ];

    /** Fingerprints of applied items kept per NPC (count), so a re-queued copy is skipped. */
    const EVAL_APPLIED_KEEP = 32;

    /**
     * MDD 15.4 stage 1: temperament resistance per signal (unitless multipliers). The MDD lists
     * a 'Volatile' row (not one of MDD 1.3's 13 temperaments) and no 'Humble' row; a temperament
     * without a row resists nothing (1.0). Passion has no column there: getSignalResistance()
     * uses MDD 1.3's passion multiplier (TEMPERAMENT_PASSION_MULT).
     */
    const TEMPERAMENT_SIGNAL_RESISTANCE = [
        'Stoic'       => ['affinity' => 0.5, 'trust' => 0.7, 'comfort' => 0.4, 'respect' => 0.8, 'maturity' => 0.5],
        'Romantic'    => ['affinity' => 1.3, 'trust' => 1.0, 'comfort' => 1.2, 'respect' => 0.8, 'maturity' => 1.0],
        'Anxious'     => ['affinity' => 1.5, 'trust' => 0.6, 'comfort' => 0.5, 'respect' => 0.7, 'maturity' => 1.3],
        'Guarded'     => ['affinity' => 0.6, 'trust' => 0.4, 'comfort' => 0.3, 'respect' => 0.7, 'maturity' => 0.8],
        'Playful'     => ['affinity' => 1.2, 'trust' => 0.9, 'comfort' => 1.4, 'respect' => 0.6, 'maturity' => 0.7],
        'Bold'        => ['affinity' => 0.9, 'trust' => 1.0, 'comfort' => 1.1, 'respect' => 1.3, 'maturity' => 0.9],
        'Volatile'    => ['affinity' => 1.5, 'trust' => 0.5, 'comfort' => 0.5, 'respect' => 0.6, 'maturity' => 1.5],
        'Independent' => ['affinity' => 0.7, 'trust' => 0.8, 'comfort' => 0.5, 'respect' => 1.0, 'maturity' => 0.6],
        'Nurturing'   => ['affinity' => 1.1, 'trust' => 1.1, 'comfort' => 1.3, 'respect' => 0.7, 'maturity' => 0.8],
        'Gentle'      => ['affinity' => 1.0, 'trust' => 0.9, 'comfort' => 1.2, 'respect' => 0.5, 'maturity' => 0.9],
        'Jealous'     => ['affinity' => 1.3, 'trust' => 0.4, 'comfort' => 0.4, 'respect' => 0.9, 'maturity' => 1.2],
        'Proud'       => ['affinity' => 0.7, 'trust' => 0.6, 'comfort' => 0.3, 'respect' => 1.5, 'maturity' => 0.7],
        'Defiant'     => ['affinity' => 1.1, 'trust' => 0.7, 'comfort' => 0.8, 'respect' => 1.2, 'maturity' => 1.0],
    ];

    /** R_temperament[signal] (MDD 15.4; passion: MDD 1.3). Unitless. */
    public static function getSignalResistance($temperament, string $signal): float
    {
        if ($signal === 'passion') {
            return (float) (self::TEMPERAMENT_PASSION_MULT[$temperament] ?? 1.0);
        }
        return (float) (self::TEMPERAMENT_SIGNAL_RESISTANCE[$temperament][$signal] ?? 1.0);
    }

    /**
     * The NPC's maturity type (MDD 15.6): an active arc override (_plasticity_override until
     * its raw game-calendar expiry, as applyDelta reads it), else the stored type, else the
     * temperament default.
     */
    public static function resolveMaturityType(array $dynamics): string
    {
        $override = $dynamics['_plasticity_override'] ?? null;
        if (is_string($override) && isset(self::MATURITY_PLASTICITY_VALUES[$override])) {
            $expires = floatval($dynamics['_plasticity_override_expires_gamets'] ?? 0); // raw gamets
            $now = floatval($dynamics['_last_gamets'] ?? 0);                           // raw gamets
            if (!($now > 0 && $now >= $expires)) {
                return $override;
            }
        }
        $stored = $dynamics['dimensions']['maturity']['plasticity_type'] ?? null;
        if (is_string($stored) && isset(self::MATURITY_PLASTICITY_VALUES[$stored])) {
            return $stored;
        }
        return self::getMaturityPlasticityType($dynamics['inferred_temperament'] ?? null);
    }

    /** State a modifier row reads (units in affinityModifierDefaults()). */
    private static function affinityModifierState(array $dynamics, string $state): ?float
    {
        $temperament = $dynamics['inferred_temperament'] ?? null;
        switch ($state) {
            case 'jealousy':
                return floatval($dynamics['jealousy_anger'] ?? 0);
            case 'passion':
                return self::getPassion($dynamics);
            case 'resentment':
                return floatval($dynamics['dimensions']['resentment']['x'] ?? 0);
            case 'maturity':
            case 'comfort':
            case 'trust':
            case 'respect':
                $x = $dynamics['dimensions'][$state]['x'] ?? null;
                return is_numeric($x) ? floatval($x) : self::getTemperamentBaseline($temperament, $state);
            case 'self_confidence':
                // null until the dimension is active (as the cross-signal cap reads it)
                $x = $dynamics['dimensions']['self_confidence']['x'] ?? null;
                return is_numeric($x) ? floatval($x) : null;
        }
        return null;
    }

    const AFFINITY_MODIFIER_STATES = ['maturity', 'jealousy', 'resentment', 'passion', 'comfort', 'trust', 'respect', 'self_confidence'];

    /** Why a configured modifier row cannot be used, or null when it is valid. */
    private static function affinityModifierRowProblem($row): ?string
    {
        if (!is_array($row)) return 'not an object';
        if (!in_array($row['sign'] ?? null, ['gain', 'loss', 'any'], true)) return "sign must be gain, loss or any";
        if (isset($row['tags']) && !is_array($row['tags'])) return 'tags must be a list';
        if (isset($row['requires']) && !is_string($row['requires'])) return 'requires must be a config key';
        if (isset($row['when']) && !is_array($row['when'])) return 'when must be a list';
        foreach ((array) ($row['when'] ?? []) as $cond) {
            if (!is_array($cond)) return 'a when condition is not an object';
            if (isset($cond['state'])) {
                if (!in_array($cond['state'], self::AFFINITY_MODIFIER_STATES, true)) return "unknown state '{$cond['state']}'";
                if (!in_array($cond['op'] ?? null, ['<', '<=', '>', '>='], true) || !is_numeric($cond['value'] ?? null)) {
                    return 'a state condition needs op (< <= > >=) and a numeric value';
                }
            } elseif (!isset($cond['attachment']) && !isset($cond['trait']) && !isset($cond['weather'])
                && !in_array($cond['love_language'] ?? null, ['primary', 'secondary'], true)) {
                return 'unknown condition ' . json_encode($cond);
            }
        }
        $mult = $row['mult'] ?? null;
        if (is_array($mult)) {
            if (!in_array($mult['state'] ?? null, self::AFFINITY_MODIFIER_STATES, true)
                || !is_numeric($mult['ref'] ?? null) || !is_numeric($mult['at_ref'] ?? null) || !is_numeric($mult['per_point'] ?? null)) {
                return 'a linear mult needs a known state and numeric ref, at_ref, per_point';
            }
        } elseif (!is_numeric($mult)) {
            return 'mult must be a number or a linear formula';
        }
        return null;
    }

    private static function affinityConditionHolds(array $dynamics, array $cond, array $tags, array $cfg): bool
    {
        if (isset($cond['state'])) {
            $v = self::affinityModifierState($dynamics, $cond['state']);
            if ($v === null) {
                return false;   // state not active: its rows do not match
            }
            $ref = floatval($cond['value']);
            switch ($cond['op']) {
                case '<':  return $v < $ref;
                case '<=': return $v <= $ref;
                case '>':  return $v > $ref;
                case '>=': return $v >= $ref;
            }
            return false;
        }
        if (isset($cond['attachment'])) {
            return self::getAttachmentStyle($dynamics) === strtolower((string) $cond['attachment']);
        }
        if (isset($cond['trait'])) {
            return self::hasTrait($dynamics, (string) $cond['trait']);
        }
        if (isset($cond['weather'])) {
            return ($dynamics['_internal_weather'] ?? 'clear') === (string) $cond['weather'];
        }
        $ll = $dynamics['love_language_' . $cond['love_language']] ?? null;
        if (empty($ll)) return false;
        $map = is_array($cfg['affinity_tag_love_language'] ?? null) ? $cfg['affinity_tag_love_language'] : [];
        foreach ($tags as $tag) {
            if (($map[$tag] ?? null) === $ll) return true;
        }
        return false;
    }

    /**
     * M_modifiers for one affinity delta (decisions 2026-09-23 §1): the product of every
     * matching row of the configured table (config 'affinity_modifiers'), clamped to
     * affinity_modifier_min..affinity_modifier_max. Rows read traits through hasTrait().
     *
     * @param float $raw  signed raw delta (only its sign matters)
     * @param array $tags eval source tags
     * @return array ['M' => clamped multiplier, 'product' => unclamped, 'rows' => [row id => multiplier]]
     */
    public static function affinityModifiers(array $dynamics, float $raw, array $tags): array
    {
        $out = ['M' => 1.0, 'product' => 1.0, 'rows' => []];
        if (abs($raw) < 0.0001) {
            return $out;
        }
        $sign = $raw > 0 ? 'gain' : 'loss';
        $tags = array_values(array_unique(array_map(fn($t) => strtolower(trim((string) $t)), $tags)));
        $cfg = self::getConfig();
        $rows = $cfg['affinity_modifiers'] ?? null;
        if (!is_array($rows)) {
            error_log("[RelDyn] ERROR affinity_modifiers config is not a list; using the default table");
            $rows = self::affinityModifierDefaults();
        }

        $product = 1.0;
        foreach ($rows as $i => $row) {
            $id = (is_array($row) && isset($row['id'])) ? (string) $row['id'] : "#{$i}";
            $problem = self::affinityModifierRowProblem($row);
            if ($problem !== null) {
                error_log("[RelDyn] ERROR affinity_modifiers row '{$id}' ignored: {$problem}");
                continue;
            }
            if ($row['sign'] !== 'any' && $row['sign'] !== $sign) continue;
            if (!empty($row['requires']) && empty($cfg[$row['requires']])) continue;
            $rowTags = array_map(fn($t) => strtolower(trim((string) $t)), (array) ($row['tags'] ?? []));
            if (!empty($rowTags) && empty(array_intersect($rowTags, $tags))) continue;
            foreach ((array) ($row['when'] ?? []) as $cond) {
                if (!self::affinityConditionHolds($dynamics, $cond, $tags, $cfg)) continue 2;
            }

            $mult = $row['mult'];
            if (is_array($mult)) {
                $state = self::affinityModifierState($dynamics, $mult['state']);
                $mult = floatval($mult['at_ref']) + floatval($mult['per_point']) * ($state - floatval($mult['ref']));
                if (isset($row['mult']['min']) && is_numeric($row['mult']['min'])) $mult = max(floatval($row['mult']['min']), $mult);
                if (isset($row['mult']['max']) && is_numeric($row['mult']['max'])) $mult = min(floatval($row['mult']['max']), $mult);
            }
            $mult = floatval($mult);
            $out['rows'][$id] = $mult;
            $product *= $mult;
        }

        $min = floatval($cfg['affinity_modifier_min'] ?? 0.25);   // unitless
        $max = floatval($cfg['affinity_modifier_max'] ?? 3.0);    // unitless
        $out['product'] = $product;
        $out['M'] = max($min, min($max, $product));
        return $out;
    }

    /**
     * Apply one raw eval signal through the pipeline (see the section comment).
     *
     * @param string $signal       affinity|trust|comfort|respect|passion|maturity
     * @param float  $raw          raw signal (dimension points; affinity in core points), clamped
     *                             to the contract range first
     * @param float  $significance 0..1; |delta| <= eval_significance_clamp x significance
     * @return array ['dimension' => $signal, 'actual' => change of dimensions.<signal>.x (for
     *               affinity: mirror units, core = x2), 'line' => the math, as logged]
     */
    public static function applyEvalSignal($npcName, array &$dynamics, string $signal, float $raw, array $tags, float $significance): array
    {
        $result = ['dimension' => $signal, 'actual' => 0.0, 'line' => ''];
        $range = self::EVAL_CONTRACT_SIGNALS[$signal] ?? null;
        if ($range === null) {
            error_log("[RelDyn-EVAL] applyEvalSignal: unknown signal '{$signal}' for {$npcName} ignored");
            return $result;
        }
        $input = $raw;
        $raw = max(-$range, min($range, $raw));
        if (abs($raw) < 0.0001) {
            return $result;
        }
        $clampNote = abs($input - $raw) > 0.0001 ? sprintf(' (clamped from %+.2f)', $input) : '';
        $rawIn = $raw;
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $steps = '';

        // Ick (PR 15): passion gains invert, comfort is forced negative
        $beforeIck = $raw;
        if (self::applyIckEffects($dynamics, $signal, $raw) && abs($raw - $beforeIck) > 0.0001) {
            $steps .= sprintf(' ick->%+.2f', $raw);
        }
        // Charisma effectiveness (PR 15) on affinity and passion
        $style = $dynamics['_charisma_tracker']['detected_style'] ?? null;
        if ($style !== null && in_array($signal, ['affinity', 'passion'], true)) {
            $mat = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
            $cm = self::getCharismaEffectiveness($style, $temperament, $mat, $signal);
            if (abs($cm - 1.0) > 0.001) {
                $raw *= $cm;
                $steps .= sprintf(' charisma(%s)x%.2f', $style, $cm);
            }
        }
        if (abs($raw) < 0.0001) {
            return $result;
        }
        // Shared activity (decisions §6, MDD 1.2 / 1.5): a passion gain carries the place being
        // shared, 0.5x-2.0x by its appraisal and 0.7x on a bad date. The love language comes
        // from the first tag that maps to one.
        if ($signal === 'passion' && $raw > 0) {
            $ll = null;
            $tagLL = (array) self::configValue('affinity_tag_love_language');
            foreach ($tags as $t) {
                if (isset($tagLL[strtolower((string) $t)])) { $ll = $tagLL[strtolower((string) $t)]; break; }
            }
            $pm = RelDynFacets::sharedActivityPassionMult($dynamics, $ll);
            if (abs($pm - 1.0) > 0.001) {
                $raw *= $pm;
                $steps .= sprintf(' place x%.2f', $pm);
            }
        }

        $R = self::getSignalResistance($temperament, $signal);
        $type = self::resolveMaturityType($dynamics);
        $dirKey = $raw > 0 ? 'Y_up' : 'Y_down';
        $P = floatval(self::MATURITY_PLASTICITY_VALUES[$type][$dirKey]);
        $M = 1.0;
        $mText = '';
        $overrides = [];
        if ($signal === 'affinity') {
            // The mirror must track core before it moves, or commitPlayerAffinity() has no
            // marker to measure RelDyn's change against.
            if (!is_numeric($dynamics['_aff_mirror_x'] ?? null)) {
                $rel = self::getPlayerRelationship($npcName);
                self::refreshAffinityMirror($dynamics, intval($rel['aff'] ?? 0));
                self::log("[EVAL] {$npcName}: affinity mirror read from core (aff " . intval($rel['aff'] ?? 0) . ')');
            }
            $mods = self::affinityModifiers($dynamics, $raw, $tags);
            $M = $mods['M'];
            $parts = [];
            foreach ($mods['rows'] as $id => $mult) {
                $parts[] = sprintf('%s %.3f', $id, $mult);
            }
            $mText = sprintf(' x M=%.3f [%s%s]', $M, implode(' x ', $parts) ?: 'no rows',
                abs($mods['product'] - $M) > 1e-9 ? sprintf(' = %.3f, clamped', $mods['product']) : '');
            // resentment > 50 is the M row resentment_blocks_gains, low self-confidence the row
            // low_self_confidence_losses: M (clamped 0.25..3.0) holds them, not the caps again
            $overrides['skip_caps'] = ['resentment_affinity_gain', 'low_self_confidence_losses'];
        }

        $y = $R * $P * $M;
        $overrides['Y_up'] = $y;
        $overrides['Y_down'] = $y;
        $significance = max(0.0, min(1.0, $significance));
        $clampPoints = max(0.0, floatval(self::configValue('eval_significance_clamp')));  // points at significance 1
        $overrides['max_abs'] = $clampPoints * $significance;

        $actual = self::applyDelta($signal, $dynamics, $raw, $temperament, $overrides);
        $result['actual'] = $actual;

        // Physics units: core points for affinity (mirror x2), dimension points otherwise
        $moved = ($signal === 'affinity') ? $actual * 2.0 : $actual;
        $pre = $raw * $y;
        $rest = abs($pre) > 1e-9 ? $moved / $pre : 0.0;
        $unit = ($signal === 'affinity') ? ' core' : '';
        $result['line'] = sprintf(
            '[EVAL-MATH] %s %s: raw %+.2f%s%s tags=[%s] x R(%s)=%.2f x P(%s %s)=%.2f%s = %+.3f; x decay/caps %.3f, |d|<=%.1f (sig %.2f) => %+.3f%s',
            $npcName, $signal, $rawIn, $clampNote, $steps,
            implode(',', $tags), $temperament ?? 'none', $R, $type, $raw > 0 ? 'up' : 'down', $P, $mText,
            $pre, $rest, $overrides['max_abs'], $significance, $moved, $unit
        );
        self::log($result['line']);
        return $result;
    }

    /** True for an item shaped like the shared eval contract (validated by normalizeEvalContractItem). */
    public static function isEvalContractItem($item): bool
    {
        return is_array($item) && array_key_exists('v', $item) && array_key_exists('signals', $item);
    }

    /**
     * Validate a shared-eval-contract item and bring it into range. Returns null (logged) for
     * an item that cannot be applied; out-of-range values are clamped and unknown tags dropped
     * (both logged). Not a contract item at all (legacy *_delta shape): null, not logged.
     */
    public static function normalizeEvalContractItem($item): ?array
    {
        if (!self::isEvalContractItem($item)) {
            return null;
        }
        $reject = function (string $why) use ($item): ?array {
            error_log("[RelDyn-EVAL] eval item rejected ({$why}): " . substr((string) json_encode($item), 0, 300));
            return null;
        };
        if (!is_numeric($item['v']) || intval($item['v']) !== self::EVAL_CONTRACT_VERSION) {
            return $reject('contract version ' . json_encode($item['v']) . ', expected ' . self::EVAL_CONTRACT_VERSION);
        }
        if (($item['source'] ?? null) !== self::EVAL_CONTRACT_SOURCE) {
            return $reject('source ' . json_encode($item['source'] ?? null) . ', expected ' . self::EVAL_CONTRACT_SOURCE);
        }
        if (!is_string($item['npc'] ?? null) || trim($item['npc']) === '') {
            return $reject('no npc name');
        }
        if (!is_array($item['signals'])) {
            return $reject('signals is not an object');
        }
        if (!is_numeric($item['significance'] ?? null)) {
            return $reject('significance is not a number');
        }
        $npc = trim($item['npc']);

        $signals = [];
        foreach (self::EVAL_CONTRACT_SIGNALS as $signal => $range) {
            if (!array_key_exists($signal, $item['signals'])) {
                error_log("[RelDyn-EVAL] eval item for {$npc}: signal {$signal} missing, read as 0");
                $signals[$signal] = 0.0;
                continue;
            }
            $v = $item['signals'][$signal];
            if (!is_numeric($v)) {
                return $reject("signal {$signal} is not a number");
            }
            $v = floatval($v);
            if ($v > $range || $v < -$range) {
                error_log("[RelDyn-EVAL] eval item for {$npc}: signal {$signal} {$item['signals'][$signal]} outside -{$range}..{$range}, clamped");
                $v = floatval(max(-$range, min($range, $v)));
            }
            $signals[$signal] = $v;
        }
        foreach (array_diff(array_keys($item['signals']), array_keys(self::EVAL_CONTRACT_SIGNALS)) as $extra) {
            error_log("[RelDyn-EVAL] eval item for {$npc}: unknown signal '{$extra}' ignored");
        }

        $tags = [];
        foreach ((array) ($item['tags'] ?? []) as $tag) {
            $t = strtolower(trim((string) $tag));
            if (!in_array($t, self::EVAL_CONTRACT_TAGS, true)) {
                error_log("[RelDyn-EVAL] eval item for {$npc}: unknown tag '{$t}' dropped");
                continue;
            }
            $tags[$t] = true;
        }

        $significance = floatval($item['significance']);
        if ($significance < 0.0 || $significance > 1.0) {
            error_log("[RelDyn-EVAL] eval item for {$npc}: significance {$significance} outside 0..1, clamped");
            $significance = max(0.0, min(1.0, $significance));
        }

        $g = is_array($item['grievance'] ?? null) ? $item['grievance'] : [];
        $j = is_array($item['jealousy'] ?? null) ? $item['jealousy'] : [];
        return [
            'v' => self::EVAL_CONTRACT_VERSION,
            'npc' => $npc,
            'npc_id' => is_numeric($item['npc_id'] ?? null) ? intval($item['npc_id']) : null,
            'gamets' => is_numeric($item['gamets'] ?? null) ? intval($item['gamets']) : null,
            'source' => self::EVAL_CONTRACT_SOURCE,
            'signals' => $signals,
            'tags' => array_keys($tags),
            'grievance' => [
                'flag' => !empty($g['flag']),
                'kind' => is_string($g['kind'] ?? null) ? $g['kind'] : null,
                'severity' => max(0, min(3, intval($g['severity'] ?? 0))),
            ],
            'jealousy' => [
                'flag' => !empty($j['flag']),
                'rival' => is_string($j['rival'] ?? null) ? $j['rival'] : null,
                'intensity' => max(0, min(3, intval($j['intensity'] ?? 0))),
            ],
            'significance' => $significance,
            'positive_interaction' => !empty($item['positive_interaction']),
            'summary' => is_string($item['summary'] ?? null) ? $item['summary'] : '',
            // optional (additive to v1): names present at the exchange; null = not recorded
            'witnesses' => is_array($item['witnesses'] ?? null)
                ? array_values(array_filter(array_map(fn($w) => trim((string) $w), array_filter($item['witnesses'], 'is_string')), fn($w) => $w !== ''))
                : null,
        ];
    }

    /**
     * Apply one shared-eval-contract item to $dynamics: every non-zero signal through
     * applyEvalSignal() (in EVAL_CONTRACT_SIGNALS order), then its grievance / jealousy /
     * positive_interaction through applyEvalFeelings() (resentment accumulator, MDD 15.5).
     * An item already applied to this NPC (same fingerprint in _eval_applied) is skipped
     * whole. The caller commits affinity (commitPlayerAffinity) and saves.
     *
     * @param array|null &$feelings set to applyEvalFeelings()'s result ([] when not applied)
     * @return array dimension => actual change (dimension units; affinity in mirror units)
     */
    public static function processEvalContractItem($npcName, array $item, array &$dynamics, ?array &$feelings = null): array
    {
        $feelings = [];
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }
        $n = self::normalizeEvalContractItem($item);
        if ($n === null) {
            return [];
        }
        if (strcasecmp($n['npc'], trim((string) $npcName)) !== 0) {
            error_log("[RelDyn-EVAL] item for '{$n['npc']}' in the inbox of '{$npcName}' dropped");
            return [];
        }

        $fingerprint = sha1((string) json_encode([
            strtolower($n['npc']), $n['npc_id'], $n['gamets'], $n['signals'], $n['tags'],
            $n['grievance'], $n['jealousy'], $n['significance'], $n['summary'],
        ]));
        $applied = is_array($dynamics['_eval_applied'] ?? null) ? array_values($dynamics['_eval_applied']) : [];
        if (in_array($fingerprint, $applied, true)) {
            error_log("[RelDyn-EVAL] {$npcName}: eval item already applied (gamets {$n['gamets']}), skipped: {$n['summary']}");
            return [];
        }

        $totals = [];
        foreach (self::EVAL_CONTRACT_SIGNALS as $signal => $_range) {
            $raw = $n['signals'][$signal];
            if (abs($raw) < 0.0001) {
                continue;
            }
            $r = self::applyEvalSignal($npcName, $dynamics, $signal, $raw, $n['tags'], $n['significance']);
            $totals[$signal] = $r['actual'];
            // What the legacy path fed downstream: the reason per moved dimension (context
            // <recent_emotional_shifts>) and the dimensional memory (confrontation / diary fuel)
            if (abs($r['actual']) >= 0.0001 && $n['summary'] !== '') {
                $dynamics['dimensions'][$signal]['last_reason'] = $n['summary'];
                $dynamics['dimensions'][$signal]['last_delta'] = $r['actual'];
                $bondName = $GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player';
                self::storeDimensionalMemory($dynamics, $signal, $r['actual'], $n['summary'], $bondName);
            }
        }
        // Interaction significance for the diary's defining_moment trigger, on the legacy 1..3
        // level scale: contract significance 0..1 x 3, rounded (0.33, "normal +-10 of 30" -> 1;
        // 1.0 -> 3). The strongest item of this request counts.
        $level = max(1, min(3, (int) round($n['significance'] * 3)));
        $GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] = max($level, intval($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] ?? 0));

        // Grievance / jealousy / positive interaction (resentment, conflict): once per accepted
        // item, after its signals. A rejected, misaddressed or already-applied item never gets here.
        $feelings = self::applyEvalFeelings((string) $npcName, $n, $dynamics);

        $applied[] = $fingerprint;
        $dynamics['_eval_applied'] = array_slice($applied, -self::EVAL_APPLIED_KEEP);
        self::log("[EVAL] {$npcName} item gamets={$n['gamets']} sig={$n['significance']} tags=[" . implode(',', $n['tags']) . "] "
            . "applied " . json_encode($totals) . ($n['grievance']['flag'] ? " grievance={$n['grievance']['kind']}" : '')
            . ($feelings !== [] ? ' feelings ' . json_encode($feelings) : '')
            . " ({$n['summary']})");
        return $totals;
    }


    // ========== RESENTMENT DIMENSION (PR 7) ==========

    /**
     * Process pending grievances into resentment buildup.
     *
     * Legacy pending list (pre-contract evals): each grievance adds grievance_resentment_raw
     * (MDD 15.5: +5) to resentment via applyDelta, whose cross-signal caps apply the 15.5
     * suppressed-buildup +50% once. Grievance text is stored in a rolling window (last 10).
     * Contract v1 items go through recordGrievance() instead.
     *
     * @param array  &$dynamics    NPC dynamics blob (by reference)
     * @param string|null $temperament Temperament name
     * @return int Number of grievances processed
     */
    public static function processGrievances(&$dynamics, $temperament = null)
    {
        if (!isset($dynamics['dimensions']['resentment'])) {
            return 0;
        }

        $resentment = &$dynamics['dimensions']['resentment'];
        $pending = $resentment['pending_grievances'] ?? [];

        if (empty($pending)) {
            return 0;
        }

        // Ensure grievance_log exists
        if (!isset($resentment['grievance_log'])) {
            $resentment['grievance_log'] = [];
        }

        $processed = 0;

        foreach ($pending as $grievanceText) {
            // MDD 15.5: +5 raw resentment points. The suppressed-buildup +50% (resentment > 30,
            // maturity < 50) is applied once, in applyCrossSignalCaps.
            $baseAmount = floatval(self::configValue('grievance_resentment_raw'));

            // Apply through XYZ physics (cross-signal caps + rubber band)
            $actual = self::applyDelta('resentment', $dynamics, $baseAmount, $temperament);

            // Store grievance in rolling log (keep last 10)
            $resentment['grievance_log'][] = [
                'text' => is_string($grievanceText) ? $grievanceText : json_encode($grievanceText),
                'timestamp' => time(),
                'amount' => round($actual, 2),
            ];
            if (count($resentment['grievance_log']) > 10) {
                $resentment['grievance_log'] = array_slice($resentment['grievance_log'], -10);
            }

            $currentVal = round(floatval($resentment['x'] ?? 0), 2);
            $npcName = $dynamics['_npc_name'] ?? 'unknown';
            error_log("[RelDyn-RESENTMENT] Grievance processed for {$npcName}: +{$actual} resentment, now at {$currentVal}");

            $processed++;
        }

        // Clear pending grievances after processing
        $resentment['pending_grievances'] = [];

        return $processed;
    }

    /**
     * Process natural resentment decay from a positive interaction.
     *
     * Applies -1 via applyDelta. The inverted rubber band resists this recovery,
     * making resentment sticky even with positive interactions. Rate-limited to
     * once per 15 accumulated minutes of actual play time.
     *
     * @param array  &$dynamics               NPC dynamics blob (by reference)
     * @param string|null $temperament         Temperament name
     * @param bool   $wasPositiveInteraction   Whether the interaction was positive
     * @return float Actual decay applied (0 if skipped)
     */
    public static function processResentmentDecay(&$dynamics, $temperament = null, $wasPositiveInteraction = false)
    {
        if (!$wasPositiveInteraction) {
            return 0.0;
        }

        if (!isset($dynamics['dimensions']['resentment'])) {
            return 0.0;
        }

        $resentment = &$dynamics['dimensions']['resentment'];
        $currentX = floatval($resentment['x'] ?? 0);

        // Nothing to decay
        if ($currentX <= 0) {
            return 0.0;
        }

        // Rate limit: cooldown using gamets-based filtered play time (~15 real min)
        // Falls back to IRL accumulated time if gamets data not yet available
        $playGamets = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
        $lastPlayGamets = floatval($dynamics['_resentment_last_play_gamets'] ?? 0);
        if ($playGamets > 0 || $lastPlayGamets > 0) {
            // Gamets path: use filtered play time
            if (($playGamets - $lastPlayGamets) < self::GAMETS_RESENTMENT_COOLDOWN) {
                return 0.0; // Debounce: ~15 real min of gameplay between decays
            }
        } else {
            // Fallback: IRL accumulated time (backward compat until gamets populates)
            $accumulated = intval($dynamics['_accumulated_time'] ?? 0);
            $lastAccumulated = intval($dynamics['_resentment_last_accumulated'] ?? 0);
            if (($accumulated - $lastAccumulated) < 900) {
                return 0.0; // Debounce: 15 accumulated minutes between decays
            }
        }

        // Apply -1 through XYZ physics (inverted RB will resist this!)
        $actual = self::applyDelta('resentment', $dynamics, -1.0, $temperament);

        $dynamics['_resentment_last_accumulated'] = intval($dynamics['_accumulated_time'] ?? 0);
        $dynamics['_resentment_last_play_gamets'] = $playGamets;
        $resentment['last_decay_tick'] = intval($dynamics['_accumulated_time'] ?? 0); // Keep for backward compat

        $currentVal = round(floatval($resentment['x'] ?? 0), 2);
        $npcName = $dynamics['_npc_name'] ?? 'unknown';
        error_log("[RelDyn-RESENTMENT] Natural decay: {$actual} resentment, now at {$currentVal}");

        return $actual;
    }

    /**
     * Process a confrontation event where the NPC expresses a grievance.
     *
     * Applies -10 via applyDelta (addressed decay). Clears the oldest grievance
     * from the log. This is the intended "healthy" way resentment resolves —
     * the NPC speaks up about what's been bothering them.
     *
     * @param array  &$dynamics    NPC dynamics blob (by reference)
     * @param string|null $temperament Temperament name
     * @return float Actual decay applied
     */
    public static function processResentmentConfrontation(&$dynamics, $temperament = null)
    {
        if (!isset($dynamics['dimensions']['resentment'])) {
            return 0.0;
        }

        $resentment = &$dynamics['dimensions']['resentment'];
        $currentX = floatval($resentment['x'] ?? 0);

        // Nothing to confront
        if ($currentX <= 0) {
            return 0.0;
        }

        // Apply -10 through XYZ physics (inverted RB still resists, but -10 is strong)
        $actual = self::applyDelta('resentment', $dynamics, -10.0, $temperament);

        // Clear oldest grievance from log
        if (!empty($resentment['grievance_log'])) {
            array_shift($resentment['grievance_log']);
        }

        $currentVal = round(floatval($resentment['x'] ?? 0), 2);
        $npcName = $dynamics['_npc_name'] ?? 'unknown';
        error_log("[RelDyn-RESENTMENT] Confrontation: {$actual} resentment, now at {$currentVal}");

        return $actual;
    }

    // ========== END RESENTMENT DIMENSION (PR 7) ==========

    // ========== EVAL FEELINGS: RESENTMENT, JEALOUSY, CONFLICT (decisions §5, MDD 15.5 / 6.5) ==========

    /** MDD 15.5 suppressed buildup: resentment (0..100) above this ... */
    const RESENTMENT_SUPPRESSED_ABOVE = 30;
    /** ... and maturity (0..100) below this ... */
    const RESENTMENT_SUPPRESSED_MATURITY_BELOW = 50;
    /** ... multiply resentment gains by this. */
    const RESENTMENT_SUPPRESSED_MULT = 1.5;

    /** MDD 15.5 threshold events, resentment points (0..100), reached at >= the value. */
    const RESENTMENT_PASSIVE_AGGRESSIVE_AT = 30;   // tone leaks into keywords
    const RESENTMENT_CONFRONTATION_AT = 50;        // NPC initiates confrontation (P3, Director)
    const RESENTMENT_WITHDRAWAL_AT = 70;           // affinity frozen, comfort drops to 0
    const RESENTMENT_WALKAWAY_AT = 90;             // the walkaway
    /** MDD 15.4 cross-signal: resentment strictly above this halves affinity gains. */
    const RESENTMENT_HALVES_GAINS_ABOVE = 50;

    /**
     * MDD 15.5 at 70: emotional withdrawal, comfort drops to 0 and stays there while the NPC
     * is withdrawn (affinity is frozen by getResentmentEffects()'s affinity_gain_mult). Run
     * after every resentment or comfort change (applyDelta, the calendar conversion).
     * Returns true when it lowered comfort.
     */
    public static function enforceResentmentWithdrawal(array &$dynamics): bool
    {
        if (!self::getResentmentEffects($dynamics)['withdrawn']) {
            return false;
        }
        $comfort = $dynamics['dimensions']['comfort']['x'] ?? null;
        if (is_numeric($comfort) && floatval($comfort) <= 0.0) {
            return false;
        }
        if (!isset($dynamics['dimensions']['comfort']) || !is_array($dynamics['dimensions']['comfort'])) {
            $dynamics['dimensions']['comfort'] = [];
        }
        $dynamics['dimensions']['comfort']['x'] = 0.0;
        error_log("[RelDyn-RESENTMENT] withdrawal (resentment " . round(floatval($dynamics['dimensions']['resentment']['x'] ?? 0), 2)
            . " >= " . self::RESENTMENT_WITHDRAWAL_AT . "): comfort " . (is_numeric($comfort) ? round(floatval($comfort), 2) : 'unset') . " -> 0");
        return true;
    }

    /**
     * MDD 15.5 effects of the current resentment, the one accessor every consumer reads (the
     * affinity pipeline's gain multiplier, context, the P3 confrontation). Pure.
     *
     * @return array{resentment: float, passive_aggressive: bool, confrontation_due: bool,
     *               withdrawn: bool, walkaway: bool, affinity_gain_mult: float}
     *         affinity_gain_mult multiplies affinity GAINS only: 0.5 above 50, 0.0 (frozen) at 70+.
     */
    public static function getResentmentEffects(array $dynamics): array
    {
        $r = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);   // 0..100
        $gainMult = 1.0;
        if ($r >= self::RESENTMENT_WITHDRAWAL_AT) {
            $gainMult = 0.0;
        } elseif ($r > self::RESENTMENT_HALVES_GAINS_ABOVE) {
            $gainMult = 0.5;
        }
        return [
            'resentment'         => $r,
            'passive_aggressive' => $r >= self::RESENTMENT_PASSIVE_AGGRESSIVE_AT,
            'confrontation_due'  => $r >= self::RESENTMENT_CONFRONTATION_AT,
            'withdrawn'          => $r >= self::RESENTMENT_WITHDRAWAL_AT,
            'walkaway'           => $r >= self::RESENTMENT_WALKAWAY_AT,
            'affinity_gain_mult' => $gainMult,
        ];
    }

    /** Low self-respect + low maturity (MDD autonomy design, PR 16 caps): suffers inward. */
    public static function isPeoplePleaser(array $dynamics): bool
    {
        $dims = $dynamics['dimensions'] ?? [];
        return floatval($dims['self_confidence']['x'] ?? 50) < self::PEOPLE_PLEASER_CONFIDENCE_CAP
            && floatval($dims['maturity']['x'] ?? 50) < self::PEOPLE_PLEASER_MATURITY_CAP;
    }

    /**
     * Facts power_gap is derived from, all read from core data:
     *  - core_type: core relationships.Player.type (stored by prerequest as _core_rel_type);
     *  - in_party:  the NPC is in the player's current party (conf_opts CurrentParty via
     *               CHIM's CACHE_PARTY / DataGetCurrentPartyConf): a commanded follower;
     *  - factions:  core_npc_master.extended_data.factions ([{name, rank}], plugin editor ids).
     * A failed read is logged and that fact counts as absent.
     */
    public static function powerGapFacts(string $npcName, array $dynamics): array
    {
        $facts = ['core_type' => $dynamics['_core_rel_type'] ?? null, 'in_party' => false, 'factions' => []];

        $party = $GLOBALS['CACHE_PARTY'] ?? null;
        if ($party === null && function_exists('DataGetCurrentPartyConf') && !empty($GLOBALS['db'])) {
            try {
                $party = DataGetCurrentPartyConf();
            } catch (\Throwable $e) {
                error_log("[RelDyn-RESENTMENT] power gap: party read failed for {$npcName}: " . $e->getMessage());
            }
        }
        $party = is_string($party) ? json_decode($party, true) : $party;
        if (is_array($party)) {
            foreach ($party as $key => $member) {
                $name = is_array($member) ? ($member['name'] ?? $key) : $key;
                if (is_string($name) && strcasecmp(trim($name), trim($npcName)) === 0) {
                    $facts['in_party'] = true;
                    break;
                }
            }
        }

        if (!empty($GLOBALS['db'])) {
            try {
                $row = self::fetchCoreProfileRow($npcName);
                $ext = json_decode((string) ($row['extended_data'] ?? ''), true);
                if (is_array($ext) && is_array($ext['factions'] ?? null)) {
                    $facts['factions'] = $ext['factions'];
                }
            } catch (\Throwable $e) {
                error_log("[RelDyn-RESENTMENT] power gap: core_npc_master read failed for {$npcName}: " . $e->getMessage());
            }
        }
        return $facts;
    }

    /**
     * power_gap (0..1, decisions §5): how little the NPC can leave. The largest matching
     * source counts (sources are not added: a servant in the party is still 1.0). Pure.
     *
     * @return array{gap: float, sources: string[]}
     */
    public static function computePowerGap(array $facts): array
    {
        $gap = 0.0;
        $sources = [];
        $take = function (float $g, string $why) use (&$gap, &$sources) {
            if ($g <= 0) return;
            $sources[] = $why;
            $gap = max($gap, $g);
        };

        $type = strtolower(trim((string) ($facts['core_type'] ?? '')));
        $byType = (array) self::configValue('power_gap_core_types');
        if ($type !== '' && isset($byType[$type])) {
            $take(floatval($byType[$type]), "type:{$type}");
        }
        if (!empty($facts['in_party'])) {
            $take(floatval(self::configValue('power_gap_in_party')), 'party');
        }
        foreach ((array) ($facts['factions'] ?? []) as $faction) {
            if (!is_array($faction) || intval($faction['rank'] ?? 0) < 0) continue;   // rank -1: not a member
            $key = self::profileMatchKey($faction['name'] ?? '');
            foreach ((array) self::configValue('power_gap_factions') as $rule) {
                foreach ((array) ($rule['match'] ?? []) as $needle) {
                    if ($key !== '' && $needle !== '' && str_contains($key, self::profileMatchKey($needle))) {
                        $take(floatval($rule['gap'] ?? 0), "faction:" . ($faction['name'] ?? ''));
                        continue 3;
                    }
                }
            }
        }
        return ['gap' => max(0.0, min(1.0, $gap)), 'sources' => $sources];
    }

    /**
     * One flagged grievance (not jealousy) into the accumulator (MDD 15.5, decisions §5):
     *   raw = grievance_resentment_raw (5) x grievance_severity_mult[severity]
     *         x (1 + power_gap) x post-hoover multiplier (_hoover_resentment_mult, >= 1)
     * through applyDelta (suppressed +50%, inverted rubber band). A people-pleaser
     * (isPeoplePleaser) takes it as resentment_self instead. Logged in grievance_log (last 10).
     *
     * @return array{raw: float, amount: float, target: string, power_gap: float, severity: int}
     */
    public static function recordGrievance(array &$dynamics, array $grievance, array $powerFacts, string $summary = ''): array
    {
        $severity = max(0, min(3, intval($grievance['severity'] ?? 0)));
        $sevMult = floatval(((array) self::configValue('grievance_severity_mult'))[$severity] ?? 1.0);
        $gap = self::computePowerGap($powerFacts);
        $hoover = max(1.0, floatval($dynamics['_hoover_resentment_mult'] ?? 1.0));
        $raw = floatval(self::configValue('grievance_resentment_raw')) * $sevMult * (1.0 + $gap['gap']) * $hoover;   // raw resentment points

        $target = self::isPeoplePleaser($dynamics) ? 'resentment_self' : 'resentment';
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $amount = self::applyDelta($target, $dynamics, $raw, $temperament);

        if (!isset($dynamics['dimensions']['resentment']) || !is_array($dynamics['dimensions']['resentment'])) {
            $dynamics['dimensions']['resentment'] = ['x' => 0, 'baseline' => 0, 'active' => true];
        }
        $kind = is_string($grievance['kind'] ?? null) ? $grievance['kind'] : null;
        $log = (array) ($dynamics['dimensions']['resentment']['grievance_log'] ?? []);
        $log[] = [
            'text'      => $summary !== '' ? $summary : ($kind ?? 'grievance'),
            'kind'      => $kind,
            'severity'  => $severity,
            'power_gap' => $gap['gap'],
            'raw'       => round($raw, 4),
            'amount'    => round($amount, 4),
            'target'    => $target,
            'gamets'    => self::currentGamets(),   // raw game-calendar gamets (0 when unknown)
        ];
        $dynamics['dimensions']['resentment']['grievance_log'] = array_slice($log, -10);

        error_log("[RelDyn-RESENTMENT] grievance kind=" . ($kind ?? '-') . " severity={$severity} power_gap={$gap['gap']} ("
            . implode(',', $gap['sources']) . ") raw=" . round($raw, 3) . " -> {$target} +" . round($amount, 3));
        return ['raw' => $raw, 'amount' => $amount, 'target' => $target, 'power_gap' => $gap['gap'], 'severity' => $severity];
    }

    /**
     * Apply the feelings side of one contract v1 eval item to this NPC (signals are the
     * affinity/dimension consumer's): grievance -> resentment (or jealousy for jealousy
     * kinds), jealousy events, and positive_interaction -> resentment -1 and conflict repair.
     * Call once per item. Returns [] for a non-contract item.
     *
     * @return array{grievance: ?array, jealousy: float, resentment_decay: float, repair_burst: float, romantic_exposure: bool, witnesses: ?array}
     *         witnesses: the item's names present at the exchange (null when it recorded none)
     */
    public static function applyEvalFeelings(string $npcName, $item, array &$dynamics): array
    {
        if (!self::isEvalContractItem($item) || intval($item['v']) !== self::EVAL_CONTRACT_VERSION
            || ($item['source'] ?? null) !== self::EVAL_CONTRACT_SOURCE) {
            return [];
        }
        unset($dynamics['_eval_feelings_seen']);   // retired flag (per exchange now: postrequest $evalOwnsExchange)
        $out = ['grievance' => null, 'jealousy' => 0.0, 'resentment_decay' => 0.0, 'repair_burst' => 0.0, 'romantic_exposure' => false,
                'witnesses' => is_array($item['witnesses'] ?? null) ? array_values(array_filter($item['witnesses'], 'is_string')) : null];
        $tags = array_map(fn($t) => strtolower(trim((string) $t)), array_filter((array) ($item['tags'] ?? []), 'is_scalar'));
        $summary = is_string($item['summary'] ?? null) ? $item['summary'] : '';
        $temperament = $dynamics['inferred_temperament'] ?? null;

        // Jealousy sources: the jealousy object, the jealousy_trigger tag, a jealousy-kind grievance.
        $jealous = is_array($item['jealousy'] ?? null) ? $item['jealousy'] : [];
        $intensity = !empty($jealous['flag']) ? max(0, min(3, intval($jealous['intensity'] ?? 0))) : null;
        $rival = is_string($jealous['rival'] ?? null) && trim($jealous['rival']) !== '' ? trim($jealous['rival']) : null;
        if ($intensity === null && in_array('jealousy_trigger', $tags, true)) {
            $intensity = 1;
        }

        $grievance = is_array($item['grievance'] ?? null) ? $item['grievance'] : [];
        if (!empty($grievance['flag'])) {
            $kind = strtolower(trim((string) ($grievance['kind'] ?? '')));
            $jealousyKinds = array_map('strtolower', (array) self::configValue('jealousy_grievance_kinds'));
            if ($kind !== '' && in_array($kind, $jealousyKinds, true)) {
                // Jealousy is not a grievance: it feeds resentment through the calendar conversion (§5)
                $intensity = max($intensity ?? 0, max(0, min(3, intval($grievance['severity'] ?? 0))));
            } elseif (self::configValue('dimension_engine_enabled')) {
                $out['grievance'] = self::recordGrievance($dynamics, $grievance, self::powerGapFacts($npcName, $dynamics), $summary);
            }
        }

        if ($intensity !== null && self::configValue('jealousy_enabled')) {
            $gain = self::jealousyEventGain($dynamics, $intensity);
            if ($gain > 0) {
                self::addJealousy($dynamics, $gain, $rival);
                $out['jealousy'] = $gain;
            }
        }

        if (!empty($item['positive_interaction'])) {
            // The positive-interaction count (passion stages) for an exchange the eval scored
            // (postrequest counts it only when the local classifier scored the exchange)
            $dynamics['total_positive_interactions'] = intval($dynamics['total_positive_interactions'] ?? 0) + 1;
            self::checkStageAdvancement($dynamics);
            // MDD 15.5 natural decay: -1 raw per meaningful positive interaction (the eval judged it)
            if (floatval($dynamics['dimensions']['resentment']['x'] ?? 0) > 0) {
                $out['resentment_decay'] = self::applyDelta('resentment', $dynamics,
                    -floatval(self::configValue('resentment_positive_decay')), $temperament);
            }
            if (!empty($dynamics['in_conflict']) && self::configValue('conflict_enabled')) {
                $burst = self::recordConflictPositive($dynamics);
                if ($burst > 0) {
                    self::addPassion($dynamics, $burst, 'repair');
                    $out['repair_burst'] = $burst;
                }
            }
            $out['romantic_exposure'] = count(array_intersect($tags,
                array_map('strtolower', (array) self::configValue('jealousy_bystander_tags')))) > 0;
        }
        return $out;
    }

    /**
     * Jealousy points (0..100 scale) one eval jealousy event adds to this NPC:
     *   jealousy_eval_gain x jealousy_intensity_mult[intensity] x TEMPERAMENT_JEALOUSY_MULT (MDD 1.3)
     *   x attachment jealousy_mult x relationship preference (polyamorous 0.2, not_interested 0)
     */
    public static function jealousyEventGain(array $dynamics, int $intensity, float $commitment = 1.0): float
    {
        $intensity = max(0, min(3, $intensity));
        $pref = $dynamics['relationship_preference'] ?? null;
        if ($pref === 'not_interested') {
            return 0.0;
        }
        $gain = floatval(self::configValue('jealousy_eval_gain'))
            * floatval(((array) self::configValue('jealousy_intensity_mult'))[$intensity] ?? 1.0)
            * floatval(self::TEMPERAMENT_JEALOUSY_MULT[$dynamics['inferred_temperament'] ?? ''] ?? 1.0)
            * floatval(self::getAttachmentModifier($dynamics, 'jealousy_mult') ?? 1.0)
            * ($pref === 'polyamorous' ? 0.2 : 1.0)
            * $commitment;
        return max(0.0, $gain);
    }

    /** Jealousy scale ceiling, jealousy points (MDD 6.5: walkaway at 100). */
    const JEALOUSY_SCALE_MAX = 100.0;

    /**
     * Jealousy points a bystander gains from seeing the player be intimate with another NPC:
     * an intensity-1 event scaled by the bystander's commitment, read from its core
     * relationships.Player.type (_core_rel_type, stored by its own last prerequest) through
     * jealousy_bystander_commitment. Not committed (or type unknown) -> 0. Pure.
     */
    public static function bystanderJealousyGain(array $observerDynamics): float
    {
        $type = strtolower(trim((string) ($observerDynamics['_core_rel_type'] ?? '')));
        $commitment = floatval(((array) self::configValue('jealousy_bystander_commitment'))[$type] ?? 0.0);
        if ($commitment <= 0) {
            return 0.0;
        }
        return self::jealousyEventGain($observerDynamics, 1, $commitment);
    }

    /**
     * NPCs near $npcName (CHIM's CACHE_PEOPLE, '|'-delimited) watched the player be intimate
     * with $npcName (an eval item for $npcName with romantic_exposure). Each committed
     * bystander with RelDyn state gains bystanderJealousyGain(), rival = $npcName, and is
     * saved (merge save). Returns bystander => jealousy points added.
     */
    public static function scanBystanderJealousy(string $npcName, ?string $cachePeople = null): array
    {
        $cachePeople = $cachePeople ?? (string) ($GLOBALS['CACHE_PEOPLE'] ?? '');
        $added = [];
        $seen = [];
        foreach (explode('|', $cachePeople) as $name) {
            $name = trim($name);
            $key = strtolower($name);
            if ($name === '' || $key === strtolower(trim($npcName)) || isset($seen[$key])) continue;
            $seen[$key] = true;
            if (self::loadStoredDynamics($name) === null) continue;   // no bond state: not jealous of anyone

            $dyn = self::getDynamics($name);
            $gain = self::bystanderJealousyGain($dyn);
            if ($gain <= 0) continue;
            self::addJealousy($dyn, $gain, $npcName);
            if (!self::saveDynamics($name, $dyn)) {
                error_log("[RelDyn-JEALOUSY] bystander {$name}: save failed, +{$gain} jealousy lost");
                continue;
            }
            $added[$name] = $gain;
            self::log("Bystander jealousy: {$name} +" . round($gain, 2) . " (saw the player with {$npcName})");
        }
        return $added;
    }

    /**
     * Observe the NPC's core affinity (relationships.Player.aff, -100..100) at raw game time
     * $nowGamets, whoever changed it (core eval, RelDyn, the editor). A session runs while
     * observations come no more than conflict_session_gap_game_hours apart; a drop of
     * conflict_threshold_affinity_drop points below the session's high opens a conflict
     * (MDD conflict/repair). Returns true when this observation opened one.
     */
    public static function observeCoreAffinity(array &$dynamics, float $coreAff, float $nowGamets): bool
    {
        if ($nowGamets <= 0) {
            return false;   // game clock unknown: sessions cannot be told apart
        }
        $gapGamets = floatval(self::configValue('conflict_session_gap_game_hours')) * self::GAMETS_PER_DAY / 24.0;
        $s = $dynamics['_conflict_aff_session'] ?? null;
        $seen = is_array($s) ? floatval($s['seen_gamets'] ?? 0) : 0.0;
        if (!is_array($s) || !is_numeric($s['high'] ?? null) || $seen <= 0 || $seen > $nowGamets
            || $nowGamets - $seen > $gapGamets) {
            $high = $coreAff;   // a new session starts here
        } else {
            $high = max(floatval($s['high']), $coreAff);
        }

        $opened = false;
        $threshold = floatval(self::configValue('conflict_threshold_affinity_drop'));   // core affinity points
        if ($high - $coreAff >= $threshold && empty($dynamics['in_conflict']) && self::configValue('conflict_enabled')) {
            self::enterConflict($dynamics);
            self::log("Conflict: core affinity fell " . round($high - $coreAff, 2) . " below this session's high ({$high} -> {$coreAff})");
            $high = $coreAff;
            $opened = true;
        }
        $dynamics['_conflict_aff_session'] = ['high' => $high, 'seen_gamets' => $nowGamets];
        return $opened;
    }

    // ========== END EVAL FEELINGS ==========


    // ========== RELATIONSHIP TYPE MODIFIERS (PR 5) ==========

    /**
     * Dimensions that are GLOBAL (intrinsic to the NPC, not per-bond).
     * These are NOT modified by relationship type multipliers.
     */
    const GLOBAL_DIMENSIONS = ['maturity', 'self_confidence', 'coord_m', 'coord_f', 'arousal', 'valence'];

    /**
     * 9-type relationship modifier grid.
     *
     * Each relationship type defines multipliers for per-bond dimensions.
     * These modify the NPC's global temperament baselines and resistance
     * into per-bond effective values:
     *
     *   effective_baseline  = min(global_baseline * type_modifier, range_max)
     *   effective_resistance = global_resistance * type_modifier['resistance']
     *
     * Same NPC, same moment, completely different effective personality
     * depending on the bond type with the person they're talking to.
     *
     * Dimensions not listed here (maturity, self_confidence, coord_m, coord_f,
     * arousal, valence) are global and return 1.0 (no modification).
     */
    const RELATIONSHIP_TYPE_MODIFIERS = [
        'stranger' => [
            'trust' => 0.5, 'comfort' => 0.5, 'respect' => 0.8,
            'warmth' => 0.3, 'passion' => 0.3,
            'decay_rate' => 2.0, 'resistance' => 0.7,
        ],
        'acquaintance' => [
            'trust' => 0.7, 'comfort' => 0.6, 'respect' => 0.9,
            'warmth' => 0.5, 'passion' => 0.5,
            'decay_rate' => 1.5, 'resistance' => 0.8,
        ],
        'friend' => [
            'trust' => 1.2, 'comfort' => 1.5, 'respect' => 1.2,
            'warmth' => 1.5, 'passion' => 0.8,
            'decay_rate' => 0.7, 'resistance' => 1.2,
        ],
        'crush' => [
            'trust' => 1.0, 'comfort' => 1.0, 'respect' => 1.0,
            'warmth' => 2.0, 'passion' => 2.0,
            'decay_rate' => 1.2, 'resistance' => 1.5,
        ],
        'bonded' => [
            'trust' => 2.0, 'comfort' => 2.5, 'respect' => 1.5,
            'warmth' => 2.5, 'passion' => 2.0,
            'decay_rate' => 0.3, 'resistance' => 1.5,
        ],
        'sworn' => [
            'trust' => 2.5, 'comfort' => 2.0, 'respect' => 2.0,
            'warmth' => 2.0, 'passion' => 1.5,
            'decay_rate' => 0.2, 'resistance' => 1.3,
        ],
        'rival' => [
            'trust' => 0.3, 'comfort' => 0.2, 'respect' => 1.5,
            'warmth' => 0.1, 'passion' => 0.8,
            'decay_rate' => 0.5, 'resistance' => 0.5,
        ],
        'hostile' => [
            'trust' => 0.1, 'comfort' => 0.1, 'respect' => 0.5,
            'warmth' => 0.0, 'passion' => 0.3,
            'decay_rate' => 0.0, 'resistance' => 0.3,
        ],
        'mercenary' => [
            'trust' => 0.5, 'comfort' => 0.3, 'respect' => 1.0,
            'warmth' => 0.2, 'passion' => 0.2,
            'decay_rate' => 3.0, 'resistance' => 0.5,
        ],
        'friendzone' => [
            'trust'   => 1.5,
            'comfort' => 1.8,
            'respect' => 1.3,
            'warmth'  => 1.2,
            'passion' => 0.1,
            'decay_rate' => 0.5,
        ],
        'parasite' => [
            'trust'   => 0.3,
            'comfort' => 0.2,
            'respect' => 0.5,
            'warmth'  => 0.2,
            'passion' => 0.5,
            'decay_rate' => 4.0,
        ],
    ];
    /**
     * Default mapping from relationship stage to relationship type.
     * Used when no explicit relationship_type is set on the bond.
     */
    const STAGE_TO_TYPE_MAP = [
        'early'       => 'acquaintance',
        'established' => 'friend',
        'deep'        => 'bonded',
    ];

    /**
     * Core relationship type (relationships.Player.type, lib/relationship_manager.php TYPES)
     * -> RelDyn bond key (RELATIONSHIP_TYPE_MODIFIERS / TIER_FLOOR_GATES). null = the core
     * type names no flavour RelDyn distinguishes: the bond's depth then comes from core
     * affinity (DEPTH_TYPE_BY_TIER). Custom types the player created are treated as null.
     */
    const CORE_TYPE_TO_RELDYN_TYPE = [
        'romantic'      => 'bonded',       // draft: Romantic / Bonded share the trust gate
        'crush'         => 'crush',
        'ex'            => null,
        'platonic'      => 'friend',
        'familial'      => 'bonded',
        'protective'    => 'friend',
        'fanatical'     => 'sworn',        // blind loyalty (housecarl)
        'servant'       => 'sworn',
        'mentor'        => 'mentor',       // TIER_FLOOR_GATES respect + maturity
        'student'       => 'student',
        'professional'  => 'acquaintance',
        'transactional' => 'mercenary',
        'client'        => 'mercenary',
        'patron'        => 'mercenary',
        'rival'         => 'rival',
        'jealous'       => 'rival',
        'enemy'         => 'hostile',
        'nemesis'       => 'hostile',
        'betrayed'      => 'hostile',
        'contempt'      => 'hostile',
        'neutral'       => null,
    ];

    /** Mapped core types that outrank RelDyn's own overlays (parasite, friendzone). */
    const CORE_HOSTILE_RELDYN_TYPES = ['hostile', 'rival'];

    /** Bond depth by RelDyn tier (core affinity) when core's type carries no flavour. */
    const DEPTH_TYPE_BY_TIER = [
        'hostile'      => 'stranger',
        'stranger'     => 'stranger',
        'acquaintance' => 'acquaintance',
        'friend'       => 'friend',
        'close_friend' => 'friend',
        'bonded'       => 'bonded',
        'devoted'      => 'bonded',
    ];

    /**
     * Record core's relationships.Player.type for this request (prerequest reads it with the
     * affinity mirror). A missing Player entry is core's default, 'neutral'.
     */
    public static function setCoreRelationshipType(array &$dynamics, $coreType): void
    {
        $type = strtolower(trim((string) ($coreType ?? '')));
        $dynamics['_core_rel_type'] = ($type === '') ? 'neutral' : $type;
    }

    /**
     * Determine the current relationship type for an NPC bond: the one type every consumer
     * reads (absence decay gates, per-bond modifiers, friendzone cap, breaking arc, parasite).
     *
     * Resolution order:
     *   1. Core hostility: a core Player.type mapping to hostile/rival wins outright
     *   2. PR 12 override: $dynamics['_relationship_type_override'] (parasite, etc.)
     *   3. PR 12 friendzone: attraction matrix friendzoned flag + friend tier on core affinity
     *   4. Core Player.type (source of truth), mapped by CORE_TYPE_TO_RELDYN_TYPE
     *   5. Core type without flavour ('neutral', custom): depth from the core affinity tier
     *   Fallback only when core's type is unknown (no core row read for this blob):
     *   6. Explicit $dynamics['relationship_type'], then the interaction-count stage via
     *      STAGE_TO_TYPE_MAP, then 'stranger'
     *
     * @param string     $npcName   NPC name (for future per-NPC overrides)
     * @param array|null $dynamics  NPC dynamics blob
     * @return string    Relationship type key (lowercase, RELATIONSHIP_TYPE_MODIFIERS / TIER_FLOOR_GATES)
     */
    public static function getRelationshipType($npcName, $dynamics = null)
    {
        $dynamics = is_array($dynamics) ? $dynamics : [];
        $coreType = $dynamics['_core_rel_type'] ?? null;
        $coreMapped = null;
        if (is_string($coreType) && $coreType !== '') {
            $coreMapped = self::CORE_TYPE_TO_RELDYN_TYPE[$coreType] ?? null;
            // 1. Core hostility outranks RelDyn overlays
            if ($coreMapped !== null && in_array($coreMapped, self::CORE_HOSTILE_RELDYN_TYPES, true)) {
                return $coreMapped;
            }
        }

        // 2. PR 12: Explicit type override (friendzone, parasite, etc.)
        $override = $dynamics['_relationship_type_override'] ?? null;
        if ($override && isset(self::RELATIONSHIP_TYPE_MODIFIERS[$override])) {
            return $override;
        }

        // 3. PR 12: Friendzone from Attraction Matrix
        // (friend tier or above on core affinity; the old "affinity > 40" was the draft 0..100 scale)
        if (!empty($dynamics['_attraction_friendzoned'])) {
            $tier = self::getCurrentTier(self::getCoreAffinity($dynamics));
            if (self::tierRank($tier) >= self::tierRank('friend')) {
                return 'friendzone';
            }
        }

        // 4. Core type is the source of truth
        if ($coreMapped !== null) {
            return $coreMapped;
        }
        // 5. Core type without a RelDyn flavour: depth from core affinity
        if (is_string($coreType) && $coreType !== '') {
            return self::DEPTH_TYPE_BY_TIER[self::getCurrentTier(self::getCoreAffinity($dynamics))] ?? 'stranger';
        }

        // 6. Fallback without core data: explicit RelDyn type, then stage
        if (!empty($dynamics['relationship_type'])) {
            $type = strtolower($dynamics['relationship_type']);
            if (isset(self::RELATIONSHIP_TYPE_MODIFIERS[$type])) {
                return $type;
            }
        }

        $stage = $dynamics['stage'] ?? null;
        if ($stage && isset(self::STAGE_TO_TYPE_MAP[$stage])) {
            return self::STAGE_TO_TYPE_MAP[$stage];
        }

        return 'stranger';
    }

    /**
     * Get the type modifier multiplier for a given relationship type + dimension.
     *
     * Returns 1.0 (no modification) for:
     *   - Global dimensions (maturity, self_confidence, coord_m, coord_f, arousal, valence)
     *   - Unknown relationship types
     *   - Dimensions not in the modifier table (e.g., affinity, resentment)
     *
     * @param string $relationshipType  Relationship type key (e.g., 'bonded', 'stranger')
     * @param string $dimensionId       Dimension key or 'resistance' / 'decay_rate'
     * @return float  Multiplier value
     */
    public static function getTypeModifier($relationshipType, $dimensionId)
    {
        // Global dimensions are never modified by relationship type
        if (in_array($dimensionId, self::GLOBAL_DIMENSIONS, true)) {
            return 1.0;
        }

        // Lookup type row, then dimension column
        if (isset(self::RELATIONSHIP_TYPE_MODIFIERS[$relationshipType][$dimensionId])) {
            return (float) self::RELATIONSHIP_TYPE_MODIFIERS[$relationshipType][$dimensionId];
        }

        // Unknown type or dimension not in table
        return 1.0;
    }
    /**
     * Get the effective baseline for a dimension within a specific bond.
     *
     * Formula: min(global_baseline * type_modifier, range_max)
     *
     * For global dimensions (maturity, self_confidence, coord_m, coord_f,
     * arousal, valence), returns the global baseline directly -- these are
     * intrinsic to the NPC, not per-bond.
     *
     * @param string      $npcName          NPC name (reserved for future per-NPC overrides)
     * @param string      $dimensionId      Dimension key
     * @param string|null $temperament      Temperament name
     * @param string      $relationshipType Relationship type key
     * @return float  Effective baseline value, clamped to range_max
     */
    public static function getEffectiveBaseline($npcName, $dimensionId, $temperament, $relationshipType)
    {
        $globalBaseline = self::getTemperamentBaseline($temperament, $dimensionId);

        // Global dimensions skip modifier -- return global baseline directly
        if (in_array($dimensionId, self::GLOBAL_DIMENSIONS, true)) {
            return $globalBaseline;
        }

        $modifier = self::getTypeModifier($relationshipType, $dimensionId);
        $def = self::getDimensionDefinition($dimensionId);
        $rangeMax = $def ? (float) $def['range_max'] : 100.0;

        return min($globalBaseline * $modifier, $rangeMax);
    }

    /**
     * Get the effective resistance (plasticity profile) for a dimension within a specific bond.
     *
     * Formula: Y_up * resistance_modifier, Y_down * resistance_modifier
     *
     * For global dimensions (maturity, self_confidence, coord_m, coord_f,
     * arousal, valence), returns the unmodified plasticity profile.
     *
     * @param string|null $temperament      Temperament name
     * @param string      $dimensionId      Dimension key
     * @param string      $relationshipType Relationship type key
     * @param array       $context          Optional context (e.g., ['maturity' => 72])
     * @return array  ['Y_up' => float, 'Y_down' => float]
     */
    public static function getEffectiveResistance($temperament, $dimensionId, $relationshipType, $context = [])
    {
        $profile = self::getPlasticityProfile($temperament, $dimensionId, $context);

        // Global dimensions skip modifier -- return unmodified profile
        if (in_array($dimensionId, self::GLOBAL_DIMENSIONS, true)) {
            return $profile;
        }

        $modifier = self::getTypeModifier($relationshipType, 'resistance');

        return [
            'Y_up'   => $profile['Y_up'] * $modifier,
            'Y_down' => $profile['Y_down'] * $modifier,
        ];
    }

    // ========== END RELATIONSHIP TYPE MODIFIERS (PR 5) ==========



    // ========== AFFINITY DECAY + TIER DEMOTION (PR 5) ==========
    //
    // Time-based affinity decay with temperament-specific rates, tier
    // retention thresholds, floor gates per relationship type, and a
    // maturity gate that determines whether floors even apply.
    //
    // PARALLEL to the existing passion decay in prerequest.php -- gated
    // behind dimension_engine_enabled. Does not replace existing decay.
    // ================================================================

    /**
     * Temperament decay rates per tick (1 tick = GAMETS_PER_DECAY_TICK).
     * Higher magnitude = faster erosion from absence.
     * UNITS: core affinity points (-100..+100 scale) per tick, not mirror points.
     */
    const TEMPERAMENT_DECAY_RATES = [
        'Anxious'     => -2.0,  // "Haven't talked in 2 days, do you even care?"
        'Jealous'     => -1.8,  // Abandonment + paranoid imagination
        'Romantic'    => -1.5,  // Pines, wilts without contact
        'Playful'     => -1.0,  // Out of sight, out of mind
        'Guarded'     => -0.8,  // Walls already up, absence expected
        'Bold'        => -0.7,  // Has own life but notices
        'Nurturing'   => -0.5,  // Patient, waits, worries quietly
        'Humble'      => -0.5,  // Undemanding, steady
        'Proud'       => -0.5,  // Won't chase, but remembers
        'Gentle'      => -0.4,  // Slow to fade
        'Stoic'       => -0.3,  // Barely notices, duty doesn't require presence
        'Defiant'     => -0.3,  // Won't admit they miss you
        'Independent' => -0.1,  // Might prefer the space
    ];

    /**
     * Temperament tier retention thresholds.
     * How far below the tier floor affinity can drop before demotion triggers.
     * More negative = more retention (holds the tier longer despite decay).
     * UNITS: core affinity points, added to the core-unit tier floor (RELATIONSHIP_TIERS).
     */
    const TEMPERAMENT_TIER_RETENTION = [
        'Independent' => -30,  // Barely cares about labels
        'Stoic'       => -25,  // Loyalty is a decision
        'Nurturing'   => -20,  // Holds on tight
        'Gentle'      => -20,  // Patient
        'Humble'      => -20,  // Gives benefit of the doubt
        'Bold'        => -15,  // Reasonable patience
        'Romantic'    => -15,  // Wants to believe
        'Guarded'     => -15,  // Hard to earn, hard to lose
        'Defiant'     => -15,  // Stubborn loyalty
        'Proud'       => -10,  // Demands consistency
        'Playful'     => -10,  // Moves on quickly
        'Anxious'     => -10,  // Fragile, hair trigger
        'Jealous'     =>  -5,  // Snaps fast
    ];

    /**
     * Tier floor gates by relationship type.
     * Maps relationship type to the dimension(s) that must fail before
     * demotion can proceed. 'none' = raw affinity only. 'no_decay' = immune.
     *
     * Format: string (single dimension), array (all must fail), or special flag.
     */
    const TIER_FLOOR_GATES = [
        'romantic'      => 'trust',              // "Can neglect but not betray"
        'bonded'        => 'trust',              // Same as romantic
        'sworn'         => 'respect',            // "Can be absent but not incompetent"
        'companion'     => 'respect',            // Same as sworn
        'friend'        => 'comfort',            // "Go months without talking, pick up where left off"
        'platonic'      => 'comfort',            // Same as friend
        'follower'      => ['trust', 'respect'], // Both must hold
        'loyal'         => ['trust', 'respect'], // Same as follower
        'mentor'        => ['respect', 'maturity'], // "Don't disrespect what I taught you"
        'student'       => ['respect', 'maturity'],
        'mercenary'     => 'none',              // Pure economics, no floor
        'transactional' => 'none',
        'rival'         => 'no_decay',          // Hate is self-sustaining
        'hostile'       => 'no_decay',
        'antagonist'    => 'no_decay',
    ];

    /**
     * Relationship tier definitions with affinity thresholds.
     * Ordered from lowest to highest. Used for tier lookup and demotion checks.
     *
     * UNITS: core affinity (relationships.Player.aff, -100..+100), never the 0..100 mirror
     * dimensions.affinity.x. The bands are core's own (RelationshipManager::TIERS), so RelDyn
     * and core name the same bond the same depth; a neutral stranger (core 0) is 'stranger'.
     * The design draft's 0..100 bands (hostile 0-10 .. devoted 86-100) predate the core
     * bridge; their seven steps map onto core's labels in order:
     *   hostile = Wary and below, stranger = Neutral, acquaintance = Acquaintance,
     *   friend = Friendly, close_friend = Fond, bonded = Devoted, devoted = Bonded.
     */
    const RELATIONSHIP_TIERS = [
        'hostile'      => ['min' => -100, 'max' => -6],
        'stranger'     => ['min' =>   -5, 'max' =>  5],
        'acquaintance' => ['min' =>    6, 'max' => 30],
        'friend'       => ['min' =>   31, 'max' => 55],
        'close_friend' => ['min' =>   56, 'max' => 75],
        'bonded'       => ['min' =>   76, 'max' => 90],
        'devoted'      => ['min' =>   91, 'max' => 100],
    ];

    /**
     * Context tier (0-3, how much relational state reaches the prompt) per RelDyn tier.
     * Tier 0 hostile/stranger: nothing; 1 acquaintance: band keywords; 2 friend and
     * close_friend: keywords + summary; 3 bonded/devoted: full state (needs the live bond).
     */
    const CONTEXT_TIER_BY_RELATIONSHIP_TIER = [
        'hostile'      => 0,
        'stranger'     => 0,
        'acquaintance' => 1,
        'friend'       => 2,
        'close_friend' => 2,
        'bonded'       => 3,
        'devoted'      => 3,
    ];

    /** Stamp next to _current_tier: the label was judged on core affinity (-100..100) tiers. */
    const TIER_LABEL_UNITS = 'core_aff';

    /** Default gate threshold: floor holds if gate signal > this */
    const TIER_GATE_THRESHOLD = 50;

    /** Maturity must exceed this for floors to activate */
    const MATURITY_FLOOR_THRESHOLD = 40;

    /**
     * The NPC's affinity toward the player in CORE units (-100..+100).
     *
     * This is core relationships.Player.aff as mirrored at the start of the request
     * (refreshAffinityMirror) plus RelDyn's own change since then that commitPlayerAffinity()
     * has not pushed yet. The mirror x is (aff + 100) / 2, so aff = 2x - 100. Every tier
     * decision reads affinity through this helper and maps it with getCurrentTier().
     * Without a mirror (core never read for this blob) x is not a mirror value, and core's
     * default for a missing Player entry applies: 0, a neutral stranger.
     */
    public static function getCoreAffinity(array $dynamics): float
    {
        $mark = $dynamics['_aff_mirror_x'] ?? null;
        if (!is_numeric($mark)) {
            return 0.0;
        }
        $x = $dynamics['dimensions']['affinity']['x'] ?? $mark;
        $x = is_numeric($x) ? floatval($x) : floatval($mark);
        $core = $x * 2.0 - 100.0;
        return (float) max(self::CORE_AFFINITY_MIN, min(self::CORE_AFFINITY_MAX, round($core, 4)));
    }

    /**
     * Set the NPC's affinity to a CORE value (-100..+100) by moving the mirror x.
     * commitPlayerAffinity() pushes the difference to core as a locked delta.
     */
    private static function setCoreAffinity(array &$dynamics, float $coreAff): void
    {
        $coreAff = max(self::CORE_AFFINITY_MIN, min(self::CORE_AFFINITY_MAX, $coreAff));
        $dynamics['dimensions']['affinity']['x'] = round(($coreAff + 100.0) / 2.0, 4);
    }

    /**
     * Label of the DIMENSION_BANDS['affinity'] entry (the design draft's 0..100 bands) for each
     * RelDyn tier on core affinity, following core's own labels: a neutral stranger has
     * "no strong feelings" (Neutral), core Wary/Cold read Cold, Friendly reads Warm, Devoted
     * reads Close. The hostile tier splits at AFFINITY_BAND_HOSTILE_MAX (core units).
     */
    const AFFINITY_BAND_BY_TIER = [
        'hostile'      => 'Cold',
        'stranger'     => 'Neutral',
        'acquaintance' => 'Neutral',
        'friend'       => 'Warm',
        'close_friend' => 'Fond',
        'bonded'       => 'Close',
        'devoted'      => 'Devoted',
    ];

    /** Core affinity (-100..+100) at or below which the 'Hostile' band applies (core Resentful and below). */
    const AFFINITY_BAND_HOSTILE_MAX = -56;

    /**
     * Affinity band for context lines, from core affinity (never look the mirror x up in
     * DIMENSION_BANDS['affinity']: its ranges are the draft's 0..100 scale).
     */
    public static function getAffinityBand(array $dynamics): ?array
    {
        $core = self::getCoreAffinity($dynamics);
        $label = ($core <= self::AFFINITY_BAND_HOSTILE_MAX)
            ? 'Hostile'
            : (self::AFFINITY_BAND_BY_TIER[self::getCurrentTier($core)] ?? null);
        foreach (self::DIMENSION_BANDS['affinity'] ?? [] as $band) {
            if ($band['label'] === $label) {
                return $band;
            }
        }
        return null;
    }

    /** Context tier (0-3) the NPC's current core affinity supports, before the high-water mark. */
    public static function getAffinityContextTier(array $dynamics): int
    {
        $tier = self::getCurrentTier(self::getCoreAffinity($dynamics));
        return self::CONTEXT_TIER_BY_RELATIONSHIP_TIER[$tier] ?? 0;
    }

    /**
     * Get the current relationship tier from a CORE affinity value.
     *
     * @param float $affinity  Core affinity, -100..+100 (use getCoreAffinity(), not affinity.x)
     * @return string  Tier name (hostile, stranger, acquaintance, friend, close_friend, bonded, devoted)
     */
    public static function getCurrentTier($affinity)
    {
        $affinity = max(self::CORE_AFFINITY_MIN, min(self::CORE_AFFINITY_MAX, floatval($affinity)));

        // Walk tiers from highest to lowest -- first match wins
        $tiersReversed = array_reverse(self::RELATIONSHIP_TIERS, true);
        foreach ($tiersReversed as $tierName => $range) {
            if ($affinity >= $range['min']) {
                return $tierName;
            }
        }

        return 'hostile'; // Fallback
    }

    /**
     * Get the floor (minimum affinity) for a given tier.
     *
     * @param string $tierName  Tier name
     * @return int  The min core affinity (-100..+100) for this tier, or 0 if unknown
     */
    public static function getTierFloor($tierName)
    {
        return self::RELATIONSHIP_TIERS[$tierName]['min'] ?? 0;
    }

    /** Position of a tier in RELATIONSHIP_TIERS (hostile = 0), -1 when unknown. */
    public static function tierRank($tierName)
    {
        $rank = array_search($tierName, array_keys(self::RELATIONSHIP_TIERS), true);
        return $rank === false ? -1 : $rank;
    }

    /**
     * Check whether tier demotion should happen based on floor gates.
     *
     * Evaluates:
     * 1. Has affinity dropped below tier floor minus retention threshold?
     * 2. Has the floor gate dimension failed (below gate threshold)?
     * 3. Is the maturity gate active (maturity > 40)?
     *
     * All three conditions must be met for demotion to proceed.
     * Exception: immature NPCs (maturity <= 40) have NO floors -- demotion
     * proceeds on raw affinity alone.
     *
     * @param array  $dynamics         NPC dynamics blob
     * @param string $temperament      NPC temperament
     * @param string $relationshipType Relationship type (romantic, friend, etc.)
     * @return array ['should_demote' => bool, 'reason' => string, 'new_tier' => string|null,
     *               'floor_active' => bool, 'gate_status' => string]
     */
    public static function checkTierDemotion($dynamics, $temperament, $relationshipType, $heldTier = null)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $affinity = self::getCoreAffinity($dynamics); // core units, like the tier floors and retention
        $currentTier = self::getCurrentTier($affinity);
        // The label being defended is the tier held so far, which is above the tier the
        // decayed number maps to in exactly the cases this check exists for.
        if (is_string($heldTier) && self::tierRank($heldTier) > self::tierRank($currentTier)) {
            $currentTier = $heldTier;
        }
        $tierFloor = self::getTierFloor($currentTier);

        // Default result
        $result = [
            'should_demote' => false,
            'reason'        => 'within_threshold',
            'current_tier'  => $currentTier,
            'new_tier'      => null,
            'floor_active'  => false,
            'gate_status'   => 'n/a',
        ];

        // Can't demote below hostile
        if ($currentTier === 'hostile') {
            $result['reason'] = 'already_bottom';
            return $result;
        }

        // --- Check if affinity is below tier floor ---
        if ($affinity >= $tierFloor) {
            $result['reason'] = 'above_floor';
            return $result;
        }

        // --- Get retention threshold from temperament ---
        $retention = self::TEMPERAMENT_TIER_RETENTION[$temperament] ?? -15;
        $demotionThreshold = $tierFloor + $retention; // retention is negative, so this lowers the threshold
        $result['demotion_threshold'] = $demotionThreshold;

        // Affinity hasn't dropped far enough past the floor
        if ($affinity > $demotionThreshold) {
            $result['reason'] = 'within_retention';
            return $result;
        }

        // --- Resolve floor gate for this relationship type ---
        $relTypeLower = strtolower(trim($relationshipType));
        $gate = self::TIER_FLOOR_GATES[$relTypeLower] ?? 'none';

        // No decay types -- never demote
        if ($gate === 'no_decay') {
            $result['reason'] = 'no_decay_type';
            return $result;
        }

        // --- Check maturity gate ---
        $maturity = floatval($dims['maturity']['x'] ?? 50);
        $maturityActive = ($maturity > self::MATURITY_FLOOR_THRESHOLD);
        $result['floor_active'] = $maturityActive;

        // Immature NPCs: NO floors. Demotion proceeds on raw affinity alone.
        if (!$maturityActive) {
            $result['should_demote'] = true;
            $result['reason'] = 'immature_no_floor';
            $result['new_tier'] = self::getCurrentTier($affinity);
            $result['gate_status'] = 'bypassed_low_maturity';
            return $result;
        }

        // --- Check floor gate dimension(s) ---
        // No gate = raw affinity only -- demote
        if ($gate === 'none') {
            $result['should_demote'] = true;
            $result['reason'] = 'no_gate_raw_affinity';
            $result['new_tier'] = self::getCurrentTier($affinity);
            $result['gate_status'] = 'none';
            return $result;
        }

        // Single dimension gate
        if (is_string($gate)) {
            $gateValue = floatval($dims[$gate]['x'] ?? 50);
            if ($gateValue < self::TIER_GATE_THRESHOLD) {
                $result['should_demote'] = true;
                $result['reason'] = "gate_failed_{$gate}";
                $result['new_tier'] = self::getCurrentTier($affinity);
                $result['gate_status'] = "{$gate}=" . round($gateValue, 1) . '<' . self::TIER_GATE_THRESHOLD;
                return $result;
            }
            // Gate holds -- no demotion
            $result['reason'] = 'gate_holds';
            $result['gate_status'] = "{$gate}=" . round($gateValue, 1) . '>=' . self::TIER_GATE_THRESHOLD;
            return $result;
        }

        // Multi-dimension gate (array) -- ALL must fail for demotion
        if (is_array($gate)) {
            $allFailed = true;
            $gateDetails = [];
            foreach ($gate as $gateDim) {
                $gateValue = floatval($dims[$gateDim]['x'] ?? 50);
                $failed = ($gateValue < self::TIER_GATE_THRESHOLD);
                $gateDetails[] = "{$gateDim}=" . round($gateValue, 1) . ($failed ? '<' : '>=') . self::TIER_GATE_THRESHOLD;
                if (!$failed) {
                    $allFailed = false;
                }
            }
            $result['gate_status'] = implode(', ', $gateDetails);

            if ($allFailed) {
                $result['should_demote'] = true;
                $result['reason'] = 'all_gates_failed';
                $result['new_tier'] = self::getCurrentTier($affinity);
                return $result;
            }

            $result['reason'] = 'gate_holds';
            return $result;
        }

        return $result;
    }

    // ========== TIERED CONTEXT INJECTION ==========
    //
    // Context tier determines how much relational data is injected into
    // the LLM context. Higher tiers = richer context. A high water mark
    // ensures NPCs aren't "forgotten" when affinity drops.
    //
    // Affinity here is CORE affinity (-100..+100), through getCoreAffinity() and the
    // RelDyn tier (getCurrentTier, CONTEXT_TIER_BY_RELATIONSHIP_TIER):
    // Tier 0 (Stranger)     : hostile/stranger, core <= 5    — nothing injected
    // Tier 1 (Acquaintance) : acquaintance, core 6..30       — band keywords only
    // Tier 2 (Friend+)      : friend/close_friend, core 31..75 — keywords + maturity + shifts
    // Tier 3 (Bonded+)      : bonded/devoted, core 76+       — full dimensional state
    //
    // HWM rule: once tier 2 is reached, it becomes the permanent floor.
    // Tier 3 requires active high affinity (core 76+).
    // ===============================================

    /**
     * Calculate the effective context tier for an NPC based on affinity and HWM.
     *
     * The high water mark (HWM) ensures that once an NPC reaches tier 2,
     * context never drops below tier 2 even if affinity tanks to 0.
     * "You don't forget who someone is because you hate them."
     *
     * Tier 3 is NOT preserved by HWM — it requires active high affinity (core 76+).
     *
     * @param array $dynamics  Full NPC dynamics blob
     * @return int  Effective context tier (0-3)
     */
    public static function getContextTier($dynamics)
    {
        // Current tier from core affinity (not the 0..100 mirror x)
        $currentTier = self::getAffinityContextTier(is_array($dynamics) ? $dynamics : []);

        // High water mark — tier 2 is permanent once reached
        $hwm = intval($dynamics['context_tier_hwm'] ?? 0);
        $effectiveTier = max($currentTier, min($hwm, 2)); // HWM capped at tier 2 for floor

        // Tier 3 only if current affinity supports it
        if ($currentTier >= 3) {
            $effectiveTier = 3;
        }

        return $effectiveTier;
    }

    /**
     * Update the context tier high water mark based on current affinity.
     *
     * Should be called on every context build so the HWM ratchets up
     * as the relationship deepens.
     *
     * @param array &$dynamics  Full NPC dynamics blob (modified in place)
     * @return bool  True if HWM was updated (needs save)
     */
    public static function updateContextTierHWM(&$dynamics)
    {
        // Same core-affinity tier as getContextTier(): the mirror x must never raise the HWM
        $tier = self::getAffinityContextTier(is_array($dynamics) ? $dynamics : []);

        $oldHwm = intval($dynamics['context_tier_hwm'] ?? 0);
        $dynamics['context_tier_hwm'] = max($oldHwm, $tier);

        return ($dynamics['context_tier_hwm'] > $oldHwm);
    }


    /**
     * Process affinity decay from absence, with tier demotion checks.
     *
     * Decay formula (core affinity points):
     *   temperament base rate x type decay_rate modifier x attachment absence mult
     *   x ambient resist x ticks_elapsed
     * One tick = GAMETS_PER_DECAY_TICK of absence (see calculateDecayTicks).
     *
     * Absence only fades the positive part of the bond: the number decays toward the NPC's
     * affinity baseline (core units, never below core 0) and stops there. Affinity at or
     * below that point is left alone: absence neither manufactures nor heals negative
     * affinity (decisions 2026-09-23 section 2; negative states resolve through contact).
     *
     * This method:
     * 1. Calculates decay amount from temperament, type, attachment and elapsed ticks
     * 2. Applies decay to CORE affinity (-100..+100, via getCoreAffinity/setCoreAffinity)
     * 3. Checks tier demotion if affinity crossed a tier floor
     * 4. Returns detailed result for logging/debugging
     *
     * Gated behind dimension_engine_enabled. Does NOT replace existing
     * passion decay in prerequest.php.
     *
     * @param array  &$dynamics        NPC dynamics blob (modified in place)
     * @param string $npcName          NPC name (for logging)
     * @param string $temperament      NPC temperament
     * @param string $relationshipType Relationship type (romantic, friend, etc.)
     * @param float  $ticksElapsed     Absence ticks (1 tick = GAMETS_PER_DECAY_TICK of game calendar)
     * @return array ['decay_amount' => float, 'old_affinity' => float, 'new_affinity' => float,
     *               'old_tier' => string, 'new_tier' => string, 'tier_changed' => bool,
     *               'demotion_info' => array]
     */
    public static function processAffinityDecay(&$dynamics, $npcName, $temperament, $relationshipType, $ticksElapsed)
    {
        $ticksElapsed = max(0.0, floatval($ticksElapsed));

        // Ensure dimensions sub-array exists
        if (!isset($dynamics['dimensions'])) {
            $dynamics['dimensions'] = [];
        }
        if (!isset($dynamics['dimensions']['affinity'])) {
            $dynamics['dimensions']['affinity'] = ['x' => 0, 'baseline' => null];
        }

        $oldAffinity = self::getCoreAffinity($dynamics); // core units: tiers, rates and retention are too
        $oldTier = self::getCurrentTier($oldAffinity);
        // A label held by an earlier decay run outranks the tier of the (already decayed) number.
        // Only a label judged on core units counts: the 203f8f40 build stored labels from the
        // 0..100 mirror (core 0 = 'friend', core 45 = 'bonded'), which would hold or freeze decay.
        $heldTier = ($dynamics['_current_tier_units'] ?? null) === self::TIER_LABEL_UNITS
            ? ($dynamics['_current_tier'] ?? null) : null;
        if (is_string($heldTier) && self::tierRank($heldTier) > self::tierRank($oldTier)) {
            $oldTier = $heldTier;
        }

        $result = [
            'decay_amount'   => 0.0,
            'old_affinity'   => $oldAffinity,
            'new_affinity'   => $oldAffinity,
            'old_tier'       => $oldTier,
            'new_tier'       => $oldTier,
            'tier_changed'   => false,
            'demotion_info'  => null,
            'skipped'        => false,
            'skip_reason'    => null,
        ];

        // --- No decay for zero ticks ---
        if ($ticksElapsed < 0.001) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'no_ticks';
            return $result;
        }

        // --- No decay for rival/hostile/antagonist relationship types ---
        $relTypeLower = strtolower(trim($relationshipType));
        $gate = self::TIER_FLOOR_GATES[$relTypeLower] ?? 'none';
        if ($gate === 'no_decay') {
            $result['skipped'] = true;
            $result['skip_reason'] = 'no_decay_type';
            return $result;
        }

        // --- Relationship type decay modifier (design draft: type_decay_modifier) ---
        // 1.0 for types without a row; hostile has 0.0 (hate doesn't fade passively)
        $typeDecayMult = self::getTypeModifier($relTypeLower, 'decay_rate');
        if ($typeDecayMult <= 0.0) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'no_decay_type';
            return $result;
        }

        // --- Where absence decay stops (core units) ---
        // The NPC's affinity baseline (per-NPC override, else temperament "natural pull
        // toward connection"; both core units, as in applyDelta), never below core 0.
        $affBaseline = $dynamics['dimensions']['affinity']['baseline'] ?? null;
        if (!is_numeric($affBaseline)) {
            $affBaseline = self::getTemperamentBaseline($temperament, 'affinity');
        }
        $decayTarget = max(0.0, floatval($affBaseline));
        $result['decay_target'] = $decayTarget;
        if ($oldAffinity <= $decayTarget) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'at_or_below_baseline';
            return $result;
        }

        // --- Calculate base decay ---
        $baseDecayRate = self::TEMPERAMENT_DECAY_RATES[$temperament] ?? -0.5; // core points per tick

        // decay_per_tick is already negative; multiply by ticks
        $totalDecay = $baseDecayRate * $typeDecayMult * $ticksElapsed;

        // Attachment style modifies absence decay
        $absenceMult = self::getAttachmentModifier($dynamics, 'affinity_absence_mult') ?? 1.0;
        $totalDecay *= $absenceMult;
        // (The April ambient decay resist is gone: absence decay is time apart, and a loved
        // place halts in-contact passion decay instead, MDD 1.5 - RelDynFacets::poiPassionFloor.)

        // --- Apply decay to affinity, stopping at the baseline target ---
        $newAffinity = max($decayTarget, min((float) self::CORE_AFFINITY_MAX, $oldAffinity + $totalDecay));
        $actualDecay = $newAffinity - $oldAffinity;

        // Attachment-driven comfort change during absence
        $absenceComfortDelta = self::getAttachmentModifier($dynamics, 'absence_comfort_delta') ?? 0.0;
        if (abs($absenceComfortDelta) > 0.001 && $ticksElapsed > 0) {
            $comfortChange = $absenceComfortDelta * $ticksElapsed;
            self::applyDelta('comfort', $dynamics, $comfortChange, $temperament);
        }

        // Store updated affinity (mirror x; commitPlayerAffinity pushes the change to core)
        self::setCoreAffinity($dynamics, $newAffinity);

        $result['decay_amount'] = $actualDecay;
        $result['new_affinity'] = $newAffinity;

        // --- Check tier demotion ---
        $newTierRaw = self::getCurrentTier($newAffinity);
        $demotionCheck = self::checkTierDemotion($dynamics, $temperament, $relationshipType, $oldTier);
        $result['demotion_info'] = $demotionCheck;

        if ($demotionCheck['should_demote']) {
            // Demotion approved -- tier changes to whatever affinity now maps to
            $result['new_tier'] = $demotionCheck['new_tier'] ?? $newTierRaw;
            $result['tier_changed'] = ($result['new_tier'] !== $oldTier);
        } else {
            // Floor holds -- keep the old tier label even though number dropped
            // The affinity NUMBER still decayed, but the TIER is retained
            $result['new_tier'] = $oldTier;
            $result['tier_changed'] = false;

            // ...down to the retention threshold: going further would trigger demotion,
            // which the floor gate just refused. (The number reaches core aff since A2.)
            if (isset($demotionCheck['demotion_threshold'])) {
                $bound = min($oldAffinity, floatval($demotionCheck['demotion_threshold']));
                if ($newAffinity < $bound) {
                    $newAffinity = $bound;
                    $actualDecay = $newAffinity - $oldAffinity;
                    self::setCoreAffinity($dynamics, $newAffinity);
                    $result['decay_amount'] = $actualDecay;
                    $result['new_affinity'] = $newAffinity;
                }
            }
        }

        // --- Store tier on dynamics for other systems to read ---
        $dynamics['_current_tier'] = $result['new_tier'];
        $dynamics['_current_tier_units'] = self::TIER_LABEL_UNITS;
        // (the absence checkpoint is moved by calculateDecayTicks, which consumed the ticks)

        // --- Log ---
        $tierStr = $result['tier_changed']
            ? "tier={$oldTier}=>{$result['new_tier']} (DEMOTED: {$demotionCheck['reason']})"
            : "tier={$oldTier} (held: {$demotionCheck['reason']})";
        $gateStr = $demotionCheck['gate_status'] !== 'n/a' ? " gate=[{$demotionCheck['gate_status']}]" : '';
        $floorStr = $demotionCheck['floor_active'] ? ' floor=active' : ' floor=inactive';

        error_log("[RelDyn-DECAY] npc={$npcName} decay=" . round($actualDecay, 2)
            . " affinity=" . round($oldAffinity, 1) . "=>" . round($newAffinity, 1)
            . " ticks=" . round($ticksElapsed, 1) . " temp={$temperament}"
            . " {$tierStr}{$gateStr}{$floorStr}");

        return $result;
    }

    // ========== DIVINE INTERVENTION (PR 10) ==========

    /**
     * Get all bonds for an NPC from the database.
     * Returns array keyed by bond target name with 'aff', 'type', 'trust' keys.
     * Caches only inside a request scope (beginRequest()); otherwise reads fresh.
     */
    public static function getAllBondsForNpc($npcName): array
    {
        $cacheKey = strtolower($npcName);
        if (self::inRequestScope() && isset(self::$bondCache[$cacheKey])) {
            return self::$bondCache[$cacheKey];
        }

        $bonds = [];
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return $bonds;

            $escaped = $db->escape($npcName);
            $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            if (is_array($row) && !empty($row['extended_data'])) {
                $ext = json_decode($row['extended_data'], true) ?: [];
                // CHIM 3.4.1: the player's bond is keyed "Player"
                $relationships = self::normalizeRelationshipMap($ext['relationships'] ?? []);
                foreach ($relationships as $targetName => $relData) {
                    $bonds[$targetName] = [
                        'aff'   => floatval($relData['aff'] ?? 0),
                        'type'  => $relData['type'] ?? 'stranger',
                        'trust' => floatval($relData['trust'] ?? 0),
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn-DI] getAllBondsForNpc error for {$npcName}: " . $e->getMessage());
        }

        if (self::inRequestScope()) {
            self::$bondCache[$cacheKey] = $bonds;
        }
        return $bonds;
    }

    /**
     * Calculate anchor status for DI fork decision.
     * Trust.x is GLOBAL (not per-bond). Uses it once for player bond,
     * aff*0.5 as trust proxy for NPC-to-NPC bonds.
     */
    public static function calculateAnchorStatus($npcName, &$dynamics): array
    {
        $bonds = self::getAllBondsForNpc($npcName);
        $dims = $dynamics['dimensions'] ?? [];

        $globalTrustX = floatval($dims['trust']['x'] ?? 0);
        $totalTrust = 0.0;
        $maxAffinity = 0.0;

        foreach ($bonds as $targetName => $bond) {
            $bondAff = ($bond['aff'] + 100) / 2.0; // Scale -100..+100 to 0..100

            if ($targetName === self::PLAYER_RELATIONSHIP_KEY) {
                $totalTrust += $globalTrustX;
            } else {
                $totalTrust += max(0, $bondAff * 0.5);
            }

            if ($bondAff > $maxAffinity) {
                $maxAffinity = $bondAff;
            }
        }

        return [
            'has_anchor'   => ($totalTrust > 100 && $maxAffinity > 60),
            'is_alone'     => ($totalTrust < 50 && $maxAffinity < 40),
            'total_trust'  => $totalTrust,
            'max_affinity' => $maxAffinity,
        ];
    }

    /**
     * Trigger Divine Intervention — catastrophic event processing.
     * The Fork: anchor → redemption, alone → breaking, neither → unstable window.
     */
    public static function triggerDivineIntervention($npcName, $eventType, $severity, &$dynamics)
    {
        // Config gate
        $config = self::getConfig();
        if (empty($config['divine_intervention_enabled'])) {
            return;
        }

        // Session cooldown
        $lastDI = floatval($dynamics['_divine_intervention_last'] ?? 0);
        $currentPlayGamets = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
        if ($lastDI > 0 && ($currentPlayGamets - $lastDI) < self::DI_COOLDOWN_GAMETS) {
            self::log("[DIVINE] Cooldown active for {$npcName}, skipping DI");
            return;
        }

        // Calculate anchor status
        $anchor = self::calculateAnchorStatus($npcName, $dynamics);

        // The Fork
        if ($anchor['has_anchor']) {
            self::applyRedemptionArc($npcName, $severity, $dynamics);
        } elseif ($anchor['is_alone']) {
            self::applyBreakingArc($npcName, $severity, $dynamics);
        } else {
            // Neither anchored nor alone — open unstable window
            self::openUnstableWindow($npcName, $eventType, $severity, $dynamics);
        }

        // Update DI tracking
        $dynamics['_divine_intervention_count'] = intval($dynamics['_divine_intervention_count'] ?? 0) + 1;
        $dynamics['_divine_intervention_last'] = $currentPlayGamets;

        self::log("[DIVINE] Triggered for {$npcName}: type={$eventType}, sev={$severity}, anchor=[trust={$anchor['total_trust']}, maxAff={$anchor['max_affinity']}]");
    }

    /**
     * Redemption Arc — baseline rewrite UP.
     * Triggered when NPC has strong anchor bonds during catastrophic event.
     */
    private static function applyRedemptionArc($npcName, $severity, &$dynamics)
    {
        $shift = min(30, 15 + ($severity * 3));
        $dims = &$dynamics['dimensions'];

        // Maturity baseline UP — bypass applyDelta
        $maturityBaseline = floatval($dims['maturity']['baseline'] ?? 50);
        $dims['maturity']['baseline'] = min(100, $maturityBaseline + $shift);
        $dims['maturity']['x'] = min(100, floatval($dims['maturity']['x'] ?? $maturityBaseline) + $shift);

        // Zero resentment
        $dims['resentment']['x'] = 0;
        $dims['resentment']['pending_grievances'] = [];

        // Halve resentment_self
        $dims['resentment_self']['x'] = floatval($dims['resentment_self']['x'] ?? 0) / 2.0;

        // Comfort baseline +10
        $comfortBaseline = floatval($dims['comfort']['baseline'] ?? 30);
        $dims['comfort']['baseline'] = min(100, $comfortBaseline + 10);

        // Plasticity override: Growth for 30 game days (raw gamets)
        $currentRawGamets = floatval($dynamics['_last_gamets'] ?? 0);
        $dynamics['_plasticity_override'] = 'Growth';
        $dynamics['_plasticity_override_start_gamets'] = $currentRawGamets;
        $dynamics['_plasticity_override_expires_gamets'] = $currentRawGamets + self::THIRTY_GAME_DAYS_GAMETS;

        // Attachment shift eligible
        $dynamics['_attachment_shift_available'] = true;
        $dynamics['_divine_intervention_last_type'] = 'redemption';

        self::log("[DIVINE] Redemption arc for {$npcName}: shift={$shift}, maturity_baseline={$dims['maturity']['baseline']}");
    }

    /**
     * Breaking Arc — baseline rewrite DOWN.
     * Triggered when NPC is isolated during catastrophic event.
     */
    private static function applyBreakingArc($npcName, $severity, &$dynamics)
    {
        $shift = min(30, 15 + ($severity * 3));
        $dims = &$dynamics['dimensions'];

        // Maturity baseline DOWN
        $maturityBaseline = floatval($dims['maturity']['baseline'] ?? 50);
        $dims['maturity']['baseline'] = max(0, $maturityBaseline - $shift);
        $dims['maturity']['x'] = max(0, floatval($dims['maturity']['x'] ?? $maturityBaseline) - $shift);

        // Comfort baseline -20
        $comfortBaseline = floatval($dims['comfort']['baseline'] ?? 30);
        $dims['comfort']['baseline'] = max(0, $comfortBaseline - 20);

        // Halve trust
        $dims['trust']['x'] = floatval($dims['trust']['x'] ?? 50) / 2.0;

        // Zero warmth toward non-bonded
        $relType = self::getRelationshipType($npcName, $dynamics);
        if ($relType !== 'bonded' && $relType !== 'sworn') {
            $dims['warmth']['x'] = 0;
        }

        // Plasticity override: Brittle for 30 game days (raw gamets)
        $currentRawGamets = floatval($dynamics['_last_gamets'] ?? 0);
        $dynamics['_plasticity_override'] = 'Brittle';
        $dynamics['_plasticity_override_start_gamets'] = $currentRawGamets;
        $dynamics['_plasticity_override_expires_gamets'] = $currentRawGamets + self::THIRTY_GAME_DAYS_GAMETS;

        $dynamics['_attachment_shift_available'] = true;
        $dynamics['_divine_intervention_last_type'] = 'breaking';

        self::log("[DIVINE] Breaking arc for {$npcName}: shift={$shift}, maturity_baseline={$dims['maturity']['baseline']}");
    }

    /**
     * Open an unstable window when DI finds neither anchor nor isolation.
     * Full implementation in Segment 3 (Unstable Window).
     */
    private static function openUnstableWindow($npcName, $eventType, $severity, &$dynamics)
    {
        // Check if window already open
        $existingWindow = $dynamics['_unstable_window'] ?? null;
        if ($existingWindow && empty($existingWindow['resolved'])) {
            // Already open — resolve existing based on new event
            if ($eventType === 'betrayal') {
                $dynamics['_unstable_window']['resolved'] = true;
                $dynamics['_unstable_window']['resolution'] = 'breaking';
                self::applyBreakingArc($npcName, $severity, $dynamics);
                self::log("[DIVINE] Existing unstable window resolved via betrayal -> breaking");
            }
            return;
        }

        $dynamics['_unstable_window'] = [
            'start_gamets'    => floatval($dynamics['_accumulated_play_gamets'] ?? 0),
            'duration_gamets' => self::UNSTABLE_WINDOW_GAMETS,
            'event_type'      => $eventType,
            'severity'        => $severity,
            'resolved'        => false,
            'resolution'      => null,
        ];

        self::log("[DIVINE] Unstable window opened for {$npcName}: event={$eventType}, severity={$severity}");
    }

    /**
     * Check unstable window state. Called during prerequest for NPCs with active windows.
     * Returns 'redemption', 'breaking', 'active', or null (no window).
     */
    public static function checkUnstableWindow($npcName, &$dynamics, $interactingWith = null): ?string
    {
        $window = $dynamics['_unstable_window'] ?? null;
        if (!$window || !empty($window['resolved'])) {
            return null;
        }
        // Config gate
        $config = self::getConfig();
        if (empty($config['divine_intervention_enabled'])) {
            return null;
        }

        $currentGamets = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
        $elapsed = $currentGamets - floatval($window['start_gamets']);
        $duration = floatval($window['duration_gamets']);
        $elapsedFraction = ($duration > 0) ? ($elapsed / $duration) : 0;

        // Check multiple potential anchors: the player AND nearby NPCs
        $potentialAnchors = [];
        if ($interactingWith !== null) {
            $potentialAnchors[] = $interactingWith;
        }
        // Check CACHE_PEOPLE for NPC anchors
        $cachePeopleRaw = $GLOBALS['CACHE_PEOPLE'] ?? '';
        $cachePeople = array_values(array_filter(array_map('trim', explode('|', $cachePeopleRaw))));
        foreach ($cachePeople as $nearbyNpc) {
            if (!empty($nearbyNpc) && strcasecmp($nearbyNpc, $npcName) !== 0) {
                $potentialAnchors[] = $nearbyNpc;
            }
        }
        $potentialAnchors = array_unique($potentialAnchors);

        $bonds = self::getAllBondsForNpc($npcName);
        foreach ($potentialAnchors as $anchor) {
            // Bonds are keyed "Player" for the player (CHIM 3.4.1); $anchor may be the real name
            $anchorKey = self::relationshipTargetKey($anchor);
            $bond = $bonds[$anchorKey] ?? null;
            if ($bond) {
                $bondAff = ($bond['aff'] + 100) / 2.0;
                $bondTrust = ($anchorKey === self::PLAYER_RELATIONSHIP_KEY)
                    ? floatval($dynamics['dimensions']['trust']['x'] ?? 0)
                    : max(0, $bondAff * 0.5);
                if ($bondAff > 50 && $bondTrust > 40) {
                    $dynamics['_unstable_window']['resolved'] = true;
                    $dynamics['_unstable_window']['resolution'] = 'redemption';
                    self::applyRedemptionArc($npcName, $window['severity'], $dynamics);
                    self::log("[DIVINE] Unstable window resolved: REDEMPTION via {$anchor}");
                    return 'redemption';
                }
            }
        }

        // Check if window expired
        if ($elapsed >= $duration) {
            $dynamics['_unstable_window']['resolved'] = true;
            $dynamics['_unstable_window']['resolution'] = 'breaking';
            self::applyBreakingArc($npcName, $window['severity'], $dynamics);
            self::log("[DIVINE] Unstable window expired: BREAKING for {$npcName}");
            return 'breaking';
        }

        return 'active';
    }

    /**
     * Generate escalating crisis narration for unstable window context injection.
     * Three tiers: fresh shock (0-25%), mid-crisis (25-75%), desperate urgency (75%+).
     */
    public static function generateCrisisNarration($npcName, $windowState): string
    {
        $eventType = $windowState['event_type'] ?? 'unknown';
        $elapsed = floatval($windowState['_elapsed_fraction'] ?? 0);

        if ($elapsed >= 0.75) {
            $urgencyNarrations = [
                'companion_death' => "{$npcName} is barely holding on. The grief has hollowed them out and something fundamental is about to snap. They are searching desperately for a reason not to break -- and running out of time to find one.",
                'near_tpk'        => "{$npcName} has been teetering on the edge since the brush with death and the balance is finally tipping. The window to reach them is closing -- whatever they become next is being decided RIGHT NOW.",
                'betrayal'        => "{$npcName} has been spiraling since the betrayal and the descent is accelerating. The walls they are building are almost finished. Once they close, they may never open again.",
                'home_destruction' => "{$npcName} is adrift and sinking. Without an anchor, the current is pulling them somewhere dark. If someone doesn't reach them soon, they will be unreachable.",
                'quest_event'     => "{$npcName} has been rebuilding their worldview for a while now and the foundation is setting. Whatever shape it takes will be permanent. The last chance to influence the direction is slipping away.",
            ];
            return $urgencyNarrations[$eventType] ?? "{$npcName} is at a breaking point. Something irreversible is about to happen. Time is almost up.";
        } elseif ($elapsed >= 0.25) {
            $midNarrations = [
                'companion_death' => "{$npcName} carries the loss like a physical weight. The numbness is fading and what replaces it will depend on who or what they encounter next. They are looking for meaning -- or proof that there is none.",
                'near_tpk'        => "{$npcName} keeps reliving the moment they almost died. The fear is transforming into something else -- gratitude or rage, depending on what the world shows them next.",
                'betrayal'        => "{$npcName} is cycling between fury and disbelief. The trust that was broken is being examined from every angle. They are deciding whether to rebuild or burn it all down.",
                'home_destruction' => "{$npcName} drifts between places that used to feel familiar but no longer do. They are looking for a new anchor point -- consciously or not.",
                'quest_event'     => "{$npcName} has been questioning everything they thought they knew. The old certainties are gone. New ones haven't formed yet.",
            ];
            return $midNarrations[$eventType] ?? "{$npcName} is at a crossroads. Something fundamental is shifting. The direction hasn't been decided yet.";
        } else {
            $freshNarrations = [
                'companion_death' => "{$npcName} is hollow. Something behind their eyes has gone quiet. They are present but unreachable -- the loss hasn't fully landed yet, but when it does, everything could change.",
                'near_tpk'        => "{$npcName} survived, but barely. The brush with death left something cracked. They are oscillating between grateful and terrified, and which way they settle depends on what happens next.",
                'betrayal'        => "{$npcName} is reeling from a betrayal that rewrote their understanding of someone they trusted. The ground shifted. They are looking for something to hold onto -- or deciding there is nothing worth holding.",
                'home_destruction' => "{$npcName} lost something that represented safety. Without that anchor, they are adrift. The next person who shows up might become their new anchor -- or prove that anchors break.",
                'quest_event'     => "{$npcName} witnessed something that shattered their worldview. The old rules don't apply anymore. They are rebuilding from scratch, and the foundation could go either way.",
            ];
            return $freshNarrations[$eventType] ?? "{$npcName} is in shock. Something fundamental just happened. They don't know what it means yet.";
        }
    }

    /**
     * Placeholder for future SNQE quest event integration.
     * External systems can call this to trigger DI from quest events.
     */
    public static function onQuestEvent($npcName, $questId, $stageId)
    {
        // Placeholder — not wired in PR 10
        self::log("[DIVINE] Quest event placeholder: npc={$npcName}, quest={$questId}, stage={$stageId}");
    }

    // ========== DEATH/GRIEF SYSTEM (PR 10) ==========

    /**
     * Register an NPC death for a survivor. Creates grief bond, sets widow's lock, triggers Phase 1, fires survivor DI.
     */
    public static function onNpcDeath($deceasedName, $survivorName, &$survivorDynamics)
    {
        // Config gate
        $config = self::getConfig();
        if (empty($config['grief_system_enabled'])) {
            return;
        }

        // Bond duration proxy: use deceased's accumulated time
        $deceasedDynamics = self::getDynamics($deceasedName);
        $bondDurationHours = floatval($deceasedDynamics['_accumulated_time'] ?? 0) / 3600.0;

        $bonds = self::getAllBondsForNpc($survivorName);
        $deceasedBond = $bonds[$deceasedName] ?? null;
        $bondAffinity = $deceasedBond ? (($deceasedBond['aff'] + 100) / 2.0) : 0;

        $dims = $survivorDynamics['dimensions'] ?? [];

        // Initialize grief bond with per-phase applied flags
        $survivorDynamics['_grief_bonds'][$deceasedName] = [
            'phase'                   => 1,
            'death_gamets'            => floatval($survivorDynamics['_accumulated_play_gamets'] ?? 0),
            'bond_duration_hours'     => $bondDurationHours,
            'original_type'           => $deceasedBond['type'] ?? 'friend',
            'bond_affinity_at_death'  => $bondAffinity,
            'warmth_at_death'         => floatval($dims['warmth']['x'] ?? 0),
            'trust_at_death'          => floatval($dims['trust']['x'] ?? 0),
            'phase_transitions'       => [1 => floatval($survivorDynamics['_accumulated_play_gamets'] ?? 0)],
            '_phase_1_applied'        => false,
            '_phase_2_applied'        => false,
            '_phase_3_applied'        => false,
            '_phase_4_applied'        => false,
        ];

        // Widow's Lock ceiling
        $bondDurationWeight = min(2.0, $bondDurationHours / 100.0);
        $ceiling = 100 - ($bondDurationWeight * 20);
        $existingCeiling = floatval($survivorDynamics['_widow_lock_ceiling'] ?? 100);
        $survivorDynamics['_widow_lock_ceiling'] = min($existingCeiling, $ceiling);

        // Apply Phase 1 immediate effects
        self::applyGriefPhase($survivorName, $deceasedName, 1, $survivorDynamics);

        // Trigger Survivor's DI
        $severity = min(5, max(1, intval($bondDurationWeight * 2.5)));
        self::triggerDivineIntervention($survivorName, 'companion_death', $severity, $survivorDynamics);

        self::log("[GRIEF] Death of {$deceasedName} registered for {$survivorName}: duration={$bondDurationHours}h, ceiling={$ceiling}, severity={$severity}");
    }

    /**
     * Process grief phase transitions. Called during prerequest for NPCs with active grief bonds.
     * Phase thresholds scale by bond duration weight.
     */
    public static function processGriefPhases($npcName, &$dynamics)
    {
        $griefBonds = &$dynamics['_grief_bonds'];
        if (empty($griefBonds)) return;

        // Config gate
        $config = self::getConfig();
        if (empty($config['grief_system_enabled'])) return;

        $currentGamets = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
        $gametsPerHour = self::GAMETS_PER_REAL_HOUR;

        foreach ($griefBonds as $deceasedName => &$grief) {
            $elapsed = $currentGamets - floatval($grief['death_gamets']);
            $hoursElapsed = $elapsed / $gametsPerHour;

            $weight = min(2.0, floatval($grief['bond_duration_hours']) / 100.0);
            $weight = max(0.1, $weight);

            $currentPhase = intval($grief['phase']);
            $newPhase = $currentPhase;

            // Phase transition thresholds (scaled by bond_duration_weight)
            if ($hoursElapsed >= 15.0 * $weight && $currentPhase < 4) {
                $newPhase = 4;
            } elseif ($hoursElapsed >= 5.0 * $weight && $currentPhase < 3) {
                $newPhase = 3;
            } elseif ($hoursElapsed >= 2.0 * $weight && $currentPhase < 2) {
                $newPhase = 2;
            }

            if ($newPhase > $currentPhase) {
                $grief['phase_transitions'][$newPhase] = $currentGamets;
                $grief['phase'] = $newPhase;
                self::applyGriefPhase($npcName, $deceasedName, $newPhase, $dynamics);
            }
        }
    }

    /**
     * Apply grief phase effects. One-shot per phase (checked via _phase_X_applied flag).
     */
    private static function applyGriefPhase($npcName, $deceasedName, $phase, &$dynamics)
    {
        $dims = &$dynamics['dimensions'];
        $grief = &$dynamics['_grief_bonds'][$deceasedName];

        // Check if this phase has already been applied
        $appliedKey = "_phase_{$phase}_applied";
        if (!empty($grief[$appliedKey])) {
            return;
        }
        $grief[$appliedKey] = true;

        switch ($phase) {
            case 1: // Acute
                $dims['comfort']['x'] = max(0, floatval($dims['comfort']['x'] ?? 50) - 15);
                $dims['warmth']['x'] = max(0, floatval($dims['warmth']['x'] ?? 30) - 10);
                $dims['valence']['x'] = min(-30, floatval($dims['valence']['x'] ?? 0));
                $dims['arousal']['x'] = max(50, floatval($dims['arousal']['x'] ?? 10));
                self::log("[GRIEF] Phase 1 (Acute) applied for {$npcName} re: {$deceasedName}");
                break;

            case 2: // Bargaining
                $dims['trust']['x'] = max(0, floatval($dims['trust']['x'] ?? 50) - 5);
                self::log("[GRIEF] Phase 2 (Bargaining) applied for {$npcName} re: {$deceasedName}");
                break;

            case 3: // Integration
                // Recovery begins — no direct writes; rubber band handles recovery
                self::log("[GRIEF] Phase 3 (Integration) applied for {$npcName} re: {$deceasedName}");
                break;

            case 4: // Carrying Forward
                // Grieving → Memorial transition
                self::log("[GRIEF] Phase 4 (Carrying Forward) for {$npcName} re: {$deceasedName}: ceiling={$dynamics['_widow_lock_ceiling']}");
                break;
        }
    }

    /**
     * Get grief keywords for context injection. High maturity = quiet grief, low maturity = public breakdown.
     */
    public static function getGriefKeywords($npcName, $deceasedName, $phase, $maturity): string
    {
        $quiet = ($maturity > 60);
        switch ($phase) {
            case 1:
                return $quiet
                    ? "{$npcName} carries the loss of {$deceasedName} in silence. Still waters, but the undercurrent is devastating. Withdrawn, unreachable, numbly functional."
                    : "{$npcName} is shattered by the loss of {$deceasedName}. Visibly struggling, breaking down, unable to maintain composure. The grief is raw and public.";
            case 2:
                return $quiet
                    ? "{$npcName} speaks of {$deceasedName} as if they might return. Idealizing the memory, recounting only the good. A quiet bargaining with fate."
                    : "{$npcName} swings between desperate hope and crushing reality about {$deceasedName}. Talks about them constantly, looking for signs, refusing to let go.";
            case 3:
                return $quiet
                    ? "{$npcName} has begun to make peace with {$deceasedName}'s absence. The sharp edges of grief are smoothing. They speak of them with bittersweet warmth."
                    : "{$npcName} is slowly finding ground after losing {$deceasedName}. Good moments mixed with sudden waves of loss. Healing, but unevenly.";
            case 4:
                return "{$npcName} carries {$deceasedName}'s memory as part of who they are now. The grief has transformed into something quieter -- a memorial, not a wound. They can form new bonds, though the lost one left a permanent mark.";
        }
        return '';
    }

    // ========== ATTACHMENT STYLE SYSTEM (PR 10) ==========

    public static function getAttachmentStyle($dynamics): string
    {
        $config = self::getConfig();
        if (empty($config['attachment_style_enabled'])) {
            return 'secure'; // No-op when disabled
        }
        $explicit = $dynamics['attachment_style'] ?? null;
        if ($explicit && isset(self::ATTACHMENT_MODIFIERS[$explicit])) {
            return $explicit;
        }
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        return self::TEMPERAMENT_ATTACHMENT_DEFAULTS[$temperament] ?? 'secure';
    }

    public static function getAttachmentModifier($dynamics, string $key)
    {
        $style = self::getAttachmentStyle($dynamics);
        return self::ATTACHMENT_MODIFIERS[$style][$key] ?? null;
    }

    public static function processAttachmentShift(&$dynamics): ?string
    {
        if (empty($dynamics['_attachment_shift_available'])) {
            return null;
        }
        $config = self::getConfig();
        if (empty($config['attachment_style_enabled']) || empty($config['divine_intervention_enabled'])) {
            return null;
        }

        $currentStyle = self::getAttachmentStyle($dynamics);
        $diType = $dynamics['_divine_intervention_last_type'] ?? null;
        $newStyle = null;

        if ($diType === 'redemption') {
            $newStyle = self::ATTACHMENT_REDEMPTION_SHIFTS[$currentStyle] ?? $currentStyle;
        } elseif ($diType === 'breaking') {
            $breakTarget = self::ATTACHMENT_BREAKING_SHIFTS[$currentStyle] ?? null;
            if ($breakTarget === null && $currentStyle === 'secure') {
                // Determined by self_confidence
                $selfConf = floatval($dynamics['dimensions']['self_confidence']['x'] ?? 50);
                $newStyle = ($selfConf > 50) ? 'avoidant' : 'anxious';
            } else {
                $newStyle = $breakTarget ?? $currentStyle;
            }
        }

        if ($newStyle && $newStyle !== $currentStyle) {
            $dynamics['attachment_style'] = $newStyle;
            $dynamics['_attachment_shift_available'] = false;
            self::log("[ATTACHMENT] Shift: {$currentStyle} -> {$newStyle} via {$diType}");
            return $newStyle;
        }

        $dynamics['_attachment_shift_available'] = false;
        return null;
    }

    public static function checkAttachmentDrift(&$dynamics, string $temperament): ?string
    {
        $config = self::getConfig();
        if (empty($config['attachment_style_enabled'])) {
            return null;
        }

        $accum = intval($dynamics['_accumulated_time'] ?? 0);
        $lastCheck = intval($dynamics['_attachment_drift_last_check'] ?? 0);
        if (($accum - $lastCheck) < 18000) { // 5 hours of play time
            return null;
        }
        $dynamics['_attachment_drift_last_check'] = $accum;

        $currentStyle = self::getAttachmentStyle($dynamics);
        $dims = $dynamics['dimensions'] ?? [];
        $maturity = floatval($dims['maturity']['x'] ?? 50);
        $trust = floatval($dims['trust']['x'] ?? 50);
        $resentment = floatval($dims['resentment']['x'] ?? 0);

        // Anxious -> Secure drift: sustained health
        if ($currentStyle === 'anxious') {
            if ($maturity > 55 && $trust > 60 && $resentment < 15) {
                $dynamics['_attachment_drift_score'] = intval($dynamics['_attachment_drift_score'] ?? 0) + 1;
                if ($dynamics['_attachment_drift_score'] >= 3) {
                    $dynamics['attachment_style'] = 'secure';
                    $dynamics['_attachment_drift_score'] = 0;
                    return 'secure';
                }
            } else {
                $dynamics['_attachment_drift_score'] = max(0, intval($dynamics['_attachment_drift_score'] ?? 0) - 1);
            }
        }

        // Toxic -> Anxious drift: maturity exceeded floor+10 (only via DI/override)
        if ($currentStyle === 'toxic') {
            $floor = self::ATTACHMENT_MODIFIERS['toxic']['maturity_floor'];
            if ($maturity >= ($floor + 10)) {
                $dynamics['attachment_style'] = 'anxious';
                $dynamics['_attachment_drift_score'] = 0;
                return 'anxious';
            }
        }

        return null;
    }

    /**
     * Calculate absence ticks since the player last talked to this NPC, and consume them.
     *
     * Absence runs on the GAME CALENDAR (raw gamets, currentGamets()): waiting and sleeping
     * are time passing in the world (decisions 2026-09-23 section 2, no wait-scumming).
     * Save+quit adds nothing (the game clock doesn't run offline).
     *
     * One tick = GAMETS_PER_DECAY_TICK raw gamets (1,389,000 = 200 game minutes, i.e.
     * ~10 real minutes of normal play at 20:1; 7.2 ticks per game day).
     *
     * The checkpoint (_decay_last_game_gamets) moves to "now" on every call that reads the
     * clock, so the returned ticks are consumed and never counted twice; the caller must
     * apply them (or deliberately drop them, e.g. while decay is paused).
     * Returns 0 when the clock is unknown (checkpoint kept), on first contact or after an
     * earlier save was loaded (checkpoint re-armed), and for a turn less than one tick after
     * the previous one (still talking: conversation is not absence).
     *
     * Walkaway (PR 16): the time the NPC was gone (_decay_paused_intervals, game calendar,
     * see startDecayPause/endDecayPause) is left out of the ticks and dropped, not banked. The
     * absence before the NPC left and after it came back still counts, whichever request
     * (this NPC's turn or the calendar scan) started or ended the walkaway.
     *
     * @param array &$dynamics  NPC dynamics blob (modified: sets _decay_last_game_gamets)
     * @return float  Number of absence ticks elapsed
     */
    public static function calculateDecayTicks(&$dynamics)
    {
        $now = self::currentGamets();
        if ($now <= 0) {
            return 0.0;
        }
        $mark = floatval($dynamics['_decay_last_game_gamets'] ?? 0);
        $dynamics['_decay_last_game_gamets'] = $now;

        if ($mark <= 0 || $mark > $now) {
            self::dropConsumedDecayPauses($dynamics, $now);
            return 0.0;
        }

        $gametsSinceDecay = $now - $mark;                                   // raw gamets
        $paused = self::decayPausedGametsBetween($dynamics, $mark, $now);    // raw gamets
        self::dropConsumedDecayPauses($dynamics, $now);
        if ($gametsSinceDecay < self::GAMETS_PER_DECAY_TICK) {
            return 0.0;
        }

        $ticksElapsed = max(0.0, $gametsSinceDecay - $paused) / self::GAMETS_PER_DECAY_TICK;

        return $ticksElapsed;
    }

    /**
     * Pause absence decay from now (game calendar): the NPC walked away. The interval stays
     * open until endDecayPause(). Unit: raw gamets.
     */
    public static function startDecayPause(array &$dynamics): void
    {
        $dynamics['_walkaway_affinity_decay_paused'] = true;
        $now = self::currentGamets();
        if ($now <= 0) {
            return;   // no clock: the legacy flag alone pauses the whole gap
        }
        $intervals = is_array($dynamics['_decay_paused_intervals'] ?? null) ? $dynamics['_decay_paused_intervals'] : [];
        foreach ($intervals as $iv) {
            if (is_array($iv) && ($iv['until'] ?? null) === null) {
                return;   // already paused
            }
        }
        $intervals[] = ['from' => $now, 'until' => null];
        $dynamics['_decay_paused_intervals'] = array_values($intervals);
    }

    /** End the walkaway's decay pause now (game calendar); decay counts again from here. */
    public static function endDecayPause(array &$dynamics): void
    {
        $wasPaused = !empty($dynamics['_walkaway_affinity_decay_paused']);
        $dynamics['_walkaway_affinity_decay_paused'] = false;
        $now = self::currentGamets();
        $intervals = is_array($dynamics['_decay_paused_intervals'] ?? null) ? $dynamics['_decay_paused_intervals'] : [];
        $open = false;
        foreach ($intervals as $i => $iv) {
            if (is_array($iv) && ($iv['until'] ?? null) === null) {
                $intervals[$i]['until'] = $now > 0 ? max($now, floatval($iv['from'] ?? 0)) : floatval($iv['from'] ?? 0);
                $open = true;
            }
        }
        if (!$open && $wasPaused && $now > 0) {
            // Paused by an older build (flag only): paused since the absence checkpoint.
            $from = floatval($dynamics['_decay_last_game_gamets'] ?? 0);
            if ($from > 0 && $from < $now) {
                $intervals[] = ['from' => $from, 'until' => $now];
            }
        }
        if ($intervals) {
            $dynamics['_decay_paused_intervals'] = array_values($intervals);
        }
    }

    /** Raw gamets of [from, to] covered by decay pauses (an open pause runs to $to). */
    private static function decayPausedGametsBetween(array $dynamics, float $from, float $to): float
    {
        $intervals = is_array($dynamics['_decay_paused_intervals'] ?? null) ? $dynamics['_decay_paused_intervals'] : [];
        $open = false;
        $paused = 0.0;
        foreach ($intervals as $iv) {
            if (!is_array($iv)) continue;
            $start = floatval($iv['from'] ?? 0);
            $end = ($iv['until'] ?? null) === null ? $to : floatval($iv['until']);
            if (($iv['until'] ?? null) === null) $open = true;
            $paused += max(0.0, min($end, $to) - max($start, $from));
        }
        if (!$open && !empty($dynamics['_walkaway_affinity_decay_paused'])) {
            // Paused by an older build (flag only, no interval): the whole gap is paused.
            return $to - $from;
        }
        return min($paused, $to - $from);
    }

    /** Forget pauses the absence checkpoint has passed (closed at or before $now). */
    private static function dropConsumedDecayPauses(array &$dynamics, float $now): void
    {
        if (!is_array($dynamics['_decay_paused_intervals'] ?? null)) return;
        $keep = array_values(array_filter($dynamics['_decay_paused_intervals'], fn($iv) => is_array($iv)
            && (($iv['until'] ?? null) === null || floatval($iv['until']) > $now)));
        if ($keep) {
            $dynamics['_decay_paused_intervals'] = $keep;
        } else {
            unset($dynamics['_decay_paused_intervals']);
        }
    }

    // ========== END AFFINITY DECAY + TIER DEMOTION (PR 5) ==========

    // ========== SOCIAL SENSITIVITY CURVES (PR 5) ==========

    /**
     * Temperament → sensitivity curve mapping.
     * romantic_mid = average of inner_circle + open_heart
     * uniform_low = flat 0.5, uniform_mid = flat 0.7
     */
    const TEMPERAMENT_SENSITIVITY_CURVES = [
        'Stoic'       => 'inner_circle',
        'Independent' => 'inner_circle',
        'Guarded'     => 'inner_circle',
        'Proud'       => 'inner_circle',   // Exception: respect dimension uses open_heart
        'Anxious'     => 'open_heart',
        'Nurturing'   => 'open_heart',
        'Gentle'      => 'open_heart',
        'Humble'      => 'open_heart',
        'Romantic'    => 'romantic_mid',
        'Playful'     => 'uniform_low',
        'Bold'        => 'uniform_mid',
        'Jealous'     => 'inner_circle',   // Exception: passion/comfort uses open_heart
        'Defiant'     => 'inverse_tolerance',
    ];

    /**
     * Calculate social sensitivity multiplier from curve type and bond level.
     *
     * @param string $curveType   inner_circle|open_heart|uniform|uniform_low|uniform_mid|romantic_mid|inverse_tolerance
     * @param float  $bondLevel   Affinity value 0-100
     * @param bool   $isNegative  Whether the delta being modified is negative
     * @return float Sensitivity multiplier 0.0-1.0
     */
    public static function calculateSocialSensitivity($curveType, $bondLevel, $isNegative = false)
    {
        $bondLevel = max(0, min(100, floatval($bondLevel)));

        switch ($curveType) {
            case 'inner_circle':
                // Quadratic: only close bonds land
                return ($bondLevel * $bondLevel) / 10000.0;

            case 'open_heart':
                // Square root: everyone's opinion matters
                return sqrt($bondLevel) / 10.0;

            case 'uniform':
                return 1.0;

            case 'uniform_low':
                return 0.5;

            case 'uniform_mid':
                return 0.7;

            case 'romantic_mid':
                // Average of inner_circle and open_heart
                $inner = ($bondLevel * $bondLevel) / 10000.0;
                $open  = sqrt($bondLevel) / 10.0;
                return ($inner + $open) / 2.0;

            case 'inverse_tolerance':
                // Close bonds get patience (low sensitivity to negatives)
                // Positive deltas always pass at 1.0
                if (!$isNegative) {
                    return 1.0;
                }
                return max(0.1, 1.0 - ($bondLevel * $bondLevel) / 10000.0);

            default:
                return 1.0;
        }
    }

    /**
     * Get the social sensitivity curve type for a temperament.
     * Per-NPC override via dynamics['social_sensitivity_curve'] takes priority.
     */
    public static function getSocialSensitivityCurve($temperament, $dynamics = null)
    {
        // Per-NPC override
        if ($dynamics !== null && !empty($dynamics['social_sensitivity_curve'])) {
            return $dynamics['social_sensitivity_curve'];
        }
        return self::TEMPERAMENT_SENSITIVITY_CURVES[$temperament] ?? 'open_heart';
    }

    /**
     * Apply social sensitivity to a raw delta.
     *
     * @param array  $dynamics     NPC dynamics (reads affinity for bond level)
     * @param string $dimensionId  Which dimension is being modified
     * @param float  $rawDelta     Raw delta before sensitivity
     * @param string $temperament  NPC temperament
     * @return float Modified delta
     */
    public static function applySocialSensitivity($dynamics, $dimensionId, $rawDelta, $temperament)
    {
        // Config gate
        $cfg = self::getConfig();
        if (isset($cfg['social_sensitivity_enabled']) && !$cfg['social_sensitivity_enabled']) {
            return $rawDelta;
        }

        // Global dimensions bypass sensitivity (self-evaluative)
        if (in_array($dimensionId, self::GLOBAL_DIMENSIONS, true)) {
            return $rawDelta;
        }

        $curve = self::getSocialSensitivityCurve($temperament, $dynamics);

        // Proud exception: respect dimension uses open_heart (disrespect from anyone lands)
        // Only applies if using temperament default (not per-NPC override)
        if (empty($dynamics['social_sensitivity_curve'])) {
            if ($temperament === 'Proud' && $dimensionId === 'respect') {
                $curve = 'open_heart';
            }

            // Jealous exception: passion/comfort dimensions use open_heart
            // (hyperaware of partner's attention toward ANYONE)
            if ($temperament === 'Jealous' && in_array($dimensionId, ['passion', 'comfort'], true)) {
                $curve = 'open_heart';
            }
        }

        $bondLevel = $dynamics['dimensions']['affinity']['x'] ?? 50;
        $isNegative = ($rawDelta < 0);
        $sensitivity = self::calculateSocialSensitivity($curve, $bondLevel, $isNegative);

        return $rawDelta * $sensitivity;
    }

    // ========== END SOCIAL SENSITIVITY CURVES (PR 5) ==========

    // ========== FULL PIPELINE INTEGRATION (PR 5) ==========
    //
    // Wraps applyDelta with type modifiers and social sensitivity so that
    // callers get the complete physics pipeline in a single call:
    //   1. Social sensitivity curve (bond-weighted impact)
    //   2. Type modifier on effective baseline
    //   3. Type modifier on effective resistance
    //   4. Cross-signal caps (already wired inside applyDelta)
    //   5. XYZ physics (Y resistance, Z rubber-band decay)
    //
    // Global dimensions (maturity, self_confidence, coord_m, coord_f,
    // arousal, valence) skip type/sensitivity modifiers -- they are
    // intrinsic to the NPC, not per-bond.
    // ===========================================================

    /**
     * Apply a delta through the full pipeline: social sensitivity, type
     * modifiers, cross-signal caps, and XYZ physics.
     *
     * This is the primary entry point for eval-driven or event-driven
     * dimension changes once all PR 5 subsystems are available.
     *
     * Gated behind dimension_engine_enabled.
     *
     * @param string      $dimensionId       Dimension key (e.g. 'trust')
     * @param array       &$dynamics         NPC dynamics blob (by reference)
     * @param float       $rawDelta          Raw delta from eval or event
     * @param string|null $temperament       Temperament name
     * @param string|null $relationshipType  Bond type (stranger, bonded, etc.) -- auto-detected if null
     * @param array       $overrides         Caller-supplied overrides for applyDelta
     * @return float  Actual delta applied (after all pipeline stages)
     */
    public static function applyDeltaWithContext($dimensionId, &$dynamics, $rawDelta, $temperament, $relationshipType = null, $overrides = [])
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return 0.0;
        }

        $rawDelta = floatval($rawDelta);
        if (abs($rawDelta) < 0.0001) {
            return 0.0;
        }

        // --- Global dimensions skip type/sensitivity modifiers ---
        $isGlobal = in_array($dimensionId, self::GLOBAL_DIMENSIONS, true);

        $delta = $rawDelta;
        $mergedOverrides = $overrides;

        if (!$isGlobal) {
            // --- Resolve relationship type if not provided ---
            if ($relationshipType === null) {
                $npcName = $dynamics['npc_name'] ?? '';
                $relationshipType = self::getRelationshipType($npcName, $dynamics);
            }

            // --- Apply social sensitivity (bond-weighted impact curve) ---
            if (method_exists(__CLASS__, 'applySocialSensitivity')) {
                $delta = self::applySocialSensitivity($dynamics, $dimensionId, $rawDelta, $temperament);
            }

            // --- Get effective baseline (temperament x type modifier) ---
            $effectiveBaseline = self::getEffectiveBaseline(null, $dimensionId, $temperament, $relationshipType);

            // --- Get effective resistance (temperament x type modifier) ---
            // getEffectiveResistance returns ['Y_up' => float, 'Y_down' => float]
            $plasticityCtx = [];
            if (isset($dynamics['dimensions']['maturity']['x'])) {
                $plasticityCtx['maturity'] = $dynamics['dimensions']['maturity']['x'];
            }
            $effectiveProfile = self::getEffectiveResistance($temperament, $dimensionId, $relationshipType, $plasticityCtx);

            // --- Merge effective values into overrides ---
            // Set the baseline override so applyDelta uses the type-modified baseline
            $mergedOverrides['baseline'] = $effectiveBaseline;

            // Also set the stored baseline on the dimension state so the rubber band
            // center reflects the type-modified value
            if (!isset($dynamics['dimensions'])) {
                $dynamics['dimensions'] = [];
            }
            if (!isset($dynamics['dimensions'][$dimensionId])) {
                $dynamics['dimensions'][$dimensionId] = [];
            }
            $dynamics['dimensions'][$dimensionId]['baseline'] = $effectiveBaseline;

            // Use the type-modified Y values unless the caller already provided explicit Y overrides
            if (!isset($mergedOverrides['Y_up']) && !isset($mergedOverrides['Y_down'])) {
                $mergedOverrides['Y_up']   = $effectiveProfile['Y_up'];
                $mergedOverrides['Y_down'] = $effectiveProfile['Y_down'];
            }
        }

        // --- Delegate to applyDelta (handles cross-signal caps + physics) ---
        $actualDelta = self::applyDelta($dimensionId, $dynamics, $delta, $temperament, $mergedOverrides);

        return $actualDelta;
    }

    // ========== TEXT INTENSITY ENGINE ==========
    //
    // Post-processing layer that transforms base band keywords at render time
    // based on current arousal, passion, and maturity dimension values.
    //
    // Three independent effects that stack:
    //   1. Maturity degradation (scrambles base text â applied first)
    //   2. Arousal intensity (adds CAPS and !!! â applied second)
    //   3. Passion intensity (amplifies emotional weight â applied third)
    //   4. Calm/Hollow dampening (ellipsis wrap when both arousal + passion very low)
    //
    // Design invariants:
    //   - Base DIMENSION_BANDS keywords are NEVER modified â intensity is render-time only
    //   - Seeded randomness: same input + same dimension values = same output (deterministic)
    //   - No DB calls â pure string manipulation
    // =============================================

    /**
     * Canonical list of emotional words recognized by the intensity engine.
     * Used to identify which words in a keyword string receive CAPS, scrambling, etc.
     */
    const EMOTIONAL_WORDS = [
        'love', 'hate', 'fear', 'trust', 'rage', 'panic', 'desire', 'desperate',
        'burning', 'ache', 'hurt', 'betray', 'protect', 'save', 'kill', 'die',
        'heart', 'soul', 'blood', 'fire', 'fury', 'agony', 'ecstasy', 'trembling',
        'shaking', 'pounding', 'racing', 'electric', 'magnetic', 'overwhelming',
        'consuming', 'devoted', 'hostile', 'contempt', 'vulnerable', 'shattered',
        'broken', 'alive', 'dead', 'numb', 'hollow', 'bitter', 'resentful',
        'frantic', 'wild', 'raw', 'intense', 'charged', 'heated', 'cold', 'frozen',
        'warm', 'hot', 'safe', 'dangerous', 'threat', 'bond', 'connection',
        'loyalty', 'sacrifice', 'abandon', 'cling', 'withdraw', 'collapse', 'surge',
        'spike', 'crash', 'shudder', 'flinch', 'gasp', 'sob', 'scream', 'whisper',
        'despises', 'contemptuous', 'dismissive', 'fondness', 'unshakable',
        'profound', 'flushed', 'restrained', 'paralyzed', 'validation',
    ];

    /**
     * Apply text intensity post-processing to a band keyword string.
     *
     * Reads arousal, passion, maturity from $dynamics['dimensions'] and applies
     * four stacking effects in order: maturity degradation -> arousal intensity ->
     * passion intensity -> calm dampening.
     *
     * @param string $keywords  Base band keyword string (from DIMENSION_BANDS)
     * @param array  $dynamics  Full dynamics blob (must contain 'dimensions' key)
     * @return string  Transformed keyword string
     */
    public static function applyTextIntensity($keywords, $dynamics)
    {
        if (empty($keywords) || empty($dynamics['dimensions'])) {
            return $keywords;
        }

        $dims = $dynamics['dimensions'];

        // Extract the three driver values (default to safe middle-ground if absent)
        $arousal  = (isset($dims['arousal']['x']) && $dims['arousal']['x'] !== null)
            ? floatval($dims['arousal']['x']) : 10.0;
        $passion  = (isset($dims['passion']['x']) && $dims['passion']['x'] !== null)
            ? floatval($dims['passion']['x']) : 0.0;
        $maturity = (isset($dims['maturity']['x']) && $dims['maturity']['x'] !== null)
            ? floatval($dims['maturity']['x']) : 60.0;

        // Gate: if all values are at safe defaults, skip processing entirely
        // Exception: calm/hollow state (arousal < 10 AND passion < 10) is a meaningful
        // non-default state that needs dampening even when maturity is normal
        $arousalDefault  = ($arousal <= 10);
        $passionDefault  = ($passion <= 15);
        $maturityDefault = ($maturity >= 56);
        $calmHollow      = ($arousal < 10 && $passion < 10);
        if ($arousalDefault && $passionDefault && $maturityDefault && !$calmHollow) {
            return $keywords;
        }

        $text = $keywords;

        // 1. Maturity degradation (scrambles base â must come first)
        $text = self::degradeText($text, $maturity);

        // 2. Arousal intensity (CAPS and !!!)
        // 3. Passion intensity (amplifies emotional weight)
        $text = self::intensifyText($text, $arousal, $passion);

        // 4. Calm/Hollow dampening (when both arousal AND passion very low)
        if ($arousal < 10 && $passion < 10) {
            $text = self::dampenText($text);
        }

        return $text;
    }

    /**
     * Degrade text based on emotional maturity level.
     *
     * Lower maturity = more chaotic text. Uses seeded randomness so the same
     * input text always produces the same degraded output.
     *
     * @param string $text           Input keyword string
     * @param float  $maturityLevel  Current maturity value (0-100)
     * @return string  Degraded text
     */
    public static function degradeText($text, $maturityLevel)
    {
        $mat = floatval($maturityLevel);

        // 56+: no degradation (mature, coherent)
        if ($mat >= 56) {
            return $text;
        }

        // Seed the random generator based on text content for determinism
        $seed = crc32($text . ':' . floor($mat / 5));
        mt_srand($seed);

        // 41-55: light degradation â occasional ... pauses between phrases
        if ($mat >= 41) {
            // Split on commas, insert ... between ~30% of phrases
            $phrases = array_map('trim', explode(',', $text));
            $result = [];
            foreach ($phrases as $i => $phrase) {
                $result[] = $phrase;
                if ($i < count($phrases) - 1 && mt_rand(1, 100) <= 30) {
                    $result[count($result) - 1] .= '...';
                }
            }
            mt_srand(); // Reset RNG
            return implode(', ', $result);
        }

        // Determine degradation intensity
        if ($mat >= 21) {
            // 21-40: moderate degradation
            $vowelReplaceRate = 15;   // ~15% of vowels
            $caseScrambleRate = 20;   // ~20% of letters in emotional words
            $insertPauseRate  = 0;    // no inter-word pauses at moderate
        } else {
            // 0-20: heavy degradation
            $vowelReplaceRate = 30;   // ~30% of vowels
            $caseScrambleRate = 40;   // ~40% of letters in emotional words
            $insertPauseRate  = 15;   // ~15% chance of .. between words
        }

        // Build a set of emotional words for quick lookup
        $emotionalSet = array_flip(self::EMOTIONAL_WORDS);

        $words = explode(' ', $text);
        $processed = [];

        foreach ($words as $word) {
            // Strip trailing punctuation for matching, reattach after
            $punctuation = '';
            if (preg_match('/^(.*?)([,;:.!?]+)$/', $word, $pm)) {
                $word = $pm[1];
                $punctuation = $pm[2];
            }

            $lowerWord = strtolower($word);
            $isEmotional = isset($emotionalSet[$lowerWord]);

            // --- Vowel replacement (organic: skip first vowel of each word ~50% of time) ---
            $chars = str_split($word);
            $vowelsSeen = 0;
            for ($i = 0; $i < count($chars); $i++) {
                if (preg_match('/[aeiouAEIOU]/', $chars[$i])) {
                    $vowelsSeen++;
                    // Skip first vowel more often to keep word recognizable
                    if ($vowelsSeen === 1 && mt_rand(1, 100) <= 50) {
                        continue;
                    }
                    if (mt_rand(1, 100) <= $vowelReplaceRate) {
                        $chars[$i] = (mt_rand(0, 1) === 0) ? '_' : '.';
                    }
                }
            }
            $word = implode('', $chars);

            // --- Case scrambling on emotional words ---
            if ($isEmotional && $caseScrambleRate > 0) {
                $chars = str_split($word);
                for ($i = 0; $i < count($chars); $i++) {
                    if (ctype_alpha($chars[$i]) && mt_rand(1, 100) <= $caseScrambleRate) {
                        $chars[$i] = (mt_rand(0, 1) === 0)
                            ? strtoupper($chars[$i])
                            : strtolower($chars[$i]);
                    }
                }
                $word = implode('', $chars);
            }

            // Reattach punctuation (heavy degradation: sometimes fragment it)
            if ($mat < 21 && !empty($punctuation) && mt_rand(1, 100) <= 25) {
                $punctuation = str_repeat(substr($punctuation, 0, 1), mt_rand(1, 3));
            }
            $word .= $punctuation;

            $processed[] = $word;

            // --- Inter-word pause insertion (heavy degradation only) ---
            if ($insertPauseRate > 0 && mt_rand(1, 100) <= $insertPauseRate) {
                $processed[] = '..';
            }
        }

        mt_srand(); // Reset RNG
        return implode(' ', $processed);
    }

    /**
     * Intensify text based on arousal and passion levels.
     *
     * High arousal adds CAPS and ! marks. High passion amplifies emotional words
     * further. Combined high values stack for maximum intensity.
     *
     * @param string $text          Input keyword string
     * @param float  $arousalLevel  Current arousal value (0-100)
     * @param float  $passionLevel  Current passion value (0-100)
     * @return string  Intensified text
     */
    public static function intensifyText($text, $arousalLevel, $passionLevel)
    {
        $arousal = floatval($arousalLevel);
        $passion = floatval($passionLevel);

        // No modification at low levels
        if ($arousal <= 35 && $passion <= 35) {
            return $text;
        }

        // Seed for determinism
        $seed = crc32($text . ':intensity:' . floor($arousal / 5) . ':' . floor($passion / 5));
        mt_srand($seed);

        // Build emotional word lookup
        $emotionalSet = array_flip(self::EMOTIONAL_WORDS);

        // Determine intensity tiers
        // Arousal tiers: 0=none, 1=low(36-55), 2=mid(56-75), 3=high(76-100)
        $aTier = 0;
        if ($arousal >= 76) $aTier = 3;
        elseif ($arousal >= 56) $aTier = 2;
        elseif ($arousal >= 36) $aTier = 1;

        // Passion tiers: 0=none, 1=low(36-55), 2=mid(56-75), 3=high(76-100)
        $pTier = 0;
        if ($passion >= 76) $pTier = 3;
        elseif ($passion >= 56) $pTier = 2;
        elseif ($passion >= 36) $pTier = 1;

        // Combined intensity level (0-6): drives how many words get affected
        $combined = $aTier + $pTier;

        // Caps probability for emotional words (based on combined intensity)
        $capsChance = min(95, 15 + ($combined * 14));

        // Exclamation probability per phrase (based on arousal tier)
        $exclChance = min(90, 10 + ($aTier * 20) + ($pTier * 10));

        // Process word by word
        $words = explode(' ', $text);
        $processed = [];

        foreach ($words as $word) {
            // Strip trailing punctuation
            $punctuation = '';
            if (preg_match('/^(.*?)([,;:.!?]+)$/', $word, $pm)) {
                $word = $pm[1];
                $punctuation = $pm[2];
            }

            $lowerWord = strtolower(preg_replace('/[_.]/', '', $word));
            $isEmotional = isset($emotionalSet[$lowerWord]);

            if ($isEmotional) {
                // CAPS based on combined intensity
                if (mt_rand(1, 100) <= $capsChance) {
                    $word = strtoupper($word);
                }

                // At passion tier 2+, add emphasis to emotional words
                if ($pTier >= 2 && mt_rand(1, 100) <= 50) {
                    $word = strtoupper($word);
                }
            }

            $processed[] = $word . $punctuation;
        }

        // Reassemble
        $text = implode(' ', $processed);

        // Now process by phrases (comma-separated) for exclamation marks
        $phrases = array_map('trim', explode(',', $text));
        $exclaimed = [];

        foreach ($phrases as $i => $phrase) {
            if (empty($phrase)) {
                $exclaimed[] = $phrase;
                continue;
            }

            // Check if this phrase contains emotional words
            $hasEmotional = false;
            foreach (self::EMOTIONAL_WORDS as $ew) {
                if (stripos($phrase, $ew) !== false) {
                    $hasEmotional = true;
                    break;
                }
            }

            if ($hasEmotional && mt_rand(1, 100) <= $exclChance) {
                // Number of ! based on arousal tier
                $excl = str_repeat('!', min($aTier, 3));
                if ($pTier >= 3) {
                    $excl .= '!'; // Extra ! for max passion
                }

                // Strip existing trailing punctuation from phrase before adding !
                $phrase = rtrim($phrase, ' !.');
                $phrase .= $excl;
            }

            $exclaimed[] = $phrase;
        }

        mt_srand(); // Reset RNG
        return implode(', ', $exclaimed);
    }

    /**
     * Dampen text for the calm/hollow state (arousal < 10 AND passion < 10).
     *
     * Lowercases everything, wraps phrases in ellipsis, occasionally inserts
     * hedging words like "maybe" or "hard to tell".
     *
     * @param string $text  Input keyword string
     * @return string  Dampened text
     */
    public static function dampenText($text)
    {
        // Seed for determinism
        $seed = crc32($text . ':dampen');
        mt_srand($seed);

        $text = strtolower($text);
        $phrases = array_map('trim', explode(',', $text));
        $dampened = [];

        $hedges = ['maybe', 'hard to tell', 'barely there'];
        $hedgeInserted = false;

        foreach ($phrases as $i => $phrase) {
            if (empty($phrase)) continue;

            // Wrap in ellipsis
            $phrase = '...' . $phrase . '...';

            $dampened[] = $phrase;

            // Insert a hedge word once or twice (not every phrase)
            if (!$hedgeInserted && mt_rand(1, 100) <= 35) {
                $dampened[] = $hedges[mt_rand(0, count($hedges) - 1)];
                $hedgeInserted = true;
            }
        }

        mt_srand(); // Reset RNG
        return implode(' ', $dampened);
    }

    // ========== END TEXT INTENSITY ENGINE ==========


    // ========== PHYSICAL STATE BRIDGES (PR 8) ==========
    //
    // Read real signals from CHIM core data and apply temporary dimension
    // modifiers.  These are session-scoped: they apply while the physical
    // condition is active and are reversed when it clears. No MinAI reads.
    // ====================================================

    /**
     * Mapping of physical state names to dimension deltas.
     *
     * Each key is a detected state; each value is an array of
     * dimensionId => raw delta to apply through applyDelta().
     *
     * "trust_healer" and "respect_warrior" are NOT real dimensions --
     * detectPhysicalStates() remaps them to 'trust' / 'respect' only
     * when the NPC temperament matches (Nurturing->trust_healer,
     * Bold/Defiant->respect_warrior).
     */
    const PHYSICAL_STATE_MODIFIERS = [
        'cold'        => ['comfort' => -10, 'arousal' => +20, 'valence' => -15],
        'warm_fire'   => ['comfort' => +10, 'warmth' => +5, 'passion' => +5],
        'injured'     => ['arousal' => +30, 'valence' => -20, 'maturity' => -5, 'trust_healer' => +3],
        'well_rested' => ['maturity' => +2, 'comfort' => +5],
        'exhausted'   => ['maturity' => -5, 'comfort' => -8],
        'raining'     => ['comfort' => -3, 'warmth' => -2],
        'snowing'     => ['comfort' => -8, 'warmth' => -5, 'arousal' => +10, 'valence' => -10],
        'clear_night' => ['comfort' => +3, 'passion' => +3],
        'dirty'       => ['comfort' => -3, 'respect' => -2],
        'bloody'      => ['arousal' => +5, 'respect_warrior' => +1],
    ];

    /**
     * Currently active physical states from CHIM core data.
     *
     * Returns a flat array of state name strings (keys from PHYSICAL_STATE_MODIFIERS).
     * Weather states come from the core place (RelDynFacets::currentPlaceContext: the weather
     * the plugin reports and whether the player is inside) and apply only outside:
     *   rain -> raining; snow -> snowing + cold; night with known clear/pleasant weather ->
     *   clear_night.
     * CHIM 3.4.1 core reports no health, stamina, dirt or blood, so injured / exhausted /
     * well_rested / dirty / bloody (April: MinAI vitals, Dirt and Blood) are unknown and
     * never detected; warm_fire has no core source either.
     *
     * @param string $npcName    The NPC being spoken to
     * @param string $playerName The player character name (unused: the place is the scene's)
     * @return string[] Active state names, e.g. ['snowing', 'cold']
     */
    public static function detectPhysicalStates($npcName, $playerName)
    {
        $states = [];
        $place = RelDynFacets::currentPlaceContext((string) $npcName);
        if (empty($place['known']) || $place['is_interior'] !== false) {
            return $states;   // indoors (or unknown): the weather outside does not reach the NPC
        }

        $weather = (array) $place['weather'];
        if (in_array('rain', $weather, true)) {
            $states[] = 'raining';
        }
        if (in_array('snow', $weather, true)) {
            $states[] = 'snowing';
            $states[] = 'cold';
        }
        $clearSky = !empty($weather) && empty(array_intersect($weather, ['rain', 'snow', 'cloudy', 'fog']));
        if ($clearSky && $place['time_of_day'] === 'night') {
            $states[] = 'clear_night';
        }

        return $states;
    }

    /**
     * Apply dimension modifiers for currently active physical states.
     *
     * For each active state, looks up PHYSICAL_STATE_MODIFIERS and applies
     * the delta through applyDelta().  Tracks what was applied in
     * $dynamics['_applied_physical_deltas'] so clearPhysicalStateModifiers()
     * can reverse them when the condition clears.
     *
     * Skips states already present in $dynamics['_active_physical_states']
     * to prevent double-application within the same session.
     *
     * "trust_healer" / "respect_warrior" pseudo-dimensions are remapped to
     * real dimensions based on NPC temperament.
     *
     * Gated behind dimension_engine_enabled config toggle.
     *
     * @param array       &$dynamics     NPC dynamics blob (modified in place)
     * @param string[]    $activeStates  Output of detectPhysicalStates()
     * @param string|null $temperament   NPC temperament name
     */
    public static function applyPhysicalStateModifiers(&$dynamics, $activeStates, $temperament = null)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return;
        }

        // Initialise tracking arrays if missing
        if (!isset($dynamics['_active_physical_states']) || !is_array($dynamics['_active_physical_states'])) {
            $dynamics['_active_physical_states'] = [];
        }
        if (!isset($dynamics['_applied_physical_deltas']) || !is_array($dynamics['_applied_physical_deltas'])) {
            $dynamics['_applied_physical_deltas'] = [];
        }

        // Temperament-gated pseudo-dimension remapping
        $healerTemperaments  = ['Nurturing', 'Gentle', 'Anxious'];
        $warriorTemperaments = ['Bold', 'Defiant', 'Proud'];

        foreach ($activeStates as $state) {
            // Skip if this state was already applied this session
            if (in_array($state, $dynamics['_active_physical_states'], true)) {
                continue;
            }

            $modifiers = self::PHYSICAL_STATE_MODIFIERS[$state] ?? null;
            if (!$modifiers) {
                continue;
            }

            $appliedForState = [];

            foreach ($modifiers as $dimId => $delta) {
                // Remap pseudo-dimensions
                if ($dimId === 'trust_healer') {
                    if ($temperament && in_array($temperament, $healerTemperaments, true)) {
                        $dimId = 'trust';
                    } else {
                        continue; // Skip -- temperament does not qualify
                    }
                }
                if ($dimId === 'respect_warrior') {
                    if ($temperament && in_array($temperament, $warriorTemperaments, true)) {
                        $dimId = 'respect';
                    } else {
                        continue;
                    }
                }

                // Verify this is a known dimension before applying
                $def = self::getDimensionDefinition($dimId);
                if (!$def) {
                    continue;
                }

                $actual = self::applyDelta($dimId, $dynamics, floatval($delta), $temperament);
                if (abs($actual) > 0.001) {
                    $appliedForState[$dimId] = $actual;
                    $sign = $actual >= 0 ? '+' : '';
                    error_log("[RelDyn-PHYS] Physical state {$state}: {$dimId} {$sign}" . round($actual, 2));
                }
            }

            if (!empty($appliedForState)) {
                $dynamics['_applied_physical_deltas'][$state] = $appliedForState;
            }
            $dynamics['_active_physical_states'][] = $state;
        }
    }

    /**
     * Clear (reverse) physical state modifiers when conditions are no longer active.
     *
     * Compares previously applied states ($dynamics['_active_physical_states'])
     * against the current active set.  Any state that was applied but is no
     * longer active gets its deltas reversed through applyDelta() with
     * inverted sign.
     *
     * @param array    &$dynamics     NPC dynamics blob (modified in place)
     * @param string[] $activeStates  Currently active states from detectPhysicalStates()
     * @param string|null $temperament NPC temperament name
     */
    public static function clearPhysicalStateModifiers(&$dynamics, $activeStates, $temperament = null)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return;
        }

        $previousStates = $dynamics['_active_physical_states'] ?? [];
        $appliedDeltas  = $dynamics['_applied_physical_deltas'] ?? [];

        if (empty($previousStates)) {
            return;
        }

        // Find states that were active before but are no longer
        $clearedStates = array_diff($previousStates, $activeStates);

        foreach ($clearedStates as $state) {
            if (!isset($appliedDeltas[$state])) {
                continue;
            }

            // Reverse each delta that was applied for this state
            foreach ($appliedDeltas[$state] as $dimId => $appliedDelta) {
                $reverseDelta = -1.0 * floatval($appliedDelta);
                $actual = self::applyDelta($dimId, $dynamics, $reverseDelta, $temperament);
                if (abs($actual) > 0.001) {
                    $sign = $actual >= 0 ? '+' : '';
                    error_log("[RelDyn-PHYS] Cleared state {$state}: {$dimId} {$sign}" . round($actual, 2));
                }
            }

            unset($appliedDeltas[$state]);
        }

        // Update tracking: only keep states that are still active
        $dynamics['_active_physical_states']   = array_values(array_intersect($previousStates, $activeStates));
        $dynamics['_applied_physical_deltas']  = $appliedDeltas;
    }

    // ========== END PHYSICAL STATE BRIDGES ==========

    // ========== ENVIRONMENTAL MODIFIERS (PR 8, rewired on place facets 2026-09-24) ==========
    //
    // What a place and the hour do to anyone's body, whatever the NPC likes: danger keeps
    // them alert, the dark makes them uneasy, dawn and dusk have their moods. Driven by the
    // core place facets (RelDynFacets::placeFacets) and the game clock; the amounts are the
    // April values, in config (environment_facet_effects / environment_time_effects).
    // Whether a place is loved or hated (Ashe at ease in a library, Aela restless) is the
    // appraisal's job (decisions §6), so the April place-type comfort / valence rows
    // (tavern +comfort, dungeon -valence, temple +valence ...) are not applied here.
    // Temporary: re-applied only when the effects change, the previous ones reversed first.

    /**
     * Dimension effects (dimension => raw delta) of an environment:
     *   environment_facet_effects[facet][dim] x facet weight, for each facet of the place
     *   + environment_time_effects[time of day][dim]
     * Deltas under 0.01 are dropped; values are rounded to 0.01.
     */
    public static function environmentEffects(array $facets, ?string $timeOfDay): array
    {
        $cfg = self::getConfig();
        $effects = [];
        foreach ((array) ($cfg['environment_facet_effects'] ?? []) as $facet => $dims) {
            $w = floatval($facets[$facet] ?? 0.0);
            if ($w <= 0.0) continue;
            foreach ((array) $dims as $dim => $delta) {
                $effects[$dim] = ($effects[$dim] ?? 0.0) + $w * floatval($delta);
            }
        }
        if ($timeOfDay !== null) {
            foreach ((array) (($cfg['environment_time_effects'] ?? [])[$timeOfDay] ?? []) as $dim => $delta) {
                $effects[$dim] = ($effects[$dim] ?? 0.0) + floatval($delta);
            }
        }
        $out = [];
        foreach ($effects as $dim => $v) {
            if (abs($v) >= 0.01) $out[$dim] = round($v, 2);
        }
        ksort($out);
        return $out;
    }

    /**
     * Apply environmental modifiers to NPC dynamics (prerequest, dimension engine on).
     *
     * Reads the NPC's core place (RelDynFacets::currentPlaceContext), turns it into facets
     * and effects (environmentEffects). When the effects differ from the ones applied last
     * time, those are reversed and the new ones applied through applyDelta. Tracked in
     * $dynamics['_env_applied_effects'] (dim => applied delta) and
     * $dynamics['_active_environment'] ("place|time of day" + effect signature).
     *
     * @return array Applied effects, or [] when nothing changed
     */
    public static function applyEnvironmentalModifiers(&$dynamics, $npcName, $playerName, $temperament)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled']) || empty($config['environment_modifiers_enabled'])) {
            return [];
        }

        $place = RelDynFacets::currentPlaceContext((string) $npcName);
        $effects = empty($place['known']) ? [] : self::environmentEffects(RelDynFacets::placeFacets($place), $place['time_of_day']);
        $envKey = ($place['name'] !== '' ? $place['name'] : ($place['known'] ? 'wilderness' : 'unknown'))
            . '|' . ($place['time_of_day'] ?? 'unknown') . '|' . json_encode($effects);
        if ($envKey === ($dynamics['_active_environment'] ?? '')) {
            return [];
        }

        // Reverse previous environmental effects exactly: take back what was applied (not a
        // new experience through applyDelta's physics, which would leave a residue each time).
        if (!empty($dynamics['_env_applied_effects']) && is_array($dynamics['_env_applied_effects'])) {
            foreach ($dynamics['_env_applied_effects'] as $dim => $val) {
                $def = self::getDimensionDefinition($dim);
                if (abs($val) > 0.0001 && $def && isset($dynamics['dimensions'][$dim])) {
                    $x = floatval($dynamics['dimensions'][$dim]['x'] ?? 0) - floatval($val);
                    $dynamics['dimensions'][$dim]['x'] = max((float) $def['range_min'], min((float) $def['range_max'], $x));
                }
            }
        }

        $appliedEffects = [];
        foreach ($effects as $dim => $val) {
            if (!isset($dynamics['dimensions'][$dim])) continue;
            $actual = self::applyDelta($dim, $dynamics, floatval($val), $temperament);
            if (abs($actual) > 0.0001) {
                $appliedEffects[$dim] = $actual;
            }
        }

        $dynamics['_active_environment'] = $envKey;
        $dynamics['_env_applied_effects'] = $appliedEffects;

        if (!empty($appliedEffects) && (!empty($config['dimension_debug_logging']) || !empty($config['log_enabled']))) {
            $effectStr = [];
            foreach ($appliedEffects as $dim => $val) {
                $effectStr[] = "{$dim}=" . ($val > 0 ? '+' : '') . round($val, 1);
            }
            self::log("[RelDyn-ENV] {$npcName} @ " . ($place['name'] ?: 'wilderness') . " ({$place['time_of_day']}): " . implode(', ', $effectStr));
        }

        return $appliedEffects;
    }

    // ========== END ENVIRONMENTAL MODIFIERS ==========


    // ========== ITEM DIMENSION MODIFIER METHODS (PR 8) ==========
    //
    // classifyItem        — keyword matching against CONSUMABLE_EFFECTS / EQUIPPED_EFFECTS
    // processConsumable   — apply immediate spike + permanent cost + expiry tracking
    // processEquipChange  — apply/reverse equipped baseline modifiers
    // processGift         — love language + interest matching gift delta
    // tickConsumableExpiry — reverse expired consumable spikes (called from prerequest)
    // detectItemEvents    — parse eventlog/gameRequest for item actions (called from postrequest)
    // processItemEvents   — orchestrator for all item events
    // ================================================================

    /**
     * Classify an item name against CONSUMABLE_EFFECTS and EQUIPPED_EFFECTS.
     *
     * Returns ['type' => 'consumable'|'equipped', 'key' => entry_key, 'entry' => entry_array]
     * or null if no match.
     *
     * Specific entries are checked first (skooma, sleeping_tree_sap, ale, healing_potion, meal),
     * then generics (generic_potion, generic_food, generic_drink) as fallback.
     * Within EQUIPPED_EFFECTS, all entries are checked in order.
     *
     * @param string      $itemName  Item display name
     * @param string|null $itemId    Optional form ID (unused currently, reserved for future DB lookup)
     * @return array|null  Classification result or null
     */
    public static function classifyItem($itemName, $itemId = null)
    {
        if (empty($itemName)) {
            return null;
        }

        $lower = strtolower(trim($itemName));

        // --- Consumables: specific entries first, generics last ---
        $specificKeys = ['skooma', 'sleeping_tree_sap', 'ale', 'healing_potion', 'meal'];
        $genericKeys  = ['generic_potion', 'generic_food', 'generic_drink'];

        foreach ($specificKeys as $key) {
            $entry = self::CONSUMABLE_EFFECTS[$key] ?? null;
            if (!$entry) continue;
            foreach ($entry['keywords'] as $kw) {
                if (strpos($lower, strtolower($kw)) !== false) {
                    return ['type' => 'consumable', 'key' => $key, 'entry' => $entry];
                }
            }
        }

        // --- Equipped items ---
        foreach (self::EQUIPPED_EFFECTS as $key => $entry) {
            foreach ($entry['keywords'] as $kw) {
                if (strpos($lower, strtolower($kw)) !== false) {
                    return ['type' => 'equipped', 'key' => $key, 'entry' => $entry];
                }
            }
        }

        // --- Generic consumable fallbacks ---
        foreach ($genericKeys as $key) {
            $entry = self::CONSUMABLE_EFFECTS[$key] ?? null;
            if (!$entry) continue;
            foreach ($entry['keywords'] as $kw) {
                if (strpos($lower, strtolower($kw)) !== false) {
                    return ['type' => 'consumable', 'key' => $key, 'entry' => $entry];
                }
            }
        }

        // --- Last resort: regex word-boundary checks ---
        if (preg_match('/\b(potion)\b/i', $itemName)) {
            return ['type' => 'consumable', 'key' => 'generic_potion', 'entry' => self::CONSUMABLE_EFFECTS['generic_potion']];
        }
        if (preg_match('/\b(ale|mead|wine)\b/i', $itemName)) {
            return ['type' => 'consumable', 'key' => 'ale', 'entry' => self::CONSUMABLE_EFFECTS['ale']];
        }
        if (preg_match('/\b(food|bread|cheese)\b/i', $itemName)) {
            return ['type' => 'consumable', 'key' => 'generic_food', 'entry' => self::CONSUMABLE_EFFECTS['generic_food']];
        }

        return null;
    }

    /**
     * Process a consumable item being consumed by the NPC.
     *
     * 1. Classify the item
     * 2. Apply immediate effects through applyDelta (temporary — tracked for expiry)
     * 3. Store in _active_consumables with gamets-based expiry timestamp
     * 4. Apply permanent costs directly to dimension baselines (never reversed)
     *
     * Gated behind dimension_engine_enabled config toggle.
     *
     * @param array       &$dynamics    NPC dynamics blob (modified in place)
     * @param string      $itemName     Consumed item display name
     * @param string|null $temperament  NPC temperament name
     * @return array  Map of dimension => actual_delta applied, empty if unrecognised
     */
    public static function processConsumable(&$dynamics, $itemName, $temperament = null)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }

        $classified = self::classifyItem($itemName);
        if (!$classified || $classified['type'] !== 'consumable') {
            return [];
        }

        $key   = $classified['key'];
        $entry = $classified['entry'];
        $results = [];

        // --- Apply immediate effects through XYZ engine ---
        $appliedImmediate = [];
        foreach (($entry['immediate'] ?? []) as $dimId => $delta) {
            $actual = self::applyDelta($dimId, $dynamics, floatval($delta), $temperament);
            if (abs($actual) > 0.001) {
                $appliedImmediate[$dimId] = $actual;
                $results[$dimId] = $actual;
            }
        }

        // --- Track for expiry (game-time based) ---
        $currentGameTs = 0;
        if (function_exists('DataLastKnownGameTS')) {
            $currentGameTs = intval(DataLastKnownGameTS());
        }
        $durationGamets = intval(($entry['duration_game_hours'] ?? 0.5) * self::GAMETS_PER_HOUR);
        $expiresAt = $currentGameTs + $durationGamets;

        if (!isset($dynamics['_active_consumables']) || !is_array($dynamics['_active_consumables'])) {
            $dynamics['_active_consumables'] = [];
        }

        $dynamics['_active_consumables'][] = [
            'key'       => $key,
            'item_name' => $itemName,
            'immediate' => $appliedImmediate,
            'expires_gamets' => $expiresAt,
            'applied_at' => time(),
        ];

        // --- Apply permanent costs directly to baselines (never reversed) ---
        foreach (($entry['permanent'] ?? []) as $dimId => $cost) {
            if (abs($cost) < 0.0001) continue;

            // Permanent costs modify the baseline, not the current value
            if (!isset($dynamics['dimensions'][$dimId])) {
                $dynamics['dimensions'][$dimId] = [];
            }
            $currentBaseline = floatval($dynamics['dimensions'][$dimId]['baseline'] ?? 50.0);
            $dynamics['dimensions'][$dimId]['baseline'] = $currentBaseline + floatval($cost);

            $sign = $cost >= 0 ? '+' : '';
            error_log("[RelDyn-ITEM] Permanent cost: {$dimId}_baseline {$sign}{$cost} (now " . round($dynamics['dimensions'][$dimId]['baseline'], 1) . ")");
        }

        // --- Log ---
        $effectStr = [];
        foreach ($results as $dim => $val) {
            $sign = $val >= 0 ? '+' : '';
            $effectStr[] = "{$dim} {$sign}" . round($val, 1);
        }
        $permStr = [];
        foreach (($entry['permanent'] ?? []) as $dim => $val) {
            if (abs($val) < 0.0001) continue;
            $sign = $val >= 0 ? '+' : '';
            $permStr[] = "{$dim}_baseline {$sign}{$val}";
        }
        error_log("[RelDyn-ITEM] Consumed {$key} ({$itemName}): " . implode(', ', $effectStr)
            . (!empty($permStr) ? " | permanent: " . implode(', ', $permStr) : '')
            . " | expires in " . round($entry['duration_game_hours'] ?? 0.5, 1) . "h game time");

        return $results;
    }

    /**
     * Process an equip or unequip event.
     *
     * On equip:   apply while_equipped baseline modifiers, track in _equipped_modifiers.
     * On unequip: reverse baseline modifiers, apply on_removal spike effects.
     *
     * Gated behind dimension_engine_enabled config toggle.
     *
     * @param array       &$dynamics    NPC dynamics blob (modified in place)
     * @param string      $itemName     Equipped/unequipped item name
     * @param bool        $equipped     true = equip event, false = unequip event
     * @param string|null $temperament  NPC temperament name
     * @return array  Map of dimension => actual_delta applied
     */
    public static function processEquipChange(&$dynamics, $itemName, $equipped, $temperament = null)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }

        $classified = self::classifyItem($itemName);
        if (!$classified || $classified['type'] !== 'equipped') {
            return [];
        }

        $key   = $classified['key'];
        $entry = $classified['entry'];
        $results = [];

        if (!isset($dynamics['_equipped_modifiers']) || !is_array($dynamics['_equipped_modifiers'])) {
            $dynamics['_equipped_modifiers'] = [];
        }

        if ($equipped) {
            // --- EQUIP: apply baseline modifiers ---
            $applied = [];
            foreach (($entry['while_equipped'] ?? []) as $dimId => $delta) {
                if (!isset($dynamics['dimensions'][$dimId])) {
                    $dynamics['dimensions'][$dimId] = [];
                }
                $currentBaseline = floatval($dynamics['dimensions'][$dimId]['baseline'] ?? 50.0);
                $dynamics['dimensions'][$dimId]['baseline'] = $currentBaseline + floatval($delta);
                $applied[$dimId] = floatval($delta);

                $sign = $delta >= 0 ? '+' : '';
                $results[$dimId] = floatval($delta);
                error_log("[RelDyn-ITEM] Equip {$key}: {$dimId}_baseline {$sign}{$delta}");
            }

            $dynamics['_equipped_modifiers'][$key] = [
                'item_name' => $itemName,
                'applied'   => $applied,
                'equipped_at' => time(),
            ];
        } else {
            // --- UNEQUIP: reverse baseline modifiers ---
            $tracked = $dynamics['_equipped_modifiers'][$key] ?? null;
            if ($tracked) {
                foreach (($tracked['applied'] ?? []) as $dimId => $appliedDelta) {
                    if (!isset($dynamics['dimensions'][$dimId])) {
                        $dynamics['dimensions'][$dimId] = [];
                    }
                    $currentBaseline = floatval($dynamics['dimensions'][$dimId]['baseline'] ?? 50.0);
                    $dynamics['dimensions'][$dimId]['baseline'] = $currentBaseline - floatval($appliedDelta);

                    $sign = -$appliedDelta >= 0 ? '+' : '';
                    error_log("[RelDyn-ITEM] Unequip {$key}: {$dimId}_baseline {$sign}" . (-$appliedDelta));
                }
                unset($dynamics['_equipped_modifiers'][$key]);
            }

            // --- Apply on_removal spike effects ---
            foreach (($entry['on_removal'] ?? []) as $dimId => $delta) {
                if (abs($delta) < 0.0001) continue;
                $actual = self::applyDelta($dimId, $dynamics, floatval($delta), $temperament);
                if (abs($actual) > 0.001) {
                    $results[$dimId] = $actual;
                    $sign = $actual >= 0 ? '+' : '';
                    error_log("[RelDyn-ITEM] Removal shock {$key}: {$dimId} {$sign}" . round($actual, 2));
                }
            }
        }

        return $results;
    }

    /**
     * Process a gift being given to the NPC.
     *
     * Gift formula: gift_delta = base_value * love_language_match * interest_match * context_mult
     *
     * Base value is 5 (one gift = ~5 affinity/comfort delta before multipliers).
     * Love language match (gifts primary) = 2.0x.
     * Interest match = the NPC's appraisal of the item's facets, 0.5x (hates it) .. 2.0x
     * (loves it) (MDD 1.2; RelDynFacetClassifier::giftAppraisal).
     * Item with no facets at all = 0.5x (feels transactional).
     * Gift during active resentment = 0.3x.
     *
     * Gated behind dimension_engine_enabled config toggle.
     *
     * @param array       &$dynamics    NPC dynamics blob (modified in place)
     * @param string      $itemName     Gifted item name
     * @param string      $giverName    Who gave the gift (usually the player)
     * @param string|null $temperament  NPC temperament name
     * @param string|null $npcName      NPC receiving the gift (for logging)
     * @return array  Map of dimension => actual_delta applied
     */
    public static function processGift(&$dynamics, $itemName, $giverName, $temperament = null, $npcName = null)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }

        $baseValue = 5.0;
        $results = [];

        // --- Love language match ---
        $llMult = 1.0;
        $primaryLL = $dynamics['love_language_primary'] ?? null;
        if ($primaryLL === self::LL_GIFTS) {
            $llMult = 2.0;
        }

        // --- Interest match (decisions §6): the item's facets appraised by this NPC's signed
        // preferences, mapped into MDD 1.2's 0.5x..2.0x; an item with no facets at all stays
        // thing_appraisal.gift_unclassified_mult (a generic gift feels transactional) ---
        $gift = RelDynFacetClassifier::giftAppraisal($dynamics, (string) ($npcName ?? ''), (string) $itemName);
        $interestMult = $gift['mult'];
        $itemInterest = $gift['appraisal']['dominant'] ?? null;
        $dynamics['_last_gift_felt'] = $gift['felt'];

        // --- Context multiplier ---
        $contextMult = 1.0;

        // Resentment check: gift during active resentment = 0.3x
        $resentmentX = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);
        if ($resentmentX > 20) {
            $contextMult = 0.3;
        }

        // --- Calculate final delta ---
        $giftDelta = $baseValue * $llMult * $interestMult * $contextMult;

        // --- Apply to dimensions ---
        // Primary: affinity gets the full delta
        $actual = self::applyDelta('affinity', $dynamics, $giftDelta, $temperament);
        if (abs($actual) > 0.001) {
            $results['affinity'] = $actual;
        }

        // Secondary: comfort gets half the delta (gifts make people comfortable)
        $comfortDelta = $giftDelta * 0.5;
        $actual = self::applyDelta('comfort', $dynamics, $comfortDelta, $temperament);
        if (abs($actual) > 0.001) {
            $results['comfort'] = $actual;
        }

        // Tertiary: if love language match, passion spike
        if ($llMult > 1.0) {
            $passionDelta = $giftDelta * 0.3;
            $actual = self::applyDelta('passion', $dynamics, $passionDelta, $temperament);
            if (abs($actual) > 0.001) {
                $results['passion'] = $actual;
            }
        }

        // --- Log ---
        $effectStr = [];
        foreach ($results as $dim => $val) {
            $sign = $val >= 0 ? '+' : '';
            $effectStr[] = "{$dim} {$sign}" . round($val, 1);
        }
        error_log("[RelDyn-ITEM] Gift to {$npcName} from {$giverName}: {$itemName} | base={$baseValue} LL={$llMult}x interest={$interestMult}x context={$contextMult}x => delta=" . round($giftDelta, 1)
            . " | " . implode(', ', $effectStr)
            . ($itemInterest ? " (interest={$itemInterest})" : ' (no interest match)'));

        return $results;
    }

    /**
     * Tick consumable expiry: reverse immediate effects of expired consumables.
     *
     * Called from prerequest.php every interaction. Checks _active_consumables
     * against current game timestamp and reverses any that have expired.
     *
     * @param array &$dynamics  NPC dynamics blob (modified in place)
     * @return int  Number of consumables expired this tick
     */
    public static function tickConsumableExpiry(&$dynamics)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return 0;
        }

        $activeConsumables = $dynamics['_active_consumables'] ?? [];
        if (empty($activeConsumables)) {
            return 0;
        }

        $currentGameTs = 0;
        if (function_exists('DataLastKnownGameTS')) {
            $currentGameTs = intval(DataLastKnownGameTS());
        }

        // Can't check expiry without game time
        if ($currentGameTs <= 0) {
            return 0;
        }

        $temperament = $dynamics['inferred_temperament'] ?? null;
        $remaining = [];
        $expiredCount = 0;

        foreach ($activeConsumables as $consumable) {
            $expiresAt = intval($consumable['expires_gamets'] ?? 0);

            if ($currentGameTs >= $expiresAt) {
                // --- EXPIRED: reverse immediate effects ---
                foreach (($consumable['immediate'] ?? []) as $dimId => $appliedDelta) {
                    $reverseDelta = -1.0 * floatval($appliedDelta);
                    $actual = self::applyDelta($dimId, $dynamics, $reverseDelta, $temperament);
                    if (abs($actual) > 0.001) {
                        $sign = $actual >= 0 ? '+' : '';
                        error_log("[RelDyn-ITEM] Expired {$consumable['key']}: {$dimId} {$sign}" . round($actual, 2));
                    }
                }
                $expiredCount++;
                error_log("[RelDyn-ITEM] Consumable expired: {$consumable['key']} ({$consumable['item_name']})");
            } else {
                $remaining[] = $consumable;
            }
        }

        $dynamics['_active_consumables'] = $remaining;
        return $expiredCount;
    }

    /**
     * Detect item events from the current interaction context.
     *
     * Parses gameRequest action data and eventlog for:
     *   - Consume: eventlog itemfound with consume keywords
     *   - Gift:    ExtCmdGiveItem action pattern, or "gave X to NPC" eventlog
     *
     * Returns an array of detected events, each:
     *   ['action' => 'consume'|'gift', 'item' => name, ...]
     *
     * @param array  $gameRequest  The current CHIM game request array
     * @param string $npcName      NPC being spoken to
     * @param string $playerName   Player character name
     * @return array  List of detected item events
     */
    public static function detectItemEvents($gameRequest, $npcName, $playerName)
    {
        $events = [];
        $action = $gameRequest[3] ?? '';

        // --- Gift detection: ExtCmdGiveItem pattern ---
        if (preg_match('/ExtCmd(?:Give|Trade)Item@([^:\r\n]+)/i', $action, $m)) {
            $events[] = [
                'action' => 'gift',
                'item'   => trim($m[1]),
                'giver'  => $playerName,
            ];
        }

        // --- Gift detection: eventlog "gave X to NPC" ---
        // "Recent" = last 30 s of play on the eventlog game clock (gamets), not the wall clock.
        $db = $GLOBALS['db'] ?? null;
        $nowGamets = self::currentGamets();
        $sinceGamets = intval($nowGamets - self::ITEM_EVENT_WINDOW_GAMETS);
        if ($db && $nowGamets > 0) {
            try {
                // NPC name matched literally: its % and _ are escaped, not LIKE wildcards
                $escapedNpc = $db->escape(self::escapeLike($npcName));
                $rows = $db->fetchAll(
                    "SELECT data FROM eventlog WHERE type='itemfound' "
                    . "AND data LIKE '%gave%to%{$escapedNpc}%' ESCAPE '\\' "
                    . "AND gamets > {$sinceGamets} "
                    . "ORDER BY gamets DESC, ts DESC LIMIT 3"
                );
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (preg_match('/\bgave\s+(?:\d+\s+)?(.+?)\s+to\s+/i', $row['data'] ?? '', $gm)) {
                            $giftItem = trim($gm[1]);
                            // Avoid duplicating if already detected from ExtCmdGiveItem
                            $alreadyDetected = false;
                            foreach ($events as $ev) {
                                if ($ev['action'] === 'gift' && stripos($giftItem, $ev['item']) !== false) {
                                    $alreadyDetected = true;
                                    break;
                                }
                            }
                            if (!$alreadyDetected) {
                                $events[] = [
                                    'action' => 'gift',
                                    'item'   => $giftItem,
                                    'giver'  => $playerName,
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                self::logError('detectItemEvents gift lookup', $e);
            }
        }

        // (Drunk / on-skooma states were MinAI flags; CHIM 3.4.1 core has none, so only
        // the eventlog consume lines below count.)

        // --- Consumable detection: eventlog consume patterns ---
        if ($db && $nowGamets > 0) {
            try {
                // Whole words only (PostgreSQL \m \M word boundaries): 'private chest' is not 'ate'.
                $rows = $db->fetchAll(
                    "SELECT data FROM eventlog WHERE type='itemfound' "
                    . "AND (data ~* '\\m(consumed|drank|ate)\\M' OR data ~* '\\mused\\M.*potion') "
                    . "AND gamets > {$sinceGamets} "
                    . "ORDER BY gamets DESC, ts DESC LIMIT 3"
                );
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $data = $row['data'] ?? '';
                        if (preg_match('/\b(?:consumed|drank|ate|used)\s+(?:\d+\s+)?(.+?)(?:\s*$|\s*,)/i', $data, $cm)) {
                            $events[] = [
                                'action' => 'consume',
                                'item'   => trim($cm[1]),
                                'source' => 'eventlog',
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                self::logError('detectItemEvents consume lookup', $e);
            }
        }

        return $events;
    }

    /**
     * Process all detected item events for this interaction.
     *
     * Orchestrator that calls processConsumable, processEquipChange, and
     * processGift based on detected events.
     *
     * @param array       &$dynamics    NPC dynamics blob
     * @param array       $gameRequest  Current game request
     * @param string      $npcName      NPC name
     * @param string      $playerName   Player name
     * @param string|null $temperament  NPC temperament
     * @return array  All applied deltas: ['consumable' => [...], 'gift' => [...], 'equip' => [...]]
     */
    public static function processItemEvents(&$dynamics, $gameRequest, $npcName, $playerName, $temperament = null)
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }

        $events = self::detectItemEvents($gameRequest, $npcName, $playerName);
        if (empty($events)) {
            return [];
        }

        $allResults = ['consumable' => [], 'gift' => [], 'equip' => []];

        foreach ($events as $event) {
            switch ($event['action']) {
                case 'consume':
                    $results = self::processConsumable($dynamics, $event['item'], $temperament);
                    if (!empty($results)) {
                        $allResults['consumable'][] = ['item' => $event['item'], 'deltas' => $results];
                    }
                    break;

                case 'gift':
                    $results = self::processGift(
                        $dynamics,
                        $event['item'],
                        $event['giver'] ?? $playerName,
                        $temperament,
                        $npcName
                    );
                    if (!empty($results)) {
                        $allResults['gift'][] = ['item' => $event['item'], 'deltas' => $results];
                    }
                    break;

                case 'equip':
                    $results = self::processEquipChange($dynamics, $event['item'], true, $temperament);
                    if (!empty($results)) {
                        $allResults['equip'][] = ['item' => $event['item'], 'action' => 'equip', 'deltas' => $results];
                    }
                    break;

                case 'unequip':
                    $results = self::processEquipChange($dynamics, $event['item'], false, $temperament);
                    if (!empty($results)) {
                        $allResults['equip'][] = ['item' => $event['item'], 'action' => 'unequip', 'deltas' => $results];
                    }
                    break;
            }
        }

        return $allResults;
    }

    // ========== END ITEM DIMENSION MODIFIER METHODS ==========


    // ========== DIMENSIONAL MEMORY (PR 9) ==========
    //
    // Stored eval reasons tied to specific dimensional changes. Each memory
    // records what happened, which dimension moved, and by how much. The
    // rolling window keeps the 10 most significant (highest |delta|) per
    // dimension per bond, ensuring confrontation fuel and diary content
    // always reference the events that mattered most.
    // =================================================

    /**
     * Store a dimensional memory entry after an eval delta is applied.
     *
     * Appends to $dynamics['dimensional_memory'] and enforces a rolling
     * window of MAX_DIM_MEMORIES_PER_DIM (10) per dimension per bond,
     * sorted by abs_delta descending. When over limit the smallest/oldest
     * entry is pruned.
     *
     * @param array  &$dynamics   NPC dynamics blob (by reference)
     * @param string $dimensionId Dimension ID (e.g. 'trust', 'comfort')
     * @param float  $delta       The actual delta applied (signed)
     * @param string $reason      Human-readable reason string from the eval
     * @param string|null $bondName    Bond target name (default: from GLOBALS)
     */
    public static function storeDimensionalMemory(&$dynamics, $dimensionId, $delta, $reason, $bondName = null)
    {
        if ($bondName === null) {
            $bondName = trim($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player');
        }
        if (empty($reason) || !is_string($reason) || abs($delta) < 0.0001) {
            return;
        }

        if (!isset($dynamics['dimensional_memory']) || !is_array($dynamics['dimensional_memory'])) {
            $dynamics['dimensional_memory'] = [];
        }

        // Cap reason length to prevent blob bloat
        $reason = trim($reason);
        if (strlen($reason) > 200) {
            $reason = substr($reason, 0, 197) . '...';
        }

        $entry = [
            'dim'       => $dimensionId,
            'delta'     => round($delta, 2),
            'reason'    => $reason,
            'bond'      => $bondName,
            'ts'        => date(DATE_ATOM),
            'abs_delta' => round(abs($delta), 2),
        ];

        $dynamics['dimensional_memory'][] = $entry;

        // Enforce rolling window: max 10 per dimension per bond
        $maxPerDim = 10;
        $key = $dimensionId . '|' . $bondName;
        $grouped = [];
        $other = [];

        foreach ($dynamics['dimensional_memory'] as $mem) {
            $memKey = ($mem['dim'] ?? '') . '|' . ($mem['bond'] ?? '');
            if ($memKey === $key) {
                $grouped[] = $mem;
            } else {
                $other[] = $mem;
            }
        }

        if (count($grouped) > $maxPerDim) {
            // Sort by abs_delta descending; ties broken by timestamp descending (newest first)
            usort($grouped, function ($a, $b) {
                $cmp = ($b['abs_delta'] ?? 0) <=> ($a['abs_delta'] ?? 0);
                if ($cmp !== 0) return $cmp;
                return strcmp($b['ts'] ?? '', $a['ts'] ?? '');
            });
            $grouped = array_slice($grouped, 0, $maxPerDim);
        }

        $dynamics['dimensional_memory'] = array_merge($other, $grouped);
    }

    /**
     * Retrieve dimensional memories, optionally filtered by dimension and/or bond.
     *
     * Returns memories sorted by abs_delta descending (most significant first).
     *
     * @param array       $dynamics    NPC dynamics blob
     * @param string|null $dimensionId Filter to this dimension (null = all)
     * @param string|null $bondName    Filter to this bond (null = all)
     * @param int         $limit       Max results to return
     * @return array  Array of memory entries
     */
    public static function getDimensionalMemories($dynamics, $dimensionId = null, $bondName = null, $limit = 10)
    {
        $memories = $dynamics['dimensional_memory'] ?? [];
        if (!is_array($memories) || empty($memories)) {
            return [];
        }

        // Filter
        if ($dimensionId !== null) {
            $memories = array_filter($memories, function ($m) use ($dimensionId) {
                return ($m['dim'] ?? '') === $dimensionId;
            });
        }
        if ($bondName !== null) {
            $memories = array_filter($memories, function ($m) use ($bondName) {
                return ($m['bond'] ?? '') === $bondName;
            });
        }

        // Sort by abs_delta descending
        usort($memories, function ($a, $b) {
            $cmp = ($b['abs_delta'] ?? 0) <=> ($a['abs_delta'] ?? 0);
            if ($cmp !== 0) return $cmp;
            return strcmp($b['ts'] ?? '', $a['ts'] ?? '');
        });

        return array_slice($memories, 0, $limit);
    }

    /**
     * Get confrontation fuel -- top negative-delta memories for grievance dimensions.
     *
     * Filters to trust, comfort, respect, and affinity (the dimensions that
     * accumulate grievances), then returns the top 5 most significant negative
     * memories formatted as confrontation points the NPC can reference.
     *
     * @param array  $dynamics NPC dynamics blob
     * @param string $bondName Bond target name
     * @return array  Array of confrontation-ready reason strings
     */
    public static function getConfrontationFuel($dynamics, $bondName = null)
    {
        if ($bondName === null) {
            $bondName = trim($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player');
        }
        $memories = $dynamics['dimensional_memory'] ?? [];
        if (!is_array($memories) || empty($memories)) {
            return [];
        }

        $grievanceDims = ['trust', 'comfort', 'respect', 'affinity'];

        // Filter to negative deltas in grievance-relevant dimensions for this bond
        $candidates = array_filter($memories, function ($m) use ($grievanceDims, $bondName) {
            return ($m['bond'] ?? '') === $bondName
                && ($m['delta'] ?? 0) < 0
                && in_array($m['dim'] ?? '', $grievanceDims, true);
        });

        if (empty($candidates)) {
            return [];
        }

        // Sort by abs_delta descending (biggest grievances first)
        usort($candidates, function ($a, $b) {
            return ($b['abs_delta'] ?? 0) <=> ($a['abs_delta'] ?? 0);
        });

        // Take top 5 and extract formatted reason strings
        $candidates = array_slice($candidates, 0, 5);
        $fuel = [];
        foreach ($candidates as $mem) {
            $fuel[] = $mem['reason'] ?? '';
        }

        return array_values(array_filter($fuel, function ($r) { return $r !== ''; }));
    }

    /**
     * Build a <dimensional_memory> context block for LLM injection.
     *
     * Includes the most significant recent memories formatted as natural-
     * language statements. Gated behind context tier >= 2 -- strangers and
     * acquaintances don't get memory context.
     *
     * @param array  $dynamics NPC dynamics blob
     * @param string $npcName  NPC display name
     * @param string $bondName Bond target name
     * @param int    $limit    Max memories to include
     * @return string|null  The context block, or null if nothing to inject
     */
    public static function buildMemoryContext($dynamics, $npcName, $bondName, $limit = 5)
    {
        // Gate: tier >= 2 required (don't share memories with strangers)
        $contextTier = self::getContextTier($dynamics);
        if ($contextTier < 2) {
            return null;
        }

        $memories = self::getDimensionalMemories($dynamics, null, $bondName, $limit);
        if (empty($memories)) {
            return null;
        }

        $lines = [];
        $now = time();

        foreach ($memories as $mem) {
            $dimId = $mem['dim'] ?? 'unknown';
            $delta = $mem['delta'] ?? 0;
            $reason = $mem['reason'] ?? '';
            $ts = $mem['ts'] ?? '';

            // Human-readable time ago, in words (felt steering: no numbers reach the LLM)
            $timeAgo = 'recently';
            if (!empty($ts)) {
                $memTime = strtotime($ts);
                if ($memTime !== false && $memTime > 0) {
                    $diffSec = $now - $memTime;
                    if ($diffSec < 3600) {
                        $timeAgo = 'moments ago';
                    } elseif ($diffSec < 86400) {
                        $timeAgo = 'earlier today';
                    } elseif ($diffSec < 7 * 86400) {
                        $timeAgo = 'a few days ago';
                    } else {
                        $timeAgo = 'a while ago';
                    }
                }
            }

            // Dimension label
            $label = self::DIMENSION_LABELS[$dimId] ?? ucfirst(str_replace('_', ' ', $dimId));

            // Direction word
            if ($delta > 0) {
                $direction = 'rose';
            } elseif ($delta < 0) {
                $direction = 'dropped';
            } else {
                $direction = 'shifted';
            }

            $lines[] = "- {$label} {$direction} because: '{$reason}' ({$timeAgo})";
        }

        if (empty($lines)) {
            return null;
        }

        return "<dimensional_memory>\n"
            . "{$npcName}'s strongest memories about {$bondName}:\n"
            . implode("\n", $lines) . "\n"
            . "</dimensional_memory>";
    }

    // ========== END DIMENSIONAL MEMORY (PR 9) ==========

    // ========== REPUTATION LAYER METHODS (PR 9) ==========

    /**
     * Calculate reputation modifiers for a player-NPC pair from player stats.
     *
     * $stats (all optional; a missing key is unknown and adds nothing):
     *   dragon_kills int, quests_completed int, bounty_gold int (all holds), murders int,
     *   thane_holds string[] (hold names as NPC_HOLD_KEYWORDS values), level int,
     *   player_factions string[] and npc_factions string[] (FACTION_REPUTATION_MAP names),
     *   dragonborn bool.
     * April read these from MinAI actor values; CHIM 3.4.1 core has no such source wired yet
     * (playerReputationStats()), so nothing is invented here.
     *
     * @return array  Per-dimension modifiers, e.g. ['trust' => 5, 'respect' => 12, 'comfort' => 3]
     */
    public static function calculateReputation($playerName, $npcName, $temperament, array $stats = [])
    {
        $modifiers = ['trust' => 0.0, 'respect' => 0.0, 'comfort' => 0.0];

        if (empty($playerName)) {
            return $modifiers;
        }
        $add = function (string $source, float $units) use (&$modifiers) {
            foreach (self::REPUTATION_SOURCES[$source] as $dim => $perUnit) {
                $modifiers[$dim] += $units * $perUnit;
            }
        };

        // 1. Dragon kills: fame from slaying dragons (capped at 10 kills)
        $dragonKills = intval($stats['dragon_kills'] ?? 0);
        if ($dragonKills > 0) $add('dragon_kills', min($dragonKills, 10));

        // 2. Quests completed: general fame from helpfulness (capped at 40)
        $quests = intval($stats['quests_completed'] ?? 0);
        if ($quests > 0) $add('quests_completed', min($quests, 40));

        // 3. Crimes: 1 unit per 100 bounty gold across holds (capped at 20 units)
        $crimeUnits = min(intdiv(abs(intval($stats['bounty_gold'] ?? 0)), 100), 20);
        if ($crimeUnits > 0) $add('crimes_committed', $crimeUnits);

        // 4. Murders: heavy infamy with fear-respect component (capped at 5)
        $murders = intval($stats['murders'] ?? 0);
        if ($murders > 0) $add('murders', min($murders, 5));

        // 5. Thane status: only counts in the NPC's hold
        $thaneHolds = (array) ($stats['thane_holds'] ?? []);
        if (!empty($thaneHolds)) {
            $npcHold = self::detectNpcHold($npcName);
            if ($npcHold !== null) {
                foreach ($thaneHolds as $hold) {
                    if (strcasecmp(trim((string) $hold), $npcHold) === 0) {
                        $add('thane', 1);
                        break;
                    }
                }
            }
        }

        // 6. Faction rank: selective respect based on shared/rival factions
        $modifiers['respect'] += self::calculateFactionReputation(
            (array) ($stats['npc_factions'] ?? []), (array) ($stats['player_factions'] ?? []));

        // 7. Player level: only levels above 10 contribute, capped at level 50
        $level = intval($stats['level'] ?? 0);
        if ($level > 10) $add('player_level', min($level - 10, 40));

        // 8. Dragonborn recognition
        if (!empty($stats['dragonborn'])) {
            $modifiers['respect'] += 10.0;
            $modifiers['trust']   += 3.0;
        }

        // Apply caps
        foreach (self::REPUTATION_CAPS as $dim => $caps) {
            if (isset($modifiers[$dim])) {
                $modifiers[$dim] = max($caps['min'], min($caps['max'], $modifiers[$dim]));
            }
        }

        self::log("Reputation calculated for {$playerName} -> {$npcName}: " . json_encode($modifiers));

        return $modifiers;
    }

    /**
     * Player stats calculateReputation() reads. CHIM 3.4.1 core keeps Skyrim stats in
     * core_player, but no reader is wired yet (roadmap player-stats-pipeline), and April's
     * source was MinAI: unknown, so [] and reputation applies nothing.
     */
    public static function playerReputationStats($playerName): array
    {
        return [];
    }

    /**
     * Get the reputation decay factor based on interaction count.
     *
     * Returns a multiplier from 1.0 (no interactions, full reputation effect)
     * to 0.0 (15+ interactions, reputation irrelevant).
     *
     * @param array $dynamics  The NPC dynamics blob
     * @return float  Decay factor in [0.0, 1.0]
     */
    public static function getReputationDecayFactor($dynamics)
    {
        $interactions = intval($dynamics['interaction_count'] ?? 0);
        // Also count total_positive_interactions as a secondary signal
        $totalPositive = intval($dynamics['total_positive_interactions'] ?? 0);
        // Use whichever is higher (more representative of actual contact)
        $effectiveCount = max($interactions, $totalPositive);

        return max(0.0, 1.0 - ($effectiveCount / self::REPUTATION_DECAY_INTERACTIONS));
    }

    /**
     * Apply reputation modifiers to an NPC's dimension baselines.
     *
     * This method:
     * - Calculates raw reputation values
     * - Applies the interaction-based decay factor
     * - Adjusts dimension X values (trust, respect, comfort) accordingly
     * - Marks the dynamics blob to prevent re-application
     *
     * Only runs once per NPC (first contact or when reputation hasn't been
     * calculated yet). Subsequent calls with existing _reputation_applied
     * will recalculate the effective contribution using the decay factor,
     * so the reputation effect diminishes as personal experience accumulates.
     *
     * @param array       &$dynamics    The NPC dynamics blob (modified in place)
     * @param string      $playerName   Player character name
     * @param string      $npcName      NPC name
     * @param string|null $temperament  NPC temperament
     * @return void
     */
    public static function applyReputationModifiers(&$dynamics, $playerName, $npcName, $temperament)
    {
        // No player stats known (no core source wired yet): nothing to apply
        $stats = self::playerReputationStats($playerName);
        if (empty($stats)) {
            return;
        }

        $decayFactor = self::getReputationDecayFactor($dynamics);

        // If reputation is fully decayed, nothing to do (and remove any residual)
        if ($decayFactor <= 0.0) {
            // Mark as applied with zero contribution
            $dynamics['_reputation_applied'] = true;
            $dynamics['_reputation_raw'] = $dynamics['_reputation_raw'] ?? [];
            $dynamics['_reputation_effective'] = ['trust' => 0, 'respect' => 0, 'comfort' => 0];
            return;
        }

        // Calculate raw reputation (only once -- cache the raw values)
        if (!isset($dynamics['_reputation_raw']) || empty($dynamics['_reputation_raw'])) {
            $rawReputation = self::calculateReputation($playerName, $npcName, $temperament, $stats);
            $dynamics['_reputation_raw'] = $rawReputation;
        } else {
            $rawReputation = $dynamics['_reputation_raw'];
        }

        // Calculate current effective reputation (raw * decay)
        $effective = [];
        foreach ($rawReputation as $dim => $rawVal) {
            $effective[$dim] = round($rawVal * $decayFactor, 2);
        }

        // Determine what was previously applied (to compute delta)
        $previousEffective = $dynamics['_reputation_effective'] ?? ['trust' => 0, 'respect' => 0, 'comfort' => 0];

        // Apply the delta (difference between new effective and old effective)
        $dims = ['trust', 'respect', 'comfort'];
        foreach ($dims as $dim) {
            if (!isset($dynamics['dimensions'][$dim])) continue;

            $newEff = $effective[$dim] ?? 0;
            $oldEff = $previousEffective[$dim] ?? 0;
            $delta = $newEff - $oldEff;

            if (abs($delta) > 0.01) {
                $current = floatval($dynamics['dimensions'][$dim]['x'] ?? 0);
                $def = self::getDimensionDefinition($dim);
                $rangeMin = $def ? (float) $def['range_min'] : 0;
                $rangeMax = $def ? (float) $def['range_max'] : 100;

                $dynamics['dimensions'][$dim]['x'] = max($rangeMin, min($rangeMax, $current + $delta));
            }
        }

        // Store state
        $dynamics['_reputation_applied'] = true;
        $dynamics['_reputation_effective'] = $effective;
        $dynamics['_reputation_decay_factor'] = $decayFactor;
    }

    /**
     * The hold the NPC is in: the hold of its current core place (the player's scene;
     * RelDynFacets::currentPlaceContext), or of the place name, matched against
     * NPC_HOLD_KEYWORDS. null when core has no location or no keyword matches.
     * (April read MinAI's AllFactions / locationkeywords.)
     *
     * @param string $npcName  NPC name
     * @return string|null  Hold name (e.g., 'Whiterun') or null if unknown
     */
    public static function detectNpcHold($npcName)
    {
        $place = RelDynFacets::currentPlaceContext((string) $npcName);
        if (empty($place['known'])) {
            return null;
        }
        foreach ([$place['hold'], $place['name']] as $text) {
            $lower = strtolower((string) $text);
            if ($lower === '') continue;
            foreach (self::NPC_HOLD_KEYWORDS as $keyword => $holdName) {
                if (strpos($lower, $keyword) !== false) {
                    return $holdName;
                }
            }
        }
        return null;
    }

    /**
     * Faction-based reputation bonus/penalty (respect points).
     *
     * For each FACTION_REPUTATION_MAP faction the NPC is in, the player's membership in a
     * respected faction adds faction_rank respect, a disdained one costs half of it.
     * Faction names as in FACTION_REPUTATION_MAP (case-insensitive). Pure: the membership
     * lists come from the caller (April asked MinAI's IsInFaction).
     *
     * @param string[] $npcFactions     factions the NPC belongs to
     * @param string[] $playerFactions  factions the player belongs to
     * @return float  Net respect modifier from faction relationships
     */
    public static function calculateFactionReputation(array $npcFactions, array $playerFactions)
    {
        $in = function (string $faction, array $list): bool {
            foreach ($list as $member) {
                if (strcasecmp(trim((string) $member), $faction) === 0) return true;
            }
            return false;
        };

        $netBonus = 0.0;
        foreach (self::FACTION_REPUTATION_MAP as $npcFaction => $rules) {
            if (!$in($npcFaction, $npcFactions)) {
                continue;
            }

            $matchFound = false;
            foreach ($rules['respects'] as $playerFaction) {
                if ($in($playerFaction, $playerFactions)) {
                    $netBonus += self::REPUTATION_SOURCES['faction_rank']['respect'];
                    $matchFound = true;
                    break; // One match per faction group is enough
                }
            }

            foreach ($rules['disdains'] as $playerFaction) {
                if ($in($playerFaction, $playerFactions)) {
                    $netBonus -= self::REPUTATION_SOURCES['faction_rank']['respect'] * 0.5;
                    break;
                }
            }

            if ($matchFound) break; // One NPC faction match is sufficient
        }

        return $netBonus;
    }

    /**
     * Get a summary of the player's reputation for logging/debugging.
     *
     * @param array $dynamics  The NPC dynamics blob (must have _reputation_raw set)
     * @return string  Human-readable summary
     */
    public static function getReputationSummary($dynamics)
    {
        if (empty($dynamics['_reputation_raw'])) {
            return 'No reputation data calculated.';
        }

        $raw = $dynamics['_reputation_raw'];
        $eff = $dynamics['_reputation_effective'] ?? $raw;
        $decay = $dynamics['_reputation_decay_factor'] ?? 1.0;

        $parts = [];
        foreach ($raw as $dim => $val) {
            $effVal = $eff[$dim] ?? 0;
            $sign = $effVal >= 0 ? '+' : '';
            $parts[] = "{$dim}: {$sign}{$effVal} (raw: {$val})";
        }

        return sprintf("Decay: %.0f%% | %s", $decay * 100, implode(', ', $parts));
    }

    // ========== END REPUTATION LAYER METHODS (PR 9) ==========


    // ========== DIARY SELF-EVAL (PR 9) ==========

    /**
     * Maturity-gated diary prompt templates.
     * Controls the DEPTH of self-reflection an NPC is capable of.
     * Below 20 maturity: no self-awareness, surface events only.
     * Above 40: genuine examination. Above 60: clear self-knowledge.
     */
    const DIARY_DEPTH_TEMPLATES = [
        'shallow' => ['min_maturity' => 0, 'max_maturity' => 20,
            'prompt' => '{npc} writes a brief, shallow diary entry. No self-awareness. Just surface events.'],
        'pattern' => ['min_maturity' => 20, 'max_maturity' => 40,
            'prompt' => '{npc} writes a diary entry showing early pattern recognition. Starting to notice repeated behaviors.'],
        'examination' => ['min_maturity' => 40, 'max_maturity' => 60,
            'prompt' => '{npc} writes a genuinely self-reflective diary entry. Examines motivations and feelings honestly.'],
        'insight' => ['min_maturity' => 60, 'max_maturity' => 100,
            'prompt' => '{npc} writes a deeply insightful diary entry. Clear self-knowledge, understanding of patterns and growth.'],
    ];

    /** Cooldown between diary reflections in accumulated seconds (30 min of actual play). */
    const DIARY_REFLECTION_COOLDOWN = 1800;

    /** Minimum crisis indicators needed to trigger diary reflection. */
    const DIARY_MIN_CRISIS_INDICATORS = 2;

    /** Minimum maturity for meaningful (eval-scorable) self-reflection. */
    const DIARY_MIN_MATURITY = 20;

    /**
     * Determine whether an NPC should generate a diary self-reflection.
     *
     * Checks for dimensional crisis (multiple dimensions in bad bands)
     * and sufficient maturity for self-awareness. Rate-limited to once
     * per 30 accumulated minutes to prevent spam.
     *
     * @param array $dynamics  The NPC's dynamics array
     * @return bool  True if diary reflection should be generated
     */
    public static function shouldGenerateDiaryReflection($dynamics)
    {
        // Rate limit: max once per 30 accumulated minutes of actual play
        $accumulated = intval($dynamics['_accumulated_time'] ?? 0);
        $lastAccumulated = intval($dynamics['_diary_last_accumulated'] ?? 0);
        if (($accumulated - $lastAccumulated) < self::DIARY_REFLECTION_COOLDOWN) {
            return false;
        }

        // Resolve dimension values with null-safe fallback
        $dims = $dynamics['dimensions'] ?? [];
        $resentment     = floatval($dims['resentment']['x'] ?? 0);
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);
        $comfort        = floatval($dims['comfort']['x'] ?? 50);
        $trust          = floatval($dims['trust']['x'] ?? 50);
        $maturity       = floatval($dims['maturity']['x'] ?? 50);

        // Must have minimum maturity for self-awareness
        if ($maturity <= self::DIARY_MIN_MATURITY) {
            return false;
        }

        // Count crisis indicators
        $crisisCount = 0;
        if ($resentment > 30)     $crisisCount++;
        if ($resentmentSelf > 30) $crisisCount++;
        if ($comfort < 20)        $crisisCount++;
        if ($trust < 20)          $crisisCount++;
        if ($maturity < 30)       $crisisCount++;

        return ($crisisCount >= self::DIARY_MIN_CRISIS_INDICATORS);
    }
    /**
     * Build prompt context for diary generation based on dimensional state.
     *
     * Maturity gates the DEPTH of reflection: shallow NPCs get surface
     * events, mature NPCs get genuine self-examination. The prompt context
     * includes current band keywords for crisis dimensions, grievance log
     * entries, and the appropriate depth template.
     *
     * @param array  $dynamics  The NPC's dynamics array
     * @param string $npcName   The NPC's display name
     * @return string  Prompt context for diary generation
     */
    public static function generateDiaryPromptContext($dynamics, $npcName)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $maturity = floatval($dims['maturity']['x'] ?? 50);

        // Select depth template based on maturity
        $depthKey = 'shallow';
        foreach (self::DIARY_DEPTH_TEMPLATES as $key => $template) {
            if ($maturity >= $template['min_maturity'] && $maturity <= $template['max_maturity']) {
                $depthKey = $key;
            }
        }
        $depthTemplate = self::DIARY_DEPTH_TEMPLATES[$depthKey];
        $depthPrompt = str_replace('{npc}', $npcName, $depthTemplate['prompt']);

        // Collect crisis dimension keywords
        $crisisDimensions = [];
        $dimensionsToCheck = [
            'resentment'      => ['crisis_if' => 'above', 'threshold' => 30],
            'resentment_self' => ['crisis_if' => 'above', 'threshold' => 30],
            'comfort'         => ['crisis_if' => 'below', 'threshold' => 20],
            'trust'           => ['crisis_if' => 'below', 'threshold' => 20],
            'maturity'        => ['crisis_if' => 'below', 'threshold' => 30],
        ];

        foreach ($dimensionsToCheck as $dimId => $check) {
            $xVal = floatval($dims[$dimId]['x'] ?? 50);
            $inCrisis = false;
            if ($check['crisis_if'] === 'above' && $xVal > $check['threshold']) {
                $inCrisis = true;
            } elseif ($check['crisis_if'] === 'below' && $xVal < $check['threshold']) {
                $inCrisis = true;
            }

            if ($inCrisis) {
                $band = self::getDimensionBand($dimId, $xVal);
                if ($band) {
                    $crisisDimensions[] = [
                        'dimension' => $dimId,
                        'band_label' => $band['label'],
                        'keywords' => $band['keywords'],
                        'value' => round($xVal),
                    ];
                }
            }
        }

        // Build crisis state summary
        $crisisLines = [];
        foreach ($crisisDimensions as $cd) {
            $label = str_replace('_', ' ', $cd['dimension']);
            $crisisLines[] = "  {$label}: {$cd['band_label']} ({$cd['keywords']})";
        }
        $crisisSummary = !empty($crisisLines)
            ? "Current emotional crisis state:\n" . implode("\n", $crisisLines)
            : "No active crisis dimensions.";

        // Pull grievance log entries (most significant dimensional memories)
        $grievanceContext = '';
        $grievanceLog = $dims['resentment']['grievance_log'] ?? [];
        if (!empty($grievanceLog) && $maturity >= 20) {
            $recentGrievances = array_slice($grievanceLog, -3); // last 3 most relevant
            $grievanceLines = [];
            foreach ($recentGrievances as $g) {
                $grievanceLines[] = "  - " . ($g['text'] ?? 'unresolved grievance');
            }
            $grievanceContext = "\nRecent grievances weighing on {$npcName}:\n" . implode("\n", $grievanceLines);
        }

        // Assemble final prompt context
        $context = "[Diary Self-Reflection for {$npcName}]\n";
        $context .= "{$depthPrompt}\n\n";
        $context .= "{$crisisSummary}\n";
        if ($grievanceContext) {
            $context .= $grievanceContext . "\n";
        }

        // Maturity-specific guidance for LLM
        if ($maturity < 20) {
            $context .= "\n{$npcName} has no self-awareness. The diary should be a flat recounting of events with no insight.";
        } elseif ($maturity < 40) {
            $context .= "\n{$npcName} is beginning to notice patterns but cannot articulate why. Use phrases like 'I keep doing this' or 'why does this always happen'.";
        } elseif ($maturity < 60) {
            $context .= "\n{$npcName} is capable of genuine self-examination. They can connect feelings to causes and express 'I need to change' or 'I understand what I did wrong'.";
        } else {
            $context .= "\n{$npcName} has deep self-knowledge. The diary should show clear understanding of personal patterns, honest acknowledgment of flaws, and genuine growth orientation.";
        }

        return $context;
    }
    /**
     * Capture a snapshot of current X values for all active dimensions.
     *
     * Stored in $dynamics['_diary_snapshots'][] with timestamp.
     * Keeps only the last 5 snapshots (rolling window).
     *
     * @param array &$dynamics  The NPC's dynamics array (modified in place)
     */
    public static function storeDiarySnapshot(&$dynamics)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $snapshot = ['ts' => time(), 'values' => []];

        foreach ($dims as $dimId => $dimData) {
            // Only snapshot active dimensions with a non-null X
            if (!is_array($dimData)) continue;
            if (empty($dimData['active'])) continue;
            if ($dimData['x'] === null) continue;

            $snapshot['values'][$dimId] = floatval($dimData['x']);
        }

        if (!isset($dynamics['_diary_snapshots']) || !is_array($dynamics['_diary_snapshots'])) {
            $dynamics['_diary_snapshots'] = [];
        }

        $dynamics['_diary_snapshots'][] = $snapshot;

        // Keep last 5 only
        if (count($dynamics['_diary_snapshots']) > 5) {
            $dynamics['_diary_snapshots'] = array_slice($dynamics['_diary_snapshots'], -5);
        }
    }

    /**
     * Compare most recent snapshot to current dimensional state.
     *
     * For each dimension present in the most recent snapshot:
     *   delta = current_x - snapshot_x
     *   growth:     delta > 3
     *   stagnation: abs(delta) <= 3
     *   regression: delta < -3
     *
     * @param array $dynamics  The NPC's dynamics array
     * @return array  ['growth' => [dim => delta, ...], 'stagnation' => [dim, ...], 'regression' => [dim => delta, ...]]
     */
    public static function compareTrajectory($dynamics)
    {
        $result = ['growth' => [], 'stagnation' => [], 'regression' => []];

        $snapshots = $dynamics['_diary_snapshots'] ?? [];
        if (empty($snapshots)) {
            return $result;
        }

        // Most recent snapshot
        $latest = end($snapshots);
        $snappedValues = $latest['values'] ?? [];
        $dims = $dynamics['dimensions'] ?? [];

        foreach ($snappedValues as $dimId => $snappedX) {
            if (!isset($dims[$dimId]) || !is_array($dims[$dimId])) continue;
            $currentX = $dims[$dimId]['x'];
            if ($currentX === null) continue;

            $delta = floatval($currentX) - floatval($snappedX);

            if ($delta > 3) {
                $result['growth'][$dimId] = round($delta, 2);
            } elseif ($delta < -3) {
                $result['regression'][$dimId] = round($delta, 2);
            } else {
                $result['stagnation'][] = $dimId;
            }
        }

        return $result;
    }

    /**
     * Score a diary entry as a self-interaction.
     *
     * This is the NPC talking to THEMSELVES. No external interaction needed.
     * The dimensional state produces the diary content, the eval scores it,
     * and maturity can shift from internal processing.
     *
     * Primarily affects:
     * - maturity: +1 to +3 per meaningful reflection (self-reflection = growth)
     * - resentment_self: -1 to -3 for recognizing patterns (recognition = healing)
     *
     * @param array  &$dynamics     The NPC's dynamics array (modified in place)
     * @param string $diaryText     The generated diary text to evaluate
     * @param string $temperament   NPC temperament for applyDelta physics
     * @return array  Applied deltas: ['maturity' => float, 'resentment_self' => float]
     */
    public static function processDiaryEval(&$dynamics, $diaryText, $temperament)
    {
        $config = self::getConfig();
        $mode = $config['diary_reflection_mode'] ?? 'baseline';

        $dims = $dynamics['dimensions'] ?? [];
        $maturity = floatval($dims['maturity']['x'] ?? 50);
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);

        $appliedDeltas = [
            'maturity' => 0.0,
            'resentment_self' => 0.0,
        ];

        // Below minimum maturity: diary is too shallow to score
        if ($maturity < self::DIARY_MIN_MATURITY) {
            error_log("[RelDyn-DIARY] Maturity too low ({$maturity}) for meaningful self-reflection");
            // Still snapshot even if too shallow
            self::storeDiarySnapshot($dynamics);
            return $appliedDeltas;
        }

        $npcName = $dynamics['_npc_name'] ?? 'unknown';

        if ($mode === 'baseline') {
            // ---- BASELINE MODE: math-only, no LLM call ----
            $trajectory = self::compareTrajectory($dynamics);
            $growthCount     = count($trajectory['growth']);
            $stagnationCount = count($trajectory['stagnation']);
            $regressionCount = count($trajectory['regression']);

            // maturity +1 per growth dimension (cap +3)
            if ($growthCount > 0) {
                $maturityGain = min(3.0, floatval($growthCount));
                $actualMaturity = self::applyDelta('maturity', $dynamics, $maturityGain, $temperament);
                $appliedDeltas['maturity'] += $actualMaturity;
            }

            // resentment_self +2 if stagnation count >= 2
            if ($stagnationCount >= 2) {
                $actualRs = self::applyDelta('resentment_self', $dynamics, 2.0, $temperament);
                $appliedDeltas['resentment_self'] += $actualRs;
            }

            // maturity -1 per regression dimension (cap -2)
            if ($regressionCount > 0) {
                $maturityLoss = max(-2.0, -1.0 * floatval($regressionCount));
                $actualLoss = self::applyDelta('maturity', $dynamics, $maturityLoss, $temperament);
                $appliedDeltas['maturity'] += $actualLoss;
            }

            error_log("[RelDyn-DIARY] Baseline diary eval for {$npcName}: " .
                "growth={$growthCount}, stagnation={$stagnationCount}, regression={$regressionCount} | " .
                "maturity " . sprintf('%+.2f', $appliedDeltas['maturity']) .
                ", resentment_self " . sprintf('%+.2f', $appliedDeltas['resentment_self']));

        } else {
            // ---- TRAJECTORY MODE: LLM-scored (existing logic) ----
            $reflectionScore = self::scoreDiaryReflection($diaryText, $maturity);

            // Maturity delta: +1 to +3 based on reflection quality
            $maturityDelta = max(1.0, min(3.0, $reflectionScore));
            $actualMaturity = self::applyDelta('maturity', $dynamics, $maturityDelta, $temperament);
            $appliedDeltas['maturity'] = $actualMaturity;

            // Resentment_self healing: recognizing patterns reduces self-directed resentment
            if ($resentmentSelf > 15 && $reflectionScore >= 1.5) {
                $healingDelta = -1 * min(3.0, $reflectionScore);
                $actualHealing = self::applyDelta('resentment_self', $dynamics, $healingDelta, $temperament);
                $appliedDeltas['resentment_self'] = $actualHealing;
            }

            error_log("[RelDyn-DIARY] Trajectory diary eval for {$npcName}: maturity " .
                sprintf('%+.2f', $actualMaturity) . ", resentment_self " .
                sprintf('%+.2f', $appliedDeltas['resentment_self']) .
                " (reflection score: {$reflectionScore})");
        }

        // Update reflection timestamp (both wall-clock and accumulated)
        $dynamics['_last_diary_reflection'] = time();
        $dynamics['_diary_last_accumulated'] = intval($dynamics['_accumulated_time'] ?? 0);

        // Snapshot after processing (both modes)
        self::storeDiarySnapshot($dynamics);

        return $appliedDeltas;
    }


    /**
     * Score diary text quality based on content signals and maturity.
     *
     * Looks for markers of self-awareness: pattern recognition, emotional
     * vocabulary, cause-effect reasoning, growth language. Higher maturity
     * enables access to deeper markers.
     *
     * @param string $diaryText  The diary entry text
     * @param float  $maturity   Current maturity value
     * @return float  Reflection score (1.0 to 3.0)
     */
    private static function scoreDiaryReflection($diaryText, $maturity)
    {
        $score = 1.0; // Base: minimal reflection
        $text = strtolower($diaryText);
        $wordCount = str_word_count($text);

        // Length bonus: longer entries suggest more engagement (diminishing returns)
        if ($wordCount > 50) $score += 0.2;
        if ($wordCount > 100) $score += 0.2;

        // Pattern recognition markers (maturity 20+)
        $patternMarkers = ['keep doing', 'always', 'again', 'every time', 'pattern', 'repeating',
            'same mistake', 'i notice', 'why do i'];
        foreach ($patternMarkers as $marker) {
            if (strpos($text, $marker) !== false) {
                $score += 0.3;
                break; // Only count once per category
            }
        }

        // Emotional vocabulary markers (maturity 30+)
        if ($maturity >= 30) {
            $emotionMarkers = ['feel', 'afraid', 'ashamed', 'guilty', 'angry at myself',
                'disappointed', 'regret', 'hurt', 'vulnerable', 'lonely'];
            foreach ($emotionMarkers as $marker) {
                if (strpos($text, $marker) !== false) {
                    $score += 0.3;
                    break;
                }
            }
        }

        // Cause-effect reasoning (maturity 40+)
        if ($maturity >= 40) {
            $reasoningMarkers = ['because', 'that\'s why', 'i understand', 'i realize',
                'led to', 'caused', 'result of', 'consequence'];
            foreach ($reasoningMarkers as $marker) {
                if (strpos($text, $marker) !== false) {
                    $score += 0.4;
                    break;
                }
            }
        }

        // Growth language (maturity 50+)
        if ($maturity >= 50) {
            $growthMarkers = ['need to change', 'want to be better', 'i can', 'i will',
                'going to try', 'learn from', 'grow', 'different next time'];
            foreach ($growthMarkers as $marker) {
                if (strpos($text, $marker) !== false) {
                    $score += 0.4;
                    break;
                }
            }
        }

        // Self-knowledge (maturity 60+)
        if ($maturity >= 60) {
            $insightMarkers = ['i understand why i', 'this is who i', 'i accept',
                'i\'ve been', 'my tendency to', 'i own', 'accountable'];
            foreach ($insightMarkers as $marker) {
                if (strpos($text, $marker) !== false) {
                    $score += 0.4;
                    break;
                }
            }
        }

        return min(3.0, $score);
    }
    /**
     * Check whether dimensional pain should generate an intrinsic goal.
     *
     * When self_worth_deficit exceeds threshold AND maturity is above minimum:
     *   self_worth_deficit = (50 - avg_respect) + (50 - maturity) + (resentment_self / 2)
     *
     * If deficit > 60: flag for motivation system. Stores goal in
     * $dynamics['_intrinsic_goals'][] with priority derived from pain level.
     *
     * @param array  &$dynamics  The NPC's dynamics array (modified in place)
     * @param string $npcName    The NPC's display name
     * @return array|null  Goal info if generated, null otherwise
     */
    public static function checkIntrinsicGoalGeneration(&$dynamics, $npcName)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $maturity       = floatval($dims['maturity']['x'] ?? 50);
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);
        $respect        = floatval($dims['respect']['x'] ?? 50);

        // Must have minimum maturity for self-awareness
        if ($maturity <= self::DIARY_MIN_MATURITY) {
            return null;
        }

        // self_worth_deficit = (50 - avg_respect) + (50 - maturity) + (resentment_self / 2)
        // Using per-bond respect directly (avg across bonds would require bond iteration;
        // for now use the current bond's respect as a proxy)
        $selfWorthDeficit = (50 - $respect) + (50 - $maturity) + ($resentmentSelf / 2);

        if ($selfWorthDeficit <= 60) {
            return null;
        }

        // Ensure goals array exists
        if (!isset($dynamics['_intrinsic_goals']) || !is_array($dynamics['_intrinsic_goals'])) {
            $dynamics['_intrinsic_goals'] = [];
        }

        // Don't duplicate if an active goal of the same type already exists
        foreach ($dynamics['_intrinsic_goals'] as $existing) {
            if (($existing['type'] ?? '') === 'self_worth_recovery' && ($existing['active'] ?? false)) {
                return null; // Already has this goal
            }
        }

        $goal = [
            'type'                => 'self_worth_recovery',
            'label'               => 'I need to change.',
            'priority'            => min(1.0, $selfWorthDeficit / 100),
            'created_at'          => time(),
            'active'              => true,
            'deficit_at_creation' => round($selfWorthDeficit, 2),
            'sustain_requirement' => 'maturity must stay above baseline for sustained ticks',
            'failure_mode'        => 'slip below baseline: goal deactivates, resentment_self +5',
            'success_mode'        => 'baseline drifts upward, goal evolves to maintain',
        ];

        $dynamics['_intrinsic_goals'][] = $goal;

        // Cap stored goals to 5
        if (count($dynamics['_intrinsic_goals']) > 5) {
            $dynamics['_intrinsic_goals'] = array_slice($dynamics['_intrinsic_goals'], -5);
        }

        error_log("[RelDyn-DIARY] Intrinsic goal generated for {$npcName}: self_worth_recovery " .
            "(deficit={$selfWorthDeficit}, priority={$goal['priority']})");

        return $goal;
    }

    // ========== END DIARY SELF-EVAL (PR 9) ==========

    // ========== DIRECTOR-ASSIGNED GOALS (PR 39) ==========

    /**
     * Set an active director-assigned goal for this NPC.
     * @param array &$dynamics  The dynamics blob
     * @param string $goalText  What the NPC should try to do
     * @param string $source    'director' | 'bgl' | 'intrinsic'
     * @param int $maxAgeGamets Max age in gamets before expiry (3600=~1h director, 7200=~2h bgl)
     * @param float $priority   0.0-1.0 urgency
     */
    public static function setDirectorGoal(&$dynamics, $goalText, $source = 'director', $maxAgeGamets = 3600, $priority = 0.5) {
        // Expire current goal if one exists
        if (!empty($dynamics['_director_goal']) && !empty($dynamics['_director_goal']['active'])) {
            self::expireDirectorGoal($dynamics);
        }

        $dynamics['_director_goal'] = [
            'text'            => trim($goalText),
            'source'          => $source,
            'created_at'      => time(),
            'created_gamets'  => self::getPlayGamets($dynamics),
            'max_age_gamets'  => $maxAgeGamets,
            'priority'        => max(0.0, min(1.0, floatval($priority))),
            'active'          => true,
        ];

        self::log("[RelDyn-GOAL] Set director goal ({$source}, pri={$priority}): " . substr($goalText, 0, 80));
    }

    /**
     * Get the active director goal if it exists and hasn't expired.
     * Does NOT expire it — call expireDirectorGoal() separately.
     * @param array $dynamics
     * @return array|null The goal array, or null if none/expired/disabled
     */
    public static function getActiveDirectorGoal($dynamics) {
        if (!empty($dynamics['_director_goal_disabled'])) return null;

        $goal = $dynamics['_director_goal'] ?? null;
        if (!$goal || empty($goal['active']) || empty($goal['text'])) return null;

        // Check age
        $currentGamets = self::getPlayGamets($dynamics);
        $goalAge = $currentGamets - floatval($goal['created_gamets'] ?? 0);
        $maxAge = floatval($goal['max_age_gamets'] ?? 3600);

        if ($goalAge > $maxAge) return null; // Expired but not yet cleaned up

        return $goal;
    }

    /**
     * Expire the current director goal, moving it to history.
     * @param array &$dynamics
     */
    public static function expireDirectorGoal(&$dynamics) {
        $goal = $dynamics['_director_goal'] ?? null;
        if (!$goal || empty($goal['text'])) {
            $dynamics['_director_goal'] = null;
            return;
        }

        $goal['active'] = false;
        $goal['ended_at'] = time();
        $goal['ended_gamets'] = self::getPlayGamets($dynamics);
        $goal['outcome'] = 'expired';

        if (!isset($dynamics['_director_goal_history'])) {
            $dynamics['_director_goal_history'] = [];
        }
        array_unshift($dynamics['_director_goal_history'], $goal);
        $dynamics['_director_goal_history'] = array_slice($dynamics['_director_goal_history'], 0, 5);

        $dynamics['_director_goal'] = null;
        self::log("[RelDyn-GOAL] Expired director goal: " . substr($goal['text'], 0, 60));
    }

    /**
     * Mark the current director goal as fulfilled.
     * @param array &$dynamics
     * @param string $reason How it was fulfilled
     */
    public static function fulfillDirectorGoal(&$dynamics, $reason = 'completed') {
        $goal = $dynamics['_director_goal'] ?? null;
        if (!$goal || empty($goal['text'])) {
            $dynamics['_director_goal'] = null;
            return;
        }

        $goal['active'] = false;
        $goal['ended_at'] = time();
        $goal['ended_gamets'] = self::getPlayGamets($dynamics);
        $goal['outcome'] = 'fulfilled';
        $goal['fulfill_reason'] = $reason;

        if (!isset($dynamics['_director_goal_history'])) {
            $dynamics['_director_goal_history'] = [];
        }
        array_unshift($dynamics['_director_goal_history'], $goal);
        $dynamics['_director_goal_history'] = array_slice($dynamics['_director_goal_history'], 0, 5);

        $dynamics['_director_goal'] = null;
        self::log("[RelDyn-GOAL] Fulfilled director goal ({$reason}): " . substr($goal['text'], 0, 60));
    }

    // ========== END DIRECTOR-ASSIGNED GOALS (PR 39) ==========

    // ========== ATTRACTION MATRIX — PLAYER DATA (PR 11) ==========

    /**
     * Get player stats from conf_opts storage.
     * Returns default structure if no stats synced yet.
     */
    public static function getPlayerStats(): array
    {
        $defaults = [
            'level' => 1,
            'gold' => 0,
            'lifetime_wealth' => 0,
            'race' => 'Nord',
            'gender' => 'Male',
            'skills' => [],
            'kill_counts' => ['people' => 0, 'animals' => 0, 'creatures' => 0, 'dragons' => 0, 'undead' => 0],
            'quest_count' => 0,
            'property_count' => 0,
            'thane_holds' => [],
            'faction_ranks' => [],
            'synced_at' => 0,
        ];

        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return $defaults;

            $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id = '_player_stats' LIMIT 1");
            if ($row && !empty($row['value'])) {
                $stats = json_decode($row['value'], true);
                if (is_array($stats)) {
                    return array_merge($defaults, $stats);
                }
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn-MATRIX] getPlayerStats error: " . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Get player appearance text from core_player or the player bio.
     */
    public static function getPlayerAppearance(): string
    {
        try {
            // Try core_player table first
            $db = $GLOBALS['db'] ?? null;
            if ($db) {
                $row = $db->fetchOne("SELECT value FROM core_player WHERE id = 'appearance' LIMIT 1");
                if ($row && !empty($row['value'])) {
                    return $row['value'];
                }
            }
        } catch (\Throwable $e) {
            self::logError('getPlayerAppearance core_player', $e);
        }

        // Fallback to the player bio. 3.4.1 has no PLAYER_BIOS global; core resolves
        // core_player 'bio' first, then legacy PLAYER_BIOS (global / conf_opts).
        if (function_exists('ResolvePlayerBackstory')) {
            return ResolvePlayerBackstory();
        }
        return $GLOBALS['PLAYER_BIOS'] ?? '';
    }

    /**
     * Get or generate player appearance embedding (384-dim vector).
     * Caches in conf_opts to avoid re-embedding on every interaction.
     */
    public static function getPlayerAppearanceEmbedding(): array
    {
        $appearance = self::getPlayerAppearance();
        if (empty(trim($appearance))) {
            return []; // No appearance data available
        }

        $textHash = md5($appearance);

        // Check cache
        try {
            $db = $GLOBALS['db'] ?? null;
            if ($db) {
                $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id = '_player_appearance_embedding' LIMIT 1");
                if ($row && !empty($row['value'])) {
                    $cached = json_decode($row['value'], true);
                    if (is_array($cached) && ($cached['text_hash'] ?? '') === $textHash && !empty($cached['vector'])) {
                        return $cached['vector'];
                    }
                }
            }
        } catch (\Throwable $e) { self::logError('getPlayerAppearanceEmbedding', $e); }

        // Generate new embedding
        if (!function_exists('getEmbedding')) {
            // Try to include the embedding helper
            $embeddingPath = realpath(__DIR__ . '/../../lib/memory_helper_embeddings.php');
            if ($embeddingPath && file_exists($embeddingPath)) {
                @include_once($embeddingPath);
            }
        }

        if (!function_exists('getEmbedding')) {
            error_log("[RelDyn-MATRIX] getEmbedding() not available — cannot compute appearance embedding");
            return [];
        }

        try {
            $vector = getEmbedding($appearance);
            if (!is_array($vector) || empty($vector)) {
                return [];
            }

            // Cache it
            if ($db) {
                $cacheData = json_encode(['text_hash' => $textHash, 'vector' => $vector]);
                $escaped = $db->escape($cacheData);
                $db->execQuery("INSERT INTO conf_opts (id, value) VALUES ('_player_appearance_embedding', '{$escaped}')
                    ON CONFLICT (id) DO UPDATE SET value = '{$escaped}'");
            }

            return $vector;
        } catch (\Throwable $e) {
            error_log("[RelDyn-MATRIX] Embedding generation failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get or generate NPC beauty keywords embedding.
     * Caches in the NPC's attraction_profile.
     */
    public static function getNpcBeautyEmbedding($npcName, &$dynamics): array
    {
        $profile = $dynamics['attraction_profile'] ?? null;
        if (!$profile) {
            $profile = self::getArchetypeProfile($dynamics);
        }

        $keywords = $profile['beauty_keywords'] ?? [];
        if (empty($keywords)) return [];

        // Check cached embedding
        if (!empty($profile['beauty_keywords_embedding'])) {
            return $profile['beauty_keywords_embedding'];
        }

        // Generate from keywords text
        $keywordText = implode(', ', $keywords);

        if (!function_exists('getEmbedding')) {
            $embeddingPath = realpath(__DIR__ . '/../../lib/memory_helper_embeddings.php');
            if ($embeddingPath && file_exists($embeddingPath)) {
                @include_once($embeddingPath);
            }
        }

        if (!function_exists('getEmbedding')) {
            return [];
        }

        try {
            $vector = getEmbedding($keywordText);
            if (is_array($vector) && !empty($vector)) {
                // Cache on the dynamics object (saved with next saveDynamics)
                if (!is_array($dynamics['attraction_profile'])) {
                    $dynamics['attraction_profile'] = $profile;
                }
                $dynamics['attraction_profile']['beauty_keywords_embedding'] = $vector;
                return $vector;
            }
        } catch (\Throwable $e) {
            error_log("[RelDyn-MATRIX] NPC beauty embedding failed for {$npcName}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Cosine similarity clamped to 0.0-1.0 for attraction scoring.
     * Wraps the existing cosineSimilarity() — negative similarity = 0 for attraction purposes.
     */
    public static function clampedCosineSimilarity(array $a, array $b): float
    {
        return max(0.0, min(1.0, self::cosineSimilarity($a, $b)));
    }

    /**
     * Get the archetype profile for an NPC based on their interests/temperament.
     * Falls back to 'Warrior' if no match found.
     */
    public static function getArchetypeProfile($dynamics): array
    {
        // Try to infer archetype from NPC interests (signed facet preferences as MDD 1.2
        // multipliers; only a real liking, above indifferent, names an archetype)
        $interests = array_filter(self::getInterests($dynamics), fn($m) => $m > 1.0);
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';

        // Map dominant interest to archetype
        if (!empty($interests)) {
            arsort($interests);
            $topInterest = array_key_first($interests);

            $interestToArchetype = [
                'combat'     => 'Warrior',
                'adventure'  => 'Warrior',
                'scholarly'  => 'Scholar',
                'enchanting' => 'Scholar',
                'nature'     => 'Primal',
                'social'     => 'Bard',
                'wealth'     => 'Noble',
                'spiritual'  => 'Priest',
                'crafting'   => 'Warrior',
                'alchemy'    => 'Scholar',
                'domestic'   => 'Noble',
            ];

            $archetype = $interestToArchetype[$topInterest] ?? 'Warrior';
        } else {
            // Fallback: temperament-based
            $temperamentToArchetype = [
                'Romantic'    => 'Bard',
                'Anxious'     => 'Noble',
                'Playful'     => 'Bard',
                'Humble'      => 'Priest',
                'Nurturing'   => 'Priest',
                'Gentle'      => 'Scholar',
                'Jealous'     => 'Noble',
                'Stoic'       => 'Warrior',
                'Proud'       => 'Noble',
                'Bold'        => 'Warrior',
                'Independent' => 'Rogue',
                'Defiant'     => 'Primal',
                'Guarded'     => 'Rogue',
            ];
            $archetype = $temperamentToArchetype[$temperament] ?? 'Warrior';
        }

        $profile = self::ATTRACTION_ARCHETYPES[$archetype] ?? self::ATTRACTION_ARCHETYPES['Warrior'];

        // Add default tier thresholds if not present
        if (!isset($profile['tier_thresholds'])) {
            $profile['tier_thresholds'] = self::DEFAULT_TIER_THRESHOLDS;
        }

        return $profile;
    }

    // ========== ATTRACTION MATRIX — SCORING & GATING (PR 11) ==========

    /**
     * Score a single attraction pillar.
     * Returns 0.0-1.0 continuous score.
     */
    public static function scoreAttractionPillar(string $pillarId, array $profile, array $playerData, string $npcName = '', &$dynamics = null): float
    {
        $config = self::getConfig();

        switch ($pillarId) {
            case 'beauty':
                // Cosine similarity: player appearance embedding vs NPC beauty keywords embedding
                $weight = floatval($config['attraction_beauty_weight'] ?? 1.0);
                $playerEmbed = self::getPlayerAppearanceEmbedding();
                if (empty($playerEmbed)) return 0.5 * $weight; // No data = neutral

                $npcEmbed = [];
                if ($dynamics !== null) {
                    $npcEmbed = self::getNpcBeautyEmbedding($npcName, $dynamics);
                }
                if (empty($npcEmbed)) {
                    // Generate from profile keywords
                    $keywords = $profile['beauty_keywords'] ?? [];
                    if (!empty($keywords) && function_exists('getEmbedding')) {
                        try {
                            $npcEmbed = getEmbedding(implode(', ', $keywords));
                        } catch (\Throwable $e) { self::logError('scoreAttractionPillar embedding', $e); }
                    }
                }
                if (empty($npcEmbed)) return 0.5 * $weight;

                return self::clampedCosineSimilarity($playerEmbed, $npcEmbed) * $weight;

            case 'strength':
                $weight = floatval($config['attraction_strength_weight'] ?? 1.0);
                $mode = $profile['strength_mode'] ?? 'flexible_total';
                $threshold = floatval($profile['strength_threshold'] ?? 150);

                if ($mode === 'irrelevant') return 1.0;

                $relevantSkills = $profile['strength_skills'] ?? [];
                $playerSkills = $playerData['skills'] ?? [];

                if (empty($playerSkills)) return 0.0; // No skill data synced

                $playerTotal = 0;
                foreach ($relevantSkills as $skill) {
                    $playerTotal += floatval($playerSkills[$skill] ?? 0);
                }

                if ($mode === 'flexible_total') {
                    // Give partial credit for non-preferred skills
                    $allSkillTotal = array_sum(array_map('floatval', $playerSkills));
                    $playerTotal = max($playerTotal, $allSkillTotal * 0.4);
                }

                return min(1.0, ($playerTotal / max(1, $threshold))) * $weight;

            case 'status':
                $weight = floatval($config['attraction_status_weight'] ?? 1.0);
                $metrics = $profile['status_metrics'] ?? [];
                if (empty($metrics)) return 1.0;

                $totalScore = 0;
                foreach ($metrics as $metric) {
                    $totalScore += self::scoreStatusMetric($metric, $playerData);
                }
                return min(1.0, ($totalScore / count($metrics))) * $weight;

            case 'competence':
                $weight = floatval($config['attraction_competence_weight'] ?? 1.0);
                $metrics = $profile['competence_metrics'] ?? [];
                if (empty($metrics)) return 1.0;

                $totalScore = 0;
                foreach ($metrics as $metric) {
                    $totalScore += self::scoreCompetenceMetric($metric, $playerData);
                }
                return min(1.0, ($totalScore / count($metrics))) * $weight;
        }

        return 0.0;
    }

    /**
     * Score a single status metric against player data.
     */
    private static function scoreStatusMetric(array $metric, array $playerData): float
    {
        $type = $metric['type'] ?? '';
        $min = floatval($metric['min'] ?? 0);
        if ($min < 0.001) return 1.0; // No threshold = auto-pass

        switch ($type) {
            case 'faction_rank':
                $faction = $metric['faction'] ?? '';
                $ranks = $playerData['faction_ranks'] ?? [];
                $rank = floatval($ranks[$faction] ?? 0);
                return min(1.0, $rank / max(1, $min));

            case 'npc_affinity':
                // Check player's affinity with a specific NPC
                $targetNpc = $metric['npc'] ?? '';
                if (empty($targetNpc)) return 0.0;
                try {
                    $db = $GLOBALS['db'] ?? null;
                    if ($db) {
                        $escaped = $db->escape($targetNpc);
                        $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
                        if ($row && !empty($row['extended_data'])) {
                            $ext = json_decode($row['extended_data'], true) ?: [];
                            $aff = floatval(self::getPlayerRelationshipFromExtended($ext)['aff'] ?? 0);
                            $normalizedAff = ($aff + 100) / 2.0; // Scale -100..+100 to 0..100
                            return min(1.0, $normalizedAff / max(1, $min));
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("[RelDyn] npc_affinity metric read failed for {$targetNpc}: " . $e->getMessage());
                }
                return 0.0;

            case 'lifetime_wealth':
                $wealth = floatval($playerData['lifetime_wealth'] ?? 0);
                return min(1.0, $wealth / max(1, $min));

            case 'thane_count':
                $thanes = $playerData['thane_holds'] ?? [];
                $count = is_array($thanes) ? count($thanes) : 0;
                return min(1.0, $count / max(1, $min));

            case 'property_count':
                $props = intval($playerData['property_count'] ?? 0);
                return min(1.0, $props / max(1, $min));
        }

        return 0.0;
    }

    /**
     * Score a single competence metric against player data.
     */
    private static function scoreCompetenceMetric(array $metric, array $playerData): float
    {
        $type = $metric['type'] ?? '';
        $min = floatval($metric['min'] ?? 0);
        if ($min < 0.001) return 1.0;

        switch ($type) {
            case 'kill_category':
                $category = $metric['category'] ?? '';
                $kills = $playerData['kill_counts'] ?? [];
                $count = floatval($kills[$category] ?? 0);
                return min(1.0, $count / max(1, $min));

            case 'quest_count':
                $count = intval($playerData['quest_count'] ?? 0);
                return min(1.0, $count / max(1, $min));

            case 'quest_line':
                $quest = $metric['quest'] ?? '';
                $minStage = floatval($metric['min_stage'] ?? $min);
                // Check quest progress — for now use faction_ranks as proxy
                $factionRanks = $playerData['faction_ranks'] ?? [];
                $rank = floatval($factionRanks[$quest] ?? 0);
                return min(1.0, $rank / max(1, $minStage));

            case 'kill_total':
                $kills = $playerData['kill_counts'] ?? [];
                $total = array_sum(array_map('floatval', $kills));
                return min(1.0, $total / max(1, $min));
        }

        return 0.0;
    }

    /**
     * Check gender preference compatibility.
     */
    public static function checkGenderPreference(array $profile, array $playerData): bool
    {
        $pref = $profile['gender_pref'] ?? 'bisexual';
        $fluidity = floatval($profile['gender_fluidity'] ?? 0.5);
        $playerGender = strtolower($playerData['gender'] ?? 'male');

        if ($pref === 'bisexual') return true;
        if ($fluidity >= 0.9) return true; // Fully fluid

        // Determine if gender matches preference
        $npcExpectsOpposite = ($pref === 'heterosexual');
        // We don't know NPC gender from this data — infer from profile keywords
        // For now, assume NPC gender context is handled elsewhere and just check fluidity
        // If fluidity > random threshold, allow it regardless
        if ($fluidity > 0.5) return true;

        // Strict check would need NPC gender — skip for now, return true
        // Gender gating will be refined when NPC profiles have explicit gender fields
        return true;
    }

    /**
     * Calculate the maximum relationship tier available based on pillar scores.
     */
    public static function calculateTierCeiling(array $profile, array $pillarScores): string
    {
        $thresholds = $profile['tier_thresholds'] ?? self::DEFAULT_TIER_THRESHOLDS;

        // Walk from highest to lowest tier
        $tierOrder = ['sworn', 'bonded', 'close', 'friend', 'crush'];

        foreach ($tierOrder as $tier) {
            if (!isset($thresholds[$tier])) continue;
            $requirements = $thresholds[$tier];
            $passes = true;
            foreach ($requirements as $pillar => $minScore) {
                if (($pillarScores[$pillar] ?? 0) < $minScore) {
                    $passes = false;
                    break;
                }
            }
            if ($passes) return $tier;
        }

        return 'stranger';
    }

    /**
     * Calculate passion modifier based on pillar scores and intimacy gate type.
     */
    public static function calculatePassionModifier(array $profile, array $pillarScores, bool $visceralPass): float
    {
        $gate = $profile['intimacy_gate'] ?? 'balanced';
        $beautyScore = $pillarScores['beauty'] ?? 0;

        switch ($gate) {
            case 'visceral':
                return $visceralPass ? max(0.3, $beautyScore) : 0.1;

            case 'bond':
                // Passion barely builds without commitment — beauty gives small bonus
                return $beautyScore * 0.3; // Max 0.3 until bonded tier is reached

            case 'balanced':
                return $visceralPass ? max(0.5, $beautyScore) : ($beautyScore * 0.5);
        }

        return 0.5;
    }

    /**
     * Calculate effective tolerance from maturity x attachment style.
     * Returns 0.0-1.0 where higher = more tolerant/flexible.
     */
    public static function calculateEffectiveTolerance($dynamics): float
    {
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $attachStyle = self::getAttachmentStyle($dynamics);

        $baseTolerance = $maturity / 100.0;

        switch ($attachStyle) {
            case 'secure':
                $tolerance = $baseTolerance * 1.2; // High maturity = more tolerant
                break;
            case 'avoidant':
                $tolerance = 1.0 - ($baseTolerance * 0.8); // High maturity = less tolerant (refined standards)
                break;
            case 'anxious':
                $tolerance = ($maturity > 50) ? (1.0 - $baseTolerance * 0.5) : 0.9; // Low mat = desperate "tolerance"
                break;
            case 'toxic':
                $tolerance = 0.3; // Pattern-locked
                break;
            default:
                $tolerance = 0.5;
        }

        return min(1.0, max(0.0, $tolerance));
    }

    /**
     * Main Attraction Matrix calculation.
     * Scores all 4 pillars, determines tier ceiling, friendzone, passion modifier.
     * Caches result on dynamics for reuse within the same request cycle.
     */
    public static function calculateAttractionMatrix(string $npcName, array &$dynamics, array $playerData = null): array
    {
        // Config gate
        $config = self::getConfig();
        if (empty($config['attraction_matrix_enabled'])) {
            return [
                'pillar_scores' => ['beauty' => 1.0, 'strength' => 1.0, 'status' => 1.0, 'competence' => 1.0],
                'pillar_pass' => ['beauty' => true, 'strength' => true, 'status' => true, 'competence' => true],
                'visceral_pass' => true,
                'sociological_pass' => true,
                'gender_pass' => true,
                'max_tier' => 'sworn',
                'friendzoned' => false,
                'passion_mult' => 1.0,
                'respect_mult' => 1.0,
                'effective_tolerance' => 0.5,
                'intimacy_gate' => 'balanced',
                'enabled' => false,
            ];
        }

        // Load player data if not provided
        if ($playerData === null) {
            $playerData = self::getPlayerStats();
        }

        // Get NPC attraction profile
        $profile = $dynamics['attraction_profile'] ?? null;
        if (!$profile || !is_array($profile)) {
            $profile = self::getArchetypeProfile($dynamics);
        }

        // Score each pillar
        $pillarScores = [];
        foreach (['beauty', 'strength', 'status', 'competence'] as $pillar) {
            $pillarScores[$pillar] = self::scoreAttractionPillar($pillar, $profile, $playerData, $npcName, $dynamics);
        }

        // Apply rigidity rules
        $rigidity = $profile['pillar_rigidity'] ?? [];
        $passThreshold = self::ATTRACTION_PASS_THRESHOLD;

        // Adjust threshold by effective tolerance
        $tolerance = self::calculateEffectiveTolerance($dynamics);
        $adjustedThreshold = $passThreshold * (1.0 - ($tolerance * 0.2)); // High tolerance = slightly lower bar

        $pillarPass = [];
        foreach ($pillarScores as $pillar => $score) {
            $rig = $rigidity[$pillar] ?? 'soft';
            switch ($rig) {
                case 'rigid':
                    $pillarPass[$pillar] = ($score >= $adjustedThreshold);
                    break;
                case 'flexible':
                    $pillarPass[$pillar] = ($score >= $adjustedThreshold * 0.6);
                    break;
                case 'soft':
                    $pillarPass[$pillar] = true; // Always passes, score still affects modifier
                    break;
                case 'irrelevant':
                    $pillarPass[$pillar] = true;
                    $pillarScores[$pillar] = 1.0;
                    break;
                default:
                    $pillarPass[$pillar] = ($score >= $adjustedThreshold);
            }
        }

        // Axis results
        $visceralPass = $pillarPass['beauty'] && $pillarPass['strength'];
        $sociologicalPass = $pillarPass['status'] && $pillarPass['competence'];

        // Gender check
        $genderPass = self::checkGenderPreference($profile, $playerData);

        // Tier ceiling
        $maxTier = self::calculateTierCeiling($profile, $pillarScores);

        // Friendzone: sociological pass + visceral fail + gender ok
        $friendzoned = ($sociologicalPass && !$visceralPass && $genderPass);

        // Passion modifier
        $passionMult = self::calculatePassionModifier($profile, $pillarScores, $visceralPass);

        // Respect modifier
        $respectMult = (($pillarScores['competence'] ?? 0) + ($pillarScores['status'] ?? 0)) / 2.0;

        // Friendzone caps passion
        if ($friendzoned) {
            $passionMult = min($passionMult, 0.2);
        }

        $result = [
            'pillar_scores'       => $pillarScores,
            'pillar_pass'         => $pillarPass,
            'visceral_pass'       => $visceralPass,
            'sociological_pass'   => $sociologicalPass,
            'gender_pass'         => $genderPass,
            'max_tier'            => $maxTier,
            'friendzoned'         => $friendzoned,
            'passion_mult'        => $passionMult,
            'respect_mult'        => $respectMult,
            'effective_tolerance' => $tolerance,
            'intimacy_gate'       => $profile['intimacy_gate'] ?? 'balanced',
            'enabled'             => true,
        ];

        // Cache on dynamics
        $dynamics['_attraction_matrix_cache'] = $result;
        $dynamics['_attraction_tier_ceiling'] = $maxTier;
        $dynamics['_attraction_friendzoned'] = $friendzoned;
        $dynamics['_attraction_passion_mult'] = $passionMult;
        $dynamics['_attraction_matrix_last_eval'] = intval($dynamics['interaction_count'] ?? 0);

        self::log("[MATRIX] {$npcName}: beauty=" . round($pillarScores['beauty'], 2) . " str=" . round($pillarScores['strength'], 2) . " status=" . round($pillarScores['status'], 2) . " comp=" . round($pillarScores['competence'], 2) . " tier={$maxTier} fz=" . ($friendzoned ? '1' : '0') . " passion_mult=" . round($passionMult, 2));

        return $result;
    }

    /**
     * Generate attraction context text for LLM injection.
     */
    public static function generateAttractionContext(string $npcName, array $matrixResult, array $dynamics): string
    {
        $scores = $matrixResult['pillar_scores'] ?? [];
        $friendzoned = $matrixResult['friendzoned'] ?? false;
        $intimacyGate = $matrixResult['intimacy_gate'] ?? 'balanced';
        $maxTier = $matrixResult['max_tier'] ?? 'stranger';
        $visceralPass = $matrixResult['visceral_pass'] ?? false;

        $lines = [];

        // Physical attraction
        $beautyScore = $scores['beauty'] ?? 0;
        if ($beautyScore > 0.7) {
            $lines[] = "{$npcName} finds the player's appearance strongly compelling -- exactly their type.";
        } elseif ($beautyScore > 0.4) {
            $lines[] = "{$npcName} finds the player reasonably attractive, though not overwhelmingly so.";
        } elseif ($beautyScore > 0.2) {
            $lines[] = "{$npcName} is not particularly drawn to the player's appearance.";
        } else {
            $lines[] = "{$npcName} feels no physical attraction to the player.";
        }

        // Competence/status
        $compScore = (($scores['competence'] ?? 0) + ($scores['status'] ?? 0)) / 2;
        if ($compScore > 0.7) {
            $lines[] = "They see the player as accomplished and worthy of deep respect.";
        } elseif ($compScore > 0.4) {
            $lines[] = "The player has some standing but hasn't fully proven themselves yet.";
        } else {
            $lines[] = "The player hasn't earned significant standing in {$npcName}'s eyes.";
        }

        // Friendzone
        if ($friendzoned) {
            $lines[] = "{$npcName} values the player as a trusted companion but romantic attraction is absent. This is a deep friendship, not a romance. Any flirtation will be deflected with warmth, not cruelty.";
        }

        // Tier ceiling
        $tierLabels = [
            'stranger' => 'cautious distance',
            'crush' => 'casual interest',
            'friend' => 'genuine friendship',
            'close' => 'close partnership',
            'bonded' => 'deep commitment',
            'sworn' => 'soul-level bond',
        ];
        $ceilingLabel = $tierLabels[$maxTier] ?? 'neutral distance';
        if ($maxTier !== 'sworn') {
            $lines[] = "Maximum relationship depth currently available: {$ceilingLabel}. Deeper tiers require proving more to {$npcName}.";
        }

        // Intimacy gate
        if ($intimacyGate === 'visceral' && $visceralPass) {
            $lines[] = "{$npcName} is physically open to the player regardless of commitment status.";
        } elseif ($intimacyGate === 'bond') {
            $lines[] = "{$npcName} requires genuine emotional commitment before physical intimacy.";
        }

        return implode(' ', $lines);
    }

    // ========== END ATTRACTION MATRIX — SCORING & GATING (PR 11) ==========

    // ========== CASCADING AFFINITY NETWORK (PR 12) ==========

    /**
     * Propagate a significant affinity change to bonded NPCs.
     * One hop only — no recursive cascading.
     * Uses social sensitivity curves to modulate impact per receiving NPC.
     *
     * @param string $sourceNpc   NPC whose affinity changed
     * @param float  $affinityDelta The delta that triggered the cascade
     * @param string $playerName  Player name (excluded from cascade targets)
     * @return array  Results per affected NPC
     */
    public static function propagateAffinityChange(string $sourceNpc, float $affinityDelta, string $playerName): array
    {
        $config = self::getConfig();
        if (empty($config['cascade_network_enabled'])) return [];

        $threshold = floatval($config['cascade_threshold'] ?? self::CASCADE_THRESHOLD);
        if (abs($affinityDelta) < $threshold) return [];

        $decay = floatval($config['cascade_decay'] ?? self::CASCADE_DECAY);
        $sourceBonds = self::getAllBondsForNpc($sourceNpc);
        $results = [];
        $count = 0;

        foreach ($sourceBonds as $targetName => $bond) {
            if ($count >= self::CASCADE_MAX_TARGETS) break;
            if (strcasecmp($targetName, $playerName) === 0 || self::isPlayerRelationshipKey($targetName)) continue;

            $bondAff = ($bond['aff'] + 100) / 200.0; // Normalize to 0-1
            if ($bondAff < 0.2) continue; // Weak bonds don't propagate

            // Load target NPC dynamics
            $targetDynamics = self::getDynamics($targetName);
            if (empty($targetDynamics) || !is_array($targetDynamics)) continue;

            $targetTemperament = $targetDynamics['inferred_temperament'] ?? $targetDynamics['temperament'] ?? 'Stoic';

            // Calculate base cascade delta
            $cascadeDelta = $affinityDelta * $bondAff * $decay;

            // Check if target dislikes source — inverse cascade (enemy of my enemy)
            $targetBonds = self::getAllBondsForNpc($targetName);
            $targetToSource = $targetBonds[$sourceNpc] ?? null;
            if ($targetToSource) {
                $targetSourceAff = ($targetToSource['aff'] + 100) / 200.0;
                if ($targetSourceAff < 0.3) {
                    $cascadeDelta *= -0.5; // Weaker inverse
                }
            }

            // Apply social sensitivity curve
            if (method_exists(self::class, 'applySocialSensitivity')) {
                $cascadeDelta = self::applySocialSensitivity($targetDynamics, 'affinity', $cascadeDelta, $targetTemperament);
            }

            if (abs($cascadeDelta) < 1.0) continue; // Too small to matter

            // Apply to target's affinity toward player
            self::applyDelta('affinity', $targetDynamics, $cascadeDelta, $targetTemperament);
            self::saveDynamics($targetName, $targetDynamics);

            $results[] = [
                'target' => $targetName,
                'cascade_delta' => round($cascadeDelta, 2),
                'bond_strength' => round($bondAff, 2),
            ];

            $count++;
            self::log("[CASCADE] {$sourceNpc} -> {$targetName}: delta=" . round($cascadeDelta, 2) . " (bond=" . round($bondAff, 2) . ")");
        }

        return $results;
    }

    // ========== END CASCADING AFFINITY NETWORK (PR 12) ==========

    // ========== DUTY OVERRIDE (PR 12) ==========

    /**
     * Check if current interaction is quest-protected.
     * Returns dampening factor: 1.0 = normal, 0.0 = fully protected, 0.1 = quest-dampened.
     */
    public static function getDutyOverrideFactor(): float
    {
        $config = self::getConfig();
        if (empty($config['duty_override_enabled'])) return 1.0;

        // Check explicit flag from game-side
        try {
            $db = $GLOBALS['db'] ?? null;
            if ($db) {
                $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id = '_duty_override_active' LIMIT 1");
                if ($row && !empty($row['value'])) {
                    $override = json_decode($row['value'], true);
                    if (is_array($override) && !empty($override['active'])) {
                        return floatval($override['dampening'] ?? 0.1);
                    }
                }
            }
        } catch (\Throwable $e) { self::logError('getDutyOverrideFactor', $e); }

        // Check request type for quest indicators
        $gameRequest = $GLOBALS['gameRequest'] ?? [];
        $reqType = is_array($gameRequest) ? ($gameRequest[0] ?? '') : '';
        $reqData = is_array($gameRequest) ? ($gameRequest[3] ?? '') : '';

        $questIndicators = ['quest_dialogue', 'quest_event', 'snqe_', 'forced_dialogue', 'scene_dialogue'];
        foreach ($questIndicators as $indicator) {
            if (stripos($reqType, $indicator) !== false || stripos($reqData, $indicator) !== false) {
                return 0.1;
            }
        }

        return 1.0;
    }

    // ========== END DUTY OVERRIDE (PR 12) ==========

    // ========== PARASITE DETECTION (PR 12) ==========

    /**
     * Check interaction patterns for parasitic behavior (gift-only engagement).
     * Returns 'parasite' if detected, null otherwise.
     */
    public static function checkParasitePattern(string $npcName, array &$dynamics): ?string
    {
        $config = self::getConfig();
        if (empty($config['parasite_detection_enabled'])) return null;

        $pattern = $dynamics['_interaction_pattern'] ?? [];
        $totalWindow = intval($pattern['total_window'] ?? 0);

        if ($totalWindow < 10) return null; // Not enough data

        $giftCount = intval($pattern['gift_count'] ?? 0);
        $genuineCount = intval($pattern['genuine_count'] ?? 0);
        $giftRatio = $giftCount / max(1, $totalWindow);

        // Trigger: >70% gifts AND <3 genuine interactions in the window
        if ($giftRatio > 0.7 && $genuineCount < 3) {
            $currentOverride = $dynamics['_relationship_type_override'] ?? null;
            if ($currentOverride !== 'parasite') {
                // Record history
                $dynamics['_relationship_type_history'][] = [
                    'from' => self::getRelationshipType($npcName, $dynamics),
                    'from_override' => $currentOverride,   // restored on recovery (null = follow core)
                    'to' => 'parasite',
                    'at' => intval($dynamics['interaction_count'] ?? 0),
                    'reason' => 'gift_ratio=' . round($giftRatio, 2),
                ];
                $dynamics['_relationship_type_override'] = 'parasite';
                self::log("[TYPE] Parasite detected for {$npcName}: gift_ratio=" . round($giftRatio, 2));
                return 'parasite';
            }
        }

        return null;
    }

    /**
     * Check if a parasite relationship has recovered (genuine engagement resumed).
     */
    public static function checkParasiteRecovery(string $npcName, array &$dynamics): ?string
    {
        $currentOverride = $dynamics['_relationship_type_override'] ?? null;
        if ($currentOverride !== 'parasite') return null;

        $pattern = $dynamics['_interaction_pattern'] ?? [];
        $genuineCount = intval($pattern['genuine_count'] ?? 0);
        $giftCount = intval($pattern['gift_count'] ?? 0);

        // Recovery: 3+ genuine interactions AND gifts <= genuine
        if ($genuineCount >= 3 && $giftCount <= $genuineCount) {
            $history = $dynamics['_relationship_type_history'] ?? [];
            $lastEntry = !empty($history) ? end($history) : null;
            $previousType = ($lastEntry && isset($lastEntry['from'])) ? $lastEntry['from'] : null;
            // Restore the override that was active before (usually none), not the type the bond
            // had then: an override of a core-derived type would shadow core's type from now on.
            $previousOverride = ($lastEntry && isset($lastEntry['from_override'])) ? $lastEntry['from_override'] : null;

            $dynamics['_relationship_type_override'] = ($previousOverride !== 'parasite') ? $previousOverride : null;
            $dynamics['_relationship_type_history'][] = [
                'from' => 'parasite',
                'to' => $previousType ?? 'friend',
                'at' => intval($dynamics['interaction_count'] ?? 0),
                'reason' => 'genuine_recovery',
            ];
            self::log("[TYPE] Parasite recovery for {$npcName}: returning to " . ($previousType ?? 'default'));
            return $previousType ?? 'friend';
        }

        return null;
    }

    /**
     * Update interaction pattern tracking.
     * Called from postrequest after interaction classification.
     */
    public static function updateInteractionPattern(array &$dynamics, ?string $interactionType, float $affinityDelta): void
    {
        if (!isset($dynamics['_interaction_pattern']) || !is_array($dynamics['_interaction_pattern'])) {
            $dynamics['_interaction_pattern'] = [
                'gift_count' => 0, 'genuine_count' => 0,
                'total_window' => 0, 'window_start' => 0,
                'last_interaction_type' => null,
            ];
        }

        $pattern = &$dynamics['_interaction_pattern'];
        $interactionCount = intval($dynamics['interaction_count'] ?? 0);

        // Reset window every 20 interactions
        if (($interactionCount - intval($pattern['window_start'] ?? 0)) >= 20) {
            $pattern['gift_count'] = 0;
            $pattern['genuine_count'] = 0;
            $pattern['total_window'] = 0;
            $pattern['window_start'] = $interactionCount;
        }

        $pattern['total_window']++;
        $pattern['last_interaction_type'] = $interactionType;

        if ($interactionType === 'gifts') {
            $pattern['gift_count']++;
        } elseif ($interactionType !== null && $affinityDelta > 0) {
            $pattern['genuine_count']++;
        }
    }

    // ========== END PARASITE DETECTION (PR 12) ==========

    // ========== BASELINE DRIFT (PR 13) ==========

    /**
     * Check and apply baseline drift for all driftable dimensions.
     * Called during diary eval (~5 hours play time).
     * Nudges baselines toward sustained reality. Capped at ±20 from temperament default.
     */
    public static function processBaselineDrift(string $npcName, array &$dynamics): array
    {
        $config = self::getConfig();
        if (empty($config['baseline_drift_enabled'])) return [];

        $driftResults = [];
        $samples = &$dynamics['_baseline_drift_samples'];
        if (!is_array($samples)) $samples = [];

        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $dims = &$dynamics['dimensions'];

        $driftable = ['affinity', 'trust', 'comfort', 'respect', 'warmth', 'maturity'];

        foreach ($driftable as $dimId) {
            if (!isset($dims[$dimId])) continue;

            $currentX = floatval($dims[$dimId]['x'] ?? 0);
            $currentBaseline = floatval($dims[$dimId]['baseline'] ?? 0);
            $temperamentBaseline = self::getTemperamentBaseline($temperament, $dimId);

            // Record sample
            if (!isset($samples[$dimId])) $samples[$dimId] = [];
            $samples[$dimId][] = $currentX;
            if (count($samples[$dimId]) > 5) {
                $samples[$dimId] = array_slice($samples[$dimId], -5);
            }

            if (count($samples[$dimId]) < self::BASELINE_DRIFT_MIN_SAMPLES) continue;

            // Check consistency: all samples on same side of baseline (with tolerance)
            $allAbove = true;
            $allBelow = true;
            foreach ($samples[$dimId] as $sample) {
                if ($sample <= $currentBaseline + self::BASELINE_DRIFT_TOLERANCE) $allAbove = false;
                if ($sample >= $currentBaseline - self::BASELINE_DRIFT_TOLERANCE) $allBelow = false;
            }

            if (!$allAbove && !$allBelow) continue; // Mixed — no drift

            // Calculate drift
            $avgSample = array_sum($samples[$dimId]) / count($samples[$dimId]);
            $driftAmount = ($avgSample - $currentBaseline) * self::BASELINE_DRIFT_RATE;

            // Cap: can't drift more than ±MAX from temperament default
            $newBaseline = $currentBaseline + $driftAmount;
            $driftFromDefault = $newBaseline - $temperamentBaseline;
            if (abs($driftFromDefault) > self::BASELINE_DRIFT_MAX) {
                $newBaseline = $temperamentBaseline + (self::BASELINE_DRIFT_MAX * ($driftFromDefault > 0 ? 1 : -1));
                $driftAmount = $newBaseline - $currentBaseline;
            }

            if (abs($driftAmount) < 0.1) continue;

            $dims[$dimId]['baseline'] = round($newBaseline, 2);
            $driftResults[$dimId] = [
                'old_baseline' => $currentBaseline,
                'new_baseline' => round($newBaseline, 2),
                'drift' => round($driftAmount, 2),
            ];

            self::log("[DRIFT] {$npcName} {$dimId}: baseline {$currentBaseline} -> " . round($newBaseline, 2));
        }

        return $driftResults;
    }

    // ========== END BASELINE DRIFT (PR 13) ==========

    // ========== INTERNAL WEATHER ENGINE (PR 13) ==========

    /**
     * Update internal weather (MDD 4.1) from the facet state: pressure fed both ways by
     * appraisals, the daily roll and deprivation of loved facets (RelDynFacets::updateWeather),
     * after catching up on where the NPC has been since her last turn and where she is now
     * (RelDynFacets::catchUpPresence, core eventlog). Returns the weather; 'clear' while
     * internal weather is switched off.
     */
    public static function updateInternalWeather(string $npcName, array &$dynamics, ?float $nowGamets = null): string
    {
        if (!self::configValue('internal_weather_enabled')) return 'clear';
        $now = $nowGamets ?? self::currentGamets();
        $prefs = RelDynFacets::preferences($dynamics, $npcName);
        RelDynFacets::catchUpPresence($npcName, $dynamics, $prefs, $now);
        return RelDynFacets::updateWeather($npcName, $dynamics, $prefs, $now);
    }

    /**
     * Emotional gravity (MDD 4.1, a constant pull): the weather pulls dimensions by config
     * facet_appraisal.weather_modifiers (raw points) x weather_modifier_per_game_hour for every
     * game hour since the last pull (_weather_gravity_gamets; at most
     * exposure_max_gap_game_hours of them). Requests without game time passing pull nothing,
     * the first request only starts the clock.
     */
    public static function applyWeatherModifiers(string $npcName, array &$dynamics, string $temperament, ?float $nowGamets = null): void
    {
        $now = $nowGamets ?? self::currentGamets();
        if ($now <= 0) return;   // game clock unknown: no time can be credited
        $cfg = RelDynFacets::getAppraisalConfig();
        $last = floatval($dynamics['_weather_gravity_gamets'] ?? 0);
        if ($last > 0 && $now <= $last) return;
        $dynamics['_weather_gravity_gamets'] = $now;
        if ($last <= 0) return;
        $hours = min(($now - $last) / (self::GAMETS_PER_DAY / 24.0), floatval($cfg['exposure_max_gap_game_hours']));

        $weather = $dynamics['_internal_weather'] ?? 'clear';
        $modifiers = (array) (((array) ($cfg['weather_modifiers'] ?? []))[$weather] ?? []);
        $scale = floatval($cfg['weather_modifier_per_game_hour']) * $hours;

        foreach ($modifiers as $dimId => $delta) {
            self::applyDelta($dimId, $dynamics, floatval($delta) * $scale, $temperament);
        }
    }

    /**
     * Generate M/F-aware intimacy deprivation context.
     */
    public static function generateIntimacyDeprivationContext(string $npcName, array $dynamics): ?string
    {
        $intimacySat = floatval($dynamics['_interest_satisfaction']['intimacy'] ?? 1.0);
        if ($intimacySat > 0.3) return null;

        $dims = $dynamics['dimensions'] ?? [];
        $coordM = floatval($dims['coord_m']['x'] ?? 50);
        $coordF = floatval($dims['coord_f']['x'] ?? 50);
        $maturity = floatval($dims['maturity']['x'] ?? 50);

        if ($maturity < 30) {
            $key = 'low_maturity';
        } elseif ($coordM > 65 && $coordM > $coordF) {
            $key = 'high_m';
        } elseif ($coordF > 65 && $coordF > $coordM) {
            $key = 'high_f';
        } else {
            $key = 'balanced';
        }

        return str_replace('{NAME}', $npcName, self::INTIMACY_DEPRIVATION_CONTEXT[$key]);
    }

    // ========== VAMPIRE/WEREWOLF MOODIFICATIONS (PR 13) ==========

    /**
     * Get creature dimension modifiers based on time/moon.
     * Returns array of dimId => modifier value, or empty array.
     */
    public static function getCreatureModifiers(string $npcName, array $dynamics): array
    {
        $config = self::getConfig();
        if (empty($config['creature_moodifications_enabled'])) return [];

        $creatureType = self::detectCreatureType($npcName, $dynamics);
        if (!$creatureType) return [];

        $isNight = self::isGameNight();
        $isFullMoon = self::isFullMoon();
        $modifiers = [];

        if ($creatureType === 'vampire') {
            $base = self::VAMPIRE_NIGHT_MODIFIERS;
            if ($isNight) {
                $modifiers = $base;
            } else {
                foreach ($base as $dim => $val) {
                    $modifiers[$dim] = -$val * self::CREATURE_DAY_INVERSION;
                }
            }
        } elseif ($creatureType === 'werewolf') {
            $base = self::WEREWOLF_MOON_MODIFIERS;
            if ($isFullMoon) {
                $modifiers = $base;
            } elseif ($isNight) {
                foreach ($base as $dim => $val) {
                    $modifiers[$dim] = $val * 0.5;
                }
            } else {
                foreach ($base as $dim => $val) {
                    $modifiers[$dim] = -$val * self::CREATURE_DAY_INVERSION;
                }
            }
        }

        return $modifiers;
    }

    /**
     * Apply creature modifiers to dimensions (scaled x0.1 per interaction).
     */
    public static function applyCreatureModifiers(string $npcName, array &$dynamics, string $temperament): void
    {
        $modifiers = self::getCreatureModifiers($npcName, $dynamics);
        foreach ($modifiers as $dimId => $delta) {
            $scaledDelta = $delta * 0.1;
            self::applyDelta($dimId, $dynamics, $scaledDelta, $temperament);
        }
    }

    /**
     * Detect vampire/werewolf from NPC factions or creature_type field.
     */
    public static function detectCreatureType(string $npcName, array $dynamics): ?string
    {
        // Check dynamics field first (manual override or cached)
        $creature = $dynamics['creature_type'] ?? null;
        if ($creature) return $creature;

        // Check factions from DB
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;

            $escaped = $db->escape($npcName);
            $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            if ($row && !empty($row['extended_data'])) {
                $ext = json_decode($row['extended_data'], true) ?: [];
                $factions = $ext['factions'] ?? [];
                foreach ($factions as $faction) {
                    $name = strtolower($faction['name'] ?? '');
                    if (strpos($name, 'vampire') !== false || strpos($name, 'volkihar') !== false) {
                        return 'vampire';
                    }
                    if (strpos($name, 'werewolf') !== false) {
                        return 'werewolf';
                    }
                }
            }
        } catch (\Throwable $e) { self::logError('detectCreatureType', $e); }

        return null;
    }

    /**
     * Check if it's night in-game (8PM-5AM).
     */
    public static function isGameNight(?float $gamets = null): bool
    {
        $gameHour = self::gameHourOfDay($gamets);
        if ($gameHour === null) return false;
        return ($gameHour >= 20 || $gameHour < 5);
    }

    /**
     * Estimate full moon (every 5th game day).
     */
    public static function isFullMoon(?float $gamets = null): bool
    {
        $gamets = $gamets ?? self::currentGamets();
        if ($gamets <= 0) return false;

        $gameDays = $gamets / self::GAMETS_PER_DAY;
        $dayInCycle = fmod($gameDays, 5);
        return ($dayInCycle >= 4 && $dayInCycle < 5);
    }

    // ========== END INTERNAL WEATHER ENGINE (PR 13) ==========

    // ========== EMERGENT EMOTION LABELING (PR 13) ==========

    const EMERGENT_EMOTIONS = [
        'infatuation' => [
            'rules' => ['affinity' => [70, 100], 'passion' => [60, 100], 'trust' => [0, 40]],
            'context' => "{NAME} is infatuated -- consumed by idealized desire without genuine trust. This is not love. It is projection.",
        ],
        'codependency' => [
            'rules' => ['affinity' => [80, 100], 'comfort' => [0, 30], 'self_confidence' => [0, 30]],
            'context' => "{NAME} needs the player in an unhealthy way. This bond is survival, not choice.",
        ],
        'suffocation' => [
            'rules' => ['affinity' => [60, 100], 'comfort' => [80, 100], 'resentment' => [30, 100]],
            'context' => "{NAME} loves the player but feels trapped by the closeness. Too much warmth, not enough air.",
        ],
        'contempt' => [
            'rules' => ['resentment' => [50, 100], 'affinity' => [40, 100], 'respect' => [0, 25]],
            'context' => "{NAME} has lost all respect while still being emotionally attached. This is contempt -- the most corrosive emotion.",
        ],
        'longing' => [
            'rules' => ['affinity' => [60, 100], 'warmth' => [50, 100], 'passion' => [0, 15]],
            'context' => "{NAME} cares deeply but the fire is gone. A bittersweet ache -- wishing things were different.",
        ],
        'protective_fury' => [
            'rules' => ['affinity' => [70, 100], 'arousal' => [60, 100], 'valence' => [-100, -20]],
            'context' => "{NAME} would burn the world down for the player. This is ferocious, primal protectiveness.",
        ],
        'quiet_devotion' => [
            'rules' => ['affinity' => [80, 100], 'maturity' => [70, 100], 'resentment' => [0, 10], 'passion' => [0, 40]],
            'context' => "{NAME} has reached deep, peaceful commitment. No drama, no desperation. They choose this person with full awareness.",
        ],
        'bitter_nostalgia' => [
            'rules' => ['affinity' => [0, 30], 'warmth' => [50, 100], 'resentment' => [40, 100]],
            'context' => "{NAME} remembers what this was. The warmth of those memories clashes with the bitterness of what went wrong.",
        ],
        'grudging_respect' => [
            'rules' => ['respect' => [60, 100], 'affinity' => [0, 20], 'resentment' => [30, 100]],
            'context' => "{NAME} does not like the player. But they cannot deny their competence. Hating someone you have to respect.",
        ],
        'volatile_passion' => [
            'rules' => ['passion' => [70, 100], 'maturity' => [0, 30], 'arousal' => [50, 100]],
            'context' => "{NAME} is burning hot and completely unstable. Could flip to devotion, rage, or despair in one interaction.",
        ],
    ];

    // ========== AUTONOMOUS DIARY + SOCIAL MASKING CONSTANTS (PR 14) ==========
    const DIARY_INTERACTION_GAP = 15;
    const DIARY_CONSUMABLE_BLOCK = true;

    const MASK_DIMENSION_OVERRIDES = [
        'comfort'         => ['direction' => 'raise', 'target' => 60],
        'resentment'      => ['direction' => 'lower', 'target' => 10],
        'resentment_self' => ['direction' => 'lower', 'target' => 5],
        'valence'         => ['direction' => 'raise', 'target' => 0],
        'arousal'         => ['direction' => 'lower', 'target' => 20],
        'warmth'          => ['direction' => 'lower', 'target' => 30],
    ];

    const MASK_MATURITY_COST_DEFAULT = 0.15;

    /**
     * Detect emergent emotions from dimension combinations.
     * Returns array of matched emotion IDs.
     */
    public static function detectEmergentEmotions(array $dynamics): array
    {
        $config = self::getConfig();
        if (empty($config['emergent_emotions_enabled'])) return [];

        $dims = $dynamics['dimensions'] ?? [];
        $detected = [];

        foreach (self::EMERGENT_EMOTIONS as $emotionId => $spec) {
            $rules = $spec['rules'] ?? [];
            $match = true;

            foreach ($rules as $dimId => $range) {
                $value = floatval($dims[$dimId]['x'] ?? 0);
                if ($value < $range[0] || $value > $range[1]) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                $detected[] = $emotionId;
            }
        }

        return $detected;
    }

    /**
     * Generate context text for detected emergent emotions (cap at 2).
     */
    public static function generateEmergentEmotionContext(string $npcName, array $detected): string
    {
        if (empty($detected)) return '';

        $top = array_slice($detected, 0, 2);
        $lines = [];
        foreach ($top as $emotionId) {
            $spec = self::EMERGENT_EMOTIONS[$emotionId] ?? null;
            if ($spec) {
                $lines[] = str_replace('{NAME}', $npcName, $spec['context']);
            }
        }

        return implode(' ', $lines);
    }

    // ========== END EMERGENT EMOTION LABELING (PR 13) ==========

    // ========== AUTONOMOUS DIARY TRIGGER SYSTEM (PR 14) ==========

    /**
     * Check whether an autonomous diary entry should be triggered.
     *
     * Gate chain:
     *   1. Config: autonomous_diary_enabled
     *   2. Cooldown: DIARY_REFLECTION_COOLDOWN elapsed since _diary_last_accumulated
     *      (phase_transition source gets reduced 300s minimum)
     *   3. Consumable block: DIARY_CONSUMABLE_BLOCK prevents diary during consumable effects
     *   4. Maturity gate: maturity > DIARY_MIN_MATURITY
     *   5. Content triggers: at least one substantive change detected
     *   6. Interaction source also checks diary_interaction_gap
     *
     * @param string $npcName       NPC identifier
     * @param array  &$dynamics     NPC dynamics blob (pending triggers written back)
     * @param string $triggerSource One of 'interaction', 'rest', 'phase_transition', 'emotion_onset'
     * @return bool  True if diary should be generated
     */
    public static function checkDiaryTrigger(string $npcName, array &$dynamics, string $triggerSource = 'interaction'): bool
    {
        // Gate 1: Config
        $config = self::getConfig();
        if (empty($config['autonomous_diary_enabled'])) {
            return false;
        }

        // Gate 2: Cooldown (accumulated time based)
        $accumulated = intval($dynamics['_accumulated_time'] ?? 0);
        $lastAccumulated = intval($dynamics['_diary_last_accumulated'] ?? 0);
        $elapsed = $accumulated - $lastAccumulated;

        // Phase transitions get reduced cooldown (minimum 300s = 5 min play time)
        $cooldown = self::DIARY_REFLECTION_COOLDOWN;
        if ($triggerSource === 'phase_transition') {
            $cooldown = max(300, intval($cooldown * 0.3));
        }

        if ($elapsed < $cooldown) {
            return false;
        }

        // Gate 3: Consumable block
        if (self::DIARY_CONSUMABLE_BLOCK) {
            $consumableActive = !empty($dynamics['_active_consumable']);
            if ($consumableActive) {
                return false;
            }
        }

        // Gate 4: Maturity gate
        $dims = $dynamics['dimensions'] ?? [];
        $maturity = floatval($dims['maturity']['x'] ?? 0);
        if ($maturity <= self::DIARY_MIN_MATURITY) {
            return false;
        }

        // Gate 5 (interaction source only): Interaction gap check
        if ($triggerSource === 'interaction') {
            $interactionCount = intval($dynamics['interaction_count'] ?? 0);
            $lastDiaryInteraction = intval($dynamics['_diary_last_interaction'] ?? 0);
            $gap = intval($config['diary_interaction_gap'] ?? self::DIARY_INTERACTION_GAP);
            if (($interactionCount - $lastDiaryInteraction) < $gap) {
                return false;
            }
        }

        // Gate 6: Content triggers — must have something worth writing about
        $triggers = self::detectDiaryContentTriggers($dynamics);
        if (empty($triggers)) {
            return false;
        }

        // All gates passed: store pending triggers and source
        $dynamics['_diary_pending_triggers'] = $triggers;
        $dynamics['_diary_trigger_source'] = $triggerSource;

        self::log("[DIARY-TRIGGER] {$npcName}: autonomous diary triggered via {$triggerSource} — " .
            implode('; ', $triggers));

        return true;
    }

    /**
     * Detect substantive content triggers that warrant a diary entry.
     *
     * Checks seven categories of meaningful change:
     *   1. Sustained delta: 15+ drift from baseline in any dimension
     *   2. Crisis indicators: 2+ of resentment>30, comfort<20, trust<20, resentment_self>30
     *   3. Phase transitions: DI count changed, grief phase changed, attachment shifted
     *   4. New emergent emotions since last diary
     *   5. Intimacy critical: intimacy dimension < 0.1 (near-zero)
     *   6. Tier change: attraction tier ceiling shifted
     *   7. Defining moment: last interaction had significance >= 3
     *
     * @param array $dynamics  The NPC's dynamics array
     * @return array  Array of trigger description strings (empty = no triggers)
     */
    public static function detectDiaryContentTriggers(array $dynamics): array
    {
        $triggers = [];
        $dims = $dynamics['dimensions'] ?? [];

        // 1. Sustained delta: check baseline drift samples for 15+ deviation
        $driftSamples = $dynamics['_baseline_drift_samples'] ?? [];
        if (!empty($driftSamples)) {
            foreach ($driftSamples as $dimId => $samples) {
                if (!is_array($samples) || empty($samples)) continue;
                $baseline = floatval($dims[$dimId]['baseline'] ?? $dims[$dimId]['x'] ?? 0);
                $latest = end($samples);
                $latestVal = is_array($latest) ? floatval($latest['v'] ?? $latest[0] ?? 0) : floatval($latest);
                $delta = abs($latestVal - $baseline);
                if ($delta >= 15.0) {
                    $triggers[] = "sustained_delta:{$dimId}(" . round($delta, 1) . ")";
                    break; // One is enough
                }
            }
        }

        // 2. Crisis indicators: 2+ of resentment>30, comfort<20, trust<20, resentment_self>30
        $crisisCount = 0;
        $resentment = floatval($dims['resentment']['x'] ?? 0);
        $comfort = floatval($dims['comfort']['x'] ?? 50);
        $trust = floatval($dims['trust']['x'] ?? 50);
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);

        if ($resentment > 30) $crisisCount++;
        if ($comfort < 20) $crisisCount++;
        if ($trust < 20) $crisisCount++;
        if ($resentmentSelf > 30) $crisisCount++;

        if ($crisisCount >= self::DIARY_MIN_CRISIS_INDICATORS) {
            $triggers[] = "crisis_indicators:{$crisisCount}";
        }

        // 3. Phase transitions: DI count, grief phase, attachment shift
        $diCount = intval($dynamics['_divine_intervention_count'] ?? 0);
        $lastDiCount = intval($dynamics['_diary_last_di_count'] ?? 0);
        if ($diCount > $lastDiCount) {
            $triggers[] = "di_count_changed:{$lastDiCount}->{$diCount}";
        }

        // Grief phase changes
        $griefBonds = $dynamics['_grief_bonds'] ?? [];
        $lastGriefPhases = $dynamics['_diary_last_grief_phases'] ?? [];
        $currentGriefPhases = [];
        foreach ($griefBonds as $name => $grief) {
            $currentGriefPhases[$name] = intval($grief['phase'] ?? 0);
        }
        if ($currentGriefPhases !== $lastGriefPhases && !empty($currentGriefPhases)) {
            $triggers[] = "grief_phase_changed";
        }

        // Attachment shift
        $currentAttachment = $dynamics['attachment_style'] ?? null;
        $lastAttachment = $dynamics['_diary_last_attachment'] ?? null;
        if ($currentAttachment !== $lastAttachment && $currentAttachment !== null && $lastAttachment !== null) {
            $triggers[] = "attachment_shifted:{$lastAttachment}->{$currentAttachment}";
        }

        // 4. New emergent emotions since last diary
        $currentEmotions = self::detectEmergentEmotions($dynamics);
        $lastEmotions = $dynamics['_diary_last_emotions'] ?? [];
        $newEmotions = array_diff($currentEmotions, $lastEmotions);
        if (!empty($newEmotions)) {
            $triggers[] = "new_emotions:" . implode(',', $newEmotions);
        }

        // 5. Intimacy critical: near-zero
        $intimacy = floatval($dims['intimacy']['x'] ?? 50);
        if ($intimacy < 0.1 && isset($dims['intimacy'])) {
            $triggers[] = "intimacy_critical:" . round($intimacy, 3);
        }

        // 6. Tier change: attraction tier ceiling shifted
        $currentTier = $dynamics['_attraction_tier_ceiling'] ?? 'sworn';
        $lastTier = $dynamics['_diary_last_tier_ceiling'] ?? 'sworn';
        if ($currentTier !== $lastTier) {
            $triggers[] = "tier_changed:{$lastTier}->{$currentTier}";
        }

        // 7. Defining moment: last interaction had significance >= 3
        $lastSignificance = intval($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] ?? 0);
        if ($lastSignificance >= 3) {
            $triggers[] = "defining_moment:significance_{$lastSignificance}";
        }

        return $triggers;
    }

    /**
     * Mark diary as completed: update all tracking fields to current state.
     *
     * Called after a diary entry has been successfully generated and processed.
     * Updates all "last" tracking fields so subsequent trigger checks compare
     * against the post-diary state.
     *
     * @param array &$dynamics  NPC dynamics blob (modified in place)
     */
    public static function markDiaryCompleted(array &$dynamics): void
    {
        // Accumulated time bookmark
        $dynamics['_diary_last_accumulated'] = intval($dynamics['_accumulated_time'] ?? 0);

        // Interaction count bookmark
        $dynamics['_diary_last_interaction'] = intval($dynamics['interaction_count'] ?? 0);

        // Divine intervention count bookmark
        $dynamics['_diary_last_di_count'] = intval($dynamics['_divine_intervention_count'] ?? 0);

        // Attachment style bookmark
        $dynamics['_diary_last_attachment'] = $dynamics['attachment_style'] ?? null;

        // Emergent emotions bookmark
        $dynamics['_diary_last_emotions'] = self::detectEmergentEmotions($dynamics);

        // Tier ceiling bookmark
        $dynamics['_diary_last_tier_ceiling'] = $dynamics['_attraction_tier_ceiling'] ?? 'sworn';

        // Grief phases bookmark
        $griefBonds = $dynamics['_grief_bonds'] ?? [];
        $griefPhases = [];
        foreach ($griefBonds as $name => $grief) {
            $griefPhases[$name] = intval($grief['phase'] ?? 0);
        }
        $dynamics['_diary_last_grief_phases'] = $griefPhases;

        // Clear pending triggers
        $dynamics['_diary_pending_triggers'] = [];
        $dynamics['_diary_trigger_source'] = null;
    }

    // ========== END AUTONOMOUS DIARY TRIGGER SYSTEM (PR 14) ==========

    // ========== SOCIAL MASKING (PR 14) ==========

    /**
     * Determine if masking should be active.
     * Masking occurs when non-trusted NPCs are present.
     */
    public static function shouldMask(string $npcName, array $dynamics): bool
    {
        $config = self::getConfig();
        if (empty($config['social_masking_enabled'])) return false;

        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        if ($maturity < 25) return false;

        $cachePeople = $GLOBALS['CACHE_PEOPLE'] ?? '';
        if (empty(trim($cachePeople))) return false;

        $people = array_filter(array_map('trim', explode('|', $cachePeople)));
        $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');

        $audience = [];
        foreach ($people as $person) {
            if (strcasecmp($person, $npcName) === 0) continue;
            if (strcasecmp($person, $playerName) === 0) continue;
            $audience[] = $person;
        }

        if (empty($audience)) return false;

        // Check if ALL audience members are trusted
        $allTrusted = true;
        $bonds = self::getAllBondsForNpc($npcName);
        foreach ($audience as $audienceNpc) {
            $bond = $bonds[$audienceNpc] ?? null;
            if (!$bond) {
                $allTrusted = false;
                break;
            }
            $bondAff = ($bond['aff'] + 100) / 2.0;
            if ($bondAff < 50) {
                $allTrusted = false;
                break;
            }
        }

        if ($allTrusted) return false;

        return true;
    }

    /**
     * Calculate performed (masked) dimension values.
     * Shifts dimensions toward socially acceptable targets.
     * Effectiveness scales with maturity (25=0%, 75=100%).
     */
    public static function calculatePerformedState(array $dynamics): array
    {
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $dims = $dynamics['dimensions'] ?? [];
        $performed = [];

        $maskEffectiveness = max(0.0, min(1.0, ($maturity - 25) / 50.0));

        foreach (self::MASK_DIMENSION_OVERRIDES as $dimId => $override) {
            $trueValue = floatval($dims[$dimId]['x'] ?? 0);
            $target = floatval($override['target']);
            $shift = ($target - $trueValue) * $maskEffectiveness;
            $performed[$dimId] = round($trueValue + $shift, 2);
        }

        foreach ($dims as $dimId => $dimData) {
            if (!isset($performed[$dimId])) {
                $performed[$dimId] = floatval($dimData['x'] ?? 0);
            }
        }

        return $performed;
    }

    /**
     * Apply the cost of maintaining a social mask.
     * Drains maturity per masked interaction.
     */
    public static function applyMaskingCost(string $npcName, array &$dynamics): void
    {
        $config = self::getConfig();
        $cost = floatval($config['mask_maturity_cost'] ?? self::MASK_MATURITY_COST_DEFAULT);

        $attachStyle = self::getAttachmentStyle($dynamics);
        if ($attachStyle === 'avoidant') {
            $cost *= 0.5; // Practiced maskers
        } elseif ($attachStyle === 'anxious') {
            $cost *= 1.5; // Struggling to hold facade
        }

        $dims = &$dynamics['dimensions'];
        $currentMaturity = floatval($dims['maturity']['x'] ?? 50);
        $dims['maturity']['x'] = max(0, $currentMaturity - $cost);

        $dynamics['_mask_interactions_count'] = intval($dynamics['_mask_interactions_count'] ?? 0) + 1;
    }

    /**
     * Generate social masking context for LLM.
     * Dual-state: true + performed with maturity-gated quality.
     */
    public static function generateMaskingContext(string $npcName, array $dynamics, array $performedState): string
    {
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $dims = $dynamics['dimensions'] ?? [];

        $lines = [];

        $trueComfort = round(floatval($dims['comfort']['x'] ?? 50), 1);
        $trueResentment = round(floatval($dims['resentment']['x'] ?? 0), 1);
        $trueWarmth = round(floatval($dims['warmth']['x'] ?? 30), 1);

        $perfComfort = round($performedState['comfort'] ?? $trueComfort, 1);
        $perfResentment = round($performedState['resentment'] ?? $trueResentment, 1);
        $perfWarmth = round($performedState['warmth'] ?? $trueWarmth, 1);

        $lines[] = "[TRUE STATE - what {$npcName} actually feels but is hiding:]";
        $lines[] = "comfort={$trueComfort}, resentment={$trueResentment}, warmth={$trueWarmth}";
        $lines[] = "[PERFORMED STATE - what {$npcName} is showing to others:]";
        $lines[] = "comfort={$perfComfort}, resentment={$perfResentment}, warmth={$perfWarmth}";

        if ($maturity >= 65) {
            $lines[] = "[MASKING QUALITY: SEAMLESS] {$npcName} maintains perfect composure. The performed state is what shows in dialogue. The true state leaks ONLY through very subtle tells -- a micro-pause, a careful word choice, a glance that lingers too long.";
        } elseif ($maturity >= 45) {
            $lines[] = "[MASKING QUALITY: FUNCTIONAL] {$npcName} mostly holds composure but the cracks show under pressure. Forced cheerfulness, slightly too-quick subject changes, tension in their voice.";
        } elseif ($maturity >= 25) {
            $lines[] = "[MASKING QUALITY: UNSTABLE] {$npcName} is TRYING to mask but failing. The true state bleeds through constantly -- warm one moment, cold the next. This looks like mood swings to anyone watching.";
        }

        $cachePeople = $GLOBALS['CACHE_PEOPLE'] ?? '';
        $audience = array_filter(array_map('trim', explode('|', $cachePeople)));
        $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
        $audience = array_filter($audience, function($p) use ($npcName, $playerName) {
            return strcasecmp($p, $npcName) !== 0 && strcasecmp($p, $playerName) !== 0;
        });
        if (!empty($audience)) {
            $audienceStr = implode(', ', array_slice(array_values($audience), 0, 3));
            $lines[] = "[AUDIENCE: {$audienceStr}] {$npcName} is masking because these people are present.";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate mask-drop context when transitioning from public to private.
     */
    public static function generateMaskDropContext(string $npcName, array $dynamics): ?string
    {
        $wasMasking = !empty($dynamics['_was_masking']);
        $isMasking = self::shouldMask($npcName, $dynamics);

        if ($wasMasking && !$isMasking) {
            $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
            if ($maturity >= 45) {
                return "{$npcName} lets the mask fall now that they are alone with the player. The composure dissolves into something more honest. Whatever they show now is real.";
            } else {
                return "{$npcName} visibly deflates now that the audience is gone. The effort of pretending is written on their face.";
            }
        }

        return null;
    }

    // ========== END SOCIAL MASKING (PR 14) ==========

    // ========== ICK / DESPERATION TRACKER (PR 15) ==========
    //
    // Monitors romantic attempt frequency vs NPC receptivity.
    // Spamming affection while NPC is cold triggers passion INVERSION.
    // MDD Section 6.3.
    // ==========================================================

    // --- Constants ---
    const ICK_BASE_THRESHOLD = 0.5;       // 50% romantic attempts in window = desperate
    const ICK_WINDOW_SIZE = 10;           // Rolling window (interactions)
    const ICK_COMFORT_FLOOR = 40;         // Comfort must be below this for ick
    const ICK_PASSION_FLOOR = 20;         // Passion must be below this OR warmth below floor
    const ICK_WARMTH_FLOOR = 30;          // Warmth must be below this OR passion below floor
    const ICK_RESENTMENT_PER_ATTEMPT = 5; // Resentment added per romantic attempt while ick active
    const ICK_COMFORT_OVERRIDE = -3.0;    // Forced comfort delta when ick active
    const ICK_COOLDOWN_PLAY_GAMETS = 1389000; // Cooldown after ick clears: 10 min of real play (600s * GAMETS_PER_REAL_SECOND)
    const ICK_RECOVERY = [
        'comfort'    => 50,   // Comfort must exceed this
        'passion'    => 40,   // Passion must exceed this
        'resentment' => 20,   // Resentment must be below this (OR confrontation occurred)
    ];

    // Moods that count as romantic/flirtatious from the NPC's perspective
    const ROMANTIC_MOODS = [
        'flirty', 'romantic', 'playful', 'teasing', 'charmed',
        'smitten', 'coy', 'seductive', 'affectionate', 'flustered',
    ];

    /**
     * Determine if an interaction constitutes a romantic attempt by the player.
     *
     * @param string|null $interactionLL  Love language classification (LL_TOUCH, LL_WORDS, etc.)
     * @param string|null $mood           NPC's last mood
     * @param array       $evalResult     Eval result (may contain romantic_intent)
     * @return bool
     */
    public static function isRomanticAttempt($interactionLL, $mood, $evalResult = [])
    {
        // Physical touch is always romantic
        if ($interactionLL === self::LL_TOUCH) {
            return true;
        }

        // Words + romantic mood = romantic attempt
        if ($interactionLL === self::LL_WORDS && !empty($mood)) {
            if (in_array(strtolower($mood), self::ROMANTIC_MOODS, true)) {
                return true;
            }
        }

        // Eval detected high romantic intent from player
        $romanticIntent = intval($evalResult['romantic_intent'] ?? 0);
        if ($romanticIntent >= 2) {
            return true;
        }

        return false;
    }

    /**
     * Update the ick rolling window tracker.
     *
     * @param array  &$dynamics    NPC dynamics blob
     * @param bool   $isRomantic   Was this a romantic attempt?
     * @param string $temperament  NPC temperament
     * @return bool True if ick state changed
     */
    public static function updateIckTracker(&$dynamics, $isRomantic, $temperament)
    {
        $cfg = self::getConfig();
        if (empty($cfg['ick_system_enabled'] ?? true)) {
            return false;
        }

        // Initialize tracker
        if (!isset($dynamics['_ick_tracker']) || !is_array($dynamics['_ick_tracker'])) {
            $dynamics['_ick_tracker'] = [
                'romantic_count'     => 0,
                'total_count'        => 0,
                'window_start'       => intval($dynamics['interaction_count'] ?? 0),
                'ick_active'         => false,
                'ick_triggered_at'   => 0,
                'ick_cooldown_until_play_gamets' => 0,
            ];
        }

        $tracker = &$dynamics['_ick_tracker'];
        $interactionCount = intval($dynamics['interaction_count'] ?? 0);

        // Reset window if exceeded
        if (($interactionCount - intval($tracker['window_start'])) >= self::ICK_WINDOW_SIZE) {
            $tracker['romantic_count'] = 0;
            $tracker['total_count'] = 0;
            $tracker['window_start'] = $interactionCount;
        }

        // Track this interaction
        $tracker['total_count']++;
        if ($isRomantic) {
            $tracker['romantic_count']++;
        }

        // If ick already active, accumulate resentment on continued romantic attempts
        if ($tracker['ick_active'] && $isRomantic) {
            self::applyDelta('resentment', $dynamics, self::ICK_RESENTMENT_PER_ATTEMPT, $temperament);
            self::log("[ICK] Continued romantic attempt while ick active — resentment +{" . self::ICK_RESENTMENT_PER_ATTEMPT . "}");
            return false; // State didn't change
        }

        // Check if ick should trigger (only if not already active and not in cooldown)
        if (!$tracker['ick_active']) {
            if (self::checkIckTrigger($dynamics, $temperament)) {
                $tracker['ick_active'] = true;
                $tracker['ick_triggered_at'] = time();
                self::log("[ICK] TRIGGERED for NPC — romantic ratio too high while unreceptive");
                return true; // State changed
            }
        }

        return false;
    }

    /**
     * Check whether ick trigger conditions are met.
     *
     * @param array  $dynamics     NPC dynamics
     * @param string $temperament  NPC temperament
     * @return bool
     */
    public static function checkIckTrigger($dynamics, $temperament)
    {
        $tracker = $dynamics['_ick_tracker'] ?? null;
        if (!$tracker || $tracker['total_count'] < 3) {
            return false; // Need minimum interactions in window
        }

        // Check cooldown (accumulated play time; a legacy wall-clock ick_cooldown_until is ignored)
        if (!empty($tracker['ick_cooldown_until_play_gamets']) && self::getPlayGamets($dynamics) < floatval($tracker['ick_cooldown_until_play_gamets'])) {
            return false;
        }

        // Calculate romantic ratio
        $ratio = $tracker['romantic_count'] / max(1, $tracker['total_count']);

        // Maturity-gated threshold
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $cfg = self::getConfig();
        $baseThreshold = floatval($cfg['ick_base_threshold'] ?? self::ICK_BASE_THRESHOLD);
        $threshold = $baseThreshold * (1 + $maturity / 100.0);

        // Catalyst archetype lowers threshold by 30% for mature NPCs
        $charismaStyle = $dynamics['_charisma_tracker']['detected_style'] ?? null;
        if ($charismaStyle === 'catalyst' && $maturity > 60) {
            $threshold *= 0.7;
            self::log("[ICK] Catalyst style detected + high maturity — threshold reduced 30%");
        }

        if ($ratio < $threshold) {
            return false; // Not enough romantic pressure
        }

        // Check receptivity conditions
        $dims = $dynamics['dimensions'] ?? [];
        $comfort = floatval($dims['comfort']['x'] ?? 50);
        $passion = floatval($dims['passion']['x'] ?? 0);
        $warmth  = floatval($dims['warmth']['x'] ?? 50);

        // NPC must be unreceptive: comfort < 40 AND (passion < 20 OR warmth < 30)
        if ($comfort >= self::ICK_COMFORT_FLOOR) {
            return false; // NPC is comfortable — no ick
        }

        if ($passion >= self::ICK_PASSION_FLOOR && $warmth >= self::ICK_WARMTH_FLOOR) {
            return false; // NPC is receptive — no ick
        }

        return true;
    }

    /**
     * Check if ick should clear (recovery conditions met).
     *
     * @param array &$dynamics NPC dynamics
     * @return bool True if ick was cleared
     */
    public static function checkIckRecovery(&$dynamics)
    {
        $tracker = &$dynamics['_ick_tracker'];
        if (empty($tracker) || !$tracker['ick_active']) {
            return false;
        }

        $dims = $dynamics['dimensions'] ?? [];
        $comfort    = floatval($dims['comfort']['x'] ?? 0);
        $passion    = floatval($dims['passion']['x'] ?? 0);
        $resentment = floatval($dims['resentment']['x'] ?? 0);

        $recovery = self::ICK_RECOVERY;
        $comfortOk    = ($comfort > $recovery['comfort']);
        $passionOk    = ($passion > $recovery['passion']);
        $resentmentOk = ($resentment < $recovery['resentment']);

        // Check if confrontation occurred (resentment was addressed)
        $confrontationOccurred = !empty($dynamics['_ick_confrontation_resolved']);

        if ($comfortOk && $passionOk && ($resentmentOk || $confrontationOccurred)) {
            $tracker['ick_active'] = false;
            $tracker['ick_cooldown_until_play_gamets'] = self::getPlayGamets($dynamics) + self::ICK_COOLDOWN_PLAY_GAMETS;
            unset($tracker['ick_cooldown_until']); // legacy wall-clock value
            $tracker['romantic_count'] = 0;
            $tracker['total_count'] = 0;
            $dynamics['_ick_confrontation_resolved'] = false;
            self::log("[ICK] CLEARED — recovery conditions met, cooldown set");
            return true;
        }

        return false;
    }

    /**
     * Apply ick effects to an eval delta before XYZ physics.
     * Called from processEvalDeltas.
     *
     * @param array  $dynamics     NPC dynamics
     * @param string $dimensionId  Dimension being modified
     * @param float  &$rawDelta    Raw delta (modified in place)
     * @return bool True if ick modified the delta
     */
    public static function applyIckEffects($dynamics, $dimensionId, &$rawDelta)
    {
        $tracker = $dynamics['_ick_tracker'] ?? null;
        if (empty($tracker) || !$tracker['ick_active']) {
            return false;
        }

        $modified = false;

        // Passion INVERSION — gains become losses
        if ($dimensionId === 'passion' && $rawDelta > 0) {
            $rawDelta = -abs($rawDelta);
            $modified = true;
            self::log("[ICK] Passion INVERTED: {$rawDelta}");
        }

        // Comfort override — forced negative
        if ($dimensionId === 'comfort') {
            $rawDelta = min($rawDelta, self::ICK_COMFORT_OVERRIDE);
            $modified = true;
        }

        return $modified;
    }

    /**
     * Generate context injection for active ick state.
     *
     * @param array  $dynamics    NPC dynamics
     * @param string $npcName     NPC name
     * @param string $temperament Temperament
     * @return string|null Context block or null
     */
    public static function getIckContext($dynamics, $npcName, $temperament)
    {
        $tracker = $dynamics['_ick_tracker'] ?? null;
        if (empty($tracker) || !$tracker['ick_active']) {
            return null;
        }

        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $resentment = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);

        $base = "{$npcName} finds the player's persistent romantic attention uncomfortable and suffocating. ";
        $base .= "Their advances feel desperate, not flattering. ";

        // Maturity determines reaction style
        if ($maturity >= 65) {
            $base .= "{$npcName} recognizes the pattern clearly and will address it directly if pressed further.";
        } elseif ($maturity >= 45) {
            $base .= "{$npcName} feels uneasy but may not articulate why — withdrawing instead of confronting.";
        } else {
            $base .= "{$npcName} is confused by their own discomfort — they can't tell if the attention is bad or if something is wrong with them.";
        }

        // Resentment escalation
        if ($resentment >= 50) {
            $base .= " Has reached breaking point about the unwanted advances — will confront this directly.";
        }

        return $base;
    }

    // ========== END ICK / DESPERATION TRACKER (PR 15) ==========

    // ========== CHARISMA ARCHETYPES (PR 15) ==========
    //
    // Detects player interaction style (Rock/Catalyst/Charmer) from
    // patterns in romantic_intent and affinity deltas.
    // Applies effectiveness multipliers per NPC temperament + maturity.
    // MDD Section 5.1.
    // ==========================================================

    const CHARISMA_WINDOW = 10;       // Interactions to analyze
    const CHARISMA_MIN_SAMPLES = 5;   // Minimum before detection
    const CHARISMA_VARIANCE_HIGH = 15.0; // Above this = Catalyst
    const CHARISMA_VARIANCE_LOW = 5.0;   // Below this = Rock or Charmer

    /**
     * Effectiveness multipliers: [temperament => [style => [dimension => multiplier]]]
     */
    const CHARISMA_EFFECTIVENESS = [
        'rock' => [
            'effective'   => ['Anxious', 'Gentle', 'Humble'],       // Stability seekers
            'ineffective' => ['Independent', 'Bold', 'Defiant'],    // Don't need stability
            'aff_mult_eff'   => 1.3,
            'aff_mult_ineff' => 0.7,
        ],
        'catalyst' => [
            'effective'   => ['Playful', 'Romantic'],                // Intensity = excitement
            'ineffective' => ['Stoic', 'Guarded', 'Proud'],         // See through it
            'passion_mult_eff'   => 1.5,
            'passion_mult_ineff' => 0.5,
        ],
        'charmer' => [
            'effective'   => ['Nurturing', 'Gentle', 'Romantic', 'Humble'], // Broad base
            'ineffective' => ['Independent', 'Proud'],              // Loses respect over time
            'passion_mult_eff'   => 1.2,
            'passion_mult_ineff' => 0.8,
        ],
    ];

    /**
     * Update the charisma style tracker with latest interaction data.
     *
     * @param array &$dynamics      NPC dynamics
     * @param int   $romanticIntent romantic_intent from eval (0-3)
     * @param float $affinityDelta  affinity_delta from eval
     */
    public static function updateCharismaTracker(&$dynamics, $romanticIntent, $affinityDelta)
    {
        $cfg = self::getConfig();
        if (empty($cfg['charisma_detection_enabled'] ?? true)) {
            return;
        }

        if (!isset($dynamics['_charisma_tracker']) || !is_array($dynamics['_charisma_tracker'])) {
            $dynamics['_charisma_tracker'] = [
                'recent_intents' => [],
                'recent_deltas'  => [],
                'detected_style' => null,
                'style_confidence' => 0.0,
                'style_detected_at' => 0,
            ];
        }

        $tracker = &$dynamics['_charisma_tracker'];

        // Push to rolling window
        $tracker['recent_intents'][] = intval($romanticIntent);
        $tracker['recent_deltas'][] = floatval($affinityDelta);

        // Trim to window size
        if (count($tracker['recent_intents']) > self::CHARISMA_WINDOW) {
            array_shift($tracker['recent_intents']);
        }
        if (count($tracker['recent_deltas']) > self::CHARISMA_WINDOW) {
            array_shift($tracker['recent_deltas']);
        }

        // Detect style if enough samples
        if (count($tracker['recent_deltas']) >= self::CHARISMA_MIN_SAMPLES) {
            $detected = self::detectCharismaStyle($tracker['recent_intents'], $tracker['recent_deltas']);
            if ($detected !== null) {
                $tracker['detected_style'] = $detected['style'];
                $tracker['style_confidence'] = $detected['confidence'];
                $tracker['style_detected_at'] = time();
            }
        }
    }

    /**
     * Analyze interaction patterns to detect charisma style.
     *
     * @param array $intents  Recent romantic_intent values
     * @param array $deltas   Recent affinity_delta values
     * @return array|null ['style' => string, 'confidence' => float] or null
     */
    public static function detectCharismaStyle($intents, $deltas)
    {
        $count = count($deltas);
        if ($count < self::CHARISMA_MIN_SAMPLES) {
            return null;
        }

        // Calculate delta statistics
        $avgDelta = array_sum($deltas) / $count;
        $variance = 0.0;
        foreach ($deltas as $d) {
            $variance += ($d - $avgDelta) ** 2;
        }
        $variance /= $count;

        // Calculate intent statistics
        $avgIntent = array_sum($intents) / $count;
        $highIntentCount = count(array_filter($intents, fn($i) => $i >= 2));

        // Detection logic:
        // Catalyst: high variance (push-pull), alternating positive/negative
        if ($variance > self::CHARISMA_VARIANCE_HIGH && abs($avgDelta) > 1.0) {
            return ['style' => 'catalyst', 'confidence' => min(1.0, $variance / 30.0)];
        }

        // Charmer: consistent positive, high romantic intent
        if ($variance < self::CHARISMA_VARIANCE_LOW && $avgDelta > 0.5 && $avgIntent >= 1.0) {
            $confidence = min(1.0, ($avgDelta / 3.0) * ($avgIntent / 2.0));
            return ['style' => 'charmer', 'confidence' => $confidence];
        }

        // Rock: consistent, low emotional variation, low romantic intent
        if ($variance < self::CHARISMA_VARIANCE_LOW && abs($avgDelta) < 1.5 && $avgIntent < 1.0) {
            return ['style' => 'rock', 'confidence' => min(1.0, (1.5 - abs($avgDelta)) / 1.5)];
        }

        return null; // Mixed/ambiguous
    }

    /**
     * Get charisma effectiveness multiplier for a given style against NPC.
     *
     * @param string|null $style       Detected style (rock/catalyst/charmer) or null
     * @param string      $temperament NPC temperament
     * @param float       $maturity    NPC maturity value
     * @param string      $dimensionId Which dimension to get multiplier for
     * @return float Multiplier (1.0 = no effect)
     */
    public static function getCharismaEffectiveness($style, $temperament, $maturity, $dimensionId)
    {
        if ($style === null || !isset(self::CHARISMA_EFFECTIVENESS[$style])) {
            return 1.0;
        }

        $profile = self::CHARISMA_EFFECTIVENESS[$style];
        $isEffective = in_array($temperament, $profile['effective'] ?? [], true);
        $isIneffective = in_array($temperament, $profile['ineffective'] ?? [], true);

        // Catalyst special: ineffective against HIGH maturity regardless of temperament
        if ($style === 'catalyst' && $maturity > 60 && !$isEffective) {
            $isIneffective = true;
        }

        // Charmer special: diminishing returns — after many interactions becomes less effective
        // (handled externally via overuse counter)

        // Select multiplier based on dimension
        if ($dimensionId === 'affinity' && $style === 'rock') {
            return $isEffective ? $profile['aff_mult_eff'] : ($isIneffective ? $profile['aff_mult_ineff'] : 1.0);
        }
        if ($dimensionId === 'passion' && in_array($style, ['catalyst', 'charmer'], true)) {
            $key_eff = 'passion_mult_eff';
            $key_ineff = 'passion_mult_ineff';
            return $isEffective ? $profile[$key_eff] : ($isIneffective ? $profile[$key_ineff] : 1.0);
        }

        return 1.0;
    }

    /**
     * Generate charisma awareness context for high-maturity NPCs.
     *
     * @param array  $dynamics    NPC dynamics
     * @param string $npcName     NPC name
     * @return string|null Context block or null
     */
    public static function getCharismaContext($dynamics, $npcName)
    {
        $tracker = $dynamics['_charisma_tracker'] ?? null;
        if (empty($tracker) || empty($tracker['detected_style'])) {
            return null;
        }

        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $style = $tracker['detected_style'];
        $confidence = floatval($tracker['style_confidence']);

        // Only high-maturity NPCs become aware of the pattern
        if ($maturity < 55 || $confidence < 0.5) {
            return null;
        }

        $labels = [
            'rock'     => 'stoic and authoritative, projecting stability',
            'catalyst' => 'using push-pull intensity, alternating warmth and distance',
            'charmer'  => 'consistently flattering and accommodating',
        ];

        $label = $labels[$style] ?? $style;

        if ($maturity >= 70) {
            return "{$npcName} has clearly recognized the player's interaction pattern — they are being {$label}. "
                 . "This recognition doesn't mean rejection, but {$npcName} sees through the technique.";
        }

        return "{$npcName} is starting to notice a pattern in how the player interacts — {$label}. "
             . "Not fully conscious of it yet, but something feels calculated.";
    }

    // ========== END CHARISMA ARCHETYPES (PR 15) ==========

    // ========== CROSS-BOND GUILT BLEED (PR 15) ==========
    //
    // When resentment_self > 30, guilt bleeds into comfort toward
    // all bonded partners. Higher bond = more guilt.
    // MDD: "You don't feel comfortable around people who love you
    //        when you know what you've done."
    // ==========================================================

    /**
     * Apply guilt bleed: resentment_self reduces comfort toward bonded NPCs.
     * Called once per diary cycle (not every interaction).
     *
     * @param array  &$dynamics   NPC dynamics
     * @param string $npcName     NPC name
     * @param string $temperament NPC temperament
     * @return array Results of bleed application
     */
    public static function applyGuiltBleed(&$dynamics, $npcName, $temperament)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);

        if ($resentmentSelf <= 30) {
            return [];
        }

        $results = [];
        $bonds = self::getAllBondsForNpc($npcName);

        foreach ($bonds as $targetName => $bond) {
            $bondAff = floatval($bond['aff'] ?? 0);
            if ($bondAff < 40) {
                continue; // Only bleed to meaningful bonds
            }

            $bondStrength = ($bondAff + 100) / 200.0; // Normalize to 0-1
            $bleed = $resentmentSelf * $bondStrength;
            $bleedDelta = -min(15.0, $bleed); // Cap at -15

            if (abs($bleedDelta) < 0.5) {
                continue; // Too small to matter
            }

            // Load target dynamics and apply comfort delta
            $targetDynamics = self::getDynamics($targetName);
            if (empty($targetDynamics) || !is_array($targetDynamics)) {
                continue;
            }

            $targetTemperament = $targetDynamics['inferred_temperament'] ?? $targetDynamics['temperament'] ?? 'Stoic';
            $actual = self::applyDelta('comfort', $targetDynamics, $bleedDelta, $targetTemperament);

            if (abs($actual) > 0.001) {
                self::saveDynamics($targetName, $targetDynamics);
                $results[] = [
                    'target' => $targetName,
                    'bleed_delta' => round($bleedDelta, 2),
                    'actual' => round($actual, 4),
                    'bond_strength' => round($bondStrength, 2),
                ];
                self::log("[GUILT-BLEED] {$npcName} guilt → {$targetName} comfort: delta=" . round($actual, 4) . " (bond=" . round($bondStrength, 2) . ")");
            }
        }

        return $results;
    }

    // ========== END CROSS-BOND GUILT BLEED (PR 15) ==========

    // ========== AUTONOMY OVERRIDE (PR 16) ==========
    //
    // Personality-gated refusal spectrum. NPCs refuse commands based on
    // self-confidence × maturity × attachment × trust × respect.
    // MDD: "People-pleasers don't refuse. They comply, suffer, and break."
    // ==========================================================

    // Autonomy score weights — how each dimension contributes
    const AUTONOMY_WEIGHTS = [
        'distrust'        => 0.25,   // (100 - trust) * weight
        'disrespect'      => 0.20,   // (100 - respect) * weight
        'resentment'      => 0.30,   // resentment * weight
        'self_confidence'  => 0.15,   // (self_confidence / 100) * weight * 100
        'maturity_mod'    => 0.10,   // maturity modifier * weight
    ];

    // State thresholds (autonomy_score → state)
    const AUTONOMY_THRESHOLDS = [
        'compliant'  => 30,   // < 30
        'resistant'  => 55,   // 30-55
        'refusing'   => 75,   // 55-75
        // > 75 = walkaway
    ];

    // Actions denied per autonomy state
    const AUTONOMY_DENIED_ACTIONS = [
        'refusing' => [
            'FollowPlayer', 'Follow', 'MakeFollower',
            'OpenInventory', 'OpenInventory2',
            'GiveItemTo', 'GiveGoldTo',
        ],
        'walkaway' => [
            'FollowPlayer', 'Follow', 'MakeFollower',
            'OpenInventory', 'OpenInventory2',
            'GiveItemTo', 'GiveGoldTo',
            'ComeCloser', 'IncreaseWalkSpeed', 'DecreaseWalkSpeed',
        ],
    ];

    // Walkaway constants. No passive resentment decay while the NPC is away (decisions
    // 2026-09-23 §2: time does not heal); leaving them alone resolves the boundary test.
    const WALKAWAY_FOLLOW_RESENTMENT_MULT = 2.0;      // Resentment multiplier when player follows
    const WALKAWAY_FOLLOW_TRUST_PENALTY = -5.0;       // Permanent trust hit when player follows during walkaway
    const BOUNDARY_TEST_MIN_HOURS = 24;                // Minimum boundary test, game-calendar hours
    const BOUNDARY_TEST_MAX_HOURS = 48;                // Maximum boundary test, game-calendar hours
    const WALKAWAY_RECOVERY_RESENTMENT_MAX = 50;       // Early recovery: resentment (0..100) below this
    const WALKAWAY_RECOVERY_COMFORT_MIN = 30;          // Early recovery: comfort (0..100) above this

    // Hoover constants (Toxic exclusive)
    const HOOVER_MIN_HOURS = 72;                       // Minimum sleeper timer, game-calendar hours (MDD 6.6, decisions §2)
    const HOOVER_MAX_HOURS = 96;                       // Maximum sleeper timer, game-calendar hours
    const HOOVER_MATURITY_CAP = 40;                    // Maturity must be below this for hoover
    const HOOVER_RESENTMENT_REBUILD_MULT = 1.5;        // Post-hoover resentment rebuilds faster
    const HOOVER_WALKAWAY_THRESHOLD_REDUCTION = 0.20;  // Next walkaway triggers 20% sooner
    const HOOVER_SNAP = [                               // Dimensional snap on hoover return
        'resentment' => 0,
        'passion'    => 100,
        'comfort'    => 50,
    ];

    // People-pleaser caps
    const PEOPLE_PLEASER_CONFIDENCE_CAP = 30;
    const PEOPLE_PLEASER_MATURITY_CAP = 40;
    const PEOPLE_PLEASER_RESENTMENT_SELF_RATE = 0.1;   // autonomy_score * this per interaction

    /**
     * Evaluate the autonomy state for an NPC based on personality matrix.
     *
     * @param array  $dynamics    NPC dynamics
     * @param string $temperament NPC temperament
     * @return array ['state', 'autonomy_score', 'refusal_type', 'deny_actions', 'context', 'people_pleaser']
     */
    public static function evaluateAutonomyState($dynamics, $temperament = 'Stoic')
    {
        $dims = $dynamics['dimensions'] ?? [];

        // Read dimension values
        $trust           = floatval($dims['trust']['x'] ?? 50);
        $respect         = floatval($dims['respect']['x'] ?? 50);
        $resentment      = floatval($dims['resentment']['x'] ?? 0);
        $selfConfidence  = floatval($dims['self_confidence']['x'] ?? 50);
        $maturity        = floatval($dims['maturity']['x'] ?? 50);

        // Maturity modifier: high maturity changes HOW refusal happens, not IF
        // 0-30: -0.5 (less likely to act on feelings)
        // 30-60: 0 (neutral)
        // 60-100: +0.5 (more likely to set clear boundaries)
        $maturityMod = 0;
        if ($maturity < 30) {
            $maturityMod = -0.5;
        } elseif ($maturity > 60) {
            $maturityMod = 0.5;
        }

        // Calculate autonomy score
        $w = self::AUTONOMY_WEIGHTS;
        $score = (100 - $trust) * $w['distrust']
               + (100 - $respect) * $w['disrespect']
               + $resentment * $w['resentment']
               + ($selfConfidence / 100) * $w['self_confidence'] * 100
               + $maturityMod * $w['maturity_mod'] * 100;

        // Clamp to 0-100
        $score = max(0, min(100, $score));

        // Check for hoover reduction (post-hoover NPCs trigger walkaway sooner)
        $hooverCount = intval($dynamics['_hoover_count'] ?? 0);
        $effectiveThresholds = self::AUTONOMY_THRESHOLDS;
        if ($hooverCount > 0) {
            $reduction = self::HOOVER_WALKAWAY_THRESHOLD_REDUCTION * $hooverCount;
            // Lower the refusing/walkaway thresholds
            $effectiveThresholds['refusing'] = max(40, $effectiveThresholds['refusing'] * (1 - $reduction));
        }

        // Determine state from score
        if ($score < $effectiveThresholds['compliant']) {
            $state = 'compliant';
        } elseif ($score < $effectiveThresholds['resistant']) {
            $state = 'resistant';
        } elseif ($score < ($effectiveThresholds['refusing'] ?? 75)) {
            $state = 'refusing';
        } else {
            $state = 'walkaway';
        }

        // People-pleaser override: low confidence + low maturity = forced compliance
        $isPeoplePleaser = self::isPeoplePleaser($dynamics);

        if ($isPeoplePleaser && ($state === 'refusing' || $state === 'walkaway')) {
            $state = 'compliant';
        }

        // Ick + low comfort OR high resentment → force walkaway regardless
        $ickActive = !empty($dynamics['_ick_tracker']['ick_active']);
        $comfort = floatval($dims['comfort']['x'] ?? 50);
        if ($ickActive && $comfort < 20) {
            $state = 'walkaway';
        }
        // MDD 15.5: the walkaway is at resentment 90 (70 is withdrawal), from the one accessor
        if (self::getResentmentEffects($dynamics)['walkaway'] && !$isPeoplePleaser) {
            $state = 'walkaway';
        }
        // MDD 6.5: jealousy (0..100) reaching jealousy_walkaway_at -> walkaway
        if (floatval($dynamics['jealousy_anger'] ?? 0) >= floatval(self::configValue('jealousy_walkaway_at'))) {
            $state = 'walkaway';
        }

        // If already in walkaway, stay in walkaway
        if (!empty($dynamics['_walkaway_state']) && $dynamics['_walkaway_state'] !== 'normal') {
            $state = 'walkaway';
        }

        // Get refusal type and denied actions
        $refusalType = ($state === 'compliant') ? null : self::getRefusalType($dynamics, $temperament);
        $deniedActions = self::getDeniedActions($state);

        return [
            'state'           => $state,
            'autonomy_score'  => round($score, 2),
            'refusal_type'    => $refusalType,
            'deny_actions'    => $deniedActions,
            'people_pleaser'  => $isPeoplePleaser,
            'resentment_self_buildup' => $isPeoplePleaser ? round($score * self::PEOPLE_PLEASER_RESENTMENT_SELF_RATE, 2) : 0,
        ];
    }

    /**
     * Determine refusal type from personality matrix quadrant.
     *
     * @param array  $dynamics    NPC dynamics
     * @param string $temperament NPC temperament
     * @return string 'silent'|'boundary'|'dramatic'|'direct'|'manipulative'
     */
    public static function getRefusalType($dynamics, $temperament = 'Stoic')
    {
        $dims = $dynamics['dimensions'] ?? [];
        $selfConf = floatval($dims['self_confidence']['x'] ?? 50);
        $maturity = floatval($dims['maturity']['x'] ?? 50);
        $attachment = $dynamics['attachment_style'] ?? 'secure';

        // Attachment overrides
        if ($attachment === 'toxic') {
            return 'manipulative';
        }

        // Personality matrix quadrant
        $highConf = $selfConf >= 50;
        $highMat = $maturity >= 50;

        if (!$highConf && !$highMat) {
            $type = 'silent';      // Low conf + low mat: comply but suffer
        } elseif (!$highConf && $highMat) {
            $type = 'boundary';    // Low conf + high mat: polite but firm
        } elseif ($highConf && !$highMat) {
            $type = 'dramatic';    // High conf + low mat: emotional outburst
        } else {
            $type = 'direct';      // High conf + high mat: clean refusal
        }

        // Attachment shifts
        if ($attachment === 'anxious' && $type !== 'silent') {
            $type = 'silent';      // Anxious → comply to keep bond
        }
        if ($attachment === 'avoidant' && $type !== 'direct') {
            $type = 'direct';      // Avoidant → withdraw without explaining
        }

        return $type;
    }

    /**
     * Get the list of actions to deny based on autonomy state.
     *
     * @param string $state Autonomy state
     * @return array Action names to remove
     */
    public static function getDeniedActions($state)
    {
        return self::AUTONOMY_DENIED_ACTIONS[$state] ?? [];
    }

    /**
     * Generate context injection text for the LLM based on autonomy state.
     *
     * @param array  $dynamics    NPC dynamics
     * @param string $npcName     NPC name
     * @param string $temperament NPC temperament
     * @return string|null Context text or null
     */
    public static function getAutonomyContext($dynamics, $npcName, $temperament = 'Stoic')
    {
        $eval = self::evaluateAutonomyState($dynamics, $temperament);
        $state = $eval['state'];

        if ($state === 'compliant') {
            // People-pleaser internalization
            if ($eval['people_pleaser'] && $eval['autonomy_score'] >= 30) {
                return "{$npcName} is uncomfortable but says nothing. A tightness in their chest, "
                     . "a forced smile. They want to refuse but can't bring themselves to.";
            }
            return null;
        }

        $refusalType = $eval['refusal_type'];
        $dims = $dynamics['dimensions'] ?? [];
        $maturity = floatval($dims['maturity']['x'] ?? 50);

        if ($state === 'resistant') {
            $detail = '';
            if ($maturity > 60) {
                $detail = ' They are choosing their words carefully, setting soft limits.';
            } elseif ($maturity < 30) {
                $detail = ' Shorter responses, clipped tone, avoiding eye contact.';
            }
            return "{$npcName} is growing uncomfortable with the player's commands. "
                 . "They follow for now but with visible reluctance.{$detail}";
        }

        if ($state === 'refusing') {
            $reaction = match ($refusalType) {
                'silent'       => "Compliance on the surface, but something has broken inside. They do as asked with hollow eyes.",
                'boundary'     => "\"I need you to respect my boundaries.\" Polite but immovable.",
                'dramatic'     => "Voice raised, hands trembling. An emotional outburst building toward a breaking point.",
                'direct'       => "\"I'm not going to do that.\" No apology, no negotiation.",
                'manipulative' => "\"Of course, anything you want.\" The words are right but the eyes are wrong.",
                default        => "Resistance is clear in their body language.",
            };
            return "{$npcName} has decided they will not comply with the player's demands. "
                 . "{$reaction} They may end the conversation or walk away if pushed further.";
        }

        // Walkaway
        $walkState = $dynamics['_walkaway_state'] ?? 'pending';
        if ($walkState === 'recovery') {
            return "{$npcName} has returned because they chose to, not because they were summoned. "
                 . "The air is fragile. They are watching to see if things have really changed.";
        }
        if ($walkState === 'permanent') {
            return "{$npcName} is done. This bridge is burned. They feel nothing but cold distance "
                 . "where warmth used to be.";
        }
        if ($walkState === 'active' || $walkState === 'boundary_test') {
            return "{$npcName} has left. They are processing what happened. "
                 . "Approaching them now risks making things permanently worse.";
        }
        // pending or just-triggered
        return "{$npcName} is done. They are leaving. No amount of persuasion will change this "
             . "right now. The conversation is over.";
    }

    // ========== END AUTONOMY OVERRIDE (PR 16) ==========

    // ========== WALKAWAY STATE MACHINE (PR 16) ==========
    //
    // 5-state machine: normal → pending → active → boundary_test → recovery/permanent
    // NPCs physically leave, travel to home, and may or may not return
    // based on player behavior during the boundary test window.
    // ==========================================================

    /**
     * Initiate walkaway sequence. Sets state to 'pending' (1 interaction grace).
     *
     * @param array  &$dynamics NPC dynamics (modified in place)
     * @param string $npcName   NPC name
     * @param string $reason    Why walkaway triggered ('ick_comfort', 'resentment', 'autonomy')
     */
    public static function initiateWalkaway(&$dynamics, $npcName, $reason = 'autonomy')
    {
        $currentState = $dynamics['_walkaway_state'] ?? 'normal';

        // Don't re-initiate if already walking away
        if ($currentState !== 'normal') {
            return;
        }

        // Back from a resolved boundary test with the resentment still there: the player
        // gets a few contacts to start repairing it before the NPC leaves again.
        $grace = intval($dynamics['_walkaway_return_grace'] ?? 0);
        if ($grace > 0) {
            $dynamics['_walkaway_return_grace'] = $grace - 1;
            self::log("[WALKAWAY] {$npcName} holds off leaving again ({$reason}); return grace left: " . ($grace - 1));
            return;
        }

        // Calculate boundary test duration (random within range, game-calendar hours)
        $testHours = self::BOUNDARY_TEST_MIN_HOURS
                   + (mt_rand(0, 100) / 100.0) * (self::BOUNDARY_TEST_MAX_HOURS - self::BOUNDARY_TEST_MIN_HOURS);

        $dynamics['_walkaway_state'] = 'pending';
        $dynamics['_walkaway_reason'] = $reason;
        self::markGameClock($dynamics, '_walkaway_started_calendar_gamets');
        $dynamics['_walkaway_boundary_test_hours'] = round($testHours, 1);
        $dynamics['_walkaway_player_followed'] = false;

        self::log("[WALKAWAY] Initiated for {$npcName}: reason={$reason}, boundary_test={$testHours}h");
    }

    /**
     * Advance walkaway from pending to active. Called on next interaction after pending.
     * Forces NPC to travel to home location.
     *
     * @param array  &$dynamics NPC dynamics (modified in place)
     * @param string $npcName   NPC name
     */
    public static function activateWalkaway(&$dynamics, $npcName)
    {
        $dynamics['_walkaway_state'] = 'active';
        self::markGameClock($dynamics, '_walkaway_activated_calendar_gamets');

        // Pause affinity decay during walkaway (they chose to leave, not forgotten)
        self::startDecayPause($dynamics);

        // Execute physical departure
        self::executeWalkawaySelfDismiss($npcName, $dynamics);

        self::log("[WALKAWAY] Activated for {$npcName} — NPC departing to home");
    }

    /**
     * Process per-interaction tick during walkaway.
     * Handles resentment decay/escalation and boundary test progression.
     *
     * @param array  &$dynamics   NPC dynamics (modified in place)
     * @param string $npcName     NPC name
     * @param string $temperament NPC temperament
     * @param bool   $isDialogue  Whether this tick is from player dialogue (pressure)
     * @return array Status update
     */
    public static function processWalkawayTick(&$dynamics, $npcName, $temperament = 'Stoic', $isDialogue = false)
    {
        $state = $dynamics['_walkaway_state'] ?? 'normal';
        if ($state === 'normal' || $state === 'permanent') {
            return ['state' => $state, 'changed' => false];
        }

        // Walkaway timers are game-calendar stamps. A walkaway started by an older build
        // holds only play-clock or wall-clock stamps (other keys), and an earlier save puts
        // a stamp in the future: (re)start those clocks now instead of trusting them.
        $calendarKeys = ['_walkaway_started_calendar_gamets'];
        if ($state === 'active' || $state === 'boundary_test') $calendarKeys[] = '_walkaway_activated_calendar_gamets';
        if ($state === 'boundary_test') $calendarKeys[] = '_boundary_test_started_calendar_gamets';
        foreach ($calendarKeys as $timerKey) {
            if (self::gameHoursSince($dynamics, $timerKey) === null) {
                self::markGameClock($dynamics, $timerKey);
            }
        }

        // Pending → Active on next interaction
        if ($state === 'pending') {
            self::activateWalkaway($dynamics, $npcName);
            return ['state' => 'active', 'changed' => true, 'action' => 'activated'];
        }

        $result = ['state' => $state, 'changed' => false];

        // Check if player is pressuring (dialogue during walkaway)
        if ($isDialogue && ($state === 'active' || $state === 'boundary_test')) {
            // Player followed and engaged — resentment escalation
            $dynamics['_walkaway_player_followed'] = true;

            $dims = &$dynamics['dimensions'];
            $currentResentment = floatval($dims['resentment']['x'] ?? 0);
            $newResentment = min(100, $currentResentment * self::WALKAWAY_FOLLOW_RESENTMENT_MULT);
            $dims['resentment']['x'] = $newResentment;

            // Permanent trust hit
            self::applyDelta('trust', $dynamics, self::WALKAWAY_FOLLOW_TRUST_PENALTY, $temperament);

            self::log("[WALKAWAY] Player pressured {$npcName} during walkaway: resentment {$currentResentment}→{$newResentment}, trust " . self::WALKAWAY_FOLLOW_TRUST_PENALTY);
            $result['changed'] = true;
            $result['pressure_applied'] = true;
            return $result;
        }

        // Move to boundary test phase after activation
        if ($state === 'active') {
            $dynamics['_walkaway_state'] = 'boundary_test';
            self::markGameClock($dynamics, '_boundary_test_started_calendar_gamets');
            $result['state'] = 'boundary_test';
            $result['changed'] = true;
        }

        // During boundary test: no passive resentment decay (time away does not heal)
        if ($state === 'boundary_test' || $dynamics['_walkaway_state'] === 'boundary_test') {
            // Check boundary test outcome
            $boundaryResult = self::checkBoundaryTest($dynamics);
            if ($boundaryResult === 'recovery') {
                $dynamics['_walkaway_state'] = 'recovery';
                self::markGameClock($dynamics, '_walkaway_recovery_calendar_gamets');
                self::endDecayPause($dynamics);
                self::log("[WALKAWAY] {$npcName} entering recovery — boundary test resolved");
                $result['state'] = 'recovery';
                $result['changed'] = true;
            } elseif ($boundaryResult === 'permanent') {
                $dynamics['_walkaway_state'] = 'permanent';
                self::markGameClock($dynamics, '_walkaway_permanent_calendar_gamets');
                self::endDecayPause($dynamics);
                self::log("[WALKAWAY] {$npcName} permanent departure — boundary test failed");
                $result['state'] = 'permanent';
                $result['changed'] = true;
            }
        }

        return $result;
    }

    /**
     * Evaluate boundary test pass/fail conditions.
     *
     * @param array $dynamics NPC dynamics
     * @return string|null 'recovery', 'permanent', or null (still testing)
     */
    public static function checkBoundaryTest($dynamics)
    {
        if (floatval($dynamics['_boundary_test_started_calendar_gamets'] ?? 0) <= 0) {
            return null;
        }

        // Game-calendar hours (decisions 2026-09-23 §2): time apart in the world, so a
        // wait or a sleep counts. Leaving them alone is what the test asks for.
        $testHours = floatval($dynamics['_walkaway_boundary_test_hours'] ?? 36);
        $elapsedHours = self::gameHoursSince($dynamics, '_boundary_test_started_calendar_gamets') ?? 0.0;

        $dims = $dynamics['dimensions'] ?? [];
        $resentment = floatval($dims['resentment']['x'] ?? 0);
        $comfort = floatval($dims['comfort']['x'] ?? 50);

        // Player followed → failed (MDD 6.4), whatever the resentment behind the walkaway.
        if (!empty($dynamics['_walkaway_player_followed'])) {
            return 'permanent';
        }

        // A Toxic sleeper does not come back through the boundary test (early recovery
        // included): it vanishes until its hoover (MDD 6.6, 72-96 game-calendar hours).
        if (self::isHooverSleeper($dynamics)) {
            return null;
        }

        // Early recovery: resentment (0..100) already below threshold AND comfort (0..100)
        // above minimum
        if ($resentment < self::WALKAWAY_RECOVERY_RESENTMENT_MAX
            && $comfort > self::WALKAWAY_RECOVERY_COMFORT_MIN) {
            return 'recovery';
        }

        // Left alone for the whole test → resolved. This clears the walkaway only; the
        // resentment behind it stays until positive contact brings it down.
        if ($elapsedHours >= $testHours) {
            return 'recovery';
        }

        return null; // Still testing
    }

    /**
     * Advance a walkaway by one tick and carry out a resolved boundary test: the NPC
     * returns and the walkaway state is cleared (resentment is left as it is), with a
     * return grace so they do not walk out again before the player can make amends.
     *
     * @return array ['state' => string after the tick, 'returned' => bool]
     */
    public static function resolveWalkawayTick(&$dynamics, $npcName, $temperament = 'Stoic', $isDialogue = false)
    {
        $tick = self::processWalkawayTick($dynamics, $npcName, $temperament, $isDialogue);
        $returned = false;
        if (($tick['state'] ?? '') === 'recovery') {
            self::executeAutonomousReturn($npcName, $dynamics);
            self::resetWalkawayState($dynamics);
            $dynamics['_walkaway_return_grace'] = max(0, intval(self::configValue('walkaway_return_grace_contacts')));
            $returned = true;
        }
        return ['state' => $dynamics['_walkaway_state'] ?? 'normal', 'returned' => $returned];
    }

    /** Toxic/Disorganized NPC below the hoover maturity cap, with the hoover enabled. */
    public static function isHooverSleeper($dynamics): bool
    {
        if (!(self::getConfig()['hoover_enabled'] ?? true)) {
            return false;
        }
        return ($dynamics['attachment_style'] ?? 'secure') === 'toxic'
            && floatval($dynamics['dimensions']['maturity']['x'] ?? 50) < self::HOOVER_MATURITY_CAP;
    }

    /**
     * Check if walkaway recovery conditions allow autonomous return.
     *
     * @param array $dynamics NPC dynamics
     * @return bool True if NPC should return
     */
    public static function checkWalkawayRecovery($dynamics)
    {
        $state = $dynamics['_walkaway_state'] ?? 'normal';
        return ($state === 'recovery');
    }

    /**
     * Force NPC to travel to their home location via rolecommand.
     *
     * @param string $npcName  NPC name
     * @param array  $dynamics NPC dynamics
     * @return bool True if command was injected
     */
    public static function executeWalkawaySelfDismiss($npcName, $dynamics)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return false;

            // Get NPC refid
            $escaped = $db->escape($npcName);
            $npcRow = $db->fetchOne("SELECT refid FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            if (empty($npcRow['refid'])) {
                self::log("[WALKAWAY] Cannot dismiss {$npcName}: no refid");
                return false;
            }

            $refid = $npcRow['refid'];

            // Determine destination
            $homeLocation = $dynamics['home_location'] ?? null;
            $actionCmd = '';

            if ($homeLocation) {
                // Travel to assigned home
                $escapedLoc = $db->escape($homeLocation);
                $locRow = $db->fetchOne("SELECT formid FROM locations WHERE name='{$escapedLoc}' LIMIT 1");
                if (!empty($locRow['formid'])) {
                    $refHex = self::convertRefIdToHex($refid);
                    $actionCmd = "rolecommand|BackgroundCmd@{$refHex}@TravelTo/{$locRow['formid']}";
                }
            }

            if (empty($actionCmd)) {
                // Fallback: use ReturnHome command
                $refHex = self::convertRefIdToHex($refid);
                $actionCmd = "rolecommand|BackgroundCmd@{$refHex}@ReturnHome/";
            }

            // Inject into responselog
            $db->insert('responselog', [
                'localts' => time(),
                'sent'    => 0,
                'actor'   => 'rolemaster',
                'text'    => '',
                'action'  => $actionCmd,
                'tag'     => '',
            ]);

            self::log("[WALKAWAY] Injected dismiss command for {$npcName}: {$actionCmd}");
            return true;

        } catch (\Throwable $e) {
            self::logError("walkaway dismiss {$npcName}", $e);
            return false;
        }
    }

    /**
     * Force NPC to return to player's location after recovery.
     *
     * @param string $npcName  NPC name
     * @param array  $dynamics NPC dynamics
     * @return bool True if command was injected
     */
    public static function executeAutonomousReturn($npcName, $dynamics)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return false;

            $escaped = $db->escape($npcName);
            $npcRow = $db->fetchOne("SELECT refid FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
            if (empty($npcRow['refid'])) {
                return false;
            }

            $refHex = self::convertRefIdToHex($npcRow['refid']);
            $actionCmd = "rolecommand|BackgroundCmd@{$refHex}@MoveToPlayer";

            $db->insert('responselog', [
                'localts' => time(),
                'sent'    => 0,
                'actor'   => 'rolemaster',
                'text'    => '',
                'action'  => $actionCmd,
                'tag'     => '',
            ]);

            self::log("[WALKAWAY] Autonomous return for {$npcName}: {$actionCmd}");
            return true;

        } catch (\Throwable $e) {
            self::logError("walkaway return {$npcName}", $e);
            return false;
        }
    }

    /**
     * Reset walkaway state back to normal. Used when NPC recovers.
     *
     * @param array &$dynamics NPC dynamics
     */
    public static function resetWalkawayState(&$dynamics)
    {
        $dynamics['_walkaway_state'] = 'normal';
        self::endDecayPause($dynamics);   // closes the pause interval (calculateDecayTicks drops it)
        unset(
            $dynamics['_walkaway_reason'],
            $dynamics['_walkaway_started_gamets'],
            $dynamics['_walkaway_activated_gamets'],
            $dynamics['_walkaway_boundary_test_hours'],
            $dynamics['_walkaway_player_followed'],
            $dynamics['_boundary_test_started_gamets'],
            $dynamics['_walkaway_recovery_gamets'],
            $dynamics['_walkaway_permanent_gamets'],
            $dynamics['_walkaway_started_calendar_gamets'],
            $dynamics['_walkaway_activated_calendar_gamets'],
            $dynamics['_boundary_test_started_calendar_gamets'],
            $dynamics['_walkaway_recovery_calendar_gamets'],
            $dynamics['_walkaway_permanent_calendar_gamets'],
            // legacy wall-clock stamps (pre-3.4.1 builds)
            $dynamics['_walkaway_started_at'],
            $dynamics['_walkaway_activated_at'],
            $dynamics['_boundary_test_started_at'],
            $dynamics['_walkaway_recovery_at'],
            $dynamics['_walkaway_permanent_at']
        );
    }

    /**
     * Convert a refid (from DB) to hex string for rolecommand injection.
     *
     * @param string $refid The refid from core_npc_master
     * @return string Hex string like "0x00012345"
     */
    public static function convertRefIdToHex($refid)
    {
        // refid may already be hex string or decimal
        if (is_string($refid) && strpos($refid, '0x') === 0) {
            return $refid;
        }
        $int = intval($refid);
        $unsigned = $int & 0xFFFFFFFF;
        return "0x" . str_pad(dechex($unsigned), 8, "0", STR_PAD_LEFT);
    }

    // ========== END WALKAWAY STATE MACHINE (PR 16) ==========

    // ========== HOOVER PROTOCOL (PR 16) ==========
    //
    // Toxic/Disorganized NPCs don't stay gone. After walkaway, they return
    // with a gaslighting charm offensive — wiping resentment, maxing passion.
    // MDD: "The abuse cycle. They hoover you back."
    // ==========================================================

    /**
     * Check if a Hoover return is eligible.
     *
     * @param array $dynamics NPC dynamics
     * @return bool True if hoover should trigger
     */
    public static function checkHooverEligibility($dynamics)
    {
        // Must be in walkaway state (active or boundary_test)
        $walkState = $dynamics['_walkaway_state'] ?? 'normal';
        if (!in_array($walkState, ['active', 'boundary_test'])) {
            return false;
        }

        // Must be toxic attachment with maturity below the cap
        $attachment = $dynamics['attachment_style'] ?? 'secure';
        if ($attachment !== 'toxic') {
            return false;
        }
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        if ($maturity >= self::HOOVER_MATURITY_CAP) {
            return false;
        }

        // Sleeper timer: 72-96 game-calendar hours since they left (decisions 2026-09-23 §2)
        $walkKey = !empty($dynamics['_walkaway_activated_calendar_gamets']) ? '_walkaway_activated_calendar_gamets' : '_walkaway_started_calendar_gamets';
        $walkStart = intval($dynamics[$walkKey] ?? 0);
        if ($walkStart === 0) {
            return false;
        }

        $elapsedHours = self::gameHoursSince($dynamics, $walkKey);
        if ($elapsedHours === null) {
            return false;
        }

        // Random window within min-max range (use deterministic seed from walkaway start)
        $hooverHours = self::HOOVER_MIN_HOURS
                     + (($walkStart % 100) / 100.0) * (self::HOOVER_MAX_HOURS - self::HOOVER_MIN_HOURS);

        return ($elapsedHours >= $hooverHours);
    }

    /**
     * Execute the Hoover Protocol — dimensional snap + return.
     *
     * @param array  &$dynamics NPC dynamics (modified in place)
     * @param string $npcName   NPC name
     * @param string $temperament NPC temperament
     * @return array Snap results
     */
    public static function executeHoover(&$dynamics, $npcName, $temperament = 'Stoic')
    {
        $snap = self::HOOVER_SNAP;
        $results = [];

        // Apply dimensional snap
        foreach ($snap as $dim => $target) {
            $current = floatval($dynamics['dimensions'][$dim]['x'] ?? 50);
            $delta = $target - $current;
            if ($dim === 'passion') {
                self::setPassion($dynamics, $target);
                $results[$dim] = ['from' => $current, 'to' => $target];
            } else {
                $dynamics['dimensions'][$dim]['x'] = floatval($target);
                $results[$dim] = ['from' => $current, 'to' => $target];
            }
        }

        // Track hoover history
        $dynamics['_hoover_count'] = intval($dynamics['_hoover_count'] ?? 0) + 1;
        self::markPlayCheckpoint($dynamics, '_hoover_last_gamets');
        unset($dynamics['_hoover_last_at']); // legacy wall-clock stamp
        $dynamics['_hoover_resentment_mult'] = self::HOOVER_RESENTMENT_REBUILD_MULT;

        // Reset walkaway state
        self::resetWalkawayState($dynamics);

        // Force NPC to return
        self::executeAutonomousReturn($npcName, $dynamics);

        self::log("[HOOVER] Executed for {$npcName}: snap=" . json_encode($results)
            . ", hoover_count={$dynamics['_hoover_count']}");

        return $results;
    }

    /**
     * Generate hoover-specific context for LLM injection.
     *
     * @param array  $dynamics NPC dynamics
     * @param string $npcName  NPC name
     * @return string|null Context text or null
     */
    public static function getHooverContext($dynamics, $npcName)
    {
        // Only inject for 48 real play hours after hoover
        $hoursSince = self::playHoursSince($dynamics, '_hoover_last_gamets');
        if ($hoursSince === null || $hoursSince > 48) {
            return null;
        }

        $hooverCount = intval($dynamics['_hoover_count'] ?? 0);
        $charm = ($hooverCount === 1)
            ? "sweet, attentive, exactly what the player wants to hear"
            : "performing the same charm offensive they've used before — the patterns are becoming visible";

        return "{$npcName} has returned acting as if nothing happened. {$charm}. "
             . "This is NOT genuine recovery. The underlying issues are buried, not resolved. "
             . "Resentment will rebuild faster this time.";
    }

    // ========== END HOOVER PROTOCOL (PR 16) ==========

    // ========== CORE AFFINITY BRIDGE (CHIM 3.4.1) ==========
    //
    // Core owns player affinity: core_npc_master.extended_data.relationships.Player.aff
    // (-100..+100). CHIM 3.4.1 keys the player's entry by the literal "Player"
    // (RelationshipManager::normalizeTargetName), never by the character's name; the
    // real name is only for display and prompts.
    //
    // dimensions.affinity.x (0..100) is a read-only mirror of the core value. RelDyn's own
    // affinity changes (eval deltas, absence decay, gifts, passion-gain bonus) are
    // queued in core units in _pending_aff_delta and applied by commitPlayerAffinity()
    // as a delta inside a transaction that holds core's per-NPC advisory lock
    // (1001000000 + npc id, the key relationship_system uses around applyChanges).
    // Core eval and RelDyn can both move affinity, up or down, and neither overwrites
    // the other. _aff_mirror_x records the mirrored value so only RelDyn's own change
    // (x - mirror) is queued, and it is queued once: the mirror is reset right after.

    const PLAYER_RELATIONSHIP_KEY = 'Player';
    const CORE_RELATIONSHIP_LOCK_BASE = 1001000000;
    const CORE_AFFINITY_MIN = -100;
    const CORE_AFFINITY_MAX = 100;

    private static function loadRelationshipManager()
    {
        if (!class_exists('RelationshipManager')) {
            require_once __DIR__ . '/../../lib/relationship_manager.php';
        }
    }

    /**
     * Canonical relationship-map key for a target name ("Player" for the player).
     * Also maps the prerequest snapshot of the player's name, because postrequest
     * may run after conf.php reset PLAYER_NAME.
     */
    public static function relationshipTargetKey($targetName)
    {
        self::loadRelationshipManager();
        $key = RelationshipManager::normalizeTargetName($targetName);
        $snapshotName = trim((string)($GLOBALS['RELDYN_PLAYER_NAME'] ?? ''));
        if ($key !== self::PLAYER_RELATIONSHIP_KEY && $snapshotName !== '' && strcasecmp(trim((string)$targetName), $snapshotName) === 0) {
            return self::PLAYER_RELATIONSHIP_KEY;
        }
        return $key;
    }

    public static function isPlayerRelationshipKey($targetName)
    {
        return self::relationshipTargetKey($targetName) === self::PLAYER_RELATIONSHIP_KEY;
    }

    /**
     * Relationship map as core reads it: legacy real-name player entries folded into "Player".
     */
    public static function normalizeRelationshipMap($relationships)
    {
        if (!is_array($relationships)) {
            return [];
        }
        self::loadRelationshipManager();
        $normalized = RelationshipManager::normalizeRelationshipMap($relationships);
        foreach (array_keys($normalized) as $target) {
            if ($target !== self::PLAYER_RELATIONSHIP_KEY && self::isPlayerRelationshipKey($target)) {
                if (!isset($normalized[self::PLAYER_RELATIONSHIP_KEY])) {
                    $normalized[self::PLAYER_RELATIONSHIP_KEY] = $normalized[$target];
                }
                unset($normalized[$target]);
            }
        }
        return $normalized;
    }

    /**
     * The player's relationship entry from a decoded core extended_data array, or null.
     */
    public static function getPlayerRelationshipFromExtended($extended)
    {
        if (!is_array($extended)) {
            return null;
        }
        $rels = self::normalizeRelationshipMap($extended['relationships'] ?? []);
        return $rels[self::PLAYER_RELATIONSHIP_KEY] ?? null;
    }

    /**
     * The player's relationship entry for an NPC (core row), or null.
     */
    public static function getPlayerRelationship($npcName)
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || empty($npcName)) {
            return null;
        }
        $escaped = $db->escape($npcName);
        $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
        if (!is_array($row) || empty($row['extended_data'])) {
            return null;
        }
        return self::getPlayerRelationshipFromExtended(json_decode($row['extended_data'], true));
    }

    /**
     * Set the read-only affinity mirror from a core aff value (-100..+100 -> 0..100).
     */
    public static function refreshAffinityMirror(&$dynamics, $coreAff)
    {
        if (!isset($dynamics['dimensions'])) {
            $dynamics['dimensions'] = [];
        }
        if (!isset($dynamics['dimensions']['affinity'])) {
            $dynamics['dimensions']['affinity'] = ['x' => 0, 'baseline' => null];
        }
        $mirror = round((floatval($coreAff) + 100) / 2.0, 2);
        $dynamics['dimensions']['affinity']['x'] = $mirror;
        $dynamics['_aff_mirror_x'] = $mirror;
    }

    /**
     * Queue an affinity change in core units (-100..+100 scale) for commitPlayerAffinity().
     */
    public static function queueAffinityDelta(&$dynamics, $coreDelta)
    {
        $dynamics['_pending_aff_delta'] = round(floatval($dynamics['_pending_aff_delta'] ?? 0) + floatval($coreDelta), 4);
    }

    /**
     * Push RelDyn's pending affinity change to core.
     *
     * Moves any change RelDyn made to dimensions.affinity.x since the last mirror into
     * the pending queue (dimension units x2 = core units), then applies the whole-point
     * part of the queue as a locked delta on relationships.Player.aff. The fraction
     * stays queued. On success the mirror is refreshed from the value core now holds.
     *
     * @return array|null ['old' => int, 'new' => int, 'delta' => int] or null if nothing was written
     */
    public static function commitPlayerAffinity($npcName, &$dynamics)
    {
        $marker = $dynamics['_aff_mirror_x'] ?? null;
        $x = $dynamics['dimensions']['affinity']['x'] ?? null;
        if ($marker !== null && $x !== null && abs(floatval($x) - floatval($marker)) > 0.0001) {
            self::queueAffinityDelta($dynamics, (floatval($x) - floatval($marker)) * 2.0);
            $dynamics['dimensions']['affinity']['x'] = floatval($marker);
        }

        $pending = floatval($dynamics['_pending_aff_delta'] ?? 0);
        $whole = (int)$pending; // truncate toward zero; the fraction waits for the next change
        if ($whole === 0) {
            return null;
        }

        $result = self::applyPlayerAffinityDelta($npcName, $whole);
        if ($result === null) {
            return null; // keep the delta queued; logged by applyPlayerAffinityDelta
        }

        // Drop what was requested (a clamped remainder at +/-100 is not retried forever).
        // A user-locked NPC drops the whole queue: holding it back would land it the
        // moment the lock is lifted, over the value the user pinned.
        $dynamics['_pending_aff_delta'] = !empty($result['locked']) ? 0.0 : round($pending - $whole, 4);
        self::refreshAffinityMirror($dynamics, $result['new']);
        if (self::configValue('conflict_enabled')) {
            self::observeCoreAffinity($dynamics, floatval($result['new']), self::currentGamets());   // conflict/repair
        }
        return $result;
    }

    /**
     * Apply a delta to core relationships.Player.aff atomically.
     *
     * Runs in one transaction holding pg_advisory_xact_lock(1001000000 + npc id), which
     * serialises with core relationship_system's pg_advisory_lock on the same key. Only
     * relationships.Player.aff is written (the whole relationships object only when the
     * Player entry is missing or a legacy real-name entry must be folded into it).
     * Nothing is written when extended_data.relationships_locked is set (editor lock).
     *
     * @return array|null ['old' => int, 'new' => int, 'delta' => int] (plus 'locked' => true
     *                    when skipped for the editor lock) or null on failure
     */
    public static function applyPlayerAffinityDelta($npcName, $delta)
    {
        $delta = (int)$delta;
        $db = $GLOBALS['db'] ?? null;
        if (!$db || empty($npcName) || $delta === 0) {
            return null;
        }

        // Same deterministic name -> id resolution as RelDyn's own storage, so the core
        // lock and write target the row whose plugin data holds this NPC's dynamics.
        $npcId = intval(RelDynStorage::resolveNpcId($npcName) ?? 0);
        if ($npcId <= 0) {
            error_log("[RelDyn-AFF] Cannot apply affinity delta {$delta}: no core_npc_master row for {$npcName}");
            return null;
        }
        $lockId = self::CORE_RELATIONSHIP_LOCK_BASE + $npcId;

        try {
            if ($db->execQuery("BEGIN") === false) {
                error_log("[RelDyn-AFF] BEGIN failed for {$npcName}");
                return null;
            }
            if ($db->execQuery("SELECT pg_advisory_xact_lock({$lockId})") === false) {
                throw new RuntimeException("advisory lock {$lockId} failed");
            }

            $locked = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE id = {$npcId} FOR UPDATE");
            $extended = json_decode($locked['extended_data'] ?? '{}', true);
            if (!is_array($extended)) {
                throw new RuntimeException("extended_data is not valid JSON");
            }

            $rawRels = $extended['relationships'] ?? [];
            $rels = self::normalizeRelationshipMap($rawRels);
            $playerRel = $rels[self::PLAYER_RELATIONSHIP_KEY] ?? ['aff' => 0, 'type' => 'neutral'];
            $oldAff = intval($playerRel['aff'] ?? 0);

            // USER LOCK: the relationship editor pinned this NPC's relationships. Core's
            // writers (applyChanges, saveRelationships) skip such NPCs; so does RelDyn.
            if (!empty($extended['relationships_locked'])) {
                if ($db->execQuery("COMMIT") === false) {
                    throw new RuntimeException("COMMIT failed");
                }
                self::log("[AFF] SKIP {$npcName} -> Player " . sprintf('%+d', $delta) . ": relationships_locked (manual edits protected, aff stays {$oldAff})");
                return ['old' => $oldAff, 'new' => $oldAff, 'delta' => 0, 'locked' => true];
            }

            $newAff = max(self::CORE_AFFINITY_MIN, min(self::CORE_AFFINITY_MAX, $oldAff + $delta));

            $hasLegacyKey = false;
            foreach (array_keys(is_array($rawRels) ? $rawRels : []) as $target) {
                if ($target !== self::PLAYER_RELATIONSHIP_KEY && self::isPlayerRelationshipKey($target)) {
                    $hasLegacyKey = true;
                    break;
                }
            }

            if (!$hasLegacyKey && is_array($rawRels) && isset($rawRels[self::PLAYER_RELATIONSHIP_KEY]) && is_array($rawRels[self::PLAYER_RELATIONSHIP_KEY])) {
                $path = '{relationships,' . self::PLAYER_RELATIONSHIP_KEY . ',aff}';
                $value = json_encode($newAff);
            } else {
                // jsonb_set cannot create intermediate keys: write the relationships object
                $playerRel['aff'] = $newAff;
                $rels[self::PLAYER_RELATIONSHIP_KEY] = $playerRel;
                $path = '{relationships}';
                $value = json_encode((object)$rels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $valueEscaped = $db->escape($value);
            $updated = $db->execQuery("UPDATE core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{$path}', '{$valueEscaped}'::jsonb, true) WHERE id = {$npcId}");
            if ($updated === false) {
                throw new RuntimeException("affinity UPDATE failed");
            }
            if ($db->execQuery("COMMIT") === false) {
                throw new RuntimeException("COMMIT failed");
            }
        } catch (\Throwable $e) {
            $db->execQuery("ROLLBACK");
            error_log("[RelDyn-AFF] Affinity delta {$delta} for {$npcName} rolled back: " . $e->getMessage());
            return null;
        }

        // Same game-timeline snapshot core writes after relationship changes
        if (function_exists('chimRelationshipTimelineStamp')) {
            chimRelationshipTimelineStamp($npcId);
        }

        self::log("[AFF] {$npcName} -> Player: " . sprintf('%+d', $delta) . " (aff {$oldAff} -> {$newAff})");
        return ['old' => $oldAff, 'new' => $newAff, 'delta' => $newAff - $oldAff];
    }

    // ========== END CORE AFFINITY BRIDGE (CHIM 3.4.1) ==========

}

// Facets -> appraisal -> feeling (decisions 2026-09-23 §6); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_facets.php';
