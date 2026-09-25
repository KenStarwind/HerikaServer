<?php
/**
 * Relationship Dynamics — natural exclusivity (Ken, decisions 2026-09-24 §17).
 *
 * "I like the player enough not to entertain other options, even though we aren't official."
 *
 * The exclusivity PULL is a continuous inner state (0..1) toward the player, not a title:
 *
 *   gate    = clamp(passion / spark_passion, 0, 1)       no romantic spark, no pull (a close
 *                                                         friend is not exclusive)
 *   drive   = gate x weighted mean of
 *               passion  clamp(passion / passion_full, 0, 1)
 *               bond     core affinity from bond_core_aff.from (0) to .full (1)
 *               fulfill  (band + 1) / 2 of the player pair (rulings §11), unknown_fulfillment before any
 *   who she is (disposition, clamped):
 *               relationship preference (monogamous > unset > uncommitted > polyamorous; none
 *               for not interested / aromantic) x possessiveness and restraint (loyalty, duty)
 *               traits x maturity (a mature NPC knows what she wants) x attachment (her axes'
 *               corners: secure commits steadiest, avoidant keeps a door open)
 *   title   = core relationships.Player.type (romantic / crush) MULTIPLIES the drive: a title
 *             strengthens the pull, it never creates it (no drive, no pull)
 *   release = a deliberate step-back out of the romance (rulings §9, either boundary lane) multiplies
 *             it by stepped_back_mult (0: "that closeness is over"); a new romance ends it
 *   weaken  = low fulfillment (the band below fulfillment.low_band) and long neglect (game days
 *             past the bond's absence grace since the last actual interaction of the player pair
 *             halve it every neglect_half_life_game_days): how a neglected partner starts
 *             listening to someone else
 *   pull    = clamp(drive x disposition x title x release x weaken, 0, 1); band devoted / taken / leaning / open
 *
 * Expression (NPC-NPC context steering, feelings never numbers): when another NPC makes a
 * romantic move on her in an NPC-to-NPC exchange (a radiant round, or a rechat whose previous
 * speaker is that NPC), read from what CHIM logs (the suitor's recent eventlog lines to her:
 * config move markers, compliments addressed to her, or two kinds of cue; everyday speech is no
 * move), she deflects / cools / mentions someone in
 * her own style (config style rules over her traits, attachment and maturity), naming the
 * player only when there is a title. A weakened pull with a romantic bond behind it reads as
 * drifting instead.
 *
 * Damping (RelDyn side): her romantic interest in each suitor is RelDyn's own ledger
 * ($dynamics['_exclusivity']['suitors'], keyed like fulfillment pairs): each romantic move and
 * each rise of her core affinity toward the suitor while a move is recent or core's NPC-NPC eval
 * holds a romantic type between them (the suitor's relationships entry for her, or hers for
 * him; that type alone never steers her reply) adds interest
 * x (1 - damping x pull). Core's own NPC-NPC 'aff' number is core's (the fork's owner hook can
 * only take a target whole, not scale a delta): see the lane's open question.
 *
 * Units: pull and its parts 0..1; passion points 0..100; core affinity -100..+100; interest
 * points 0..100; time on the game calendar (raw gamets, RelationshipDynamics::GAMETS_PER_DAY).
 */

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/eval_producer.php';

final class RelDynExclusivity
{
    const STATE_KEY = '_exclusivity';

    const BAND_DEVOTED = 'devoted';
    const BAND_TAKEN = 'taken';
    const BAND_LEANING = 'leaning';
    const BAND_OPEN = 'open';

    /** Core request types whose other party is RECHAT_PREVIOUS_SPEAKER (main.php). */
    const PREVIOUS_SPEAKER_TYPES = RelationshipDynamics::PREVIOUS_SPEAKER_REQUEST_TYPES;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'exclusivity' (a stored config replaces whole settings/tables).
     * Serene's starting values for Ken's §17 ruling (the MDD gives none); tune after playtest.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,

            // --- the drive (from her feelings for the player) ---
            'spark_passion' => 20.0,     // passion points: the romantic gate is full here (decisions §13 spark)
            'passion_full'  => 60.0,     // passion points at which the passion term is full
            'bond_core_aff' => ['from' => 20.0, 'full' => 80.0],   // core affinity: bond term 0 at from, 1 at full
            'unknown_fulfillment' => 0.5,  // fulfillment term before the player pair has a band (neutral)
            'drive_weights' => ['passion' => 0.4, 'bond' => 0.35, 'fulfillment' => 0.25],

            // --- who she is (disposition) ---
            // relationships preference => multiplier; unlisted (unset, asexual) 1.0
            'preference_mult' => ['monogamous' => 1.25, 'demisexual' => 1.15, 'uncommitted' => 0.6,
                                  'polyamorous' => 0.35, 'not_interested' => 0.0, 'aromantic' => 0.0],
            // trait code (RelDynTraits::TRAITS) => w: x (1 + w x (trait - 0.5)). Po possessiveness;
            // D restraint / duty, the loyalty trait ("a decision, kept").
            'trait_weights' => ['Po' => 0.3, 'D' => 0.5],
            'maturity_mult' => ['at0' => 0.8, 'at100' => 1.15],   // linear in maturity points 0..100
            // attachment style corner => multiplier, blended at her axes (decisions §12): secure
            // commitment is the steadiest; anxious clings but hungers for being wanted (the neglect
            // grace, not this, is where that bites); avoidant keeps a door open
            'attachment_mult' => ['secure' => 1.1, 'anxious' => 1.0, 'avoidant' => 0.8, 'toxic' => 0.85],
            'disposition_range' => [0.0, 1.6],

            // --- the title: core relationships.Player.type => multiplier on the drive ---
            'title_mult' => ['romantic' => 1.35, 'crush' => 1.1],
            // A deliberate step-back out of the romance (rulings §9, either boundary lane:
            // RelDynFulfillment::romanceSteppedBack) multiplies the pull by this (0..1): "kind, but
            // that closeness is over", so 0 releases it; while it stands she never drifts either
            'stepped_back_mult' => 0.0,

            // --- what weakens it ---
            'low_fulfillment_cut' => 0.6,          // x (1 - cut x depth); depth 0 at fulfillment.low_band, 1 at band -1
            'neglect_half_life_game_days' => 7.0,  // past the absence grace the pull halves every this many game days

            // --- bands (pull at or above) ---
            'bands' => [self::BAND_DEVOTED => 0.7, self::BAND_TAKEN => 0.45, self::BAND_LEANING => 0.25],
            // "Drifting" (a weakened pull with a romantic bond behind it): below the taken band, a
            // title or passion at bond_passion_min, and the pull cut to at most weakened_share of
            // what it would be unweakened; or, once low fulfillment or neglect has begun to cut it
            // at all, her interest in the suitor at interest_notice. His interest alone, with the
            // player there and the bond fulfilled, is no drifting (its text says the player has
            // felt far away).
            'drifting' => ['weakened_share' => 0.7, 'bond_passion_min' => 20.0, 'interest_notice' => 15.0],

            // --- suitors (NPC-NPC romantic moves; her interest in them, damped) ---
            // Core relationship types (lib/relationship_manager.php TYPES; the suitor's core entry for
            // her, or hers for him) that are romantic: they make his rising regard romantic interest
            // in the ledger, but a type set long ago is no move in this exchange
            'romantic_types' => ['romantic', 'crush', 'obsessed'],
            // What makes a line a romantic move (case-insensitive, whole words; ordinary speech
            // between companions is not courtship):
            //   move_markers       a phrase that is a move on its own
            //   compliment_words   praise that is a move when addressed to her: a compliment_forms
            //                      regex ({WORD} = the word, quoted) matches
            //   cues               kind => phrases; a praise word, an invitation or a feature of hers
            //                      alone is everyday speech, cues of two different kinds are a move
            'move_markers' => ['kiss me', 'kiss you', 'darling', 'sweetheart', 'my love', 'my beloved', 'dinner with me',
                'dance with me', 'court you', 'courting you', 'fancy you', 'sweet on you', 'marry me', 'be mine',
                'take you out', 'spend the night with me', 'you look lovely'],
            'compliment_words' => ['beautiful', 'lovely', 'gorgeous', 'handsome', 'stunning', 'pretty', 'radiant',
                'captivating', 'alluring', 'enchanting'],
            'compliment_forms' => [
                "\\byou(?:'re|\u{2019}re|\\s+are|\\s+look|\\s+looked|\\s+seem|\\s+have)\\s+(?:(?:so|truly|very|quite|really|such|a|an|the|most)\\s+){0,3}{WORD}\\b",
                "\\byour\\s+(?:eyes|smile|face|hair|voice|lips|laugh)\\s+(?:is|are)\\s+(?:(?:so|truly|very|quite|really)\\s+){0,2}{WORD}\\b",
            ],
            'cues' => [
                'praise'  => ['beautiful', 'lovely', 'gorgeous', 'handsome', 'stunning', 'pretty', 'radiant', 'captivating', 'alluring'],
                'feature' => ['your eyes', 'your smile', 'your lips', 'your hair', 'your voice', 'beautiful eyes', 'lovely eyes', 'pretty eyes'],
                'invite'  => ['walk with me', 'a drink with me', 'share a drink', 'come with me tonight', 'just the two of us'],
                'longing' => ['still alone', 'spoken for', 'anyone special', 'my heart', 'write a song about', 'a song about you',
                              'thinking about you', 'think of you', 'dream of you', 'miss you'],
            ],
            'move_window_game_hours' => 6.0,   // a move this recent steers her reply
            'eventlog_scan_rows' => 16,        // recent chat rows read for the suitor's lines
            'interest_per_move' => 6.0,        // interest points a move adds before damping
            'core_gain_factor' => 1.0,         // interest points per core affinity point she gained toward him (romantic)
            'damping' => 0.9,                  // gains x (1 - damping x pull)
            'interest_half_life_game_days' => 10.0,
            'max_suitors' => 8,

            // --- style (first matching rule; every condition in a rule must hold) ---
            // maturity_below / maturity_at_least: maturity points; attachment + min: that corner's
            // weight at her axes; trait + min / below: a trait code of her vector (0..1)
            'style_rules' => [
                ['style' => 'sharp', 'maturity_below' => 40.0],
                ['style' => 'flustered', 'attachment' => 'anxious', 'min' => 0.4],
                ['style' => 'flustered', 'trait' => 'C', 'below' => 0.3],
                ['style' => 'cool', 'attachment' => 'avoidant', 'min' => 0.4],
                ['style' => 'cool', 'trait' => 'G', 'min' => 0.75],
                ['style' => 'playful', 'trait' => 'E', 'min' => 0.7, 'and' => ['trait' => 'D', 'below' => 0.4]],
            ],
            'default_style' => 'plain',

            // --- felt text (feelings, never numbers): band => style => text ---
            // {NAME} her, {SUITOR} him, {PLAYER} the player, {SOMEONE} the player with a title,
            // else someone_untitled
            'someone_untitled' => 'someone they have not named out loud',
            'header' => "{NAME} right now, with {SUITOR}. Show it in what {NAME} does and says; never name or explain these feelings:",
            'felt_text' => [
                self::BAND_DEVOTED => [
                    'plain'     => "{SUITOR} is making a romantic approach, and {NAME} is not entertaining it for a moment: their heart is with {SOMEONE}. They turn it aside plainly, without cruelty, and say so if pressed.",
                    'sharp'     => "{SUITOR} is flirting, and {NAME} bristles: their heart is with {SOMEONE}, and they cut it off fast, with an edge.",
                    'flustered' => "{SUITOR} is flirting, and it flusters {NAME}: they fumble for a way out, and {SOMEONE} comes up almost at once, a reminder as much to themselves as to {SUITOR}.",
                    'cool'      => "{SUITOR} is flirting, and {NAME} goes cool and gives it nothing to hold on to; their thoughts are with {SOMEONE}, and they do not explain.",
                    'playful'   => "{SUITOR} is flirting, and {NAME} laughs it off lightly, never taking the bait; their heart is with {SOMEONE}, and it shows in how easily they let it go.",
                ],
                self::BAND_TAKEN => [
                    'plain'     => "{SUITOR} seems to be making a romantic approach; {NAME} deflects it, steering the talk elsewhere, and mentions {SOMEONE} if it goes on.",
                    'sharp'     => "{SUITOR} seems to be flirting; {NAME} is short with it and lets an edge show, and {SOMEONE} is the reason they give if it goes on.",
                    'flustered' => "{SUITOR} seems to be flirting; {NAME} gets awkward and changes the subject, and somehow {SOMEONE} comes up.",
                    'cool'      => "{SUITOR} seems to be flirting; {NAME} cools noticeably and keeps it polite and brief, thinking of {SOMEONE}.",
                    'playful'   => "{SUITOR} seems to be flirting; {NAME} deflects with a joke and does not play along; {SOMEONE} is on their mind.",
                ],
                self::BAND_LEANING => [
                    'plain'     => "{SUITOR} seems interested in {NAME}; {NAME} stays polite but a little cooler than usual, and their thoughts drift to {PLAYER}.",
                    'sharp'     => "{SUITOR} seems interested in {NAME}; {NAME} is prickly about it, half flattered and half annoyed, and thinks of {PLAYER}.",
                    'flustered' => "{SUITOR} seems interested in {NAME}; {NAME} does not quite know what to do with it and thinks of {PLAYER}.",
                    'cool'      => "{SUITOR} seems interested in {NAME}; {NAME} keeps a polite distance and gives nothing away.",
                    'playful'   => "{SUITOR} seems interested in {NAME}; {NAME} banters back but keeps it light, and does not let it go anywhere.",
                ],
                'drifting' => [
                    'plain'     => "{SUITOR}'s attention is warmer than {NAME} expected, and they do not push it away as quickly as they once would have; {PLAYER} has felt far away lately.",
                    'sharp'     => "{SUITOR}'s attention lands on a sore spot: {PLAYER} has been distant, and part of {NAME} wants to see how it feels to be wanted.",
                    'flustered' => "{SUITOR}'s attention makes {NAME} flush; with {PLAYER} so distant lately, it is hard not to enjoy being noticed.",
                    'cool'      => "{NAME} keeps their guard up with {SUITOR}, but lets the conversation go on longer than they would have; {PLAYER} has been far away.",
                    'playful'   => "{NAME} plays along with {SUITOR} a little more than they should; {PLAYER} has been far away lately, and the attention is nice.",
                ],
            ],
        ];
    }

    /** The exclusivity settings: stored config per setting/table, defaults for the rest. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('exclusivity');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    // =====================================================================
    // THE PULL (pure)
    // =====================================================================

    /**
     * The exclusivity pull toward the player at $now (raw gamets) and its parts. Pure: reads
     * her dynamics only (passion, core affinity mirror, the player pair's fulfillment, traits,
     * attachment, maturity, relationship preference, core type, last contact).
     *
     * @return array ['pull' => 0..1, 'band' => devoted|taken|leaning|open, 'unweakened' => 0..1,
     *                'gate', 'drive', 'disposition', 'title', 'titled' => bool, 'low_cut', 'neglect',
     *                'passion' => points, 'bond' => 0..1, 'fulfillment' => 0..1, 'overdue_game_days']
     */
    public static function pull(array $dynamics, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $passion = floatval(RelationshipDynamics::getPassion($dynamics));
        $gate = self::clamp01($passion / max(0.001, floatval($cfg['spark_passion'])));

        $aff = RelationshipDynamics::getCoreAffinity($dynamics);
        $from = floatval($cfg['bond_core_aff']['from'] ?? 20.0);
        $full = floatval($cfg['bond_core_aff']['full'] ?? 80.0);
        $bond = self::clamp01(($aff - $from) / max(0.001, $full - $from));

        $fcfg = RelDynFulfillment::config();
        $band = null;
        $state = RelDynFulfillment::pairState($dynamics);
        if (RelDynFulfillment::enabled() && is_array($state['w'] ?? null) && $now > 0) {
            $band = RelDynFulfillment::bandAt($state, max($now, floatval($state['gamets'] ?? 0)), $fcfg);
        }
        $fulfill = $band === null ? self::clamp01(floatval($cfg['unknown_fulfillment'])) : self::clamp01(($band + 1.0) / 2.0);

        $w = (array) $cfg['drive_weights'];
        $terms = ['passion' => self::clamp01($passion / max(0.001, floatval($cfg['passion_full']))), 'bond' => $bond, 'fulfillment' => $fulfill];
        $sum = 0.0;
        $wSum = 0.0;
        foreach ($terms as $k => $v) {
            $wk = max(0.0, floatval($w[$k] ?? 0));
            $sum += $wk * $v;
            $wSum += $wk;
        }
        $drive = $gate * ($wSum > 0 ? $sum / $wSum : 0.0);

        $disposition = self::disposition($dynamics, $cfg);
        $coreType = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $titleMult = ((array) $cfg['title_mult'])[$coreType] ?? null;
        $titled = is_numeric($titleMult);
        $title = $titled ? max(0.0, floatval($titleMult)) : 1.0;

        // Weakening: low fulfillment (depth below the low band) and neglect past the grace
        $lowCut = 1.0;
        if ($band !== null) {
            $low = floatval($fcfg['low_band']);
            $depth = self::clamp01(($low - $band) / max(0.001, $low + 1.0));
            $lowCut = max(0.0, 1.0 - floatval($cfg['low_fulfillment_cut']) * $depth);
        }
        $overdue = 0.0;
        $neglect = 1.0;
        $graceEnd = self::neglectGraceEnd($dynamics, $state);
        if ($graceEnd !== null && $now > $graceEnd) {
            $overdue = ($now - $graceEnd) / RelationshipDynamics::GAMETS_PER_DAY;   // game days
            $half = floatval($cfg['neglect_half_life_game_days']);
            $neglect = $half > 0 ? pow(0.5, $overdue / $half) : 1.0;
        }

        // She stepped back from the romance on purpose (rulings §9): that closeness is over
        $steppedBack = RelDynFulfillment::romanceSteppedBack($dynamics) !== null;
        $release = $steppedBack ? self::clamp01(floatval($cfg['stepped_back_mult'] ?? 0.0)) : 1.0;

        $unweakened = self::clamp01($drive * $disposition * $title * $release);
        $pull = self::clamp01($unweakened * $lowCut * $neglect);
        return [
            'pull' => round($pull, 4), 'band' => self::bandOf($pull, $cfg), 'unweakened' => round($unweakened, 4),
            'gate' => round($gate, 4), 'drive' => round($drive, 4), 'disposition' => round($disposition, 4),
            'title' => round($title, 4), 'titled' => $titled, 'stepped_back' => $steppedBack,
            'low_cut' => round($lowCut, 4), 'neglect' => round($neglect, 4),
            'passion' => round($passion, 2), 'bond' => round($bond, 4), 'fulfillment' => round($fulfill, 4),
            'overdue_game_days' => round($overdue, 3),
        ];
    }

    /**
     * When the player's absence stops being excused (raw gamets), or null: the absence grace of
     * this bond (RelationshipDynamics::neglectGraceEndGamets: bond type, her neglect profile, the
     * band the last contact left) counted from the last ACTUAL interaction of the player pair
     * (rulings §11 presence: the pair's contact days; an NPC's own remark or a rechat is no
     * interaction), else from the last contact stamp.
     */
    private static function neglectGraceEnd(array $dynamics, ?array $pairState): ?float
    {
        $graceEnd = RelationshipDynamics::neglectGraceEndGamets($dynamics);
        if ($graceEnd === null) return null;
        $lastContact = floatval($dynamics['_last_contact_gamets'] ?? 0);
        $days = array_values(array_filter((array) ($pairState['contact_days'] ?? []), 'is_int'));
        if ($days === []) return $graceEnd;
        $lastInteraction = min($lastContact, (max($days) + 1) * RelationshipDynamics::GAMETS_PER_DAY);
        return $lastInteraction + ($graceEnd - $lastContact);
    }

    /**
     * Who she is, as a multiplier on the drive (clamped to disposition_range): relationship
     * preference x traits (possessiveness, restraint / loyalty) x maturity x attachment.
     */
    public static function disposition(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $pref = strtolower(trim((string) ($dynamics['relationship_preference'] ?? '')));
        $m = floatval(((array) $cfg['preference_mult'])[$pref] ?? 1.0);

        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        if (is_array($x)) {
            foreach ((array) $cfg['trait_weights'] as $code => $weight) {
                if (!is_numeric($x[$code] ?? null)) continue;
                $m *= max(0.0, 1.0 + floatval($weight) * (floatval($x[$code]) - 0.5));
            }
        }
        $maturity = max(0.0, min(100.0, floatval($dynamics['dimensions']['maturity']['x'] ?? 50.0)));
        $mm = (array) $cfg['maturity_mult'];
        $m *= floatval($mm['at0'] ?? 1.0) + (floatval($mm['at100'] ?? 1.0) - floatval($mm['at0'] ?? 1.0)) * $maturity / 100.0;
        $m *= RelationshipDynamics::attachmentBlend($dynamics, (array) $cfg['attachment_mult'], 1.0);

        $range = (array) $cfg['disposition_range'];
        return max(floatval($range[0] ?? 0.0), min(floatval($range[1] ?? 2.0), $m));
    }

    public static function bandOf(float $pull, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $bands = (array) $cfg['bands'];
        foreach ([self::BAND_DEVOTED, self::BAND_TAKEN, self::BAND_LEANING] as $band) {
            if ($pull >= floatval($bands[$band] ?? INF)) return $band;
        }
        return self::BAND_OPEN;
    }

    /** Her way of turning someone aside: the first matching style rule, else default_style. */
    public static function style(array $dynamics, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics) ?? [];
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50.0);
        $corners = RelationshipDynamics::attachmentWeights($dynamics);
        $holds = function (array $r) use (&$holds, $x, $maturity, $corners): bool {
            if (isset($r['maturity_below']) && !($maturity < floatval($r['maturity_below']))) return false;
            if (isset($r['maturity_at_least']) && !($maturity >= floatval($r['maturity_at_least']))) return false;
            if (isset($r['attachment']) && !(floatval($corners[(string) $r['attachment']] ?? 0.0) >= floatval($r['min'] ?? 0.5))) return false;
            if (isset($r['trait'])) {
                $v = $x[(string) $r['trait']] ?? null;
                if (!is_numeric($v)) return false;
                if (isset($r['min']) && !(floatval($v) >= floatval($r['min']))) return false;
                if (isset($r['below']) && !(floatval($v) < floatval($r['below']))) return false;
            }
            if (isset($r['and']) && is_array($r['and']) && !$holds($r['and'])) return false;
            return true;
        };
        foreach ((array) $cfg['style_rules'] as $rule) {
            if (is_array($rule) && isset($rule['style']) && $holds($rule)) return (string) $rule['style'];
        }
        return (string) $cfg['default_style'];
    }

    // =====================================================================
    // SUITORS (her romantic interest in others, RelDyn side, damped)
    // =====================================================================

    /** Does $phrase occur in $line as whole words (case-insensitive)? */
    private static function hasPhrase(string $line, string $phrase): bool
    {
        $phrase = trim($phrase);
        return $phrase !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu', $line) === 1;
    }

    /**
     * Is $line (a suitor's words) a romantic move? Pure. A move marker; or a compliment word
     * addressed to her (a compliment_forms pattern); or cues of at least two different kinds
     * (praise, a feature of hers, an invitation, longing). A single everyday cue ("beautiful
     * weather", "walk with me to Jorrvaskr", "by my heart") is not.
     */
    public static function isRomanticLine(string $line, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        foreach ((array) ($cfg['move_markers'] ?? []) as $marker) {
            if (self::hasPhrase($line, (string) $marker)) return true;
        }
        foreach ((array) ($cfg['compliment_words'] ?? []) as $word) {
            $word = trim((string) $word);
            if ($word === '') continue;
            foreach ((array) ($cfg['compliment_forms'] ?? []) as $form) {
                $hit = @preg_match('/' . str_replace('{WORD}', preg_quote($word, '/'), (string) $form) . '/iu', $line);
                if ($hit === false) {
                    throw new InvalidArgumentException('RelDynExclusivity: exclusivity.compliment_forms has an invalid pattern: ' . $form);
                }
                if ($hit === 1) return true;
            }
        }
        $kinds = 0;
        foreach ((array) ($cfg['cues'] ?? []) as $phrases) {
            foreach ((array) $phrases as $phrase) {
                if (self::hasPhrase($line, (string) $phrase)) { $kinds++; break; }
            }
        }
        return $kinds >= 2;
    }

    /** A suitor's ledger entry with its interest decayed to $now (interest points). Pure. */
    public static function interestAt(array $entry, float $now, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $v = floatval($entry['interest'] ?? 0.0);
        $days = max(0.0, $now - floatval($entry['gamets'] ?? $now)) / RelationshipDynamics::GAMETS_PER_DAY;
        $half = floatval($cfg['interest_half_life_game_days']);
        return ($half > 0 && $days > 0) ? $v * pow(0.5, $days / $half) : $v;
    }

    /** The ledger entry for $suitor (pair key as fulfillment's), or null. */
    public static function suitor(array $dynamics, string $suitor): ?array
    {
        $e = $dynamics[self::STATE_KEY]['suitors'][RelDynFulfillment::pairKey($suitor)] ?? null;
        return is_array($e) ? $e : null;
    }

    /**
     * Record what this exchange with $suitor showed, at $now, damped by the pull: each new
     * romantic line of his (eventlog rowid past the entry's watermark) adds interest_per_move,
     * and a rise of her core affinity toward him since last seen, while the exchange is romantic,
     * adds core_gain_factor per point; both x (1 - damping x pull). The entry keeps the pull he met
     * ('pull_seen'). Returns the interest points added (after damping).
     *
     * @param array $lines  his romantic lines to her: [['rowid' => int, 'text' => string], ...]
     */
    public static function recordExchange(array &$dynamics, string $suitor, array $lines, ?float $herCoreAff, bool $romantic, float $pull, float $now, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $key = RelDynFulfillment::pairKey($suitor);
        $state = is_array($dynamics[self::STATE_KEY] ?? null) ? $dynamics[self::STATE_KEY] : [];
        $suitors = is_array($state['suitors'] ?? null) ? $state['suitors'] : [];
        $e = is_array($suitors[$key] ?? null) ? $suitors[$key] : ['name' => trim($suitor), 'interest' => 0.0, 'moves' => 0, 'seen_rowid' => 0];
        $interest = self::interestAt($e, $now, $cfg);
        $damp = self::clamp01(1.0 - floatval($cfg['damping']) * self::clamp01($pull));

        $raw = 0.0;
        $seen = intval($e['seen_rowid'] ?? 0);
        foreach ($lines as $l) {
            $rowid = intval($l['rowid'] ?? 0);
            if ($rowid <= $seen) continue;
            $raw += floatval($cfg['interest_per_move']);
            $e['moves'] = intval($e['moves'] ?? 0) + 1;
            $e['last_move_gamets'] = $now;
            $seen = max($seen, $rowid);
        }
        $e['seen_rowid'] = $seen;
        if ($herCoreAff !== null) {
            $before = $e['aff_seen'] ?? null;
            if ($romantic && is_numeric($before) && $herCoreAff > floatval($before)) {
                $raw += ($herCoreAff - floatval($before)) * floatval($cfg['core_gain_factor']);
            }
            $e['aff_seen'] = round($herCoreAff, 2);
        }
        $added = $raw * $damp;
        $e['interest'] = round(max(0.0, min(100.0, $interest + $added)), 4);
        $e['pull_seen'] = round(self::clamp01($pull), 4);   // her pull toward the player when he last approached
        $e['gamets'] = $now;
        $e['name'] = trim($suitor);
        $suitors[$key] = $e;
        // Keep the most recent suitors only
        uasort($suitors, fn($a, $b) => floatval($b['gamets'] ?? 0) <=> floatval($a['gamets'] ?? 0));
        $state['suitors'] = array_slice($suitors, 0, max(1, intval($cfg['max_suitors'])), true);
        $dynamics[self::STATE_KEY] = $state;
        return round($added, 4);
    }

    // =====================================================================
    // FELT TEXT (feelings, never numbers)
    // =====================================================================

    /**
     * The felt line for an NPC-NPC exchange in which $suitor makes (or recently made) a
     * romantic move: by the pull's band and her style; drifting when a romantic bond sits behind
     * a weakened pull. Null when the pull is open and nothing ties her to the player. Pure.
     *
     * @return array|null ['kind' => band|'drifting', 'style' => string, 'text' => string]
     */
    public static function feltLine(array $dynamics, array $pull, string $npcName, string $suitor, string $playerName, float $interest, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $band = (string) $pull['band'];
        $d = (array) $cfg['drifting'];
        // A romance she stepped back from is no bond to drift from (rulings §9)
        $bonded = empty($pull['stepped_back'])
            && (!empty($pull['titled']) || floatval($pull['passion'] ?? 0) >= floatval($d['bond_passion_min'] ?? 20.0));
        $weakened = floatval($pull['unweakened'] ?? 0) > 0
            && floatval($pull['pull']) <= floatval($d['weakened_share'] ?? 0.7) * floatval($pull['unweakened']);
        // Low fulfillment or neglect has begun to cut the pull at all: the player has felt far
        // away, and his interest can make her drift before the weakening alone would
        $cutting = floatval($pull['low_cut'] ?? 1.0) < 1.0 || floatval($pull['neglect'] ?? 1.0) < 1.0;
        $kind = null;
        if (in_array($band, [self::BAND_DEVOTED, self::BAND_TAKEN], true)) {
            $kind = $band;
        } elseif ($bonded && ($weakened || ($cutting && $interest >= floatval($d['interest_notice'] ?? 15.0)))) {
            $kind = 'drifting';
        } elseif ($band === self::BAND_LEANING) {
            $kind = $band;
        }
        if ($kind === null) return null;
        $style = self::style($dynamics, $cfg);
        $texts = (array) (((array) $cfg['felt_text'])[$kind] ?? []);
        $text = (string) ($texts[$style] ?? $texts[(string) $cfg['default_style']] ?? '');
        if ($text === '') return null;
        $someone = !empty($pull['titled']) ? $playerName : strtr((string) $cfg['someone_untitled'], ['{NAME}' => $npcName]);
        return ['kind' => $kind, 'style' => $style,
                'text' => strtr($text, ['{NAME}' => $npcName, '{SUITOR}' => $suitor, '{PLAYER}' => $playerName, '{SOMEONE}' => $someone])];
    }

    /** The <subtext> block of one felt line (the NPC-NPC exchange). */
    public static function render(string $npcName, string $suitor, string $text, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $header = strtr((string) $cfg['header'], ['{NAME}' => $npcName, '{SUITOR}' => $suitor]);
        return "<subtext>\n{$header}\n- {$text}\n</subtext>";
    }

    // =====================================================================
    // THE NPC-NPC EXCHANGE (reads CHIM core; the context hook calls it)
    // =====================================================================

    private static function sameName(string $a, string $b): bool
    {
        return $a !== '' && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * The other NPC in this NPC-to-NPC request: the previous speaker of a rechat, continue or
     * continue_group (core sets RECHAT_PREVIOUS_SPEAKER before the context hooks: the rechat
     * payload's speaker, else the last speech row), else the other party of a radiant request's
     * own line ("Speaker: text (talking to X)"). Null when the other party is the player or
     * unknown.
     */
    public static function counterpart($gameRequest, string $npcName, string $playerName): ?string
    {
        if (!is_array($gameRequest)) return null;
        $type = strtolower(trim((string) ($gameRequest[0] ?? '')));
        $other = null;
        if (in_array($type, self::PREVIOUS_SPEAKER_TYPES, true)) {
            $other = trim((string) ($GLOBALS['RECHAT_PREVIOUS_SPEAKER'] ?? ''));
        } elseif (RelationshipDynamics::isRadiantRequest($gameRequest)) {
            $line = RelDynEval::parseDialogueRow('chat', (string) ($gameRequest[3] ?? ''), $npcName, $playerName);
            if ($line !== null) {
                if ($line['role'] === 'other') {
                    $other = $line['speaker'];
                } elseif ($line['role'] === 'npc' && $line['listener'] !== null) {
                    $other = $line['listener'];
                }
            }
        }
        $other = trim((string) $other);
        if ($other === '' || self::sameName($other, $npcName) || self::sameName($other, $playerName)
            || strcasecmp($other, RelDynFulfillment::PLAYER) === 0 || in_array(mb_strtolower($other), ['everyone', 'all', 'the narrator'], true)) {
            return null;
        }
        return $other;
    }

    /**
     * $suitor's recent romantic lines to $npcName from CHIM's eventlog (chat rows within
     * move_window_game_hours of $now, the latest eventlog_scan_rows, spoken or emitted): his line,
     * to her (or naming her), matching a move marker. [['rowid' => int, 'text' => string], ...]
     */
    public static function romanticLines(string $npcName, string $suitor, string $playerName, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [];
        $since = (int) floor($now - floatval($cfg['move_window_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0);
        $row = $db->fetchOne(
            "SELECT coalesce(json_agg(r ORDER BY r.rowid), '[]'::json)::text AS rows FROM (
                 SELECT rowid, data FROM eventlog
                 WHERE type = 'chat' AND gamets >= \$1
                   AND (delivery_state IS NULL OR delivery_state = ANY(\$2::text[]))
                 ORDER BY rowid DESC LIMIT \$3
             ) r",
            [$since, '{' . implode(',', RelDynEval::VISIBLE_CHAT_STATES) . '}', max(1, intval($cfg['eventlog_scan_rows']))]
        );
        $rows = json_decode((string) ($row['rows'] ?? 'null'), true);
        if (!is_array($rows)) {
            throw new RuntimeException('RelDynExclusivity: eventlog read failed');
        }
        $out = [];
        foreach ($rows as $r) {
            $line = RelDynEval::parseDialogueRow('chat', (string) ($r['data'] ?? ''), $npcName, $playerName);
            if ($line === null || !self::sameName($line['speaker'], $suitor)) continue;
            $toHer = false;
            foreach (preg_split('/\s*(?:,|&|\band\b)\s*/iu', (string) ($line['listener'] ?? '')) ?: [] as $part) {
                if (self::sameName($part, $npcName)) $toHer = true;
            }
            if (!$toHer && mb_stripos($line['text'], $npcName) === false) continue;
            if (!self::isRomanticLine($line['text'], $cfg)) continue;
            $out[] = ['rowid' => intval($r['rowid']), 'text' => $line['text']];
        }
        return $out;
    }

    /** Core's relationship entry of $from toward $to ('aff' core points, 'type'), or null. */
    private static function coreBond(string $from, string $to): ?array
    {
        foreach (RelationshipDynamics::getAllBondsForNpc($from) as $target => $bond) {
            if (self::sameName((string) $target, $to)) return $bond;
        }
        return null;
    }

    /**
     * The NPC-to-NPC exchange hook (context.php, recency position): when $suitor has made a
     * romantic move on her (his recent lines in CHIM's eventlog, or core's NPC-NPC eval holds a
     * romantic type between them), update her suitor ledger (damped by the pull) and return the
     * <subtext> block for her reply; null otherwise. Saves her dynamics when the ledger moved.
     */
    public static function onNpcExchange(string $npcName, string $suitor, string $playerName, float $now): ?string
    {
        if (!self::enabled() || !RelationshipDynamics::isEnabled() || $now <= 0) return null;
        $cfg = self::config();
        $types = array_map('strtolower', (array) $cfg['romantic_types']);
        $his = self::coreBond($suitor, $npcName);
        $hers = self::coreBond($npcName, $suitor);
        $coreRomantic = in_array(strtolower((string) ($his['type'] ?? '')), $types, true)
            || in_array(strtolower((string) ($hers['type'] ?? '')), $types, true);
        $lines = self::romanticLines($npcName, $suitor, $playerName, $now, $cfg);
        $prior = null;
        $dynamics = RelationshipDynamics::getDynamics($npcName);
        $entry = self::suitor($dynamics, $suitor);
        if (is_array($entry) && isset($entry['last_move_gamets'])) $prior = floatval($entry['last_move_gamets']);
        $recentMove = $lines !== [] || ($prior !== null && $now - $prior <= floatval($cfg['move_window_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0);
        if (!$coreRomantic && !$recentMove) return null;
        // A standing romantic core type between them keeps the ledger (his rising regard is romantic
        // interest), but only a move in this exchange (§17: "when another NPC makes romantic moves")
        // steers her reply

        // Her bond with the player as core holds it now (title, affinity): an NPC-to-NPC exchange
        // runs no prerequest of hers, so the stored snapshot may lag core's eval. Read, not stored.
        $view = $dynamics;
        $toPlayer = self::coreBond($npcName, RelationshipDynamics::PLAYER_RELATIONSHIP_KEY);
        if (is_array($toPlayer)) {
            RelationshipDynamics::setCoreRelationshipType($view, $toPlayer['type'] ?? 'neutral');
            RelationshipDynamics::refreshAffinityMirror($view, floatval($toPlayer['aff'] ?? 0));
        }
        $p = self::pull($view, $now, $cfg);
        $added = self::recordExchange($dynamics, $suitor, $lines, is_numeric($hers['aff'] ?? null) ? floatval($hers['aff']) : null,
            true, $p['pull'], $now, $cfg);
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
        $interest = self::interestAt((array) self::suitor($dynamics, $suitor), $now, $cfg);
        RelationshipDynamics::log("[EXCL] {$npcName} <- {$suitor}: pull " . $p['pull'] . " ({$p['band']}), " . count($lines)
            . " new move(s), interest +{$added} -> " . round($interest, 2) . ($coreRomantic ? ', core romantic' : ''));
        if (!$recentMove) return null;
        $line = self::feltLine($view, $p, $npcName, $suitor, $playerName, $interest, $cfg);
        return $line === null ? null : self::render($npcName, $suitor, $line['text'], $cfg);
    }

    // =====================================================================
    // JEV (numbers: Jev picks actions, it does not roleplay)
    // =====================================================================

    /** Compact numbers for Jev's state block: pull, band, parts, suitors' interest (top 3). */
    public static function jev(array $dynamics, float $now): ?array
    {
        if (!self::enabled() || $now <= 0) return null;
        $cfg = self::config();
        $p = self::pull($dynamics, $now, $cfg);
        $suitors = [];
        foreach ((array) ($dynamics[self::STATE_KEY]['suitors'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $suitors[(string) ($e['name'] ?? '')] = round(self::interestAt($e, $now, $cfg), 2);
        }
        arsort($suitors);
        return ['pull' => $p['pull'], 'band' => $p['band'], 'titled' => $p['titled'], 'stepped_back' => $p['stepped_back'], 'unweakened' => $p['unweakened'],
                'low_cut' => $p['low_cut'], 'neglect' => $p['neglect'], 'suitor_interest' => array_slice($suitors, 0, 3, true)];
    }
}
