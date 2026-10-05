<?php
/**
 * Relationship Dynamics — registration for the CHIM fork hook that lets RelDyn keep repeating scene dialogue
 * out of core's eventlog (lib/relationship_manager.php chimBackgroundChatSkipped(), asked by logEvent()
 * in lib/chat_helper_functions.php for every 'chat_background' row it is about to store).
 *
 * The Forgotten City's time loop replays the same voiced lines every loop. With vanilla-dialogue capture on
 * they would flood chat_background and the window the dynamic profile reads. RelDyn's config
 * (eval_producer.scene_excluded_locations, scene_skip_write) names the places whose scene lines are not
 * stored. Core loads this file itself (every ext/<name>/background_chat_gate.php) the first time it asks.
 * RelDynEval::backgroundChatSkipped() decides; any failure, RelDyn off or the flag off stores the line as
 * core always did.
 */

$GLOBALS['CHIM_BACKGROUND_CHAT_GATES']['reldyn'] = static function (string $data, ?string $location): bool {
    try {
        require_once __DIR__ . '/relationship_dynamics.php';
        require_once __DIR__ . '/eval_producer.php';
        return RelDynEval::backgroundChatSkipped($data, $location);
    } catch (\Throwable $e) {
        if (class_exists('RelationshipDynamics', false)) RelationshipDynamics::logError('background chat gate', $e);
        else error_log('[RelDyn] ERROR background chat gate: ' . $e->getMessage());
        return false;
    }
};
