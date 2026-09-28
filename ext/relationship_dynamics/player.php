<?php
/**
 * Relationship Dynamics — Player Profile page (roadmap player-profile-page;
 * D:\docs\reldyn-player-profile-design.md "The Profile Page"; memory project_player_profile_spider).
 *
 * "The mirror you didn't ask for": a read-only view of how NPCs collectively experience the
 * player (RelDynMirror, batch R): the dimension bars with their bands, charisma, attachment with
 * its transition, love language, validation locus, reputation, a prose paragraph, the growth
 * trajectory, the spider graph, and Rangroo's shareable card (inline SVG; downloadable as a
 * standalone .svg or .html file). Per-NPC fulfillment spider (needs vs what the bond covers).
 *
 * One write: the opt-in that lets NPCs sense the profile (player_mirror.prompt.enabled; Rangroo:
 * "option to keep it off / not inject into prompt"), POST with CSRF, through the config store.
 *
 *   GET  player.php[?npc=<name>]            the page
 *   GET  player.php?export=svg|html[&anon=1] the card as a file (anon: without the player's name)
 *   POST player.php action=prompt_toggle prompt_enabled=0|1 csrf_token=...
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

$h = [RelDynUiPage::class, 'h'];
$playerName = trim((string) ($GLOBALS['PLAYER_NAME'] ?? ''));
if ($playerName === '') $playerName = 'Player';

// ---------------------------------------------------------------- the one write
$notice = null;
$noticeOk = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!RelDynUiPage::csrfValid($_POST)) {
        http_response_code(403);
        $notice = 'The form had expired or did not come from this page; nothing was changed. Try again.';
    } elseif (($_POST['action'] ?? '') === 'prompt_toggle' && in_array((string) ($_POST['prompt_enabled'] ?? ''), ['0', '1'], true)) {
        $on = (string) $_POST['prompt_enabled'] === '1';
        $noticeOk = RelDynPlayerView::savePromptEnabled($on);
        RelationshipDynamics::clearConfigCache();
        $notice = $noticeOk
            ? ($on ? 'NPCs now sense this profile: a felt line in their prompts, in words, never numbers.' : 'NPCs no longer sense this profile; it stays on this page only.')
            : 'The setting could not be saved (see the server log).';
        if (!$noticeOk) http_response_code(500);
    } else {
        http_response_code(400);
        $notice = 'Unknown request; nothing was changed.';
    }
}

// ---------------------------------------------------------------- the model
$state = RelDynMirror::state();
$cfg = RelDynMirror::config();
$now = RelDynPlayerView::gameNow($state);
$view = RelDynPlayerView::build($state, $cfg, $now, $playerName);

// ---------------------------------------------------------------- exports (the card as a file)
$export = (string) ($_GET['export'] ?? '');
if ($export === 'svg' || $export === 'html') {
    $card = RelDynPlayerView::card($view, $playerName, empty($_GET['anon']));
    $svg = RelDynUiCharts::card($card);
    $base = 'reldyn-profile' . (empty($_GET['anon']) ? '-' . (preg_replace('/[^A-Za-z0-9_-]+/', '-', $playerName) ?: 'player') : '');
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        header('Content-Disposition: attachment; filename="' . trim($base, '-') . '.' . $export . '"');
        header('Content-Type: ' . ($export === 'svg' ? 'image/svg+xml; charset=utf-8' : 'text/html; charset=utf-8'));
    }
    echo $export === 'svg'
        ? "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $svg . "\n"
        : "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
          . '<title>' . $h($card['title']) . " — player profile</title></head>\n<body style=\"margin:0;padding:16px;background:#111;display:flex;justify-content:center\">\n"
          . '<figure style="margin:0;max-width:640px;width:100%">' . preg_replace('/ width="640" height="(\d+)"/', ' width="100%" style="height:auto;display:block"', $svg, 1)
          . "</figure>\n</body></html>\n";
    return;
}

// ---------------------------------------------------------------- the bond spider
$bonds = RelDynPlayerView::bondNames();
$selected = null;
$want = trim((string) ($_GET['npc'] ?? ''));
foreach ($bonds as $b) {
    if ($want !== '' && mb_strtolower($b) === mb_strtolower($want)) $selected = $b;
}
$graph = null;
if ($selected !== null) {
    // a page view queues nothing: the NPC's trait read is looked up without enqueueing inside
    // one request scope, so getDynamics' profile resolution finds it memoized (as npc.php does)
    RelationshipDynamics::beginRequest();
    try {
        RelDynTraitRead::stateFor($selected, false);
        $graph = RelationshipDynamics::fulfillmentGraph($selected, $now > 0 ? $now : null);
    } catch (\Throwable $e) {
        RelationshipDynamics::logError("player page fulfillment for {$selected}", $e);
    } finally {
        RelationshipDynamics::endRequest();
    }
}

// ---------------------------------------------------------------- the page
RelDynUiPage::securityHeaders();
$webRoot = RelDynUiPage::webRoot();
$TITLE = 'CHIM - Player Profile';
ob_start();
include $enginePath . 'ui/tmpl/head.html';
echo '<link rel="stylesheet" href="' . $h($webRoot) . '/ui/css/main.css">';
include $enginePath . 'ui/tmpl/navbar.php';
echo RelDynUiPage::css();

$trendClass = fn(?string $t) => $t === 'up' ? 'rd-up' : ($t === 'down' ? 'rd-down' : '');
$cardSvg = RelDynUiCharts::card($view['card']);
$snippet = '<figure style="margin:0;max-width:640px">' . preg_replace('/ width="640" height="(\d+)"/', ' width="100%" style="height:auto;display:block"', $cardSvg, 1) . '</figure>';
?>
<main>
<div class="rd-wrap" id="reldyn-player">
    <?php echo RelDynUiPage::nav('player.php'); ?>

    <?php if ($notice !== null): ?>
    <div class="rd-notice <?php echo $noticeOk ? 'rd-ok' : 'rd-err'; ?>" role="status"><?php echo $h($notice); ?></div>
    <?php endif; ?>

    <header class="rd-header">
        <h1>Player profile: <?php echo $h($playerName); ?><?php if ($view['mode'] === 'character'): ?><span class="rd-badge">CHARACTER MODE</span><?php endif; ?></h1>
        <p>
            <?php if ($view['known']): ?>
            Auto-generated from <?php echo intval($view['interactions']); ?> NPC interactions with <?php echo intval($view['npcs']); ?> <?php echo $view['npcs'] === 1 ? 'person' : 'people'; ?>
            (<?php echo intval($view['observations']); ?> kept in the window).
            <?php else: ?>
            No interactions observed yet.
            <?php endif; ?>
            <?php if (!$view['mirror_enabled']): ?> The mirror is switched off in the settings: nothing new is observed.<?php endif; ?>
        </p>
    </header>

    <section class="rd-section" id="rd-dimensions" aria-labelledby="rd-dimensions-h">
        <h2 id="rd-dimensions-h">Dimensions</h2>
        <?php foreach ($view['dimensions'] as $d): ?>
        <div class="rd-dim" data-axis="<?php echo $h($d['axis']); ?>">
            <div class="rd-dim-label"><?php echo $h($d['label']); ?></div>
            <div class="rd-bar" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $h(RelDynUiCharts::num($d['score'], 0)); ?>" aria-label="<?php echo $h($d['label']); ?>"><span style="width: <?php echo $h(RelDynUiCharts::num($d['score'], 1)); ?>%"></span></div>
            <div class="rd-dim-score"><?php echo $h(RelDynUiCharts::num($d['score'], 0)); ?> <span class="<?php echo $trendClass($d['trend']); ?>"><?php echo $h(RelDynUiCharts::arrow($d['trend'])); ?></span> <?php echo $h($d['band_name']); ?></div>
            <div class="rd-dim-words"><?php echo $h($d['keywords']); ?>
                <small>· from <?php echo intval($d['evidence']); ?> observation<?php echo $d['evidence'] === 1 ? '' : 's'; ?><?php if (abs($d['score'] - $d['observed']) > 0.05): ?> · observed <?php echo $h(RelDynUiCharts::num($d['observed'], 0)); ?><?php endif; ?></small>
                <?php if (count($d['history']) >= 2): ?><span class="rd-spark"><?php echo RelDynUiCharts::sparkline($d['history'], ['title' => $d['label'] . ' over time']); ?></span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="rd-section" id="rd-reads" aria-labelledby="rd-reads-h">
        <h2 id="rd-reads-h">Style and patterns</h2>
        <div class="rd-grid">
            <div class="rd-tile" id="rd-charisma"><div class="rd-tile-label">Charisma</div>
                <div class="rd-tile-value"><?php echo $h($view['charisma']['text']); ?></div><div class="rd-tile-sub"><?php echo $h($view['charisma']['detail']); ?></div></div>
            <div class="rd-tile" id="rd-attachment"><div class="rd-tile-label">Attachment</div>
                <div class="rd-tile-value"><?php echo $h($view['attachment']['text']); ?></div><div class="rd-tile-sub"><?php echo $h($view['attachment']['detail']); ?></div></div>
            <div class="rd-tile" id="rd-love"><div class="rd-tile-label">Love language</div>
                <div class="rd-tile-value"><?php echo $h($view['love_language']['text']); ?></div><div class="rd-tile-sub">what you do, not what you say</div></div>
            <div class="rd-tile" id="rd-locus"><div class="rd-tile-label">Validation locus</div>
                <div class="rd-tile-value"><?php echo $h($view['validation_locus']['text']); ?></div>
                <div class="rd-tile-sub"><?php echo $h($view['validation_locus']['detail']); ?><?php if ($view['validation_locus']['validators'] !== []): ?> (<?php echo $h(implode(', ', $view['validation_locus']['validators'])); ?>)<?php endif; ?></div></div>
            <div class="rd-tile" id="rd-reputation"><div class="rd-tile-label">Reputation</div>
                <div class="rd-tile-value"><?php echo $h($view['reputation']['text']); ?></div><div class="rd-tile-sub"><?php echo $h($view['reputation']['detail']); ?></div></div>
        </div>
    </section>

    <section class="rd-section" id="rd-prose" aria-labelledby="rd-prose-h">
        <h2 id="rd-prose-h">How NPCs experience you</h2>
        <p class="rd-prose"><?php echo $h($view['prose']); ?></p>
    </section>

    <section class="rd-section" id="rd-trajectory" aria-labelledby="rd-trajectory-h">
        <h2 id="rd-trajectory-h">Growth trajectory</h2>
        <?php if ($view['trajectory']['lines'] !== []): ?>
        <ul class="rd-list">
            <?php foreach ($view['trajectory']['lines'] as $l): ?>
            <li><strong><?php echo $h($l['label']); ?>:</strong> <span class="<?php echo $trendClass($l['trend']); ?>"><?php echo $h($l['arrow']); ?></span> <?php echo $h($l['text']); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="rd-muted"><?php echo $h($view['trajectory']['note']); ?></p>
        <?php endif; ?>
    </section>

    <section class="rd-section" id="rd-card" aria-labelledby="rd-card-h">
        <h2 id="rd-card-h">Spider graph and shareable card</h2>
        <div class="rd-chart"><?php echo $cardSvg; ?></div>
        <div class="rd-form-row">
            <a class="rd-btn" href="player.php?export=svg" download>Download .svg</a>
            <a class="rd-btn rd-secondary" href="player.php?export=html" download>Download .html</a>
            <a class="rd-btn rd-secondary" href="player.php?export=svg&amp;anon=1" download>.svg without my name</a>
        </div>
        <p class="rd-muted">The card never names an NPC. The file is self-contained (no scripts, no fonts or images from elsewhere). HTML snippet to paste anywhere:</p>
        <textarea id="rd-snippet" rows="4" readonly><?php echo $h($snippet); ?></textarea>
    </section>

    <section class="rd-section" id="rd-optin" aria-labelledby="rd-optin-h">
        <h2 id="rd-optin-h">Do NPCs sense this profile?</h2>
        <p>Currently: <strong><?php echo $view['prompt_enabled'] ? 'Yes' : 'No'; ?></strong>.
            <span class="rd-muted">When on, the most telling bands reach NPC prompts as one felt line of how you come across, in words, never numbers.
            When off, this profile stays on this page (reputation with strangers is separate).</span></p>
        <form method="post" action="player.php" class="rd-form-row">
            <?php echo RelDynUiPage::csrfField(); ?>
            <input type="hidden" name="action" value="prompt_toggle">
            <input type="hidden" name="prompt_enabled" value="<?php echo $view['prompt_enabled'] ? '0' : '1'; ?>">
            <button type="submit" class="rd-btn<?php echo $view['prompt_enabled'] ? ' rd-secondary' : ''; ?>"><?php echo $view['prompt_enabled'] ? 'Keep it out of prompts' : 'Let NPCs sense it'; ?></button>
        </form>
    </section>

    <section class="rd-section" id="rd-bonds" aria-labelledby="rd-bonds-h">
        <h2 id="rd-bonds-h">What your bonds need</h2>
        <?php if ($bonds === []): ?>
        <p class="rd-muted">No NPC has RelDyn state yet.</p>
        <?php else: ?>
        <form method="get" action="player.php" class="rd-form-row">
            <label for="rd-npc">NPC</label>
            <select id="rd-npc" name="npc">
                <option value="">Choose…</option>
                <?php foreach ($bonds as $b): ?>
                <option value="<?php echo $h($b); ?>"<?php echo $b === $selected ? ' selected' : ''; ?>><?php echo $h($b); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="rd-btn rd-secondary">Show</button>
        </form>
        <?php if (is_array($graph)): ?>
        <div class="rd-chart" id="rd-fulfillment"><?php echo RelDynUiCharts::fulfillmentSpider($graph); ?></div>
        <p class="rd-muted">
            Overall: <?php echo $h(RelDynUiCharts::num(floatval($graph['band'] ?? 0), 2)); ?> (−1 starving … +1 well fed)<?php if (!empty($graph['low'])): ?>, <strong>low</strong><?php endif; ?>;
            trend <?php echo $h(RelDynUiCharts::num(floatval($graph['trend'] ?? 0), 3)); ?> per game day;
            boundary <?php echo $h($graph['boundary']['state'] ?? 'none'); ?><?php if (isset($graph['boundary']['game_days_left'])): ?> (<?php echo $h(RelDynUiCharts::num(floatval($graph['boundary']['game_days_left']), 1)); ?> game days left)<?php endif; ?>.
            <?php if (empty($graph['known'])): ?>Nothing delivered yet: the needs are read from who they are.<?php endif; ?>
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <p class="rd-muted" style="text-align:center">This profile updates itself from your interactions. You cannot edit it. Only change it.</p>
</div>
</main>
<script>
(function () {
    var t = document.getElementById('rd-snippet');
    if (t) t.addEventListener('focus', function () { t.select(); });
})();
</script>
<?php
include $enginePath . 'ui/tmpl/footer.html';
echo RelDynUiPage::finish((string) ob_get_clean(), $TITLE);
