<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Stand-in for $db that only hands back a stored conf_opts value. It holds no config
 * logic of its own: merging, parsing and filtering are all the engine's.
 */
final class RelDynConfigRowDb
{
    public ?string $value = null;

    public function fetchOne($sql, $params = null)
    {
        if (strpos((string)$sql, 'conf_opts') !== false && $this->value !== null) {
            return ['value' => $this->value];
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string)$s); }
}

/**
 * config-store: the stored conf_opts row is merged over defaultConfig(), the settings form
 * can switch toggles off, and a save stores only known keys.
 *
 * The save tests run against a real PostgreSQL when RELDYN_TEST_PG_DSN points at a
 * THROWAWAY database (never dwemer); each test uses its own schema and drops it.
 */
final class RelDynConfigStoreTest extends TestCase
{
    private array $savedGlobals = [];
    private ?string $schema = null;
    private $pg = null;
    private string $dsn = '';

    protected function setUp(): void
    {
        foreach (['db'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        if ($this->schema !== null) {
            pg_close($this->pg->link);
            $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
            pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
            pg_close($admin);
        }
    }

    private function useStoredRow(?string $json): void
    {
        $db = new RelDynConfigRowDb();
        $db->value = $json;
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    /** A real PostgreSQL connection in a private schema with core's conf_opts table. */
    private function usePostgres(): object
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_cfg' . getmypid() . '_' . bin2hex(random_bytes(3));
        $link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($link, "CREATE SCHEMA {$this->schema}");
        pg_query($link, "SET search_path TO {$this->schema}");
        // Same shape as data/database_default.sql: conf_opts(id text PRIMARY KEY, value text).
        pg_query($link, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        $this->pg = new class($link) {
            public $link;
            public function __construct($link) { $this->link = $link; }
            public function fetchOne($q, array $params = [])
            {
                $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
                return $res ? (pg_fetch_assoc($res) ?: []) : [];
            }
            public function fetchAll($q) { $r = pg_query($this->link, $q); $rows = []; while ($r && ($row = pg_fetch_assoc($r))) $rows[] = $row; return $rows; }
            public function execQuery($q) { return pg_query($this->link, $q); }
            public function escape($s) { return pg_escape_string($this->link, (string)$s); }
        };
        $GLOBALS['db'] = $this->pg;
        RelationshipDynamics::clearConfigCache();
        return $this->pg;
    }

    private function storedRow(): ?array
    {
        $row = pg_fetch_assoc(pg_query($this->pg->link, "SELECT value FROM conf_opts WHERE id = 'relationship_dynamics_config'"));
        return $row ? json_decode($row['value'], true) : null;
    }

    /** What the browser sends for the settings form: hidden "" then, if ticked, the checkbox's "on". */
    private function formPost(array $checked, array $fields = []): array
    {
        $post = ['save_reldyn' => '1'];
        foreach (RelationshipDynamics::CONFIG_FORM_TOGGLES as $key) {
            $post[$key] = in_array($key, $checked, true) ? 'on' : '';
        }
        return array_merge($post, $fields);
    }

    // ------------------------------------------------------------------
    // getConfig merge
    // ------------------------------------------------------------------

    public function testStoredRowIsMergedOverDefaults(): void
    {
        // A row saved by an older settings page: no cascade/attraction weight keys.
        $this->useStoredRow(json_encode(['enabled' => true, 'log_enabled' => true, 'base_passion_gain' => 3.5]));
        $cfg = RelationshipDynamics::getConfig();

        $this->assertTrue($cfg['log_enabled'], 'stored value wins');
        $this->assertSame(3.5, $cfg['base_passion_gain']);
        $defaults = RelationshipDynamics::defaultConfig();
        foreach (['cascade_threshold', 'cascade_decay', 'attraction_beauty_weight', 'dimension_max_context_lines',
                  'dimension_engine_enabled', 'passion_enabled', 'hoover_enabled'] as $key) {
            $this->assertArrayHasKey($key, $cfg, "{$key} missing from the stored row takes the current default");
            $this->assertSame($defaults[$key], $cfg[$key]);
        }
    }

    public function testNoStoredRowGivesDefaults(): void
    {
        $this->useStoredRow(null);
        $this->assertSame(RelationshipDynamics::defaultConfig(), RelationshipDynamics::getConfig());
    }

    public function testUnreadableStoredRowGivesDefaults(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'rdcfg');
        $prev = ini_set('error_log', $log);
        try {
            $this->useStoredRow('{not json');
            $this->assertSame(RelationshipDynamics::defaultConfig(), RelationshipDynamics::getConfig());
            $this->useStoredRow('"a string"');
            $this->assertSame(RelationshipDynamics::defaultConfig(), RelationshipDynamics::getConfig());
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        $this->assertSame(2, substr_count((string)file_get_contents($log), 'ERROR loadStoredConfig'),
            'an unreadable row is reported even with log_enabled off');
        unlink($log);
    }

    public function testEveryKeyTheSettingsFormWritesHasADefault(): void
    {
        $defaults = RelationshipDynamics::defaultConfig();
        foreach (array_merge(RelationshipDynamics::CONFIG_FORM_TOGGLES, array_keys(RelationshipDynamics::CONFIG_FORM_NUMBERS),
                             ['diary_reflection_mode']) as $key) {
            $this->assertArrayHasKey($key, $defaults, "{$key} is on the settings page but not a known key");
        }
    }

    public function testSettingsPageFieldsMatchWhatTheSaveParses(): void
    {
        $html = file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/settings.php');
        preg_match_all('/<input type="hidden" name="([a-z_]+)" value="">\s*<input type="checkbox" name="\1"/', $html, $m);
        $this->assertEqualsCanonicalizing(RelationshipDynamics::CONFIG_FORM_TOGGLES, $m[1],
            'every checkbox has its hidden "" twin and is parsed as a toggle');
        preg_match_all('/<input type="number"[^>]*name="([a-z_]+)"/', $html, $n);
        $this->assertEqualsCanonicalizing(array_keys(RelationshipDynamics::CONFIG_FORM_NUMBERS), $n[1]);
    }

    // ------------------------------------------------------------------
    // Settings form parsing
    // ------------------------------------------------------------------

    public function testUncheckedTogglesSaveAsOff(): void
    {
        $cfg = RelationshipDynamics::configFromForm($this->formPost(['enabled', 'dimension_engine_enabled']), []);

        $this->assertTrue($cfg['enabled']);
        $this->assertTrue($cfg['dimension_engine_enabled']);
        $this->assertFalse($cfg['log_enabled'], 'hidden input alone means the box was unticked');
        $this->assertFalse($cfg['hoover_enabled']);
        $this->assertFalse($cfg['dimension_context_enabled']);
    }

    public function testNumbersAreClampedAndUnknownKeysDropped(): void
    {
        $cfg = RelationshipDynamics::configFromForm($this->formPost([], [
            'base_passion_gain' => '99',
            'reunion_min_affection' => '-500',
            'attraction_eval_interval' => '0',
            'diary_reflection_mode' => 'nonsense',
            'evil_key' => 'x',
        ]), ['stale_removed_key' => 1, 'cascade_decay' => 0.5]);

        $this->assertSame(10.0, $cfg['base_passion_gain']);
        $this->assertSame(-100, $cfg['reunion_min_affection'], 'core affinity floor is -100');
        $this->assertSame(1, $cfg['attraction_eval_interval']);
        $this->assertSame('baseline', $cfg['diary_reflection_mode']);
        $this->assertArrayNotHasKey('evil_key', $cfg);
        $this->assertArrayNotHasKey('stale_removed_key', $cfg, 'an unknown key already in the row is dropped');
        $this->assertSame(0.5, $cfg['cascade_decay'], 'a known key the form does not show keeps its stored value');
        $this->assertArrayNotHasKey('cascade_threshold', $cfg, 'a key never stored stays absent, so it follows the default');
        $this->assertSame([], array_diff_key($cfg, RelationshipDynamics::defaultConfig()));
    }

    // ------------------------------------------------------------------
    // Save round trip on PostgreSQL
    // ------------------------------------------------------------------

    public function testSaveTurnsATogglesOffAndStoresOnlyKnownKeys(): void
    {
        $this->usePostgres();
        pg_query($this->pg->link, "INSERT INTO conf_opts (id, value) VALUES ('relationship_dynamics_config',
            '{\"enabled\": true, \"hoover_enabled\": true, \"april_leftover\": 7, \"cascade_decay\": 0.5}')");

        $this->assertTrue(RelationshipDynamics::saveConfigFromForm($this->formPost(['enabled'], ['evil_key' => '1'])));

        $stored = $this->storedRow();
        $this->assertFalse($stored['hoover_enabled'], 'toggle switched off is stored off');
        $this->assertTrue($stored['enabled']);
        $this->assertArrayNotHasKey('april_leftover', $stored);
        $this->assertArrayNotHasKey('evil_key', $stored);
        $this->assertSame([], array_diff_key($stored, RelationshipDynamics::defaultConfig()));

        $cfg = RelationshipDynamics::getConfig();
        $this->assertFalse($cfg['hoover_enabled']);
        $this->assertSame(0.5, $cfg['cascade_decay']);
        $this->assertSame(RelationshipDynamics::defaultConfig()['cascade_threshold'], $cfg['cascade_threshold']);
    }

    public function testFirstSaveInsertsTheRow(): void
    {
        $this->usePostgres();
        $this->assertTrue(RelationshipDynamics::saveConfigFromForm($this->formPost(['enabled', 'log_enabled'], ['passion_max' => '150'])));
        $stored = $this->storedRow();
        $this->assertTrue($stored['log_enabled']);
        $this->assertSame(150.0, (float)$stored['passion_max']);
        $this->assertSame(150.0, (float)RelationshipDynamics::getConfig()['passion_max']);
    }
}
