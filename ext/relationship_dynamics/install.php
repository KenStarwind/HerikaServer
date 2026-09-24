<?php
/**
 * Relationship Dynamics — Config Seeder
 *
 * Run once to insert default config into conf_opts.
 * Safe to re-run: does nothing if config already exists.
 *
 * Usage from browser: /ext/relationship_dynamics/install.php
 * Usage from CLI:     php /var/www/html/HerikaServer/ext/relationship_dynamics/install.php
 */

// Bootstrap CHIM if running standalone (3.4.1: conf/conf.php is empty, use the runtime bootstrap)
$enginePath = realpath(__DIR__ . '/../../') . '/';
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrapIfNeeded($enginePath, [
    'run_db_updates' => false,
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => true,
]);

$db = $GLOBALS['db'];

// Check if already installed
$existing = $db->fetchOne("SELECT id FROM conf_opts WHERE id = 'relationship_dynamics_config' LIMIT 1");
if (!empty($existing)) {
    echo "Relationship Dynamics config already exists. No changes made.\n";
    echo "To reset, run: DELETE FROM conf_opts WHERE id = 'relationship_dynamics_config';\n";
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';

$defaultConfig = RelationshipDynamics::defaultConfig();

$jsonConfig = json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$escaped = $db->escape($jsonConfig);

$db->execQuery("INSERT INTO conf_opts (id, value) VALUES ('relationship_dynamics_config', '{$escaped}')");

echo "Relationship Dynamics config installed successfully!\n";
echo "Config size: " . strlen($jsonConfig) . " bytes\n";
echo "\nCore model: Passion (RPM) drives Affinity gain (Speed)\n";
echo "Systems: Love Languages, Diminishing Returns, Passion, Reunion, Jealousy, Repair, Stages\n";
echo "\nTo disable: UPDATE conf_opts SET value = jsonb_set(value::jsonb, '{enabled}', 'false')::text WHERE id = 'relationship_dynamics_config';\n";
