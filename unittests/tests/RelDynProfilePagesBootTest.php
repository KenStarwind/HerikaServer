<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * player-profile-page / debug-tooling: the new standalone pages boot on CHIM 3.4.1 without
 * Postgres (runtime bootstrap, a pre-seeded in-memory $db as a running CHIM request has), lint
 * clean, render, and write nothing on a GET or on a POST without the CSRF token.
 * (The full pages against real Postgres: RelDynProfilePagesPostgresTest.)
 */
final class RelDynProfilePagesBootTest extends TestCase
{
    private const PAGES = [
        'player.php'         => 'Player profile: Kaida',
        'debug_pipeline.php' => 'Pipeline dry run',
        'debug_compose.php'  => '=== Relationship Dynamics Debug: Ashe ===',
    ];
    private const LIBS = ['reldyn_ui_charts.php', 'reldyn_ui_page.php', 'reldyn_player_view.php', 'reldyn_dry_run.php'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function pages(): array
    {
        $out = [];
        foreach (self::PAGES as $p => $needle) $out[$p] = [$p, $needle];
        return $out;
    }

    public function testEveryNewFileLintsClean(): void
    {
        foreach (array_merge(array_keys(self::PAGES), self::LIBS) as $f) {
            [$code, $out, $err] = self::php(['-l', self::root() . '/ext/relationship_dynamics/' . $f]);
            $this->assertSame(0, $code, "{$f}: {$out}{$err}");
        }
    }

    #[DataProvider('pages')]
    public function testThePageBootsThroughTheRuntimeBootstrapAndRendersWritingNothing(string $page, string $needle): void
    {
        $src = (string) file_get_contents(self::root() . '/ext/relationship_dynamics/' . $page);
        $this->assertStringContainsString('lib/runtime_bootstrap.php', $src);
        $this->assertMatchesRegularExpression('/chimRuntimeBootstrapIfNeeded\(/', $src);
        $this->assertDoesNotMatchRegularExpression('~(require|include)(_once)?[^;]*conf/conf\.php~', $src);

        [$code, $out, $err, $queries, $status] = self::runPage($page, 'GET');
        $this->assertStringNotContainsString('Fatal error', $err, $err);
        $this->assertSame(0, $code, $err);
        $this->assertContains($status, [200, false], 'no error status');
        $this->assertStringContainsString($needle, $out);
        $this->assertSame([], self::writes($queries), 'a GET writes nothing');
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated):[^\n]*relationship_dynamics/', $err);
    }

    public static function posts(): array
    {
        return [
            'opt-in without token' => ['player.php', ['action' => 'prompt_toggle', 'prompt_enabled' => '1'], 'nothing was changed'],
            'opt-in, forged token' => ['player.php', ['action' => 'prompt_toggle', 'prompt_enabled' => '1', 'csrf_token' => str_repeat('a', 64)], 'nothing was changed'],
            'dry run without token' => ['debug_pipeline.php', ['action' => 'dry_run', 'npc' => 'Ashe', 'item' => '{}'], 'nothing was run'],
        ];
    }

    #[DataProvider('posts')]
    public function testAPostWithoutThisSessionsTokenIsRefusedWithNoWrite(string $page, array $post, string $says): void
    {
        [$code, $out, $err, $queries, $status] = self::runPage($page, 'POST', $post);
        $this->assertSame(0, $code, $err);
        $this->assertSame(403, $status);
        $this->assertStringContainsString($says, $out);
        $this->assertSame([], self::writes($queries));
        $this->assertStringNotContainsString('BEGIN', implode("\n", $queries), 'no dry run started');
    }

    private static function writes(array $queries): array
    {
        return array_values(array_filter($queries, fn($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE|BEGIN)\b/i', (string) $q)));
    }

    private static function php(array $args): array
    {
        $proc = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root());
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string) $out, (string) $err];
    }

    /** @return array [exit code, stdout (page), stderr, queries the page ran, http status] */
    private static function runPage(string $page, string $method, array $post = []): array
    {
        $dir = sys_get_temp_dir() . '/reldyn_pboot_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($dir, 0700, true);
        $harness = $dir . '/harness.php';
        file_put_contents($harness, <<<'PHP'
<?php
[$self, $page, $method, $post, $qfile] = $argv;
$GLOBALS['chim_interaction_generation'] = 0;
final class RelDynPagesBootDb
{
    public array $queries = [];
    public function fetchOne($q, $p = []) { $this->queries[] = $q; return null; }
    public function fetchAll($q) { $this->queries[] = $q; return []; }
    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function query($q) { $this->queries[] = $q; return false; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
    public function insert($t, $d) { $this->queries[] = "INSERT {$t}"; return true; }
    public function update($t, $s, $w = 'FALSE') { $this->queries[] = "UPDATE {$t}"; return true; }
    public function delete($t, $w = 'FALSE') { $this->queries[] = "DELETE {$t}"; return true; }
    public function close() {}
}
$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = new RelDynPagesBootDb();
$GLOBALS['PLAYER_NAME'] = 'Kaida';
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['SCRIPT_NAME'] = '/HerikaServer/ext/relationship_dynamics/' . basename($page);
$_GET = [];
$_POST = json_decode($post, true) ?: [];
register_shutdown_function(function () use ($qfile) {
    file_put_contents($qfile, json_encode(['queries' => $GLOBALS['db']->queries, 'status' => http_response_code()]));
});
require $page;
PHP);
        try {
            [$code, $out, $err] = self::php(['-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'session.save_path=' . $dir,
                $harness, self::root() . '/ext/relationship_dynamics/' . $page, $method, json_encode($post), $dir . '/q.json']);
            $q = json_decode((string) @file_get_contents($dir . '/q.json'), true) ?: ['queries' => [], 'status' => null];
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
        return [$code, $out, $err, (array) $q['queries'], $q['status']];
    }
}
