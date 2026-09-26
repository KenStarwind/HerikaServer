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
require_once __DIR__ . '/reldyn_traits.php';
require_once __DIR__ . '/reldyn_trait_read.php';
require_once __DIR__ . '/reldyn_trait_assign.php';

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

    // A2 bleedout: the temperament drain table (Anxious -3.0 .. Defiant +1.0) is retired in traits
    // phase 3. The fall is a trait outcome now (RelDynTraits::bleedout, bleedoutResponse()):
    // passion, valence and arousal from fight = C Pd (1 - D) and fear = L (1 - C).


    // Temperament → reunion multiplier: MDD 1.3 Reunion column. Traits phase 3 (design §2.2,
    // attachment de-duplication): Anxious's MDD 1.8 was its trait model (1.31) plus an anxiety
    // bump; the bump moved to attachment anxiety (attachment modifiers 'reunion_mult', anxious
    // x1.4), so the Anxious row is the model value. An Anxious NPC with an anxious attachment
    // gets 1.31 x 1.4 = 1.83.
    const TEMPERAMENT_REUNION_MULT = [
        'Romantic'    => 1.5,
        'Anxious'     => 1.31,
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

    // Temperament → jealousy multiplier: MDD 1.3 Jealousy column. Traits phase 3 (design §2.1
    // A4): Anxious's anxiety part (MDD 1.5 = its possessiveness model 1.39 + 0.11) is dropped:
    // the attachment jealousy_mult (anxious x2.0) already counts it.
    const TEMPERAMENT_JEALOUSY_MULT = [
        'Romantic'    => 1.3,
        'Anxious'     => 1.39,
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
    // The play clock counts played GAME time (raw gamets, waits/sleeps/travel/loads cut out:
    // beatPlayClock). Normal gameplay at 20:1 time compression is ~2315 gamets per real
    // second, so durations written as "real minutes of play" are that many play gamets:
    // GAMETS_PER_DECAY_TICK = 10 real minutes of normal gameplay:
    //   600 real seconds * 2315 gamets/sec = 1,389,000 gamets.

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

    /** The glow after a fight (MDD §3.3 post-combat context): 5 min of real play on the game clock. */
    const POST_COMBAT_GLOW_GAMETS = self::COMBAT_KILL_STREAK_WINDOW_GAMETS;

    /** Recent gift/consume window on the eventlog game clock: 30 s of real play. */
    const ITEM_EVENT_WINDOW_GAMETS = 69450; // 30 * GAMETS_PER_REAL_SECOND

    // ========== DIVINE INTERVENTION CONSTANTS (PR 10) ==========
    /** Minimum play gamets between DI checks (one decay tick = ~10 real min). */
    const DI_COOLDOWN_GAMETS = 1389000; // same as GAMETS_PER_DECAY_TICK

    /**
     * April's unstable window: 24 real hours of play time (the debug harness still builds windows
     * with it). The window now runs on the game calendar: config protocols.divine.
     */
    const UNSTABLE_WINDOW_GAMETS = 200016000; // 24 * GAMETS_PER_REAL_HOUR

    // Grief phases: config protocols.grief.phase_game_hours (game calendar, reldyn_protocols.php).

    // Attachment (MDD 6.1) is two axes now (decisions 2026-09-24 §12): see the ATTACHMENT: TWO
    // AXES section and config 'attachment' (attachmentDefaults). The April temperament ->
    // attachment map is gone: Guarded/Stoic/Independent/Proud/Defiant were not avoidant.

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

    /** Config 'baseline_drift' defaults (units in defaultConfig()). */
    const BASELINE_DRIFT_DEFAULTS = [
        'dimensions' => ['affinity', 'trust', 'comfort', 'respect', 'maturity'],
        'rate' => self::BASELINE_DRIFT_RATE,
        'max_from_origin' => self::BASELINE_DRIFT_MAX,
        'min_samples' => self::BASELINE_DRIFT_MIN_SAMPLES,
        'tolerance' => self::BASELINE_DRIFT_TOLERANCE,
        'window' => 5,
    ];

    /** Config 'per_bond_display' defaults (units in defaultConfig()). */
    const PER_BOND_DISPLAY_DEFAULTS = [
        'affinity_bonus_max' => 0.15,
        'type_curve_exponent' => ['passion' => 0.5, 'warmth' => 0.5, 'respect' => 0.5, 'trust' => 0.75, 'comfort' => 0.75],
        // Rulings 2026-09-25 §18 #8: these dimensions saturate instead of clamping when the
        // multiplier lifts them (mult > 1): range x (1 - (1 - x/range)^mult), so a partner's
        // comfort and trust keep their shape near the top (a hard clamp read every partner above
        // raw ~46 as 100). A multiplier at or below 1 stays linear (x x mult never clamps).
        'saturating' => ['trust', 'comfort'],
    ];

    /** Config 'self_confidence' defaults (units in defaultConfig()). */
    const SELF_CONFIDENCE_DEFAULTS = ['arrogant_confidence_above' => 75.0, 'arrogant_maturity_below' => 30.0];

    // ========== INTERNAL WEATHER / DEPRIVATION ==========
    // Weather, its modifiers and deprivation live in RelDynFacets (config facet_appraisal).

    // ========== VAMPIRE/WEREWOLF MOODIFICATIONS (PR 13) ==========
    // Rows, moon and detection live in RelDynCreatures (config 'creatures', reldyn_creatures.php).

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
    // Pre-contact baseline modifier (reldyn_reputation.php, RelDynReputation): fame / infamy /
    // status from the player profile, fading with meaningful interactions.
    // =============================================================

    /**
     * Per-dimension caps for reputation modifiers.
     * No single source or combination can push a dimension beyond these bounds.
     */
    const REPUTATION_CAPS = [
        'trust'   => ['min' => -20, 'max' => 10],
        'respect' => ['min' => -10, 'max' => 20],
        'comfort' => ['min' => -15, 'max' => 10],
    ];

    /** Respect points a shared / respected faction is worth (calculateFactionReputation). */
    const FACTION_RANK_RESPECT = 8.0;

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

    /**
     * Identity of the open request scope (null outside one), so request-scoped caches kept by
     * other RelDyn classes (RelDynPlayer::profile) expire exactly when this scope does.
     */
    public static function requestScopeToken(): ?string
    {
        return self::inRequestScope() ? self::$requestScopePid . ':' . self::$requestScopeStartedAt : null;
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
     * Schema 3 (rulings 2026-09-25 §18 #10): rows older than it were written before the eval's
     * 'confessing' / 'forgiveness' tags existed, so the tag lists they store lack them;
     * loadStoredConfig() adds them (CONFIG_TAGS_ADDED_V3). A row stamped 3 or later is a choice.
     */
    const CONFIG_SCHEMA = 3;

    /**
     * Tag lists rulings 2026-09-25 §18 #10 extended ('confessing' split from 'confiding', the
     * player's 'forgiveness'): stored-config path => the tags added. A list of tags gains the
     * missing ones; a table keyed by tag (fulfillment.tag_delivery) gains their default rows. A
     * path the stored row does not hold is left to the section's default.
     */
    const CONFIG_TAGS_ADDED_V3 = [
        'protocols.parasite.genuine_tags'        => ['confessing', 'forgiveness'],
        'intrinsic_goals.eval_tags.bond_seeking' => ['confessing'],
        'attraction.emotional_passion.tags'      => ['confessing'],
        'fulfillment.tag_delivery'               => ['confessing', 'forgiveness'],
    ];

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
        $schema = intval($stored['config_schema'] ?? 1);
        if ($schema < 2) {
            // Pre-fix row: its toggles are all true by the isset() bug, not by choice.
            $stored = array_diff_key($stored, array_flip(self::CONFIG_FORM_TOGGLES));
        }
        if ($schema < 3) {
            $stored = self::addRulingTags($stored);
        }
        // Migrated in memory: a settings-page save stores it with the current stamp
        if ($schema < self::CONFIG_SCHEMA) $stored['config_schema'] = self::CONFIG_SCHEMA;
        return $stored;
    }

    /**
     * A row written before rulings 2026-09-25 §18 #10 (config_schema < 3): add the rulings' tags to
     * the tag lists it stores (CONFIG_TAGS_ADDED_V3), without touching anything it chose.
     */
    private static function addRulingTags(array $stored): array
    {
        $defaults = self::defaultConfig();
        foreach (self::CONFIG_TAGS_ADDED_V3 as $path => $tags) {
            $keys = explode('.', $path);
            $list = $stored;
            $default = $defaults;
            foreach ($keys as $k) {
                $list = is_array($list) && array_key_exists($k, $list) ? $list[$k] : null;
                $default = is_array($default) && array_key_exists($k, $default) ? $default[$k] : null;
            }
            if (!is_array($list)) continue;
            foreach ($tags as $tag) {
                if (array_is_list($list)) {
                    if ($list !== [] && !in_array($tag, $list, true)) $list[] = $tag;
                } elseif (!array_key_exists($tag, $list) && is_array($default) && array_key_exists($tag, $default)) {
                    $list[$tag] = $default[$tag];
                }
            }
            $ref = &$stored;
            foreach ($keys as $k) $ref = &$ref[$k];
            $ref = $list;
            unset($ref);
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
            'type_filter_enabled' => true,           // relationship preference filter in the Attraction Matrix (reldyn_attraction.php)
            // XYZ Dimension Engine — on by default: the MDD section 15 eval signals,
            // modifier pipeline and resentment accumulator all run through it.
            'dimension_engine_enabled' => true,
            // Dimension state reaches the LLM as band keywords (felt steering, never numbers;
            // decisions 2026-09-23 section 3).
            'dimension_context_enabled' => true,
            'dimension_debug_logging' => false,
            'dimension_max_context_lines' => 10,
            // Felt steering (reldyn_felt.php): one <subtext> block after the dialogue history, tiered
            // and token-budgeted; behavioral keywords, subtext and intensity formatting, never numbers.
            'felt_steering' => RelDynFelt::configDefaults(),
            // context_pre.php (feedback_context_engineering_v2): <knowledge_of_player> by tier and
            // the emotional core inside <character> (primacy). Off: the <subtext> block carries all.
            'context_pre_enabled' => true,
            // Diary reflection mode (feedback_diary_system): 'baseline' (snapshot math, no LLM) or
            // 'trajectory' (one LLM call reads the NPC's recent entries in core's diary)
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
            // Attraction Matrix tables (MDD §2 / §1.4 / §6.2, decisions §9; reldyn_attraction.php)
            'attraction' => RelDynAttraction::defaults(),
            // Relationship preference -> jealousy multiplier (pipeline doc: monogamous 2x,
            // polyamorous 0.1x; not interested: no jealousy)
            // aromantic: no romantic rival (traits design §1.7), so no jealousy
            'preference_jealousy_mult' => ['monogamous' => 2.0, 'polyamorous' => 0.1, 'not_interested' => 0.0, 'aromantic' => 0.0],
            // PR 12: Affinity Network + Relationship Types
            'cascade_network_enabled' => true,
            'cascade_threshold' => 15,               // core affinity points (|delta| that ripples)
            'cascade_decay' => 0.3,                  // fraction
            'duty_override_enabled' => true,
            // Quests (reldyn_quests.php): the duty override's dampening and hostility tests, the
            // questlog consumer of the quest event hook, life-changing stages
            'quests' => RelDynQuests::configDefaults(),
            // Intrinsic goals, tier 1 (reldyn_goals.php, MDD 14.2)
            'intrinsic_goals' => RelDynGoals::configDefaults(),
            // Reputation: the pre-contact offset from fame / infamy / status (reldyn_reputation.php)
            'reputation' => RelDynReputation::configDefaults(),
            // Prompt gating: who knows the player (name, story, rumours by hold; reldyn_gating.php)
            'prompt_gating' => RelDynGating::configDefaults(),
            // Item modifiers (PR 8, item-modifiers): appraised dimensions, eventlog rows per request
            'item_modifiers' => self::ITEM_MODIFIER_DEFAULTS,
            'parasite_detection_enabled' => true,
            // PR 13: Environmental Quirks
            'baseline_drift_enabled' => true,
            // Baseline drift (audit #57, PR 13; decisions §2 "time does not heal, contact does"):
            // on contact, one sample per game-calendar day of each listed dimension (its x;
            // affinity in core units -100..100, as its baseline); at the diary eval, when the last
            // min_samples samples all sit more than tolerance points on one side of the baseline,
            // the GLOBAL baseline moves rate x (their average - baseline), at most max_from_origin
            // points from where it started (the seed, or the value an editor / arc last set).
            // window = samples (game days) kept. Warmth is not listed (recap 2026-03-31 Fix 5).
            'baseline_drift' => self::BASELINE_DRIFT_DEFAULTS,
            // Per-bond display multiplier (feedback_baseline_vs_perbond, feedback_perbond_tuning):
            // what a per-bond dimension reads as toward the player, at display time only (never
            // stored): x x (1 + affinity_bonus_max x core affinity 0..100 / 100) x type^exponent,
            // type = RELATIONSHIP_TYPE_MODIFIERS[bond type][dimension] (unitless). The exponents
            // soften the type table (session 2026-03-30: sqrt; recap 2026-03-31: pow 0.75 for
            // trust and comfort). Tension checks (knowledge bridges, emergent emotions) read RAW x.
            'per_bond_display' => self::PER_BOND_DISPLAY_DEFAULTS,
            // Self-confidence cross-effect (dimension draft, Dimension 11): above
            // arrogant_confidence_above with maturity below arrogant_maturity_below (points
            // 0..100) the self-confidence band speaks as arrogance (felt text
            // self_confidence_arrogant) instead of its band keywords.
            'self_confidence' => self::SELF_CONFIDENCE_DEFAULTS,
            'internal_weather_enabled' => true,
            'creature_moodifications_enabled' => true,
            'emergent_emotions_enabled' => true,
            'significance_scaling_enabled' => true,
            // PR 14: Social Masking + Autonomous Diary
            // Off by default (shipped that way while <social_mask> printed numbers; its text is felt
            // steering now, generateMaskingContext): turning it on is a gameplay call.
            'social_masking_enabled' => false,
            'autonomous_diary_enabled' => true,
            'diary_interaction_gap' => 15,           // interactions
            'mask_maturity_cost' => 0.15,            // maturity points per masked interaction (the eval's masking.flag)
            // Who wears the Mask (MDD 11 "High Status Priority + Toxic/Avoidant"; shouldMask):
            // maturity (0..100 points) at least maturity_min (a mask needs composure), an attachment
            // style (getAttachmentStyle, the axes' region) in attachment_styles, the status trait
            // (RelDynTraits code) at least status_min (0..1; 'Pd' pride: status matters to her;
            // "high" = above the 0.5 a bio without evidence reads; reads centre, decisions §16 #10),
            // and someone present whose core affinity (-100..100) with her is below
            // trusted_affinity_min (or who has no bond row).
            'social_masking' => [
                'maturity_min' => 25.0,
                'attachment_styles' => ['avoidant', 'toxic'],
                'status_trait' => 'Pd',
                'status_min' => 0.55,
                'trusted_affinity_min' => 0.0,
            ],
            // PR 15: Social Sensitivity + Ick + Charisma
            'social_sensitivity_enabled' => true,
            // Eval signals scaled by the NPC's social sensitivity curve at the bond level (core
            // affinity toward the player, 0..100): how much the player's words land. Affinity
            // (the bond itself) and passion (the attraction spark / uphill owns "who") are not
            // listed; global dimensions are never scaled.
            'social_sensitivity_signals' => ['trust', 'comfort', 'respect'],
            'ick_system_enabled' => true,
            'ick_base_threshold' => 0.5,             // fraction of romantic attempts in the window
            'charisma_detection_enabled' => true,
            // Charisma (MDD 5.1, rulings §18 #11): the player's style from the eval's charisma
            // grades (window / min_samples = graded exchanges, min_share 0..1 of the window;
            // CHARISMA_DEFAULTS, charismaConfig)
            'charisma' => self::CHARISMA_DEFAULTS,
            // PR 16: Autonomy Override + Walkaway + Hoover
            'autonomy_enabled' => true,
            // Autonomy override (MDD 6.4, PR16 plan, autonomy design memory): the autonomy score
            // (0..100) = (100 - trust) x distrust + (100 - respect) x disrespect + resentment x
            // resentment + self_confidence x self_confidence + maturity_mod (-0.5 below maturity
            // 30, +0.5 above 60) x 100 x maturity_mod (dimension points); the state thresholds
            // (score: below compliant = compliant, below resistant = resistant, below refusing =
            // refusing, else walkaway); per state, the core action codes (functions/functions.php
            // ENABLED_FUNCTIONS) taken off the LLM's list for that NPC (ext functions.php hook). A
            // stored 'weights' / 'thresholds' / 'denied_actions' replaces that table whole.
            'autonomy' => self::AUTONOMY_DEFAULTS,
            'walkaway_enabled' => true,              // off: no walkaway starts (evaluateAutonomyState stops at refusing; one under way carries on)
            'hoover_enabled' => true,
            // PR 39: Director-Assigned Goals (the hooks ran them unless switched off)
            'director_goals_enabled' => true,
            // How long a goal stays active, in PLAY hours on the NPC's play clock
            // (_accumulated_play_gamets / GAMETS_PER_REAL_HOUR; waits, sleeps and time away never
            // age it), by source (PR 39 plan: 1 h director, 2 h background life). The prerequest
            // bridge of core's HERIKA_GOALS keeps its 2 h and background priority (0..1).
            'director_goals' => [
                'max_age_play_hours' => ['director' => 1.0, 'bgl' => 2.0],
                'bridge_max_age_play_hours' => 2.0,
                'bridge_priority' => 0.4,
            ],
            // Temperament / maturity-type / trait auto-generation tables
            'temperament_autogen' => self::temperamentAutogenDefaults(),
            // Personality traits (D:\docs\reldyn-personality-traits-design.md): assignment 'read'
            // (phase 2: override > preset > bio read over priors > priors) or 'label' (phase 1
            // legacy: the old vote's preset point); residual_reach = Rule R reach (trait-space distance)
            'traits' => ['assignment' => RelDynTraits::ASSIGNMENT, 'residual_reach' => RelDynTraits::RESIDUAL_REACH],
            // The bio trait read (reldyn_trait_read.php): its own queue, drained after the eval
            'trait_reader' => RelDynTraitRead::defaultConfig(),
            // Attachment on two axes (decisions 2026-09-24 §12): derivation, style regions,
            // drift (Earned Security), style modifier rows, felt text (attachmentDefaults)
            'attachment' => self::attachmentDefaults(),
            // Signed facet preferences (-1..+1) auto-derivation tables (decisions §6, reldyn_facets.php)
            'facet_preferences' => RelDynFacets::preferenceDefaults(),
            // Appraisal: felt read, effects, internal weather (decisions §6, reldyn_facets.php)
            'facet_appraisal' => RelDynFacets::appraisalDefaults(),
            // ===== Time (decisions 2026-09-23 §2: time does not heal, contact does) =====
            // Contacts (this NPC's requests) an NPC back from a resolved boundary test
            // waits before walking away again while its resentment is still high.
            'walkaway_return_grace_contacts' => 5,
            // Pursuit (MDD 6.4 "follow them", rulings §8): for a NEGLECT walkaway (one that
            // starts on the player's return, walkawayReason), the player's lines in the first
            // walkaway_parting_game_minutes game-calendar minutes after the NPC left are the
            // parting conversation (the return greeting that set it off, a plea as they go),
            // not following them. Talking to them after that, while the walkaway lasts, is
            // seeking them out: the boundary test fails. Other walkaways get no window.
            // 60 game minutes = 3 real minutes at the default timescale 20.
            'walkaway_parting_game_minutes' => 60,
            // MDD 6.5: core affinity (-100..100) at or below walkaway_affinity_at -> walkaway, in a
            // bond that existed: the NPC's context-tier high-water mark (0 stranger .. 3 bonded)
            // reached walkaway_affinity_min_tier (Serene: 2, a friend once; a stranger insulted
            // down to -20 is not a relationship rotting).
            'walkaway_affinity_at' => -20,
            'walkaway_affinity_min_tier' => 2,
            // MDD 6.5 "permanently severs" (PR16 plan: the type snaps to ex-bonded): a permanent
            // walkaway sets the hard reject_recruitment flag and writes core's Player.type from
            // the bond type (key) to its severed form (core relationship types; 'ex' = former
            // romantic partner, 'estranged' = broken familial / platonic bond). Types not listed
            // are left as they are.
            'walkaway_sever_types' => [
                'romantic' => 'ex', 'crush' => 'ex', 'platonic' => 'estranged', 'protective' => 'estranged',
                'admirer' => 'estranged', 'familial' => 'estranged',
            ],
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
            // The fall of bleedout (A2, traits phase 3; design §2.5, MDD 1.3 combat notes and the MDD
            // bleedout section; RelDynTraits::bleedout): net = fight - fear (unitless), with
            //   fight = rage_weight x C Rs L (1 - D)                      (Bold / Defiant / Aela: RAGE)
            //   fear  = panic_weight x (1 - C) max(L, attachment anxiety)  (Anxious terror; Guarded /
            //           Gentle existential) + humiliation_weight x egocentric(Pd) (the proud) +
            //           shame_weight x smoothstep(avoidance; shame_avoidance) (avoidant: ashamed of
            //           needing help)
            //   passion = passion_per_net x net (passion points, clamped -5..+5; |passion| below
            //             dead_band does nothing): a positive net fights harder (gainPassion, the
            //             attraction route), a negative one drains (setPassion)
            //   valence = valence_per_net x net (valence points): the sign of fight - fear
            //   arousal = arousal_base x (0.5 + L) (arousal points): every fall is a spike
            // Calibration (Serene, for the MDD notes): passion_per_net 5.22 keeps the Anxious preset's
            // MDD panic (-3.0 passion points); valence_per_net 80 puts the Guarded and Gentle presets'
            // "deep negative-valence spike" at the injured spike (-20 valence points) while the
            // Independent preset stays minimal (-3); rage_weight 2 makes the Bold and Defiant presets
            // fight (passion up); humiliation_weight 0.3 keeps the Proud preset's drain near its old
            // -2.0; shame_weight 0.4 is the avoidant fall.
            // No trait vector: passion no_vector_passion, no valence or arousal (today's drain).
            'bleedout_response' => ['rage_weight' => 2.0, 'panic_weight' => 1.0, 'humiliation_weight' => 0.3,
                                    'shame_weight' => 0.4, 'shame_avoidance' => [0.35, 0.65],
                                    'passion_per_net' => 5.22, 'valence_per_net' => 80.0, 'arousal_base' => 20.0,
                                    'dead_band' => 0.05, 'no_vector_passion' => -1.5],
            // Warmth fades with absence too (decisions §2, rulings §8): after
            // warmth_absence_grace_game_hours x the NPC's neglect grace_mult without contact,
            // warmth (0..100) above its baseline loses warmth_absence_fade_per_game_day x the
            // NPC's neglect rate_mult per game-calendar day (getNeglectProfile, the same per-NPC
            // scaling as neglect), down to the baseline (never below: absence does not deepen
            // coldness either). Starting value 1.0: warmth is the slow, deep state, a third of
            // passion's rate.
            'warmth_absence_grace_game_hours' => 24,
            'warmth_absence_fade_per_game_day' => 1.0,
            // Global neglect (decisions §2): per RelDyn bond type (getRelationshipType), game days
            // without contact before neglect starts, and RAW resentment (0..100 points, through
            // applyDelta like fester) per game day after that. Types not listed never accrue.
            // These are the rates of a typical NPC (neglect_severity multipliers 1.0); each NPC
            // scales them by who it is (rulings §8, getNeglectProfile).
            'neglect_enabled' => true,
            'neglect_bond_types' => [
                'bonded'     => ['grace_game_days' => 3, 'resentment_per_game_day' => 1.0],
                'crush'      => ['grace_game_days' => 4, 'resentment_per_game_day' => 0.75],
                'sworn'      => ['grace_game_days' => 5, 'resentment_per_game_day' => 0.5],
                'friend'     => ['grace_game_days' => 7, 'resentment_per_game_day' => 0.25],
                'friendzone' => ['grace_game_days' => 7, 'resentment_per_game_day' => 0.25],
                'parasite'   => ['grace_game_days' => 2, 'resentment_per_game_day' => 0.5],
            ],
            // Per-NPC severity of neglect and warmth fade (rulings 2026-09-24 §8), see
            // neglectSeverityDefaults() / getNeglectProfile() for the formula.
            'neglect_severity' => self::neglectSeverityDefaults(),
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
            // Trust damps possessive jealousy STRONGLY (traits design §1.1, §1.4; decisions §14):
            // every jealousy gain x clamp(1 - this x trust / 100, 0.3, 1), trust = the bond's
            // trust dimension (points 0..100). 0 = no damping. Protective concern is damped only
            // mildly (concern.trust_damping); neither damps the values count.
            'jealousy_trust_damping' => 0.7,
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
            // Physical-state bridges (detectPhysicalStates): the player's live HP ratio under which
            // the scene reads as 'injured' (April's vitals rule).
            'physical_states' => ['injured_health_ratio' => 0.3],
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
            // ===== Player profile (player-stats-pipeline, rulings 2026-09-24 §9) =====
            // Archetype / pillar tables over CHIM core's player data (reldyn_player.php).
            'player_profile' => RelDynPlayer::configDefaults(),
            // ===== Fulfillment coverage + mature boundary (rulings 2026-09-24 §9) =====
            // Needs vector, deliveries, decay, band thresholds, boundary windows, felt text
            // (reldyn_fulfillment.php, RelDynFulfillment::configDefaults()).
            'fulfillment' => RelDynFulfillment::configDefaults(),
            // ===== Protective concern + the values path of both channels (traits design §1) =====
            // Risk appraisal, concern gains, repetition counter, routes A/B, felt text
            // (reldyn_concern.php, RelDynConcern::configDefaults()).
            'concern' => RelDynConcern::configDefaults(),
            // ===== Resentment threshold events (MDD 15.5, dimension design resentment_self) =====
            // Confrontation at the NPC's threshold, resentment_self's thresholds and recovery,
            // cross-bond guilt bleed, felt text (reldyn_resentment.php, RelDynResentment::configDefaults()).
            'resentment_arc' => RelDynResentment::configDefaults(),
            // ===== Intimacy need per NPC (rulings 2026-09-24 §10) =====
            // Physical / emotional axes from a trait combo, fulfillment axes, deprivation text
            // (reldyn_intimacy.php, RelDynIntimacy::configDefaults()).
            'intimacy_need' => RelDynIntimacy::configDefaults(),
            // ===== Creature moodifications (feedback_creature_moodifications, decisions §7) =====
            // Detection, Skyrim's moon cycle, the night / day / moon rows, the return from
            // beast form, felt text (reldyn_creatures.php, RelDynCreatures::configDefaults()).
            'creatures' => RelDynCreatures::configDefaults(),
            // ===== Combat passion (MDD §3.3, roadmap combat-passion) =====
            // Witness / shared / danger multipliers, kill streak, core's death / bleedout rows
            // read from the eventlog (reldyn_combat.php, RelDynCombat::configDefaults()).
            'combat' => RelDynCombat::configDefaults(),
            // ===== Tiered governors (MDD §8, roadmap tiered-governors) =====
            // Passion floor / ceiling per relationship tier, the attracted NPC's raise, exempt
            // writers (reldyn_governors.php, RelDynGovernors::configDefaults()).
            'governors' => RelDynGovernors::configDefaults(),
            // ===== Natural exclusivity (decisions 2026-09-24 §17) =====
            // The pull toward the player (drive, disposition, title, weakening), bands, suitor
            // markers and damping, style rules, NPC-NPC felt text (reldyn_exclusivity.php).
            'exclusivity' => RelDynExclusivity::configDefaults(),
            // ===== Romance promotion + Sharmat handoff (rulings 2026-09-24 §9) =====
            // Ladder, moment thresholds, momentum per NPC (reldyn_romance.php).
            'romance_promotion' => RelDynRomance::configDefaults(),
            // ===== Save load (roadmap save-load-rollback) =====
            // What survives loading an earlier save, ledger checkpoints (reldyn_timeline.php).
            'save_load' => RelDynTimeline::configDefaults(),
            // ===== Self-reflection on core's diary (roadmap diary-trigger, diary-reflection-eval) =====
            // Depth, the verdicts' deltas, moments, the trajectory call (reldyn_diary.php).
            'diary_reflection' => RelDynDiary::configDefaults(),
            // ===== Core's request poll (roadmap prerequest-on-poll) =====
            // What each poll runs: the play heartbeat beat, the save-load reconcile (onPollRequest).
            'poll' => self::pollConfigDefaults(),
            // ===== Divine Intervention, grief / widow's lock, the Ick, the Parasite (P3) =====
            // Window and fork thresholds, grief phases and felt text, ick tuning, the transactional
            // ledger (reldyn_protocols.php, RelDynProtocols::configDefaults()).
            'protocols' => RelDynProtocols::configDefaults(),
        ];
    }

    /**
     * Rulings 2026-09-24 §8: neglect is per NPC. How hard an absence hits scales with who the
     * NPC is; the same scaling applies to warmth fading with absence. getNeglectProfile():
     *
     *   codependence c (0..1) = clamp( w x A[attachment] + (1 - w) x T[temperament]
     *                                  + sum of trait bumps, 0, 1 )
     *       w = codependence_attachment_weight; A: Avoidant 0 .. Secure 0.5 .. Anxious/Toxic 1
     *       at the style corners, blended at the NPC's attachment axes (attachmentBlend);
     *       T (traits phase 3, design §2.2 A20: the temperament table counted anxiety a second
     *       time and insecure +0.2 a third): possessiveness Po, the Jealous part,
     *         T = 0.5 - 0.5 (1 - smoothstep(Po; codependence_possessiveness.low))
     *                 + 0.5 smoothstep(Po; codependence_possessiveness.high)
     *       0 at Po <= 0.10 (Independent, Stoic), 0.5 across the middle, 1 at Po >= 0.90
     *       (Jealous); no trait vector: codependence_temperament_default. Independent/avoidant
     *       NPCs barely mind absence, codependent ones take it hard.
     *   pride p (0..1) = clamp( P[temperament] + sum of trait bumps, 0, 1 )
     *       Proud 0.5, egocentric +0.5 (Proud is egocentric by default, so a Proud NPC is 1.0).
     *       Being ignored is a slight.
     *   maturity term = (maturity - 50) / 50, maturity = dimensions.maturity.x (0..100), so
     *       -1..+1. Mature NPCs understand "life happens".
     *
     *   grace_mult = 2 ^ ( g_c x (2c - 1) + g_m x maturity term + g_p x p )
     *   rate_mult  = 2 ^ ( r_c x (2c - 1) + r_m x maturity term + r_p x p )
     *   each clamped to [mult_min, mult_max] (unitless).
     *
     * A coefficient of 1 doubles (or halves) the multiplier at the end of its term's range.
     * The typical NPC (c = 0.5, maturity 50, no pride) gets 1.0 and 1.0: the bond table rates.
     * Neglect: grace = neglect_bond_types grace_game_days x grace_mult (game days), raw
     * resentment per game day = resentment_per_game_day x rate_mult. Warmth fade: grace =
     * warmth_absence_grace_game_hours x grace_mult, fade per game day =
     * warmth_absence_fade_per_game_day x rate_mult.
     *
     * Rate alone only moves the day an absence maxes an NPC out (resentment never decays), so
     * neglect also has a per-NPC ceiling: the resentment (0..100 points) an absence ALONE can
     * carry this NPC to. It plateaus there; time does not heal it either.
     *
     *   ceiling = clamp( ceiling_base + P_c x (2c - 1) + P_m x maturity term + P_p x p, 0, 100 )
     *       P = ceiling_points (resentment points at the end of each term's range).
     *
     * Defaults: the typical NPC plateaus at 50 (hurt, not withdrawn); independent/avoidant
     * (c = 0) never reaches withdrawal (70) at any maturity or pride; only codependent
     * (c >= 0.7), immature (maturity < 50) NPCs reach the walkaway (90), pride widening the
     * maturity range (c = 1, e.g. Jealous on an anxious attachment, walks at maturity <= 12.5,
     * <= 42.5 with full pride). Fester (open conflict) and jealousy are not neglect: no ceiling.
     */
    public static function neglectSeverityDefaults(): array
    {
        return [
            'codependence_attachment' => ['avoidant' => 0.0, 'secure' => 0.5, 'anxious' => 1.0, 'toxic' => 1.0],
            'codependence_attachment_weight' => 0.6,
            // possessiveness (0..1) edges of the two smoothsteps of T (see above)
            'codependence_possessiveness' => ['low' => [0.10, 0.30], 'high' => [0.55, 0.90]],
            'codependence_temperament_default' => 0.5,   // T with no trait vector (unitless 0..1)
            'pride_temperament' => ['Proud' => 0.5],
            'pride_traits' => ['egocentric' => 0.5],
            // log2 coefficients: [codependence, maturity, pride]
            'grace_log2' => ['codependence' => -1.0, 'maturity' => 1.0, 'pride' => -0.5],
            'rate_log2' => ['codependence' => 1.0, 'maturity' => -1.0, 'pride' => 1.0],
            'mult_min' => 0.125,
            'mult_max' => 8.0,
            // Neglect ceiling (resentment points, 0..100): base + points x [codependence, maturity, pride] terms
            'ceiling_base' => 50.0,
            'ceiling_points' => ['codependence' => 25.0, 'maturity' => -20.0, 'pride' => 12.0],
        ];
    }

    /** The neglect severity tables: stored config per key, defaults for the rest. */
    public static function getNeglectSeverityConfig(): array
    {
        $defaults = self::neglectSeverityDefaults();
        $stored = self::configValue('neglect_severity');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /**
     * Decisions 2026-09-23 §1 (PROPOSED math): the initial M_modifiers table for affinity.
     *
     * A row matches an affinity delta on (sign, tags, when):
     *   'sign'     'gain' (delta > 0), 'loss' (delta < 0) or 'any'
     *   'tags'     the delta must carry at least one of these eval source tags; [] = any delta
     *   'when'     every condition must hold (AND):
     *                ['state' => S, 'op' => '<'|'<='|'>'|'>=', 'value' => number]  (S below)
     *                ['attachment' => secure|avoidant|anxious|toxic]   (MDD 6.1: a weight, not a
     *                    yes/no: how far the NPC sits toward that style's corner, attachmentWeights;
     *                    the row applies as 1 + w x (mult - 1), so a textbook NPC of the style gets
     *                    mult, one halfway gets half the effect)
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
                // saveDynamics() merges this copy's changes onto whatever is stored by then
                $merged[self::LOAD_TOKEN_KEY] = self::rememberLoadedBase($base, RelDynTimeline::loadGeneration());
                return $merged;
            }
            if (!empty($GLOBALS['db']) && RelDynStorage::resolveNpcId($npcName) !== null) {
                $defaults = self::defaultDynamics();
                $defaults[self::LOAD_TOKEN_KEY] = self::rememberLoadedBase(self::defaultDynamics(), RelDynTimeline::loadGeneration());
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
        // Personality traits, phase 1: trait_vector mirrors the temperament label being written
        $mine = RelDynTraits::syncStored($mine);

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
                    // A copy read before a save load (or before its reconcile) is not saved after it
                    // (save-load-rollback): its changes, clocks included, belong to the timeline
                    // the load discarded, or to state core is still restoring.
                    $gen = ($token !== null) ? (self::$loadedGenerations[$token] ?? null) : null;
                    $stale = self::staleCopy($token);
                    if ($stale['reason'] !== null) {
                        error_log("[RelDyn] saveDynamics for {$npcName}: {$stale['reason']}; its changes are dropped");
                        return false;
                    }
                    $gen = $stale['gen'] ?? $gen;
                    for ($attempt = 1; $attempt <= self::SAVE_MERGE_ATTEMPTS; $attempt++) {
                        $current = RelDynStorage::readKeyForUpdate($npcId, RelDynStorage::KEY_DYNAMICS);
                        if ($current === null) {
                            error_log("[RelDyn] saveDynamics for {$npcName}: core_npc_master row {$npcId} is gone");
                            return false;
                        }
                        $toWrite = $mine;
                        if ($base !== null) {
                            $theirs = self::normalizeStoredDynamics(is_array($current['value']) ? $current['value'] : null);
                            $toWrite = RelDynTraits::syncStored(self::syncLegacyFromDimensions(self::mergeDynamics($base, $mine, $theirs)));
                        }
                        if (RelDynStorage::setKeyIfUnchanged($npcId, RelDynStorage::KEY_DYNAMICS, $current['expected'], $toWrite)) {
                            $toWrite[self::LOAD_TOKEN_KEY] = self::rememberLoadedBase($toWrite, $gen);
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

    /**
     * Is a loaded copy (its LOAD_TOKEN_KEY token) stale against save loads? Read now
     * (RelDynTimeline::saveGate). Stale: a load happened after the copy was read (its
     * generation differs), or the newest load is not reconciled yet (core may still be
     * restoring; the copy belongs to no timeline yet). A copy without a known generation is
     * judged by the second rule only.
     *
     * @return array ['reason' => ?string (null = fresh), 'gen' => ?int the generation now]
     */
    private static function staleCopy(?string $token): array
    {
        $gen = ($token !== null) ? (self::$loadedGenerations[$token] ?? null) : null;
        $gate = RelDynTimeline::saveGate();
        $nowGen = $gate['gen'];
        if ($gen !== null && $nowGen !== null && $nowGen !== $gen) {
            return ['reason' => "a save was loaded after this copy was read (init row {$gen} -> {$nowGen}); it belongs to the discarded timeline", 'gen' => $nowGen];
        }
        if (!$gate['reconciled']) {
            return ['reason' => "a save load (init row {$nowGen}) is not reconciled yet; core may still be restoring", 'gen' => $nowGen];
        }
        return ['reason' => null, 'gen' => $nowGen];
    }

    // ---- Lost-update protection for saveDynamics() -------------------------------------

    /** Hidden key carrying a loaded copy's snapshot token (never stored). */
    const LOAD_TOKEN_KEY = '_rd_load_token';
    const LOADED_BASES_MAX = 32;
    const SAVE_MERGE_ATTEMPTS = 5;

    /** token => the stored state (normalized) a loaded copy was derived from, this process only. */
    private static $loadedBases = [];
    /** token => load generation (RelDynTimeline::loadGeneration) when the copy was read; null = unknown. */
    private static $loadedGenerations = [];

    /** Stored blob in the shape getDynamics() hands out (defaults filled, dimensions migrated). */
    private static function normalizeStoredDynamics(?array $stored): array
    {
        $d = self::migrateDimensions(array_merge(self::defaultDynamics(), $stored ?? []));
        unset($d[self::LOAD_TOKEN_KEY]);
        RelDynFulfillment::migrate($d);   // the pre-pair fulfillment blob is the player pair (rulings §11)
        return self::syncLegacyFromDimensions($d);
    }

    private static function rememberLoadedBase(array $base, ?int $generation = null): string
    {
        $token = md5(serialize($base) . '|' . ($generation ?? '-'));
        unset(self::$loadedBases[$token], self::$loadedGenerations[$token]);
        self::$loadedBases[$token] = $base;
        self::$loadedGenerations[$token] = $generation;
        while (count(self::$loadedBases) > self::LOADED_BASES_MAX) {
            $oldest = array_key_first(self::$loadedBases);
            unset(self::$loadedBases[$oldest], self::$loadedGenerations[$oldest]);
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

        $k = self::ATTACHMENT_DRIFT_KEY;
        $bd = is_array($base[$k] ?? null) ? $base[$k] : [];
        $md = $mine[$k] ?? null;
        $td = $theirs[$k] ?? null;
        if (is_array($md) && is_array($td) && !self::sameMergeValue($md, $bd) && !self::sameMergeValue($td, $bd)) {
            $out[$k] = self::mergeAttachmentDrift($bd, $md, $td);
        }
        return $out;
    }

    /**
     * Attachment drift state changed by two concurrent requests (decisions §12): both
     * experiences count and the drift bounds hold for the sum.
     *  - offsets (signed, axis units): base + both changes (a missing base offset is 0);
     *  - both moves on the same game day: the bounded part of the two changes together moves an
     *    axis at most the day's remaining budget (max_per_game_day - what the base had spent that
     *    day), and the day's spend is capped at max_per_game_day; an arc (arc_fearful or a
     *    change past the budget, unbounded by design) is added as it is. Different days: each
     *    move had its own day, the later day's spend is kept;
     *  - never further than max_from_base from the base, or than either side already was (an arc);
     *  - the fearful region is enforced on read (attachmentPoint), the held style and the arc
     *    mark come from the side that changed them (mine when both did), the log is both sides'
     *    entries in game-time order.
     */
    private static function mergeAttachmentDrift(array $b, array $m, array $t): array
    {
        $cfg = self::getAttachmentConfig();
        $drift = (array) ($cfg['drift'] ?? []);
        $cap = floatval($drift['max_per_game_day'] ?? 0.03);
        $maxFromBase = floatval($drift['max_from_base'] ?? 0.4);
        $pick = function (string $key) use ($b, $m, $t) {
            $bv = $b[$key] ?? null;
            if (!self::sameMergeValue($m[$key] ?? null, $bv)) return $m[$key] ?? null;
            return $t[$key] ?? null;
        };
        $out = $m;
        $dayM = intval($m['day'] ?? -1);
        $dayT = intval($t['day'] ?? -1);
        $dayB = intval($b['day'] ?? -1);
        $sameDay = $dayM === $dayT;
        foreach (self::ATTACHMENT_AXES as $axis) {
            $b0 = floatval($b[$axis] ?? 0.0);
            $dm = floatval($m[$axis] ?? 0.0) - $b0;
            $dt = floatval($t[$axis] ?? 0.0) - $b0;
            $spentB = $dayB === $dayM ? floatval($b['moved'][$axis] ?? 0.0) : 0.0;
            if ($sameDay) {
                $room = max(0.0, $cap - $spentB);
                $bounded = 0.0;
                $unbounded = 0.0;
                foreach ([$dm, $dt] as $change) {
                    if (abs($change) > $room + 1e-9) $unbounded += $change; else $bounded += $change;
                }
                $net = max(-$room, min($room, $bounded)) + $unbounded;
                $spent = $spentB + (floatval($m['moved'][$axis] ?? 0.0) - $spentB) + (floatval($t['moved'][$axis] ?? 0.0) - $spentB);
                $out['moved'][$axis] = round(min($cap, max(0.0, $spent)), 6);
            } else {
                $net = $dm + $dt;
                $out['moved'][$axis] = round(floatval(($dayM > $dayT ? $m : $t)['moved'][$axis] ?? 0.0), 6);
            }
            $limit = max($maxFromBase, abs(floatval($m[$axis] ?? 0.0)), abs(floatval($t[$axis] ?? 0.0)));
            $out[$axis] = round(max(-$limit, min($limit, $b0 + $net)), 6);
        }
        $out['day'] = max($dayM, $dayT);
        foreach (['arc_fearful', 'style', 'style_base'] as $key) {
            $v = $pick($key);
            if ($v === null) unset($out[$key]); else $out[$key] = $v;
        }
        $log = [];
        $seen = [];
        foreach ([$m['log'] ?? [], $t['log'] ?? []] as $side) {
            foreach ((array) $side as $e) {
                $id = json_encode($e);
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                $log[] = $e;
            }
        }
        usort($log, fn($x, $y) => floatval($x['gamets'] ?? 0) <=> floatval($y['gamets'] ?? 0));
        $out['log'] = array_slice($log, -max(1, intval($drift['log_size'] ?? 12)));
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
        // (Attachment drift state is merged as a whole in mergeAttachmentDrift().)
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
            '_accumulated_time'       => 0,    // play seconds with this NPC: play clock credit / GAMETS_PER_REAL_SECOND
            '_decay_last_accumulated' => 0,    // accumulated time at last affinity decay
            '_resentment_last_accumulated' => 0, // accumulated time at last resentment decay
            '_diary_last_accumulated' => 0,    // accumulated time at last diary reflection

            // ========== GAMETS PLAY TIME TRACKING ==========
            '_last_gamets'              => 0,     // game-calendar gamets at this NPC's last turn
            '_accumulated_play_gamets'  => 0,     // play clock: played game time (waits, sleeps, travel, loads excluded)
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

            // ========== ATTACHMENT (PR 10; two axes, decisions §12) ==========
            // Axes are read from the profile (getAttachmentAxes); this is the drift offset
            // (attachmentExperience / driftAttachmentFromDays), null until the first move.
            '_attachment_shift_available' => false,
            self::ATTACHMENT_DRIFT_KEY => null,

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
            // (internal weather state: _weather_state / _facet_fed, reldyn_facets.php;
            // intimacy need: _intimacy_need, RelDynIntimacy::ensureNeed, deprivation from _fulfillment)
            '_baseline_drift_samples' => [],
            '_internal_weather' => 'clear',
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

    // ========== PLAY SECONDS (_accumulated_time) ==========

    /**
     * Advance this NPC's play seconds (_accumulated_time) by the play clock's credit for this
     * turn (updatePlayTime()), in real-second units at the default timescale
     * (GAMETS_PER_REAL_SECOND). Game time only: a wait, a sleep or a break with the game closed
     * adds nothing, because the play clock credited nothing for it. Used by the diary
     * reflection cooldowns, the grief bond length and the resentment-decay debounce fallback.
     *
     * @param array &$dynamics            NPC dynamics blob (modified in place)
     * @param float $playGametsCredited   play gamets updatePlayTime() credited this turn
     * @return float  play seconds added
     */
    public static function updateAccumulatedTime(&$dynamics, float $playGametsCredited = 0.0): float
    {
        unset($dynamics['_last_interaction_ts']);   // the real-time stamp of the old clock
        if ($playGametsCredited <= 0) {
            return 0.0;
        }
        $seconds = $playGametsCredited / self::GAMETS_PER_REAL_SECOND;
        $dynamics['_accumulated_time'] = floatval($dynamics['_accumulated_time'] ?? 0) + $seconds;
        return $seconds;
    }

    // ========== GAMETS PLAY TIME TRACKING ==========

    /**
     * Advance this NPC's play clock (_accumulated_play_gamets) at its turn, in game time only.
     *
     * The credit is the played game time between this NPC's previous turn and now: the global
     * play heartbeat's advance since that turn (beatPlayClock(): the eventlog's game clock with
     * waits, sleeps, fast travel and loads cut out), never more than the game calendar moved
     * for this NPC. A turn with no heartbeat mark (stored before it existed, or from a reset
     * heartbeat) credits nothing: that gap cannot be proven play. Without a heartbeat at all
     * (no database) a gap is credited only up to PLAY_GAP_MAX_GAMETS; a longer jump is not
     * proven play. Real time is never read: the same game gives the same clock.
     *
     * First call (no _last_gamets) initializes without adding time.
     *
     * @param array &$dynamics            NPC dynamics blob (modified in place)
     * @param float|null $currentGamets   game calendar now (raw gamets; default currentGamets())
     * @param float|null $globalPlayGamets  global play heartbeat now (play gamets), null = unavailable
     * @return float  play gamets credited this turn
     */
    public static function updatePlayTime(&$dynamics, $currentGamets = null, ?float $globalPlayGamets = null)
    {
        unset($dynamics['_last_real_ts']);   // the real-time stamp of the old clock

        // Heartbeat bound for this gap (play gamets), read before the mark moves to now.
        $globalBound = null;
        if ($globalPlayGamets !== null) {
            $lastGlobal = $dynamics['_last_global_play_gamets'] ?? null;
            $globalBound = (is_numeric($lastGlobal) && floatval($lastGlobal) <= $globalPlayGamets)
                ? $globalPlayGamets - floatval($lastGlobal)
                : 0.0;   // no mark yet, or a mark from a reset heartbeat: nothing proven
            $dynamics['_last_global_play_gamets'] = $globalPlayGamets;
        }

        $currentGamets = $currentGamets === null ? self::currentGamets() : floatval($currentGamets);
        if ($currentGamets <= 0) {
            return 0.0;
        }

        $lastGamets = floatval($dynamics['_last_gamets'] ?? 0);
        $dynamics['_last_gamets'] = $currentGamets;
        if ($lastGamets <= 0) {
            return 0.0;   // first turn: start the clock
        }

        $gametsDelta = $currentGamets - $lastGamets;
        if ($gametsDelta <= 0) {
            return 0.0;   // same moment, or an earlier save loaded
        }

        if ($globalBound !== null) {
            $credited = min($gametsDelta, $globalBound);
            if ($credited < $gametsDelta) {
                self::log("[RelDyn-GAMETS] {$gametsDelta} game gamets since the last turn, {$globalBound} of them played: credited {$credited}");
            }
        } else {
            $credited = $gametsDelta <= self::PLAY_GAP_MAX_GAMETS ? $gametsDelta : 0.0;
        }

        $dynamics['_accumulated_play_gamets'] = floatval($dynamics['_accumulated_play_gamets'] ?? 0) + $credited;

        return (float) $credited;
    }

    /**
     * Play seconds in minutes.
     *
     * @param array $dynamics  NPC dynamics blob
     * @return float  Accumulated minutes
     */
    public static function getAccumulatedMinutes($dynamics)
    {
        return floatval($dynamics['_accumulated_time'] ?? 0) / 60.0;
    }

    // ---------- Global play heartbeat (game time only) ----------
    // One conf_opts row counts played game time across the whole game, whoever the player talks
    // to. Its source is core's eventlog, the densest game clock CHIM 3.4.1 keeps: every event
    // carries the game time it happened at (gamets), and while the game runs core logs the
    // plugin's 'request' poll about every 5 real seconds (processor/comm.php, time() % 5), plus
    // infoloc/infonpc/combat/dialogue rows. Each beat walks the rows logged since the last one,
    // in rowid order, and credits the game time between consecutive rows (foldPlayRows), except
    // where the game skipped time instead of playing it:
    //   - a wait: core's waitstop handler logs 'info_timeforward' "<h> hours have passed" at
    //     the end of the wait (from conf_opts last_waitstart); the h-hour window before it is
    //     cut out of every gap it overlaps (a row naming no usable window: the gap ending at
    //     it is not play);
    //   - a sleep: the gap after 'goodnight' (core logs it as the player lies down) and the gap
    //     ending at 'goodmorning' are not play;
    //   - a load: an 'init' row restarts the baseline at the loaded game time, as does any jump
    //     back of more than PLAY_GAP_MAX_GAMETS (an earlier save); a smaller step back is a row
    //     stamped with an older game time (background writers) and changes nothing;
    //   - any jump forward over PLAY_GAP_MAX_GAMETS between two rows: fast travel, a carriage,
    //     jail, a later save, or a wait or sleep the game sent no event for. The shortest wait
    //     or sleep Skyrim offers is one game hour, above the limit.
    // Real time never enters it: time with the game closed, paused or in a menu leaves the
    // game clock where it was, so it adds nothing, and the same rows always give the same clock.
    // updatePlayTime() bounds each NPC's play credit by it.

    const PLAY_HEARTBEAT_ROW_ID = 'relationship_dynamics_play_clock';
    /**
     * Raw gamets: the longest step between two consecutive eventlog rows still counted as play.
     * One real minute of play at timescale 20 (60 x GAMETS_PER_REAL_SECOND = 20 game minutes):
     * twelve poll intervals, or a 5 s poll gap at a timescale up to 240; under the one game hour
     * (GAMETS_PER_DAY / 24 = 416,667) of the shortest wait or sleep.
     */
    const PLAY_GAP_MAX_GAMETS = 138900;
    /** Game hours: the longest wait or sleep Skyrim offers; an info_timeforward naming more is not trusted as a window. */
    const PLAY_WAIT_MAX_HOURS = 24.0;
    /** A gap ENDING at one of these rows is not play (the game skipped to it). */
    const PLAY_SKIP_TO_TYPES = ['info_timeforward', 'goodmorning', 'waitstop'];
    /** The gap STARTING at one of these rows is not play (the game skips from it). */
    const PLAY_SKIP_FROM_TYPES = ['goodnight', 'waitstart'];
    /** Eventlog rows read per query, and queries per beat (the rest is read by the next beat). */
    const PLAY_SCAN_CHUNK = 500;
    const PLAY_SCAN_MAX_CHUNKS = 40;

    /**
     * Fold eventlog rows (rowid order) into heartbeat state {rowid, gamets, play}: gamets is the
     * game time the next gap starts from (0 = none yet), play the played gamets so far. Pure
     * game time: see the heartbeat notes above. Wait windows are taken from the
     * info_timeforward rows among $rows (a wait split across two beats can leak at most one
     * PLAY_GAP_MAX_GAMETS slice).
     *
     * @param array $state  ['rowid' => int, 'gamets' => float, 'play' => float, 'sleep' => bool]
     * @param array $rows   [['rowid', 'gamets', 'type', 'data' (info_timeforward only)], ...]
     */
    public static function foldPlayRows(array $state, array $rows): array
    {
        $rowid = intval($state['rowid'] ?? 0);
        $base = floatval($state['gamets'] ?? 0);
        $play = floatval($state['play'] ?? 0);
        $sleep = !empty($state['sleep']);   // a goodnight whose gap is still ahead

        $hour = self::GAMETS_PER_DAY / 24.0;   // core: hours = gamets * 0.0000024
        $windows = [];
        foreach ($rows as $r) {
            if (strtolower((string) ($r['type'] ?? '')) === 'info_timeforward'
                && preg_match('/^\s*([0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?)\s+hours? have passed/', (string) ($r['data'] ?? ''), $m)) {
                $h = floatval($m[1]);
                $end = floatval($r['gamets'] ?? 0);
                if ($h > 0 && $h <= self::PLAY_WAIT_MAX_HOURS && $end > 0) {
                    $windows[intval($r['rowid'] ?? 0)] = [$end - $h * $hour, $end];
                }
            }
        }

        foreach ($rows as $r) {
            $rowid = max($rowid, intval($r['rowid'] ?? 0));
            $g = floatval($r['gamets'] ?? 0);
            $type = strtolower((string) ($r['type'] ?? ''));
            if ($g <= 0) {
                continue;
            }
            if ($base <= 0 || $type === 'init') {
                $base = $g;   // first row, or a load: game time restarts here
                $sleep = in_array($type, self::PLAY_SKIP_FROM_TYPES, true);
                continue;
            }
            $delta = $g - $base;
            if ($delta <= 0) {
                if (-$delta > self::PLAY_GAP_MAX_GAMETS) {   // an earlier save: restart here
                    $base = $g;
                    $sleep = false;
                }
                if (in_array($type, self::PLAY_SKIP_FROM_TYPES, true)) {
                    $sleep = true;
                }
                continue;   // else a row stamped with an older game time
            }
            $from = $base;
            $base = $g;
            // A wait row whose window is known is cut precisely below; any other skip row ends a gap that is not play
            $skipped = $sleep || (in_array($type, self::PLAY_SKIP_TO_TYPES, true) && !isset($windows[intval($r['rowid'] ?? 0)]));
            $sleep = in_array($type, self::PLAY_SKIP_FROM_TYPES, true);
            if ($skipped) {
                continue;
            }
            $credit = $delta;
            foreach ($windows as [$ws, $we]) {
                $credit -= max(0.0, min($g, $we) - max($from, $ws));
            }
            if ($credit > 0 && $credit <= self::PLAY_GAP_MAX_GAMETS) {
                $play += $credit;
            }
        }

        return ['rowid' => $rowid, 'gamets' => $base, 'play' => $play, 'sleep' => $sleep];
    }

    /**
     * Advance the global play heartbeat over the eventlog rows logged since its last beat and
     * return its total (play gamets), or null when there is no database. Compare-and-set: of
     * two concurrent beats one writes; the other returns the stored total (its rows are read
     * again by the next beat). The first beat on a database, or on a row from the real-time
     * build ({real_ts, gamets, play}), anchors at the newest eventlog row and keeps the play
     * total: nothing before it is credited.
     */
    public static function beatPlayClock(): ?float
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return null;
        }
        try {
            $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::PLAY_HEARTBEAT_ROW_ID]);
            $raw = is_array($row) ? ($row['value'] ?? null) : null;
            $cur = is_string($raw) ? json_decode($raw, true) : null;
            $valid = is_array($cur) && is_numeric($cur['rowid'] ?? null) && is_numeric($cur['gamets'] ?? null)
                && is_numeric($cur['play'] ?? null);

            if (!$valid) {
                $play = is_array($cur) && is_numeric($cur['play'] ?? null) ? floatval($cur['play']) : 0.0;
                if ($raw !== null && !is_array($cur)) {
                    error_log("[RelDyn] ERROR beatPlayClock: conf_opts " . self::PLAY_HEARTBEAT_ROW_ID . " unreadable; restarting it");
                    $play = 0.0;
                }
                $state = self::anchorPlayClock($db, $play);
                return self::writePlayClock($db, $raw, $state);
            }

            $state = ['rowid' => intval($cur['rowid']), 'gamets' => floatval($cur['gamets']),
                      'play' => floatval($cur['play']), 'sleep' => !empty($cur['sleep'])];
            $startRowid = $state['rowid'];
            for ($i = 0; $i < self::PLAY_SCAN_MAX_CHUNKS; $i++) {
                $rows = $db->fetchAll('SELECT rowid, gamets, type, CASE WHEN type = \'info_timeforward\' THEN data END AS data'
                    . ' FROM eventlog WHERE rowid > ' . intval($state['rowid']) . ' ORDER BY rowid LIMIT ' . self::PLAY_SCAN_CHUNK);
                $rows = is_array($rows) ? array_values(array_filter($rows, static function ($r) use ($state) {
                    return is_array($r) && is_numeric($r['rowid'] ?? null) && intval($r['rowid']) > $state['rowid']
                        && is_numeric($r['gamets'] ?? null);
                })) : [];
                if (!$rows) {
                    break;
                }
                $state = self::foldPlayRows($state, $rows);
                if (count($rows) < self::PLAY_SCAN_CHUNK) {
                    break;
                }
            }

            if ($state['rowid'] === $startRowid) {
                // Nothing new. An eventlog whose newest rowid is below ours was rebuilt: re-anchor.
                $newest = $db->fetchOne('SELECT rowid FROM eventlog ORDER BY rowid DESC LIMIT 1');
                if (is_array($newest) && is_numeric($newest['rowid'] ?? null) && intval($newest['rowid']) < $startRowid) {
                    return self::writePlayClock($db, $raw, self::anchorPlayClock($db, $state['play']));
                }
                return $state['play'];
            }
            return self::writePlayClock($db, $raw, $state);
        } catch (Throwable $e) {
            self::logError('beatPlayClock', $e);
            return null;
        }
    }

    /** Heartbeat state anchored at the newest eventlog row, keeping $play. */
    private static function anchorPlayClock($db, float $play): array
    {
        $newest = $db->fetchOne('SELECT rowid, gamets FROM eventlog ORDER BY rowid DESC LIMIT 1');
        $newest = is_array($newest) ? $newest : [];
        return ['rowid' => intval($newest['rowid'] ?? 0), 'gamets' => max(0.0, floatval($newest['gamets'] ?? 0)),
                'play' => $play, 'sleep' => false];
    }

    /** Compare-and-set the heartbeat row from $raw (null = absent) to $state; returns the stored play total. */
    private static function writePlayClock($db, ?string $raw, array $state): float
    {
        $next = json_encode(['rowid' => $state['rowid'], 'gamets' => $state['gamets'], 'play' => $state['play'],
                             'sleep' => !empty($state['sleep'])]);
        $won = $raw === null
            ? $db->fetchOne('INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO NOTHING RETURNING id',
                [self::PLAY_HEARTBEAT_ROW_ID, $next])
            : $db->fetchOne('UPDATE conf_opts SET value = $2 WHERE id = $1 AND value = $3 RETURNING id',
                [self::PLAY_HEARTBEAT_ROW_ID, $next, $raw]);
        if (isset($won['id'])) {
            return (float) $state['play'];
        }
        $again = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::PLAY_HEARTBEAT_ROW_ID]);
        $other = json_decode((string) ($again['value'] ?? ''), true);
        return is_array($other) && is_numeric($other['play'] ?? null) ? floatval($other['play']) : (float) $state['play'];
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
    //      - consumable expiry, plasticity override (30 game days), night/moon;
    //      - Divine Intervention's unstable window (24 h), grief phases (48 h / 1 week /
    //        4 weeks x the bond weight), the ick's trigger stamp, the parasite half-life (2 h)
    //        (reldyn_protocols.php; grief is a process in the world, not a negative state
    //        toward the player).
    //    Rule: negative states never go DOWN on this clock (time does not heal); they
    //    only go down through positive contact. Positive states fade on it.
    // 2. FILTERED PLAY CLOCK: _accumulated_play_gamets, per NPC, advanced by
    //    updatePlayTime() on that NPC's requests by the played game time the global
    //    heartbeat (beatPlayClock: core's eventlog game clock, waits, sleeps, fast travel
    //    and loads cut out) saw since its last turn. Game time only: no real seconds, so
    //    time with the game closed or paused adds nothing and waits and sleeps add nothing.
    //    Unit: play gamets; GAMETS_PER_REAL_HOUR = one real hour of play at timescale 20.
    //    Used for what happens WHILE the player is playing: in-contact passion and
    //    jealousy decay, the diminishing-returns session multiplier, cooldowns (resentment
    //    -1 debounce, ick, divine intervention, ambient trickle), the hoover's 48 h
    //    after-glow context, and reunion's check that the time apart held real play (no
    //    reunion from a wait). A wait or sleep must never be able to trigger or clear these.
    // 3. PLAY SECONDS: _accumulated_time, the play clock's credit in real-second units
    //    (updateAccumulatedTime). For positive cooldowns that must not be farmable (diary
    //    reflection), grief bond duration (the widow's weight: play hours the bond has been
    //    in the player's world, RelDynProtocols::bondHours), and the resentment-decay debounce fallback
    //    before the play clock has a value. Values stored by the real-time build carry over.
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
    const PROFILE_AUTOGEN_VERSION = 2;   // 2: attachment on two axes (decisions §12), no stored label

    /** The 13 temperaments of MDD 1.3, in table order. The order also breaks vote ties. */
    const TEMPERAMENT_TYPES = [
        'Romantic', 'Anxious', 'Bold', 'Playful', 'Humble', 'Nurturing', 'Gentle',
        'Jealous', 'Proud', 'Defiant', 'Guarded', 'Independent', 'Stoic',
    ];

    /**
     * MDD 6.1 style labels, derived from the attachment axes (getAttachmentStyle). 'toxic' is
     * the fearful region; it is never derived (only an override, a preset or an arc).
     */
    const ATTACHMENT_STYLE_TYPES = ['secure', 'avoidant', 'anxious', 'toxic'];

    /**
     * Profile fields a per-NPC override can set (stored in $dynamics['profile_overrides']).
     * attachment_axes = ['anxiety' => 0..1, 'avoidance' => 0..1]; attachment_style = a label,
     * read as that style's textbook point. Setting one clears the other. trait_vector = a partial
     * map of trait name => 0..1 (personality traits design §4.1 precedence 1; stored, not read by
     * the phase-1 assignment). 'traits' stays the trait TAG override.
     */
    const PROFILE_OVERRIDE_FIELDS = ['temperament', 'attachment_style', 'attachment_axes', 'maturity_type', 'traits', 'trait_vector'];

    /** Dimensions whose x/baseline migrateDimensions() seeds from the temperament baseline. */
    const TEMPERAMENT_SEEDED_DIMENSIONS = ['maturity', 'trust', 'comfort', 'respect', 'warmth', 'coord_m', 'coord_f', 'self_confidence'];

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

            // Attachment (MDD 6.1) is not derived from temperament any more: two axes from their
            // own evidence (config 'attachment', deriveAttachmentAxes; decisions §12).
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
            // attachment_axes (decisions §12): anxiety / avoidance, axis units 0..1.
            'npc_overrides' => [
                // MDD 8.2/3.3, 15.6; rulings 2026-09-24 §8. Attachment §12: low-moderate anxiety,
                // moderate avoidance rooted in self-protection, expected to ease as trust is earned.
                // Personality (decisions §16 #4): under the read assignment her vector is this
                // hand-set, spoiler-free conclusion (she is on trait_reader.skip, never read):
                // Stoic-leaning, Resilient (Rs/L at the corner), guard 0.75 (romance momentum
                // 1 + 4 (G - 0.6) = 1.6), maturity starting at 75 (MDD 15.4). 'temperament'
                // is the label assignment's (phase 1 legacy) preset only.
                'ashe'   => ['temperament' => 'Guarded', 'maturity_type' => 'Resilient',
                             'trait_vector' => ['guard' => 0.75, 'expressiveness' => 0.30, 'confidence' => 0.65, 'pride' => 0.40,
                                                'resilience' => 0.75, 'reactivity' => 0.35607, 'warmth' => 0.40, 'restraint' => 0.70,
                                                'possessiveness' => 0.20, 'protectiveness' => 0.55],
                             'maturity_start' => 75,
                             'attachment_axes' => ['anxiety' => 0.3, 'avoidance' => 0.5]],
                // §12: guarded in Ken's sense (hard to get in, arm's length until let in) and
                // secure-leaning: low anxiety, moderate avoidance (copes through action, not talk),
                // fiercely loyal to her own. Her temperament is not preset: it still derives from
                // her class (Hunter -> Independent, MDD 1.3). RelDyn's Guarded temperament is the
                // whole MDD 1.3 package (trust 20, comfort 15, self-confidence 40, Brittle maturity,
                // passion x0.6, bookish facet tastes), which would reshape her far beyond how hard
                // she is to get into; that choice is Ken's. Her attachment is these axes either way.
                'aela the huntress' => ['attachment_axes' => ['anxiety' => 0.15, 'avoidance' => 0.35]],
                // Rulings 2026-09-25 §18 #9 (Ken can veto): the toxic test bed attaches like one. Her
                // bio read reads secure (her toxicity is scheming, not how she attaches), and the
                // fearful region is never derived (MDD 6.1: set by hand or by an arc), so this is
                // her hand-set point: a nudge just inside the fearful region (thresholds 0.5), well
                // short of the textbook corner (0.85): she wants closeness and fears it, used once
                // and cast off. The fearful protocol applies (region keys: maturity floor 30,
                // conflict passion, the hoover, the manipulative refusal); drift can still carry
                // her out as trust is earned.
                'muiri' => ['attachment_axes' => ['anxiety' => 0.6, 'avoidance' => 0.55]],
                'mikael' => ['maturity_type' => 'Volatile'],                            // MDD 15.6
                'serana' => ['maturity_type' => 'Growth'],                              // MDD 15.6
                'nazeem' => ['maturity_type' => 'Rigid'],                               // MDD 15.6
                // MDD 8.2 C, for the label assignment only (phase 1 legacy, reproduced exactly): under
                // the read assignment her bio decides (decisions §16 #2), so the entry is ignored there
                'ysolda' => ['temperament' => 'Anxious', 'assignment' => 'label'],
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
     *           'losses' (loss history: people the NPC lost, RelDyn grief bonds),
     *           'trait_auto' (read assignment: RelDynTraitAssign::resolve() of the NPC's config
     *           preset / hand-set vector, Sharmat label, bio read and priors).
     *
     * Priority per field: per-NPC override > named preset (config npc_overrides) > derived.
     * Label assignment (phase 1 legacy): temperament from MARAS, then Sharmat (pipeline §5.1),
     * then the core-data vote. Read assignment (phase 2): the auto label is trait_auto's (its
     * preset, else the nearest preset of its vector); the vote and MARAS are retired, and the
     * dependents (maturity type, tags) come from the composed vector.
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
        $traitAuto = is_array($options['trait_auto'] ?? null) ? $options['trait_auto'] : null;
        if ($traitAuto !== null) {
            // read assignment: the config preset / hand-set vector and Sharmat are inside trait_auto
            $autoTemp = $traitAuto['label']; $autoSource = $traitAuto['label_source'];
        } elseif ($marasTemp !== null) {
            $autoTemp = $marasTemp; $autoSource = 'maras';
        } elseif ($sharmatTemp !== null) {
            $autoTemp = $sharmatTemp; $autoSource = 'sharmat';
        } elseif ($voted !== null) {
            $autoTemp = $voted; $autoSource = 'core';
        } else {
            $autoTemp = self::validTemperament($cfg['fallback_temperament'] ?? null) ?? 'Stoic'; $autoSource = 'fallback';
        }
        $preset = [
            'temperament'      => $traitAuto !== null ? null : self::validTemperament($preset['temperament'] ?? null),
            'attachment_style' => self::validAttachmentStyle($preset['attachment_style'] ?? null),
            'attachment_axes'  => self::validAttachmentAxes($preset['attachment_axes'] ?? null),
            'maturity_type'    => self::validMaturityType($preset['maturity_type'] ?? null),
            'traits'           => isset($preset['traits']) ? self::normalizeTraits($preset['traits'], $cfg) : null,
        ];
        $clean = [
            'temperament'      => self::validTemperament($overrides['temperament'] ?? null),
            'maturity_type'    => self::validMaturityType($overrides['maturity_type'] ?? null),
            'traits'           => isset($overrides['traits']) ? self::normalizeTraits($overrides['traits'], $cfg) : null,
        ];

        [$temperament, $sources['temperament']] = self::pickProfileValue($clean['temperament'], $preset['temperament'], $autoTemp, $autoSource);
        // Read assignment: the NPC's vector = the auto vector under its per-NPC overrides
        $vector = null;
        if ($traitAuto !== null) {
            $vector = RelDynTraits::composeRead($traitAuto['x'], $traitAuto['label'], $temperament,
                $overrides['temperament'] ?? null, $overrides['trait_vector'] ?? null)['x'];
        }
        // What each dependent is without a per-NPC override: preset, else derived from the temperament
        // (read assignment: from the vector).
        $auto = self::profileDefaultDependents($temperament, $archetype, $cfg, $preset, $vector);
        $profile = ['temperament' => $temperament];
        foreach (['maturity_type', 'traits'] as $dep) {
            [$profile[$dep], $sources[$dep]] = self::pickProfileValue($clean[$dep], $preset[$dep], $auto[$dep], 'derived');
        }

        // Attachment (decisions §12): two axes from their own evidence, never from the
        // temperament alone; override > preset > derived (attachmentBase on this profile).
        $textHits = self::attachmentTextHits($row);
        $attachment = self::attachmentBase([
            'profile_overrides' => $overrides,
            '_profile_autogen'  => ['archetype' => $archetype, 'preset' => array_filter($preset, fn($v) => $v !== null),
                                    'attachment_text' => $textHits, 'traits_source' => $sources['traits']],
            'inferred_temperament' => $temperament,
            'traits' => $profile['traits'],
            '_grief_bonds' => array_fill(0, max(0, intval($options['losses'] ?? 0)), []),
        ] + ($vector !== null ? ['trait_vector' => RelDynTraits::toStored($vector), '_trait_vector_src' => ['assignment' => 'read']] : []));
        $profile['attachment'] = $attachment;
        $profile['attachment_text'] = $textHits;
        $profile['attachment_style'] = self::attachmentStyleOf($attachment['anxiety'], $attachment['avoidance']);
        $sources['attachment'] = $attachment['source'];

        return $profile + [
            'archetype'        => $archetype,
            'sources'          => $sources,
            'maturity_type_origin' => $auto['maturity_type_origin'] ?? null,
            'vector'           => $vector,
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
    private static function profileDefaultDependents(string $temperament, ?string $archetype, array $cfg, array $preset, ?array $vector = null): array
    {
        $auto = self::deriveProfileDependents($temperament, $archetype, $cfg, $vector);
        foreach (['maturity_type', 'traits'] as $dep) {
            if (isset($preset[$dep])) {
                $auto[$dep] = $preset[$dep];
                if ($dep === 'maturity_type' && $vector !== null) $auto['maturity_type_origin'] = 'preset';
            }
        }
        return $auto;
    }

    /**
     * Maturity type and traits implied by a temperament and archetype (attachment: attachmentBase).
     * Read assignment ($vector given): the class rule (archetype_maturity_type, Bard -> Volatile)
     * else the vector's nearest MDD 15.6 corner (a display label: the physics reads the exact
     * two-axis formula, continuousMaturityY), and the C1 tags from pride and confidence plus the
     * archetype's tags.
     */
    private static function deriveProfileDependents(string $temperament, ?string $archetype, array $cfg, ?array $vector = null): array
    {
        if ($vector !== null) {
            $classType = $archetype !== null ? self::validMaturityType(((array) ($cfg['archetype_maturity_type'] ?? []))[$archetype] ?? null) : null;
            $tags = array_merge(RelDynTraitAssign::tagsOf($vector),
                $archetype !== null ? (array) (((array) ($cfg['archetype_traits'] ?? []))[$archetype] ?? []) : []);
            return ['maturity_type' => $classType ?? RelDynTraits::maturityCorner($vector),
                    'maturity_type_origin' => $classType !== null ? 'class' : 'traits',
                    'traits' => self::normalizeTraits($tags, $cfg) ?? []];
        }
        // A17 and C1 through the trait engine: the nearest preset's row of the config tables
        $maturityType = ($archetype !== null ? self::validMaturityType(((array) ($cfg['archetype_maturity_type'] ?? []))[$archetype] ?? null) : null)
            ?? self::validMaturityType(RelDynTraits::labelParam($temperament, (array) ($cfg['temperament_maturity_type'] ?? []), null))
            ?? 'Adaptive';

        $traits = array_merge(
            (array) RelDynTraits::labelParam($temperament, (array) ($cfg['temperament_traits'] ?? []), []),
            $archetype !== null ? (array) (((array) ($cfg['archetype_traits'] ?? []))[$archetype] ?? []) : []
        );
        $traits = self::normalizeTraits($traits, $cfg) ?? [];

        return ['maturity_type' => $maturityType, 'traits' => $traits];
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
     * Resolve temperament, maturity type and traits once per NPC and store them in RelDyn
     * state (inferred_temperament, dimensions.maturity.plasticity_type, traits; provenance and
     * the attachment preset / derivation in _profile_autogen). Attachment is two axes read on
     * the fly from this profile (getAttachmentAxes); no label is stored.
     * Values an NPC already carries (set by the editor, Sharmat, or an arc) are kept.
     * Returns true when it changed $dynamics. A failed core read is logged and retried
     * on the next call rather than stored as a fallback.
     *
     * Read assignment (personality traits phase 2, config traits.assignment 'read'): the NPC's
     * trait vector (RelDynTraitAssign: preset > bio read over priors > priors) is resolved here
     * too and stored as _trait_vector_src.auto; the label is its preset or nearest preset. The
     * cheap exit also needs the vector current (read assignment, VERSION); an NPC whose read is
     * still pending costs one lookup per request (RelDynTraitRead::stateFor) until it is done,
     * then is resolved again (untouched seeded baselines move, live values stay). So does an NPC
     * whose priors came from an incomplete core row (no race or class yet), until the row is filled.
     * Label assignment: an NPC last resolved under the read assignment is resolved again, so
     * switching back to 'label' restores the phase-1 profile (its untouched seeds included).
     */
    public static function ensureTemperamentProfile($npcName, &$dynamics): bool
    {
        $readMode = RelDynTraits::assignment() === 'read';
        $wasRead = (($dynamics['_trait_vector_src']['assignment'] ?? null) === 'read');
        if (intval($dynamics['_profile_autogen']['version'] ?? 0) >= self::PROFILE_AUTOGEN_VERSION) {
            if ($readMode ? !self::traitProfileStale((string) $npcName, $dynamics) : !$wasRead) {
                return false;
            }
        }
        $npcName = (string) $npcName;
        try {
            $row = self::fetchCoreProfileRow($npcName);
        } catch (Throwable $e) {
            error_log("[RelDyn] temperament auto-generation: core_npc_master read failed for {$npcName}: " . $e->getMessage());
            return false;
        }

        $prevTemp = self::validTemperament($dynamics['inferred_temperament'] ?? null);
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
        if ($prevTemp !== null && $prevMaturityType !== null && !isset($overrides['maturity_type']) && !$wasAuto('maturity_type', $prevMaturityType)) {
            $overrides['maturity_type'] = $prevMaturityType; $kept['maturity_type'] = true;
        }
        if ($prevTraits !== null && !isset($overrides['traits']) && !$wasAuto('traits', $prevTraits)) {
            $overrides['traits'] = $prevTraits; $kept['traits'] = true;
        }

        $sharmatStyle = self::getSharmatSpeechStyle($npcName);
        $sharmatTemp = is_string($sharmatStyle) ? self::validTemperament(self::speechStyleToTemperament($sharmatStyle)) : null;
        $traitIn = $readMode ? self::traitAssignmentFor($npcName, $row, $sharmatTemp) : null;
        $profile = self::deriveNpcProfile($npcName, $row, [
            'overrides' => $overrides,
            'sharmat_style' => is_string($sharmatStyle) ? $sharmatStyle : null,
            'losses' => is_array($dynamics['_grief_bonds'] ?? null) ? count($dynamics['_grief_bonds']) : 0,
        ] + ($traitIn !== null ? ['trait_auto' => $traitIn['auto']] : []));
        foreach ($kept as $field => $_) {
            if ($profile['sources'][$field] === 'override') $profile['sources'][$field] = 'stored';
        }

        $dynamics['inferred_temperament'] = $profile['temperament'];
        $dynamics['dimensions']['maturity']['plasticity_type'] = $profile['maturity_type'];
        $dynamics['traits'] = $profile['traits'];
        if ($traitIn !== null) {
            // Personality traits phase 2 (design §4.6): the auto vector and its provenance; the
            // stored trait_vector is composed from it by syncStored (editor preset, per-trait override)
            $dynamics['_trait_vector_src'] = $traitIn['src'];
        }
        // Personality traits: trait_vector (read: composed from the auto vector; label: the label's preset point)
        $dynamics = RelDynTraits::syncStored($dynamics);
        // Attachment is read from the axes (getAttachmentAxes), never from a stored label: an
        // April / earlier label (the temperament auto-map) is not carried (decisions §3, §12).
        unset($dynamics['attachment_style']);

        // Seed the temperament-based dimensions here, as migrateDimensions() would: a first
        // save merges this copy onto a normalized empty state, which is seeded from the
        // null-temperament fallback ('Stoic'). Dimensions already seeded from that fallback
        // and untouched since (x and baseline still equal the seed) are seeded again.
        // Read assignment: a baseline still at the value it was seeded with (this resolution's
        // previous seed, or the previous label's preset seed) is seeded again from the vector;
        // back under the label assignment, a read seed still untouched goes back to the preset's.
        $seeded = [];
        foreach (self::TEMPERAMENT_SEEDED_DIMENSIONS as $dim) {
            if (!is_array($dynamics['dimensions'][$dim] ?? null)) continue;
            $x = $dynamics['dimensions'][$dim]['x'] ?? null;
            $base = $dynamics['dimensions'][$dim]['baseline'] ?? null;
            $new = self::getTemperamentBaseline($profile['temperament'], $dim, $readMode ? $dynamics : null);
            $stoicSeed = self::getTemperamentBaseline('Stoic', $dim);
            $untouchedFallbackSeed = $prevTemp === null && $x !== null && $base !== null
                && abs(floatval($x) - $stoicSeed) < 1e-9 && abs(floatval($base) - $stoicSeed) < 1e-9;
            // warmth's defaultDynamics() placeholder (x 0, baseline null) is unset, not a value
            $placeholder = $dim === 'warmth' && $base === null && is_numeric($x) && abs(floatval($x)) < 1e-9;
            $untouchedSeed = false;
            if (($readMode || $wasRead) && $x !== null && $base !== null) {
                $prevSeed = $prevGen['seeded'][$dim] ?? ($prevTemp !== null ? self::getTemperamentBaseline($prevTemp, $dim) : null);
                $untouchedSeed = is_numeric($prevSeed) && abs(floatval($x) - floatval($prevSeed)) < 1e-9
                    && abs(floatval($base) - floatval($prevSeed)) < 1e-9;
            }
            if ($x === null || $untouchedFallbackSeed || $placeholder || $untouchedSeed) {
                $dynamics['dimensions'][$dim]['x'] = $new;
                $dynamics['dimensions'][$dim]['baseline'] = $new;
                $seeded[$dim] = $new;
            }
        }

        $dynamics['_profile_autogen'] = [
            'version'          => self::PROFILE_AUTOGEN_VERSION,
            'archetype'        => $profile['archetype'],
            'base_temperament' => $profile['base_temperament'],
            'preset'           => $profile['preset'],
            'temperament_source'      => $profile['sources']['temperament'],
            'attachment_source'       => $profile['sources']['attachment'],
            'attachment_text'         => $profile['attachment_text'],   // attachment keyword hits in the core text
            'attachment'              => ['anxiety' => $profile['attachment']['anxiety'], 'avoidance' => $profile['attachment']['avoidance'],
                                          'signals' => $profile['attachment']['signals']],
            'maturity_type_source'    => $profile['sources']['maturity_type'],
            'traits_source'           => $profile['sources']['traits'],
            'auto'             => $profile['auto'],
            'signals'          => $profile['signals'],
        ];
        if ($readMode) {
            $dynamics['_profile_autogen']['seeded'] = $seeded;
            $dynamics['_profile_autogen']['signals'] = $traitIn['auto']['prior']['signals'];
            $dynamics['_profile_autogen']['maturity_type_origin'] = $profile['maturity_type_origin'];
        }
        self::log("Profile auto-gen for {$npcName}: temperament={$profile['temperament']} ({$profile['sources']['temperament']}), "
            . sprintf('attachment=%s (anxiety %.2f, avoidance %.2f, %s), ', $profile['attachment_style'],
                $profile['attachment']['anxiety'], $profile['attachment']['avoidance'], $profile['sources']['attachment'])
            . "maturity_type={$profile['maturity_type']}, traits=" . implode(',', $profile['traits'])
            . ' [' . implode(' ', $profile['signals']) . ']');
        if ($traitIn !== null) {
            $tp = $dynamics['trait_preset'] ?? [];
            self::log(sprintf('Traits for %s: nearest %s (%.2f), %s, read %s [%s]', $npcName, $tp['nearest'] ?? '?',
                floatval($tp['distance'] ?? 0), $traitIn['auto']['label_source'], $traitIn['src']['read_status'],
                implode(' ', $traitIn['auto']['prior']['signals'])));
        }
        return true;
    }

    /**
     * Read assignment: does the stored vector need a new resolution? Yes when it was not written
     * by the read assignment at the current RelDynTraits::VERSION, or when its read was pending
     * (or the lookup failed) and is done now (one lookup per request: RelDynTraitRead::stateFor).
     */
    private static function traitProfileStale(string $npcName, array $dynamics): bool
    {
        $src = $dynamics['_trait_vector_src'] ?? null;
        if (!is_array($src) || ($src['assignment'] ?? null) !== 'read' || !is_array($src['auto'] ?? null)
            || intval($dynamics['trait_vector_version'] ?? 0) < RelDynTraits::VERSION) {
            return true;
        }
        if (in_array($src['read_status'] ?? null, ['pending', 'error'], true)
            && RelDynTraitRead::stateFor($npcName)['status'] === 'done') {
            return true;
        }
        // priors from an incomplete core row (the game had not sent race / class yet): resolved
        // again once it has (one core row read per request until then)
        if (($src['prior']['complete'] ?? true) === false) {
            $token = self::requestScopeToken();
            $key = strtolower($npcName);
            if ($token === null || (self::$priorRowChecked['token'] ?? null) !== $token) self::$priorRowChecked = ['token' => $token, 'names' => []];
            if ($token !== null && isset(self::$priorRowChecked['names'][$key])) return false;
            if ($token !== null) self::$priorRowChecked['names'][$key] = true;
            try {
                return self::corePriorRowComplete(self::fetchCoreProfileRow($npcName));
            } catch (Throwable $e) {
                error_log("[RelDyn-TRAITS] core row re-check failed for {$npcName}: " . $e->getMessage());
                return false;   // keep the stored vector; checked again next request
            }
        }
        return false;
    }

    /** Per request: NPCs whose incomplete prior row was already checked (traitProfileStale). */
    private static $priorRowChecked = ['token' => null, 'names' => []];

    /**
     * True when a core row carries what the priors read from the game (design §4.4): a race and
     * a class. Voice comes from npc_templates_v2; factions and skills may legitimately be empty.
     */
    public static function corePriorRowComplete(array $row): bool
    {
        $ext = self::decodeProfileJson($row['extended_data'] ?? null);
        $class = $ext['class'] ?? null;
        $className = is_array($class) ? ($class['name'] ?? '') : $class;
        return trim((string) ($row['race'] ?? '')) !== '' && trim((string) $className) !== '';
    }

    /**
     * The read assignment's inputs and auto vector for an NPC (design §4.1, §4.4): config
     * npc_overrides (a hand-set trait_vector, else a preset name) > the Sharmat label > the bio
     * read (RelDynTraitRead::stateFor: a miss enqueues it) blended over the priors (voice from
     * npc_templates_v2, class / factions / top skill / race from the core row).
     * Returns ['auto' => RelDynTraitAssign::resolve(), 'src' => _trait_vector_src].
     */
    private static function traitAssignmentFor(string $npcName, array $row, ?string $sharmatTemp): array
    {
        $cfg = self::getTemperamentAutogenConfig();
        $preset = (array) (((array) ($cfg['npc_overrides'] ?? []))[strtolower(trim($npcName))] ?? []);
        // an entry marked for the label assignment only (Ysolda's MDD 8.2 C Anxious, decisions §16 #2)
        if (($preset['assignment'] ?? null) === 'label') $preset = [];
        $hand = is_array($preset['trait_vector'] ?? null) ? $preset['trait_vector'] : null;
        $cfgPreset = $hand === null ? self::validTemperament($preset['temperament'] ?? null) : null;
        $state = RelDynTraitRead::stateFor($npcName);

        $ext = self::decodeProfileJson($row['extended_data'] ?? null);
        $meta = self::decodeProfileJson($row['metadata'] ?? null);
        [$classArch] = self::profileArchetypes($ext, $meta, $cfg);
        $factions = [];
        foreach ((array) ($ext['factions'] ?? []) as $f) {
            if (is_array($f) && intval($f['rank'] ?? 0) >= 0 && is_string($f['name'] ?? null)) $factions[] = $f['name'];
        }
        $voice = RelDynTraitRead::voiceFor($state['key'], $npcName, is_string($row['voiceid'] ?? null) ? $row['voiceid'] : null);
        $screened = $state['status'] === 'skip';
        $presetLabel = $cfgPreset ?? ($hand === null ? $sharmatTemp : null);
        $auto = RelDynTraitAssign::resolve([
            'preset'         => $presetLabel,
            'preset_source'  => $cfgPreset !== null ? 'preset' : 'sharmat',
            'hand_set'       => $hand,
            'maturity_start' => $preset['maturity_start'] ?? null,
            'prior_in'       => ['voice' => $voice, 'class' => $classArch, 'factions' => $factions,
                                 'skills' => (array) ($meta['skills'] ?? []), 'race' => $row['race'] ?? null],
            'read'           => $screened ? null : $state['result'],
            'screened'       => $screened,
        ]);
        $src = [
            'assignment'   => 'read',
            'auto'         => RelDynTraits::toStored($auto['x']),
            'auto_label'   => $auto['label'],
            'auto_source'  => $auto['label_source'],
            'traits'       => $auto['src'],
            'prior'        => ['signals' => $auto['prior']['signals'], 'voice' => $voice, 'complete' => self::corePriorRowComplete($row)],
            'read_status'  => $state['status'],
            'template_key' => $state['key'],
            'src_hash'     => $state['hash'],
            'prompt_v'     => RelDynTraitRead::PROMPT_V,
            'model'        => $state['model'],
        ];
        return ['auto' => $auto, 'src' => $src];
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
     * Set (or with null, clear) a per-NPC override for temperament, attachment_style (a label:
     * that style's textbook point), attachment_axes (['anxiety' => 0..1, 'avoidance' => 0..1]),
     * maturity_type or traits, and apply it; trait_vector (a partial trait map) is only stored
     * in phase 1 and changes nothing else. Changing the temperament re-derives the
     * dependents that still hold their automatic value. The two attachment overrides replace
     * each other; the attachment drift offset stays (experience is kept, the base moves).
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
                'attachment_axes'  => self::validAttachmentAxes($value),
                'maturity_type'    => self::validMaturityType($value),
                'traits'           => self::normalizeTraits($value, $cfg),
                'trait_vector'     => RelDynTraits::validOverride($value),
            };
            if ($value === null) return false;
        }

        $overrides = (array) ($dynamics['profile_overrides'] ?? []);
        if ($value === null) unset($overrides[$field]); else $overrides[$field] = $value;
        if ($value !== null && $field === 'attachment_style') unset($overrides['attachment_axes']);
        if ($value !== null && $field === 'attachment_axes') unset($overrides['attachment_style']);
        $dynamics['profile_overrides'] = $overrides;
        $traitSrc = $dynamics['_trait_vector_src'] ?? null;
        $readAuto = RelDynTraits::assignment() === 'read' && is_array($traitSrc) && is_array($traitSrc['auto'] ?? null);
        // The trait_vector override must not re-run the label resolution below (that would reset
        // a stored non-override temperament, maturity type or tag list to the auto-generated one).
        // Label assignment: it is only stored. Read assignment: the vector is recomposed and the
        // dependents that are still automatic (maturity type, tags) follow it.
        if ($field === 'trait_vector') {
            if ($readAuto) {
                $dynamics = RelDynTraits::syncStored($dynamics);
                $autogen = (array) ($dynamics['_profile_autogen'] ?? []);
                $vec = RelDynTraits::readVector($dynamics);
                $label = self::validTemperament($dynamics['inferred_temperament'] ?? null) ?? 'Stoic';
                $auto = self::profileDefaultDependents($label, $autogen['archetype'] ?? null, $cfg, (array) ($autogen['preset'] ?? []), $vec);
                $cur = ['maturity_type' => $dynamics['dimensions']['maturity']['plasticity_type'] ?? null, 'traits' => $dynamics['traits'] ?? null];
                foreach (['maturity_type', 'traits'] as $dep) {
                    if (array_key_exists($dep, $overrides) || !($cur[$dep] === null || $cur[$dep] === ($autogen['auto'][$dep] ?? null))) continue;
                    if ($dep === 'maturity_type') $dynamics['dimensions']['maturity']['plasticity_type'] = $auto[$dep];
                    else $dynamics['traits'] = $auto[$dep];
                }
                $autogen['auto'] = $auto;
                $dynamics['_profile_autogen'] = $autogen;
            }
            return true;
        }

        $autogen = (array) ($dynamics['_profile_autogen'] ?? []);
        $prevAuto = (array) ($autogen['auto'] ?? []);
        $temperament = $overrides['temperament'] ?? self::validTemperament($autogen['base_temperament'] ?? null)
            ?? self::validTemperament($dynamics['inferred_temperament'] ?? null) ?? 'Stoic';
        // Read assignment: the dependents come from the vector the new label composes to
        $vector = $readAuto ? RelDynTraits::composeRead(RelDynTraits::fromStored($traitSrc['auto']), $traitSrc['auto_label'] ?? null,
            $temperament, $overrides['temperament'] ?? null, $overrides['trait_vector'] ?? null)['x'] : null;
        $auto = self::profileDefaultDependents($temperament, $autogen['archetype'] ?? null, $cfg, (array) ($autogen['preset'] ?? []), $vector);

        $current = [
            'maturity_type'    => $dynamics['dimensions']['maturity']['plasticity_type'] ?? null,
            'traits'           => $dynamics['traits'] ?? null,
        ];
        $effective = ['temperament' => $temperament];
        foreach (['maturity_type', 'traits'] as $dep) {
            if (array_key_exists($dep, $overrides)) {
                $effective[$dep] = $overrides[$dep];
            } elseif ($current[$dep] === null || $current[$dep] === ($prevAuto[$dep] ?? null) || $dep === $field) {
                $effective[$dep] = $auto[$dep];    // still automatic (or its override was just cleared)
            } else {
                $effective[$dep] = $current[$dep]; // changed since by something else: keep
            }
        }

        $dynamics['inferred_temperament'] = $effective['temperament'];
        $dynamics['dimensions']['maturity']['plasticity_type'] = $effective['maturity_type'];
        $dynamics['traits'] = $effective['traits'];
        $autogen['auto'] = $auto;
        $dynamics['_profile_autogen'] = $autogen;
        $dynamics = RelDynTraits::syncStored($dynamics);   // trait_vector follows the label (read: recomposed)
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
        $warmthCurve = $temperament !== null ? self::temperamentToWarmthCurve($temperament, $dynamics) : null;

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

    /** C3 (traits design §2.2): the primary love language a temperament implies; others LL_TIME. */
    const TEMPERAMENT_LOVE_LANGUAGE = [
        'Romantic'    => self::LL_WORDS,
        'Jealous'     => self::LL_TIME,
        'Proud'       => self::LL_SERVICE,
        'Humble'      => self::LL_GIFTS,
        'Independent' => self::LL_TIME,
    ];

    /** C3 through the trait engine: the nearest preset's love language (label-valued). */
    private static function temperamentToLoveLanguage($temperament, ?array $dynamics = null)
    {
        return RelDynTraits::labelParam($temperament, self::TEMPERAMENT_LOVE_LANGUAGE, self::LL_TIME, $dynamics);
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

    /** A6 (traits design §2.1): the warmth curve (CURVE_PARAMS) a temperament names. */
    const TEMPERAMENT_WARMTH_CURVES = [
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

    /**
     * A6 through the trait engine: the nearest preset's curve name. (Phase 3 computes the
     * curve numbers from traits every time: RelDynTraits columns warmth_half_life etc.)
     */
    private static function temperamentToWarmthCurve($temperament, ?array $dynamics = null)
    {
        return RelDynTraits::labelParam($temperament, self::TEMPERAMENT_WARMTH_CURVES, self::CURVE_MODERATE, $dynamics);
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
        $floor = self::passionStageFloor($dynamics);

        self::setPassion($dynamics, max($floor, self::getPassion($dynamics) - $decay));
        $dynamics['passion_updated_at'] = $now;
    }

    /**
     * The relationship stage's passion floor (STAGE_PARAMS, passion points), through the
     * Attraction Matrix like every other passion writer (decisions §13): a floor within the
     * spark holds for anyone (the spark is open to anyone); a non-negotiable hard zero holds
     * none (positive exchanges piling up stages never lift passion the NPC cannot feel at
     * all); the part of a floor above the spark holds only while the NPC's curve is met
     * (passion_mult of at least 1: at or past her floors), since above the spark she only
     * warms as fast as the uphill allows; never above the MDD 1.4 passion ceiling. A summary
     * from before the spark: 0 when its passion_mult is 0.
     * The relationship tier's floor (MDD 8.1 tiered governors, RelDynGovernors) joins it once
     * passion has reached it: it holds passion there, it never lifts passion that is below it
     * (MDD 8.3, the decoupling principle: "low passion, high tier" is a real bond, the political
     * marriage). The higher of the two, never above the tier's ceiling (Divorced / Hostile: none).
     */
    public static function passionStageFloor(array $dynamics): float
    {
        $stage = $dynamics['stage'] ?? self::STAGE_EARLY;
        $floor = floatval(self::STAGE_PARAMS[$stage]['floor'] ?? 0);
        $gov = RelDynGovernors::governor($dynamics);
        if ($gov !== null) {
            $held = self::getPassion($dynamics) >= $gov['floor'] ? $gov['floor'] : 0.0;
            $floor = min(max($floor, $held), $gov['ceiling']);
        }
        $a = $dynamics['_attraction'] ?? null;
        if ($floor <= 0.0 || !is_array($a)) return $floor;
        $sparkMult = $a['spark_mult'] ?? ($a['passion_mult'] ?? 1.0);
        if (is_numeric($sparkMult) && floatval($sparkMult) <= 0.0) return 0.0;
        $spark = is_numeric($a['spark'] ?? null) ? floatval($a['spark']) : 0.0;
        if ($floor > $spark && floatval($a['passion_mult'] ?? 1.0) < 1.0) return $spark;
        // never above the MDD 1.4 passion ceiling (RelDynAttraction::gainFactor)
        if (is_numeric($a['passion_ceiling'] ?? null)) $floor = min($floor, floatval($a['passion_ceiling']));
        return $floor;
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
     * No attraction cap here: decisions §13 retired the MDD 6.2 hard cap of 20 (attraction
     * scales gains through attractionPassionFactor instead).
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
        // Won over is reached where passion reaches it, even if a tier's ceiling holds it there
        RelDynAttraction::notePassionReached($dynamics, $value);
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

        // Temperament multiplier (A1, through the trait engine)
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $tempMult = RelDynTraits::param($temperament, 'passion_mult', 1.0, $dynamics);

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

        // The Ick (MDD 6.3): while it lasts the passion multiplier inverts, gains become losses
        // (the eval's passion signal is inverted in applyIckEffects; this is every other gain)
        $amount = floatval($amount);
        if ($amount > 0 && !empty($dynamics['_ick_tracker']['ick_active']) && !empty($cfg['ick_system_enabled'] ?? true)) {
            $amount = -$amount;
            self::log("[ICK] {$source} passion gain inverted: " . round($amount, 3));
        }

        self::setPassion($dynamics, max(0.0, min($max, self::getPassion($dynamics) + $amount)));
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
        $a = is_array($dynamics) ? ($dynamics['_attraction'] ?? null) : null;
        if (self::attractionHoldsDriveAtSpark($a)) {
            $passion = max($passion, floatval($a['spark']));   // see affinityDrivePassion
        }
        return 0.3 + ($passion / 100.0) * 1.7;
    }

    /**
     * The passion (points) the MDD 1.1 affinity drive reads ("0 passion = x0.3 gain, 100 =
     * x2.0"): the NPC's passion, except under an attraction hard zero (orientation, a rigid
     * pillar below its bar, asexual / aromantic / not interested; decisions §13). There passion
     * is structurally 0 (no spark, no gain), which is no verdict on the friendship: the drive
     * reads at least the spark, the passion anyone else gets freely, so a friendship with an
     * NPC who cannot feel passion for the player grows like one at the spark, not idling at
     * x0.3 forever. The same holds for an NPC whose passion only the emotional channels move
     * (asexual, decisions §15: "their affinity growth is not slowed"), and for a preference hard
     * zero while the Matrix does not judge the player (aromantic friendship: not slowed).
     */
    public static function affinityDrivePassion(array $dynamics): float
    {
        $passion = self::getPassion($dynamics);
        $a = $dynamics['_attraction'] ?? null;
        if (self::attractionHoldsDriveAtSpark($a)) {
            return max($passion, floatval($a['spark']));
        }
        return $passion;
    }

    /**
     * Whether the MDD 1.1 affinity drive reads at least the spark (affinityDrivePassion): an
     * attraction hard zero (judged, or a preference's with the Matrix off: withPreference) or
     * passion restricted to the emotional channels (passion_channel, asexual).
     */
    private static function attractionHoldsDriveAtSpark($a): bool
    {
        if (!is_array($a) || !is_numeric($a['spark'] ?? null)) return false;
        $prefZero = str_starts_with((string) ($a['hard_zero'] ?? ''), 'preference:');
        return (!empty($a['hard_zero']) && (!empty($a['enabled']) || $prefZero)) || ($a['passion_channel'] ?? null) === 'emotional';
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

        // Temperament modifier (A3, through the trait engine) x attachment anxiety (the anxiety
        // part of the MDD 1.3 reunion column, moved there in traits phase 3)
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $tempMult = RelDynTraits::param($temperament, 'reunion_mult', 1.0, $dynamics);
        $attachMult = floatval(self::getAttachmentModifier($dynamics, 'reunion_mult') ?? 1.0);
        $spike *= $tempMult * $attachMult;

        $dynamics['reunion_spike_given'] = true;
        $dynamics['_reunion_hours_apart'] = round($hoursApart, 2);   // game-calendar hours, for context.php

        self::log("Reunion spike for NPC: +{$spike} passion (game_hours_apart={$hoursApart}, temp_mult={$tempMult}, attachment_mult={$attachMult})");

        return $spike;
    }

    /**
     * The fall of bleedout for this NPC (A2 redesign, traits phase 3): config bleedout_response
     * at the NPC's own trait vector and attachment axes (RelDynTraits::bleedout). With $apply, the arousal spike and
     * the valence (sign of fight - fear) go through applyDelta (Y overridden to 1: the traits
     * already scale them, the plasticity table must not again); passion is returned for the
     * caller's route (gainPassion when positive, a drain when negative, nothing inside the dead
     * band). No vector: no_vector_passion (today's -1.5 drain), nothing else.
     *
     * @return array ['passion' (passion points), 'valence', 'arousal' (points asked),
     *                'applied' => ['valence' => actual, 'arousal' => actual], 'fight', 'fear', 'net', 'vector' => bool]
     */
    public static function bleedoutResponse(array &$dynamics, bool $apply = false): array
    {
        $cfg = (array) (self::configValue('bleedout_response') ?? []) + self::defaultConfig()['bleedout_response'];
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $x = RelDynTraits::vectorFor($temperament, $dynamics);
        if ($x === null) {
            return ['passion' => floatval($cfg['no_vector_passion']), 'valence' => 0.0, 'arousal' => 0.0,
                    'applied' => ['valence' => 0.0, 'arousal' => 0.0], 'fight' => null, 'fear' => null, 'net' => null, 'vector' => false];
        }
        $r = RelDynTraits::bleedout($x, $cfg, self::getAttachmentAxes($dynamics));
        if (abs($r['passion']) < floatval($cfg['dead_band'])) $r['passion'] = 0.0;
        $r['applied'] = ['valence' => 0.0, 'arousal' => 0.0];
        if ($apply) {
            $flat = ['Y_up' => 1.0, 'Y_down' => 1.0];
            $r['applied']['arousal'] = self::applyDelta('arousal', $dynamics, $r['arousal'], $temperament, $flat);
            $r['applied']['valence'] = self::applyDelta('valence', $dynamics, $r['valence'], $temperament, $flat);
        }
        $r['vector'] = true;
        return $r;
    }

    /**
     * NPC-to-NPC request types (radiant dialogue): the player is not in the exchange, so no
     * RelDyn hook reads or steers the player relationship for them (prerequest, context_pre,
     * context, postrequest). Core's relationship_system handles NPC <-> NPC.
     */
    const RADIANT_REQUEST_TYPES = ['radiant', 'radiantsearchingfriend', 'radiantsearchinghostile',
        'radiantcombathostile', 'minai_force_rechat'];

    /** True for an NPC-to-NPC request ($gameRequest[0] in RADIANT_REQUEST_TYPES). */
    public static function isRadiantRequest($gameRequest): bool
    {
        $type = is_array($gameRequest) ? strtolower(trim((string) ($gameRequest[0] ?? ''))) : '';
        return in_array($type, self::RADIANT_REQUEST_TYPES, true);
    }

    /** Core request types that answer a previous speaker (main.php RECHAT_PREVIOUS_SPEAKER). */
    const PREVIOUS_SPEAKER_REQUEST_TYPES = ['rechat', 'continue', 'continue_group'];

    /**
     * The NPC a rechat / continue / continue_group answers, when that previous speaker is another
     * NPC (not the player, the Narrator or "everyone"); null otherwise or when unknown. Core sets
     * RECHAT_PREVIOUS_SPEAKER after the prerequest hooks (main.php); before that a rechat names
     * its speaker in its own payload ($gameRequest[3] JSON 'speaker'), and a continue /
     * continue_group is answered as core answers it a moment later: the speaker of core's last
     * speech row (coreLastSpeechSpeaker, a SELECT). A continue whose previous speaker is $npcName
     * herself answers nobody else.
     */
    public static function previousNpcSpeaker($gameRequest, string $playerName, ?string $npcName = null): ?string
    {
        if (!is_array($gameRequest)) return null;
        $type = strtolower(trim((string) ($gameRequest[0] ?? '')));
        if (!in_array($type, self::PREVIOUS_SPEAKER_REQUEST_TYPES, true)) return null;
        $prev = trim((string) ($GLOBALS['RECHAT_PREVIOUS_SPEAKER'] ?? ''));
        if ($prev === '' && $type === 'rechat') {
            $payload = json_decode(trim((string) ($gameRequest[3] ?? '')), true);
            $prev = is_array($payload) && is_string($payload['speaker'] ?? null) ? trim($payload['speaker']) : '';
        } elseif ($prev === '' && !array_key_exists('RECHAT_PREVIOUS_SPEAKER', $GLOBALS)) {
            // a continue before core resolved it (the prerequest): core's own source, read now
            $prev = trim((string) (self::coreLastSpeechSpeaker() ?? ''));
        }
        if ($prev === '' || strcasecmp($prev, trim($playerName)) === 0 || strcasecmp($prev, self::PLAYER_RELATIONSHIP_KEY) === 0
            || in_array(mb_strtolower($prev), ['everyone', 'all', 'the narrator', 'narrator'], true)) {
            return null;
        }
        if ($type !== 'rechat' && $npcName !== null && strcasecmp($prev, trim($npcName)) === 0) {
            return null;   // she goes on with her own line
        }
        return $prev;
    }

    /**
     * The speaker of core's last speech row, as main.php reads it for a continue /
     * continue_group (SELECT speaker FROM speech ORDER BY rowid DESC LIMIT 1), or null when
     * there is none or no database. Read only.
     */
    public static function coreLastSpeechSpeaker(): ?string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return null;
        }
        try {
            $row = $db->fetchOne("SELECT speaker FROM speech ORDER BY rowid DESC LIMIT 1");
        } catch (Throwable $e) {
            error_log('[RelDyn] continue: reading core\'s last speech row failed: ' . $e->getMessage());
            return null;
        }
        $speaker = is_array($row) ? trim((string) ($row['speaker'] ?? '')) : '';
        return $speaker !== '' ? $speaker : null;
    }

    /**
     * An NPC-to-NPC exchange: the player is not in it (a radiant round, or a rechat / continue
     * answering another NPC: previousNpcSpeaker). No RelDyn hook reads or steers the player
     * relationship for it; the context hook gives only natural exclusivity's NPC-NPC line
     * (decisions §17). Core's relationship_system handles NPC <-> NPC.
     */
    public static function isNpcExchange($gameRequest, string $playerName, ?string $npcName = null): bool
    {
        return self::isRadiantRequest($gameRequest) || self::previousNpcSpeaker($gameRequest, $playerName, $npcName) !== null;
    }

    // ========== CORE'S REQUEST POLL (roadmap prerequest-on-poll) ==========
    // The AIAgent plugin asks main.php for queued responses with a 'request' event every POLINT
    // real seconds (AIAgent.ini; 1 without one). It carries no NPC profile (HTTPManager::log
    // with no actor sends no &profile=), so main.php loads no NPC and HERIKA_NAME stays
    // conf.php's default. main.php runs the ext prerequest hooks, then processor/comm.php
    // answers it (DataDequeue), logs it to eventlog when time() % 5 == 0 and sets $MUST_END,
    // and main.php ends the request before context_pre / context / postrequest: of RelDyn's
    // hooks it reaches prerequest.php only. RelDyn takes it by its type (whatever NPC name core
    // left in HERIKA_NAME) and runs onPollRequest() only.

    /** $gameRequest[0] of core's poll for queued responses (main.php $fast_commands, comm.php). */
    const POLL_REQUEST_TYPES = ['request'];

    /** True for core's poll for queued responses ($gameRequest[0] in POLL_REQUEST_TYPES). */
    public static function isPollRequest($gameRequest): bool
    {
        $type = is_array($gameRequest) ? strtolower(trim((string) ($gameRequest[0] ?? ''))) : '';
        return in_array($type, self::POLL_REQUEST_TYPES, true);
    }

    /** Defaults for config key 'poll': what RelDyn does on core's poll. */
    public static function pollConfigDefaults(): array
    {
        return [
            // Beat the global play heartbeat (beatPlayClock), so it is current when the player
            // speaks: the turn then reads no backlog and is never capped by a lagging beat.
            'play_clock' => true,
            // Reconcile a save load (RelDynTimeline::reconcileIfLoaded) on the first poll after
            // core restored it, instead of on the first dialogue turn.
            'save_load' => true,
            // Note NPCs the plugin reports in beast / vampire lord form (RelDynCreatures::observeForms,
            // one conf_opts row, no bond touched), so a change between two turns is not missed.
            'creature_forms' => true,
        ];
    }

    /**
     * RelDyn's whole work for one poll: the save-load reconcile, then the play heartbeat beat,
     * then the creature form watch, each behind its 'poll' switch. No bond is read or written and
     * no global is published; while core is still restoring a load, nothing runs (as for a
     * dialogue entry). Its own request scope, closed on return.
     *
     * @return array ['enabled' => bool, 'reconcile' => ?array (reconcileIfLoaded's result),
     *                'play' => ?float (heartbeat total, play gamets; null = no beat),
     *                'creature_forms' => int (NPCs in a beast / vampire lord form now; absent when off)]
     */
    public static function onPollRequest(): array
    {
        self::beginRequest();
        try {
            $out = ['enabled' => self::isEnabled(), 'reconcile' => null, 'play' => null];
            if (!$out['enabled']) {
                return $out;
            }
            $stored = self::configValue('poll');
            $cfg = is_array($stored) ? array_replace(self::pollConfigDefaults(), $stored) : self::pollConfigDefaults();
            if (!empty($cfg['save_load'])) {
                try {
                    $out['reconcile'] = RelDynTimeline::reconcileIfLoaded();
                } catch (Throwable $e) {
                    self::logError('save-load reconcile on poll', $e);
                }
                if (!empty($out['reconcile']['deferred'])) {
                    return $out;   // core is still restoring the load: leave RelDyn state alone
                }
            }
            if (!empty($cfg['play_clock'])) {
                $out['play'] = self::beatPlayClock();
            }
            if (!empty($cfg['creature_forms'])) {
                try {
                    $out['creature_forms'] = RelDynCreatures::observeForms();
                } catch (Throwable $e) {
                    self::logError('creature form watch on poll', $e);
                }
            }
            return $out;
        } finally {
            self::endRequest();
        }
    }

    /** CHIM request types in which the player speaks to the NPC (core's inputtext family). */
    const PLAYER_INPUT_REQUEST_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];

    /**
     * True when this request is the player speaking to the NPC ($gameRequest[0] in
     * PLAYER_INPUT_REQUEST_TYPES). Radiant / rechat rounds (NPC to NPC) and NPC-initiated
     * remarks are not: whatever must be said to the player waits for the player's turn.
     */
    public static function isPlayerInputRequest($gameRequest): bool
    {
        $type = is_array($gameRequest) ? strtolower(trim((string) ($gameRequest[0] ?? ''))) : '';
        return in_array($type, self::PLAYER_INPUT_REQUEST_TYPES, true);
    }

    /**
     * Record contact with the player now: this NPC's request is being handled. Stamps the
     * game calendar (absence, reunion, neglect) and the play clock (reunion's check that
     * the time apart held real play). Call after checkReunion() and after the calendar
     * step for this NPC, which both measure the time since the previous contact.
     */
    public static function markContact(array &$dynamics): void
    {
        // The contact before this one (raw gamets): walkawayReason() tells the return from an
        // absence (this contact ends it) from a later line of the same conversation.
        if (isset($dynamics['_last_contact_gamets'])) {
            $dynamics['_previous_contact_gamets'] = $dynamics['_last_contact_gamets'];
        }
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
     * Who this NPC is when the player stays away (rulings 2026-09-24 §8): codependence
     * (0..1), pride (0..1), maturity (0..100) and the grace / rate multipliers they give.
     * Formula and units: neglectSeverityDefaults(). Pure: reads only $dynamics and config.
     *
     * @return array ['codependence', 'pride', 'maturity', 'grace_mult', 'rate_mult', 'ceiling' (resentment points)]
     */
    public static function getNeglectProfile(array $dynamics): array
    {
        $cfg = self::getNeglectSeverityConfig();
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $traits = self::getTraits($dynamics);
        $clamp01 = fn(float $v): float => max(0.0, min(1.0, $v));

        $w = $clamp01(floatval($cfg['codependence_attachment_weight']));
        // A = the codependence_attachment corners read at the NPC's axes (attachmentBlend)
        $a = self::attachmentBlend($dynamics, (array) $cfg['codependence_attachment'], 0.5);
        // A20 from possessiveness (phase 3) and A21 (0.5 x egocentric(Pd), Rule R), through the trait engine.
        // T reads the NPC's own vector (or its own label's preset point), never the Stoic stand-in
        // for a missing temperament: no vector keeps codependence_temperament_default (design §3.6)
        $vector = RelDynTraits::vectorFor($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null, $dynamics);
        $t = $vector !== null
            ? self::codependenceFromPossessiveness(floatval($vector['Po']), (array) $cfg['codependence_possessiveness'])
            : floatval($cfg['codependence_temperament_default']);
        $c = $w * $a + (1.0 - $w) * $t;
        $p = floatval(RelDynTraits::tableParam($temperament, (array) $cfg['pride_temperament'], 0.0, 'R',
            fn(array $x) => 0.5 * RelDynTraits::egocentric($x['Pd']), 'unit01', $dynamics));
        foreach ($traits as $trait) {
            $p += floatval(((array) $cfg['pride_traits'])[$trait] ?? 0.0);
        }
        $c = $clamp01($c);
        $p = $clamp01($p);

        $maturity = max(0.0, min(100.0, floatval($dynamics['dimensions']['maturity']['x'] ?? 50)));
        $terms = ['codependence' => 2.0 * $c - 1.0, 'maturity' => ($maturity - 50.0) / 50.0, 'pride' => $p];
        $mult = function (array $coef) use ($terms, $cfg): float {
            $exp = 0.0;
            foreach ($terms as $k => $v) {
                $exp += floatval($coef[$k] ?? 0.0) * $v;
            }
            return max(floatval($cfg['mult_min']), min(floatval($cfg['mult_max']), 2.0 ** $exp));
        };
        $ceiling = floatval($cfg['ceiling_base']);   // resentment points
        foreach ($terms as $k => $v) {
            $ceiling += floatval(((array) $cfg['ceiling_points'])[$k] ?? 0.0) * $v;
        }
        $rangeMax = floatval(self::getDimensionDefinition('resentment')['range_max'] ?? 100);

        return [
            'codependence' => $c,
            'pride' => $p,
            'maturity' => $maturity,
            'grace_mult' => $mult((array) $cfg['grace_log2']),
            'rate_mult' => $mult((array) $cfg['rate_log2']),
            'ceiling' => max(0.0, min($rangeMax, $ceiling)),
        ];
    }

    /**
     * A20 (traits phase 3): the codependence of a possessiveness Po (0..1), unitless 0..1:
     * 0.5 - 0.5 (1 - smoothstep(Po; low)) + 0.5 smoothstep(Po; high). $edges: ['low' => [a, b],
     * 'high' => [a, b]] (codependence_possessiveness).
     */
    public static function codependenceFromPossessiveness(float $po, array $edges): float
    {
        [$la, $lb] = array_map('floatval', (array) ($edges['low'] ?? [0.10, 0.30]));
        [$ha, $hb] = array_map('floatval', (array) ($edges['high'] ?? [0.55, 0.90]));
        $t = 0.5 - 0.5 * (1.0 - RelDynTraits::smoothstep($po, $la, $lb)) + 0.5 * RelDynTraits::smoothstep($po, $ha, $hb);
        return max(0.0, min(1.0, $t));
    }

    /**
     * Advance one NPC through game-calendar time [from, to] (raw gamets) with no contact:
     *  - fester: open conflict + maturity below fester_maturity_below -> raw resentment per day;
     *  - jealousy: jealousy above jealousy_resentment_above -> raw resentment per day (§5);
     *  - neglect: bonded NPC past its grace since _last_contact_gamets -> raw resentment per
     *    day, logged as one 'neglect' grievance per absence; grace and rate scaled per NPC
     *    and by the fulfillment band at the last contact (absenceBandFactors), resentment
     *    from neglect stops at the NPC's ceiling (getNeglectProfile);
     *  - passion fade: past the absence grace, passion fades per day x attachment multiplier
     *    down to the stage floor;
     *  - warmth fade: warmth above its baseline, at its own rate, grace and rate scaled per
     *    NPC like neglect.
     * Nothing negative is ever reduced here. Neglect and fade are skipped while the NPC is
     * the one who left (walkaway). Pure: no database, no clock reads.
     *
     * @return array ['game_days', 'resentment_raw', 'resentment', 'jealousy_resentment_raw', 'neglect_days', 'neglect_ceiling', 'passion_fade', 'warmth_fade', 'bond_type']
     */
    public static function advanceCalendar(array &$dynamics, float $fromGamets, float $toGamets): array
    {
        $out = ['game_days' => 0.0, 'resentment_raw' => 0.0, 'resentment' => 0.0, 'jealousy_resentment_raw' => 0.0,
                'neglect_days' => 0.0, 'neglect_ceiling' => null, 'passion_fade' => 0.0, 'warmth_fade' => 0.0, 'bond_type' => null];
        if ($fromGamets <= 0 || $toGamets <= $fromGamets) {
            return $out;
        }
        $days = ($toGamets - $fromGamets) / self::GAMETS_PER_DAY;
        $out['game_days'] = $days;

        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);   // 0..100
        $away = ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal';
        $lastContact = floatval($dynamics['_last_contact_gamets'] ?? 0);       // raw gamets
        $raw = 0.0;                                                            // raw resentment points (fester)
        $neglectRaw = 0.0;                                                     // raw resentment points (neglect)

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

        // Who this NPC is when left alone (rulings §8): scales neglect and warmth fade.
        $severity = self::getNeglectProfile($dynamics);
        // How fulfilled the bond was at the last contact (rulings §9): a high band buffers the
        // absence (longer grace, slower), a low one makes it bite sooner and harder.
        $bandScale = self::absenceBandFactors($dynamics);

        // Neglect: game days past the bond's grace since the player's last contact.
        if (!$away && $lastContact > 0 && self::configValue('neglect_enabled')) {
            $bondType = self::getRelationshipType('', $dynamics);
            $out['bond_type'] = $bondType;
            $bond = (self::configValue('neglect_bond_types') ?? [])[$bondType] ?? null;
            if (is_array($bond)) {
                $graceDays = floatval($bond['grace_game_days'] ?? 0) * $severity['grace_mult'] * $bandScale['grace'];   // game days
                $neglectDays = self::calendarDaysFrom($fromGamets, $toGamets, $lastContact + $graceDays * self::GAMETS_PER_DAY);
                if ($neglectDays > 0) {
                    // raw resentment points per game day x game days
                    $neglectRaw = floatval($bond['resentment_per_game_day'] ?? 0) * $severity['rate_mult'] * $bandScale['rate'] * $neglectDays;
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
                // the style corners read at the NPC's axes (attachmentBlend)
                $mult = self::attachmentBlend($dynamics, (array) (self::configValue('passion_absence_attachment_mult') ?? []), 1.0);
                $floor = self::passionStageFloor($dynamics);
                $passion = self::getPassion($dynamics);
                if ($passion > $floor) {
                    $fade = floatval(self::configValue('passion_absence_fade_per_game_day')) * $absentDays * $mult;
                    $new = max($floor, $passion - $fade);
                    self::setPassion($dynamics, $new);
                    $out['passion_fade'] = $passion - $new;
                }
            }

            // Warmth (0..100) above its baseline fades at its own rate, grace and rate scaled
            // per NPC like neglect (rulings §8).
            $warmth = $dynamics['dimensions']['warmth']['x'] ?? null;
            $graceGamets = floatval(self::configValue('warmth_absence_grace_game_hours')) * $severity['grace_mult']
                * self::GAMETS_PER_DAY / 24.0;
            $absentDays = self::calendarDaysFrom($fromGamets, $toGamets, $lastContact + $graceGamets);
            if (is_numeric($warmth) && $absentDays > 0) {
                $baseline = $dynamics['dimensions']['warmth']['baseline'] ?? null;
                $baseline = is_numeric($baseline) ? floatval($baseline) : self::getTemperamentBaseline($temperament, 'warmth', $dynamics);
                if (floatval($warmth) > $baseline) {
                    $fade = floatval(self::configValue('warmth_absence_fade_per_game_day')) * $absentDays * $severity['rate_mult'];
                    $new = max($baseline, floatval($warmth) - $fade);
                    $dynamics['dimensions']['warmth']['x'] = round($new, 6);
                    $out['warmth_fade'] = floatval($warmth) - $new;
                }
            }
        }

        // Feed resentment in fixed quanta (see CALENDAR_RESENTMENT_QUANTUM). Fester has the
        // whole range; neglect has its own buffer and stops at this NPC's neglect ceiling
        // (rulings §8, getNeglectProfile): an absence alone takes them only that far.
        $out['resentment_raw'] = $raw + $neglectRaw;
        $out['neglect_ceiling'] = $severity['ceiling'];
        $max = floatval(self::getDimensionDefinition('resentment')['range_max'] ?? 100);
        $out['resentment'] += self::drainCalendarResentment($dynamics, '_calendar_resentment_raw', $raw, $max, $temperament);
        $felt = self::drainCalendarResentment($dynamics, '_calendar_neglect_raw', $neglectRaw, min($max, $severity['ceiling']), $temperament);
        $out['resentment'] += $felt;
        if ($felt > 0.0) {
            // This absence (counted from that contact) grew their resentment: a walkaway that
            // starts on the player's return from it is a neglect walkaway (walkawayReason).
            $dynamics['_neglect_resentment_since_gamets'] = $lastContact;   // raw gamets
        }

        return $out;
    }

    /**
     * Add $raw resentment points to the buffer $bufferKey and apply it to resentment in
     * CALENDAR_RESENTMENT_QUANTUM steps through applyDelta, never past $cap (resentment
     * points). At the cap the buffer is dropped: nothing left to feel from this source.
     *
     * @return float resentment points actually applied
     */
    private static function drainCalendarResentment(array &$dynamics, string $bufferKey, float $raw, float $cap, ?string $temperament): float
    {
        $applied = 0.0;
        $buffer = floatval($dynamics[$bufferKey] ?? 0) + $raw;
        while ($buffer >= self::CALENDAR_RESENTMENT_QUANTUM - 1e-9) {
            $room = $cap - floatval($dynamics['dimensions']['resentment']['x'] ?? 0);
            if ($room <= 1e-9) {
                $buffer = 0.0;
                break;
            }
            $applied += self::applyDelta('resentment', $dynamics, self::CALENDAR_RESENTMENT_QUANTUM, $temperament, ['max_abs' => $room]);
            $buffer -= self::CALENDAR_RESENTMENT_QUANTUM;
        }
        $dynamics[$bufferKey] = round(max(0.0, $buffer), 9);
        return $applied;
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

    // =========================================================================
    // FULFILLMENT (rulings 2026-09-24 §9: neglect is the absence of fulfillment)
    // =========================================================================

    /**
     * The neglect_bond_types row of this NPC's bond with the player (getRelationshipType), or
     * null when the bond is not one whose neglect matters (strangers, acquaintances, rivals):
     * such an NPC gets no fulfillment state, no relationship deprivation, no unmet-needs line.
     */
    public static function neglectBond(array $dynamics): ?array
    {
        $bond = (self::configValue('neglect_bond_types') ?? [])[self::getRelationshipType('', $dynamics)] ?? null;
        return is_array($bond) ? $bond : null;
    }

    /**
     * Absence scaling by the fulfillment band at the last contact (the band a contact left
     * behind, _fulfillment.contact_band, -1..+1): grace x 2^(g x band), rate x 2^(r x band),
     * g / r = fulfillment.absence_band_log2. 1 and 1 when no band is known or fulfillment is off.
     *
     * @return array ['grace' => multiplier, 'rate' => multiplier, 'band' => ?float]
     */
    public static function absenceBandFactors(array $dynamics): array
    {
        $out = ['grace' => 1.0, 'rate' => 1.0, 'band' => null];
        $band = RelDynFulfillment::pairState($dynamics)['contact_band'] ?? null;   // the player pair
        if (!is_numeric($band) || !RelDynFulfillment::enabled()) return $out;
        $band = max(-1.0, min(1.0, floatval($band)));
        $l = (array) RelDynFulfillment::config()['absence_band_log2'];
        return ['grace' => 2.0 ** (floatval($l['grace'] ?? 0) * $band), 'rate' => 2.0 ** (floatval($l['rate'] ?? 0) * $band), 'band' => $band];
    }

    /**
     * Game time (raw gamets) at which this bond's absence grace runs out: the last contact +
     * grace_game_days of its bond type x the NPC's grace_mult (getNeglectProfile) x the
     * fulfillment band factor (absenceBandFactors). Null when there is no contact yet or the
     * bond's neglect does not matter (neglectBond). Same grace as advanceCalendar's.
     */
    public static function neglectGraceEndGamets(array $dynamics): ?float
    {
        $lastContact = floatval($dynamics['_last_contact_gamets'] ?? 0);   // raw gamets
        if ($lastContact <= 0) return null;
        $bond = self::neglectBond($dynamics);
        if ($bond === null) return null;
        $graceDays = floatval($bond['grace_game_days'] ?? 0) * self::getNeglectProfile($dynamics)['grace_mult']
            * self::absenceBandFactors($dynamics)['grace'];
        return $lastContact + $graceDays * self::GAMETS_PER_DAY;
    }

    /**
     * Low fulfillment while the player is around is neglect (rulings §9). For each sampled
     * game-day end [gamets, band] of a game day the player actually had contact with this NPC
     * (RelDynFulfillment::recordContactDay; a day away is absence: inside the grace it is
     * excused, past it advanceCalendar's absence neglect counts), raw resentment for that game day =
     *     bond resentment_per_game_day x unfulfilled_rate_mult x the NPC's neglect rate_mult
     *     x depth,   depth = clamp((low_band - band) / (low_band + 1), 0, 1)
     * through the neglect buffer, so it stops at the NPC's neglect ceiling (getNeglectProfile:
     * a mature NPC's anger is capped there; an immature, codependent one can reach the
     * walkaway). Bond types not in neglect_bond_types and a walked-away NPC accrue nothing.
     *
     * @return array ['raw' => raw resentment points, 'resentment' => points applied, 'days' => game days charged]
     */
    public static function chargeUnfulfilledNeglect(array &$dynamics, array $samples): array
    {
        $out = ['raw' => 0.0, 'resentment' => 0.0, 'days' => 0];
        if (!self::configValue('neglect_enabled') || ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal') return $out;
        $lastContact = floatval($dynamics['_last_contact_gamets'] ?? 0);   // raw gamets
        if ($lastContact <= 0) return $out;
        $bond = self::neglectBond($dynamics);
        if ($bond === null) return $out;

        $cfg = RelDynFulfillment::config();
        $severity = self::getNeglectProfile($dynamics);
        $low = floatval($cfg['low_band']);
        $rate = floatval($bond['resentment_per_game_day'] ?? 0) * floatval($cfg['unfulfilled_rate_mult']) * $severity['rate_mult'];
        $fstate = RelDynFulfillment::pairState($dynamics) ?? [];   // the player pair
        foreach ($samples as [$t, $band]) {
            if (!RelDynFulfillment::wasPresentOn($fstate, RelDynFulfillment::gameDayEndedAt(floatval($t)))) continue;   // away that day
            $depth = max(0.0, min(1.0, ($low - floatval($band)) / max(0.001, $low + 1.0)));
            if ($depth <= 0.0) continue;
            $out['raw'] += $rate * $depth;   // raw resentment points for one game day
            $out['days']++;
        }
        if ($out['raw'] <= 0.0) return $out;

        $max = floatval(self::getDimensionDefinition('resentment')['range_max'] ?? 100);
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $out['resentment'] = self::drainCalendarResentment($dynamics, '_calendar_neglect_raw', $out['raw'], min($max, $severity['ceiling']), $temperament);
        $state = RelDynFulfillment::pairState($dynamics) ?? [];
        $since = floatval($state['low_since_gamets'] ?? $samples[0][0]);
        $now = floatval(end($samples)[0]);
        self::recordUnfulfilledGrievance($dynamics, $since, $now, $out['raw'], RelDynFulfillment::unmetPhrases($state, $now, 2, $cfg));
        return $out;
    }

    /** One 'neglect' grievance (kind 'unfulfilled') per low stretch, keyed by when it began, kept current. */
    private static function recordUnfulfilledGrievance(array &$dynamics, float $sinceGamets, float $nowGamets, float $raw, array $unmet): void
    {
        if (!isset($dynamics['dimensions']['resentment']) || !is_array($dynamics['dimensions']['resentment'])) {
            $dynamics['dimensions']['resentment'] = ['x' => 0, 'baseline' => 0, 'active' => true];
        }
        $log = $dynamics['dimensions']['resentment']['grievance_log'] ?? [];
        if (!is_array($log)) $log = [];
        $text = 'neglect: needs unmet' . ($unmet ? ' (' . implode(', ', $unmet) . ')' : '');
        $found = null;
        foreach ($log as $i => $entry) {
            if (is_array($entry) && ($entry['kind'] ?? null) === 'unfulfilled'
                && abs(floatval($entry['since_gamets'] ?? -1) - $sinceGamets) < 0.5) {
                $found = $i;
            }
        }
        if ($found !== null) {
            $log[$found]['raw'] = round(floatval($log[$found]['raw'] ?? 0) + $raw, 4);
            $log[$found]['gamets'] = $nowGamets;
            $log[$found]['text'] = $text;
        } else {
            $log[] = ['text' => $text, 'tag' => 'neglect', 'kind' => 'unfulfilled', 'since_gamets' => $sinceGamets,
                      'raw' => round($raw, 4), 'gamets' => $nowGamets];
        }
        $dynamics['dimensions']['resentment']['grievance_log'] = array_slice($log, -10);
    }

    /**
     * Move this NPC's fulfillment to $now (raw gamets): on contact ($contact, the prerequest)
     * with a bond whose neglect matters (neglectBond) create or refresh the needs vector first; sample day-ends, charge unfulfilled neglect,
     * run the mature boundary (RelDynFulfillment::tick); carry out a failed probation's
     * step-back on core (boundaryStepBack); on contact record the band this contact leaves
     * behind (absenceBandFactors reads it for the next absence). No state and no contact: nothing.
     *
     * The player pair (rulings §11: fulfillment is per relationship pair). Presence
     * ($present, default $contact) is an actual interaction within the pair
     * (isPairInteraction): only then does today count as a day the player was there.
     *
     * @return array ['changed' => bool, 'events' => string[], 'step_back' => ?array, 'band' => ?float]
     */
    public static function advanceFulfillment(string $npcName, array &$dynamics, float $now, bool $contact = false, ?bool $present = null): array
    {
        $out = ['changed' => false, 'events' => [], 'step_back' => null, 'band' => null];
        if ($now <= 0 || !RelDynFulfillment::enabled()) return $out;
        if ($contact && self::neglectBond($dynamics) !== null) {
            $out['changed'] = RelDynIntimacy::ensureNeed($npcName, $dynamics);
            $out['changed'] = RelDynFulfillment::ensure($dynamics, RelDynFacets::preferences($dynamics, $npcName), $now) || $out['changed'];
        }
        if (RelDynFulfillment::pairState($dynamics) === null) return $out;
        if ($present ?? $contact) {
            // Today counts as a day the pair interacted (unfulfilled neglect, the low stretch)
            $out['changed'] = RelDynFulfillment::recordContactDay($dynamics, $now) || $out['changed'];
        }

        $tick = RelDynFulfillment::tick($dynamics, $now);
        $out['events'] = $tick['events'];
        $out['changed'] = $out['changed'] || $tick['changed'];
        // Attachment drift (decisions §12, MDD 6.1 Earned Security): sustained fulfillment or
        // its absence, day by day, and a mature boundary the player honoured.
        if (self::driftAttachmentFromDays($dynamics, $tick['samples'], $npcName)) $out['changed'] = true;
        if (in_array('resolved', $tick['events'], true)
            && self::attachmentExperience($dynamics, 'boundary_kept', $now, 1.0, $npcName)) {
            $out['changed'] = true;
        }
        if ((RelDynFulfillment::pairState($dynamics)['boundary']['state'] ?? null) === 'failed') {
            $out['step_back'] = self::boundaryStepBack($npcName, $dynamics, $now);
            $out['changed'] = true;
        }
        $state = RelDynFulfillment::pairState($dynamics);
        $band = RelDynFulfillment::bandAt($state, $now);
        $out['band'] = $band;
        if ($contact) {
            $state['contact_band'] = $band;
            RelDynFulfillment::setPairState($dynamics, RelDynFulfillment::PLAYER, $state);
            $out['changed'] = true;
        }
        foreach ($out['events'] as $event) {
            self::log("[FULFILL] {$npcName}: {$event} (band " . round($band, 2) . ')');
        }
        if (!empty($tick['neglect']['raw'])) {
            self::log("[FULFILL] {$npcName}: unfulfilled neglect raw " . round($tick['neglect']['raw'], 3)
                . " over {$tick['neglect']['days']} game day(s), resentment +" . round($tick['neglect']['resentment'], 3));
        }
        return $out;
    }

    /**
     * A mature NPC's failed probation (rulings §9): a deliberate step-back of core's
     * relationships.Player.type (fulfillment.step_back_types), with its reason, under core's
     * lock (changeCoreRelationshipType). Success: the snapshot _core_rel_type follows and the
     * boundary closes with the step-back to say once; a refused write (relationships_locked,
     * core changed the type meanwhile) closes it without a step-back. Either way the low
     * stretch starts over, so nothing is retried every tick.
     *
     * @return array ['from' => core type, 'to' => ?core type, 'written' => bool, 'reason' => string]
     */
    private static function boundaryStepBack(string $npcName, array &$dynamics, float $now): array
    {
        $cfg = RelDynFulfillment::config();
        $state = RelDynFulfillment::pairState($dynamics);
        $from = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $to = RelDynFulfillment::stepBackTarget($dynamics, $cfg);
        $unmet = RelDynFulfillment::unmetPhrases($state, $now, 2, $cfg);
        $reason = sprintf('mature boundary: stated calmly, then a probation of %s game days without %d consistent days of change%s',
            rtrim(rtrim(number_format(floatval($cfg['probation_game_days']), 1, '.', ''), '0'), '.'),
            intval($cfg['consistent_game_days']), $unmet ? ' (unmet: ' . implode(', ', $unmet) . ')' : '');
        $written = $to !== null && self::changeCoreRelationshipType($npcName, $to, $reason, $from);

        unset($state['low_since_gamets']);
        if ($written) {
            $dynamics['_core_rel_type'] = $to;
            $state['boundary'] = ['state' => 'none', 'stepped_back_gamets' => $now, 'from' => $from, 'to' => $to, 'say' => 'step_back'];
            $log = is_array($state['step_backs'] ?? null) ? $state['step_backs'] : [];
            $log[] = ['from' => $from, 'to' => $to, 'gamets' => $now, 'reason' => $reason];
            $state['step_backs'] = array_slice($log, -5);
        } else {
            $state['boundary'] = ['state' => 'none', 'blocked_gamets' => $now, 'from' => $from, 'to' => $to];
            error_log("[RelDyn-FULFILL] {$npcName}: probation failed but the step-back {$from} -> " . ($to ?? '-') . " was not written; boundary closed");
        }
        RelDynFulfillment::setPairState($dynamics, RelDynFulfillment::PLAYER, $state);
        return ['from' => $from, 'to' => $to, 'written' => $written, 'reason' => $reason];
    }

    /**
     * Shared contract (fulfillment lane): the NPC's needs, coverage per axis, band and trend at
     * $now (default: the game clock). Needs come from the stored state; before the first
     * contact they are derived from the NPC's preferences and read neutral.
     *
     * @return array ['needs' => axis => 0..1, 'coverage' => axis => -1..1, 'band' => -1..1,
     *                'trend' => band per game day, 'known' => bool, 'low_band' => bool]
     */
    public static function fulfillment(string $npcName, array $dynamics, ?float $now = null, string $target = RelDynFulfillment::PLAYER): array
    {
        $now = $now ?? self::currentGamets();
        $prefs = is_array(RelDynFulfillment::pairState($dynamics, $target)['w'] ?? null) ? [] : RelDynFacets::preferences($dynamics, $npcName);
        return RelDynFulfillment::compute($dynamics, $prefs, $now, $target);
    }

    /**
     * Spider-graph read API (api_fulfillment.php, for the P5 UI): RelDynFulfillment::graph of a
     * stored NPC's pair with $target (the player by default).
     */
    public static function fulfillmentGraph(string $npcName, ?float $now = null, string $target = RelDynFulfillment::PLAYER): array
    {
        $dynamics = self::getDynamics($npcName);
        $now = $now ?? self::currentGamets();
        $prefs = is_array(RelDynFulfillment::pairState($dynamics, $target)['w'] ?? null) ? [] : RelDynFacets::preferences($dynamics, $npcName);
        return RelDynFulfillment::graph($npcName, $dynamics, $prefs, $now, $target);
    }

    /**
     * Is this request an actual interaction of the player pair (rulings §11: presence is
     * interaction within the pair, "a follower you never talk to is not fulfilling")? The player
     * speaking to the NPC (isPlayerInputRequest), or intimacy with the player the plugin
     * reports (RelDynIntimacy::requestKind). An NPC's own remark, a radiant round or a
     * bystander's turn is not.
     */
    public static function isPairInteraction($gameRequest, string $playerName): bool
    {
        if (!is_array($gameRequest)) return false;
        return self::isPlayerInputRequest($gameRequest) || RelDynIntimacy::requestKind($gameRequest, $playerName) !== null;
    }

    /**
     * An exchange the local classifier read as a love language (the eval did not score it):
     * fulfillment.legacy_love_language_units to that axis, plus (with $intimacyAxes) the
     * intimacy axes of the tag the eval would have given (legacy_love_language_tag: a hug is
     * 'touch') x the same units. A request the plugin reports as intimacy (a scene, a VR touch)
     * feeds the intimacy axes itself (RelDynIntimacy::recordRequest): pass false for it.
     * Returns the units applied.
     */
    public static function recordLoveLanguageFulfillment(array &$dynamics, ?string $loveLanguage, float $now, bool $intimacyAxes = true): array
    {
        if ($loveLanguage === null) return [];
        $cfg = RelDynFulfillment::config();
        $units = floatval($cfg['legacy_love_language_units']);
        $amounts = [$loveLanguage => $units];
        $tag = $intimacyAxes ? (((array) ($cfg['legacy_love_language_tag'] ?? []))[$loveLanguage] ?? null) : null;
        foreach ((array) (((array) $cfg['tag_delivery'])[$tag] ?? []) as $axis => $u) {
            if (RelDynIntimacy::isAxis((string) $axis) && is_numeric($u)) $amounts[$axis] = ($amounts[$axis] ?? 0.0) + floatval($u) * $units;
        }
        return RelDynFulfillment::deliver($dynamics, $amounts, $now);
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
        // Fulfillment (rulings §9): day-end samples, unfulfilled neglect and the mature boundary
        // move with the calendar for every bond that has a fulfillment state, talked to or not.
        if (RelDynFulfillment::pairState($dyn) !== null) {
            $f = self::advanceFulfillment($npcName, $dyn, $now, false);
            $result['fulfillment'] = $f;
            $changed = $changed || $f['changed'];
        }
        // Grief phases, an unstable window nobody came to, the parasite's passion half-life:
        // they run on the game calendar whether or not the player is around (reldyn_protocols.php)
        if (RelDynProtocols::calendarTick($npcName, $dyn, $now)) {
            $result['protocols'] = true;
            $changed = true;
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
            // Repair after conflict is an attachment experience (MDD 6.1 Earned Security)
            self::attachmentExperience($dynamics, 'repair', self::currentGamets());

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
    public static function getReunionText($npcName, $temperament, $hoursApart, $player, ?array $dynamics = null)
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

        $set = RelDynTraits::labelParam($temperament, $texts, $default, $dynamics);   // D1: nearest preset's text set
        return $set[$tier] ?? null;
    }

    // =========================================================================
    // EFFECTIVE DISPOSITION
    // =========================================================================

    /**
     * Calculate effective sex_disposal with passion and jealousy overlay. Passion that only the
     * emotional channels move (asexual, decisions §15) is no sexual arousal: it adds nothing.
     */
    public static function getEffectiveDisposition($baseDisposal, $dynamics)
    {
        $passion = floatval($dynamics['passion'] ?? 0);
        if (($dynamics['_attraction']['passion_channel'] ?? null) === 'emotional') $passion = 0.0;
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

            // Core 3.4.1 combat events (coreCombatState). Health: the plugin's live stats report
            // (RelDynCombat::npcHealth), read only while she is fighting now; no report = null
            // (unknown, never assumed healthy or hurt).
            $state = self::coreCombatState($npcName);
            $inCombat = $state['in_combat'];
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
            $healthPct = $inCombat ? RelDynCombat::npcHealth($npcName) : null;

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

    /**
     * The glow after a fight (MDD §3.3): a short summary when this NPC was in a core combat row
     * (death / bleedout / combat end, by the row's people) within POST_COMBAT_GLOW_GAMETS (5 min
     * of play) before now on the game clock; null otherwise, and null when the clock is unknown.
     * April read the last 10 combat rows with no time window, so one fight glowed forever.
     */
    public static function getRecentCombatSummary($npcName)
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            if (!$db) return null;
            $now = self::currentGamets();
            if ($now <= 0) return null;

            $player = $GLOBALS['PLAYER_NAME'] ?? 'the player';
            $combatTypes = "'death','bleedout','combatend','combatendmighty'";
            $since = intval($now - self::POST_COMBAT_GLOW_GAMETS);
            $until = intval($now);

            $rows = $db->fetchAll(
                "SELECT type, data, people FROM eventlog WHERE type IN ({$combatTypes}) AND gamets > {$since} AND gamets <= {$until}"
                . " ORDER BY rowid DESC LIMIT 10"
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

        // ========== WARMTH (emotional openness, 0..100) ==========
        // Starts at the temperament baseline (TEMPERAMENT_BASELINES warmth), like trust and
        // comfort. defaultDynamics() holds x = 0 with baseline null as an "unset" placeholder:
        // read as a real value it made every newly met NPC 'Walled'. A blob whose warmth
        // baseline was never set and whose x is still that placeholder (0, no reason recorded)
        // takes the baseline; a warmth something already moved keeps its x. Only with a known
        // temperament: before the profile auto-generation, ensureTemperamentProfile seeds it
        // (TEMPERAMENT_SEEDED_DIMENSIONS), so no fallback seed has to be recognised later.
        $warmthTemperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        if (($dynamics['dimensions']['warmth']['baseline'] ?? null) === null && is_string($warmthTemperament) && $warmthTemperament !== '') {
            $temperament = $warmthTemperament;
            $base = RelationshipDynamics::getTemperamentBaseline($temperament, 'warmth', $dynamics);
            $wx = $dynamics['dimensions']['warmth']['x'] ?? null;
            $placeholder = $wx === null || (is_numeric($wx) && abs(floatval($wx)) < 0.0001
                && empty($dynamics['dimensions']['warmth']['last_reason']) && empty($dynamics['dimensions']['warmth']['last_delta']));
            $dynamics['dimensions']['warmth']['baseline'] = $base;
            if ($placeholder) {
                $dynamics['dimensions']['warmth']['x'] = $base;
            }
        }

        // ========== MATURITY DIMENSION (PR 3) ==========
        // Initialize maturity from temperament baseline if not yet set
        if (($dynamics['dimensions']['maturity']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['maturity']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'maturity', $dynamics);
            $dynamics['dimensions']['maturity']['baseline'] = $dynamics['dimensions']['maturity']['x'];

            // Assign plasticity type from temperament if not already set
            if (empty($dynamics['dimensions']['maturity']['plasticity_type'])) {
                $dynamics['dimensions']['maturity']['plasticity_type'] = RelationshipDynamics::getMaturityPlasticityType($temperament, $dynamics);
            }
        }
        // Ensure plasticity_type is populated even if x was already set (backfill)
        if (empty($dynamics['dimensions']['maturity']['plasticity_type'])) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['maturity']['plasticity_type'] = RelationshipDynamics::getMaturityPlasticityType($temperament, $dynamics);
        }
        // Ensure 'active' flag is set (forward-compat for existing data)
        if (!isset($dynamics['dimensions']['maturity']['active'])) {
            $dynamics['dimensions']['maturity']['active'] = true;
        }


        // ========== TRUST DIMENSION (PR 4) ==========
        // Initialize trust from temperament baseline if not yet set
        if (($dynamics['dimensions']['trust']['x'] ?? null) === null) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
            $dynamics['dimensions']['trust']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'trust', $dynamics);
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
            $dynamics['dimensions']['comfort']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'comfort', $dynamics);
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
            $dynamics['dimensions']['respect']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'respect', $dynamics);
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
            $dynamics['dimensions']['coord_m']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'coord_m', $dynamics);
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
            $dynamics['dimensions']['coord_f']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'coord_f', $dynamics);
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
            $dynamics['dimensions']['self_confidence']['x'] = RelationshipDynamics::getTemperamentBaseline($temperament, 'self_confidence', $dynamics);
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
            '_attachment_shift_available' => false,
            self::ATTACHMENT_DRIFT_KEY => null,
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
            '_internal_weather' => 'clear',
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

    /**
     * Core relationship types this bond cannot hold now. One source: the Attraction Matrix
     * summary (relationship preference filter + pillars + earned tier ceiling), refreshed by
     * updateAttraction() each request. $chimAffinity is unused (kept for old callers).
     */
    public static function getBlockedTypes($dynamics, $chimAffinity = null)
    {
        return array_values(array_map('strval', (array) ($dynamics['_attraction']['blocked_types'] ?? [])));
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
     * Keywords are behavioral: what the NPC DOES, never a label or a mechanic (decisions
     * 2026-09-23 §3); 'them' is the player. RelDynFelt renders them, the intensity engine on
     * top; the extreme low maturity / resentment_self bands are hand-degraded.
     *
     * Special dimensions (mf, arousal_valence) use separate helper methods
     * because they combine two axes into a single behavioral descriptor.
     */
    const DIMENSION_BANDS = [
        'affinity' => [
            ['label' => 'Hostile',  'range' => [0, 10],   'keywords' => "turns away when they approach, answers in single words, wants them gone"],
            ['label' => 'Cold',     'range' => [11, 25],  'keywords' => "keeps them at arm's length, curt replies, offers no small talk"],
            ['label' => 'Neutral',  'range' => [26, 40],  'keywords' => "polite and brief, keeps to business, offers nothing personal"],
            ['label' => 'Warm',     'range' => [41, 55],  'keywords' => "easy greeting, lingers for a word or two, smiles at their jokes"],
            ['label' => 'Fond',     'range' => [56, 70],  'keywords' => "seeks them out to talk, saves them the good seat, steps in when they are threatened"],
            ['label' => 'Close',    'range' => [71, 85],  'keywords' => "tells them things no one else hears, checks on them first, plans around them"],
            ['label' => 'Devoted',  'range' => [86, 100], 'keywords' => "puts their safety before their own, stands with them against anyone, would follow them anywhere"],
        ],
        'passion' => [
            ['label' => 'Cold',     'range' => [0, 15],   'keywords' => "going through the motions, eyes elsewhere, no spark in the voice"],
            ['label' => 'Tepid',    'range' => [16, 35],  'keywords' => "mild interest, the odd second glance"],
            ['label' => 'Warm',     'range' => [36, 55],  'keywords' => "laughs more easily around them, steals glances, finds reasons to stay"],
            ['label' => 'Heated',   'range' => [56, 75],  'keywords' => "flushed, leans in, charged silences, loses the thread when they come close"],
            ['label' => 'Burning',  'range' => [76, 90],  'keywords' => "can barely focus, pulse racing, keeps finding reasons to touch them"],
            ['label' => 'Redline',  'range' => [91, 100], 'keywords' => "trembling restraint, can't look away, every word charged"],
        ],
        'warmth' => [
            ['label' => 'Walled',      'range' => [0, 20],   'keywords' => "closed off, answers in single words, avoids their eyes, keeps physical distance"],
            ['label' => 'Guarded',     'range' => [21, 40],  'keywords' => "polite but measured, deflects personal questions, formal tone"],
            ['label' => 'Cautious',    'range' => [41, 55],  'keywords' => "the occasional real smile, shares a small thing then watches how it lands"],
            ['label' => 'Comfortable', 'range' => [56, 70],  'keywords' => "relaxed posture, says what they think, easy in shared quiet"],
            ['label' => 'Open',        'range' => [71, 85],  'keywords' => "brings up their own worries unprompted, laughs freely, asks how they really are"],
            ['label' => 'Intimate',    'range' => [86, 100], 'keywords' => "completely unguarded, shares fears and hopes, easy touch"],
        ],
        'maturity' => [
            ['label' => 'Chaotic',    'range' => [0, 20],   'keywords' => "flares up.. then TAKES it back, blames whoever is closest, can't sit with a feeling, sulks and snaps again"],
            ['label' => 'Immature',   'range' => [21, 40],  'keywords' => "dodges hard conversations, jokes or snaps instead of answering, blames others"],
            ['label' => 'Developing', 'range' => [41, 55],  'keywords' => "catches themselves mid-reaction sometimes, apologizes late, means well and slips"],
            ['label' => 'Grounded',   'range' => [56, 70],  'keywords' => "says plainly what bothers them, keeps their voice level in a disagreement"],
            ['label' => 'Mature',     'range' => [71, 85],  'keywords' => "names what they feel calmly, listens before answering, makes room for the other side"],
            ['label' => 'Wise',       'range' => [86, 100], 'keywords' => "turns a quarrel into a real talk, knows their own patterns, steady under pressure"],
        ],
        'trust' => [
            ['label' => 'Distrustful', 'range' => [0, 15],   'keywords' => "watches their hands, answers questions with questions, keeps anything personal back"],
            ['label' => 'Wary',        'range' => [16, 35],  'keywords' => "double-checks what they say, commits to nothing, reveals little"],
            ['label' => 'Uncertain',   'range' => [36, 50],  'keywords' => "offers a small confidence, then watches how it is handled"],
            ['label' => 'Established', 'range' => [51, 70],  'keywords' => "shares personal matters, turns to them in danger, assumes good intent"],
            ['label' => 'Deep',        'range' => [71, 85],  'keywords' => "voices fears to them, leans on them when it is hard, takes their word without checking"],
            ['label' => 'Absolute',    'range' => [86, 100], 'keywords' => "would put their life in their hands, keeps no secrets from them"],
        ],
        'comfort' => [
            ['label' => 'Tense',    'range' => [0, 20],   'keywords' => "stiff, formal, weighs every word, keeps physical distance"],
            ['label' => 'Uneasy',   'range' => [21, 40],  'keywords' => "polite but short, finds reasons to end the conversation"],
            ['label' => 'Neutral',  'range' => [41, 55],  'keywords' => "socially correct, pleasant on the surface, nothing more"],
            ['label' => 'At ease',  'range' => [56, 70],  'keywords' => "drops formality, genuine smiles, sits a little closer"],
            ['label' => 'Familiar', 'range' => [71, 85],  'keywords' => "teases freely, tells embarrassing stories, casual touch"],
            ['label' => 'Home',     'range' => [86, 100], 'keywords' => "completely unmasked, messy and real, would fall asleep beside them"],
        ],
        'respect' => [
            ['label' => 'Contempt',      'range' => [0, 15],   'keywords' => "talks over them, dismisses their ideas, openly mocks"],
            ['label' => 'Unimpressed',   'range' => [16, 35],  'keywords' => "doesn't ask their opinion, humors them and does it their own way"],
            ['label' => 'Neutral',       'range' => [36, 50],  'keywords' => "hears them out, does not defer to them"],
            ['label' => 'Appreciates',   'range' => [51, 65],  'keywords' => "asks their view in what they know, defers to them there"],
            ['label' => 'Admires',       'range' => [66, 80],  'keywords' => "seeks their counsel, speaks well of them to others, follows their lead in key things"],
            ['label' => 'Reveres',       'range' => [81, 100], 'keywords' => "measures themselves against them, quotes them, wants to be worthy of them"],
        ],
        'resentment' => [
            ['label' => 'Clean',       'range' => [0, 15],   'keywords' => ''],
            ['label' => 'Simmering',   'range' => [16, 30],  'keywords' => "bites their tongue, small things land harder than they should"],
            ['label' => 'Edged',       'range' => [31, 50],  'keywords' => "sighs instead of speaking up, a passive-aggressive edge, shorter patience"],
            ['label' => 'Frustrated',  'range' => [51, 70],  'keywords' => "visibly frustrated, kind words from them no longer land, pulls back emotionally"],
            ['label' => 'Withdrawn',   'range' => [71, 90],  'keywords' => "cold and distant, has stopped trying, eyes on the door"],
            ['label' => 'Done',        'range' => [91, 100], 'keywords' => "emotionally checked out, one foot out the door, nothing left to say"],
        ],
        'self_confidence' => [
            ['label' => 'Hollow',     'range' => [0, 15],   'keywords' => "can't decide anything alone, looks to others before every choice"],
            ['label' => 'Dependent',  'range' => [16, 30],  'keywords' => "asks for approval before acting, waits to be told it was right"],
            ['label' => 'Uncertain',  'range' => [31, 45],  'keywords' => "second-guesses after deciding, compares themselves to others"],
            ['label' => 'Grounded',   'range' => [46, 60],  'keywords' => "trusts their own judgment, checks with others after, not before"],
            ['label' => 'Assured',    'range' => [61, 75],  'keywords' => "decides and acts, takes advice without needing it"],
            ['label' => 'Sovereign',  'range' => [76, 90],  'keywords' => "fully self-directed, quiet certainty, unmoved by flattery"],
            ['label' => 'Ubermensch', 'range' => [91, 100], 'keywords' => "answers to no one, other opinions slide off"],
        ],
        // ========== RESENTMENT_SELF (PR 7) ==========
        'resentment_self' => [
            ['label' => 'Clean',        'range' => [0, 15],   'keywords' => ''],
            ['label' => 'Self-doubt',   'range' => [16, 30],  'keywords' => "replays mistakes, harder on themselves than on anyone"],
            ['label' => 'Self-critical', 'range' => [31, 50],  'keywords' => "apologizes for things that aren't their fault, shrinks from notice"],
            ['label' => 'Withdrawing',  'range' => [51, 70],  'keywords' => "brushes off compliments, pulls away from everyone"],
            ['label' => 'Shutdown',     'range' => [71, 90],  'keywords' => "can't meet anyone's eyes, expects the worst and thinks they deserve it"],
            ['label' => 'Crisis',       'range' => [91, 100], 'keywords' => "isolating.. completely, talks about themselves with contempt, close to something drastic"],
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
            'keywords' => 'steady presence, stands close when there is danger, calm and kind authority',
        ],
        '+M/-F' => [
            'label'    => 'Stoic Distance',
            'keywords' => 'dutiful but cold, clipped and correct, feelings locked away',
        ],
        '-M/+F' => [
            'label'    => 'Soft Vulnerability',
            'keywords' => 'yields easily, looks for reassurance, voice softens toward pleading',
        ],
        '-M/-F' => [
            'label'    => 'Bitter Withdrawal',
            'keywords' => 'bitter asides, passive-aggressive digs, withdraws and keeps score',
        ],
    ];

    /**
     * Arousal/Valence combination keyword definitions.
     * Thresholds: Arousal > 50 = High, <= 50 = Low; Valence > 0 = Positive, <= 0 = Negative.
     */
    const AROUSAL_VALENCE_BANDS = [
        'high_positive' => [
            'label'    => 'Electrified',
            'keywords' => "grinning, talks fast, can't stand still",
        ],
        'high_negative' => [
            'label'    => 'Panicked',
            'keywords' => 'heart pounding, breath short, looks for the door or a fight',
        ],
        'low_positive' => [
            'label'    => 'Content',
            'keywords' => 'unhurried, easy smile, comfortable silences',
        ],
        'low_negative' => [
            'label'    => 'Numb',
            'keywords' => 'flat voice, far-away stare, going through the motions',
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
                'keywords'      => 'calm, composed, even-tempered',
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
            // Y_up: traits phase 3 moves the anxiety bump (MDD 1.5; the guard model gives 0.84) to
            // the attachment (trust_gain_mult, anxious corner 1.8; design §2.2, counted twice)
            'Anxious'     => ['Y_up' => 0.84, 'Y_down' => 1.5],   // Volatile -- cross-signal with maturity
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
    public static function getTemperamentBaseline($temperament, $dimensionId, ?array $dynamics = null)
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

        // Temperament-specific baseline (A7-A14, through the trait engine: the NPC's own vector
        // under the read assignment ($dynamics), else the preset point of an exact temperament
        // name; any other label keeps today's lookup / the dimension default)
        if (isset(self::TEMPERAMENT_BASELINES[$dimensionId])
            && (RelDynTraits::isPreset($temperament) || RelDynTraits::readVector($dynamics) !== null)) {
            return (float) RelDynTraits::param($temperament, 'baseline_' . $dimensionId, $def['default_baseline'], $dynamics);
        }
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
    public static function getPlasticityProfile($temperament, $dimensionId, $context = [], ?array $dynamics = null)
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
            // Read assignment: the exact two-axis formula at the NPC's own (Rs, L) (continuousMaturityY)
            if (is_array($context['maturity_y'] ?? null)) return $context['maturity_y'];
            $plasticityType = $context['plasticity_type'] ?? null;
            if ($plasticityType && isset(self::MATURITY_PLASTICITY_VALUES[$plasticityType])) {
                return self::MATURITY_PLASTICITY_VALUES[$plasticityType];
            }
            // Fall through to temperament-based lookup below
        }

        // Temperament-specific lookup (A15, through the trait engine: the NPC's own vector under the
        // read assignment, else an exact temperament name)
        if ((RelDynTraits::isPreset($temperament) || RelDynTraits::readVector($dynamics) !== null) && RelDynTraits::hasColumn("y_{$dimensionId}_up")) {
            return [
                'Y_up'   => RelDynTraits::param($temperament, "y_{$dimensionId}_up", 1.0, $dynamics),
                'Y_down' => RelDynTraits::param($temperament, "y_{$dimensionId}_down", 1.0, $dynamics),
            ];
        }
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
    public static function getMaturityPlasticityType($temperament, ?array $dynamics = null)
    {
        // A17: the MDD 15.6 corner nearest to the exact two-axis formula at the preset's (Rs, L)
        // (read assignment: at the NPC's own vector)
        $x = RelDynTraits::vectorFor($temperament, $dynamics);
        if ($x !== null) return RelDynTraits::maturityCorner($x);
        return self::TEMPERAMENT_MATURITY_PLASTICITY[$temperament] ?? 'Adaptive';
    }

    // ========== END MATURITY DIMENSION HELPERS ==========

    // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========

    /**
     * Derive the confidence input signal from other dimension values (dimension draft,
     * Dimension 11). Self-confidence is NOT eval-scored; its evidence is:
     *   confidence_input = respect x 0.3 + maturity x 0.3 - resentment_self x 0.3
     *                      + goal_completion_rate x 100 x 0.1
     * (dimension points 0..100; goal_completion_rate 0..1, goalCompletionRate()).
     * "avg respect received" has no tracker yet: the respect dimension's x stands in (open
     * question in the roadmap hand-off). Not wired into drift: the formula tops out at 70, so
     * as an absolute target it would pull every confident NPC down (roadmap hand-off).
     *
     * @param array $dynamics  NPC dynamics array with dimensions sub-object
     * @return float  Computed confidence input (dimension points, unclamped)
     */
    public static function deriveConfidenceInput($dynamics)
    {
        $dims = $dynamics['dimensions'] ?? [];
        $respect = floatval($dims['respect']['x'] ?? 50);                  // stand-in for respect received
        $maturity = floatval($dims['maturity']['x'] ?? 50);
        $resentmentSelf = floatval($dims['resentment_self']['x'] ?? 0);    // "I hate what I've done"
        $goalCompletionRate = self::goalCompletionRate((array) $dynamics); // "I follow through"

        $input = $respect * 0.3 + $maturity * 0.3 - $resentmentSelf * 0.3 + $goalCompletionRate * 100.0 * 0.1;
        return round($input, 2);
    }

    /**
     * Share (0..1) of the NPC's recent director goals (_director_goal_history, the last 5) that
     * ended fulfilled rather than expired; 0.5 (neutral) before any goal has ended.
     */
    public static function goalCompletionRate(array $dynamics): float
    {
        $fulfilled = 0;
        $ended = 0;
        foreach ((array) ($dynamics['_director_goal_history'] ?? []) as $goal) {
            $outcome = is_array($goal) ? ($goal['outcome'] ?? null) : null;
            if ($outcome === 'fulfilled') { $fulfilled++; $ended++; }
            elseif ($outcome === 'expired') { $ended++; }
        }
        return $ended > 0 ? $fulfilled / $ended : 0.5;
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

        // ========== ATTACHMENT MODIFIERS (PR 10; two axes, decisions §12) ==========
        // Resentment buildup amplification: blended across the style corners by the axes
        if ($dimensionId === 'resentment' && $rawDelta > 0) {
            $modifiedDelta *= floatval(self::getAttachmentModifier($dynamics, 'resentment_gain_mult') ?? 1.0);
        }
        // Trust gains: the anxiety part of A15h (traits phase 3, design §2.2), blended by the axes.
        // Only where A15h's temperament Y applies: the eval pipeline (R x maturity-type Y) skips it
        if ($dimensionId === 'trust' && $rawDelta > 0 && !in_array('attachment_trust_gain', $skip, true)) {
            $modifiedDelta *= floatval(self::getAttachmentModifier($dynamics, 'trust_gain_mult') ?? 1.0);
        }

        // Maturity floor: the fearful region's (region key, not blended)
        $maturityFloor = $dimensionId === 'maturity' && $rawDelta > 0 ? self::getAttachmentModifier($dynamics, 'maturity_floor') : null;
        if ($maturityFloor !== null) {
            $currentMaturity = floatval($dims['maturity']['x'] ?? 50);
            $floor = floatval($maturityFloor);
            if ($currentMaturity >= $floor) {
                $modifiedDelta = 0;
            } elseif (($currentMaturity + $modifiedDelta) > $floor) {
                $modifiedDelta = max(0, $floor - $currentMaturity);
            }
        }

        // Toxic conflict passion (fearful region): resentment gains queue passion
        $conflictPassion = $dimensionId === 'resentment' && $rawDelta > 0
            ? floatval(self::getAttachmentModifier($dynamics, 'conflict_passion_gain') ?? 0.0) : 0.0;
        if ($conflictPassion > 0) {
            $GLOBALS['RELDYN_ATTACHMENT_CONFLICT_PASSION'] =
                ($GLOBALS['RELDYN_ATTACHMENT_CONFLICT_PASSION'] ?? 0) + $conflictPassion;
        }

        // Widow's Lock: cap affinity gains for grieving NPCs. The ceiling and the affinity delta are
        // core points (-100..100); x is the mirror (0..100), read back in core points here.
        if ($dimensionId === 'affinity' && $rawDelta > 0) {
            $ceiling = floatval($dynamics['_widow_lock_ceiling'] ?? 100);
            if ($ceiling < 100) {
                $currentAff = floatval($dims['affinity']['x'] ?? 50) * 2.0 - 100.0;
                $held = $modifiedDelta;
                if ($currentAff >= $ceiling) {
                    $modifiedDelta = 0;
                } elseif (($currentAff + $modifiedDelta) > $ceiling) {
                    $modifiedDelta = max(0, $ceiling - $currentAff);
                }
                if ($modifiedDelta < $held) {
                    self::log("[GRIEF] widow's lock: affinity gain " . round($held, 3) . ' -> ' . round($modifiedDelta, 3) . " at core {$currentAff} (ceiling {$ceiling})");
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
     *                                 skip_caps (applyCrossSignalCaps rules to skip),
     *                                 attraction_source (label of a passion gain in the
     *                                 attraction log; default 'dimension engine')
     * @param float|null &$attractionFactor Out: the attraction factor a passion gain was
     *                                 scaled by (1.0 when none applied)
     * @return float     The actual delta applied (after all physics)
     */
    public static function applyDelta($dimensionId, &$dynamics, $rawDelta, $temperament = null, $overrides = [], &$attractionFactor = null)
    {
        $attractionFactor = 1.0;
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
            $baseline = self::getTemperamentBaseline($temperament, $dimensionId, $dynamics);
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
            // Read assignment: the exact two-axis formula at the NPC's (Rs, L) while the type is
            // still the automatic one from the traits (an arc override below replaces it)
            $continuous = isset($overrides['plasticity_type']) ? null : self::continuousMaturityY($dynamics);
            if ($continuous !== null) $plasticityContext['maturity_y'] = $continuous;
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
                    unset($plasticityContext['maturity_y']);
                }
            }
        }

        $profile = self::getPlasticityProfile($temperament, $dimensionId, $plasticityContext, $dynamics);

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

        // Held temporary offsets (heldTemporaryOffset: a creature row, physical states, the place,
        // the guilt bleed) are not where the NPC is: the rubber band reads the value without them,
        // so an offset taken back exactly leaves her where she would be without it (a nonlinear
        // band under a held offset made the lift overshoot). Dimension points; 0 for affinity.
        $held = $mirrorScale ? 0.0 : self::heldTemporaryOffset($dynamics, $dimensionId);
        $xPhys = $x - $held;

        // --- Core physics application ---
        // Determine if the delta would cross the baseline (overshoot handling)
        $xAfterRaw = $xPhys + $rawDelta; // hypothetical end position without physics
        $crossesBaseline = (($xPhys >= $baseline && $xAfterRaw < $baseline) ||
                            ($xPhys <= $baseline && $xAfterRaw > $baseline)) &&
                           abs($xPhys - $baseline) > 0.0001;

        $actualDelta = 0.0;

        if ($crossesBaseline) {
            // --- SPLIT at baseline crossing ---
            // Portion 1: from X to baseline
            $deltaToBaseline = $baseline - $xPhys;
            $remainingRaw = $rawDelta - $deltaToBaseline;

            // Portion 1 is always TOWARD baseline
            $actual1 = self::applyPortionDelta($xPhys, $baseline, $deltaToBaseline, $z, $yUp, $yDown, $invertRubberBand);

            // After applying portion 1, X is at (or very near) baseline
            $xAtBaseline = $xPhys + $actual1;

            // Portion 2: from baseline onward (AWAY from baseline)
            $actual2 = self::applyPortionDelta($xAtBaseline, $baseline, $remainingRaw, $z, $yUp, $yDown, $invertRubberBand);

            $actualDelta = $actual1 + $actual2;
        } else {
            // --- No crossing: single application ---
            $actualDelta = self::applyPortionDelta($xPhys, $baseline, $rawDelta, $z, $yUp, $yDown, $invertRubberBand);
        }

        // --- Decisions §13: a passion GAIN is x the attraction factor (the spark below 20, the
        // curve above it, 0 for a hard zero; RelDynAttraction::gainFactor) from this request's
        // summary (none yet: not judged, x1), then bounded by the tier's governor (MDD 8,
        // RelDynGovernors). Applied to the physics' move, so the spark splits where passion
        // itself crosses it (the physics is linear in the delta) ---
        if ($dimensionId === 'passion' && $actualDelta > 0) {
            $attractionFactor = self::loggedPassionFactor($dynamics, $actualDelta,
                (string) ($overrides['attraction_source'] ?? 'dimension engine'),
                is_array($overrides['attraction_tags'] ?? null) ? $overrides['attraction_tags'] : null,
                isset($overrides['attraction_source']) ? (string) $overrides['attraction_source'] : null);
            $actualDelta *= $attractionFactor;
            if ($actualDelta < 0.0001) {
                return 0.0;
            }
        }

        // --- Significance clamp (eval consumer): |delta| <= max_abs, in physics units
        // (dimension points; core affinity points for affinity) ---
        if (isset($overrides['max_abs'])) {
            $maxAbs = max(0.0, floatval($overrides['max_abs']));
            $actualDelta = max(-$maxAbs, min($maxAbs, $actualDelta));
        }

        // --- Clamp to range (the value without the held offsets, then with them) ---
        $newX = max($rangeMin, min($rangeMax, max($rangeMin, min($rangeMax, $xPhys + $actualDelta)) + $held));
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
            $charismaStyle = self::charismaStyle($dynamics);
            if ($charismaStyle !== null && in_array($dimId, ['affinity', 'passion'], true)) {
                $matForCharisma = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
                $charismaMult = self::getCharismaEffectiveness($charismaStyle, $temperament, $matForCharisma, $dimId, $dynamics);
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
            // Anchored to the newest eventlog row now: a save load logged after it can tell
            // whether it discarded this exchange (RelDynTimeline::inboxItemRolledBack)
            $anchor = null;
            try {
                $anchor = RelDynTimeline::enabled() ? RelDynTimeline::newestEventlogRowid() : null;
            } catch (\Throwable $e) {
                self::logError('queuePendingEval anchor (queued_at orders the item instead)', $e);
            }
            return RelDynStorage::appendItem($npcId, RelDynStorage::KEY_EVAL_INBOX, self::evalInboxEntry($evalResult, $anchor));
        } catch (\Throwable $e) {
            error_log("[RelDyn-EVAL] queuePendingEval failed for {$npcName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * One eval inbox entry: {queued_at, eval, anchor_rowid?}. queued_at is a unix timestamp
     * kept for the logs / editor and, without an anchor, to order the item against a save
     * load; nothing measures a duration with it. anchor_rowid: an eventlog row logged no later
     * than the exchange's queueing (the worker's job anchor, else the newest row then).
     */
    public static function evalInboxEntry(array $evalResult, ?int $anchorRowid = null): array
    {
        $entry = ['queued_at' => time(), 'eval' => $evalResult];
        if ($anchorRowid !== null) {
            $entry['anchor_rowid'] = $anchorRowid;
        }
        return $entry;
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

    /** The _eval_applied fingerprint of a normalized contract item (normalizeEvalContractItem). */
    private static function fingerprintOf(array $n): string
    {
        return sha1((string) json_encode([
            strtolower($n['npc']), $n['npc_id'], $n['gamets'], $n['signals'], $n['tags'],
            $n['grievance'], $n['jealousy'], $n['significance'], $n['summary'],
        ]));
    }

    /** The _eval_applied fingerprint of a contract item, or null when it is not one. */
    public static function evalContractFingerprint(array $item): ?string
    {
        $n = self::normalizeEvalContractItem($item);
        return $n === null ? null : self::fingerprintOf($n);
    }

    /**
     * Union of two _eval_applied_log lists ([{fp, item}], oldest first), one entry per
     * fingerprint, the newest save_load.applied_log_keep kept.
     */
    private static function mergeAppliedLog(array $a, array $b): array
    {
        $out = [];
        foreach (array_merge(array_values($a), array_values($b)) as $e) {
            if (!is_array($e) || !is_string($e['fp'] ?? null) || !is_array($e['item'] ?? null)) continue;
            unset($out[$e['fp']]);
            $out[$e['fp']] = ['fp' => $e['fp'], 'item' => $e['item']];
        }
        $keep = max(0, intval(RelDynTimeline::config()['applied_log_keep']));
        return array_slice(array_values($out), -$keep ?: count($out));
    }

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
                if (is_array($stored['_eval_applied_log'] ?? null)) {
                    $dynamics['_eval_applied_log'] = self::mergeAppliedLog((array) $stored['_eval_applied_log'], (array) ($dynamics['_eval_applied_log'] ?? []));
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
                $rolledBack = false;
                try {
                    $rolledBack = $fromInbox && RelDynTimeline::enabled() && RelDynTimeline::inboxItemRolledBack($item);
                } catch (\Throwable $e) {
                    self::logError("processPendingEvalDeltas save-load check for {$npcName} (item applied)", $e);
                }
                if ($rolledBack) {
                    error_log("[RelDyn-EVAL] processPendingEvalDeltas: eval item for {$npcName} dropped: a save load discarded its exchange (gamets " . ($pending['gamets'] ?? '?') . ")");
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
                if ($fromInbox) {
                    $handled++;
                    // save-load-rollback: the item itself, so a load that follows core's restore
                    // to before its application can apply it again (RelDynTimeline::reconcileNpc)
                    $fp = self::isEvalContractItem($pending) ? self::evalContractFingerprint($pending) : null;
                    if ($fp !== null && in_array($fp, (array) ($dynamics['_eval_applied'] ?? []), true)) {
                        $dynamics['_eval_applied_log'] = self::mergeAppliedLog((array) ($dynamics['_eval_applied_log'] ?? []), [['fp' => $fp, 'item' => $item]]);
                    }
                }
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
     * Apply this NPC's eval inbox and what follows from it: the items through
     * processPendingEvalDeltas() (saved there), bystander jealousy for romantic exposure (the
     * committed NPCs who SAW it: the exchange's eventlog people, item.witnesses, not whoever
     * is near her now), the affinity change pushed to core as a locked delta, and betrayal
     * detection. The eval worker calls it right after it fills the inbox
     * (RelDynEval::applyInboxInWorker); postrequest.php calls it for anything left. Nothing
     * with the dimension engine off (the inbox is then dropped by pendingEvalForRequest()).
     *
     * @param array &$dynamics NPC dynamics blob from getDynamics() (by reference); saved
     * @return array  Map of dimensionId => summed actual_delta applied, or empty array
     */
    public static function applyEvalInbox(string $npcName, array &$dynamics): array
    {
        $config = self::getConfig();
        if (empty($config['dimension_engine_enabled'])) {
            return [];
        }
        $evalResults = self::processPendingEvalDeltas($npcName, $dynamics);
        $evalFeelings = $GLOBALS['RELDYN_EVAL_FEELINGS'] ?? [];
        if (!empty($evalFeelings)) {
            // Grievances, jealousy, resentment decay and repair from contract items
            self::saveDynamics($npcName, $dynamics);
            if ($config['jealousy_enabled'] ?? true) {
                foreach ($evalFeelings as $f) {
                    if (empty($f['romantic_exposure'])) {
                        continue;
                    }
                    if (!is_array($f['witnesses'] ?? null)) {
                        self::log("Bystander jealousy for {$npcName}: the eval item recorded no witnesses; nobody is made jealous");
                        continue;
                    }
                    self::scanBystanderJealousy($npcName, '|' . implode('|', $f['witnesses']) . '|');
                }
            }
        }
        if (!empty($evalResults)) {
            error_log("[RelDyn-EVAL] XYZ eval deltas applied for {$npcName}: " . json_encode($evalResults));
            // affinity_delta moved the mirror; push it to core as a locked delta
            self::commitPlayerAffinity($npcName, $dynamics);
            self::saveDynamics($npcName, $dynamics);
            // Betrayal by a bonded partner (Divine Intervention) is read per applied item
            // (RelDynProtocols::onEvalItem): the contract's trust signal never reaches -50.
        }
        // Romance promotion (rulings §9): the moments these items carried, checked on core's
        // fresh type and affinity (after the commit above); saves what it consumed.
        RelDynRomance::maybePromote($npcName, $dynamics);
        return $evalResults;
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
        'confession', 'confiding', 'confessing', 'forgiveness',
    ];

    /**
     * charisma grades of the contract (additive v1, rulings 2026-09-25 §18 #11, MDD 5.1): the
     * player's approach in one exchange; 'none' = no particular approach. The charisma styles
     * (CHARISMA_EFFECTIVENESS keys) are the three that are not 'none'.
     */
    const EVAL_CHARISMA_GRADES = ['rock', 'catalyst', 'charmer', 'none'];

    /** Fingerprints of applied items kept per NPC (count), so a re-queued copy is skipped. */
    const EVAL_APPLIED_KEEP = 32;

    /**
     * MDD 15.4 stage 1: temperament resistance per signal (unitless multipliers). Passion has
     * no column there: getSignalResistance() uses MDD 1.3's passion multiplier
     * (TEMPERAMENT_PASSION_MULT). MDD 15.4 edits approved by Ken (decisions §16 #6, traits
     * phase 3):
     *   - the maturity column is retired: it double-counted with the MDD 15.6 maturity type,
     *     which applyEvalSignal already applies as P (R maturity is 1.0 for everyone);
     *   - the unreachable 'Volatile' row is deleted (no temperament has that name; Volatile is
     *     a maturity type, MATURITY_PLASTICITY_VALUES);
     *   - Humble stays without a row: 1.0 on every signal ("modest, steady, low drama").
     * Traits phase 3 (design §2.1 A16) splits trust and comfort by direction: these rows are the
     * GAIN resistance; a loss reads RelDynTraits column resist_{signal}_down (the MDD's
     * slow-gain / fast-loss Y_down columns, RelDynTraits::resistLossTable). Symmetric R made a
     * Guarded NPC as hard to lose trust with as to win it.
     */
    const TEMPERAMENT_SIGNAL_RESISTANCE = [
        'Stoic'       => ['affinity' => 0.5, 'trust' => 0.7, 'comfort' => 0.4, 'respect' => 0.8],
        'Romantic'    => ['affinity' => 1.3, 'trust' => 1.0, 'comfort' => 1.2, 'respect' => 0.8],
        'Anxious'     => ['affinity' => 1.5, 'trust' => 0.6, 'comfort' => 0.5, 'respect' => 0.7],
        'Guarded'     => ['affinity' => 0.6, 'trust' => 0.4, 'comfort' => 0.3, 'respect' => 0.7],
        'Playful'     => ['affinity' => 1.2, 'trust' => 0.9, 'comfort' => 1.4, 'respect' => 0.6],
        'Bold'        => ['affinity' => 0.9, 'trust' => 1.0, 'comfort' => 1.1, 'respect' => 1.3],
        'Independent' => ['affinity' => 0.7, 'trust' => 0.8, 'comfort' => 0.5, 'respect' => 1.0],
        'Nurturing'   => ['affinity' => 1.1, 'trust' => 1.1, 'comfort' => 1.3, 'respect' => 0.7],
        'Gentle'      => ['affinity' => 1.0, 'trust' => 0.9, 'comfort' => 1.2, 'respect' => 0.5],
        'Jealous'     => ['affinity' => 1.3, 'trust' => 0.4, 'comfort' => 0.4, 'respect' => 0.9],
        'Proud'       => ['affinity' => 0.7, 'trust' => 0.6, 'comfort' => 0.3, 'respect' => 1.5],
        'Defiant'     => ['affinity' => 1.1, 'trust' => 0.7, 'comfort' => 0.8, 'respect' => 1.2],
    ];

    /**
     * R_temperament[signal] (MDD 15.4; passion: MDD 1.3; maturity: retired, 1.0). Unitless.
     * $loss: the delta is a loss (trust and comfort have a separate loss resistance, phase 3).
     */
    public static function getSignalResistance($temperament, string $signal, ?array $dynamics = null, bool $loss = false): float
    {
        // A1 / A16 through the trait engine (a non-preset label keeps today's row lookup)
        if ($signal === 'passion') {
            return (float) RelDynTraits::param($temperament, 'passion_mult', 1.0, $dynamics);
        }
        if ($loss && RelDynTraits::hasColumn("resist_{$signal}_down")) {
            return (float) RelDynTraits::param($temperament, "resist_{$signal}_down", 1.0, $dynamics);
        }
        if (RelDynTraits::hasColumn("resist_{$signal}")) {
            return (float) RelDynTraits::param($temperament, "resist_{$signal}", 1.0, $dynamics);
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
        return self::getMaturityPlasticityType($dynamics['inferred_temperament'] ?? null, $dynamics);
    }

    /**
     * Read assignment: [Y_up, Y_down] from the exact two-axis formula (design §3.2) at the
     * NPC's own resilience and reactivity, while its maturity type is still the automatic one
     * derived from the traits (not an override, a config preset, a class rule such as
     * Bard -> Volatile, nor a type set on the NPC since). Null otherwise: the type's corner applies.
     */
    public static function continuousMaturityY(array $dynamics): ?array
    {
        $x = RelDynTraits::readVector($dynamics);
        if ($x === null) return null;
        $gen = (array) ($dynamics['_profile_autogen'] ?? []);
        if (($gen['maturity_type_origin'] ?? null) !== 'traits' || isset($dynamics['profile_overrides']['maturity_type'])) return null;
        $stored = $dynamics['dimensions']['maturity']['plasticity_type'] ?? null;
        if ($stored !== null && $stored !== ($gen['auto']['maturity_type'] ?? null)) return null;
        [$up, $down] = RelDynTraits::maturityY(floatval($x['Rs']), floatval($x['L']));
        [$lo, $hi] = RelDynTraits::CLAMPS['maturity_y'];
        return ['Y_up' => max($lo, min($hi, $up)), 'Y_down' => max($lo, min($hi, $down))];
    }

    /** The maturity Y the eval applies: an active arc override's corner, else continuousMaturityY, else the type's corner. */
    public static function effectiveMaturityY(array $dynamics): array
    {
        $override = $dynamics['_plasticity_override'] ?? null;
        $overrideActive = is_string($override) && isset(self::MATURITY_PLASTICITY_VALUES[$override])
            && !(floatval($dynamics['_last_gamets'] ?? 0) > 0 && floatval($dynamics['_last_gamets'] ?? 0) >= floatval($dynamics['_plasticity_override_expires_gamets'] ?? 0));
        if (!$overrideActive) {
            $c = self::continuousMaturityY($dynamics);
            if ($c !== null) return $c;
        }
        return self::MATURITY_PLASTICITY_VALUES[self::resolveMaturityType($dynamics)];
    }

    /** State a modifier row reads (units in affinityModifierDefaults()). */
    private static function affinityModifierState(array $dynamics, string $state): ?float
    {
        $temperament = $dynamics['inferred_temperament'] ?? null;
        switch ($state) {
            case 'jealousy':
                return floatval($dynamics['jealousy_anger'] ?? 0);
            case 'passion':
                return self::affinityDrivePassion($dynamics);
            case 'resentment':
                return floatval($dynamics['dimensions']['resentment']['x'] ?? 0);
            case 'maturity':
            case 'comfort':
            case 'trust':
            case 'respect':
                $x = $dynamics['dimensions'][$state]['x'] ?? null;
                return is_numeric($x) ? floatval($x) : self::getTemperamentBaseline($temperament, $state, $dynamics);
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
            return self::attachmentConditionWeight($dynamics, $cond) > 0.0;
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

    /** Weight (0..1) of an ['attachment' => style] condition: the NPC's corner weight for that style. */
    private static function attachmentConditionWeight(array $dynamics, array $cond): float
    {
        return floatval(self::attachmentWeights($dynamics)[strtolower((string) $cond['attachment'])] ?? 0.0);
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
            $weight = 1.0;   // attachment conditions weigh the row (attachmentConditionWeight)
            foreach ((array) ($row['when'] ?? []) as $cond) {
                if (!self::affinityConditionHolds($dynamics, $cond, $tags, $cfg)) continue 2;
                if (isset($cond['attachment'])) $weight *= self::attachmentConditionWeight($dynamics, $cond);
            }

            $mult = $row['mult'];
            if (is_array($mult)) {
                $state = self::affinityModifierState($dynamics, $mult['state']);
                $mult = floatval($mult['at_ref']) + floatval($mult['per_point']) * ($state - floatval($mult['ref']));
                if (isset($row['mult']['min']) && is_numeric($row['mult']['min'])) $mult = max(floatval($row['mult']['min']), $mult);
                if (isset($row['mult']['max']) && is_numeric($row['mult']['max'])) $mult = min(floatval($row['mult']['max']), $mult);
            }
            $mult = 1.0 + $weight * (floatval($mult) - 1.0);
            $out['rows'][$id] = $mult;
            $product *= $mult;
        }

        $min = floatval($cfg['affinity_modifier_min'] ?? 0.25);   // unitless
        $max = floatval($cfg['affinity_modifier_max'] ?? 3.0);    // unitless
        $out['product'] = $product;
        $out['M'] = max($min, min($max, $product));
        return $out;
    }

    /** The affinity mirror, read from core when this NPC's state has none yet (the eval's first touch). */
    private static function ensureEvalAffinityMirror($npcName, array &$dynamics): void
    {
        if (!is_numeric($dynamics['_aff_mirror_x'] ?? null)) {
            $rel = self::getPlayerRelationship($npcName);
            self::refreshAffinityMirror($dynamics, intval($rel['aff'] ?? 0));
            self::log("[EVAL] {$npcName}: affinity mirror read from core (aff " . intval($rel['aff'] ?? 0) . ')');
        }
    }

    /**
     * Apply one raw eval signal through the pipeline (see the section comment).
     *
     * @param string $signal       affinity|trust|comfort|respect|passion|maturity
     * @param float  $raw          raw signal (dimension points; affinity in core points), clamped
     *                             to the contract range first
     * @param float  $significance 0..1; |delta| <= eval_significance_clamp x significance
     * @param float|null $bondLevel social sensitivity bond level (core affinity 0..100) the
     *               exchange was spoken at; null = now (socialSensitivityBondLevel)
     * @return array ['dimension' => $signal, 'actual' => change of dimensions.<signal>.x (for
     *               affinity: mirror units, core = x2), 'line' => the math, as logged]
     */
    public static function applyEvalSignal($npcName, array &$dynamics, string $signal, float $raw, array $tags, float $significance, ?float $bondLevel = null): array
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
        $style = self::charismaStyle($dynamics);
        if ($style !== null && in_array($signal, ['affinity', 'passion'], true)) {
            $mat = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
            $cm = self::getCharismaEffectiveness($style, $temperament, $mat, $signal, $dynamics);
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
            // Attraction (decisions §13): the spark, then the uphill x attachment (rulings §9);
            // Aela warms to a warrior, a bard climbs a long hill; a hard zero leaves exactly 0.
            // The dimension engine applies it to the physics' move (applyDelta, attraction_source).
            // Decisions §15: the item's tags are the gain's channel (an asexual NPC's passion
            // grows only through the emotional ones).
            self::attractionPassionMult((string) $npcName, $dynamics);   // this request's summary
            $gf = RelDynAttraction::gainFactor((array) $dynamics['_attraction'], self::getPassion($dynamics), $raw, $tags);
            // ... and the tier's governor (MDD 8): no room under the tier's passion ceiling
            $govRoom = $gf > 0.0 ? RelDynGovernors::gainFactor($dynamics, self::getPassion($dynamics), $raw * $gf) : 1.0;
            if ($gf <= 0.0 || $govRoom <= 0.0) {
                self::attractionPassionFactor((string) $npcName, $dynamics, $raw, 'eval', $tags);   // logged: why
                $why = isset($dynamics['_attraction']['hard_zero']) ? 'attraction hard zero'
                    : (!RelDynAttraction::channelOpen((array) $dynamics['_attraction'], $tags) ? 'emotional passion only: not an emotional channel'
                    : ($gf <= 0.0 ? 'at the attraction passion ceiling' : "at the tier's passion ceiling"));
                $result['line'] = sprintf('%s %+.2f%s%s -> 0 (%s)', $signal, $rawIn, $clampNote, $steps, $why);
                return $result;
            }
        }
        // Respect gains x respect_mult (plan §4 rate (competence + status) / 2 through this NPC's
        // eyes, 1.0 at the neutral pillar score: RelDynAttraction::respectMult)
        if ($signal === 'respect' && $raw > 0) {
            $rm = self::attractionRespectMult((string) $npcName, $dynamics);
            if (abs($rm - 1.0) > 0.001) {
                $raw *= $rm;
                $steps .= sprintf(' respect_mult x%.2f', $rm);
            }
            if ($raw <= 0.0) {
                $result['line'] = sprintf('%s %+.2f%s%s -> 0', $signal, $rawIn, $clampNote, $steps);
                return $result;
            }
        }

        $R = self::getSignalResistance($temperament, $signal, $dynamics, $raw < 0);
        $type = self::resolveMaturityType($dynamics);
        $dirKey = $raw > 0 ? 'Y_up' : 'Y_down';
        $P = floatval(self::effectiveMaturityY($dynamics)[$dirKey]);
        $M = 1.0;
        $mText = '';
        $overrides = [];
        if ($signal === 'affinity') {
            // The mirror must track core before it moves, or commitPlayerAffinity() has no
            // marker to measure RelDyn's change against.
            self::ensureEvalAffinityMirror($npcName, $dynamics);
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

        // Social sensitivity (dimension draft "Social Sensitivity"): how much the player's words
        // land on this NPC, its curve at the bond level (core affinity toward the player, 0..100).
        // Only the signals config lists (not affinity, the bond itself, nor passion).
        $S = 1.0;
        if (in_array($signal, (array) self::configValue('social_sensitivity_signals'), true)) {
            if ($bondLevel === null) {
                self::ensureEvalAffinityMirror($npcName, $dynamics);   // the bond level is core affinity
                $bondLevel = self::socialSensitivityBondLevel($dynamics);
            }
            $S = self::socialSensitivityFactor($dynamics, $signal, $raw < 0, $temperament, $bondLevel);
            $mText .= sprintf(' x S(bond %.1f)=%.4f', $bondLevel, $S);
        }

        $y = $R * $P * $M * $S;
        // A15h's attachment part rides with the temperament Y it replaced, which this path never read
        $overrides['skip_caps'] = array_merge((array) ($overrides['skip_caps'] ?? []), ['attachment_trust_gain']);
        $overrides['Y_up'] = $y;
        $overrides['Y_down'] = $y;
        $significance = max(0.0, min(1.0, $significance));
        $clampPoints = max(0.0, floatval(self::configValue('eval_significance_clamp')));  // points at significance 1
        $overrides['max_abs'] = $clampPoints * $significance;

        if ($signal === 'passion') {
            $overrides['attraction_source'] = "{$npcName}: eval";
            $overrides['attraction_tags'] = array_values(array_map('strval', (array) $tags));
        }
        $attractionFactor = 1.0;
        $actual = self::applyDelta($signal, $dynamics, $raw, $temperament, $overrides, $attractionFactor);
        $result['actual'] = $actual;
        if (abs($attractionFactor - 1.0) > 0.001) {
            $steps .= sprintf(' attraction x%.2f', $attractionFactor);
        }

        // Physics units: core points for affinity (mirror x2), dimension points otherwise
        $moved = ($signal === 'affinity') ? $actual * 2.0 : $actual;
        $pre = $raw * $y * $attractionFactor;
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
        ] + (($e = self::normalizeEvalExposure($item['exposure'] ?? null, $npc)) !== null ? ['exposure' => $e] : [])
          + self::normalizeEvalExtraFields($item, $npc);
    }

    /** romantic_intent upper bound of the contract (RelDynEval::ROMANTIC_INTENT_MAX). */
    const EVAL_ROMANTIC_INTENT_MAX = 3;

    /**
     * Contract v1 optional fields of decisions §8 (additive): romantic_intent (int 0..3),
     * goal_addressed + goal_ref (bool + the shown goal's directorGoalRef), masking {flag,
     * slipped}; charisma (rulings §18 #11: one of EVAL_CHARISMA_GRADES); reply_mood
     * (lowercased; written by code from core's moods_issued, never by the LLM); and
     * reported_intimacy (a RelDynIntimacy request kind, written by code from the request). Only
     * the fields the item carries, valid, come back; an invalid one is logged and left out (the
     * item still applies). An older item has none of them: its readers (charisma, the Ick,
     * director goal, masking) get nothing from it.
     */
    public static function normalizeEvalExtraFields(array $item, string $npc = ''): array
    {
        $out = [];
        if (array_key_exists('romantic_intent', $item)) {
            if (is_numeric($item['romantic_intent'])) {
                $out['romantic_intent'] = (int) max(0, min(self::EVAL_ROMANTIC_INTENT_MAX, round(floatval($item['romantic_intent']))));
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: romantic_intent is not a number, ignored");
            }
        }
        if (array_key_exists('charisma', $item)) {
            $grade = is_string($item['charisma']) ? strtolower(trim($item['charisma'])) : null;
            if ($grade !== null && in_array($grade, self::EVAL_CHARISMA_GRADES, true)) {
                $out['charisma'] = $grade;
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: charisma " . json_encode($item['charisma']) . ' is not one of '
                    . implode('|', self::EVAL_CHARISMA_GRADES) . ', ignored');
            }
        }
        if (array_key_exists('goal_addressed', $item)) {
            if (is_bool($item['goal_addressed']) && is_string($item['goal_ref'] ?? null) && $item['goal_ref'] !== '') {
                $out['goal_addressed'] = $item['goal_addressed'];
                $out['goal_ref'] = $item['goal_ref'];
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: goal_addressed needs a boolean and the goal_ref it answers, ignored");
            }
        }
        if (array_key_exists('reply_mood', $item)) {
            // the mood the NPC answered that exchange in (code-written, RelDynEval job), for the Ick
            $mood = is_string($item['reply_mood']) ? strtolower(trim($item['reply_mood'])) : '';
            if ($mood !== '' && mb_strlen($mood) <= 40) {
                $out['reply_mood'] = $mood;
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: reply_mood is not a mood name, ignored");
            }
        }
        if (array_key_exists('reported_intimacy', $item)) {
            // intimacy the game or Sharmat reported for that exchange (code-written, RelDynEval job:
            // RelDynIntimacy::requestKind), for the Ick
            $kind = is_string($item['reported_intimacy']) ? trim($item['reported_intimacy']) : '';
            if ($kind !== '' && array_key_exists($kind, (array) (RelDynIntimacy::config()['requests'] ?? []))) {
                $out['reported_intimacy'] = $kind;
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: reported_intimacy is not a reported intimacy kind, ignored");
            }
        }
        if (array_key_exists('masking', $item) && $item['masking'] !== null) {
            $m = $item['masking'];
            if (is_array($m) && is_bool($m['flag'] ?? null) && is_bool($m['slipped'] ?? false)) {
                if ($m['flag'] || !empty($m['slipped'])) {
                    $out['masking'] = ['flag' => true, 'slipped' => !empty($m['slipped'])];
                }
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: masking is not {flag, slipped} booleans, ignored");
            }
        }
        if (array_key_exists('duty_factor', $item)) {
            // a duty exchange (code-written from RelDynQuests, MDD 9): 0..1 on its negative signals
            if (is_numeric($item['duty_factor']) && floatval($item['duty_factor']) >= 0.0 && floatval($item['duty_factor']) <= 1.0) {
                $out['duty_factor'] = floatval($item['duty_factor']);
            } else {
                error_log("[RelDyn-EVAL] eval item for {$npc}: duty_factor is not a number 0..1, ignored");
            }
        }
        return $out;
    }

    /**
     * Contract v1 optional field 'exposure' (additive; traits design §1.2 route A): the player
     * told the NPC about something it was not there for. {flag, kinds: [RelDynConcern::KINDS
     * keys but 'rival'], intensity 1..3, when: today|last_night|earlier, disclosed: true}.
     * Returns null for no exposure (absent, not flagged, or no known kind; a malformed one is
     * logged). 'rival' (flirting in front of the NPC) is the jealousy object, not an exposure.
     */
    public static function normalizeEvalExposure($e, string $npc = ''): ?array
    {
        if ($e === null) {
            return null;
        }
        if (!is_array($e)) {
            error_log("[RelDyn-EVAL] eval item for {$npc}: exposure is not an object, ignored");
            return null;
        }
        if (empty($e['flag'])) {
            return null;
        }
        $kinds = [];
        $raw = is_array($e['kinds'] ?? null) ? $e['kinds'] : (is_string($e['kind'] ?? null) ? [$e['kind']] : []);
        foreach ($raw as $k) {
            $k = strtolower(trim((string) $k));
            if ($k === 'rival' || RelDynConcern::channelOf($k) === null) {
                error_log("[RelDyn-EVAL] eval item for {$npc}: exposure kind '{$k}' dropped");
                continue;
            }
            if (!in_array($k, $kinds, true)) $kinds[] = $k;
        }
        if ($kinds === []) {
            return null;
        }
        $when = strtolower(trim((string) ($e['when'] ?? 'today')));
        return [
            'flag' => true,
            'kinds' => $kinds,
            'intensity' => max(1, min(3, intval($e['intensity'] ?? 1))),
            'when' => in_array($when, RelDynConcern::WHEN, true) ? $when : 'today',
            'disclosed' => true,
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

        $fingerprint = self::fingerprintOf($n);
        $applied = is_array($dynamics['_eval_applied'] ?? null) ? array_values($dynamics['_eval_applied']) : [];
        if (in_array($fingerprint, $applied, true)) {
            error_log("[RelDyn-EVAL] {$npcName}: eval item already applied (gamets {$n['gamets']}), skipped: {$n['summary']}");
            return [];
        }

        $totals = [];
        // The bond the words were spoken in: this item's own affinity change (applied first)
        // must not decide how much its trust / comfort / respect signals land
        self::ensureEvalAffinityMirror($npcName, $dynamics);
        $bondLevel = self::socialSensitivityBondLevel($dynamics);
        $itemGamets = floatval($n['gamets'] ?? 0) > 0 ? floatval($n['gamets']) : self::currentGamets();   // raw game time
        // The reason anchor (dimensional memory): the summary cleaned of scores and named
        // feelings once, here, so every reader (context, confrontation, diary) gets only the event
        $anchor = $n['summary'] !== '' ? RelDynFelt::sanitizeReason($n['summary']) : null;
        foreach (self::EVAL_CONTRACT_SIGNALS as $signal => $_range) {
            $raw = $n['signals'][$signal];
            if (abs($raw) < 0.0001) {
                continue;
            }
            // Duty override (MDD 9): quest-scripted friction with a hostile NPC is not held
            // against the bond; her negative signals of that exchange land dampened
            if ($raw < 0 && isset($n['duty_factor'])) {
                $raw *= floatval($n['duty_factor']);
                if (abs($raw) < 0.0001) continue;
            }
            $r = self::applyEvalSignal($npcName, $dynamics, $signal, $raw, $n['tags'], $n['significance'], $bondLevel);
            $totals[$signal] = $r['actual'];
            // What the legacy path fed downstream: the reason per moved dimension (context
            // <recent_emotional_shifts>) and the dimensional memory (confrontation / diary fuel)
            if (abs($r['actual']) >= 0.0001 && $anchor !== null) {
                $dynamics['dimensions'][$signal]['last_reason'] = $anchor;
                $dynamics['dimensions'][$signal]['last_delta'] = $r['actual'];
                $bondName = $GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player';
                self::storeDimensionalMemory($dynamics, $signal, $r['actual'], $anchor, $bondName, $itemGamets);
            }
        }
        // Interaction significance for the diary's defining_moment trigger, on the legacy 1..3
        // level scale: contract significance 0..1 x 3, rounded (0.33, "normal +-10 of 30" -> 1;
        // 1.0 -> 3). The strongest item of this request counts.
        $level = max(1, min(3, (int) round($n['significance'] * 3)));
        $GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] = max($level, intval($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] ?? 0));
        // ... and kept on the NPC until the diary's next mark (the worker applies outside her request)
        $dynamics['_diary_significance_peak'] = max($level, intval($dynamics['_diary_significance_peak'] ?? 0));
        // A lifted attraction ceiling advances only through significant interactions
        // (attraction design memory); a completed lift refreshes the summary at once. A bond the
        // Matrix has not tracked yet (eval worker before any prerequest, or a passionless item
        // that never asked for the passion gate) is evaluated first, so the item still counts.
        if (!is_array($dynamics['_attraction_state'] ?? null) && $n['positive_interaction']) {
            self::updateAttraction((string) $npcName, $dynamics);
        }
        RelDynAttraction::recordSignificance((string) $npcName, $dynamics, $n['significance'], $n['positive_interaction']);

        // Grievance / jealousy / positive interaction (resentment, conflict): once per accepted
        // item, after its signals. A rejected, misaddressed or already-applied item never gets here.
        $feelings = self::applyEvalFeelings((string) $npcName, $n, $dynamics);
        // Both channels' values path (traits design §1.5): the jealousy event is a 'rival'
        // incident; a disclosed exposure (route A) is learned; reassurance addresses one
        $concern = RelDynConcern::onEvalItem((string) $npcName, $n, $dynamics, $feelings);
        if ($concern['events'] !== [] || $concern['concern'] > 0 || $concern['jealousy'] > 0) {
            $feelings['concern'] = $concern;
        }
        // What the exchange gave against the NPC's needs (rulings §9 fulfillment; its physical /
        // emotional intimacy axes, rulings §10), at its game time.
        RelDynFulfillment::deliver($dynamics, RelDynFulfillment::evalItemAmounts($n),
            floatval($n['gamets'] ?? 0) > 0 ? floatval($n['gamets']) : self::currentGamets());
        // A romantic moment or a setback, for the romance promotion after the inbox (rulings §9)
        RelDynRomance::noteMoment($dynamics, $n);
        // The player's first exchange after her fall, answered with care or not (MDD 3.3 rescue response)
        RelDynCombat::onEvalItem((string) $npcName, $n, $dynamics);
        // Betrayal / a lie are attachment experiences (decisions §12), x the exchange's significance
        foreach ((array) (self::getAttachmentConfig()['drift']['tag_events'] ?? []) as $tag => $event) {
            if (in_array($tag, $n['tags'], true)) {
                self::attachmentExperience($dynamics, (string) $event,
                    floatval($n['gamets'] ?? 0) > 0 ? floatval($n['gamets']) : self::currentGamets(), $n['significance'], (string) $npcName);
            }
        }
        // Decisions §8 fields, each read once per applied item (here, not by a request peeking
        // at the inbox, which the eval worker has usually emptied by then)
        self::applyEvalExtraFields((string) $npcName, $n, $dynamics, $itemGamets);
        // Her intrinsic goals the exchange served (MDD 14.2), and one more meaningful
        // interaction against what she had heard of the player (reputation-layer)
        RelDynGoals::onEvalItem((string) $npcName, $dynamics, $n, $itemGamets);
        RelDynReputation::countInteraction($dynamics, floatval($n['significance']));
        // Betrayal by a bonded partner (Divine Intervention) and the exchange's kind in the
        // parasite ledger (reldyn_protocols.php)
        RelDynProtocols::onEvalItem((string) $npcName, $n, $dynamics);

        $applied[] = $fingerprint;
        $dynamics['_eval_applied'] = array_slice($applied, -self::EVAL_APPLIED_KEEP);
        self::log("[EVAL] {$npcName} item gamets={$n['gamets']} sig={$n['significance']} tags=[" . implode(',', $n['tags']) . "] "
            . "applied " . json_encode($totals) . ($n['grievance']['flag'] ? " grievance={$n['grievance']['kind']}" : '')
            . ($feelings !== [] ? ' feelings ' . json_encode($feelings) : '')
            . " ({$n['summary']})");
        return $totals;
    }

    /**
     * The decisions §8 eval fields of one applied item (normalized), into their readers:
     *   charisma         -> the charisma tracker (MDD 5.1, rulings §18 #11: the player's style
     *                       from the eval's grade of each exchange's approach), charisma on;
     *   romantic_intent  -> the Ick (MDD 6.3, recordIckEvalAttempt, with the item's reply_mood:
     *                       the mood the NPC answered that exchange in), the ick system on;
     *   goal_addressed   -> fulfils the director goal the eval was shown (goal_ref), when it is
     *                       still the active one (PR 39), director goals on;
     *   masking          -> the cost of a performed front (applyMaskingCost), and a slip of
     *                       the front leaves a one-shot for the next context (MDD 11), social
     *                       masking on.
     */
    public static function applyEvalExtraFields(string $npcName, array $n, array &$dynamics, float $gamets): void
    {
        $cfg = self::getConfig();
        if (isset($n['charisma']) && !empty($cfg['charisma_detection_enabled'] ?? true)) {
            self::updateCharismaTracker($dynamics, (string) $n['charisma'], $gamets);
        }
        if (isset($n['romantic_intent']) && !empty($cfg['ick_system_enabled'] ?? true)) {
            self::recordIckEvalAttempt($npcName, $n, $dynamics);
        }
        if (($n['goal_addressed'] ?? false) === true && !empty($cfg['director_goals_enabled'])) {
            $goal = self::getActiveDirectorGoal($dynamics);
            if ($goal === null) {
                self::log("[RelDyn-GOAL] {$npcName}: the eval says the goal was addressed; no goal is active any more");
            } elseif (self::directorGoalRef($goal) !== $n['goal_ref']) {
                self::log("[RelDyn-GOAL] {$npcName}: the eval answered for an earlier goal; the current one stays");
            } else {
                self::fulfillDirectorGoal($dynamics, 'eval_confirmed');
            }
        }
        if (is_array($n['masking'] ?? null) && !empty($cfg['social_masking_enabled'])) {
            self::applyMaskingCost($npcName, $dynamics);
            if (!empty($n['masking']['slipped'])) {
                $dynamics['_mask_slip_gamets'] = $gamets;
                self::log("[RelDyn-MASK] {$npcName}: the front slipped (eval)");
            }
        }
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
        // Falls back to play seconds (_accumulated_time) if the play clock has no value yet
        $playGamets = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
        $lastPlayGamets = floatval($dynamics['_resentment_last_play_gamets'] ?? 0);
        if ($playGamets > 0 || $lastPlayGamets > 0) {
            // Gamets path: use filtered play time
            if (($playGamets - $lastPlayGamets) < self::GAMETS_RESENTMENT_COOLDOWN) {
                return 0.0; // Debounce: ~15 real min of gameplay between decays
            }
        } else {
            // Fallback: play seconds (backward compat until the play clock populates)
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
     * The NPC got to say it (MDD 15.5 addressed decay, "-10 when the NPC gets to EXPRESS the
     * grievance"): resentment - confrontation.addressed_relief points, taken as is (through the
     * inverted rubber band a -10 left about a tenth of itself: saying it barely helped), the
     * backlog it was about (every open grievance_log entry toward the player) marked addressed
     * at game time $at (RelDynResentment::isOpenGrievance: an entry that grows after that, such
     * as an absence that goes on, is open again), and for a calm confrontation ($resolved, not a
     * blow-up) the ick's confrontation condition (_ick_confrontation_resolved). Called when the
     * confrontation is said (RelDynResentment::takeFeltLines).
     *
     * @return float The resentment change (<= 0, resentment points)
     */
    public static function processResentmentConfrontation(&$dynamics, $temperament = null, bool $resolved = true, ?float $at = null)
    {
        $before = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);   // 0..100
        if ($before <= 0 || !isset($dynamics['dimensions']['resentment']) || !is_array($dynamics['dimensions']['resentment'])) {
            return 0.0;
        }
        $relief = max(0.0, floatval(RelDynResentment::config()['confrontation']['addressed_relief']));
        $after = max(0.0, $before - $relief);
        $dynamics['dimensions']['resentment']['x'] = round($after, 4);
        $at = $at ?? self::currentGamets();   // raw gamets
        $addressed = 0;
        foreach ((array) ($dynamics['dimensions']['resentment']['grievance_log'] ?? []) as $i => $g) {
            if (RelDynResentment::isOpenGrievance($g)) {
                $dynamics['dimensions']['resentment']['grievance_log'][$i]['addressed'] = $at;
                $addressed++;
            }
        }
        if ($resolved) {
            $dynamics['_ick_confrontation_resolved'] = true;
        }
        $npcName = $dynamics['_npc_name'] ?? 'unknown';
        error_log("[RelDyn-RESENTMENT] Confrontation said for {$npcName}: resentment " . round($before, 2) . ' -> ' . round($after, 2)
            . " ({$addressed} grievance(s) addressed" . ($resolved ? ', resolved' : ', a blow-up') . ')');
        return round($after - $before, 4);
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
     *         x $dutyFactor (MDD 9 duty override: quest-scripted friction with a hostile NPC is
     *           not held against the bond; 1 off duty)
     * through applyDelta (suppressed +50%, inverted rubber band). A people-pleaser
     * (isPeoplePleaser) takes it as resentment_self instead. Logged in grievance_log (last 10;
     * 'duty' = the factor when it applied).
     *
     * @return array{raw: float, amount: float, target: string, power_gap: float, severity: int}
     */
    public static function recordGrievance(array &$dynamics, array $grievance, array $powerFacts, string $summary = '', float $dutyFactor = 1.0): array
    {
        $severity = max(0, min(3, intval($grievance['severity'] ?? 0)));
        $sevMult = floatval(((array) self::configValue('grievance_severity_mult'))[$severity] ?? 1.0);
        $gap = self::computePowerGap($powerFacts);
        $hoover = max(1.0, floatval($dynamics['_hoover_resentment_mult'] ?? 1.0));
        $dutyFactor = max(0.0, min(1.0, $dutyFactor));
        $raw = floatval(self::configValue('grievance_resentment_raw')) * $sevMult * (1.0 + $gap['gap']) * $hoover * $dutyFactor;   // raw resentment points

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
        ] + ($dutyFactor < 1.0 ? ['duty' => round($dutyFactor, 4)] : []);
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
                // Duty override (MDD 9): quest-scripted friction is not held against the bond, its
                // grievance lands at the exchange's duty factor like its negative signals
                $duty = is_numeric($item['duty_factor'] ?? null) ? max(0.0, min(1.0, floatval($item['duty_factor']))) : 1.0;
                $out['grievance'] = self::recordGrievance($dynamics, $grievance, self::powerGapFacts($npcName, $dynamics), $summary, $duty);
                // Wronged again inside the probation of a mature NPC's grievance boundary: the
                // pattern went on (RelDynConcern::onContact carries out the step-back). Quest
                // friction on duty is not the pattern going on.
                if ($duty >= 1.0 && RelDynConcern::onGrievance($npcName, $dynamics,
                        floatval($item['gamets'] ?? 0) > 0 ? floatval($item['gamets']) : self::currentGamets())) {
                    $out['grievance_boundary_failed'] = true;
                }
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
            // resentment_self's recovery path (processResentmentSelfDecay, a confession, the
            // player's forgiveness, a gentle approach while she is away in her shame)
            $self = RelDynResentment::onPositiveEval($npcName, $dynamics, $tags);
            if ($self['decay'] + $self['recovery'] + $self['gentle'] > 0) {
                $out['resentment_self_relief'] = $self['decay'] + $self['recovery'] + $self['gentle'];
            }
            if (!empty($dynamics['in_conflict']) && self::configValue('conflict_enabled')) {
                $burst = self::recordConflictPositive($dynamics);
                if ($burst > 0) {
                    $out['repair_burst'] = self::gainPassion($npcName, $dynamics, $burst, 'repair');
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
     *   x attachment jealousy_mult x relationship preference (config preference_jealousy_mult:
     *   monogamous 2.0, polyamorous 0.1, not_interested 0, aromantic 0) x commitment
     *   x jealousyTrustFactor (trust damps it strongly, traits design §1.4)
     */
    public static function jealousyEventGain(array $dynamics, int $intensity, float $commitment = 1.0): float
    {
        $intensity = max(0, min(3, $intensity));
        $pref = strtolower(trim((string) ($dynamics['relationship_preference'] ?? '')));
        $prefMult = floatval(((array) self::configValue('preference_jealousy_mult'))[$pref] ?? 1.0);
        if ($prefMult <= 0) {
            return 0.0;
        }
        $gain = floatval(self::configValue('jealousy_eval_gain'))
            * floatval(((array) self::configValue('jealousy_intensity_mult'))[$intensity] ?? 1.0)
            * floatval(RelDynTraits::param($dynamics['inferred_temperament'] ?? '', 'jealousy_mult', 1.0, $dynamics))   // A4
            * floatval(self::getAttachmentModifier($dynamics, 'jealousy_mult') ?? 1.0)
            * $prefMult
            * $commitment
            * self::jealousyTrustFactor($dynamics);
        return max(0.0, $gain);
    }

    /**
     * Possessive trust damping (traits design §1.4): clamp(1 - jealousy_trust_damping x trust /
     * 100, 0.3, 1), trust = dimensions.trust.x (points 0..100). A bond with no trust value yet
     * is not damped (1.0). Unitless.
     */
    public static function jealousyTrustFactor(array $dynamics): float
    {
        $trust = $dynamics['dimensions']['trust']['x'] ?? null;
        if (!is_numeric($trust)) {
            return 1.0;
        }
        $k = max(0.0, floatval(self::configValue('jealousy_trust_damping')));
        return max(0.3, min(1.0, 1.0 - $k * max(0.0, min(100.0, floatval($trust))) / 100.0));
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
            // A 'rival' incident for the bystander's values path (traits design §1.5)
            $seenAt = self::currentGamets();
            if ($seenAt > 0) {
                RelDynConcern::learn($name, $dyn, ['kinds' => ['rival' => 1], 'gamets' => $seenAt, 'route' => 'bystander', 'feeling_applied' => true], $seenAt);
            }
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
        // Dimension draft "Bond Type Transition: Bonded -> Grieving": trust x0 (can't build trust
        // with a memory), comfort x1.5, respect / warmth x2 (idealization), passion x0. Its decay
        // is the grief phases' (processGriefPhases), so no decay_rate column (getTypeModifier 1.0).
        'grieving' => [
            'trust' => 0.0, 'comfort' => 1.5, 'respect' => 2.0,
            'warmth' => 2.0, 'passion' => 0.0,
            'resistance' => 0.3,
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

        // 3b. Attraction ceiling (MDD 8 / plan §5): a core romance type the attraction blocks, or
        // has not yet earned through significant interactions, gets no romance modifiers; the
        // bond reads as its depth, capped at the ceiling.
        $attraction = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : null;
        if ($coreMapped !== null && $attraction !== null && in_array($coreType, (array) ($attraction['blocked_types'] ?? []), true)) {
            return self::DEPTH_TYPE_BY_TIER[self::attractionCappedTier($dynamics)] ?? 'stranger';
        }

        // 4. Core type is the source of truth
        if ($coreMapped !== null) {
            return $coreMapped;
        }
        // 5. Core type without a RelDyn flavour: depth from core affinity, capped at the
        // attraction ceiling (tier-specific modifiers above it are blocked)
        if (is_string($coreType) && $coreType !== '') {
            return self::DEPTH_TYPE_BY_TIER[self::attractionCappedTier($dynamics)] ?? 'stranger';
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
     * RelDyn tier on core affinity, no higher than the attraction ceiling (_attraction.ceiling_tier,
     * null = none). Affinity itself is never capped: only what the tier unlocks.
     */
    public static function attractionCappedTier(array $dynamics): string
    {
        $tier = self::getCurrentTier(self::getCoreAffinity($dynamics));
        $ceiling = $dynamics['_attraction']['ceiling_tier'] ?? null;
        if (is_string($ceiling) && self::tierRank($ceiling) >= 0 && self::tierRank($tier) > self::tierRank($ceiling)) {
            return $ceiling;
        }
        return $tier;
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

    // ---- Per-bond display multiplier (feedback_baseline_vs_perbond, feedback_perbond_tuning) ----
    // Baselines are GLOBAL: who the NPC is with everyone. The bond with the player shows only
    // at display time, as a multiplier on the stored value: never persisted, never a shifted
    // baseline (April's applyDeltaWithContext wrote type-modified baselines into the stored
    // state; it and its helpers are retired). A type change snaps: the multiplier is read from
    // the current type every time.

    /** Config 'per_bond_display' over its defaults (exponents per dimension merged one by one). */
    public static function perBondDisplayConfig(): array
    {
        $stored = self::configValue('per_bond_display');
        $cfg = is_array($stored) ? array_replace(self::PER_BOND_DISPLAY_DEFAULTS, $stored) : self::PER_BOND_DISPLAY_DEFAULTS;
        $cfg['type_curve_exponent'] = array_replace(self::PER_BOND_DISPLAY_DEFAULTS['type_curve_exponent'],
            is_array($cfg['type_curve_exponent'] ?? null) ? $cfg['type_curve_exponent'] : []);
        return $cfg;
    }

    /**
     * The per-bond display multiplier (unitless) of $dimensionId toward the player:
     *   (1 + affinity_bonus_max x clamp(core affinity, 0, 100) / 100) x type^exponent
     * with type = RELATIONSHIP_TYPE_MODIFIERS[$relationshipType][$dimensionId] (the bond type
     * getRelationshipType() reads when null) and exponent = per_bond_display.type_curve_exponent
     * (0.5 when the dimension has none). 1.0 for the global dimensions and for any dimension
     * without a type column (affinity, the bond itself; resentment).
     */
    public static function perBondMultiplier(array $dynamics, string $dimensionId, ?string $relationshipType = null): float
    {
        if (in_array($dimensionId, self::GLOBAL_DIMENSIONS, true)) {
            return 1.0;
        }
        $type = $relationshipType ?? self::getRelationshipType((string) ($dynamics['npc_name'] ?? ''), $dynamics);
        $row = self::RELATIONSHIP_TYPE_MODIFIERS[$type] ?? null;
        if (!is_array($row) || !isset($row[$dimensionId])) {
            return 1.0;
        }
        $cfg = self::perBondDisplayConfig();
        $exponent = floatval($cfg['type_curve_exponent'][$dimensionId] ?? 0.5);
        $typeMult = pow(max(0.0, floatval($row[$dimensionId])), $exponent);
        $bond = max(0.0, min(100.0, self::getCoreAffinity($dynamics)));   // core affinity points
        $bonus = 1.0 + max(0.0, floatval($cfg['affinity_bonus_max'])) * $bond / 100.0;
        return $bonus * $typeMult;
    }

    /**
     * A dimension as it reads toward the player (display time only): $value (default: the
     * stored x; pass a baseline to get the effective baseline) x perBondMultiplier(), clamped
     * to the dimension's range. Null when there is no value. Tension checks read the RAW value.
     * The stored x's held temporary offsets (heldTemporaryOffset: the guilt bleed, a creature
     * row, a physical state, the place) are states, not the bond: the multiplier scales the value
     * without them and they are added back as they are, so a partner's guilt still shows (a
     * saturating multiplier swallowed it). A $value passed in is read as it is.
     */
    public static function getEffectiveDimensionValue(array $dynamics, string $dimensionId, ?float $value = null, ?string $relationshipType = null): ?float
    {
        $held = 0.0;
        if ($value === null) {
            $x = $dimensionId === 'passion' ? self::getPassion($dynamics) : ($dynamics['dimensions'][$dimensionId]['x'] ?? null);
            if (!is_numeric($x)) {
                return null;
            }
            $value = floatval($x);
            $held = $dimensionId === 'affinity' ? 0.0 : self::heldTemporaryOffset($dynamics, $dimensionId);
        }
        $mult = self::perBondMultiplier($dynamics, $dimensionId, $relationshipType);
        if ($mult === 1.0) {
            return $value;
        }
        $def = self::getDimensionDefinition($dimensionId);
        $min = $def ? floatval($def['range_min']) : 0.0;
        $max = $def ? floatval($def['range_max']) : 100.0;
        $own = max($min, min($max, $value - $held));
        $shown = self::perBondScaled($dimensionId, $own, $mult, $min, $max);
        return max($min, min($max, $shown + $held));
    }

    /**
     * $own (dimension points, within $min..$max) under the per-bond multiplier: linear (x x mult,
     * clamped) or, for a per_bond_display.saturating dimension lifted by a multiplier above 1,
     * the saturating curve of rulings §18 #8 on the range: u = (x - min) / span,
     * min + span x (1 - (1 - u)^mult). Same slope as x x mult at the bottom, 100 only at 100.
     */
    public static function perBondScaled(string $dimensionId, float $own, float $mult, float $min = 0.0, float $max = 100.0): float
    {
        $span = $max - $min;
        $saturating = in_array($dimensionId, (array) (self::perBondDisplayConfig()['saturating'] ?? []), true);
        if (!$saturating || $mult <= 1.0 || $span <= 0.0) {
            return max($min, min($max, $own * $mult));
        }
        $u = max(0.0, min(1.0, ($own - $min) / $span));
        return $min + $span * (1.0 - pow(1.0 - $u, $mult));
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
     * A18, traits phase 3 (design §2.2): the rate is owned by possessiveness (RelDynTraits column
     * 'absence_decay', Rule R over -(0.17 + 1.61 Po)), and the attachment affinity_absence_mult
     * applies on top. Anxious's -2.0 counted its anxiety a third time (over that mult and neglect
     * codependence): its row is the possessiveness model now (Po .60).
     */
    const TEMPERAMENT_DECAY_RATES = [
        'Anxious'     => -1.14, // was -2.0: its anxiety is the attachment's (x2.0 when anxious)
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

    /**
     * Context tier (0-3) the NPC's current core affinity supports, before the high-water mark,
     * no deeper than the attraction ceiling (plan §5: context depth above it is blocked).
     */
    public static function getAffinityContextTier(array $dynamics): int
    {
        $tier = self::attractionCappedTier($dynamics);
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
        $retention = RelDynTraits::param($temperament, 'tier_retention', -15, $dynamics);   // A19, trait engine
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
            $affBaseline = self::getTemperamentBaseline($temperament, 'affinity', $dynamics);
        }
        $decayTarget = max(0.0, floatval($affBaseline));
        $result['decay_target'] = $decayTarget;
        if ($oldAffinity <= $decayTarget) {
            $result['skipped'] = true;
            $result['skip_reason'] = 'at_or_below_baseline';
            return $result;
        }

        // --- Calculate base decay ---
        $baseDecayRate = RelDynTraits::param($temperament, 'absence_decay', -0.5, $dynamics); // A18 (possessiveness): core points per tick

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
     * Anchor status for the DI fork (dimension design "The Fork"). Trust.x is GLOBAL (not
     * per-bond): the player's bond counts it once; an NPC bond counts its mirror affinity x
     * divine.npc_trust_per_affinity. Affinity on the mirror scale 0..100 (the design's affinity).
     * The dead anchor no one: $exclude (the deceased of this event) and every bond the NPC
     * grieves are left out. Thresholds: config protocols.divine.
     */
    public static function calculateAnchorStatus($npcName, &$dynamics, array $exclude = []): array
    {
        $bonds = self::getAllBondsForNpc($npcName);
        $dims = $dynamics['dimensions'] ?? [];
        $cfg = RelDynProtocols::config()['divine'];
        $dead = array_map('strtolower', array_merge(array_map('strval', $exclude), RelDynProtocols::deadNames(is_array($dynamics) ? $dynamics : [])));

        $globalTrustX = floatval($dims['trust']['x'] ?? 0);
        $totalTrust = 0.0;
        $maxAffinity = 0.0;

        foreach ($bonds as $targetName => $bond) {
            if (in_array(strtolower((string) $targetName), $dead, true)) {
                continue;
            }
            $bondAff = ($bond['aff'] + 100) / 2.0; // core -100..+100 -> mirror 0..100

            if ($targetName === self::PLAYER_RELATIONSHIP_KEY) {
                $totalTrust += $globalTrustX;
            } else {
                $totalTrust += max(0, $bondAff * floatval($cfg['npc_trust_per_affinity']));
            }

            if ($bondAff > $maxAffinity) {
                $maxAffinity = $bondAff;
            }
        }

        return [
            'has_anchor'   => ($totalTrust > floatval($cfg['anchor_total_trust_above']) && $maxAffinity > floatval($cfg['anchor_max_affinity_above'])),
            'is_alone'     => ($totalTrust < floatval($cfg['alone_total_trust_below']) && $maxAffinity < floatval($cfg['alone_max_affinity_below'])),
            'total_trust'  => $totalTrust,
            'max_affinity' => $maxAffinity,
        ];
    }

    /**
     * Trigger Divine Intervention — catastrophic event processing.
     * The Fork: anchor → redemption, alone → breaking, neither → unstable window.
     * $exclude: names that cannot anchor this event (the deceased of a companion death).
     */
    public static function triggerDivineIntervention($npcName, $eventType, $severity, &$dynamics, array $exclude = [])
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
        $anchor = self::calculateAnchorStatus($npcName, $dynamics, $exclude);

        // The Fork
        if ($anchor['has_anchor']) {
            self::applyRedemptionArc($npcName, $severity, $dynamics);
        } elseif ($anchor['is_alone']) {
            self::applyBreakingArc($npcName, $severity, $dynamics);
        } else {
            // Neither anchored nor alone — open unstable window
            self::openUnstableWindow($npcName, $eventType, $severity, $dynamics, $exclude);
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

        // Plasticity override: Growth for 30 game days (raw game calendar from the event)
        $currentRawGamets = RelDynProtocols::calendarNow($dynamics);
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

        // Plasticity override: Brittle for 30 game days (raw game calendar from the event)
        $currentRawGamets = RelDynProtocols::calendarNow($dynamics);
        $dynamics['_plasticity_override'] = 'Brittle';
        $dynamics['_plasticity_override_start_gamets'] = $currentRawGamets;
        $dynamics['_plasticity_override_expires_gamets'] = $currentRawGamets + self::THIRTY_GAME_DAYS_GAMETS;

        $dynamics['_attachment_shift_available'] = true;
        $dynamics['_divine_intervention_last_type'] = 'breaking';

        self::log("[DIVINE] Breaking arc for {$npcName}: shift={$shift}, maturity_baseline={$dims['maturity']['baseline']}");
    }

    /**
     * Open an unstable window when DI finds neither anchor nor isolation: divine.
     * unstable_window_game_hours of the game calendar (raw gamets) from now. $exclude: who cannot
     * anchor it (the deceased; the partner who betrayed her).
     */
    private static function openUnstableWindow($npcName, $eventType, $severity, &$dynamics, array $exclude = [])
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
            'start_gamets'    => RelDynProtocols::calendarNow($dynamics),   // raw game calendar
            'duration_gamets' => floatval(RelDynProtocols::config()['divine']['unstable_window_game_hours']) * self::GAMETS_PER_DAY / 24.0,
            'clock'           => 'calendar',
            'event_type'      => $eventType,
            'severity'        => $severity,
            'exclude'         => array_values(array_map('strval', $exclude)),
            'resolved'        => false,
            'resolution'      => null,
        ];

        self::log("[DIVINE] Unstable window opened for {$npcName}: event={$eventType}, severity={$severity}");
    }

    /**
     * Check unstable window state: an anchor who is here resolves it as redemption; past its end on
     * the game calendar with nobody, breaking. Anchors: $interactingWith (the player on their own
     * turn) and $people (who is around: core's CACHE_PEOPLE names; null = that global). The dead
     * anchor no one. Returns 'redemption', 'breaking', 'active', or null (no window).
     */
    public static function checkUnstableWindow($npcName, &$dynamics, $interactingWith = null, ?array $people = null): ?string
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
        $cfg = RelDynProtocols::config()['divine'];

        $currentGamets = RelDynProtocols::calendarNow($dynamics);   // raw game calendar
        if ($currentGamets <= 0) {
            return 'active';
        }
        if (($window['clock'] ?? null) !== 'calendar') {
            // A window from the play-clock build: it starts over on the calendar (start fresh)
            $dynamics['_unstable_window']['start_gamets'] = $currentGamets;
            $dynamics['_unstable_window']['duration_gamets'] = floatval($cfg['unstable_window_game_hours']) * self::GAMETS_PER_DAY / 24.0;
            $dynamics['_unstable_window']['clock'] = 'calendar';
            $window = $dynamics['_unstable_window'];
        }
        $elapsed = $currentGamets - floatval($window['start_gamets']);
        $duration = floatval($window['duration_gamets']);

        // Potential anchors: the player on their turn and whoever is around
        $potentialAnchors = [];
        if ($interactingWith !== null) {
            $potentialAnchors[] = $interactingWith;
        }
        if ($people === null) {
            $people = array_values(array_filter(array_map('trim', explode('|', (string) ($GLOBALS['CACHE_PEOPLE'] ?? '')))));
        }
        // Nobody anchors from the grave, nor the one the window is about (a betrayer)
        $dead = array_map('strtolower', array_merge(RelDynProtocols::deadNames($dynamics), (array) ($window['exclude'] ?? [])));
        foreach ($people as $nearby) {
            $nearby = trim((string) $nearby);
            if ($nearby !== '' && strcasecmp($nearby, (string) $npcName) !== 0) {
                $potentialAnchors[] = $nearby;
            }
        }
        $potentialAnchors = array_unique($potentialAnchors);

        $bonds = self::getAllBondsForNpc($npcName);
        foreach ($potentialAnchors as $anchor) {
            // Bonds are keyed "Player" for the player (CHIM 3.4.1); $anchor may be the real name
            $anchorKey = self::relationshipTargetKey($anchor);
            if (in_array(strtolower((string) $anchor), $dead, true) || in_array(strtolower((string) $anchorKey), $dead, true)) {
                continue;
            }
            $key = RelDynProtocols::bondKey($bonds, (string) $anchorKey);
            $bond = $key !== null ? $bonds[$key] : null;
            if ($bond) {
                $bondAff = ($bond['aff'] + 100) / 2.0;   // mirror 0..100
                $bondTrust = ($anchorKey === self::PLAYER_RELATIONSHIP_KEY)
                    ? floatval($dynamics['dimensions']['trust']['x'] ?? 0)
                    : max(0, $bondAff * floatval($cfg['npc_trust_per_affinity']));
                if ($bondAff > floatval($cfg['window_anchor_affinity_above']) && $bondTrust > floatval($cfg['window_anchor_trust_above'])) {
                    $dynamics['_unstable_window']['resolved'] = true;
                    $dynamics['_unstable_window']['resolution'] = 'redemption';
                    $dynamics['_unstable_window']['anchor'] = (string) $anchor;
                    self::applyRedemptionArc($npcName, $window['severity'], $dynamics);
                    $dynamics[RelDynProtocols::CRISIS_SAY_KEY] = ['kind' => 'redemption', 'by' => (string) $anchor, 'gamets' => $currentGamets];
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
            $dynamics[RelDynProtocols::CRISIS_SAY_KEY] = ['kind' => 'breaking', 'gamets' => $currentGamets];
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
     * quest-event-hook: a stage change of a journal quest that names this NPC (RelDynQuests
     * routes core's questlog rows here, once each). Recorded on the NPC (RelDynQuests::EVENTS_KEY:
     * id, name, stage, game time; the last events_keep), advances her intrinsic goals the quest
     * matches (RelDynGoals::onQuestEvent), and a stage config quests.life_changing lists for her
     * is a divine intervention ('quest_event', its severity) once per quest stage.
     * $ctx: 'name' (the journal's quest name), 'objective' (the stage's objective text), 'gamets'.
     *
     * @return array ['recorded' => bool, 'goals' => goal ids advanced, 'divine' => bool]
     */
    public static function onQuestEvent(string $npcName, string $questId, int $stageId, array &$dynamics, array $ctx = []): array
    {
        $out = ['recorded' => false, 'goals' => [], 'divine' => false];
        $questId = trim($questId);
        if ($questId === '') return $out;
        $events = is_array($dynamics[RelDynQuests::EVENTS_KEY] ?? null) ? array_values($dynamics[RelDynQuests::EVENTS_KEY]) : [];
        foreach ($events as $e) {
            if (is_array($e) && ($e['id'] ?? null) === $questId && intval($e['stage'] ?? -1) === $stageId) {
                return $out;   // this stage already reached her (a save reload replays the journal)
            }
        }
        $gamets = floatval($ctx['gamets'] ?? 0) > 0 ? floatval($ctx['gamets']) : self::currentGamets();
        $event = ['id' => $questId, 'name' => trim((string) ($ctx['name'] ?? '')), 'stage' => $stageId, 'gamets' => $gamets];
        $out['goals'] = RelDynGoals::onQuestEvent($npcName, $dynamics, $event + ['objective' => (string) ($ctx['objective'] ?? '')], $gamets);
        $life = RelDynQuests::lifeChanging($npcName, $questId, $stageId);
        if ($life !== null) {
            self::triggerDivineIntervention($npcName, 'quest_event', $life['severity'], $dynamics);
            $event['divine'] = true;
            $out['divine'] = true;
        }
        $events[] = $event;
        $dynamics[RelDynQuests::EVENTS_KEY] = array_slice($events, -max(1, intval(RelDynQuests::config()['events_keep'])));
        $out['recorded'] = true;
        self::log("[RelDyn-QUEST] {$npcName}: {$questId} stage {$stageId}" . ($event['name'] !== '' ? " ({$event['name']})" : '')
            . ($out['goals'] ? ' goals ' . implode(',', $out['goals']) : '') . ($out['divine'] ? ' divine intervention' : ''));
        return $out;
    }

    // ========== DEATH/GRIEF SYSTEM (PR 10; reldyn_protocols.php) ==========

    /**
     * Register an NPC death for a survivor (once per deceased): the grief bond (phases on the game
     * calendar from $at, raw gamets; default the game clock), Phase 1, the widow's lock for a
     * partner (core affinity ceiling 100 - weight x 20) and the survivor's Divine Intervention,
     * whose fork never counts the deceased as an anchor. Model: RelDynProtocols.
     */
    public static function onNpcDeath($deceasedName, $survivorName, &$survivorDynamics, ?float $at = null)
    {
        // Config gate
        $config = self::getConfig();
        if (empty($config['grief_system_enabled'])) {
            return;
        }
        if (isset($survivorDynamics['_grief_bonds'][$deceasedName])) {
            self::log("[GRIEF] Death of {$deceasedName} already registered for {$survivorName}");
            return;
        }
        $cfg = RelDynProtocols::config();

        $bonds = self::getAllBondsForNpc($survivorName);
        $key = RelDynProtocols::bondKey($bonds, (string) $deceasedName);
        $deceasedBond = $key !== null ? $bonds[$key] : null;
        $coreAff = $deceasedBond ? floatval($deceasedBond['aff']) : 0.0;          // core points
        $bondAffinity = $deceasedBond ? (($coreAff + 100) / 2.0) : 0;             // mirror 0..100

        // Bond duration: the play hours the bond has been in the player's world
        $bondDurationHours = RelDynProtocols::bondHours($survivorDynamics, (string) $deceasedName);
        $bondDurationWeight = RelDynProtocols::griefWeight($bondDurationHours, $cfg);
        $lock = $deceasedBond !== null && RelDynProtocols::widowLockApplies($deceasedBond, $cfg);

        $dims = $survivorDynamics['dimensions'] ?? [];
        $death = ($at !== null && $at > 0) ? $at : RelDynProtocols::calendarNow($survivorDynamics);   // raw game calendar

        // Initialize grief bond with per-phase applied flags
        $survivorDynamics['_grief_bonds'][$deceasedName] = [
            'phase'                   => 1,
            'clock'                   => 'calendar',
            'death_gamets'            => $death,
            'bond_duration_hours'     => round($bondDurationHours, 4),   // play hours
            'original_type'           => $deceasedBond['type'] ?? 'friend',
            'bond_affinity_at_death'  => $bondAffinity,
            'core_affinity_at_death'  => $coreAff,
            'widow_lock'              => $lock,
            'bond_type'               => 'grieving',
            'warmth_at_death'         => floatval($dims['warmth']['x'] ?? 0),
            'trust_at_death'          => floatval($dims['trust']['x'] ?? 0),
            'phase_transitions'       => [1 => $death],
            '_phase_1_applied'        => false,
            '_phase_2_applied'        => false,
            '_phase_3_applied'        => false,
            '_phase_4_applied'        => false,
        ];

        // Widow's Lock ceiling (core affinity points), for a partner only
        $ceiling = 100 - ($bondDurationWeight * floatval($cfg['grief']['lock_per_weight']));
        if ($lock) {
            $existingCeiling = floatval($survivorDynamics['_widow_lock_ceiling'] ?? 100);
            $survivorDynamics['_widow_lock_ceiling'] = min($existingCeiling, $ceiling);
        }

        // Phase 1 and its held offsets
        self::applyGriefPhaseOnce($survivorName, (string) $deceasedName, 1, $survivorDynamics);
        RelDynProtocols::tickGrief((string) $survivorName, $survivorDynamics, $death);

        // Trigger Survivor's DI (the deceased anchors no one)
        $severity = min(5, max(1, intval($bondDurationWeight * 2.5)));
        self::triggerDivineIntervention($survivorName, 'companion_death', $severity, $survivorDynamics, [(string) $deceasedName]);

        self::log("[GRIEF] Death of {$deceasedName} registered for {$survivorName}: duration={$bondDurationHours}h weight={$bondDurationWeight}"
            . ($lock ? " widow_lock ceiling={$ceiling} (core)" : ' no widow lock') . ", severity={$severity}");
    }

    /**
     * Process grief phase transitions on the game calendar (prerequest for the NPC spoken to,
     * the calendar step for everyone): RelDynProtocols::tickGrief.
     */
    public static function processGriefPhases($npcName, &$dynamics)
    {
        if (empty($dynamics['_grief_bonds']) && empty($dynamics[RelDynProtocols::GRIEF_HELD_KEY])) return false;
        $config = self::getConfig();
        if (empty($config['grief_system_enabled'])) return false;
        return RelDynProtocols::tickGrief((string) $npcName, $dynamics, RelDynProtocols::calendarNow($dynamics));
    }

    /**
     * A grief phase's one-shot effects (checked via the _phase_X_applied flag). Standing effects
     * (the acute offsets, the valence lock, the memory's warmth) are RelDynProtocols::tickGrief's.
     */
    public static function applyGriefPhaseOnce($npcName, $deceasedName, $phase, &$dynamics)
    {
        $dims = &$dynamics['dimensions'];
        $grief = &$dynamics['_grief_bonds'][$deceasedName];

        // Check if this phase has already been applied
        $appliedKey = "_phase_{$phase}_applied";
        if (!empty($grief[$appliedKey])) {
            return;
        }
        $grief[$appliedKey] = true;
        $g = RelDynProtocols::config()['grief'];

        switch ($phase) {
            case 1: // Acute: held offsets and the valence lock (tickGrief)
                self::log("[GRIEF] Phase 1 (Acute) for {$npcName} re: {$deceasedName}");
                break;

            case 2: // Bargaining: "the world takes people from me"; the memory idealized (tickGrief)
                $dims['trust']['x'] = max(0, floatval($dims['trust']['x'] ?? 50) + floatval($g['bargaining_trust']));
                self::log("[GRIEF] Phase 2 (Bargaining) for {$npcName} re: {$deceasedName}");
                break;

            case 3: // Integration: the other bonds recover, the memory settles (tickGrief)
                self::log("[GRIEF] Phase 3 (Integration) for {$npcName} re: {$deceasedName}");
                break;

            case 4: // Carrying Forward: Grieving -> Memorial
                self::log("[GRIEF] Phase 4 (Carrying Forward) for {$npcName} re: {$deceasedName}: ceiling=" . ($dynamics['_widow_lock_ceiling'] ?? 100));
                break;
        }
    }

    /**
     * Grief keywords for one loss (quiet grief at maturity above grief.quiet_maturity, else public).
     * The felt lines themselves come from RelDynProtocols::griefFeltLines (with coping style).
     */
    public static function getGriefKeywords($npcName, $deceasedName, $phase, $maturity): string
    {
        $quiet = floatval($maturity) >= floatval(RelDynProtocols::config()['grief']['quiet_maturity']);
        return RelDynProtocols::griefText((string) $npcName, (string) $deceasedName, intval($phase), $quiet ? 'quiet' : 'public', 'plain');
    }

    // =========================================================================
    // ATTACHMENT: TWO AXES (decisions 2026-09-24 §12, MDD 6.1)
    //
    // Fraley & Shaver (2000): adult attachment is two continuous dimensions, anxiety (fear of
    // abandonment) and avoidance (discomfort with closeness and dependence once someone is
    // in), each 0..1 ("axis units"). The named styles are regions of that plane:
    //   secure   = low anxiety,  low avoidance
    //   anxious  = high anxiety, low avoidance        (preoccupied)
    //   avoidant = low anxiety,  high avoidance       (dismissive-avoidant)
    //   toxic    = high anxiety, high avoidance       (fearful; MDD 6.1 "Toxic/Disorganized".
    //              'toxic' stays the stored/table label because the MDD protocol tables,
    //              the hoover and the M table key on it)
    // Temperament is a separate axis: Guarded = how hard it is to get IN; avoidance = how
    // uncomfortable closeness stays once someone is in. Guarded does not raise avoidance.
    //
    // Axes per NPC = base + drift offset.
    //   base:  per-NPC override (profile_overrides.attachment_axes, or an explicit
    //          profile_overrides.attachment_style label = that style's textbook point) >
    //          named preset (temperament_autogen npc_overrides.attachment_axes) > derived
    //          (deriveAttachmentAxes: class/role, traits, temperament as a weak prior, loss
    //          history). Computed from the current profile, so an override or a new loss
    //          takes effect at once. Deterministic.
    //   drift: _attachment_drift, moved slowly by sustained experience on the game calendar
    //          (MDD 6.1 "Earned Security", attachmentExperience / driftAttachmentFromDays).
    //          Drift is GLOBAL to the NPC: attachment is a working model the person carries
    //          into every bond (MDD: baselines are global, per-bond is a multiplier). Each
    //          bond's experience drives it, weighted by how much that bond matters
    //          (drift.bond_weight); today the player bond is the only one RelDyn tracks.
    //
    // Label-keyed tables (M rows, neglect codependence, absence, jealousy, intimacy decay,
    // passion, romance pace, masking) are the four CORNERS of the plane: an NPC at a
    // style's textbook point (prototype low/high) gets exactly that row, an NPC between
    // corners the bilinear blend (attachmentWeights / attachmentBlend). The fearful protocol
    // behaviours (hoover, manipulative refusal, maturity floor, conflict-passion) are a
    // region, not a gradient: they read the label.
    // =========================================================================

    const ATTACHMENT_AXES = ['anxiety', 'avoidance'];

    /** State key of the drift offset and its per-day budget / log. */
    const ATTACHMENT_DRIFT_KEY = '_attachment_drift';

    /**
     * Defaults for config key 'attachment' (a stored config replaces whole settings/tables).
     * Numbers are Serene's starting values for Ken's §12 ruling (the MDD gives the styles, not
     * the plane); tune after playtest. Axis values are axis units 0..1.
     */
    public static function attachmentDefaults(): array
    {
        return [
            // Region thresholds: at or above = high on that axis (getAttachmentStyle).
            'thresholds' => ['anxiety' => 0.5, 'avoidance' => 0.5],
            // Axis units. A style reached by drift is held until the axes are this far past a
            // threshold (one small step across the line is not a new style: label-keyed
            // consumers, the refusal type, the diary trigger). A new base (override, preset,
            // loss) or an arc reads the plain region.
            'hysteresis' => 0.05,
            // Textbook points: a style's corner is 'low'/'high' on each axis. An NPC at a corner
            // reads that style's table row exactly; attachmentWeights() is linear between.
            'prototype' => ['low' => 0.15, 'high' => 0.85],

            // --- derivation (deriveAttachmentAxes), additive, then clamped to min..max ---
            'derive' => [
                'base' => ['anxiety' => 0.15, 'avoidance' => 0.15],   // no evidence = textbook secure
                // Class / role (the profile archetype, MDD 1.3 class presets). Self-reliant roles
                // cope through action more than talk.
                'archetype' => [
                    'Ranger'   => ['avoidance' => 0.1],
                    'Assassin' => ['avoidance' => 0.2],
                    'Guard'    => ['avoidance' => 0.05],
                    'Noble'    => ['avoidance' => 0.05],
                    'Healer'   => ['avoidance' => -0.05],
                    'Bard'     => ['anxiety' => 0.05],
                ],
                // Traits (decisions §1): insecure is attachment anxiety; egocentric keeps others
                // at a distance a little (self-sufficient image).
                'traits' => [
                    'insecure'   => ['anxiety' => 0.35],
                    'egocentric' => ['avoidance' => 0.1],
                ],
                // Temperament is only a weak prior. Guarded is deliberately absent: it is how hard
                // it is to get in (openness, entry), not discomfort once someone is in.
                'temperament' => [
                    'Anxious'     => ['anxiety' => 0.2],
                    'Jealous'     => ['anxiety' => 0.15],
                    'Romantic'    => ['anxiety' => 0.05],
                    'Independent' => ['avoidance' => 0.1],
                    'Stoic'       => ['avoidance' => 0.05],
                    'Proud'       => ['avoidance' => 0.05],
                    'Defiant'     => ['avoidance' => 0.05],
                    'Nurturing'   => ['avoidance' => -0.05],
                ],
                // Profile text (core_npc_master text_fields of temperament_autogen, read once by
                // ensureTemperamentProfile): word stems of attachment BEHAVIOUR, per distinct hit,
                // at most text_max_hits per axis. Guarded words (wary, reserved, arm's length:
                // hard to get in) are temperament evidence, not listed here.
                'text_keywords' => [
                    'anxiety'   => ['clingy', 'needy', 'abandon', 'insecure', 'desperate for', 'fears being left', 'afraid of being left'],
                    'avoidance' => ['aloof', 'detached', 'emotionally distant', 'stonewall', 'withdraws', 'withdrawn',
                                    'shuts down', 'shuts people out', 'uncomfortable with affection', 'uncomfortable with closeness'],
                ],
                'text_per_hit' => ['anxiety' => 0.1, 'avoidance' => 0.1],
                'text_max_hits' => 3,
                // Loss history (RelDyn grief bonds: people this NPC lost), per loss, at most
                // loss_max_bonds counted.
                'loss_per_bond' => ['anxiety' => 0.05, 'avoidance' => 0.05],
                'loss_max_bonds' => 3,
                'min' => 0.05,
                'max' => 0.95,
                // Fearful (MDD Toxic/Disorganized) is never derived (MDD 6.1 pipeline: set by
                // hand or by an arc): a derived point in that region has its nearer axis pulled
                // this far below its threshold.
                'fearful_margin' => 0.01,
            ],

            // --- drift (MDD 6.1 "Earned Security" and its "regression slingshot") ---
            'drift' => [
                'enabled' => true,
                // A game day whose day-end fulfillment band (-1..+1, RelDynFulfillment) is at
                // least this, and on which the player had contact, is a fulfilled day.
                'fulfilled_band' => 0.25,
                // Axis units per fulfilled day, x the trust factor (trust / trust_full, 0..1:
                // security is earned as trust is) x the bond weight.
                'fulfilled_per_game_day' => ['anxiety' => -0.004, 'avoidance' => -0.006],
                'trust_full' => 70.0,   // trust points (0..100) from which a fulfilled day counts in full
                // Axis units per low day (band below fulfillment low_band, on a day that counts:
                // the player was there, or the absence ran past its grace), x the depth
                // (low_band .. -1 -> 0 .. 1, as unfulfilled neglect) x the bond weight.
                'low_per_game_day' => ['anxiety' => 0.006, 'avoidance' => 0.003],
                // Discrete experiences, axis units per event (x the bond weight, x significance for
                // eval tags). Negative = toward security.
                'events' => [
                    'repair'            => ['anxiety' => -0.02, 'avoidance' => -0.03],   // a conflict resolved through contact
                    'boundary_kept'     => ['anxiety' => -0.02, 'avoidance' => -0.03],   // the player respected their boundary
                    'betrayal'          => ['anxiety' => 0.04, 'avoidance' => 0.04],
                    'lie'               => ['anxiety' => 0.015, 'avoidance' => 0.01],
                    'walkaway'          => ['anxiety' => 0.03, 'avoidance' => 0.04],     // things got bad enough that they left
                    'boundary_violated' => ['anxiety' => 0.02, 'avoidance' => 0.04],     // followed while they needed space
                    // MDD 3.3 rescue response, Anxious: "massive bonding, but creates dependency
                    // pattern" (x the NPC's anxious lean, RelDynCombat::rescueResponse)
                    'rescue_dependency' => ['anxiety' => 0.02, 'avoidance' => 0.0],
                ],
                // Eval source tags that are attachment experiences (tag => event).
                'tag_events' => ['betrayal' => 'betrayal', 'lie' => 'lie'],
                // MDD 6.1: earned security is possible "but massive regression slingshot on
                // neglect": a rise of an axis that sits below its base is x this until back at base.
                'slingshot_mult' => 3.0,
                // Bounds: all drift together moves an axis at most max_per_game_day per game day,
                // and never further than max_from_base from the base (arcs excepted).
                'max_per_game_day' => 0.03,
                'max_from_base' => 0.4,
                // Drift alone never carries an NPC into the fearful region (arcs and overrides can).
                'reach_fearful' => false,
                // How much a bond's experience moves the NPC (RelDyn relationship type; default for
                // types not listed).
                'bond_weight' => [
                    'bonded' => 1.0, 'crush' => 0.8, 'sworn' => 0.8, 'friend' => 0.6, 'friendzone' => 0.6,
                    'parasite' => 0.4, 'rival' => 0.3, 'hostile' => 0.3, 'acquaintance' => 0.2,
                    'mercenary' => 0.2, 'stranger' => 0.0, 'default' => 0.3,
                ],
                'log_size' => 12,
            ],

            // Divine-intervention arcs (PR 10): a one-time shift in axis units, not budgeted.
            // Redemption lowers both axes; breaking raises avoidance (anxiety for a secure NPC with
            // self_confidence <= 50, as the April table: secure -> anxious/avoidant, anxious -> toxic).
            // From the fearful region redemption moves each axis by this share of the step
            // (unitless): avoidance only, so fearful -> anxious as the April table (the fear of
            // abandonment outlasts the push-pull), not straight to secure.
            'arcs' => ['redemption' => 0.4, 'breaking' => 0.4,
                       'redemption_from_fearful' => ['anxiety' => 0.0, 'avoidance' => 1.0]],

            // Style rows (MDD 6.1 / PR 10), blended by attachmentWeights() unless the key is a
            // region key. Units: multipliers unitless, thresholds in dimension points 0..100,
            // absence_comfort_delta comfort points per absence tick, conflict_passion_gain
            // passion points per resentment gain.
            'modifiers' => [
                'secure' => [
                    'resentment_gain_mult' => 1.0, 'confrontation_threshold' => 50, 'absence_comfort_delta' => 0.0,
                    'affinity_absence_mult' => 1.0, 'jealousy_mult' => 1.0, 'maturity_floor' => null,
                    'conflict_passion_gain' => 0.0, 'suffocation_threshold' => null,
                    'reunion_mult' => 1.0, 'trust_gain_mult' => 1.0,
                ],
                'avoidant' => [
                    'resentment_gain_mult' => 1.0, 'confrontation_threshold' => 70, 'absence_comfort_delta' => 0.5,
                    'affinity_absence_mult' => 0.5,   // decisions 2026-09-23 section 2: Avoidant x0.5
                    'jealousy_mult' => 0.5, 'maturity_floor' => null,
                    'conflict_passion_gain' => 0.0, 'suffocation_threshold' => 60,
                    'reunion_mult' => 1.0, 'trust_gain_mult' => 1.0,
                ],
                'anxious' => [
                    'resentment_gain_mult' => 1.5, 'confrontation_threshold' => 30, 'absence_comfort_delta' => -1.0,
                    'affinity_absence_mult' => 2.0, 'jealousy_mult' => 2.0, 'maturity_floor' => null,
                    'conflict_passion_gain' => 0.0, 'suffocation_threshold' => null,
                    // traits phase 3: the anxiety part of the MDD 1.3 reunion column (Anxious 1.8 =
                    // model 1.31 x 1.4), moved here from temperament (reunion multiplier, unitless)
                    'reunion_mult' => 1.4,
                    // traits phase 3: the anxiety part of the MDD 1.5 trust-gain row (A15h; Anxious
                    // 1.5 = model 0.84 x 1.8), moved here from temperament (trust gain multiplier,
                    // unitless): an anxious attachment trusts fast
                    'trust_gain_mult' => 1.8,
                ],
                'toxic' => [
                    'resentment_gain_mult' => 1.3, 'confrontation_threshold' => 50, 'absence_comfort_delta' => 0.0,
                    'affinity_absence_mult' => 1.0, 'jealousy_mult' => 1.5, 'maturity_floor' => 30,
                    'conflict_passion_gain' => 5.0, 'suffocation_threshold' => null,
                    'reunion_mult' => 1.0, 'trust_gain_mult' => 1.0,
                ],
            ],
            // Keys read from the NPC's region (label), never blended: the fearful protocol.
            'region_keys' => ['maturity_floor', 'conflict_passion_gain', 'suffocation_threshold', 'confrontation_threshold'],

            // Felt text (the eval's state summary): how the NPC attaches, as behaviour. Never
            // numbers, never a style name. 'mild' when the NPC sits less than mild_below of the
            // way into its region's corner (attachmentWeights).
            'felt_text' => [
                'secure'   => 'at ease with closeness; trusts it will hold',
                'anxious'  => 'craves closeness and fears losing it; reads distance as a warning',
                'avoidant' => 'keeps some distance even once someone is in; copes alone before leaning on anyone',
                'toxic'    => 'wants closeness and fears it at once; pulls close, then pushes away',
                'mild_prefix' => 'a little ',
                'mild_below' => 0.5,
                // Inside the secure region: how far the NPC sits toward the high end of an axis
                // (0..1 between prototype low and high) from which that axis colours the text
                // (the larger one only).
                'secure_lean' => [
                    'anxiety'   => 'mostly at ease with closeness, though a little watchful for signs of distance',
                    'avoidance' => 'mostly at ease with closeness, though copes alone before leaning on anyone',
                ],
                'lean_at' => 0.25,
            ],
        ];
    }

    /** The attachment settings: stored config per setting/table, defaults for the rest. */
    public static function getAttachmentConfig(): array
    {
        $defaults = self::attachmentDefaults();
        $stored = self::configValue('attachment');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /** A style's textbook point (prototype corner), or null for an unknown label. */
    public static function attachmentPrototype(string $style, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $lo = floatval($cfg['prototype']['low'] ?? 0.15);
        $hi = floatval($cfg['prototype']['high'] ?? 0.85);
        return match (strtolower(trim($style))) {
            'secure'   => ['anxiety' => $lo, 'avoidance' => $lo],
            'anxious'  => ['anxiety' => $hi, 'avoidance' => $lo],
            'avoidant' => ['anxiety' => $lo, 'avoidance' => $hi],
            'toxic', 'fearful' => ['anxiety' => $hi, 'avoidance' => $hi],
            default    => null,
        };
    }

    /** ['anxiety' => 0..1, 'avoidance' => 0..1] from an override array, or null when it is not one. */
    public static function validAttachmentAxes($axes): ?array
    {
        if (!is_array($axes)) return null;
        $out = [];
        foreach (self::ATTACHMENT_AXES as $axis) {
            $v = $axes[$axis] ?? null;
            if (!is_numeric($v) || floatval($v) < 0.0 || floatval($v) > 1.0) return null;
            $out[$axis] = floatval($v);
        }
        return $out;
    }

    /**
     * The derivation (decisions §12): base + the rows matching the NPC's class/role archetype,
     * traits, temperament (weak prior) and loss history, clamped to min..max; a point in the
     * fearful region is pulled out of it (never derived). Pure: same inputs and config, same
     * result.
     *
     * @param array $in ['archetype' => ?string, 'temperament' => ?string, 'traits' => string[], 'losses' => int,
     *                   'text_hits' => ['anxiety' => int, 'avoidance' => int] (attachmentTextHits)]
     * @return array ['anxiety' => 0..1, 'avoidance' => 0..1, 'signals' => string[]]
     */
    public static function deriveAttachmentAxes(array $in, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $d = (array) ($cfg['derive'] ?? []);
        $v = ['anxiety' => floatval($d['base']['anxiety'] ?? 0.15), 'avoidance' => floatval($d['base']['avoidance'] ?? 0.15)];
        $signals = [];
        $add = function ($row, float $scale, string $signal) use (&$v, &$signals) {
            $used = false;
            foreach ((array) $row as $axis => $delta) {
                if (!array_key_exists($axis, $v) || !is_numeric($delta) || floatval($delta) == 0.0) continue;
                $v[$axis] += floatval($delta) * $scale;
                $used = true;
            }
            if ($used) $signals[] = $scale == 1.0 ? $signal : sprintf('%s x%d', $signal, (int) $scale);
        };
        if (!empty($in['archetype'])) $add(((array) ($d['archetype'] ?? []))[$in['archetype']] ?? [], 1.0, "archetype:{$in['archetype']}");
        foreach ((array) ($in['traits'] ?? []) as $trait) {
            $trait = strtolower((string) $trait);
            $add(((array) ($d['traits'] ?? []))[$trait] ?? [], 1.0, "trait:{$trait}");
        }
        // C2 through the trait engine: anxiety Rule I, avoidance Rule R (0.08 - 0.21 W)
        if (!empty($in['temperament'])) $add(RelDynTraits::rowParam($in['temperament'], (array) ($d['temperament'] ?? []),
            ['avoidance' => ['R', [0.08, 'W' => -0.21]]], 'offset', is_array($in['dynamics'] ?? null) ? $in['dynamics'] : null), 1.0, "temperament:{$in['temperament']}");
        $maxHits = max(0, intval($d['text_max_hits'] ?? 3));
        foreach (self::ATTACHMENT_AXES as $axis) {
            $hits = min($maxHits, max(0, intval($in['text_hits'][$axis] ?? 0)));
            if ($hits > 0) $add([$axis => floatval($d['text_per_hit'][$axis] ?? 0.0)], (float) $hits, "text:{$axis}");
        }
        $losses = min(max(0, intval($in['losses'] ?? 0)), max(0, intval($d['loss_max_bonds'] ?? 3)));
        if ($losses > 0) $add((array) ($d['loss_per_bond'] ?? []), (float) $losses, 'loss');

        $min = floatval($d['min'] ?? 0.05);
        $max = floatval($d['max'] ?? 0.95);
        foreach ($v as $axis => $x) $v[$axis] = max($min, min($max, $x));

        $t = (array) ($cfg['thresholds'] ?? []);
        $tA = floatval($t['anxiety'] ?? 0.5);
        $tV = floatval($t['avoidance'] ?? 0.5);
        if ($v['anxiety'] >= $tA && $v['avoidance'] >= $tV) {
            $margin = floatval($d['fearful_margin'] ?? 0.01);
            if ($v['anxiety'] - $tA <= $v['avoidance'] - $tV) $v['anxiety'] = $tA - $margin; else $v['avoidance'] = $tV - $margin;
            $signals[] = 'not_fearful';
        }
        return ['anxiety' => round($v['anxiety'], 6), 'avoidance' => round($v['avoidance'], 6), 'signals' => $signals];
    }

    /**
     * Distinct attachment-behaviour keyword hits (config attachment.derive.text_keywords, word
     * start, case-insensitive) in a core_npc_master row's profile text, per axis. Pure.
     */
    public static function attachmentTextHits(array $row, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $text = '';
        foreach ((array) (self::getTemperamentAutogenConfig()['text_fields'] ?? []) as $field) {
            if (!empty($row[$field]) && is_string($row[$field])) $text .= ' ' . $row[$field];
        }
        $out = ['anxiety' => 0, 'avoidance' => 0];
        if (trim($text) === '') return $out;
        foreach ((array) ($cfg['derive']['text_keywords'] ?? []) as $axis => $stems) {
            if (!array_key_exists($axis, $out)) continue;
            foreach ((array) $stems as $stem) {
                if (preg_match('/\b' . preg_quote((string) $stem, '/') . '/iu', $text)) $out[$axis]++;
            }
        }
        return $out;
    }

    /** The derivation's inputs from RelDyn state (profile archetype, temperament, traits, text hits, grief bonds). */
    public static function attachmentInputs(array $dynamics): array
    {
        $hits = $dynamics['_profile_autogen']['attachment_text'] ?? null;
        return [
            'text_hits'   => is_array($hits) ? $hits : [],
            'archetype'   => is_string($dynamics['_profile_autogen']['archetype'] ?? null) ? $dynamics['_profile_autogen']['archetype'] : null,
            'temperament' => self::validTemperament($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null),
            'traits'      => self::attachmentTraitEvidence($dynamics),
            'losses'      => is_array($dynamics['_grief_bonds'] ?? null) ? count($dynamics['_grief_bonds']) : 0,
            // read assignment: C2 reads the NPC's own vector (RelDynTraits::readVector)
            'dynamics'    => RelDynTraits::readVector($dynamics) !== null ? $dynamics : null,
        ];
    }

    /**
     * The NPC's traits that are evidence about attachment (decisions §12: temperament is only a
     * weak prior). A trait the profile carries only because its temperament implies it
     * (temperament_autogen temperament_traits, e.g. Anxious -> insecure, traits_source
     * 'derived') is the temperament again and is left out; one set on the NPC (override, preset,
     * editor), or implied by its class archetype, counts.
     */
    private static function attachmentTraitEvidence(array $dynamics): array
    {
        $traits = self::getTraits($dynamics);
        if (isset($dynamics['profile_overrides']['traits']) || ($dynamics['_profile_autogen']['traits_source'] ?? null) !== 'derived') {
            return $traits;
        }
        $acfg = self::getTemperamentAutogenConfig();
        $temperament = self::validTemperament($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null);
        $archetype = $dynamics['_profile_autogen']['archetype'] ?? null;
        $vector = RelDynTraits::readVector($dynamics);
        $implied = $vector !== null
            ? RelDynTraitAssign::tagsOf($vector)   // read assignment: the C1 tags its pride / confidence imply
            : array_map('strtolower', (array) RelDynTraits::labelParam($temperament ?? '', (array) ($acfg['temperament_traits'] ?? []), []));
        $fromRole = is_string($archetype) ? array_map('strtolower', (array) (((array) ($acfg['archetype_traits'] ?? []))[$archetype] ?? [])) : [];
        return array_values(array_filter($traits, fn($t) => !in_array($t, $implied, true) || in_array($t, $fromRole, true)));
    }

    /**
     * The NPC's base axes before drift: override > preset > derived (section comment).
     *
     * @return array ['anxiety', 'avoidance', 'source' => override|preset|derived, 'signals' => string[]]
     */
    public static function attachmentBase(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $overrides = is_array($dynamics['profile_overrides'] ?? null) ? $dynamics['profile_overrides'] : [];
        $axes = self::validAttachmentAxes($overrides['attachment_axes'] ?? null);
        if ($axes !== null) return $axes + ['source' => 'override', 'signals' => ['override:axes']];
        $style = self::validAttachmentStyle($overrides['attachment_style'] ?? null);
        if ($style !== null) return self::attachmentPrototype($style, $cfg) + ['source' => 'override', 'signals' => ["override:{$style}"]];

        $preset = (array) ($dynamics['_profile_autogen']['preset'] ?? []);
        $axes = self::validAttachmentAxes($preset['attachment_axes'] ?? null);
        if ($axes !== null) return $axes + ['source' => 'preset', 'signals' => ['preset:axes']];
        $style = self::validAttachmentStyle($preset['attachment_style'] ?? null);
        if ($style !== null) return self::attachmentPrototype($style, $cfg) + ['source' => 'preset', 'signals' => ["preset:{$style}"]];

        $derived = self::deriveAttachmentAxes(self::attachmentInputs($dynamics), $cfg);
        return ['anxiety' => $derived['anxiety'], 'avoidance' => $derived['avoidance'], 'source' => 'derived', 'signals' => $derived['signals']];
    }

    /**
     * The NPC's attachment now: base + drift offset, each axis 0..1. With attachment styles off
     * every NPC reads the secure textbook point (the April no-op).
     *
     * @return array ['anxiety', 'avoidance', 'base' => ['anxiety', 'avoidance'], 'source', 'signals']
     */
    public static function getAttachmentAxes($dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $dynamics = is_array($dynamics) ? $dynamics : [];
        if (empty(self::getConfig()['attachment_style_enabled'])) {
            $p = self::attachmentPrototype('secure', $cfg);
            return $p + ['base' => $p, 'source' => 'disabled', 'signals' => []];
        }
        $base = self::attachmentBase($dynamics, $cfg);
        $drift = is_array($dynamics[self::ATTACHMENT_DRIFT_KEY] ?? null) ? $dynamics[self::ATTACHMENT_DRIFT_KEY] : [];
        return self::attachmentPoint($base, $drift, $cfg) + ['base' => ['anxiety' => $base['anxiety'], 'avoidance' => $base['avoidance']],
                       'source' => $base['source'], 'signals' => $base['signals']];
    }

    /**
     * Base + drift offset, with the drift invariants applied on every read (the base is live:
     * an override or a new loss moves it under an old offset, and concurrent saves add offsets):
     *  - the drift keeps an axis within derive.min..max (a base outside them stays where it is);
     *  - drift never carries a non-fearful base into the fearful region (unless reach_fearful or
     *    an arc took the NPC there, drift state arc_fearful): the axis the drift carried over
     *    the line with the smaller excess stops at the fearful edge (threshold - fearful_margin).
     *
     * @param array $base  ['anxiety', 'avoidance'] axis units
     * @param array $drift the ATTACHMENT_DRIFT_KEY state
     * @return array ['anxiety' => 0..1, 'avoidance' => 0..1]
     */
    private static function attachmentPoint(array $base, array $drift, array $cfg): array
    {
        $min = floatval($cfg['derive']['min'] ?? 0.05);
        $max = floatval($cfg['derive']['max'] ?? 0.95);
        $p = [];
        foreach (self::ATTACHMENT_AXES as $axis) {
            $b = floatval($base[$axis]);
            $p[$axis] = self::clamp01(max(min($min, $b), min(max($max, $b), $b + floatval($drift[$axis] ?? 0.0))));
        }
        if (!empty($cfg['drift']['reach_fearful']) || !empty($drift['arc_fearful'])
            || self::attachmentStyleOf($p['anxiety'], $p['avoidance'], $cfg) !== 'toxic'
            || self::attachmentStyleOf(floatval($base['anxiety']), floatval($base['avoidance']), $cfg) === 'toxic') {
            return $p;
        }
        $pull = null;
        foreach (self::ATTACHMENT_AXES as $axis) {
            $t = floatval($cfg['thresholds'][$axis] ?? 0.5);
            if (floatval($base[$axis]) >= $t) continue;   // the base was already high here: not what drift crossed
            if ($pull === null || $p[$axis] - $t < $p[$pull] - floatval($cfg['thresholds'][$pull] ?? 0.5)) $pull = $axis;
        }
        $p[$pull] = floatval($cfg['thresholds'][$pull] ?? 0.5) - floatval($cfg['derive']['fearful_margin'] ?? 0.01);
        return $p;
    }

    /** The style region of a point (thresholds inclusive): secure|anxious|avoidant|toxic (= fearful). */
    public static function attachmentStyleOf(float $anxiety, float $avoidance, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $hiA = $anxiety >= floatval($cfg['thresholds']['anxiety'] ?? 0.5);
        $hiV = $avoidance >= floatval($cfg['thresholds']['avoidance'] ?? 0.5);
        return $hiA ? ($hiV ? 'toxic' : 'anxious') : ($hiV ? 'avoidant' : 'secure');
    }

    /**
     * The named style derived from the axes, for display and the tables that key on it: the
     * region, or the style drift last settled in while the axes are within config hysteresis of
     * it on the same base (attachmentHeldStyle).
     */
    public static function getAttachmentStyle($dynamics): string
    {
        $cfg = self::getAttachmentConfig();
        $a = self::getAttachmentAxes($dynamics, $cfg);
        $drift = is_array($dynamics) && is_array($dynamics[self::ATTACHMENT_DRIFT_KEY] ?? null) ? $dynamics[self::ATTACHMENT_DRIFT_KEY] : [];
        return self::attachmentHeldStyle($a, $a['base'], $drift, $cfg);
    }

    /**
     * The region of point $a, unless the drift state holds a style (drift.style, reached on
     * drift.style_base) that $a is still within hysteresis of, on the same base: then that
     * style. A different base (override, preset, loss) reads the plain region.
     */
    private static function attachmentHeldStyle(array $a, array $base, array $drift, array $cfg): string
    {
        $plain = self::attachmentStyleOf($a['anxiety'], $a['avoidance'], $cfg);
        $held = self::validAttachmentStyle($drift['style'] ?? null);
        $heldBase = $drift['style_base'] ?? null;
        if ($held === null || $held === $plain || !is_array($heldBase)) return $plain;
        foreach (self::ATTACHMENT_AXES as $axis) {
            if (!is_numeric($heldBase[$axis] ?? null) || abs(floatval($heldBase[$axis]) - floatval($base[$axis])) > 1e-9) return $plain;
        }
        $h = max(0.0, floatval($cfg['hysteresis'] ?? 0.0));
        $high = ['anxiety' => in_array($held, ['anxious', 'toxic'], true), 'avoidance' => in_array($held, ['avoidant', 'toxic'], true)];
        foreach (self::ATTACHMENT_AXES as $axis) {
            $x = round(floatval($a[$axis]), 9);
            $t = floatval($cfg['thresholds'][$axis] ?? 0.5);
            if ($high[$axis] ? $x <= round($t - $h, 9) : $x >= round($t + $h, 9)) return $plain;
        }
        return $held;
    }

    /**
     * How far the NPC sits toward each style's corner (bilinear, sums to 1): with
     * u = (anxiety - low) / (high - low) and v the same for avoidance, both clamped 0..1,
     * secure (1-u)(1-v), anxious u(1-v), avoidant (1-u)v, toxic uv.
     */
    public static function attachmentWeights($dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $a = self::getAttachmentAxes($dynamics, $cfg);
        $lo = floatval($cfg['prototype']['low'] ?? 0.15);
        $span = max(1e-9, floatval($cfg['prototype']['high'] ?? 0.85) - $lo);
        // rounded so a textbook point reads its corner exactly (0.85 - 0.15 is not 0.7 in floats)
        $u = round(self::clamp01(($a['anxiety'] - $lo) / $span), 9);
        $v = round(self::clamp01(($a['avoidance'] - $lo) / $span), 9);
        return ['secure' => (1 - $u) * (1 - $v), 'anxious' => $u * (1 - $v), 'avoidant' => (1 - $u) * $v, 'toxic' => $u * $v];
    }

    /**
     * A label-keyed table (style => number) read at the NPC's point: the corner-weighted
     * blend. A style the table leaves out counts as $default.
     */
    public static function attachmentBlend($dynamics, array $table, float $default = 1.0): float
    {
        $sum = 0.0;
        foreach (self::attachmentWeights($dynamics) as $style => $w) {
            $x = $table[$style] ?? $default;
            $sum += $w * (is_numeric($x) ? floatval($x) : $default);
        }
        return $sum;
    }

    /**
     * A style modifier (config attachment.modifiers): blended across the corners, or read from
     * the NPC's region for the region keys (the fearful protocol) and keys without a number on
     * every row.
     */
    public static function getAttachmentModifier($dynamics, string $key)
    {
        $cfg = self::getAttachmentConfig();
        $rows = (array) ($cfg['modifiers'] ?? []);
        $values = [];
        foreach (['secure', 'anxious', 'avoidant', 'toxic'] as $style) {
            $values[$style] = ((array) ($rows[$style] ?? []))[$key] ?? null;
        }
        $numeric = count(array_filter($values, 'is_numeric')) === 4;
        if (!$numeric || in_array($key, (array) ($cfg['region_keys'] ?? []), true)) {
            return $values[self::getAttachmentStyle($dynamics)];
        }
        return self::attachmentBlend($dynamics, $values);
    }

    /** Felt phrase for how the NPC attaches (behaviour, never numbers or a style name). */
    public static function attachmentFeltText($dynamics): string
    {
        $cfg = self::getAttachmentConfig();
        $t = (array) ($cfg['felt_text'] ?? []);
        $style = self::getAttachmentStyle($dynamics);
        $text = (string) ($t[$style] ?? '');
        $w = self::attachmentWeights($dynamics, $cfg);
        if ($style !== 'secure') {
            if ($w[$style] < floatval($t['mild_below'] ?? 0.5)) $text = (string) ($t['mild_prefix'] ?? '') . $text;
            return $text;
        }
        // Secure: the axis the NPC sits furthest toward (0..1 of the way from prototype low to
        // high: the corner weights summed along that axis) colours the text from lean_at.
        $toward = ['anxiety' => $w['anxious'] + $w['toxic'], 'avoidance' => $w['avoidant'] + $w['toxic']];
        $axis = $toward['avoidance'] >= $toward['anxiety'] ? 'avoidance' : 'anxiety';
        $lean = ((array) ($t['secure_lean'] ?? []))[$axis] ?? null;
        if (is_string($lean) && $lean !== '' && $toward[$axis] >= floatval($t['lean_at'] ?? 0.25)) $text = $lean;
        return $text;
    }

    /** How much this NPC's bond with the player moves its attachment (drift.bond_weight, 0..1). */
    public static function attachmentBondWeight(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::getAttachmentConfig();
        $w = (array) ($cfg['drift']['bond_weight'] ?? []);
        $type = (string) self::getRelationshipType('', $dynamics);
        return self::clamp01(floatval($w[$type] ?? $w['default'] ?? 0.0));
    }

    /**
     * One attachment experience (config drift.events): moves the axes by the event's row x the
     * bond weight x $scale (eval significance), within the drift bounds. Unknown events are
     * logged, not applied.
     *
     * @param float $gamets raw game-calendar gamets of the experience (the day budget's day)
     * @return array axis => axis units actually moved
     */
    public static function attachmentExperience(array &$dynamics, string $event, float $gamets, float $scale = 1.0, string $npcName = ''): array
    {
        $cfg = self::getAttachmentConfig();
        $drift = (array) ($cfg['drift'] ?? []);
        if (empty($drift['enabled']) || empty(self::getConfig()['attachment_style_enabled'])) return [];
        $row = ((array) ($drift['events'] ?? []))[$event] ?? null;
        if (!is_array($row)) {
            error_log("[RelDyn] attachment experience '{$event}' is not in config attachment.drift.events; ignored");
            return [];
        }
        $k = self::attachmentBondWeight($dynamics, $cfg) * max(0.0, $scale);
        $delta = [];
        foreach (self::ATTACHMENT_AXES as $axis) $delta[$axis] = floatval($row[$axis] ?? 0.0) * $k;
        $moved = self::moveAttachment($dynamics, $delta, $gamets, $event, $cfg);
        if ($moved) self::log("[ATTACHMENT] {$npcName} {$event}: " . self::attachmentMoveText($moved));
        return $moved;
    }

    /**
     * Sustained experience from fulfillment day-end samples ([[gamets, band], ...],
     * RelDynFulfillment::tick): a fulfilled day with contact lowers the axes (x trust factor),
     * a low day that counts raises them (x depth). Nothing while a walkaway lasts (the
     * walkaway itself was the experience).
     *
     * @return array axis => axis units moved in total
     */
    public static function driftAttachmentFromDays(array &$dynamics, array $samples, string $npcName = ''): array
    {
        $cfg = self::getAttachmentConfig();
        $drift = (array) ($cfg['drift'] ?? []);
        if (empty($drift['enabled']) || empty(self::getConfig()['attachment_style_enabled']) || $samples === []) return [];
        if (($dynamics['_walkaway_state'] ?? 'normal') !== 'normal') return [];
        $state = RelDynFulfillment::pairState($dynamics) ?? [];   // the player pair
        $fcfg = RelDynFulfillment::config();
        $low = floatval($fcfg['low_band']);
        $graceEnd = self::neglectGraceEndGamets($dynamics);
        $bond = self::attachmentBondWeight($dynamics, $cfg);
        $trust = floatval($dynamics['dimensions']['trust']['x'] ?? 50);   // trust points 0..100
        $trustFactor = self::clamp01($trust / max(1e-9, floatval($drift['trust_full'] ?? 70)));
        $total = [];
        foreach ($samples as $sample) {
            [$t, $band] = [floatval($sample[0] ?? 0), floatval($sample[1] ?? 0)];
            $present = RelDynFulfillment::wasPresentOn($state, RelDynFulfillment::gameDayEndedAt($t));
            if ($present && $band >= floatval($drift['fulfilled_band'] ?? 0.25)) {
                $row = (array) ($drift['fulfilled_per_game_day'] ?? []);
                $k = $trustFactor * $bond;
                $why = 'fulfilled_day';
            } elseif ($band < $low && ($present || $graceEnd === null || $t > $graceEnd)) {
                $row = (array) ($drift['low_per_game_day'] ?? []);
                $k = self::clamp01(($low - $band) / max(0.001, $low + 1.0)) * $bond;
                $why = 'low_day';
            } else {
                continue;
            }
            $delta = [];
            foreach (self::ATTACHMENT_AXES as $axis) $delta[$axis] = floatval($row[$axis] ?? 0.0) * $k;
            foreach (self::moveAttachment($dynamics, $delta, $t, $why, $cfg) as $axis => $m) {
                $total[$axis] = ($total[$axis] ?? 0.0) + $m;
            }
        }
        if ($total) self::log("[ATTACHMENT] {$npcName} " . count($samples) . ' day(s): ' . self::attachmentMoveText($total));
        return $total;
    }

    private static function attachmentMoveText(array $moved): string
    {
        $parts = [];
        foreach ($moved as $axis => $m) $parts[] = sprintf('%s %+.4f', $axis, $m);
        return implode(', ', $parts);
    }

    /**
     * Move the drift offset by $delta (axis => axis units) at game time $gamets:
     *  - a rise of an axis below its base is x slingshot_mult until back at base (MDD 6.1);
     *  - $bounded: at most max_per_game_day per axis per game day (all sources), never further
     *    than max_from_base from the base (an offset already beyond, from an arc, may only shrink),
     *    and never into the fearful region unless reach_fearful;
     *  - the axis stays within derive.min..max.
     * Arcs ($bounded false) spend no day budget; an arc that lands in the fearful region marks
     * it (arc_fearful: attachmentPoint lets it stand), any move out of it clears the mark. The
     * style the move settles in (attachmentHeldStyle; an arc: the plain region) is kept with
     * the base it was reached on (style, style_base).
     * The offset, the day budget and a short log live in $dynamics[ATTACHMENT_DRIFT_KEY].
     *
     * @return array axis => axis units actually moved (only axes that moved)
     */
    private static function moveAttachment(array &$dynamics, array $delta, float $gamets, string $why, array $cfg, bool $bounded = true): array
    {
        $drift = (array) ($cfg['drift'] ?? []);
        $state = is_array($dynamics[self::ATTACHMENT_DRIFT_KEY] ?? null) ? $dynamics[self::ATTACHMENT_DRIFT_KEY] : [];
        $base = self::attachmentBase($dynamics, $cfg);
        $min = floatval($cfg['derive']['min'] ?? 0.05);
        $max = floatval($cfg['derive']['max'] ?? 0.95);
        $t = ['anxiety' => floatval($cfg['thresholds']['anxiety'] ?? 0.5), 'avoidance' => floatval($cfg['thresholds']['avoidance'] ?? 0.5)];
        $margin = floatval($cfg['derive']['fearful_margin'] ?? 0.01);
        $cur = self::attachmentPoint($base, $state, $cfg);
        $styleBefore = self::attachmentHeldStyle($cur, $base, $state, $cfg);
        $day = $gamets > 0 ? (int) floor($gamets / self::GAMETS_PER_DAY) : 0;
        if (intval($state['day'] ?? -1) !== $day) {
            $state['day'] = $day;
            $state['moved'] = ['anxiety' => 0.0, 'avoidance' => 0.0];
        }
        $moved = [];
        foreach (self::ATTACHMENT_AXES as $axis) {
            $d = floatval($delta[$axis] ?? 0.0);
            if (abs($d) < 1e-12) continue;
            $old = $cur[$axis] - $base[$axis];   // the offset as read (attachmentPoint), not a stale stored one
            $gap = $base[$axis] - $cur[$axis];                       // > 0: sits below its base (earned)
            $mult = max(1.0, floatval($drift['slingshot_mult'] ?? 1.0));
            if ($d > 0 && $gap > 0) {
                $d = ($d * $mult <= $gap) ? $d * $mult : $gap + ($d - $gap / $mult);
            }
            if ($bounded) {
                $room = max(0.0, floatval($drift['max_per_game_day'] ?? 0.03) - floatval($state['moved'][$axis] ?? 0.0));
                $d = max(-$room, min($room, $d));
            }
            $new = $old + $d;
            if ($bounded) {
                $limit = max(floatval($drift['max_from_base'] ?? 0.4), abs($old));
                $new = max(-$limit, min($limit, $new));
            }
            $x = max(min($min, $base[$axis]), min(max($max, $base[$axis]), $base[$axis] + $new));
            if ($bounded && $d > 0 && empty($drift['reach_fearful'])) {
                $other = $axis === 'anxiety' ? 'avoidance' : 'anxiety';
                if ($cur[$other] >= $t[$other] && $x >= $t[$axis]) {
                    $x = max($cur[$axis], min($x, $t[$axis] - $margin));   // stop at the fearful edge
                }
            }
            $applied = $x - $cur[$axis];
            if (abs($applied) < 1e-12) continue;
            $state[$axis] = round($x - $base[$axis], 6);
            $cur[$axis] = $x;
            if ($bounded) $state['moved'][$axis] = round(floatval($state['moved'][$axis] ?? 0.0) + abs($applied), 6);
            $moved[$axis] = $applied;
        }
        if ($moved) {
            $log = is_array($state['log'] ?? null) ? $state['log'] : [];
            $log[] = ['gamets' => $gamets, 'why' => $why] + array_map(fn($m) => round($m, 5), $moved);
            $state['log'] = array_slice($log, -max(1, intval($drift['log_size'] ?? 12)));
            $state['anxiety'] = floatval($state['anxiety'] ?? 0.0);
            $state['avoidance'] = floatval($state['avoidance'] ?? 0.0);
            $fearful = self::attachmentStyleOf($cur['anxiety'], $cur['avoidance'], $cfg) === 'toxic';
            if (!$bounded) {
                $state['arc_fearful'] = $fearful && self::attachmentStyleOf($base['anxiety'], $base['avoidance'], $cfg) !== 'toxic';
            } elseif (!$fearful) {
                $state['arc_fearful'] = false;
            }
            $state['style'] = $bounded ? self::attachmentHeldStyle($cur, $base, ['style' => $styleBefore,
                'style_base' => ['anxiety' => $base['anxiety'], 'avoidance' => $base['avoidance']]], $cfg)
                : self::attachmentStyleOf($cur['anxiety'], $cur['avoidance'], $cfg);
            $state['style_base'] = ['anxiety' => $base['anxiety'], 'avoidance' => $base['avoidance']];
            $dynamics[self::ATTACHMENT_DRIFT_KEY] = $state;
        }
        return $moved;
    }

    /**
     * A divine-intervention arc's attachment shift (PR 10, flagged by the redemption / breaking
     * arcs): one unbudgeted move (config attachment.arcs), the only drift that may enter the
     * fearful region. Returns the style after the shift when it changed, else null.
     */
    public static function processAttachmentShift(&$dynamics): ?string
    {
        if (empty($dynamics['_attachment_shift_available'])) {
            return null;
        }
        $config = self::getConfig();
        if (empty($config['attachment_style_enabled']) || empty($config['divine_intervention_enabled'])) {
            return null;
        }
        $dynamics['_attachment_shift_available'] = false;

        $cfg = self::getAttachmentConfig();
        $before = self::getAttachmentStyle($dynamics);
        $diType = $dynamics['_divine_intervention_last_type'] ?? null;
        $arcs = (array) ($cfg['arcs'] ?? []);
        $step = is_string($diType) && is_numeric($arcs[$diType] ?? null) ? floatval($arcs[$diType]) : 0.0;
        if ($step <= 0.0) return null;
        if ($diType === 'redemption' && $before === 'toxic') {
            // fearful -> anxious (the April table): per-axis share of the step
            $share = (array) ($arcs['redemption_from_fearful'] ?? []);
            $delta = [];
            foreach (self::ATTACHMENT_AXES as $axis) $delta[$axis] = -$step * floatval($share[$axis] ?? 1.0);
        } elseif ($diType === 'redemption') {
            $delta = ['anxiety' => -$step, 'avoidance' => -$step];
        } elseif ($before === 'secure') {
            $selfConf = floatval($dynamics['dimensions']['self_confidence']['x'] ?? 50);
            $delta = $selfConf > 50 ? ['avoidance' => $step] : ['anxiety' => $step];
        } else {
            $delta = ['avoidance' => $step];   // anxious -> fearful, avoidant stays, fearful stays
        }
        $gamets = floatval($dynamics['_last_gamets'] ?? 0);
        self::moveAttachment($dynamics, $delta, $gamets, "arc:{$diType}", $cfg, false);
        $after = self::getAttachmentStyle($dynamics);
        if ($after === $before) return null;
        self::log("[ATTACHMENT] Shift: {$before} -> {$after} via {$diType}");
        return $after;
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
        return RelDynTraits::labelParam($temperament, self::TEMPERAMENT_SENSITIVITY_CURVES, 'open_heart', is_array($dynamics) ? $dynamics : null);   // A22 (nearest preset)
    }

    /**
     * The bond level the curves read: the NPC's core affinity toward the player, clamped to
     * 0..100 (core points; the dimension draft's worked numbers, stranger 5 / acquaintance 30 /
     * friend 60 / bonded 85, are core affinity tiers). A hostile bond is 0. Not the 0..100
     * mirror x, where a core-0 stranger would read 50.
     */
    public static function socialSensitivityBondLevel(array $dynamics): float
    {
        return max(0.0, min(100.0, self::getCoreAffinity($dynamics)));
    }

    /**
     * The bond level the affinity cascade reads for its target (propagateAffinityChange): the
     * target's affinity toward the player on the mirror scale, (core + 100) / 2 in 0..100 (50 =
     * a stranger), as before the eval path moved to core affinity (socialSensitivityBondLevel):
     * word reaching a target who has never met the player still counts for something.
     */
    public static function cascadeBondLevel(array $targetDynamics): float
    {
        return max(0.0, min(100.0, (self::getCoreAffinity($targetDynamics) + 100.0) / 2.0));
    }

    /** A cascade delta (core affinity points) through the target's sensitivity curve at cascadeBondLevel. */
    public static function cascadeSocialSensitivity(array $targetDynamics, float $delta, ?string $temperament): float
    {
        return $delta * self::socialSensitivityFactor($targetDynamics, 'affinity', $delta < 0, $temperament, self::cascadeBondLevel($targetDynamics));
    }

    /**
     * Social sensitivity multiplier (0..1) of a delta on $dimensionId from the player: the
     * NPC's curve (per-NPC override; else A22 through the trait engine at the NPC's vector;
     * else the temperament's curve) at the bond level ($bondLevel, core affinity 0..100; null =
     * socialSensitivityBondLevel now). 1.0 for the global dimensions (self-evaluative) and when
     * social_sensitivity_enabled is off.
     */
    public static function socialSensitivityFactor(array $dynamics, string $dimensionId, bool $isNegative, ?string $temperament = null, ?float $bondLevel = null): float
    {
        if (!self::configValue('social_sensitivity_enabled')) {
            return 1.0;
        }
        if (in_array($dimensionId, self::GLOBAL_DIMENSIONS, true)) {
            return 1.0;
        }
        $temperament = $temperament ?? ($dynamics['inferred_temperament'] ?? null);
        $bondLevel = $bondLevel === null ? self::socialSensitivityBondLevel($dynamics) : max(0.0, min(100.0, $bondLevel));

        // A22 through the trait engine: the preset curves (with the Proud / Jealous exceptions)
        // read pointwise at the NPC's vector; a per-NPC curve override or a non-preset label
        // keeps the curve path below.
        $vector = empty($dynamics['social_sensitivity_curve']) ? RelDynTraits::vectorFor($temperament, $dynamics) : null;
        if ($vector !== null) {
            return RelDynTraits::sensitivityAt($vector, $dimensionId, $bondLevel, $isNegative);
        }

        // The Proud (respect) and Jealous (passion, comfort) open_heart exceptions are preset
        // curves now (RelDynTraits::presetCurve); a per-NPC override curve never had them.
        $curve = self::getSocialSensitivityCurve($temperament, $dynamics);
        return self::calculateSocialSensitivity($curve, $bondLevel, $isNegative);
    }

    /**
     * Apply social sensitivity to a raw delta (socialSensitivityFactor).
     *
     * @param array  $dynamics     NPC dynamics (core affinity toward the player = bond level)
     * @param string $dimensionId  Which dimension is being modified
     * @param float  $rawDelta     Raw delta before sensitivity
     * @param string $temperament  NPC temperament
     * @return float Modified delta
     */
    public static function applySocialSensitivity($dynamics, $dimensionId, $rawDelta, $temperament)
    {
        return $rawDelta * self::socialSensitivityFactor((array) $dynamics, (string) $dimensionId, $rawDelta < 0,
            $temperament === null ? null : (string) $temperament);
    }

    // ========== END SOCIAL SENSITIVITY CURVES (PR 5) ==========

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
     * Apply text intensity post-processing to a band keyword string (feedback_intensity_formatting):
     * maturity degradation -> arousal / passion emphasis -> numb flattening, bounded and
     * deterministic (RelDynFelt::intensify; tunables in config felt_steering.intensity).
     *
     * @param string $keywords  Base band keyword string (from DIMENSION_BANDS)
     * @param array  $dynamics  Full dynamics blob (reads dimensions arousal / valence / maturity, passion)
     * @return string  Transformed keyword string
     */
    public static function applyTextIntensity($keywords, $dynamics)
    {
        if (empty($keywords) || !is_array($dynamics)) {
            return $keywords;
        }
        return RelDynFelt::intensify((string) $keywords, $dynamics);
    }

    /**
     * Degrade text for a maturity value (0..100 points): RelDynFelt::degrade at the maturity's
     * degradation level (bounded, deterministic).
     */
    public static function degradeText($text, $maturityLevel)
    {
        return RelDynFelt::degrade((string) $text, RelDynFelt::degradationLevel(floatval($maturityLevel)));
    }

    /**
     * Emphasis for arousal / passion (0..100 points): RelDynFelt::emphasize at the larger one's
     * intensity level (a few CAPS words and '!', bounded).
     */
    public static function intensifyText($text, $arousalLevel, $passionLevel)
    {
        return RelDynFelt::emphasize((string) $text, RelDynFelt::intensityLevel(floatval($arousalLevel), floatval($passionLevel)));
    }

    /** Numb / hollow flattening: RelDynFelt::flatten. */
    public static function dampenText($text)
    {
        return RelDynFelt::flatten((string) $text);
    }

    // ========== END TEXT INTENSITY ENGINE ==========


    // ========== PHYSICAL STATE BRIDGES (PR 8) ==========
    //
    // Read real signals from CHIM core data and apply temporary dimension
    // modifiers.  These are session-scoped: they apply while the physical
    // condition is active and are reversed when it clears. No MinAI reads.
    // ====================================================

    /** A23: temperaments whose trust answers 'injured' (healer gate) / respect answers 'bloody' (warrior gate). */
    const PHYSICAL_HEALER_TEMPERAMENTS  = ['Nurturing', 'Gentle', 'Anxious'];
    const PHYSICAL_WARRIOR_TEMPERAMENTS = ['Bold', 'Defiant', 'Proud'];

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
     *   injured  the player's live health (the plugin's gamedata.php 'stats' report, core_player.stats,
     *            RelDynCombat::playerHealth) under physical_states.injured_health_ratio of max
     *            (April's MinAI vitals rule, 0.3), indoors or out; no report = unknown, not injured.
     * Weather states come from the core place (RelDynFacets::currentPlaceContext: the weather
     * the plugin reports and whether the player is inside) and apply only outside:
     *   rain -> raining; snow -> snowing + cold; night with known clear/pleasant weather ->
     *   clear_night.
     * Not detected (no CHIM 3.4.1 core source): hunger, dirty / bloody (April: Dirt and Blood),
     * warm_fire; exhausted / well_rested were April's player-stamina proxy (stamina is a combat
     * resource that refills in seconds, not rest), left unknown pending a rest signal.
     *
     * @param string $npcName    The NPC being spoken to
     * @param string $playerName The player character name (the injured read is the player's)
     * @return string[] Active state names, e.g. ['snowing', 'cold']
     */
    public static function detectPhysicalStates($npcName, $playerName)
    {
        $states = [];
        $cfg = (array) (self::configValue('physical_states') ?? []) + self::defaultConfig()['physical_states'];
        $hp = RelDynCombat::playerHealth();
        if ($hp !== null && $hp < floatval($cfg['injured_health_ratio'])) {
            $states[] = 'injured';
        }

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

        // Temperament-gated pseudo-dimension remapping (A23, membership through the trait engine)
        $healerTemperaments  = self::PHYSICAL_HEALER_TEMPERAMENTS;
        $warriorTemperaments = self::PHYSICAL_WARRIOR_TEMPERAMENTS;

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
                    if ($temperament && RelDynTraits::membership($temperament, $healerTemperaments, $dynamics) >= 0.5) {
                        $dimId = 'trust';
                    } else {
                        continue; // Skip -- temperament does not qualify
                    }
                }
                if ($dimId === 'respect_warrior') {
                    if ($temperament && RelDynTraits::membership($temperament, $warriorTemperaments, $dynamics) >= 0.5) {
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
     * Take back temporary dimension effects exactly: x minus what was applied (dim => actual
     * delta), clamped to the dimension's range. Not a new experience through applyDelta's
     * physics (rubber band, resistance), which would leave a residue on every on/off cycle.
     */
    public static function reverseAppliedDeltas(array &$dynamics, array $applied, string $logTag, string $what): void
    {
        foreach ($applied as $dim => $val) {
            $def = self::getDimensionDefinition($dim);
            if (abs(floatval($val)) <= 0.0001 || !$def || !isset($dynamics['dimensions'][$dim])) continue;
            $x = floatval($dynamics['dimensions'][$dim]['x'] ?? 0) - floatval($val);
            $dynamics['dimensions'][$dim]['x'] = max((float) $def['range_min'], min((float) $def['range_max'], $x));
            if ($logTag !== '') {
                error_log("[{$logTag}] Cleared {$what}: {$dim} " . (-floatval($val) >= 0 ? '+' : '') . round(-floatval($val), 2));
            }
        }
    }

    /**
     * Clear (reverse) physical state modifiers when conditions are no longer active.
     *
     * Compares previously applied states ($dynamics['_active_physical_states'])
     * against the current active set.  Any state that was applied but is no
     * longer active gets exactly its applied deltas taken back (reverseAppliedDeltas).
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

            // Take back exactly what was applied for this state
            self::reverseAppliedDeltas($dynamics, (array) $appliedDeltas[$state], 'RelDyn-PHYS', "state {$state}");

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

        // Reverse previous environmental effects exactly (reverseAppliedDeltas); taken back, they
        // are no longer held (heldTemporaryOffset) while the new ones are applied.
        if (!empty($dynamics['_env_applied_effects']) && is_array($dynamics['_env_applied_effects'])) {
            self::reverseAppliedDeltas($dynamics, $dynamics['_env_applied_effects'], '', 'environment');
        }
        $dynamics['_env_applied_effects'] = [];

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

    /** Config 'item_modifiers' over its defaults (defaultConfig documents the keys). */
    public static function itemModifierConfig(): array
    {
        $stored = self::configValue('item_modifiers');
        return array_replace(self::ITEM_MODIFIER_DEFAULTS, is_array($stored) ? $stored : []);
    }

    const ITEM_MODIFIER_DEFAULTS = [
        // Dimensions of a consumable's spike and a worn item's baseline scaled by how the NPC
        // appraises the item (its facets x her signed preferences, decisions §6): the pleasure
        // of a drink she likes, the weight of a symbol she holds dear. A gain scales by m, a loss
        // by 1 / m, m = RelDynFacets::interestMultiplier(valence) (MDD 1.2 range 0.5..2.0); an
        // item without facets stays as the table has it.
        'appraised_dims' => ['comfort', 'warmth', 'passion', 'respect', 'trust'],
        // eventlog rows read per NPC per request (gifts and consumes since her watermark)
        'event_rows' => 10,
    ];

    /**
     * The NPC's appraisal of an item as a multiplier m (0.5..2.0; 1.0 without facets) and the
     * appraisal itself (null without facets).
     */
    public static function itemAppraisal(array $dynamics, string $npcName, string $itemName): array
    {
        $a = RelDynFacetClassifier::thingAppraisal(RelDynFacets::preferences($dynamics, $npcName), 'item', $itemName);
        return ['m' => $a === null ? 1.0 : RelDynFacets::interestMultiplier((float) $a['valence']), 'appraisal' => $a];
    }

    /** A table's effects through an appraisal multiplier (appraised_dims: gains x m, losses / m). */
    public static function appraisedEffects(array $effects, float $m): array
    {
        $dims = (array) self::itemModifierConfig()['appraised_dims'];
        $m = max(1e-6, $m);
        $out = [];
        foreach ($effects as $dim => $v) {
            $v = floatval($v);
            $out[$dim] = in_array($dim, $dims, true) ? ($v >= 0 ? $v * $m : $v / $m) : $v;
        }
        return $out;
    }

    /**
     * Process a consumable item being consumed by the NPC.
     *
     * 1. Classify the item (CONSUMABLE_EFFECTS)
     * 2. Her appraisal of it (facets x preferences) scales the spike (appraisedEffects); the
     *    experience feeds her weather and needs like any other thing (RelDynFacets::experienceThing)
     * 3. The spike is a held temporary offset until it wears off (game-calendar hours on the
     *    eventlog clock): tickConsumableExpiry takes back exactly what was applied
     * 4. Permanent costs go to the dimension baselines (never reversed)
     *
     * Gated behind dimension_engine_enabled config toggle.
     *
     * @param array       &$dynamics    NPC dynamics blob (modified in place)
     * @param string      $itemName     Consumed item display name
     * @param string|null $temperament  NPC temperament name
     * @param string      $npcName      the NPC (her preferences; '' = neutral)
     * @return array  Map of dimension => actual_delta applied, empty if unrecognised
     */
    public static function processConsumable(&$dynamics, $itemName, $temperament = null, string $npcName = '')
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
        $now = self::currentGamets();
        $appraisal = self::itemAppraisal($dynamics, $npcName, (string) $itemName);
        if ($appraisal['appraisal'] !== null && $npcName !== '') {
            // her own drink is shared with the player pair only on a turn of that pair (rulings §11)
            RelDynFacets::experienceThing($npcName, $dynamics, 'item', (string) $itemName,
                RelDynFacets::preferences($dynamics, $npcName), $now, $appraisal['appraisal']['facets'],
                self::isPairInteraction($GLOBALS['gameRequest'] ?? null, (string) ($GLOBALS['PLAYER_NAME'] ?? 'Player')) ? RelDynFulfillment::PLAYER : null);
        }

        // --- Apply immediate effects through XYZ engine ---
        $appliedImmediate = [];
        foreach (self::appraisedEffects((array) ($entry['immediate'] ?? []), $appraisal['m']) as $dimId => $delta) {
            $actual = self::applyDelta($dimId, $dynamics, floatval($delta), $temperament);
            if (abs($actual) > 0.001) {
                $appliedImmediate[$dimId] = $actual;
                $results[$dimId] = $actual;
            }
        }

        // --- Track for expiry (game-calendar hours on the eventlog clock) ---
        $durationGamets = intval(($entry['duration_game_hours'] ?? 0.5) * self::GAMETS_PER_HOUR);

        if (!isset($dynamics['_active_consumables']) || !is_array($dynamics['_active_consumables'])) {
            $dynamics['_active_consumables'] = [];
        }

        $dynamics['_active_consumables'][] = [
            'key'       => $key,
            'item_name' => $itemName,
            'immediate' => $appliedImmediate,
            'expires_gamets' => $now > 0 ? $now + $durationGamets : 0,
            'applied_gamets' => $now,
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
        error_log("[RelDyn-ITEM] Consumed {$key} ({$itemName}) x" . round($appraisal['m'], 2) . ": " . implode(', ', $effectStr)
            . (!empty($permStr) ? " | permanent: " . implode(', ', $permStr) : '')
            . " | expires in " . round($entry['duration_game_hours'] ?? 0.5, 1) . "h game time");

        return $results;
    }

    /**
     * Process an equip or unequip event of the NPC's own gear (core_npc_master.metadata.equipment).
     *
     * On equip:   the while_equipped baseline modifiers (through her appraisal of the item,
     *             appraisedEffects), tracked in _equipped_modifiers: a held offset on the
     *             baseline (heldBaselineOffset; baseline drift runs without it).
     * On unequip: those modifiers taken back exactly; the on_removal spike unless $initial.
     *
     * Gated behind dimension_engine_enabled config toggle.
     *
     * @param array       &$dynamics    NPC dynamics blob (modified in place)
     * @param string      $itemName     Equipped/unequipped item name
     * @param bool        $equipped     true = equip event, false = unequip event
     * @param string|null $temperament  NPC temperament name
     * @param string      $npcName      the NPC (her preferences; '' = neutral)
     * @return array  Map of dimension => actual_delta applied
     */
    public static function processEquipChange(&$dynamics, $itemName, $equipped, $temperament = null, string $npcName = '')
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
            if (isset($dynamics['_equipped_modifiers'][$key])) {
                return [];   // already held (a second ring of the same kind adds nothing)
            }
            // --- EQUIP: apply baseline modifiers, as she values the item ---
            $m = self::itemAppraisal($dynamics, $npcName, (string) $itemName)['m'];
            $applied = [];
            foreach (self::appraisedEffects((array) ($entry['while_equipped'] ?? []), $m) as $dimId => $delta) {
                if (!isset($dynamics['dimensions'][$dimId])) {
                    $dynamics['dimensions'][$dimId] = [];
                }
                $currentBaseline = floatval($dynamics['dimensions'][$dimId]['baseline'] ?? 50.0);
                $dynamics['dimensions'][$dimId]['baseline'] = $currentBaseline + floatval($delta);
                $applied[$dimId] = round(floatval($delta), 4);

                $sign = $delta >= 0 ? '+' : '';
                $results[$dimId] = round(floatval($delta), 4);
                error_log("[RelDyn-ITEM] Equip {$key} ({$itemName}) x" . round($m, 2) . ": {$dimId}_baseline {$sign}" . round($delta, 2));
            }

            $dynamics['_equipped_modifiers'][$key] = [
                'item_name' => $itemName,
                'applied'   => $applied,
                'equipped_gamets' => self::currentGamets(),
            ];
        } else {
            // --- UNEQUIP: reverse baseline modifiers ---
            $tracked = $dynamics['_equipped_modifiers'][$key] ?? null;
            if (!$tracked) {
                return [];   // never held: nothing to take back, no removal to feel
            }
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

    /** The worn gear's standing offset on $dim's baseline (points; processEquipChange). */
    public static function equippedBaselineOffset(array $dynamics, string $dim): float
    {
        $held = 0.0;
        foreach ((array) ($dynamics['_equipped_modifiers'] ?? []) as $e) {
            if (is_array($e)) $held += floatval($e['applied'][$dim] ?? 0.0);
        }
        return $held;
    }

    /**
     * Standing offsets held on $dim's stored baseline that are states, not who she is:
     * resentment_self's (RelDynResentment::baselineOffset) and her worn gear's. Baseline drift
     * runs on the baseline without them.
     */
    public static function heldBaselineOffset(array $dynamics, string $dim): float
    {
        return RelDynResentment::baselineOffset($dynamics, $dim) + self::equippedBaselineOffset($dynamics, $dim);
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
        // A gift that touches one of her intrinsic goals moves it (MDD 14.2)
        if (is_array($gift['appraisal'])) {
            RelDynGoals::onExperience((string) ($npcName ?? ''), $dynamics, 'item', (string) $itemName, (array) ($gift['appraisal']['facets'] ?? []), self::currentGamets());
        }

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
     * Tick consumable expiry: take back exactly the spike of every consumable that wore off
     * (held temporary offsets, reverseAppliedDeltas), on the eventlog game clock.
     *
     * Called from prerequest.php every interaction.
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

        // Can't check expiry without game time
        $currentGameTs = self::currentGamets();
        if ($currentGameTs <= 0) {
            return 0;
        }

        $remaining = [];
        $expiredCount = 0;

        foreach ($activeConsumables as $consumable) {
            $expiresAt = floatval($consumable['expires_gamets'] ?? 0);

            if ($expiresAt > 0 && $currentGameTs >= $expiresAt) {
                // --- EXPIRED: the spike is taken back exactly ---
                self::reverseAppliedDeltas($dynamics, (array) ($consumable['immediate'] ?? []), 'RelDyn-ITEM', "{$consumable['key']} wore off");
                $expiredCount++;
                error_log("[RelDyn-ITEM] Consumable expired: {$consumable['key']} ({$consumable['item_name']})");
            } elseif ($expiresAt <= 0) {
                // consumed with no game clock: it wears off from the first known time
                $consumable['expires_gamets'] = $currentGameTs + intval((self::CONSUMABLE_EFFECTS[$consumable['key'] ?? '']['duration_game_hours'] ?? 0.5) * self::GAMETS_PER_HOUR);
                $remaining[] = $consumable;
            } else {
                $remaining[] = $consumable;
            }
        }

        $dynamics['_active_consumables'] = $remaining;
        return $expiredCount;
    }

    /**
     * Detect item events for this NPC from core's eventlog (the plugin's own lines):
     *   - gift:    itemfound "<player> gave <n> <item> to <NPC>,(value <v> gold)"
     *              (Plugin.cpp TESContainerChangedEvent, player -> AI agent)
     *   - consume: infoaction "<NPC> consumes <item>." (the Consume action, Commands.cpp), and
     *              itemfound "<NPC> drank / ate / consumed <item>" (her line only: the player
     *              drinking is not her drinking)
     * Rows after her watermarks (processItemEvents keeps one per stream: $afterRowid for gifts,
     * $consumeAfterRowid for her consumables, default $afterRowid) each count once, the oldest
     * first, at most event_rows per stream per request (the rest the next request); without
     * one, the newest of the last ITEM_EVENT_WINDOW_GAMETS of the eventlog game clock.
     * $scanned: the last rowid each stream read (null when it read none).
     *
     * @param array    $gameRequest  The current CHIM game request array (the game clock)
     * @param string   $npcName      NPC being spoken to
     * @param string   $playerName   Player character name
     * @param int|null $afterRowid   her gift watermark (eventlog.rowid) or null
     * @param int|null $consumeAfterRowid her consumable watermark, or null for $afterRowid
     * @param array|null $scanned    out: ['gift' => ?int, 'consume' => ?int]
     * @return array  List of detected item events ['action', 'item', ..., 'rowid']
     */
    public static function detectItemEvents($gameRequest, $npcName, $playerName, ?int $afterRowid = null, ?int $consumeAfterRowid = null, ?array &$scanned = null)
    {
        $events = [];
        $scanned = ['gift' => null, 'consume' => null];
        $db = $GLOBALS['db'] ?? null;
        $nowGamets = self::currentGamets();
        $consumeAfterRowid = $consumeAfterRowid ?? $afterRowid;
        if (!$db || ($nowGamets <= 0 && $afterRowid === null)) {
            return [];
        }
        // Past a watermark: the oldest rows after it first, so a long handover or a busy log is
        // read over several requests, never skipped; the first look: the newest in the window
        $window = 'gamets > ' . intval($nowGamets - self::ITEM_EVENT_WINDOW_GAMETS);
        $since = $afterRowid !== null ? 'rowid > ' . intval($afterRowid) : $window;
        $consumeSince = $consumeAfterRowid !== null ? 'rowid > ' . intval($consumeAfterRowid) : $window;
        $order = $afterRowid !== null ? 'ORDER BY rowid ASC' : 'ORDER BY gamets DESC, ts DESC';
        $consumeOrder = $consumeAfterRowid !== null ? 'ORDER BY rowid ASC' : 'ORDER BY gamets DESC, ts DESC';
        $limit = max(1, intval(self::itemModifierConfig()['event_rows']));

        // --- Gifts: the player's handover to this NPC ---
        try {
            // NPC name matched literally: its % and _ are escaped, not LIKE wildcards
            $escapedNpc = $db->escape(self::escapeLike($npcName));
            $rows = $db->fetchAll(
                "SELECT rowid, data FROM eventlog WHERE type='itemfound' "
                . "AND data LIKE '%gave%to%{$escapedNpc}%' ESCAPE '\\' "
                . "AND {$since} "
                . "{$order} LIMIT {$limit}"
            );
            foreach ((array) $rows as $row) {
                if (isset($row['rowid'])) $scanned['gift'] = max(intval($scanned['gift'] ?? 0), intval($row['rowid']));
                if (!preg_match('/^\s*(.+?)\s+gave\s+(?:(\d+)\s+)?(.+?)\s+to\s+(.+?)\s*(?:,\s*\(value\s+(\d+)\s+gold\))?\s*$/i', (string) ($row['data'] ?? ''), $gm)) continue;
                if (strcasecmp(trim($gm[1]), trim((string) $playerName)) !== 0 || strcasecmp(trim($gm[4]), trim((string) $npcName)) !== 0) continue;
                $events[] = [
                    'action' => 'gift',
                    'item'   => trim($gm[3]),
                    'giver'  => $playerName,
                    'value'  => isset($gm[5]) && $gm[5] !== '' ? intval($gm[5]) : null,
                    'rowid'  => isset($row['rowid']) ? intval($row['rowid']) : null,
                ];
            }
        } catch (\Throwable $e) {
            self::logError('detectItemEvents gift lookup', $e);
        }

        // (Drunk / on-skooma states were MinAI flags; CHIM 3.4.1 core has none.)

        // --- Consumables: what this NPC ate, drank or used ---
        try {
            // Whole words only (PostgreSQL \m \M word boundaries): 'private chest' is not 'ate'.
            // Her own lines only (the row starts with her name), so other people's meals never
            // crowd hers out of the rows read.
            $ownLine = $db->escape(self::escapeLike(trim((string) $npcName)));
            $rows = $db->fetchAll(
                "SELECT rowid, data FROM eventlog WHERE type IN ('infoaction', 'itemfound') "
                . "AND ltrim(data) ILIKE '{$ownLine} %' ESCAPE '\\' "
                . "AND (data ~* '\\m(consumes|consumed|drank|ate)\\M' OR data ~* '\\mused\\M.*potion') "
                . "AND {$consumeSince} "
                . "{$consumeOrder} LIMIT {$limit}"
            );
            foreach ((array) $rows as $row) {
                if (isset($row['rowid'])) $scanned['consume'] = max(intval($scanned['consume'] ?? 0), intval($row['rowid']));
                $data = (string) ($row['data'] ?? '');
                if (!preg_match('/^\s*(.+?)\s+(?:consumes|consumed|drank|ate|used)\s+(?:\d+\s+)?(.+?)\s*\.?\s*(?:,.*)?$/i', $data, $cm)) continue;
                if (strcasecmp(trim($cm[1]), trim((string) $npcName)) !== 0) continue;
                $events[] = [
                    'action' => 'consume',
                    'item'   => trim($cm[2], " .\t"),
                    'source' => 'eventlog',
                    'rowid'  => isset($row['rowid']) ? intval($row['rowid']) : null,
                ];
            }
        } catch (\Throwable $e) {
            self::logError('detectItemEvents consume lookup', $e);
        }

        return $events;
    }

    /**
     * Equip / unequip events of the NPC's own gear: core's metadata.equipment (gamedata.php
     * 'equipment', slot => item name) against what RelDyn saw last (_equipment_seen). The first
     * look is 'initial' (what she already wears: its baseline, no removal to feel).
     */
    public static function detectEquipChanges(string $npcName, array &$dynamics): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($npcName) === '') return [];
        try {
            $row = $db->fetchOne('SELECT metadata FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1', [$npcName]);
        } catch (\Throwable $e) {
            self::logError('detectEquipChanges equipment read', $e);
            return [];
        }
        $meta = is_array($row) && isset($row['metadata']) ? json_decode((string) $row['metadata'], true) : null;
        $equipment = is_array($meta['equipment'] ?? null) ? $meta['equipment'] : null;
        if ($equipment === null) return [];   // core has not reported her gear
        $worn = [];
        foreach ($equipment as $slot => $name) {
            if (!is_string($name) || trim($name) === '' || preg_match('/_(baseid|keywords)$/', (string) $slot)) continue;
            $worn[strtolower(trim($name))] = trim($name);
        }
        $initial = !is_array($dynamics['_equipment_seen'] ?? null);
        $seen = $initial ? [] : $dynamics['_equipment_seen'];
        $events = [];
        foreach ($worn as $k => $name) {
            if (!isset($seen[$k])) $events[] = ['action' => 'equip', 'item' => $name, 'initial' => $initial];
        }
        foreach ($seen as $k => $name) {
            if (!isset($worn[$k])) $events[] = ['action' => 'unequip', 'item' => (string) $name];
        }
        $dynamics['_equipment_seen'] = $worn;
        return $events;
    }

    /**
     * Process all detected item events for this interaction.
     *
     * Orchestrator that calls processConsumable, processEquipChange, and
     * processGift based on detected events; keeps her eventlog watermark (_item_event_rowid)
     * so a row counts once.
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

        // Her eventlog watermarks, one per stream (gifts to her, her own consumables): each moves to
        // the last row it read, so rows past a full read wait for the next request
        $mark = is_numeric($dynamics['_item_event_rowid'] ?? null) ? intval($dynamics['_item_event_rowid']) : null;
        $consumeMark = is_numeric($dynamics['_item_consume_rowid'] ?? null) ? intval($dynamics['_item_consume_rowid']) : $mark;
        $scanned = [];
        $events = self::detectItemEvents($gameRequest, $npcName, $playerName, $mark, $consumeMark, $scanned);
        $top = $mark ?? 0;
        foreach ($events as $event) {
            if (isset($event['rowid'])) $top = max($top, intval($event['rowid']));
        }
        if ($mark !== null) {
            $dynamics['_item_event_rowid'] = max($mark, intval($scanned['gift'] ?? 0));
            $dynamics['_item_consume_rowid'] = max(intval($consumeMark), intval($scanned['consume'] ?? 0));
        } elseif ($top > 0) {
            $dynamics['_item_event_rowid'] = $dynamics['_item_consume_rowid'] = $top;   // first look: past what it saw
        } else {
            // First look: from here on, rows after the newest one count
            $db = $GLOBALS['db'] ?? null;
            try {
                $newest = $db ? $db->fetchOne('SELECT rowid FROM eventlog ORDER BY rowid DESC LIMIT 1') : [];
                if (isset($newest['rowid'])) $dynamics['_item_event_rowid'] = $dynamics['_item_consume_rowid'] = intval($newest['rowid']);
            } catch (\Throwable $e) {
                self::logError('processItemEvents watermark', $e);
            }
        }
        $events = array_merge($events, self::detectEquipChanges((string) $npcName, $dynamics));
        if (empty($events)) {
            return [];
        }

        $allResults = ['consumable' => [], 'gift' => [], 'equip' => []];

        foreach ($events as $event) {
            switch ($event['action']) {
                case 'consume':
                    $results = self::processConsumable($dynamics, $event['item'], $temperament, (string) $npcName);
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
                    $results = self::processEquipChange($dynamics, $event['item'], true, $temperament, (string) $npcName);
                    if (!empty($results)) {
                        $allResults['equip'][] = ['item' => $event['item'], 'action' => 'equip', 'deltas' => $results];
                    }
                    break;

                case 'unequip':
                    $results = self::processEquipChange($dynamics, $event['item'], false, $temperament, (string) $npcName);
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
     * The reason is stored cleaned (RelDynFelt::sanitizeReason: the event, never its scores or
     * named feelings), so every reader (context, confrontation, diary) gets text fit for the
     * LLM; a reason with no event left is not stored. Stamped with the exchange's game time.
     *
     * @param array  &$dynamics   NPC dynamics blob (by reference)
     * @param string $dimensionId Dimension ID (e.g. 'trust', 'comfort')
     * @param float  $delta       The actual delta applied (signed)
     * @param string $reason      Human-readable reason string from the eval
     * @param string|null $bondName    Bond target name (default: from GLOBALS)
     * @param float|null  $gamets      Raw game time of the exchange (default: the current game clock)
     */
    public static function storeDimensionalMemory(&$dynamics, $dimensionId, $delta, $reason, $bondName = null, ?float $gamets = null)
    {
        if ($bondName === null) {
            $bondName = trim($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player');
        }
        if (empty($reason) || !is_string($reason) || abs($delta) < 0.0001) {
            return;
        }
        $reason = RelDynFelt::sanitizeReason($reason);
        if ($reason === null) {
            return;
        }

        if (!isset($dynamics['dimensional_memory']) || !is_array($dynamics['dimensional_memory'])) {
            $dynamics['dimensional_memory'] = [];
        }

        // Cap reason length to prevent blob bloat
        if (strlen($reason) > 200) {
            $reason = substr($reason, 0, 197) . '...';
        }

        $entry = [
            'dim'       => $dimensionId,
            'delta'     => round($delta, 2),
            'reason'    => $reason,
            'bond'      => $bondName,
            'gamets'    => $gamets ?? self::currentGamets(),   // raw game time
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
            // Sort by abs_delta descending; ties broken by game time descending (newest first)
            usort($grouped, function ($a, $b) {
                $cmp = ($b['abs_delta'] ?? 0) <=> ($a['abs_delta'] ?? 0);
                if ($cmp !== 0) return $cmp;
                return floatval($b['gamets'] ?? 0) <=> floatval($a['gamets'] ?? 0);
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
            return floatval($b['gamets'] ?? 0) <=> floatval($a['gamets'] ?? 0);
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

        // Sort by abs_delta descending (biggest grievances first), newest first on a tie
        usort($candidates, function ($a, $b) {
            $cmp = ($b['abs_delta'] ?? 0) <=> ($a['abs_delta'] ?? 0);
            return $cmp !== 0 ? $cmp : (floatval($b['gamets'] ?? 0) <=> floatval($a['gamets'] ?? 0));
        });

        // Top 5 distinct events (one exchange moves several dimensions under the same reason),
        // cleaned: only the event reaches the LLM (an entry stored before cleaning is cleaned here)
        $fuel = [];
        foreach ($candidates as $mem) {
            $r = RelDynFelt::sanitizeReason((string) ($mem['reason'] ?? ''));
            if ($r === null || isset($fuel[strtolower($r)])) {
                continue;
            }
            $fuel[strtolower($r)] = $r;
            if (count($fuel) >= 5) {
                break;
            }
        }
        return array_values($fuel);
    }

    /**
     * The felt memory line for the context (RelDynFelt): the strongest moments of this bond and
     * the last eval reasons, each as what happened and whether it still warms or stings.
     * Context tier >= 2 only -- strangers and acquaintances don't get memory context.
     *
     * @param array  $dynamics NPC dynamics blob
     * @param string $npcName  NPC display name
     * @param string $bondName Bond target name
     * @param int    $limit    Max memories to include
     * @param array  $exclude  reasons already in front of the LLM (the grievances line), left out
     * @return string|null  One line of prose, or null if nothing to say
     */
    public static function buildMemoryContext($dynamics, $npcName, $bondName, $limit = 5, array $exclude = [])
    {
        // Gate: tier >= 2 required (don't share memories with strangers)
        if (self::getContextTier($dynamics) < 2) {
            return null;
        }
        $cfg = RelDynFelt::config();
        $t = (array) $cfg['text'];
        $maxChars = max(20, intval($cfg['reason_max_chars']));

        // The strongest moments of this bond, then the last eval reasons (last_reason per
        // dimension); the reason and whether it still warms or stings, never the dimension, the
        // delta or a timestamp (felt steering, decisions 2026-09-23 §3). Reasons are the eval
        // LLM's free text: RelDynFelt::sanitizeReason keeps the event, drops scores and feelings.
        $items = [];
        $skip = array_flip(array_map(fn($r) => strtolower((string) $r), $exclude));
        $push = function (string $reason, float $delta) use (&$items, $t, $maxChars, $skip) {
            // The eval's free-text summary: what happened, never its scores or named feelings
            $reason = RelDynFelt::sanitizeReason($reason);
            if ($reason === null || isset($skip[strtolower($reason)])) return;
            if (strlen($reason) > $maxChars) {
                $cut = substr($reason, 0, $maxChars);
                $reason = rtrim(substr($cut, 0, (int) (strrpos($cut, ' ') ?: $maxChars)), ' ,;.') . '...';
            }
            $key = strtolower($reason);
            if (isset($items[$key])) return;
            $items[$key] = "'{$reason}' (" . ($delta < 0 ? $t['memory_sting'] : $t['memory_warm']) . ')';
        };
        foreach (self::getDimensionalMemories($dynamics, null, $bondName, $limit) as $mem) {
            $push((string) ($mem['reason'] ?? ''), floatval($mem['delta'] ?? 0));
        }
        foreach ((array) ($dynamics['dimensions'] ?? []) as $dim) {
            if (is_array($dim) && is_string($dim['last_reason'] ?? null)) {
                $push($dim['last_reason'], floatval($dim['last_delta'] ?? 0));
            }
        }
        if ($items === []) {
            return null;
        }
        return strtr((string) $t['memory'], ['{ITEMS}' => implode('; ', array_slice(array_values($items), 0, max(1, intval($limit)))),
            '{NAME}' => $npcName, '{PLAYER}' => $bondName]);
    }

    // ========== END DIMENSIONAL MEMORY (PR 9) ==========

    // ========== REPUTATION LAYER METHODS (PR 9) ==========

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
                    $netBonus += self::FACTION_RANK_RESPECT;
                    $matchFound = true;
                    break; // One match per faction group is enough
                }
            }

            foreach ($rules['disdains'] as $playerFaction) {
                if ($in($playerFaction, $playerFactions)) {
                    $netBonus -= self::FACTION_RANK_RESPECT * 0.5;
                    break;
                }
            }

            if ($matchFound) break; // One NPC faction match is sufficient
        }

        return $netBonus;
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

    // The snapshots, the trajectory comparison and the reflection's verdict live in RelDynDiary
    // (reldyn_diary.php): the reflection rides on core's diary entries.

    // Intrinsic goals, incl. self-worth 'I need to change': RelDynGoals (reldyn_goals.php).

    // ========== END DIARY SELF-EVAL (PR 9) ==========

    // ========== DIRECTOR-ASSIGNED GOALS (PR 39) ==========

    /** config 'director_goals' laid over its defaults (a stored row may lack a key). */
    public static function directorGoalConfig(): array
    {
        $defaults = self::defaultConfig()['director_goals'];
        $stored = self::configValue('director_goals');
        return array_replace($defaults, is_array($stored) ? $stored : []);
    }

    /** Max age of a goal from $source, in play gamets (config max_age_play_hours x GAMETS_PER_REAL_HOUR). */
    public static function directorGoalMaxAgePlayGamets(string $source, ?float $playHours = null): float
    {
        if ($playHours === null) {
            $ages = (array) self::directorGoalConfig()['max_age_play_hours'];
            $playHours = floatval($ages[$source] ?? $ages['director'] ?? 1.0);
        }
        return max(0.0, $playHours) * self::GAMETS_PER_REAL_HOUR;
    }

    /**
     * Identity of one goal (its text, source and play-clock start), which the eval carries back
     * as goal_ref so the consumer fulfils only the goal the eval was shown.
     */
    public static function directorGoalRef(array $goal): string
    {
        return substr(sha1(json_encode([trim((string) ($goal['text'] ?? '')), (string) ($goal['source'] ?? ''),
            round(floatval($goal['created_gamets'] ?? 0), 3)])), 0, 16);
    }

    /**
     * Set an active director-assigned goal for this NPC.
     * @param array &$dynamics          The dynamics blob
     * @param string $goalText          What the NPC should try to do
     * @param string $source            'director' | 'bgl' | 'intrinsic'
     * @param float|null $maxAgePlayHours Play hours on the NPC's play clock before it expires;
     *                                  null = config director_goals.max_age_play_hours[$source]
     * @param float $priority           0.0-1.0 urgency
     */
    public static function setDirectorGoal(&$dynamics, $goalText, $source = 'director', ?float $maxAgePlayHours = null, $priority = 0.5) {
        // Expire current goal if one exists
        if (!empty($dynamics['_director_goal']) && !empty($dynamics['_director_goal']['active'])) {
            self::expireDirectorGoal($dynamics);
        }

        $dynamics['_director_goal'] = [
            'text'                 => trim($goalText),
            'source'               => $source,
            'created_gamets'       => self::getPlayGamets($dynamics),   // play gamets
            'max_age_play_gamets'  => self::directorGoalMaxAgePlayGamets((string) $source, $maxAgePlayHours),
            'priority'             => max(0.0, min(1.0, floatval($priority))),
            'active'               => true,
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

        // Age on the play clock (play gamets). A goal stored before the play-hour ages (its
        // max_age_gamets was real seconds read as play gamets) takes its source's age.
        $currentGamets = self::getPlayGamets($dynamics);
        $goalAge = $currentGamets - floatval($goal['created_gamets'] ?? 0);
        $maxAge = is_numeric($goal['max_age_play_gamets'] ?? null) ? floatval($goal['max_age_play_gamets'])
            : self::directorGoalMaxAgePlayGamets((string) ($goal['source'] ?? 'director'));

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

    // ========== ATTRACTION MATRIX (decisions §9 contract, reldyn_attraction.php) ==========

    /**
     * Shared contract (attraction lane): the MDD §2 Attraction Matrix for $npcName on the
     * player profile (RelDynPlayer::profile() unless one is given). Pure.
     * @return array ['score' => 0..1, 'passes' => bool, 'friendzoned' => bool,
     *   'pillars' => [name => ['score','weight','rigidity','pass', ...]], 'ceiling_tier' => string|null,
     *   'reason' => string, ...extras (RelDynAttraction::evaluate)]
     */
    public static function attractionFor(string $npcName, array $dynamics, ?array $playerProfile = null): array
    {
        return RelDynAttraction::evaluate($npcName, $dynamics, $playerProfile ?? self::attractionPlayerProfile());
    }

    /** The player profile for the Matrix; not read at all while the Matrix is off (it gates nothing). */
    private static function attractionPlayerProfile(): array
    {
        return empty(self::getConfig()['attraction_matrix_enabled']) ? ['known' => false] : RelDynPlayer::profile();
    }

    /**
     * Evaluate and record the attraction for this request (tier-lift state, the _attraction
     * summary every consumer reads, the passion hard cap). prerequest runs it once per request.
     */
    public static function updateAttraction(string $npcName, array &$dynamics, ?array $playerProfile = null): array
    {
        return RelDynAttraction::update($npcName, $dynamics, $playerProfile ?? self::attractionPlayerProfile());
    }

    /**
     * The above-spark passion gain multiplier (decisions §13: curve x attachment [x prebond];
     * 0 for a hard zero), for display and logs. Gains use attractionPassionFactor, which also
     * knows the spark. Uses this request's summary; evaluates first when the NPC has none yet
     * (eval worker / calendar scan on a bond never seen by prerequest).
     */
    public static function attractionPassionMult(string $npcName, array &$dynamics): float
    {
        if (!is_array($dynamics['_attraction'] ?? null)) {
            self::updateAttraction($npcName, $dynamics);
        }
        $m = $dynamics['_attraction']['passion_mult'] ?? 1.0;
        return is_numeric($m) ? max(0.0, floatval($m)) : 1.0;
    }

    /**
     * The one attraction factor (unitless) every passion GAIN goes through (decisions §13):
     * the legacy path, the eval passion signal, gainPassion (reunion, combat, conflict repair),
     * the hoover snap and a place's floor. A gain of $raw passion points at the NPC's current
     * passion: the part below the spark at spark_mult (attachment; anyone), the rest at
     * passion_mult (the uphill), 0 for a hard zero (RelDynAttraction::gainFactor). Uses this
     * request's summary; evaluates first when the NPC has none yet. Logged per gain ($source).
     */
    public static function attractionPassionFactor(string $npcName, array &$dynamics, float $raw, string $source, ?array $tags = null): float
    {
        if (!is_array($dynamics['_attraction'] ?? null)) {
            self::updateAttraction($npcName, $dynamics);
        }
        return self::loggedPassionFactor($dynamics, $raw, "{$npcName}: {$source}", $tags, $source);
    }

    /**
     * RelDynAttraction::gainFactor on the stored summary at the current passion, then the tier's
     * governor (MDD 8, RelDynGovernors::gainFactor: the gain never lifts passion past the tier's
     * ceiling; $source in its exempt_sources is not bounded), logged ($label: who / which path;
     * $tags: the gain's eval tags, its channel, decisions §15).
     */
    private static function loggedPassionFactor(array $dynamics, float $raw, string $label, ?array $tags = null, ?string $source = null): float
    {
        $passion = self::getPassion($dynamics);
        $a = (array) ($dynamics['_attraction'] ?? []);
        $factor = $a === [] ? 1.0 : RelDynAttraction::gainFactor($a, $passion, $raw, $tags);
        $governed = false;
        if ($factor > 0.0 && $raw > 0.0) {
            $gov = RelDynGovernors::gainFactor($dynamics, $passion, $raw * $factor, $source);
            $governed = $gov < 1.0;
            $factor *= $gov;
        }
        self::log(sprintf('[ATTRACTION] %s passion +%.4f at %.2f x%.4f%s', $label, $raw, $passion, $factor,
            ($factor <= 0.0 && $raw > 0.0) ? ' (' . self::passionClosedReason($a, $tags, $dynamics) . ')'
                : ($governed ? ' (bounded by ' . self::governorReason($dynamics) . ')' : '')));
        return $factor;
    }

    /** Why a passion gain adds nothing (log / eval line): hard zero, a closed channel, the MDD 1.4 ceiling or the tier's governor. */
    private static function passionClosedReason(array $a, ?array $tags, array $dynamics = []): string
    {
        if (isset($a['hard_zero'])) return 'hard zero: ' . $a['hard_zero'];
        if ($a !== [] && !RelDynAttraction::channelOpen($a, $tags)) {
            return 'channel closed: ' . ($a['passion_channel'] ?? 'restricted') . ' passion only, tags ' . ($tags ? implode(',', $tags) : 'none');
        }
        if (is_numeric($a['passion_ceiling'] ?? null) && self::getPassion($dynamics) >= floatval($a['passion_ceiling'])) {
            return 'at the MDD 1.4 passion ceiling ' . $a['passion_ceiling'];
        }
        return 'at ' . self::governorReason($dynamics);
    }

    /** The tier governor for a log line: "the <tier> tier's passion ceiling <points>[ (raised)]". */
    private static function governorReason(array $dynamics): string
    {
        $g = RelDynGovernors::governor($dynamics);
        return $g === null ? 'no governor' : sprintf("the %s tier's passion ceiling %s%s", $g['tier'], $g['ceiling'], $g['raised'] ? ' (raised)' : '');
    }

    /**
     * A passion GAIN of $raw passion points from $source, through the attraction (decisions
     * §13: raw x attractionPassionFactor; a hard zero adds exactly 0), then addPassion (stage
     * ceiling). $tags: the gain's eval tags (its channel, decisions §15; null = no channel).
     * Returns the gain asked of addPassion (points).
     */
    public static function gainPassion(string $npcName, array &$dynamics, float $raw, string $source, ?array $tags = null): float
    {
        if ($raw <= 0.0) {
            return 0.0;
        }
        $gain = $raw * self::attractionPassionFactor($npcName, $dynamics, $raw, $source, $tags);
        if ($gain <= 0.0) {
            return 0.0;
        }
        self::addPassion($dynamics, $gain, $source);
        return $gain;
    }

    /**
     * Respect gain multiplier for the eval respect signal's gains: the plan §4 rate
     * (competence + status) / 2 as this NPC reads the player, against the Matrix's neutral
     * pillar score, within MDD 1.2's 0.5x..2.0x (RelDynAttraction::respectMult). 1.0 while config
     * attraction.respect_mult_enabled is off or the Matrix does not judge.
     */
    public static function attractionRespectMult(string $npcName, array &$dynamics): float
    {
        if (empty(RelDynAttraction::config()['respect_mult_enabled'])) {
            return 1.0;
        }
        if (!is_array($dynamics['_attraction'] ?? null)) {
            self::updateAttraction($npcName, $dynamics);
        }
        $m = $dynamics['_attraction']['respect_mult'] ?? 1.0;
        return is_numeric($m) ? max(0.0, floatval($m)) : 1.0;
    }

    /**
     * Can this bond hold core relationship type $coreType now (romance lane: promotion into
     * romantic types)? False for a romance type the attraction blocks or has not yet earned
     * through significant interactions. Non-romance types are not the Matrix's to block.
     */
    public static function attractionAllowsType(string $npcName, array &$dynamics, string $coreType): bool
    {
        if (!is_array($dynamics['_attraction'] ?? null)) {
            self::updateAttraction($npcName, $dynamics);
        }
        return !in_array(strtolower(trim($coreType)), (array) ($dynamics['_attraction']['blocked_types'] ?? []), true);
    }

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

    /** C4 (traits design §2.2): the attraction archetype a temperament falls back to. */
    const TEMPERAMENT_ARCHETYPE = [
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
            // Fallback: temperament-based (C4, the nearest preset's archetype)
            $archetype = RelDynTraits::labelParam($temperament, self::TEMPERAMENT_ARCHETYPE, 'Warrior', $dynamics);
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
     * Calculate effective tolerance from maturity x attachment.
     * Returns 0.0-1.0 where higher = more tolerant/flexible. Each style corner has its own
     * maturity curve (below); the NPC's tolerance is those curves blended at its attachment
     * axes (attachmentBlend), so a textbook NPC of a style reads that style's curve.
     */
    public static function calculateEffectiveTolerance($dynamics): float
    {
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $baseTolerance = $maturity / 100.0;
        $clamp = fn(float $t): float => min(1.0, max(0.0, $t));

        $byStyle = [
            'secure'   => $clamp($baseTolerance * 1.2),          // High maturity = more tolerant
            'avoidant' => $clamp(1.0 - ($baseTolerance * 0.8)),  // High maturity = less tolerant (refined standards)
            'anxious'  => $clamp(($maturity > 50) ? (1.0 - $baseTolerance * 0.5) : 0.9), // Low mat = desperate "tolerance"
            'toxic'    => 0.3,                                   // Pattern-locked
        ];
        return $clamp(self::attachmentBlend($dynamics, $byStyle, 0.5));
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

            // Apply social sensitivity curve (at the target's bond on the mirror scale)
            $cascadeDelta = self::cascadeSocialSensitivity($targetDynamics, $cascadeDelta, $targetTemperament);

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

    // Duty override (MDD 9): RelDynQuests::dutyState / onPrerequest (reldyn_quests.php).


    // ========== PARASITE DETECTION (PR 12; MDD 6.2, reldyn_protocols.php) ==========

    /**
     * The transactional pattern (MDD 6.2): of the exchanges in the rolling ledger
     * (RelDynProtocols::recordExchange; counts total_window / gift_count / genuine_count), at
     * least parasite.min_exchanges, gifts above this NPC's share (parasiteRatioThreshold) with
     * fewer than parasite.genuine_below genuine ones -> the parasite type. Returns 'parasite' when
     * detected now, null otherwise.
     */
    public static function checkParasitePattern(string $npcName, array &$dynamics): ?string
    {
        $config = self::getConfig();
        if (empty($config['parasite_detection_enabled'])) return null;
        $p = RelDynProtocols::config()['parasite'];

        $pattern = $dynamics['_interaction_pattern'] ?? [];
        $totalWindow = intval($pattern['total_window'] ?? 0);

        if ($totalWindow < intval($p['min_exchanges'])) return null; // Not enough data

        $giftCount = intval($pattern['gift_count'] ?? 0);
        $genuineCount = intval($pattern['genuine_count'] ?? 0);
        $giftRatio = $giftCount / max(1, $totalWindow);
        $threshold = RelDynProtocols::parasiteRatioThreshold($dynamics);

        if ($giftRatio > $threshold && $genuineCount < intval($p['genuine_below'])) {
            $currentOverride = $dynamics['_relationship_type_override'] ?? null;
            if ($currentOverride !== 'parasite') {
                // Record history
                $dynamics['_relationship_type_history'][] = [
                    'from' => self::getRelationshipType($npcName, $dynamics),
                    'from_override' => $currentOverride,   // restored on recovery (null = follow core)
                    'to' => 'parasite',
                    'at' => intval($dynamics['interaction_count'] ?? 0),
                    'reason' => 'gift_ratio=' . round($giftRatio, 2) . ' threshold=' . round($threshold, 2),
                ];
                $dynamics['_relationship_type_override'] = 'parasite';
                self::log("[TYPE] Parasite detected for {$npcName}: gift_ratio=" . round($giftRatio, 2) . ' threshold=' . round($threshold, 2));
                return 'parasite';
            }
        }

        return null;
    }

    /**
     * Genuine engagement resumed: parasite.recover_genuine_at_least genuine exchanges in the
     * ledger and gifts no more than them. Restores the override that was active before.
     */
    public static function checkParasiteRecovery(string $npcName, array &$dynamics): ?string
    {
        $currentOverride = $dynamics['_relationship_type_override'] ?? null;
        if ($currentOverride !== 'parasite') return null;
        $p = RelDynProtocols::config()['parasite'];

        $pattern = $dynamics['_interaction_pattern'] ?? [];
        $genuineCount = intval($pattern['genuine_count'] ?? 0);
        $giftCount = intval($pattern['gift_count'] ?? 0);

        if ($genuineCount >= intval($p['recover_genuine_at_least']) && $giftCount <= $genuineCount) {
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
     * The postrequest's read of one exchange with the player for the parasite ledger: a gift seen
     * this request ($gift) or a positive exchange of a love language other than gifts
     * ($positive, the local classifier; the eval's item may raise it later, same game time).
     */
    public static function updateInteractionPattern(array &$dynamics, ?string $interactionType, float $affinityDelta, ?float $gamets = null, bool $gift = false, bool $positive = false): void
    {
        $g = ($gamets !== null && $gamets > 0) ? $gamets : self::currentGamets();
        if ($g <= 0) {
            return;   // no game clock: the exchange cannot be told apart from its eval item
        }
        $kind = ($gift || $interactionType === self::LL_GIFTS) ? RelDynProtocols::KIND_GIFT
            : (($positive || $affinityDelta > 0) && $interactionType !== null ? RelDynProtocols::KIND_GENUINE : RelDynProtocols::KIND_OTHER);
        RelDynProtocols::recordExchange($dynamics, $g, $kind);
    }

    // ========== END PARASITE DETECTION (PR 12) ==========

    // ========== BASELINE DRIFT (PR 13) ==========
    //
    // Significant bonds change who you are (audit #57; recap 2026-03-31 Fix 5): a dimension held
    // away from its GLOBAL baseline, day after day of contact, slowly moves that baseline.
    // Time does not heal, contact does (decisions 2026-09-23 §2): samples come only from the
    // NPC's own requests, one per game-calendar day; waiting or sleeping adds none. The drift is
    // applied at the diary eval (postrequest, before markDiaryCompleted).

    /** Config 'baseline_drift' over its defaults. */
    public static function baselineDriftConfig(): array
    {
        $stored = self::configValue('baseline_drift');
        return is_array($stored) ? array_replace(self::BASELINE_DRIFT_DEFAULTS, $stored) : self::BASELINE_DRIFT_DEFAULTS;
    }

    /**
     * The value a drift sample records for $dimId: affinity in core units (-100..100, the units
     * of its baseline and physics), every other dimension its x without the temporary offsets
     * held on it right now (heldTemporaryOffset): a full moon or an injury is not who the NPC is,
     * and is taken back exactly when it ends. Null when unset.
     */
    public static function driftSampleValue(array $dynamics, string $dimId): ?float
    {
        if ($dimId === 'affinity') {
            return is_numeric($dynamics['_aff_mirror_x'] ?? null) ? self::getCoreAffinity($dynamics) : null;
        }
        $x = $dynamics['dimensions'][$dimId]['x'] ?? null;
        return is_numeric($x) ? floatval($x) - self::heldTemporaryOffset($dynamics, $dimId) : null;
    }

    /**
     * Points currently held on $dimId's x by the temporary-offset pipeline, each taken back
     * exactly when its state ends: the creature row (RelDynCreatures, _creature.applied), the
     * physical states (_applied_physical_deltas per state), the place and hour
     * (_env_applied_effects), on comfort the guilt bleed (RelDynResentment, guilt.applied), and
     * acute grief on comfort / warmth (RelDynProtocols, _grief_held). Dimension points; 0 when none. The physics (applyDelta), the drift samples and the
     * per-bond display read the value without them.
     */
    public static function heldTemporaryOffset(array $dynamics, string $dimId): float
    {
        $held = floatval($dynamics[RelDynCreatures::STATE_KEY]['applied'][$dimId] ?? 0.0);
        foreach ((array) ($dynamics['_applied_physical_deltas'] ?? []) as $applied) {
            if (is_array($applied)) $held += floatval($applied[$dimId] ?? 0.0);
        }
        if (is_array($dynamics['_env_applied_effects'] ?? null)) {
            $held += floatval($dynamics['_env_applied_effects'][$dimId] ?? 0.0);
        }
        if ($dimId === 'comfort') {
            $held += floatval($dynamics[RelDynResentment::STATE_KEY]['guilt']['applied'] ?? 0.0);
        }
        // What she heard of the player before meeting them (reputation-layer), fading
        $held += RelDynReputation::heldOffset($dynamics, $dimId);
        // A consumable's spike until it wears off (item-modifiers)
        foreach ((array) ($dynamics['_active_consumables'] ?? []) as $c) {
            if (is_array($c)) $held += floatval($c['immediate'][$dimId] ?? 0.0);
        }
        // Acute grief toward every other bond (RelDynProtocols::tickGrief)
        $held += floatval($dynamics[RelDynProtocols::GRIEF_HELD_KEY][$dimId] ?? 0.0);
        return $held;
    }

    /**
     * The GLOBAL baseline of $dimId in its sample units: the stored baseline, else the NPC's
     * trait / temperament baseline (affinity is not seeded: core units from the traits).
     */
    public static function driftBaseline(array $dynamics, string $dimId): float
    {
        $stored = $dynamics['dimensions'][$dimId]['baseline'] ?? null;
        if (is_numeric($stored)) {
            return floatval($stored);
        }
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        return self::getTemperamentBaseline($temperament, $dimId, $dynamics);
    }

    /**
     * On contact (prerequest): record today's sample of every drift dimension. One sample per
     * game-calendar day (raw gamets / GAMETS_PER_DAY); a later contact the same day replaces
     * that day's sample (the day's experience as it ended). Keeps the last baseline_drift.window
     * days. No game clock ($now <= 0): nothing can be credited to a day, nothing is recorded.
     * Returns true when a sample was written.
     */
    public static function recordBaselineDriftSample(array &$dynamics, float $now): bool
    {
        if (!self::configValue('baseline_drift_enabled') || $now <= 0) {
            return false;
        }
        $cfg = self::baselineDriftConfig();
        $day = (int) floor($now / self::GAMETS_PER_DAY);
        $window = max(1, intval($cfg['window']));
        $samples = is_array($dynamics['_baseline_drift_samples'] ?? null) ? $dynamics['_baseline_drift_samples'] : [];
        $wrote = false;
        foreach ((array) $cfg['dimensions'] as $dimId) {
            $v = self::driftSampleValue($dynamics, (string) $dimId);
            if ($v === null) continue;
            // older builds kept bare values (no day): they cannot be placed on the calendar
            $list = array_values(array_filter((array) ($samples[$dimId] ?? []), fn($s) => is_array($s) && isset($s['day'])));
            $last = count($list) - 1;
            if ($last >= 0 && intval($list[$last]['day']) === $day) {
                $list[$last]['v'] = round($v, 4);
            } else {
                $list[] = ['v' => round($v, 4), 'day' => $day];
            }
            $samples[$dimId] = array_slice($list, -$window);
            $wrote = true;
        }
        $dynamics['_baseline_drift_samples'] = $samples;
        return $wrote;
    }

    /**
     * At the diary eval: every drift dimension whose last min_samples samples all sit more than
     * tolerance points on one side of its baseline moves that baseline rate x (their average -
     * baseline), at most max_from_origin points from its origin. The origin is where drift
     * started (_baseline_drift_origin: the seed, or the value an editor or a divine-intervention
     * arc last set; a baseline that no longer equals the value drift left is re-anchored there).
     * The baseline read is the NPC's own: resentment_self's standing offset on it
     * (RelDynResentment::baselineOffset, lifted exactly when the shame falls back) and her worn
     * gear's (equippedBaselineOffset) are states, not a new origin (heldBaselineOffset), so drift
     * runs without them and writes them back on top.
     * Gated by the game calendar: a day's evidence moves a baseline once, so a second diary eval
     * before a newer contact day has been sampled leaves it where the first one put it.
     * Returns dimension => ['old_baseline', 'new_baseline', 'drift'] (sample units).
     */
    public static function processBaselineDrift(string $npcName, array &$dynamics): array
    {
        if (!self::configValue('baseline_drift_enabled')) return [];
        $cfg = self::baselineDriftConfig();
        $minSamples = max(1, intval($cfg['min_samples']));
        $tolerance = max(0.0, floatval($cfg['tolerance']));
        $rate = max(0.0, floatval($cfg['rate']));
        $maxFromOrigin = max(0.0, floatval($cfg['max_from_origin']));
        $samples = is_array($dynamics['_baseline_drift_samples'] ?? null) ? $dynamics['_baseline_drift_samples'] : [];
        $origins = is_array($dynamics['_baseline_drift_origin'] ?? null) ? $dynamics['_baseline_drift_origin'] : [];
        $results = [];

        foreach ((array) $cfg['dimensions'] as $dimId) {
            $dimId = (string) $dimId;
            $list = array_values(array_filter((array) ($samples[$dimId] ?? []), fn($s) => is_array($s) && isset($s['v'], $s['day'])));
            if (count($list) < $minSamples) continue;
            $latestDay = intval($list[count($list) - 1]['day']);
            $recent = array_map(fn($s) => floatval($s['v']), array_slice($list, -$minSamples));
            $selfOffset = self::heldBaselineOffset($dynamics, $dimId);   // held on the stored baseline (resentment_self, worn gear)
            $baseline = self::driftBaseline($dynamics, $dimId) - $selfOffset;   // her own

            $above = min($recent) > $baseline + $tolerance;
            $below = max($recent) < $baseline - $tolerance;
            if (!$above && !$below) continue;   // not held on one side: no drift

            $o = is_array($origins[$dimId] ?? null) ? $origins[$dimId] : null;
            if ($o === null || !is_numeric($o['at'] ?? null) || abs(floatval($o['at']) - $baseline) > 1e-6) {
                $o = ['origin' => $baseline, 'at' => $baseline];   // first drift, or set since
            }
            if (is_numeric($o['day'] ?? null) && intval($o['day']) >= $latestDay) continue;   // this day already counted
            $origin = floatval($o['origin']);
            $avg = array_sum($recent) / count($recent);
            $new = $baseline + ($avg - $baseline) * $rate;
            $new = max($origin - $maxFromOrigin, min($origin + $maxFromOrigin, $new));
            $def = self::getDimensionDefinition($dimId);
            if ($dimId === 'affinity') {
                $new = max(self::CORE_AFFINITY_MIN, min(self::CORE_AFFINITY_MAX, $new));
            } elseif ($def) {
                $new = max(floatval($def['range_min']), min(floatval($def['range_max']), $new));
            }
            $drift = $new - $baseline;
            if (abs($drift) < 1e-9) continue;   // at the bound

            $dynamics['dimensions'][$dimId]['baseline'] = $new + $selfOffset;
            $origins[$dimId] = ['origin' => $origin, 'at' => $new, 'day' => $latestDay];
            $results[$dimId] = ['old_baseline' => $baseline + $selfOffset, 'new_baseline' => $new + $selfOffset, 'drift' => $drift];
            self::log(sprintf('[DRIFT] %s %s: baseline %.2f -> %.2f (samples avg %.2f, origin %.2f%s)', $npcName, $dimId,
                $baseline, $new, $avg, $origin, $dimId === 'affinity' ? ', core units' : ''));
        }
        if ($origins !== []) {
            $dynamics['_baseline_drift_origin'] = $origins;
        }
        return $results;
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

    // ========== VAMPIRE/WEREWOLF MOODIFICATIONS (PR 13) ==========

    /**
     * The creature row's offsets for the NPC now (dimension => points; RelDynCreatures::rowFor),
     * [] for a non-creature or with creature_moodifications_enabled off.
     */
    public static function getCreatureModifiers(string $npcName, array $dynamics, ?float $gamets = null): array
    {
        if (!RelDynCreatures::enabled()) return [];
        return RelDynCreatures::current($npcName, $dynamics, $gamets)['effects'];
    }

    /**
     * Hold the creature row's offsets (RelDynCreatures::update: applied once, taken back when the
     * row changes; never re-added per request) and notice a return from beast form.
     */
    public static function applyCreatureModifiers(string $npcName, array &$dynamics, string $temperament): void
    {
        RelDynCreatures::update($npcName, $dynamics, $temperament);
    }

    /** vampire | werewolf | null from core data (RelDynCreatures::detect). */
    public static function detectCreatureType(string $npcName, array $dynamics): ?string
    {
        return RelDynCreatures::detect($npcName, $dynamics)['type'];
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

    /** Full moon in Skyrim's 24-day cycle (RelDynCreatures::moonPhase); false when the clock is unknown. */
    public static function isFullMoon(?float $gamets = null): bool
    {
        $phase = RelDynCreatures::moonPhase($gamets ?? self::currentGamets());
        return $phase !== null && $phase['full'];
    }

    // ========== END INTERNAL WEATHER ENGINE (PR 13) ==========

    // ========== EMERGENT EMOTION LABELING (PR 13) ==========

    const EMERGENT_EMOTIONS = [
        'infatuation' => [
            'rules' => ['affinity' => [70, 100], 'passion' => [60, 100], 'trust' => [0, 40]],
            'context' => "{NAME} idealizes the player: hangs on every word, yet shares nothing real and believes nothing they promise.",
        ],
        'codependency' => [
            'rules' => ['affinity' => [80, 100], 'comfort' => [0, 30], 'self_confidence' => [0, 30]],
            'context' => "{NAME} clings to the player: needs to know where they are, can't settle when apart, agrees too fast.",
        ],
        'suffocation' => [
            'rules' => ['affinity' => [60, 100], 'comfort' => [80, 100], 'resentment' => [30, 100]],
            'context' => "{NAME} wants the closeness and needs air: pulls back after tender moments, snappish when crowded.",
        ],
        'contempt' => [
            'rules' => ['resentment' => [50, 100], 'affinity' => [40, 100], 'respect' => [0, 25]],
            'context' => "{NAME} is still attached and can't stand the player: eye-rolls, cutting remarks, sneers at their ideas.",
        ],
        'longing' => [
            'rules' => ['affinity' => [60, 100], 'warmth' => [50, 100], 'passion' => [0, 15]],
            'context' => "{NAME} is tender and wistful with the player, remembers the spark, sighs over what used to be.",
        ],
        'protective_fury' => [
            'rules' => ['affinity' => [70, 100], 'arousal' => [60, 100], 'valence' => [-100, -20]],
            'context' => "{NAME} bristles at any threat to the player, steps between them and danger, ready to do violence for them.",
        ],
        'quiet_devotion' => [
            'rules' => ['affinity' => [80, 100], 'maturity' => [70, 100], 'resentment' => [0, 10], 'passion' => [0, 40]],
            'context' => "{NAME} is steady and unhurried with the player: no drama, small constant kindnesses, chooses them again every day.",
        ],
        'bitter_nostalgia' => [
            'rules' => ['affinity' => [0, 30], 'warmth' => [50, 100], 'resentment' => [40, 100]],
            'context' => "{NAME} softens at old memories of the player, then hardens at what went wrong; warm and bitter in one breath.",
        ],
        'grudging_respect' => [
            'rules' => ['respect' => [60, 100], 'affinity' => [0, 20], 'resentment' => [30, 100]],
            'context' => "{NAME} dislikes the player and still defers to their skill; compliments come out through gritted teeth.",
        ],
        'volatile_passion' => [
            'rules' => ['passion' => [70, 100], 'maturity' => [0, 30], 'arousal' => [50, 100]],
            'context' => "{NAME} burns hot around the player and swings without warning: tender, furious, desperate, all within a breath.",
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

        // The bond this NPC had when her moments were first watched: a later change is a moment
        if (($dynamics['_diary_last_rel_type'] ?? null) === null) {
            $dynamics['_diary_last_rel_type'] = $dynamics['_core_rel_type'] ?? null;
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

        // Gate 3: Consumable block (consumeItem keeps them in _active_consumables until they expire)
        if (self::DIARY_CONSUMABLE_BLOCK && RelDynDiary::intoxicated($dynamics)) {
            return false;
        }

        // Gate 4: Maturity gate (her own maturity: a held offset such as the full moon is not who she is)
        $maturity = self::driftSampleValue($dynamics, 'maturity') ?? 0.0;
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
     * Detect the meaningful moments since the last mark (markDiaryCompleted's bookmarks) that
     * the NPC's next diary entry reflects on (RelDynDiary):
     *   1. Sustained delta: 15+ drift from baseline in any dimension
     *   2. Crisis indicators: 2+ of resentment>30, comfort<20, trust<20, resentment_self>30
     *   3. Phase transitions: DI count changed, grief phase changed, attachment shifted
     *   4. New emergent emotions since last diary
     *   5. Intimacy critical: intimacy dimension < 0.1 (near-zero)
     *   6. Tier change: attraction tier ceiling shifted
     *   7. Defining moment: an interaction of significance level 3 (_diary_significance_peak)
     *   8. Conflict opened (conflict_opened)
     *   9. Boundary: a boundary lane moved (boundary:fulfillment, boundary:concern)
     *  10. Bond change: core's relationship type changed (bond_changed:<from>-><to>)
     *
     * @param array $dynamics  The NPC's dynamics array
     * @return array  Array of trigger description strings (empty = no triggers)
     */
    public static function detectDiaryContentTriggers(array $dynamics): array
    {
        $triggers = [];
        $dims = $dynamics['dimensions'] ?? [];

        // 1. Sustained delta: check baseline drift samples for 15+ deviation (the baseline in the
        // samples' units: affinity core points, driftBaseline)
        $driftSamples = $dynamics['_baseline_drift_samples'] ?? [];
        if (!empty($driftSamples)) {
            foreach ($driftSamples as $dimId => $samples) {
                if (!is_array($samples) || empty($samples)) continue;
                $baseline = self::driftBaseline($dynamics, (string) $dimId);
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

        // Attachment shift (the style region the axes are in)
        $currentAttachment = self::getAttachmentStyle($dynamics);
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

        // 7. Defining moment: an interaction of significance level 3 since the last mark (the
        // strongest applied eval item's level, kept on the NPC: the eval worker applies items
        // outside this request)
        $lastSignificance = max(intval($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] ?? 0),
            intval($dynamics['_diary_significance_peak'] ?? 0));
        if ($lastSignificance >= 3) {
            $triggers[] = "defining_moment:significance_{$lastSignificance}";
        }

        // 8. Conflict: one opened since the last mark (enterConflict stamps conflict_entered_at)
        $conflictAt = $dynamics['conflict_entered_at'] ?? null;
        $lastConflictAt = $dynamics['_diary_last_conflict_at'] ?? null;
        if (!empty($dynamics['in_conflict']) && is_numeric($conflictAt)
            && (!is_numeric($lastConflictAt) || floatval($conflictAt) !== floatval($lastConflictAt))) {
            $triggers[] = 'conflict_opened';
        }

        // 9. Boundaries: the fulfillment boundary (rulings §9) or the values boundary (concern)
        // moved to a new state or stepped the bond back since the last mark
        $lastBoundaries = is_array($dynamics['_diary_last_boundaries'] ?? null) ? $dynamics['_diary_last_boundaries'] : [];
        foreach (self::diaryBoundarySignatures($dynamics) as $lane => $sig) {
            if ($sig !== 'none' && $sig !== ($lastBoundaries[$lane] ?? 'none')) {
                $triggers[] = "boundary:{$lane}";
            }
        }

        // 10. Bond change: core's relationship type moved since the last mark
        $relType = $dynamics['_core_rel_type'] ?? null;
        $lastRelType = $dynamics['_diary_last_rel_type'] ?? null;
        if (is_string($relType) && is_string($lastRelType) && $relType !== $lastRelType) {
            $triggers[] = "bond_changed:{$lastRelType}->{$relType}";
        }

        return $triggers;
    }

    /**
     * Each boundary lane's state for the diary's bookmarks: 'none', or its state with the game
     * time of its last step-back ('probation', 'none@<gamets>').
     *
     * @return array{fulfillment: string, concern: string}
     */
    public static function diaryBoundarySignatures(array $dynamics): array
    {
        $out = [];
        // Fulfillment is stored per relationship pair (rulings §11): the player pair's boundary
        foreach (['fulfillment' => RelDynFulfillment::pairState($dynamics)['boundary'] ?? null,
                  'concern' => $dynamics[RelDynConcern::STATE_KEY]['boundary'] ?? null] as $lane => $b) {
            $state = is_array($b) && is_string($b['state'] ?? null) ? $b['state'] : 'none';
            $out[$lane] = $state . (is_array($b) && is_numeric($b['stepped_back_gamets'] ?? null) ? '@' . $b['stepped_back_gamets'] : '');
        }
        return $out;
    }

    /**
     * Mark the moments a trigger found (postrequest, after baseline drift): every "last" tracking
     * field moves to the current state, so the next check compares against it, and the pending
     * triggers become one moment kept for the NPC's next entry in core's diary
     * (RelDynDiary::keepMoments; the reflection on it is RelDynDiary::onPrerequest).
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
        $dynamics['_diary_last_attachment'] = self::getAttachmentStyle($dynamics);

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

        // Significance, conflict, boundary and bond bookmarks
        $dynamics['_diary_significance_peak'] = 0;
        $dynamics['_diary_last_conflict_at'] = !empty($dynamics['in_conflict']) ? ($dynamics['conflict_entered_at'] ?? null) : null;
        $dynamics['_diary_last_boundaries'] = self::diaryBoundarySignatures($dynamics);
        $dynamics['_diary_last_rel_type'] = $dynamics['_core_rel_type'] ?? null;

        // The marked moments wait for the NPC's next diary entry (RelDynDiary::onPrerequest)
        RelDynDiary::keepMoments($dynamics, (array) ($dynamics['_diary_pending_triggers'] ?? []), self::currentGamets());
        $dynamics['_diary_pending_triggers'] = [];
        $dynamics['_diary_trigger_source'] = null;
    }

    // ========== END AUTONOMOUS DIARY TRIGGER SYSTEM (PR 14) ==========

    // ========== SOCIAL MASKING (PR 14) ==========

    /** config 'social_masking' laid over its defaults (a stored row may lack a key). */
    public static function maskingConfig(): array
    {
        $defaults = self::defaultConfig()['social_masking'];
        $stored = self::configValue('social_masking');
        return array_replace($defaults, is_array($stored) ? $stored : []);
    }

    /**
     * Is this NPC someone who wears the Mask at all (MDD 11: "High Status Priority +
     * Toxic/Avoidant")? Her attachment style (the region of her two axes) is one of
     * social_masking.attachment_styles and her status trait reaches status_min. Who is
     * watching is shouldMask's question.
     */
    public static function wearsMask(array $dynamics): bool
    {
        $mc = self::maskingConfig();
        $style = self::getAttachmentStyle($dynamics);
        if (!in_array($style, array_map('strval', (array) $mc['attachment_styles']), true)) {
            return false;
        }
        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $trait = (string) $mc['status_trait'];
        return is_array($x) && isset($x[$trait]) && floatval($x[$trait]) >= floatval($mc['status_min']);
    }

    /**
     * Determine if masking should be active: social masking on, an NPC who wears the Mask
     * (wearsMask), composed enough to hold one (maturity at least social_masking.maturity_min)
     * and someone present she does not trust (core's CACHE_PEOPLE, set before the context
     * hooks, never before prerequest).
     */
    public static function shouldMask(string $npcName, array $dynamics): bool
    {
        $config = self::getConfig();
        if (empty($config['social_masking_enabled'])) return false;
        $mc = self::maskingConfig();

        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        if ($maturity < floatval($mc['maturity_min'])) return false;
        if (!self::wearsMask($dynamics)) return false;

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
            if (floatval($bond['aff']) < floatval($mc['trusted_affinity_min'])) {   // core affinity -100..100
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

        // Avoidant corner: practiced maskers (x0.5); anxious: struggling to hold the facade
        // (x1.5); blended at the NPC's attachment axes
        $cost *= self::attachmentBlend($dynamics, ['avoidant' => 0.5, 'anxious' => 1.5], 1.0);

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
        // Felt steering (decisions 2026-09-23 §3): what shows and what leaks, never the numbers.
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $t = (array) RelDynFelt::config()['text']['mask'];
        $hidden = self::maskHiddenText($dynamics, $performedState);

        $cachePeople = $GLOBALS['CACHE_PEOPLE'] ?? '';
        $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
        $audience = array_values(array_filter(array_map('trim', explode('|', $cachePeople)), function ($p) use ($npcName, $playerName) {
            return $p !== '' && strcasecmp($p, $npcName) !== 0 && strcasecmp($p, $playerName) !== 0;
        }));
        $who = $audience !== [] ? implode(', ', array_slice($audience, 0, 3)) : (string) $t['others'];

        $quality = $maturity >= 65 ? 'seamless' : ($maturity >= 45 ? 'functional' : 'unstable');
        return strtr((string) $t['intro'], ['{NAME}' => $npcName, '{AUDIENCE}' => $who, '{TRUE}' => $hidden])
            . ' ' . (string) $t[$quality];
    }

    /**
     * What the front hides, in words (felt steering, the eval's state summary): the true band
     * keywords of whichever of comfort / resentment / warmth sits furthest from what is
     * performed; the mask text's fallback when none of them has keywords.
     */
    public static function maskHiddenText(array $dynamics, array $performedState): string
    {
        $true = [];
        foreach (['resentment', 'comfort', 'warmth'] as $dim) {
            $x = floatval($dynamics['dimensions'][$dim]['x'] ?? ($dim === 'resentment' ? 0 : 50));
            $gap = abs($x - floatval($performedState[$dim] ?? $x));
            $band = self::getDimensionBand($dim, $x);
            if ($band !== null && trim((string) $band['keywords']) !== '') $true[$dim] = [$gap, (string) $band['keywords']];
        }
        uasort($true, fn($a, $b) => $b[0] <=> $a[0]);
        return $true !== [] ? reset($true)[1] : (string) (RelDynFelt::config()['text']['mask']['hidden_default'] ?? 'more than they show');
    }

    /**
     * This turn's mask (the context hook, after core set CACHE_PEOPLE): is she masking now,
     * did the mask just drop (masking last turn, alone with the player now), and did the eval
     * see the front slip since (the one-shot applyEvalExtraFields left). Records the turn's
     * state for the next one and for the eval (_was_masking, _performed_state_cache).
     *
     * @return array{masking: bool, performed: ?array, drop: bool, slip: bool, changed: bool}
     */
    public static function maskingTurn(string $npcName, array &$dynamics): array
    {
        $enabled = !empty(self::getConfig()['social_masking_enabled']);
        $was = $enabled && !empty($dynamics['_was_masking']);   // switched off: no drop to show
        $is = self::shouldMask($npcName, $dynamics);
        $performed = $is ? self::calculatePerformedState($dynamics) : null;
        $slip = $enabled && is_numeric($dynamics['_mask_slip_gamets'] ?? null);
        if (!$enabled && (!empty($dynamics['_was_masking']) || isset($dynamics['_mask_slip_gamets']))) {
            $dynamics['_was_masking'] = false;
            unset($dynamics['_mask_slip_gamets']);
            return ['masking' => false, 'performed' => null, 'drop' => false, 'slip' => false, 'changed' => true];
        }
        $changed = $was !== $is || ($dynamics['_performed_state_cache'] ?? null) !== $performed || $slip;
        $dynamics['_was_masking'] = $is;
        $dynamics['_performed_state_cache'] = $performed;
        unset($dynamics['_mask_slip_gamets']);
        if ($was !== $is) {
            self::log("[RelDyn-MASK] {$npcName}: " . ($is ? 'puts on the mask (an untrusted audience)' : 'the mask drops (no audience)'));
        }
        return ['masking' => $is, 'performed' => $performed, 'drop' => $was && !$is, 'slip' => $slip, 'changed' => $changed];
    }

    /**
     * Mask-drop context when transitioning from public to private (maskingTurn's drop), from
     * the felt text: composed (maturity at least 45) or deflated.
     */
    public static function generateMaskDropContext(string $npcName, array $dynamics): ?string
    {
        $t = (array) RelDynFelt::config()['text']['mask'];
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        return strtr((string) $t[$maturity >= 45 ? 'drop_composed' : 'drop_deflated'], ['{NAME}' => $npcName]);
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
    const ICK_RESENTMENT_PER_ATTEMPT = 5; // Resentment added per continued romantic attempt while ick active
    const ICK_COMFORT_OVERRIDE = -3.0;    // Comfort per continued romantic attempt while ick active
    const ICK_COOLDOWN_PLAY_GAMETS = 1389000; // Cooldown after ick clears: 10 min of real play (600s * GAMETS_PER_REAL_SECOND)
    const ICK_RECOVERY = [
        'comfort'    => 40,   // Comfort must exceed this (dimension design: "recover above 40")
        'quiet'      => 3,    // ... and passion stable: at its floor, or this many interactions without an attempt
        'resentment' => 20,   // Resentment must be below this (OR confrontation occurred)
    ];
    // (Tunable in config protocols.ick, whose defaults are these constants.)

    // Moods in which the NPC answers courting in kind (the default of config
    // protocols.ick.reciprocal_moods): core 3.4.1's own (lib/emote_moods.php: sexy, lovely,
    // seductive, playful, teasing), then older and custom mood names
    const ROMANTIC_MOODS = [
        'sexy', 'lovely', 'seductive', 'playful', 'teasing',
        'flirty', 'romantic', 'charmed', 'smitten', 'coy', 'affectionate', 'flustered', 'loving', 'aroused',
    ];

    /**
     * Does this exchange count as UNRECIPROCATED romantic pressure from the player (MDD 6.3,
     * the Desperation Tracker: "flirt attempts vs. the NPC's current state")?
     *
     * $mood is the mood the NPC answered in (moods_issued: the NPC's own reply). An NPC that
     * answers in one of protocols.ick.reciprocal_moods (sexy, lovely, playful ...) is
     * reciprocating: that exchange is mutual, never pressure, whatever the player said. Otherwise
     * physical touch counts, and so does a high eval romantic_intent. (Intimacy the game reports
     * inside a romance is never pressure either: RelDynProtocols::ickAttemptOfRequest.)
     *
     * @param string|null $interactionLL  Love language classification (LL_TOUCH, LL_WORDS, etc.)
     * @param string|null $mood           The NPC's own last mood (its reply)
     * @param array       $evalResult     Eval result (may contain romantic_intent)
     * @return bool
     */
    public static function isRomanticAttempt($interactionLL, $mood, $evalResult = [])
    {
        // She flirted back: reciprocated, not the Ick's business
        $reciprocal = array_map('strtolower', (array) RelDynProtocols::config()['ick']['reciprocal_moods']);
        if (!empty($mood) && in_array(strtolower(trim((string) $mood)), $reciprocal, true)) {
            return false;
        }

        // Physical touch the NPC did not answer in kind
        if ($interactionLL === self::LL_TOUCH) {
            return true;
        }

        // Eval detected high romantic intent from player
        $romanticIntent = intval($evalResult['romantic_intent'] ?? 0);
        if ($romanticIntent >= 2) {
            return true;
        }

        return false;
    }

    /** Exchanges (raw gamets, as ints) the Ick remembers as counted attempts, newest last. */
    const ICK_COUNTED_KEEP = 20;

    /**
     * Update the ick rolling window tracker: one interaction (postrequest), a romantic attempt
     * when the local classification says so (touch she did not answer in kind). The eval's
     * romantic_intent is counted per applied item (recordIckEvalAttempt), never peeked at here.
     *
     * @param array      &$dynamics    NPC dynamics blob
     * @param bool       $isRomantic   Was this a romantic attempt?
     * @param string     $temperament  NPC temperament
     * @param float|null $gamets       raw game time of the exchange (remembered when counted,
     *                                 so its eval item is not counted again)
     * @return bool True if ick state changed
     */
    public static function updateIckTracker(&$dynamics, $isRomantic, $temperament, ?float $gamets = null)
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
            if ($gamets !== null) {
                $tracker['counted_gamets'] = array_slice(array_merge((array) ($tracker['counted_gamets'] ?? []), [(int) round($gamets)]), -self::ICK_COUNTED_KEEP);
            }
        }
        unset($tracker);
        return self::ickAfterInteraction($dynamics, (bool) $isRomantic, $temperament, $gamets);
    }

    /**
     * The eval's side of the Ick (MDD 6.3), once per applied item ($n: a normalized contract
     * item with romantic_intent): clear courting (romantic_intent >= 2) that the NPC did not
     * answer in kind (the item's reply_mood, the mood of that exchange's reply; isRomanticAttempt)
     * counts as a romantic attempt of the interaction the postrequest already counted, unless
     * that exchange was already counted (touch, or this item before) or the window has no
     * uncounted interaction left to attribute it to. Intimacy the game reported for that exchange
     * (the item's reported_intimacy, code-written) inside a romance is never pressure
     * (RelDynProtocols::ickReportedIntimacy). An item carrying its own grievance leaves that
     * exchange's resentment to the grievance (ickAfterInteraction). Returns true when the ick
     * state changed.
     */
    public static function recordIckEvalAttempt(string $npcName, array $n, array &$dynamics): bool
    {
        if (!self::isRomanticAttempt(null, $n['reply_mood'] ?? null, $n)) {
            return false;
        }
        $g = (int) round(floatval($n['gamets'] ?? 0));
        if (RelDynProtocols::ickReportedIntimacy($dynamics, is_string($n['reported_intimacy'] ?? null) ? $n['reported_intimacy'] : null)) {
            self::log("[ICK] {$npcName}: intimacy the game reported at gamets {$g}, inside the romance: not pressure");
            return false;
        }
        $tracker = $dynamics['_ick_tracker'] ?? null;
        if (!is_array($tracker)) {
            self::log("[ICK] {$npcName}: eval attempt at gamets {$g} with no interaction counted: not counted");
            return false;
        }
        if (in_array($g, array_map('intval', (array) ($tracker['counted_gamets'] ?? [])), true)) {
            return false;   // this exchange is already counted
        }
        if (intval($tracker['romantic_count']) >= intval($tracker['total_count'])) {
            self::log("[ICK] {$npcName}: eval attempt at gamets {$g}: every interaction of this window is counted already");
            return false;
        }
        $dynamics['_ick_tracker']['romantic_count'] = intval($tracker['romantic_count']) + 1;
        $dynamics['_ick_tracker']['counted_gamets'] = array_slice(array_merge((array) ($tracker['counted_gamets'] ?? []), [$g]), -self::ICK_COUNTED_KEEP);
        self::log("[ICK] {$npcName}: courting she did not answer in kind (eval, gamets {$g}, reply mood " . ($n['reply_mood'] ?? 'unknown') . ')');
        return self::ickAfterInteraction($dynamics, true, $dynamics['inferred_temperament'] ?? null, $g > 0 ? (float) $g : null,
            !empty($n['grievance']['flag']));
    }

    /**
     * After an interaction is counted: while the ick is active, a continued attempt costs comfort
     * and builds resentment (config protocols.ick, points through applyDelta) and anything else
     * is a quiet interaction (the player backing off, read by the recovery); otherwise its trigger,
     * stamped with the exchange's game time ($gamets, raw; default the game clock) and the play
     * clock, never the wall clock. $grievanceOwnsResentment: the eval item of this exchange
     * carries its own grievance, which is the exchange's resentment (the resentment flow): the
     * attempt's resentment is not added on top of it.
     */
    private static function ickAfterInteraction(array &$dynamics, bool $isRomantic, $temperament, ?float $gamets = null, bool $grievanceOwnsResentment = false): bool
    {
        $ick = RelDynProtocols::config()['ick'];
        unset($dynamics['_ick_tracker']['ick_triggered_at']);   // the April wall-clock stamp, never read

        if (!empty($dynamics['_ick_tracker']['ick_active'])) {
            if (!$isRomantic) {
                $dynamics['_ick_tracker']['quiet'] = intval($dynamics['_ick_tracker']['quiet'] ?? 0) + 1;
                return false;
            }
            $dynamics['_ick_tracker']['quiet'] = 0;
            $r = $grievanceOwnsResentment ? 0.0 : self::applyDelta('resentment', $dynamics, floatval($ick['resentment_per_attempt']), $temperament);
            $c = self::applyDelta('comfort', $dynamics, floatval($ick['comfort_per_attempt']), $temperament);
            self::log(sprintf('[ICK] Continued romantic attempt while ick active: resentment %+.2f%s, comfort %+.2f', $r,
                $grievanceOwnsResentment ? ' (the grievance carries it)' : '', $c));
            return false; // State didn't change
        }

        // Check if ick should trigger (only if not already active and not in cooldown)
        if (self::checkIckTrigger($dynamics, $temperament)) {
            $dynamics['_ick_tracker']['ick_active'] = true;
            $dynamics['_ick_tracker']['quiet'] = 0;
            $dynamics['_ick_tracker']['ick_triggered_gamets'] = ($gamets !== null && $gamets > 0) ? $gamets : self::currentGamets();
            $dynamics['_ick_tracker']['ick_triggered_play_gamets'] = self::getPlayGamets($dynamics);
            self::log("[ICK] TRIGGERED for NPC — romantic ratio too high while unreceptive");
            return true; // State changed
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

        // MDD 1.4: the player pushing past a failed attraction check reaches the Ick sooner
        // with a low-openness NPC, later with a high-openness one
        if (!empty($dynamics['_attraction']['failed'])) {
            $band = (string) ($dynamics['_attraction']['openness'] ?? 'medium');
            $threshold *= floatval(((array) RelDynAttraction::config()['openness_ick_mult'])[$band] ?? 1.0);
        }

        // Catalyst archetype lowers threshold by 30% for mature NPCs
        $charismaStyle = self::charismaStyle($dynamics);
        if ($charismaStyle === 'catalyst' && $maturity > 60) {
            $threshold *= 0.7;
            self::log("[ICK] Catalyst style detected + high maturity — threshold reduced 30%");
        }
        // MDD 5.1: the Charmer "triggers Ick if overused"
        if (self::charmerOverused($dynamics)) {
            $threshold *= floatval(self::charismaConfig()['charmer_overuse_ick_mult']);
            self::log('[ICK] the Charmer overused: threshold x ' . self::charismaConfig()['charmer_overuse_ick_mult']);
        }

        // Attachment (dimension design, avoidant: "suffocation threshold (ick) lowered"): the
        // avoidance axis lowers it, continuously (RelDynProtocols::ickAvoidanceMult)
        $threshold *= RelDynProtocols::ickAvoidanceMult(is_array($dynamics) ? $dynamics : []);
        $ick = RelDynProtocols::config()['ick'];

        if ($ratio < $threshold) {
            return false; // Not enough romantic pressure
        }

        // Receptive by the Attraction Matrix (rulings §9): she is drawn to the player and her
        // romance axis is open and earned (a crush or more). Romantic pressure from someone she
        // wants is not the Ick, whatever her comfort / passion levels read today.
        $att = $dynamics['_attraction'] ?? null;
        if (is_array($att) && !empty($att['enabled']) && !empty($att['passes']) && empty($att['friendzoned'])
            && intval($att['romance']['effective'] ?? 0) >= RelDynAttraction::ROMANCE_CRUSH) {
            return false;
        }

        // Check receptivity conditions
        $dims = $dynamics['dimensions'] ?? [];
        $comfort = floatval($dims['comfort']['x'] ?? 50);
        $passion = floatval($dims['passion']['x'] ?? 0);
        $warmth  = floatval($dims['warmth']['x'] ?? 50);

        // NPC must be unreceptive: comfort < 40 AND (passion < 20 OR warmth < 30) (config protocols.ick)
        if ($comfort >= floatval($ick['comfort_floor'])) {
            return false; // NPC is comfortable — no ick
        }

        if ($passion >= floatval($ick['passion_floor']) && $warmth >= floatval($ick['warmth_floor'])) {
            return false; // NPC is receptive — no ick
        }

        // Inside a romance the floors alone are not her coldness (a fresh start's seed reads
        // below them): her comfort must have been pushed below where she rests (RelDynProtocols::ickColdIsHers)
        if (!RelDynProtocols::ickColdIsHers(is_array($dynamics) ? $dynamics : [], ['ick' => $ick])) {
            self::log('[ICK] romantic pressure on a partner at her own resting ease: not the Ick');
            return false;
        }

        return true;
    }

    /**
     * Check if ick should clear (dimension design recovery: "Stop flirting. Comfort needs to
     * recover above 40 AND passion needs to stabilize ... Time + space + the NPC addressing it"):
     * comfort above ick.recovery_comfort_above, passion stable (at or above the Ick's passion
     * floor, or no attempt for ick.recovery_quiet_interactions counted interactions: the push has
     * stopped) and resentment below ick.recovery_resentment_below or a calm confrontation.
     * (The April passion > 40 could not be met: passion gains are inverted while the ick lasts.)
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

        $ick = RelDynProtocols::config()['ick'];
        $comfortOk    = ($comfort > floatval($ick['recovery_comfort_above']));
        $passionOk    = ($passion >= floatval($ick['passion_floor']) || intval($tracker['quiet'] ?? 0) >= intval($ick['recovery_quiet_interactions']));
        $resentmentOk = ($resentment < floatval($ick['recovery_resentment_below']));

        // Check if confrontation occurred (resentment was addressed)
        $confrontationOccurred = !empty($dynamics['_ick_confrontation_resolved']);

        if ($comfortOk && $passionOk && ($resentmentOk || $confrontationOccurred)) {
            $tracker['ick_active'] = false;
            $tracker['ick_cooldown_until_play_gamets'] = self::getPlayGamets($dynamics) + floatval($ick['cooldown_play_gamets']);
            unset($tracker['ick_cooldown_until']); // legacy wall-clock value
            $tracker['romantic_count'] = 0;
            $tracker['total_count'] = 0;
            $tracker['quiet'] = 0;
            $dynamics['_ick_confrontation_resolved'] = false;
            self::log("[ICK] CLEARED — recovery conditions met, cooldown set");
            return true;
        }

        return false;
    }

    /**
     * Apply ick effects to an eval delta before XYZ physics: passion gains invert (MDD 6.3).
     * Comfort is not forced down on every exchange: the continued attempt pays it
     * (ickAfterInteraction), so an exchange without pressure can let her recover.
     * Called from processEvalDeltas / applyEvalSignal.
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

        // Felt steering: what the NPC does about the unwanted attention, never a verdict on it.
        $base = "{$npcName} steps back when the player leans in, deflects the compliments, steers every flirtation back to business. ";

        // Maturity determines reaction style
        if ($maturity >= 65) {
            $base .= "{$npcName} sees the pattern clearly and will name it plainly if it goes on.";
        } elseif ($maturity >= 45) {
            $base .= "{$npcName} withdraws rather than confronting it; shorter answers, more distance.";
        } else {
            $base .= "{$npcName} gets flustered and prickly, unsure whether the problem is the player or themselves.";
        }

        // Resentment escalation (the design's "confrontation at resentment 50"); with the resentment
        // arc on, its confrontation is the one voice for it (RelDynResentment::voicesConfrontation)
        if ($resentment >= 50 && !RelDynResentment::voicesConfrontation()) {
            $base .= " Close to snapping about the unwanted advances; the next one gets a sharp answer.";
        }

        return $base;
    }

    // ========== END ICK / DESPERATION TRACKER (PR 15) ==========

    // ========== CHARISMA ARCHETYPES (PR 15) ==========
    //
    // The player's interaction style (Rock / Catalyst / Charmer, MDD 5.1: "no press X to flirt,
    // LLM sentiment analysis grades Charisma flavor") from the eval's own grade of each
    // exchange's approach: the contract field charisma (rulings 2026-09-25 §18 #11), which
    // replaced the affinity-variance heuristic (push-pull read from how her affinity signals
    // swung, the Rock from a steady player). Applies effectiveness multipliers per NPC
    // temperament + maturity.
    // ==========================================================

    /**
     * Config 'charisma' defaults (Serene's picks; the MDD gives the styles, not the counts):
     *   window       graded exchanges kept (the rolling window; 'none' grades count)
     *   min_samples  graded exchanges in the window before any style is read
     *   min_share    share (0..1) of the window one style needs to be the player's style; that
     *                share is its confidence (getCharismaContext's awareness needs 0.5)
     *   charmer_overuse_share     the Charmer graded in at least this share of the window is
     *                             overuse (MDD 5.1: "triggers Ick if overused"; charmerOverused)
     *   charmer_overuse_ick_mult  the Ick's threshold while he is overused (the Catalyst's cut for a
     *                             mature NPC, checkIckTrigger)
     *   charmer_friendzone_maturity_at_most, charmer_friendzone_passion_mult  MDD 5.2 "Low
     *                             Maturity ... Friendzones the Charmer": at or below this maturity
     *                             (the immature line of the tier gates) his passion multiplier is
     *                             this, whatever her temperament; affinity is untouched
     */
    const CHARISMA_DEFAULTS = ['window' => 10, 'min_samples' => 5, 'min_share' => 0.5,
        'charmer_overuse_share' => 0.7, 'charmer_overuse_ick_mult' => 0.7,
        'charmer_friendzone_maturity_at_most' => 40.0, 'charmer_friendzone_passion_mult' => 0.5];

    /** Source of a tracker fed by the eval's charisma grades; any other tracker starts over. */
    const CHARISMA_TRACKER_SOURCE = 'eval_charisma';

    /** Config 'charisma' over its defaults. */
    public static function charismaConfig(): array
    {
        $stored = self::configValue('charisma');
        return is_array($stored) ? array_replace(self::CHARISMA_DEFAULTS, $stored) : self::CHARISMA_DEFAULTS;
    }

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
     * Update the charisma style tracker with one exchange's grade.
     *
     * Fed once per applied eval item that carries charisma (applyEvalExtraFields): an exchange
     * nobody graded says nothing about the player's style. The window keeps the last
     * charisma.window grades ('none' included: ordinary talk dilutes a style); the style is
     * read again on every grade, and a window that reads as no style clears the old one (the
     * label follows the evidence, it does not stick).
     *
     * @param array      &$dynamics NPC dynamics
     * @param string     $grade     the eval's charisma grade (EVAL_CHARISMA_GRADES)
     * @param float|null $gamets    raw game time of the exchange (stamps a new detection)
     */
    public static function updateCharismaTracker(&$dynamics, string $grade, ?float $gamets = null): void
    {
        $cfg = self::getConfig();
        if (empty($cfg['charisma_detection_enabled'] ?? true)) {
            return;
        }
        $grade = strtolower(trim($grade));
        if (!in_array($grade, self::EVAL_CHARISMA_GRADES, true)) {
            error_log("[RelDyn-CHARISMA] charisma grade '{$grade}' is not one of " . implode('|', self::EVAL_CHARISMA_GRADES) . ', not counted');
            return;
        }

        // A tracker of the retired heuristic (romantic intent and affinity deltas) is not this
        // evidence: it starts over
        if (!is_array($dynamics['_charisma_tracker'] ?? null) || ($dynamics['_charisma_tracker']['source'] ?? null) !== self::CHARISMA_TRACKER_SOURCE) {
            $dynamics['_charisma_tracker'] = [
                'source' => self::CHARISMA_TRACKER_SOURCE,
                'recent_grades' => [],
                'detected_style' => null,
                'style_confidence' => 0.0,
                'style_detected_gamets' => 0.0,
            ];
        }

        $c = self::charismaConfig();
        $tracker = &$dynamics['_charisma_tracker'];
        $tracker['recent_grades'][] = $grade;
        while (count($tracker['recent_grades']) > max(1, intval($c['window']))) {
            array_shift($tracker['recent_grades']);
        }

        $detected = self::detectCharismaStyle($tracker['recent_grades'], $c);
        if ($detected !== null) {
            if ($detected['style'] !== ($tracker['detected_style'] ?? null)) {
                $tracker['style_detected_gamets'] = $gamets ?? self::currentGamets();   // raw game time
            }
            $tracker['detected_style'] = $detected['style'];
            $tracker['style_confidence'] = $detected['confidence'];
        } elseif (($tracker['detected_style'] ?? null) !== null) {
            $tracker['detected_style'] = null;
            $tracker['style_confidence'] = 0.0;
        }
        unset($tracker);
    }

    /**
     * The player's detected charisma style (rock / catalyst / charmer) with this NPC, or null:
     * only a tracker fed by the eval's charisma grades counts (updateCharismaTracker).
     */
    public static function charismaStyle(array $dynamics): ?string
    {
        $t = $dynamics['_charisma_tracker'] ?? null;
        if (!is_array($t) || ($t['source'] ?? null) !== self::CHARISMA_TRACKER_SOURCE) {
            return null;
        }
        $style = $t['detected_style'] ?? null;
        return is_string($style) && isset(self::CHARISMA_EFFECTIVENESS[$style]) ? $style : null;
    }

    /**
     * The Charmer overused (MDD 5.1): his detected style, graded in at least
     * charisma.charmer_overuse_share of the window (the tracker's confidence).
     */
    public static function charmerOverused(array $dynamics): bool
    {
        if (self::charismaStyle($dynamics) !== 'charmer') return false;
        return floatval($dynamics['_charisma_tracker']['style_confidence'] ?? 0.0)
            >= floatval(self::charismaConfig()['charmer_overuse_share']);
    }

    /**
     * The style a window of charisma grades reads as: the style graded most often, when the
     * window holds at least charisma.min_samples grades, that style is at least
     * charisma.min_share of them and no other style is graded as often (a tie is mixed).
     * 'none' grades count toward the window, never as a style. Pure.
     *
     * @param array      $grades charisma grades, oldest first (EVAL_CHARISMA_GRADES)
     * @param array|null $cfg    charismaConfig()
     * @return array|null ['style' => string, 'confidence' => its share of the window 0..1] or null
     */
    public static function detectCharismaStyle(array $grades, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::charismaConfig();
        $n = count($grades);
        if ($n === 0 || $n < max(1, intval($cfg['min_samples']))) {
            return null;
        }
        $counts = array_fill_keys(array_keys(self::CHARISMA_EFFECTIVENESS), 0);
        foreach ($grades as $g) {
            if (is_string($g) && isset($counts[$g])) {
                $counts[$g]++;
            }
        }
        arsort($counts);
        $styles = array_keys($counts);
        $top = $styles[0];
        if ($counts[$top] === 0 || (isset($styles[1]) && $counts[$styles[1]] === $counts[$top])) {
            return null;   // no approach at all, or mixed
        }
        $share = $counts[$top] / $n;
        if ($share < floatval($cfg['min_share'])) {
            return null;
        }
        return ['style' => $top, 'confidence' => round($share, 4)];
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
    public static function getCharismaEffectiveness($style, $temperament, $maturity, $dimensionId, ?array $dynamics = null)
    {
        if ($style === null || !isset(self::CHARISMA_EFFECTIVENESS[$style])) {
            return 1.0;
        }

        $profile = self::CHARISMA_EFFECTIVENESS[$style];
        // A24 through the trait engine: +1 effective .. -1 ineffective at the NPC's vector
        if (RelDynTraits::hasColumn("charisma_{$style}") && (RelDynTraits::isPreset($temperament) || RelDynTraits::readVector($dynamics) !== null)) {
            $effect = floatval(RelDynTraits::param($temperament, "charisma_{$style}", 0.0, $dynamics));
            $isEffective = $effect >= 0.5;
            $isIneffective = $effect <= -0.5;
        } else {
            $isEffective = in_array($temperament, $profile['effective'] ?? [], true);
            $isIneffective = in_array($temperament, $profile['ineffective'] ?? [], true);
        }

        // Catalyst special: ineffective against HIGH maturity regardless of temperament
        if ($style === 'catalyst' && $maturity > 60 && !$isEffective) {
            $isIneffective = true;
        }
        // MDD 5.1: the Rock is effective against Anxious and Overcast NPCs (internal weather)
        if ($style === 'rock' && is_array($dynamics) && ($dynamics['_internal_weather'] ?? null) === 'overcast') {
            $isEffective = true;
            $isIneffective = false;
        }

        // Charmer special (MDD 5.2): low maturity friendzones him; his passion barely moves her,
        // whatever her temperament (overuse is the Ick's: charmerOverused, checkIckTrigger)
        $c = self::charismaConfig();
        if ($style === 'charmer' && $dimensionId === 'passion' && floatval($maturity) <= floatval($c['charmer_friendzone_maturity_at_most'])) {
            return floatval($c['charmer_friendzone_passion_mult']);
        }

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
        $style = self::charismaStyle($dynamics);
        if ($style === null) {
            return null;
        }
        $tracker = $dynamics['_charisma_tracker'];

        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
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

    /** Config 'autonomy' defaults (defaultConfig documents the units). */
    const AUTONOMY_DEFAULTS = [
        'weights' => self::AUTONOMY_WEIGHTS,
        'thresholds' => self::AUTONOMY_THRESHOLDS,
        'denied_actions' => self::AUTONOMY_DENIED_ACTIONS,
    ];

    /** Config 'autonomy' over its defaults (a stored table replaces that table). */
    public static function autonomyConfig(): array
    {
        $stored = self::configValue('autonomy');
        return array_replace(self::AUTONOMY_DEFAULTS, is_array($stored) ? $stored : []);
    }

    /**
     * The action filter of autonomy-command-denial (ext/relationship_dynamics/functions.php):
     * core's functions/functions.php requires every ext functions.php after it loaded the
     * enabled action codes and before it drops the definitions of the codes that are not
     * enabled, so removing a code from ENABLED_FUNCTIONS here takes the action off this
     * request's list. The prerequest's evaluation (RELDYN_AUTONOMY_EVAL) is for the NPC it ran
     * for (RELDYN_AUTONOMY_NPC); a request that speaks as someone else by now keeps its list.
     * Returns the codes removed.
     */
    public static function applyAutonomyActionFilter(): array
    {
        $eval = $GLOBALS['RELDYN_AUTONOMY_EVAL'] ?? null;
        $npc = $GLOBALS['RELDYN_AUTONOMY_NPC'] ?? null;
        $enabled = $GLOBALS['ENABLED_FUNCTIONS'] ?? null;
        if (!is_array($eval) || !is_string($npc) || !is_array($enabled)) return [];
        $deny = array_values(array_filter((array) ($eval['deny_actions'] ?? []), 'is_string'));
        if ($deny === []) return [];
        $speaker = trim((string) ($GLOBALS['HERIKA_NAME'] ?? ''));
        if (strcasecmp($speaker, trim($npc)) !== 0) {
            self::log("[RelDyn-AUTONOMY] action filter for {$npc} not applied: this request speaks as '{$speaker}'");
            return [];
        }
        $denySet = array_change_key_case(array_flip($deny), CASE_LOWER);
        $kept = [];
        $removed = [];
        foreach ($enabled as $code) {
            if (is_string($code) && isset($denySet[strtolower($code)])) {
                $removed[] = $code;
            } else {
                $kept[] = $code;
            }
        }
        if ($removed !== []) {
            $GLOBALS['ENABLED_FUNCTIONS'] = $kept;
            self::log("[RelDyn-AUTONOMY] {$npc} ({$eval['state']}, " . ($eval['refusal_type'] ?? 'none') . '): actions taken off: ' . implode(', ', $removed));
        }
        return $removed;
    }

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
        $acfg = self::autonomyConfig();
        $w = array_replace(self::AUTONOMY_WEIGHTS, (array) $acfg['weights']);
        $score = (100 - $trust) * $w['distrust']
               + (100 - $respect) * $w['disrespect']
               + $resentment * $w['resentment']
               + ($selfConfidence / 100) * $w['self_confidence'] * 100
               + $maturityMod * $w['maturity_mod'] * 100;

        // Clamp to 0-100
        $score = max(0, min(100, $score));

        // Check for hoover reduction (post-hoover NPCs trigger walkaway sooner)
        $hooverCount = intval($dynamics['_hoover_count'] ?? 0);
        $effectiveThresholds = array_replace(self::AUTONOMY_THRESHOLDS, (array) $acfg['thresholds']);
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

        // swallowed: the override turned a refusal into compliance (what the people-pleaser
        // internalizes, RelDynResentment::peoplePleaserBuildup)
        $swallowed = false;
        if ($isPeoplePleaser && ($state === 'refusing' || $state === 'walkaway')) {
            $state = 'compliant';
            $swallowed = true;
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
        // MDD 6.5: affinity down to walkaway_affinity_at in a bond that existed -> walkaway
        if (self::affinityWalkawayDue($dynamics)) {
            $state = 'walkaway';
        }
        // resentment_self crisis (dimension design: above 90 the NPC seeks isolation, the autonomy
        // override self-triggered): a people-pleaser's silence ends here too
        if (RelDynResentment::selfCrisis($dynamics)) {
            $state = 'walkaway';
        }

        // A walkaway is due; it starts only when one can (walkawayHold: walkaway_enabled on, no
        // return grace). Held, the NPC is not leaving, and nobody tells the LLM she is: the
        // strongest state short of it (refusing; a people-pleaser swallows it)
        $walkawayDue = ($state === 'walkaway');
        $walkawayHeld = null;
        $walking = !empty($dynamics['_walkaway_state']) && $dynamics['_walkaway_state'] !== 'normal';
        if ($walkawayDue && !$walking) {
            $walkawayHeld = self::walkawayHold($dynamics);
            if ($walkawayHeld !== null) {
                $state = $isPeoplePleaser ? 'compliant' : 'refusing';
                $swallowed = $swallowed || $isPeoplePleaser;
            }
        }

        // If already in walkaway, stay in walkaway
        if ($walking) {
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
            'swallowed'       => $swallowed,
            'walkaway_due'    => $walkawayDue,     // a walkaway trigger holds (prerequest initiates it)
            'walkaway_held'   => $walkawayHeld,    // null | 'grace' | 'disabled' (walkawayHold)
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
        $attachment = self::getAttachmentStyle($dynamics);   // the style region (categorical choice)

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
        $lists = (array) self::autonomyConfig()['denied_actions'];
        return array_values(array_filter((array) ($lists[$state] ?? []), 'is_string'));
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

        // Back from a walkaway, inside the return grace: what the LLM hears is the return
        if (($eval['walkaway_held'] ?? null) === 'grace') {
            return "{$npcName} has returned because they chose to, not because they were summoned. "
                 . "The air is fragile. They are watching to see if things have really changed.";
        }

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
        $shame = ($dynamics['_walkaway_reason'] ?? self::walkawayReason($dynamics)) === 'shame';
        if ($shame && ($walkState === 'active' || $walkState === 'boundary_test')) {
            // Rulings §18 #7: approaching her is not pursuit; a gentle word can reach her
            return "{$npcName} has gone off alone, too ashamed to face anyone. "
                 . "Being found is hard, but a gentle word might reach them; pressing or blaming would not.";
        }
        if ($shame && $walkState !== 'permanent') {
            return "{$npcName} cannot bear to be seen right now and is pulling away to be alone with it. "
                 . "Pressing them will only drive them further.";
        }
        if ($walkState === 'permanent') {
            return "{$npcName} is done: cold, distant, answers only what must be answered. "
                 . "This bridge is burned.";
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
     * Why an NPC the autonomy evaluation sends away is leaving (prerequest's walkaway
     * initiation): 'ick_comfort' (ick with comfort below 20), 'resentment' (above 70),
     * 'jealousy' (MDD 6.5), else 'autonomy'. A resentment walkaway that starts on the player's
     * return from an absence whose neglect grew that resentment is 'neglect' (rulings
     * 2026-09-24 §8): its parting conversation is not pursuit (processWalkawayTick). Pure.
     */
    public static function walkawayReason(array $dynamics): string
    {
        $comfort = floatval($dynamics['dimensions']['comfort']['x'] ?? 50);         // 0..100
        $resentment = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);    // 0..100
        if (!empty($dynamics['_ick_tracker']['ick_active']) && $comfort < 20) {
            return 'ick_comfort';
        }
        if ($resentment > 70) {
            // The absence that grew it (counted from the contact before it) ended with this
            // request's contact: markContact moved that contact to _previous_contact_gamets.
            $since = $dynamics['_neglect_resentment_since_gamets'] ?? null;      // raw gamets
            $previous = $dynamics['_previous_contact_gamets'] ?? null;            // raw gamets
            $onReturn = is_numeric($since) && is_numeric($previous) && abs(floatval($since) - floatval($previous)) < 0.5;
            return $onReturn ? 'neglect' : 'resentment';
        }
        if (floatval($dynamics['jealousy_anger'] ?? 0) >= floatval(self::configValue('jealousy_walkaway_at'))) {
            return 'jealousy';   // MDD 6.5
        }
        if (self::affinityWalkawayDue($dynamics)) {
            return 'affinity';   // MDD 6.5
        }
        if (RelDynResentment::selfCrisis($dynamics)) {
            return 'shame';      // resentment_self crisis: the NPC isolates itself
        }
        return 'autonomy';
    }

    /**
     * Why a walkaway that is due cannot start now, or null when it can: 'disabled'
     * (walkaway_enabled off) or 'grace' (back from a resolved boundary test: the return grace
     * holds contacts, _walkaway_return_grace, and the contact that spent the last one,
     * _walkaway_grace_spent_contact = its _last_contact_gamets, is held too). Pure.
     */
    public static function walkawayHold(array $dynamics): ?string
    {
        if (!self::configValue('walkaway_enabled')) {
            return 'disabled';
        }
        if (intval($dynamics['_walkaway_return_grace'] ?? 0) > 0) {
            return 'grace';
        }
        $spent = $dynamics['_walkaway_grace_spent_contact'] ?? null;   // raw gamets
        $contact = $dynamics['_last_contact_gamets'] ?? null;         // raw gamets
        if (is_numeric($spent) && is_numeric($contact) && abs(floatval($spent) - floatval($contact)) < 1e-6) {
            return 'grace';
        }
        return null;
    }

    /**
     * MDD 6.5 affinity walkaway: core affinity (getCoreAffinity, -100..100) at or below
     * walkaway_affinity_at, in a bond that existed (context_tier_hwm >= walkaway_affinity_min_tier).
     * False while core's affinity was never read for this NPC. Pure.
     */
    public static function affinityWalkawayDue(array $dynamics): bool
    {
        if (!is_numeric($dynamics['_aff_mirror_x'] ?? null)) {
            return false;
        }
        return self::getCoreAffinity($dynamics) <= floatval(self::configValue('walkaway_affinity_at'))
            && intval($dynamics['context_tier_hwm'] ?? 0) >= intval(self::configValue('walkaway_affinity_min_tier'));
    }

    /**
     * A permanent walkaway (MDD 6.5 "the NPC permanently severs"): the hard reject_recruitment
     * flag (a permanent NPC also stays in the 'walkaway' autonomy state, which denies the
     * follow / recruit actions), and core's Player.type moved to its severed form
     * (walkaway_sever_types: romantic -> ex, platonic -> estranged ...) under core's lock. A
     * type not listed, or a refused write (relationships_locked, core changed it meanwhile), is
     * left as it is. Returns the core type written, or null.
     */
    public static function severBond(string $npcName, array &$dynamics): ?string
    {
        $dynamics['_reject_recruitment'] = true;
        $from = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $to = ((array) self::configValue('walkaway_sever_types'))[$from] ?? null;
        if (!is_string($to) || $to === '') {
            self::log("[WALKAWAY] {$npcName}: severed (reject_recruitment); core type '{$from}' left as it is");
            return null;
        }
        if (empty($GLOBALS['db'])) {
            error_log("[RelDyn-WALKAWAY] {$npcName}: severed (reject_recruitment); no database, core type {$from} not written");
            return null;
        }
        $written = self::changeCoreRelationshipType($npcName, $to,
            'permanent walkaway: followed during the boundary test, the bond is severed', $from);
        if ($written) {
            $dynamics['_core_rel_type'] = $to;
        }
        self::log("[WALKAWAY] {$npcName}: severed (reject_recruitment), core type {$from} -> " . ($written ? $to : "{$from} (write refused)"));
        return $written ? $to : null;
    }

    /**
     * Initiate walkaway sequence. Sets state to 'pending' (1 interaction grace).
     *
     * @param array  &$dynamics NPC dynamics (modified in place)
     * @param string $npcName   NPC name
     * @param string $reason    Why walkaway triggered (walkawayReason(): 'ick_comfort', 'resentment', 'neglect', 'jealousy', 'autonomy')
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
            // this contact is held to the end (walkawayHold), the next one may leave
            $dynamics['_walkaway_grace_spent_contact'] = $dynamics['_last_contact_gamets'] ?? null;
            self::log("[WALKAWAY] {$npcName} holds off leaving again ({$reason}); return grace left: " . ($grace - 1));
            return;
        }
        if (self::walkawayHold($dynamics) === 'grace') {
            return;   // the contact that spent the last grace
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
        // Leaving the bond is an attachment experience (decisions §12: walkaway raises the axes)
        self::attachmentExperience($dynamics, 'walkaway', self::currentGamets(), 1.0, (string) $npcName);

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

        // Pursuit (MDD 6.4 "follow them") is talking to them after they left. Rulings
        // 2026-09-24 §8 exempts the neglect walkaway only: the player's lines within
        // walkaway_parting_game_minutes game-calendar minutes of that departure are the return
        // greeting and the conversation it set off, not following them; those ticks carry on
        // like any other below. An NPC walking out of a fight (resentment, jealousy, the ick,
        // autonomy) is followed by the next line, as before.
        // Rulings 2026-09-25 §18 #7: an NPC who left in shame (a resentment_self crisis) is not
        // followed by being approached: she is not running from the player, and a gentle word is
        // what can reach her (RelDynResentment::onPositiveEval: the gentle approach). Never pursuit.
        $partingMinutes = floatval(self::configValue('walkaway_parting_game_minutes'));
        $sinceLeftMinutes = (self::gameHoursSince($dynamics, '_walkaway_activated_calendar_gamets') ?? 0.0) * 60.0;
        $shame = ($dynamics['_walkaway_reason'] ?? null) === 'shame';
        $parting = ($dynamics['_walkaway_reason'] ?? null) === 'neglect' && $sinceLeftMinutes < $partingMinutes;
        $isPursuit = $isDialogue && ($state === 'active' || $state === 'boundary_test') && !$parting && !$shame;
        if ($isDialogue && $shame && ($state === 'active' || $state === 'boundary_test')) {
            self::log("[WALKAWAY] {$npcName}: the player approached her after she left in shame: not pursuit");
            $result['shame_approach'] = true;
        } elseif ($isDialogue && !$isPursuit && ($state === 'active' || $state === 'boundary_test')) {
            self::log("[WALKAWAY] {$npcName}: player spoke " . round($sinceLeftMinutes, 1)
                . " game minutes after they left (parting window {$partingMinutes}): not pursuit");
            $result['parting'] = true;
        }

        if ($isPursuit) {
            // Player followed and engaged — resentment escalation
            if (empty($dynamics['_walkaway_player_followed'])) {
                // Once per walkaway: their need for space was not respected (decisions §12)
                self::attachmentExperience($dynamics, 'boundary_violated', self::currentGamets(), 1.0, (string) $npcName);
            }
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

        // Move to boundary test phase after activation. The test counts from when they left
        // (the activation stamp, fixed above if missing), not from this tick: a player who
        // stayed away the whole test is not caught by a first tick on their return.
        if ($state === 'active') {
            $dynamics['_walkaway_state'] = 'boundary_test';
            $left = $dynamics['_walkaway_activated_calendar_gamets'] ?? null;   // raw gamets
            if ($left !== null) {
                $dynamics['_boundary_test_started_calendar_gamets'] = $left;
            } else {
                self::markGameClock($dynamics, '_boundary_test_started_calendar_gamets');   // no game clock yet
            }
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
                // Left alone as they needed: a kept boundary (decisions §12)
                self::attachmentExperience($dynamics, 'boundary_kept', self::currentGamets(), 1.0, (string) $npcName);
                self::log("[WALKAWAY] {$npcName} entering recovery — boundary test resolved");
                $result['state'] = 'recovery';
                $result['changed'] = true;
            } elseif ($boundaryResult === 'permanent') {
                $dynamics['_walkaway_state'] = 'permanent';
                self::markGameClock($dynamics, '_walkaway_permanent_calendar_gamets');
                self::endDecayPause($dynamics);
                $result['severed_type'] = self::severBond((string) $npcName, $dynamics);
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
        // The feeling behind the walkaway: a shame walkaway (resentment_self crisis) is not over
        // because resentment toward the player is low
        if (($dynamics['_walkaway_reason'] ?? null) === 'shame') {
            $resentment = floatval($dims['resentment_self']['x'] ?? 0);
        }

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
        return self::getAttachmentStyle($dynamics) === 'toxic'   // the fearful region (MDD 6.6)
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

        // Must be in the fearful (Toxic/Disorganized) region with maturity below the cap
        if (self::getAttachmentStyle($dynamics) !== 'toxic') {
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
                // The snap up is a passion gain: x the attraction factor (decisions §13), so a
                // hard zero leaves passion where it was; never past the target or passion_max
                $current = self::getPassion($dynamics);
                $to = $target;
                if ($target > $current) {
                    $max = floatval(self::getConfig()['passion_max'] ?? 100.0);
                    $raw = floatval($target) - $current;
                    $to = min(floatval($target), $max, $current + $raw * self::attractionPassionFactor((string) $npcName, $dynamics, $raw, 'hoover'));
                }
                self::setPassion($dynamics, $to);
                $results[$dim] = ['from' => $current, 'to' => self::getPassion($dynamics)];
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

        return "{$npcName} is back and acting as if nothing happened: {$charm}. "
             . "The old grievances sit under the charm, unspoken, and flare faster than before.";
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

        // save-load-rollback: a copy read before a load (or before its reconcile) never writes
        // its mirror drift or pending delta into core's restored Player.aff
        $stale = self::staleCopy(is_string($dynamics[self::LOAD_TOKEN_KEY] ?? null) ? $dynamics[self::LOAD_TOKEN_KEY] : null);
        if ($stale['reason'] !== null) {
            error_log("[RelDyn-AFF] commitPlayerAffinity for {$npcName}: {$stale['reason']}; nothing is pushed into core");
            return null;
        }

        // Widow's lock (grief): no gain carries Player.aff past the ceiling (core points), whatever
        // RelDyn path queued it; a bond already above it is never lowered by the lock
        $ceiling = floatval($dynamics['_widow_lock_ceiling'] ?? 100);
        $result = self::applyPlayerAffinityDelta($npcName, $whole, $ceiling < self::CORE_AFFINITY_MAX ? (int) floor($ceiling) : null);
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
     * $gainCeiling (core points, the widow's lock): a gain stops there, read under the lock
     * against the value core holds; a value already above it is kept, never lowered.
     *
     * @return array|null ['old' => int, 'new' => int, 'delta' => int] (plus 'locked' => true
     *                    when skipped for the editor lock) or null on failure
     */
    public static function applyPlayerAffinityDelta($npcName, $delta, ?int $gainCeiling = null)
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
            if ($delta > 0 && $gainCeiling !== null && $newAff > $gainCeiling) {
                $newAff = max($oldAff, $gainCeiling);
                self::log("[AFF] {$npcName} -> Player " . sprintf('%+d', $delta) . ": the widow's lock holds it at {$newAff}");
            }

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


    /**
     * Shared contract (fulfillment lane): set core's relationships.Player.type for this NPC,
     * the one relationship type everything reads (getRelationshipType). Used for step-backs
     * (the mature boundary) and romance promotion.
     *
     * Same discipline as applyPlayerAffinityDelta(): one transaction holding
     * pg_advisory_xact_lock(1001000000 + npc id), core's own per-NPC relationship lock; only
     * relationships.Player.type is written (the whole relationships object only when the Player
     * entry is missing or a legacy real-name entry must be folded in); nothing is written when
     * extended_data.relationships_locked is set (editor lock); core's timeline stamp runs after
     * a write. $newType must be one of core's types (RelationshipManager::TYPES or an alias).
     * $expectedFrom (optional, compare-and-set): write only while core still holds that type (a
     * string) or one of those types (a list: the romance ladder's step), so a decision made on
     * an older snapshot never overrides a type core changed meanwhile.
     * After a write, RelDyn's record of it (plugin_extended_data.reldyn core_type_change: from,
     * to, reason, gamets, direction) for the romance ladder, which reads a step-back from it.
     *
     * @return bool true when core holds $newType afterwards (already did: no write), false when
     *              refused (unknown type, editor lock, type changed meanwhile, no row) or failed
     */
    public static function changeCoreRelationshipType(string $npcName, string $newType, string $reason, string|array|null $expectedFrom = null): bool
    {
        self::loadRelationshipManager();
        $type = strtolower(trim($newType));
        $type = RelationshipManager::TYPE_ALIASES[$type] ?? $type;
        if (!in_array($type, RelationshipManager::TYPES, true)) {
            error_log("[RelDyn-TYPE] {$npcName}: '{$newType}' is not a core relationship type; nothing written ({$reason})");
            return false;
        }
        $db = $GLOBALS['db'] ?? null;
        $npcId = intval(RelDynStorage::resolveNpcId($npcName) ?? 0);
        if (!$db || $npcId <= 0) {
            error_log("[RelDyn-TYPE] Cannot set {$npcName} -> Player type {$type}: no core_npc_master row");
            return false;
        }
        $lockId = self::CORE_RELATIONSHIP_LOCK_BASE + $npcId;

        try {
            if ($db->execQuery("BEGIN") === false) {
                error_log("[RelDyn-TYPE] BEGIN failed for {$npcName}");
                return false;
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
            $oldType = strtolower(trim((string) ($playerRel['type'] ?? 'neutral')));

            $refusal = null;
            if (!empty($extended['relationships_locked'])) {
                $refusal = 'relationships_locked (manual edits protected)';
            } elseif ($expectedFrom !== null) {
                $expected = array_map(fn($t) => strtolower(trim((string) $t)), (array) $expectedFrom);
                if (!in_array($oldType, $expected, true)) {
                    $refusal = "core type is '{$oldType}', not the expected '" . implode('/', $expected) . "'";
                }
            }
            if ($refusal !== null || $oldType === $type) {
                if ($db->execQuery("COMMIT") === false) {
                    throw new RuntimeException("COMMIT failed");
                }
                if ($refusal !== null) {
                    error_log("[RelDyn-TYPE] SKIP {$npcName} -> Player type {$oldType} -> {$type}: {$refusal} ({$reason})");
                    return false;
                }
                return true;
            }

            $hasLegacyKey = false;
            foreach (array_keys(is_array($rawRels) ? $rawRels : []) as $target) {
                if ($target !== self::PLAYER_RELATIONSHIP_KEY && self::isPlayerRelationshipKey($target)) {
                    $hasLegacyKey = true;
                    break;
                }
            }
            if (!$hasLegacyKey && is_array($rawRels) && isset($rawRels[self::PLAYER_RELATIONSHIP_KEY]) && is_array($rawRels[self::PLAYER_RELATIONSHIP_KEY])) {
                $path = '{relationships,' . self::PLAYER_RELATIONSHIP_KEY . ',type}';
                $value = json_encode($type);
            } else {
                $playerRel['type'] = $type;
                $rels[self::PLAYER_RELATIONSHIP_KEY] = $playerRel;
                $path = '{relationships}';
                $value = json_encode((object) $rels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $valueEscaped = $db->escape($value);
            $updated = $db->execQuery("UPDATE core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{$path}', '{$valueEscaped}'::jsonb, true) WHERE id = {$npcId}");
            if ($updated === false) {
                throw new RuntimeException("type UPDATE failed");
            }
            if ($db->execQuery("COMMIT") === false) {
                throw new RuntimeException("COMMIT failed");
            }
        } catch (\Throwable $e) {
            $db->execQuery("ROLLBACK");
            error_log("[RelDyn-TYPE] {$npcName} -> Player type {$type} rolled back: " . $e->getMessage());
            return false;
        }

        // Same game-timeline snapshot core writes after relationship changes
        if (function_exists('chimRelationshipTimelineStamp')) {
            chimRelationshipTimelineStamp($npcId);
        }
        $direction = RelDynRomance::direction($oldType, $type);
        $record = ['from' => $oldType, 'to' => $type, 'reason' => substr($reason, 0, 200),
                   'gamets' => self::currentGamets(), 'direction' => $direction];
        if (!RelDynStorage::setKey($npcId, RelDynRomance::STORAGE_KEY_TYPE_CHANGE, $record)) {
            error_log("[RelDyn-TYPE] ERROR {$npcName}: core type changed but RelDyn's record of it was not stored");
        }
        error_log("[RelDyn-TYPE] {$npcName} -> Player: type {$oldType} -> {$type} ({$reason}) [{$direction}]");
        return true;
    }

    // ========== END CORE AFFINITY BRIDGE (CHIM 3.4.1) ==========

    /**
     * Jev's explicit state block (decisions 2026-09-23 §3: the one exception to felt steering).
     * Jev picks actions ("if I feel this and my goal is that, then I do x"), so it gets the
     * concrete values for $npcName toward the player: affinity (core units), dimensions, passion,
     * jealousy, resentment, attachment, temperament, weather, open conflict, boundary and
     * walkaway state, fulfillment band, attraction curve, spark and hard zero, place appraisal, goal, and
     * a compact text rendering. See RelDynJev::state for the fields and units. Read-only.
     */
    public static function jevStateBlock(string $npcName): array
    {
        return RelDynJev::state($npcName, self::getDynamics($npcName), self::currentGamets());
    }

}

// Facets -> appraisal -> feeling (decisions 2026-09-23 §6); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_facets.php';
// Player profile (player-stats-pipeline); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_player.php';
// Attraction Matrix (MDD §2, decisions §9); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_attraction.php';
// Fulfillment coverage and the mature boundary (rulings 2026-09-24 §9); defaults in defaultConfig().
require_once __DIR__ . '/reldyn_fulfillment.php';
// Intimacy need per NPC, physical / emotional (rulings 2026-09-24 §10); defaults in defaultConfig().
require_once __DIR__ . '/reldyn_intimacy.php';
// Romance promotion + Sharmat handoff (rulings 2026-09-24 §9); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_romance.php';
// Save-load consistency (roadmap save-load-rollback); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_timeline.php';
// Felt steering: the LLM-bound context (decisions 2026-09-23 §3); its defaults are part of defaultConfig().
require_once __DIR__ . '/reldyn_felt.php';
// Jev's explicit state block (the §3 exception): numbers, for the action picker.
require_once __DIR__ . '/reldyn_jev.php';
// Protective concern and the values path of both channels (traits design §1); defaults in defaultConfig().
require_once __DIR__ . '/reldyn_concern.php';
// Resentment threshold events: the MDD 15.5 confrontation, resentment_self, guilt bleed; defaults in defaultConfig().
require_once __DIR__ . '/reldyn_resentment.php';
// Creature moodifications (vampires, werewolves; Skyrim's moon cycle)
require_once __DIR__ . '/reldyn_creatures.php';
// Combat passion routing (core combat requests + core's death / bleedout eventlog rows)
require_once __DIR__ . '/reldyn_combat.php';
// Tiered governors: the relationship tier's passion floor and ceiling (MDD 8)
require_once __DIR__ . '/reldyn_governors.php';
// Natural exclusivity: the pull toward the player, NPC-NPC deflection, damped interest in suitors (decisions §17)
require_once __DIR__ . '/reldyn_exclusivity.php';

// Quests: the duty override and the quest event hook (duty-override, quest-event-hook)
require_once __DIR__ . '/reldyn_quests.php';

// Intrinsic goals, tier 1 (MDD 14.2; intrinsic-goals)
require_once __DIR__ . '/reldyn_goals.php';

// Reputation: the pre-contact baseline (reputation-layer)
require_once __DIR__ . '/reldyn_reputation.php';

// Prompt gating: who knows the player (prompt-gating-*; the CHIM fork hook registers in player_knowledge.php)
require_once __DIR__ . '/reldyn_gating.php';

// Self-reflection on core's diary entries (baseline math / trajectory LLM); defaults in defaultConfig().
require_once __DIR__ . '/reldyn_diary.php';
// Divine Intervention, grief / widow's lock, the Ick's tuning, the Parasite (P3 protocols)
require_once __DIR__ . '/reldyn_protocols.php';
