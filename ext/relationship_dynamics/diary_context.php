<?php
/**
 * Relationship Dynamics — registration for the CHIM fork hook that puts RelDyn's marked moments and
 * the NPC's maturity depth into core's diary prompt (lib/relationship_manager.php chimDiaryContextFor(),
 * asked by core's three diary writers: generateFollowerDiary, generateNearbyDiary and the player's
 * 'diary' request in processor/request.php). Decisions §20 #19.
 *
 * Core loads this file itself (every ext/<name>/diary_context.php) the first time it asks. The RelDyn
 * classes load only then. RelDynDiary::promptContext() decides; null (the Narrator, an NPC RelDyn has
 * never held, RelDyn or the diary reflection off) leaves core's prompt exactly as it was.
 */

$GLOBALS['CHIM_DIARY_CONTEXT_PROVIDERS']['reldyn'] = static function (string $npcName): ?string {
    try {
        require_once __DIR__ . '/relationship_dynamics.php';
        return RelDynDiary::promptContext($npcName);
    } catch (\Throwable $e) {
        if (class_exists('RelationshipDynamics', false)) RelationshipDynamics::logError('diary prompt context', $e);
        else error_log('[RelDyn] ERROR diary prompt context: ' . $e->getMessage());
        return null;
    }
};
