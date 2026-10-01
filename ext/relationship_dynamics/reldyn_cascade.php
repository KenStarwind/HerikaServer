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
 * curve:
 *     ripple = change x (|bond| / 100) x cascade_decay x (bond < 0 ? -cascade.enemy_mult : 1)
 * an ally takes the source's side (her hurt is felt), an enemy takes the opposite (the inverted
 * ripple, weaker: enemy_mult 0.5). A ripple under cascade.min_ripple is dropped. One hop only.
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
 * NOT BUILT (judged, review queue): applying a ripple to NPCs merely present (CACHE_PEOPLE) or
 * named in someone else's request. Their own next prerequest is their load; writing other NPCs'
 * core rows from inside a request that is not theirs is the eager design the audit retired.
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
            'felt' => [
                'enabled' => true,
                'salience' => 0.7,           // 0..1 among the turn's felt lines
            ],
            'felt_text' => [
                // {NAME} the hearer, {PLAYER} the player, {SOURCE} who it happened to, {REASON} " (what happened)" or nothing
                'ally_hurt'    => "{NAME} has heard what {PLAYER} did to {SOURCE}{REASON}; it sits badly, and {NAME} is cooler toward {PLAYER} for it.",
                'ally_helped'  => "{NAME} has heard what {PLAYER} did for {SOURCE}{REASON}; {NAME} thinks better of {PLAYER} for it.",
                'rival_hurt'   => "{NAME} has heard what {PLAYER} did to {SOURCE}{REASON}; {NAME} is not sorry for {SOURCE}, and warms to {PLAYER} for it.",
                'rival_helped' => "{NAME} has heard what {PLAYER} did for {SOURCE}{REASON}; {NAME} resents it, and is cooler toward {PLAYER} for it.",
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
        foreach (['defining', 'felt', 'felt_text'] as $k) {
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

        $entry = ['fp' => $fingerprint, 'delta' => $delta, 'gamets' => $gamets, 'anchor' => $anchor, 'defining' => $defining];
        $out = is_array($dynamics[self::OUT_KEY] ?? null) ? array_values($dynamics[self::OUT_KEY]) : [];
        foreach ($out as $e) {
            if (($e['fp'] ?? null) === $fingerprint) return null;   // already noted
        }
        $out[] = $entry;
        $dynamics[self::OUT_KEY] = array_slice($out, -self::OUT_MAX);
        RelationshipDynamics::log("[CASCADE] {$npcName}: an eval item moved her affinity " . sprintf('%+.2f', $delta)
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
     */
    public static function rippleFor(float $delta, float $bond, ?array $cfg = null): ?float
    {
        $cfg = $cfg ?? self::config();
        if (abs($bond) <= floatval($cfg['min_bond'])) return null;
        $decay = floatval(RelationshipDynamics::configValue('cascade_decay'));
        $ripple = $delta * min(1.0, abs($bond) / 100.0) * $decay * ($bond < 0 ? -floatval($cfg['enemy_mult']) : 1.0);
        return abs($ripple) < floatval($cfg['min_ripple']) ? null : round($ripple, 3);
    }

    /** Which felt line the ripple earns: the source hurt or helped, the hearer her ally or her rival. */
    public static function feltKind(float $delta, float $bond): string
    {
        return ($bond < 0 ? 'rival' : 'ally') . '_' . ($delta < 0 ? 'hurt' : 'helped');
    }

    /**
     * The NPCs holding a bond to $source that hear of a change of $delta: [['id', 'name', 'bond',
     * 'ripple', 'kind']] strongest bond first, at most max_targets. Reads core's relationship maps
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
     * Queue one ripple (a noted entry) on every target. Returns the queued items' targets:
     * [['target', 'cascade_delta', 'bond_strength']] (the shape propagateAffinityChange returns).
     * A target whose append failed makes the whole call report failure (null): the caller keeps
     * the entry and tries again, and the targets that did get it dedupe by ripple id.
     */
    public static function queue(string $source, array $entry, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $delta = floatval($entry['delta'] ?? 0.0);
        $results = [];
        $failed = false;
        foreach (self::targetsFor($source, $delta, $cfg) as $t) {
            $item = [
                'id' => substr(sha1(($entry['fp'] ?? '') . '|' . strtolower($source) . '|' . $t['id']), 0, 24),
                'source' => $source,
                'delta' => $t['ripple'],
                'kind' => $t['kind'],
                'gamets' => floatval($entry['gamets'] ?? 0.0),
                'anchor' => is_string($entry['anchor'] ?? null) ? $entry['anchor'] : null,
                'defining' => !empty($entry['defining']),
            ];
            if (!RelDynStorage::appendItem($t['id'], self::INBOX_KEY, $item)) {
                error_log("[RelDyn] ERROR cascade: could not queue the ripple of {$source} for {$t['name']} (npc {$t['id']})");
                $failed = true;
                continue;
            }
            $results[] = ['target' => $t['name'], 'cascade_delta' => $t['ripple'], 'bond_strength' => round(abs($t['bond']) / 100.0, 2)];
            RelationshipDynamics::log("[CASCADE] {$source} -> {$t['name']}: ripple " . sprintf('%+.2f', $t['ripple']) . " queued (bond {$t['bond']}, {$t['kind']})");
        }
        return $failed ? null : $results;
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
            error_log("[RelDyn] ERROR cascade: {$npcName}'s ripples were not all queued; they stay noted for her next eval item");
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
     * Apply the ripples waiting for $npcName. Call after her affinity mirror was refreshed from core.
     * The state (applied ids, the pending delta, the felt entries) is saved BEFORE the inbox is
     * trimmed; the whole points are committed to core after, the fraction waits. Returns what was
     * applied: [['source', 'raw', 'applied']] (core points before / after her curve).
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

        $cfg = self::config();
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        $applied = is_array($dynamics[self::APPLIED_KEY] ?? null) ? array_values($dynamics[self::APPLIED_KEY]) : [];
        $felt = is_array($dynamics[self::FELT_KEY] ?? null) ? array_values($dynamics[self::FELT_KEY]) : [];
        $results = [];
        $total = 0.0;
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_numeric($item['delta'] ?? null) || !is_string($item['source'] ?? null)) {
                error_log("[RelDyn] cascade: unrecognised ripple in the inbox of {$npcName} dropped: " . substr((string) json_encode($item), 0, 200));
                continue;
            }
            if (in_array($item['id'], $applied, true)) continue;
            $applied[] = $item['id'];
            $raw = floatval($item['delta']);
            $adj = RelationshipDynamics::cascadeSocialSensitivity($dynamics, $raw, is_string($temperament) ? $temperament : null);
            $total += $adj;
            $results[] = ['source' => $item['source'], 'raw' => round($raw, 3), 'applied' => round($adj, 3)];
            // (a stranger's context tier never carries a bond line: nothing to say, so nothing is kept to say)
            if (!empty($cfg['felt']['enabled']) && abs($adj) > 0.0001 && is_string($item['kind'] ?? null)
                && RelationshipDynamics::getContextTier($dynamics) >= 1) {
                $felt[] = ['source' => $item['source'], 'kind' => $item['kind'],
                    'reason' => is_string($item['anchor'] ?? null) ? $item['anchor'] : null, 'gamets' => floatval($item['gamets'] ?? 0)];
            }
            RelationshipDynamics::log("[CASCADE] {$npcName} heard of {$item['source']}: ripple " . sprintf('%+.2f', $raw)
                . ' -> ' . sprintf('%+.2f', $adj) . ' through her curve');
        }
        $dynamics[self::APPLIED_KEY] = array_slice($applied, -self::APPLIED_KEEP);
        $dynamics[self::FELT_KEY] = array_slice($felt, -self::FELT_MAX);
        if (abs($total) > 0.0001) {
            RelationshipDynamics::queueAffinityDelta($dynamics, $total);
        }
        if (!RelationshipDynamics::saveDynamics($npcName, $dynamics)) {
            error_log("[RelDyn] ERROR cascade: {$npcName}'s state was not saved; her ripples stay in the inbox for her next request");
            return [];
        }
        if (!RelDynStorage::dropFirstItems($npcId, self::INBOX_KEY, count($items))) {
            error_log("[RelDyn] ERROR cascade: could not trim " . count($items) . " applied ripple(s) from the inbox of {$npcName}; their ids skip them next time");
        }
        $r = RelationshipDynamics::commitPlayerAffinity($npcName, $dynamics);
        if ($r !== null) {
            $GLOBALS['RELDYN_PRE_AFF'] = intval($r['new']);   // the affinity snapshot of this request is core's now
            RelationshipDynamics::log("[CASCADE] {$npcName}: core affinity {$r['old']} -> {$r['new']} from what she heard");
        }
        return $results;
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
                'text' => strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerName, '{SOURCE}' => (string) ($e['source'] ?? ''), '{REASON}' => $reason]),
                'salience' => $salience,
            ];
        }
        unset($dynamics[self::FELT_KEY]);
        $out['changed'] = true;
        return $out;
    }
}
