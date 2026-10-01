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
 * Ysolda's, wasn't it?" Only a thing she could recognize: the eventlog names items, never the
 * physical one, so a fungible item is never a recognized re-gift (recognizable): gold and the
 * regift_fungible_words (whole words), any consumable (CONSUMABLE_EFFECTS keywords: mead, a
 * potion, bread), or a name two different people gave the player within the lookback (which
 * one is this?). A distinct thing that came as several from one giver is still that giver's.
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
            // whole words of an item name that make it fungible (besides every consumable): money
            // and ammunition, one coin or arrow is any other
            'regift_fungible_words' => ['gold', 'septim', 'septims', 'coin', 'coins', 'arrow', 'arrows', 'bolt', 'bolts', 'lockpick', 'lockpicks'],
            // The handover as a delivery to fulfillment (interaction-classification): a gift row (or
            // a give / trade request) delivers the 'gift' tag of fulfillment.tag_delivery (food,
            // drink and potions deliver 'help': service) once, at this significance (0..1: the
            // eval's units scale, fulfillment.significance_floor).
            'handover_significance' => 0.5,
            // Game hours within which a handover row and the same tag from the request's side (the
            // eval's tag, or the local classifier) are one handover, not two deliveries.
            'eval_pair_game_hours' => 1.0,
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
     * Could she tell this one from any other of its name? Not gold or another regift_fungible_words
     * item, not a consumable (any CONSUMABLE_EFFECTS keyword). Whole-word matches ('Dragonscale' is
     * no ale). Pure.
     */
    public static function recognizable(string $item, ?array $cfg = null): bool
    {
        $words = (array) (($cfg ?? self::config())['regift_fungible_words'] ?? []);
        foreach (RelationshipDynamics::CONSUMABLE_EFFECTS as $entry) {
            foreach ((array) ($entry['keywords'] ?? []) as $kw) $words[] = $kw;
        }
        $name = strtolower(trim($item));
        foreach ($words as $w) {
            $w = strtolower(trim((string) $w));
            if ($w !== '' && preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/', $name)) return false;
        }
        return true;
    }

    // =====================================================================
    // THE HANDOVER AS A DELIVERY TO FULFILLMENT (roadmap interaction-classification)
    // =====================================================================

    /** Dynamics key of the handover ledger: id => ['at' => raw gamets, 'src' => 'row'|'request', 'tag' => 'gift'|'help', 'paired' => bool]. */
    const LEDGER_KEY = '_handover_ledger';

    /** Most entries the ledger keeps (the oldest go first). */
    const LEDGER_KEEP = 8;

    /**
     * Is $item something she would eat, drink or take as medicine (any CONSUMABLE_EFFECTS keyword,
     * whole words: 'Dragonscale' is no ale, a plural is the same word)? Pure.
     */
    public static function isConsumable(string $item): bool
    {
        $name = strtolower(trim($item));
        if ($name === '') return false;
        foreach (RelationshipDynamics::CONSUMABLE_EFFECTS as $entry) {
            foreach ((array) ($entry['keywords'] ?? []) as $kw) {
                $kw = strtolower(trim((string) $kw));
                if ($kw !== '' && preg_match('/(?<![a-z])' . preg_quote($kw, '/') . '(?:s|es)?(?![a-z])/', $name) === 1) return true;
            }
        }
        return false;
    }

    /**
     * The love language an item handed to her speaks: food, drink and potions look after her (acts
     * of service), anything else is a gift. A handover that names no item keeps the old reading,
     * service. Pure.
     */
    public static function handoverLoveLanguage(?string $item): string
    {
        if ($item === null || trim($item) === '') return RelationshipDynamics::LL_SERVICE;
        return self::isConsumable($item) ? RelationshipDynamics::LL_SERVICE : RelationshipDynamics::LL_GIFTS;
    }

    /** The eval contract tag the handover of $item stands for: 'gift' or, for food and potions, 'help'. Pure. */
    public static function handoverTag(?string $item): string
    {
        return self::handoverLoveLanguage($item) === RelationshipDynamics::LL_GIFTS ? 'gift' : 'help';
    }

    /**
     * One handover is one delivery, whoever sees it first (the eventlog row of the player's
     * handover, or the request's side: the eval's tag, when its exchange held the row
     * (handoverTagsInExchange)). Each calls this before it delivers $tag at raw game time $at, with its
     * $source ('row' | 'request'): true = the other side already delivered it (an unpaired entry of
     * the other source, the same tag, within eval_pair_game_hours: now paired, deliver nothing);
     * false = nobody has, deliver it (an unpaired entry of this source is kept for the other side).
     * The ledger is a keyed map, so two writers' saves merge entry by entry. Pure on $dynamics.
     */
    public static function noteHandover(array &$dynamics, string $source, string $tag, float $at, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        $window = max(0.0, floatval($cfg['eval_pair_game_hours'])) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        $ledger = is_array($dynamics[self::LEDGER_KEY] ?? null) ? $dynamics[self::LEDGER_KEY] : [];
        $best = null;
        $bestGap = null;
        foreach ($ledger as $id => $e) {
            if (!is_array($e) || !empty($e['paired']) || ($e['src'] ?? '') === $source || ($e['tag'] ?? '') !== $tag) continue;
            $gap = abs(floatval($e['at'] ?? 0) - $at);
            if ($gap <= $window && ($bestGap === null || $gap < $bestGap)) { $best = (string) $id; $bestGap = $gap; }
        }
        if ($best !== null) {
            $ledger[$best]['paired'] = true;
            $paired = true;
        } else {
            $n = 0;
            do { $id = $source . ':' . $tag . ':' . intval($at) . ($n > 0 ? '.' . $n : ''); $n++; } while (isset($ledger[$id]));
            $ledger[$id] = ['at' => $at, 'src' => $source, 'tag' => $tag, 'paired' => false];
            $paired = false;
        }
        // keep the newest entries of the last game day
        $newest = $at;
        foreach ($ledger as $e) { $newest = max($newest, floatval($e['at'] ?? 0)); }
        foreach ($ledger as $id => $e) {
            if ($newest - floatval($e['at'] ?? 0) > RelationshipDynamics::GAMETS_PER_DAY) unset($ledger[$id]);
        }
        if (count($ledger) > self::LEDGER_KEEP) {
            uasort($ledger, fn($a, $b) => floatval($a['at'] ?? 0) <=> floatval($b['at'] ?? 0));
            $ledger = array_slice($ledger, -self::LEDGER_KEEP, null, true);
        }
        $dynamics[self::LEDGER_KEY] = $ledger;
        return $paired;
    }

    /**
     * The tags ('gift' / 'help') of the player's handovers to $npcName that one exchange held: the
     * eventlog 'itemfound' rows "<player> gave [n] <item> to <npc>" logged after $afterRowid (the
     * previous exchange's anchor; null = the exchange's first scored row is unknown, so the rows of
     * the last $sinceGamets onwards) up to $uptoRowid (the exchange's anchor). The evidence that an
     * eval item's 'gift' / 'help' tag speaks of a handover that really happened in that exchange
     * (the tag alone, 'help' above all, is not: the player also helps with a wolf or a lockpick), so
     * only then is the tag one delivery with the row's (noteHandover). Throws on a failed query:
     * the caller treats the exchange's handovers as unknown, not as none.
     *
     * @return string[] distinct tags, in order of first appearance, possibly empty
     */
    public static function handoverTagsInExchange(string $npcName, string $playerName, ?int $afterRowid, int $uptoRowid, ?float $sinceGamets = null): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) throw new RuntimeException('RelDynGifts::handoverTagsInExchange: no database connection');
        $npcLike = $db->escape(RelationshipDynamics::escapeLike(trim($npcName)));
        $where = ["type = 'itemfound'", "data LIKE '%gave%to%{$npcLike}%' ESCAPE '\\'", 'rowid <= ' . intval($uptoRowid)];
        if ($afterRowid !== null) {
            $where[] = 'rowid > ' . intval($afterRowid);
        } elseif ($sinceGamets !== null) {
            $where[] = 'gamets >= ' . intval($sinceGamets);
        }
        $rows = $db->fetchAll('SELECT data FROM eventlog WHERE ' . implode(' AND ', $where) . ' ORDER BY rowid ASC LIMIT 50');
        $tags = [];
        foreach ((array) $rows as $row) {
            [$line] = self::stripStolenMarker((string) ($row['data'] ?? ''));
            if (!preg_match('/^\s*(.+?)\s+gave\s+(?:\d+\s+)?(.+?)\s+to\s+(.+?)\s*(?:,\s*\(value\s+\d+\s+gold\))?\s*$/i', $line, $m)) continue;
            if (strcasecmp(trim($m[1]), trim($playerName)) !== 0 || strcasecmp(trim($m[3]), trim($npcName)) !== 0) continue;
            $tag = self::handoverTag(trim($m[2]));
            if (!in_array($tag, $tags, true)) $tags[] = $tag;
        }
        return $tags;
    }

    /**
     * Deliver the handover of $item at raw game time $at to the player pair's fulfillment: the
     * 'gift' (or, for food, drink and potions, 'help') row of fulfillment.tag_delivery at
     * handover_significance. Returns axis => units applied ([] without a fulfillment state).
     */
    public static function deliverHandover(array &$dynamics, ?string $item, float $at, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $fc = RelDynFulfillment::config();
        $row = (array) (((array) $fc['tag_delivery'])[self::handoverTag($item)] ?? []);
        $floor = max(0.0, min(1.0, floatval($fc['significance_floor'])));
        $scale = $floor + (1.0 - $floor) * max(0.0, min(1.0, floatval($cfg['handover_significance'])));
        $amounts = [];
        foreach ($row as $axis => $units) {
            if (is_numeric($units) && floatval($units) > 0) $amounts[$axis] = floatval($units) * $scale;
        }
        return $amounts === [] ? [] : RelDynFulfillment::deliver($dynamics, $amounts, $at);
    }

    /**
     * Who gave the player $item before this handover (the eventlog rows "<giver> gave [n] <item>
     * to <player>" of regift_row_types, before $beforeRowid, within the lookback of $now on the
     * game calendar), or null. The giver is neither the player nor $npcName. Null for a thing no
     * one could recognize (recognizable: fungible), and when two different people gave the player
     * an item of that name.
     */
    public static function previousGiver(string $npcName, string $item, string $playerName, ?int $beforeRowid, float $now, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($item) === '' || trim($playerName) === '') return null;
        if (!self::recognizable($item, $cfg)) return null;
        $types = array_values(array_filter(array_map('strval', (array) ($cfg['regift_row_types'] ?? [])), fn($t) => $t !== ''));
        if ($types === []) return null;
        $typeList = implode(', ', array_map(fn($t) => "'" . $db->escape($t) . "'", $types));
        $pattern = $db->escape('%gave%' . RelationshipDynamics::escapeLike($item) . '%to%' . RelationshipDynamics::escapeLike(trim($playerName)) . '%');
        $where = ["type IN ({$typeList})", "data ILIKE '{$pattern}' ESCAPE '\\'"];
        if ($beforeRowid !== null) $where[] = 'rowid < ' . intval($beforeRowid);
        if ($now > 0) $where[] = 'gamets >= ' . intval($now - floatval($cfg['regift_lookback_game_days']) * RelationshipDynamics::GAMETS_PER_DAY);
        $rows = $db->fetchAll('SELECT data FROM eventlog WHERE ' . implode(' AND ', $where) . ' ORDER BY rowid DESC LIMIT 20');
        $givers = [];
        foreach ((array) $rows as $row) {
            if (!preg_match('/^\s*(.+?)\s+gave\s+(?:\d+\s+)?(.+?)\s+to\s+(.+?)\s*(?:,\s*\(value\s+\d+\s+gold\))?\s*$/i', (string) ($row['data'] ?? ''), $m)) continue;
            $giver = trim($m[1]);
            if (strcasecmp(trim($m[2]), trim($item)) !== 0 || strcasecmp(trim($m[3]), trim($playerName)) !== 0) continue;
            if (strcasecmp($giver, trim($playerName)) === 0) continue;
            $givers[strtolower($giver)] = $giver;
        }
        // none, or two people gave one (she herself among them): which one is this?
        if (count($givers) !== 1) return null;
        $giver = (string) array_values($givers)[0];
        return strcasecmp($giver, trim($npcName)) === 0 ? null : $giver;
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
