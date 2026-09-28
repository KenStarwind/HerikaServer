<?php
/**
 * Relationship Dynamics — shared chrome for RelDyn's standalone pages (player.php,
 * debug_pipeline.php): CSRF as CHIM 3.4.1 core's own pages do it (ui/playthrough_manager.php: a
 * session token of random_bytes(32), a hidden csrf_token field, hash_equals on every POST), HTML
 * escaping, the web root, security headers, and the core UI look (ui/tmpl/head.html +
 * ui/tmpl/navbar.php + ui/css/main.css, included by the page itself at global scope, the way core
 * pages and settings.php include them) with RelDyn's section styles.
 */

final class RelDynUiPage
{
    /** Session key of the RelDyn pages' CSRF token. */
    const CSRF_KEY = 'reldyn_csrf';
    /** POST field carrying it (core's name). */
    const CSRF_FIELD = 'csrf_token';

    /** Start the session (before any output: the CSRF token lives in it). */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::CSRF_KEY];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . self::CSRF_FIELD . '" value="' . self::h(self::csrfToken()) . '">';
    }

    /** True when $post carries this session's token. */
    public static function csrfValid(array $post): bool
    {
        self::startSession();
        $token = $post[self::CSRF_FIELD] ?? null;
        $mine = $_SESSION[self::CSRF_KEY] ?? null;
        return is_string($token) && $token !== '' && is_string($mine) && $mine !== '' && hash_equals($mine, $token);
    }

    /** HTML-escape (text and attribute values). */
    public static function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** CHIM's web root ('/HerikaServer') from the script path. */
    public static function webRoot(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $pos = strpos($script, '/ext/');
        return rtrim($pos !== false ? substr($script, 0, $pos) : '', '/');
    }

    /** Headers every RelDyn page sends (no sniffing, no framing by other sites, no referrer leak). */
    public static function securityHeaders(string $contentType = 'text/html; charset=utf-8'): void
    {
        if (headers_sent()) return;
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
    }

    /** Replace the <title> core's head.html writes (escaped; core does it unescaped). */
    public static function finish(string $buffer, string $title): string
    {
        $t = self::h($title);
        return preg_replace_callback('/(<title>)(.*?)(<\/title>)/is', fn($m) => $m[1] . $t . $m[3], $buffer, 1) ?? $buffer;
    }

    /** The RelDyn pages' links; $current: the page's file name. */
    public static function nav(string $current): string
    {
        $pages = [
            'settings.php' => 'RelDyn hub',
            'npc.php' => 'NPC editor',
            'player.php' => 'Player profile',
            'debug_pipeline.php' => 'Pipeline dry run',
            'debug_compose.php' => 'NPC state dump',
        ];
        $out = '<nav class="rd-nav" aria-label="Relationship Dynamics pages">';
        foreach ($pages as $file => $label) {
            $out .= $file === $current
                ? '<span class="rd-nav-current" aria-current="page">' . self::h($label) . '</span>'
                : '<a href="' . self::h($file) . '">' . self::h($label) . '</a>';
        }
        return $out . '</nav>';
    }

    /** RelDyn section styles (settings.php's look), phone-width friendly. */
    public static function css(): string
    {
        return <<<'CSS'
<style>
html, body { background: #1a1a1a; }
.rd-wrap { padding: 72px 16px 40px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #e0e0e0; max-width: 980px; margin: 0 auto; box-sizing: border-box; }
.rd-wrap *, .rd-wrap *::before, .rd-wrap *::after { box-sizing: border-box; }
.rd-header, .rd-section { background: linear-gradient(180deg, rgba(42,42,42,0.95), rgba(30,30,30,0.98));
    padding: 18px 20px; border-radius: 10px; border: 1px solid #3a3a3a; margin-bottom: 16px; }
.rd-header h1 { font-family: 'MagicCards', serif; color: rgb(242,124,17); margin: 0 0 4px; font-size: 1.6em; letter-spacing: 1px; overflow-wrap: anywhere; }
.rd-header p, .rd-muted { color: #9fb1c9; margin: 0; font-size: 0.9em; }
.rd-section h2 { font-family: 'MagicCards', serif; color: rgb(242,124,17); font-size: 1.15em; margin: 0 0 12px;
    padding-bottom: 8px; border-bottom: 1px solid rgba(242,124,17,0.2); letter-spacing: 1px; }
.rd-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
.rd-nav a, .rd-nav span { padding: 5px 10px; border-radius: 6px; border: 1px solid #4a4a4a; font-size: 0.85em; text-decoration: none; }
.rd-nav a { color: #9fb1c9; background: #222; }
.rd-nav a:hover { border-color: rgb(242,124,17); color: #fff; }
.rd-nav .rd-nav-current { color: #1a1a1a; background: rgb(242,124,17); border-color: rgb(242,124,17); font-weight: 600; }
.rd-badge { display: inline-block; font-family: monospace; font-size: 0.75em; color: #5ab0f0; border: 1px solid rgba(90,176,240,0.5);
    padding: 2px 8px; border-radius: 4px; margin-left: 6px; vertical-align: middle; }
.rd-dim { display: grid; grid-template-columns: minmax(120px, 170px) 1fr auto; gap: 4px 12px; align-items: center;
    padding: 8px 0; border-bottom: 1px solid #2e2e2e; }
.rd-dim:last-child { border-bottom: none; }
.rd-dim-label { font-weight: 600; }
.rd-bar { position: relative; height: 12px; background: #151515; border: 1px solid #4a4a4a; border-radius: 6px; overflow: hidden; }
.rd-bar > span { position: absolute; left: 0; top: 0; bottom: 0; background: linear-gradient(90deg, #b85c0a, rgb(242,124,17)); }
.rd-bar::after { content: ''; position: absolute; left: 50%; top: 0; bottom: 0; border-left: 1px dashed #777; }
.rd-dim-score { font-family: monospace; white-space: nowrap; }
.rd-dim-words { grid-column: 1 / -1; color: #c8c8c8; font-size: 0.86em; }
.rd-dim-words small { color: #8a8a8a; }
.rd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
.rd-tile { background: #202020; border: 1px solid #3a3a3a; border-radius: 8px; padding: 10px 12px; min-width: 0; }
.rd-tile-label { font-family: monospace; font-size: 0.72em; color: #9fb1c9; letter-spacing: 1.2px; text-transform: uppercase; }
.rd-tile-value { font-size: 1.05em; font-weight: 700; margin-top: 2px; overflow-wrap: anywhere; }
.rd-tile-sub { font-size: 0.8em; color: rgb(242,124,17); margin-top: 2px; overflow-wrap: anywhere; }
.rd-prose { font-style: italic; color: #d8d8d8; line-height: 1.55; margin: 0; }
.rd-list { margin: 0; padding-left: 18px; }
.rd-list li { margin: 3px 0; }
.rd-chart { overflow-x: auto; }
.rd-form-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
.rd-wrap select, .rd-wrap textarea, .rd-wrap input[type="text"] { background: #1a1a1a; border: 1px solid #4a4a4a; color: #f0f0f0;
    padding: 6px 10px; border-radius: 6px; max-width: 100%; }
.rd-wrap textarea { width: 100%; font-family: monospace; font-size: 0.82em; }
.rd-btn { background: rgb(242,124,17); color: #1a1a1a; border: none; padding: 7px 14px; border-radius: 6px; font-weight: 600;
    cursor: pointer; text-decoration: none; display: inline-block; }
.rd-btn.rd-secondary { background: #333; color: #e0e0e0; border: 1px solid #555; }
.rd-notice { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
.rd-notice.rd-ok { background: rgba(92,201,138,0.12); border: 1px solid #5cc98a; }
.rd-notice.rd-err { background: rgba(224,102,102,0.12); border: 1px solid #e06666; }
.rd-table-wrap { overflow-x: auto; }
.rd-table { border-collapse: collapse; width: 100%; font-size: 0.85em; }
.rd-table th, .rd-table td { border-bottom: 1px solid #2e2e2e; padding: 5px 8px; text-align: left; vertical-align: top; }
.rd-table th { color: #9fb1c9; font-weight: 600; }
.rd-table td code, .rd-code { font-family: monospace; overflow-wrap: anywhere; white-space: pre-wrap; }
.rd-up { color: #5cc98a; } .rd-down { color: #e06666; }
@media (max-width: 560px) {
    .rd-wrap { padding: 64px 8px 32px; }
    .rd-dim { grid-template-columns: 1fr auto; }
    .rd-dim .rd-bar { grid-column: 1 / -1; grid-row: 2; }
}
</style>
CSS;
    }
}
