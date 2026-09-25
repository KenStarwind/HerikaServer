<?php
/**
 * Jev's explicit state (decisions 2026-09-23 §3, the one exception to felt steering).
 *
 * Jev (the tactical action picker) does not roleplay: "if I feel this and my goal is that, then
 * I do x". It receives the concrete values, not the felt prose the dialogue model gets. The Jev
 * plugin is expected to call RelationshipDynamics::jevStateBlock($npcName) and use either the
 * structured array or its compact 'text' rendering (this file never calls or edits Jev).
 *
 * Fields (all toward the player; defaults for an NPC RelDyn never saw, nothing invented):
 *   npc              string
 *   affinity         float  core units -100..100 (core relationships.Player.aff + RelDyn's
 *                           uncommitted change; RelationshipDynamics::getCoreAffinity)
 *   affinity_tier    string hostile|stranger|acquaintance|friend|close_friend|bonded|devoted
 *   context_tier     int    0..3 (with the high-water mark)
 *   trust, comfort, respect, warmth, maturity, resentment, resentment_self, self_confidence
 *                    float  dimension points 0..100
 *   arousal          float  0..100      valence  float -100..100
 *   passion          float  0..100 (capped by the Attraction Matrix)
 *   jealousy         float  0..100      jealousy_rival ?string
 *   attachment       string secure|anxious|avoidant|toxic (the style region of the axes; toxic =
 *                           fearful, MDD Toxic/Disorganized)
 *   attachment_anxiety, attachment_avoidance
 *                    float  0..1 the two attachment axes (decisions §12, Fraley & Shaver): fear of
 *                           abandonment; discomfort with closeness once someone is in
 *   temperament      ?string
 *   relationship_type string RelDyn type (RelationshipDynamics::getRelationshipType)
 *   core_type        ?string core relationships.Player.type
 *   weather          string sunny|clear|overcast|stormy
 *   open_conflict    bool   conflict_repairs int (positive interactions since it opened)
 *   boundary         string none|pending|probation|failed (mature boundary, rulings §9)
 *   walkaway         string normal|pending|active|boundary_test|recovery|permanent
 *   fulfillment      ['band' => -1..1, 'trend' => band per game day, 'low' => bool, 'known' => bool]
 *   attraction       ['enabled' => bool, 'outcome' => ?string, 'score' => 0..1,
 *                     rulings §11, attraction is a modifier AND a gate:
 *                     'modifier' => modifier(S) (continuous in the score),
 *                     'gate_product' => 0 or 1 (product of the required-pillar gates, each met or not),
 *                     'gate' => bool (the passion gate is open),
 *                     'passion_mult' => passion-gain multiplier = modifier x gates x attachment [x prebond],
 *                     'respect_mult' => respect-gain multiplier (0.5..2.0, 1 at the neutral pillar score),
 *                     'passion_cap' => ?float points, 'friendzoned' => bool]
 *   place            null | ['name' => ?string, 'valence' => -1..1, 'intensity' => 0..1, 'dominant' => ?string]
 *   goal             null | ['text' => string, 'priority' => 0..1]
 *   units            field => unit description
 *   text             compact one-line rendering ("key=value ...") for a prompt
 */
final class RelDynJev
{
    const UNITS = [
        'affinity' => 'core units -100..100',
        'dimensions' => 'points 0..100 (valence -100..100)',
        'passion' => 'points 0..100', 'jealousy' => 'points 0..100',
        'fulfillment.band' => '-1..1 (below fulfillment low_band = neglected)',
        'fulfillment.trend' => 'band change per game day',
        'attraction.modifier' => 'modifier(S), multiplier', 'attraction.gate_product' => '0 or 1',
        'attraction.passion_mult' => 'passion-gain multiplier', 'attraction.respect_mult' => 'respect-gain multiplier',
        'attraction.score' => '0..1',
        'attachment_anxiety' => 'axis 0..1 (fear of abandonment)',
        'attachment_avoidance' => 'axis 0..1 (discomfort with closeness once in)',
        'place.valence' => '-1..1', 'place.intensity' => '0..1', 'goal.priority' => '0..1',
    ];

    public static function state(string $npcName, array $dynamics, float $now): array
    {
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $num = function (string $d, float $default) use ($dims): float {
            $x = $dims[$d]['x'] ?? null;
            return is_numeric($x) ? round(floatval($x), 2) : $default;
        };
        $affinity = round(RelationshipDynamics::getCoreAffinity($dynamics), 2);

        $fulfillment = ['band' => 0.0, 'trend' => 0.0, 'low' => false, 'known' => false];
        if ($now > 0 && RelDynFulfillment::enabled()) {
            $f = RelationshipDynamics::fulfillment($npcName, $dynamics, $now);
            $fulfillment = ['band' => round(floatval($f['band'] ?? 0), 3), 'trend' => round(floatval($f['trend'] ?? 0), 4),
                'low' => !empty($f['low_band']), 'known' => !empty($f['known'])];
        }

        $a = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $attraction = [
            'enabled' => !empty($a['enabled']),
            'outcome' => isset($a['outcome']) ? (string) $a['outcome'] : null,
            'modifier' => round(floatval($a['passion']['modifier'] ?? 1.0), 4),
            'gate_product' => round(floatval($a['passion']['gate_product'] ?? 1.0), 4),
            'gate' => empty($a['enabled']) ? true : !empty($a['passes']),
            'passion_mult' => round(floatval($a['passion_mult'] ?? $dynamics['_attraction_passion_mult'] ?? 1.0), 4),
            'respect_mult' => round(floatval($a['respect_mult'] ?? 1.0), 4),
            'score' => round(floatval($a['score'] ?? 0), 4),
            'passion_cap' => is_numeric($a['passion_cap'] ?? null) ? floatval($a['passion_cap']) : null,
            'friendzoned' => !empty($a['friendzoned']),
        ];

        $place = null;
        $pa = $dynamics['_place_appraisal'] ?? null;
        if (is_array($pa) && isset($pa['valence'])) {
            $place = ['name' => isset($pa['place']) ? (string) $pa['place'] : null,
                'valence' => round(floatval($pa['valence']), 3), 'intensity' => round(floatval($pa['intensity'] ?? 0), 3),
                'dominant' => isset($pa['dominant']) ? (string) $pa['dominant'] : null];
        }

        $goal = null;
        $g = RelationshipDynamics::getActiveDirectorGoal($dynamics);
        if (is_array($g) && trim((string) ($g['text'] ?? '')) !== '') {
            $goal = ['text' => trim((string) $g['text']), 'priority' => round(floatval($g['priority'] ?? 0.5), 2)];
        }

        $boundary = $dynamics[RelDynFulfillment::STATE_KEY]['boundary']['state'] ?? 'none';
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        $rival = trim((string) ($dynamics['jealousy_trigger_npc'] ?? ''));
        $out = [
            'npc' => $npcName,
            'affinity' => $affinity,
            'affinity_tier' => RelationshipDynamics::getCurrentTier($affinity),
            'context_tier' => RelationshipDynamics::getContextTier($dynamics),
            'trust' => $num('trust', 50.0), 'comfort' => $num('comfort', 50.0), 'respect' => $num('respect', 50.0),
            'warmth' => $num('warmth', 50.0), 'maturity' => $num('maturity', 50.0), 'resentment' => $num('resentment', 0.0),
            'resentment_self' => $num('resentment_self', 0.0), 'self_confidence' => $num('self_confidence', 50.0),
            'arousal' => $num('arousal', 10.0), 'valence' => $num('valence', 0.0),
            'passion' => round(RelationshipDynamics::getPassion($dynamics), 2),
            'jealousy' => round(floatval($dynamics['jealousy_anger'] ?? 0), 2),
            'jealousy_rival' => $rival !== '' ? $rival : null,
            'attachment' => RelationshipDynamics::attachmentStyleOf($axes['anxiety'], $axes['avoidance']),
            'attachment_anxiety' => round($axes['anxiety'], 3),
            'attachment_avoidance' => round($axes['avoidance'], 3),
            'temperament' => RelationshipDynamics::validTemperament($dynamics['inferred_temperament'] ?? null),
            'relationship_type' => (string) RelationshipDynamics::getRelationshipType($npcName, $dynamics),
            'core_type' => isset($dynamics['_core_rel_type']) ? (string) $dynamics['_core_rel_type'] : null,
            'weather' => (string) ($dynamics['_internal_weather'] ?? 'clear'),
            'open_conflict' => !empty($dynamics['in_conflict']),
            'conflict_repairs' => intval($dynamics['conflict_positive_count'] ?? 0),
            'boundary' => is_string($boundary) ? $boundary : 'none',
            'walkaway' => (string) ($dynamics['_walkaway_state'] ?? 'normal'),
            'fulfillment' => $fulfillment,
            'attraction' => $attraction,
            'place' => $place,
            'goal' => $goal,
            'units' => self::UNITS,
        ];
        $out['text'] = self::render($out);
        return $out;
    }

    /** Compact one-line rendering for a prompt: "key=value" pairs, numbers as they are. */
    public static function render(array $s): string
    {
        $f = fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $parts = [
            "npc={$s['npc']}",
            'affinity=' . $f($s['affinity']) . "({$s['affinity_tier']})",
            "type={$s['relationship_type']}",
        ];
        foreach (['trust', 'comfort', 'respect', 'warmth', 'maturity', 'passion'] as $k) $parts[] = "{$k}=" . $f($s[$k]);
        $parts[] = 'jealousy=' . $f($s['jealousy']) . ($s['jealousy_rival'] !== null ? "(rival {$s['jealousy_rival']})" : '');
        $parts[] = 'resentment=' . $f($s['resentment']);
        $parts[] = 'mood=' . $f($s['arousal']) . '/' . $f($s['valence']);
        $parts[] = "attachment={$s['attachment']}(anxiety " . number_format($s['attachment_anxiety'], 2, '.', '')
            . ' avoidance ' . number_format($s['attachment_avoidance'], 2, '.', '') . ')';
        if ($s['temperament'] !== null) $parts[] = "temperament={$s['temperament']}";
        $parts[] = "weather={$s['weather']}";
        $parts[] = 'conflict=' . ($s['open_conflict'] ? 'open' : 'none');
        $parts[] = "boundary={$s['boundary']}";
        $parts[] = "walkaway={$s['walkaway']}";
        $parts[] = 'fulfillment=' . number_format($s['fulfillment']['band'], 2, '.', '') . ($s['fulfillment']['low'] ? '(low)' : '');
        $a = $s['attraction'];
        if ($a['enabled']) {
            $parts[] = 'attraction=' . ($a['outcome'] ?? 'unknown') . ' modifier=' . number_format($a['modifier'], 2, '.', '')
                . ' gate=' . ($a['gate'] ? 'open' : 'closed') . '(' . number_format($a['gate_product'], 2, '.', '') . ')'
                . ' passion_mult=' . number_format($a['passion_mult'], 2, '.', '');
        }
        if ($s['place'] !== null) {
            $parts[] = 'place=' . number_format($s['place']['valence'], 2, '.', '') . ($s['place']['dominant'] !== null ? "({$s['place']['dominant']})" : '');
        }
        if ($s['goal'] !== null) {
            $parts[] = 'goal="' . str_replace('"', "'", $s['goal']['text']) . '"(' . number_format($s['goal']['priority'], 1, '.', '') . ')';
        }
        return implode(' ', $parts);
    }
}
