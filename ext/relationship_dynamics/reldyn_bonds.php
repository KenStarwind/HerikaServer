<?php
/**
 * Relationship Dynamics — extended bond kinds: committed, conflicted, sworn; the breakup fork; the infidelity loop
 * (roadmap relationship-types-extended; pipeline Stage 2 "new relationship types", MDD 8 / 6.5).
 *
 * Ken, 2026-10-01 (decisions §24): "extended relationship types (conflicted, committed, sworn, breakup fork, infidelity loop)",
 * built as direction, not law: dynamic, scaled by who the NPC is, no hard permanent gate, no one ever fully immune to a feeling.
 *
 * CORE OWNS relationships.Player.type. Core's UI knows the 32 types of lib/relationship_manager.php TYPES; 'committed',
 * 'conflicted' and 'sworn' are not among them and are never written there. They are RelDyn's own state ($dynamics['_bonds']),
 * mapped onto core's types and RelDyn's bond-type tables like this:
 *
 *   committed   core 'romantic' (unchanged). The formal rung of a romance: held for a while (the longer for whoever commits
 *               slowly: RelDynExclusivity::disposition, the preference, the attachment), with the exclusivity pull at its
 *               'taken' band, trust and affinity standing. Beyond a crush, not a title: the infidelity weighs full against it
 *               and an ending costs more. In RelDyn's per-type tables it is the bonded row ('bonded' = core romantic), so
 *               nothing keyed by type loses a couple.
 *   sworn       an oath: core's own 'fanatical' / 'servant' (blind loyalty, serving), a faction that binds (housecarl, while
 *               in the party), or the editor. Duty at any affinity: while the oath holds the autonomy state is capped at
 *               'resistant' (RelationshipDynamics::evaluateAutonomyState), whatever the NPC feels; the oath STRAINS with
 *               lost respect (the sworn floor gate: absent is allowed, incompetent is not) and a betrayal, and breaks at 1:
 *               forsworn, the cap gone. It mends when respect comes back. RelDyn's 'sworn' bond type (trust x2.5 ...) for
 *               an NPC whose core type carries no romance.
 *   conflicted  the soft end of a romance: core 'ex' + RelDyn's own state. RelDyn bond type 'conflicted' (warmth and passion
 *               stay alive, trust and comfort cooler); the governor reads it as a crush (passion can burn, jealousy is
 *               active), not as the hostile row core's 'ex' maps to. It has a way back (REKINDLE) and a way to harden.
 *
 * THE BREAKUP FORK. A romance ends in three ways, chosen by who the NPC is and how it ended, never at random and never by a
 * flag: scores (0..1) from the ill will of the end (resentment and the cause: standards, neglect, pursuit, someone else), the
 * goodwill left (what is not ill will, x trust), the longing (passion and affinity that remain), the NPC's maturity and
 * attachment (anxious and fearful cling; avoidant cuts clean):
 *   ex          the hard end: cold, closed, the governor's hostile row; ill will wins. Core 'ex'.
 *   conflicted  they cannot quite let go: longing wins over a goodwill that is not enough to be friends. Core 'ex' + the soft
 *               state above.
 *   friends     the romance is over and the friendship is not: goodwill and maturity win over a longing that has cooled.
 *               Core 'platonic' (the same step the boundary lane's calm step-back takes).
 * It runs when a romance is walked out of ('deserve' and 'affinity' walkaways, RelationshipDynamics::initiateWalkaway ->
 * onWalkaway), when it is severed (the player followed the NPC who left: severBond -> onSever), when the NPC leaves it for
 * someone else or confesses (the infidelity loop), and when core's type is moved to 'ex' by core itself (observed). The core
 * write goes through RelationshipDynamics::changeCoreRelationshipType (its advisory lock, relationships_locked respected);
 * a refused write leaves the romance as it is and says so.
 *
 * REKINDLE (conflicted). Positive exchanges that mean something (quality time, reassurance, an apology, forgiveness, touch) build a progress
 * 0..1, scaled by the end's ill will, the NPC's attachment, and capped per game day (spamming warmth is not love); a setback
 * takes progress back. Time does nothing: contact does. At 1, with resentment low enough and trust standing, core goes 'ex' ->
 * 'crush' and the normal romance ladder carries on from there (a new beginning, not as if nothing happened). Resentment past
 * the harden line makes it an ex. A hard ex thaws toward friends the same way, slower.
 *
 * THE INFIDELITY LOOP (natural exclusivity §17). A romance's exclusivity pull is cut by low fulfillment and long neglect
 * (RelDynExclusivity::pull: unweakened vs weakened). The cut, less who the NPC is (restraint: the exclusivity disposition,
 * the preference's expectation of fidelity), with the pull of the best suitor in the interest ledger, is a pressure on the game
 * calendar (up and down; contact and fulfillment bring it down). Its stages: drifting (listens to attention), seeking (answers
 * a suitor's moves, passion toward the player fades), strayed (has crossed a line, held for a while with a suitor of real
 * interest). A loyal NPC needs more of everything and a longer neglect; none is immune. Strayed, the NPC carries it by who
 * they are (guilt, justification, concealment) and resolves it: the mature and loyal confess, the avoidant leave, the fearful keep
 * both for a long time. The fade of passion closes the loop (a weaker passion is a weaker pull, a larger cut). An NPC whose
 * preference does not expect fidelity (polyamorous, uncommitted) is open, not unfaithful: nothing ends. Consistent with the
 * jealousy lane (a jealous NPC is invested and strays less) and the cascade (a hard ending the player caused ripples to the
 * NPC's circle through the affinity it costs).
 *
 * Units: shares, pressures, strengths 0..1 (unitless); dimension points 0..100; core affinity -100..100; passion points;
 * game hours / days on the game calendar (raw gamets, RelationshipDynamics::GAMETS_PER_DAY). No wall clock.
 * State: $dynamics['_bonds'] (state()).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynBonds
{
    const KEY = '_bonds';
    const VERSION = 1;

    const COMMITTED = 'committed';
    const CONFLICTED = 'conflicted';
    const SWORN = 'sworn';
    const KINDS = [self::COMMITTED, self::CONFLICTED, self::SWORN];

    const FORK_EX = 'ex';
    const FORK_CONFLICTED = 'conflicted';
    const FORK_FRIENDS = 'friends';
    /** In the order a tie is broken (the hardest first). */
    const FORKS = [self::FORK_EX, self::FORK_CONFLICTED, self::FORK_FRIENDS];

    /** The core type each fork leaves the bond at. */
    const FORK_CORE_TYPE = [self::FORK_EX => 'ex', self::FORK_CONFLICTED => 'ex', self::FORK_FRIENDS => 'platonic'];

    const STAGES = ['drifting', 'seeking', 'strayed'];

    /** Test seam: callable(string $npc, string $to, string $reason, ?string $from): bool replaces the core write. */
    public static $coreWriter = null;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'bonds' (nested tables merge per entry). Serene's starting values for Ken's §24 ruling. */
    public static function configDefaults(): array
    {
        return [
            // Off: no kinds, no fork, no loop; the type tables read as before (conflicted / sworn never override a type).
            'enabled' => true,

            // --- committed ---
            'committed' => [
                'enabled' => true,
                // game days a romance (core rung 2) must have held before it is formal, at an exclusivity disposition of 1; the
                // disposition divides it (never below disposition_min), so a polyamorous or uncommitted NPC takes far longer
                'base_game_days' => 7.0, 'disposition_min' => 0.15,
                // and: the exclusivity pull (its taken band), trust, core affinity
                'pull_min' => 0.45, 'trust_min' => 50.0, 'aff_min' => 40.0,
                // the weight of an infidelity against a romance that is not (yet) formal
                'informal_weight' => 0.5,
            ],

            // --- sworn ---
            'oath' => [
                'enabled' => true,
                // core Player.type values that are the oath itself
                'core_types' => ['fanatical', 'servant'],
                // factions (editor ids) that bind, and the NPC must be in the party for it to be the player they are sworn to
                'factions' => ['FavorJarlsMakeHousecarlsFaction'], 'faction_needs_party' => true, 'check_game_days' => 1.0,
                // strain 0..1: respect (points) from -> full, plus betrayals; a positive exchange takes `repair` off the betrayals
                'respect_from' => 40.0, 'respect_full' => 5.0,
                'betrayal_tags' => ['betrayal'], 'betrayal_strain' => 0.5, 'repair' => 0.05,
                // the oath breaks at this strain; it mends when respect is back and the strain below
                'break_at' => 1.0, 'renew_respect' => 60.0, 'renew_strain_below' => 0.3,
            ],

            // --- the breakup fork ---
            'breakup' => [
                'enabled' => true,
                // how hard an ending is by its cause (0..1); a cause not listed is default_cause
                'causes' => ['standards' => 0.35, 'affinity' => 0.5, 'jealousy' => 0.55, 'resentment' => 0.65, 'neglect' => 0.35,
                             'ick_comfort' => 0.7, 'autonomy' => 0.5, 'shame' => 0.3, 'pursued' => 0.9, 'infidelity_confessed' => 0.3,
                             'left_for_other' => 0.9, 'boundary' => 0.1, 'core' => 0.45, 'player' => 0.2],
                'default_cause' => 0.5,
                // longing = the mean of passion / passion_full and (core affinity above 0) / aff_full, each clamped 0..1
                'longing' => ['passion_full' => 60.0, 'aff_full' => 80.0],
                // goodwill = (1 - ill will) x (trust_floor + (1 - trust_floor) x trust)
                'trust_floor' => 0.4,
                // maturity weight: RelDynConcern::expression w. The clinging attachment corners (cannot let go):
                'cling' => ['secure' => 0.0, 'anxious' => 1.0, 'avoidant' => 0.0, 'toxic' => 0.8],
                'avoid' => ['secure' => 0.0, 'anxious' => 0.0, 'avoidant' => 1.0, 'toxic' => 0.3],
                // What an ending leaves behind. passion_keep: the share of passion kept. The rest are points x ill will
                // (0..1) x committed_mult when the romance was formal: trust / comfort (points, negative), resentment
                // (points), affinity (core points, negative). An ending the NPC did not cause (cause weight under
                // `blameless_below`) leaves no resentment.
                'aftermath' => [
                    self::FORK_EX         => ['passion_keep' => 0.5, 'trust' => -8.0, 'comfort' => -6.0, 'resentment' => 20.0, 'affinity' => -20.0],
                    self::FORK_CONFLICTED => ['passion_keep' => 1.0, 'trust' => -4.0, 'comfort' => -3.0, 'resentment' => 6.0, 'affinity' => -4.0],
                    self::FORK_FRIENDS    => ['passion_keep' => 0.7, 'trust' => 0.0, 'comfort' => 0.0, 'resentment' => 0.0, 'affinity' => -5.0],
                ],
                'committed_mult' => 1.25,
                // the NPC's circle hears of a hard ending the player's conduct caused (RelationshipDynamics::propagateAffinityChange)
                'cascade' => true, 'cascade_causes' => ['standards', 'resentment', 'ick_comfort', 'pursued', 'neglect', 'jealousy'],
            ],

            // --- rekindle (conflicted) and thaw (ex) ---
            'rekindle' => [
                'enabled' => true,
                // tag => weight of an exchange that carries it (the strongest counts); an unrelated positive exchange counts at `positive`
                'tags' => ['quality_time' => 1.0, 'reassurance' => 1.0, 'apology' => 1.5, 'forgiveness' => 1.5, 'touch' => 0.6, 'gift' => 0.3],
                'positive' => 0.4, 'min_significance' => 0.3,
                // progress per exchange = step x significance x tag weight x (1 - ill will) x attachment; at most daily_cap a game day
                'step' => 0.15, 'daily_cap' => 0.3, 'setback' => 1.5,
                'attachment' => ['secure' => 1.0, 'anxious' => 1.3, 'avoidant' => 0.7, 'toxic' => 1.0],
                // at progress 1: resentment at most / trust at least; core goes `to`
                'resentment_max' => 35.0, 'trust_min' => 40.0, 'to' => 'crush',
                // resentment from which a conflicted bond hardens into an ex
                'harden_resentment' => 60.0,
                // an ex thaws to friends: slower, and only for one who can (maturity weight)
                'thaw' => ['step' => 0.08, 'resentment_max' => 30.0, 'trust_min' => 40.0, 'maturity_w_min' => 0.4, 'to' => 'platonic'],
                // the exclusivity pull's release while conflicted (0 = none, 1 = as if still together)
                'conflicted_release' => 0.4,
            ],

            // --- the infidelity loop ---
            'infidelity' => [
                'enabled' => true,
                // a romance (core rung) at least. The opening is how little holds the NPC: what neglect and low fulfillment cut from the pull
                // (the cut), widened by how little of a pull there was to begin with (hollow = 1 - the pull unweakened, x hollow_weight, once
                // the cut is under way): a fading passion makes a hollower pull, which closes the loop.
                'min_rung' => 2, 'hollow_weight' => 0.5, 'max_steps' => 60,
                // what the relationship preference expects of fidelity (1 = all of it); under open_below the NPC is open, not unfaithful
                'expectation' => ['monogamous' => 1.0, 'demisexual' => 1.0, 'uncommitted' => 0.45, 'polyamorous' => 0.1, 'default' => 0.9],
                'open_below' => 0.3,
                // restraint = exclusivity disposition / disposition_range (0..1)
                'disposition_range' => 1.6,
                // temptation: the best suitor's interest (points) from -> full; the pressure's target = cut x (tempt_base + (1 - tempt_base) x temptation)
                'tempt' => ['from' => 10.0, 'full' => 60.0], 'tempt_base' => 0.35,
                // a jealous NPC is invested: the target x (1 - jealous_damp x jealousy share)
                'jealous_damp' => 0.3, 'jealous_full' => 70.0,
                // the pressure follows its target on the game calendar
                'rates' => ['rise_per_game_hour' => 0.02, 'fall_per_game_hour' => 0.03, 'max_step_game_hours' => 72.0],
                // the stage lines (pressure) and how far restraint lifts them: line + shift x restraint
                'stages' => ['drifting' => 0.25, 'seeking' => 0.45, 'strayed' => 0.7],
                'restraint_shift' => ['drifting' => 0.15, 'seeking' => 0.2, 'strayed' => 0.25],
                // strayed also needs a suitor of real interest (points), held for this long (game hours)
                'strayed_interest' => 40.0, 'strayed_hold_game_hours' => 12.0, 'min_interest' => 15.0,
                // passion toward the player fades while it lasts (points a game day x the stage's factor), down to the floor
                'passion_fade' => ['per_game_day' => 1.5, 'stage' => ['drifting' => 0.5, 'seeking' => 1.0, 'strayed' => 2.0], 'floor' => 12.0],
                // strayed: the weight it carries by who the NPC is (restraint x maturity): guilt toward self, ill will toward the player
                'guilt' => ['resentment_self' => 10.0, 'resentment' => 8.0],
                // how it ends, game days from the line crossed: the mature and loyal confess, the anxious confess late, the avoidant
                // and the rest leave, the fearful keep both for long (then leave). Mature = maturity weight at least, loyal = restraint at least.
                'resolve' => ['mature_w' => 0.6, 'loyal' => 0.5, 'confess_mature' => 1.0, 'confess_anxious' => 3.0, 'leave_avoidant' => 4.0,
                              'leave' => 3.0, 'both_fearful' => 21.0, 'anxious_at' => 0.5, 'avoidant_at' => 0.5, 'fearful_at' => 0.35],
            ],

            // --- felt text (feelings, never numbers; {NAME} the NPC, {PLAYER} the player, {SUITOR}, {BECAUSE}; no pronoun of their own) ---
            'because' => [
                'standards' => '{NAME} deserves better than this',
                'neglect' => '{NAME} has been alone in this for too long',
                'affinity' => 'the feeling that held it together has gone',
                'jealousy' => 'the jealousy has worn it through',
                'resentment' => 'too much has gone unsaid and unforgiven',
                'ick_comfort' => 'there was no room left to breathe',
                'autonomy' => 'it stopped being a choice',
                'shame' => '{NAME} could not face it any longer',
                'pursued' => '{PLAYER} would not let {NAME} go',
                'infidelity_confessed' => '{NAME} has let someone else in',
                'left_for_other' => '{NAME} is with someone else now',
                'boundary' => 'what {NAME} asked for did not change',
                'core' => 'it is over',
                'player' => 'it is over',
                'default' => 'it is not working',
            ],
            'felt_text' => [
                'committed' => "{NAME} has settled into this with {PLAYER}: it is no longer something {NAME} is trying out. {NAME} thinks of the two of them as a couple, and acts like it.",
                'breakup_ex_mature' => "{NAME} tells {PLAYER} plainly that it is over, and means it: {BECAUSE}. There is no cruelty in how it is said, and no opening left to argue through.",
                'breakup_ex_raw' => "{NAME} ends it with {PLAYER}, cold and final: {BECAUSE}. Whatever there was, {NAME} wants it gone.",
                'breakup_conflicted_mature' => "{NAME} ends it with {PLAYER}, and it hurts to say: {BECAUSE}. It is not finished in {NAME}'s heart, and {NAME} does not pretend it is.",
                'breakup_conflicted_raw' => "{NAME} ends it with {PLAYER}, badly: {BECAUSE}. {NAME} is angry and wrecked in the same breath, and cannot quite let go.",
                'breakup_friends_mature' => "{NAME} tells {PLAYER} that the romance is over and the friendship is not: {BECAUSE}. It is said kindly, and {NAME} means to keep {PLAYER} in {NAME}'s life.",
                'breakup_friends_raw' => "{NAME} tells {PLAYER} it is not going to be a romance any more: {BECAUSE}. {NAME} would rather stay friends than lose {PLAYER} altogether.",
                'rekindled' => "{NAME} lets the wall come down a little: willing to try again with {PLAYER}, slowly, and not as if nothing happened.",
                'hardened' => "{NAME} stops leaving the door open: whatever feeling was left for {PLAYER} has turned cold.",
                'thawed' => "{NAME} has stopped being angry at {PLAYER}; what is left is a friendship worth having.",
                'oath_broken' => "{NAME} no longer holds to the oath sworn to {PLAYER}: the word was kept as long as it could be, and it can not be kept now.",
                'oath_renewed' => "{NAME} takes up the oath to {PLAYER} again, quietly: respect is back, and the word is worth keeping.",
                'sworn_cold' => "Sworn to {PLAYER}, and {NAME} keeps the oath whatever {NAME} feels: serves plainly, does what duty asks, and does not pretend to warmth.",
                'sworn_warm' => "Sworn to {PLAYER}, and glad of it: {NAME} keeps the oath as a point of pride and means every word of it.",
                'conflicted' => "{NAME} and {PLAYER} are not together, and neither has really let go: warmth that catches and then a guarded step back. {NAME} could be won back slowly, and not by being pushed.",
                'ex' => "{NAME} and {PLAYER} were together once and it is over: civil, closed, and not interested in being talked round.",
                'drifting' => "{NAME} has felt far from {PLAYER} for a while, and notices who notices {NAME} more than {NAME} used to.",
                'seeking' => "{NAME} has begun to look elsewhere for what {PLAYER} has not been giving, and is more open to {SUITOR}'s attention than {NAME} would have been.",
                'strayed_guilt' => "{NAME} has grown close to {SUITOR} in a way {NAME} has not told {PLAYER}, and carries it badly: over-careful, too attentive, unable to meet {PLAYER}'s eyes for long.",
                'strayed_justified' => "{NAME} has grown close to {SUITOR} and has stopped feeling it needs explaining: {PLAYER} was not there, and {NAME} says so, sharply, when it comes up.",
                'strayed_conceal' => "{NAME} has grown close to {SUITOR} and says nothing; {NAME} is pleasant, even, and further away than {PLAYER} can quite put a finger on.",
                'strayed_open' => "{NAME} is close to {SUITOR} as well, and sees no conflict in it; {PLAYER} is not the only one, and {NAME} is not hiding that.",
                'suitor_seeking' => "{SUITOR}'s attention is welcome, and {NAME} does not turn it aside; {PLAYER} has been far away, and it is easy to enjoy being wanted.",
                'suitor_strayed' => "{SUITOR} and {NAME} are closer than {NAME} lets on, and the warmth between them is hard to hide.",
            ],
            'salience' => ['committed' => 0.8, 'breakup' => 1.0, 'rekindled' => 0.9, 'hardened' => 0.9, 'thawed' => 0.8, 'oath_broken' => 1.0, 'oath_renewed' => 0.9,
                           'sworn' => 0.6, 'conflicted' => 0.7, 'ex' => 0.5, 'drifting' => 0.5, 'seeking' => 0.7, 'strayed' => 0.85],
        ];
    }

    private const MERGED_TABLES = ['committed', 'oath', 'breakup', 'rekindle', 'infidelity', 'because', 'felt_text', 'salience'];

    /** The bonds settings: stored config per setting, nested tables merged per entry. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('bonds');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (self::MERGED_TABLES as $t) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
        }
        foreach (['breakup' => ['causes', 'longing', 'cling', 'avoid', 'aftermath'], 'rekindle' => ['tags', 'attachment', 'thaw'],
                  'infidelity' => ['expectation', 'tempt', 'rates', 'stages', 'restraint_shift', 'passion_fade', 'guilt', 'resolve']] as $t => $subs) {
            foreach ($subs as $s) {
                $cfg[$t][$s] = array_replace((array) $defaults[$t][$s], is_array($stored[$t][$s] ?? null) ? $stored[$t][$s] : []);
            }
        }
        foreach (array_keys((array) $cfg['breakup']['aftermath']) as $fork) {
            $cfg['breakup']['aftermath'][$fork] = array_replace((array) ($defaults['breakup']['aftermath'][$fork] ?? []),
                is_array($stored['breakup']['aftermath'][$fork] ?? null) ? $stored['breakup']['aftermath'][$fork] : []);
        }
        $cfg['infidelity']['passion_fade']['stage'] = array_replace((array) $defaults['infidelity']['passion_fade']['stage'],
            is_array($stored['infidelity']['passion_fade']['stage'] ?? null) ? $stored['infidelity']['passion_fade']['stage'] : []);
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

    private static function day(): float
    {
        return (float) RelationshipDynamics::GAMETS_PER_DAY;
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private static function between(float $v, float $from, float $full): float
    {
        return $full > $from ? self::clamp01(($v - $from) / ($full - $from)) : ($v >= $full ? 1.0 : 0.0);
    }

    private static function dim(array $dynamics, string $dim, float $default): float
    {
        $x = $dynamics['dimensions'][$dim]['x'] ?? null;
        return is_numeric($x) ? floatval($x) : $default;
    }

    private static function coreType(array $dynamics): string
    {
        return strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
    }

    /** Core's romance rung of the bond (RelDynRomance::rung: 0 none, 1 crush / admirer / obsessed, 2 romantic). */
    public static function rung(array $dynamics): int
    {
        return RelDynRomance::rung(self::coreType($dynamics));
    }

    // =====================================================================
    // STATE
    // =====================================================================

    /**
     * $dynamics['_bonds']: v, kind (committed | conflicted: the stored claim, validated against core's type by kind()),
     * core_seen (core's type as RelDyn last looked), romantic_since / committed_since (raw gamets), oath {active, source, since,
     * strain, betrayal, broken, checked_day}, breakup {at, fork, cause, by, hardness, shares, from, to, status, rekindle, thaw,
     * day {day, pts}}, infidelity {pressure, stage, with, gamets, strayed_since, line_crossed, style}, say (one-shots), last.
     */
    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::KEY] ?? null) || intval($dynamics[self::KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::KEY] = ['v' => self::VERSION, 'say' => []];
        }
        return $dynamics[self::KEY];
    }

    private static function stored(array $dynamics): array
    {
        return is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
    }

    /** Forget the stored claims, the oath, the ending's record and the infidelity loop (the editor's reset). Core's type is not touched. */
    public static function reset(array &$dynamics): void
    {
        unset($dynamics[self::KEY]);
    }

    /** Set or clear the oath by hand (the editor): an oath the NPC took (source 'editor'), or none. A cleared oath also forgets a broken one. */
    public static function setOath(array &$dynamics, bool $on, ?float $now = null): void
    {
        $state = &self::state($dynamics);
        if ($on) {
            $state['oath'] = ['active' => true, 'source' => 'editor', 'since' => $now ?? RelationshipDynamics::currentGamets(), 'strain' => 0.0, 'betrayal' => 0.0];
        } else {
            unset($state['oath']);
        }
        unset($state);
    }

    // =====================================================================
    // THE KINDS (pure)
    // =====================================================================

    /** Is the oath in force: an oath state or a core type that is the oath, not broken. */
    public static function oathActive(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled']) || empty($cfg['oath']['enabled'])) return false;
        $s = self::stored($dynamics);
        if (!empty($s['oath']['broken'])) return false;
        return !empty($s['oath']['active']) || in_array(self::coreType($dynamics), array_map('strtolower', (array) $cfg['oath']['core_types']), true);
    }

    /** Does duty hold the NPC to the player: the oath in force. */
    public static function dutyHolds(array $dynamics): bool
    {
        return self::oathActive($dynamics);
    }

    /** The oath's strain 0..1 (respect lost, betrayals not repaired), whether or not it has broken. */
    public static function oathStrain(array $dynamics, ?array $cfg = null): float
    {
        $o = (array) (($cfg ?? self::config())['oath']);
        $respect = self::between(100.0 - self::dim($dynamics, 'respect', 50.0), 100.0 - floatval($o['respect_from']), 100.0 - floatval($o['respect_full']));
        return round(self::clamp01($respect + floatval(self::stored($dynamics)['oath']['betrayal'] ?? 0.0)), 4);
    }

    /**
     * The kinds that hold now, validated against core's type (a stored claim core's type no longer supports is stale):
     * conflicted needs core 'ex'; committed needs core's romantic rung 2; sworn needs the oath in force.
     *
     * @return string[]
     */
    public static function kinds(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return [];
        $out = [];
        $claimed = self::stored($dynamics)['kind'] ?? null;
        if ($claimed === self::CONFLICTED && self::coreType($dynamics) === 'ex') $out[] = self::CONFLICTED;
        if ($claimed === self::COMMITTED && self::rung($dynamics) >= 2) $out[] = self::COMMITTED;
        if (self::oathActive($dynamics, $cfg)) $out[] = self::SWORN;
        return $out;
    }

    public static function has(array $dynamics, string $kind): bool
    {
        return in_array($kind, self::kinds($dynamics), true);
    }

    /**
     * RelDyn's bond type this overrides (RelationshipDynamics::getRelationshipType, after the explicit override): 'conflicted' for the
     * soft end of a romance, 'sworn' for an oath-bound NPC whose core type carries no romance or hostility. null otherwise.
     * $coreMapped: the RelDyn type core's own type maps to (CORE_TYPE_TO_RELDYN_TYPE), null for none.
     */
    public static function typeOverride(array $dynamics, ?string $coreMapped): ?string
    {
        if (!is_array($dynamics[self::KEY] ?? null)) return null;   // no state: the core type's own mapping stands
        $cfg = self::config();
        if (empty($cfg['enabled'])) return null;
        if (self::has($dynamics, self::CONFLICTED)) return self::CONFLICTED;
        if (self::oathActive($dynamics, $cfg) && !in_array($coreMapped, ['bonded', 'crush', 'hostile', 'rival', 'sworn'], true)) return self::SWORN;
        return null;
    }

    /**
     * The exclusivity pull's release by the end of a romance (multiplies the pull, 0..1): an ex or a friend is not holding out for
     * the player (0); a conflicted bond still carries a torch (rekindle.conflicted_release); otherwise 1.
     */
    public static function release(array $dynamics): float
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return 1.0;
        if (self::has($dynamics, self::CONFLICTED)) return self::clamp01(floatval($cfg['rekindle']['conflicted_release']));
        $b = self::stored($dynamics)['breakup'] ?? null;
        if (is_array($b) && in_array($b['fork'] ?? null, [self::FORK_EX, self::FORK_FRIENDS], true) && ($b['status'] ?? '') === 'applied' && self::rung($dynamics) < 2) return 0.0;
        return 1.0;
    }

    /**
     * Did the romance end hard and stay ended (the fork's ex, core's type still not a romance)? An ending is a state, not a verdict: a
     * thaw into friends or a rekindle ends it. RelDynConsent reads it as a closed state ('ended').
     */
    public static function endedHard(array $dynamics): bool
    {
        if (!self::enabled()) return false;
        $b = self::stored($dynamics)['breakup'] ?? null;
        return is_array($b) && ($b['status'] ?? '') === 'applied' && ($b['fork'] ?? '') === self::FORK_EX && self::rung($dynamics) < 1
            && !self::has($dynamics, self::CONFLICTED);
    }

    /** How heavily an infidelity weighs against this romance (1 formal, committed.informal_weight otherwise), x the preference's expectation. */
    public static function fidelityWeight(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $formal = self::has($dynamics, self::COMMITTED) ? 1.0 : self::clamp01(floatval($cfg['committed']['informal_weight']));
        return round($formal * self::expectation($dynamics, $cfg), 4);
    }

    /** What the NPC's relationship preference expects of fidelity (0..1). */
    public static function expectation(array $dynamics, ?array $cfg = null): float
    {
        $t = (array) (($cfg ?? self::config())['infidelity']['expectation']);
        $pref = strtolower(trim((string) ($dynamics['relationship_preference'] ?? '')));
        return self::clamp01(floatval($t[$pref] ?? ($t['default'] ?? 0.9)));
    }

    /** Restraint 0..1: the exclusivity disposition (preference x loyalty traits x maturity x attachment) over its range. */
    public static function restraint(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        return self::clamp01(RelDynExclusivity::disposition($dynamics) / max(0.1, floatval($cfg['infidelity']['disposition_range'])));
    }

    // =====================================================================
    // THE BREAKUP FORK (pure scores, then the act)
    // =====================================================================

    /** The maturity weight of how the NPC ends and mends things (RelDynConcern::expression w, 0..1). */
    private static function maturityWeight(array $dynamics): float
    {
        return self::clamp01(floatval(RelDynConcern::expression($dynamics, RelDynConcern::traitsOf($dynamics))['w']));
    }

    /**
     * The fork's scores (0..1) for ending the romance now with $cause, and what they were made of. Pure.
     *
     * @return array{ex: float, conflicted: float, friends: float, fork: string, hardness: float, goodwill: float, longing: float,
     *               maturity: float, cling: float, avoid: float, cause_hardness: float}
     */
    public static function forkScores(array $dynamics, string $cause, ?array $cfg = null, bool $friendsOpen = true): array
    {
        $cfg = $cfg ?? self::config();
        $b = (array) $cfg['breakup'];
        $res = self::clamp01(self::dim($dynamics, 'resentment', 0.0) / 100.0);
        $causeH = self::clamp01(floatval(((array) $b['causes'])[$cause] ?? $b['default_cause']));
        $h = 1.0 - (1.0 - $res) * (1.0 - $causeH);                      // ill will
        $trust = self::clamp01(RelDynDark::ownTrust($dynamics) / 100.0);
        $tf = self::clamp01(floatval($b['trust_floor']));
        $g = (1.0 - $h) * ($tf + (1.0 - $tf) * $trust);                 // goodwill
        $lg = (array) $b['longing'];
        $passion = self::clamp01(floatval(RelationshipDynamics::getPassion($dynamics)) / max(1.0, floatval($lg['passion_full'])));
        $aff = self::clamp01(max(0.0, RelationshipDynamics::getCoreAffinity($dynamics)) / max(1.0, floatval($lg['aff_full'])));
        $l = 0.5 * $passion + 0.5 * $aff;                               // longing
        $m = self::maturityWeight($dynamics);
        $cling = self::clamp01(RelationshipDynamics::attachmentBlend($dynamics, (array) $b['cling'], 0.0));
        $avoid = self::clamp01(RelationshipDynamics::attachmentBlend($dynamics, (array) $b['avoid'], 0.0));

        $friends = $friendsOpen ? $g * (0.25 + 0.75 * $m) * (1.0 - 0.6 * $l) * (1.0 - 0.5 * $cling) * (1.0 - 0.4 * $avoid) : 0.0;
        $conflicted = $l * (0.3 + 0.7 * $g) * (0.5 + 0.5 * (1.0 - $m)) * max(0.0, 0.7 + 0.5 * $cling - 0.3 * $avoid);
        $ex = pow($h, 1.3) * (0.6 + 0.4 * (1.0 - $g)) * (1.0 - 0.5 * $l * (1.0 - $h)) * (1.0 + 0.2 * $avoid);
        $scores = [self::FORK_EX => self::clamp01($ex), self::FORK_CONFLICTED => self::clamp01($conflicted), self::FORK_FRIENDS => self::clamp01($friends)];
        $fork = self::FORK_EX;
        foreach (self::FORKS as $f) {
            if ($scores[$f] > $scores[$fork] + 1e-9) $fork = $f;
        }
        return array_map(fn($v) => round($v, 4), $scores) + ['fork' => $fork, 'hardness' => round($h, 4), 'goodwill' => round($g, 4),
            'longing' => round($l, 4), 'maturity' => round($m, 4), 'cling' => round($cling, 4), 'avoid' => round($avoid, 4), 'cause_hardness' => round($causeH, 4)];
    }

    /** The "because" clause of a cause (its text, names filled). */
    private static function because(string $cause, string $npc, string $player, array $cfg): string
    {
        $t = (array) $cfg['because'];
        return strtr((string) ($t[$cause] ?? $t['default'] ?? 'it is not working'), ['{NAME}' => $npc, '{PLAYER}' => $player]);
    }

    /** The core write, or the test seam. */
    private static function writeCore(string $npc, array &$dynamics, string $to, string $reason, ?string $from): bool
    {
        if (is_callable(self::$coreWriter)) {
            $ok = (bool) (self::$coreWriter)($npc, $to, $reason, $from);
        } elseif (empty($GLOBALS['db'])) {
            error_log("[RelDyn-BONDS] {$npc}: no database, core type {$from} -> {$to} not written");
            $ok = false;
        } else {
            $ok = RelationshipDynamics::changeCoreRelationshipType($npc, $to, $reason, $from);
        }
        if ($ok) {
            $dynamics['_core_rel_type'] = $to;
            $state = &self::state($dynamics);
            $state['core_seen'] = $to;
            unset($state);
        }
        return $ok;
    }

    /** One-shot felt lines wait here until the player is addressed (takeFeltLines). At most 4. */
    private static function queue(array &$dynamics, array $say): void
    {
        $state = &self::state($dynamics);
        $pending = array_values((array) ($state['say'] ?? []));
        $pending[] = $say;
        $state['say'] = array_slice($pending, -4);
        unset($state);
    }

    /**
     * End the romance now (module doc): the fork decides, core's type is written, the aftermath lands. Returns ['fork', 'cause',
     * 'status' => applied | blocked | skipped, 'written' => ?core type, 'scores', 'aftermath'].
     *
     * @param string $by 'npc' (the NPC ends it), 'core' (core moved the type itself: nothing is written, friends is not offered)
     */
    public static function breakup(string $npcName, array &$dynamics, string $cause, string $by = 'npc', ?float $now = null, array $opts = []): array
    {
        $cfg = self::config();
        $now = $now ?? RelationshipDynamics::currentGamets();
        $out = ['fork' => null, 'cause' => $cause, 'status' => 'skipped', 'written' => null, 'scores' => null, 'aftermath' => []];
        // (core's own ending has moved its type already: the type it left is $opts['from'])
        if (empty($cfg['enabled']) || empty($cfg['breakup']['enabled']) || (self::rung($dynamics) < 2 && $by !== 'core')) return $out;
        $from = $by === 'core' ? (string) ($opts['from'] ?? 'romantic') : self::coreType($dynamics);
        $wasCommitted = self::has($dynamics, self::COMMITTED);
        $scores = self::forkScores($dynamics, $cause, $cfg, $by !== 'core');
        $fork = $scores['fork'];
        $to = self::FORK_CORE_TYPE[$fork];
        $out['scores'] = $scores;
        $out['fork'] = $fork;
        $player = (string) ($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player');

        $write = $by === 'core' ? true : self::writeCore($npcName, $dynamics, $to, "the romance ends ({$cause}): " . self::because($cause, $npcName, $player, $cfg) . " [{$fork}]", $from);
        $record = ['at' => $now, 'fork' => $fork, 'cause' => $cause, 'by' => $by, 'hardness' => $scores['hardness'], 'shares' => [
            self::FORK_EX => $scores[self::FORK_EX], self::FORK_CONFLICTED => $scores[self::FORK_CONFLICTED], self::FORK_FRIENDS => $scores[self::FORK_FRIENDS]],
            'from' => $from, 'to' => $to, 'committed' => $wasCommitted, 'rekindle' => 0.0, 'thaw' => 0.0, 'with' => $opts['with'] ?? null];
        if (!$write) {
            $record['status'] = 'blocked';
            $state = &self::state($dynamics);
            $state['breakup_blocked'] = $record;
            unset($state);
            $out['status'] = 'blocked';
            error_log("[RelDyn-BONDS] {$npcName}: the romance ends ({$cause}, {$fork}) but core refused the type {$from} -> {$to}; nothing changes");
            return $out;
        }
        $record['status'] = 'applied';
        $state = &self::state($dynamics);
        $state['breakup'] = $record;
        $state['kind'] = $fork === self::FORK_CONFLICTED ? self::CONFLICTED : null;
        unset($state['committed_since'], $state['romantic_since'], $state['breakup_blocked'], $state['infidelity']);
        unset($state);
        $out['status'] = 'applied';
        $out['written'] = $by === 'core' ? null : $to;
        $out['aftermath'] = self::applyAftermath($npcName, $dynamics, $fork, $scores['hardness'], $wasCommitted, $cause, $cfg, $now);
        self::queue($dynamics, ['key' => 'breakup', 'fork' => $fork, 'cause' => $cause, 'maturity' => $scores['maturity']]);
        RelationshipDynamics::log(sprintf('[BONDS] %s: the romance ends (%s by %s) -> %s (ex %.2f, conflicted %.2f, friends %.2f; ill will %.2f, goodwill %.2f, longing %.2f, maturity %.2f)',
            $npcName, $cause, $by, $fork, $scores[self::FORK_EX], $scores[self::FORK_CONFLICTED], $scores[self::FORK_FRIENDS], $scores['hardness'],
            $scores['goodwill'], $scores['longing'], $scores['maturity']));
        return $out;
    }

    /** What the ending leaves behind (module config 'breakup.aftermath'), returned as dimension => change. */
    private static function applyAftermath(string $npc, array &$dynamics, string $fork, float $hardness, bool $committed, string $cause, array $cfg, float $now = 0.0): array
    {
        $b = (array) $cfg['breakup'];
        $row = (array) ($b['aftermath'][$fork] ?? []);
        $mult = $committed ? floatval($b['committed_mult']) : 1.0;
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $done = [];
        $passion = floatval(RelationshipDynamics::getPassion($dynamics));
        $keep = self::clamp01(floatval($row['passion_keep'] ?? 1.0));
        if ($keep < 1.0 && $passion > 0.0) {
            RelationshipDynamics::setPassion($dynamics, round($passion * $keep, 4));
            $done['passion'] = round($passion * $keep - $passion, 4);
        }
        foreach (['trust', 'comfort', 'resentment'] as $dim) {
            $pts = floatval($row[$dim] ?? 0.0) * $hardness * $mult;
            if (abs($pts) < 0.01) continue;
            $done[$dim] = round(RelationshipDynamics::applyDelta($dim, $dynamics, $pts, $temperament), 4);
        }
        $aff = floatval($row['affinity'] ?? 0.0) * max(0.25, $hardness) * $mult;
        if (abs($aff) >= 0.01) {
            $before = RelationshipDynamics::getCoreAffinity($dynamics);
            RelationshipDynamics::setCoreAffinityValue($dynamics, $before + $aff);
            $done['affinity'] = round(RelationshipDynamics::getCoreAffinity($dynamics) - $before, 4);
            // The NPC's circle hears of an ending the player's conduct caused, as of any defining moment (RelDynCascade::queue: through the
            // delivery rules, a witness at once, word of mouth later; each hearer applies it at their own next turn)
            if (!empty($b['cascade']) && in_array($cause, (array) $b['cascade_causes'], true) && RelDynCascade::enabled()
                && abs($done['affinity']) >= floatval(RelDynCascade::config()['defining']['min_delta'])) {
                try {
                    RelDynCascade::queue($npc, ['fp' => sha1("breakup|{$npc}|{$cause}|" . round($now, 0)), 'delta' => $done['affinity'], 'gamets' => $now,
                        'anchor' => 'the romance ended', 'defining' => true, 'witnesses' => null, 'hold' => RelDynCascade::lastSeenHold($npc, $now)]);
                } catch (\Throwable $e) {
                    RelationshipDynamics::logError("breakup ripple of {$npc}", $e);
                }
            }
        }
        return $done;
    }

    // =====================================================================
    // THE ENTRY POINTS OF THE FORK (walkaway, sever)
    // =====================================================================

    /**
     * A walkaway has been initiated (RelationshipDynamics::initiateWalkaway): a romance that is walked out of because of the NPC's
     * standards ('deserve') or because the feeling has gone ('affinity') ends. Other walkaways (anger, jealousy, autonomy, shame) are
     * a leaving the boundary test may undo; they end nothing here.
     */
    public static function onWalkaway(string $npcName, array &$dynamics, string $reason): ?array
    {
        $cause = ['deserve' => 'standards', 'affinity' => 'affinity'][$reason] ?? null;
        if ($cause === null) return null;
        $r = self::breakup($npcName, $dynamics, $cause, 'npc');
        return $r['status'] === 'skipped' ? null : $r;
    }

    /**
     * A permanent walkaway (the player followed the NPC who left, RelationshipDynamics::severBond): a romance that is severed ends
     * through the fork (cause 'pursued'). Returns null when the bond is no romance (severBond's own types apply), else the
     * breakup result with 'written' the core type written (null when none).
     */
    public static function onSever(string $npcName, array &$dynamics): ?array
    {
        if (!self::enabled() || self::rung($dynamics) < 2) return null;
        $r = self::breakup($npcName, $dynamics, 'pursued', 'npc');
        return $r['status'] === 'skipped' ? null : $r;
    }

    // =====================================================================
    // ADVANCE (the prerequest hook)
    // =====================================================================

    /**
     * One step to $now (raw gamets): core's type as it stands (a romance core ended itself), the oath and its strain, the formal rung of
     * a romance, a conflicted bond's rekindle or hardening, the infidelity loop. Returns ['events' => [..], 'kinds' => [..]].
     */
    public static function advance(string $npcName, array &$dynamics, float $now): array
    {
        $out = ['events' => [], 'kinds' => []];
        $cfg = self::config();
        if (empty($cfg['enabled']) || $now <= 0) {
            return $out;
        }
        $core = self::coreType($dynamics);
        $state = &self::state($dynamics);
        $seen = isset($state['core_seen']) ? (string) $state['core_seen'] : null;
        $state['core_seen'] = $core;
        unset($state);

        // 1. core's own ending of a romance (its eval, the editor): the fork records it, nothing is written but a friends step is not offered
        if ($seen !== null && $seen !== $core && RelDynRomance::rung($seen) >= 2 && $core === 'ex') {
            $r = self::breakup($npcName, $dynamics, 'core', 'core', $now, ['from' => $seen]);
            if ($r['status'] === 'applied') $out['events'][] = 'breakup_' . $r['fork'];
        } elseif ($seen !== null && $seen !== $core && RelDynRomance::rung($seen) >= 2 && RelDynRomance::rung($core) < 2 && $core !== 'ex'
            && RelDynFulfillment::romanceSteppedBack($dynamics) !== null && !is_array(self::stored($dynamics)['breakup'] ?? null)) {
            // the boundary lane's calm step-back is the friends ending, already written by that lane
            $state = &self::state($dynamics);
            $state['breakup'] = ['at' => $now, 'fork' => self::FORK_FRIENDS, 'cause' => 'boundary', 'by' => 'npc', 'hardness' => 0.1, 'status' => 'applied',
                'from' => $seen, 'to' => $core, 'committed' => !empty($state['committed_since']), 'rekindle' => 0.0, 'thaw' => 0.0, 'observed' => true];
            unset($state['committed_since'], $state['romantic_since'], $state['kind']);
            unset($state);
            $out['events'][] = 'breakup_friends';
        }

        // 2. the bond kinds
        $out['events'] = array_merge($out['events'], self::stepOath($npcName, $dynamics, $now, $cfg));
        $out['events'] = array_merge($out['events'], self::stepCommitted($npcName, $dynamics, $now, $cfg));
        $out['events'] = array_merge($out['events'], self::stepEnded($npcName, $dynamics, $now, $cfg));
        $out['events'] = array_merge($out['events'], self::stepInfidelity($npcName, $dynamics, $now, $cfg));
        $out['kinds'] = self::kinds($dynamics, $cfg);
        return $out;
    }

    /**
     * The game calendar's step for this NPC, talked to or not (RelationshipDynamics::advanceNpcCalendar): an ended romance's hardening and
     * way back, the formal rung, the infidelity loop. The oath's faction read (a database read) and core's own change of type are the
     * NPC's own turn's. Returns ['changed' => bool, 'affinity' => bool (the aftermath moved the core affinity: commit it)].
     */
    public static function calendarTick(string $npc, array &$dynamics, float $now): array
    {
        $out = ['changed' => false, 'affinity' => false];
        $cfg = self::config();
        if (empty($cfg['enabled']) || $now <= 0) return $out;
        // no romance, nothing ended, nothing stored: nothing to tick (and no state made for a bond that has none)
        if (!is_array($dynamics[self::KEY] ?? null) && self::rung($dynamics) < 1) return $out;
        $before = json_encode(self::stored($dynamics));
        $aff = RelationshipDynamics::getCoreAffinity($dynamics);
        self::stepCommitted($npc, $dynamics, $now, $cfg);
        self::stepEnded($npc, $dynamics, $now, $cfg);
        self::stepInfidelity($npc, $dynamics, $now, $cfg);
        $out['changed'] = json_encode(self::stored($dynamics)) !== $before;
        $out['affinity'] = abs(RelationshipDynamics::getCoreAffinity($dynamics) - $aff) > 1e-9;
        return $out;
    }

    // ---- the oath ---------------------------------------------------------

    private static function stepOath(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $events = [];
        $o = (array) $cfg['oath'];
        if (empty($o['enabled'])) return $events;
        $state = &self::state($dynamics);
        $oath = is_array($state['oath'] ?? null) ? $state['oath'] : [];
        unset($state);

        // a faction that binds, read at most once per check_game_days (a database read), and only for the player's party
        $checked = floatval($oath['checked'] ?? 0);
        if (empty($oath['active']) && empty($oath['broken']) && !empty($o['factions']) && ($checked <= 0 || $now - $checked >= floatval($o['check_game_days']) * self::day())
            && !in_array(self::coreType($dynamics), array_map('strtolower', (array) $o['core_types']), true)) {
            $oath['checked'] = $now;
            try {
                $facts = RelationshipDynamics::powerGapFacts($npc, $dynamics);
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("oath factions of {$npc}", $e);
                $facts = ['factions' => [], 'in_party' => false];
            }
            $names = [];
            foreach ((array) ($facts['factions'] ?? []) as $f) {
                $names[] = strtolower(trim((string) (is_array($f) ? ($f['name'] ?? '') : $f)));
            }
            $binding = array_map('strtolower', array_map('strval', (array) $o['factions']));
            if (array_intersect($names, $binding) !== [] && (empty($o['faction_needs_party']) || !empty($facts['in_party']))) {
                $oath = ['active' => true, 'source' => 'faction', 'since' => $now, 'strain' => 0.0, 'betrayal' => 0.0] + $oath;
                $events[] = 'oath_sworn';
                RelationshipDynamics::log("[BONDS] {$npc}: bound by a faction oath to the player");
            }
        }
        if (!self::oathActiveIn($dynamics, $oath, $cfg)) {
            $state = &self::state($dynamics);
            if ($oath !== []) $state['oath'] = $oath;
            unset($state);
            return $events;
        }

        $view = $dynamics;
        $view[self::KEY]['oath'] = $oath;
        $strain = self::oathStrain($view, $cfg);
        $oath['strain'] = $strain;
        if (empty($oath['broken']) && $strain >= floatval($o['break_at'])) {
            $oath['broken'] = true;
            $oath['broken_at'] = $now;
            $events[] = 'oath_broken';
            $state = &self::state($dynamics);
            $state['oath'] = $oath;
            unset($state);
            self::queue($dynamics, ['key' => 'oath_broken']);
            RelationshipDynamics::log("[BONDS] {$npc}: the oath breaks (strain " . round($strain, 3) . '): respect is gone');
            return $events;
        }
        $state = &self::state($dynamics);
        $state['oath'] = $oath;
        unset($state);
        return $events;
    }

    /** oathActive() for an oath state not yet stored (the faction check above). */
    private static function oathActiveIn(array $dynamics, array $oath, array $cfg): bool
    {
        $view = $dynamics;
        $view[self::KEY] = ['v' => self::VERSION, 'oath' => $oath];
        return self::oathActive($view, $cfg);
    }

    /** A broken oath mends once respect is back and the strain eased; also reads in the strain of a state not yet active. */
    private static function mendOath(string $npc, array &$dynamics, float $now, array $cfg): bool
    {
        $o = (array) $cfg['oath'];
        $s = self::stored($dynamics);
        if (empty($s['oath']['broken'])) return false;
        if (self::dim($dynamics, 'respect', 50.0) < floatval($o['renew_respect']) || self::oathStrain($dynamics, $cfg) >= floatval($o['renew_strain_below'])) return false;
        $state = &self::state($dynamics);
        $state['oath']['broken'] = false;
        $state['oath']['renewed_at'] = $now;
        $state['oath']['strain'] = self::oathStrain($dynamics, $cfg);
        unset($state);
        self::queue($dynamics, ['key' => 'oath_renewed']);
        RelationshipDynamics::log("[BONDS] {$npc}: the oath is taken up again (respect is back)");
        return true;
    }

    // ---- committed --------------------------------------------------------

    /** Game days a romance must hold before it is formal for this NPC (base / disposition, the disposition kept at least disposition_min). */
    public static function commitDays(array $dynamics, ?array $cfg = null): float
    {
        $c = (array) (($cfg ?? self::config())['committed']);
        $d = max(floatval($c['disposition_min']), RelDynExclusivity::disposition($dynamics));
        return round(floatval($c['base_game_days']) / $d, 3);
    }

    private static function stepCommitted(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $events = [];
        $c = (array) $cfg['committed'];
        $state = &self::state($dynamics);
        if (self::rung($dynamics) < 2) {
            // a romance that is not (or is no longer) core's romantic rung is no formal one
            if (($state['kind'] ?? null) === self::COMMITTED) unset($state['kind']);
            unset($state['romantic_since'], $state['committed_since']);
            unset($state);
            return $events;
        }
        if (empty($state['romantic_since'])) {
            $since = $now;
            $partners = $dynamics['memory_anchors']['partners']['gamets'] ?? null;
            if (is_numeric($partners) && floatval($partners) > 0 && floatval($partners) <= $now) $since = floatval($partners);
            $state['romantic_since'] = $since;
        }
        if (empty($c['enabled']) || ($state['kind'] ?? null) === self::COMMITTED) {
            unset($state);
            return $events;
        }
        $since = floatval($state['romantic_since']);
        unset($state);
        $held = ($now - $since) / self::day();
        $needed = self::commitDays($dynamics, $cfg);
        $pull = RelDynExclusivity::pull($dynamics, $now)['pull'];
        $ok = $held >= $needed && $pull >= floatval($c['pull_min']) && RelDynDark::ownTrust($dynamics) >= floatval($c['trust_min'])
            && RelationshipDynamics::getCoreAffinity($dynamics) >= floatval($c['aff_min']) && empty($dynamics['in_conflict'])
            && ($dynamics['_walkaway_state'] ?? 'normal') === 'normal' && self::infidelityStage($dynamics) !== 'strayed';
        if (!$ok) return $events;
        $state = &self::state($dynamics);
        $state['kind'] = self::COMMITTED;
        $state['committed_since'] = $now;
        unset($state);
        self::queue($dynamics, ['key' => 'committed']);
        $events[] = 'committed';
        RelationshipDynamics::log(sprintf('[BONDS] %s: the romance is formal now (held %.1f game days of %.1f, pull %.2f)', $npc, $held, $needed, $pull));
        return $events;
    }

    // ---- an ended romance -------------------------------------------------

    private static function stepEnded(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $events = [];
        $o = (array) $cfg['oath'];
        if (!empty($o['enabled'])) {
            if (self::mendOath($npc, $dynamics, $now, $cfg)) $events[] = 'oath_renewed';
        }
        $b = self::stored($dynamics)['breakup'] ?? null;
        if (!is_array($b) || ($b['status'] ?? '') !== 'applied') return $events;
        // a new romance ends the old ending (core back at a romance rung: the ladder promoted it)
        if (self::rung($dynamics) >= 1) {
            $state = &self::state($dynamics);
            $state['breakup']['status'] = 'over';
            if (($state['kind'] ?? null) === self::CONFLICTED) unset($state['kind']);
            unset($state);
            return $events;
        }
        $res = self::dim($dynamics, 'resentment', 0.0);
        $r = (array) $cfg['rekindle'];
        if (self::has($dynamics, self::CONFLICTED)) {
            if ($res >= floatval($r['harden_resentment'])) {
                $state = &self::state($dynamics);
                $state['kind'] = null;
                $state['breakup']['fork'] = self::FORK_EX;
                $state['breakup']['hardened_at'] = $now;
                unset($state);
                self::queue($dynamics, ['key' => 'hardened']);
                $events[] = 'hardened';
                RelationshipDynamics::log("[BONDS] {$npc}: resentment has hardened the conflicted bond into an ex");
            } elseif (floatval($b['rekindle'] ?? 0.0) >= 1.0) {
                $events = array_merge($events, self::tryRekindle($npc, $dynamics, $now, $cfg));
            }
        } elseif (($b['fork'] ?? null) === self::FORK_EX && floatval($b['thaw'] ?? 0.0) >= 1.0) {
            $events = array_merge($events, self::tryThaw($npc, $dynamics, $now, $cfg));
        }
        return $events;
    }

    private static function tryRekindle(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $r = (array) $cfg['rekindle'];
        if (self::dim($dynamics, 'resentment', 0.0) > floatval($r['resentment_max']) || RelDynDark::ownTrust($dynamics) < floatval($r['trust_min'])
            || ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal') {
            return [];
        }
        if (!self::writeCore($npc, $dynamics, (string) $r['to'], 'the NPC lets it start again: the way back was walked', 'ex')) {
            error_log("[RelDyn-BONDS] {$npc}: ready to rekindle but core refused ex -> {$r['to']}; the progress stays");
            return [];
        }
        $state = &self::state($dynamics);
        $state['kind'] = null;
        $state['breakup']['status'] = 'rekindled';
        $state['breakup']['rekindled_at'] = $now;
        unset($state);
        self::queue($dynamics, ['key' => 'rekindled']);
        RelationshipDynamics::log("[BONDS] {$npc}: rekindled (ex -> {$r['to']}); the romance ladder carries on from here");
        return ['rekindled'];
    }

    private static function tryThaw(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $t = (array) $cfg['rekindle']['thaw'];
        if (self::dim($dynamics, 'resentment', 0.0) > floatval($t['resentment_max']) || RelDynDark::ownTrust($dynamics) < floatval($t['trust_min'])
            || self::maturityWeight($dynamics) < floatval($t['maturity_w_min'])) {
            return [];
        }
        if (!self::writeCore($npc, $dynamics, (string) $t['to'], 'the anger is gone: a friendship is left', 'ex')) return [];
        $state = &self::state($dynamics);
        $state['breakup']['fork'] = self::FORK_FRIENDS;
        $state['breakup']['thawed_at'] = $now;
        unset($state);
        self::queue($dynamics, ['key' => 'thawed']);
        RelationshipDynamics::log("[BONDS] {$npc}: the anger is gone; ex -> {$t['to']}");
        return ['thawed'];
    }

    /**
     * An applied eval item $n (a normalized contract item): an oath's betrayal and its repair; an ended romance's way back (rekindle for
     * a conflicted bond, thaw for a hard ex) or setback. Returns the progress change (0 when none).
     */
    public static function onEvalItem(string $npcName, array $n, array &$dynamics, float $gamets): float
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return 0.0;
        $tags = array_map('strtolower', array_map('strval', (array) ($n['tags'] ?? [])));
        $sig = floatval($n['significance'] ?? 0.0);
        $positive = !empty($n['positive_interaction']);

        // the oath: a betrayal strains it, a positive exchange repairs the betrayals
        if (self::oathActive($dynamics, $cfg) || !empty(self::stored($dynamics)['oath'])) {
            $o = (array) $cfg['oath'];
            $state = &self::state($dynamics);
            if (is_array($state['oath'] ?? null) || self::oathActive($dynamics, $cfg)) {
                if (array_intersect($tags, array_map('strtolower', (array) $o['betrayal_tags'])) !== [] && $sig >= 0.3) {
                    $state['oath']['betrayal'] = round(min(1.0, floatval($state['oath']['betrayal'] ?? 0.0) + floatval($o['betrayal_strain']) * $sig), 4);
                } elseif ($positive && floatval($state['oath']['betrayal'] ?? 0.0) > 0.0) {
                    $state['oath']['betrayal'] = round(max(0.0, floatval($state['oath']['betrayal']) - floatval($o['repair']) * max(0.3, $sig)), 4);
                }
            }
            unset($state);
        }

        // an ended romance's way back
        $b = self::stored($dynamics)['breakup'] ?? null;
        if (!is_array($b) || ($b['status'] ?? '') !== 'applied' || self::rung($dynamics) >= 1) return 0.0;
        $isConflicted = self::has($dynamics, self::CONFLICTED);
        $isEx = ($b['fork'] ?? null) === self::FORK_EX;
        if (!$isConflicted && !$isEx) return 0.0;
        $r = (array) $cfg['rekindle'];
        $field = $isConflicted ? 'rekindle' : 'thaw';
        $step = $isConflicted ? floatval($r['step']) : floatval($r['thaw']['step']);
        $setback = !$positive && $sig >= floatval($r['min_significance']);
        $weight = 0.0;
        if ($positive && $sig >= floatval($r['min_significance'])) {
            $weight = floatval($r['positive']);
            foreach ((array) $r['tags'] as $tag => $w) {
                if (in_array(strtolower((string) $tag), $tags, true)) $weight = max($weight, floatval($w));
            }
        }
        if ($weight <= 0.0 && !$setback) return 0.0;
        $ill = self::clamp01(floatval($b['hardness'] ?? 0.5));
        $att = max(0.1, RelationshipDynamics::attachmentBlend($dynamics, (array) $r['attachment'], 1.0));
        $day = $gamets > 0 ? (int) floor($gamets / RelationshipDynamics::GAMETS_PER_DAY) : 0;
        $state = &self::state($dynamics);
        $d = is_array($state['breakup']['day'] ?? null) ? $state['breakup']['day'] : ['day' => $day, 'pts' => 0.0];
        if (intval($d['day'] ?? -1) !== $day) $d = ['day' => $day, 'pts' => 0.0];
        $before = floatval($state['breakup'][$field] ?? 0.0);
        if ($setback) {
            $delta = -min($before, $step * $sig * floatval($r['setback']));
        } else {
            $raw = $step * $sig * $weight * (1.0 - $ill) * $att;
            $delta = max(0.0, min($raw, max(0.0, floatval($r['daily_cap']) - floatval($d['pts'])), 1.0 - $before));
            $d['pts'] = round(floatval($d['pts']) + $delta, 4);
        }
        $state['breakup'][$field] = round(max(0.0, min(1.0, $before + $delta)), 4);
        $state['breakup']['day'] = $d;
        $after = $state['breakup'][$field];
        unset($state);
        if (abs($delta) > 0.0) {
            RelationshipDynamics::log(sprintf('[BONDS] %s: %s %+.3f -> %.3f (%s)', $npcName, $field, $delta, $after, $setback ? 'a setback' : 'an exchange that meant something'));
        }
        return round($delta, 4);
    }

    // ---- the infidelity loop ---------------------------------------------

    /** The loop's stage now ('drifting' | 'seeking' | 'strayed' | null). */
    public static function infidelityStage(array $dynamics): ?string
    {
        $s = self::stored($dynamics)['infidelity']['stage'] ?? null;
        return is_string($s) && in_array($s, self::STAGES, true) ? $s : null;
    }

    /** The suitor with the most interest in the ledger now: ['name', 'interest' (points)] or null. Pure. */
    public static function bestSuitor(array $dynamics, float $now): ?array
    {
        $best = null;
        foreach ((array) ($dynamics[RelDynExclusivity::STATE_KEY]['suitors'] ?? []) as $e) {
            if (!is_array($e) || trim((string) ($e['name'] ?? '')) === '') continue;
            $i = RelDynExclusivity::interestAt($e, $now);
            if ($best === null || $i > $best['interest']) $best = ['name' => trim((string) $e['name']), 'interest' => $i];
        }
        return $best;
    }

    /**
     * The infidelity loop's pull toward straying at $now (module doc), pure: cut (how far neglect and low fulfillment have cut the
     * exclusivity pull), temptation (the best suitor's interest), the target the pressure follows, the stage lines (lifted by restraint).
     *
     * @return array{cut: float, hollow: float, opening: float, restraint: float, temptation: float, target: float, lines: array, suitor: ?array, unweakened: float, pull: float}
     */
    public static function infidelityTarget(array $dynamics, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $i = (array) $cfg['infidelity'];
        $p = RelDynExclusivity::pull($dynamics, $now);
        $unweakened = floatval($p['unweakened']);
        $cut = $unweakened > 1e-6 ? self::clamp01(1.0 - floatval($p['pull']) / $unweakened) : 0.0;
        // a hollow pull widens an opening that neglect has begun to make; it does not make one (a state that begins fresh has a low pull and nothing cut)
        $hollow = self::clamp01(1.0 - $unweakened) * self::clamp01(floatval($i['hollow_weight'])) * RelDynTraits::smoothstep($cut, 0.05, 0.3);
        $opening = 1.0 - (1.0 - $cut) * (1.0 - $hollow);
        $suitor = self::bestSuitor($dynamics, $now);
        $t = (array) $i['tempt'];
        $temptation = $suitor !== null ? self::between($suitor['interest'], floatval($t['from']), floatval($t['full'])) : 0.0;
        $base = self::clamp01(floatval($i['tempt_base']));
        $target = $opening * ($base + (1.0 - $base) * $temptation);
        $jealous = self::between(self::dim($dynamics, 'jealousy', 0.0), 0.0, floatval($i['jealous_full']));
        $target *= 1.0 - self::clamp01(floatval($i['jealous_damp'])) * $jealous;
        $restraint = self::restraint($dynamics, $cfg);
        $lines = [];
        foreach (self::STAGES as $stage) {
            $lines[$stage] = round(min(0.98, floatval($i['stages'][$stage]) + floatval($i['restraint_shift'][$stage]) * $restraint), 4);
        }
        return ['cut' => round($cut, 4), 'hollow' => round($hollow, 4), 'opening' => round($opening, 4), 'restraint' => round($restraint, 4), 'temptation' => round($temptation, 4), 'target' => round(self::clamp01($target), 4),
                'lines' => $lines, 'suitor' => $suitor, 'unweakened' => $unweakened, 'pull' => floatval($p['pull'])];
    }

    /** How the NPC carries a line crossed: 'conceal' (avoidant or fearful), 'guilt' (loyal), else 'justified'. */
    public static function strayedStyle(array $dynamics, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $res = (array) $cfg['infidelity']['resolve'];
        $w = RelationshipDynamics::attachmentWeights($dynamics);
        if (floatval($w['avoidant'] ?? 0.0) >= floatval($res['avoidant_at']) || floatval($w['toxic'] ?? 0.0) >= floatval($res['fearful_at'])) return 'conceal';
        return self::restraint($dynamics, $cfg) >= floatval($res['loyal']) ? 'guilt' : 'justified';
    }

    /** How a line crossed ends: ['kind' => confess | leave | both, 'game_days' => days after the line was crossed]. Pure. */
    public static function resolution(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $r = (array) $cfg['infidelity']['resolve'];
        $w = RelationshipDynamics::attachmentWeights($dynamics);
        $m = self::maturityWeight($dynamics);
        $loyal = self::restraint($dynamics, $cfg) >= floatval($r['loyal']);
        // the fearful keep both whatever their maturity: the pull of the one and the fear of losing the other
        if (floatval($w['toxic'] ?? 0.0) >= floatval($r['fearful_at'])) return ['kind' => 'both', 'game_days' => floatval($r['both_fearful'])];
        if ($m >= floatval($r['mature_w']) && $loyal) return ['kind' => 'confess', 'game_days' => floatval($r['confess_mature'])];
        if (floatval($w['anxious'] ?? 0.0) >= floatval($r['anxious_at'])) return ['kind' => 'confess', 'game_days' => floatval($r['confess_anxious'])];
        if (floatval($w['avoidant'] ?? 0.0) >= floatval($r['avoidant_at'])) return ['kind' => 'leave', 'game_days' => floatval($r['leave_avoidant'])];
        return ['kind' => 'leave', 'game_days' => floatval($r['leave'])];
    }

    /**
     * The infidelity loop to $now. An absence is what feeds it and the NPC is only asked for on the player's turns (and by the calendar
     * scan), so a long gap is walked in steps of at most a game day (the pull, the suitor's regard and the line crossed all move with the
     * calendar inside it), at most infidelity.max_steps of them; an ending inside the gap ends the walk.
     */
    private static function stepInfidelity(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $events = [];
        $i = (array) $cfg['infidelity'];
        $last = floatval(self::stored($dynamics)['infidelity']['gamets'] ?? 0);
        $gap = $last > 0 ? $now - $last : 0.0;
        $n = $gap > self::day() ? min(max(1, intval($i['max_steps'] ?? 60)), (int) ceil($gap / self::day())) : 1;
        for ($k = 1; $k <= $n; $k++) {
            $t = $n === 1 ? $now : $last + $gap * $k / $n;
            $events = array_merge($events, self::infidelityStep($npc, $dynamics, $t, $cfg));
            if (self::rung($dynamics) < intval($i['min_rung'])) break;   // the romance ended inside the gap
        }
        return $events;
    }

    /** One step of the loop at $now (see stepInfidelity). */
    private static function infidelityStep(string $npc, array &$dynamics, float $now, array $cfg): array
    {
        $events = [];
        $i = (array) $cfg['infidelity'];
        $state = &self::state($dynamics);
        if (empty($i['enabled']) || self::rung($dynamics) < intval($i['min_rung'])) {
            // no romance (or the loop is off): without a romance there is nothing to stray from
            if (is_array($state['infidelity'] ?? null) && self::rung($dynamics) < intval($i['min_rung'])) unset($state['infidelity']);
            unset($state);
            return $events;
        }
        $inf = is_array($state['infidelity'] ?? null) ? $state['infidelity'] : ['pressure' => 0.0, 'stage' => null];
        unset($state);
        $last = floatval($inf['gamets'] ?? 0);
        $hours = $last > 0 ? max(0.0, ($now - $last) / self::hour()) : 0.0;
        $tgt = self::infidelityTarget($dynamics, $now, $cfg);
        $p0 = floatval($inf['pressure'] ?? 0.0);
        $rates = (array) $i['rates'];
        $hoursStep = min($hours, floatval($rates['max_step_game_hours']));
        $rate = $tgt['target'] > $p0 ? floatval($rates['rise_per_game_hour']) : floatval($rates['fall_per_game_hour']);
        $p = $last > 0 ? self::clamp01($p0 + ($tgt['target'] - $p0) * (1.0 - pow(1.0 - self::clamp01($rate), $hoursStep))) : $tgt['target'];

        // the stage by the NPC's own lines
        $stage = null;
        foreach (self::STAGES as $s) {
            if ($p >= $tgt['lines'][$s]) $stage = $s;
        }
        $suitor = $tgt['suitor'];
        $with = $suitor !== null && $suitor['interest'] >= floatval($i['min_interest']) ? $suitor['name'] : ($inf['with'] ?? null);
        // strayed needs a suitor of real interest, held for a while
        if ($stage === 'strayed') {
            if ($suitor === null || $suitor['interest'] < floatval($i['strayed_interest'])) {
                $stage = 'seeking';
                unset($inf['strayed_since']);
            } elseif (empty($inf['strayed_since'])) {
                $inf['strayed_since'] = $now;
                $stage = 'seeking';
            } elseif (($now - floatval($inf['strayed_since'])) / self::hour() < floatval($i['strayed_hold_game_hours'])) {
                $stage = 'seeking';
            }
        } else {
            unset($inf['strayed_since']);
        }
        $before = $inf['stage'] ?? null;
        // a line crossed does not uncross itself: only the ending (breakup / confession) or an open NPC's calm ends the stage
        if (($before === 'strayed') && $stage !== 'strayed') $stage = 'strayed';
        $open = self::expectation($dynamics, $cfg) < floatval($i['open_below']);

        // the fade of passion closes the loop (a weaker passion is a weaker pull)
        $fade = 0.0;
        if ($stage !== null && $last > 0 && !$open) {
            $pf = (array) $i['passion_fade'];
            $factor = floatval(((array) $pf['stage'])[$stage] ?? 0.0);
            $passion = floatval(RelationshipDynamics::getPassion($dynamics));
            $floor = min($passion, floatval($pf['floor']));
            $fade = min($passion - $floor, floatval($pf['per_game_day']) * $factor * ($hoursStep / 24.0));
            if ($fade > 0.0005) RelationshipDynamics::setPassion($dynamics, round($passion - $fade, 4));
            else $fade = 0.0;
        }

        $inf['pressure'] = round($p, 4);
        $inf['stage'] = $stage;
        $inf['with'] = $with;
        $inf['gamets'] = $now;
        $inf['last'] = ['target' => $tgt['target'], 'cut' => $tgt['cut'], 'opening' => $tgt['opening'], 'temptation' => $tgt['temptation'], 'restraint' => $tgt['restraint'], 'lines' => $tgt['lines']];
        $state = &self::state($dynamics);
        $state['infidelity'] = $inf;
        unset($state);

        if ($stage !== $before) {
            $events[] = 'infidelity_' . ($stage ?? 'cleared');
            RelationshipDynamics::log(sprintf('[BONDS] %s: %s%s (pressure %.3f, cut %.2f, temptation %.2f, restraint %.2f)', $npc,
                $stage === null ? 'the pull toward someone else is gone' : "the pull toward someone else is {$stage}", $with !== null && $stage !== null ? " with {$with}" : '',
                $p, $tgt['cut'], $tgt['temptation'], $tgt['restraint']));
        }

        // a line crossed: the weight it carries, once; then how it ends
        if ($stage === 'strayed' && !$open) {
            $events = array_merge($events, self::stepStrayed($npc, $dynamics, $now, $cfg, $tgt));
        }
        return $events;
    }

    private static function stepStrayed(string $npc, array &$dynamics, float $now, array $cfg, array $tgt): array
    {
        $events = [];
        $i = (array) $cfg['infidelity'];
        $state = &self::state($dynamics);
        $inf = $state['infidelity'];
        if (empty($inf['line_crossed'])) {
            $inf['line_crossed'] = $now;
            $inf['style'] = self::strayedStyle($dynamics, $cfg);
            $state['infidelity'] = $inf;
            unset($state);
            // the weight by who the NPC is: guilt toward self for the loyal, a grievance toward the player for the rest
            $weight = self::fidelityWeight($dynamics, $cfg);
            $loyalty = self::clamp01(self::restraint($dynamics, $cfg) * (0.5 + 0.5 * self::maturityWeight($dynamics)));
            $g = (array) $i['guilt'];
            $temperament = $dynamics['inferred_temperament'] ?? null;
            if ($loyalty * floatval($g['resentment_self']) * $weight > 0.01) {
                RelationshipDynamics::applyDelta('resentment_self', $dynamics, floatval($g['resentment_self']) * $loyalty * $weight, $temperament);
            }
            if ((1.0 - $loyalty) * floatval($g['resentment']) * $weight > 0.01) {
                RelationshipDynamics::applyDelta('resentment', $dynamics, floatval($g['resentment']) * (1.0 - $loyalty) * $weight, $temperament);
            }
            $events[] = 'line_crossed';
            return $events;
        }
        unset($state);
        // how it ends
        $res = self::resolution($dynamics, $cfg);
        $days = ($now - floatval($inf['line_crossed'])) / self::day();
        if ($days < $res['game_days']) return $events;
        $cause = $res['kind'] === 'confess' ? 'infidelity_confessed' : 'left_for_other';
        // an infidelity that ends it is hard when it was left for the other, softer when it was confessed
        $r = self::breakup($npc, $dynamics, $cause, 'npc', $now, ['with' => $inf['with'] ?? null]);
        if ($r['status'] === 'applied') $events[] = 'breakup_' . $r['fork'];
        elseif ($r['status'] === 'blocked') {
            $state = &self::state($dynamics);
            $state['infidelity']['line_crossed'] = $now;   // core refused: it stays unresolved a while longer
            unset($state);
        }
        return $events;
    }

    /**
     * The suitor's line in an NPC-NPC exchange (RelDynExclusivity::onNpcExchange): when the NPC is seeking or has strayed, with this
     * suitor, the reply is warm instead of a deflection. null otherwise. Feelings, the names, no pronoun, no digits.
     */
    public static function suitorLine(array $dynamics, string $npcName, string $suitor, string $playerName): ?string
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || empty($cfg['infidelity']['enabled'])) return null;
        $inf = self::stored($dynamics)['infidelity'] ?? null;
        $stage = is_array($inf) ? ($inf['stage'] ?? null) : null;
        if (!in_array($stage, ['seeking', 'strayed'], true)) return null;
        if (trim((string) ($inf['with'] ?? '')) === '' || mb_strtolower(trim((string) $inf['with'])) !== mb_strtolower(trim($suitor))) return null;
        $key = $stage === 'strayed' ? 'suitor_strayed' : 'suitor_seeking';
        return strtr((string) $cfg['felt_text'][$key], ['{NAME}' => $npcName, '{SUITOR}' => $suitor, '{PLAYER}' => $playerName]);
    }

    // =====================================================================
    // FELT TEXT
    // =====================================================================

    /**
     * This turn's one-shots for RelDynFelt, consumed only when the player is speaking to this NPC: the formal rung, a breakup said by
     * the fork and the NPC's maturity, a rekindle, a hardening, a thaw, an oath broken or taken up again. Lines: ['key', 'lane' => 'turn',
     * 'salience', 'must', 'intense', 'text'].
     *
     * @return array ['lines' => list, 'changed' => bool]
     */
    public static function takeFeltLines(array &$dynamics, string $npcName, string $playerName, bool $playerAddressed = true): array
    {
        $out = ['lines' => [], 'changed' => false];
        if (!is_array($dynamics[self::KEY] ?? null) || !$playerAddressed || ($dynamics[self::KEY]['say'] ?? []) === []) return $out;
        $cfg = self::config();
        $t = (array) $cfg['felt_text'];
        $sal = (array) $cfg['salience'];
        $state = &self::state($dynamics);
        foreach ((array) $state['say'] as $s) {
            $key = (string) ($s['key'] ?? '');
            $vars = ['{NAME}' => $npcName, '{PLAYER}' => $playerName];
            if ($key === 'breakup') {
                $how = floatval($s['maturity'] ?? 0.5) >= 0.5 ? 'mature' : 'raw';
                $textKey = 'breakup_' . (string) ($s['fork'] ?? 'ex') . '_' . $how;
                $vars['{BECAUSE}'] = self::because((string) ($s['cause'] ?? 'default'), $npcName, $playerName, $cfg);
            } elseif (isset($t[$key])) {
                $textKey = $key;
            } else {
                continue;
            }
            if (!isset($t[$textKey])) {
                error_log("[RelDyn-BONDS] {$npcName}: no felt text '{$textKey}', dropped");
                continue;
            }
            $out['lines'][] = ['key' => $key === 'breakup' ? 'breakup_' . (string) ($s['fork'] ?? 'ex') : $key, 'lane' => 'turn',
                'salience' => floatval($sal[$key] ?? 0.9), 'must' => true, 'intense' => $key === 'breakup', 'text' => strtr((string) $t[$textKey], $vars)];
        }
        $state['say'] = [];
        $out['changed'] = true;
        unset($state);
        return $out;
    }

    /**
     * The standing lines of the bond kinds: [['key', 'text', 'salience'], ...]. A sworn NPC's duty (warm or cold by how they feel), an
     * ended romance's state (conflicted, ex), the infidelity loop's stage. $tier: the context tier; nothing below tier 1. Feelings and
     * what the NPC does, names only, no digits.
     */
    public static function feltLines(array $dynamics, string $npc, string $player, int $tier = 2): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $tier < 1) return [];
        $t = (array) $cfg['felt_text'];
        $sal = (array) $cfg['salience'];
        $vars = ['{NAME}' => $npc, '{PLAYER}' => $player];
        $lines = [];
        if (self::oathActive($dynamics, $cfg)) {
            $warm = RelationshipDynamics::getCoreAffinity($dynamics) >= 30.0;
            $key = $warm ? 'sworn_warm' : 'sworn_cold';
            $lines[] = ['key' => $key, 'text' => strtr((string) $t[$key], $vars), 'salience' => floatval($sal['sworn'])];
        }
        $b = self::stored($dynamics)['breakup'] ?? null;
        if (is_array($b) && ($b['status'] ?? '') === 'applied' && self::rung($dynamics) < 1) {
            if (self::has($dynamics, self::CONFLICTED)) {
                $lines[] = ['key' => 'conflicted', 'text' => strtr((string) $t['conflicted'], $vars), 'salience' => floatval($sal['conflicted'])];
            } elseif (($b['fork'] ?? null) === self::FORK_EX) {
                $lines[] = ['key' => 'ex', 'text' => strtr((string) $t['ex'], $vars), 'salience' => floatval($sal['ex'])];
            }
        }
        $inf = self::stored($dynamics)['infidelity'] ?? null;
        $stage = is_array($inf) ? ($inf['stage'] ?? null) : null;
        if ($stage !== null && $tier >= 2) {
            $suitor = trim((string) ($inf['with'] ?? ''));
            $vars['{SUITOR}'] = $suitor !== '' ? $suitor : 'someone else';
            if (self::expectation($dynamics, $cfg) < floatval($cfg['infidelity']['open_below'])) {
                if ($stage === 'strayed') $lines[] = ['key' => 'strayed_open', 'text' => strtr((string) $t['strayed_open'], $vars), 'salience' => floatval($sal['strayed'])];
            } elseif ($stage === 'strayed') {
                $style = (string) ($inf['style'] ?? self::strayedStyle($dynamics, $cfg));
                $lines[] = ['key' => 'strayed_' . $style, 'text' => strtr((string) $t['strayed_' . $style], $vars), 'salience' => floatval($sal['strayed'])];
            } else {
                $lines[] = ['key' => $stage, 'text' => strtr((string) $t[$stage], $vars), 'salience' => floatval($sal[$stage])];
            }
        }
        return $lines;
    }

    // =====================================================================
    // JEV
    // =====================================================================

    /** Numbers for Jev: the kinds, the oath's strain, the ending's fork and way back, the infidelity loop. */
    public static function jev(array $dynamics, float $now): array
    {
        $cfg = self::config();
        $s = self::stored($dynamics);
        $b = is_array($s['breakup'] ?? null) ? $s['breakup'] : null;
        $inf = is_array($s['infidelity'] ?? null) ? $s['infidelity'] : [];
        return [
            'enabled' => !empty($cfg['enabled']),
            'kinds' => self::kinds($dynamics, $cfg),
            'core_type' => self::coreType($dynamics) ?: null,
            'bond_type' => (string) RelationshipDynamics::getRelationshipType('', $dynamics),
            'committed' => ['since' => isset($s['committed_since']) ? floatval($s['committed_since']) : null,
                'held_game_days' => !empty($s['romantic_since']) && $now > 0 ? round(max(0.0, ($now - floatval($s['romantic_since'])) / self::day()), 2) : null,
                'needed_game_days' => self::rung($dynamics) >= 2 ? self::commitDays($dynamics, $cfg) : null],
            'oath' => ['active' => self::oathActive($dynamics, $cfg), 'source' => $s['oath']['source'] ?? (self::oathActive($dynamics, $cfg) ? 'core_type' : null),
                'strain' => self::oathActive($dynamics, $cfg) || !empty($s['oath']) ? self::oathStrain($dynamics, $cfg) : 0.0, 'broken' => !empty($s['oath']['broken'])],
            'breakup' => $b === null ? null : ['fork' => $b['fork'] ?? null, 'cause' => $b['cause'] ?? null, 'status' => $b['status'] ?? null,
                'hardness' => round(floatval($b['hardness'] ?? 0.0), 3), 'rekindle' => round(floatval($b['rekindle'] ?? 0.0), 3), 'thaw' => round(floatval($b['thaw'] ?? 0.0), 3),
                'shares' => (array) ($b['shares'] ?? [])],
            'infidelity' => ['stage' => $inf['stage'] ?? null, 'pressure' => round(floatval($inf['pressure'] ?? 0.0), 3), 'with' => $inf['with'] ?? null,
                'style' => $inf['style'] ?? null, 'target' => isset($inf['last']['target']) ? floatval($inf['last']['target']) : null,
                'cut' => isset($inf['last']['cut']) ? floatval($inf['last']['cut']) : null, 'expectation' => self::expectation($dynamics, $cfg),
                'line_crossed' => isset($inf['line_crossed']) ? floatval($inf['line_crossed']) : null],
        ];
    }
}
