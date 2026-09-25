<?php
/**
 * Felt steering (decisions 2026-09-23 §3): how RelDyn's state reaches the LLM.
 *
 * Dimension state reaches the dialogue model only as felt steering: behavioral keywords (what
 * the NPC DOES), subtext and intensity formatting. Never numbers, never "you feel that you trust
 * the player". Jev is the one exception and gets explicit numbers (reldyn_jev.php).
 *
 * Pipeline, once per request:
 *   compose()   every RelDyn source (dimension bands, passion, jealousy, conflict, place /
 *               topic / gift reads, fulfillment and boundary, intimacy, attraction, weather,
 *               combat, grief, crisis, masking, autonomy, hoover, memories, goal ...) becomes
 *               one candidate line: [key, scope self|bond, lane core|turn, salience 0..1,
 *               must, intense, text]. One-shot sources are consumed here, so compose runs
 *               exactly once per request (context_pre hands its result to context.php).
 *   select()    context tier (tiers + high-water mark, feedback_context_tiers): a stranger
 *               gets the NPC's own state only (plus a first-sight attraction read and open
 *               hostility), tiers cap the lines, a token budget per tier trims the least
 *               salient lines; must lines (the one-shot boundary / relief / step-back
 *               statements) always stay.
 *   placement   context_pre.php (primacy, inside <character>): <knowledge_of_player> (what
 *               the NPC knows of the player, by tier, with one tension bridge) and the
 *               emotional core (the most salient enduring lines). context.php (recency, after
 *               the dialogue history): one <subtext> block with the rest, ordered by salience.
 *               With context_pre off (or not run) the <subtext> block carries every line.
 *   intensity   applyTextIntensity(): arousal / passion escalate to CAPS and '!', low maturity
 *               degrades the text itself; bounded (a few words per line), never unreadable
 *               (feedback_intensity_formatting).
 *
 * Units: dimension values are the dimensions' own points (0..100; coord_m / coord_f / valence
 * -100..100), passion and jealousy 0..100 points, affinity is core units (-100..100) read only
 * through RelationshipDynamics::getCoreAffinity. Token counts are estimates (estimateTokens).
 */

final class RelDynFelt
{
    /** Request-scoped handoff from context_pre.php to context.php (same PHP request). */
    const HANDOFF_GLOBAL = 'RELDYN_FELT_HANDOFF';

    const SCOPE_SELF = 'self';
    const SCOPE_BOND = 'bond';
    const LANE_CORE = 'core';   // enduring state: who they are to the player right now
    const LANE_TURN = 'turn';   // this moment: an event, a place, a statement

    /** The lines the last build() selected, key => rendered text (logs, debug_compose, tests). */
    private static array $lastRendered = [];

    /** key => text of every line the current request's felt steering put in front of the LLM. */
    public static function lastRendered(): array
    {
        return self::$lastRendered;
    }

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults (config key 'felt_steering'). A stored config replaces settings / tables one by
     * one, except 'text' and 'intensity', which merge per entry so editing one wording keeps
     * the rest.
     */
    public static function configDefaults(): array
    {
        return [
            // Lines per context tier (0 stranger / hostile, 1 acquaintance, 2 friend, 3 bonded),
            // both placements together; must lines are never cut.
            'tier_max_lines' => [0 => 3, 1 => 5, 2 => 8, 3 => 10],
            // Estimated tokens (estimateTokens) of the lines + <subtext> header per tier; the
            // least salient lines are dropped until it fits. The knowledge_of_player sentence
            // has its own budget below.
            'tier_token_budget' => [0 => 110, 1 => 190, 2 => 290, 3 => 360],
            'knowledge_token_budget' => 80,
            // Enduring ('core' lane) lines lifted into the <character> block by context_pre, from
            // this context tier (a stranger gets only the knowledge line there).
            'core_lines' => 2,
            'core_min_tier' => 1,
            // Where context_pre puts its block in HERIKA_PERS: 'append' (after the character's
            // own bio, before core's relationship block) or 'prepend' (before the bio).
            'position' => 'append',
            // Dimension points from the baseline within which a band says nothing (unless it is
            // the band table's first or last band).
            'baseline_deadband' => 10.0,
            // Salience of a band line: min(1, |x - baseline| / 100 x weight) + extreme_bonus.
            'dimension_weight' => [
                'affinity' => 1.0, 'trust' => 1.0, 'warmth' => 1.0, 'comfort' => 0.9, 'respect' => 0.8,
                'resentment' => 1.3, 'maturity' => 0.8, 'self_confidence' => 0.6, 'resentment_self' => 0.9,
                'coord_mf' => 0.7, 'arousal_valence' => 1.0,
            ],
            'extreme_bonus' => 0.15,
            // From context tier 2 the maturity line (how they handle what they feel) always
            // speaks, at least at this salience.
            'maturity_floor_salience' => 0.35,
            // Base salience per source (0..1); passion / jealousy / goal scale by their value.
            'salience' => [
                'crisis' => 0.95, 'walkaway' => 0.95, 'bleeding_out' => 1.0, 'combat' => 0.9, 'mask' => 0.85,
                'probation' => 0.85, 'autonomy' => 0.85, 'reunion' => 0.8, 'blush' => 0.8, 'grief' => 0.8,
                'hoover' => 0.8, 'ick' => 0.8, 'conflict' => 0.75, 'll_reaction' => 0.7, 'parasite' => 0.7,
                'emergent' => 0.65, 'place' => 0.6, 'gift' => 0.6, 'intimacy' => 0.6, 'unmet' => 0.6,
                'creature' => 0.6, 'mask_drop' => 0.6, 'topic' => 0.55, 'attraction' => 0.5,
                'post_combat' => 0.5, 'duty' => 0.5, 'charisma' => 0.45, 'memory' => 0.45, 'weather' => 0.4,
            ],
            'passion_salience_offset' => 0.1,    // passion line salience = passion / 100 + this
            'jealousy_salience_offset' => 0.2,   // jealousy line salience = jealousy / 100 + this
            // Memories (dimensional_memory + the last eval reasons), from this context tier.
            'memory_min_tier' => 2,
            'memory_items' => 3,
            'reason_max_chars' => 90,
            // The attraction read (RelDynAttraction::feltText) against the bond it sits in:
            // strained = open conflict, an active ick, resentment from strain_resentment_min
            // (points; the 'Frustrated' band: "kind words from them no longer land") or jealousy
            // from strain_jealousy_min (points; the 'hurt' band): the pull says nothing then.
            // Flirtation is answered from context tier flirt_min_tier (2 = friend) or passion
            // flirt_passion_min (points; the 'warm' band), or inside a romance; below that the
            // pull shows only as looks (MDD 8.1: at Unknown / Acquaintance romantic gestures
            // meet the Asymmetry Penalty).
            'attraction' => [
                'strain_resentment_min' => 51.0, 'strain_jealousy_min' => 60.0,
                'flirt_min_tier' => 2, 'flirt_passion_min' => 40.0,
            ],
            // Autonomy speaks from this evaluated state (resistant < refusing < walkaway); a
            // merely resistant disposition shows through the trust / respect / resentment bands.
            'autonomy_min_state' => 'refusing',
            // Emergent emotions that only make sense inside a romance: need one of these core
            // relationship types, or passion (0..100) at least emergent_romantic_passion_min.
            'emergent_romantic_only' => ['longing', 'infatuation', 'volatile_passion'],
            'romantic_types' => ['romantic', 'crush'],
            'emergent_romantic_passion_min' => 40.0,
            // Bands written by hand (the extreme low ones): no automatic degradation on top.
            'handwritten_bands' => ['maturity' => ['Chaotic'], 'resentment_self' => ['Crisis']],
            // Knowledge-of-player tension bridges (dimension points 0..100).
            'bridge' => [
                'drawn_passion_min' => 56.0, 'guarded_warmth_max' => 40.0,
                'closed_warmth_max' => 35.0, 'wary_trust_max' => 35.0,
                'worn_resentment_min' => 51.0,
                'uneasy_comfort_max' => 30.0,
                'steady_trust_min' => 70.0, 'steady_resentment_max' => 15.0,
            ],
            'intensity' => [
                // arousal / passion points (0..100) from which formatting is mild / strong / extreme
                'level_at' => [36.0, 56.0, 76.0],
                'caps_words' => [0, 0, 2, 3],          // emphasis words in CAPS per line, by level 0..3
                'caps_first_phrase_at' => 3,           // level from which the first phrase is all CAPS
                'caps_phrase_max_words' => 6,          // ... when it has at most this many words
                'exclaim_first' => [0, 0, 1, 3],       // '!' after the first phrase, by level
                'exclaim_end' => [0, 1, 1, 1],         // '!' at the end of the line, by level
                // maturity points (0..100) below which light / moderate / heavy degradation applies
                'maturity_at' => [56.0, 41.0, 21.0],
                'degrade_case_words' => [0, 0, 1, 2],  // words in chaotic casing, by degradation 0..3
                'degrade_drop_words' => [0, 0, 1, 1],  // words that lose one letter to '_'
                'degrade_pauses' => [0, 1, 1, 2],      // phrase breaks turned into a trailing-off pause
                // light degradation touches only about one line in this many ("occasional")
                'light_every' => 3,
                'degrade_min_word_len' => 5,           // shorter words are never touched
                // numb / hollow (arousal points below, and valence points below): flat, trailing text
                'hollow_arousal_below' => 15.0,
                'hollow_valence_below' => -30.0,
            ],
            // Words intensity may set in CAPS (besides RelationshipDynamics::EMOTIONAL_WORDS).
            'emphasis_words' => [
                "can't", 'look', 'looking', 'touch', 'close', 'closer', 'racing', 'pulse', 'breath', 'jaw',
                'clipped', 'snaps', 'furious', 'now', 'never', 'burning', 'trembling', 'stare', 'staring',
                'hands', 'gaze', 'fight', 'away', 'door', 'eyes', 'keeps', 'every', 'flares', 'panic',
                'heart', 'pounding', 'accusation', 'brittle', 'charged', 'rival',
            ],
            'text' => self::TEXT_DEFAULTS,
        ];
    }

    const TEXT_DEFAULTS = [
        // <subtext> header: {NAME} NPC, {PLAYER} the player ("them" in the keyword lines).
        'header_bond' => "{NAME} with {PLAYER} right now (\"them\" is {PLAYER}). Show it in what {NAME} does and how {NAME} speaks; never name or explain these feelings:",
        'header_self' => "{NAME} right now. Show it in what {NAME} does and how {NAME} speaks; never name or explain these feelings:",
        'core_header' => "Underneath, right now (\"them\" is {PLAYER}; show it, never state it):",
        'player_ref_stranger' => 'this stranger',
        'player_ref_hostile' => 'this person',
        // What the NPC knows of the player, by context tier (prompt gating P3 consolidates here).
        'knowledge' => [
            'hostile'      => "{NAME} knows {PLAYER} only as trouble; whatever has passed between them earned no warmth, and {NAME} gives them nothing freely.",
            'stranger'     => "To {NAME} this is a stranger: only what can be seen, their bearing, gear and manner. {NAME} knows nothing of their name, past or deeds unless told, and does not act familiar.",
            // core's relationship block names the player to every NPC (coreNamesPlayer): the
            // name is known, nothing behind it
            'stranger_named' => "{NAME} has barely met {PLAYER}: a name and what can be seen, their bearing, gear and manner; nothing of their past or deeds unless told, and {NAME} does not act familiar.",
            'acquaintance' => "{NAME} knows {PLAYER} by name and a few shared words, not by heart: polite familiarity, nothing personal assumed.",
            'friend'       => "{NAME} knows {PLAYER} well: their habits, their humour, what they have shared on the road.",
            'lapsed'       => "{NAME} knows {PLAYER} well, which is exactly why it cuts: the familiarity is all still there, the old warmth is not.",
            'bonded'       => "{NAME} knows {PLAYER} deeply: their moods before they speak, their old wounds, everything they have survived together.",
        ],
        'bridge' => [
            'drawn_but_guarded' => "Drawn to {PLAYER} and fighting it: the pull shows in glances, never in words.",
            'cares_but_closed'  => "Cares more than {NAME} lets show; the feeling is there, the openness is not.",
            'fond_but_wary'     => "Fond of {PLAYER} and still keeps one eye open; closeness has not become trust.",
            'close_but_uneasy'  => "Close to {PLAYER} and still never quite at ease with them; stiff where it should be easy.",
            'worn_out'          => "Cares for {PLAYER} and is worn thin by them at once; patience frayed to the bone.",
            'steady'            => "Steady, unhurried loyalty with nothing left to prove.",
        ],
        // Passion toward the player (RelationshipDynamics::getPassionBand of 0..100 points).
        'passion' => [
            'burning'  => "can't stop looking at them, loses the thread mid-sentence, finds excuses to touch them",
            'intense'  => "drifts closer than needed, holds their gaze a beat too long, voice drops",
            'warm'     => "brightens when they speak, finds reasons to stay near",
            'stirring' => "a small smile when they come near, quickly hidden",
            'faint'    => "an odd, unexamined glance their way now and then",
        ],
        // The same passion toward someone the NPC is not drawn to (attraction: friendzone /
        // unattracted, or an attraction hard zero; decisions §13 lets passion climb there on the
        // uphill): a friend's intensity, never desire, and no urge, so the passion line agrees
        // with the attraction line's deflection.
        'passion_platonic' => [
            'burning'  => "fiercely glad of them, seeks them out at every turn; a loyal, fierce affection, and nothing romantic in it",
            'intense'  => "lights up when they come near and wants their company; warm and loyal, not romantic",
            'warm'     => "brightens when they speak, glad of their company",
            'stirring' => "a small, easy smile when they come near",
            'faint'    => "an odd, unexamined glance their way now and then",
        ],
        // The urge that rides on passion (from warm up), by primary love language.
        'urge' => [
            'words_of_affirmation' => "the words for what they mean to {NAME} are right there, wanting out",
            'quality_time'         => "keeps inventing reasons to make the moment last",
            'physical_touch'       => "keeps measuring the space between them, wanting to close it",
            'acts_of_service'      => "looks for something to do for them, to show it instead of saying it",
            'gifts'                => "keeps thinking of something to give them that would say it",
        ],
        'blush' => [
            'strong' => "heat floods {NAME}'s face and will not be hidden; the body says what the words have not",
            'mild'   => "colour rises in {NAME}'s cheeks, unbidden; it catches {NAME} off guard",
            'faint'  => "a faint warmth at the skin; that moment landed differently than expected",
        ],
        // Jealousy (RelationshipDynamics::getJealousyBand). {RIVAL}: the trigger's name.
        'jealousy' => [
            'seething'  => "jaw tight, answers in clipped half-sentences, keeps circling back to {RIVAL}",
            'hurt'      => "warmth gone brittle, questions edged with accusation about {RIVAL}",
            'unsettled' => "guarded, flinches when {RIVAL} comes up",
            'edgy'      => "a slight edge, watches who they talk to",
        ],
        'rival_unknown' => "where they were and with whom",
        // Open conflict, by repair progress (positive interactions since it opened).
        'conflict' => [
            'none'   => "walls up after what they did; needs to see real effort before softening",
            'one'    => "still hurt, watching whether their efforts are real; kindness lands heavier than usual",
            'two'    => "cautiously warming again; every kind word from them lands double right now",
        ],
        // Reaction to the last gesture, by love-language match.
        'll_reaction' => [
            'words_of_affirmation' => "eyes brighten at those words; the whole bearing softens",
            'quality_time'         => "plainly grateful for their company, as if the time itself were a gift",
            'physical_touch'       => "breath catches at the contact; leans into it almost without meaning to",
            'acts_of_service'      => "watches what they did with quiet intensity; it says more than words",
            'gifts'                => "handles the gift with surprising tenderness, moved beyond its worth",
            'secondary'            => "warmly appreciative of the gesture, if not undone by it",
            'miss'                 => "a polite smile for the gesture; it does not reach {NAME} the way some other attention would",
        ],
        // Internal weather (MDD 4.1): the NPC's own mood.
        'weather' => [
            'sunny'    => "easy laugh, generous with small talk, in good spirits",
            'overcast' => "a little flat, sighs, loses interest in things quickly without knowing why",
            'stormy'   => "restless and short-fused, snaps at small things, can't settle",
        ],
        'parasite' => "the gifts keep coming and {NAME} has stopped being moved by them; thanks sound rehearsed, with a transactional edge",
        'duty'     => "this exchange is duty, not choice, for {PLAYER}; {NAME} knows it and does not take it personally",
        'combat' => [
            'bleeding_out' => "{NAME} is down and barely conscious; every breath is a fight to stay awake",
            'badly_hurt'   => "{NAME} is badly hurt and still fighting at {PLAYER}'s side, senses razor sharp",
            'hurt'         => "{NAME} is wounded but holding the line with {PLAYER}, all adrenaline",
            'fighting'     => "{NAME} fights beside {PLAYER}: watches their back, calls out threats, moves in step with them",
            'after'        => "the fight is barely over; adrenaline still in {NAME}'s hands, the shared danger hanging in the air",
        ],
        'creature' => [
            'vampire_night'  => "{NAME}'s vampiric nature is ascendant: sharper, hungrier, the mask of humanity thinner",
            'werewolf_moon'  => "the full moon pulls at {NAME}'s beast blood: fighting for control, everything raw and immediate",
            'werewolf_night' => "{NAME}'s beast blood stirs in the dark: sharper senses, shorter patience",
        ],
        'memory'       => "Still carries: {ITEMS}.",
        'memory_warm'  => "still warm",
        'memory_sting' => "still stings",
        'goal' => [
            'strong'     => "{NAME} is set on this: {GOAL}",
            'mind'       => "on {NAME}'s mind: {GOAL}",
            'background' => "at the back of {NAME}'s mind: {GOAL}",
        ],
        // Social masking (performed vs true), by maturity; {AUDIENCE} who they perform for.
        'mask' => [
            'intro'      => "In front of {AUDIENCE}, {NAME} performs ease; underneath: {TRUE}.",
            'seamless'   => "The mask holds; only tiny tells slip: a micro-pause, a too-careful word, a glance that lingers.",
            'functional' => "The mask mostly holds and cracks under pressure: forced cheer, too-quick subject changes.",
            'unstable'   => "The mask keeps slipping: warm one moment, cold the next.",
            'others'     => "others",
        ],
    ];

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('felt_steering');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['text', 'intensity'] as $merged) {
            $cfg[$merged] = array_replace_recursive($defaults[$merged], is_array($stored[$merged] ?? null) ? $stored[$merged] : []);
        }
        return $cfg;
    }

    // =====================================================================
    // TOKENS
    // =====================================================================

    /**
     * Estimated LLM tokens: a lowercase or Capitalised word of up to 7 letters is one token (one
     * more per further 7 letters), a CAPS or mIxEd word ceil(len / 3), punctuation half a token.
     * An estimate for budgets and the before/after report, not a tokenizer.
     */
    public static function estimateTokens(string $s): int
    {
        preg_match_all("/[A-Za-z]+|[^\sA-Za-z]/", $s, $m);
        $t = 0.0;
        foreach ($m[0] as $piece) {
            if (ctype_alpha($piece)) {
                $len = strlen($piece);
                $t += (strtolower($piece) === $piece || ucfirst(strtolower($piece)) === $piece)
                    ? 1 + intdiv($len - 1, 7) : (int) ceil($len / 3);
            } else {
                $t += 0.5;
            }
        }
        return (int) ceil($t);
    }

    // =====================================================================
    // COMPOSE (every source -> candidate lines; consumes the one-shots)
    // =====================================================================

    private static function line(string $key, string $scope, string $lane, float $salience, string $text, array $extra = []): array
    {
        return array_merge([
            'key' => $key, 'scope' => $scope, 'lane' => $lane,
            'salience' => max(0.0, min(1.0, $salience)), 'must' => false, 'intense' => false,
            'handwritten' => false, 'tier0' => false, 'text' => trim($text),
        ], $extra);
    }

    private static function fill(string $text, array $vars): string
    {
        return strtr($text, $vars);
    }

    /**
     * Every candidate line for this request. Mutates $dynamics (HWM, blush / fulfillment
     * one-shots, the place turn) and reports 'changed' so the caller saves once.
     *
     * $env (all optional; the hooks pass the request's globals):
     *   player_addressed bool   the player is speaking to this NPC (one-shots wait for it)
     *   last_ll ?string         love language of the last classified interaction
     *   duty_factor float       <1 when quest duty drives the exchange
     *   masking bool, performed ?array   social masking this request
     *   ick bool                the ick is active this request
     *   goal ?array             the active director goal
     *   audience string[]       people present besides the NPC and the player
     *
     * @return array ['lines' => list, 'changed' => bool, 'tier' => int]
     */
    public static function compose(string $npc, string $player, array &$dynamics, float $now, array $env = []): array
    {
        $cfg = self::config();
        $rd = RelationshipDynamics::getConfig();
        $t = (array) $cfg['text'];
        $sal = (array) $cfg['salience'];
        $lines = [];
        $changed = false;

        if (RelationshipDynamics::updateContextTierHWM($dynamics)) $changed = true;
        $tier = RelationshipDynamics::getContextTier($dynamics);
        // A stranger-tier NPC does not know the player's name (prompt gating's #PLAYER_REF#).
        $player = self::playerRef($player, $tier, $dynamics, $cfg);
        $vars = ['{NAME}' => $npc, '{PLAYER}' => $player];
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];

        // --- Dimension bands: behavioral keywords (what the NPC does) ---
        if (!empty($rd['dimension_context_enabled']) && $dims !== []) {
            foreach (self::bandLines($dynamics, $tier, $cfg) as $l) $lines[] = $l;
        }

        // --- Passion toward the player, with the urge its love language gives it ---
        $passion = RelationshipDynamics::getPassion($dynamics);
        $pBand = RelationshipDynamics::getPassionBand($passion);
        // Not that kind of pull (the Attraction Matrix: not attracted, or a hard zero), outside
        // a romance core already holds: the platonic reading of the same passion, no urge
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $coreRomance = in_array((string) ($dynamics['_core_rel_type'] ?? ''), (array) $cfg['romantic_types'], true);
        $platonic = !empty($att['enabled']) && (!empty($att['hard_zero']) || (($att['attracted'] ?? true) === false && !$coreRomance));
        $pTable = $platonic && isset($t['passion_platonic']) ? 'passion_platonic' : 'passion';
        if (isset($t[$pTable][$pBand])) {
            $text = self::fill((string) $t[$pTable][$pBand], $vars);
            $primary = $dynamics['love_language_primary'] ?? null;
            if (!$platonic && $passion >= 40 && is_string($primary) && isset($t['urge'][$primary])) {
                $text .= ', ' . self::fill((string) $t['urge'][$primary], $vars);
            }
            $lines[] = self::line('passion', self::SCOPE_BOND, self::LANE_CORE,
                $passion / 100 + floatval($cfg['passion_salience_offset']), $text, ['intense' => true]);
        }

        // --- Blush: one-shot on a passion spike ---
        $lastDelta = floatval($dynamics['_last_passion_delta'] ?? 0);
        $blushMult = floatval($dynamics['pending_blush_mult'] ?? 1.0);
        if ($lastDelta >= 4.0 && $blushMult >= 1.5) {
            $lines[] = self::line('blush', self::SCOPE_BOND, self::LANE_TURN, floatval($sal['blush']),
                self::fill((string) $t['blush'][$lastDelta >= 7.0 ? 'strong' : 'mild'], $vars));
            $dynamics['_last_passion_delta'] = 0;
            $dynamics['pending_blush_mult'] = 1.0;
            $changed = true;
        } elseif ($lastDelta >= 2.0 && $blushMult >= 1.0) {
            $lines[] = self::line('blush', self::SCOPE_BOND, self::LANE_TURN, floatval($sal['blush']) - 0.2,
                self::fill((string) $t['blush']['faint'], $vars));
            $dynamics['_last_passion_delta'] = 0;
            $changed = true;
        }

        // --- Reunion (temperament-aware; the checkReunion hours are game-calendar hours) ---
        if (!empty($dynamics['reunion_spike_given'])) {
            $text = RelationshipDynamics::getReunionText($npc, (string) ($dynamics['inferred_temperament'] ?? ''),
                floatval($dynamics['_reunion_hours_apart'] ?? 0), $player, $dynamics);
            if ($text) $lines[] = self::line('reunion', self::SCOPE_BOND, self::LANE_TURN, floatval($sal['reunion']), $text);
        }

        // --- Jealousy ---
        $jealousy = floatval($dynamics['jealousy_anger'] ?? 0);
        $jBand = RelationshipDynamics::getJealousyBand($jealousy);
        if (isset($t['jealousy'][$jBand])) {
            $rival = trim((string) ($dynamics['jealousy_trigger_npc'] ?? ''));
            $lines[] = self::line('jealousy', self::SCOPE_BOND, self::LANE_CORE,
                $jealousy / 100 + floatval($cfg['jealousy_salience_offset']),
                self::fill((string) $t['jealousy'][$jBand], $vars + ['{RIVAL}' => $rival !== '' ? $rival : (string) $t['rival_unknown']]),
                ['intense' => true]);
        }

        // --- Open conflict / repair ---
        if (!empty($dynamics['in_conflict'])) {
            $repair = intval($dynamics['conflict_positive_count'] ?? 0);
            $lines[] = self::line('conflict', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['conflict']),
                self::fill((string) $t['conflict'][$repair >= 2 ? 'two' : ($repair >= 1 ? 'one' : 'none')], $vars),
                ['intense' => true]);
        }

        // --- The last gesture's love-language resonance (the discovery mechanic) ---
        $lastLL = $env['last_ll'] ?? null;
        $primaryLL = $dynamics['love_language_primary'] ?? null;
        if (is_string($lastLL) && $lastLL !== '' && $primaryLL) {
            $key = $lastLL === $primaryLL ? $lastLL
                : ($lastLL === ($dynamics['love_language_secondary'] ?? null) ? 'secondary' : 'miss');
            if (isset($t['ll_reaction'][$key])) {
                $lines[] = self::line('ll_reaction', self::SCOPE_BOND, self::LANE_TURN, floatval($sal['ll_reaction']),
                    self::fill((string) $t['ll_reaction'][$key], $vars));
            }
        }

        // --- Place appraisal (decisions §6): runs here, after core set CACHE_LOCATION ---
        if (!empty($rd['ambient_enabled'])) {
            $placeTurn = RelDynFacets::contextTurn($npc, $dynamics, $now);
            if ($placeTurn['changed']) $changed = true;
            if ($placeTurn['text'] !== null) {
                $lines[] = self::line('place', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['place']), (string) $placeTurn['text']);
            }
        }

        // --- Topic / gift resonance (the previous turn's felt read, prose) ---
        foreach (['topic' => '_last_topic_felt', 'gift' => '_last_gift_felt'] as $key => $field) {
            $felt = $dynamics[$field] ?? null;
            if (is_string($felt) && trim($felt) !== '') {
                $lines[] = self::line($key, self::SCOPE_SELF, self::LANE_TURN, floatval($sal[$key]), $felt);
            }
        }

        // --- Combat: shared danger, the glow after ---
        $combat = RelationshipDynamics::getCombatContext($npc);
        if ($combat) {
            if (!empty($combat['bleeding_out'])) {
                $lines[] = self::line('combat', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['bleeding_out']),
                    self::fill((string) $t['combat']['bleeding_out'], $vars));
            } elseif (!empty($combat['in_combat'])) {
                $hp = $combat['health_pct'];   // null: core does not report NPC health
                $k = ($hp !== null && $hp < 0.3) ? 'badly_hurt' : (($hp !== null && $hp < 0.6) ? 'hurt' : 'fighting');
                $lines[] = self::line('combat', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['combat']),
                    self::fill((string) $t['combat'][$k], $vars), ['intense' => true]);
            }
        }
        if (!$combat || empty($combat['in_combat'])) {
            if (RelationshipDynamics::getRecentCombatSummary($npc)) {
                $lines[] = self::line('post_combat', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['post_combat']),
                    self::fill((string) $t['combat']['after'], $vars));
            }
        }

        // --- Attraction (the request's Attraction Matrix read): a first-sight read is fine
        // at tier 0; it follows the bond it sits in and the tier (attraction config) ---
        $ac = (array) $cfg['attraction'];
        $attraction = RelDynAttraction::feltText($npc, (array) ($dynamics['_attraction'] ?? []), [
            'player' => $player,
            'tier' => $tier,
            'passion' => $passion,
            'strained' => !empty($dynamics['in_conflict']) || !empty($env['ick']) || !empty($dynamics['_ick_tracker']['ick_active'])
                || floatval($dims['resentment']['x'] ?? 0) >= floatval($ac['strain_resentment_min'] ?? 51.0)
                || $jealousy >= floatval($ac['strain_jealousy_min'] ?? 60.0),
            'romantic' => in_array((string) ($dynamics['_core_rel_type'] ?? ''), (array) $cfg['romantic_types'], true),
            'flirt_min_tier' => intval($ac['flirt_min_tier'] ?? 2),
            'flirt_passion_min' => floatval($ac['flirt_passion_min'] ?? 40.0),
        ]);
        if (!empty($attraction)) {
            $lines[] = self::line('attraction', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['attraction']),
                $attraction, ['tier0' => true]);
        }

        // --- Duty / parasite ---
        if (floatval($env['duty_factor'] ?? 1.0) < 1.0) {
            $lines[] = self::line('duty', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['duty']), self::fill((string) $t['duty'], $vars));
        }
        if (($dynamics['_relationship_type_override'] ?? null) === 'parasite') {
            $lines[] = self::line('parasite', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['parasite']), self::fill((string) $t['parasite'], $vars));
        }

        // --- Internal weather (the NPC's own mood) ---
        $weather = (string) ($dynamics['_internal_weather'] ?? 'clear');
        if (isset($t['weather'][$weather])) {
            $lines[] = self::line('weather', self::SCOPE_SELF, self::LANE_CORE, floatval($sal['weather']),
                self::fill((string) $t['weather'][$weather], $vars), ['intense' => true]);
        }

        // --- Fulfillment / mature boundary (rulings §9): one-shots are said to the player's face ---
        $felt = RelDynFulfillment::takeFeltTexts($dynamics, $npc, $player, $now, !empty($env['player_addressed']));
        if ($felt['changed']) $changed = true;
        foreach ($felt['texts'] as $kind => $text) {
            if (in_array($kind, ['boundary', 'resolved', 'step_back'], true)) {
                $lines[] = self::line("fulfillment_{$kind}", self::SCOPE_BOND, self::LANE_TURN, 1.0, $text, ['must' => true]);
            } else {
                $lines[] = self::line("fulfillment_{$kind}", self::SCOPE_BOND, self::LANE_CORE,
                    floatval($sal[$kind === 'probation' ? 'probation' : 'unmet']), $text);
            }
        }

        // --- Intimacy need (rulings §10) ---
        $intimacy = RelDynIntimacy::feltText($npc, $player, $dynamics, $now);
        if ($intimacy) $lines[] = self::line('intimacy', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['intimacy']), $intimacy);

        // --- Creature state ---
        $creature = RelationshipDynamics::detectCreatureType($npc, $dynamics);
        if ($creature) {
            $isNight = RelationshipDynamics::isGameNight();
            $k = null;
            if ($creature === 'vampire' && $isNight) $k = 'vampire_night';
            elseif ($creature === 'werewolf' && RelationshipDynamics::isFullMoon()) $k = 'werewolf_moon';
            elseif ($creature === 'werewolf' && $isNight) $k = 'werewolf_night';
            if ($k !== null) {
                $lines[] = self::line('creature', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['creature']), self::fill((string) $t['creature'][$k], $vars));
            }
        }

        // --- Emergent emotions (dimension combinations), romance-only ones inside a romance ---
        $emotions = RelationshipDynamics::detectEmergentEmotions($dynamics);
        $romantic = $coreRomance || (!$platonic && $passion >= floatval($cfg['emergent_romantic_passion_min']));
        $emotions = array_values(array_filter($emotions,
            fn($e) => $romantic || !in_array($e, (array) $cfg['emergent_romantic_only'], true)));
        if ($emotions !== []) {
            $text = RelationshipDynamics::generateEmergentEmotionContext($npc, $emotions);
            if ($text !== '') $lines[] = self::line('emergent', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['emergent']), $text);
        }

        // --- Social masking / mask drop ---
        if (!empty($env['masking'])) {
            $performed = is_array($env['performed'] ?? null) ? $env['performed'] : RelationshipDynamics::calculatePerformedState($dynamics);
            $text = RelationshipDynamics::generateMaskingContext($npc, $dynamics, $performed);
            if ($text !== '') $lines[] = self::line('mask', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['mask']), $text);
        } else {
            $drop = RelationshipDynamics::generateMaskDropContext($npc, $dynamics);
            if ($drop) $lines[] = self::line('mask_drop', self::SCOPE_SELF, self::LANE_TURN, floatval($sal['mask_drop']), $drop);
        }

        // --- Crisis window (PR 10) ---
        $window = $dynamics['_unstable_window'] ?? null;
        if (is_array($window) && empty($window['resolved']) && !empty($rd['divine_intervention_enabled'])) {
            $elapsed = floatval($dynamics['_accumulated_play_gamets'] ?? 0) - floatval($window['start_gamets'] ?? 0);
            $duration = floatval($window['duration_gamets'] ?? 0);
            $window['_elapsed_fraction'] = $duration > 0 ? $elapsed / $duration : 0;
            $text = RelationshipDynamics::generateCrisisNarration($npc, $window);
            if ($text !== '') $lines[] = self::line('crisis', self::SCOPE_SELF, self::LANE_CORE, floatval($sal['crisis']), $text);
        }

        // --- Grief: the two strongest bonds lost ---
        $grief = is_array($dynamics['_grief_bonds'] ?? null) ? $dynamics['_grief_bonds'] : [];
        if ($grief !== [] && !empty($rd['grief_system_enabled'])) {
            uasort($grief, fn($a, $b) => ($b['bond_affinity_at_death'] ?? 0) <=> ($a['bond_affinity_at_death'] ?? 0));
            $maturity = floatval($dims['maturity']['x'] ?? 50);
            foreach (array_slice($grief, 0, 2, true) as $deceased => $g) {
                $text = RelationshipDynamics::getGriefKeywords($npc, (string) $deceased, intval($g['phase'] ?? 1), $maturity);
                if ($text !== '') $lines[] = self::line("grief_{$deceased}", self::SCOPE_SELF, self::LANE_CORE, floatval($sal['grief']), $text);
            }
        }

        // --- Ick / charisma awareness ---
        // (postrequest sets RELDYN_ICK_ACTIVE after the context hooks: the stored tracker is what
        // this request's context can read)
        if (!empty($env['ick']) || !empty($dynamics['_ick_tracker']['ick_active'])) {
            $text = RelationshipDynamics::getIckContext($dynamics, $npc, $dynamics['inferred_temperament'] ?? null);
            if ($text) $lines[] = self::line('ick', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['ick']), $text);
        }
        $charisma = RelationshipDynamics::getCharismaContext($dynamics, $npc);
        if ($charisma) $lines[] = self::line('charisma', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['charisma']), $charisma);

        // --- Autonomy: refusing / walkaway speak; a resistant disposition shows in the bands ---
        if (!empty($rd['autonomy_enabled'])) {
            $temperament = (string) ($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic');
            $eval = RelationshipDynamics::evaluateAutonomyState($dynamics, $temperament);
            $order = ['compliant' => 0, 'resistant' => 1, 'refusing' => 2, 'walkaway' => 3];
            $speaks = ($order[$eval['state']] ?? 0) >= ($order[(string) $cfg['autonomy_min_state']] ?? 2)
                || ($eval['state'] === 'compliant' && !empty($eval['people_pleaser']));
            if ($speaks) {
                $text = RelationshipDynamics::getAutonomyContext($dynamics, $npc, $temperament);
                if ($text) {
                    $walk = $eval['state'] === 'walkaway';
                    $lines[] = self::line('autonomy', self::SCOPE_BOND, self::LANE_CORE,
                        floatval($sal[$walk ? 'walkaway' : 'autonomy']), $text, ['must' => $walk]);
                }
            }
        }

        // --- Hoover (PR 16) ---
        if (!empty($rd['hoover_enabled'])) {
            $text = RelationshipDynamics::getHooverContext($dynamics, $npc);
            if ($text) $lines[] = self::line('hoover', self::SCOPE_BOND, self::LANE_CORE, floatval($sal['hoover']), $text);
        }

        // --- Memories: the strongest moments of this bond and the last eval reasons (tier 2+) ---
        if ($tier >= intval($cfg['memory_min_tier'])) {
            $memory = RelationshipDynamics::buildMemoryContext($dynamics, $npc, $player, intval($cfg['memory_items']));
            if ($memory !== null) $lines[] = self::line('memory', self::SCOPE_BOND, self::LANE_TURN, floatval($sal['memory']), $memory);
        }

        // --- Director goal (what the NPC is set on) ---
        $goal = $env['goal'] ?? null;
        if (is_array($goal) && trim((string) ($goal['text'] ?? '')) !== '') {
            $p = floatval($goal['priority'] ?? 0.5);
            $lines[] = self::line('goal', self::SCOPE_SELF, self::LANE_TURN, $p,
                self::fill((string) $t['goal'][$p >= 0.8 ? 'strong' : ($p >= 0.5 ? 'mind' : 'background')],
                    $vars + ['{GOAL}' => rtrim(trim((string) $goal['text']), '.')]));
        }

        // One referent for the player: older text sources say "the player"; the LLM gets the same
        // name (or stranger reference) the headers define
        $lines = array_values(array_filter(array_map(function (array $l) use ($player): array {
            $l['text'] = self::nameThePlayer($l['text'], $player);
            return $l;
        }, $lines), fn($l) => $l['text'] !== ''));
        return ['lines' => $lines, 'changed' => $changed, 'tier' => $tier, 'player_ref' => $player];
    }

    /** "the player" / "The player" in a felt line -> the player reference (name or 'this stranger'). */
    public static function nameThePlayer(string $text, string $player): string
    {
        return preg_replace_callback('/\b([Tt])he player\b/', fn($m) => $m[1] === 'T' ? ucfirst($player) : $player, $text);
    }

    /**
     * How RelDyn's text names the player: their name from context tier 1 (acquaintance) up;
     * below that 'this stranger', or 'this person' for a hostile core affinity (the prompt
     * gating design's #PLAYER_REF#, so the P3 port and this agree). While core's
     * relationship_system is on it names the player to every NPC in the same <character>
     * block (RelationshipManager::buildContext always lists the player), so RelDyn names them
     * too: one referent, never "a stranger" beside core's "Kaida: Neutral".
     */
    public static function coreNamesPlayer(): bool
    {
        return filter_var($GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** RelDyn's name for the player at this tier (see coreNamesPlayer). */
    public static function playerRef(string $player, int $tier, array $dynamics, ?array $cfg = null): string
    {
        if ($tier >= 1 || self::coreNamesPlayer()) return $player;
        $t = (array) (($cfg ?? self::config())['text']);
        return RelationshipDynamics::getCurrentTier(RelationshipDynamics::getCoreAffinity($dynamics)) === 'hostile'
            ? (string) $t['player_ref_hostile'] : (string) $t['player_ref_stranger'];
    }

    /**
     * Dimension band lines: the band's behavioral keywords when the value sits away from its
     * baseline (or was pushed into an extreme band). M/F and arousal/valence combine into one
     * line each.
     */
    private static function bandLines(array $dynamics, int $tier, array $cfg): array
    {
        $dims = $dynamics['dimensions'];
        $dead = floatval($cfg['baseline_deadband']);
        $w = (array) $cfg['dimension_weight'];
        $bonus = floatval($cfg['extreme_bonus']);
        $hand = (array) $cfg['handwritten_bands'];
        $out = [];
        $x = fn(string $d) => (isset($dims[$d]['x']) && is_numeric($dims[$d]['x'])) ? floatval($dims[$d]['x']) : null;

        // M/F quadrant
        $m = $x('coord_m');
        $f = $x('coord_f');
        if ($m !== null && $f !== null) {
            $dist = max(abs($m - floatval($dims['coord_m']['baseline'] ?? 0)), abs($f - floatval($dims['coord_f']['baseline'] ?? 0)));
            if ($dist > $dead) {
                $band = RelationshipDynamics::getMFQuadrantBand($m, $f);
                $out[] = self::line('coord_mf', self::SCOPE_SELF, self::LANE_CORE, min(1.0, $dist / 100 * floatval($w['coord_mf'] ?? 1)),
                    $band['keywords'], ['intense' => true]);
            }
        }
        // Arousal / valence
        $a = $x('arousal');
        $v = $x('valence');
        if ($a !== null && $v !== null && $a > 10) {
            $dist = max(abs($a - floatval($dims['arousal']['baseline'] ?? 10)), abs($v - floatval($dims['valence']['baseline'] ?? 0)));
            if ($dist > $dead) {
                $band = RelationshipDynamics::getArousalValenceBand($a, $v);
                $extreme = $a > 80;
                $out[] = self::line('arousal_valence', self::SCOPE_SELF, self::LANE_CORE,
                    min(1.0, $dist / 100 * floatval($w['arousal_valence'] ?? 1)) + ($extreme ? $bonus : 0), $band['keywords'], ['intense' => true]);
            }
        }

        foreach (['affinity', 'warmth', 'trust', 'comfort', 'respect', 'resentment', 'maturity', 'self_confidence', 'resentment_self'] as $dim) {
            if (!isset($dims[$dim]) || $x($dim) === null) continue;
            $def = RelationshipDynamics::getDimensionDefinition($dim);
            if (!$def) continue;
            if ($dim === 'affinity') {
                $val = RelationshipDynamics::getCoreAffinity($dynamics);   // core units
                $base = floatval($dims['affinity']['baseline'] ?? RelationshipDynamics::getTemperamentBaseline($dynamics['inferred_temperament'] ?? null, 'affinity', $dynamics));
                $band = RelationshipDynamics::getAffinityBand($dynamics);
            } else {
                $val = $x($dim);
                $base = floatval($dims[$dim]['baseline'] ?? $def['default_baseline']);
                $band = RelationshipDynamics::getDimensionBand($dim, $val);
            }
            if ($band === null || trim((string) $band['keywords']) === '') continue;   // e.g. resentment 'Clean'
            $all = RelationshipDynamics::DIMENSION_BANDS[$dim] ?? [];
            $extreme = $all !== [] && ($band['label'] === $all[0]['label'] || $band['label'] === $all[count($all) - 1]['label']);
            $dist = abs($val - $base);
            // An extreme band the NPC merely rests in (its baseline sits in the same band) is its
            // nature, which the bio carries; only a value pushed there is steering news
            $resting = false;
            if ($extreme && $dim !== 'affinity' && $dist <= $dead) {
                $baseBand = RelationshipDynamics::getDimensionBand($dim, $base);
                $resting = is_array($baseBand) && ($baseBand['label'] ?? null) === $band['label'];
            }
            $salience = min(1.0, $dist / 100 * floatval($w[$dim] ?? 1.0)) + ($extreme ? $bonus : 0.0);
            if ($dim === 'maturity' && $tier >= 2) {
                $salience = max($salience, floatval($cfg['maturity_floor_salience']));
            } elseif ($dist <= $dead && (!$extreme || $resting)) {
                continue;
            }
            $scope = in_array($dim, ['maturity', 'self_confidence', 'resentment_self'], true) ? self::SCOPE_SELF : self::SCOPE_BOND;
            $out[] = self::line($dim, $scope, self::LANE_CORE, $salience, (string) $band['keywords'], [
                'intense' => true,
                'handwritten' => in_array($band['label'], (array) ($hand[$dim] ?? []), true),
                // open hostility shows even to a stranger-tier NPC (core enemies sit at tier 0)
                'tier0' => $dim === 'affinity' && $band['label'] === 'Hostile',
            ]);
        }
        return $out;
    }

    // =====================================================================
    // REASONS FROM THE EVAL (memory line): felt, never numbers or stated feelings
    // =====================================================================

    const NUMBER_WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];

    /** A clause naming a dimension, a score or a feeling instead of what happened. */
    const STATED_FEELING_PATTERN = '/\b(affinity|trust(s|ed|ing)?|distrust(s|ed)?|comfort(able)?|respect(s|ed)?|warmth|passion(ate)?|'
        . 'resent(s|ed|ment)?|jealous(y)?|maturity|arousal|valence|attract(ed|ion)?|feel(s|ing|ings)?|felt|'
        . 'signal|score[ds]?|points?|likes?|loves?)\b/i';

    /**
     * An eval summary as it may reach the <subtext> memory line (decisions §3: never numbers,
     * never "she now trusts the player"). The eval LLM writes one free line; this keeps what
     * HAPPENED and drops how it was scored:
     *   - bracketed asides are dropped;
     *   - small counts (0..20) are written out ("3 bandits" -> "three bandits");
     *   - clauses (split on ';', ':', ' - ', ', and she ...' style joins are kept whole) that
     *     still hold a digit, a sign-number, or name a dimension / feeling are dropped.
     * Returns null when nothing of the event is left.
     */
    public static function sanitizeReason(string $reason): ?string
    {
        $r = trim(preg_replace('/\s+/', ' ', $reason));
        if ($r === '') return null;
        $r = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/', '', $r);
        $r = preg_replace_callback('/(?<![\d.+\-])\b(\d{1,2})\b(?![\d.%])/', function ($m) {
            $n = intval($m[1]);
            return $n <= 20 ? self::NUMBER_WORDS[$n] : $m[0];
        }, $r);
        $clauses = preg_split('/\s*(?:;|:|\s[-\x{2013}\x{2014}]\s|,\s*(?=(?:and\s+|so\s+|now\s+)?(?:she|he|they)\b))\s*/u', $r);
        $keep = [];
        foreach ((array) $clauses as $c) {
            $c = trim($c, " \t,.");
            if ($c === '') continue;
            if (preg_match('/\d|%/', $c) || preg_match(self::STATED_FEELING_PATTERN, $c)) continue;
            $keep[] = $c;
        }
        if ($keep === []) return null;
        return implode('; ', $keep);
    }

    // =====================================================================
    // SELECT (tier, caps, budget)
    // =====================================================================

    /** Most salient first; must lines first of all. Stable for equal salience. */
    private static function sortLines(array $lines): array
    {
        $i = 0;
        foreach ($lines as &$l) $l['_i'] = $i++;
        unset($l);
        usort($lines, fn($a, $b) => [$b['must'], $b['salience'], $a['_i']] <=> [$a['must'], $a['salience'], $b['_i']]);
        return array_map(function ($l) { unset($l['_i']); return $l; }, $lines);
    }

    /**
     * The lines that reach the LLM at this context tier: tier 0 (stranger / hostile) keeps
     * the NPC's own state plus lines marked tier0; the tier's line cap and token budget drop
     * the least salient lines; must lines always stay. Intensity is applied here.
     */
    public static function select(string $npc, string $player, array $lines, int $tier, array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if ($tier <= 0) {
            $lines = array_filter($lines, fn($l) => $l['must'] || $l['scope'] === self::SCOPE_SELF || $l['tier0']);
        }
        $lines = self::sortLines(array_values($lines));
        $max = intval(((array) $cfg['tier_max_lines'])[$tier] ?? 10);
        $kept = [];
        $n = 0;
        foreach ($lines as $l) {
            if ($l['must'] || $n < $max) {
                $kept[] = $l;
                if (!$l['must']) $n++;
            }
        }
        foreach ($kept as &$l) {
            if ($l['intense']) {
                $l['text'] = self::intensify($l['text'], $dynamics, $l['handwritten'], $cfg);
            }
        }
        unset($l);
        $budget = intval(((array) $cfg['tier_token_budget'])[$tier] ?? 300);
        while (count($kept) > 0 && self::estimateTokens((string) self::renderSubtext($npc, $player, $kept, $cfg)) > $budget) {
            $drop = null;
            for ($i = count($kept) - 1; $i >= 0; $i--) {
                if (!$kept[$i]['must']) { $drop = $i; break; }
            }
            if ($drop === null) break;
            array_splice($kept, $drop, 1);
        }
        return $kept;
    }

    /**
     * Split the selected lines: the $n most salient enduring ('core' lane) lines go to the
     * <character> block (primacy), the rest stay in <subtext> (recency).
     * @return array [core lines, recency lines]
     */
    public static function splitCore(array $selected, int $n): array
    {
        $core = [];
        $rest = [];
        foreach ($selected as $l) {
            if (count($core) < $n && $l['lane'] === self::LANE_CORE && !$l['must']) $core[] = $l;
            else $rest[] = $l;
        }
        return [$core, $rest];
    }

    // =====================================================================
    // RENDER
    // =====================================================================

    /** The recency block: one <subtext> with the lines, most salient first. Null when empty. */
    public static function renderSubtext(string $npc, string $player, array $lines, ?array $cfg = null): ?string
    {
        if ($lines === []) return null;
        $cfg = $cfg ?? self::config();
        $bond = false;
        foreach ($lines as $l) if ($l['scope'] === self::SCOPE_BOND) $bond = true;
        $header = strtr((string) $cfg['text'][$bond ? 'header_bond' : 'header_self'], ['{NAME}' => $npc, '{PLAYER}' => $player]);
        return "<subtext>\n{$header}\n" . implode("\n", array_map(fn($l) => '- ' . $l['text'], $lines)) . "\n</subtext>";
    }

    /**
     * What the NPC knows of the player, by context tier (feedback_context_engineering_v2 P0):
     * a stranger knows only what can be seen (no name, unless core's relationship block names
     * the player anyway: coreNamesPlayer), an acquaintance a little, a friend
     * well, a bonded NPC deeply; a tier-2 floor held by the high-water mark while affinity has
     * fallen reads "lapsed". One tension bridge (e.g. devoted but closed) from tier 1 up.
     */
    public static function knowledgeOfPlayer(string $npc, string $player, array $dynamics, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $t = (array) $cfg['text'];
        $tier = RelationshipDynamics::getContextTier($dynamics);
        // the player's name only where RelDyn names them (playerRef: tier 1 up, or core names them)
        $vars = ['{NAME}' => $npc, '{PLAYER}' => self::playerRef($player, $tier, $dynamics, $cfg)];
        $core = RelationshipDynamics::getCoreAffinity($dynamics);
        $current = RelationshipDynamics::getAffinityContextTier($dynamics);
        if ($tier <= 0) {
            $key = RelationshipDynamics::getCurrentTier($core) === 'hostile' ? 'hostile'
                : (self::coreNamesPlayer() ? 'stranger_named' : 'stranger');
        } elseif ($tier === 1) {
            $key = 'acquaintance';
        } elseif ($tier === 2) {
            $key = $current < 2 ? 'lapsed' : 'friend';
        } else {
            $key = 'bonded';
        }
        $text = strtr((string) $t['knowledge'][$key], $vars);
        if ($tier >= 1) {
            $bridge = self::bridge($dynamics, $tier, $current, $cfg);
            if ($bridge !== null) $text .= ' ' . strtr((string) $t['bridge'][$bridge], $vars);
        }
        return $text;
    }

    /** The one tension bridge that applies (first match), or null. */
    private static function bridge(array $dynamics, int $tier, int $current, array $cfg): ?string
    {
        $b = (array) $cfg['bridge'];
        $dims = $dynamics['dimensions'] ?? [];
        $v = fn(string $d, float $def) => is_numeric($dims[$d]['x'] ?? null) ? floatval($dims[$d]['x']) : $def;
        $warmth = $v('warmth', 50.0);
        $trust = $v('trust', 50.0);
        $resentment = $v('resentment', 0.0);
        $passion = RelationshipDynamics::getPassion($dynamics);
        if ($passion >= floatval($b['drawn_passion_min']) && $warmth <= floatval($b['guarded_warmth_max'])) return 'drawn_but_guarded';
        if ($current >= 2 && $resentment >= floatval($b['worn_resentment_min'])) return 'worn_out';
        if ($current >= 2 && $warmth <= floatval($b['closed_warmth_max'])) return 'cares_but_closed';
        if ($current >= 2 && $trust <= floatval($b['wary_trust_max'])) return 'fond_but_wary';
        if ($current >= 2 && $v('comfort', 50.0) <= floatval($b['uneasy_comfort_max'])) return 'close_but_uneasy';
        if ($tier >= 3 && $trust >= floatval($b['steady_trust_min']) && $resentment <= floatval($b['steady_resentment_max'])) return 'steady';
        return null;
    }

    /**
     * The <character> block context_pre adds: <knowledge_of_player> (skipped when another
     * plugin, e.g. the P3 prompt-gating port, already wrote one) and the emotional core.
     */
    public static function renderCharacterBlock(string $npc, string $player, string $knowledge, array $core, bool $knowledgePresent, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $parts = [];
        if (!$knowledgePresent && $knowledge !== '') {
            $parts[] = "<knowledge_of_player>\n{$knowledge}\n</knowledge_of_player>";
        }
        if ($core !== []) {
            $header = strtr((string) $cfg['text']['core_header'], ['{NAME}' => $npc, '{PLAYER}' => $player]);
            $parts[] = "<emotional_core>\n{$header}\n" . implode("\n", array_map(fn($l) => '- ' . $l['text'], $core)) . "\n</emotional_core>";
        }
        return implode("\n", $parts);
    }

    // =====================================================================
    // INTENSITY (feedback_intensity_formatting): bounded, deterministic
    // =====================================================================

    private static function level(float $value, array $at): int
    {
        $lvl = 0;
        foreach (array_values($at) as $i => $threshold) {
            if ($value >= floatval($threshold)) $lvl = $i + 1;
        }
        return $lvl;
    }

    /** Degradation level 0..3 from maturity (0..100 points): 0 none, 1 light, 2 moderate, 3 heavy. */
    public static function degradationLevel(float $maturity, ?array $cfg = null): int
    {
        $at = array_values((array) (($cfg ?? self::config())['intensity']['maturity_at']));
        $lvl = 0;
        foreach ($at as $i => $threshold) {
            if ($maturity < floatval($threshold)) $lvl = $i + 1;
        }
        return $lvl;
    }

    /** Intensity level 0..3 from the larger of arousal and passion (0..100 points). */
    public static function intensityLevel(float $arousal, float $passion, ?array $cfg = null): int
    {
        $at = (array) (($cfg ?? self::config())['intensity']['level_at']);
        return max(self::level($arousal, $at), self::level($passion, $at));
    }

    /**
     * One line through the intensity engine: maturity degradation (unless hand-written),
     * then arousal / passion emphasis, then the numb / hollow flattening.
     */
    public static function intensify(string $text, array $dynamics, bool $handwritten = false, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $dims = $dynamics['dimensions'] ?? [];
        $num = fn(string $d, float $def) => is_numeric($dims[$d]['x'] ?? null) ? floatval($dims[$d]['x']) : $def;
        $arousal = $num('arousal', 10.0);
        $valence = $num('valence', 0.0);
        $maturity = $num('maturity', 60.0);
        $passion = RelationshipDynamics::getPassion($dynamics);
        $ic = (array) $cfg['intensity'];

        if (!$handwritten) $text = self::degrade($text, self::degradationLevel($maturity, $cfg), $cfg);
        $text = self::emphasize($text, self::intensityLevel($arousal, $passion, $cfg), $cfg);
        if ($arousal < floatval($ic['hollow_arousal_below']) && $valence < floatval($ic['hollow_valence_below'])
            && $passion < floatval($ic['level_at'][0])) {
            $text = self::flatten($text);
        }
        return $text;
    }

    private static function isEmphasisWord(string $word, array $cfg): bool
    {
        static $sets = [];
        $key = md5(json_encode($cfg['emphasis_words']));
        if (!isset($sets[$key])) {
            $sets[$key] = array_flip(array_map('strtolower', array_merge(RelationshipDynamics::EMOTIONAL_WORDS, (array) $cfg['emphasis_words'])));
        }
        return isset($sets[$key][strtolower(trim($word, ",.;:!?'\""))]);
    }

    /**
     * Arousal / passion emphasis by level 0..3 (config intensity): level 1 one '!' after the
     * first phrase; 2 a couple of emphasis words in CAPS and '!'; 3 the first phrase in CAPS
     * (when short) plus up to three CAPS words, '!!!'. At most caps_words words and three '!'
     * in a row: the line stays readable.
     */
    public static function emphasize(string $text, int $level, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $ic = (array) $cfg['intensity'];
        if ($level <= 0 || trim($text) === '') return $text;
        $phrases = explode(', ', $text);

        // CAPS
        $capsFirst = $level >= intval($ic['caps_first_phrase_at'])
            && str_word_count($phrases[0]) <= intval($ic['caps_phrase_max_words']);
        if ($capsFirst) $phrases[0] = strtoupper($phrases[0]);
        $capsLeft = intval($ic['caps_words'][$level] ?? 0);
        foreach ($phrases as $pi => &$phrase) {
            if ($capsLeft <= 0) break;
            if ($pi === 0 && $capsFirst) continue;
            $words = explode(' ', $phrase);
            foreach ($words as &$word) {
                if ($capsLeft > 0 && self::isEmphasisWord($word, $cfg)) {
                    $word = strtoupper($word);
                    $capsLeft--;
                }
            }
            unset($word);
            $phrase = implode(' ', $words);
        }
        unset($phrase);

        // Exclamation: after the first phrase (the comma it replaces goes), and at the end
        $first = min(3, intval($ic['exclaim_first'][$level] ?? 0));
        $end = min(3, intval($ic['exclaim_end'][$level] ?? 0));
        if (count($phrases) > 1 && $first > 0) {
            $head = rtrim(array_shift($phrases), ' .!') . str_repeat('!', $first);
            $out = $head . ' ' . implode(', ', $phrases);
        } else {
            $out = implode(', ', $phrases);
            if (count($phrases) === 1) $end = max($end, $first);
        }
        if ($end > 0) {
            $out = rtrim($out, ' .!') . str_repeat('!', $end);
        }
        return $out;
    }

    /**
     * Low-maturity degradation by level 0..3 (feedback_intensity_formatting: the text IS the
     * instability): light trails off after the first phrase; moderate adds chaotic casing on a
     * couple of long words and one missing letter; heavy more of both and a second break.
     * Deterministic (the same line degrades the same way), one change per word at most,
     * words shorter than degrade_min_word_len untouched.
     */
    public static function degrade(string $text, int $level, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $ic = (array) $cfg['intensity'];
        if ($level <= 0 || trim($text) === '') return $text;
        if ($level === 1 && crc32($text) % max(1, intval($ic['light_every'])) !== 0) return $text;
        $minLen = max(4, intval($ic['degrade_min_word_len']));

        $words = explode(' ', $text);
        // candidate long words, ranked deterministically (emphasis words first)
        $cand = [];
        foreach ($words as $i => $w) {
            $core = preg_replace('/[^A-Za-z]/', '', $w);
            if (strlen($core) >= $minLen && ctype_lower($core)) {
                $cand[$i] = [self::isEmphasisWord($w, $cfg) ? 0 : 1, crc32($core) % 997, $i];
            }
        }
        uasort($cand, fn($a, $b) => $a <=> $b);
        $order = array_keys($cand);
        $caseN = intval($ic['degrade_case_words'][$level] ?? 0);
        $dropN = intval($ic['degrade_drop_words'][$level] ?? 0);
        $touched = [];
        foreach (array_slice($order, 0, $caseN) as $i) {
            $words[$i] = self::chaoticCase($words[$i]);
            $touched[$i] = true;
        }
        $dropped = 0;
        foreach ($order as $i) {
            if ($dropped >= $dropN) break;
            if (isset($touched[$i])) continue;
            $new = self::dropLetter($words[$i]);
            if ($new !== $words[$i]) { $words[$i] = $new; $dropped++; }
        }
        $text = implode(' ', $words);

        $pauses = intval($ic['degrade_pauses'][$level] ?? 0);
        if ($pauses > 0) {
            $parts = explode(', ', $text);
            if (count($parts) > 1) {
                $mark = $level >= 3 ? '.. ' : '... ';
                $out = '';
                foreach ($parts as $pi => $p) {
                    $out .= $p;
                    if ($pi < count($parts) - 1) $out .= ($pi < $pauses) ? $mark : ', ';
                }
                $text = $out;
            } else {
                $text = rtrim($text, '.') . '...';
            }
        }
        return $text;
    }

    /** "dysregulated" -> "dYsREguLAted": deterministic mixed case, first letter kept. */
    private static function chaoticCase(string $word): string
    {
        $bits = crc32(strtolower($word)) | 0x5;
        $out = '';
        $letters = 0;
        for ($i = 0, $n = strlen($word); $i < $n; $i++) {
            $c = $word[$i];
            if (ctype_alpha($c)) {
                $out .= ($letters > 0 && (($bits >> ($letters % 24)) & 1)) ? strtoupper($c) : $c;
                $letters++;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    /** One interior vowel (the middle-most) becomes '_': "emotions" -> "emot_ons". */
    private static function dropLetter(string $word): string
    {
        $n = strlen($word);
        $best = null;
        for ($i = 1; $i < $n - 1; $i++) {
            if (strpos('aeiou', $word[$i]) !== false && ctype_alpha($word[$i - 1]) && ctype_alpha($word[$i + 1])) {
                if ($best === null || abs($i - $n / 2) < abs($best - $n / 2)) $best = $i;
            }
        }
        if ($best === null) return $word;
        $word[$best] = '_';
        return $word;
    }

    /** Numb / hollow: lowercase, trailing off, one hedge. */
    public static function flatten(string $text): string
    {
        $phrases = array_map('trim', explode(',', strtolower(rtrim($text, '.!'))));
        return implode('... ', array_filter($phrases, fn($p) => $p !== '')) . '... hard to tell';
    }

    // =====================================================================
    // HOOKS
    // =====================================================================

    /** The request's environment for compose(), from the hook globals. */
    public static function envFromGlobals(array $dynamics): array
    {
        $cfg = RelationshipDynamics::getConfig();
        return [
            'player_addressed' => RelationshipDynamics::isPlayerInputRequest($GLOBALS['gameRequest'] ?? null),
            'last_ll' => $GLOBALS['RELDYN_LAST_INTERACTION_LL'] ?? null,
            'duty_factor' => floatval($GLOBALS['RELDYN_DUTY_FACTOR'] ?? 1.0),
            'masking' => !empty($GLOBALS['RELDYN_MASKING_ACTIVE']),
            'performed' => $GLOBALS['RELDYN_PERFORMED_STATE'] ?? null,
            'ick' => !empty($GLOBALS['RELDYN_ICK_ACTIVE']),
            'goal' => !empty($cfg['director_goals_enabled'])
                ? ($GLOBALS['RELDYN_DIRECTOR_GOAL'] ?? RelationshipDynamics::getActiveDirectorGoal($dynamics)) : null,
        ];
    }

    /** Compose and select for the current request's NPC; saves the dynamics once if changed. */
    private static function build(string $npc, string $player): ?array
    {
        self::$lastRendered = [];
        $dynamics = RelationshipDynamics::getDynamics($npc);
        if (empty($dynamics['love_language_primary'])) {
            return null;   // not initialised yet (prerequest generates the profile)
        }
        $cfg = self::config();
        $composed = self::compose($npc, $player, $dynamics, RelationshipDynamics::currentGamets(), self::envFromGlobals($dynamics));
        if ($composed['changed']) {
            RelationshipDynamics::saveDynamics($npc, $dynamics);
        }
        $player = $composed['player_ref'];
        $selected = self::select($npc, $player, $composed['lines'], $composed['tier'], $dynamics, $cfg);
        foreach ($selected as $l) self::$lastRendered[$l['key']] = $l['text'];
        RelationshipDynamics::log("[FELT] {$npc}: tier {$composed['tier']}, " . count($composed['lines']) . ' candidates, '
            . count($selected) . ' kept (' . implode(', ', array_map(fn($l) => $l['key'], $selected)) . ')');
        return ['dynamics' => $dynamics, 'lines' => $selected, 'tier' => $composed['tier'], 'cfg' => $cfg, 'player_ref' => $player];
    }

    /**
     * context_pre.php: <knowledge_of_player> and the emotional core into HERIKA_PERS (inside
     * <character>, main.php builds it right after the context_pre hooks), and the remaining
     * lines handed to context.php. Off (context_pre_enabled false): does nothing, and
     * context.php renders every line.
     */
    public static function contextPre(string $npc, string $player): void
    {
        unset($GLOBALS[self::HANDOFF_GLOBAL]);
        if (empty(RelationshipDynamics::getConfig()['context_pre_enabled'])) return;
        $built = self::build($npc, $player);
        if ($built === null) return;
        $cfg = $built['cfg'];
        [$core, $rest] = self::splitCore($built['lines'],
            $built['tier'] >= intval($cfg['core_min_tier']) ? intval($cfg['core_lines']) : 0);
        $pers = (string) ($GLOBALS['HERIKA_PERS'] ?? '');
        $knowledgePresent = stripos($pers, '<knowledge_of_player>') !== false;
        $knowledge = self::knowledgeOfPlayer($npc, $player, $built['dynamics'], $cfg);
        $block = self::renderCharacterBlock($npc, $built['player_ref'], $knowledge, $core, $knowledgePresent, $cfg);
        if ($block !== '') {
            $GLOBALS['HERIKA_PERS'] = ($cfg['position'] === 'prepend')
                ? $block . "\n\n" . $pers
                : rtrim($pers) . "\n\n" . $block;
        }
        $GLOBALS[self::HANDOFF_GLOBAL] = ['npc' => $npc, 'lines' => $rest, 'player_ref' => $built['player_ref']];
        RelationshipDynamics::log("[FELT] {$npc}: context_pre ~" . self::estimateTokens($block) . ' tokens in <character>');
    }

    /**
     * context.php: the one <subtext> block after the dialogue history (recency), from
     * context_pre's remaining lines, or composed here when context_pre did not run.
     */
    public static function contextPost(string $npc, string $player): void
    {
        $handoff = $GLOBALS[self::HANDOFF_GLOBAL] ?? null;
        unset($GLOBALS[self::HANDOFF_GLOBAL]);
        if (is_array($handoff) && ($handoff['npc'] ?? null) === $npc) {
            $lines = (array) $handoff['lines'];
            $ref = (string) ($handoff['player_ref'] ?? $player);
        } else {
            $built = self::build($npc, $player);
            if ($built === null) return;
            $lines = $built['lines'];
            $ref = $built['player_ref'];
        }
        $block = self::renderSubtext($npc, $ref, $lines);
        if ($block !== null) {
            $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => $block];
            RelationshipDynamics::log("[FELT] {$npc}: <subtext> " . count($lines) . ' lines, ~' . self::estimateTokens($block) . ' tokens');
        }
    }
}
