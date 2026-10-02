<?php
/**
 * Relationship Dynamics — the body: bio-mimetic feedback (body language, voice emotion) and the physical blush.
 *
 * Ken 2026-10-01 §24: "Bio-mimetic feedback (voice emotion, body language, approach / turn-away): a good use for Jev."
 * and the OBlush correction: "seeing an NPC blush when you flirt matters more than a HUD element". Roadmap
 * bio-mimetic-feedback and oblush-physical-blush (MDD §7 bio-mimetic and narrative feedback, pipeline Bio-Mimetic).
 *
 * WHAT THE BODY SAYS. Four body-language cues, each a 0..1 STRENGTH from the NPC's state and who the NPC is, never a
 * yes / no gate: nobody is fully immune to a feeling, so a guarded or shy NPC needs more, a bold one less, and
 * everyone gives way at the extreme.
 *   approach      effective passion past a threshold (60 for an average NPC; guard and shyness raise it, boldness
 *                 lowers it, always inside [floor, ceiling]); eased by a pull-back, a walkaway, an open conflict, jealousy
 *   turn_away     a walkaway in progress, a pull-back, the withdrawing end of the fear of losing the player; the
 *                 avoidant turn away more, the anxious less, neither never
 *   shy_glance    passion the NPC is shy about (RelDynAttraction::shyness), the flush a primary love-language match holds;
 *                 a bold NPC still steals a glance at overwhelming passion
 *   tense_stance  an open conflict, resentment, jealousy, the ick; the immature show it more, the mature less, never none
 * and the VOICE: an emotion for TTS from effective passion, arousal and valence, mapped onto CHIM's own mood list (the
 * NPC's emote_moods) and Cartesia's emotion tags, with a pace and an intensity. Core already carries a mood on every spoken
 * line (the LLM's mood field -> returnLines -> the TTS function), so the voice reaches it as felt steering for the model
 * to pick that mood from; an opt-in switch (voice.force_mood) sets core's FORCE_MOOD for the line instead.
 *
 * WHO CHOOSES. Without Jev the strongest cue speaks, as felt text only (default_pick). Jev (the tactical layer, which gets
 * the explicit numbers: RelDynJev 'body') picks which cue fires and calls pick(); that pick speaks instead of the default
 * for pick_ttl_play_seconds. The one cue with a core action behind it (approach -> ComeCloser, actions) comes back from
 * pick() as a ready command line for Jev to send through the channel its own actions use. Turning away, glancing and standing
 * tense have no animation channel in core: they are context text until the game side hooks them (see ANIMATION HOOKS).
 *
 * THE BLUSH. A passion moment (a flirt that lands, a love-language gesture: the spike behind the felt blush line) puts a
 * blush on the NPC's model through OBlush (Nexus 145083), whose OBlushHandle exposes enableBlush(Actor) / disableBlush(Actor,
 * node). The server sends one command through CHIM's own command channel (responselog 'command', the ExtCmd bridge:
 * ExtCmdRelDynBody_Blush@<seconds>@<token>), a small Papyrus bridge (AIAgent RelDynBody / RelDynBodyOBlush) calls OBlush on
 * the NPC and takes it off after <seconds>; ExtCmdRelDynBody_BlushOff takes it off at once (the safeguard below). The
 * duration scales with the moment (30 s at the faintest felt blush, 120 s at the strongest, more past it), x the love-language
 * match (primary 2, secondary 1.5: "a primary holds longer"), x blushiness (a shy or unsure NPC blushes longer and a poised one
 * shorter, never to nothing), clamped. A blush still on is extended only when the new moment would outlast it; a new one waits
 * cooldown_play_seconds after the last ended, so it never flickers. If the game never confirms the end (a reloaded save), the
 * next prerequest sends BlushOff once, idempotently.
 *
 * ANIMATION HOOKS (what a game-side hook would need): a Papyrus / SKSE command ExtCmdRelDynBody_Pose@<cue>@<seconds> the
 * bridge could map to an idle animation event (Debug.SendAnimationEvent) per cue, SetLookAt / ClearLookAt on the player for
 * shy_glance, and a facing change (SetAngle toward or away) for approach / turn_away; and a mood-to-TTS-emotion override that
 * survives the LLM's own mood (core's FORCE_MOOD is the only one today). None of those exist in CHIM 3.4.1.
 *
 * Clocks (feedback_timer_design): the blush's duration and cooldown, a pick's lifetime and the cue cooldowns run on the NPC's
 * filtered PLAY clock (_accumulated_play_gamets; 1 play second = GAMETS_PER_REAL_SECOND), never the wall clock. Units: strengths
 * 0..1, passion / arousal points 0..100, valence points -100..100, durations real play seconds.
 *
 * State: $dynamics['_body'] = [v, picked => [cue, source, play], last => cue => play, blush => [token, start_play, until_play,
 * seconds, delta, mult, off_sent], outbox => commands waiting for the channel].
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynBody
{
    const KEY = '_body';
    const VERSION = 1;
    /** Priority order for ties and for the default pick: what pushes the NPC away speaks before what draws. */
    const CUES = ['turn_away', 'tense_stance', 'approach', 'shy_glance'];
    /** The command names the Papyrus bridge (AIAgent script RelDynBody) answers; CHIM routes ExtCmd<Script>_<Action> to it. */
    const CMD_BLUSH = 'ExtCmdRelDynBody_Blush';
    const CMD_BLUSH_OFF = 'ExtCmdRelDynBody_BlushOff';
    /** Core's default mood list (lib/emote_moods.php getDefaultEmoteMoods), for when the NPC's own list is not at hand. */
    const FALLBACK_MOODS = ['sassy', 'assertive', 'sexy', 'smug', 'kindly', 'lovely', 'seductive', 'sarcastic', 'smirking', 'amused',
        'irritated', 'playful', 'neutral', 'teasing', 'desperate', 'scared', 'pleading', 'sad', 'happy', 'angry', 'drunk', 'shy', 'surprised'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config 'body'. A stored config replaces settings one by one; the tables merge per entry (config()). */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'cues' => [
                // A cue weaker than this is not offered (strength 0..1)
                'min_strength' => 0.15,
                // Without Jev the strongest cue speaks as felt text
                'default_pick' => true,
                // A pick (Jev's) speaks for this long (play seconds), while its cue is still offered
                'pick_ttl_play_seconds' => 180.0,
                // The same cue is not picked again before this (play seconds): no ComeCloser spam
                'cooldown_play_seconds' => ['approach' => 120.0, 'turn_away' => 90.0, 'shy_glance' => 45.0, 'tense_stance' => 90.0],
                // The core action a picked cue fires, where core has one; a cue with none is context text
                'actions' => ['approach' => 'ComeCloser'],
                'approach' => [
                    // Effective passion points an average NPC starts approaching at; guard / shyness move it, inside floor..ceiling
                    'passion_at' => 60.0, 'guard_shift' => 12.0, 'shy_shift' => 10.0, 'floor' => 40.0, 'ceiling' => 85.0,
                    // Passion points over the threshold to full strength
                    'span' => 25.0,
                    // Strength taken off by what pulls the NPC away (fraction 0..1 at full; never all of it for a bold pull)
                    'suppress' => ['pullback' => 0.9, 'walkaway' => 0.9, 'conflict' => 0.7, 'jealousy' => 0.5],
                ],
                'turn_away' => [
                    // Strength by walkaway state
                    'walkaway' => ['pending' => 0.5, 'active' => 1.0, 'boundary_test' => 0.8, 'recovery' => 0.4, 'permanent' => 1.0],
                    // Pulled back: at least this, rising with the pull-back's pressure (0..1)
                    'pullback_floor' => 0.6,
                    // The withdrawing end of the fear of losing the player: strength = this x the fear (0..1)
                    'withdraw' => 0.8,
                    // The avoidant turn away more, the anxious less: strength x (1 + this x (avoidance - anxiety)), bounded
                    'avoidance_gain' => 0.5,
                ],
                'shy_glance' => [
                    // Passion points where the glance begins and where it is full for a fully shy NPC
                    'passion_from' => 15.0, 'passion_full' => 45.0,
                    // Anyone may steal a glance at overwhelming passion: from this many points, up to this strength
                    'overwhelm_from' => 70.0, 'overwhelm_strength' => 0.35,
                    // Added while a primary love-language match still holds the flush
                    'blush_hold' => 0.3,
                    'suppress' => ['pullback' => 0.8, 'walkaway' => 0.8, 'conflict' => 0.8],
                ],
                'tense_stance' => [
                    // Base strength of an open conflict (rising with resentment to 1)
                    'conflict' => 0.6,
                    // Resentment points where a grievance shows in the body, and over how many points it reaches full
                    'resentment_from' => 35.0, 'resentment_span' => 45.0,
                    // Jealousy points where it shows, over how many points it reaches full, and its weight
                    'jealousy_from' => 45.0, 'jealousy_span' => 40.0, 'jealousy_weight' => 0.7,
                    // The ick, while it lasts
                    'ick' => 0.7,
                    // Maturity (points, 50 = neutral): the immature show tension more, the mature less; strength x (1 + this x (50 - maturity) / 50)
                    'maturity_gain' => 0.5,
                ],
            ],
            'voice' => [
                'enabled' => true,
                // Opt in: set core's FORCE_MOOD to the voice's mood for the line (otherwise the voice is felt steering and the LLM picks the mood)
                'force_mood' => false,
                // A voice weaker than this (0..1) is not worth a cue: a flat, ordinary voice
                'min_intensity' => 0.25,
                // Effective passion points for the seductive / lovely / kindly voice
                'passion' => ['burning' => 70.0, 'warm' => 45.0, 'soft' => 25.0],
                // Valence points (-100..100): sour = irritated or sad, hostile = angry; bright = a happy voice
                'valence' => ['hostile' => -50.0, 'sour' => -25.0, 'bright' => 35.0],
                // Arousal points: high = fast and sharp, low = slow and flat
                'arousal' => ['high' => 60.0, 'low' => 25.0],
                // Shyness (0..1) from which a drawn voice is shy rather than low and slow; self-confidence points from which a warm, lively voice teases
                'shy_at' => 0.6, 'confident_at' => 60.0,
                // Pace by arousal points: slow under the first, fast over the second (Cartesia's speed words)
                'pace' => ['slow_below' => 25.0, 'fast_above' => 70.0],
                // Voice family => the NPC's moods in the order they are tried (the first the NPC's emote_moods holds wins)
                'moods' => [
                    'seductive' => ['seductive', 'sexy', 'lovely'], 'shy' => ['shy', 'lovely', 'kindly'],
                    'lovely' => ['lovely', 'kindly', 'happy'], 'teasing' => ['teasing', 'playful', 'smirking'],
                    'kindly' => ['kindly', 'lovely', 'neutral'], 'happy' => ['happy', 'amused', 'kindly'],
                    'angry' => ['angry', 'irritated'], 'irritated' => ['irritated', 'angry'], 'sad' => ['sad'],
                ],
                // Mood => Cartesia emotion tag (the triple-stack's first layer; mirrors tts/tts-cartesia.php's vocabulary)
                'cartesia' => [
                    'seductive' => 'flirtatious', 'sexy' => 'flirtatious', 'shy' => 'hesitant', 'lovely' => 'affectionate',
                    'kindly' => 'content', 'teasing' => 'joking/comedic', 'playful' => 'joking/comedic', 'smirking' => 'joking/comedic',
                    'happy' => 'happy', 'amused' => 'happy', 'angry' => 'angry', 'irritated' => 'frustrated', 'sad' => 'sad',
                    'neutral' => 'neutral',
                ],
                // Intensity words (core's emotion_intensity) by magnitude 0..1: moderate from the first, strong from the second
                'intensity_at' => ['moderate' => 0.4, 'strong' => 0.7],
            ],
            'blush' => [
                'enabled' => true,
                // Seconds of a blush at the faintest felt blush (felt_steering.blush.faint_at), at the strongest (strong_at) and at a huge
                // moment (huge_at passion points, where it stops growing); linear between the three
                'seconds_faint' => 30.0, 'seconds_strong' => 120.0, 'seconds_huge' => 180.0, 'huge_at' => 20.0,
                // A blush is never shorter or longer than this (seconds), whatever the match and the NPC multiply it to
                'min_seconds' => 10.0, 'max_seconds' => 300.0,
                // A new blush waits this long (play seconds) after the last one ended: no flicker
                'cooldown_play_seconds' => 20.0,
                // A blush still on is extended only when the new moment would outlast it by this many seconds
                'extend_margin_seconds' => 10.0,
                // If the game has not confirmed the end this long after it was due, the next prerequest sends BlushOff once (play seconds)
                'safeguard_grace_play_seconds' => 15.0,
                // Blushiness = 1 + shyness x this + (50 - self-confidence) / 50 x that, kept inside [min, max] (never 0: nobody is immune)
                'blushiness' => ['shyness' => 0.8, 'confidence' => 0.3, 'min' => 0.6, 'max' => 1.6],
            ],
            'text' => [
                // The shipped wording names the NPC and carries no pronoun for the NPC ("they" in a felt block is the player); the
                // pronoun vars are there for whoever edits it. 'platonic_*': an NPC who is not drawn that way, or whose passion is emotional.
                'approach' => [
                    'soft' => "{NAME} edges a little closer to {PLAYER} than the moment needs, shortening the distance a step at a time",
                    'strong' => "{NAME} keeps closing the distance to {PLAYER}, unhurried, until near enough to touch, and does not pull away",
                    'platonic_soft' => "{NAME} lingers near {PLAYER}, easy in their company",
                    'platonic_strong' => "{NAME} seeks {PLAYER} out and stays near, glad of their company",
                ],
                'turn_away' => [
                    'soft' => "{NAME} angles away from {PLAYER}, answers sideways, eyes on anything else",
                    'strong' => "{NAME} turns a shoulder to {PLAYER}, then a back, and keeps the way out in view",
                ],
                'shy_glance' => [
                    'soft' => "{NAME} glances at {PLAYER} and away again before it can be noticed",
                    'strong' => "{NAME} steals looks at {PLAYER} and drops the eyes the moment they are caught, hands busy with nothing, words coming out smaller than meant",
                ],
                'tense_stance' => [
                    'soft' => "{NAME} stays a little too still, jaw set, weight braced",
                    'strong' => "{NAME} stands rigid and braced, jaw locked, hands closed, every muscle ready for a fight that has not started",
                ],
                'voice' => [
                    'seductive' => "{NAME}'s voice has dropped low and slow, breath audible between words",
                    'shy' => "{NAME}'s voice has gone soft and thin, trailing off at the ends of sentences",
                    'lovely' => "{NAME}'s voice has warmed, soft and unhurried",
                    'teasing' => "{NAME}'s voice is light and playful, a smile in every line",
                    'kindly' => "{NAME}'s voice is gentle and even",
                    'happy' => "{NAME}'s voice is bright and quick",
                    'angry' => "{NAME}'s voice is hard and clipped, each word set down like a blow",
                    'irritated' => "{NAME}'s voice has an edge, short and impatient",
                    'sad' => "{NAME}'s voice is flat and quiet, slow, without lift",
                ],
            ],
            // Felt salience (0..1): base per kind; the cue adds up to 'cue_gain' x its strength
            // (secondary to the NPC's own state: a crowded tier drops these first)
            'salience' => ['cue' => 0.05, 'cue_gain' => 0.2, 'voice' => 0.03, 'voice_gain' => 0.07],
            // The cue text reads as the strong variant from this strength
            'strong_at' => 0.55,
        ];
    }

    /** The section as RelDyn reads it: the stored row laid over the defaults, tables merged per entry. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('body');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['cues', 'voice', 'blush', 'text'] as $table) {
            $cfg[$table] = is_array($stored[$table] ?? null) ? self::overlay($defaults[$table], $stored[$table]) : $defaults[$table];
        }
        return $cfg;
    }

    /** $stored laid over $defaults entry by entry, tables inside tables too; a list (or a value) in $stored replaces the default whole. */
    private static function overlay(array $defaults, array $stored): array
    {
        foreach ($stored as $k => $v) {
            $d = $defaults[$k] ?? null;
            $defaults[$k] = (is_array($v) && is_array($d) && !array_is_list($v) && !array_is_list($d)) ? self::overlay($d, $v) : $v;
        }
        return $defaults;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function clamp(float $v, float $lo = 0.0, float $hi = 1.0): float
    {
        return max($lo, min($hi, $v));
    }

    // =====================================================================
    // WHAT THE NPC FEELS, AS THE BODY READS IT
    // =====================================================================

    /**
     * The kind of pull, as the felt compose reads it: platonic (not attracted that way, a hard zero, or a deliberate step back out of a
     * romance: whatever passion there is reads as affection, no desire) and emotional (an asexual NPC: longing without the physical).
     *
     * @return array{platonic: bool, emotional: bool}
     */
    public static function pull(array $dynamics): array
    {
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $romantic = in_array((string) ($dynamics['_core_rel_type'] ?? ''), (array) RelDynFelt::config()['romantic_types'], true);
        $platonic = !empty($att['enabled']) && (!empty($att['hard_zero']) || (($att['attracted'] ?? true) === false && !$romantic));
        $platonic = $platonic || RelDynFulfillment::romanceSteppedBack($dynamics) !== null;
        return ['platonic' => $platonic, 'emotional' => !$platonic && ($att['passion_channel'] ?? null) === 'emotional'];
    }

    /** The inputs the cues and the voice read, each as points / 0..1 as documented; pure over the NPC's state. */
    public static function inputs(array $dynamics): array
    {
        $pull = self::pull($dynamics);
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $x = fn(string $d, float $default): float => is_numeric($dims[$d]['x'] ?? null) ? floatval($dims[$d]['x']) : $default;
        $vec = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        $withdraw = 0.0;
        if (RelDynKeeping::enabled() && RelDynKeeping::response($dynamics)['kind'] === 'withdraw') $withdraw = RelDynKeeping::fear($dynamics);
        $ick = !empty($dynamics['_ick_tracker']['ick_active']) && !empty(RelationshipDynamics::getConfig()['ick_system_enabled'] ?? true);
        return [
            'passion' => round(RelationshipDynamics::getEffectivePassion($dynamics), 2),
            'shyness' => RelDynAttraction::shyness($dynamics),
            'guard' => is_array($vec) ? self::clamp(floatval($vec['G'] ?? 0.5)) : 0.5,
            'avoidance' => floatval($axes['avoidance'] ?? 0.0),
            'anxiety' => floatval($axes['anxiety'] ?? 0.0),
            'pullback' => RelDynPullback::active($dynamics) ? self::clamp(RelDynPullback::pressure($dynamics)) : 0.0,
            'walkaway' => (string) ($dynamics['_walkaway_state'] ?? 'normal'),
            'conflict' => !empty($dynamics['in_conflict']),
            'resentment' => $x('resentment', 0.0),
            'jealousy' => floatval($dynamics['jealousy_anger'] ?? 0),
            'ick' => $ick,
            'maturity' => $x('maturity', 50.0),
            'self_confidence' => $x('self_confidence', 50.0),
            'withdraw' => round($withdraw, 3),
            'arousal' => $x('arousal', 10.0),
            'valence' => $x('valence', 0.0),
            'flush_held' => intval($dynamics[RelDynFelt::BLUSH_HOLD_KEY] ?? 0) > 0,
            'platonic' => $pull['platonic'],
            'emotional' => $pull['emotional'],
        ];
    }

    /** How far the walkaway state pulls the NPC from the player (0..1 by turn_away.walkaway, 0 when normal). */
    private static function walkawayPull(array $in, array $cfg): float
    {
        return self::clamp(floatval(((array) $cfg['cues']['turn_away']['walkaway'])[$in['walkaway']] ?? 0.0));
    }

    /** The approach threshold in passion points for this NPC: boldness lowers it, guard and shyness raise it, always inside floor..ceiling. */
    public static function approachThreshold(array $in, ?array $cfg = null): float
    {
        $c = (array) (($cfg ?? self::config())['cues']['approach']);
        $t = floatval($c['passion_at']) + floatval($c['guard_shift']) * ($in['guard'] - 0.5) * 2.0 + floatval($c['shy_shift']) * $in['shyness'];
        return self::clamp($t, floatval($c['floor']), floatval($c['ceiling']));
    }

    /**
     * Every cue's strength 0..1 from the inputs. Pure: no clock, no database.
     *
     * @return array<string, float>
     */
    public static function strengths(array $in, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $cc = (array) $cfg['cues'];
        $walk = self::walkawayPull($in, $cfg);

        // approach: passion past the NPC's own threshold, eased by what pulls the NPC away
        $a = (array) $cc['approach'];
        $over = $in['passion'] - self::approachThreshold($in, $cfg);
        $approach = $over > 0 ? self::clamp($over / max(1.0, floatval($a['span']))) : 0.0;
        $sup = (array) $a['suppress'];
        $jealous = self::clamp(($in['jealousy'] - floatval($cc['tense_stance']['jealousy_from'])) / max(1.0, floatval($cc['tense_stance']['jealousy_span'])));
        foreach ([
            self::clamp(floatval($sup['pullback']) * $in['pullback']), self::clamp(floatval($sup['walkaway']) * $walk),
            $in['conflict'] ? floatval($sup['conflict']) : 0.0, self::clamp(floatval($sup['jealousy']) * $jealous),
        ] as $eased) {
            $approach *= (1.0 - self::clamp($eased));
        }

        // turn_away: a walkaway, a pull-back, the withdrawing fear of losing the player
        $t = (array) $cc['turn_away'];
        $away = max($walk, $in['pullback'] > 0 ? max(floatval($t['pullback_floor']), $in['pullback']) : 0.0, floatval($t['withdraw']) * $in['withdraw']);
        $lean = $in['avoidance'] - $in['anxiety'];
        $away = self::clamp($away * (1.0 + floatval($t['avoidance_gain']) * $lean));

        // shy_glance: passion the NPC is shy about, the flush a match holds, a glance anyone steals at overwhelming passion
        $s = (array) $cc['shy_glance'];
        $drawn = self::clamp(($in['passion'] - floatval($s['passion_from'])) / max(1.0, floatval($s['passion_full']) - floatval($s['passion_from'])));
        $shy = $in['shyness'] * $drawn;
        if ($in['flush_held']) $shy += floatval($s['blush_hold']);
        $overwhelm = self::clamp(($in['passion'] - floatval($s['overwhelm_from'])) / max(1.0, 100.0 - floatval($s['overwhelm_from']))) * floatval($s['overwhelm_strength']);
        $shy = max($shy, $overwhelm);
        if (!empty($in['platonic'])) $shy = 0.0;   // nothing to be shy about in that way: whatever passion there is reads as affection
        $ssup = (array) $s['suppress'];
        foreach ([floatval($ssup['pullback']) * $in['pullback'], floatval($ssup['walkaway']) * $walk, $in['conflict'] ? floatval($ssup['conflict']) : 0.0] as $eased) {
            $shy *= (1.0 - self::clamp($eased));
        }

        // tense_stance: conflict, resentment, jealousy, the ick; the immature show it more
        $k = (array) $cc['tense_stance'];
        $res = self::clamp(($in['resentment'] - floatval($k['resentment_from'])) / max(1.0, floatval($k['resentment_span'])));
        $tense = max(
            $in['conflict'] ? floatval($k['conflict']) + (1.0 - floatval($k['conflict'])) * $res : 0.0,
            $res, floatval($k['jealousy_weight']) * $jealous, $in['ick'] ? floatval($k['ick']) : 0.0
        );
        $tense = self::clamp($tense * (1.0 + floatval($k['maturity_gain']) * (50.0 - $in['maturity']) / 50.0));

        return ['turn_away' => round($away, 4), 'tense_stance' => round($tense, 4), 'approach' => round($approach, 4), 'shy_glance' => round(self::clamp($shy), 4)];
    }

    // =====================================================================
    // THE VOICE
    // =====================================================================

    /** The moods the NPC may use: its own emote_moods (core's list when it has none). */
    private static function allowedMoods(): array
    {
        $raw = $GLOBALS['EMOTEMOODS'] ?? '';
        $raw = is_array($raw) ? implode(',', $raw) : trim((string) $raw);
        if ($raw === '') return self::FALLBACK_MOODS;
        $moods = function_exists('normalizeEmoteMoods') ? normalizeEmoteMoods($raw)
            : array_values(array_filter(array_map(fn($m) => strtolower(trim($m)), preg_split('/[
,|]+/', $raw))));
        return $moods !== [] ? $moods : self::FALLBACK_MOODS;
    }

    /**
     * The voice's emotion for TTS from effective passion, arousal and valence (and shyness / self-confidence for who is speaking),
     * or null for an ordinary voice.
     *
     * @return ?array{family: string, mood: ?string, intensity: string, magnitude: float, pace: string, cartesia: ?string}
     */
    public static function voice(array $in, ?array $cfg = null, ?array $allowed = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $v = (array) $cfg['voice'];
        if (empty($v['enabled'])) return null;
        $p = $in['passion'];
        $val = $in['valence'];
        $ar = $in['arousal'];
        $pv = (array) $v['passion'];
        $vv = (array) $v['valence'];
        $av = (array) $v['arousal'];
        $family = null;
        $mag = 0.0;
        if ($val <= floatval($vv['sour'])) {
            if ($ar <= floatval($av['low'])) $family = 'sad';
            elseif ($val <= floatval($vv['hostile']) && $ar >= floatval($av['high'])) $family = 'angry';
            else $family = 'irritated';
            $mag = max(abs($val) / 100.0, $ar / 100.0 * 0.6);
        } elseif ($p >= floatval($pv['burning'])) {
            $family = $in['shyness'] >= floatval($v['shy_at']) ? 'shy' : 'seductive';
            $mag = $p / 100.0;
        } elseif ($p >= floatval($pv['warm'])) {
            $family = $in['shyness'] >= floatval($v['shy_at']) ? 'shy'
                : (($in['self_confidence'] >= floatval($v['confident_at']) && $ar >= floatval($av['high'])) ? 'teasing' : 'lovely');
            $mag = $p / 100.0;
        } elseif ($p >= floatval($pv['soft'])) {
            $family = 'kindly';
            $mag = $p / 100.0;
        } elseif ($val >= floatval($vv['bright'])) {
            $family = $ar >= floatval($av['high']) ? 'happy' : 'kindly';
            $mag = max($val / 100.0, $ar / 100.0 * 0.6);
        }
        // no desire there (platonic) or none of the body (emotional): warm and kind, not seductive, shy or teasing
        if (in_array($family, ['seductive', 'shy', 'teasing', 'lovely'], true) && !empty($in['platonic'])) $family = 'kindly';
        if ($family === 'seductive' && !empty($in['emotional'])) $family = 'lovely';
        if ($family === null || $mag < floatval($v['min_intensity'])) return null;
        $mag = self::clamp($mag);
        $at = (array) $v['intensity_at'];
        $intensity = $mag >= floatval($at['strong']) ? 'strong' : ($mag >= floatval($at['moderate']) ? 'moderate' : 'low');
        $pace = (array) $v['pace'];
        $speed = $ar < floatval($pace['slow_below']) ? 'slow' : ($ar > floatval($pace['fast_above']) ? 'fast' : 'normal');
        $allowed = $allowed ?? self::allowedMoods();
        $mood = null;
        foreach ((array) ($v['moods'][$family] ?? []) as $candidate) {
            if (in_array($candidate, $allowed, true)) { $mood = (string) $candidate; break; }
        }
        $cartesia = $mood !== null ? ($v['cartesia'][$mood] ?? null) : ($v['cartesia'][$family] ?? null);
        return ['family' => $family, 'mood' => $mood, 'intensity' => $intensity, 'magnitude' => round($mag, 3), 'pace' => $speed,
            'cartesia' => is_string($cartesia) ? $cartesia : null];
    }

    // =====================================================================
    // THE STATE (picks, cooldowns, the blush, the outbox)
    // =====================================================================

    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::KEY] ?? null) || intval($dynamics[self::KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::KEY] = ['v' => self::VERSION, 'picked' => null, 'last' => [], 'blush' => null, 'outbox' => []];
        }
        return $dynamics[self::KEY];
    }

    private static function play(array $dynamics): float
    {
        return RelationshipDynamics::getPlayGamets($dynamics);
    }

    private static function perSecond(): float
    {
        return (float) RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    }

    /** The offered cues (strength at or over min_strength), strongest first, ties by CUES order. @return array<string, float> */
    public static function offered(array $strengths, ?array $cfg = null): array
    {
        $min = floatval((($cfg ?? self::config())['cues'])['min_strength']);
        $out = [];
        foreach (self::CUES as $cue) {
            if (($strengths[$cue] ?? 0.0) >= $min) $out[$cue] = $strengths[$cue];
        }
        uksort($out, fn($a, $b) => ($out[$b] <=> $out[$a]) ?: (array_search($a, self::CUES, true) <=> array_search($b, self::CUES, true)));
        return $out;
    }

    /**
     * The cue that speaks now: a fresh pick (Jev's) whose cue is still offered, else (default_pick) the strongest offered one,
     * else none. A pick of no cue (Jev decided nothing fits) keeps the default quiet for its lifetime.
     *
     * 'voice' is Jev's word on the voice with its pick: false = the voice stays ordinary for the pick's lifetime, true or null = the
     * voice speaks by its own rule.
     *
     * @return array{cue: ?string, source: ?string, strength: float, voice: ?bool}
     */
    public static function chosen(array $dynamics, ?array $in = null, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $in = $in ?? self::inputs($dynamics);
        $offered = self::offered(self::strengths($in, $cfg), $cfg);
        $p = $dynamics[self::KEY]['picked'] ?? null;
        if (is_array($p) && is_numeric($p['play'] ?? null)) {
            $age = (self::play($dynamics) - floatval($p['play'])) / self::perSecond();
            if ($age >= 0 && $age <= floatval($cfg['cues']['pick_ttl_play_seconds'])) {
                $voice = array_key_exists('voice', $p) && $p['voice'] !== null ? (bool) $p['voice'] : null;
                if (!isset($p['cue']) || $p['cue'] === null) return ['cue' => null, 'source' => (string) ($p['source'] ?? 'jev'), 'strength' => 0.0, 'voice' => $voice];
                if (isset($offered[$p['cue']])) return ['cue' => (string) $p['cue'], 'source' => (string) ($p['source'] ?? 'jev'), 'strength' => $offered[$p['cue']], 'voice' => $voice];
            }
        }
        if (!empty($cfg['cues']['default_pick']) && $offered !== []) {
            $cue = array_key_first($offered);
            return ['cue' => $cue, 'source' => 'default', 'strength' => $offered[$cue], 'voice' => null];
        }
        return ['cue' => null, 'source' => null, 'strength' => 0.0, 'voice' => null];
    }

    // =====================================================================
    // FELT STEERING (the cue and the voice as text for the LLM)
    // =====================================================================

    /** Text of a cue at a strength (soft / strong variant). */
    public static function cueText(string $cue, float $strength, ?array $cfg = null, bool $platonic = false): string
    {
        $cfg = $cfg ?? self::config();
        $variant = ($platonic && isset($cfg['text'][$cue]['platonic_soft']) ? 'platonic_' : '') . ($strength >= floatval($cfg['strong_at']) ? 'strong' : 'soft');
        return (string) ($cfg['text'][$cue][$variant] ?? '');
    }

    /**
     * RelDynFelt lines: ['key', 'scope', 'salience', 'text', 'tag', 'intense'] for the cue that speaks and for the voice. Text keeps
     * {NAME} / {PLAYER} and the pronoun vars for RelDynFelt's fill. With the body off, or nothing worth showing: none.
     */
    public static function feltLines(string $npcName, string $playerRef, array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return [];
        $in = self::inputs($dynamics);
        $sal = (array) $cfg['salience'];
        $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerRef];
        $lines = [];
        $c = self::chosen($dynamics, $in, $cfg);
        if ($c['cue'] !== null) {
            $lines[] = ['key' => 'body_' . $c['cue'], 'scope' => 'bond', 'salience' => min(1.0, floatval($sal['cue']) + floatval($sal['cue_gain']) * $c['strength']),
                'text' => strtr(self::cueText($c['cue'], $c['strength'], $cfg, !empty($in['platonic']) || !empty($in['emotional'])), $vars), 'tag' => null, 'intense' => false];
        }
        $voice = $c['voice'] === false ? null : self::voice($in, $cfg);
        if ($voice !== null && isset($cfg['text']['voice'][$voice['family']])) {
            $lines[] = ['key' => 'voice', 'scope' => 'bond', 'salience' => min(1.0, floatval($sal['voice']) + floatval($sal['voice_gain']) * $voice['magnitude']),
                'text' => strtr((string) $cfg['text']['voice'][$voice['family']], $vars), 'tag' => null, 'intense' => false];
        }
        return $lines;
    }

    /**
     * Opt-in (voice.force_mood): set core's FORCE_MOOD for the line to the voice's mood. Never over a mood something else forced
     * (a prompt's own extra mood); never without a mood the NPC may use. Returns the mood set or null.
     */
    public static function applyVoice(array $dynamics, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled']) || empty($cfg['voice']['enabled']) || empty($cfg['voice']['force_mood'])) return null;
        if (isset($GLOBALS['FORCE_MOOD']) && $GLOBALS['FORCE_MOOD'] !== '') return null;
        $in = self::inputs($dynamics);
        if (self::chosen($dynamics, $in, $cfg)['voice'] === false) return null;
        $voice = self::voice($in, $cfg);
        if ($voice === null || $voice['mood'] === null) return null;
        $GLOBALS['FORCE_MOOD'] = $voice['mood'];
        return $voice['mood'];
    }

    // =====================================================================
    // JEV: the numbers, and the pick
    // =====================================================================

    /**
     * The body for Jev: each cue's strength and whether it is offered, the threshold approach used, the cue that speaks now and
     * who chose it, the voice, the blush, what a pick may fire. All numbers as in the module doc.
     */
    public static function jev(array $dynamics, float $now = 0.0): array
    {
        $cfg = self::config();
        $in = self::inputs($dynamics);
        $strengths = self::strengths($in, $cfg);
        $offered = self::offered($strengths, $cfg);
        $chosen = self::chosen($dynamics, $in, $cfg);
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $play = self::play($dynamics);
        $last = (array) ($state['last'] ?? []);
        $cooldown = (array) $cfg['cues']['cooldown_play_seconds'];
        $cues = [];
        foreach (self::CUES as $cue) {
            $since = isset($last[$cue]) && is_numeric($last[$cue]) ? ($play - floatval($last[$cue])) / self::perSecond() : null;
            $wait = $since !== null && $since >= 0 ? max(0.0, floatval($cooldown[$cue] ?? 0) - $since) : 0.0;
            $cues[$cue] = ['strength' => $strengths[$cue], 'offered' => isset($offered[$cue]), 'action' => $cfg['cues']['actions'][$cue] ?? null,
                'cooldown_play_seconds' => round($wait, 1)];
        }
        $b = is_array($state['blush'] ?? null) ? $state['blush'] : null;
        $left = $b !== null && is_numeric($b['until_play'] ?? null) ? max(0.0, (floatval($b['until_play']) - $play) / self::perSecond()) : 0.0;
        if ($left > floatval($cfg['blush']['max_seconds']) + 1.0) $left = 0.0;
        return [
            'enabled' => !empty($cfg['enabled']),
            'cues' => $cues,
            'offered' => array_keys($offered),
            'approach_threshold' => round(self::approachThreshold($in, $cfg), 2),
            'chosen' => ['cue' => $chosen['cue'], 'source' => $chosen['source'], 'strength' => $chosen['strength']],
            'voice' => self::voice($in, $cfg),
            'voice_held_back' => $chosen['voice'] === false,
            'blush' => ['on' => $left > 0, 'play_seconds_left' => round($left, 1), 'blushiness' => round(self::blushiness($dynamics, $cfg), 3)],
            'inputs' => ['passion' => $in['passion'], 'shyness' => round($in['shyness'], 3), 'guard' => round($in['guard'], 3), 'pullback' => round($in['pullback'], 3),
                'withdraw' => $in['withdraw'], 'arousal' => $in['arousal'], 'valence' => $in['valence']],
        ];
    }

    /**
     * Jev's pick: fire $cue (null = no cue fits) for $npcName. Loads and saves the NPC's state through RelDyn's own path. Refuses a
     * cue that is not offered or is still on cooldown. The one cue with a core action comes back with its command line
     * ("Name|command|ComeCloser@") for the caller to send through the channel it already uses; the cue then speaks as felt text
     * from the NPC's next compose.
     *
     * $voice is Jev's word on the voice: false keeps it ordinary while the pick speaks, true or null leaves it to its own rule.
     *
     * @return array{ok: bool, reason: ?string, cue: ?string, strength: float, action: ?string, command: ?string, voice: ?array}
     */
    public static function pick(string $npcName, ?string $cue, string $source = 'jev', ?bool $voice = null): array
    {
        $none = ['ok' => false, 'reason' => null, 'cue' => $cue, 'strength' => 0.0, 'action' => null, 'command' => null, 'voice' => null];
        try {
            $cfg = self::config();
            if (empty($cfg['enabled'])) return ['reason' => 'off'] + $none;
            if ($cue !== null && !in_array($cue, self::CUES, true)) return ['reason' => 'unknown_cue'] + $none;
            $dynamics = RelationshipDynamics::getDynamics($npcName);
            $in = self::inputs($dynamics);
            $strengths = self::strengths($in, $cfg);
            $offered = self::offered($strengths, $cfg);
            $play = self::play($dynamics);
            $state = &self::state($dynamics);
            if ($cue !== null) {
                if (!isset($offered[$cue])) return ['reason' => 'not_offered', 'strength' => $strengths[$cue]] + $none;
                $since = isset($state['last'][$cue]) && is_numeric($state['last'][$cue]) ? ($play - floatval($state['last'][$cue])) / self::perSecond() : null;
                if ($since !== null && $since >= 0 && $since < floatval($cfg['cues']['cooldown_play_seconds'][$cue] ?? 0)) {
                    return ['reason' => 'cooldown', 'strength' => $strengths[$cue]] + $none;
                }
                $state['last'][$cue] = $play;
            }
            $state['picked'] = ['cue' => $cue, 'source' => $source, 'play' => $play, 'voice' => $voice];
            $action = $cue !== null ? ($cfg['cues']['actions'][$cue] ?? null) : null;
            RelationshipDynamics::saveDynamics($npcName, $dynamics);
            RelationshipDynamics::log("[BODY] {$npcName}: " . ($cue ?? 'no cue') . " picked by {$source}" . ($cue !== null ? ' strength=' . $strengths[$cue] : ''));
            return ['ok' => true, 'reason' => null, 'cue' => $cue, 'strength' => $cue !== null ? $strengths[$cue] : 0.0,
                'action' => is_string($action) && $action !== '' ? $action : null,
                'command' => is_string($action) && $action !== '' ? "{$npcName}|command|{$action}@" : null,
                'voice' => $voice === false ? null : self::voice($in, $cfg)];
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("body pick {$npcName}", $e);
            return ['reason' => 'error'] + $none;
        }
    }

    // =====================================================================
    // THE BLUSH
    // =====================================================================

    /** How readily this NPC blushes: 1 + shyness x w1 + (50 - self-confidence) / 50 x w2, inside [min, max]; never 0. */
    public static function blushiness(array $dynamics, ?array $cfg = null): float
    {
        $b = (array) (($cfg ?? self::config())['blush']['blushiness']);
        $in = self::inputs($dynamics);
        $v = 1.0 + floatval($b['shyness']) * $in['shyness'] + floatval($b['confidence']) * (50.0 - $in['self_confidence']) / 50.0;
        return self::clamp($v, floatval($b['min']), floatval($b['max']));
    }

    /** Seconds a moment of $delta passion points blushes for, with the love-language match $mult (1 / 1.5 / 2) and this NPC's blushiness. */
    public static function blushSeconds(array $dynamics, float $delta, float $mult, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $bc = (array) $cfg['blush'];
        $felt = (array) RelDynFelt::config()['blush'];
        $faint = floatval($felt['faint_at']);
        $strong = floatval($felt['strong_at']);
        $huge = max($strong + 0.01, floatval($bc['huge_at']));
        $sf = floatval($bc['seconds_faint']);
        $ss = floatval($bc['seconds_strong']);
        $sh = floatval($bc['seconds_huge']);
        $base = $delta <= $strong
            ? $sf + ($ss - $sf) * ($delta - $faint) / max(0.01, $strong - $faint)
            : $ss + ($sh - $ss) * min(1.0, ($delta - $strong) / ($huge - $strong));
        $s = max($sf * 0.5, $base) * max(1.0, $mult) * self::blushiness($dynamics, $cfg);
        return round(self::clamp($s, floatval($bc['min_seconds']), floatval($bc['max_seconds'])), 1);
    }

    /**
     * A passion moment landed (the felt blush line fires on it): put a blush on the NPC's model. Queues the command in the NPC's
     * outbox (flush() sends it through the channel); starts one, extends one still on when this outlasts it, or holds back (it is
     * still on enough, or the last one only just ended). Returns what it did: ['action' => start|extend|hold|cooldown|off, ...].
     *
     * @param float $delta passion points of the moment (the felt blush's size); $mult the love-language match (1, 1.5, 2)
     */
    public static function onBlushMoment(string $npcName, array &$dynamics, float $delta, float $mult): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || empty($cfg['blush']['enabled'])) return ['action' => 'off'];
        $bc = (array) $cfg['blush'];
        $play = self::play($dynamics);
        $seconds = self::blushSeconds($dynamics, $delta, $mult, $cfg);
        $state = &self::state($dynamics);
        $b = is_array($state['blush']) ? $state['blush'] : null;
        $per = self::perSecond();
        if ($b !== null && is_numeric($b['until_play'] ?? null)) {
            $untilIn = (floatval($b['until_play']) - $play) / $per;
            // a blush the play clock says is longer than any can be belongs to a timeline a load discarded: it is over
            if ($untilIn > 0 && $untilIn <= floatval($bc['max_seconds']) + 1.0 && $play >= floatval($b['start_play'] ?? 0)) {
                // still on: extend only when this moment would outlast it by the margin
                if ($seconds < $untilIn + floatval($bc['extend_margin_seconds'])) return ['action' => 'hold', 'seconds' => $seconds, 'left' => round($untilIn, 1)];
                $b['until_play'] = $play + $seconds * $per;
                $b['seconds'] = $seconds;
                $b['token'] = intval($b['token'] ?? 0) + 1;
                $b['off_sent'] = false;
                $state['blush'] = $b;
                $state['outbox'][] = ['cmd' => self::CMD_BLUSH, 'param' => (int) round($seconds) . '@' . $b['token']];
                return ['action' => 'extend', 'seconds' => $seconds, 'token' => $b['token']];
            }
            $since = ($play - floatval($b['until_play'])) / $per;
            if ($since >= 0 && $since < floatval($bc['cooldown_play_seconds'])) return ['action' => 'cooldown', 'seconds' => $seconds, 'since' => round($since, 1)];
        }
        $token = intval($b['token'] ?? 0) + 1;
        $state['blush'] = ['token' => $token, 'start_play' => $play, 'until_play' => $play + $seconds * $per, 'seconds' => $seconds,
            'delta' => round($delta, 2), 'mult' => $mult, 'off_sent' => false];
        $state['outbox'][] = ['cmd' => self::CMD_BLUSH, 'param' => (int) round($seconds) . '@' . $token];
        return ['action' => 'start', 'seconds' => $seconds, 'token' => $token];
    }

    /**
     * The safeguard (each prerequest): a blush whose end the game never confirmed (a reloaded save lost the wait) is taken off
     * once, a grace after it was due. Idempotent on the game side: BlushOff on an NPC that is not blushing does nothing.
     */
    public static function tick(string $npcName, array &$dynamics): bool
    {
        $cfg = self::config();
        $b = $dynamics[self::KEY]['blush'] ?? null;
        if (empty($cfg['enabled']) || !is_array($b) || !empty($b['off_sent']) || !is_numeric($b['until_play'] ?? null)) return false;
        $late = (self::play($dynamics) - floatval($b['until_play'])) / self::perSecond();
        // the play clock went back under the blush (a load): the timeline it belonged to is gone, so is the blush, and the game may still wear one
        if ($late < -(floatval($cfg['blush']['max_seconds']) + 1.0)) $late = floatval($cfg['blush']['safeguard_grace_play_seconds']);
        if ($late < floatval($cfg['blush']['safeguard_grace_play_seconds'])) return false;
        $state = &self::state($dynamics);
        $state['blush']['off_sent'] = true;
        $state['outbox'][] = ['cmd' => self::CMD_BLUSH_OFF, 'param' => (string) intval($b['token'] ?? 0)];
        return true;
    }

    /**
     * Send what waits in the outbox through CHIM's command channel: responselog rows for the NPC, action "command|<ExtCmd>@<param>"
     * (core's own shape: the game's SPGResponse queue 'command', parseCommand, the ExtCmd bridge dispatch). Clears the outbox.
     * Returns true when the state changed (the caller saves). A failed insert is logged and dropped, never retried forever.
     */
    public static function flush(string $npcName, array &$dynamics): bool
    {
        $out = $dynamics[self::KEY]['outbox'] ?? null;
        if (!is_array($out) || $out === []) return false;
        $state = &self::state($dynamics);
        $state['outbox'] = [];
        $db = $GLOBALS['db'] ?? null;
        if (!is_object($db) || !method_exists($db, 'insert')) {
            error_log("[RelDyn] body: no database to queue " . count($out) . " command(s) for {$npcName}; dropped");
            return true;
        }
        foreach ($out as $c) {
            $cmd = (string) ($c['cmd'] ?? '');
            if ($cmd === '') continue;
            try {
                $ok = $db->insert('responselog', ['localts' => time(), 'sent' => 0, 'actor' => $npcName, 'text' => '',
                    'action' => 'command|' . $cmd . '@' . (string) ($c['param'] ?? ''), 'tag' => '']);
                if ($ok === false) error_log("[RelDyn] body: the {$cmd} command for {$npcName} could not be queued; dropped");
                else RelationshipDynamics::log("[BODY] {$npcName}: queued {$cmd}@" . (string) ($c['param'] ?? ''));
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("body command {$cmd} for {$npcName}", $e);
            }
        }
        return true;
    }
}
