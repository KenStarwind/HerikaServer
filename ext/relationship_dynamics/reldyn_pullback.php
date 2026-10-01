<?php
/**
 * Relationship Dynamics — let in, and pulling back.
 *
 * Ken, 2026-10-01: "closed off" is not a pass / fail and it is not permanent. Relationships have
 * ups and downs: if the internal weather has turned inclement and she feels unfulfilled she may
 * close off for some time, even at a high tier. Maturity decides how it shows: an immature
 * person lets emotions dictate mood; a more mature one voices how they feel; a less mature one
 * pouts or fights.
 *
 * Two separate things (the old static bridge, derived warmth <= 35 at tier 2+, was both at
 * once, and permanent: a devoted bond with no passion read closed forever, a romance never
 * closed on a bad stretch):
 *
 *   LET IN   durable, earned: how far she has let the player in, sqrt(comfort x trust) in any
 *            bond type (points 0..100). No passion term. A guarded NPC (trait G) earns it slower
 *            through her own slower comfort and trust gains, so there is no extra penalty here.
 *            Below let_in.low she has not let the player in yet.
 *   PULLING  a temporary state: pressure 0..1 (hysteresis, persists across turns with its
 *   BACK     since-gamets). Inputs: the internal weather (its state and the gravity it has built
 *            up), the fulfillment deficit (unmet needs; RelDynFulfillment::weatherDeprivation, or
 *            the relationship_deprivation of _weather_state), and unresolved resentment or an open
 *            conflict. Mood sensitivity scales with immaturity (weather drives an immature NPC
 *            hard, a mature one little, never nothing); guard lowers the on-threshold, a deeper
 *            let-in raises it. On above the on-threshold, off below the lower off-threshold, so
 *            it does not flicker; it fades as the inputs ease. A mature NPC who has voiced it and
 *            is then met (a positive exchange: the eval's positive_interaction / goal_addressed /
 *            a reassurance or quality-time tag) reopens faster.
 *
 * THE MORNING AFTER (Ken, 2026-10-01 §22): after intimacy a fearful NPC (anxious AND avoidant: the fearful corner of
 * the attachment axes) pulls back for a while: a fourth input, 'aftermath', sized by how fearful the NPC is (the corners
 * blended at the NPC's own axes, so a half-fearful NPC gets half; the avoidant corner part of it, the secure
 * the least, never zero) and by nothing else: it holds for a night, then fades like any pull-back, and a reassuring
 * exchange takes some of it off. Distance for a while, never shame: its words say the closeness was welcome and a lot, and that
 * room is what is needed (RelDynPostIntimacy::onIntimateRequest hands the encounter to onIntimacy()).
 *
 * How it shows is RelDynConcern::expression (one mechanism for every channel, with this lane's
 * own style table): mature voices it plainly and asks for it, an in-between NPC means to and it
 * comes out sharp, an immature one pouts or picks a fight (reactivity L); the attachment corner
 * colours it (anxious: protest and reassurance-seeking; avoidant: quiet and distant). The state
 * stands in RelDynFelt's knowledge-of-player bridge (the tension line) once she is let in; its
 * entering and reopening are said once to the player's face (takeFeltLines). Felt text only:
 * feelings, no digits; Jev gets the numbers (jev()).
 *
 * State: $dynamics['_pullback'] (state()). Units: let-in and trait-free inputs as noted; pressure,
 * inputs and thresholds 0..1 (unitless), dimension points 0..100, game hours and raw gamets on
 * the game calendar (no wall clock).
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynPullback
{
    const KEY = '_pullback';
    const VERSION = 1;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'pullback' (nested tables merge per entry; 'styles' is taken whole). */
    public static function configDefaults(): array
    {
        return [
            // Off: the knowledge-of-player bridge is exactly the old one (derived warmth <= closed_warmth_max).
            'enabled' => true,

            // How far she has let the player in = sqrt(comfort x trust) (dimension points).
            'let_in' => [
                // below this she has not let the player in yet (and has nothing to pull back from)
                'low' => 40.0,
            ],

            // --- the pressure's target: the weighted inputs, each 0..1 ---
            'weights' => [
                'weather'   => 0.45,   // the internal weather and its gravity, x her mood gain
                'deficit'   => 0.60,   // the relationship's fulfillment deficit (unmet needs)
                'grievance' => 0.35,   // unresolved resentment or an open conflict
                'aftermath' => 2.00,   // the morning after intimacy, for the one who fears the closeness (aftermath below): at half the push it presses in full
            ],
            // Decisions §22 (Ken, 2026-10-01): the fearful morning after, through the pull-back. The input is
            // size x shape: size = push x how fearful the NPC is (fearful: the attachment corner => 0..1,
            // blended at the NPC's two axes; the fearful corner, anxious and avoidant at once, in full), shape =
            // 1 for hold_game_hours after the encounter's last scene request, then falling to nothing over
            // fade_game_hours. A positive exchange that meets the NPC (met.tags / met.flags) takes met_relief of what
            // is left. Not shame: a distance, said as one (felt_text 'enter_aftermath_*').
            'aftermath' => [
                'enabled' => true,
                'fearful' => ['secure' => 0.03, 'anxious' => 0.1, 'avoidant' => 0.3, 'toxic' => 1.0],
                'push' => 1.0,
                'hold_game_hours' => 12.0,
                'fade_game_hours' => 24.0,
                'met_relief' => 0.5,
            ],
            // weather => how hard it pushes (0 none .. 1 full)
            'weather_push' => ['sunny' => 0.0, 'clear' => 0.0, 'overcast' => 0.45, 'stormy' => 1.0],
            // The weather's gravity (RelationshipDynamics::applyWeatherGravity) builds up and lags:
            // a bad stretch keeps pulling after the weather clears. share = how much of the weather
            // input it is; valence_full = the held valence offset (points, negative) that reads as full.
            'gravity' => ['share' => 0.5, 'valence_full' => 15.0],
            // Resentment (points) from which it pushes, and at which it pushes in full; an open conflict
            // pushes 'conflict', each repair (positive exchange since it opened) takes 'repair_relief' of it off.
            'grievance' => ['resentment_from' => 25.0, 'resentment_full' => 75.0, 'conflict' => 0.6, 'repair_relief' => 0.25],
            // Mood sensitivity: the multiplier on the weather input at maturity weight 0 (immature,
            // RelDynConcern::expression w) and 1 (mature). Mature damps it, never to zero.
            'mood_gain' => ['immature' => 1.6, 'mature' => 0.4],

            // --- hysteresis ---
            // on above 'on'; off below on - gap. Guard (G, 0..1) moves both by -guard_shift per unit above
            // the middle; a deeper let-in raises them by let_in_shift per 50 points above 50 (and lowers
            // them as far below). The off-threshold never goes under off_min.
            'threshold' => ['on' => 0.55, 'gap' => 0.25, 'guard_shift' => 0.30, 'let_in_shift' => 0.12, 'off_min' => 0.05],
            // The pressure follows its target on the game calendar: this share of the gap per game hour
            // (up: how fast a bad stretch builds; down: how fast it eases), a step at most max_step_game_hours.
            'rates' => ['rise_per_game_hour' => 0.12, 'fall_per_game_hour' => 0.06, 'max_step_game_hours' => 72.0],

            // --- being met ---
            // A positive exchange while she is pulled back takes 'relief' (pressure) off, scaled by the
            // exchange's significance (floor + (1 - floor) s), by how mature she is (immature_mult at
            // weight 0 up to 1) and by whether she has voiced it (unvoiced_mult when she has not), and
            // eases the target by 'ease' for ease_game_hours (the needs just met are not unmet again at once).
            'met' => [
                'relief' => 0.35, 'significance_floor' => 0.3, 'immature_mult' => 0.35, 'unvoiced_mult' => 0.5,
                'ease' => 0.5, 'ease_game_hours' => 12.0,
                'tags' => ['reassurance', 'quality_time'],
                'flags' => ['positive_interaction', 'goal_addressed'],
            ],

            // --- expression ---
            // Immature style = the highest score of sum(weight x (trait - 0.5)): a fight when reactivity (L) is
            // high, a sulk when it is low (and expressiveness E and confidence C are low too). RelDynConcern::expression
            // reads this table (the concern lane's own has a third, control, that does not belong here).
            'styles' => [
                'accusation' => ['L' => 1.0, 'Pd' => 0.5],
                'sulking'    => ['L' => -1.0, 'E' => -1.0, 'C' => -0.5],
            ],
            // What she names as missing when no single need stands out.
            'needs_fallback' => 'the ease there used to be between them',
            'salience' => ['reopen' => 1.0, 'enter' => 1.0],

            // --- felt text (feelings, never numbers). {NAME} NPC, {PLAYER} the player, {NEEDS} what is
            // missing, {HOW} how it comes out. Names the NPC sparingly, no pronoun for her. ---
            'felt_text' => [
                // the standing state, in knowledge_of_player once she is let in (kept to one sentence)
                'standing_mature'     => "Has pulled back from {PLAYER} for now and knows why: missing {NEEDS}, and has said so; the care is all still there, held back until it is met.",
                'standing_mixed'      => "Pulling back from {PLAYER}, and it comes out sharp: means to say plainly what has been missing ({NEEDS}), and says it {HOW} instead.",
                'standing_accusation' => "Spoiling for a fight with {PLAYER}: everything small becomes proof that {NEEDS} will never come, and it comes out as blame.",
                'standing_sulking'    => "Pouting and pulled back from {PLAYER}: short answers, less warmth than usual, waiting to be asked what is wrong.",
                // said once, to the player's face, when she pulls back
                'enter_mature'     => "{NAME} tells {PLAYER} plainly what has been missing lately: {NEEDS}. Not blame, a plain fact about where things stand, and a request to have it back; then room for {PLAYER} to answer.",
                'enter_mixed'      => "{NAME} means to say evenly what has been missing ({NEEDS}), but it comes out {HOW}.",
                'enter_accusation' => "{NAME} picks at {PLAYER}: where were they, why is it always like this, does any of it matter. It is really about missing {NEEDS}, and it comes out as a fight.",
                'enter_sulking'    => "{NAME} withdraws from {PLAYER}: short answers, eyes elsewhere, no warmth, waiting to be asked what is wrong and not offering it.",
                // said once, when she opens up again
                // the morning after intimacy for the one who fears the closeness (Ken §22: distance for a while, never shame)
                'standing_aftermath_mature'     => "Needs a little distance from {PLAYER} after getting so close, and has said so: the closeness was welcome and it is a lot all at once; the care is all still there.",
                'standing_aftermath_mixed'      => "Keeping some distance from {PLAYER} after getting so close: means to say plainly that it is a lot all at once, and says it {HOW} instead.",
                'standing_aftermath_accusation' => "Spiky with {PLAYER} after getting so close: small things become proof of something, and it comes out as blame; it is the closeness that frightens, not {PLAYER}.",
                'standing_aftermath_sulking'    => "Quiet and a step away from {PLAYER} after getting so close: short answers, room left between them, waiting to be asked what is wrong.",
                'enter_aftermath_mature'     => "{NAME} tells {PLAYER} plainly that being that close was welcome and is a lot all at once, and that a little distance would help {NAME} feel steady again. Nothing {PLAYER} did; a request for room, and then room for {PLAYER} to answer.",
                'enter_aftermath_mixed'      => "{NAME} means to say evenly that getting that close was a lot and some room would help, but it comes out {HOW}.",
                'enter_aftermath_accusation' => "{NAME} picks at {PLAYER} the morning after: how quickly it all went, what it was supposed to mean. It is really the closeness that frightens {NAME}, and it comes out as a fight.",
                'enter_aftermath_sulking'    => "{NAME} keeps {PLAYER} at arm's length the morning after: short answers, room left between them, waiting to be asked what is wrong and not offering it.",
                'reopen_mature'    => "Something in {NAME} eases: what was missing has been given, and the distance closes without a speech.",
                'reopen_immature'  => "The mood lifts without anyone saying so: {NAME} is warmer toward {PLAYER} again, a little grudgingly.",
            ],
            // how it comes out for the in-between NPC ({HOW}): by style
            'style_phrases' => [
                'accusation' => ['how' => 'as an accusation'],
                'sulking'    => ['how' => 'clipped and cold, then silence'],
            ],
            // The attachment corner's colour on any of the above: 'mature' for one who voices it, 'raw' otherwise.
            'attachment_phrases' => [
                'anxious' => [
                    'mature' => "; asks to be reassured that {PLAYER} still wants {NAME} close",
                    'raw'    => "; keeps testing whether {PLAYER} still wants {NAME} near, protesting, needing to be told",
                ],
                'avoidant' => [
                    'mature' => "; says it quietly and briefly, then keeps some distance",
                    'raw'    => "; goes quiet and distant, and would sooner stay that way than ask",
                ],
                'toxic' => [
                    'mature' => "; wants {PLAYER} to come after {NAME}, and says so, badly",
                    'raw'    => "; wants {PLAYER} to come after {NAME} and makes it hard to, reaching and shoving away in the same breath",
                ],
            ],
        ];
    }

    private const MERGED_TABLES = ['let_in', 'weights', 'weather_push', 'gravity', 'grievance', 'mood_gain', 'threshold', 'rates', 'met',
        'aftermath', 'salience', 'felt_text', 'style_phrases', 'attachment_phrases'];

    /** The pullback settings: stored config per setting, its nested tables merged per entry ('styles' whole). */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('pullback');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (self::MERGED_TABLES as $t) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
        }
        $cfg['aftermath']['fearful'] = array_replace($defaults['aftermath']['fearful'], is_array($stored['aftermath']['fearful'] ?? null) ? $stored['aftermath']['fearful'] : []);
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function hour(): float
    {
        return RelationshipDynamics::GAMETS_PER_DAY / 24.0;
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    // =====================================================================
    // LET IN (pure)
    // =====================================================================

    /** sqrt(comfort x trust), dimension points 0..100. No passion term, any bond type. */
    public static function letInOf(float $comfort, float $trust): float
    {
        return sqrt(max(0.0, $comfort) * max(0.0, $trust));
    }

    /** How far she has let the player in (points 0..100), from the raw comfort and trust (like every tension check). */
    public static function letIn(array $dynamics): float
    {
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $v = fn(string $d) => is_numeric($dims[$d]['x'] ?? null) ? floatval($dims[$d]['x']) : 50.0;
        return round(self::letInOf($v('comfort'), $v('trust')), 4);
    }

    /** Has she not let the player in yet (let-in below let_in.low)? */
    public static function notLetInYet(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return self::letIn($dynamics) < floatval($cfg['let_in']['low']);
    }

    // =====================================================================
    // THE FEARFUL MORNING AFTER (pure)
    // =====================================================================

    /**
     * How fearful of closeness the NPC is (0..1), for the push after intimacy: the attachment corners blended at the NPC's
     * two axes (RelationshipDynamics::attachmentBlend over aftermath.fearful): the fearful corner (anxious and avoidant at
     * once) in full, the avoidant corner a part of it, the anxious a little, the secure the least; continuous, never a label.
     */
    public static function fearfulness(array $dynamics, ?array $cfg = null): float
    {
        $a = (array) (($cfg ?? self::config())['aftermath']);
        return self::clamp01(RelationshipDynamics::attachmentBlend($dynamics, (array) $a['fearful'], 0.0));
    }

    /**
     * The aftermath input at $now (0..1, game calendar): the stored size, held hold_game_hours after the encounter's last
     * scene request, then falling linearly to nothing over fade_game_hours. $at: the stored ['size', 'last'] (the state's
     * 'aftermath'); null / past the fade: 0. Pure.
     */
    public static function aftermathInput(?array $at, float $now, ?array $cfg = null): float
    {
        $a = (array) (($cfg ?? self::config())['aftermath']);
        if (empty($a['enabled']) || !is_array($at) || $now <= 0) return 0.0;
        $size = self::clamp01(floatval($at['size'] ?? 0));
        $hours = max(0.0, ($now - floatval($at['last'] ?? 0)) / self::hour());
        $over = $hours - max(0.0, floatval($a['hold_game_hours']));
        if ($over <= 0.0) return $size;
        $fade = max(1e-6, floatval($a['fade_game_hours']));
        return $over >= $fade ? 0.0 : $size * (1.0 - $over / $fade);
    }

    // =====================================================================
    // THE PRESSURE (pure)
    // =====================================================================

    /**
     * Mood sensitivity: the multiplier on the weather input, from the maturity weight w (0 immature
     * .. 1 mature, RelDynConcern::expression): linear between mood_gain.immature and mood_gain.mature,
     * never below a tenth (a mature NPC is damped, not deaf).
     */
    public static function moodGain(float $w, ?array $cfg = null): float
    {
        $g = (array) (($cfg ?? self::config())['mood_gain']);
        $w = self::clamp01($w);
        return max(0.1, floatval($g['immature']) + (floatval($g['mature']) - floatval($g['immature'])) * $w);
    }

    /**
     * The thresholds ['on', 'off'] (pressure 0..1) for a let-in (points) and a guard trait (0..1): guard
     * lowers them, a deeper let-in raises them; off sits 'gap' under on.
     */
    public static function thresholds(float $letIn, float $guard, ?array $cfg = null): array
    {
        $t = (array) (($cfg ?? self::config())['threshold']);
        $on = floatval($t['on']) - floatval($t['guard_shift']) * (self::clamp01($guard) - 0.5)
            + floatval($t['let_in_shift']) * (max(0.0, min(100.0, $letIn)) - 50.0) / 50.0;
        $on = max(0.2, min(0.95, $on));
        $off = max(floatval($t['off_min']), $on - floatval($t['gap']));
        return ['on' => round($on, 4), 'off' => round(min($off, $on - 0.01), 4)];
    }

    /**
     * The inputs ('weather', 'deficit', 'grievance', 'aftermath', each 0..1, and 'gravity' the part of weather
     * that is the held pull) at $now from the NPC's own state. Pure.
     */
    public static function inputs(array $dynamics, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $weatherOn = !empty(RelationshipDynamics::configValue('internal_weather_enabled'));
        $name = $weatherOn ? (string) ($dynamics['_internal_weather'] ?? 'clear') : 'clear';
        $push = self::clamp01(floatval(((array) $cfg['weather_push'])[$name] ?? 0.0));
        $held = floatval($dynamics['_weather_gravity']['offsets']['valence'] ?? 0.0);
        $gravity = $weatherOn ? self::clamp01(-$held / max(0.001, floatval($cfg['gravity']['valence_full']))) : 0.0;
        $share = self::clamp01(floatval($cfg['gravity']['share']));
        $weather = (1.0 - $share) * $push + $share * $gravity;

        $deficit = $now > 0 ? RelDynFulfillment::weatherDeprivation($dynamics, $now) : null;
        if ($deficit === null) $deficit = floatval($dynamics['_weather_state']['relationship_deprivation'] ?? 0.0);
        $deficit = self::clamp01($deficit);

        $g = (array) $cfg['grievance'];
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $res = floatval($dims['resentment']['x'] ?? 0.0);
        $from = floatval($g['resentment_from']);
        $grievance = self::clamp01(($res - $from) / max(1.0, floatval($g['resentment_full']) - $from));
        if (!empty($dynamics['in_conflict'])) {
            $open = floatval($g['conflict']) * (1.0 - self::clamp01(intval($dynamics['conflict_positive_count'] ?? 0) * floatval($g['repair_relief'])));
            $grievance = max($grievance, self::clamp01($open));
        }
        $aftermath = self::aftermathInput(is_array($dynamics[self::KEY]['aftermath'] ?? null) ? $dynamics[self::KEY]['aftermath'] : null, $now, $cfg);
        return ['weather' => round($weather, 4), 'gravity' => round($gravity, 4), 'deficit' => round($deficit, 4), 'grievance' => round($grievance, 4),
                'aftermath' => round($aftermath, 4)];
    }

    /** The pressure the inputs pull toward (0..1): the weighted sum, the weather x the mood gain. */
    public static function target(array $inputs, float $moodGain, ?array $cfg = null): float
    {
        $w = (array) (($cfg ?? self::config())['weights']);
        return self::clamp01(floatval($w['weather']) * $moodGain * floatval($inputs['weather'] ?? 0)
            + floatval($w['deficit']) * floatval($inputs['deficit'] ?? 0)
            + floatval($w['grievance']) * floatval($inputs['grievance'] ?? 0)
            + floatval($w['aftermath'] ?? 0) * floatval($inputs['aftermath'] ?? 0));
    }

    /** The pressure after $hours game hours toward $target: up at rise_per_game_hour, down at fall_per_game_hour. */
    public static function approach(float $pressure, float $target, float $hours, ?array $cfg = null): float
    {
        $r = (array) (($cfg ?? self::config())['rates']);
        $hours = max(0.0, min($hours, floatval($r['max_step_game_hours'])));
        $rate = $target > $pressure ? floatval($r['rise_per_game_hour']) : floatval($r['fall_per_game_hour']);
        $share = 1.0 - pow(1.0 - self::clamp01($rate), $hours);
        return self::clamp01($pressure + ($target - $pressure) * $share);
    }

    /** Hysteresis: in once at or above 'on' (and she has let the player in), out once at or below 'off'. */
    public static function decide(bool $active, float $pressure, array $thresholds, float $letIn, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        if ($active) return $pressure > floatval($thresholds['off']);
        return $pressure >= floatval($thresholds['on']) && $letIn >= floatval($cfg['let_in']['low']);
    }

    // =====================================================================
    // STATE
    // =====================================================================

    /**
     * $dynamics['_pullback']: v, pressure (0..1), active, since_gamets (raw gamets, while active),
     * ended_gamets, gamets (the last advance), voiced (she has said it to the player's face), episodes,
     * ease_until_gamets, ease, met (exchanges that met her this episode), say (one-shots to say: key,
     * band, style, attachment), last (the numbers of the last advance, for Jev).
     */
    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::KEY] ?? null) || intval($dynamics[self::KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::KEY] = ['v' => self::VERSION, 'pressure' => 0.0, 'active' => false, 'say' => [], 'episodes' => 0];
        }
        return $dynamics[self::KEY];
    }

    /** Is she pulled back now (the stored state; off with the switch)? */
    public static function active(array $dynamics): bool
    {
        return !empty($dynamics[self::KEY]['active']) && self::enabled();
    }

    public static function pressure(array $dynamics): float
    {
        return floatval($dynamics[self::KEY]['pressure'] ?? 0.0);
    }

    // =====================================================================
    // WHO SHE IS (the one expression mechanism)
    // =====================================================================

    /**
     * How she expresses it: RelDynConcern::expression (maturity path, band and the trait-scored style,
     * from this lane's own style table) and the attachment style that colours it ('anxious' | 'avoidant' |
     * 'toxic' (fearful) | null for secure): the region her axes sit in (RelationshipDynamics::getAttachmentStyle).
     *
     * @return array{m: float, w: float, band: string, path: string, style: string, attachment: ?string}
     */
    public static function expression(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $traits = RelDynConcern::traitsOf($dynamics);
        $e = RelDynConcern::expression($dynamics, $traits, array_replace(RelDynConcern::config(), ['styles' => (array) $cfg['styles']]));
        $style = RelationshipDynamics::getAttachmentStyle($dynamics);
        $e['attachment'] = in_array($style, ['anxious', 'avoidant', 'toxic'], true) ? $style : null;
        return $e;
    }

    /** What she names as missing, as a noun phrase: her least covered needs, else the fallback. */
    private static function needsText(array $dynamics, float $now, array $cfg): string
    {
        $state = RelDynFulfillment::pairState($dynamics);
        $phrases = is_array($state) && $now > 0 ? RelDynFulfillment::unmetPhrases($state, $now, 2) : [];
        if ($phrases === []) return (string) $cfg['needs_fallback'];
        $last = array_pop($phrases);
        return $phrases === [] ? $last : implode(', ', $phrases) . ' and ' . $last;
    }

    /**
     * The text key of a line ('standing' | 'enter') by the NPC's expression: mature, mixed, or the immature style; for the
     * fearful morning after ($cause 'aftermath') the line of that cause when the config has one.
     */
    private static function expressionKey(string $kind, array $e, ?string $cause = null, ?array $cfg = null): string
    {
        $how = $e['band'] === 'mature' ? 'mature' : ($e['band'] === 'mixed' ? 'mixed' : $e['style']);
        if ($cause === 'aftermath' && isset((($cfg ?? self::config())['felt_text'])["{$kind}_aftermath_{$how}"])) return "{$kind}_aftermath_{$how}";
        return $kind . '_' . $how;
    }

    private static function colour(array $e, array $vars, array $cfg): string
    {
        if ($e['attachment'] === null) return '';
        $p = (array) (((array) $cfg['attachment_phrases'])[$e['attachment']] ?? []);
        return strtr((string) ($p[$e['band'] === 'mature' ? 'mature' : 'raw'] ?? ''), $vars);
    }

    private static function vars(string $npc, string $player, array $e, string $needs, array $cfg): array
    {
        $how = (string) (((array) $cfg['style_phrases'])[$e['style']]['how'] ?? '');
        return ['{NAME}' => $npc, '{PLAYER}' => $player, '{NEEDS}' => $needs, '{HOW}' => $how];
    }

    /**
     * The standing line for knowledge_of_player while she is pulled back and has let the player in:
     * one sentence by her expression, coloured by her attachment corner. Feelings, no digits.
     */
    public static function standingText(string $npc, string $player, array $dynamics, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $e = self::expression($dynamics, $cfg);
        $needs = self::needsText($dynamics, floatval($dynamics[self::KEY]['gamets'] ?? 0), $cfg);
        $vars = self::vars($npc, $player, $e, $needs, $cfg);
        $text = rtrim(strtr((string) $cfg['felt_text'][self::expressionKey('standing', $e, self::cause($dynamics), $cfg)], $vars), '.');
        return $text . self::colour($e, $vars, $cfg) . '.';
    }

    /** Does the standing line name what is missing (so the generic unmet line beside it would only repeat)? */
    public static function namesNeeds(array $dynamics, ?array $cfg = null): bool
    {
        if (self::cause($dynamics) === 'aftermath') return false;   // the morning after names the closeness, not what is missing
        $e = self::expression($dynamics, $cfg);
        return !($e['band'] === 'immature' && $e['style'] === 'sulking');
    }

    /** What the current pull-back is mostly about: 'aftermath' (the fearful morning after) or null (the weather, the needs, a grievance). */
    public static function cause(array $dynamics): ?string
    {
        $c = $dynamics[self::KEY]['cause'] ?? null;
        return !empty($dynamics[self::KEY]['active']) && is_string($c) && $c !== '' ? $c : null;
    }

    // =====================================================================
    // ADVANCE (the prerequest hook)
    // =====================================================================

    /**
     * Advance the state to $now: the inputs, the target, the pressure on the game calendar, then the
     * hysteresis. Enters (one-shot queued) or leaves (one-shot queued). Without a game clock, with the
     * switch off, nothing happens (a state kept stays as it is). Returns ['entered' => bool, 'left' => bool,
     * 'pressure' => float, 'active' => bool].
     */
    public static function advance(string $npcName, array &$dynamics, float $now): array
    {
        $out = ['entered' => false, 'left' => false, 'pressure' => self::pressure($dynamics), 'active' => self::active($dynamics)];
        if (!self::enabled() || $now <= 0) return $out;
        $cfg = self::config();
        $state = &self::state($dynamics);
        $traits = RelDynConcern::traitsOf($dynamics);
        $e = self::expression($dynamics, $cfg);
        $letIn = self::letIn($dynamics);
        $inputs = self::inputs($dynamics, $now, $cfg);
        $target = self::target($inputs, self::moodGain($e['w'], $cfg), $cfg);
        $met = (array) $cfg['met'];
        if ($now < floatval($state['ease_until_gamets'] ?? 0)) $target *= 1.0 - self::clamp01(floatval($state['ease'] ?? $met['ease']));

        $last = floatval($state['gamets'] ?? 0);
        $hours = $last > 0 ? max(0.0, ($now - $last) / self::hour()) : 0.0;
        // first sight of the NPC: the state of the world is what it is
        $pressure = $last > 0 ? self::approach(floatval($state['pressure']), $target, $hours, $cfg) : $target;
        $thr = self::thresholds($letIn, floatval($traits['G'] ?? 0.5), $cfg);
        $was = !empty($state['active']);
        $is = self::decide($was, $pressure, $thr, $letIn, $cfg);

        $state['pressure'] = round($pressure, 4);
        $state['gamets'] = $now;
        $state['last'] = ['target' => round($target, 4), 'inputs' => $inputs, 'let_in' => round($letIn, 2), 'on' => $thr['on'], 'off' => $thr['off'],
            'mood_gain' => round(self::moodGain($e['w'], $cfg), 4)];
        if ($is && !$was) {
            $state['active'] = true;
            $state['since_gamets'] = $now;
            $state['voiced'] = false;
            $state['met'] = 0;
            $state['episodes'] = intval($state['episodes'] ?? 0) + 1;
            // the morning after is the cause when it is the largest of what presses (weights x inputs, the weather x the mood gain)
            $w = (array) $cfg['weights'];
            $parts = ['weather' => floatval($w['weather']) * self::moodGain($e['w'], $cfg) * floatval($inputs['weather']),
                'deficit' => floatval($w['deficit']) * floatval($inputs['deficit']), 'grievance' => floatval($w['grievance']) * floatval($inputs['grievance']),
                'aftermath' => floatval($w['aftermath'] ?? 0) * floatval($inputs['aftermath'])];
            arsort($parts);
            $cause = array_key_first($parts) === 'aftermath' && $parts['aftermath'] > 0.0 ? 'aftermath' : null;
            $state['cause'] = $cause;
            self::queue($state, ['key' => 'enter', 'band' => $e['band'], 'style' => $e['style'], 'attachment' => $e['attachment'], 'cause' => $cause]);
            $out['entered'] = true;
            RelationshipDynamics::log("[PULLBACK] {$npcName}: pulls back (pressure " . round($pressure, 3) . " >= {$thr['on']}, let-in " . round($letIn, 1)
                . ", {$e['band']}/{$e['style']}" . ($e['attachment'] !== null ? "/{$e['attachment']}" : '') . ')');
        } elseif ($was && !$is) {
            $state['active'] = false;
            $state['ended_gamets'] = $now;
            unset($state['ease_until_gamets'], $state['ease'], $state['cause']);
            // she says the reopening only for a pull-back she showed
            self::queue($state, ['key' => 'reopen', 'band' => $e['band'], 'style' => $e['style'], 'attachment' => $e['attachment']]);
            $out['left'] = true;
            RelationshipDynamics::log("[PULLBACK] {$npcName}: opens up again (pressure " . round($pressure, 3) . " <= {$thr['off']}) after "
                . round(($now - floatval($state['since_gamets'] ?? $now)) / self::hour(), 1) . ' game hours');
        }
        $out['pressure'] = $state['pressure'];
        $out['active'] = !empty($state['active']);
        unset($state);
        return $out;
    }

    /**
     * Queue a one-shot. An unsaid one of the other kind is cancelled: a pull-back she never showed has no
     * reopening to say, and a reopening not yet said is overtaken by pulling back again. At most 3.
     */
    private static function queue(array &$state, array $say): void
    {
        $pending = array_values((array) ($state['say'] ?? []));
        $same = array_values(array_filter($pending, fn($s) => ($s['key'] ?? null) === ($say['key'] ?? null)));
        $cancelled = count($same) !== count($pending);
        $state['say'] = $same;
        if ($say['key'] === 'reopen' && $cancelled) return;
        $state['say'][] = $say;
        $state['say'] = array_slice($state['say'], -3);
    }

    // =====================================================================
    // THE FEARFUL MORNING AFTER (the post-intimacy hook)
    // =====================================================================

    /**
     * A scene request that reports intimacy with the player (RelDynPostIntimacy::onIntimateRequest, a new encounter or the
     * same one going on) at $now (raw gamets): the NPC's fear of the closeness (fearfulness) sizes the push the aftermath input
     * will carry, held from this request (an encounter goes on: the morning counts from its last request), never lower than what
     * is left of an earlier one. Returns the size stored (0 for one who does not fear it, or with the switch off).
     */
    public static function onIntimacy(string $npcName, array &$dynamics, float $now): float
    {
        $cfg = self::config();
        $a = (array) $cfg['aftermath'];
        if (!self::enabled() || empty($a['enabled']) || $now <= 0) return 0.0;
        $size = self::clamp01(floatval($a['push'])) * self::fearfulness($dynamics, $cfg);
        $left = self::aftermathInput(is_array($dynamics[self::KEY]['aftermath'] ?? null) ? $dynamics[self::KEY]['aftermath'] : null, $now, $cfg);
        $state = &self::state($dynamics);
        $state['aftermath'] = ['size' => round(max($size, $left), 4), 'last' => $now];
        $out = $state['aftermath']['size'];
        unset($state);
        RelationshipDynamics::log("[PULLBACK] {$npcName}: after intimacy the closeness weighs " . round($out, 3) . ' (fearfulness ' . round(self::fearfulness($dynamics, $cfg), 3) . ')');
        return $out;
    }

    // =====================================================================
    // BEING MET (the eval consumer)
    // =====================================================================

    /**
     * One applied eval item ($n from normalizeEvalContractItem): a positive exchange that addresses it while she is
     * pulled back (positive_interaction, goal_addressed, or a reassurance / quality-time tag) takes pressure off,
     * more for one who is mature and has voiced it, and eases the target for a while. Returns the relief taken (0..1).
     */
    public static function onEvalItem(string $npcName, array $n, array &$dynamics, float $at): float
    {
        if (!self::enabled()) return 0.0;
        $active = !empty($dynamics[self::KEY]['active']);
        $morning = is_array($dynamics[self::KEY]['aftermath'] ?? null);
        if (!$active && !$morning) return 0.0;
        $cfg = self::config();
        $met = (array) $cfg['met'];
        $positive = false;
        foreach ((array) $met['flags'] as $flag) {
            if (!empty($n[$flag])) $positive = true;
        }
        foreach ((array) ($n['tags'] ?? []) as $tag) {
            if (in_array($tag, (array) $met['tags'], true)) $positive = true;
        }
        if (!$positive) return 0.0;
        // a kind word the morning after takes some of the closeness's weight off, pulled back or not
        if ($morning) {
            $relief = self::clamp01(floatval(((array) $cfg['aftermath'])['met_relief']));
            $size = floatval($dynamics[self::KEY]['aftermath']['size'] ?? 0);
            $dynamics[self::KEY]['aftermath']['size'] = round($size * (1.0 - $relief), 4);
        }
        if (!$active) return 0.0;
        $state = &self::state($dynamics);
        $w = self::expression($dynamics, $cfg)['w'];
        $floor = floatval($met['significance_floor']);
        $sig = $floor + (1.0 - $floor) * self::clamp01(floatval($n['significance'] ?? 0.3));
        $mature = floatval($met['immature_mult']) + (1.0 - floatval($met['immature_mult'])) * $w;
        $relief = floatval($met['relief']) * $sig * $mature * (!empty($state['voiced']) ? 1.0 : floatval($met['unvoiced_mult']));
        $state['pressure'] = round(max(0.0, floatval($state['pressure']) - $relief), 4);
        $state['met'] = intval($state['met'] ?? 0) + 1;
        $state['ease'] = round(floatval($met['ease']) * $mature, 4);
        $state['ease_until_gamets'] = max($at, floatval($state['gamets'] ?? 0)) + floatval($met['ease_game_hours']) * self::hour();
        RelationshipDynamics::log("[PULLBACK] {$npcName}: met (relief " . round($relief, 3) . ", pressure now {$state['pressure']}" . (!empty($state['voiced']) ? ', voiced' : '') . ')');
        unset($state);
        return $relief;
    }

    // =====================================================================
    // FELT TEXT
    // =====================================================================

    /**
     * This turn's one-shots for RelDynFelt, consumed only when the player is speaking to this NPC:
     * the entering (said to the player's face, by her expression; a mature NPC's marks it voiced) and the
     * reopening. Lines: ['key', 'lane' => 'turn', 'salience', 'must', 'intense', 'text'].
     *
     * @return array ['lines' => list, 'changed' => bool]
     */
    public static function takeFeltLines(array &$dynamics, string $npcName, string $playerName, float $now, bool $playerAddressed = true): array
    {
        $out = ['lines' => [], 'changed' => false];
        if (!self::enabled() || !is_array($dynamics[self::KEY] ?? null) || !$playerAddressed || ($dynamics[self::KEY]['say'] ?? []) === []) return $out;
        $cfg = self::config();
        $t = (array) $cfg['felt_text'];
        $sal = (array) $cfg['salience'];
        $state = &self::state($dynamics);
        $needs = self::needsText($dynamics, $now, $cfg);
        foreach ((array) $state['say'] as $s) {
            $key = (string) ($s['key'] ?? '');
            $e = ['band' => (string) ($s['band'] ?? 'mixed'), 'style' => (string) ($s['style'] ?? 'sulking'), 'attachment' => $s['attachment'] ?? null];
            $vars = self::vars($npcName, $playerName, $e, $needs, $cfg);
            if ($key === 'enter') {
                $textKey = self::expressionKey('enter', $e, is_string($s['cause'] ?? null) ? $s['cause'] : null, $cfg);
                // the one who meant to say it evenly and could not has still said it
                if ($e['band'] !== 'immature') $state['voiced'] = true;
            } elseif ($key === 'reopen') {
                $textKey = 'reopen_' . ($e['band'] === 'mature' ? 'mature' : 'immature');
            } else {
                continue;
            }
            if (!isset($t[$textKey])) {
                error_log("[RelDyn-PULLBACK] {$npcName}: no felt text '{$textKey}', dropped");
                continue;
            }
            $text = rtrim(strtr((string) $t[$textKey], $vars), '.');
            $out['lines'][] = ['key' => $key, 'lane' => 'turn', 'salience' => floatval($sal[$key] ?? 1.0), 'must' => true,
                'intense' => $key === 'enter' && $e['band'] === 'immature', 'text' => $text . ($key === 'enter' ? self::colour($e, $vars, $cfg) : '') . '.'];
        }
        $state['say'] = [];
        $out['changed'] = true;
        unset($state);
        return $out;
    }

    // =====================================================================
    // JEV (numbers are fine here, never for the LLM)
    // =====================================================================

    /**
     * Let-in and the pull-back state for Jev: let_in (points), the state's pressure, thresholds and inputs (0..1), whether it is
     * active, how long (game hours), whether the NPC voiced it, what it is about (cause), the morning after's size, the NPC's
     * expression and attachment corner.
     */
    public static function jev(array $dynamics, float $now): array
    {
        $cfg = self::config();
        $s = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $active = !empty($s['active']) && !empty($cfg['enabled']);
        $e = self::expression($dynamics, $cfg);
        $since = $active && $now > 0 && is_numeric($s['since_gamets'] ?? null) ? max(0.0, ($now - floatval($s['since_gamets'])) / self::hour()) : null;
        return [
            'enabled' => !empty($cfg['enabled']),
            'let_in' => round(self::letIn($dynamics), 2),
            'not_let_in_yet' => self::letIn($dynamics) < floatval($cfg['let_in']['low']),
            'active' => $active,
            'pressure' => round(floatval($s['pressure'] ?? 0.0), 3),
            'on' => isset($s['last']['on']) ? floatval($s['last']['on']) : null,
            'off' => isset($s['last']['off']) ? floatval($s['last']['off']) : null,
            'target' => isset($s['last']['target']) ? floatval($s['last']['target']) : null,
            'inputs' => (array) ($s['last']['inputs'] ?? []),
            'mood_gain' => isset($s['last']['mood_gain']) ? floatval($s['last']['mood_gain']) : round(self::moodGain($e['w'], $cfg), 3),
            'since_game_hours' => $since !== null ? round($since, 2) : null,
            'voiced' => $active && !empty($s['voiced']),
            'episodes' => intval($s['episodes'] ?? 0),
            'cause' => $active && is_string($s['cause'] ?? null) ? $s['cause'] : null,
            'aftermath' => ['fearfulness' => round(self::fearfulness($dynamics, $cfg), 3), 'size' => round(floatval($s['aftermath']['size'] ?? 0.0), 3),
                'input' => $now > 0 ? round(self::aftermathInput(is_array($s['aftermath'] ?? null) ? $s['aftermath'] : null, $now, $cfg), 3) : null],
            'band' => $e['band'], 'style' => $e['style'], 'attachment' => $e['attachment'],
        ];
    }
}
