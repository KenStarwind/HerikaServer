<?php
/**
 * Relationship Dynamics — the player mirror's spider graph (read-only JSON; player-profile design,
 * project_player_profile_spider, for the P5 UI).
 *
 * GET  ->  {"ok": true, "known", "mode", "observations", "interactions", "npcs",
 *           "axes": [{"axis", "label", "value", "observed", "band", "keywords", "evidence",
 *                     "confidence", "trend", "was"}],
 *           "charisma", "attachment", "love_language", "validation_locus", "reputation"}
 * (RelDynMirror::spider). It writes nothing. Numbers are for the page, never for the LLM.
 */

header('Content-Type: application/json');

$enginePath = realpath(__DIR__ . '/../../') . '/';
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrapIfNeeded($enginePath, [
    'run_db_updates' => false,
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => true,
]);

require_once __DIR__ . '/relationship_dynamics.php';

try {
    echo json_encode(['ok' => true] + RelDynMirror::spider(), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[RelDyn] ERROR api_player_mirror: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'player mirror read failed']);
}
