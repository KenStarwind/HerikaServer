<?php
/**
 * RelDyn personality traits, phase 2: build the committed trait-read seed
 * (ext/relationship_dynamics/data/trait_reads_seed.json) from the live CHIM database.
 *
 *   RELDYN_LIVE_PG_DSN="host=localhost dbname=dwemer user=..." \
 *   php ext/relationship_dynamics/tools/trait_read_seed.php [--keys=FILE] [--budget=110]
 *       [--max-calls=N] [--limit=N] [--only=key,key] [--dry-run] [--json-mode]
 *
 * - The live database is READ ONLY here: the session runs with default_transaction_read_only,
 *   and the adapter refuses anything but SELECT. It reads bio templates, RELLLM_CONNECTOR
 *   (general_settings) or the default profile's connector (core_profiles), and the connector
 *   row. Core's audit insert inside fast_request is skipped ($GLOBALS['db'] is null during the
 *   call). No API key or URL is printed, logged or written: the seed records the connector id
 *   and model name only.
 * - Idempotent and resumable: a template whose done read has the same src_hash and prompt
 *   version is skipped; a failed one is retried until 3 attempts. The seed is rewritten after
 *   every call (atomic rename).
 * - Hard cap: the seed's meta.llm_calls counts every call ever made for it; the tool stops at
 *   --budget (default 110) and at --max-calls for this invocation.
 * - The skip list (Ashe) is never selected, fetched or read.
 * - The seed holds quotes (<= 12 words each) and numbers only: no bio text.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$enginePath = realpath(__DIR__ . '/../../../') . '/';
$GLOBALS['ENGINE_PATH'] = $enginePath;
// core's JSON response helpers (loaded by fast_request) read these; no game session here
$GLOBALS['PLAYER_NAME'] = $GLOBALS['PLAYER_NAME'] ?? 'Player';
$GLOBALS['HERIKA_NAME'] = $GLOBALS['HERIKA_NAME'] ?? 'The Narrator';
require_once $enginePath . 'lib/logger.php';
require_once __DIR__ . '/../reldyn_trait_read.php';

/** SELECT-only adapter over a read-only pg session (the CHIM sql method names the connector code uses). */
final class RelDynReadOnlyPg
{
    private $link;

    public function __construct(string $dsn)
    {
        $this->link = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException('cannot connect to the live database (RELDYN_LIVE_PG_DSN)');
        pg_query($this->link, 'SET default_transaction_read_only = on');
        pg_query($this->link, "SET statement_timeout = '30s'");
    }

    private function guard(string $q): void
    {
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $q) || preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|GRANT)\b/i', preg_replace("/'[^']*'/", "''", $q))) {
            throw new RuntimeException('read-only adapter refused a non-SELECT statement');
        }
    }

    public function fetchOne($q, array $params = [])
    {
        $this->guard($q);
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('query failed: ' . pg_last_error($this->link));
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->guard($q);
        $res = pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('query failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function escape($s) { return pg_escape_string($this->link, (string) $s); }
    public function execQuery($q) { $this->guard($q); return pg_query($this->link, $q); }
    public function insert($t, $d) { throw new RuntimeException('read-only adapter: insert refused'); }
    public function insertReturningId($t, $d, $c = 'id') { throw new RuntimeException('read-only adapter: insert refused'); }
    public function updateRow($t, $d, $w) { throw new RuntimeException('read-only adapter: update refused'); }
}

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }

$opts = getopt('', ['keys:', 'budget:', 'max-calls:', 'limit:', 'only:', 'dry-run', 'json-mode', 'seed:']);
$keysFile = $opts['keys'] ?? (__DIR__ . '/../data/trait_read_npcs.txt');
$seedPath = $opts['seed'] ?? RelDynTraitRead::SEED_FILE;
$budget = max(0, intval($opts['budget'] ?? 110));
$maxCalls = isset($opts['max-calls']) ? max(0, intval($opts['max-calls'])) : $budget;
$limit = isset($opts['limit']) ? max(0, intval($opts['limit'])) : PHP_INT_MAX;
$dry = isset($opts['dry-run']);
$jsonMode = isset($opts['json-mode']);

// --- the selection (template keys; '#' comments) ---
$keys = [];
foreach (file($keysFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim(preg_replace('/#.*/', '', $line) ?? '');
    if ($line !== '') $keys[] = strtolower($line);
}
if (isset($opts['only'])) $keys = array_values(array_intersect($keys, array_map('strtolower', array_map('trim', explode(',', $opts['only'])))));
$cfg = RelDynTraitRead::defaultConfig();
$cfg['skip'] = ['ashe'];
$skipped = array_values(array_filter($keys, fn($k) => RelDynTraitRead::isSkipped($k, $cfg)));
$keys = array_values(array_unique(array_filter($keys, fn($k) => !RelDynTraitRead::isSkipped($k, $cfg))));
out(sprintf('selection: %d template keys (%d skip-listed, never read)', count($keys), count($skipped)));

// --- the seed so far ---
$seed = is_file($seedPath) ? (json_decode((string) file_get_contents($seedPath), true) ?: []) : [];
$seed += ['format' => 'reldyn_trait_reads_seed', 'version' => 1, 'prompt_v' => RelDynTraitRead::PROMPT_V,
          'note' => 'Bio trait reads (design D:\\docs\\reldyn-personality-traits-design.md 4.2). Quotes of <= 12 words and numbers only; no bio text. Ashe is skip-listed and never read.',
          'llm_calls' => 0, 'connector' => null, 'reads' => []];
$seed['selection'] = $keys;
$saveSeed = function () use (&$seed, $seedPath) {
    ksort($seed['reads']);
    $seed['updated'] = gmdate('c');
    $tmp = $seedPath . '.tmp';
    @mkdir(dirname($seedPath), 0777, true);
    file_put_contents($tmp, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    rename($tmp, $seedPath);
};

// --- live DB, read only ---
$dsn = getenv('RELDYN_LIVE_PG_DSN') ?: 'dbname=dwemer';
$db = new RelDynReadOnlyPg($dsn);
$GLOBALS['db'] = $db;

// --- connector: RELLLM_CONNECTOR, else the default profile's primary ---
$row = $db->fetchOne("SELECT value FROM general_settings WHERE id = 'RELLLM_CONNECTOR' LIMIT 1");
$connId = intval($row['value'] ?? 0);
$connFrom = 'RELLLM_CONNECTOR';
if ($connId <= 0) {
    $row = $db->fetchOne('SELECT llm_primary_id FROM core_profiles ORDER BY slot NULLS LAST, id LIMIT 1');
    $connId = intval($row['llm_primary_id'] ?? 0);
    $connFrom = 'default profile';
}
if ($connId <= 0) { out('no connector configured (RELLLM_CONNECTOR / default profile)'); exit(2); }
require_once $enginePath . 'lib/core/llm_connector.class.php';
$lc = new LLMConnector();
$conn = $lc->readOne($connId);
if (!is_array($conn) || empty($conn['driver'])) { out("connector {$connId} not found"); exit(2); }
$model = (string) ($conn['model'] ?? '');
out("connector: id {$connId} ({$connFrom}), model {$model}");   // never the URL or key
$seed['connector'] = ['id' => $connId, 'source' => $connFrom, 'model' => $model];

$llm = function (array $messages, array $params) use ($lc, $conn, $db, $jsonMode) {
    $driver = $lc->getConnector($conn);
    $lc->setOldGlobals($conn);
    if ($jsonMode) $params['response_format'] = ['type' => 'json_object'];
    $GLOBALS['db'] = null;   // core's audit_request insert is skipped: the live DB stays read-only
    try {
        return ['text' => $driver->fast_request($messages, $params, 'reldyn_traits_seed'), 'model' => (string) ($conn['model'] ?? '')];
    } finally {
        $GLOBALS['db'] = $db;
    }
};

$calls = 0;
$done = 0;
$failed = 0;
$fresh = 0;
foreach ($keys as $i => $key) {
    if ($done + $failed >= $limit) break;
    $tpl = $db->fetchOne('SELECT npc_name, ' . implode(', ', RelDynTraitRead::FIELDS) . ' FROM combined_bio_templates WHERE lower(npc_name) = lower($1) LIMIT 1', [$key]);
    if (!isset($tpl['npc_name'])) { out("  {$key}: no bio template, skipped"); continue; }
    $fields = RelDynTraitRead::fieldsOf($tpl);
    if (!RelDynTraitRead::hasText($fields)) { out("  {$key}: empty template, skipped"); continue; }
    $hash = RelDynTraitRead::srcHash($fields);
    $prev = $seed['reads'][$key] ?? null;
    if (is_array($prev) && ($prev['src_hash'] ?? '') === $hash && intval($prev['prompt_v'] ?? 0) === RelDynTraitRead::PROMPT_V) {
        if (($prev['status'] ?? '') === 'done') { $fresh++; continue; }
        if (intval($prev['attempts'] ?? 0) >= 3) { out("  {$key}: dead after 3 attempts, not retried"); continue; }
    } else {
        $prev = null;
    }
    if ($dry) {
        $m = RelDynTraitRead::buildMessages(RelDynTraitRead::displayName($key), $fields);
        out("  {$key}: would read (" . strlen($m[0]['content'] . $m[1]['content']) . ' chars)');
        if ($i === 0) out($m[1]['content']);
        continue;
    }
    if (intval($seed['llm_calls']) >= $budget) { out("budget of {$budget} LLM calls reached; stopping"); break; }
    if ($calls >= $maxCalls) { out("--max-calls {$maxCalls} reached; stopping"); break; }

    $calls++;
    $seed['llm_calls'] = intval($seed['llm_calls']) + 1;
    $t0 = microtime(true);
    try {
        $res = RelDynTraitRead::readOnce($key, $fields, $llm, $cfg);
    } catch (\Throwable $e) {
        $res = ['ok' => false, 'result' => null, 'error' => get_class($e) . ': ' . substr($e->getMessage(), 0, 160), 'model' => $model];
    }
    $attempts = intval($prev['attempts'] ?? 0) + 1;
    if ($res['ok']) {
        $seed['reads'][$key] = ['src_hash' => $hash, 'prompt_v' => RelDynTraitRead::PROMPT_V, 'status' => 'done', 'attempts' => $attempts,
                                'model' => $res['model'] ?: $model, 'result' => $res['result']];
        $done++;
        $zeroed = count(array_filter($res['result']['traits'], fn($t) => ($t['note'] ?? '') === 'quote_mismatch'));
        out(sprintf('  %s: done (%.1fs, %d quote(s) rejected)', $key, microtime(true) - $t0, $zeroed));
    } else {
        $seed['reads'][$key] = ['src_hash' => $hash, 'prompt_v' => RelDynTraitRead::PROMPT_V, 'status' => 'failed', 'attempts' => $attempts,
                                'model' => $res['model'] ?: $model, 'last_error' => substr((string) $res['error'], 0, 200)];
        $failed++;
        out("  {$key}: FAILED ({$res['error']})");
    }
    $saveSeed();
}
if (!$dry) $saveSeed();
out(sprintf('calls this run %d, total %d/%d; done %d, failed %d, already fresh %d', $calls, $seed['llm_calls'], $budget, $done, $failed, $fresh));
