<?php
/**
 * Relationship Dynamics — the resentment threshold events: the confrontation (MDD 15.5 at 50),
 * resentment_self's thresholds and recovery, and cross-bond guilt bleed.
 *
 * CONFRONTATION (MDD 15.5 "50: NPC initiates confrontation"; dimension design Dimension 10;
 * autonomy design memory: the personality matrix). When resentment reaches the NPC's
 * confrontation threshold (the attachment row's confrontation_threshold, a region key: secure
 * 50, anxious 30 "confront immediately", avoidant 70, toxic 50; confrontation.at when the row
 * has none) the NPC says it to the player's face, with the grievances it carries as fuel
 * (grievance_log, then the dimensional memory; felt phrases, never numbers). HOW is maturity's
 * (RelDynConcern::expression, the one expression model): a mature NPC says it calmly and
 * directly in one conversation; an in-between one means to and it comes out in its style; an
 * immature one blows up (control / accusation / sulking), older hurts dragged in. Saying it is
 * the MDD 15.5 addressed decay (confrontation.addressed_relief resentment points).
 *   - People-pleasers (low self-confidence and maturity) never confront: they internalize
 *     (resentment_self, RelationshipDynamics::recordGrievance).
 *   - One boundary at a time per bond (rulings §9, decisions §14): no confrontation while a
 *     boundary's own conversation is due (either lane's statement pending or step-back due)
 *     or while the grievance boundary watches its probation. A confrontation is not a
 *     boundary: during another boundary's probation (unmet needs, a values kind) a first one
 *     is still said. A mature NPC who already said it and is wronged again treats the
 *     repetition as a values mismatch that feeds the boundary flow: it opens the values
 *     boundary (RelDynConcern::openGrievanceBoundary, channel 'grievance': statement,
 *     probation, a step-back when another grievance lands inside it), unless a boundary
 *     already runs: then that one carries it (no second boundary, no second conversation).
 *     After the grievance boundary ends, only a grievance since feeds the next one.
 *   - An immature NPC festers and blows up again, each time something new was added, at most
 *     once per confrontation.cooldown_play_minutes of played time (the play clock).
 *   - A repeat needs a grievance logged since the last confrontation (it happened again);
 *     the episode ends when resentment falls below confrontation.rearm_below.
 *
 * RESENTMENT_SELF (dimension design "resentment_self", autonomy design memory; roadmap
 * resentment-self). Thresholds (strictly above, resentment_self points 0..100):
 *   > 30  comfort baseline -5 (the NPC's baseline is global: every bond)
 *   > 50  a self-reflection moment (one-shot felt line; the confession it can lead to below)
 *   > 70  warmth baseline -10
 *   > 90  crisis: the NPC seeks isolation, a self-triggered walkaway (reason 'shame',
 *         RelationshipDynamics::evaluateAutonomyState), people-pleaser or not
 * Baseline offsets are reversible: they lift exactly when resentment_self falls back.
 * Recovery (open issue: "no recovery path ... trapped in permanent guilt"): applied as
 * resentment_self points, not through the inverted rubber band that caused the trap:
 *   - confession: the NPC tells what she is ashamed of and it is met with care (eval tag,
 *     self.recovery_tags). The dimension draft's confession follows the self-reflection (the
 *     Director's scene at 50, then she tells), so it opens only once the reflection was said to
 *     the player, and it is made once (the MDD 15.5 addressed decay, like the confrontation's):
 *     the next opening up is the decay again. The window closes when resentment_self is worked
 *     through (reflection_rearm_at), and the next reflection opens the next one;
 *   - processResentmentSelfDecay: -0.5 x (1 + maturity/100) per positive interaction while
 *     comfort is above 20, at most once per self.decay_cooldown_play_minutes of played time.
 * The people-pleaser buildup (prerequest) only runs when the people-pleaser override swallowed a
 * refusal (self.buildup_when) and at most once per self.buildup_cooldown_play_minutes of played
 * time, so the recovery above is not outrun once the mistreatment stops.
 *
 * GUILT BLEED (dimension design "Cross-bond dimensional bleed"): resentment_self above
 * guilt_bleed.above bleeds into comfort toward the player in proportion to the bond:
 *   bleed = min(cap, resentment_self x bond / 100),  bond = core affinity clamped 0..100
 * (a stranger barely registers, a partner haunts). A standing, bounded offset on comfort
 * (comfort points), moved to its target each tick and lifted exactly as the guilt fades. It is
 * one of the held temporary offsets (RelationshipDynamics::heldTemporaryOffset): the physics
 * reads comfort without it (so lifting it leaves her where she would be without the guilt),
 * the drift does not sample it as who she is, and the per-bond display shows it as it is.
 *
 * Felt text only for the LLM (feelings, never numbers); Jev gets the numbers (jev()).
 * State: $dynamics['_resentment_arc'] (state()). Units: resentment / resentment_self /
 * comfort / warmth points 0..100; play minutes on the play clock (_accumulated_play_gamets,
 * RelationshipDynamics::GAMETS_PER_REAL_SECOND x 60 play gamets each); game time raw gamets.
 * No wall clock.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynResentment
{
    const STATE_KEY = '_resentment_arc';
    const VERSION = 1;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'resentment_arc' (a stored config replaces settings per section;
     * felt_text and fuel_phrases merge per entry). MDD / design numbers unless marked Serene's.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'confrontation' => [
                'enabled' => true,
                // MDD 15.5: resentment points at which the NPC confronts, when the attachment row
                // (attachment.modifiers[*].confrontation_threshold) has none.
                'at' => 50.0,
                // Dimension design (hybrid timers): 60 minutes between confrontation attempts,
                // counted on the play clock (played time; the design's IRL minutes).
                'cooldown_play_minutes' => 60.0,
                // Serene: the episode is over (the next confrontation is a first one again) when
                // resentment falls below the MDD 15.5 passive-aggressive threshold.
                'rearm_below' => 30.0,
                // MDD 15.5 addressed decay: resentment points taken off when the NPC says it.
                'addressed_relief' => 10.0,
                // Grievances named in one confrontation (dimension design: "a LIST of specific
                // grievances"; Serene: three keep it one conversation).
                'fuel_items' => 3,
            ],
            'self' => [
                'enabled' => true,
                // Dimension design thresholds (strictly above, resentment_self points).
                'baselines' => [
                    ['above' => 30.0, 'dimension' => 'comfort', 'offset' => -5.0],
                    ['above' => 70.0, 'dimension' => 'warmth', 'offset' => -10.0],
                ],
                'reflection_above' => 50.0,
                'crisis_above' => 90.0,
                // Serene: the self-reflection re-arms once resentment_self is back at or below this.
                'reflection_rearm_at' => 30.0,
                // Roadmap: processResentmentSelfDecay = -(base x (1 + maturity/100)) resentment_self
                // points per positive interaction while comfort is above comfort_above.
                'decay_base' => 0.5,
                'decay_comfort_above' => 20.0,
                // Serene: the same cadence as resentment's decay (GAMETS_RESENTMENT_COOLDOWN).
                'decay_cooldown_play_minutes' => 15.0,
                // Dimension design: confession (addressed decay) -10; forgiveness from the affected
                // party -15 has no eval tag yet (open question), so it maps nothing by default.
                // Eval tag => resentment_self points taken off (the exchange must be positive).
                'recovery_tags' => ['confiding' => 10.0],
                // People-pleaser buildup (prerequest), at most once per buildup_cooldown_play_minutes
                // of played time (Serene: the decay's cadence). When (a list pick):
                //   'swallowed'     the people-pleaser override turned a refusal into compliance
                //                   (evaluateAutonomyState 'swallowed'; autonomy design: "they comply
                //                   ... but the damage is internal");
                //   'uncomfortable' the autonomy score at or above buildup_uncomfortable_at (the
                //                   compliant threshold). It outruns the recovery path while kindness
                //                   has not yet lifted trust and respect back (e2e: Lynly trapped).
                'buildup_when' => 'swallowed',
                'buildup_uncomfortable_at' => 30.0,
                'buildup_cooldown_play_minutes' => 15.0,
            ],
            'guilt_bleed' => [
                'enabled' => true,
                // applyGuiltBleed's gate (resentment_self points) and the design's cap (comfort points).
                'above' => 30.0,
                'cap' => 15.0,
            ],
            // Felt text (feelings, never numbers). {NAME} NPC, {PLAYER} player, {FUEL} the
            // grievances as the NPC would name them, {HOW} the immature style's adverb.
            'felt_text' => [
                'confront_mature'   => "{NAME} has been carrying something and says it now, calmly and directly, in one conversation: {FUEL}. "
                    . "Not an attack and not a ledger of old scores; {NAME} wants {PLAYER} to know, and then lets it rest.",
                'confront_mixed'    => "{NAME} finally says what has been building up ({FUEL}); {NAME} means to say it evenly, but it comes out {HOW}.",
                'confront_immature' => "It all comes out at once, {HOW}: {NAME} has been stewing over {FUEL}, and it boils over at {PLAYER}, "
                    . "older hurts dragged in with it.",
                'reflection' => "{NAME} keeps turning over something they are ashamed of, alone; it sits between {NAME} and everyone. "
                    . "If {PLAYER} asks gently, it might finally come out.",
            ],
            // How the NPC names a grievance it raises (no numbers). Eval grievances use their
            // summary (sanitized) or their kind; these cover the ones the engine records.
            'fuel_phrases' => [
                'neglect'     => "being left alone for days on end",
                'unfulfilled' => "waiting on things from {PLAYER} that never came",
                'values'      => "{KIND}",
                'none'        => "all the small things that kept piling up",
            ],
        ];
    }

    /** The settings: stored config per section, text tables merged per entry. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('resentment_arc');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['confrontation', 'self', 'guilt_bleed', 'felt_text', 'fuel_phrases'] as $section) {
            $cfg[$section] = array_replace($defaults[$section], is_array($stored[$section] ?? null) ? $stored[$section] : []);
        }
        return $cfg;
    }

    public static function enabled(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return !empty($cfg['enabled']) && !empty(RelationshipDynamics::configValue('dimension_engine_enabled'));
    }

    /**
     * This arc's confrontation is the one voice for grievances (MDD 15.5): said at the NPC's own
     * attachment threshold, once, in her way, never by a people-pleaser. When it is off, the felt
     * text falls back to the standing grievances line at the flat threshold.
     */
    public static function voicesConfrontation(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return self::enabled($cfg) && !empty($cfg['confrontation']['enabled']);
    }

    /** Play gamets in $minutes of played time (the play clock's "real minutes of play"). */
    public static function playGamets(float $minutes): float
    {
        return $minutes * 60.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    }

    /**
     * $dynamics['_resentment_arc'], created when missing:
     *   confront  ['count' => said this episode, 'last_gamets' => game time of the last one said
     *             (or boundary opened), 'last_play' => play gamets then, 'pending' => null |
     *             ['mode' => mature|mixed|immature, 'style' => string, 'decided_gamets' => float]]
     *   self      ['baselines' => dimension => ['offset' => points, 'prior' => ?float],
     *             'reflect_armed' => bool, 'reflect_pending' => bool, 'last_decay_play' => ?float,
     *             'last_buildup_play' => ?float, 'confess_open' => bool (missing: false; the
     *             reflection was said and she has not confessed yet)]
     *   guilt     ['applied' => comfort points currently taken off (<= 0)]
     */
    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::STATE_KEY] ?? null) || intval($dynamics[self::STATE_KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::STATE_KEY] = [
                'v' => self::VERSION,
                'confront' => ['count' => 0, 'last_gamets' => null, 'last_play' => null, 'pending' => null],
                'self' => ['baselines' => [], 'reflect_armed' => true, 'reflect_pending' => false,
                           'last_decay_play' => null, 'last_buildup_play' => null],
                'guilt' => ['applied' => 0.0],
            ];
        }
        return $dynamics[self::STATE_KEY];
    }

    private static function x(array $dynamics, string $dim, float $default = 0.0): float
    {
        $x = $dynamics['dimensions'][$dim]['x'] ?? null;
        return is_numeric($x) ? floatval($x) : $default;
    }

    // =====================================================================
    // CONFRONTATION (MDD 15.5 at 50)
    // =====================================================================

    /**
     * Resentment points at which this NPC confronts: its attachment region's
     * confrontation_threshold (anxious confront early, avoidant late), else confrontation.at.
     */
    public static function confrontationThreshold(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $t = RelationshipDynamics::getAttachmentModifier($dynamics, 'confrontation_threshold');
        return is_numeric($t) ? floatval($t) : floatval($cfg['confrontation']['at']);
    }

    /**
     * A grievance_log entry toward the player not yet raised: never addressed, or grown since it
     * was (an absence that goes on is kept current in place: its 'gamets' moves past the
     * game time 'addressed' holds). An inward one (target resentment_self) is never raised.
     */
    public static function isOpenGrievance($g): bool
    {
        if (!is_array($g) || ($g['target'] ?? 'resentment') === 'resentment_self') return false;
        $addressed = $g['addressed'] ?? null;
        if ($addressed === null || $addressed === false) return true;
        return is_numeric($addressed) && floatval($g['gamets'] ?? 0) > floatval($addressed);
    }

    /** Open grievances toward the player (isOpenGrievance), logged after game time $since when given. */
    private static function openGrievances(array $dynamics, ?float $since = null): int
    {
        return count(array_filter((array) ($dynamics['dimensions']['resentment']['grievance_log'] ?? []),
            fn($g) => self::isOpenGrievance($g) && ($since === null || floatval($g['gamets'] ?? 0) > $since)));
    }

    /**
     * A boundary's own conversation is due this turn, so the confrontation waits (one
     * conversation at a time): either lane's boundary pending its statement or failed (its
     * step-back due), or the concern lane's grievance boundary watching its probation (it is
     * this lane's repetition; its failure is the next step, not a confrontation). Another
     * boundary's probation does not silence a first confrontation: the grievances are a
     * different conversation, and a confrontation is not a boundary.
     */
    private static function boundarySaying(array $dynamics): bool
    {
        $f = (string) ($dynamics[RelDynFulfillment::STATE_KEY]['boundary']['state'] ?? 'none');
        $cb = (array) ($dynamics[RelDynConcern::STATE_KEY]['boundary'] ?? []);
        $c = (string) ($cb['state'] ?? 'none');
        return in_array($f, ['pending', 'failed'], true) || in_array($c, ['pending', 'failed'], true)
            || ($c === 'probation' && ($cb['channel'] ?? null) === RelDynConcern::GRIEVANCE);
    }

    /**
     * Mark every open grievance toward the player addressed at game time $at (the grievance
     * boundary's statement carries them, as a confrontation does). Returns how many.
     */
    private static function markAddressed(array &$dynamics, float $at): int
    {
        $n = 0;
        foreach ((array) ($dynamics['dimensions']['resentment']['grievance_log'] ?? []) as $i => $g) {
            if (!self::isOpenGrievance($g)) continue;
            $dynamics['dimensions']['resentment']['grievance_log'][$i]['addressed'] = $at;
            $n++;
        }
        return $n;
    }

    /** Game time the concern lane's grievance boundary last ended (stepped back, blocked, resolved), or null. */
    private static function grievanceBoundaryEnded(array $dynamics): ?float
    {
        $b = (array) ($dynamics[RelDynConcern::STATE_KEY]['boundary'] ?? []);
        if (($b['state'] ?? 'none') !== 'none' || ($b['kind'] ?? null) !== RelDynConcern::GRIEVANCE_KIND) return null;
        foreach (['stepped_back_gamets', 'blocked_gamets', 'resolved_gamets'] as $k) {
            if (is_numeric($b[$k] ?? null)) return floatval($b[$k]);
        }
        return null;
    }

    /**
     * The prerequest's confrontation step for this NPC at game time $now (after the calendar,
     * fulfillment and concern steps). Queues the confrontation to be said to the player's face
     * (takeFeltLines), or for a mature NPC's repetition opens the values boundary. Returns
     * event names: confrontation_due | boundary_due | internalized | episode_over.
     */
    public static function tickConfrontation(string $npcName, array &$dynamics, float $now): array
    {
        $events = [];
        $cfg = self::config();
        $c = (array) $cfg['confrontation'];
        if (!self::enabled($cfg) || empty($c['enabled'])) return $events;
        $state = &self::state($dynamics);
        $cf = &$state['confront'];
        $r = self::x($dynamics, 'resentment');

        if ($r < floatval($c['rearm_below'])) {
            if (intval($cf['count']) > 0 || $cf['pending'] !== null) $events[] = 'episode_over';
            $cf['count'] = 0;
            $cf['pending'] = null;
            unset($cf, $state);
            return $events;
        }
        $threshold = self::confrontationThreshold($dynamics, $cfg);
        if ($cf['pending'] !== null || $r < $threshold) { unset($cf, $state); return $events; }
        if (RelationshipDynamics::isPeoplePleaser($dynamics)) {
            unset($cf, $state);
            return ['internalized'];   // the silent quadrant: suffers inward, never confronts
        }
        if (($dynamics['_walkaway_state'] ?? 'normal') !== 'normal' || self::boundarySaying($dynamics)) {
            unset($cf, $state);
            return $events;   // the walkaway, or a boundary's own conversation this turn, carries it
        }
        $play = RelationshipDynamics::getPlayGamets($dynamics);
        if ($cf['last_play'] !== null && $play - floatval($cf['last_play']) < self::playGamets(floatval($c['cooldown_play_minutes']))) {
            unset($cf, $state);
            return $events;
        }
        // A repeat needs something new since the last one (it happened again), and since the
        // grievance boundary's end: the grievance that failed its probation was answered by it
        if (intval($cf['count']) > 0 && self::openGrievances($dynamics, self::grievanceBoundaryEnded($dynamics)) === 0) {
            unset($cf, $state);
            return $events;
        }

        $expr = RelDynConcern::expression($dynamics, RelDynConcern::traitsOf($dynamics));
        if ($expr['path'] === 'mature' && intval($cf['count']) > 0
            && (RelDynFulfillment::boundaryActive($dynamics) || RelDynConcern::boundaryActive($dynamics))) {
            // Already said, and another lane's boundary watches its probation: no second boundary
            // beside it and no second conversation; the running boundary carries it (§9 / §14)
            unset($cf, $state);
            return $events;
        }
        if ($expr['path'] === 'mature' && intval($cf['count']) > 0) {
            unset($cf, $state);
            if (RelDynConcern::openGrievanceBoundary($npcName, $dynamics, $now)) {
                self::markAddressed($dynamics, $now);   // the boundary's statement carries them
                $cf = &$dynamics[self::STATE_KEY]['confront'];
                $cf['last_gamets'] = $now;
                $cf['last_play'] = $play;
                unset($cf);
                RelationshipDynamics::log("[RESENT] {$npcName}: wronged again after saying it calmly: the values boundary (resentment " . round($r, 2) . ')');
                return ['boundary_due'];
            }
            $cf = &$dynamics[self::STATE_KEY]['confront'];   // no step-back to make: said calmly again
        }
        $mode = $expr['path'] === 'mature' ? ($expr['band'] === 'mature' ? 'mature' : 'mixed') : 'immature';
        $cf['pending'] = ['mode' => $mode, 'style' => (string) $expr['style'], 'decided_gamets' => $now];
        unset($cf, $state);
        RelationshipDynamics::log("[RESENT] {$npcName}: confrontation due ({$mode}, resentment " . round($r, 2)
            . " at threshold " . round($threshold, 2) . ')');
        return ['confrontation_due'];
    }

    /**
     * The grievances the NPC raises, newest first, as it would name them (never numbers): open
     * grievance_log entries (isOpenGrievance; engine kinds through fuel_phrases, eval ones by
     * their sanitized summary, else their kind), then the dimensional memory's confrontation
     * fuel. Returns ['phrases' => string[], 'entries' => grievance_log indexes named].
     */
    public static function fuel(array $dynamics, string $npcName, string $playerName, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $max = max(1, intval($cfg['confrontation']['fuel_items']));
        $p = (array) $cfg['fuel_phrases'];
        $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerName];
        $out = ['phrases' => [], 'entries' => []];
        $add = function (?string $phrase, ?int $idx) use (&$out, $max): void {
            $phrase = $phrase === null ? null : trim(rtrim(trim($phrase), '.'));
            if ($phrase === null || $phrase === '' || preg_match('/\d/', $phrase) || count($out['phrases']) >= $max) return;
            foreach ($out['phrases'] as $have) {
                if (strcasecmp($have, $phrase) === 0) { if ($idx !== null) $out['entries'][] = $idx; return; }
            }
            $out['phrases'][] = $phrase;
            if ($idx !== null) $out['entries'][] = $idx;
        };
        $log = (array) ($dynamics['dimensions']['resentment']['grievance_log'] ?? []);
        for ($i = count($log) - 1; $i >= 0; $i--) {
            $g = $log[$i] ?? null;
            if (!self::isOpenGrievance($g)) continue;
            $kind = (string) ($g['kind'] ?? '');
            if (($g['tag'] ?? null) === 'neglect') {
                $add(strtr((string) ($kind === 'unfulfilled' ? $p['unfulfilled'] : $p['neglect']), $vars), $i);
            } elseif (str_starts_with($kind, 'values_conflict:')) {
                $add(strtr((string) $p['values'], $vars + ['{KIND}' => RelDynConcern::kindPhraseFor(substr($kind, 16), $npcName, $playerName)]), $i);
            } else {
                $summary = is_string($g['text'] ?? null) && $g['text'] !== $kind ? RelDynFelt::sanitizeReason((string) $g['text']) : null;
                $add($summary ?? ($kind !== '' ? str_replace('_', ' ', $kind) : null), $i);
            }
        }
        foreach (RelationshipDynamics::getConfrontationFuel($dynamics, $playerName) as $reason) {
            $add(RelDynFelt::sanitizeReason((string) $reason), null);
        }
        return $out;
    }

    // =====================================================================
    // RESENTMENT_SELF: thresholds, reflection, recovery, the people-pleaser buildup
    // =====================================================================

    /** resentment_self above self.crisis_above (a self-triggered isolation walkaway). */
    public static function selfCrisis(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return self::enabled($cfg) && !empty($cfg['self']['enabled'])
            && self::x($dynamics, 'resentment_self') > floatval($cfg['self']['crisis_above']);
    }

    /**
     * The thresholds' standing effects at the current resentment_self: each baseline row's
     * offset applied to that dimension's baseline while above, lifted exactly when not; the
     * self-reflection armed / queued. Returns event names (baseline:<dim>+|-, reflection).
     */
    public static function tickSelf(string $npcName, array &$dynamics): array
    {
        $events = [];
        $cfg = self::config();
        if (!self::enabled($cfg) || empty($cfg['self']['enabled'])) return $events;
        $rs = self::x($dynamics, 'resentment_self');
        $state = &self::state($dynamics);
        $applied = (array) $state['self']['baselines'];
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        foreach ((array) $cfg['self']['baselines'] as $row) {
            $dim = (string) ($row['dimension'] ?? '');
            if ($dim === '' || !isset($dynamics['dimensions'][$dim]) || !is_array($dynamics['dimensions'][$dim])) continue;
            $want = $rs > floatval($row['above'] ?? INF);
            $have = isset($applied[$dim]);
            if ($want && !$have) {
                $prior = $dynamics['dimensions'][$dim]['baseline'] ?? null;
                $base = is_numeric($prior) ? floatval($prior) : RelationshipDynamics::getTemperamentBaseline($temperament, $dim, $dynamics);
                $offset = floatval($row['offset'] ?? 0);
                $dynamics['dimensions'][$dim]['baseline'] = round($base + $offset, 4);
                $applied[$dim] = ['offset' => $offset, 'prior' => is_numeric($prior) ? floatval($prior) : null];
                $events[] = "baseline:{$dim}" . ($offset < 0 ? '-' : '+');
            } elseif (!$want && $have) {
                $offset = floatval($applied[$dim]['offset'] ?? 0);
                $prior = $applied[$dim]['prior'] ?? null;
                $now = $dynamics['dimensions'][$dim]['baseline'] ?? null;
                if ($prior === null && is_numeric($now)
                    && abs(floatval($now) - RelationshipDynamics::getTemperamentBaseline($temperament, $dim, $dynamics) - $offset) < 1e-6) {
                    unset($dynamics['dimensions'][$dim]['baseline']);   // back to the temperament's own
                } elseif (is_numeric($now)) {
                    $dynamics['dimensions'][$dim]['baseline'] = round(floatval($now) - $offset, 4);
                }
                unset($applied[$dim]);
                $events[] = "baseline:{$dim}0";
            }
        }
        $state['self']['baselines'] = $applied;

        if ($rs > floatval($cfg['self']['reflection_above']) && !empty($state['self']['reflect_armed'])) {
            $state['self']['reflect_armed'] = false;
            $state['self']['reflect_pending'] = true;
            $events[] = 'reflection';
        } elseif ($rs <= floatval($cfg['self']['reflection_rearm_at'])) {
            $state['self']['reflect_armed'] = true;
            $state['self']['reflect_pending'] = false;
            $state['self']['confess_open'] = false;   // worked through: nothing left to confess
        }
        unset($state);
        if ($events !== []) RelationshipDynamics::log("[RESENT-SELF] {$npcName}: " . implode(', ', $events) . ' (resentment_self ' . round($rs, 2) . ')');
        return $events;
    }

    /**
     * resentment_self's standing offset on $dim's baseline right now (a baselines row applied
     * by tickSelf; dimension points, 0 when none). Baseline drift reads the baseline without it
     * (RelationshipDynamics::processBaselineDrift): a state on who she is, not a new origin.
     */
    public static function baselineOffset(array $dynamics, string $dim): float
    {
        return floatval($dynamics[self::STATE_KEY]['self']['baselines'][$dim]['offset'] ?? 0.0);
    }

    /**
     * Take $points off resentment_self as is (recovery is not fought by the inverted rubber
     * band: the open issue's trap). Returns the points taken.
     */
    private static function relieveSelf(array &$dynamics, float $points): float
    {
        $before = self::x($dynamics, 'resentment_self');
        if ($before <= 0.0 || $points <= 0.0) return 0.0;
        $after = max(0.0, $before - $points);
        $dynamics['dimensions']['resentment_self']['x'] = round($after, 4);
        return $before - $after;
    }

    /**
     * The eval side of recovery for one applied contract item (positive exchanges only):
     *   - processResentmentSelfDecay: -(decay_base x (1 + maturity/100)) points while comfort
     *     is above decay_comfort_above, at most once per decay_cooldown_play_minutes of play;
     *   - recovery_tags (confession: the NPC opened up and it was met with care): their points,
     *     only while the confession is open (the self-reflection was said to the player and she
     *     has not confessed since), and once: the confession closes it.
     * Returns ['decay' => points, 'recovery' => points, 'tags' => string[]].
     */
    public static function onPositiveEval(string $npcName, array &$dynamics, array $tags): array
    {
        $out = ['decay' => 0.0, 'recovery' => 0.0, 'tags' => []];
        $cfg = self::config();
        if (!self::enabled($cfg) || empty($cfg['self']['enabled']) || self::x($dynamics, 'resentment_self') <= 0.0) return $out;
        $s = (array) $cfg['self'];
        $state = &self::state($dynamics);
        $play = RelationshipDynamics::getPlayGamets($dynamics);
        $last = $state['self']['last_decay_play'];
        if (self::x($dynamics, 'comfort', 50.0) > floatval($s['decay_comfort_above'])
            && ($last === null || $play - floatval($last) >= self::playGamets(floatval($s['decay_cooldown_play_minutes'])))) {
            $m = self::x($dynamics, 'maturity', 50.0);
            $out['decay'] = self::relieveSelf($dynamics, floatval($s['decay_base']) * (1.0 + $m / 100.0));
            $state['self']['last_decay_play'] = $play;
        }
        foreach ((array) $s['recovery_tags'] as $tag => $points) {
            if (empty($state['self']['confess_open']) || !in_array((string) $tag, $tags, true)) continue;
            $out['recovery'] += self::relieveSelf($dynamics, floatval($points));
            $out['tags'][] = (string) $tag;
            $state['self']['confess_open'] = false;   // told: the confession is made once
        }
        unset($state);
        if ($out['decay'] > 0 || $out['recovery'] > 0) {
            RelationshipDynamics::log("[RESENT-SELF] {$npcName}: recovery -" . round($out['decay'] + $out['recovery'], 3)
                . ($out['tags'] ? ' (' . implode(',', $out['tags']) . ')' : '') . ', now ' . round(self::x($dynamics, 'resentment_self'), 2));
        }
        return $out;
    }

    /**
     * The people-pleaser's internalization at the prerequest ($eval: evaluateAutonomyState()):
     * resentment_self_buildup raw points through applyDelta, only when self.buildup_when holds
     * ('swallowed': the override swallowed a refusal; 'uncomfortable': autonomy score >=
     * buildup_uncomfortable_at) and at most once per buildup_cooldown_play_minutes of play.
     * Returns the resentment_self points applied.
     */
    public static function peoplePleaserBuildup(string $npcName, array &$dynamics, array $eval): float
    {
        if (empty($eval['people_pleaser']) || floatval($eval['resentment_self_buildup'] ?? 0) <= 0) return 0.0;
        $cfg = self::config();
        $s = (array) $cfg['self'];
        $when = (string) ($s['buildup_when'] ?? 'swallowed');
        if ($when === 'uncomfortable') {
            if (floatval($eval['autonomy_score'] ?? 0) < floatval($s['buildup_uncomfortable_at'])) return 0.0;
        } elseif ($when === 'swallowed') {
            if (empty($eval['swallowed'])) return 0.0;
        } else {
            error_log("[RelDyn-RESENT-SELF] {$npcName}: unknown resentment_arc.self.buildup_when '{$when}' (swallowed | uncomfortable): no buildup");
            return 0.0;
        }
        $state = &self::state($dynamics);
        $play = RelationshipDynamics::getPlayGamets($dynamics);
        $last = $state['self']['last_buildup_play'];
        if ($last !== null && $play - floatval($last) < self::playGamets(floatval($s['buildup_cooldown_play_minutes']))) {
            unset($state);
            return 0.0;
        }
        $state['self']['last_buildup_play'] = $play;
        unset($state);
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        $applied = RelationshipDynamics::applyDelta('resentment_self', $dynamics, floatval($eval['resentment_self_buildup']), $temperament);
        RelationshipDynamics::log("[RESENT-SELF] {$npcName}: people-pleaser says nothing (autonomy " . round(floatval($eval['autonomy_score']), 1)
            . "): resentment_self +" . round($applied, 3));
        return $applied;
    }

    // =====================================================================
    // GUILT BLEED (cross-bond dimensional bleed)
    // =====================================================================

    /** Bleed target (comfort points, <= 0): -min(cap, resentment_self x bond / 100) above the gate, else 0. */
    public static function guiltBleedTarget(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $g = (array) $cfg['guilt_bleed'];
        $rs = self::x($dynamics, 'resentment_self');
        if (!self::enabled($cfg) || empty($g['enabled']) || $rs <= floatval($g['above'])) return 0.0;
        $bond = max(0.0, min(100.0, RelationshipDynamics::getCoreAffinity($dynamics)));
        return -min(floatval($g['cap']), $rs * $bond / 100.0);
    }

    /**
     * Move the standing guilt offset on comfort to its target (guiltBleedTarget): comfort x
     * changes by the difference, clamped to 0..100, and exactly what moved is recorded, so the
     * offset lifts without residue. Returns the comfort points moved this tick.
     */
    public static function tickGuiltBleed(string $npcName, array &$dynamics): float
    {
        if (!is_numeric($dynamics['dimensions']['comfort']['x'] ?? null)) return 0.0;
        $target = self::guiltBleedTarget($dynamics);
        $state = &self::state($dynamics);
        $applied = floatval($state['guilt']['applied'] ?? 0.0);
        $delta = $target - $applied;
        if (abs($delta) < 1e-6) { unset($state); return 0.0; }
        $x = floatval($dynamics['dimensions']['comfort']['x']);
        $new = max(0.0, min(100.0, $x + $delta));
        $moved = $new - $x;
        $dynamics['dimensions']['comfort']['x'] = round($new, 4);
        $state['guilt']['applied'] = round($applied + $moved, 6);
        if ($target === 0.0 && abs($state['guilt']['applied']) < 1e-4) $state['guilt']['applied'] = 0.0;
        unset($state);
        RelationshipDynamics::enforceResentmentWithdrawal($dynamics);
        RelationshipDynamics::log("[GUILT-BLEED] {$npcName}: comfort " . ($moved >= 0 ? '+' : '') . round($moved, 3)
            . ' (guilt toward the player, target ' . round($target, 3) . ')');
        return $moved;
    }

    // =====================================================================
    // PREREQUEST STEP
    // =====================================================================

    /** The prerequest's step (before the autonomy evaluation): thresholds, guilt bleed, confrontation. */
    public static function onPrerequest(string $npcName, array &$dynamics, float $now): array
    {
        if (!self::enabled()) return [];
        $events = self::tickSelf($npcName, $dynamics);
        if (abs(self::tickGuiltBleed($npcName, $dynamics)) > 0) $events[] = 'guilt_bleed';
        return array_merge($events, self::tickConfrontation($npcName, $dynamics, $now));
    }

    // =====================================================================
    // FELT TEXT (feelings, never numbers)
    // =====================================================================

    /**
     * This turn's lines for RelDynFelt, consumed only when the player is speaking to this NPC
     * ($playerAddressed): the confrontation (its relief applied as it is said) and the
     * self-reflection. Lines: ['key', 'lane', 'salience', 'must', 'intense', 'text'].
     *
     * @return array ['lines' => list, 'changed' => bool, 'relief' => resentment points taken off,
     *               'named' => the grievances the confrontation said (other lines leave them out)]
     */
    public static function takeFeltLines(array &$dynamics, string $npcName, string $playerName, float $now, bool $playerAddressed = true): array
    {
        $out = ['lines' => [], 'changed' => false, 'relief' => 0.0, 'named' => []];
        $cfg = self::config();
        if (!self::enabled($cfg) || !$playerAddressed || !is_array($dynamics[self::STATE_KEY] ?? null)) return $out;
        $t = (array) $cfg['felt_text'];
        $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerName];
        $state = &self::state($dynamics);

        $pending = $state['confront']['pending'];
        if (is_array($pending)) {
            $mode = (string) ($pending['mode'] ?? 'mature');
            $fuel = self::fuel($dynamics, $npcName, $playerName, $cfg);
            $phrases = $fuel['phrases'] !== [] ? $fuel['phrases'] : [strtr((string) $cfg['fuel_phrases']['none'], $vars)];
            $sp = (array) (((array) RelDynConcern::config()['style_phrases'])[(string) ($pending['style'] ?? '')] ?? []);
            $text = strtr((string) ($t["confront_{$mode}"] ?? $t['confront_mature']),
                $vars + ['{FUEL}' => implode('; ', $phrases), '{HOW}' => (string) ($sp['how'] ?? 'sharper than meant')]);
            unset($state);
            // saying it: the MDD 15.5 addressed decay; only a calm (mature) one also resolves: meant
            // evenly but coming out in its style (mixed) is not calm, and a blow-up is not either
            $out['relief'] = -RelationshipDynamics::processResentmentConfrontation($dynamics, null, $mode === 'mature', $now);
            $state = &self::state($dynamics);
            $state['confront']['pending'] = null;
            $state['confront']['count'] = intval($state['confront']['count']) + 1;
            $state['confront']['last_gamets'] = $now;
            $state['confront']['last_play'] = RelationshipDynamics::getPlayGamets($dynamics);
            $out['lines'][] = ['key' => 'confront', 'lane' => 'turn', 'salience' => 1.0, 'must' => true,
                'intense' => $mode === 'immature', 'text' => $text];
            $out['changed'] = true;
            $out['named'] = $fuel['phrases'];
            RelationshipDynamics::log("[RESENT] {$npcName}: confrontation said ({$mode}, " . count($phrases) . " grievance(s)), resentment -"
                . round($out['relief'], 2));
        }
        if (!empty($state['self']['reflect_pending'])) {
            $state['self']['reflect_pending'] = false;
            $state['self']['confess_open'] = true;   // said to the player: what she tells next is the confession
            $out['lines'][] = ['key' => 'reflection', 'lane' => 'turn', 'salience' => 0.9, 'must' => true, 'intense' => false,
                'text' => strtr((string) $t['reflection'], $vars)];
            $out['changed'] = true;
        }
        unset($state);
        return $out;
    }

    // =====================================================================
    // JEV (numbers are fine here, never for the LLM)
    // =====================================================================

    /** Numbers for Jev: the confrontation, resentment_self's standing effects, guilt bleed, recruitment. */
    public static function jev(array $dynamics): array
    {
        $cfg = self::config();
        $s = is_array($dynamics[self::STATE_KEY] ?? null) ? $dynamics[self::STATE_KEY] : [];
        $cf = (array) ($s['confront'] ?? []);
        $baselines = [];
        foreach ((array) ($s['self']['baselines'] ?? []) as $dim => $b) $baselines[(string) $dim] = round(floatval($b['offset'] ?? 0), 2);
        return [
            'confrontation_threshold' => round(self::confrontationThreshold($dynamics, $cfg), 2),
            'confrontations' => intval($cf['count'] ?? 0),
            'confrontation_pending' => is_array($cf['pending'] ?? null) ? (string) ($cf['pending']['mode'] ?? 'mature') : null,
            'self_baseline_offsets' => $baselines,
            'self_crisis' => self::selfCrisis($dynamics, $cfg),
            'guilt_bleed' => round(floatval($s['guilt']['applied'] ?? 0.0), 2),
            'reject_recruitment' => !empty($dynamics['_reject_recruitment']),
        ];
    }
}
