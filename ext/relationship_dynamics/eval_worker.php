<?php
/**
 * Relationship Dynamics — eval worker (CLI, one-shot).
 *
 * Started detached by RelDynEval::launchWorker() after postrequest queues a job. Drains
 * reldyn_eval_queue (one drainer at a time, advisory lock) and exits. Nothing is cached
 * across jobs: every job reads the NPC's dynamics, the config and the connector fresh.
 *
 *   php ext/relationship_dynamics/eval_worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$enginePath = realpath(__DIR__ . '/../../') . '/';
require_once $enginePath . 'lib/runtime_bootstrap.php';
require_once $enginePath . 'lib/logger.php';

try {
    // 3.4.1: conf/conf.php is empty; settings (RELLLM_CONNECTOR, ...) and the player name
    // come from the runtime bootstrap (core's worker hard-sets 'Player', assessment §D).
    chimRuntimeBootstrap($enginePath, [
        'run_db_updates'        => false,
        'load_general_settings' => true,
        'load_stt_connector'    => false,
        'load_itt_connector'    => false,
        'load_player_name'      => true,
    ]);
    // The sql constructor took the playthrough-switch lease (ptr_runtime_enter): while a save
    // is loading the process exits (75) there and the jobs stay queued for the next worker.
    // A switch that starts during the drain stops it between jobs (RelDynEval::drain), and
    // the lease goes when this process exits.
    require_once __DIR__ . '/eval_producer.php';

    $stats = RelDynEval::runWorker();
    error_log('[RelDyn-EVAL] worker done: ' . json_encode($stats));
    exit(0);
} catch (\Throwable $e) {
    error_log('[RelDyn-EVAL] ERROR worker: ' . get_class($e) . ': ' . $e->getMessage());
    exit(2);
}
