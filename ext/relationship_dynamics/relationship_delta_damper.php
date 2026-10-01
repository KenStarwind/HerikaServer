<?php
/**
 * Relationship Dynamics — registration for the CHIM fork hook that lets RelDyn damp core's own
 * NPC-to-NPC romance (lib/relationship_manager.php chimRelationshipDeltaFor(): asked by both core
 * writers of 'aff', RelationshipLLM::applyChanges and RelationshipManager::parseChanges).
 * Decisions §20 #19 ("RelDyn should override most social dynamics within CHIM"), §17.
 *
 * Core loads this file itself (every ext/<name>/relationship_delta_damper.php), also in its
 * standalone relationship worker, which loads no other extension file. The RelDyn classes load
 * only for a gain (a loss is never damped). RelDynExclusivity::dampCoreDelta() decides; any
 * answer but a smaller gain, or any failure, leaves core's delta as it is.
 */

$GLOBALS['CHIM_RELATIONSHIP_DELTA_DAMPERS']['reldyn'] = static function (int $npcId, string $target, int $delta, ?string $newType): int {
    if ($delta <= 0) return $delta;
    try {
        require_once __DIR__ . '/relationship_dynamics.php';
        return RelDynExclusivity::dampCoreDelta($npcId, $target, $delta, $newType);
    } catch (\Throwable $e) {
        if (class_exists('RelationshipDynamics', false)) RelationshipDynamics::logError('exclusivity core damping', $e);
        else error_log('[RelDyn] ERROR exclusivity core damping: ' . $e->getMessage());
        return $delta;
    }
};
