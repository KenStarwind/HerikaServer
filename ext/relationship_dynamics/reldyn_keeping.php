<?php
/**
 * Relationship Dynamics — keeping: the fear of losing the relationship, and acting to keep it.
 *
 * Ken, 2026-10-01 (decisions §20.3): "toxic traits make an NPC paranoid about losing the
 * relationship and act to keep it, even to its detriment", scaled by her attachment weights and
 * never a hard switch. So this is no flag on a toxic NPC: every NPC has a fear of losing the
 * player, and who she is decides how much of it she carries.
 *
 *   fear   0..1 (stored, it follows its target on the game calendar: up fast, down slowly)
 *   target = gain x stakes^exponent x disposition x (baseline + (1 - baseline) x threat)
 *     stakes       what there is to lose: the bond's depth (core affinity) and how far she has let
 *                  the player in (RelDynPullback::letIn), each 0..1 between its from and full.
 *     disposition  who she is: her attachment corners (secure small, never zero; anxious and above
 *                  all toxic large: attachmentBlend over keeping.attachment), x her insecurity (low
 *                  self-confidence) x her possessiveness (trait Po). The anxiety / avoidance axes
 *                  are continuous, so a half-anxious NPC is half-way there.
 *     baseline     the share of stakes x disposition she carries with no threat at all: the paranoid
 *                  worry that nothing is wrong yet.
 *     threat       a soft-or (1 - prod(1 - w x input)) of what looks like losing him: the time since
 *                  they last spoke past a grace (the absence), her jealousy, the unmet needs (the
 *                  fulfillment deficit) and an open grievance or conflict (RelDynPullback::inputs).
 *
 * HOW IT SHOWS (RelDynConcern::expression: one mechanism for every channel): a mature NPC says it
 * plainly and asks to be reassured; the in-between one means to and it comes out sharp; an immature
 * one controls (checks, forbids, wants every plan cleared), accuses or sulks, by her traits. The
 * stronger the fear, the stronger the words (uneasy < clinging < controlling). Felt text only, no
 * digits (feltLine); Jev gets the numbers (jev()).
 *
 * TO ITS DETRIMENT: above detriment.from the grip costs the bond. Paranoia and strain hold her trust
 * and comfort toward the player down by a bounded standing offset (like the guilt bleed: one of the
 * held temporary offsets, RelationshipDynamics::heldTemporaryOffset; it lifts exactly as the fear
 * fades). A mature NPC holds it better (detriment.mature_relief), never entirely. The less she can
 * relax, the less she lets the player in, the more there is to fear: the loop is damped (a lower
 * let-in lowers the stakes), never a cliff.
 *
 * THE RESPONSE (Ken, 2026-10-01 §23): what the fear makes the NPC DO follows the NPC's own character graph, with no
 * moralising: RelDyn models the person, it does not judge the player (response()).
 *   appease   a people-pleaser (RelationshipDynamics::isPeoplePleaser: low confidence and low maturity) appeases and complies,
 *             even with a player who treats them badly: agrees, apologises first, swallows what it costs. Never a conflict.
 *   conflict  an immature NPC whose way is control (RelDynConcern::expression) may start a fight at the controlling band, once
 *             the fear has held there (response.conflict), as an open conflict like any other (enterConflict); it is the
 *             NPC's own act, so it is not counted as the player's evidence of leaving (threat()).
 *   withdraw  an NPC whose avoidance outruns their anxiety goes quiet and distant rather than ask (response.withdraw_at).
 *   express   everyone else shows it by maturity and traits as above: the mature say it plainly and ask to be reassured, the
 *             in-between sharply, the immature control, accuse or sulk; the anxious and the fearful corners cling.
 * Never a refusal of what the player asks: nothing here reaches the autonomy state or the action list (a conflict is an
 * attitude in the NPC's words, not a denied command), and a people-pleaser stays compliant.
 *
 * State: $dynamics['_keeping'] (state()). Units: fear, stakes, disposition, inputs 0..1 (unitless);
 * comfort / trust / jealousy / let-in points 0..100; core affinity -100..100; game hours and days on
 * the game calendar (raw gamets / RelationshipDynamics::GAMETS_PER_DAY). No wall clock.
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynKeeping
{
    const KEY = '_keeping';
    const VERSION = 1;

    /** Dimensions the detriment holds down (toward the player). */
    const HELD_DIMENSIONS = ['trust', 'comfort'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'keeping' (nested tables merge per entry). Serene's starting values for Ken's §20.3 ruling. */
    public static function configDefaults(): array
    {
        return [
            // Off: no fear of loss, no held offsets (a standing offset is lifted exactly)
            'enabled' => true,

            // --- stakes: what there is to lose, 0..1 each, the weighted mean ---
            'stakes' => [
                'bond' => ['from' => 10.0, 'full' => 70.0],      // core affinity points
                'let_in' => ['from' => 30.0, 'full' => 75.0],    // sqrt(comfort x trust) points
                'weights' => ['bond' => 0.5, 'let_in' => 0.5],
            ],

            // --- disposition: who she is ---
            // Attachment corner => how much she fears losing the relationship (0..1); an NPC reads them
            // blended at her two axes (attachmentBlend), so nobody is a hard switch. Secure is small, not zero.
            'attachment' => ['secure' => 0.08, 'avoidant' => 0.2, 'anxious' => 0.6, 'toxic' => 1.0],
            // Insecurity: x (1 + gain x (center - self_confidence) / span), self-confidence dimension points
            'insecurity' => ['center' => 50.0, 'span' => 50.0, 'gain' => 0.4],
            // Possessiveness (trait Po 0..1): x (1 + gain x (Po - 0.5))
            'possessiveness' => ['gain' => 0.5],

            // --- the threat: soft-or of weight x input (each input 0..1) ---
            'threat' => [
                'weights' => ['absence' => 0.8, 'jealousy' => 0.9, 'deficit' => 0.5, 'grievance' => 0.6],
                // the time since they last spoke: 0 up to grace_game_days, full at full_game_days
                'absence' => ['grace_game_days' => 1.0, 'full_game_days' => 6.0],
                // her jealousy (dimension points): 0 at from, full at full
                'jealousy' => ['from' => 20.0, 'full' => 70.0],
            ],
            // the share of stakes x disposition she carries with no threat at all (the paranoia that
            // nothing is wrong yet)
            'baseline' => 0.2,
            // fear = gain x stakes^stakes_exponent x disposition x (baseline + (1 - baseline) x threat), at most 1.
            // The root keeps a moderate bond from counting for nothing (what there is to lose is not linear
            // in how deep it is yet); the gain lets a mostly-toxic NPC at a deep bond with a clear threat reach 1
            'gain' => 1.5, 'stakes_exponent' => 0.5,

            // --- the fear follows its target on the game calendar: this share of the gap per game hour ---
            'rates' => ['rise_per_game_hour' => 0.10, 'fall_per_game_hour' => 0.04, 'max_step_game_hours' => 72.0],

            // --- to its detriment: above `from` the fear holds trust and comfort toward the player down by
            // up to these points (a standing offset, lifted as the fear fades); a mature NPC (the
            // concern lane's maturity weight 1) by (1 - mature_relief) of it ---
            'detriment' => ['from' => 0.4, 'trust_cap' => 12.0, 'comfort_cap' => 8.0, 'mature_relief' => 0.5],

            // --- the response (decisions §23): who the NPC is decides what the fear makes them do ---
            // withdraw_at: the NPC withdraws rather than clings when avoidance exceeds anxiety (attachment axes, 0..1) by this much.
            // conflict: an immature NPC whose way is control opens a conflict at the controlling band once the fear has held
            // there hold_game_hours, and again after cooldown_game_days at the earliest. Off: it stays in the NPC's words.
            'response' => [
                'withdraw_at' => 0.25,
                'conflict' => ['enabled' => true, 'hold_game_hours' => 6.0, 'cooldown_game_days' => 3.0],
            ],

            // --- felt text ---
            // fear from which the NPC says anything at all, and the bands 'clinging' / 'controlling'
            'felt_from' => 0.25,
            'bands' => ['clinging' => 0.5, 'controlling' => 0.75],
            // line salience (0..1) by band; it also rises with the fear
            'salience' => ['uneasy' => 0.45, 'clinging' => 0.65, 'controlling' => 0.85],
            // {NAME} NPC, {PLAYER} the player. Feelings and what she does about them, no digits; names the
            // NPC sparingly, no pronoun for her. Key: band, then her expression (mature / mixed / the style).
            'felt_text' => [
                'uneasy' => [
                    'mature'     => "{NAME} has been afraid, quietly, of losing {PLAYER}, and says so plainly when it comes up instead of acting on it; asks to be told where things stand.",
                    'mixed'      => "{NAME} means to say evenly that {NAME} is afraid of losing {PLAYER}, but it comes out as questions: where to, with whom, back when.",
                    'control'    => "{NAME} keeps checking on {PLAYER}: where, with whom, back when. It is meant as care and lands as a leash.",
                    'accusation' => "{NAME} reads small things as signs, a late return, a name mentioned, a pause, and asks about them sharply.",
                    'sulking'    => "{NAME} goes quiet whenever {PLAYER} talks of leaving or of anyone else, waiting to be asked what is wrong.",
                ],
                'clinging' => [
                    'mature'     => "{NAME} is afraid of losing {PLAYER} and honest about it: asks outright for reassurance, wants to know when {PLAYER} will be back, and holds the fear with visible effort rather than letting it steer.",
                    'mixed'      => "{NAME} is afraid of losing {PLAYER} and it keeps leaking out sharp: reasons {PLAYER} should stay, plans that need clearing first, a cold edge at any mention of someone else.",
                    'control'    => "{NAME} tries to keep {PLAYER} close: finds reasons {PLAYER} should not go, wants every plan cleared, goes cold at any mention of other people.",
                    'accusation' => "{NAME} accuses {PLAYER} of pulling away on thin evidence and demands to be told otherwise, needing the proof more than the answer.",
                    'sulking'    => "{NAME} hovers, hurt and silent, wanting {PLAYER} to notice and stay without having to be asked.",
                ],
                'controlling' => [
                    'mature'     => "The fear of losing {PLAYER} is loud in {NAME} now. {NAME} knows it is making {NAME} grip too hard, says so, and still asks {PLAYER} to stay close and to be told the truth about where {PLAYER} goes.",
                    'mixed'      => "{NAME} is gripping hard: means to trust {PLAYER} and keeps checking anyway, apologising for it and doing it again.",
                    'control'    => "{NAME} is gripping hard now: forbids, checks, tests, listens at the edge of every conversation for a sign {PLAYER} is leaving. The harder {NAME} holds, the less ease there is, and {NAME} cannot make it stop.",
                    'accusation' => "{NAME} is certain {PLAYER} is leaving and makes a scene to prove it wrong, or right; every accusation is really a plea not to go.",
                    'sulking'    => "{NAME} withdraws hard to see whether {PLAYER} will follow, a test no one agreed to take; the silence is a bid to be chased.",
                ],
            ],
            // What the NPC does about it, where it is not the maturity / trait expression above (decisions §23). Key: response, band,
            // and for withdraw whether the NPC voices it (mature) or not (raw). Behaviour, never a verdict on the player; no digits;
            // no pronoun for the NPC.
            'response_text' => [
                'appease_uneasy'      => "{NAME} is afraid of losing {PLAYER} and keeps things smooth: agrees quickly, apologises first, goes along with what {PLAYER} wants, whatever has been happening between them.",
                'appease_clinging'    => "{NAME} is afraid of losing {PLAYER} and tries to be easy to keep: does what is asked before it is asked, takes the blame for small things, and leaves out anything that might cause a scene, including what {NAME} wants.",
                'appease_controlling' => "The fear of losing {PLAYER} runs {NAME} now: {NAME} will agree to anything, apologise for everything and swallow every hurt to keep {PLAYER} from going, and does not say what it is costing.",
                'withdraw_uneasy_mature'      => "{NAME} is quietly afraid of losing {PLAYER} and, being who {NAME} is, gives {PLAYER} room instead of asking for anything; says so once, briefly, if it comes up.",
                'withdraw_uneasy_raw'         => "{NAME} is afraid of losing {PLAYER} and pulls into a shell about it: says less, keeps busy, acts as if it would not matter.",
                'withdraw_clinging_mature'    => "{NAME} is afraid of losing {PLAYER} and shows it by going quieter, not closer: keeps some distance on purpose, says plainly that it is hard to ask for reassurance, and lets {PLAYER} decide.",
                'withdraw_clinging_raw'       => "{NAME} is afraid of losing {PLAYER} and goes cold and distant to get ahead of it: short answers, no asking, bracing to be left and half ready to leave first.",
                'withdraw_controlling_mature' => "The fear of losing {PLAYER} is loud in {NAME} now, and the habit of {NAME} is to pull away from it: more distance, fewer words, a stillness that is not calm. {NAME} knows it makes things worse, says so, and still cannot ask.",
                'withdraw_controlling_raw'    => "{NAME} has all but shut the door on {PLAYER}: so sure of being left that {NAME} is leaving first in every small way, and hoping, against all of it, that {PLAYER} notices and follows.",
                'conflict' => "{NAME} has started a fight with {PLAYER} to keep {PLAYER} from going: accusations, demands to know where {PLAYER} has been and with whom, ultimatums that are really pleas. {NAME} wants {PLAYER} to stay, and is making it hard.",
            ],
        ];
    }

    private const MERGED_TABLES = ['stakes', 'attachment', 'insecurity', 'possessiveness', 'threat', 'rates', 'detriment', 'bands', 'salience', 'response', 'response_text'];

    /** The keeping settings: stored config per setting, its nested tables merged per entry ('felt_text' per band and expression). */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('keeping');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (self::MERGED_TABLES as $t) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
        }
        foreach (['stakes' => ['bond', 'let_in', 'weights'], 'threat' => ['weights', 'absence', 'jealousy']] as $t => $subs) {
            foreach ($subs as $s) {
                $cfg[$t][$s] = array_replace((array) $defaults[$t][$s], is_array($stored[$t][$s] ?? null) ? $stored[$t][$s] : []);
            }
        }
        $cfg['response']['conflict'] = array_replace((array) $defaults['response']['conflict'], is_array($stored['response']['conflict'] ?? null) ? $stored['response']['conflict'] : []);
        $cfg['felt_text'] = $defaults['felt_text'];
        foreach ((array) ($stored['felt_text'] ?? []) as $band => $row) {
            if (is_array($row) && isset($cfg['felt_text'][$band])) $cfg['felt_text'][$band] = array_replace($cfg['felt_text'][$band], $row);
        }
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

    /** $v between $from (0) and $full (1). */
    private static function between(float $v, float $from, float $full): float
    {
        return $full > $from ? self::clamp01(($v - $from) / ($full - $from)) : ($v >= $full ? 1.0 : 0.0);
    }

    // =====================================================================
    // THE TARGET (pure)
    // =====================================================================

    /** What there is to lose (0..1): the bond's depth and how far she has let the player in. */
    public static function stakes(array $dynamics, ?array $cfg = null): float
    {
        $s = (array) (($cfg ?? self::config())['stakes']);
        $bond = self::between(RelationshipDynamics::getCoreAffinity($dynamics), floatval($s['bond']['from']), floatval($s['bond']['full']));
        $letIn = self::between(RelDynPullback::letIn($dynamics), floatval($s['let_in']['from']), floatval($s['let_in']['full']));
        $wb = max(0.0, floatval($s['weights']['bond']));
        $wl = max(0.0, floatval($s['weights']['let_in']));
        return $wb + $wl > 0.0 ? self::clamp01(($wb * $bond + $wl * $letIn) / ($wb + $wl)) : 0.0;
    }

    /**
     * Who she is (0..1): the attachment corners blended at her axes, x her insecurity (low
     * self-confidence), x her possessiveness (trait Po). Continuous; secure is small, never zero.
     */
    public static function disposition(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $a = RelationshipDynamics::attachmentBlend($dynamics, (array) $cfg['attachment'], 0.0);
        $ins = (array) $cfg['insecurity'];
        $conf = is_numeric($dynamics['dimensions']['self_confidence']['x'] ?? null) ? floatval($dynamics['dimensions']['self_confidence']['x']) : floatval($ins['center']);
        $insecure = max(0.5, min(1.5, 1.0 + floatval($ins['gain']) * (floatval($ins['center']) - $conf) / max(1.0, floatval($ins['span']))));
        $po = floatval(RelDynConcern::traitsOf($dynamics)['Po'] ?? 0.5);
        $possessive = max(0.5, min(1.5, 1.0 + floatval(((array) $cfg['possessiveness'])['gain']) * ($po - 0.5)));
        return round(self::clamp01($a * $insecure * $possessive), 4);
    }

    /**
     * What looks like losing the player ('absence', 'jealousy', 'deficit', 'grievance', each 0..1) at $now
     * from the NPC's own state, and their soft-or ('threat'). Pure.
     */
    public static function threat(array $dynamics, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $t = (array) $cfg['threat'];
        $prev = floatval($dynamics['_previous_contact_gamets'] ?? 0);
        $days = ($prev > 0 && $now > $prev) ? ($now - $prev) / RelationshipDynamics::GAMETS_PER_DAY : 0.0;
        $absence = self::between($days, floatval($t['absence']['grace_game_days']), floatval($t['absence']['full_game_days']));
        $jealousy = self::between(floatval($dynamics['dimensions']['jealousy']['x'] ?? 0.0), floatval($t['jealousy']['from']), floatval($t['jealousy']['full']));
        // a conflict the NPC started out of this fear is their own act, not the player's sign of leaving (no runaway loop)
        $own = $dynamics;
        if (!empty($dynamics[self::KEY]['conflict']['open'])) $own['in_conflict'] = false;
        $p = RelDynPullback::inputs($own, $now);
        $in = ['absence' => $absence, 'jealousy' => $jealousy, 'deficit' => floatval($p['deficit']), 'grievance' => floatval($p['grievance'])];
        $keep = 1.0;
        foreach ($in as $k => $v) $keep *= 1.0 - self::clamp01(floatval(((array) $t['weights'])[$k] ?? 0.0) * $v);
        $in = array_map(fn($v) => round($v, 4), $in);
        $in['threat'] = round(1.0 - $keep, 4);
        return $in;
    }

    /** The fear the state pulls toward (0..1): gain x stakes^exponent x disposition x (baseline + (1 - baseline) x threat). */
    public static function target(float $stakes, float $disposition, float $threat, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $b = self::clamp01(floatval($cfg['baseline']));
        return self::clamp01(max(0.0, floatval($cfg['gain'])) * pow(self::clamp01($stakes), max(0.0, floatval($cfg['stakes_exponent'])))
            * $disposition * ($b + (1.0 - $b) * self::clamp01($threat)));
    }

    /** The fear after $hours game hours toward $target: up at rise_per_game_hour, down at fall_per_game_hour. */
    public static function approach(float $fear, float $target, float $hours, ?array $cfg = null): float
    {
        $r = (array) (($cfg ?? self::config())['rates']);
        $hours = max(0.0, min($hours, floatval($r['max_step_game_hours'])));
        $rate = $target > $fear ? floatval($r['rise_per_game_hour']) : floatval($r['fall_per_game_hour']);
        return self::clamp01($fear + ($target - $fear) * (1.0 - pow(1.0 - self::clamp01($rate), $hours)));
    }

    /** Her band for a fear: null below felt_from, else uneasy | clinging | controlling. */
    public static function band(float $fear, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        if ($fear < floatval($cfg['felt_from'])) return null;
        $b = (array) $cfg['bands'];
        return $fear >= floatval($b['controlling']) ? 'controlling' : ($fear >= floatval($b['clinging']) ? 'clinging' : 'uneasy');
    }

    /**
     * What the grip costs the bond: the standing offsets (dimension => points, <= 0) the fear holds on trust and
     * comfort toward the player. Zero up to detriment.from, full at fear 1; a mature NPC (expression weight w)
     * by (1 - mature_relief x w) of it. Pure.
     */
    public static function detrimentTarget(float $fear, float $maturityWeight, ?array $cfg = null): array
    {
        $d = (array) (($cfg ?? self::config())['detriment']);
        $excess = self::between($fear, floatval($d['from']), 1.0);
        $mult = 1.0 - self::clamp01(floatval($d['mature_relief'])) * self::clamp01($maturityWeight);
        return ['trust' => -round(floatval($d['trust_cap']) * $excess * $mult, 6), 'comfort' => -round(floatval($d['comfort_cap']) * $excess * $mult, 6)];
    }

    /** How she expresses it: RelDynConcern::expression (band mature|mixed|immature, style control|accusation|sulking, w). */
    public static function expression(array $dynamics): array
    {
        return RelDynConcern::expression($dynamics, RelDynConcern::traitsOf($dynamics));
    }

    /**
     * How the NPC answers the fear (decisions §23): 'kind' appease (a people-pleaser) | withdraw (avoidance outruns anxiety by
     * response.withdraw_at) | express (the maturity / trait expression: plainly, sharply, control, accusation, sulking, clinging);
     * 'lean' avoidance minus anxiety (attachment axes); 'expression' RelDynConcern::expression. Pure.
     *
     * @return array{kind: string, lean: float, expression: array}
     */
    public static function response(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        $lean = round(floatval($axes['avoidance'] ?? 0.0) - floatval($axes['anxiety'] ?? 0.0), 4);
        $kind = RelationshipDynamics::isPeoplePleaser($dynamics) ? 'appease'
            : ($lean >= floatval(((array) $cfg['response'])['withdraw_at']) ? 'withdraw' : 'express');
        return ['kind' => $kind, 'lean' => $lean, 'expression' => self::expression($dynamics)];
    }

    /** Is a conflict the fear started still open (the NPC's own fight, repaired or not)? */
    public static function conflictOpen(array $dynamics): bool
    {
        return !empty($dynamics[self::KEY]['conflict']['open']) && !empty($dynamics['in_conflict']);
    }

    /**
     * The conflict at the controlling end (decisions §23): an immature NPC whose way is control, at the controlling band, once the
     * fear has held there response.conflict.hold_game_hours, and not again before cooldown_game_days; never a people-pleaser (they
     * appease), never one who withdraws, never into a conflict already open. The conflict is an open conflict like any other
     * (RelationshipDynamics::enterConflict); it denies the player nothing. Tracks how long the fear has held in the band.
     * Returns true when this step opened one.
     */
    private static function conflictStep(string $npcName, array &$dynamics, ?string $band, array $response, float $now, array $cfg): bool
    {
        $state = &self::state($dynamics);
        if (!empty($state['conflict']['open']) && empty($dynamics['in_conflict'])) $state['conflict']['open'] = false;   // repaired
        if ($band === 'controlling') {
            if (!isset($state['controlling_since'])) $state['controlling_since'] = $now;
        } else {
            unset($state['controlling_since']);
        }
        $cc = (array) ((array) $cfg['response'])['conflict'];
        $e = $response['expression'];
        $may = !empty($cc['enabled']) && $band === 'controlling' && $response['kind'] === 'express'
            && $e['band'] === 'immature' && $e['style'] === 'control' && empty($dynamics['in_conflict']);
        if (!$may) return false;
        $held = ($now - floatval($state['controlling_since'] ?? $now)) / self::hour();
        $last = floatval($state['conflict']['opened'] ?? 0);
        if ($held < floatval($cc['hold_game_hours']) || ($last > 0 && $now >= $last && $now - $last < floatval($cc['cooldown_game_days']) * RelationshipDynamics::GAMETS_PER_DAY)) return false;
        $count = intval($state['conflict']['count'] ?? 0) + 1;
        $state['conflict'] = ['open' => true, 'opened' => $now, 'count' => $count];
        unset($state);
        RelationshipDynamics::enterConflict($dynamics);
        RelationshipDynamics::log("[KEEPING] {$npcName}: the fear of losing the player has held at its worst for " . round($held, 1)
            . " game hours, and the NPC (immature, a controlling way) starts a conflict (#{$count})");
        return true;
    }

    // =====================================================================
    // STATE
    // =====================================================================

    /**
     * $dynamics['_keeping']: v, fear (0..1), gamets (the last advance), applied (dimension => points the fear holds
     * on that dimension now, <= 0), last (the numbers of the last advance, for Jev).
     */
    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::KEY] ?? null) || intval($dynamics[self::KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::KEY] = ['v' => self::VERSION, 'fear' => 0.0, 'applied' => ['trust' => 0.0, 'comfort' => 0.0]];
        }
        return $dynamics[self::KEY];
    }

    /** Her fear of losing the player now (the stored state; 0 with the switch off). */
    public static function fear(array $dynamics): float
    {
        return self::enabled() ? floatval($dynamics[self::KEY]['fear'] ?? 0.0) : 0.0;
    }

    // =====================================================================
    // ADVANCE (the prerequest hook)
    // =====================================================================

    /**
     * One step to $now (raw gamets): the fear follows its target, and the detriment's standing offsets follow the
     * fear (moved by exactly what changed, clamped to the dimension's range, so lifting leaves no residue). With the
     * switch off the offsets are lifted and the fear is forgotten. Returns ['fear', 'target', 'band', 'moved'].
     */
    public static function advance(string $npcName, array &$dynamics, float $now): array
    {
        $out = ['fear' => 0.0, 'target' => 0.0, 'band' => null, 'moved' => ['trust' => 0.0, 'comfort' => 0.0]];
        $cfg = self::config();
        $has = is_array($dynamics[self::KEY] ?? null);
        if (empty($cfg['enabled'])) {
            if ($has) {
                $out['moved'] = self::moveOffsets($dynamics, ['trust' => 0.0, 'comfort' => 0.0]);
                unset($dynamics[self::KEY]);
            }
            return $out;
        }
        if ($now <= 0) return $out;
        $state = &self::state($dynamics);
        $stakes = self::stakes($dynamics, $cfg);
        $disp = self::disposition($dynamics, $cfg);
        $threat = self::threat($dynamics, $now, $cfg);
        $target = self::target($stakes, $disp, $threat['threat'], $cfg);
        $last = floatval($state['gamets'] ?? 0);
        $hours = $last > 0 ? max(0.0, ($now - $last) / self::hour()) : 0.0;
        $before = floatval($state['fear'] ?? 0.0);
        // first sight of the NPC: the state of the world is what it is
        $fear = $last > 0 ? self::approach($before, $target, $hours, $cfg) : $target;
        $e = self::expression($dynamics);
        $want = self::detrimentTarget($fear, $e['w'], $cfg);
        unset($state);
        $moved = self::moveOffsets($dynamics, $want);
        $state = &self::state($dynamics);
        $state['fear'] = round($fear, 4);
        $state['gamets'] = $now;
        $state['last'] = ['stakes' => round($stakes, 4), 'disposition' => $disp, 'threat' => $threat, 'target' => round($target, 4)];
        $bandBefore = self::band($before, $cfg);
        $bandNow = self::band($fear, $cfg);
        unset($state);
        self::conflictStep($npcName, $dynamics, $bandNow, ['kind' => self::response($dynamics, $cfg)['kind'], 'expression' => $e], $now, $cfg);
        if ($bandNow !== $bandBefore) {
            RelationshipDynamics::log("[KEEPING] {$npcName}: the fear of losing the player is " . ($bandNow ?? 'gone') . ' (was ' . ($bandBefore ?? 'none')
                . ', target ' . round($target, 3) . ", stakes " . round($stakes, 2) . ", disposition {$disp}, {$e['band']}/{$e['style']})");
        }
        return ['fear' => round($fear, 4), 'target' => round($target, 4), 'band' => $bandNow, 'moved' => $moved];
    }

    /**
     * Move the standing offsets (trust, comfort toward the player) to $want (dimension => points <= 0): x changes by
     * the difference in hundredths of a point (the dimensions are read at two places, the display and Jev round to
     * them), clamped to 0..100, and exactly what moved is recorded, so lifting leaves no residue. Returns
     * dimension => points moved.
     */
    private static function moveOffsets(array &$dynamics, array $want): array
    {
        $moved = ['trust' => 0.0, 'comfort' => 0.0];
        $state = &self::state($dynamics);
        foreach (self::HELD_DIMENSIONS as $dim) {
            $x = $dynamics['dimensions'][$dim]['x'] ?? null;
            if (!is_numeric($x)) continue;
            $applied = floatval($state['applied'][$dim] ?? 0.0);
            $delta = round(floatval($want[$dim] ?? 0.0) - $applied, 2);
            if (abs($delta) < 1e-9) continue;
            $new = max(0.0, min(100.0, floatval($x) + $delta));
            $moved[$dim] = $new - floatval($x);
            $dynamics['dimensions'][$dim]['x'] = round($new, 4);
            $applied += $moved[$dim];
            $state['applied'][$dim] = abs($applied) < 1e-4 && floatval($want[$dim] ?? 0.0) === 0.0 ? 0.0 : round($applied, 6);
        }
        unset($state);
        return $moved;
    }

    // =====================================================================
    // FELT TEXT
    // =====================================================================

    /**
     * The standing line while she fears losing the player ('key', 'text', 'salience'), or null: her band by the fear
     * (uneasy | clinging | controlling) and her expression (mature, mixed, or the immature style). Feelings and what
     * she does about them, no digits. $tier: the context tier; nothing below tier 1 (a stranger has nothing to lose).
     */
    public static function feltLine(array $dynamics, string $npc, string $player, int $tier = 2): ?array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $tier < 1) return null;
        $fear = floatval($dynamics[self::KEY]['fear'] ?? 0.0);
        $band = self::band($fear, $cfg);
        if ($band === null) return null;
        $resp = self::response($dynamics, $cfg);
        $e = $resp['expression'];
        $rt = (array) $cfg['response_text'];
        // what the NPC does about it, by who they are (decisions §23), else how they show it by maturity and traits
        $text = '';
        if ($resp['kind'] === 'appease') {
            $text = (string) ($rt["appease_{$band}"] ?? '');
        } elseif ($resp['kind'] === 'withdraw') {
            $text = (string) ($rt["withdraw_{$band}_" . ($e['band'] === 'mature' ? 'mature' : 'raw')] ?? '');
        } elseif ($band === 'controlling' && $e['band'] === 'immature' && $e['style'] === 'control' && self::conflictOpen($dynamics)) {
            $text = (string) ($rt['conflict'] ?? '');
        }
        if ($text === '') {
            $row = (array) (((array) $cfg['felt_text'])[$band] ?? []);
            $text = (string) ($row[$e['band'] === 'mature' ? 'mature' : ($e['band'] === 'mixed' ? 'mixed' : $e['style'])] ?? '');
        }
        if ($text === '') return null;
        $sal = floatval(((array) $cfg['salience'])[$band] ?? 0.5);
        return ['key' => 'keeping_' . $band, 'text' => strtr($text, ['{NAME}' => $npc, '{PLAYER}' => $player]),
            'salience' => round(min(1.0, $sal + 0.1 * $fear), 3), 'band' => $band, 'style' => $e['style'], 'expression' => $e['band'], 'response' => $resp['kind']];
    }

    // =====================================================================
    // JEV
    // =====================================================================

    /** Numbers for Jev: the fear, its band, what it is made of, what the grip holds down (keeping). */
    public static function jev(array $dynamics): array
    {
        $cfg = self::config();
        $s = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $fear = floatval($s['fear'] ?? 0.0);
        $resp = self::response($dynamics, $cfg);
        $e = $resp['expression'];
        return [
            'enabled' => !empty($cfg['enabled']),
            'fear' => round($fear, 3),
            'band' => self::band($fear, $cfg),
            'target' => isset($s['last']['target']) ? floatval($s['last']['target']) : null,
            'stakes' => isset($s['last']['stakes']) ? floatval($s['last']['stakes']) : null,
            'disposition' => isset($s['last']['disposition']) ? floatval($s['last']['disposition']) : round(self::disposition($dynamics, $cfg), 3),
            'threat' => (array) ($s['last']['threat'] ?? []),
            'held' => array_map(fn($v) => round(floatval($v), 3), (array) ($s['applied'] ?? [])),
            'expression' => $e['band'], 'style' => $e['style'],
            'response' => $resp['kind'], 'lean' => $resp['lean'],
            'conflict' => ['open' => self::conflictOpen($dynamics), 'count' => intval($s['conflict']['count'] ?? 0)],
        ];
    }
}
