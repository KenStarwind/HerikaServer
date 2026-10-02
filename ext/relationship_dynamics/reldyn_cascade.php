<?php
/**
 * Relationship Dynamics — the cascading affinity network (roadmap cascade-network; MDD §10,
 * dimension draft "Cascading Affinity"). Word travels: what the player does to one NPC reaches
 * the NPCs who care about her, and they feel it the next time they deal with the player.
 *
 * LAZY (MDD §10.1, "Anti-Bloat Architecture"): nothing is applied to a target when the source's
 * affinity moves. The source's eval consumer notes a ripple (noteItem), the consumer's caller
 * queues it on every target who holds a bond to the source (flush: one appended item on the
 * target's own plugin storage, reldyn.cascade_inbox), and the target applies its pending ripples
 * at ITS next prerequest (onPrerequest), after its affinity mirror was read from core, through
 * queueAffinityDelta + commitPlayerAffinity, so the change lands in core's relationships.Player.aff
 * and is not overwritten by the mirror refresh (the old eager version wrote only the target's
 * mirror and was overwritten).
 *
 * TRIGGER (the eval path, where affinity actually moves; the legacy intDelta branch of
 * postrequest can never reach the threshold): per applied eval item, when the item's own change
 * of the source's affinity toward the player (core points, what the item moved the mirror by,
 * x2) is at least cascade_threshold. A DEFINING moment (significance at or above
 * cascade.defining.significance, or a betrayal / rescue exchange of at least
 * cascade.defining.tag_significance) ripples from the lower cascade.defining.min_delta: the
 * moments people retell. A defining moment that moved nothing has no direction and does not ripple.
 *
 * WHO HEARS (target = an NPC holding a bond to the source; the MDD's "Farkas, 80 affinity to
 * Aela"): the target's OWN affinity toward the source decides, its magnitude above
 * cascade.min_bond (core points; the bond filter) and at most cascade.max_targets of them (the
 * strongest bonds first, CASCADE_MAX_TARGETS by default). The ripple, in core points before the target's own
 * curve (rulings 2026-10-01 §20 #13: leaning to the MDD, where Farkas at 80 loses about 8 of a 10 drop; that figure is
 * THIS ripple, queued for a witness. Through the target's own curve, below, he ends up with 5 to 7 of the 10, the
 * social-sensitivity curve taking a further fifth to a third off: the per-target differences the curve exists for.
 * Whether a witness should be spared the curve is Ken's to rule):
 *     ripple = change x (|bond| / 100) x hearsay x (bond < 0 ? -cascade.enemy_mult : 1)
 * hearsay = 1 for what she saw herself and cascade_decay (0.9) for what she was told or heard of
 * later: little is lost in the telling, but a retelling is still softer than the thing. An ally takes the
 * source's side (her hurt is felt), an enemy takes the opposite (the inverted ripple, weaker: enemy_mult 0.5).
 * A ripple under cascade.min_ripple is dropped. One hop only.
 *
 * WHEN SHE HEARS (rulings §20 #14: no telepathic ripples; a fight in Solitude is not known in Whiterun at once).
 * flush() stamps each queued ripple with HOW it will reach the target:
 *     witnessed  she was there (the eval item's witnesses, the eventlog people at the exchange; CACHE_PEOPLE
 *                for an exchange that is happening now): deliverable at once.
 *     delayed    otherwise it carries ready_at, the earliest game time it can reach her: the news's own gamets plus
 *                a delay from the hold distance (the prompt-gating hold graph, between where the source was and where
 *                she was last seen) and her bond to the source: sooner the closer, by place and by bond.
 *     told       a delayed ripple that arrives early: she and the source actually talked (an NPC-to-NPC exchange,
 *                a radiant round or a rechat, noteTalk from the context hook). Word of mouth needs no clock.
 * A pending ripple waits in her inbox; onPrerequest applies only what has become deliverable.
 *
 * AT THE TARGET: the ripple goes through the target's social sensitivity curve at ITS bond with
 * the player (cascadeSocialSensitivity: an Inner Circle NPC barely hears about a stranger, an Open
 * Heart NPC hears everything), then the accumulated whole points are committed to core and the
 * fraction waits for the next change. Each ripple carries an id; the target remembers the last
 * APPLIED_KEEP, so a ripple queued twice (a crash between queueing and the source's save) lands
 * once. The inbox is trimmed only after the target's state, with those ids and the pending
 * delta, is saved: nothing is lost and nothing is applied twice.
 *
 * FELT (feelings, never numbers): one one-shot line per ripple, "has heard what the player did to
 * <source>", said at the target's next player-addressed turn (takeFeltLines, composed in
 * reldyn_felt.php), by who the ripple made her to the player: an ally hurt or helped, a rival
 * hurt or helped. The reason anchor (the item's summary, cleaned of scores) rides along.
 *
 * NOT BUILT (judged, review queue): writing another NPC's core rows from inside a request that is not hers. "At once"
 * for a witness means deliverable at once: it lands at HER own next prerequest, her load, which is the lazy design
 * (the eager version the audit retired wrote other NPCs' rows from the source's request). The one thing a request
 * writes for someone else is a line in her talk ledger (noteTalk), appended atomically.
 *
 * Units: core affinity points (-100..100); gamets raw game calendar.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynCascade
{
    /** dynamics key: ripples this NPC's eval items made that are not yet queued on their targets. */
    const OUT_KEY = '_cascade_out';
    /** dynamics key: ids of ripples this NPC applied (the last APPLIED_KEEP). */
    const APPLIED_KEY = '_cascade_applied';
    /** dynamics key: one-shot felt entries waiting for a player-addressed turn. */
    const FELT_KEY = '_cascade_felt';
    /** plugin_extended_data.reldyn key of the pending ripples (a list, appended atomically). */
    const INBOX_KEY = 'cascade_inbox';
    /** plugin_extended_data.reldyn key of who she talked to while a ripple of theirs waited: [['with', 'gamets']] (appended atomically). */
    const TALK_KEY = 'cascade_talk';

    const OUT_MAX = 8;
    const APPLIED_KEEP = 64;
    const FELT_MAX = 4;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'cascade' (cascade_network_enabled, cascade_threshold and
     * cascade_decay stay the top-level keys they always were).
     */
    public static function configDefaults(): array
    {
        return [
            'min_bond' => 30.0,          // core points: |the target's affinity toward the source| must exceed this to hear
            'enemy_mult' => 0.5,         // unitless: an enemy's inverted ripple is this much of an ally's
            'min_ripple' => 1.0,         // core points: a smaller ripple is not worth carrying
            'max_targets' => RelationshipDynamics::CASCADE_MAX_TARGETS,   // targets per ripple (the strongest bonds)
            'defining' => [
                'significance' => 0.8,       // 0..1: an exchange this significant is a defining moment
                'tags' => ['betrayal', 'rescue'],   // exchanges of these kinds are defining at tag_significance
                'tag_significance' => 0.4,   // 0..1
                'min_delta' => 3.0,          // core points: a defining moment ripples from this change on
            ],
            // How the news reaches someone who was not there (rulings §20 #14). Game hours unless said.
            //   delay = (base + per_hold_step x hold steps) x (1 - bond_relief x |bond| / 100), within min..max
            // Hold steps are the prompt-gating hold graph's; an unknown place is as far as that graph's
            // unknown_hold_distance. Same hold and a close friend: about an hour; across the map: days.
            'delivery' => [
                'witness_window_game_minutes' => 60.0,   // CACHE_PEOPLE counts as present only for an exchange this recent
                'base_game_hours' => 2.0,                // the same hold, a bond of 0
                'per_hold_step_game_hours' => 8.0,       // each hold of distance adds this
                'bond_relief' => 0.5,                    // 0..1: a bond of 100 cuts the delay by this share
                'min_game_hours' => 0.25,                // never instant: news takes a moment to travel
                'max_game_hours' => 168.0,               // and never longer than a game week
                'last_seen_lookback_game_days' => 60.0,  // where she was last seen: eventlog rows this recent
            ],
            'felt' => [
                'enabled' => true,
                'salience' => 0.7,           // 0..1 among the turn's felt lines
            ],
            'felt_text' => [
                // {NAME} the hearer, {PLAYER} the player, {SOURCE} who it happened to, {REASON} " (what happened)" or nothing,
                // {HEARD} 'seen' when she was there and 'heard' otherwise
                'ally_hurt'    => "{NAME} has {HEARD} what {PLAYER} did to {SOURCE}{REASON}; it sits badly, and {NAME} is cooler toward {PLAYER} for it.",
                'ally_helped'  => "{NAME} has {HEARD} what {PLAYER} did for {SOURCE}{REASON}; {NAME} thinks better of {PLAYER} for it.",
                'rival_hurt'   => "{NAME} has {HEARD} what {PLAYER} did to {SOURCE}{REASON}; {NAME} is not sorry for {SOURCE}, and warms to {PLAYER} for it.",
                'rival_helped' => "{NAME} has {HEARD} what {PLAYER} did for {SOURCE}{REASON}; {NAME} resents it, and is cooler toward {PLAYER} for it.",
            ],
        ];
    }

    /** The section as RelDyn reads it: the stored row laid over the defaults key by key, one level down too. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('cascade');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['defining', 'delivery', 'felt', 'felt_text'] as $k) {
            $cfg[$k] = is_array($stored[$k] ?? null) ? array_replace($defaults[$k], $stored[$k]) : $defaults[$k];
        }
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(RelationshipDynamics::configValue('cascade_network_enabled'));
    }

    // =====================================================================
    // THE SOURCE: an eval item moved her affinity; note the ripple
    // =====================================================================

    /** Is this (normalized) eval item a defining moment? */
    public static function isDefining(array $n, ?array $cfg = null): bool
    {
        $d = ($cfg ?? self::config())['defining'];
        $sig = floatval($n['significance'] ?? 0.0);
        if ($sig >= floatval($d['significance'])) return true;
        $tags = array_map('strtolower', array_map('strval', (array) ($n['tags'] ?? [])));
        $kinds = array_map('strtolower', array_map('strval', (array) $d['tags']));
        return $sig >= floatval($d['tag_significance']) && array_intersect($tags, $kinds) !== [];
    }

    /**
     * An applied eval item of $npcName: when it moved her affinity enough, keep the ripple on
     * her state (saved with it, with the item's fingerprint, so it exists exactly once) for
     * flush() to queue. Called by processEvalContractItem right after the signals.
     *
     * @param array $totals dimension => actual change, as processEvalContractItem holds them (affinity in mirror units, core = x2)
     * @return array|null the noted ripple, null when the item does not ripple
     */
    public static function noteItem(string $npcName, array $n, array $totals, array &$dynamics, float $gamets, string $fingerprint, ?string $anchor): ?array
    {
        if (!self::enabled()) return null;
        $delta = round(floatval($totals['affinity'] ?? 0.0) * 2.0, 3);
        if (abs($delta) < 0.0001) return null;
        $cfg = self::config();
        $threshold = floatval(RelationshipDynamics::configValue('cascade_threshold'));
        $defining = self::isDefining($n, $cfg);
        $needed = $defining ? min($threshold, floatval($cfg['defining']['min_delta'])) : $threshold;
        if (abs($delta) < $needed) return null;

        // Who saw it and where she was: how the news travels from here (rulings §20 #14), read now, while she is
        // still in the room. Witnesses null = the item recorded none (the producer's eventlog people).
        $witnesses = is_array($n['witnesses'] ?? null) ? array_values(array_filter(array_map('strval', $n['witnesses']), fn($w) => trim($w) !== '')) : null;
        $entry = ['fp' => $fingerprint, 'delta' => $delta, 'gamets' => $gamets, 'anchor' => $anchor, 'defining' => $defining,
                  'witnesses' => $witnesses, 'hold' => self::lastSeenHold($npcName, $gamets, $cfg)];
        $out = is_array($dynamics[self::OUT_KEY] ?? null) ? array_values($dynamics[self::OUT_KEY]) : [];
        foreach ($out as $e) {
            if (($e['fp'] ?? null) === $fingerprint) return null;   // already noted
        }
        $out[] = $entry;
        $dynamics[self::OUT_KEY] = array_slice($out, -self::OUT_MAX);
        RelationshipDynamics::log("[CASCADE] {$npcName}: an eval item moved their affinity " . sprintf('%+.2f', $delta)
            . ($defining ? ' (a defining moment)' : '') . '; the ripple waits to be queued');
        return $entry;
    }

    // =====================================================================
    // THE NETWORK: who hears (pure) and queueing
    // =====================================================================

    /**
     * The ripple one target hears of a change: null when the bond is too weak or the ripple too small.
     *
     * @param float $delta core points the source's affinity toward the player moved by
     * @param float $bond  the target's affinity toward the source (core points)
     * @param string|null $via how it reaches her: 'witnessed' (or null: what a witness takes in, the MDD's own figure)
     *                         is undamped; 'told' and 'delayed' are hearsay, damped by cascade_decay
     */
    public static function rippleFor(float $delta, float $bond, ?array $cfg = null, ?string $via = null): ?float
    {
        $cfg = $cfg ?? self::config();
        if (abs($bond) <= floatval($cfg['min_bond'])) return null;
        $hearsay = ($via === null || $via === 'witnessed') ? 1.0 : floatval(RelationshipDynamics::configValue('cascade_decay'));
        $ripple = $delta * min(1.0, abs($bond) / 100.0) * $hearsay * ($bond < 0 ? -floatval($cfg['enemy_mult']) : 1.0);
        return abs($ripple) < floatval($cfg['min_ripple']) ? null : round($ripple, 3);
    }

    // =====================================================================
    // HOW THE NEWS TRAVELS (rulings 2026-10-01 §20 #14)
    // =====================================================================

    /** Names in an eventlog people column or CACHE_PEOPLE ("|A|B (state)|"), lowercased, states stripped, as keys. */
    private static function peopleKeys(?string $people): array
    {
        $out = [];
        foreach (explode('|', (string) $people) as $p) {
            $p = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $p));
            if ($p !== '') $out[mb_strtolower($p)] = true;
        }
        return $out;
    }

    /**
     * Was $target at the exchange the ripple is about? The eval item's witnesses are the record (the eventlog
     * people of its rows). An item that recorded none falls back to CACHE_PEOPLE, but only while the exchange is
     * still happening (within delivery.witness_window_game_minutes of $now): whoever is around later was not there.
     * The player is never a target.
     */
    public static function isWitness(string $target, array $entry, ?string $cachePeople, float $now, ?array $cfg = null): bool
    {
        $key = mb_strtolower(trim($target));
        if ($key === '' || RelationshipDynamics::isPlayerRelationshipKey($target)
            || $key === mb_strtolower(trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')))) {
            return false;
        }
        $witnesses = $entry['witnesses'] ?? null;
        if (is_array($witnesses)) {
            return isset(self::peopleKeys('|' . implode('|', array_map('strval', $witnesses)) . '|')[$key]);
        }
        $cfg = $cfg ?? self::config();
        $at = floatval($entry['gamets'] ?? 0);
        $window = max(0.0, floatval($cfg['delivery']['witness_window_game_minutes'])) * RelationshipDynamics::GAMETS_PER_DAY / 1440.0;
        if ($now <= 0 || $at <= 0 || abs($now - $at) > $window) return false;
        return isset(self::peopleKeys($cachePeople)[$key]);
    }

    /** Hold steps between two holds on the prompt-gating graph; an unknown place is as far as that graph's unknown_hold_distance. */
    public static function holdSteps(string $from, string $to): int
    {
        $steps = RelDynGating::holdDistance($from, $to);
        return $steps ?? intval(RelDynGating::config()['unknown_hold_distance']);
    }

    /**
     * Game hours the news takes to reach someone $steps holds away who holds $bond to the source:
     * (base + per_hold_step x steps) x (1 - bond_relief x |bond| / 100), within min_game_hours..max_game_hours.
     */
    public static function delayGameHours(int $steps, float $bond, ?array $cfg = null): float
    {
        $d = ($cfg ?? self::config())['delivery'];
        $hours = (floatval($d['base_game_hours']) + floatval($d['per_hold_step_game_hours']) * max(0, $steps))
            * (1.0 - max(0.0, min(1.0, floatval($d['bond_relief']))) * min(1.0, abs($bond) / 100.0));
        return max(floatval($d['min_game_hours']), min(floatval($d['max_game_hours']), $hours));
    }

    /**
     * How a ripple of $entry reaches a target who was NOT there: 'delayed', with the earliest game time it can
     * be delivered (ready_at, raw gamets: the news's own gamets plus the delay) and the hold steps it travels.
     *
     * @param string $targetHold where she was last seen ('' when unknown)
     */
    public static function deliveryAt(array $entry, string $targetHold, float $bond, float $now, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $steps = self::holdSteps((string) ($entry['hold'] ?? ''), $targetHold);
        $at = floatval($entry['gamets'] ?? 0);
        if ($at <= 0) $at = $now;
        $hours = self::delayGameHours($steps, $bond, $cfg);
        return ['via' => 'delayed', 'ready_at' => round($at + $hours * RelationshipDynamics::GAMETS_PER_DAY / 24.0, 3),
                'steps' => $steps, 'delay_hours' => round($hours, 3)];
    }

    /**
     * The hold $npcName was last seen in: the newest eventlog row that had her present (people column), its location
     * (core logs the whole context string, "(Context location: X ,Hold: Y, ...)": the hold is read from it; a bare
     * place name is looked up in core's locations table). '' when never seen lately, no database, or the place is not
     * on the map. At most delivery.last_seen_lookback_game_days before $before (default: now).
     */
    public static function lastSeenHold(string $npcName, ?float $before = null, ?array $cfg = null): string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($npcName) === '') return '';
        $cfg = $cfg ?? self::config();
        $before = $before ?? RelationshipDynamics::currentGamets();
        $since = $before > 0 ? (int) floor($before - max(0.0, floatval($cfg['delivery']['last_seen_lookback_game_days'])) * RelationshipDynamics::GAMETS_PER_DAY) : 0;
        $bare = $db->escapeLiteral('|' . mb_strtolower(trim($npcName)) . '|');
        $stated = $db->escapeLiteral('|' . mb_strtolower(trim($npcName)) . ' (');
        try {
            $row = $db->fetchOne(
                "SELECT e.location FROM eventlog e
                 WHERE e.location IS NOT NULL AND e.location <> '' AND e.gamets >= {$since}
                   AND (position({$bare} in lower(e.people)) > 0 OR position({$stated} in lower(e.people)) > 0)
                 ORDER BY e.gamets DESC, e.rowid DESC LIMIT 1");
            $location = is_array($row) ? trim((string) ($row['location'] ?? '')) : '';
            if ($location === '') return '';
            $parsed = RelDynFacets::parseLocationContext($location);
            if ($parsed['hold'] !== '') return $parsed['hold'];
            // not core's context string: a bare place name
            $place = $parsed['name'] !== '' ? $parsed['name'] : $location;
            $loc = $db->fetchOne('SELECT hold FROM locations WHERE lower(name) = lower(' . $db->escapeLiteral($place) . ') LIMIT 1');
            return is_array($loc) ? trim((string) ($loc['hold'] ?? '')) : '';
        } catch (\Throwable $ex) {
            RelationshipDynamics::logError("cascade: where {$npcName} was last seen", $ex);
            return '';
        }
    }

    /**
     * How a ripple of $entry reaches $target (bond $bond to the source): ['via' => 'witnessed'|'delayed',
     * 'ready_at', + 'steps', 'delay_hours' for a delayed one]. A witness is told at once; anyone else is placed on
     * the hold graph by where she was last seen.
     */
    public static function deliveryFor(string $target, float $bond, array $entry, float $now, ?array $cfg = null, ?string $cachePeople = null): array
    {
        $cfg = $cfg ?? self::config();
        if (self::isWitness($target, $entry, $cachePeople ?? (string) ($GLOBALS['CACHE_PEOPLE'] ?? ''), $now, $cfg)) {
            $at = floatval($entry['gamets'] ?? 0);
            return ['via' => 'witnessed', 'ready_at' => $at > 0 ? $at : $now];
        }
        return self::deliveryAt($entry, self::lastSeenHold($target, floatval($entry['gamets'] ?? 0) ?: $now, $cfg), $bond, $now, $cfg);
    }

    /**
     * Is this inbox item deliverable now, and how did it reach her? 'witnessed' (she was there), 'told' (she and the
     * source talked since it happened: $talks, her ledger [['with', 'gamets']]), 'delayed' (its ready_at has come, or
     * there is no game clock to wait on, or it was queued before ripples carried one), null: not yet.
     */
    public static function deliverVia(array $item, float $now, array $talks): ?string
    {
        if (($item['via'] ?? null) === 'witnessed') return 'witnessed';
        $source = mb_strtolower(trim((string) ($item['source'] ?? '')));
        $news = floatval($item['gamets'] ?? 0);
        foreach ($talks as $t) {
            if (is_array($t) && mb_strtolower(trim((string) ($t['with'] ?? ''))) === $source && floatval($t['gamets'] ?? 0) >= $news) return 'told';
        }
        if (!is_numeric($item['ready_at'] ?? null) || $now <= 0 || $now >= floatval($item['ready_at'])) return 'delayed';
        return null;
    }

    /** Which felt line the ripple earns: the source hurt or helped, the hearer her ally or her rival. */
    public static function feltKind(float $delta, float $bond): string
    {
        return ($bond < 0 ? 'rival' : 'ally') . '_' . ($delta < 0 ? 'hurt' : 'helped');
    }

    /**
     * The NPCs holding a bond to $source that hear of a change of $delta: [['id', 'name', 'bond',
     * 'ripple', 'kind']] strongest bond first, at most max_targets. 'ripple' is the whole of it, what a witness
     * takes in (queue() damps it for hearsay). Reads core's relationship maps
     * (extended_data.relationships), one query; never the player, never the source herself.
     */
    public static function targetsFor(string $source, float $delta, ?array $cfg = null): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($source) === '') return [];
        $cfg = $cfg ?? self::config();
        $e = $db->escape($source);
        try {
            $rows = $db->fetchAll(
                "SELECT n.id, n.npc_name, n.extended_data -> 'relationships' -> '{$e}' AS rel
                 FROM core_npc_master n
                 WHERE jsonb_typeof(n.extended_data -> 'relationships') = 'object'
                   AND n.extended_data -> 'relationships' -> '{$e}' IS NOT NULL
                   AND n.id = (SELECT min(m.id) FROM core_npc_master m WHERE lower(m.npc_name) = lower(n.npc_name))
                 ORDER BY n.id");
        } catch (\Throwable $ex) {
            RelationshipDynamics::logError("cascade targets of {$source}", $ex);
            return [];
        }
        $sourceId = RelDynStorage::resolveNpcId($source);
        $out = [];
        foreach ((array) $rows as $row) {
            $id = intval($row['id'] ?? 0);
            $name = (string) ($row['npc_name'] ?? '');
            if ($id <= 0 || $name === '' || $id === $sourceId || strcasecmp($name, $source) === 0
                || RelationshipDynamics::isPlayerRelationshipKey($name)) {
                continue;
            }
            $rel = json_decode((string) ($row['rel'] ?? ''), true);
            if (!is_array($rel) || !is_numeric($rel['aff'] ?? null)) continue;
            $bond = floatval($rel['aff']);
            $ripple = self::rippleFor($delta, $bond, $cfg);
            if ($ripple === null) continue;
            $out[] = ['id' => $id, 'name' => $name, 'bond' => $bond, 'ripple' => $ripple, 'kind' => self::feltKind($delta, $bond)];
        }
        usort($out, fn($a, $b) => abs($b['bond']) <=> abs($a['bond']) ?: $a['id'] <=> $b['id']);
        return array_slice($out, 0, max(0, intval($cfg['max_targets'])));
    }

    /**
     * Queue one ripple (a noted entry) on every target, stamped with how it reaches her (deliveryFor: seen at once,
     * or pending until its ready_at). Returns the queued items' targets:
     * [['target', 'cascade_delta', 'bond_strength', 'via']] (the shape propagateAffinityChange returns).
     * A target whose append failed makes the whole call report failure (null): the caller keeps
     * the entry and tries again, and the targets that did get it dedupe by ripple id.
     */
    public static function queue(string $source, array $entry, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $delta = floatval($entry['delta'] ?? 0.0);
        $now = RelationshipDynamics::currentGamets();
        $results = [];
        $failed = false;
        foreach (self::targetsFor($source, $delta, $cfg) as $t) {
            $how = self::deliveryFor($t['name'], $t['bond'], $entry, $now, $cfg);
            // hearsay is damped (cascade_decay); what a witness saw is the whole of it
            $ripple = self::rippleFor($delta, $t['bond'], $cfg, $how['via']);
            if ($ripple === null) continue;
            $item = [
                'id' => substr(sha1(($entry['fp'] ?? '') . '|' . strtolower($source) . '|' . $t['id']), 0, 24),
                'source' => $source,
                'delta' => $ripple,
                'kind' => $t['kind'],
                'gamets' => floatval($entry['gamets'] ?? 0.0),
                'anchor' => is_string($entry['anchor'] ?? null) ? $entry['anchor'] : null,
                'defining' => !empty($entry['defining']),
                'via' => $how['via'],
                'ready_at' => $how['ready_at'],
            ];
            if (!RelDynStorage::appendItem($t['id'], self::INBOX_KEY, $item)) {
                error_log("[RelDyn] ERROR cascade: could not queue the ripple of {$source} for {$t['name']} (npc {$t['id']})");
                $failed = true;
                continue;
            }
            $results[] = ['target' => $t['name'], 'cascade_delta' => $ripple, 'bond_strength' => round(abs($t['bond']) / 100.0, 2), 'via' => $how['via']];
            RelationshipDynamics::log("[CASCADE] {$source} -> {$t['name']}: ripple " . sprintf('%+.2f', $ripple) . " queued (bond {$t['bond']}, {$t['kind']}, "
                . ($how['via'] === 'witnessed' ? 'the NPC was there, at once'
                    : "word travels {$how['steps']} hold(s), earliest in " . sprintf('%.1f', $how['delay_hours']) . ' game hours, sooner if they talk') . ')');
        }
        return $failed ? null : $results;
    }

    /**
     * Word of mouth (rulings §20 #14): $a and $b actually talked (an NPC-to-NPC exchange, a radiant round or a
     * rechat; the context hook). Each who has a ripple of the other's waiting in her inbox gets the talk noted in
     * her ledger (appended atomically, nothing else of hers is written from this request), and the ripple is
     * deliverable at her next prerequest with the player. Returns how many ledgers were written; 0 when nothing
     * waits (the common case: this reads two small keys and writes nothing).
     */
    public static function noteTalk(string $a, string $b, float $gamets): int
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '' || strcasecmp($a, $b) === 0 || !self::enabled()
            || RelationshipDynamics::isPlayerRelationshipKey($a) || RelationshipDynamics::isPlayerRelationshipKey($b)) {
            return 0;
        }
        $noted = 0;
        foreach ([[$a, $b], [$b, $a]] as [$who, $with]) {
            try {
                $id = RelDynStorage::resolveNpcId($who);
                if ($id === null) continue;
                $inbox = RelDynStorage::readKeyForUpdate($id, self::INBOX_KEY);
                $items = is_array($inbox['value'] ?? null) && array_is_list($inbox['value']) ? $inbox['value'] : [];
                $newest = null;
                foreach ($items as $it) {
                    if (is_array($it) && strcasecmp(trim((string) ($it['source'] ?? '')), $with) === 0 && ($it['via'] ?? null) !== 'witnessed') {
                        $newest = max($newest ?? 0.0, floatval($it['gamets'] ?? 0));
                    }
                }
                if ($newest === null) continue;   // nothing of theirs waits for her
                $ledger = RelDynStorage::readKeyForUpdate($id, self::TALK_KEY);
                $have = is_array($ledger['value'] ?? null) && array_is_list($ledger['value']) ? $ledger['value'] : [];
                foreach ($have as $t) {
                    if (is_array($t) && strcasecmp(trim((string) ($t['with'] ?? '')), $with) === 0 && floatval($t['gamets'] ?? 0) >= $newest) continue 2;   // already noted
                }
                if (RelDynStorage::appendItem($id, self::TALK_KEY, ['with' => $with, 'gamets' => $gamets])) {
                    $noted++;
                    RelationshipDynamics::log("[CASCADE] {$who} and {$with} talked: what {$with} has news of reaches {$who} by word of mouth");
                }
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("cascade word of mouth {$a} / {$b}", $e);
            }
        }
        return $noted;
    }

    /**
     * Queue the ripples the source's eval items noted (OUT_KEY) and clear them from her state. Called
     * by applyEvalInbox after the items were applied and saved. Returns the number of ripples queued
     * on targets. With the network off, the noted ripples are dropped.
     */
    public static function flush(string $npcName, array &$dynamics): int
    {
        $out = $dynamics[self::OUT_KEY] ?? null;
        if (!is_array($out) || $out === []) return 0;
        $queued = 0;
        $complete = true;
        if (self::enabled()) {
            $cfg = self::config();
            foreach ($out as $entry) {
                if (!is_array($entry)) continue;
                $r = self::queue($npcName, $entry, $cfg);
                if ($r === null) { $complete = false; continue; }
                $queued += count($r);
            }
        }
        if (!$complete) {
            error_log("[RelDyn] ERROR cascade: {$npcName}'s ripples were not all queued; they stay noted for their next eval item");
            return $queued;
        }
        unset($dynamics[self::OUT_KEY]);
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
        return $queued;
    }

    // =====================================================================
    // THE TARGET: apply what she has heard, at her next prerequest
    // =====================================================================

    /**
     * Apply the ripples waiting for $npcName that have reached her (deliverVia: she was there, or she and the source
     * talked, or the news has had time to travel; the rest stay in the inbox). Call after her affinity mirror was
     * refreshed from core. The state (applied ids, the pending delta, the felt entries) is saved BEFORE the inbox is
     * trimmed; the whole points are committed to core after, the fraction waits. Returns what was
     * applied: [['source', 'raw', 'applied', 'via']] (core points before / after her curve).
     */
    public static function onPrerequest(string $npcName, array &$dynamics): array
    {
        if (!self::enabled()) return [];
        $npcId = RelDynStorage::resolveNpcId($npcName);
        if ($npcId === null) return [];
        // one small read of the inbox key, not the whole namespace (the dynamics blob is large and this runs every request)
        $stored = RelDynStorage::readKeyForUpdate($npcId, self::INBOX_KEY);
        $items = is_array($stored['value'] ?? null) && array_is_list($stored['value']) ? $stored['value'] : [];
        if ($items === []) return [];
        // Nothing has reached her yet (a ripple from far away, an hour or two on): leave it, and spare the lock and the save
        $now = RelationshipDynamics::currentGamets();
        $talks = self::readTalks($npcId);
        $due = false;
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_numeric($item['delta'] ?? null) || !is_string($item['source'] ?? null)
                || self::deliverVia($item, $now, $talks) !== null) {
                $due = true;
                break;
            }
        }
        if (!$due) return [];

        // One request at a time applies this NPC's ripples (the eval inbox's pattern: a session advisory
        // lock, dropped with the connection of a request that dies). Overlapping prerequests would each
        // read the same item and each apply it: the applied-id list merges as 'mine' and the pending delta
        // merges additively, so both applications would survive the save. The request that does not get the
        // lock leaves the ripples for the next one; it is not lost.
        if (!RelDynStorage::tryLockInbox($npcId, RelDynStorage::CASCADE_LOCK_CLASS)) {
            RelationshipDynamics::log("[CASCADE] {$npcName}: another request is applying the ripples; left for the next request");
            return [];
        }
        try {
            return self::applyLocked($npcName, $npcId, $dynamics);
        } finally {
            RelDynStorage::unlockInbox($npcId, RelDynStorage::CASCADE_LOCK_CLASS);
        }
    }

    /** onPrerequest's body, with the NPC's ripple lock held: the inbox and the applied ids are read fresh. */
    private static function applyLocked(string $npcName, int $npcId, array &$dynamics): array
    {
        // Under the lock: the inbox as it stands now (the one read above may predate a trim by the request
        // that held the lock before) and the ids that request stored as applied (this copy may be older)
        $stored = RelDynStorage::readKeyForUpdate($npcId, self::INBOX_KEY);
        $items = is_array($stored['value'] ?? null) && array_is_list($stored['value']) ? $stored['value'] : [];
        if ($items === []) return [];
        $storedState = RelDynStorage::loadDynamics($npcId);
        if (is_array($storedState[self::APPLIED_KEY] ?? null)) {
            $dynamics[self::APPLIED_KEY] = array_values(array_unique(array_merge(
                array_values((array) ($dynamics[self::APPLIED_KEY] ?? [])), array_values($storedState[self::APPLIED_KEY]))));
        }
        $now = RelationshipDynamics::currentGamets();
        $talkRead = RelDynStorage::readKeyForUpdate($npcId, self::TALK_KEY);
        $talks = is_array($talkRead['value'] ?? null) && array_is_list($talkRead['value']) ? $talkRead['value'] : [];

        $cfg = self::config();
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        $applied = is_array($dynamics[self::APPLIED_KEY] ?? null) ? array_values($dynamics[self::APPLIED_KEY]) : [];
        $felt = is_array($dynamics[self::FELT_KEY] ?? null) ? array_values($dynamics[self::FELT_KEY]) : [];
        $results = [];
        $total = 0.0;
        $consumed = [];   // ids that leave the inbox: applied now, or applied before (a crash left them behind)
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_numeric($item['delta'] ?? null) || !is_string($item['source'] ?? null)) {
                error_log("[RelDyn] cascade: unrecognised ripple in the inbox of {$npcName} dropped: " . substr((string) json_encode($item), 0, 200));
                continue;   // dropped from the inbox with the others (dropConsumed)
            }
            if (in_array($item['id'], $applied, true)) {
                $consumed[] = $item['id'];
                continue;
            }
            $via = self::deliverVia($item, $now, $talks);
            if ($via === null) continue;   // the news has not reached her yet: it waits in the inbox
            $consumed[] = $item['id'];
            $applied[] = $item['id'];
            $raw = floatval($item['delta']);
            $adj = RelationshipDynamics::cascadeSocialSensitivity($dynamics, $raw, is_string($temperament) ? $temperament : null);
            $total += $adj;
            $results[] = ['source' => $item['source'], 'raw' => round($raw, 3), 'applied' => round($adj, 3), 'via' => $via];
            // (a stranger's context tier never carries a bond line: nothing to say, so nothing is kept to say)
            if (!empty($cfg['felt']['enabled']) && abs($adj) > 0.0001 && is_string($item['kind'] ?? null)
                && RelationshipDynamics::getContextTier($dynamics) >= 1) {
                $felt[] = ['source' => $item['source'], 'kind' => $item['kind'],
                    'reason' => is_string($item['anchor'] ?? null) ? $item['anchor'] : null, 'gamets' => floatval($item['gamets'] ?? 0), 'via' => $via];
            }
            RelationshipDynamics::log("[CASCADE] {$npcName} " . ($via === 'witnessed' ? 'saw' : 'heard of') . " {$item['source']}: ripple " . sprintf('%+.2f', $raw)
                . ' -> ' . sprintf('%+.2f', $adj) . ' through their curve' . ($via === 'told' ? ' (word of mouth)' : ''));
        }
        $dynamics[self::APPLIED_KEY] = array_slice($applied, -self::APPLIED_KEEP);
        if ($felt !== []) $dynamics[self::FELT_KEY] = array_slice($felt, -self::FELT_MAX);
        if (abs($total) > 0.0001) {
            RelationshipDynamics::queueAffinityDelta($dynamics, $total);
        }
        if (!RelationshipDynamics::saveDynamics($npcName, $dynamics)) {
            error_log("[RelDyn] ERROR cascade: {$npcName}'s state was not saved; their ripples stay in the inbox for their next request");
            return [];
        }
        if (!self::dropConsumed($npcId, $consumed)) {
            error_log("[RelDyn] ERROR cascade: could not trim " . count($consumed) . " applied ripple(s) from the inbox of {$npcName}; their ids skip them next time");
        }
        // The talks she had were read for this pass: those that carried news did their work, the rest carry none
        if ($talks !== [] && !RelDynStorage::dropFirstItems($npcId, self::TALK_KEY, count($talks))) {
            error_log("[RelDyn] ERROR cascade: could not trim the talk ledger of {$npcName}");
        }
        $r = RelationshipDynamics::commitPlayerAffinity($npcName, $dynamics);
        if ($r !== null) {
            $GLOBALS['RELDYN_PRE_AFF'] = intval($r['new']);   // the affinity snapshot of this request is core's now
            RelationshipDynamics::log("[CASCADE] {$npcName}: core affinity {$r['old']} -> {$r['new']} from what the NPC heard");
        }
        return $results;
    }

    /** Her ledger of talks with others while their news waited ([['with', 'gamets']]); [] without one. One small key read. */
    private static function readTalks(int $npcId): array
    {
        $read = RelDynStorage::readKeyForUpdate($npcId, self::TALK_KEY);
        return is_array($read['value'] ?? null) && array_is_list($read['value']) ? $read['value'] : [];
    }

    /**
     * Take the delivered ripples (and the unrecognised ones) out of the inbox; the pending ones stay. When every item
     * goes, the first-N drop (items appended since stay). Otherwise the rest is written back only if nobody appended
     * meanwhile (compare and set), and tried again against the fresh list; what is left over after that is skipped
     * by id next time (applied ids), so a lost race leaves a harmless item, never a second application.
     */
    private static function dropConsumed(int $npcId, array $consumed): bool
    {
        for ($try = 0; $try < 4; $try++) {
            $stored = RelDynStorage::readKeyForUpdate($npcId, self::INBOX_KEY);
            $items = is_array($stored['value'] ?? null) && array_is_list($stored['value']) ? $stored['value'] : [];
            if ($items === []) return true;
            $keep = array_values(array_filter($items, fn($it) => is_array($it) && is_string($it['id'] ?? null) && is_numeric($it['delta'] ?? null)
                && is_string($it['source'] ?? null) && !in_array($it['id'], $consumed, true)));
            if (count($keep) === count($items)) return true;   // nothing to take out
            if ($keep === []) return RelDynStorage::dropFirstItems($npcId, self::INBOX_KEY, count($items));
            if (RelDynStorage::setKeyIfUnchanged($npcId, self::INBOX_KEY, $stored['expected'] ?? null, $keep)) return true;
        }
        return false;
    }

    // =====================================================================
    // FELT
    // =====================================================================

    /**
     * What she has heard, said to the player's face (one-shot, on a player-addressed turn).
     * @return array ['lines' => [['key', 'text', 'salience']], 'changed' => bool]
     */
    public static function takeFeltLines(array &$dynamics, string $npcName, string $playerName, bool $playerAddressed): array
    {
        $out = ['lines' => [], 'changed' => false];
        $entries = $dynamics[self::FELT_KEY] ?? null;
        if (!$playerAddressed || !is_array($entries) || $entries === []) return $out;
        $cfg = self::config();
        $texts = (array) $cfg['felt_text'];
        $salience = floatval($cfg['felt']['salience']);
        foreach ($entries as $e) {
            $text = is_array($e) && is_string($e['kind'] ?? null) ? ($texts[$e['kind']] ?? null) : null;
            if (!is_string($text) || $text === '') continue;
            $reason = is_string($e['reason'] ?? null) && trim($e['reason']) !== '' ? ' (' . trim($e['reason'], " .") . ')' : '';
            $out['lines'][] = [
                'key' => (string) $e['kind'],
                'text' => strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerName, '{SOURCE}' => (string) ($e['source'] ?? ''), '{REASON}' => $reason,
                    '{HEARD}' => ($e['via'] ?? null) === 'witnessed' ? 'seen' : 'heard']),
                'salience' => $salience,
            ];
        }
        unset($dynamics[self::FELT_KEY]);
        $out['changed'] = true;
        return $out;
    }
}
