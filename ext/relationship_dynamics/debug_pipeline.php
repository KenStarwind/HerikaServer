<?php
/**
 * Relationship Dynamics — pipeline dry run page (roadmap debug-tooling: the planned
 * test_pipeline_live.php, "a dry run of processEvalDeltas showing the before and after").
 *
 * Pick an NPC, paste an eval item (a shared-contract item, or a legacy *_delta result), or load a
 * real one the eval already produced for her (her pending inbox, or the recently applied items
 * RelDyn keeps for save-load rollback), and see what it WOULD change: the per-signal changes, the
 * before/after of her state, every changed value, the player-mirror observation it would record,
 * the affinity the commit would push to core, and the log lines of the run. Nothing is saved
 * (RelDynDryRun: a rolled-back transaction). No LLM call.
 *
 *   GET  debug_pipeline.php[?npc=<name>[&load=inbox:<i>|applied:<i>]]
 *   POST debug_pipeline.php action=dry_run npc=<name> item=<JSON> as_new=0|1 csrf_token=...
 */

$enginePath = realpath(__DIR__ . '/../../') . '/';
require_once $enginePath . 'lib/runtime_bootstrap.php';
require_once __DIR__ . '/reldyn_ui_page.php';
RelDynUiPage::startSession();   // before any output: the CSRF token lives in the session
chimRuntimeBootstrapIfNeeded($enginePath, [
    'run_db_updates' => false,
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => true,
]);

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/reldyn_ui_charts.php';
require_once __DIR__ . '/reldyn_player_view.php';
require_once __DIR__ . '/reldyn_dry_run.php';

$h = [RelDynUiPage::class, 'h'];
$json = fn($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$cell = function ($v) use ($h): string {
    if ($v === null) return '<span class="rd-muted">—</span>';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_float($v)) return $h(RelDynUiCharts::num($v, 4));
    if (is_array($v)) return '<code>' . $h(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>';
    return '<code>' . $h($v) . '</code>';
};

$bonds = RelDynPlayerView::bondNames();
$pick = function (string $want) use ($bonds): ?string {
    foreach ($bonds as $b) if ($want !== '' && mb_strtolower($b) === mb_strtolower($want)) return $b;
    return null;
};
$now = RelDynPlayerView::gameNow();

// Real items the eval produced for her: the pending inbox, then the recently applied ones
$realItems = function (?string $npc): array {
    if ($npc === null) return [];
    $out = [];
    try {
        $id = RelDynStorage::resolveNpcId($npc);
        if ($id !== null) {
            foreach (RelDynStorage::peekItems($id, RelDynStorage::KEY_EVAL_INBOX) as $i => $e) {
                $item = is_array($e['eval'] ?? null) ? $e['eval'] : $e;
                if (is_array($item)) $out["inbox:{$i}"] = ['label' => 'pending: ' . (string) ($item['summary'] ?? '(no summary)'), 'item' => $item];
            }
            $stored = RelDynStorage::loadDynamics($id) ?? [];
            $log = array_reverse(array_values((array) ($stored['_eval_applied_log'] ?? [])));
            foreach (array_slice($log, 0, 20) as $i => $e) {
                $item = is_array($e['item']['eval'] ?? null) ? $e['item']['eval'] : ($e['item'] ?? null);
                if (is_array($item)) $out["applied:{$i}"] = ['label' => 'applied: ' . (string) ($item['summary'] ?? '(no summary)'), 'item' => $item];
            }
        }
    } catch (\Throwable $e) {
        RelationshipDynamics::logError("pipeline dry run: real items for {$npc}", $e);
    }
    return $out;
};

$result = null;
$notice = null;
$npc = null;
$itemText = '';
$asNew = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $npc = $pick(trim((string) ($_POST['npc'] ?? '')));
    $itemText = (string) ($_POST['item'] ?? '');
    $asNew = (string) ($_POST['as_new'] ?? '1') !== '0';
    if (!RelDynUiPage::csrfValid($_POST)) {
        http_response_code(403);
        $notice = 'The form had expired or did not come from this page; nothing was run. Try again.';
    } elseif (($_POST['action'] ?? '') !== 'dry_run') {
        http_response_code(400);
        $notice = 'Unknown request; nothing was run.';
    } elseif ($npc === null) {
        http_response_code(400);
        $notice = 'Choose an NPC RelDyn keeps state for.';
    } elseif (strlen($itemText) > 65536) {
        http_response_code(400);
        $notice = 'The item is too large (64 KB at most).';
    } else {
        $item = json_decode($itemText, true, 32);
        if (!is_array($item) || array_is_list($item)) {
            http_response_code(400);
            $notice = 'The item is not a JSON object: ' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'expected {...}') . '.';
        } else {
            $result = RelDynDryRun::run($npc, $item, ['now' => $now, 'as_new' => $asNew]);
        }
    }
} else {
    $npc = $pick(trim((string) ($_GET['npc'] ?? '')));
}
$reals = $realItems($npc);
if ($itemText === '' && $npc !== null) {
    $load = (string) ($_GET['load'] ?? '');
    $itemText = (string) $json(isset($reals[$load]) ? $reals[$load]['item'] : RelDynDryRun::sampleItem($npc, $now));
}

// ---------------------------------------------------------------- the page
RelDynUiPage::securityHeaders();
$webRoot = RelDynUiPage::webRoot();
$TITLE = 'CHIM - RelDyn Pipeline Dry Run';
ob_start();
include $enginePath . 'ui/tmpl/head.html';
echo '<link rel="stylesheet" href="' . $h($webRoot) . '/ui/css/main.css">';
include $enginePath . 'ui/tmpl/navbar.php';
echo RelDynUiPage::css();
?>
<main>
<div class="rd-wrap" id="reldyn-pipeline">
    <?php echo RelDynUiPage::nav('debug_pipeline.php'); ?>
    <header class="rd-header">
        <h1>Pipeline dry run</h1>
        <p>What one eval item would change for an NPC, through the same code the eval inbox runs. Nothing is saved; no LLM is called.</p>
    </header>

    <?php if ($notice !== null): ?>
    <div class="rd-notice rd-err" role="alert"><?php echo $h($notice); ?></div>
    <?php endif; ?>

    <section class="rd-section" aria-labelledby="rd-in-h">
        <h2 id="rd-in-h">Eval item</h2>
        <?php if ($bonds === []): ?>
        <p class="rd-muted">No NPC has RelDyn state yet.</p>
        <?php else: ?>
        <form method="get" action="debug_pipeline.php" class="rd-form-row">
            <label for="rd-npc-get">NPC</label>
            <select id="rd-npc-get" name="npc">
                <option value="">Choose…</option>
                <?php foreach ($bonds as $b): ?><option value="<?php echo $h($b); ?>"<?php echo $b === $npc ? ' selected' : ''; ?>><?php echo $h($b); ?></option><?php endforeach; ?>
            </select>
            <?php if ($reals !== []): ?>
            <label for="rd-load">Real item</label>
            <select id="rd-load" name="load">
                <option value="">a sample item</option>
                <?php foreach ($reals as $k => $r): ?><option value="<?php echo $h($k); ?>"<?php echo ($_GET['load'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($r['label'], 0, 90, '…')); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="submit" class="rd-btn rd-secondary">Load</button>
        </form>
        <?php if ($npc !== null): ?>
        <form method="post" action="debug_pipeline.php">
            <?php echo RelDynUiPage::csrfField(); ?>
            <input type="hidden" name="action" value="dry_run">
            <input type="hidden" name="npc" value="<?php echo $h($npc); ?>">
            <label for="rd-item" class="rd-muted">JSON for <?php echo $h($npc); ?> (contract v<?php echo intval(RelationshipDynamics::EVAL_CONTRACT_VERSION); ?> item, or a legacy result with *_delta keys)</label>
            <textarea id="rd-item" name="item" rows="16" spellcheck="false"><?php echo $h($itemText); ?></textarea>
            <div class="rd-form-row">
                <input type="hidden" name="as_new" value="0">
                <label><input type="checkbox" name="as_new" value="1"<?php echo $asNew ? ' checked' : ''; ?>> run an item already applied to this NPC as if new</label>
                <button type="submit" class="rd-btn">Dry run</button>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if (is_array($result)): ?>
    <section class="rd-section" id="rd-result" aria-labelledby="rd-out-h">
        <h2 id="rd-out-h">Result for <?php echo $h($result['npc']); ?></h2>
        <?php if (!$result['ok']): ?>
        <div class="rd-notice rd-err" role="alert"><?php echo $h($result['error']); ?></div>
        <?php else: ?>
        <div class="rd-notice rd-ok" role="status" id="rd-nothing-saved">Dry run: nothing was saved. <?php echo $h($result['kind'] === 'contract' ? 'Contract item' : 'Legacy result'); ?><?php if (!$result['engine_on']): ?>; the dimension engine is off in the settings, so the pipeline changes nothing<?php endif; ?>.</div>
        <?php endif; ?>
        <?php foreach ($result['warnings'] as $w): ?><div class="rd-notice rd-err"><?php echo $h($w); ?></div><?php endforeach; ?>

        <?php if ($result['ok']): ?>
        <h3>Per signal</h3>
        <?php if ($result['totals'] === []): ?><p class="rd-muted">No dimension moved.</p><?php else: ?>
        <div class="rd-table-wrap"><table class="rd-table" id="rd-totals"><thead><tr><th>Dimension</th><th>Change</th></tr></thead><tbody>
            <?php foreach ($result['totals'] as $dim => $v): ?><tr><td><?php echo $h($dim); ?></td><td class="<?php echo $v > 0 ? 'rd-up' : ($v < 0 ? 'rd-down' : ''); ?>"><?php echo $h(($v > 0 ? '+' : '') . RelDynUiCharts::num($v, 3)); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <?php if (is_array($result['affinity']) && $result['affinity']['delta'] !== null): ?>
        <p id="rd-affinity">Core affinity (what the commit would push to CHIM): <?php echo $h(RelDynUiCharts::num($result['affinity']['before'], 2)); ?> → <?php echo $h(RelDynUiCharts::num($result['affinity']['after'], 2)); ?>
            (<?php echo $h(($result['affinity']['delta'] > 0 ? '+' : '') . RelDynUiCharts::num($result['affinity']['delta'], 2)); ?>)</p>
        <?php endif; ?>

        <h3>Before and after</h3>
        <div class="rd-table-wrap"><table class="rd-table" id="rd-headline"><thead><tr><th>State</th><th>Before</th><th>After</th></tr></thead><tbody>
            <?php $same = 0; foreach ($result['headline'] as $path => [$b, $a]): if ($b === $a) { $same++; continue; } ?>
            <tr><td><?php echo $h($path); ?></td><td><?php echo $cell($b); ?></td><td><?php echo $cell($a); ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <p class="rd-muted"><?php echo intval($same); ?> other summary values unchanged.</p>

        <?php if ($result['feelings'] !== []): ?>
        <h3>Feelings (grievance, jealousy, repair, concern)</h3>
        <pre class="rd-code" id="rd-feelings"><?php echo $h($json($result['feelings'])); ?></pre>
        <?php endif; ?>

        <h3>Player mirror</h3>
        <?php if (is_array($result['mirror_observation'])): ?>
        <pre class="rd-code" id="rd-mirror"><?php echo $h($json($result['mirror_observation'])); ?></pre>
        <?php else: ?><p class="rd-muted">No observation would be recorded (the mirror is off, the item has no game time, or it is a legacy result).</p><?php endif; ?>

        <h3>Every changed value (<?php echo intval($result['changes_total']); ?>)</h3>
        <div class="rd-table-wrap"><table class="rd-table" id="rd-changes"><thead><tr><th>Path</th><th>Before</th><th>After</th></tr></thead><tbody>
            <?php foreach ($result['changes'] as $path => [$b, $a]): ?><tr><td><code><?php echo $h($path); ?></code></td><td><?php echo $cell($b); ?></td><td><?php echo $cell($a); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($result['changes_total'] > count($result['changes'])): ?><p class="rd-muted">First <?php echo count($result['changes']); ?> shown.</p><?php endif; ?>

        <?php if ($result['normalized'] !== null): ?>
        <h3>The item as the pipeline read it</h3>
        <pre class="rd-code"><?php echo $h($json($result['normalized'])); ?></pre>
        <?php endif; ?>
        <?php endif; ?>

        <h3>Log lines</h3>
        <?php if ($result['log'] === []): ?><p class="rd-muted">Nothing logged. Turn on the debug log in Settings to see the eval math lines.</p>
        <?php else: ?><pre class="rd-code" id="rd-log"><?php echo $h(implode("\n", $result['log'])); ?></pre><?php endif; ?>
    </section>
    <?php endif; ?>
</div>
</main>
<?php
include $enginePath . 'ui/tmpl/footer.html';
echo RelDynUiPage::finish((string) ob_get_clean(), $TITLE);
