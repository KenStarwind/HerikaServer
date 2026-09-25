<?php
/**
 * Relationship Dynamics — fulfillment spider graph (read-only JSON, rulings 2026-09-24 §9).
 *
 * GET ?npc=<name>[&target=<name>]  ->  {"ok": true, "npc", "target", "known", "gamets", "band", "trend", "low",
 *                       "axes": [{"axis", "kind", "label", "need", "coverage"}], "boundary": {...}}
 * (RelationshipDynamics::fulfillmentGraph / RelDynFulfillment::graph). Fulfillment is per
 * relationship pair (rulings §11): target defaults to the player ('Player'). For the P5 UI; it
 * writes nothing. Numbers are for the page, never for the LLM.
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

$npcName = trim((string) ($_GET['npc'] ?? ''));
if ($npcName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing NPC name']);
    exit;
}

$target = trim((string) ($_GET['target'] ?? ''));
if ($target === '') $target = RelDynFulfillment::PLAYER;

try {
    echo json_encode(['ok' => true] + RelationshipDynamics::fulfillmentGraph($npcName, null, $target), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log("[RelDyn] ERROR api_fulfillment for {$npcName}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'fulfillment read failed']);
}
