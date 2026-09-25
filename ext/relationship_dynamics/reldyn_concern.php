<?php
/**
 * Relationship Dynamics — protective concern, and the values path of both channels.
 *
 * Personality-traits design §1.1-1.6 (decisions §14, Ken's bar example): two channels react
 * to what happens to the player.
 *   - POSSESSIVE (trait Po): fear of losing the player to someone. Kinds 'rival' (flirting or
 *     affection to others: the eval jealousy flag, the bystander scan) and 'rival_exposure'
 *     (the player out where suitors are, while the NPC was not there). The feeling is the
 *     existing jealousy (jealousy points 0..100), damped STRONGLY by trust
 *     (RelationshipDynamics::jealousyTrustFactor).
 *   - PROTECTIVE (trait Pr): fear of the player getting hurt. Kinds 'danger', 'vice' (drink,
 *     skooma), 'company' (bad company) and 'place' (a risky venue: a tavern at night among
 *     strangers, a skooma den). The feeling is a new concern state (concern points 0..100),
 *     damped only SLIGHTLY by trust.
 * Crowd alone is not risk (§1.3): a busy market by day is not the Bannered Mare at night.
 *
 * The repetition counter is pattern[kind] (§1.5, §1.6): an incident is one channel on one
 * "night" (social day) with the kinds seen that night, deduplicated however many routes reveal
 * it; each kind counts once per night. The feeling is per channel and night; the count is per
 * kind, so a danger day, a tavern night and a drink on another day are three different
 * patterns, not one. An incident counts when the NPC's sensitivity to it (Po / Pr) reaches
 * sensitivity_min; trust damps the feeling, never the count. First counted exposure of a
 * kind: a mature NPC states its values once, plainly; an immature one controls, accuses or
 * sulks (its style from its traits). Second: a felt reminder. The values_at-th of a kind
 * within window_game_days: grievance values_conflict:<kind> through the §5 grievance channel
 * (one per channel and night: kinds that reach it together file together under the first in
 * KINDS order), then the §9 boundary flow for a mature NPC (calm statement, probation, a
 * deliberate step-back if that kind happens again) or a blow-up with +50% resentment for an
 * immature one. Reassurance addresses one incident. One boundary at a time per bond: the
 * values boundary waits while the fulfillment boundary (§9) runs, and holds a romance
 * promotion back (RelDynRomance::blockingStates).
 *
 * How an NPC who was not there finds out (§1.2):
 *   route A  the player says so: the eval contract's optional 'exposure' field (onEvalItem);
 *   route B  the return check at the first contact after an absence: eventlog cues the NPC
 *            can perceive (the player drank ale / skooma within drunk_window_game_hours, the
 *            player comes back late from an inn) (onContact);
 *   present  the NPC is with the player: the place appraisal sees the place's danger directly
 *            (onPresentPlace, from the felt turn's place read; §1.3's crypt rows). A shared
 *            tavern night is not an exposure (§1.2), so only 'danger' is read here. 'company'
 *            (bad company) has no 3.4.1 detector: route A (or route C, later) only.
 *   route C  a witness tells (NPC <-> NPC): LATER, after per-relationship data (decisions
 *            §11). The hook is learn() with route 'C': a witness report is a learn() call like
 *            the others, deduplicated by the same (channel, social day) key.
 *
 * Felt text only for the LLM (worry, stated values; never numbers); Jev gets the numbers.
 * State: $dynamics['_concern'] (see state()). Units: risk and traits 0..1 (unitless),
 * intensity 0..3, concern points 0..100, game hours / days on the game calendar (raw gamets /
 * RelationshipDynamics::GAMETS_PER_DAY). No wall clock.
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynConcern
{
    const STATE_KEY = '_concern';
    const VERSION = 1;

    const POSSESSIVE = 'possessive';
    const PROTECTIVE = 'protective';
    /**
     * The resentment lane's channel on the values boundary (RelDynResentment, MDD 15.5 at 50):
     * a mature NPC who already said it calmly and was wronged again. Not a KINDS channel: it
     * has no incidents, only the boundary (openGrievanceBoundary / onGrievance).
     */
    const GRIEVANCE = 'grievance';
    const GRIEVANCE_KIND = 'grievances';

    /** Kind => channel (design §1.1). Order = the order a channel's kind is named in. */
    const KINDS = [
        'rival_exposure' => self::POSSESSIVE,
        'rival'          => self::POSSESSIVE,
        'place'          => self::PROTECTIVE,
        'vice'           => self::PROTECTIVE,
        'danger'         => self::PROTECTIVE,
        'company'        => self::PROTECTIVE,
    ];

    /** Route A 'when' values the eval may give (additive contract field). */
    const WHEN = ['today', 'last_night', 'earlier'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'concern' (a stored config replaces whole settings/tables;
     * 'felt_text' and 'kind_phrases' merge per entry). Numbers are the design's starting values
     * (§1.3-1.5, Q10 "ship as starting values, tune after a playtest") unless marked.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,

            // Traits when the NPC has no vector yet (design §4.5 base vector: 0.5, Po 0.325).
            'default_traits' => ['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5,
                                 'W' => 0.5, 'D' => 0.5, 'Po' => 0.325, 'Pr' => 0.5],

            // --- risk appraisal (§1.3), unitless 0..1 ---
            'risk' => [
                // danger appetite = combat x max(0, pref_combat) + confidence x C: covers danger
                'appetite_combat' => 0.5,
                'appetite_confidence' => 0.3,
                // Venue taste (Serene, for Ken's review; the design's danger appetite applied to
                // the venue kinds): an NPC who likes that kind of place herself (the bard whose
                // life is the inn) sees less risk in its drink and strangers: vice_r and
                // stranger_r lose venue_taste x max(0, her appraisal valence of the venue's
                // facets) (RelDynFacets::appraise, -1..1; decisions §6: places belong to
                // interests, §14). A taste for fighting is not a taste for taverns: the invented
                // combat-based tolerance is gone. 0 = the design's formula exactly; an NPC who
                // does not like the venue is unaffected, so the design's place table holds.
                'venue_taste' => 0.7,
                // venue class => vice risk (at night; x vice_day_mult by day)
                'venue_vice' => ['inn' => 0.5, 'den' => 1.0],
                'vice_day_mult' => 0.6,
                // a consume row lifts vice to at least this (CONSUMABLE_EFFECTS keys)
                'consume_lift' => ['ale' => 0.6, 'skooma' => 1.0],
                // stranger_r = min(1, strangers / strangers_full) x social_venue x (night | day mult)
                'strangers_full' => 6,
                'social_venue' => ['inn' => 1.0, 'town' => 0.4, 'den' => 0.2, 'other' => 0.2],
                'stranger_night_mult' => 0.5,
                'stranger_day_mult' => 0.25,
                // risk -> intensity: 0 below the first, 1, 2, 3 at or above the last
                'intensity_at' => [0.20, 0.45, 0.70],
                // route A: a disclosed intensity read as a risk (band middles) before perception
                'intensity_risk' => [0.0, 0.325, 0.575, 0.85],
                // rival exposure: min(3, ceil(suitors / this)), halved (down) by day
                'suitors_per_level' => 3,
                // night = game hours [from, to) wrapping midnight (place_facets night)
                'night_hours' => [20, 5],
                // Venue classes: core locations.tags or name keywords (whole word / word start or end)
                'venues' => [
                    // (no bare 'den': it ends too many names, Warmaiden's is a smithy)
                    'den'  => ['tags' => [], 'keywords' => ['skooma']],
                    'inn'  => ['tags' => ['Inn'], 'keywords' => ['inn', 'tavern', 'pub', 'mead', 'mare', 'hearth', 'skeever',
                                                                  'sleeping giant', 'winking skeever', 'silver-blood', 'frostfruit',
                                                                  'windpeak', 'nightgate', 'braidwood', 'vilemyr', 'dead man',
                                                                  'moorside', 'retching netch', 'ragged flagon']],
                    'town' => ['tags' => ['City', 'Town'], 'keywords' => ['market']],
                ],
            ],

            // --- concern (§1.4), concern points 0..100 ---
            'concern_base'   => 12.0,                 // points per incident at intensity 0/1, Pr 0.5, before damping
            'intensity_mult' => [1.0, 1.0, 1.5, 2.0], // by intensity 0..3
            'trust_damping'  => 0.15,                 // x (1 - this x trust / 100): mild
            'gain_max'       => 30.0,                 // points per incident (the eval SIGNAL_LIMITS scale)
            'max'            => 100.0,
            'decay_per_game_hour' => 4.0,             // while the player is with them (contact)
            'present_gap_game_hours' => 2.0,          // contacts this close count as time together
            'levels' => [25.0, 50.0, 75.0],           // felt line / raises it / insists (§1.4 table)
            'reassurance_relief' => 15.0,             // points a reassurance takes off (Serene)

            // --- pattern (§1.5) ---
            'sensitivity_min'  => 0.35,  // Po (possessive) / Pr (protective) from which an incident counts
            'window_game_days' => 7,
            'values_at'        => 3,     // counted incidents in the window -> values_conflict
            'day_start_hour'   => 6,     // a night belongs to the game day it started on (dedupe key)
            'mature_at'        => 60.0,  // maturity (0..100) from which the expression is mature ...
            'immature_at'      => 40.0,  // ... and at or below which immature; bands blend between
            // grievance severity (grievance_severity_mult [1, 1, 1.5, 2]): immature = +50% (MDD 15.5)
            'mature_severity'   => 1,
            'immature_severity' => 2,
            'probation_game_days' => 7.0,   // the §9 boundary's watch window after the statement

            // --- route B (return check) ---
            'return_min_game_hours'   => 3.0,   // time apart before a contact is a return
            'drunk_window_game_hours' => 6.0,   // a drink this recent still shows on the player
            'late_hours'              => [22, 5],
            'return_scan_rows'        => 200,

            // Protective / possessive incidents count toward values only for bonds whose neglect
            // matters (neglect_bond_types); possessive ones only for committed bonds
            // (jealousy_bystander_commitment by core Player.type).

            // --- immature expression (§1.5 "control, accusation or sulking") ---
            // style score = sum(weight x (trait - 0.5)); the highest wins. High Po pushes toward control.
            'styles' => [
                'control'    => ['Po' => 1.0, 'C' => 0.5],
                'accusation' => ['L' => 1.0, 'Pd' => 0.5],
                'sulking'    => ['E' => -1.0, 'C' => -0.5],
            ],

            // Line salience (RelDynFelt, 0..1)
            'salience' => ['worry_offset' => 0.2, 'reminder' => 0.6, 'probation' => 0.85],

            // --- felt text (feelings, never numbers). {NAME} NPC, {PLAYER} player, {KIND} the
            // kind as the NPC names it, {STYLE} the immature style phrase, {HOW} its adverb ---
            'felt_text' => [
                'worry_uneasy'  => "worried for {PLAYER}: keeps an eye on them, asks where they have been",
                'worry_raise'   => "worried enough to bring it up: wants to talk about {KIND}",
                'worry_insist'  => "frightened for {PLAYER}: will not let {KIND} go unsaid and pushes to be heard",
                'worry_mature'  => "; says so plainly, without blame",
                'noticed'       => "{NAME} notices {CUES} the moment {PLAYER} is back, and takes it in before saying anything",
                'stated_mature' => "{NAME} tells {PLAYER} once, plainly and without blame: {KIND} is not something {NAME} values, "
                    . "and {NAME} can see it turning into a fight between them if it keeps up. A statement of where {NAME} stands, not an order; then {NAME} lets it rest.",
                'stated_mixed'  => "{NAME} means to say it evenly, that {KIND} is not something {NAME} values, but it comes out {HOW}.",
                'stated_control'    => "{NAME} tries to put a stop to it: tells {PLAYER} there will be no more of {KIND}, as if it were {NAME}'s to decide.",
                'stated_accusation' => "{NAME} throws {KIND} at {PLAYER} as an accusation: where were they, what were they thinking, who were they with.",
                'stated_sulking'    => "{NAME} goes cold about {KIND}: short answers, no warmth, waiting for {PLAYER} to ask what is wrong.",
                'reminder'      => "{KIND} again: a pointed look, a remark that it keeps happening",
                'boundary'      => "{NAME} has thought about it calmly and says it plainly: {KIND} keeps happening, and it is not what {NAME} wants "
                    . "from the two of them. No shouting, no ultimatum; if it goes on, {NAME} will step back. Now {NAME} watches whether it changes.",
                'probation'     => "{NAME} said where they stand about {KIND}; now quietly watching whether it happens again",
                'blowup'        => "It boils over: {NAME} has had enough of {KIND} and it all comes out at once, {HOW}, older hurts dragged in with it.",
                'resolved'      => "{NAME} has noticed that {KIND} has stopped since they spoke; the watchfulness eases out of them.",
                'step_back'     => "{NAME} has made a decision and is at peace with it: {KIND} went on after {NAME} said where they stand, "
                    . "so {NAME} is stepping back from {FROM} to {TO}. No scene, no bitterness; kind, but that closeness is over.",
            ],
            'style_phrases' => [
                'control'    => ['phrase' => "wants to keep {PLAYER} where they can be watched", 'how' => 'as a demand'],
                'accusation' => ['phrase' => "the worry keeps coming out sharp, as blame", 'how' => 'as an accusation'],
                'sulking'    => ['phrase' => "the worry turns inward: cool, quiet, waiting to be asked", 'how' => 'clipped and cold, then silence'],
            ],
            'kind_phrases' => [
                'rival_exposure' => "nights out where others are circling {PLAYER}",
                'rival'          => "{PLAYER}'s attention to others",
                'place'          => "late nights at the tavern among strangers",
                'vice'           => "the drinking",
                'danger'         => "the risks {PLAYER} keeps taking",
                'company'        => "the company {PLAYER} keeps",
                // the resentment lane's channel (GRIEVANCE): a mature NPC wronged again after saying it
                'grievances'     => "being hurt the same way after already speaking up",
            ],
            'cue_phrases' => [
                'ale'    => "the drink on {PLAYER}",
                'skooma' => "the skooma haze on {PLAYER}",
                'late'   => "that {PLAYER} is coming in late from the tavern",
            ],
        ];
    }

    /** The concern settings: stored config per setting/table, text tables merged per entry. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('concern');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['felt_text', 'kind_phrases', 'cue_phrases', 'style_phrases', 'salience'] as $merged) {
            $cfg[$merged] = array_replace($defaults[$merged], is_array($stored[$merged] ?? null) ? $stored[$merged] : []);
        }
        $cfg['risk'] = array_replace($defaults['risk'], is_array($stored['risk'] ?? null) ? $stored['risk'] : []);
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function day(): float
    {
        return (float) RelationshipDynamics::GAMETS_PER_DAY;
    }

    private static function hour(): float
    {
        return RelationshipDynamics::GAMETS_PER_DAY / 24.0;
    }

    public static function channelOf(string $kind): ?string
    {
        return self::KINDS[$kind] ?? null;
    }

    // =====================================================================
    // WHO THE NPC IS
    // =====================================================================

    /** The NPC's trait codes (0..1): its vector (RelDynTraits::vectorFor), else default_traits. */
    public static function traitsOf(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $out = (array) $cfg['default_traits'];
        if (is_array($x)) {
            foreach (RelDynTraits::TRAITS as $code => $_) {
                if (is_numeric($x[$code] ?? null)) $out[$code] = floatval($x[$code]);
            }
        }
        return array_map('floatval', $out);
    }

    /** Sensitivity (0..1) to a kind: Po for the possessive kinds, Pr for the protective ones. */
    public static function sensitivity(array $traits, string $kind): float
    {
        return floatval(self::channelOf($kind) === self::POSSESSIVE ? ($traits['Po'] ?? 0) : ($traits['Pr'] ?? 0));
    }

    /**
     * How the NPC expresses it (§1.5): maturity (dimensions.maturity.x, 0..100) gives
     * w = (m - immature_at) / (mature_at - immature_at) clamped 0..1; band 'mature' (w >= 2/3),
     * 'immature' (w <= 1/3) or 'mixed'; the values path is mature from w >= 0.5. The immature
     * style (control / accusation / sulking) is the highest styles score over the traits.
     *
     * @return array{m: float, w: float, band: string, path: string, style: string}
     */
    public static function expression(array $dynamics, array $traits, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $m = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $lo = floatval($cfg['immature_at']);
        $hi = max($lo + 1e-9, floatval($cfg['mature_at']));
        $w = max(0.0, min(1.0, ($m - $lo) / ($hi - $lo)));
        $best = null;
        $bestScore = -INF;
        foreach ((array) $cfg['styles'] as $style => $weights) {
            $s = 0.0;
            foreach ((array) $weights as $code => $wt) $s += floatval($wt) * (floatval($traits[$code] ?? 0.5) - 0.5);
            if ($s > $bestScore) { $bestScore = $s; $best = (string) $style; }
        }
        return [
            'm' => $m, 'w' => round($w, 4),
            'band' => $w >= 2.0 / 3.0 ? 'mature' : ($w <= 1.0 / 3.0 ? 'immature' : 'mixed'),
            'path' => $w >= 0.5 ? 'mature' : 'immature',
            'style' => $best ?? 'sulking',
        ];
    }

    // =====================================================================
    // RISK APPRAISAL (§1.3), pure
    // =====================================================================

    /** Venue class of a place context (RelDynFacets::placeContextFromLocation): inn | den | town | other. */
    public static function venueOf(array $place, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $tags = array_map('strtolower', RelDynFacets::parseTags($place['tags'] ?? []));
        $name = trim((string) ($place['name'] ?? ''));
        foreach ((array) $cfg['risk']['venues'] as $venue => $rule) {
            foreach ((array) ($rule['tags'] ?? []) as $tag) {
                if (in_array(strtolower((string) $tag), $tags, true)) return (string) $venue;
            }
            foreach ((array) ($rule['keywords'] ?? []) as $kw) {
                if ($name !== '' && RelDynFacets::nameHasKeyword($name, (string) $kw)) return (string) $venue;
            }
        }
        return 'other';
    }

    /** True when the game hour falls in [from, to) wrapping midnight. */
    public static function inHours(?float $hour, array $range): bool
    {
        if ($hour === null) return false;
        [$from, $to] = array_map('floatval', array_values($range) + [0, 0]);
        $h = fmod(fmod($hour, 24.0) + 24.0, 24.0);
        return $from <= $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to);
    }

    /** Risk (0..1) -> intensity 0..3 (risk.intensity_at). */
    public static function intensityOf(float $risk, ?array $cfg = null): int
    {
        $cfg = $cfg ?? self::config();
        $at = array_map('floatval', array_values((array) $cfg['risk']['intensity_at']));
        $i = 0;
        foreach ($at as $threshold) {
            if ($risk >= $threshold) $i++;
        }
        return min(3, $i);
    }

    /**
     * What a place / night is to this NPC (§1.3). $facts:
     *   venue      inn | den | town | other (venueOf)
     *   night      bool (risk.night_hours)
     *   danger     place danger facet 0..1
     *   crowd      place crowd facet 0..1 (strangers estimate when 'strangers' is null)
     *   strangers  ?int people there not in the party and not bonded to the player
     *   consumed   null | ale | skooma (the player drank)
     * $npc: 'pref_combat' (signed -1..1, RelDynFacets preferences), 'C' confidence 0..1,
     * 'venue_taste' her appraisal valence of this venue's facets (-1..1; venueTaste()).
     *
     *   danger_r   = max(0, danger - appetite), appetite = 0.5 max(0, pref_combat) + 0.3 C
     *   vice_r     = max(venue_vice x (night ? 1 : 0.6), consume lift)
     *   stranger_r = min(1, strangers / 6) x social_venue x (night ? 0.5 : 0.25)
     *   risk       = 1 - (1 - danger_r)(1 - vice_r')(1 - stranger_r')   (' = less her venue ease,
     *                risk.venue_taste x max(0, venue_taste))
     *
     * @return array{risk: float, design_risk: float, intensity: int, kinds: array<string,int>,
     *   rival_exposure: int, parts: array}
     *   kinds: protective kind => perceived intensity (place: the venue's own risk; vice: the
     *   drink; danger); rival_exposure: possessive intensity (suitors at a social venue).
     */
    public static function appraise(array $facts, array $npc, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $r = (array) $cfg['risk'];
        $venue = (string) ($facts['venue'] ?? 'other');
        $night = !empty($facts['night']);
        $combat = max(0.0, floatval($npc['pref_combat'] ?? 0.0));
        $appetite = floatval($r['appetite_combat']) * $combat + floatval($r['appetite_confidence']) * floatval($npc['C'] ?? 0.5);
        $ease = floatval($r['venue_taste']) * max(0.0, min(1.0, floatval($npc['venue_taste'] ?? 0.0)));

        $danger = max(0.0, floatval($facts['danger'] ?? 0.0) - $appetite);
        $venueVice = floatval(((array) $r['venue_vice'])[$venue] ?? 0.0) * ($night ? 1.0 : floatval($r['vice_day_mult']));
        $consumed = is_string($facts['consumed'] ?? null) ? $facts['consumed'] : null;
        $lift = $consumed !== null ? floatval(((array) $r['consume_lift'])[$consumed] ?? 0.0) : 0.0;
        $vice = max($venueVice, $lift);
        $full = max(1, intval($r['strangers_full']));
        $strangers = is_numeric($facts['strangers'] ?? null)
            ? max(0, intval($facts['strangers']))
            : (int) round($full * max(0.0, min(1.0, floatval($facts['crowd'] ?? 0.0))));
        $social = (array) $r['social_venue'];
        $stranger = min(1.0, $strangers / $full) * floatval($social[$venue] ?? $social['other'] ?? 0.0)
            * floatval($night ? $r['stranger_night_mult'] : $r['stranger_day_mult']);

        $design = 1.0 - (1.0 - $danger) * (1.0 - $vice) * (1.0 - $stranger);
        $viceP = max(0.0, $vice - $ease);
        $strangerP = max(0.0, $stranger - $ease);
        $venueViceP = max(0.0, $venueVice - $ease);
        $risk = 1.0 - (1.0 - $danger) * (1.0 - $viceP) * (1.0 - $strangerP);

        $kinds = [];
        $placeRisk = 1.0 - (1.0 - $venueViceP) * (1.0 - $strangerP);
        if (($i = self::intensityOf($placeRisk, $cfg)) > 0) $kinds['place'] = $i;
        if ($consumed !== null && ($i = self::intensityOf(max(0.0, $lift - $ease), $cfg)) > 0) $kinds['vice'] = $i;
        if (($i = self::intensityOf($danger, $cfg)) > 0) $kinds['danger'] = $i;

        $rival = 0;
        if (in_array($venue, ['inn', 'town'], true) && $strangers > 0) {
            $rival = min(3, (int) ceil($strangers / max(1, intval($r['suitors_per_level']))));
            if (!$night) $rival = intdiv($rival, 2);
        }
        return [
            'risk' => round($risk, 4), 'design_risk' => round($design, 4), 'intensity' => self::intensityOf($risk, $cfg),
            'kinds' => $kinds, 'rival_exposure' => $rival,
            'parts' => ['danger' => round($danger, 4), 'vice' => round($vice, 4), 'stranger' => round($stranger, 4),
                        'appetite' => round($appetite, 4), 'venue_ease' => round($ease, 4), 'strangers' => $strangers],
        ];
    }

    /**
     * Route A: a disclosed kind at the eval's intensity, as this NPC perceives it. Protective
     * kinds read the intensity as a risk (risk.intensity_risk) less the NPC's tolerance
     * (danger: its danger appetite; the rest: its venue ease, $npc['venue_taste'] being its
     * taste for the tavern the kinds are phrased around); possessive kinds are not risk.
     */
    public static function perceive(string $kind, int $intensity, array $npc, ?array $cfg = null): int
    {
        $cfg = $cfg ?? self::config();
        $intensity = max(0, min(3, $intensity));
        if (self::channelOf($kind) !== self::PROTECTIVE) return $intensity;
        $r = (array) $cfg['risk'];
        $combat = max(0.0, floatval($npc['pref_combat'] ?? 0.0));
        $tolerance = $kind === 'danger'
            ? floatval($r['appetite_combat']) * $combat + floatval($r['appetite_confidence']) * floatval($npc['C'] ?? 0.5)
            : floatval($r['venue_taste']) * max(0.0, min(1.0, floatval($npc['venue_taste'] ?? 0.0)));
        $risk = floatval(array_values((array) $r['intensity_risk'])[$intensity] ?? 0.0);
        return self::intensityOf(max(0.0, $risk - $tolerance), $cfg);
    }

    /** Core place context of a venue class (venueOf), for its facets: the class, not one night's incidentals. */
    const VENUE_PLACES = ['inn' => ['name' => '', 'tags' => ['Inn']], 'town' => ['name' => '', 'tags' => ['Town']],
                          'den' => ['name' => 'skooma den', 'tags' => []]];

    /**
     * The NPC's own taste for a kind of venue (inn | town | den; the protective kinds are
     * phrased around the inn): her appraisal valence (-1..1) of the venue class's facets
     * (RelDynFacets::placeFacets of VENUE_PLACES) against her preferences (RelDynFacets::appraise;
     * decisions §6). 0 for any other place.
     */
    public static function venueTaste(array $dynamics, string $npcName, string $venue = 'inn'): float
    {
        $place = self::VENUE_PLACES[$venue] ?? null;
        if ($place === null) return 0.0;
        return floatval(RelDynFacets::appraise(RelDynFacets::preferences($dynamics, $npcName), RelDynFacets::placeFacets($place))['valence']);
    }

    /** Who is appraising, for appraise() / perceive(): combat preference, confidence, taste for the venue. */
    private static function npcFacts(array $dynamics, string $npcName, array $traits, string $venue = 'inn'): array
    {
        return ['pref_combat' => floatval(RelDynFacets::preferences($dynamics, $npcName)['combat'] ?? 0.0), 'C' => $traits['C'],
                'venue_taste' => self::venueTaste($dynamics, $npcName, $venue)];
    }

    // =====================================================================
    // GAINS
    // =====================================================================

    /**
     * Concern points one incident adds (§1.4):
     *   12 x intensity_mult[I] x (2 Pr) x (1 - 0.15 trust / 100) x sqrt(max(0, core aff) / 100)
     * clamped to [0, gain_max]. trust = dimensions.trust.x (0..100); core aff -100..100.
     */
    public static function concernGain(array $dynamics, int $intensity, array $traits, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $intensity = max(0, min(3, $intensity));
        $trust = max(0.0, min(100.0, floatval($dynamics['dimensions']['trust']['x'] ?? 50)));
        $aff = RelationshipDynamics::getCoreAffinity($dynamics);
        $gain = floatval($cfg['concern_base'])
            * floatval(array_values((array) $cfg['intensity_mult'])[$intensity] ?? 1.0)
            * 2.0 * floatval($traits['Pr'] ?? 0.5)
            * (1.0 - floatval($cfg['trust_damping']) * $trust / 100.0)
            * sqrt(max(0.0, $aff) / 100.0);
        return max(0.0, min(floatval($cfg['gain_max']), $gain));
    }

    /** Commitment (jealousy_bystander_commitment by core Player.type; 0 = not a partner). */
    public static function commitment(array $dynamics): float
    {
        $type = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        return floatval(((array) RelationshipDynamics::configValue('jealousy_bystander_commitment'))[$type] ?? 0.0);
    }

    // =====================================================================
    // STATE
    // =====================================================================

    /** The game day a night belongs to (day_start_hour: 23:00 and 01:00 are one night). */
    public static function socialDay(float $gamets, ?array $cfg = null): int
    {
        $cfg = $cfg ?? self::config();
        return (int) floor(($gamets - floatval($cfg['day_start_hour']) * self::hour()) / self::day());
    }

    /**
     * $dynamics['_concern'], created empty when missing:
     *   level        concern points 0..100 (protective feeling)
     *   incidents    [{channel, day (socialDay), gamets, kinds: kind => intensity, intensity,
     *                  routes[], counted, filed_kinds: kind => true, addressed}] (a stored
     *                  'filed' true, from before per-kind filing, files every kind)
     *   boundary     ['state' => none|pending|probation|failed, 'channel', 'kind', 'kinds',
     *                  'decided_gamets', 'started_gamets', 'until_gamets']
     *   say          one-shots for the player's face: [{key, channel, kind, style?, cues?, from?, to?}]
     */
    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::STATE_KEY] ?? null) || intval($dynamics[self::STATE_KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::STATE_KEY] = ['v' => self::VERSION, 'level' => 0.0, 'incidents' => [], 'boundary' => ['state' => 'none'], 'say' => []];
        }
        return $dynamics[self::STATE_KEY];
    }

    /** Concern points 0..100. */
    public static function level(array $dynamics): float
    {
        return floatval($dynamics[self::STATE_KEY]['level'] ?? 0.0);
    }

    /**
     * pattern[kind] (§1.5): counted, unaddressed nights within the window before $now on which
     * $kind was seen and not yet filed. For a channel ($kindOrChannel = POSSESSIVE /
     * PROTECTIVE): the highest pattern of its kinds (how far along its values path is).
     */
    public static function count(array $dynamics, string $kindOrChannel, float $now, ?array $cfg = null): int
    {
        $cfg = $cfg ?? self::config();
        if (in_array($kindOrChannel, [self::POSSESSIVE, self::PROTECTIVE], true)) {
            $n = 0;
            foreach (self::KINDS as $kind => $ch) {
                if ($ch === $kindOrChannel) $n = max($n, self::count($dynamics, $kind, $now, $cfg));
            }
            return $n;
        }
        $from = $now - floatval($cfg['window_game_days']) * self::day();
        $n = 0;
        foreach ((array) ($dynamics[self::STATE_KEY]['incidents'] ?? []) as $inc) {
            if (!empty($inc['counted']) && empty($inc['addressed']) && floatval($inc['gamets'] ?? 0) > $from
                && isset(((array) ($inc['kinds'] ?? []))[$kindOrChannel]) && !self::filed($inc, $kindOrChannel)) {
                $n++;
            }
        }
        return $n;
    }

    /** Whether an incident's $kind is already filed (a stored 'filed' true files every kind). */
    private static function filed(array $inc, string $kind): bool
    {
        return !empty($inc['filed']) || !empty(((array) ($inc['filed_kinds'] ?? []))[$kind]);
    }

    /** pattern[kind] of every kind with a count, for Jev. */
    public static function patterns(array $dynamics, float $now, ?array $cfg = null): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $n = self::count($dynamics, $kind, $now, $cfg);
            if ($n > 0) $out[$kind] = $n;
        }
        return $out;
    }

    /** The values boundary is running (pending, probation or failed): one boundary at a time per bond. */
    public static function boundaryActive(array $dynamics): bool
    {
        return in_array($dynamics[self::STATE_KEY]['boundary']['state'] ?? 'none', ['pending', 'probation', 'failed'], true);
    }

    /**
     * The resentment lane's repetition (decisions §14: a mature NPC treats repetition as a values
     * mismatch that feeds the §9 boundary flow; RelDynResentment::tickConfrontation): the values
     * boundary opens on channel GRIEVANCE, pending its statement to the player's face, then the
     * probation and a step-back if another grievance lands inside it (onGrievance, onContact).
     * The values path's eligibility: a step-back target, not walking away, no boundary running
     * in either lane (one boundary at a time per bond). Returns true when it opened.
     */
    public static function openGrievanceBoundary(string $npcName, array &$dynamics, float $now): bool
    {
        if ($now <= 0 || !self::enabled() || RelDynFulfillment::stepBackTarget($dynamics) === null
            || ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal'
            || self::boundaryActive($dynamics) || RelDynFulfillment::boundaryActive($dynamics)) {
            return false;
        }
        $state = &self::state($dynamics);
        $state['boundary'] = ['state' => 'pending', 'channel' => self::GRIEVANCE, 'kind' => self::GRIEVANCE_KIND,
            'kinds' => [self::GRIEVANCE_KIND], 'decided_gamets' => $now];
        unset($state);
        RelationshipDynamics::log("[CONCERN] {$npcName}: values boundary due (grievances after the confrontation)");
        return true;
    }

    /**
     * A grievance at game time $at while the GRIEVANCE boundary watches (after its statement):
     * the pattern went on, the probation fails (onContact carries out the step-back). Returns
     * true when it failed the probation.
     */
    public static function onGrievance(string $npcName, array &$dynamics, float $at): bool
    {
        $b = $dynamics[self::STATE_KEY]['boundary'] ?? null;
        if (!is_array($b) || ($b['state'] ?? 'none') !== 'probation' || ($b['channel'] ?? null) !== self::GRIEVANCE
            || $at <= floatval($b['started_gamets'] ?? INF)) {
            return false;
        }
        $dynamics[self::STATE_KEY]['boundary']['state'] = 'failed';
        $dynamics[self::STATE_KEY]['boundary']['failed_gamets'] = $at;
        RelationshipDynamics::log("[CONCERN] {$npcName}: wronged again during the grievance probation: step-back due");
        return true;
    }

    /** A kind as this NPC names it (kind_phrases, {NAME} / {PLAYER} filled): the resentment lane's fuel. */
    public static function kindPhraseFor(string $kind, string $npcName, string $playerName): string
    {
        return self::kindPhrase($kind, self::config(), ['{NAME}' => $npcName, '{PLAYER}' => $playerName]);
    }

    /** The kind a channel's incidents are about: the most frequent in the window (KINDS order on ties). */
    private static function mainKind(array $state, string $channel, float $now, array $cfg): string
    {
        $from = $now - floatval($cfg['window_game_days']) * self::day();
        $tally = [];
        foreach ((array) $state['incidents'] as $inc) {
            if (($inc['channel'] ?? null) !== $channel || empty($inc['counted']) || floatval($inc['gamets'] ?? 0) <= $from) continue;
            foreach (array_keys((array) $inc['kinds']) as $k) $tally[$k] = ($tally[$k] ?? 0) + 1;
        }
        $best = null;
        foreach (self::KINDS as $kind => $ch) {
            if ($ch !== $channel || !isset($tally[$kind])) continue;
            if ($best === null || $tally[$kind] > $tally[$best]) $best = $kind;
        }
        return $best ?? ($channel === self::POSSESSIVE ? 'rival_exposure' : 'place');
    }

    /**
     * Learn about an exposure (every route calls this). $exposure:
     *   kinds    kind => perceived intensity 1..3 (0 is dropped)
     *   gamets   when it happened (raw gamets; its social day is the dedupe key)
     *   route    A (disclosed) | B (return) | C (witness, later) | present (the eval jealousy
     *            flag) | bystander
     *   feeling_applied  true when the jealousy was already added by the existing path
     * Per channel: one incident per social day, at its worst known intensity (a higher one
     * adds only the difference in feeling). The feeling: protective -> concern (concernGain),
     * possessive rival_exposure -> jealousy (jealousyEventGain at the bond's commitment, trust
     * damped). The count: sensitivity >= sensitivity_min and the bond qualifies; trust never
     * enters. A new counted incident advances the channel's values path (advance()).
     *
     * @return array ['concern' => points added, 'jealousy' => points added, 'events' => string[]]
     */
    public static function learn(string $npcName, array &$dynamics, array $exposure, float $now): array
    {
        $out = ['concern' => 0.0, 'jealousy' => 0.0, 'events' => []];
        if (!self::enabled()) return $out;
        $cfg = self::config();
        $at = floatval($exposure['gamets'] ?? 0) > 0 ? floatval($exposure['gamets']) : $now;
        $route = (string) ($exposure['route'] ?? 'A');
        $byChannel = [];
        foreach ((array) ($exposure['kinds'] ?? []) as $kind => $i) {
            $ch = self::channelOf((string) $kind);
            $i = max(0, min(3, intval($i)));
            if ($ch === null || $i <= 0) continue;
            $byChannel[$ch][(string) $kind] = $i;
        }
        if ($byChannel === []) return $out;

        $traits = self::traitsOf($dynamics, $cfg);
        $state = &self::state($dynamics);
        $day = self::socialDay($at, $cfg);
        foreach ($byChannel as $channel => $kinds) {
            $intensity = max($kinds);
            $idx = null;
            foreach ($state['incidents'] as $n => $inc) {
                if (($inc['channel'] ?? null) === $channel && intval($inc['day'] ?? PHP_INT_MIN) === $day) { $idx = $n; break; }
            }
            $prevI = $idx !== null ? intval($state['incidents'][$idx]['intensity'] ?? 0) : 0;
            $prevKinds = $idx !== null ? array_keys((array) ($state['incidents'][$idx]['kinds'] ?? [])) : [];
            if ($idx === null) {
                $state['incidents'][] = ['channel' => $channel, 'day' => $day, 'gamets' => $at, 'kinds' => [], 'intensity' => 0,
                    'routes' => [], 'counted' => false, 'filed' => false, 'addressed' => false];
                $idx = array_key_last($state['incidents']);
            }
            $inc = &$state['incidents'][$idx];
            foreach ($kinds as $k => $i) $inc['kinds'][$k] = max(intval($inc['kinds'][$k] ?? 0), $i);
            if (!in_array($route, $inc['routes'], true)) $inc['routes'][] = $route;
            $inc['intensity'] = max($prevI, $intensity);

            // The feeling: only the part of this night not felt yet
            if ($intensity > $prevI) {
                if ($channel === self::PROTECTIVE) {
                    $gain = self::concernGain($dynamics, $intensity, $traits, $cfg) - ($prevI > 0 ? self::concernGain($dynamics, $prevI, $traits, $cfg) : 0.0);
                    if ($gain > 0) {
                        $state['level'] = min(floatval($cfg['max']), floatval($state['level']) + $gain);
                        $out['concern'] += $gain;
                    }
                } elseif (isset($kinds['rival_exposure']) && empty($exposure['feeling_applied'])
                    && RelationshipDynamics::configValue('jealousy_enabled')) {
                    $c = self::commitment($dynamics);
                    $gain = $c > 0 ? RelationshipDynamics::jealousyEventGain($dynamics, $intensity, $c)
                        - ($prevI > 0 ? RelationshipDynamics::jealousyEventGain($dynamics, $prevI, $c) : 0.0) : 0.0;
                    if ($gain > 0) {
                        RelationshipDynamics::addJealousy($dynamics, $gain, null);
                        $out['jealousy'] += $gain;
                    }
                }
            }

            // The count (trust never enters)
            if (empty($inc['counted']) && self::counts($dynamics, $channel, $kinds, $traits, $cfg)) {
                $inc['counted'] = true;
                $seen = array_keys((array) $inc['kinds']);
                unset($inc);
                $out['events'] = array_merge($out['events'], self::advance($npcName, $dynamics, $state, $channel, $seen, $at, $now, $traits, $cfg));
            } elseif (!empty($inc['counted']) && array_diff_key($kinds, $before = array_flip($prevKinds)) !== []) {
                // a kind this night had not shown yet (told later, or seen on the return): its own pattern moves
                $seen = array_keys(array_diff_key($kinds, $before));
                unset($inc);
                $out['events'] = array_merge($out['events'], self::advance($npcName, $dynamics, $state, $channel, $seen, $at, $now, $traits, $cfg));
            } else {
                unset($inc);
            }
        }
        unset($state);
        self::prune($dynamics, $now, $cfg);
        RelationshipDynamics::log("[CONCERN] {$npcName}: learned " . json_encode($byChannel) . " (route {$route}, night {$day})"
            . " concern +" . round($out['concern'], 2) . " jealousy +" . round($out['jealousy'], 2)
            . ($out['events'] ? ' events ' . implode(',', $out['events']) : ''));
        return $out;
    }

    /** Whether an incident of this channel counts toward values for this NPC and bond. */
    private static function counts(array $dynamics, string $channel, array $kinds, array $traits, array $cfg): bool
    {
        $min = floatval($cfg['sensitivity_min']);
        $sens = 0.0;
        foreach (array_keys($kinds) as $k) $sens = max($sens, self::sensitivity($traits, $k));
        if ($sens < $min || RelationshipDynamics::neglectBond($dynamics) === null) return false;
        if ($channel === self::POSSESSIVE) {
            // Rival kinds are about a partner: a committed bond whose preference knows jealousy
            // (§1.7: not for aromantic / not interested)
            $c = self::commitment($dynamics);
            return $c > 0 && RelationshipDynamics::jealousyEventGain($dynamics, 1, $c) > 0;
        }
        return true;
    }

    /**
     * A counted night of $channel that showed $kinds: the values path (§1.5), per kind. Returns
     * event names.
     *   probation open on this channel for one of $kinds -> 'failed' (the step-back is onContact's);
     *   a kind at values_at -> grievance values_conflict:<kind> (the first such kind in KINDS
     *     order; kinds reaching it together file together), the window's nights of those kinds
     *     filed; mature path with a step-back target and no other boundary running (this
     *     lane's, the fulfillment lane's) -> boundary 'pending' (statement said to the player's
     *     face, then probation); else a blow-up (immature path: severity +50%);
     *   a kind's first counted night -> the one-time statement of values (mature) /
     *     control-accusation-sulk; its second -> a reminder.
     */
    private static function advance(string $npcName, array &$dynamics, array &$state, string $channel, array $kinds, float $at, float $now, array $traits, array $cfg): array
    {
        $events = [];
        $expr = self::expression($dynamics, $traits, $cfg);
        $b = (array) $state['boundary'];
        $kinds = array_values(array_filter(array_keys(self::KINDS), fn($k) => in_array($k, $kinds, true) && self::KINDS[$k] === $channel));
        if ($kinds === []) return $events;
        $watched = array_merge((array) ($b['kinds'] ?? []), [(string) ($b['kind'] ?? '')]);
        if (($b['state'] ?? 'none') === 'probation' && ($b['channel'] ?? null) === $channel && array_intersect($kinds, $watched) !== []) {
            $state['boundary']['state'] = 'failed';
            $state['boundary']['failed_gamets'] = $now;
            return ['step_back_due'];
        }
        $when = max($now, $at);
        $n = [];
        foreach ($kinds as $k) $n[$k] = self::count($dynamics, $k, $when, $cfg);
        $reached = array_keys(array_filter($n, fn($c) => $c >= intval($cfg['values_at'])));
        if ($reached !== []) {
            $kind = $reached[0];
            $mature = $expr['path'] === 'mature';
            $severity = intval($mature ? $cfg['mature_severity'] : $cfg['immature_severity']);
            if (RelationshipDynamics::configValue('dimension_engine_enabled')) {
                $phrase = strtr((string) (((array) $cfg['kind_phrases'])[$kind] ?? $kind), ['{PLAYER}' => 'the player']);
                $g = RelationshipDynamics::recordGrievance($dynamics, ['flag' => true, 'kind' => "values_conflict:{$kind}", 'severity' => $severity],
                    RelationshipDynamics::powerGapFacts($npcName, $dynamics), "values conflict: {$phrase}");
                $events[] = "values_conflict:{$kind}";
                RelationshipDynamics::log("[CONCERN] {$npcName}: values_conflict:{$kind} (pattern " . json_encode($n)
                    . " in the window, {$expr['path']} path) resentment +" . round($g['amount'], 3));
            }
            $from = $when - floatval($cfg['window_game_days']) * self::day();
            foreach ($state['incidents'] as &$inc) {
                if (($inc['channel'] ?? null) !== $channel || empty($inc['counted']) || floatval($inc['gamets'] ?? 0) <= $from) continue;
                foreach ($reached as $k) {
                    if (isset(((array) ($inc['kinds'] ?? []))[$k])) $inc['filed_kinds'][$k] = true;
                }
            }
            unset($inc);
            $eligible = $mature && RelDynFulfillment::stepBackTarget($dynamics) !== null
                && ($dynamics['_walkaway_state'] ?? 'normal') === 'normal' && ($b['state'] ?? 'none') === 'none'
                && !RelDynFulfillment::boundaryActive($dynamics);
            if ($eligible) {
                $state['boundary'] = ['state' => 'pending', 'channel' => $channel, 'kind' => $kind, 'kinds' => $reached, 'decided_gamets' => $now];
                $events[] = 'boundary_due';
            } elseif ($mature) {
                self::queue($state, ['key' => 'stated_mature', 'channel' => $channel, 'kind' => $kind]);
                $events[] = 'stated';
            } else {
                self::queue($state, ['key' => 'blowup', 'channel' => $channel, 'kind' => $kind, 'style' => $expr['style']]);
                $events[] = 'blowup';
            }
        } elseif (($first = array_keys(array_filter($n, fn($c) => $c === 1))) !== []) {
            $key = $expr['band'] === 'mature' ? 'stated_mature' : ($expr['band'] === 'mixed' ? 'stated_mixed' : 'stated_' . $expr['style']);
            self::queue($state, ['key' => $key, 'channel' => $channel, 'kind' => $first[0], 'style' => $expr['style']]);
            $events[] = 'stated';
        } elseif (in_array(2, $n, true)) {
            $events[] = 'reminder';
        }
        return $events;
    }

    /** Queue a one-shot, replacing an unsaid one of the same channel (the newest wins), at most 4. */
    private static function queue(array &$state, array $say): void
    {
        $state['say'] = array_values(array_filter((array) $state['say'],
            fn($s) => !(($s['channel'] ?? null) === ($say['channel'] ?? null) && ($s['key'] ?? null) === ($say['key'] ?? null))));
        $state['say'][] = $say;
        $state['say'] = array_slice($state['say'], -4);
    }

    /** Drop incidents older than the window (+1 game day), at most 30 kept. */
    private static function prune(array &$dynamics, float $now, array $cfg): void
    {
        $from = $now - (floatval($cfg['window_game_days']) + 1.0) * self::day();
        $kept = array_values(array_filter((array) $dynamics[self::STATE_KEY]['incidents'], fn($i) => floatval($i['gamets'] ?? 0) > $from));
        $dynamics[self::STATE_KEY]['incidents'] = array_slice($kept, -30);
    }

    /**
     * Reassurance (eval tag, §1.5 "addressed"): the newest counted, unaddressed night is
     * addressed in both channels (one incident fewer each), and concern eases by
     * reassurance_relief. Returns true when something was addressed or eased.
     */
    public static function address(array &$dynamics, float $now): bool
    {
        if (!is_array($dynamics[self::STATE_KEY] ?? null)) return false;
        $cfg = self::config();
        $state = &self::state($dynamics);
        $changed = false;
        if (floatval($state['level']) > 0) {
            $state['level'] = max(0.0, floatval($state['level']) - floatval($cfg['reassurance_relief']));
            $changed = true;
        }
        $day = null;
        foreach (array_reverse($state['incidents']) as $inc) {
            if (!empty($inc['counted']) && empty($inc['filed']) && empty($inc['addressed'])) { $day = intval($inc['day']); break; }
        }
        if ($day !== null) {
            foreach ($state['incidents'] as &$inc) {
                if (intval($inc['day']) === $day && !empty($inc['counted'])) $inc['addressed'] = true;
            }
            unset($inc);
            $changed = true;
        }
        unset($state);
        return $changed;
    }

    // =====================================================================
    // ROUTE A + the possessive 'rival' kind: the eval consumer
    // =====================================================================

    /**
     * One applied contract v1 item ($n from normalizeEvalContractItem, $feelings from
     * applyEvalFeelings): the jealousy event it raised is a 'rival' incident (feeling already
     * applied); an 'exposure' (route A, optional additive field) is learned as this NPC
     * perceives it; a 'reassurance' tag addresses one incident.
     */
    public static function onEvalItem(string $npcName, array $n, array &$dynamics, array $feelings): array
    {
        $out = ['events' => [], 'concern' => 0.0, 'jealousy' => 0.0];
        if (!self::enabled()) return $out;
        $cfg = self::config();
        $at = floatval($n['gamets'] ?? 0) > 0 ? floatval($n['gamets']) : RelationshipDynamics::currentGamets();
        if ($at <= 0) return $out;

        if (floatval($feelings['jealousy'] ?? 0) > 0) {
            $i = max(1, min(3, intval($n['jealousy']['intensity'] ?? 1)));
            $r = self::learn($npcName, $dynamics, ['kinds' => ['rival' => $i], 'gamets' => $at, 'route' => 'present', 'feeling_applied' => true], $at);
            $out['events'] = array_merge($out['events'], $r['events']);
        }

        $e = is_array($n['exposure'] ?? null) ? $n['exposure'] : null;
        if ($e !== null && !empty($e['flag'])) {
            $npc = self::npcFacts($dynamics, $npcName, self::traitsOf($dynamics, $cfg));
            $kinds = [];
            foreach ((array) $e['kinds'] as $kind) {
                $i = self::perceive((string) $kind, intval($e['intensity'] ?? 1), $npc, $cfg);
                if ($i > 0) $kinds[(string) $kind] = $i;
            }
            $when = (string) ($e['when'] ?? 'today');
            $day = self::socialDay($at, $cfg);
            $eventAt = match ($when) {
                'last_night' => ($day - 1) * self::day() + 23.0 * self::hour(),
                'earlier'    => ($day - 2) * self::day() + 12.0 * self::hour() + floatval($cfg['day_start_hour']) * self::hour(),
                default      => $at,
            };
            if ($kinds !== []) {
                $r = self::learn($npcName, $dynamics, ['kinds' => $kinds, 'gamets' => $eventAt, 'route' => 'A'], $at);
                $out['events'] = array_merge($out['events'], $r['events']);
                $out['concern'] += $r['concern'];
                $out['jealousy'] += $r['jealousy'];
            } else {
                RelationshipDynamics::log("[CONCERN] {$npcName}: disclosed " . implode(',', (array) $e['kinds']) . " at intensity "
                    . intval($e['intensity'] ?? 0) . " does not register (tolerance)");
            }
        }

        if (in_array('reassurance', (array) ($n['tags'] ?? []), true) && self::address($dynamics, $at)) {
            $out['events'][] = 'addressed';
        }
        return $out;
    }

    // =====================================================================
    // ROUTE B (the return check), decay while together, the §9 boundary's core write
    // =====================================================================

    /**
     * The prerequest's contact (call before markContact: _last_contact_gamets is still the
     * previous contact):
     *   - concern eases decay_per_game_hour while the player is with them (contacts at most
     *     present_gap_game_hours apart); an absence heals nothing (decisions §2);
     *   - a return (at least return_min_game_hours apart): route B reads what the NPC can
     *     perceive (returnFacts) and learns it; the cues are said once ('noticed');
     *   - the values boundary: a probation that ran out clean resolves; a failed one steps the
     *     core type back (changeCoreRelationshipType, the fulfillment step_back_types).
     *
     * @return array ['changed' => bool, 'events' => string[], 'return' => ?array]
     */
    public static function onContact(string $npcName, array &$dynamics, float $now, string $playerName): array
    {
        $out = ['changed' => false, 'events' => [], 'return' => null];
        if ($now <= 0 || !self::enabled()) return $out;
        $cfg = self::config();
        $last = floatval($dynamics['_last_contact_gamets'] ?? 0);
        $hours = ($last > 0 && $now > $last) ? ($now - $last) / self::hour() : 0.0;

        if (is_array($dynamics[self::STATE_KEY] ?? null) && floatval($dynamics[self::STATE_KEY]['level'] ?? 0) > 0
            && $hours > 0 && $hours <= floatval($cfg['present_gap_game_hours'])) {
            $dynamics[self::STATE_KEY]['level'] = max(0.0, floatval($dynamics[self::STATE_KEY]['level']) - floatval($cfg['decay_per_game_hour']) * $hours);
            $out['changed'] = true;
        }

        if ($last > 0 && $hours >= floatval($cfg['return_min_game_hours'])) {
            $facts = self::returnFacts($npcName, $playerName, $last, $now, $dynamics, $cfg);
            if ($facts !== null) {
                $out['return'] = $facts;
                $state = &self::state($dynamics);
                self::queue($state, ['key' => 'noticed', 'channel' => self::PROTECTIVE, 'kind' => 'place', 'cues' => $facts['cues']]);
                unset($state);
                $r = self::learn($npcName, $dynamics, ['kinds' => $facts['kinds'], 'gamets' => $facts['gamets'], 'route' => 'B'], $now);
                $out['events'] = array_merge($out['events'], $r['events']);
                $out['changed'] = true;
            }
        }

        $b = $dynamics[self::STATE_KEY]['boundary'] ?? null;
        if (is_array($b) && ($b['state'] ?? 'none') === 'probation' && $now >= floatval($b['until_gamets'] ?? INF)) {
            $dynamics[self::STATE_KEY]['boundary'] = ['state' => 'none', 'resolved_gamets' => $now];
            $state = &self::state($dynamics);
            self::queue($state, ['key' => 'resolved', 'channel' => (string) ($b['channel'] ?? ''), 'kind' => (string) ($b['kind'] ?? '')]);
            unset($state);
            RelationshipDynamics::attachmentExperience($dynamics, 'boundary_kept', $now, 1.0, $npcName);
            $out['events'][] = 'resolved';
            $out['changed'] = true;
        } elseif (is_array($b) && ($b['state'] ?? 'none') === 'failed') {
            $out['events'][] = self::stepBack($npcName, $dynamics, $b, $now) ? 'step_back' : 'step_back_blocked';
            $out['changed'] = true;
        }
        foreach ($out['events'] as $event) RelationshipDynamics::log("[CONCERN] {$npcName}: {$event}");
        return $out;
    }

    /**
     * The present route (§1.2: "the place appraisal sees it directly"): the NPC is with the
     * player at a place ($place: core's place context, $facets: its facet vector); its danger,
     * less her appetite (§1.3), is a 'danger' exposure learned now (route 'present', one per
     * game day at its worst). Only danger: out together is a shared night, so a tavern with her
     * there is neither 'place' nor 'rival_exposure'. Returns learn()'s result (empty without
     * danger).
     */
    public static function onPresentPlace(string $npcName, array &$dynamics, array $place, array $facets, float $now): array
    {
        $out = ['concern' => 0.0, 'jealousy' => 0.0, 'events' => []];
        if ($now <= 0 || !self::enabled() || floatval($facets['danger'] ?? 0.0) <= 0.0) return $out;
        $cfg = self::config();
        $venue = self::venueOf($place, $cfg);
        $npc = self::npcFacts($dynamics, $npcName, self::traitsOf($dynamics, $cfg), $venue);
        $a = self::appraise(['venue' => $venue, 'night' => false, 'danger' => floatval($facets['danger']),
                             'strangers' => 0, 'consumed' => null], $npc, $cfg);
        if (!isset($a['kinds']['danger'])) return $out;
        return self::learn($npcName, $dynamics, ['kinds' => ['danger' => $a['kinds']['danger']], 'gamets' => $now, 'route' => 'present'], $now);
    }

    /** A failed values probation: the deliberate step-back of core's Player.type (§9). */
    private static function stepBack(string $npcName, array &$dynamics, array $b, float $now): bool
    {
        $from = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $to = RelDynFulfillment::stepBackTarget($dynamics);
        $kind = (string) ($b['kind'] ?? '');
        $reason = "mature boundary (values: {$kind}): stated calmly, then it happened again during the probation";
        $written = $to !== null && RelationshipDynamics::changeCoreRelationshipType($npcName, $to, $reason, $from);
        $state = &self::state($dynamics);
        if ($written) {
            $dynamics['_core_rel_type'] = $to;
            $state['boundary'] = ['state' => 'none', 'stepped_back_gamets' => $now, 'from' => $from, 'to' => $to, 'kind' => $kind];
            self::queue($state, ['key' => 'step_back', 'channel' => (string) ($b['channel'] ?? ''), 'kind' => $kind, 'from' => $from, 'to' => $to]);
        } else {
            $state['boundary'] = ['state' => 'none', 'blocked_gamets' => $now, 'from' => $from, 'to' => $to, 'kind' => $kind];
            error_log("[RelDyn-CONCERN] {$npcName}: values probation failed but the step-back {$from} -> " . ($to ?? '-') . " was not written; boundary closed");
        }
        unset($state);
        return $written;
    }

    /**
     * Route B: what the NPC can perceive on the player's return, from eventlog rows between the
     * last contact and now in which the NPC was NOT present (people column):
     *   drunk  an itemfound "<player> drank|consumed|ate|used <item>" row within
     *          drunk_window_game_hours of now whose item is ale or skooma (classifyItem);
     *   late   now within late_hours and the newest location row the NPC was absent from, within
     *          the same window, is an inn or a den (venueOf).
     * Where the player was and who was there stay unknown: the venue's strangers are the NPC's
     * guess from its crowd facet. null when nothing is perceivable or nothing registers.
     *
     * @return array|null ['kinds' => kind => perceived intensity, 'gamets' => the night's time,
     *                     'cues' => string[] (ale|skooma|late), 'appraisal' => appraise()]
     */
    public static function returnFacts(string $npcName, string $playerName, float $last, float $now, array $dynamics, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return null;
        $since = max($last, $now - floatval($cfg['drunk_window_game_hours']) * self::hour());
        try {
            $row = $db->fetchOne(
                "SELECT coalesce(json_agg(r ORDER BY r.gamets DESC, r.rowid DESC), '[]'::json)::text AS rows FROM (
                     SELECT rowid, gamets, type, data, people FROM eventlog
                     WHERE gamets > \$1 AND gamets <= \$2
                       AND (type = 'itemfound' OR (type IN ('infoloc', 'location', 'request') AND data LIKE '%(Context%'))
                     ORDER BY gamets DESC, rowid DESC LIMIT \$3
                 ) r",
                [(int) floor($since), (int) ceil($now), max(1, intval($cfg['return_scan_rows']))]
            );
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('concern return check eventlog', $e);
            return null;
        }
        $rows = json_decode((string) ($row['rows'] ?? ''), true);
        if (!is_array($rows)) {
            error_log("[RelDyn-CONCERN] {$npcName}: return check eventlog read failed");
            return null;
        }

        $consumed = null;
        $consumedAt = null;
        $inn = null;
        foreach ($rows as $r) {
            if (self::present($npcName, (string) ($r['people'] ?? ''))) continue;
            $data = (string) ($r['data'] ?? '');
            if (($r['type'] ?? '') === 'itemfound') {
                if (!preg_match('/^(.+?)\s+(?:consumed|drank|ate|used)\s+(?:\d+\s+)?(.+?)\s*$/iu', trim($data), $m)) continue;
                if (mb_strtolower(trim($m[1])) !== mb_strtolower(trim($playerName))) continue;
                $c = RelationshipDynamics::classifyItem(trim($m[2]));
                $key = is_array($c) && ($c['type'] ?? null) === 'consumable' ? (string) $c['key'] : null;
                if ($key === null || !isset(((array) $cfg['risk']['consume_lift'])[$key])) continue;
                $lift = floatval($cfg['risk']['consume_lift'][$key]);
                if ($consumed === null || $lift > floatval($cfg['risk']['consume_lift'][$consumed])) {
                    $consumed = $key;
                    $consumedAt = floatval($r['gamets']);
                }
            } elseif ($inn === null) {
                // the newest inn / den the player was at without the NPC (the walk home after it
                // is other places)
                $place = RelDynFacets::placeContextFromLocation($data, floatval($r['gamets']));
                $venue = self::venueOf($place, $cfg);
                if (in_array($venue, ['inn', 'den'], true)) $inn = ['place' => $place, 'venue' => $venue, 'gamets' => floatval($r['gamets'])];
            }
        }

        $late = $inn !== null && self::inHours(RelationshipDynamics::gameHourOfDay($now), (array) $cfg['late_hours']);
        if (!$late && $consumed === null) return null;

        $traits = self::traitsOf($dynamics, $cfg);
        $npc = self::npcFacts($dynamics, $npcName, $traits, $late ? $inn['venue'] : 'inn');
        $facts = ['venue' => 'other', 'night' => false, 'crowd' => 0.0, 'strangers' => $late ? null : 0, 'consumed' => $consumed];
        $at = $consumedAt ?? $now;
        if ($late) {
            $facets = RelDynFacets::placeFacets($inn['place']);
            $facts['venue'] = $inn['venue'];
            $facts['night'] = self::inHours(RelationshipDynamics::gameHourOfDay($inn['gamets']), (array) $cfg['risk']['night_hours']);
            $facts['crowd'] = floatval($facets['crowd'] ?? 0.0);
            $facts['danger'] = floatval($facets['danger'] ?? 0.0);
            $at = $inn['gamets'];
        }
        $a = self::appraise($facts, $npc, $cfg);
        $kinds = array_intersect_key($a['kinds'], array_flip($late ? ['place', 'vice'] : ['vice']));
        if ($kinds === []) {
            RelationshipDynamics::log("[CONCERN] {$npcName}: return cues (" . ($late ? 'late' : '') . ($consumed ? " {$consumed}" : '')
                . ") do not register (risk {$a['risk']}, venue ease {$a['parts']['venue_ease']})");
            return null;
        }
        $cues = [];
        if ($consumed !== null && isset($kinds['vice'])) $cues[] = $consumed;
        if ($late && isset($kinds['place'])) $cues[] = 'late';
        return ['kinds' => $kinds, 'gamets' => $at, 'cues' => $cues, 'appraisal' => $a];
    }

    /** The NPC is in an eventlog people column ("|A|B (state)|"). */
    private static function present(string $npcName, string $people): bool
    {
        foreach (explode('|', $people) as $p) {
            $p = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $p) ?? $p);
            if ($p !== '' && mb_strtolower($p) === mb_strtolower(trim($npcName))) return true;
        }
        return false;
    }

    // =====================================================================
    // FELT TEXT (feelings for the LLM, never numbers)
    // =====================================================================

    private static function kindPhrase(string $kind, array $cfg, array $vars): string
    {
        return strtr((string) (((array) $cfg['kind_phrases'])[$kind] ?? str_replace('_', ' ', $kind)), $vars);
    }

    private static function joinPhrases(array $phrases): string
    {
        if (count($phrases) <= 1) return (string) ($phrases[0] ?? '');
        $last = array_pop($phrases);
        return implode(', ', $phrases) . ' and ' . $last;
    }

    /**
     * This turn's concern lines for RelDynFelt, consuming the one-shots only when the player
     * is speaking to this NPC ($playerAddressed): the boundary statement starts the probation
     * when it is said. Lines: ['key', 'lane' (core|turn), 'salience', 'must', 'intense', 'text'].
     *   worry      concern at levels[0]+ (uneasy / raises it / insists), by expression;
     *   reminder   a channel at 2 counted incidents;
     *   probation  while the values boundary watches;
     *   one-shots  noticed, stated_*, boundary, blowup, resolved, step_back.
     *
     * @return array ['lines' => list, 'changed' => bool]
     */
    public static function takeFeltLines(array &$dynamics, string $npcName, string $playerName, float $now, bool $playerAddressed = true): array
    {
        $out = ['lines' => [], 'changed' => false];
        if (!self::enabled() || !is_array($dynamics[self::STATE_KEY] ?? null)) return $out;
        $cfg = self::config();
        $t = (array) $cfg['felt_text'];
        $sal = (array) $cfg['salience'];
        $traits = self::traitsOf($dynamics, $cfg);
        $expr = self::expression($dynamics, $traits, $cfg);
        $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerName];
        $style = (array) (((array) $cfg['style_phrases'])[$expr['style']] ?? []);
        $vars['{STYLE}'] = strtr((string) ($style['phrase'] ?? ''), $vars);
        $vars['{HOW}'] = (string) ($style['how'] ?? '');
        $state = &self::state($dynamics);

        // One-shots, said to the player's face
        if ($playerAddressed && $state['say'] !== []) {
            foreach ($state['say'] as $s) {
                $key = (string) ($s['key'] ?? '');
                $v = $vars + ['{KIND}' => self::kindPhrase((string) ($s['kind'] ?? ''), $cfg, $vars)];
                if (isset($s['style'])) {
                    $sp = (array) (((array) $cfg['style_phrases'])[$s['style']] ?? []);
                    $v['{HOW}'] = (string) ($sp['how'] ?? $v['{HOW}']);
                }
                if ($key === 'noticed') {
                    $cues = array_map(fn($c) => strtr((string) (((array) $cfg['cue_phrases'])[$c] ?? $c), $vars), (array) ($s['cues'] ?? []));
                    $v['{CUES}'] = self::joinPhrases($cues);
                }
                if ($key === 'step_back') {
                    $types = (array) (RelDynFulfillment::config()['type_phrases'] ?? []);
                    $v['{FROM}'] = (string) ($types[$s['from'] ?? ''] ?? 'what they had');
                    $v['{TO}'] = (string) ($types[$s['to'] ?? ''] ?? 'something more distant');
                }
                if (!isset($t[$key])) {
                    error_log("[RelDyn-CONCERN] {$npcName}: no felt text for one-shot '{$key}', dropped");
                    continue;
                }
                $out['lines'][] = ['key' => $key, 'lane' => 'turn', 'salience' => 1.0, 'must' => true, 'intense' => $key === 'blowup',
                    'text' => strtr((string) $t[$key], $v)];
            }
            $state['say'] = [];
            $out['changed'] = true;
        }
        $b = (array) $state['boundary'];
        if ($playerAddressed && ($b['state'] ?? 'none') === 'pending') {
            $out['lines'][] = ['key' => 'boundary', 'lane' => 'turn', 'salience' => 1.0, 'must' => true, 'intense' => false,
                'text' => strtr((string) $t['boundary'], $vars + ['{KIND}' => self::kindPhrase((string) ($b['kind'] ?? ''), $cfg, $vars)])];
            $state['boundary'] = ['state' => 'probation', 'channel' => $b['channel'] ?? null, 'kind' => $b['kind'] ?? null,
                'decided_gamets' => $b['decided_gamets'] ?? $now, 'started_gamets' => $now,
                'until_gamets' => $now + floatval($cfg['probation_game_days']) * self::day()];
            $out['changed'] = true;
            RelationshipDynamics::log("[CONCERN] {$npcName}: values boundary stated ({$b['kind']}), probation {$cfg['probation_game_days']} game days");
        } elseif (($b['state'] ?? 'none') === 'probation') {
            $out['lines'][] = ['key' => 'probation', 'lane' => 'core', 'salience' => floatval($sal['probation']), 'must' => false, 'intense' => false,
                'text' => strtr((string) $t['probation'], $vars + ['{KIND}' => self::kindPhrase((string) ($b['kind'] ?? ''), $cfg, $vars)])];
        }

        // The worry itself
        $level = floatval($state['level']);
        $levels = array_map('floatval', array_values((array) $cfg['levels']));
        if ($level >= ($levels[0] ?? 25.0)) {
            $band = $level >= ($levels[2] ?? 75.0) ? 'worry_insist' : ($level >= ($levels[1] ?? 50.0) ? 'worry_raise' : 'worry_uneasy');
            $kind = self::mainKind($state, self::PROTECTIVE, $now, $cfg);
            // One voice per prompt: once the calm boundary is under way (said or watching, or the
            // step-back made) the worry beside it is calm too, never "sharp, as blame" beside "no
            // shouting, no ultimatum": for an in-between NPC whose values path is the mature one,
            // and for one whose composure dips for now (a werewolf under the moon) while it runs
            $bs = (array) $state['boundary'];
            $calm = $expr['band'] === 'mature'
                || in_array($bs['state'] ?? 'none', ['pending', 'probation', 'failed'], true) || isset($bs['stepped_back_gamets']);
            $text = strtr((string) $t[$band], $vars + ['{KIND}' => self::kindPhrase($kind, $cfg, $vars)])
                . ($calm ? (string) $t['worry_mature'] : '; ' . $vars['{STYLE}']);
            // at the top level the NPC insists or intervenes (§1.4): a line that is never cut
            $out['lines'][] = ['key' => 'worry', 'lane' => 'core', 'salience' => $level / 100.0 + floatval($sal['worry_offset']),
                'must' => $band === 'worry_insist', 'intense' => true, 'text' => $text];
        }

        // The reminder: a kind at two counted nights (the first such kind of the channel)
        foreach ([self::POSSESSIVE, self::PROTECTIVE] as $channel) {
            foreach (self::KINDS as $kind => $ch) {
                if ($ch !== $channel || self::count($dynamics, $kind, $now, $cfg) !== 2) continue;
                $out['lines'][] = ['key' => "reminder_{$channel}", 'lane' => 'core', 'salience' => floatval($sal['reminder']), 'must' => false,
                    'intense' => false, 'text' => strtr((string) $t['reminder'], $vars + ['{KIND}' => ucfirst(self::kindPhrase($kind, $cfg, $vars))])];
                break;
            }
        }
        unset($state);
        return $out;
    }

    // =====================================================================
    // JEV (numbers are fine here, never for the LLM)
    // =====================================================================

    /** Concern numbers for Jev: level (points 0..100), pattern per channel (its highest kind) and per kind, boundary state. */
    public static function jev(array $dynamics, float $now): array
    {
        $cfg = self::config();
        $level = round(self::level($dynamics), 2);
        $levels = array_map('floatval', array_values((array) $cfg['levels']));
        $band = $level >= ($levels[2] ?? 75) ? 'insist' : ($level >= ($levels[1] ?? 50) ? 'raise' : ($level >= ($levels[0] ?? 25) ? 'uneasy' : 'none'));
        return [
            'level' => $level, 'band' => $band,
            'possessive_incidents' => $now > 0 ? self::count($dynamics, self::POSSESSIVE, $now, $cfg) : 0,
            'protective_incidents' => $now > 0 ? self::count($dynamics, self::PROTECTIVE, $now, $cfg) : 0,
            'pattern' => $now > 0 ? self::patterns($dynamics, $now, $cfg) : [],
            'values_boundary' => (string) ($dynamics[self::STATE_KEY]['boundary']['state'] ?? 'none'),
        ];
    }
}
