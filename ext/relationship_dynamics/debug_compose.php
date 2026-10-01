<?php
/**
 * Relationship Dynamics — NPC state dump (roadmap debug-tooling).
 *
 * Everything the current engine holds for one NPC toward the player, as plain text (or JSON):
 * the bond and dimensions, the personality trait vector, the two attachment axes, the attraction
 * curve, fulfillment (needs vs coverage), concern, natural exclusivity and prompt gating (what she
 * knows of the player). Numbers are for debugging; NPCs never see them (decisions §3). Read-only.
 *
 *   /ext/relationship_dynamics/debug_compose.php?npc=Aela%20the%20Huntress[&format=json]
 */

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

// Load Sharmat NsfwNpcData if available (getDynamics' legacy fallback)
$nsfwDataPath = $enginePath . 'ext/aiagent_nsfw/nsfw_data.php';
if (file_exists($nsfwDataPath)) {
    require_once $nsfwDataPath;
}

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/reldyn_ui_charts.php';
require_once __DIR__ . '/reldyn_player_view.php';

$npcName = trim((string) ($_GET['npc'] ?? 'Ashe'));
$asJson = ($_GET['format'] ?? '') === 'json';
if (!headers_sent()) {
    header($asJson ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');   // never sniffed into HTML: NPC data is printed raw
}

/** Run one section; its failure is logged and shown, never fatal. */
$section = function (string $name, callable $fn) {
    try {
        return $fn();
    } catch (\Throwable $e) {
        RelationshipDynamics::logError("debug_compose {$name}", $e);
        return ['_error' => get_class($e) . ': ' . $e->getMessage()];
    }
};

// a dump queues nothing: one request scope for the whole dump, with the NPC's trait read looked
// up without enqueueing first, so getDynamics' profile resolution finds it memoized (as npc.php does)
RelationshipDynamics::beginRequest();
$section('trait read lookup', fn() => RelDynTraitRead::stateFor($npcName, false));
$now = RelDynPlayerView::gameNow();
$cfg = RelationshipDynamics::getConfig();
$dyn = RelationshipDynamics::getDynamics($npcName);
$known = ($GLOBALS['db'] ?? null) ? RelDynStorage::resolveNpcId($npcName) !== null : false;

$jev = $section('state', fn() => RelDynJev::state($npcName, $dyn, $now));
$traits = $section('traits', function () use ($dyn) {
    $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dyn);
    if ($x === null) return null;
    $near = RelDynTraits::nearestPreset($x);
    return [
        'assignment' => RelDynTraits::assignment(),
        'source' => $dyn['_trait_vector_src'] ?? null,
        'vector' => array_map(fn($v) => round(floatval($v), 3), array_intersect_key(RelDynTraits::toStored($x), array_flip(RelDynTraits::TRAITS))),
        'maturity_start' => isset($x['maturity_start']) ? round(floatval($x['maturity_start']), 2) : null,
        'nearest_preset' => $near['name'], 'distance' => round(floatval($near['distance']), 3),
        'describe' => RelDynTraits::describe($x),
    ];
});
$attachment = $section('attachment', function () use ($dyn) {
    $axes = RelationshipDynamics::getAttachmentAxes($dyn);
    $base = RelationshipDynamics::attachmentBase($dyn);
    return [
        'anxiety' => round(floatval($axes['anxiety']), 3), 'avoidance' => round(floatval($axes['avoidance']), 3),
        'style' => RelationshipDynamics::attachmentStyleOf($axes['anxiety'], $axes['avoidance']),
        'base' => ['anxiety' => round(floatval($base['anxiety']), 3), 'avoidance' => round(floatval($base['avoidance']), 3)],
        'source' => $base['source'] ?? null, 'signals' => $base['signals'] ?? [],
        'drift' => $dyn[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] ?? null,
    ];
});
$fulfillment = $section('fulfillment', fn() => RelationshipDynamics::fulfillmentGraph($npcName, $now > 0 ? $now : null));
$gating = $section('gating', fn() => RelDynGating::knowledge($npcName, $known ? $dyn : null));

if ($asJson) {
    $state = is_array($jev) ? array_diff_key($jev, ['units' => 1]) : $jev;
    echo json_encode(['ok' => true, 'npc' => $npcName, 'stored' => $known, 'gamets' => $now, 'state' => $state,
        'traits' => $traits, 'attachment' => $attachment, 'fulfillment' => $fulfillment, 'gating' => $gating],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return;
}

$fmt = function ($v): string {
    if ($v === null) return '-';
    if (is_bool($v)) return $v ? 'yes' : 'no';
    if (is_float($v) || is_int($v)) return RelDynUiCharts::num(floatval($v), 3);
    if (is_array($v)) return $v === [] ? '[]' : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    return (string) $v;
};
$line = fn(string $label, $v) => str_pad($label . ':', 28) . $fmt($v) . "\n";
$error = fn($x) => is_array($x) && isset($x['_error']) ? "(unreadable: {$x['_error']})\n" : null;

echo "=== Relationship Dynamics Debug: {$npcName} ===\n";
echo "(numbers for debugging; NPCs never see them)\n";
echo $line('Stored state', $known ? 'yes' : 'no (defaults shown)');
echo $line('Game time (gamets)', $now > 0 ? (int) $now : null);
echo "\n--- Config ---\n";
foreach (['enabled', 'dimension_engine_enabled', 'log_enabled', 'attachment_style_enabled', 'attraction_matrix_enabled', 'internal_weather_enabled'] as $k) {
    echo $line($k, !empty($cfg[$k]));
}
echo $line('traits.assignment', is_array($traits) ? ($traits['assignment'] ?? RelDynTraits::assignment()) : RelDynTraits::assignment());
echo $line('player mirror', RelDynMirror::enabled());
echo $line('prompt gating', RelDynGating::enabled());
echo $line('fulfillment', RelDynFulfillment::enabled());
echo $line('concern', RelDynConcern::enabled());
echo $line('pullback', RelDynPullback::enabled());
echo $line('exclusivity', RelDynExclusivity::enabled());

echo "\n--- Bond and dimensions ---\n";
if (($e = $error($jev)) !== null) {
    echo $e;
} else {
    foreach (['affinity', 'affinity_tier', 'relationship_type', 'core_type', 'context_tier', 'trust', 'comfort', 'respect', 'warmth',
                 'maturity', 'passion', 'passion_spike', 'passion_effective', 'jealousy', 'jealousy_rival', 'resentment', 'resentment_self',
                 'self_confidence', 'arousal', 'valence', 'temperament', 'weather', 'weather_pull', 'open_conflict', 'conflict_repairs',
                 'walkaway', 'boundary'] as $k) {
        echo $line($k, $jev[$k] ?? null);
    }
}

echo "\n--- Traits ---\n";
if (($e = $error($traits)) !== null) echo $e;
elseif ($traits === null) echo "(no trait vector)\n";
else {
    echo $line('assignment', $traits['assignment']);
    echo $line('source', $traits['source']);
    foreach ($traits['vector'] as $trait => $v) echo $line("  {$trait}", $v);
    echo $line('maturity_start', $traits['maturity_start']);
    echo $line('nearest preset', $traits['nearest_preset'] . ' (distance ' . RelDynUiCharts::num($traits['distance'], 3) . ')');
    echo $line('reads as', $traits['describe']);
}

echo "\n--- Attachment (two axes) ---\n";
if (($e = $error($attachment)) !== null) echo $e;
else {
    echo $line('anxiety', $attachment['anxiety']);
    echo $line('avoidance', $attachment['avoidance']);
    echo $line('style', $attachment['style']);
    echo $line('base', $attachment['base']);
    echo $line('base source', $attachment['source']);
    echo $line('signals', $attachment['signals']);
    echo $line('drift', $attachment['drift']);
}

echo "\n--- Attraction curve ---\n";
if (($e = $error($jev)) !== null) echo $e;
else {
    $a = (array) ($jev['attraction'] ?? []);
    foreach (['enabled', 'outcome', 'attracted', 'won_over', 'friendzoned', 'hard_zero', 'channel', 'curve', 'spark', 'spark_mult',
                 'passion_mult', 'passion_ceiling', 'charm', 'relief', 'respect_mult', 'score'] as $k) {
        echo $line($k, $a[$k] ?? null);
    }
    foreach ((array) ($a['units'] ?? []) as $unit => $u) {
        echo $line("  pillar {$unit}", 'score ' . $fmt($u['score'] ?? null) . ' / floor ' . $fmt($u['floor'] ?? null)
            . ' -> x' . $fmt($u['m'] ?? null) . (!empty($u['met']) ? ' (met)' : ' (below)'));
    }
}

echo "\n--- Fulfillment (needs vs coverage) ---\n";
if (($e = $error($fulfillment)) !== null) echo $e;
else {
    foreach (['known', 'band', 'trend', 'low'] as $k) echo $line($k, $fulfillment[$k] ?? null);
    echo $line('boundary', $fulfillment['boundary'] ?? null);
    foreach ((array) ($fulfillment['axes'] ?? []) as $ax) {
        echo $line("  {$ax['axis']}", 'need ' . $fmt($ax['need']) . ' / coverage ' . $fmt($ax['coverage']) . " ({$ax['kind']})");
    }
}

echo "\n--- Concern ---\n";
if (($e = $error($jev)) !== null) echo $e;
else foreach ((array) ($jev['concern'] ?? []) as $k => $v) echo $line((string) $k, $v);

echo "\n--- Let in / pulling back ---\n";
if (($e = $error($jev)) !== null) echo $e;
else {
    echo $line('let_in', $jev['let_in'] ?? null);
    foreach ((array) ($jev['pullback'] ?? []) as $k => $v) echo $line((string) $k, $v);
}

echo "\n--- Exclusivity ---\n";
if (($e = $error($jev)) !== null) echo $e;
elseif (!is_array($jev['exclusivity'] ?? null)) echo "(none)\n";
else foreach ($jev['exclusivity'] as $k => $v) echo $line((string) $k, $v);

echo "\n--- Gating (what this NPC knows of the player) ---\n";
if (($e = $error($gating)) !== null) echo $e;
else foreach (['level', 'name', 'bio', 'relationship', 'speech', 'core_aff', 'peak_core_aff', 'hold', 'fames'] as $k) echo $line($k, $gating[$k] ?? null);

echo "\n--- Jev line ---\n";
echo is_array($jev) && isset($jev['text']) ? $jev['text'] . "\n" : ($error($jev) ?? "-\n");
