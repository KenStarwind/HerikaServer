<?php
/**
 * RelDyn facets: preference matching (decisions 2026-09-23 §6, "facets -> appraisal -> feeling").
 *
 * Anything the NPC experiences (place, item, topic, creature, activity) becomes a facet vector:
 * facet => weight 0..1 over FACETS (the 11 MDD 1.2 interests plus situational facets). An NPC's
 * preferences are signed, facet => -1 (hates) .. 0 (indifferent) .. +1 (loves). The appraisal
 * turns the two into a feeling; the LLM gets the felt read, never the numbers.
 *
 * Shared API, one owner lane per method:
 *   places:     placeFacets(), currentPlaceContext()
 *   classifier: thingFacets()                      (RelDynFacetClassifier, reldyn_facet_classifier.php)
 *   appraisal:  preferences(), appraise(), feltText()
 */

require_once __DIR__ . '/reldyn_facet_classifier.php';

final class RelDynFacets
{
    /** MDD 1.2 interests (11 categories). */
    const INTERESTS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
    ];

    /** Situational facets (decisions §6). */
    const SITUATIONAL = ['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'];

    const FACETS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
        'danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet',
    ];

    // =========================================================================
    // Classifier lane
    // =========================================================================

    /**
     * Facet vector of a thing, [] when unknown. $kind: item|topic|creature|place|activity.
     * Oghma entries come precomputed (embedding + prior, tools/build_oghma_facets.php) or as the
     * live knowledge_class/category/tags prior; everything else from the kind's keyword table.
     */
    public static function thingFacets(string $kind, string $name): array
    {
        return RelDynFacetClassifier::thingFacets($kind, $name);
    }

    // =========================================================================
    // Appraisal lane -- INTERIM bodies written by the classifier lane so its hand-off runs
    // end to end. The appraisal lane owns these three; on merge its bodies replace these.
    // =========================================================================

    /**
     * Signed preferences facet => -1..+1. INTERIM: a per-NPC override
     * $dynamics['facet_preferences'] wins per facet; the interests (MDD 1.2, 0.5..2.0 with 1.0
     * neutral) map 2.0 -> +1, 1.0 -> 0, 0.5 -> -1; situational facets are 0 unless overridden.
     */
    public static function preferences(array $dynamics, string $npcName): array
    {
        $prefs = array_fill_keys(self::FACETS, 0.0);
        $interests = RelationshipDynamics::getInterests($dynamics);
        foreach (self::INTERESTS as $interest) {
            $v = (float) ($interests[$interest] ?? 1.0);
            $prefs[$interest] = $v >= 1.0 ? min(1.0, $v - 1.0) : max(-1.0, ($v - 1.0) * 2.0);
        }
        foreach ((array) ($dynamics['facet_preferences'] ?? []) as $facet => $p) {
            if (in_array($facet, self::FACETS, true) && is_numeric($p)) {
                $prefs[$facet] = max(-1.0, min(1.0, (float) $p));
            }
        }
        return $prefs;
    }

    /**
     * INTERIM appraisal (decisions §6): contribution_f = facet_f x preference_f;
     * valence = sum(contributions) / sum(facet weights the NPC has a feeling about), -1..+1;
     * intensity = min(1, sum |contributions|); the facet contributing most (by |value|) is
     * what the NPC sees.
     */
    public static function appraise(array $prefs, array $facets): array
    {
        $contrib = [];
        $weight = 0.0;
        foreach ($facets as $facet => $w) {
            $p = (float) ($prefs[$facet] ?? 0.0);
            if (abs($p) < 1e-9 || !is_numeric($w)) {
                continue;
            }
            $contrib[$facet] = (float) $w * $p;
            $weight += (float) $w;
        }
        if ($contrib === [] || $weight <= 0.0) {
            return ['valence' => 0.0, 'intensity' => 0.0, 'dominant' => null, 'dominant_sign' => 1, 'contributions' => []];
        }
        $dominant = null;
        foreach ($contrib as $facet => $c) {
            if ($dominant === null || abs($c) > abs($contrib[$dominant])) {
                $dominant = $facet;
            }
        }
        return [
            'valence' => max(-1.0, min(1.0, array_sum($contrib) / $weight)),
            'intensity' => min(1.0, array_sum(array_map('abs', $contrib))),
            'dominant' => $dominant,
            'dominant_sign' => $contrib[$dominant] >= 0 ? 1 : -1,
            'contributions' => $contrib,
        ];
    }

    /** What a facet is, in words (feltText). */
    const FACET_PHRASES = [
        'combat' => 'a good fight', 'crafting' => 'honest craftsmanship', 'alchemy' => 'the alchemist\'s art',
        'enchanting' => 'the workings of magic', 'scholarly' => 'old knowledge and the pull of study',
        'nature' => 'the wild green world', 'social' => 'good company', 'domestic' => 'the comforts of home',
        'adventure' => 'the road and what lies down it', 'spiritual' => 'the gods and matters of faith',
        'wealth' => 'fine and valuable things', 'danger' => 'danger', 'crowd' => 'crowds',
        'wild' => 'untamed places', 'confined' => 'closed-in places', 'dark' => 'dark things',
        'sacred' => 'holy things', 'luxury' => 'luxury', 'quiet' => 'quiet',
    ];

    /**
     * INTERIM felt read for the LLM (prose, never numbers); null when there is no feeling.
     * $kind 'topic' (talk about $name) or 'item' (a gift of $name).
     */
    public static function feltText(string $npcName, array $appraisal, string $kind, string $name): ?string
    {
        $dominant = $appraisal['dominant'] ?? null;
        $v = (float) ($appraisal['valence'] ?? 0.0);
        if ($dominant === null || abs($v) < 1e-9) {
            return null;
        }
        $phrase = self::FACET_PHRASES[$dominant] ?? $dominant;
        $positive = $v > 0;
        if ($kind === 'item') {
            return $positive
                ? "{$npcName} turns the {$name} over with real pleasure: it speaks to {$phrase}, exactly the kind of thing {$npcName} values."
                : "{$npcName} accepts the {$name} politely, but it speaks to {$phrase}, and that means little to {$npcName}.";
        }
        return $positive
            ? "{$npcName} was genuinely drawn in by the talk of {$name}: it touched {$phrase}, something close to {$npcName}'s heart. If it comes up again, {$npcName} will light up."
            : "{$npcName} found the talk of {$name} tiresome: it is all {$phrase}, which holds nothing for {$npcName}, and attention drifted.";
    }
}
