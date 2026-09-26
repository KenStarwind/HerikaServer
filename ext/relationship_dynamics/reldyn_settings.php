<?php
/**
 * Relationship Dynamics — the settings hub's engine (roadmap settings-page, prompt-gating-admin).
 *
 * The form is generated from RelationshipDynamics::defaultConfig(): every key RelDyn has, grouped
 * by subsystem, a key added to the defaults appears on its own (in its prefix's group, or "Other").
 * Each field shows its default and whether the stored row overrides it; a key resets on its own.
 *
 * Storage (the one config row, conf_opts relationship_dynamics_config, written by
 * RelationshipDynamics::saveConfig with its schema stamp): the row holds only what differs from the
 * defaults, so a default changed in a later RelDyn reaches every key the player never touched
 * (decisions 2026-09-23 §3: missing keys come from the current defaults). How far down a section may
 * be stored in part depends on how its reader merges it: a node listed in keysNodes() is laid over its
 * defaults key by key by its reader (array_replace), so only the children that differ are stored; a
 * node under a deepNodes() entry is merged recursively (array_replace_recursive); anything else is
 * a table its reader takes whole, so it is stored whole once anything in it differs. Every listed node
 * sits under a section whose reader is in readers(), and RelDynSettingsHubTest proves, for every
 * leaf, that the reader sees the same config from the partial row as from the whole section.
 *
 * Display: what RelDyn reads (the section's reader), with "changed" when the stored row differs from
 * the default at that key. Security: POST only, the core pages' CSRF pattern (a session token checked
 * with hash_equals, ui/playthrough_manager.php), every value escaped on output, SQL read-only here
 * (the NPC list) and the one write through saveConfig's parameterized upsert.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynSettings
{
    /** Session key of the CSRF token (core's pages keep theirs the same way). */
    const CSRF_KEY = 'reldyn_csrf';

    /**
     * Inputs per form. PHP drops POST variables past max_input_vars (default 1000) without an error,
     * so a large section is split into several forms, each ending in a '_complete' sentinel.
     */
    const MAX_FORM_VARS = 600;

    /** Subsystem groups: id => [label, what it covers]. 'features' collects every on/off switch. */
    const GROUPS = [
        'features'    => ['Feature switches', 'Every on/off switch in one place. Features RelDyn ships switched off are marked.'],
        'general'     => ['General', 'The master switch, logging and the dimension engine.'],
        'passion'     => ['Passion, warmth & stages', 'Passion gain and decay, the floor and spike, reunion, stages, warmth.'],
        'conflict'    => ['Jealousy, conflict & resentment', 'Jealousy, conflict and repair, grievances, the resentment arc, power gaps.'],
        'steering'    => ['Felt steering & dimensions', 'What reaches the prompt as feeling, the eval consumer, per-bond display, mood axes.'],
        'attraction'  => ['Attraction & romance', 'The Attraction Matrix, charisma, the ick, romance promotion, governors, exclusivity, intimacy.'],
        'personality' => ['Personality & attachment', 'Traits and the bio reader, temperament tables, attachment on two axes.'],
        'absence'     => ['Absence, neglect & walkaway', 'Neglect, fades while away, bond break, affinity rot, autonomy, walkaway, the calendar scan.'],
        'player'      => ['Player, reputation & memory', 'The player profile and mirror, reputation, prompt gating, memory translation.'],
        'world'       => ['Places, things & the world', 'Facets and preferences, places, items and gifts, creatures, combat, drink, quests.'],
        'inner'       => ['Needs, goals & protocols', 'Fulfillment, concern, impulse, goals, diary reflection, the P3 protocols, masking.'],
        'system'      => ['System', 'Core poll and save-load handling.'],
        'other'       => ['Other', 'Keys no group claims yet (new ones land here until they are grouped).'],
    ];

    /** Top-level key => group (keys a prefix rule would misplace, and every section). */
    const GROUP_KEYS = [
        'enabled' => 'general', 'log_enabled' => 'general', 'context_pre_enabled' => 'steering',
        'felt_steering' => 'steering', 'per_bond_display' => 'steering', 'self_confidence' => 'steering',
        'eval_significance_clamp' => 'steering', 'affinity_modifier_min' => 'steering', 'affinity_modifier_max' => 'steering',
        'affinity_modifiers' => 'steering', 'affinity_tag_love_language' => 'steering', 'significance_scaling_enabled' => 'steering',
        'baseline_drift' => 'steering', 'baseline_drift_enabled' => 'steering', 'mood_axes' => 'steering',
        'bleedout_response' => 'steering', 'emergent_emotions_enabled' => 'steering', 'internal_weather_enabled' => 'steering',
        'base_passion_gain' => 'passion', 'decay_max_hours' => 'passion', 'ambient_enabled' => 'passion',
        'topic_bonus_enabled' => 'passion', 'flirt_bonus_enabled' => 'passion', 'passion_dynamics' => 'passion',
        'conflict_repair_passion_burst' => 'passion', 'conflict_repair_passion_mult' => 'passion',
        'preference_jealousy_mult' => 'conflict', 'grievance_resentment_raw' => 'conflict', 'grievance_severity_mult' => 'conflict',
        'resentment_positive_decay' => 'conflict', 'resentment_arc' => 'conflict', 'fester_resentment_per_game_day' => 'conflict',
        'fester_maturity_below' => 'conflict',
        'type_filter_enabled' => 'attraction', 'attraction' => 'attraction', 'romance_promotion' => 'attraction',
        'governors' => 'attraction', 'exclusivity' => 'attraction', 'charisma' => 'attraction',
        'charisma_detection_enabled' => 'attraction', 'ick_system_enabled' => 'attraction', 'ick_base_threshold' => 'attraction',
        'intimacy_need' => 'attraction', 'post_intimacy' => 'attraction',
        'temperament_autogen' => 'personality', 'traits' => 'personality', 'trait_reader' => 'personality',
        'attachment' => 'personality', 'attachment_style_enabled' => 'personality',
        'warmth_absence_grace_game_hours' => 'absence', 'warmth_absence_fade_per_game_day' => 'absence',
        'bond_break' => 'absence', 'affinity_rot' => 'absence', 'autonomy' => 'absence', 'autonomy_enabled' => 'absence',
        'hoover_enabled' => 'absence', 'calendar_scan_interval_game_hours' => 'absence', 'calendar_scan_max_npcs' => 'absence',
        'player_profile' => 'player', 'player_mirror' => 'player', 'reputation' => 'player', 'prompt_gating' => 'player',
        'memory_translation' => 'player',
        'facet_preferences' => 'world', 'facet_appraisal' => 'world', 'place_facets' => 'world', 'facet_classifier' => 'world',
        'thing_appraisal' => 'world', 'physical_states' => 'world', 'item_modifiers' => 'world', 'creatures' => 'world',
        'creature_moodifications_enabled' => 'world', 'combat' => 'world', 'combat_enabled' => 'world', 'substances' => 'world',
        'gift_delta' => 'world', 'quests' => 'world', 'duty_override_enabled' => 'world', 'cascade_network_enabled' => 'world',
        'cascade_threshold' => 'world', 'cascade_decay' => 'world',
        'fulfillment' => 'inner', 'concern' => 'inner', 'impulse' => 'inner', 'intrinsic_goals' => 'inner',
        'director_goals' => 'inner', 'director_goals_enabled' => 'inner', 'diary_reflection' => 'inner',
        'diary_reflection_mode' => 'inner', 'autonomous_diary_enabled' => 'inner', 'diary_interaction_gap' => 'inner',
        'protocols' => 'inner', 'divine_intervention_enabled' => 'inner', 'grief_system_enabled' => 'inner',
        'parasite_detection_enabled' => 'inner', 'social_masking' => 'inner', 'social_masking_enabled' => 'inner',
        'mask_maturity_cost' => 'inner', 'social_sensitivity_enabled' => 'steering', 'social_sensitivity_signals' => 'steering',
        'poll' => 'system', 'save_load' => 'system',
    ];

    /** Prefix => group for top-level keys GROUP_KEYS does not name (first match wins). */
    const GROUP_PREFIXES = [
        'dimension_' => 'general', 'passion_' => 'passion', 'reunion_' => 'passion', 'stage_' => 'passion',
        'jealousy_' => 'conflict', 'conflict_' => 'conflict', 'power_gap_' => 'conflict',
        'attraction_' => 'attraction', 'neglect_' => 'absence', 'walkaway_' => 'absence',
        'environment_' => 'world', 'director_' => 'inner', 'diary_' => 'inner',
    ];

    /** Choices for text keys that take one of a few values (dotted path => options; '@tiers' = RelDyn tiers). */
    const ENUMS = [
        'diary_reflection_mode' => ['baseline', 'trajectory'],
        'felt_steering.position' => ['append', 'prepend'],
        'save_load.relationship_state' => ['core', 'follow', 'keep'],
        'player_mirror.mode' => ['mirror', 'character'],
        'traits.assignment' => ['read', 'label'],
        'prompt_gating.name_min_tier' => '@tiers',
        'prompt_gating.floor_tier' => '@tiers',
        'prompt_gating.bio_min_tier' => '@tiers',
        'prompt_gating.fame_max_tier' => '@tiers',
    ];

    /** Labels and hints for the keys the old settings page described (dotted path => [label, hint]). */
    const LABELS = [
        'enabled' => ['System enabled', 'Master switch: off, RelDyn does nothing.'],
        'log_enabled' => ['Debug logging', 'Errors are always logged.'],
        'passion_enabled' => ['Passion (RPM)', 'Passion accumulation from interactions.'],
        'ambient_enabled' => ['Ambient / places', 'Passive warmth in places the NPC likes.'],
        'combat_enabled' => ['Combat events', 'Combat as acts of service, shared danger, falls.'],
        'jealousy_enabled' => ['Jealousy', 'Jealousy from rivals while a committed NPC is near.'],
        'reunion_enabled' => ['Reunion spike', 'Passion spike on reuniting after time apart.'],
        'conflict_enabled' => ['Conflict / repair', 'Conflict from affinity drops or jealousy; repair from kindness.'],
        'topic_bonus_enabled' => ['Topic talk bonus', 'Bonus passion when the talk matches her interests.'],
        'flirt_bonus_enabled' => ['Flirt in context', 'Stacking bonus when a flirt fits the place or the topic.'],
        'type_filter_enabled' => ['Relationship type filter', 'Her relationship preference gates the types open to the player.'],
        'dimension_engine_enabled' => ['Dimension engine', 'The XYZ dimension framework (trust, comfort, respect, maturity, resentment...).'],
        'dimension_context_enabled' => ['Dimension context', 'Dimension state reaches the prompt as felt keywords, never numbers.'],
        'dimension_debug_logging' => ['Dimension debug logging', 'Verbose applyDelta / band logging.'],
        'divine_intervention_enabled' => ['Divine intervention', 'Corrective events when a relationship stagnates or spirals.'],
        'grief_system_enabled' => ['Grief', 'Deaths leave grief; the widow\'s lock caps new affinity.'],
        'attachment_style_enabled' => ['Attachment styles', 'Attachment axes modify decay, resentment and absence.'],
        'attraction_matrix_enabled' => ['Attraction Matrix', 'Her attraction lens gates romance and scales passion.'],
        'attraction_eval_interval' => ['Attraction re-evaluation (interactions)', 'How often the attraction matrix is recalculated.'],
        'cascade_network_enabled' => ['Cascading affinity', 'Actions ripple to NPCs bonded to her.'],
        'duty_override_enabled' => ['Duty override', 'Quest dialogue dampens negative deltas during obligations.'],
        'parasite_detection_enabled' => ['Parasite detection', 'She notices a gift-only relationship.'],
        'significance_scaling_enabled' => ['Significance scaling', 'Deltas scale with how much an exchange mattered.'],
        'baseline_drift_enabled' => ['Baseline drift', 'Sustained behaviour moves the rubber band\'s centre.'],
        'internal_weather_enabled' => ['Internal weather', 'Unmet interests darken her mood (clear / overcast / stormy).'],
        'creature_moodifications_enabled' => ['Vampire / werewolf moods', 'Night and moon modifiers for creature NPCs.'],
        'emergent_emotions_enabled' => ['Emergent emotions', 'Complex states read from dimension combinations.'],
        'social_masking_enabled' => ['Social masking', 'Performed vs true state in front of an untrusted audience. Ships off until a playtest (rulings §18 #9).'],
        'autonomous_diary_enabled' => ['Autonomous diary', 'Her own reflection on core\'s diary.'],
        'social_sensitivity_enabled' => ['Social sensitivity', 'How much the player\'s words land, by bond depth.'],
        'ick_system_enabled' => ['Ick tracker', 'Pushing romance on an unreceptive NPC backfires.'],
        'charisma_detection_enabled' => ['Charisma detection', 'The player\'s style (rock / catalyst / charmer) by her personality.'],
        'autonomy_enabled' => ['Autonomy override', 'Personality-gated refusal of commands.'],
        'walkaway_enabled' => ['Walkaway', 'She leaves; a boundary test decides whether she returns.'],
        'hoover_enabled' => ['Hoover', 'Toxic NPCs return after a walkaway with a charm offensive.'],
        'director_goals_enabled' => ['Director-assigned goals', 'The Director / Background Life may assign NPCs contextual goals.'],
        'context_pre_enabled' => ['Knowledge & emotional core in <character>', 'Off: the <subtext> block carries everything.'],
        'neglect_enabled' => ['Neglect', 'Absence of fulfillment and contact builds resentment, per NPC.'],
        'environment_modifiers_enabled' => ['Environment modifiers', 'What a place and the hour do to anyone.'],
        'base_passion_gain' => ['Base passion gain', 'Passion points per interaction before multipliers.'],
        'passion_max' => ['Passion maximum', 'Hard cap (passion points).'],
        'decay_max_hours' => ['Between-session decay (hours)', '0 = passion frozen while offline.'],
        'jealousy_max' => ['Jealousy maximum', 'Hard cap (jealousy points).'],
        'jealousy_decay_per_hour' => ['Jealousy decay per hour', 'Anger fades slower than joy.'],
        'conflict_threshold_affinity_drop' => ['Affinity drop that opens a conflict', 'Core affinity points lost in one session.'],
        'conflict_threshold_jealousy' => ['Jealousy that opens a conflict', 'Jealousy points.'],
        'conflict_resolution_positive_count' => ['Kind acts to resolve a conflict', 'Positive interactions.'],
        'conflict_repair_passion_burst' => ['Repair passion burst', 'Passion gained when a conflict resolves.'],
        'conflict_repair_passion_mult' => ['Repair passion multiplier', 'Bonus on positive acts during a conflict.'],
        'reunion_min_hours' => ['Reunion: hours apart', 'Game-calendar hours apart before a reunion fires.'],
        'reunion_min_affection' => ['Reunion: minimum affinity', 'Core affinity, -100..100.'],
        'stage_established_threshold' => ['Established stage at', 'Positive interactions.'],
        'stage_deep_threshold' => ['Deep stage at', 'Positive interactions.'],
        'diary_interaction_gap' => ['Diary interaction gap', 'Interactions between reflections.'],
        'mask_maturity_cost' => ['Masking maturity cost', 'Maturity points per masked interaction.'],
        'ick_base_threshold' => ['Ick base threshold', 'Share of romantic attempts in the window that triggers the ick.'],
        'diary_reflection_mode' => ['Diary reflection mode', 'baseline: snapshot math, no LLM. trajectory: one LLM call reads her recent diary.'],
        'prompt_gating.enabled' => ['Prompt gating', 'Who knows the player: strangers do not know the name or story.'],
        'reputation.enabled' => ['Reputation layer (fame axis)', 'What the player is known for, heard by hold.'],
    ];

    /** Per process: the defaults without the stamp, and the fields generated from them (code-defined). */
    private static $defaultsCache = null;
    private static $fieldCache = null;

    // =====================================================================
    // SCHEMA: sections, readers, merge depth
    // =====================================================================

    /** The defaults the form is generated from (the row stamp is not a setting). */
    public static function defaults(): array
    {
        if (self::$defaultsCache === null) {
            $d = RelationshipDynamics::defaultConfig();
            unset($d['config_schema']);
            self::$defaultsCache = $d;
        }
        return self::$defaultsCache;
    }

    /** Section => callable returning the section as RelDyn reads it (stored row over its defaults). */
    public static function readers(): array
    {
        return [
            'felt_steering'      => [RelDynFelt::class, 'config'],
            'attraction'         => [RelDynAttraction::class, 'config'],
            'quests'             => [RelDynQuests::class, 'config'],
            'intrinsic_goals'    => [RelDynGoals::class, 'config'],
            'reputation'         => [RelDynReputation::class, 'config'],
            'memory_translation' => [RelDynMemory::class, 'config'],
            'player_mirror'      => [RelDynMirror::class, 'config'],
            'prompt_gating'      => [RelDynGating::class, 'config'],
            'item_modifiers'     => [RelationshipDynamics::class, 'itemModifierConfig'],
            'baseline_drift'     => [RelationshipDynamics::class, 'baselineDriftConfig'],
            'per_bond_display'   => [RelationshipDynamics::class, 'perBondDisplayConfig'],
            'social_masking'     => [RelationshipDynamics::class, 'maskingConfig'],
            'charisma'           => [RelationshipDynamics::class, 'charismaConfig'],
            'autonomy'           => [RelationshipDynamics::class, 'autonomyConfig'],
            'director_goals'     => [RelationshipDynamics::class, 'directorGoalConfig'],
            'temperament_autogen' => [RelationshipDynamics::class, 'getTemperamentAutogenConfig'],
            'traits'             => fn() => ['assignment' => RelDynTraits::assignment(), 'residual_reach' => RelDynTraits::residualReach()],
            'trait_reader'       => [RelDynTraitRead::class, 'config'],
            'attachment'         => [RelationshipDynamics::class, 'getAttachmentConfig'],
            'facet_preferences'  => [RelDynFacets::class, 'getPreferenceConfig'],
            'facet_appraisal'    => [RelDynFacets::class, 'getAppraisalConfig'],
            'passion_dynamics'   => [RelDynPassion::class, 'config'],
            'neglect_severity'   => [RelationshipDynamics::class, 'getNeglectSeverityConfig'],
            'bond_break'         => [RelDynAbsence::class, 'breakConfig'],
            'affinity_rot'       => [RelDynAbsence::class, 'rotConfig'],
            'place_facets'       => [RelDynFacets::class, 'placeFacetConfig'],
            'facet_classifier'   => [RelDynFacetClassifier::class, 'config'],
            'thing_appraisal'    => [RelDynFacetClassifier::class, 'appraisalConfig'],
            'player_profile'     => [RelDynPlayer::class, 'config'],
            'fulfillment'        => [RelDynFulfillment::class, 'config'],
            'concern'            => [RelDynConcern::class, 'config'],
            'resentment_arc'     => [RelDynResentment::class, 'config'],
            'intimacy_need'      => [RelDynIntimacy::class, 'config'],
            'impulse'            => [RelDynImpulse::class, 'config'],
            'creatures'          => [RelDynCreatures::class, 'config'],
            'combat'             => [RelDynCombat::class, 'config'],
            'governors'          => [RelDynGovernors::class, 'config'],
            'exclusivity'        => [RelDynExclusivity::class, 'config'],
            'romance_promotion'  => [RelDynRomance::class, 'config'],
            'save_load'          => [RelDynTimeline::class, 'config'],
            'diary_reflection'   => [RelDynDiary::class, 'config'],
            'protocols'          => [RelDynProtocols::class, 'config'],
            'substances'         => [RelDynSubstances::class, 'config'],
            'mood_axes'          => [RelDynMoodAxes::class, 'config'],
            'post_intimacy'      => [RelDynPostIntimacy::class, 'config'],
            'gift_delta'         => [RelDynGifts::class, 'config'],
        ];
    }

    /**
     * Nodes (dotted paths) whose reader lays the stored keys over the defaults one by one, so only
     * the children that differ are stored. Every section in readers() is one (their readers all start
     * with array_replace); the deeper entries are the merges those readers do inside. 'x.*' = every
     * table directly under x.
     */
    public static function keysNodes(): array
    {
        $nodes = array_keys(self::readers());
        return array_merge($nodes, [
            'memory_translation.translation', 'memory_translation.commit', 'memory_translation.anchors',
            'memory_translation.anchors.revisit', 'memory_translation.translation.salience',
            'player_mirror.signal_full', 'player_mirror.evidence', 'player_mirror.validation', 'player_mirror.charisma',
            'player_mirror.attachment', 'player_mirror.love_language', 'player_mirror.history', 'player_mirror.bands',
            'player_mirror.labels', 'player_mirror.reputation', 'player_mirror.prompt', 'player_mirror.attachment.votes',
            'player_mirror.prompt.min_tier',
            'per_bond_display.type_curve_exponent',
            'passion_dynamics.spike', 'passion_dynamics.desire',
            'bond_break.felt_text',
            'concern.felt_text', 'concern.kind_phrases', 'concern.cue_phrases', 'concern.style_phrases', 'concern.salience', 'concern.risk',
            'resentment_arc.confrontation', 'resentment_arc.self', 'resentment_arc.guilt_bleed', 'resentment_arc.felt_text',
            'resentment_arc.fuel_phrases',
            'impulse.threshold', 'impulse.romantic', 'impulse.protective', 'impulse.social', 'impulse.survival', 'impulse.curiosity',
            'impulse.resolution', 'impulse.dignity', 'impulse.motivation', 'impulse.preset_style', 'impulse.strength',
            'impulse.salience', 'impulse.urge', 'impulse.style_text', 'impulse.strength_text', 'impulse.motive_text',
            'impulse.resolution_text', 'impulse.default_traits',
            'diary_reflection.strength_scale', 'diary_reflection.max_strength', 'diary_reflection.depth_text', 'diary_reflection.moment_text',
            'protocols.*',
            'substances.drunk', 'substances.sober', 'substances.addiction',
            'facet_classifier.embedding', 'facet_classifier.build',
        ]);
    }

    /** Nodes whose reader merges the stored subtree recursively (array_replace_recursive). */
    public static function deepNodes(): array
    {
        return ['felt_steering.text', 'felt_steering.intensity', 'memory_translation.text', 'prompt_gating.text',
                'facet_appraisal.felt_text', 'impulse.alignment'];
    }

    /** May the stored row hold $path's children in part (its reader merges them key by key)? */
    public static function isKeysNode(array $path): bool
    {
        if ($path === []) return true;   // getConfig(): array_merge(defaults, row)
        $dotted = implode('.', array_map('strval', $path));
        foreach (self::deepNodes() as $deep) {
            if ($dotted === $deep || str_starts_with($dotted, $deep . '.')) return true;
        }
        if (in_array($dotted, self::keysNodes(), true)) return true;
        if (count($path) >= 2) {
            $parent = implode('.', array_map('strval', array_slice($path, 0, -1)));
            if (in_array($parent . '.*', self::keysNodes(), true)) return true;
        }
        return false;
    }

    // =====================================================================
    // VALUES
    // =====================================================================

    public static function isAssoc($v): bool
    {
        return is_array($v) && $v !== [] && !array_is_list($v);
    }

    /** Equal as config values: numbers by value (2 == 2.0), arrays element by element, the rest strictly. */
    public static function valuesEqual($a, $b): bool
    {
        $num = fn($x) => (is_int($x) || is_float($x));
        if ($num($a) && $num($b)) {
            $fa = (float) $a;
            $fb = (float) $b;
            return $fa === $fb || abs($fa - $fb) <= 1e-12 * max(1.0, abs($fa), abs($fb));
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) return false;
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::valuesEqual($v, $b[$k])) return false;
            }
            return true;
        }
        return $a === $b;
    }

    /** The value at $path in $tree; $found tells a stored null from a missing key. */
    public static function valueAt($tree, array $path, ?bool &$found = null)
    {
        $found = true;
        foreach ($path as $k) {
            if (!is_array($tree) || !array_key_exists($k, $tree)) {
                $found = false;
                return null;
            }
            $tree = $tree[$k];
        }
        return $tree;
    }

    private static function setAt(array &$tree, array $path, $value): void
    {
        $ref = &$tree;
        foreach ($path as $k) {
            if (!is_array($ref)) $ref = [];
            if (!array_key_exists($k, $ref)) $ref[$k] = [];
            $ref = &$ref[$k];
        }
        $ref = $value;
        unset($ref);
    }

    /**
     * The stored row laid over the defaults as the readers lay it (keys nodes key by key, the rest
     * whole): what the row says, without any reader's own clean-up.
     */
    public static function overlay($default, $stored, array $path = [])
    {
        if (self::isKeysNode($path) && is_array($default) && self::isAssoc($stored)) {
            $out = $default;
            foreach ($stored as $k => $v) {
                $out[$k] = array_key_exists($k, (array) $default) ? self::overlay($default[$k], $v, array_merge($path, [$k])) : $v;
            }
            return $out;
        }
        return $stored;
    }

    /** No difference from the defaults: the key stays out of the row. */
    const NO_DIFF = "\0reldyn:nodiff";

    /**
     * What the row must hold for $path so its reader sees $cur: at a keys node, only the children that
     * differ from the defaults (and keys the defaults lack that the row already held); elsewhere $cur
     * whole when it differs. NO_DIFF when nothing differs.
     */
    public static function minimalDiff($default, $cur, $stored, array $path = [])
    {
        if (self::isKeysNode($path) && self::isAssoc($default) && is_array($cur) && !array_is_list($cur)) {
            $out = [];
            foreach ($cur as $k => $v) {
                if (!array_key_exists($k, $default)) {
                    if (is_array($stored) && array_key_exists($k, $stored)) $out[$k] = $v;
                    continue;
                }
                $d = self::minimalDiff($default[$k], $v, is_array($stored) ? ($stored[$k] ?? null) : null, array_merge($path, [$k]));
                if ($d !== self::NO_DIFF) $out[$k] = $d;
            }
            return $out === [] ? self::NO_DIFF : $out;
        }
        return self::valuesEqual($default, $cur) ? self::NO_DIFF : $cur;
    }

    /** Section => the section as RelDyn reads it now (its reader, else the merged config row). */
    public static function effective(): array
    {
        $cfg = RelationshipDynamics::getConfig();
        $out = [];
        $readers = self::readers();
        foreach (self::defaults() as $key => $default) {
            if (isset($readers[$key])) {
                try {
                    $out[$key] = call_user_func($readers[$key]);
                    continue;
                } catch (\Throwable $e) {
                    RelationshipDynamics::logError("settings: reading {$key}", $e);
                }
            }
            $out[$key] = array_key_exists($key, $cfg) ? $cfg[$key] : $default;
        }
        return $out;
    }

    /** The stored row laid over the defaults (overlay), per top-level key. */
    public static function storedOverlay(?array $stored = null): array
    {
        $stored = $stored ?? RelationshipDynamics::loadStoredConfig();
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? self::overlay($default, $stored[$key], [$key]) : $default;
        }
        return $out;
    }

    // =====================================================================
    // FIELDS
    // =====================================================================

    public static function encodePath(array $path): string
    {
        return rtrim(strtr(base64_encode(json_encode(array_values($path), JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** A path code back to its path, or null when it is malformed or names nothing in the defaults. */
    public static function decodePath(string $code): ?array
    {
        if ($code === '' || strlen($code) > 2048 || preg_match('/[^A-Za-z0-9_-]/', $code)) return null;
        $json = base64_decode(strtr($code, '-_', '+/'), true);
        $path = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($path) || $path === [] || !array_is_list($path)) return null;
        foreach ($path as $k) {
            if (!is_string($k) && !is_int($k)) return null;
        }
        self::valueAt(self::defaults(), $path, $found);
        return $found ? $path : null;
    }

    /**
     * Every editable leaf of the defaults: code => ['path', 'dotted', 'kind', 'default', 'options',
     * 'min', 'max', 'label', 'hint']. Kinds: bool, int, float, string, text (long / multi-line),
     * enum, lines (a list of strings), numbers (a list of numbers), json (any other list or an empty
     * table), any (a null default).
     */
    public static function fields(): array
    {
        if (self::$fieldCache !== null) return self::$fieldCache;
        $out = [];
        foreach (self::defaults() as $key => $value) self::collect([$key], $value, $out);
        return self::$fieldCache = $out;
    }

    private static function collect(array $path, $value, array &$out): void
    {
        if (self::isAssoc($value)) {
            foreach ($value as $k => $v) self::collect(array_merge($path, [$k]), $v, $out);
            return;
        }
        $dotted = implode('.', array_map('strval', $path));
        $kind = self::kindOf($value);
        $options = null;
        if ($kind === 'string' && isset(self::ENUMS[$dotted])) {
            $kind = 'enum';
            $options = self::ENUMS[$dotted] === '@tiers' ? array_keys(RelationshipDynamics::RELATIONSHIP_TIERS) : self::ENUMS[$dotted];
        }
        $min = $max = null;
        if (count($path) === 1 && isset(RelationshipDynamics::CONFIG_FORM_NUMBERS[$path[0]])) {
            [, $min, $max] = RelationshipDynamics::CONFIG_FORM_NUMBERS[$path[0]];
        }
        [$label, $hint] = self::LABELS[$dotted] ?? [self::humanize((string) end($path)), ''];
        $out[self::encodePath($path)] = ['path' => $path, 'dotted' => $dotted, 'kind' => $kind, 'default' => $value,
            'options' => $options, 'min' => $min, 'max' => $max, 'label' => $label, 'hint' => $hint];
    }

    private static function kindOf($v): string
    {
        if (is_bool($v)) return 'bool';
        if (is_int($v)) return 'int';
        if (is_float($v)) return 'float';
        if (is_string($v)) return (strlen($v) > 90 || strpos($v, "\n") !== false) ? 'text' : 'string';
        if ($v === null) return 'any';
        if (is_array($v) && $v !== [] && array_is_list($v)) {
            if (count(array_filter($v, 'is_string')) === count($v)) return 'lines';
            if (count(array_filter($v, fn($x) => is_int($x) || is_float($x))) === count($v)) return 'numbers';
        }
        return 'json';
    }

    public static function humanize(string $key): string
    {
        $s = trim((string) preg_replace('/[_\s]+/', ' ', $key));
        return $s === '' ? $key : ucfirst($s);
    }

    /** Vars a leaf posts (a checkbox posts its hidden twin too). */
    public static function varCount(array $field): int
    {
        return $field['kind'] === 'bool' ? 2 : 1;
    }

    /** A list of strings shown one per line, when every item survives that round trip. */
    public static function linesSafe($v): bool
    {
        if (!is_array($v) || !array_is_list($v)) return false;
        foreach ($v as $item) {
            if (!is_string($item) || $item === '' || $item !== trim($item) || strpbrk($item, "\r\n") !== false) return false;
        }
        return true;
    }

    /** A value as a form shows it (strings as they are, numbers in their exact shortest form). */
    public static function formValue(array $field, $v): string
    {
        switch ($field['kind']) {
            case 'bool': return $v ? '1' : '';
            case 'int':
            case 'float':
                return (is_int($v) || is_float($v)) ? self::numberText($v) : (is_scalar($v) ? (string) $v : '');
            case 'string': case 'text': case 'enum':
                return is_scalar($v) || $v === null ? (string) $v : self::json($v);
            case 'lines':
                return self::linesSafe($v) ? implode("\n", $v) : self::json($v);
            case 'numbers':
                if (is_array($v) && array_is_list($v) && count(array_filter($v, fn($x) => is_int($x) || is_float($x))) === count($v)) {
                    return implode(', ', array_map([self::class, 'numberText'], $v));
                }
                return self::json($v);
            case 'any':
                if ($v === null) return '';
                return is_string($v) ? $v : self::json($v);
            default:
                return self::json($v);
        }
    }

    public static function numberText($v): string
    {
        if (is_int($v)) return (string) $v;
        $s = json_encode((float) $v, JSON_PRESERVE_ZERO_FRACTION);
        return is_string($s) ? $s : (string) $v;
    }

    public static function json($v): string
    {
        $s = json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return is_string($s) ? $s : 'null';
    }

    /**
     * A posted value parsed by its field's kind: ['ok' => bool, 'value', 'error', 'note'].
     * Keys the old settings page bounded (CONFIG_FORM_NUMBERS) are clamped to those bounds.
     */
    public static function parse(array $field, $raw): array
    {
        if (is_array($raw)) $raw = end($raw);
        $raw = str_replace("\r\n", "\n", (string) $raw);
        $t = trim($raw);
        $bad = fn(string $why) => ['ok' => false, 'value' => null, 'error' => $why, 'note' => null];
        $good = fn($v, ?string $note = null) => ['ok' => true, 'value' => $v, 'error' => null, 'note' => $note];
        switch ($field['kind']) {
            case 'bool':
                return $good(!in_array(strtolower($t), ['', '0', 'off', 'false', 'no'], true));
            case 'int':
            case 'float':
                if (!is_numeric($t) || !is_finite((float) $t)) return $bad('must be a number');
                $v = (float) $t;
                if ($field['kind'] === 'int') {
                    if (abs($v - round($v)) > 1e-9) return $bad('must be a whole number');
                    $v = (int) round($v);
                }
                if ($field['min'] !== null && $field['max'] !== null) {
                    $c = max($field['min'], min($field['max'], $v));
                    if ($c != $v) {
                        $c = $field['kind'] === 'int' ? (int) round($c) : (float) $c;
                        return $good($c, 'kept within ' . self::numberText($field['min']) . '..' . self::numberText($field['max']));
                    }
                }
                return $good($v);
            case 'string':
            case 'text':
                return $good($raw);
            case 'enum':
                return in_array($raw, (array) $field['options'], true) ? $good($raw) : $bad('is not one of the choices');
            case 'lines':
                if ($t !== '' && $t[0] === '[') {
                    $j = json_decode($t, true);
                    return is_array($j) && array_is_list($j) ? $good($j) : $bad('is not a JSON list');
                }
                return $good(array_values(array_filter(array_map('trim', explode("\n", $raw)), fn($s) => $s !== '')));
            case 'numbers':
                if ($t !== '' && $t[0] === '[') {
                    $j = json_decode($t, true);
                    return is_array($j) && array_is_list($j) ? $good($j) : $bad('is not a JSON list');
                }
                $ints = count(array_filter((array) $field['default'], 'is_int')) === count((array) $field['default']);
                $out = [];
                foreach (preg_split('/[\s,;]+/', $t, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                    if (!is_numeric($part) || !is_finite((float) $part)) return $bad("has '{$part}', not a number");
                    $n = (float) $part;
                    if ($ints && abs($n - round($n)) > 1e-9) return $bad('takes whole numbers');
                    $out[] = $ints ? (int) round($n) : $n;
                }
                return $good($out);
            case 'any':
                if ($t === '') return $good(null);
                $j = json_decode($t, true);
                return json_last_error() === JSON_ERROR_NONE ? $good($j) : $good($raw);
            default:   // json
                $j = json_decode($t, true);
                if (json_last_error() !== JSON_ERROR_NONE) return $bad('is not valid JSON (' . json_last_error_msg() . ')');
                if (is_array($field['default']) && !is_array($j)) return $bad('must be a JSON list or object');
                return $good($j);
        }
    }

    // =====================================================================
    // GROUPS
    // =====================================================================

    public static function groupOf(string $key): string
    {
        if (isset(self::GROUP_KEYS[$key])) return self::GROUP_KEYS[$key];
        foreach (self::GROUP_PREFIXES as $prefix => $group) {
            if (str_starts_with($key, $prefix)) return $group;
        }
        return 'other';
    }

    /** Group => its top-level keys, in defaultConfig() order ('features' is built from the switches). */
    public static function groupKeys(): array
    {
        $out = array_fill_keys(array_keys(self::GROUPS), []);
        foreach (array_keys(self::defaults()) as $key) $out[self::groupOf((string) $key)][] = (string) $key;
        return $out;
    }

    /** Every on/off switch: bool leaves named 'enabled' or '*_enabled', anywhere. */
    public static function featureFields(): array
    {
        return array_filter(self::fields(), function ($f) {
            $last = (string) end($f['path']);
            return $f['kind'] === 'bool' && ($last === 'enabled' || str_ends_with($last, '_enabled'));
        });
    }

    // =====================================================================
    // CSRF
    // =====================================================================

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::CSRF_KEY];
    }

    public static function csrfValid($posted): bool
    {
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        return is_string($posted) && $posted !== '' && is_string($token) && $token !== '' && hash_equals($token, $posted);
    }

    // =====================================================================
    // SAVE / RESET
    // =====================================================================

    /**
     * A settings POST: ['ok' => bool, 'saved' => bool, 'messages' => [], 'errors' => []].
     *   csrf_token  the session token (core's pattern); anything else: nothing is read or written
     *   _complete   the form's last input: missing = the POST was cut short (max_input_vars), refused
     *   reset       a path code: that key (or section) back to its default, nothing else
     *   f[code]     field values; only those that differ from what RelDyn reads now are applied
     */
    public static function handlePost(array $post): array
    {
        $res = ['ok' => false, 'saved' => false, 'messages' => [], 'errors' => []];
        if (!self::csrfValid($post['csrf_token'] ?? null)) {
            $res['errors'][] = 'Security check failed (missing or expired form token). Nothing was saved. Reload the page and try again.';
            return $res;
        }
        if (($post['_complete'] ?? '') !== '1') {
            $res['errors'][] = 'The form arrived incomplete (the server\'s max_input_vars may have cut it short). Nothing was saved.';
            return $res;
        }
        $fields = self::fields();
        if (isset($post['reset'])) {
            $path = is_string($post['reset']) ? self::decodePath($post['reset']) : null;
            if ($path === null) {
                $res['errors'][] = 'Unknown setting to reset. Nothing was saved.';
                return $res;
            }
            $r = self::apply([], [$path]);
            $dotted = implode('.', array_map('strval', $path));
            if (!$r['ok']) {
                $res['errors'][] = "Could not reset {$dotted} (see the server log).";
                return $res;
            }
            $res['ok'] = true;
            $res['saved'] = $r['saved'];
            $res['messages'][] = $r['saved'] ? "{$dotted} is back to its default." : "{$dotted} was already at its default.";
            $pending = self::pendingEdits((array) ($post['f'] ?? []), $fields, $r['display']);
            if ($pending) $res['messages'][] = 'Other edits in that form were not saved (' . implode(', ', $pending) . ').';
            return $res;
        }
        $edits = [];
        $display = self::effective();
        foreach ((array) ($post['f'] ?? []) as $code => $raw) {
            $field = $fields[(string) $code] ?? null;
            if ($field === null) {
                $res['errors'][] = 'The form names a setting this RelDyn does not have (reload the page). Nothing was saved.';
                return $res;
            }
            $p = self::parse($field, $raw);
            if (!$p['ok']) {
                $res['errors'][] = "{$field['dotted']} {$p['error']}.";
                continue;
            }
            if ($p['note'] !== null) $res['messages'][] = "{$field['dotted']}: {$p['note']}.";
            $now = self::valueAt($display, $field['path'], $found);
            if ($found && self::valuesEqual($now, $p['value'])) continue;
            $edits[] = [$field['path'], $p['value']];
        }
        if ($res['errors']) {
            array_unshift($res['errors'], 'Nothing was saved:');
            return $res;
        }
        if (!$edits) {
            $res['ok'] = true;
            $res['messages'][] = 'No changes to save.';
            return $res;
        }
        $r = self::apply($edits, []);
        if (!$r['ok']) {
            $res['errors'][] = 'The settings could not be saved (see the server log).';
            return $res;
        }
        $res['ok'] = true;
        $res['saved'] = true;
        $res['messages'][] = 'Saved ' . count($edits) . ' setting' . (count($edits) === 1 ? '' : 's') . ': '
            . implode(', ', array_map(fn($e) => implode('.', array_map('strval', $e[0])), $edits)) . '.';
        foreach ($edits as [$path, $value]) {
            $now = self::valueAt($r['display'], $path, $found);
            if ($found && !self::valuesEqual($now, $value)) {
                $res['messages'][] = implode('.', array_map('strval', $path)) . ' is stored, but RelDyn reads it as '
                    . self::formValue(['kind' => 'json'], $now) . ' (its reader adjusts it).';
            }
        }
        return $res;
    }

    /** Dotted paths of posted values that differ from $display (edits a reset leaves unsaved). */
    private static function pendingEdits(array $posted, array $fields, array $display): array
    {
        $out = [];
        foreach ($posted as $code => $raw) {
            $field = $fields[(string) $code] ?? null;
            if ($field === null) continue;
            $p = self::parse($field, $raw);
            $now = self::valueAt($display, $field['path'], $found);
            if (!$p['ok'] || !$found || !self::valuesEqual($now, $p['value'])) $out[] = $field['dotted'];
        }
        return $out;
    }

    /**
     * Apply leaf edits ([path, value]) and resets (paths, a key or a whole node) to the stored row and
     * save it through RelationshipDynamics::saveConfig (known keys, the schema stamp). Only the touched
     * sections are rewritten, each as its minimalDiff over the defaults. Returns ['ok', 'saved',
     * 'display' => effective() after the save].
     */
    public static function apply(array $edits, array $resets): array
    {
        $defaults = self::defaults();
        $stored = RelationshipDynamics::loadStoredConfig();
        $basis = self::storedOverlay($stored);
        $touched = [];
        foreach ($resets as $path) {
            $def = self::valueAt($defaults, $path, $found);
            if (!$found) continue;
            self::setAt($basis, $path, $def);
            $touched[(string) $path[0]] = true;
        }
        foreach ($edits as [$path, $value]) {
            self::valueAt($defaults, $path, $found);
            if (!$found) continue;
            self::setAt($basis, $path, $value);
            $touched[(string) $path[0]] = true;
        }
        $row = $stored;
        foreach (array_keys($touched) as $key) {
            $d = self::minimalDiff($defaults[$key], $basis[$key], $stored[$key] ?? null, [$key]);
            if ($d === self::NO_DIFF) unset($row[$key]); else $row[$key] = $d;
        }
        unset($row['config_schema']);
        $before = $stored;
        unset($before['config_schema']);
        if (self::valuesEqual($row, $before) && intval($stored['config_schema'] ?? 0) === RelationshipDynamics::CONFIG_SCHEMA
            && self::rowPresent()) {
            return ['ok' => true, 'saved' => false, 'display' => self::effective()];
        }
        $ok = RelationshipDynamics::saveConfig($row);
        return ['ok' => $ok, 'saved' => $ok, 'display' => self::effective()];
    }

    /** Is there a stored config row at all (a reset on a fresh install writes the stamp, nothing else)? */
    private static function rowPresent(): bool
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return false;
        try {
            $row = $db->fetchOne('SELECT id FROM conf_opts WHERE id = $1 LIMIT 1', [RelationshipDynamics::CONFIG_ROW_ID]);
        } catch (\Throwable $e) {
            return false;
        }
        return is_array($row) && !empty($row['id']);
    }

    // =====================================================================
    // PROMPT GATING PREVIEW (read-only)
    // =====================================================================

    /** Core NPC names for the pickers (SELECT only), sorted; [] without a database. */
    public static function npcNames(int $limit = 3000): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [];
        try {
            $rows = $db->fetchAll('SELECT npc_name FROM core_npc_master WHERE npc_name IS NOT NULL ORDER BY lower(npc_name) LIMIT ' . max(1, intval($limit)));
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('settings: NPC list', $e);
            return [];
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $n = trim((string) ($r['npc_name'] ?? ''));
            if ($n !== '' && strcasecmp($n, 'The Narrator') !== 0) $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    /**
     * What $npc knows of the player and the text she would get, without writing anything (no LLM,
     * no save): her stored RelDyn state (none: core's Player entry), or core affinity $aff (points
     * -100..100) in its place. ['npc', 'player', 'state' => stored|core|none, 'core_aff', 'knowledge',
     * 'text' (<knowledge_of_player>), 'note' (the nearby-actors note; null = core's own),
     * 'name_unknown' (the COMMAND_PROMPT line, or null), 'gating_on', 'core_hook'].
     */
    public static function gatingPreview(string $npc, ?float $aff = null): array
    {
        $npc = trim($npc);
        $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
        $stored = RelationshipDynamics::loadStoredDynamics($npc);
        $dyn = is_array($stored) ? $stored : [];
        $state = is_array($stored) ? 'stored' : 'none';
        if ($aff === null && !is_numeric($dyn['_aff_mirror_x'] ?? null)) {
            $rel = RelationshipDynamics::getPlayerRelationship($npc);
            if (is_array($rel) && is_numeric($rel['aff'] ?? null)) {
                $aff = floatval($rel['aff']);
                $state = $state === 'stored' ? 'stored' : 'core';
            }
        }
        if ($aff !== null) {
            $aff = max(-100.0, min(100.0, $aff));
            $x = ($aff + 100.0) / 2.0;
            $dyn['_aff_mirror_x'] = $x;
            $dyn['dimensions']['affinity']['x'] = $x;
        }
        $k = RelDynGating::knowledge($npc, $dyn);
        $gatingOn = RelDynGating::enabled();
        $text = RelDynFelt::knowledgeOfPlayer($npc, $player, $dyn, RelDynFelt::config(), $gatingOn ? $k : null);
        $answer = RelDynGating::gateAnswer($k, $npc);
        $nameLine = null;
        if (!$k['name']) {
            $nameLine = strtr((string) RelDynGating::config()['text']['name_unknown'], ['{NAME}' => $npc, '{PLAYER_NAME}' => $player]);
        }
        return ['npc' => $npc, 'player' => $player, 'state' => $state, 'core_aff' => $k['core_aff'], 'knowledge' => $k,
            'text' => $text, 'note' => $answer['note'], 'name_unknown' => $nameLine, 'gating_on' => $gatingOn,
            'core_hook' => RelDynGating::coreHookPresent()];
    }

    /** The preview at each RelDyn tier's midpoint (core affinity): tier => gatingPreview(). */
    public static function gatingTierPreview(string $npc): array
    {
        $out = [];
        foreach (RelationshipDynamics::RELATIONSHIP_TIERS as $tier => $range) {
            $out[$tier] = self::gatingPreview($npc, round(($range['min'] + $range['max']) / 2.0));
        }
        return $out;
    }
}
