<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** $db whose every query fails the way CHIM's sql class does on a SQL error (fetchAll throws). */
final class RelDynFailingDb
{
    public function fetchOne($q, array $params = []) { throw new RuntimeException('simulated fetchOne failure'); }
    public function fetchAll($q, $log = false) { throw new RuntimeException('simulated fetchAll failure'); }
    public function execQuery($q) { throw new RuntimeException('simulated execQuery failure'); }
    public function escape($s) { return str_replace("'", "''", (string)$s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * code-health-queries: caught errors are logged (always, not only with the debug log on),
 * and eventlog lookups match what they mean: NPC names are LIKE-escaped, consumable words
 * are whole words.
 *
 * The eventlog tests run against a real PostgreSQL when RELDYN_TEST_PG_DSN points at a
 * THROWAWAY database (never dwemer); each test uses its own schema and drops it.
 */
final class RelDynCodeHealthTest extends TestCase
{
    private array $savedGlobals = [];
    private ?string $schema = null;
    private string $dsn = '';
    private $link = null;
    private ?string $logFile = null;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'HERIKA_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['db'], $GLOBALS['HERIKA_NAME']);
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdlog');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string)$this->prevLog);
        @unlink($this->logFile);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        if ($this->schema !== null) {
            pg_close($this->link);
            $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
            pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
            pg_close($admin);
        }
    }

    private function log(): string
    {
        return (string)file_get_contents($this->logFile);
    }

    // ------------------------------------------------------------------
    // Caught errors are logged
    // ------------------------------------------------------------------

    /** Every catch block in the RelDyn PHP (not the debug scripts) logs what it caught. */
    public function testEveryCatchBlockLogs(): void
    {
        $silent = [];
        foreach (glob(__DIR__ . '/../../ext/relationship_dynamics/*.php') as $file) {
            $toks = token_get_all(file_get_contents($file));
            $n = count($toks);
            for ($i = 0; $i < $n; $i++) {
                if (!is_array($toks[$i]) || $toks[$i][0] !== T_CATCH) continue;
                $line = $toks[$i][2];
                $j = $i;
                while ($j < $n && $toks[$j] !== '{') $j++;
                $depth = 0;
                $body = '';
                for ($k = $j; $k < $n; $k++) {
                    $t = $toks[$k];
                    if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
                    if ($t === '}' && --$depth === 0) break;
                    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                    $body .= is_array($t) ? $t[1] : $t;
                }
                if (!preg_match('/\b(error_log|logError|Logger::(error|warn))\s*\(|\bthrow\b/', $body)) {
                    $silent[] = basename($file) . ':' . $line;
                }
            }
        }
        $this->assertSame([], $silent, 'catch blocks that swallow the error without logging it');
    }

    public function testCaughtErrorsAreLoggedWithDebugLogOff(): void
    {
        $this->assertFalse(RelationshipDynamics::defaultConfig()['log_enabled']);
        $GLOBALS['db'] = new RelDynFailingDb();
        $GLOBALS['gameRequest'] = ['inputtext', '', '5000000000', ''];

        $this->assertNull(RelationshipDynamics::getCombatContext('Lydia'));
        $this->assertNull(RelationshipDynamics::getRecentCombatSummary('Lydia'));
        $this->assertSame([], RelationshipDynamics::detectItemEvents(['inputtext', '', '5000000000', ''], 'Lydia', 'Kaida'));

        $log = $this->log();
        $this->assertStringContainsString('[RelDyn] ERROR getCombatContext kill count: RuntimeException: simulated fetchAll failure', $log);
        $this->assertStringContainsString('[RelDyn] ERROR getRecentCombatSummary', $log);
        $this->assertStringContainsString('[RelDyn] ERROR detectItemEvents gift lookup', $log);
        $this->assertStringContainsString('[RelDyn] ERROR detectItemEvents consume lookup', $log);
    }

    // ------------------------------------------------------------------
    // Eventlog pattern matching on PostgreSQL
    // ------------------------------------------------------------------

    private const NOW = 5000000000; // gamets

    private function usePostgres(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_ch' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($this->link, "CREATE SCHEMA {$this->schema}");
        pg_query($this->link, "SET search_path TO {$this->schema}");
        // Same columns as data/database_default.sql eventlog (the ones RelDyn reads).
        pg_query($this->link, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL DEFAULT 0, ts bigint, rowid bigserial NOT NULL, people text, location text)");
        pg_query($this->link, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        $GLOBALS['db'] = new class($this->link) {
            public $link;
            public function __construct($link) { $this->link = $link; }
            public function fetchOne($q, array $params = [])
            {
                $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
                if (!$res) throw new RuntimeException(pg_last_error($this->link));
                return pg_fetch_assoc($res) ?: [];
            }
            public function fetchAll($q, $log = false)
            {
                // Like CHIM's sql::fetchAll: a failed query throws.
                $res = pg_query($this->link, $q);
                if (!$res) throw new RuntimeException(pg_last_error($this->link));
                $rows = [];
                while ($row = pg_fetch_assoc($res)) $rows[] = $row;
                return $rows;
            }
            public function execQuery($q) { return pg_query($this->link, $q); }
            public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string)$s); }
            public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string)$s); }
        };
        $GLOBALS['gameRequest'] = ['inputtext', '', (string)self::NOW, ''];
    }

    private function event(string $type, string $data, string $people = ''): void
    {
        pg_query_params($this->link, 'INSERT INTO eventlog (type, data, gamets, ts, people) VALUES ($1, $2, $3, $4, $5)',
            [$type, $data, self::NOW - 1000, time(), $people]);
    }

    public function testKillCountTreatsNameWildcardsLiterally(): void
    {
        $this->usePostgres();
        $this->event('death', 'J_n has defeated a bandit', '|J_n|Kaida|');
        $this->event('death', 'Jan has defeated a bandit', '|Jan|Kaida|');
        $this->event('death', 'Jon has defeated a bandit', '|Jon|Kaida|');
        $this->event('death', 'Ma% has defeated a draugr', '|Ma%|Kaida|');
        $this->event('death', 'Maven has defeated a skeever', '|Maven|Kaida|');

        $this->assertSame(1, RelationshipDynamics::getCombatContext('J_n')['recent_kills'], '_ is not a wildcard');
        $this->assertSame(1, RelationshipDynamics::getCombatContext('Ma%')['recent_kills'], '% is not a wildcard');
        $this->assertSame(1, RelationshipDynamics::getCombatContext('Jan')['recent_kills']);
        $this->assertStringNotContainsString('ERROR', $this->log());
    }

    public function testGiftLookupTreatsNameWildcardsLiterally(): void
    {
        $this->usePostgres();
        $this->event('itemfound', 'Kaida gave 1 Sweetroll to Jan');
        $this->event('itemfound', 'Kaida gave 1 Iron Sword to J_n');

        $gifts = array_values(array_filter(
            RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'J_n', 'Kaida'),
            fn($e) => $e['action'] === 'gift'));
        $this->assertSame(['Iron Sword'], array_column($gifts, 'item'));
        $this->assertStringNotContainsString('ERROR', $this->log());
    }

    public function testConsumableWordsMatchWholeWordsOnly(): void
    {
        $this->usePostgres();
        $this->event('itemfound', 'Kaida looted a private chest');
        $this->event('itemfound', 'Kaida picked up a Pirate Sabre');
        $this->event('itemfound', 'Lydia ate Apple Pie');

        $items = array_column(array_filter(
            RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'Lydia', 'Kaida'),
            fn($e) => $e['action'] === 'consume'), 'item');
        $this->assertSame(['Apple Pie'], $items, "'private chest' and 'Pirate Sabre' are not eating");
        $this->assertStringNotContainsString('ERROR', $this->log());
    }

    public function testConsumableDrankAndPotionStillDetected(): void
    {
        $this->usePostgres();
        $this->event('itemfound', 'Lydia drank Honningbrew Mead');
        $this->event('itemfound', 'Kaida used Potion of Healing');
        $this->event('itemfound', 'Kaida used the lever');

        $items = array_column(array_filter(
            RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'Lydia', 'Kaida'),
            fn($e) => $e['action'] === 'consume'), 'item');
        sort($items);
        $this->assertSame(['Honningbrew Mead', 'Potion of Healing'], $items);
    }
}
