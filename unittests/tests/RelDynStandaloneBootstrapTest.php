<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RelDyn standalone pages must boot on CHIM 3.4.1.
 *
 * 3.4.1 ships an empty conf/conf.php; standalone pages bootstrap through
 * lib/runtime_bootstrap.php (chimRuntimeBootstrap). Each page is executed in
 * its own PHP process with a pre-built in-memory $db, so the full page runs
 * without Postgres and without touching any real database.
 */
final class RelDynStandaloneBootstrapTest extends TestCase
{
    private const PAGES = ['settings.php', 'install.php', 'api_save_npc.php', 'debug_compose.php'];

    private static function engineRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function pagePath(string $page): string
    {
        return self::engineRoot() . '/ext/relationship_dynamics/' . $page;
    }

    public static function pageProvider(): array
    {
        $cases = [];
        foreach (self::PAGES as $page) {
            $cases[$page] = [$page];
        }
        return $cases;
    }

    #[DataProvider('pageProvider')]
    public function testPageLintsClean(string $page): void
    {
        [$code, $out, $err] = self::runPhp(['-l', self::pagePath($page)]);
        $this->assertSame(0, $code, "php -l failed for {$page}: {$out}{$err}");
    }

    #[DataProvider('pageProvider')]
    public function testPageUsesRuntimeBootstrapInsteadOfConfPhp(string $page): void
    {
        $src = file_get_contents(self::pagePath($page));
        $this->assertDoesNotMatchRegularExpression('~(require|include)(_once)?[^;]*conf/conf\.php~', $src, "{$page} still requires conf/conf.php (empty on 3.4.1)");
        $this->assertStringContainsString('lib/runtime_bootstrap.php', $src);
        $this->assertMatchesRegularExpression('/chimRuntimeBootstrap(IfNeeded)?\(/', $src);
    }

    #[DataProvider('pageProvider')]
    public function testPageBootsAndRunsWithoutDatabase(string $page): void
    {
        $harness = self::writeHarness();
        try {
            [$code, $out, $err] = self::runPhp([
                '-d', 'display_errors=stderr',
                '-d', 'log_errors=0',
                $harness,
                self::engineRoot(),
                self::pagePath($page),
            ]);
        } finally {
            @unlink($harness);
        }

        $this->assertStringNotContainsString('Fatal error', $err, "{$page} fatals:\n{$err}");
        $this->assertStringNotContainsString('Failed opening required', $err, "{$page}:\n{$err}");
        $this->assertSame(0, $code, "{$page} exited {$code}\nSTDOUT:\n" . substr($out, 0, 2000) . "\nSTDERR:\n{$err}");

        // Bootstrap resolved the engine root and loaded the 3.4.1 runtime.
        $this->assertStringContainsString('__RELDYN_BOOT__ engine=' . rtrim(realpath(self::engineRoot()), '/') . '/', $out);
        $this->assertStringContainsString('runtime=1', $out);
    }

    public function testPagesOutputExpectedContent(): void
    {
        $expect = [
            'install.php'       => 'Relationship Dynamics config',
            'api_save_npc.php'  => 'Missing NPC name',
            'debug_compose.php' => '=== Relationship Dynamics Debug',
            'settings.php'      => '<',
        ];
        foreach ($expect as $page => $needle) {
            $harness = self::writeHarness();
            try {
                [$code, $out, $err] = self::runPhp(['-d', 'display_errors=stderr', $harness, self::engineRoot(), self::pagePath($page)]);
            } finally {
                @unlink($harness);
            }
            $this->assertSame(0, $code, "{$page}: {$err}");
            $this->assertStringContainsString($needle, $out, "{$page} did not render");
        }
    }

    /**
     * Hooks are discovered by file name (lib/data_functions.php requireFilesRecursively),
     * but ui/server_plugins.php only lists ext/ folders that ship a manifest.json.
     */
    public function testPluginManifestIsListedByServerPluginsPage(): void
    {
        $path = self::engineRoot() . '/ext/relationship_dynamics/manifest.json';
        $this->assertFileExists($path);
        $manifest = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($manifest, 'manifest.json is not valid JSON');
        foreach (['name', 'description', 'version'] as $key) {
            $this->assertIsString($manifest[$key] ?? null, "manifest.json missing {$key}");
            $this->assertNotSame('', trim($manifest[$key]));
        }
        $this->assertArrayNotHasKey('config_url', $manifest, 'config_url UI is out of scope for the runtime port');
    }

    private static function runPhp(array $args): array
    {
        $cmd = array_merge([PHP_BINARY], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::engineRoot());
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start PHP subprocess');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, (string)$out, (string)$err];
    }

    /**
     * Harness: pre-seeds an in-memory $db (as a running CHIM request would have)
     * so chimRuntimeBootstrap never opens a Postgres connection, then runs the page.
     */
    private static function writeHarness(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'reldyn_boot_');
        @unlink($base);
        $path = $base . '.php';
        file_put_contents($path, <<<'PHP'
<?php
[$self, $engineRoot, $page] = $argv;
// Skip the installation-wide interaction state file (would be created under conf/).
$GLOBALS['chim_interaction_generation'] = 0;

final class RelDynBootHarnessDb
{
    public array $queries = [];
    public function fetchOne($q) { $this->queries[] = $q; return null; }
    public function fetchAll($q) { $this->queries[] = $q; return []; }
    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function query($q) { $this->queries[] = $q; return false; }
    public function escape($s) { return str_replace("'", "''", (string)$s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
    public function insert($t, $d) { $this->queries[] = "INSERT {$t}"; return true; }
    public function update($t, $s, $w = 'FALSE') { $this->queries[] = "UPDATE {$t}"; return true; }
    public function delete($t, $w = 'FALSE') { $this->queries[] = "DELETE {$t}"; return true; }
    public function close() {}
}

$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = new RelDynBootHarnessDb();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/HerikaServer/ext/relationship_dynamics/' . basename($page);
$_GET = [];

register_shutdown_function(function () {
    echo "\n__RELDYN_BOOT__ engine=" . ($GLOBALS['ENGINE_PATH'] ?? '')
        . " runtime=" . (function_exists('chimRuntimeBootstrap') ? '1' : '0') . "\n";
});

require $page;
PHP);
        return $path;
    }
}
