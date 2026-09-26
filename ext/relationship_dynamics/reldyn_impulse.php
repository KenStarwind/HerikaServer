<?php
/**
 * Relationship Dynamics — impulse (short band) and motivation (long band): the want layer.
 *
 * MDD Addendum 1.3 §13-14 (roadmap impulse-short-band, motivation-inner-conflict), the traits
 * design §2.5 (the impulse engine reads traits, not labels), feedback_autonomy_design.
 *
 * IMPULSE (MDD 13.1, the lizard brain): per NPC and per type, 0..100 impulse points.
 *   Types: romantic, protective, social, survival, curiosity.
 *   Each turn with the player (the felt compose) reads a DRIVE per type from her sources:
 *     romantic    passion (raw) x privacy (fewer people around = more private), + the glow after a
 *                 fight she shared; nothing when the pull is platonic (the Attraction Matrix), after
 *                 a step-back, or while the bond is strained (open conflict, the ick, hurt, a
 *                 bond that broke while he was away)
 *     protective  the player falling in a fight now, the player hurt, fighting beside the player,
 *                 the glow after a fight, her protective worry (concern points) x concern_weight;
 *                 x (1 + k (Pr - 0.5)) (trait_gain)
 *     social      the loneliness timer (game hours since the last meaningful interaction with the
 *                 player, smoothstep onset..full) and a place both can enjoy (positive place
 *                 valence); from context tier min_tier; x (1 + k (W - 0.5)); nothing while strained
 *     survival    her own fall in a fight, her HP (known only while she fights), cold / rain
 *                 (the prerequest's physical states)
 *     curiosity   a place new to her (never seen, or not for revisit_after_game_days), x its appeal
 *                 (base_appeal + her strongest liking among the curiosity facets it carries)
 *   level = max(level decayed, drive): a sustained source holds the level, an event spikes it,
 *   and without stimulus it falls with a half-life of half_life_play_minutes (a play minute is
 *   GAMETS_PER_REAL_SECOND x 60 gamets, the same convention as the combat windows). The time is
 *   her play clock (feedback_timer_design: impulse decay on the play-gamets clock, so alt-tabbing
 *   drains nothing) or the game calendar since her last turn, whichever moved more: a wait is
 *   time in the world without stimulus too (MDD 14.5: "without player stimulus, short-band
 *   impulse returns to baseline"). A calendar gap of reset_after_game_hours or more (a sleep, a
 *   long trip), or a calendar that went back (a load), starts the short band over.
 *
 * THRESHOLD (MDD 13.1, traits design §2.5): the exact fit through Bold 30 / Guarded 70 /
 *   Anxious 40 on the trait engine, threshold = 14 + 69 G - 8 C (impulse points, clamped
 *   [min, max]): Stoic 55, Proud 57, Playful 24, the base vector 45. An impulse FIRES at or above
 *   it and STIRS (leaks without her knowing) within stir_margin under it.
 *
 * EXPRESSION (MDD 13.1 table; traits design §2.5: expression reads E, D, C, Pd, and Anxious false
 *   starts come from high L and low C): the preset nearest to her on those traits only
 *   (style_traits), mapped to its MDD 13.1 row (preset_style): Bold/Defiant bold, Guarded guarded,
 *   Stoic/Independent stoic, Proud proud, Playful playful, Anxious/Jealous anxious (false starts),
 *   Romantic/Gentle/Humble/Nurturing gentle. Exact at the presets; in between, the nearest
 *   expression wins (the traits engine's rule for label-valued surfaces), read from her own
 *   traits, never her temperament label.
 *
 * MOTIVATION (MDD 13.2, long band): her intrinsic goals (RelDynGoals, tier 1: backstory,
 *   trajectory, stage, interests, self-worth; days-to-weeks priority decay) are her motivations,
 *   weighted by priority, top motivation.top kept. The director goal (tier 2, PR 39) is what she
 *   is set on right now; it contends with her top motivation by its priority (MDD 14.4 stack).
 *
 * INNER CONFLICT (MDD 13.3): the top firing impulse against the dominant motivation (the
 *   heaviest of her top motivation and the director goal, at least motivation.min_weight): a
 *   disagreement (alignment[impulse][motivation] <= conflict_at_or_below) is resolved by her
 *   style (Bold/Playful: the impulse; Gentle: the impulse, softly; Guarded/Stoic: the
 *   motivation, leaking; Anxious: neither, frozen; Proud: whichever keeps her dignity, neither if
 *   both look weak). It reaches the LLM as an <inner_conflict> block inside <subtext>: impulse,
 *   motivation and how it resolves, in words (no numbers); the impulse line and the line of the
 *   motivation it names stand down (one voice). Jev gets the numbers (jev()).
 *
 * State: $dynamics['_impulse'] (update()). Units: impulse points 0..100; traits 0..1; play
 * gamets (her play clock) for decay; raw game-calendar gamets for the loneliness timer, the
 * reset and places seen. No wall clock.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynImpulse
{
    const KEY = '_impulse';
    const TYPES = ['romantic', 'protective', 'social', 'survival', 'curiosity'];
    const STYLES = ['bold', 'guarded', 'anxious', 'playful', 'stoic', 'proud', 'gentle'];
    /** Impulse types felt toward the player (bond scope); the rest are her own (self scope). */
    const BOND_TYPES = ['romantic', 'protective', 'social'];

    /**
     * Defaults for config 'impulse'. A stored config replaces settings one by one; the tables
     * merge per entry (config()).
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // Decay: half-life in minutes of play (her play clock or the calendar, whichever moved
            // more); a game-calendar gap of reset_after_game_hours (or a calendar that went back) resets
            'half_life_play_minutes' => 5.0,
            'reset_after_game_hours' => 8.0,
            // Threshold = base + G x guard + C x confidence (impulse points), clamped [min, max]
            'threshold' => ['base' => 14.0, 'guard' => 69.0, 'confidence' => -8.0, 'min' => 10.0, 'max' => 90.0],
            // Impulse points under the threshold where it stirs (leaks without her knowing)
            'stir_margin' => 15.0,
            // Sources, impulse points unless noted
            'romantic' => [
                'passion_weight' => 1.0,          // drive per passion point
                'crowd_per_person' => 0.25,       // privacy = 1 / (1 + this x people around besides the two)
                'aftermath' => 15.0,              // the glow after a fight she shared
                'aftermath_passion_min' => 20.0,  // passion points needed for the glow to turn romantic
            ],
            'protective' => [
                'player_bleedout' => 100.0, 'player_injured' => 60.0, 'fighting_together' => 35.0,
                'aftermath' => 25.0,
                'concern_weight' => 0.6,          // drive per concern point (RelDynConcern)
            ],
            'social' => [
                'min_tier' => 1,                  // context tier (acquaintance) before she can miss the player
                'lonely_onset_game_hours' => 6.0, 'lonely_full_game_hours' => 72.0,
                'lonely_max' => 70.0,
                'shared_interest_max' => 30.0,    // x positive place valence (0..1)
                'meaningful_significance' => 0.3, // eval significance 0..1 that resets the loneliness timer
            ],
            'survival' => [
                'bleeding_out' => 100.0, 'badly_hurt' => 75.0, 'hurt' => 40.0,
                'badly_hurt_below' => 0.3, 'hurt_below' => 0.6,   // her HP ratio 0..1
                'states' => ['cold' => 45.0, 'snowing' => 45.0, 'raining' => 10.0],
            ],
            'curiosity' => [
                'max' => 100.0,
                'base_appeal' => 0.3,             // 0..1: anyone looks around a new place a little
                'facets' => ['scholarly', 'enchanting', 'adventure', 'spiritual', 'alchemy', 'crafting'],
                'revisit_after_game_days' => 30.0,
                'seen_keep' => 60,                // places remembered (count)
            ],
            // drive x (1 + k (trait - 0.5)) per type (unitless)
            'trait_gain' => ['protective' => ['Pr' => 1.0], 'social' => ['W' => 1.0]],
            // Expression style: the preset nearest on these traits (unitless 0..1), and its MDD 13.1 row
            'style_traits' => ['E', 'D', 'C', 'Pd', 'L'],
            'preset_style' => [
                'Bold' => 'bold', 'Defiant' => 'bold', 'Guarded' => 'guarded', 'Stoic' => 'stoic', 'Independent' => 'stoic',
                'Proud' => 'proud', 'Playful' => 'playful', 'Anxious' => 'anxious', 'Jealous' => 'anxious',
                'Romantic' => 'gentle', 'Gentle' => 'gentle', 'Humble' => 'gentle', 'Nurturing' => 'gentle',
            ],
            // Trait codes used when the NPC has no vector yet (the base vector)
            'default_traits' => ['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5,
                'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5],
            // MDD 13.3: style => how a conflict resolves (impulse | impulse_soft | motivation | freeze | dignity)
            'resolution' => ['bold' => 'impulse', 'playful' => 'impulse', 'gentle' => 'impulse_soft',
                'guarded' => 'motivation', 'stoic' => 'motivation', 'anxious' => 'freeze', 'proud' => 'dignity'],
            // Proud: what would look weak to act on
            'dignity' => ['weak_impulses' => ['romantic', 'social', 'survival'], 'weak_motivations' => ['safety', 'self_worth_recovery']],
            // Motivations: her top intrinsic goals by priority (0..1); the dominant one must weigh this much
            'motivation' => ['top' => 3, 'min_weight' => 0.4],
            // Alignment -1..+1 of an impulse with a motivation ('director': the director goal);
            // at or below conflict_at_or_below they disagree
            'alignment' => [
                'romantic'   => ['bond_seeking' => 1.0, 'purpose' => -1.0, 'mastery' => 0.0, 'safety' => -0.5, 'independence' => -1.0,
                                 'revenge' => -1.0, 'self_worth_recovery' => 0.0, 'director' => -1.0],
                'protective' => ['bond_seeking' => 1.0, 'purpose' => 0.0, 'mastery' => 0.0, 'safety' => 0.5, 'independence' => 0.0,
                                 'revenge' => 0.0, 'self_worth_recovery' => 0.0, 'director' => 0.0],
                'social'     => ['bond_seeking' => 1.0, 'purpose' => -0.5, 'mastery' => 0.0, 'safety' => 0.0, 'independence' => -1.0,
                                 'revenge' => -0.5, 'self_worth_recovery' => 0.0, 'director' => -0.5],
                'survival'   => ['bond_seeking' => 0.0, 'purpose' => -1.0, 'mastery' => 0.0, 'safety' => 1.0, 'independence' => 0.0,
                                 'revenge' => -1.0, 'self_worth_recovery' => 0.0, 'director' => -1.0],
                'curiosity'  => ['bond_seeking' => -0.5, 'purpose' => 1.0, 'mastery' => 1.0, 'safety' => -1.0, 'independence' => 0.5,
                                 'revenge' => -0.5, 'self_worth_recovery' => 0.0, 'director' => -0.5],
            ],
            'conflict_at_or_below' => -0.5,
            // Impulse points above the threshold for the stronger words
            'strength' => ['strong' => 15.0, 'overwhelming' => 30.0],
            // Salience (0..1) of the felt lines
            'salience' => ['impulse' => 0.7, 'inner_conflict' => 0.9, 'stirring' => 0.45],
            // Styles whose impulse line takes the intensity formatting (CAPS, '!'); the contained
            // ones (stoic, guarded, proud, gentle) keep their restraint in the text itself
            'intense_styles' => ['bold', 'anxious', 'playful'],
            // Felt text: {NAME} {PLAYER} {URGE} {STRENGTH} {MOTIVE} {RESOLUTION} {PURSUIT} {SUBJECT} {GOAL}.
            // The NPC is named, never a pronoun (the <subtext> header's "them" is the player).
            'urge' => [
                'romantic' => 'to be close to {PLAYER}',
                'protective' => 'to stand between {PLAYER} and harm',
                'social' => 'to talk with {PLAYER} and share something',
                'survival' => 'to get clear of danger and tend the wounds',
                'survival:cold' => 'to get out of the cold',
                'survival:raining' => 'to get out of the rain',
                'curiosity' => 'to go and look around this place',
            ],
            'style_text' => [
                'bold' => '{NAME} feels a pull {URGE} and acts on it plainly: says it straight out or simply does it, no games',
                'guarded' => '{NAME} feels a pull {URGE} and would never admit it: finds excuses to be near, asks a question with an obvious answer, and does not notice doing it',
                'anxious' => "{NAME} wants {URGE} and cannot get it out: starts to say it, stops, starts again ('I wanted to... never mind. Actually...')",
                'playful' => '{NAME} wants {URGE} and turns it into a game: teasing, baiting, daring {PLAYER} to keep up',
                'stoic' => '{NAME} wants {URGE}; it comes out as one loaded sentence, or a silence that says everything',
                'proud' => '{NAME} wants {URGE} but will not be seen asking: arranges things so that {PLAYER} is the one who comes over',
                'gentle' => '{NAME} wants {URGE} and shows it softly: a small offering, a quiet word, lingering a moment longer',
            ],
            'stir_text' => 'Without quite knowing it, {NAME} half-wants {URGE}; it shows only in small things',
            'strength_text' => ['pull' => 'a pull', 'strong' => 'a strong urge', 'overwhelming' => 'an overwhelming urge'],
            'motive_text' => [
                'bond_seeking' => 'getting closer to {PLAYER}',
                'purpose' => "a purpose of {NAME}'s own{SUBJECT}",
                'mastery' => 'getting better at {PURSUIT}',
                'safety' => 'staying safe and keeping an eye on the ways out',
                'independence' => "keeping room to breathe, on {NAME}'s own terms",
                'revenge' => 'an old score to settle{SUBJECT}',
                'self_worth_recovery' => 'the change {NAME} is trying to make',
                'recovery' => 'staying away from {SUBSTANCE}',
                'director' => 'what {NAME} is set on right now: {GOAL}',
            ],
            'resolution_text' => [
                'impulse' => 'the urge wins for now: {NAME} acts first and finds the reasons afterwards',
                'impulse_soft' => 'the heart wins, softly: {NAME} follows the urge gently, without dropping the rest',
                'motivation' => '{NAME} stays the course and holds the urge down, but it leaks through in small ways',
                'freeze' => '{NAME} is caught between the two: starts toward one, then the other, and neither wins',
                'dignity_motivation' => '{NAME} will not look needy: stays the course and lets {PLAYER} be the one to come closer',
                'dignity_impulse' => '{NAME} does what the urge wants and makes it look like a choice, never a need',
                'dignity_neither' => 'both would look like weakness: {NAME} turns cool and does neither, as if none of it mattered',
            ],
            'conflict_text' => "Impulse: {STRENGTH} {URGE}\nMotivation: {MOTIVE}\nTemperament: {RESOLUTION}",
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('impulse');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['threshold', 'romantic', 'protective', 'social', 'survival', 'curiosity', 'resolution', 'dignity', 'motivation', 'preset_style',
                     'strength', 'salience', 'urge', 'style_text', 'strength_text', 'motive_text', 'resolution_text', 'default_traits'] as $merged) {
            $cfg[$merged] = array_replace($defaults[$merged], is_array($stored[$merged] ?? null) ? $stored[$merged] : []);
        }
        $cfg['alignment'] = array_replace_recursive($defaults['alignment'], is_array($stored['alignment'] ?? null) ? $stored['alignment'] : []);
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    // =====================================================================
    // WHO SHE IS (traits -> threshold, style)
    // =====================================================================

    /** Her trait codes (0..1): her vector (RelDynTraits::vectorFor), else default_traits. */
    public static function traitsOf(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $out = array_map('floatval', (array) $cfg['default_traits']);
        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        if (is_array($x)) {
            foreach (RelDynTraits::TRAITS as $code => $_) {
                if (is_numeric($x[$code] ?? null)) $out[$code] = floatval($x[$code]);
            }
        }
        return $out;
    }

    /** Firing threshold (impulse points): base + guard x G + confidence x C, clamped [min, max]. */
    public static function threshold(array $x, ?array $cfg = null): float
    {
        $t = (array) ($cfg ?? self::config())['threshold'];
        $v = floatval($t['base']) + floatval($t['guard']) * floatval($x['G'] ?? 0.5) + floatval($t['confidence']) * floatval($x['C'] ?? 0.5);
        return max(floatval($t['min']), min(floatval($t['max']), $v));
    }

    /**
     * Expression style: the preset nearest to her on style_traits (Euclidean, unitless), mapped by
     * preset_style; 'gentle' for a preset the map lacks. Ties: the MDD 1.3 order of the presets.
     */
    public static function style(array $x, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $best = null;
        $bestD = INF;
        foreach (RelDynTraits::points() as $name => $p) {
            $d = 0.0;
            foreach ((array) $cfg['style_traits'] as $code) {
                $d += (floatval($x[$code] ?? 0.5) - floatval($p[$code] ?? 0.5)) ** 2;
            }
            if ($d < $bestD - 1e-12) { $bestD = $d; $best = $name; }
        }
        $style = $best !== null ? ($cfg['preset_style'][$best] ?? null) : null;
        return in_array($style, self::STYLES, true) ? (string) $style : 'gentle';
    }

    /** Half-life in play gamets (half_life_play_minutes x 60 x GAMETS_PER_REAL_SECOND). */
    public static function halfLifePlayGamets(?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        return max(0.0, floatval($cfg['half_life_play_minutes'])) * 60.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    }

    // =====================================================================
    // LEVELS
    // =====================================================================

    /**
     * Her stored levels decayed to play clock $play and calendar $now (0 = unknown): the time since
     * the last update (play clock or calendar, whichever moved more; gamets) halves them every
     * half-life; a calendar gap of reset_after_game_hours or more, or a calendar behind the stored
     * one (a load), resets them.
     */
    public static function decayed(array $state, float $play, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $levels = array_fill_keys(self::TYPES, 0.0);
        $last = floatval($state['gamets'] ?? 0);
        if ($now > 0 && $last > 0 && ($now < $last
                || ($now - $last) >= floatval($cfg['reset_after_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0)) {
            return $levels;
        }
        $dt = max(0.0, $play - floatval($state['play_gamets'] ?? $play));
        if ($now > 0 && $last > 0) $dt = max($dt, $now - $last);   // a wait passes in the world too
        $half = self::halfLifePlayGamets($cfg);
        $f = $half > 0 ? pow(0.5, $dt / $half) : 0.0;
        foreach (self::TYPES as $type) {
            $levels[$type] = max(0.0, min(100.0, floatval($state['levels'][$type] ?? 0) * $f));
        }
        return $levels;
    }

    private static function smoothstep(float $v, float $a, float $b): float
    {
        if ($b <= $a) return $v >= $b ? 1.0 : 0.0;
        $t = max(0.0, min(1.0, ($v - $a) / ($b - $a)));
        return $t * $t * (3.0 - 2.0 * $t);
    }

    /** drive x prod(1 + k (trait - 0.5)) over the type's trait_gain entries. */
    private static function traitGain(string $type, array $x, array $cfg): float
    {
        $g = 1.0;
        foreach ((array) ($cfg['trait_gain'][$type] ?? []) as $code => $k) {
            $g *= max(0.0, 1.0 + floatval($k) * (floatval($x[$code] ?? 0.5) - 0.5));
        }
        return $g;
    }

    /**
     * The drive of each type now (impulse points 0..100) and its strongest source.
     *
     * $env (all optional):
     *   now float               raw game-calendar gamets (0 = unknown)
     *   present bool            the player is with her (default true: compose runs for her turns with the player)
     *   audience int            people around besides her and the player
     *   tier int                context tier 0..3
     *   strained bool           the bond is strained (open conflict, the ick, resentment / jealousy high,
     *                           a fresh bond break: RelDynAbsence::strains)
     *   platonic bool           the pull is not that kind (Attraction Matrix, step-back)
     *   place ?string, facets array, prefs array   the place read this turn, her preferences
     *   place_valence ?float    her appraisal of it (-1..1)
     *   combat ?array           RelationshipDynamics::getCombatContext
     *   aftermath bool          the glow after a fight she was in
     *   player_bleeding_out bool, physical string[]  the prerequest's physical states
     *   concern float           her protective worry, concern points 0..100
     *   last_meaningful ?float  calendar gamets of the last meaningful interaction
     *   seen ?float             calendar gamets she last saw this place (null = never)
     * @return array ['levels' => type => points, 'sources' => type => ?string]
     */
    public static function drives(array $dynamics, array $env, array $x, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $drive = array_fill_keys(self::TYPES, 0.0);
        $source = array_fill_keys(self::TYPES, null);
        $take = function (string $type, float $v, string $why) use (&$drive, &$source): void {
            $v = max(0.0, min(100.0, $v));
            if ($v > $drive[$type]) { $drive[$type] = $v; $source[$type] = $why; }
        };
        $present = (bool) ($env['present'] ?? true);
        $now = floatval($env['now'] ?? 0);
        $strained = !empty($env['strained']);
        $combat = is_array($env['combat'] ?? null) ? $env['combat'] : null;
        $fighting = $combat !== null && !empty($combat['in_combat']);
        $aftermath = !empty($env['aftermath']) && !$fighting;

        // Romantic: passion x privacy, with the player here; the glow after a shared fight. The
        // short band reads the passion of the moment: the effective passion (the earned floor +
        // the spike + the weather's pull, RelDynPassion), not the floor alone
        $r = (array) $cfg['romantic'];
        if ($present && !$strained && empty($env['platonic'])) {
            $passion = RelationshipDynamics::getEffectivePassion($dynamics);
            $privacy = 1.0 / (1.0 + floatval($r['crowd_per_person']) * max(0, intval($env['audience'] ?? 0)));
            $take('romantic', $passion * floatval($r['passion_weight']) * $privacy, 'passion');
            if ($aftermath && $passion >= floatval($r['aftermath_passion_min'])) {
                $take('romantic', $passion * floatval($r['passion_weight']) * $privacy + floatval($r['aftermath']), 'aftermath');
            }
        }

        // Protective: the player in danger, hurt, fighting beside her; her worry
        $p = (array) $cfg['protective'];
        $gain = self::traitGain('protective', $x, $cfg);
        if (!empty($env['player_bleeding_out'])) $take('protective', floatval($p['player_bleedout']) * $gain, 'player_bleedout');
        if (in_array('injured', (array) ($env['physical'] ?? []), true)) $take('protective', floatval($p['player_injured']) * $gain, 'player_injured');
        if ($fighting && $present) $take('protective', floatval($p['fighting_together']) * $gain, 'fighting');
        if ($aftermath) $take('protective', floatval($p['aftermath']) * $gain, 'aftermath');
        $take('protective', floatval($env['concern'] ?? 0) * floatval($p['concern_weight']) * $gain, 'concern');

        // Social: the loneliness timer and a place both can enjoy, from acquaintance up
        $s = (array) $cfg['social'];
        if ($present && !$strained && intval($env['tier'] ?? 0) >= intval($s['min_tier'])) {
            $gain = self::traitGain('social', $x, $cfg);
            $last = $env['last_meaningful'] ?? null;
            $lonely = 0.0;
            if (is_numeric($last) && floatval($last) > 0 && $now > floatval($last)) {
                $hours = ($now - floatval($last)) / (RelationshipDynamics::GAMETS_PER_DAY / 24.0);
                $lonely = self::smoothstep($hours, floatval($s['lonely_onset_game_hours']), floatval($s['lonely_full_game_hours']));
            }
            $shared = max(0.0, floatval($env['place_valence'] ?? 0));
            $v = ($lonely * floatval($s['lonely_max']) + $shared * floatval($s['shared_interest_max'])) * $gain;
            $take('social', $v, $lonely * floatval($s['lonely_max']) >= $shared * floatval($s['shared_interest_max']) ? 'lonely' : 'shared_interest');
        }

        // Survival: her own fall, her HP while she fights, cold and rain
        $sv = (array) $cfg['survival'];
        if ($combat !== null && !empty($combat['bleeding_out'])) $take('survival', floatval($sv['bleeding_out']), 'bleeding_out');
        $hp = $fighting && is_numeric($combat['health_pct'] ?? null) ? floatval($combat['health_pct']) : null;
        if ($hp !== null && $hp < floatval($sv['badly_hurt_below'])) $take('survival', floatval($sv['badly_hurt']), 'badly_hurt');
        elseif ($hp !== null && $hp < floatval($sv['hurt_below'])) $take('survival', floatval($sv['hurt']), 'hurt');
        foreach ((array) ($env['physical'] ?? []) as $state) {
            if (isset($sv['states'][$state])) $take('survival', floatval($sv['states'][$state]), (string) $state);
        }

        // Curiosity: a place new to her, by how much it calls to her
        $c = (array) $cfg['curiosity'];
        $place = trim((string) ($env['place'] ?? ''));
        if ($place !== '' && ($env['facets'] ?? []) !== []) {
            $seen = $env['seen'] ?? null;
            $novel = !is_numeric($seen) || ($now > 0 && ($now - floatval($seen)) >= floatval($c['revisit_after_game_days']) * RelationshipDynamics::GAMETS_PER_DAY);
            if ($novel) {
                $prefs = (array) ($env['prefs'] ?? []);
                $best = 0.0;
                foreach ((array) $c['facets'] as $f) {
                    $best = max($best, floatval($env['facets'][$f] ?? 0) * max(0.0, floatval($prefs[$f] ?? 0)));
                }
                $base = max(0.0, min(1.0, floatval($c['base_appeal'])));
                $take('curiosity', floatval($c['max']) * ($base + (1.0 - $base) * min(1.0, $best)), 'new_place');
            }
        }
        return ['levels' => $drive, 'sources' => $source];
    }

    // =====================================================================
    // MOTIVATION (long band) AND THE CONFLICT
    // =====================================================================

    /** Her motivations: the top motivation.top intrinsic goals, ['type', 'weight' 0..1, 'goal'], heaviest first. */
    public static function motivations(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $out = [];
        foreach (array_slice(RelDynGoals::active($dynamics), 0, max(1, intval($cfg['motivation']['top']))) as $g) {
            $out[] = ['type' => (string) $g['type'], 'weight' => max(0.0, min(1.0, floatval($g['priority'] ?? 0))), 'goal' => $g];
        }
        return $out;
    }

    /**
     * The motivation in charge: the heavier of her top motivation and the active director goal
     * ('director', its priority), when it weighs at least motivation.min_weight; else null.
     */
    public static function dominantMotivation(array $dynamics, ?array $directorGoal, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $best = self::motivations($dynamics, $cfg)[0] ?? null;
        if (is_array($directorGoal) && trim((string) ($directorGoal['text'] ?? '')) !== '') {
            $w = max(0.0, min(1.0, floatval($directorGoal['priority'] ?? 0.5)));
            if ($best === null || $w > $best['weight']) $best = ['type' => 'director', 'weight' => $w, 'goal' => $directorGoal];
        }
        if ($best === null || $best['weight'] < floatval($cfg['motivation']['min_weight'])) return null;
        return $best;
    }

    public static function alignment(string $impulse, string $motivation, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        return max(-1.0, min(1.0, floatval($cfg['alignment'][$impulse][$motivation] ?? 0.0)));
    }

    /** How a conflict resolves for her style (MDD 13.3); Proud: whichever keeps her dignity. */
    public static function resolve(string $style, string $impulse, string $motivation, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $r = (string) ($cfg['resolution'][$style] ?? 'motivation');
        if ($r !== 'dignity') return $r;
        $weakImpulse = in_array($impulse, (array) $cfg['dignity']['weak_impulses'], true);
        $weakMotive = in_array($motivation, (array) $cfg['dignity']['weak_motivations'], true);
        if ($weakImpulse && $weakMotive) return 'dignity_neither';
        if ($weakMotive) return 'dignity_impulse';
        return 'dignity_motivation';
    }

    // =====================================================================
    // THE TURN
    // =====================================================================

    /**
     * Her turn with the player (the felt compose): decay, the drives, the threshold, which fires,
     * and the conflict with the motivation in charge. Stores and returns the state; null when
     * the impulse layer is off. $env as drives() plus:
     *   play float     her play clock (default RelationshipDynamics::getPlayGamets)
     *   goal ?array    the active director goal
     */
    public static function update(string $npcName, array &$dynamics, array $env): ?array
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) {
            unset($dynamics[self::KEY]);
            return null;
        }
        $prev = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $now = floatval($env['now'] ?? 0);
        $play = floatval($env['play'] ?? RelationshipDynamics::getPlayGamets($dynamics));
        $x = self::traitsOf($dynamics, $cfg);

        // A load behind the stored calendar: what was stamped after it never happened
        if ($now > 0 && floatval($prev['gamets'] ?? 0) > $now) self::rebaseline($prev, $now);

        $placeKey = strtolower(trim((string) ($env['place'] ?? '')));
        $seen = is_array($prev['seen'] ?? null) ? $prev['seen'] : [];
        $env['seen'] = $placeKey !== '' ? ($seen[$placeKey] ?? null) : null;
        $env['last_meaningful'] = $prev['last_meaningful_gamets'] ?? null;

        $levels = self::decayed($prev, $play, $now, $cfg);
        $d = self::drives($dynamics, $env, $x, $cfg);
        $sources = is_array($prev['sources'] ?? null) ? $prev['sources'] : [];
        foreach (self::TYPES as $type) {
            if ($d['levels'][$type] >= $levels[$type] && $d['levels'][$type] > 0) {
                $levels[$type] = $d['levels'][$type];
                $sources[$type] = $d['sources'][$type];
            }
            $levels[$type] = round($levels[$type], 2);
            if ($levels[$type] <= 0.0) unset($sources[$type]);
        }

        $threshold = round(self::threshold($x, $cfg), 2);
        $style = self::style($x, $cfg);
        $firing = [];
        $stirring = [];
        foreach (self::TYPES as $type) {
            if ($levels[$type] >= $threshold) $firing[$type] = $levels[$type] - $threshold;
            elseif ($levels[$type] > 0 && $levels[$type] >= $threshold - floatval($cfg['stir_margin'])) $stirring[$type] = $levels[$type];
        }
        arsort($firing);
        arsort($stirring);
        $top = array_key_first($firing);

        $conflict = null;
        if ($top !== null) {
            $m = self::dominantMotivation($dynamics, is_array($env['goal'] ?? null) ? $env['goal'] : null, $cfg);
            if ($m !== null) {
                $a = self::alignment($top, $m['type'], $cfg);
                if ($a <= floatval($cfg['conflict_at_or_below'])) {
                    $conflict = ['impulse' => $top, 'motivation' => $m['type'], 'weight' => round($m['weight'], 3), 'alignment' => $a,
                        'resolution' => self::resolve($style, $top, $m['type'], $cfg)];
                    if ($m['type'] === 'director') {
                        $conflict['goal_text'] = trim((string) ($m['goal']['text'] ?? ''));
                    } else {
                        $conflict['goal'] = ['type' => (string) $m['goal']['type'], 'facets' => (array) ($m['goal']['facets'] ?? []),
                            'keywords' => array_values((array) ($m['goal']['keywords'] ?? []))];
                    }
                }
            }
        }

        if ($placeKey !== '' && $now > 0) {
            $seen[$placeKey] = $now;
            arsort($seen);
            $seen = array_slice($seen, 0, max(1, intval($cfg['curiosity']['seen_keep'])), true);
        }
        $state = [
            'levels' => $levels, 'sources' => $sources, 'play_gamets' => $play, 'gamets' => $now,
            'threshold' => $threshold, 'style' => $style, 'false_start' => $style === 'anxious',
            'firing' => array_keys($firing), 'top' => $top, 'stirring' => array_key_first($stirring),
            'conflict' => $conflict, 'seen' => $seen,
        ];
        if (is_numeric($prev['last_meaningful_gamets'] ?? null)) $state['last_meaningful_gamets'] = floatval($prev['last_meaningful_gamets']);
        $dynamics[self::KEY] = $state;
        if ($top !== null) {
            RelationshipDynamics::log("[RelDyn-IMPULSE] {$npcName}: {$top} " . $levels[$top] . " >= {$threshold} ({$style}, "
                . ($sources[$top] ?? '-') . ')' . ($conflict !== null ? " vs {$conflict['motivation']} -> {$conflict['resolution']}" : ''));
        }
        return $state;
    }

    /**
     * A meaningful interaction with the player (an applied eval item of at least
     * social.meaningful_significance, or a legacy-scored exchange): the loneliness timer starts
     * over at $gamets (raw game calendar), and her social impulse is met.
     */
    public static function noteMeaningful(array &$dynamics, float $significance, float $gamets): bool
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $gamets <= 0 || $significance < floatval($cfg['social']['meaningful_significance'])) return false;
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        if ($gamets <= floatval($state['last_meaningful_gamets'] ?? 0)) return false;
        $state['last_meaningful_gamets'] = $gamets;
        if (isset($state['levels']['social'])) $state['levels']['social'] = 0.0;
        $dynamics[self::KEY] = $state;
        return true;
    }

    /** A load to calendar $T: the short band starts over; stamps after $T come back to it. */
    public static function rebaseline(array &$state, float $T): void
    {
        unset($state['levels'], $state['sources'], $state['conflict'], $state['top'], $state['firing'], $state['stirring']);
        if (floatval($state['gamets'] ?? 0) > $T) $state['gamets'] = $T;
        if (is_numeric($state['last_meaningful_gamets'] ?? null) && floatval($state['last_meaningful_gamets']) > $T) {
            $state['last_meaningful_gamets'] = $T;
        }
        if (is_array($state['seen'] ?? null)) {
            $state['seen'] = array_filter($state['seen'], fn($g) => is_numeric($g) && floatval($g) <= $T);
        }
    }

    // =====================================================================
    // OUTPUT
    // =====================================================================

    private static function urge(string $type, ?string $source, array $cfg): string
    {
        $u = (array) $cfg['urge'];
        return (string) ($u["{$type}:{$source}"] ?? $u[$type] ?? '');
    }

    /** {MOTIVE}'s phrase for the conflict's motivation (her goal's pursuit / subject, or the director goal). */
    private static function motive(array $conflict, array $cfg): string
    {
        $type = (string) $conflict['motivation'];
        $text = (string) ($cfg['motive_text'][$type] ?? '');
        $vars = ['{GOAL}' => rtrim(trim((string) ($conflict['goal_text'] ?? '')), '.')];
        if (is_array($conflict['goal'] ?? null)) $vars += RelDynGoals::phraseVars($conflict['goal']);
        return strtr($text, $vars + ['{PURSUIT}' => 'what matters most', '{SUBJECT}' => '']);
    }

    /**
     * The felt lines of her current state (a feeling, never a number): the inner conflict (tag
     * 'inner_conflict'), or else the top firing impulse in her style, or else the strongest
     * stirring one. Each: ['key', 'scope' self|bond, 'salience' 0..1, 'text', 'tag' ?string,
     * 'intense' bool, 'motivation' ?string (the motivation a conflict voices)].
     */
    public static function feltLines(string $npcName, string $playerRef, array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $s = $dynamics[self::KEY] ?? null;
        if (empty($cfg['enabled']) || !is_array($s)) return [];
        $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerRef];
        $sal = (array) $cfg['salience'];
        $sources = (array) ($s['sources'] ?? []);
        $scope = fn(string $t) => in_array($t, self::BOND_TYPES, true) ? 'bond' : 'self';
        $top = $s['top'] ?? null;
        if (is_string($top) && in_array($top, self::TYPES, true)) {
            $urge = strtr(self::urge($top, $sources[$top] ?? null, $cfg), $vars);
            $margin = floatval($s['levels'][$top] ?? 0) - floatval($s['threshold'] ?? 0);
            $c = $s['conflict'] ?? null;
            if (is_array($c) && ($c['impulse'] ?? null) === $top) {
                $st = (array) $cfg['strength'];
                $strength = $margin >= floatval($st['overwhelming']) ? 'overwhelming' : ($margin >= floatval($st['strong']) ? 'strong' : 'pull');
                $text = strtr((string) $cfg['conflict_text'], [
                    '{STRENGTH}' => (string) $cfg['strength_text'][$strength],
                    '{URGE}' => $urge,
                    '{MOTIVE}' => strtr(self::motive($c, $cfg), $vars),
                    '{RESOLUTION}' => strtr((string) ($cfg['resolution_text'][$c['resolution']] ?? ''), $vars),
                ]);
                return [['key' => 'inner_conflict', 'scope' => $scope($top), 'salience' => floatval($sal['inner_conflict']),
                    'text' => $text, 'tag' => 'inner_conflict', 'intense' => false, 'motivation' => (string) $c['motivation']]];
            }
            $style = (string) ($s['style'] ?? 'gentle');
            $text = strtr((string) ($cfg['style_text'][$style] ?? $cfg['style_text']['gentle']), $vars + ['{URGE}' => $urge]);
            return [['key' => 'impulse', 'scope' => $scope($top), 'salience' => min(1.0, floatval($sal['impulse']) + max(0.0, $margin) / 100.0),
                'text' => $text, 'tag' => null, 'intense' => in_array($style, (array) $cfg['intense_styles'], true), 'motivation' => null]];
        }
        $stir = $s['stirring'] ?? null;
        if (is_string($stir) && in_array($stir, self::TYPES, true)) {
            $urge = strtr(self::urge($stir, $sources[$stir] ?? null, $cfg), $vars);
            return [['key' => 'impulse_stirring', 'scope' => $scope($stir), 'salience' => floatval($sal['stirring']),
                'text' => strtr((string) $cfg['stir_text'], $vars + ['{URGE}' => $urge]), 'tag' => null, 'intense' => false, 'motivation' => null]];
        }
        return [];
    }

    /**
     * Jev: the numbers. ['levels' => type => impulse points (as of her last turn, decayed to her
     * play clock), 'threshold' => impulse points, 'style', 'false_start', 'firing' => types,
     * 'top' => ?type, 'source' => ?string, 'motivations' => [['type', 'weight' 0..1]],
     * 'conflict' => null | ['impulse', 'motivation' (a goal type | 'director'), 'weight' 0..1,
     * 'alignment' -1..1, 'resolution']]; null when the layer is off or she has no state yet.
     */
    public static function jev(array $dynamics): ?array
    {
        $cfg = self::config();
        $s = $dynamics[self::KEY] ?? null;
        if (empty($cfg['enabled']) || !is_array($s) || !isset($s['threshold'])) return null;
        $levels = self::decayed($s, RelationshipDynamics::getPlayGamets($dynamics), 0.0, $cfg);
        $threshold = floatval($s['threshold']);
        $firing = array_values(array_filter(self::TYPES, fn($t) => $levels[$t] >= $threshold && $levels[$t] > 0));
        usort($firing, fn($a, $b) => $levels[$b] <=> $levels[$a]);
        $top = $firing[0] ?? null;
        $c = is_array($s['conflict'] ?? null) && ($s['conflict']['impulse'] ?? null) === $top ? $s['conflict'] : null;
        return [
            'levels' => array_map(fn($v) => round($v, 2), $levels),
            'threshold' => round($threshold, 2),
            'style' => (string) ($s['style'] ?? 'gentle'),
            'false_start' => !empty($s['false_start']),
            'firing' => $firing,
            'top' => $top,
            'source' => $top !== null ? ($s['sources'][$top] ?? null) : null,
            'motivations' => array_map(fn($m) => ['type' => $m['type'], 'weight' => round($m['weight'], 3)], self::motivations($dynamics, $cfg)),
            'conflict' => $c !== null ? ['impulse' => (string) $c['impulse'], 'motivation' => (string) $c['motivation'],
                'weight' => round(floatval($c['weight']), 3), 'alignment' => floatval($c['alignment']), 'resolution' => (string) $c['resolution']] : null,
        ];
    }
}
