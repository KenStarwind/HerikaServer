<?php
/**
 * Relationship Dynamics — Facets (decisions 2026-09-23 §6: facets -> appraisal -> feeling)
 *
 * One model for everything an NPC experiences (place, item, topic, creature, activity):
 *   - a FACET VECTOR: facet => weight 0..1 over the 11 MDD 1.2 interests plus the
 *     situational facets (danger, crowd, wild, confined, dark, sacred, luxury, quiet);
 *   - the NPC's signed PREFERENCES: facet => -1 (hates) .. 0 (indifferent) .. +1 (loves);
 *   - the APPRAISAL: valence = normalised sum(facet x preference), intensity, and the
 *     dominant facet (biggest |contribution|, sign kept) that decides what the NPC *sees*;
 *   - the FELT TEXT: prose for the LLM from the dominant facet, sign and intensity, never
 *     numbers (decisions §3; Jev gets the numbers).
 *
 * Shared API (lane ownership, 2026-09-24 batch):
 *   placeFacets / currentPlaceContext  places lane (CHIM core locations + eventlog)
 *   thingFacets                        classifier lane (Oghma / keyword tables)
 *   preferences / appraise / feltText  appraisal lane
 * All mapping tables live in editable config, not in code.
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynFacets
{
    /** MDD 1.2: the 11 interest categories. */
    const INTERESTS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
    ];

    /** Decisions §6: situational facets (what a place or thing is like, not what it is about). */
    const SITUATIONAL = ['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'];

    /** Every facet, interests first. A facet vector is facet => weight 0..1. */
    const FACETS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
        'danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet',
    ];

    /**
     * Facet vector (facet => 0..1) for a place. Places lane.
     *
     * @param array $placeContext ['name', 'hold', 'tags', 'is_interior', 'time_of_day', 'weather']
     *                            as currentPlaceContext() reads it from CHIM core
     */
    public static function placeFacets(array $placeContext): array
    {
        return [];
    }

    /** The NPC's current place context from CHIM core data only. Places lane. */
    public static function currentPlaceContext(string $npcName): array
    {
        return [];
    }

    /**
     * Facet vector for a thing. Classifier lane.
     *
     * @param string $kind item|topic|creature|place|activity
     * @return array facet => 0..1, [] when unknown
     */
    public static function thingFacets(string $kind, string $name): array
    {
        return [];
    }

    /**
     * The NPC's signed preferences, facet => -1..+1: auto-derived from class, skills,
     * temperament and traits, with the per-NPC override on top. Appraisal lane.
     */
    public static function preferences(array $dynamics, string $npcName): array
    {
        return [];
    }

    /**
     * Appraise a facet vector against preferences. Appraisal lane.
     *
     * @return array ['valence' => -1..1, 'intensity' => 0..1, 'dominant' => facet|null,
     *                'dominant_sign' => 1|-1, 'contributions' => facet => number]
     */
    public static function appraise(array $prefs, array $facets): array
    {
        return ['valence' => 0.0, 'intensity' => 0.0, 'dominant' => null, 'dominant_sign' => 1, 'contributions' => []];
    }

    /**
     * Prose for the LLM (a feeling, never numbers) from the appraisal's dominant facet,
     * sign and intensity; null when there is nothing worth saying. Appraisal lane.
     *
     * @param string $kind item|topic|creature|place|activity
     */
    public static function feltText(string $npcName, array $appraisal, string $kind, string $name): ?string
    {
        return null;
    }
}
