<?php
/**
 * Relationship Dynamics — Debug Panel
 *
 * Shows all dynamics state for an NPC.
 * Usage: /ext/relationship_dynamics/debug_compose.php?npc=Ashe
 */

header('Content-Type: text/plain; charset=utf-8');

// Bootstrap (3.4.1: conf/conf.php is empty, use the runtime bootstrap)
$enginePath = realpath(__DIR__ . '/../../') . '/';
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrapIfNeeded($enginePath, [
    'run_db_updates' => false,
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => true,
]);
$GLOBALS['PLAYER_NAME'] = $GLOBALS['PLAYER_NAME'] ?? 'Player';

// Load Sharmat NsfwNpcData if available
$nsfwDataPath = $enginePath . 'ext/aiagent_nsfw/nsfw_data.php';
if (file_exists($nsfwDataPath)) {
    require_once $nsfwDataPath;
}

require_once __DIR__ . '/relationship_dynamics.php';

$npcName = $_GET['npc'] ?? 'Ashe';
echo "=== Relationship Dynamics Debug: {$npcName} ===\n\n";

// Config
$cfg = RelationshipDynamics::getConfig();
echo "--- Config ---\n";
echo "enabled: " . ($cfg['enabled'] ? 'YES' : 'NO') . "\n";
echo "base_passion_gain: {$cfg['base_passion_gain']}\n";
echo "passion_max: {$cfg['passion_max']}\n";
echo "log_enabled: " . ($cfg['log_enabled'] ? 'YES' : 'NO') . "\n\n";

// Dynamics
$dyn = RelationshipDynamics::getDynamics($npcName);
echo "--- Dynamics State ---\n";
echo "Love Language Primary:   " . ($dyn['love_language_primary'] ?? '(not set)') . "\n";
echo "Love Language Secondary:  " . ($dyn['love_language_secondary'] ?? '(not set)') . "\n";
echo "Warmth Curve:            " . ($dyn['warmth_curve'] ?? '(not set)') . "\n";
echo "Inferred Temperament:    " . ($dyn['inferred_temperament'] ?? '(none)') . "\n\n";

echo "Passion:                 " . number_format(floatval($dyn['passion']), 1) . " / {$cfg['passion_max']}\n";
echo "Passion Band:            " . RelationshipDynamics::getPassionBand($dyn['passion']) . "\n";
// passion_updated_at / last_interaction_at / last_seen_at are on the play-gamets clock
$playMinute = RelationshipDynamics::GAMETS_PER_REAL_SECOND * 60.0;
$passionSince = RelationshipDynamics::playGametsSince($dyn, 'passion_updated_at');
echo "Passion Last Updated:    " . ($passionSince !== null ? round($passionSince / $playMinute) . " play-min ago" : 'never') . "\n";
echo "Passion Sources:         " . json_encode($dyn['passion_sources'] ?? []) . "\n\n";

echo "Affinity Gain Mult:      " . number_format(RelationshipDynamics::getAffinityGainMultiplier($dyn), 2) . "x (RPM→Speed)\n";
echo "Session Multiplier:      " . number_format(RelationshipDynamics::getSessionMultiplier($dyn), 2) . "x (diminishing returns)\n\n";

echo "Jealousy Anger:          " . number_format(floatval($dyn['jealousy_anger']), 1) . "\n";
echo "Jealousy Band:           " . RelationshipDynamics::getJealousyBand($dyn['jealousy_anger']) . "\n";
echo "Jealousy Trigger NPC:    " . ($dyn['jealousy_trigger_npc'] ?? '(none)') . "\n\n";

echo "In Conflict:             " . ($dyn['in_conflict'] ? 'YES' : 'no') . "\n";
echo "Conflict Positive Count: " . intval($dyn['conflict_positive_count']) . "\n\n";

echo "Interaction Count:       " . intval($dyn['interaction_count']) . "\n";
$intSince = RelationshipDynamics::playGametsSince($dyn, 'last_interaction_at');
echo "Last Interaction:        " . ($intSince !== null ? round($intSince / $playMinute) . " play-min ago" : 'never') . "\n";

$seenSince = RelationshipDynamics::playGametsSince($dyn, 'last_seen_at');
echo "Last Seen:               " . ($seenSince !== null ? round($seenSince / RelationshipDynamics::GAMETS_PER_REAL_HOUR, 1) . " play-h ago" : 'never') . "\n";
echo "Reunion Spike Given:     " . ($dyn['reunion_spike_given'] ? 'YES' : 'no') . "\n\n";

echo "Stage:                   " . strtoupper($dyn['stage'] ?? 'early') . "\n";
echo "Total Positive Ints:     " . intval($dyn['total_positive_interactions']) . "\n";
echo "LL Hints Given:          " . intval($dyn['love_language_hints_given']) . "\n\n";

// Curve parameters
$curve = $dyn['warmth_curve'] ?? 'moderate';
$params = RelationshipDynamics::CURVE_PARAMS[$curve] ?? [];
if ($params) {
    echo "--- Warmth Curve: {$curve} ---\n";
    echo "Decay Rate:              {$params['decay_rate']} per interaction\n";
    echo "Half-Life:               {$params['half_life']}h\n";
    echo "Lambda:                  {$params['lambda']}\n";
    echo "Passion Decay:           {$params['passion_decay']}/hour\n\n";
}

// Stage parameters
$stage = $dyn['stage'] ?? 'early';
$stageParams = RelationshipDynamics::STAGE_PARAMS[$stage] ?? [];
if ($stageParams) {
    echo "--- Stage: {$stage} ---\n";
    echo "Passion Floor:           {$stageParams['floor']}\n";
    echo "Passion Ceiling:         {$stageParams['ceiling']}\n";
    echo "Gain Multiplier:         {$stageParams['gain_mult']}x\n";
    echo "DR Rate Modifier:        {$stageParams['dr_mult']}x\n\n";
}

// CHIM affinity for context
try {
    $db = $GLOBALS['db'];
    $escaped = $db->escape($npcName);
    $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
    if (is_array($row) && !empty($row['extended_data'])) {
        $ext = json_decode($row['extended_data'], true) ?: [];
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended($ext); // CHIM 3.4.1 key "Player"
        if ($rel) {
            echo "--- CHIM Relationship ---\n";
            echo "Affinity:                " . ($rel['aff'] ?? '?') . "\n";
            echo "Type:                    " . ($rel['type'] ?? '?') . "\n";
            if (isset($rel['maras'])) {
                echo "MARAS Affection:         " . ($rel['maras']['affection'] ?? '?') . "\n";
                echo "MARAS Status:            " . ($rel['maras']['status'] ?? '?') . "\n";
                echo "MARAS Temperament:       " . ($rel['maras']['temperament'] ?? '?') . "\n";
            } else {
                echo "MARAS:                   (no data — running without MARAS)\n";
            }
        }
    }
} catch (Throwable $e) {
    RelationshipDynamics::logError('debug_compose', $e);
    echo "Error reading CHIM data: " . $e->getMessage() . "\n";
}

echo "\n--- Simulation ---\n";
echo "If you talk to {$npcName} right now:\n";
$simDyn = $dyn;
$simGain = RelationshipDynamics::calculatePassionGain($simDyn, $dyn['love_language_primary']);
echo "  Primary LL match passion gain: +" . number_format($simGain, 1) . "\n";
$simGain2 = RelationshipDynamics::calculatePassionGain($simDyn, 'quality_time');
echo "  Quality time (generic) gain:   +" . number_format($simGain2, 1) . "\n";
$simGain3 = RelationshipDynamics::calculatePassionGain($simDyn, null);
echo "  Unclassified interaction:      +0.0 (no passion from noise)\n";
echo "  Current effective disposition:  " . RelationshipDynamics::getEffectiveDisposition(10, $simDyn) . " (base=10)\n";
