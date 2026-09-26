<?php
/**
 * Relationship Dynamics — memory translation layer and semantic anchors (roadmap
 * memory-translation-layer; MDD §12 High-Density Context Wrapping, pipeline Addendum 12 Semantic
 * Memory Anchoring).
 *
 * THE TRANSLATION LAYER (MDD §12). Vector memory clusters by what happened; a literal log loses
 * the subtext. Before an exchange becomes memory, RelDyn's numbers become a short wrapper of
 * high-impact words (M/F coordinates, arousal / valence, attachment style, plus the states that
 * colour a moment: duty, resentment, distance, pull, jealousy, an open conflict):
 *   "(Beneath this moment with Kaida, Aela the Huntress was operating strictly out of begrudging
 *    duty, cold and correct with her feelings locked away and harbouring a deep, unresolved
 *    resentment toward Kaida. What happened: Kaida asked for work; she named the bandit camp.)"
 * translate() is pure: clauses by salience, at most translation.max_clauses, within
 * translation.token_budget (RelDynFelt::estimateTokens). Words only, never a number (the event is
 * the eval summary cleaned by RelDynFelt::sanitizeReason).
 * CHIM 3.4.1 has no hook in its memory write path (lib/data_functions.php PackIntoSummary packs
 * memory_v = the memory table + speech + death / location rows into memory_summary, which the
 * summarizer and the vector search read). So the wrapper reaches core's memory the way core's
 * own AddFirstTimeMet note does: one row in the memory table at the exchange's game time, which
 * the packer puts into the same packed_message as the exchange's speech. commit.enabled (default
 * off: a new write into a core table; open question) writes it for every applied eval item of at
 * least commit.min_significance, once per item (memory.session holds the item's fingerprint), and
 * a load prunes it with core's memory rows (comm.php deletes memory past the loaded game time).
 *
 * SEMANTIC ANCHORS (Addendum 12). Key moments of the bond are kept in the dynamics'
 * memory_anchors sub-key, one per kind, never pruned (they resist the decay of the rolling
 * dimensional memory): first_meeting (the first contact while she was a stranger to the player:
 * RelDynReputation::metBefore false), first_gift / first_intimacy (the first applied eval item
 * tagged gift / intimacy; "first kiss" of the Addendum is the eval's 'intimacy' tag, "romantic or
 * sexual closeness"), first_combat (RelDynCombat::route: a fight she fought beside the player),
 * first_rescue (the player's caring answer to her fall, MDD 3.3), partners (core's type reaching
 * the committed romantic rung, RelDynRomance::rung >= 2, observed as a change: the Addendum's
 * proposal). Each holds its game time, the place (core's location context: name and hold), the
 * cleaned event and the translation wrapper of that moment. With commit on, an anchor also writes
 * a tagged note (core's #FirstTimeMet pattern) so the summarizer marks it.
 *   - Revisit: arriving (the place changed since her last context turn) at a place where anchors
 *     were made opens a revisit: for anchors.revisit.turns player turns within max_game_hours the
 *     'anchor' felt line recalls them (the context "remembers the first time you fought
 *     together"), in the sting form while the bond is strained; and a passion moment of
 *     revisit.passion_points spike trigger points (RelDynPassion::addSpike: temperament, floor,
 *     attraction and tier gates apply; none below the spike's min floor), at most once per place
 *     per revisit.cooldown_game_hours.
 *   - Maturity: every new anchor kind moves maturity by anchors.maturity_per_new_kind points
 *     (applyDelta: plasticity and rubber band apply). The Addendum ("more diverse anchors =
 *     higher maturity") names no size: 0 (off) until Ken picks one (open question).
 *
 * Units: gamets raw game calendar (RelationshipDynamics::GAMETS_PER_DAY a day); passion spike
 * points; maturity dimension points; significance 0..1 (eval contract); tokens estimated.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynMemory
{
    /** Addendum 12: "a memory_anchors sub-key in the relationship_dynamics data". kind => anchor. */
    const ANCHORS_KEY = 'memory_anchors';
    /** Lower-cased place name of her last context turn (arrival = a change of it). */
    const PLACE_KEY = '_anchor_place_last';
    /** The open revisit: {place, gamets, turns, kinds}. */
    const VISIT_KEY = '_anchor_visit';
    /** place => raw gamets of the last revisit passion moment there. */
    const BONUS_KEY = '_anchor_bonus_gamets';
    /** memory.event of the notes this layer writes. */
    const NOTE_EVENT = 'reldyn_subtext';
    const ANCHOR_EVENT = 'reldyn_anchor';

    const KINDS = ['first_meeting', 'first_gift', 'first_combat', 'first_rescue', 'first_intimacy', 'partners'];

    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'translation' => [
                'max_clauses' => 3,          // clauses in one wrapper
                'token_budget' => 60,        // estimated tokens of the clauses (estimateTokens)
                // state thresholds (dimension points 0..100; affinity core points -100..100;
                // arousal 0..100, valence -100..100)
                'resentment_deep' => 51.0, 'resentment_grudge' => 31.0,
                'distance_affinity_max' => -20.0, 'distance_warmth_max' => 25.0,
                'affection_affinity_min' => 60.0, 'affection_warmth_min' => 60.0,
                'drawn_passion_min' => 56.0,
                'jealousy_min' => 60.0,
                'mf_min_magnitude' => 15.0,  // |coord_m| or |coord_f| below this: no M/F clause
                // clause => salience (0..1): the order clauses are picked in
                'salience' => [
                    'duty' => 1.0, 'conflict' => 0.85, 'resentment' => 0.8, 'jealousy' => 0.75, 'distance' => 0.7,
                    'arousal_valence' => 0.6, 'attachment' => 0.55, 'drawn' => 0.5, 'affection' => 0.5, 'mf' => 0.45,
                ],
            ],
            'commit' => [
                // Write the wrapper / anchor notes into core's memory table (see the file comment).
                // Off by default: a new write into a core table (open question to Ken).
                'enabled' => false,
                'min_significance' => 0.3,   // eval significance 0..1 of an item that gets a note
                'anchors' => true,           // anchors write their tagged note too (with commit on)
            ],
            'anchors' => [
                'enabled' => true,
                'kinds' => self::KINDS,
                'min_tier' => 1,             // context tier from which the revisit line speaks
                'revisit' => [
                    'enabled' => true,
                    'turns' => 3,            // player turns the revisit line holds
                    'max_game_hours' => 6.0, // game-calendar hours it holds at most
                    'passion_points' => 3.0, // spike trigger points (RelDynPassion::addSpike; topic_match is 3)
                    'cooldown_game_hours' => 24.0,
                    // the channel of the moment (decisions §15): a memory is an emotional one
                    'passion_tags' => ['quality_time'],
                    'salience' => 0.55,
                ],
                // Addendum 12 "more diverse anchors = higher maturity": maturity points per new
                // kind. No size in the design: 0 = off (open question).
                'maturity_per_new_kind' => 0.0,
            ],
            'text' => [
                'note' => '(Beneath this moment with {PLAYER}, {NAME} was {CLAUSES}.{EVENT})',
                'note_event' => ' What happened: {EVENT}',
                'anchor_note' => '(Important note: {MOMENT} This is a defining moment between {NAME} and {PLAYER}, so use tag #{TAG}.)',
                'and' => ' and ',
                'clauses' => [
                    'duty'       => 'operating strictly out of begrudging duty',
                    'conflict'   => 'still hurt from their falling-out',
                    'resentment' => 'harbouring a deep, unresolved resentment toward {PLAYER}',
                    'grudge'     => 'nursing a quiet grudge against {PLAYER}',
                    'jealousy'   => 'stung by jealousy',
                    'distance'   => 'keeping an icy, transactional distance',
                    'affection'  => 'openly fond of {PLAYER}',
                    'drawn'      => 'quietly drawn to {PLAYER}',
                    'attachment' => [
                        'anxious'  => 'anxious for reassurance',
                        'avoidant' => 'guarding against getting too close',
                        'toxic'    => 'wanting closeness and bracing against it at once',
                    ],
                    'arousal_valence' => [
                        'high_positive' => 'buzzing with bright, restless energy',
                        'high_negative' => 'tense and on edge',
                        'low_positive'  => 'at ease',
                        'low_negative'  => 'numb, going through the motions',
                    ],
                    'mf' => [
                        '+M/+F' => 'steady and protective',
                        '+M/-F' => 'cold and correct, feelings locked away',
                        '-M/+F' => 'soft and yielding',
                        '-M/-F' => 'bitter and withdrawn, keeping score',
                    ],
                ],
                // what each anchor is, for the note ({PLACE_AT}: ' at <place>' or '')
                'anchor_moment' => [
                    'first_meeting'  => '{PLAYER} and {NAME} met for the first time{PLACE_AT}.',
                    'first_gift'     => '{PLAYER} gave {NAME} a gift for the first time{PLACE_AT}.',
                    'first_combat'   => '{NAME} fought beside {PLAYER} for the first time{PLACE_AT}.',
                    'first_rescue'   => "{PLAYER} came to {NAME}'s side after {NAME} fell in a fight, the first time {PLAYER} pulled {NAME} through{PLACE_AT}.",
                    'first_intimacy' => '{NAME} and {PLAYER} first shared a romantic closeness{PLACE_AT}.',
                    'partners'       => '{NAME} and {PLAYER} became partners{PLACE_AT}.',
                ],
                'anchor_tag' => [
                    'first_meeting' => 'FirstMeeting', 'first_gift' => 'FirstGift', 'first_combat' => 'FirstFightTogether',
                    'first_rescue' => 'FirstRescue', 'first_intimacy' => 'FirstCloseness', 'partners' => 'BecamePartners',
                ],
                // the revisit line (felt steering: no numbers)
                'revisit'          => 'This place brings it back to {NAME}: {ITEMS}, right here.',
                'revisit_strained' => 'This place brings it back to {NAME}, and now it aches: {ITEMS}, right here.',
                'recall' => [
                    'first_meeting'  => 'the first meeting with {PLAYER}',
                    'first_gift'     => 'the first gift from {PLAYER}',
                    'first_combat'   => 'the first fight side by side with {PLAYER}',
                    'first_rescue'   => 'the fall {PLAYER} pulled {NAME} back up from',
                    'first_intimacy' => 'the first closeness with {PLAYER}',
                    'partners'       => 'the day {NAME} and {PLAYER} became partners',
                ],
            ],
        ];
    }

    public static function config(): array
    {
        $d = self::configDefaults();
        $stored = RelationshipDynamics::configValue('memory_translation');
        if (!is_array($stored)) return $d;
        $cfg = array_replace($d, $stored);
        foreach (['translation', 'commit', 'anchors'] as $k) {
            $cfg[$k] = array_replace($d[$k], is_array($stored[$k] ?? null) ? $stored[$k] : []);
        }
        $cfg['anchors']['revisit'] = array_replace($d['anchors']['revisit'],
            is_array($stored['anchors']['revisit'] ?? null) ? $stored['anchors']['revisit'] : []);
        $cfg['translation']['salience'] = array_replace($d['translation']['salience'],
            is_array($stored['translation']['salience'] ?? null) ? $stored['translation']['salience'] : []);
        $cfg['text'] = array_replace_recursive($d['text'], is_array($stored['text'] ?? null) ? $stored['text'] : []);
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    // =====================================================================
    // THE TRANSLATION LAYER (MDD §12)
    // =====================================================================

    /**
     * The clauses of her state (clause key => text), most salient first, at most max_clauses
     * and within token_budget. $env: 'duty' bool (the exchange was quest duty, MDD 9).
     */
    public static function clauses(array $dynamics, string $npc, string $player, array $env = [], ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $tc = (array) $cfg['translation'];
        $txt = (array) $cfg['text']['clauses'];
        $sal = (array) $tc['salience'];
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $x = fn(string $d): ?float => is_numeric($dims[$d]['x'] ?? null) ? floatval($dims[$d]['x']) : null;
        $cand = [];   // key => [salience, text]
        $add = function (string $key, string $text, float $salience) use (&$cand): void {
            if (trim($text) !== '') $cand[$key] = [$salience, $text];
        };

        if (!empty($env['duty'])) $add('duty', (string) $txt['duty'], floatval($sal['duty']));
        if (!empty($dynamics['in_conflict'])) $add('conflict', (string) $txt['conflict'], floatval($sal['conflict']));
        $res = $x('resentment');
        if ($res !== null && $res >= floatval($tc['resentment_deep'])) {
            $add('resentment', (string) $txt['resentment'], floatval($sal['resentment']));
        } elseif ($res !== null && $res >= floatval($tc['resentment_grudge'])) {
            $add('resentment', (string) $txt['grudge'], floatval($sal['resentment']) * 0.8);
        }
        if (floatval($dynamics['jealousy_anger'] ?? 0) >= floatval($tc['jealousy_min'])) {
            $add('jealousy', (string) $txt['jealousy'], floatval($sal['jealousy']));
        }
        $aff = is_numeric($dynamics['_aff_mirror_x'] ?? null) ? RelationshipDynamics::getCoreAffinity($dynamics) : null;
        $warmth = RelDynPassion::warmth($dynamics);
        if (($aff !== null && $aff <= floatval($tc['distance_affinity_max']))
            || ($warmth !== null && $warmth <= floatval($tc['distance_warmth_max']) && ($aff === null || $aff <= 0.0))) {
            $add('distance', (string) $txt['distance'], floatval($sal['distance']));
        } elseif ($aff !== null && $aff >= floatval($tc['affection_affinity_min'])
            && ($warmth === null || $warmth >= floatval($tc['affection_warmth_min']))) {
            $add('affection', (string) $txt['affection'], floatval($sal['affection']));
        }
        $arousal = $x('arousal');
        $valence = $x('valence');
        if ($arousal !== null && $valence !== null) {
            $band = RelationshipDynamics::getArousalValenceBand($arousal, $valence);
            $k = $band['arousal_level'] . '_' . $band['valence_level'];
            if (isset($txt['arousal_valence'][$k])) $add('arousal_valence', (string) $txt['arousal_valence'][$k], floatval($sal['arousal_valence']));
        }
        $style = RelationshipDynamics::getAttachmentStyle($dynamics);
        if (isset($txt['attachment'][$style])) $add('attachment', (string) $txt['attachment'][$style], floatval($sal['attachment']));
        $passion = RelationshipDynamics::getEffectivePassion($dynamics);
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $platonic = !empty($att['enabled']) && (!empty($att['hard_zero']) || ($att['attracted'] ?? true) === false);
        if (!$platonic && $passion >= floatval($tc['drawn_passion_min'])) $add('drawn', (string) $txt['drawn'], floatval($sal['drawn']));
        $m = $x('coord_m');
        $f = $x('coord_f');
        if ($m !== null && $f !== null && max(abs($m), abs($f)) >= floatval($tc['mf_min_magnitude'])) {
            $q = RelationshipDynamics::getMFQuadrantBand($m, $f)['quadrant'];
            if (isset($txt['mf'][$q])) $add('mf', (string) $txt['mf'][$q], floatval($sal['mf']));
        }

        uasort($cand, fn($a, $b) => $b[0] <=> $a[0]);
        $vars = ['{NAME}' => $npc, '{PLAYER}' => $player];
        $out = [];
        $tokens = 0;
        foreach ($cand as $key => [$s, $text]) {
            if (count($out) >= max(1, intval($tc['max_clauses']))) break;
            $t = strtr($text, $vars);
            $n = RelDynFelt::estimateTokens($t);
            if ($out !== [] && $tokens + $n > intval($tc['token_budget'])) continue;
            $out[$key] = $t;
            $tokens += $n;
        }
        return $out;
    }

    /** "a, b and c" */
    private static function join(array $parts, string $and): string
    {
        $parts = array_values($parts);
        if (count($parts) <= 1) return (string) ($parts[0] ?? '');
        $last = array_pop($parts);
        return implode(', ', $parts) . $and . $last;
    }

    /**
     * The MDD §12 wrapper of one moment: her state in words and the event, or null when her state
     * says nothing past the neutral (no clause) and there is no event. $summary: the eval's
     * summary (cleaned here).
     */
    public static function translate(array $dynamics, string $npc, string $player, ?string $summary, array $env = [], ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $t = (array) $cfg['text'];
        $clauses = self::clauses($dynamics, $npc, $player, $env, $cfg);
        if ($clauses === []) return null;
        $event = $summary !== null && trim($summary) !== '' ? RelDynFelt::sanitizeReason($summary) : null;
        $eventText = $event !== null ? strtr((string) $t['note_event'], ['{EVENT}' => rtrim($event, '.') . '.']) : '';
        return strtr((string) $t['note'], ['{NAME}' => $npc, '{PLAYER}' => $player,
            '{CLAUSES}' => self::join($clauses, (string) $t['and']), '{EVENT}' => $eventText]);
    }

    /**
     * One row in core's memory table (logMemory's columns), once per $key (memory.session):
     * INSERT ... WHERE NOT EXISTS, one statement. Returns true when written.
     */
    private static function writeNote(string $speaker, string $listener, string $message, float $gamets, string $event, string $key): bool
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $gamets <= 0) return false;
        $row = $db->fetchOne(
            'INSERT INTO memory (localts, speaker, listener, message, gamets, session, momentum, event, ts)
             SELECT $1::bigint, $2, $3, $4, $5::bigint, $6, $6, $7, $5::bigint
             WHERE NOT EXISTS (SELECT 1 FROM memory WHERE event = $7 AND session = $6)
             RETURNING gamets',
            [time(), $speaker, $listener, $message, (string) (int) round($gamets), $key, $event]
        );
        return isset($row['gamets']);
    }

    // =====================================================================
    // EVAL ITEMS (processEvalContractItem)
    // =====================================================================

    /**
     * One applied eval item ($n normalized, after its signals and feelings): the anchors its tags
     * make (first_gift, first_intimacy; first_rescue when $rescued) and, with commit on, the
     * wrapper note of the exchange at its game time. $fingerprint: the item's
     * (evalContractFingerprint), so a re-applied item writes no second note.
     */
    public static function onEvalItem(string $npc, array $n, array &$dynamics, float $gamets, string $fingerprint, bool $rescued = false): void
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return;
        $player = trim((string) ($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player'));
        $tags = array_map(fn($t) => strtolower((string) $t), (array) ($n['tags'] ?? []));
        $summary = is_string($n['summary'] ?? null) ? $n['summary'] : null;
        $env = ['duty' => isset($n['duty_factor']) && floatval($n['duty_factor']) < 1.0];
        if (in_array('gift', $tags, true)) self::noteAnchor($npc, $dynamics, 'first_gift', $gamets, $summary, $env);
        if (in_array('intimacy', $tags, true)) self::noteAnchor($npc, $dynamics, 'first_intimacy', $gamets, $summary, $env);
        if ($rescued) self::noteAnchor($npc, $dynamics, 'first_rescue', $gamets, $summary, $env);

        $c = (array) $cfg['commit'];
        if (empty($c['enabled']) || floatval($n['significance'] ?? 0) < floatval($c['min_significance'])) return;
        $note = self::translate($dynamics, $npc, $player, $summary, $env, $cfg);
        if ($note === null) return;
        try {
            if (self::writeNote($npc, $player, $note, $gamets, self::NOTE_EVENT, 'reldyn:' . $fingerprint)) {
                RelationshipDynamics::log("[RelDyn-MEMORY] {$npc}: subtext note at gamets {$gamets}: {$note}");
            }
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("memory subtext note for {$npc}", $e);
        }
    }

    // =====================================================================
    // ANCHORS (Addendum 12)
    // =====================================================================

    /** The anchors (kind => anchor). */
    public static function anchors(array $dynamics): array
    {
        $a = $dynamics[self::ANCHORS_KEY] ?? [];
        return is_array($a) ? $a : [];
    }

    /**
     * Keep $kind as an anchor of this bond unless it already has one (the first stays). Place:
     * core's location context now (RelDynFacets::currentPlaceContext); unknown place: none. With
     * commit on (commit.anchors), its tagged note. With maturity_per_new_kind, her maturity moves.
     * Returns true when the anchor was made.
     */
    public static function noteAnchor(string $npc, array &$dynamics, string $kind, float $gamets, ?string $summary = null, array $env = []): bool
    {
        $cfg = self::config();
        $ac = (array) $cfg['anchors'];
        if (empty($cfg['enabled']) || empty($ac['enabled']) || !in_array($kind, (array) $ac['kinds'], true)) return false;
        $anchors = self::anchors($dynamics);
        if (isset($anchors[$kind])) return false;
        $player = trim((string) ($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player'));
        $place = null;
        try {
            $ctx = RelDynFacets::currentPlaceContext($npc);
            if (!empty($ctx['known']) && trim((string) $ctx['name']) !== '') {
                $place = ['name' => trim((string) $ctx['name']), 'hold' => trim((string) ($ctx['hold'] ?? ''))];
            }
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("anchor place for {$npc}", $e);
        }
        $event = $summary !== null && trim($summary) !== '' ? RelDynFelt::sanitizeReason($summary) : null;
        $anchor = ['gamets' => $gamets > 0 ? $gamets : RelationshipDynamics::currentGamets(), 'place' => $place,
            'event' => $event, 'subtext' => self::translate($dynamics, $npc, $player, null, $env, $cfg)];
        $anchors[$kind] = $anchor;
        $dynamics[self::ANCHORS_KEY] = $anchors;
        // Made here: being here is no arrival
        if ($place !== null && !isset($dynamics[self::PLACE_KEY])) $dynamics[self::PLACE_KEY] = strtolower($place['name']);
        RelationshipDynamics::log("[RelDyn-MEMORY] {$npc}: anchor {$kind} at " . ($place['name'] ?? 'an unknown place') . " (gamets {$anchor['gamets']})");

        $mat = floatval($ac['maturity_per_new_kind']);
        if (abs($mat) > 1e-9 && isset($dynamics['dimensions']['maturity'])) {
            $moved = RelationshipDynamics::applyDelta('maturity', $dynamics, $mat, $dynamics['inferred_temperament'] ?? null);
            RelationshipDynamics::log(sprintf('[RelDyn-MEMORY] %s: a new kind of anchor (%s): maturity %+.3f', $npc, $kind, $moved));
        }

        $c = (array) $cfg['commit'];
        if (!empty($c['enabled']) && !empty($c['anchors'])) {
            $t = (array) $cfg['text'];
            $vars = ['{NAME}' => $npc, '{PLAYER}' => $player, '{PLACE_AT}' => $place !== null ? " at {$place['name']}" : ''];
            $moment = strtr((string) ($t['anchor_moment'][$kind] ?? ''), $vars);
            $note = strtr((string) $t['anchor_note'], $vars + ['{MOMENT}' => $moment, '{TAG}' => (string) ($t['anchor_tag'][$kind] ?? $kind)]);
            try {
                $npcKey = strtolower(preg_replace('/\s+/', '_', trim($npc)));
                self::writeNote($player, $npc, $note, $anchor['gamets'], self::ANCHOR_EVENT, "reldyn:anchor:{$npcKey}:{$kind}");
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("memory anchor note for {$npc}", $e);
            }
        }
        return true;
    }

    /**
     * On contact (prerequest, after core's type snapshot and the romance guard): the first meeting
     * (a contact while she does not know the player: RelDynReputation::metBefore false, $coreAff
     * core points), and becoming partners (core's type reached the committed romantic rung since
     * her last request: $previousType is the type before this request's refresh).
     */
    public static function onContact(string $npc, array &$dynamics, ?string $previousType, ?float $coreAff, float $now): void
    {
        if (!self::enabled()) return;
        $anchors = self::anchors($dynamics);
        if (!isset($anchors['first_meeting']) && !RelDynReputation::metBefore($dynamics, $coreAff)) {
            self::noteAnchor($npc, $dynamics, 'first_meeting', $now);
        }
        self::notePartners($npc, $dynamics, $previousType, $now);
    }

    /** The partners anchor when core's type went from below the committed romantic rung to it. */
    public static function notePartners(string $npc, array &$dynamics, ?string $previousType, float $now): bool
    {
        if (!self::enabled() || isset(self::anchors($dynamics)['partners'])) return false;
        $now_ = (string) ($dynamics['_core_rel_type'] ?? '');
        if ($previousType === null || RelDynRomance::rung($now_) < 2 || RelDynRomance::rung($previousType) >= 2) return false;
        return self::noteAnchor($npc, $dynamics, 'partners', $now);
    }

    // =====================================================================
    // REVISITS (context turn)
    // =====================================================================

    /**
     * Her context turn (RelDynFelt::compose): $ctx core's place now (currentPlaceContext shape; null
     * = read it here). An arrival at a place holding anchors opens a revisit (and its passion
     * moment, cooled down per place); while it holds, the recall line. $tier: her context tier;
     * $strained: the bond is strained (the felt compose's test); $addressed: the player speaks to
     * her this turn (the turns count those).
     *
     * @return array ['text' => ?string, 'changed' => bool, 'spike' => float passion spike points added]
     */
    public static function contextTurn(string $npc, string $playerRef, array &$dynamics, ?array $ctx, float $now, int $tier, bool $strained, bool $addressed): array
    {
        $out = ['text' => null, 'changed' => false, 'spike' => 0.0];
        $cfg = self::config();
        $ac = (array) $cfg['anchors'];
        $rv = (array) $ac['revisit'];
        if (empty($cfg['enabled']) || empty($ac['enabled'])) return $out;
        if ($ctx === null) {
            try {
                $ctx = RelDynFacets::currentPlaceContext($npc);
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("anchor revisit place for {$npc}", $e);
                return $out;
            }
        }
        $place = !empty($ctx['known']) ? strtolower(trim((string) ($ctx['name'] ?? ''))) : '';
        if ($place === '') return $out;
        $last = isset($dynamics[self::PLACE_KEY]) ? (string) $dynamics[self::PLACE_KEY] : null;
        if ($last !== $place) {
            $dynamics[self::PLACE_KEY] = $place;
            $out['changed'] = true;
            unset($dynamics[self::VISIT_KEY]);
            $kinds = [];
            foreach (self::anchors($dynamics) as $kind => $a) {
                if (is_array($a['place'] ?? null) && strtolower((string) ($a['place']['name'] ?? '')) === $place) $kinds[] = (string) $kind;
            }
            // An arrival (a first read has no before: not an arrival)
            if ($last !== null && $kinds !== [] && !empty($rv['enabled'])) {
                $dynamics[self::VISIT_KEY] = ['place' => $place, 'gamets' => $now, 'turns' => 0, 'kinds' => $kinds];
                $bonus = is_array($dynamics[self::BONUS_KEY] ?? null) ? $dynamics[self::BONUS_KEY] : [];
                $prev = floatval($bonus[$place] ?? 0);
                $cool = floatval($rv['cooldown_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
                if (floatval($rv['passion_points']) > 0.0 && ($prev <= 0.0 || $now < $prev || $now - $prev >= $cool)) {
                    $out['spike'] = RelDynPassion::addSpike($npc, $dynamics, floatval($rv['passion_points']), 'anchor_revisit',
                        array_values(array_map('strval', (array) $rv['passion_tags'])));
                    $bonus[$place] = $now;
                    $dynamics[self::BONUS_KEY] = $bonus;
                }
                RelationshipDynamics::log("[RelDyn-MEMORY] {$npc}: back at {$place} (" . implode(', ', $kinds) . ')'
                    . ($out['spike'] > 0 ? sprintf(' passion moment +%.2f', $out['spike']) : ''));
            }
        }
        $visit = $dynamics[self::VISIT_KEY] ?? null;
        if (!is_array($visit)) return $out;
        $maxAge = floatval($rv['max_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        if (($visit['place'] ?? '') !== $place || intval($visit['turns'] ?? 0) >= max(1, intval($rv['turns']))
            || $now < floatval($visit['gamets'] ?? 0) || $now - floatval($visit['gamets'] ?? 0) > $maxAge) {
            unset($dynamics[self::VISIT_KEY]);
            $out['changed'] = true;
            return $out;
        }
        if ($addressed) {
            $dynamics[self::VISIT_KEY]['turns'] = intval($visit['turns'] ?? 0) + 1;
            $out['changed'] = true;
        }
        if ($tier < intval($ac['min_tier'])) return $out;
        $t = (array) $cfg['text'];
        $vars = ['{NAME}' => $npc, '{PLAYER}' => $playerRef];
        $items = [];
        foreach (self::KINDS as $kind) {
            if (in_array($kind, (array) ($visit['kinds'] ?? []), true) && isset($t['recall'][$kind])) $items[] = strtr((string) $t['recall'][$kind], $vars);
        }
        if ($items === []) return $out;
        $out['text'] = strtr((string) $t[$strained ? 'revisit_strained' : 'revisit'], $vars + ['{ITEMS}' => self::join($items, (string) $t['and'])]);
        return $out;
    }

    /** The anchors for the page / editor (numbers and places allowed there, never for the LLM). */
    public static function describe(array $dynamics): array
    {
        $out = [];
        foreach (self::anchors($dynamics) as $kind => $a) {
            $out[] = ['kind' => (string) $kind, 'gamets' => floatval($a['gamets'] ?? 0), 'place' => $a['place']['name'] ?? null,
                'hold' => $a['place']['hold'] ?? null, 'event' => $a['event'] ?? null, 'subtext' => $a['subtext'] ?? null];
        }
        return $out;
    }
}
