<?php
/**
 * Relationship Dynamics -- Oghma facet builder (CLI).
 *
 * Precomputes a facet vector for every Oghma entry into RelDyn's own table reldyn_oghma_facets
 * (created if missing), see reldyn_facet_classifier.php for the math. Safe to re-run: rows whose
 * source text and config version are unchanged are skipped.
 *
 *   php ext/relationship_dynamics/tools/build_oghma_facets.php [--prior-only] [--force] [--show=topic,...]
 *
 *   --prior-only  do not call the embedding service; knowledge_class / category / tags prior only
 *   --force       recompute every row, not only new or changed ones
 *   --show=a,b    print the stored facets of these topics afterwards
 *
 * The embedding service is core's FEATURES.MEMORY_EMBEDDING.TXTAI_URL + /embed (MiniMe,
 * all-MiniLM-L6-v2, 384-dim). When it does not answer, the run falls back to the prior and says so.
 * Exit codes: 0 done, 1 bad arguments, 2 error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = ['prior_only' => false, 'force' => false];
$show = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--prior-only') {
        $opts['prior_only'] = true;
    } elseif ($arg === '--force') {
        $opts['force'] = true;
    } elseif (str_starts_with($arg, '--show=')) {
        $show = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7))), fn($s) => $s !== ''));
    } else {
        fwrite(STDERR, "unknown argument {$arg}\nusage: php build_oghma_facets.php [--prior-only] [--force] [--show=topic,...]\n");
        exit(1);
    }
}

$enginePath = realpath(__DIR__ . '/../../../') . '/';
require_once $enginePath . 'lib/runtime_bootstrap.php';
require_once $enginePath . 'lib/logger.php';

try {
    // 3.4.1: conf/conf.php is empty; FEATURES (TXTAI_URL) come from general_settings.
    chimRuntimeBootstrap($enginePath, [
        'run_db_updates'        => false,
        'load_general_settings' => true,
        'load_stt_connector'    => false,
        'load_itt_connector'    => false,
        'load_player_name'      => false,
    ]);
    require_once __DIR__ . '/../relationship_dynamics.php';

    $db = $GLOBALS['db'];
    $url = RelDynFacetClassifier::serviceUrl();
    echo 'embedding service: ' . ($opts['prior_only'] ? 'not used (--prior-only)' : ($url === null ? 'not configured' : "{$url}/embed")) . "\n";

    $stats = RelDynFacetClassifier::build($db, $opts);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    foreach ($show as $topic) {
        $row = $db->fetchOne(
            'SELECT topic, method, version, facets::text AS facets FROM ' . RelDynFacetClassifier::TABLE . ' WHERE topic = $1',
            [$topic]
        );
        echo $topic . ': ' . (empty($row['topic']) ? '(no row)' : "{$row['method']} {$row['facets']}") . "\n";
    }
    exit(isset($stats['error']) ? 2 : 0);
} catch (\Throwable $e) {
    error_log('[RelDyn-FACETS] ERROR builder: ' . get_class($e) . ': ' . $e->getMessage());
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(2);
}
