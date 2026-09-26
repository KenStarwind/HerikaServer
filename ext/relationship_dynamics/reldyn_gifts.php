<?php
/**
 * Relationship Dynamics — the gift delta formula and its context modifiers (roadmap
 * gift-delta-formula; dimension design draft "Gifted items").
 *
 *   gift_delta = base_item_value x love_language_match x interest_match x relationship_context
 *
 *   base            flat base_points (5.0, today's value) unless value_base.enabled: then
 *                   base_points x clamp((value / reference_gold) ^ exponent, min_mult, max_mult)
 *                   from the gold value core logs with the handover ("(value N gold)"). Off by
 *                   default: the draft names the base but not its curve, and "gold != effort"
 *                   (open question).
 *   love language   MDD 1.2: gifts as her primary language x2.0, as her secondary x1.5
 *   interest        the existing facet appraisal of the item (RelDynFacetClassifier::giftAppraisal:
 *                   MDD 1.2's 0.5x..2.0x; an item without facets is the generic gift,
 *                   thing_appraisal.gift_unclassified_mult 0.5: "gold != effort"). Counted
 *                   there once: this module adds no interest table of its own.
 *   context         active resentment x0.3 (processGift, resentment above 20 points)
 *   inversions      a stolen gift she detects: no gift delta, trust -15, respect -10 and a
 *                   grievance; a re-gift she recognizes: no gift delta, trust -10, respect -8.
 *
 * Stolen: core's handover line carries no theft flag today; a line with the stolen marker
 * ("(stolen)", config stolen_markers) is treated as detected (open question: the plugin line).
 * Re-gift: the item reached the player as someone else's gift (an eventlog row "<giver> gave
 * [n] <item> to <player>" before this handover, within regift_lookback_game_days, types
 * regift_row_types) and she knows that giver (a relationships entry of her core row): "This was
 * Ysolda's, wasn't it?"
 *
 * Units: gift delta and base in affinity-dimension points (processGift's applyDelta); gold
 * value in septims; multipliers unitless; trust / respect points; time in game days
 * (RelationshipDynamics::GAMETS_PER_DAY raw gamets each).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynGifts
{
    /** Defaults for config key 'gift_delta' (a stored config replaces whole settings / tables). */
    public static function configDefaults(): array
    {
        return [
            'base_points' => 5.0,
            'value_base' => [
                'enabled' => false,
                'reference_gold' => 50.0,
                'exponent' => 0.25,
                'min_mult' => 0.6,
                'max_mult' => 1.6,
            ],
            'love_language' => ['primary' => 2.0, 'secondary' => 1.5],
            // raw points of the inversions (applyDelta)
            'stolen' => ['enabled' => true, 'trust' => -15.0, 'respect' => -10.0, 'grievance_severity' => 2],
            'stolen_markers' => ['(stolen)'],
            'regift' => ['enabled' => true, 'trust' => -10.0, 'respect' => -8.0],
            'regift_lookback_game_days' => 30.0,
            'regift_row_types' => ['itemfound', 'itemtransfer'],
            'felt_text' => [
                'stolen' => '{NAME} knows stolen goods on sight: the {THING} is held at arm\'s length, and the thanks never comes.',
                'regift' => '{NAME} knows whose {THING} this was; the smile thins and the gift is set aside.',
            ],
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('gift_delta');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** The base of the formula for a handover of gold value $value (septims, null = unknown). Pure. */
    public static function base(?int $value, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $base = floatval($cfg['base_points']);
        $vb = (array) ($cfg['value_base'] ?? []);
        if (empty($vb['enabled']) || $value === null || $value <= 0) return $base;
        $m = (floatval($value) / max(1.0, floatval($vb['reference_gold']))) ** floatval($vb['exponent']);
        return $base * max(floatval($vb['min_mult']), min(floatval($vb['max_mult']), $m));
    }

    /** MDD 1.2: gifts as her primary love language x2.0, as her secondary x1.5, else 1.0. Pure. */
    public static function loveLanguageMult(array $dynamics, ?array $cfg = null): float
    {
        $ll = (array) (($cfg ?? self::config())['love_language'] ?? []);
        if (($dynamics['love_language_primary'] ?? null) === RelationshipDynamics::LL_GIFTS) return floatval($ll['primary'] ?? 2.0);
        if (($dynamics['love_language_secondary'] ?? null) === RelationshipDynamics::LL_GIFTS) return floatval($ll['secondary'] ?? 1.5);
        return 1.0;
    }

    /**
     * The stolen marker in a handover line: [the line without it, true] when present, else
     * [the line, false]. Case-insensitive. Pure.
     */
    public static function stripStolenMarker(string $line, ?array $cfg = null): array
    {
        foreach ((array) (($cfg ?? self::config())['stolen_markers'] ?? []) as $marker) {
            $marker = trim((string) $marker);
            if ($marker === '' || stripos($line, $marker) === false) continue;
            $clean = preg_replace('/\s*' . preg_quote($marker, '/') . '\s*/i', ' ', $line);
            return [trim(preg_replace('/\s+,/', ',', preg_replace('/ {2,}/', ' ', (string) $clean))), true];
        }
        return [$line, false];
    }

    /**
     * Who gave the player $item before this handover (the newest eventlog row "<giver> gave [n]
     * <item> to <player>" of regift_row_types, before $beforeRowid, within the lookback of $now
     * on the game calendar), or null. The giver is neither the player nor $npcName.
     */
    public static function previousGiver(string $npcName, string $item, string $playerName, ?int $beforeRowid, float $now, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($item) === '' || trim($playerName) === '') return null;
        $types = array_values(array_filter(array_map('strval', (array) ($cfg['regift_row_types'] ?? [])), fn($t) => $t !== ''));
        if ($types === []) return null;
        $typeList = implode(', ', array_map(fn($t) => "'" . $db->escape($t) . "'", $types));
        $pattern = $db->escape('%gave%' . RelationshipDynamics::escapeLike($item) . '%to%' . RelationshipDynamics::escapeLike(trim($playerName)) . '%');
        $where = ["type IN ({$typeList})", "data ILIKE '{$pattern}' ESCAPE '\\'"];
        if ($beforeRowid !== null) $where[] = 'rowid < ' . intval($beforeRowid);
        if ($now > 0) $where[] = 'gamets >= ' . intval($now - floatval($cfg['regift_lookback_game_days']) * RelationshipDynamics::GAMETS_PER_DAY);
        $rows = $db->fetchAll('SELECT data FROM eventlog WHERE ' . implode(' AND ', $where) . ' ORDER BY rowid DESC LIMIT 5');
        foreach ((array) $rows as $row) {
            if (!preg_match('/^\s*(.+?)\s+gave\s+(?:\d+\s+)?(.+?)\s+to\s+(.+?)\s*(?:,\s*\(value\s+\d+\s+gold\))?\s*$/i', (string) ($row['data'] ?? ''), $m)) continue;
            $giver = trim($m[1]);
            if (strcasecmp(trim($m[2]), trim($item)) !== 0 || strcasecmp(trim($m[3]), trim($playerName)) !== 0) continue;
            if (strcasecmp($giver, trim($playerName)) === 0 || strcasecmp($giver, trim($npcName)) === 0) continue;
            return $giver;
        }
        return null;
    }

    /** Does $npcName know $giver (an entry for the giver in her core relationships)? */
    public static function knows(string $npcName, string $giver): bool
    {
        $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
        $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
        foreach (array_keys(RelationshipDynamics::normalizeRelationshipMap((array) ($ext['relationships'] ?? []))) as $name) {
            if (strcasecmp((string) $name, trim($giver)) === 0) return true;
        }
        return false;
    }

    /** The felt read of an inverted gift ('stolen' / 'regift'), feelings only. */
    public static function feltText(string $kind, string $npcName, string $item): ?string
    {
        $text = (string) (((array) (self::config()['felt_text'] ?? []))[$kind] ?? '');
        return trim($text) === '' ? null : str_replace(['{NAME}', '{THING}'], [$npcName, $item], $text);
    }
}
