<?php
/**
 * Relationship Dynamics — per-NPC parameter editor page (decisions 2026-09-23 §4; manifest.json
 * config_url). npc.php lists and searches NPCs; npc.php?npc=<name> shows and edits every RelDyn
 * parameter of one NPC. The logic lives in reldyn_editor.php (RelDynEditor::handle); this file
 * is the CHIM chrome around it (runtime bootstrap, session, core's head / navbar / footer).
 *
 * GET never writes. POST needs the session's CSRF token and redirects back (303).
 */

// Session before any output: the CSRF token and the flash message live in it.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bootstrap CHIM (3.4.1: conf/conf.php is empty, settings come from the runtime bootstrap)
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
require_once __DIR__ . '/reldyn_editor.php';

// Web root for core's assets (as settings.php)
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$extPos = strpos($scriptPath, '/ext/');
$webRoot = ($extPos !== false) ? substr($scriptPath, 0, $extPos) : '';
$webRoot = rtrim($webRoot, '/');

if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}
try {
    $rdResult = RelDynEditor::handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, $_POST, $_SESSION);
} catch (\Throwable $e) {
    RelationshipDynamics::logError('npc.php', $e);
    $rdResult = ['status' => 500, 'location' => null, 'title' => 'Relationship Dynamics',
        'body' => RelDynEditor::renderMessagePage('The editor hit an error (see the server log). Nothing was changed by this page view.', '')];
}

if ($rdResult['status'] === 303 && $rdResult['location'] !== null) {
    header('Location: ' . $rdResult['location'], true, 303);
    exit;
}
http_response_code($rdResult['status']);
header('X-Frame-Options: SAMEORIGIN');

$TITLE = $rdResult['title'];
ob_start();
include $enginePath . 'ui/tmpl/head.html';
include $enginePath . 'ui/tmpl/navbar.php';
echo '<link rel="stylesheet" href="' . htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') . '/ui/css/main.css">';
echo $rdResult['body'];
include $enginePath . 'ui/tmpl/footer.html';
$buffer = ob_get_contents();
ob_end_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], htmlspecialchars($TITLE, ENT_QUOTES, 'UTF-8')) . '$3', $buffer, 1);
echo $buffer;
