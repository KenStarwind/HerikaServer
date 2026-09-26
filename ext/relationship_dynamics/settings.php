<?php
/**
 * Relationship Dynamics — the RelDyn hub (roadmap settings-page, prompt-gating-admin).
 *
 * Opened from Server Plugins (manifest.json config_url). Tabs:
 *   Settings       every config key RelDyn has, generated from defaultConfig() and grouped by
 *                  subsystem, with its default, whether the stored row overrides it, and a reset
 *   Prompt gating  who knows the player: switches, tier and fame fragments, the hold map, and a
 *                  read-only preview for one NPC at her real bond or any affinity
 *   NPCs & player  links to the per-NPC editor (npc.php?npc=<name>) and the player profile (player.php)
 *   Reference      formulas and the built-in tables
 *
 * Reads/writes conf_opts 'relationship_dynamics_config' through RelDynSettings (saveConfig, schema
 * stamp). Writes are POST only with the session CSRF token; GET never writes. ?embed=1 drops the
 * CHIM chrome.
 */

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
require_once __DIR__ . '/reldyn_settings.php';
require_once __DIR__ . '/reldyn_settings_view.php';

// Web root for assets (as core pages find it)
$scriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$extPos = strpos($scriptPath, '/ext/');
$webRoot = rtrim(($extPos !== false) ? substr($scriptPath, 0, $extPos) : '', '/');

$embed = isset($_GET['embed']);
$tab = (string) ($_GET['tab'] ?? 'settings');
if (!in_array($tab, ['settings', 'gating', 'people', 'reference'], true)) $tab = 'settings';
$group = (string) ($_GET['group'] ?? 'features');
if (!isset(RelDynSettings::GROUPS[$group])) $group = 'features';
$openSection = isset($_GET['open']) && is_string($_GET['open']) && array_key_exists($_GET['open'], RelDynSettings::defaults())
    ? $_GET['open'] : null;
$previewNpc = trim((string) ($_GET['npc'] ?? ''));
$previewAff = (isset($_GET['aff']) && is_numeric($_GET['aff'])) ? max(-100.0, min(100.0, floatval($_GET['aff']))) : null;

$csrfToken = RelDynSettings::csrfToken();

// =========================================================================
// POST: save or reset (CSRF-checked inside); GET never writes
// =========================================================================
$result = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = RelDynSettings::handlePost($_POST);
    if (!$result['ok'] && !RelDynSettings::csrfValid($_POST['csrf_token'] ?? null) && !headers_sent()) {
        http_response_code(403);
    }
}

$display = RelDynSettings::effective();
$overlay = RelDynSettings::storedOverlay();
$action = RelDynSettingsView::url(['tab' => $tab, 'group' => $tab === 'settings' ? $group : null,
    'open' => $tab === 'settings' ? $openSection : null], $embed);
$npcNames = in_array($tab, ['gating', 'people'], true) ? RelDynSettings::npcNames() : [];
$manifest = json_decode((string) @file_get_contents(__DIR__ . '/manifest.json'), true);
$version = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
$h = [RelDynSettingsView::class, 'h'];

// =========================================================================
// HTML
// =========================================================================
if (!$embed) {
    $TITLE = "Relationship Dynamics";
    ob_start();
    include $enginePath . 'ui/tmpl/head.html';
    echo '<link rel="stylesheet" href="' . $h($webRoot) . '/ui/css/main.css">';
    include $enginePath . 'ui/tmpl/navbar.php';
}
?>
<style>
.rd-wrap {
    --rd-accent: rgb(242, 124, 17);
    --rd-panel: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
    --rd-border: #3a3a3a;
    --rd-text: #e0e0e0;
    --rd-muted: #9a9a9a;
    box-sizing: border-box;
    padding: <?php echo $embed ? '16px' : '80px 16px 48px'; ?>;
    max-width: 1100px;
    margin: 0 auto;
    color: var(--rd-text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.rd-wrap *, .rd-wrap *::before, .rd-wrap *::after { box-sizing: border-box; }
.rd-header { background: var(--rd-panel); padding: 18px 20px; border-radius: 10px; border: 1px solid var(--rd-border); margin-bottom: 14px; }
.rd-header h1 { font-family: 'MagicCards', serif; color: var(--rd-accent); margin: 0 0 4px; font-size: 1.6em; letter-spacing: 1px; }
.rd-header p { color: #9fb1c9; margin: 0; font-size: 0.9em; }
.rd-version { color: var(--rd-muted); font-size: 0.55em; letter-spacing: 0; margin-left: 6px; }
.rd-tabs, .rd-groups { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 14px; }
.rd-tab, .rd-pill { display: inline-block; padding: 7px 14px; border-radius: 8px; border: 1px solid var(--rd-border); background: #242424; color: #cfcfcf; text-decoration: none; font-size: 0.92em; }
.rd-pill { padding: 5px 11px; border-radius: 16px; font-size: 0.85em; }
.rd-tab.active, .rd-pill.active { border-color: var(--rd-accent); color: #fff; background: rgba(242, 124, 17, 0.18); }
.rd-tab:hover, .rd-pill:hover { color: #fff; border-color: rgba(242, 124, 17, 0.6); }
.rd-pill-count { background: var(--rd-accent); color: #111; border-radius: 10px; padding: 0 6px; font-size: 0.8em; margin-left: 4px; }
.rd-section { background: var(--rd-panel); padding: 16px 18px; border-radius: 10px; border: 1px solid var(--rd-border); margin-bottom: 14px; }
.rd-section-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 10px; margin-bottom: 10px; border-bottom: 1px solid rgba(242, 124, 17, 0.2); padding-bottom: 8px; }
.rd-section h2 { font-family: 'MagicCards', serif; color: var(--rd-accent); font-size: 1.15em; margin: 0; letter-spacing: 1px; }
.rd-section h3 { color: #f0c090; font-size: 1em; margin: 14px 0 8px; }
.rd-section h4 { color: #d8d8d8; font-size: 0.9em; margin: 12px 0 4px; }
.rd-count { color: var(--rd-muted); font-size: 0.82em; }
.rd-blurb { color: var(--rd-muted); font-size: 0.86em; margin: 0 0 10px; }
.rd-path { color: #7fa7c9; font-size: 0.78em; background: transparent; word-break: break-all; }
.rd-field { display: grid; grid-template-columns: minmax(180px, 30%) 1fr; gap: 4px 14px; padding: 8px 0; border-bottom: 1px solid #2c2c2c; }
.rd-field-head { grid-row: span 2; min-width: 0; }
.rd-field-head label { display: block; color: #d6d6d6; font-size: 0.9em; margin: 0; }
.rd-field-body, .rd-field-meta, .rd-hint { min-width: 0; }
.rd-field-meta { font-size: 0.78em; color: var(--rd-muted); }
.rd-hint { grid-column: 2; font-size: 0.78em; color: #7d7d7d; }
.rd-field.rd-changed { background: rgba(242, 124, 17, 0.06); }
.rd-field.rd-changed .rd-field-head label { color: #ffcf9f; }
.rd-wrap input[type="text"], .rd-wrap input[type="number"], .rd-wrap input[type="search"], .rd-wrap select, .rd-wrap textarea {
    background: #1a1a1a; border: 1px solid #4a4a4a; color: #f0f0f0; padding: 6px 9px; border-radius: 6px; font-size: 0.9em; max-width: 100%;
}
.rd-wrap input[type="text"], .rd-wrap textarea, .rd-wrap select { width: 100%; }
.rd-wrap input.rd-num { width: 160px; }
.rd-wrap textarea { font-family: inherit; resize: vertical; }
.rd-wrap textarea.rd-json, .rd-pre { font-family: Consolas, 'Courier New', monospace; font-size: 0.82em; }
.rd-wrap input:focus, .rd-wrap textarea:focus, .rd-wrap select:focus { border-color: var(--rd-accent); outline: none; box-shadow: 0 0 4px rgba(242, 124, 17, 0.3); }
.rd-check { width: 20px; height: 20px; accent-color: var(--rd-accent); }
.rd-badge { background: rgba(242, 124, 17, 0.2); color: #ffb870; border: 1px solid rgba(242, 124, 17, 0.5); border-radius: 10px; padding: 0 7px; font-size: 0.9em; }
.rd-note { color: #9fd0ff; }
.rd-default pre { white-space: pre-wrap; margin: 4px 0; color: #bbb; }
.rd-default summary { cursor: pointer; }
.rd-reset { background: #2d2d2d; color: #ffcf9f; border: 1px solid #5a4630; border-radius: 6px; padding: 1px 9px; font-size: 0.95em; cursor: pointer; }
.rd-reset:hover { border-color: var(--rd-accent); }
.rd-node { margin: 6px 0; border-left: 2px solid #333; padding-left: 10px; }
.rd-node > summary { cursor: pointer; color: #f0c090; padding: 4px 0; }
.rd-node-name { font-weight: 600; }
.rd-save-bar { position: sticky; bottom: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 10px; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--rd-border); background: rgba(20, 20, 20, 0.97); z-index: 5; }
.rd-save { background: linear-gradient(180deg, rgb(242, 124, 17), rgb(200, 95, 5)); color: #fff; border: none; padding: 8px 22px; border-radius: 8px; font-weight: 600; cursor: pointer; }
.rd-save:hover { background: linear-gradient(180deg, rgb(255, 140, 30), rgb(242, 124, 17)); }
.rd-save-note { font-size: 0.8em; color: #7d7d7d; }
.rd-form-inline { display: inline; margin-left: auto; }
.rd-msg { font-size: 0.9em; padding: 8px 14px; border-radius: 6px; margin-bottom: 10px; }
.rd-msg.ok { background: rgba(40, 167, 69, 0.15); color: #5ddf7e; border: 1px solid rgba(40, 167, 69, 0.3); }
.rd-msg.err { background: rgba(220, 53, 69, 0.15); color: #f08090; border: 1px solid rgba(220, 53, 69, 0.3); }
.rd-filter { margin: 0 0 12px; }
.rd-filter input { width: 100%; }
.rd-switches .rd-switch { position: relative; }
.rd-switch.rd-off .rd-field-head label { color: #9a9a9a; }
.rd-ships-off-tag { position: absolute; right: 0; top: 8px; font-size: 0.72em; color: #9fd0ff; border: 1px solid #36506a; border-radius: 10px; padding: 0 6px; }
.rd-status { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 6px; }
.rd-status li { font-size: 0.9em; }
.rd-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 7px; background: #666; }
.rd-status li.on .rd-dot { background: #5ddf7e; }
.rd-status li.off .rd-dot { background: #f08090; }
.rd-preview-form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.rd-preview-form input[type="text"] { width: auto; flex: 1 1 200px; }
.rd-preview-form input[type="number"] { width: 110px; }
.rd-pre { white-space: pre-wrap; background: #1a1a1a; border: 1px solid #333; border-radius: 6px; padding: 8px 10px; color: #d4d4d4; margin: 0 0 8px; }
.rd-scroll { overflow-x: auto; }
.rd-table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
.rd-table th { text-align: left; padding: 6px 10px; color: var(--rd-accent); border-bottom: 1px solid #4a4a4a; font-weight: 600; }
.rd-table td { padding: 5px 10px; border-bottom: 1px solid #2a2a2a; color: #c0c0c0; vertical-align: top; }
.rd-kv th { width: 34%; color: #bbb; }
.rd-formula { background: #1a1a1a; border: 1px solid #3a3a3a; border-radius: 6px; padding: 10px 14px; font-family: Consolas, 'Courier New', monospace; font-size: 0.85em; color: #d4d4d4; margin: 8px 0; white-space: pre-wrap; overflow-x: auto; }
.rd-npc-links { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 12px; }
.rd-npc-links a, .rd-link-card { color: #9fd0ff; text-decoration: none; }
.rd-npc-links a:hover, .rd-link-card:hover { text-decoration: underline; }
.rd-link-card { display: inline-block; padding: 8px 14px; border: 1px solid #36506a; border-radius: 8px; }
.rd-hidden { display: none !important; }
@media (max-width: 640px) {
    .rd-wrap { padding: <?php echo $embed ? '10px' : '70px 10px 40px'; ?>; }
    .rd-field { grid-template-columns: 1fr; }
    .rd-field-head { grid-row: auto; }
    .rd-hint { grid-column: 1; }
    .rd-wrap input.rd-num { width: 100%; }
    .rd-ships-off-tag { position: static; display: inline-block; margin-bottom: 4px; }
    .rd-section { padding: 12px 10px; }
}
</style>
<main class="rd-wrap" id="rd-hub">
    <div class="rd-header">
        <h1>Relationship Dynamics<?php if ($version !== ''): ?><span class="rd-version">v<?php echo $h($version); ?></span><?php endif; ?></h1>
        <p>How NPCs feel about the player and each other. Every setting here has a default; only what you change is stored.</p>
    </div>
    <?php echo RelDynSettingsView::tabs($tab, $embed); ?>
    <?php echo RelDynSettingsView::messages($result); ?>

<?php if ($tab === 'settings'): ?>
    <?php echo RelDynSettingsView::groupNav($group, $overlay, $embed); ?>
    <p class="rd-blurb"><?php echo $h(RelDynSettings::GROUPS[$group][1]); ?></p>
    <?php echo RelDynSettingsView::filterBox(); ?>
    <?php if ($group === 'features'): ?>
        <section class="rd-section" id="rd-features"><div class="rd-section-head"><h2>Feature switches</h2></div>
        <?php echo RelDynSettingsView::featuresPanel($display, $overlay, $action, $csrfToken); ?>
        </section>
    <?php else: ?>
        <?php echo RelDynSettingsView::groupPanel($group, $display, $overlay, $action, $csrfToken, $openSection, $embed); ?>
    <?php endif; ?>

<?php elseif ($tab === 'gating'): ?>
    <?php echo RelDynSettingsView::gatingPreviewPanel($previewNpc, $previewAff, $npcNames, $embed); ?>
    <?php echo RelDynSettingsView::filterBox(); ?>
    <?php echo RelDynSettingsView::gatingPanel($display, $overlay, $action, $csrfToken); ?>

<?php elseif ($tab === 'people'): ?>
    <?php echo RelDynSettingsView::peoplePanel($npcNames, $embed); ?>

<?php else: ?>
    <section class="rd-section" id="rd-reference">
        <div class="rd-section-head"><h2>Passion (RPM)</h2></div>
        <div class="rd-formula">affinity_gain_mult = 0.3 + (passion / 100) x 1.7
  passion 0  = x0.3 (idling)    passion 50 = x1.15 (cruising)    passion 100 = x2.0 (redline)</div>
        <div class="rd-section-head"><h2>Dimensions</h2></div>
        <div class="rd-formula">actual_delta = raw_eval_delta * Y_resistance * Z_distance_decay
  Z_decay(away)  = 1 / (1 + |X - baseline| / Z)    Z_decay(toward) = min(3.0, 1 + |X - baseline| / Z)</div>
        <div class="rd-scroll"><table class="rd-table">
            <thead><tr><th>Dimension</th><th>Range</th><th>Z (rubber band)</th><th>Scope</th><th>Special</th></tr></thead>
            <tbody>
                <tr><td>Affinity</td><td>0-100</td><td>25 (wide)</td><td>Per-bond</td><td>Eval-scored</td></tr>
                <tr><td>Passion</td><td>0-100</td><td>10 (tight)</td><td>Per-bond</td><td>Floor + spike, event-driven</td></tr>
                <tr><td>Warmth</td><td>0-100</td><td>20</td><td>Per-bond</td><td>Trajectory-derived</td></tr>
                <tr><td>Trust</td><td>0-100</td><td>30 (wide)</td><td>Per-bond</td><td>Slow to build</td></tr>
                <tr><td>Comfort</td><td>0-100</td><td>20</td><td>Per-bond</td><td>Boundary-sensitive</td></tr>
                <tr><td>Respect</td><td>0-100</td><td>25 (wide)</td><td>Per-bond</td><td>Asymmetric Y</td></tr>
                <tr><td>Maturity</td><td>0-100</td><td>20</td><td>Global</td><td>Asymmetric plasticity</td></tr>
                <tr><td>Resentment</td><td>0-100</td><td>8 (tight)</td><td>Per-bond</td><td>Inverted rubber band</td></tr>
                <tr><td>M/F coords</td><td>-100 to +100</td><td>15</td><td>Global</td><td>Two-axis behavioural mode</td></tr>
                <tr><td>Arousal / valence</td><td>0-100 / -100 to +100</td><td>8-12</td><td>Global</td><td>Two-axis, fast decay</td></tr>
            </tbody>
        </table></div>

        <div class="rd-section-head"><h2>Warmth curves</h2></div>
        <p class="rd-blurb">Per-NPC curve set in the NPC editor; otherwise the curve of the NPC's nearest personality preset (guard sets how slowly warmth is won).</p>
        <?php $curve = fn(string $c) => RelationshipDynamics::CURVE_PARAMS[$c]; ?>
        <div class="rd-scroll"><table class="rd-table">
            <thead><tr><th>Curve</th><th>Nearest personality presets</th><th>Decay rate</th><th>Half-life</th><th>Passion decay</th></tr></thead>
            <tbody>
                <tr>
                    <td>slow_burn</td>
                    <td>Romantic, Gentle, Jealous</td>
                    <td><?php echo $h($curve('slow_burn')['decay_rate']); ?>/int</td><td><?php echo $h($curve('slow_burn')['half_life']); ?>h</td><td><?php echo $h($curve('slow_burn')['passion_decay']); ?>/hr</td>
                </tr>
                <tr>
                    <td>moderate</td>
                    <td>Bold, Humble, Nurturing (and the default)</td>
                    <td><?php echo $h($curve('moderate')['decay_rate']); ?>/int</td><td><?php echo $h($curve('moderate')['half_life']); ?>h</td><td><?php echo $h($curve('moderate')['passion_decay']); ?>/hr</td>
                </tr>
                <tr>
                    <td>quick_warmth</td>
                    <td>Anxious, Playful</td>
                    <td><?php echo $h($curve('quick_warmth')['decay_rate']); ?>/int</td><td><?php echo $h($curve('quick_warmth')['half_life']); ?>h</td><td><?php echo $h($curve('quick_warmth')['passion_decay']); ?>/hr</td>
                </tr>
                <tr>
                    <td>guarded</td>
                    <td>Proud, Defiant, Guarded, Independent, Stoic</td>
                    <td><?php echo $h($curve('guarded')['decay_rate']); ?>/int</td><td><?php echo $h($curve('guarded')['half_life']); ?>h</td><td><?php echo $h($curve('guarded')['passion_decay']); ?>/hr</td>
                </tr>
            </tbody>
        </table></div>

        <div class="rd-section-head"><h2>Relationship stages</h2></div>
        <div class="rd-scroll"><table class="rd-table">
            <thead><tr><th>Stage</th><th>Passion floor</th><th>Passion ceiling</th><th>Gain mult</th><th>DR mult</th></tr></thead>
            <tbody>
            <?php foreach (RelationshipDynamics::STAGE_PARAMS as $stage => $p): ?>
                <tr><td><?php echo $h(ucfirst($stage)); ?></td><td><?php echo $h($p['floor']); ?></td><td><?php echo $h($p['ceiling']); ?></td><td><?php echo $h($p['gain_mult']); ?>x</td><td><?php echo $h($p['dr_mult']); ?>x</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <div class="rd-section-head"><h2>Relationship tiers (core affinity)</h2></div>
        <div class="rd-scroll"><table class="rd-table">
            <thead><tr><th>Tier</th><th>From</th><th>To</th></tr></thead>
            <tbody>
            <?php foreach (RelationshipDynamics::RELATIONSHIP_TIERS as $tier => $r): ?>
                <tr><td><?php echo $h($tier); ?></td><td><?php echo $h($r['min']); ?></td><td><?php echo $h($r['max']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>
</main>
<script>
(function () {
    function filter(inputId, rowSelector, scope) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            (scope || document).querySelectorAll(rowSelector).forEach(function (row) {
                var hit = q === '' || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
                row.classList.toggle('rd-hidden', !hit);
                if (hit && q !== '') {
                    for (var p = row.parentElement; p; p = p.parentElement) {
                        if (p.tagName === 'DETAILS') p.open = true;
                    }
                }
            });
        });
    }
    filter('rd-filter', '.rd-field');
    filter('rd-npc-filter', '#rd-npc-list li');
})();
</script>
<?php
if (!$embed) {
    include $enginePath . 'ui/tmpl/footer.html';
    $buffer = ob_get_contents();
    ob_end_clean();
    $buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
    echo $buffer;
}
