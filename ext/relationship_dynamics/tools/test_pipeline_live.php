<?php
/**
 * RelDyn tools: test_pipeline_live, the seam check of the real pipeline (the 2026-04-01 script of that
 * name did not survive the 3.4.1 reinstall). A passing unit suite says nothing about the seams where
 * the pieces meet in a running server, so this reads the live server and says which seam is open.
 * READ ONLY: SELECTs through the read-only adapter (readonly_pg.php), file reads, and TCP connects to
 * the connectors' hosts (nothing is sent: no LLM call, no cost).
 *
 *   RELDYN_LIVE_PG_DSN="..." php ext/relationship_dynamics/tools/test_pipeline_live.php [--json] [--log-dir=DIR]
 *
 * The checks (PASS / WARN / FAIL / SKIP each):
 *   player_name       PLAYER_NAME as the runtime resolves it (core_player.player_name, else conf_opts PLAYER_NAME)
 *                     is a real name, and is the name the plugin's own lines (eventlog infoplayer / playerinfo) carry
 *                     (core's _relIsPlayer compares against PLAYER_NAME: a mismatch files the player as an NPC)
 *   rel_is_player     core's _relIsPlayer (ext/relationship_system/postrequest.php) is defined and reads PLAYER_NAME;
 *                     RelDyn's own player test (RelDynEval::isPlayerAddressed) answers for the resolved name
 *   affinity_mirror   per NPC: core's affinity toward the player (core_npc_master.extended_data relationships.Player.aff)
 *                     against RelDyn's read-only mirror (_aff_mirror_x). Drift is expected until her next request; large
 *                     drift is listed
 *   context_tier      per NPC: the context tier now (getContextTier) and its high-water mark; the mark must have
 *                     ratcheted to what the affinity supports (tier 2 is permanent once reached)
 *   php_fatals        PHP fatal / parse / uncaught errors in the tails of the server's logs (RelDyn's are a FAIL)
 *   eval_worker       reldyn_eval_queue: jobs waiting with no drainer, dead-lettered jobs, the worker log's last run
 *   connector         each core_llm_connector row's host answers a TCP connect (nothing is sent)
 *   context_log       log/context_sent_to_llm.log exists, is recent, and its newest entry reads
 *   mark_diary        RelationshipDynamics::markDiaryCompleted takes the one argument every call site gives it
 *
 * Exit code 1 when any check FAILs. Writes nothing.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/replay_prompt.php';

final class RelDynPipelineCheck
{
    const PLACEHOLDER_NAMES = ['player', 'unknown', ''];
    const START_NAMES = ['prisoner'];

    /** @var object SELECT-capable adapter (fetchOne / fetchAll) */
    private $db;
    private string $enginePath;
    private string $logDir;
    private array $opts;

    /**
     * $opts: 'tcp' (callable (host, port, timeout): bool), 'now' (unix time), 'stale_log_hours' (default 24),
     * 'mirror_warn' (core points of drift that is listed, default 0.5), 'tail_bytes' (default 524288), 'log_dir'.
     */
    public function __construct($db, string $enginePath, array $opts = [])
    {
        $this->db = $db;
        $this->enginePath = rtrim($enginePath, '/\\') . '/';
        $this->opts = $opts;
        $this->logDir = rtrim((string) ($opts['log_dir'] ?? ($this->enginePath . 'log')), '/\\') . '/';
    }

    private static function res(string $id, string $status, string $detail, array $items = []): array
    {
        return ['id' => $id, 'status' => $status, 'detail' => $detail, 'items' => $items];
    }

    private function now(): int
    {
        return intval($this->opts['now'] ?? time());
    }

    /** @return list<array{id: string, status: string, detail: string, items: array}> */
    public function run(): array
    {
        $out = [];
        foreach (['playerName', 'relIsPlayer', 'affinityAndTier', 'phpFatals', 'evalWorker', 'connectors', 'contextLog', 'markDiary'] as $step) {
            try {
                foreach ((array) $this->$step() as $r) $out[] = $r;
            } catch (Throwable $e) {
                $out[] = self::res($step, 'FAIL', 'the check itself failed: ' . $e->getMessage());
            }
        }
        return $out;
    }

    public static function exitCode(array $results): int
    {
        foreach ($results as $r) if ($r['status'] === 'FAIL') return 1;
        return 0;
    }

    public static function format(array $results): string
    {
        $out = '';
        $count = ['PASS' => 0, 'WARN' => 0, 'FAIL' => 0, 'SKIP' => 0];
        foreach ($results as $r) {
            $count[$r['status']]++;
            $out .= sprintf("%-4s %-16s %s\n", $r['status'], $r['id'], $r['detail']);
            foreach ($r['items'] as $item) $out .= '       ' . $item . "\n";
        }
        return $out . "\n{$count['PASS']} pass, {$count['WARN']} warn, {$count['FAIL']} fail, {$count['SKIP']} skipped\n";
    }

    // ------------------------------------------------------------------ player name

    /** PLAYER_NAME the way chimLoadPlayerNameIntoGlobals resolves it: core_player.player_name, else conf_opts. */
    public function resolvePlayerName(): ?string
    {
        $row = $this->db->fetchOne("SELECT value FROM core_player WHERE id = 'player_name'");
        $name = is_array($row) ? trim((string) ($row['value'] ?? '')) : '';
        if ($name !== '') return $name;
        $row = $this->db->fetchOne("SELECT value FROM conf_opts WHERE id = 'PLAYER_NAME'");
        $name = is_array($row) ? trim((string) ($row['value'] ?? '')) : '';
        return $name !== '' ? $name : null;
    }

    /** The name the plugin's newest infoplayer / playerinfo line carries (name:"X"), or null. */
    private function pluginPlayerName(): ?string
    {
        $row = $this->db->fetchOne("SELECT data FROM eventlog WHERE type IN ('infoplayer', 'playerinfo') ORDER BY rowid DESC LIMIT 1");
        if (is_array($row) && preg_match('/name:"([^"]+)"/', (string) ($row['data'] ?? ''), $m)) return trim($m[1]);
        return null;
    }

    private function playerName(): array
    {
        $name = $this->resolvePlayerName();
        if ($name === null || in_array(strtolower($name), self::PLACEHOLDER_NAMES, true)) {
            return [self::res('player_name', 'FAIL', 'PLAYER_NAME does not resolve to a name (core_player.player_name and conf_opts PLAYER_NAME are empty): every NPC check that compares against it files the player as an NPC')];
        }
        $plugin = $this->pluginPlayerName();
        if ($plugin !== null && strcasecmp($plugin, $name) !== 0) {
            return [self::res('player_name', 'FAIL', "PLAYER_NAME is '{$name}' but the plugin's own lines say '{$plugin}': core's _relIsPlayer will not recognise the player")];
        }
        if (in_array(strtolower($name), self::START_NAMES, true)) {
            return [self::res('player_name', 'WARN', "PLAYER_NAME is '{$name}', the name the game starts a character with" . ($plugin === null ? ' (no plugin line to compare)' : ' (the plugin agrees)'))];
        }
        return [self::res('player_name', 'PASS', "PLAYER_NAME is '{$name}'" . ($plugin === null ? ' (no plugin line to compare it with yet)' : ', the name the plugin sends'))];
    }

    private function relIsPlayer(): array
    {
        $file = $this->enginePath . 'ext/relationship_system/postrequest.php';
        if (!is_file($file)) return [self::res('rel_is_player', 'SKIP', 'ext/relationship_system/postrequest.php is not installed: core\'s relationship system is not in use')];
        $src = (string) file_get_contents($file);
        if (!preg_match('/function\s+_relIsPlayer\s*\([^)]*\)\s*\{(.*?)\n\}/s', $src, $m)) {
            return [self::res('rel_is_player', 'FAIL', '_relIsPlayer is not defined in ext/relationship_system/postrequest.php')];
        }
        if (strpos($m[1], 'PLAYER_NAME') === false) {
            return [self::res('rel_is_player', 'FAIL', '_relIsPlayer no longer compares against PLAYER_NAME: it will file the player as an NPC')];
        }
        $name = $this->resolvePlayerName();
        if ($name === null) return [self::res('rel_is_player', 'SKIP', '_relIsPlayer reads PLAYER_NAME, which does not resolve (see player_name)')];
        if (class_exists('RelDynEval') && !RelDynEval::isPlayerAddressed($name, $name)) {
            return [self::res('rel_is_player', 'FAIL', "RelDyn's own player test does not know '{$name}' as the player")];
        }
        return [self::res('rel_is_player', 'PASS', "_relIsPlayer reads PLAYER_NAME ('{$name}') and RelDyn's player test agrees")];
    }

    // ------------------------------------------------------------------ affinity mirror, context tier

    private function affinityAndTier(): array
    {
        $rows = $this->db->fetchAll("SELECT npc_name, extended_data, plugin_extended_data FROM core_npc_master WHERE plugin_extended_data ? 'reldyn' ORDER BY npc_name LIMIT 1000");
        if (!$rows) {
            $why = 'no NPC has RelDyn state yet (nothing has run through the hooks)';
            return [self::res('affinity_mirror', 'SKIP', $why), self::res('context_tier', 'SKIP', $why)];
        }
        $warn = floatval($this->opts['mirror_warn'] ?? 0.5);
        $drift = [];
        $tierBad = [];
        $table = [];
        $checked = 0;
        $noMirror = [];
        foreach ($rows as $r) {
            $dyn = (json_decode((string) $r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? null);
            if (!is_array($dyn) || !$dyn) continue;
            $name = (string) $r['npc_name'];
            $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode((string) ($r['extended_data'] ?? ''), true));
            $core = is_array($rel) && is_numeric($rel['aff'] ?? null) ? floatval($rel['aff']) : null;
            if (!is_numeric($dyn['_aff_mirror_x'] ?? null)) {
                $noMirror[] = $name;
                continue;
            }
            $checked++;
            $mirror = RelationshipDynamics::getCoreAffinity($dyn);
            $hwm = intval($dyn['context_tier_hwm'] ?? 0);
            $tier = RelationshipDynamics::getContextTier($dyn);
            $supports = RelationshipDynamics::getAffinityContextTier($dyn);
            $line = sprintf('%-28s core %7s  mirror %7.2f  tier %d (supports %d, high-water %d)', $name,
                $core === null ? 'none' : sprintf('%.2f', $core), $mirror, $tier, $supports, $hwm);
            $table[] = $line;
            // a mirror drifting from core is stale until her next request; one with no core entry is a mirror of nothing
            if ($core !== null && abs($core - $mirror) > $warn) $drift[] = sprintf('%s: core %.2f, mirror %.2f (%+.2f)', $name, $core, $mirror, $mirror - $core);
            elseif ($core === null && abs($mirror) > 1e-6) $drift[] = "{$name}: mirror {$mirror} with no core Player entry";
            if ($hwm < min($supports, 2)) $tierBad[] = "{$name}: high-water {$hwm} below what her affinity supports ({$supports}): updateContextTierHWM has not run since it rose";
        }
        $results = [];
        if ($checked === 0) {
            $results[] = self::res('affinity_mirror', 'WARN', 'RelDyn state exists but no NPC has an affinity mirror (_aff_mirror_x): prerequest has not mirrored core in', $noMirror ? array_slice($noMirror, 0, 10) : []);
            $results[] = self::res('context_tier', 'SKIP', 'no affinity mirror to read a tier from');
            return $results;
        }
        $results[] = $drift
            ? self::res('affinity_mirror', 'WARN', count($drift) . " of {$checked} NPCs drift more than {$warn} core points (stale until their next request)", array_slice($drift, 0, 12))
            : self::res('affinity_mirror', 'PASS', "{$checked} NPCs: RelDyn's mirror agrees with core's affinity");
        $results[] = $tierBad
            ? self::res('context_tier', 'WARN', count($tierBad) . ' high-water marks have not ratcheted', array_slice($tierBad, 0, 12))
            : self::res('context_tier', 'PASS', "{$checked} NPCs: tiers and high-water marks agree with their affinity", array_slice($table, 0, 40));
        return $results;
    }

    // ------------------------------------------------------------------ logs

    private function tail(string $file, int $bytes): ?string
    {
        if (!is_file($file) || !is_readable($file)) return null;
        $size = filesize($file);
        $h = fopen($file, 'rb');
        if (!$h) return null;
        if ($size > $bytes) fseek($h, $size - $bytes);
        $t = (string) stream_get_contents($h);
        fclose($h);
        return $t;
    }

    private function phpFatals(): array
    {
        $bytes = intval($this->opts['tail_bytes'] ?? 524288);
        $files = ['apache_error.log', 'chim.log', 'reldyn_eval_worker.log', 'relationship_worker.log', 'service.log'];
        $reldyn = [];
        $other = [];
        $read = 0;
        foreach ($files as $f) {
            $t = $this->tail($this->logDir . $f, $bytes);
            if ($t === null) continue;
            $read++;
            foreach (preg_split('/\r?\n/', $t) as $line) {
                if (!preg_match('/PHP (?:Fatal error|Parse error)|Uncaught (?:Error|Exception|TypeError|ArgumentCountError|[A-Za-z\\\\]+Exception)|Allowed memory size/', $line)) continue;
                $short = $f . ': ' . trim(substr($line, 0, 300));
                if (stripos($line, 'relationship_dynamics') !== false || stripos($line, 'RelDyn') !== false) $reldyn[] = $short; else $other[] = $short;
            }
        }
        if ($read === 0) return [self::res('php_fatals', 'SKIP', "no server log under {$this->logDir} to read")];
        if ($reldyn) return [self::res('php_fatals', 'FAIL', count($reldyn) . ' PHP fatal(s) in RelDyn code in the log tails', array_slice($reldyn, -5))];
        if ($other) return [self::res('php_fatals', 'WARN', count($other) . ' PHP fatal(s) outside RelDyn in the log tails', array_slice($other, -5))];
        return [self::res('php_fatals', 'PASS', "no PHP fatal in the tails of {$read} log file(s)")];
    }

    private function evalWorker(): array
    {
        $present = $this->db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', ['reldyn_eval_queue']);
        if (!in_array($present['present'] ?? null, ['t', true], true)) {
            return [self::res('eval_worker', 'SKIP', 'reldyn_eval_queue does not exist yet (no eval job was ever queued)')];
        }
        $by = [];
        foreach ($this->db->fetchAll('SELECT status, count(*) AS n FROM reldyn_eval_queue GROUP BY status') as $r) $by[(string) $r['status']] = intval($r['n']);
        $locked = intval(($this->db->fetchOne('SELECT count(*) AS n FROM pg_locks WHERE locktype = $1 AND classid = $2 AND objid = $3', ['advisory', 1380218437, 1]))['n'] ?? 0) > 0;
        $items = [];
        foreach ($this->db->fetchAll("SELECT npc_name, attempts, last_error FROM reldyn_eval_queue WHERE status = 'dead' ORDER BY id DESC LIMIT 3") as $r) {
            $items[] = "dead: {$r['npc_name']} after {$r['attempts']} attempts: " . substr(trim((string) $r['last_error']), 0, 160);
        }
        $logFile = $this->logDir . 'reldyn_eval_worker.log';
        $last = null;
        if (($t = $this->tail($logFile, 65536)) !== null && preg_match_all('/\[RelDyn-EVAL\] worker done[^\n]*/', $t, $m)) $last = end($m[0]);
        if ($last !== null) $items[] = 'last worker run: ' . substr($last, 0, 160) . ' (' . gmdate('Y-m-d H:i', filemtime($logFile)) . ' UTC)';
        $pending = $by['pending'] ?? 0;
        $dead = $by['dead'] ?? 0;
        $summary = 'queue: ' . ($by ? implode(', ', array_map(fn($s, $n) => "{$n} {$s}", array_keys($by), $by)) : 'empty') . '; drainer ' . ($locked ? 'running' : 'idle');
        if ($dead > 0) return [self::res('eval_worker', 'WARN', "{$dead} eval job(s) dead-lettered; {$summary}", $items)];
        if ($pending > 0 && !$locked) return [self::res('eval_worker', 'WARN', "{$pending} job(s) waiting and no drainer running (a worker starts with the next postrequest); {$summary}", $items)];
        return [self::res('eval_worker', 'PASS', $summary, $items)];
    }

    private function connectors(): array
    {
        $rows = $this->db->fetchAll('SELECT id, label, driver, model, url FROM core_llm_connector ORDER BY id');
        if (!$rows) return [self::res('connector', 'SKIP', 'no core_llm_connector row')];
        $tcp = $this->opts['tcp'] ?? [self::class, 'tcpReachable'];
        $items = [];
        $bad = 0;
        foreach ($rows as $r) {
            $url = trim((string) ($r['url'] ?? ''));
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host === '') { $items[] = "#{$r['id']} {$r['label']} ({$r['driver']}): no url"; $bad++; continue; }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $port = intval(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
            $ok = (bool) $tcp($host, $port, 3);
            if (!$ok) $bad++;
            $items[] = "#{$r['id']} {$r['label']} ({$r['driver']} / {$r['model']}) {$host}:{$port} " . ($ok ? 'answers' : 'does not answer');
        }
        return [$bad ? self::res('connector', 'FAIL', "{$bad} of " . count($rows) . ' connectors do not answer a TCP connect', $items)
            : self::res('connector', 'PASS', count($rows) . ' connector host(s) answer a TCP connect (nothing was sent)', $items)];
    }

    public static function tcpReachable(string $host, int $port, int $timeout): bool
    {
        $s = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$s) return false;
        fclose($s);
        return true;
    }

    private function contextLog(): array
    {
        $file = $this->logDir . 'context_sent_to_llm.log';
        if (!is_file($file)) return [self::res('context_log', 'FAIL', 'log/context_sent_to_llm.log does not exist: no connector has written a request yet (or log permissions)')];
        $age = $this->now() - filemtime($file);
        $max = floatval($this->opts['stale_log_hours'] ?? 24) * 3600;
        if (!is_writable($file)) return [self::res('context_log', 'WARN', 'log/context_sent_to_llm.log is not writable by this user (the web server user must write it)')];
        // the newest entry reads: the tail from its last header on
        $t = $this->tail($file, 1048576);
        $caps = $t === null ? [] : array_values(array_filter(RelDynReplay::parseLog($t), fn($c) => $c['kind'] === 'request'));
        $last = $caps ? end($caps) : null;
        $when = gmdate('Y-m-d H:i', filemtime($file)) . ' UTC';
        if ($last === null || !is_array($last['payload'])) {
            return [self::res('context_log', 'WARN', "log exists ({$when}) but no entry in its tail reads as a request: the capture format may have changed")];
        }
        $payload = $last['payload'];
        if (!isset($payload['model'], $payload['messages'])) return [self::res('context_log', 'WARN', "newest entry has no model / messages ({$when})")];
        $detail = "newest request {$last['ts']}: " . $payload['model'] . ', ' . count($payload['messages']) . ' messages, speaker ' . (RelDynReplay::speaker($payload) ?: 'unknown');
        if ($age > $max) return [self::res('context_log', 'WARN', $detail . '; but the file is ' . round($age / 3600, 1) . ' hours old')];
        return [self::res('context_log', 'PASS', $detail)];
    }

    // ------------------------------------------------------------------ the diary mark

    /** markDiaryCompleted(array &$dynamics): every call site passes that one argument (the April bug passed two). */
    private function markDiary(): array
    {
        $m = new ReflectionMethod('RelationshipDynamics', 'markDiaryCompleted');
        if ($m->getNumberOfRequiredParameters() !== 1) {
            return [self::res('mark_diary', 'FAIL', 'markDiaryCompleted takes ' . $m->getNumberOfRequiredParameters() . ' required argument(s), the call sites give one')];
        }
        $bad = [];
        $calls = 0;
        $dir = $this->enginePath . 'ext/relationship_dynamics/';
        foreach (glob($dir . '*.php') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            if (!preg_match_all('/markDiaryCompleted\(([^;]*?)\)\s*;/', $src, $mm, PREG_SET_ORDER)) continue;
            foreach ($mm as $call) {
                if (preg_match('/function\s+markDiaryCompleted/', $call[0])) continue;
                $calls++;
                $depth = 0;
                $args = $call[1] === '' ? 0 : 1;
                foreach (str_split($call[1]) as $ch) {
                    if ($ch === '(' || $ch === '[') $depth++;
                    elseif ($ch === ')' || $ch === ']') $depth--;
                    elseif ($ch === ',' && $depth === 0) $args++;
                }
                if ($args !== 1) $bad[] = basename($f) . ': markDiaryCompleted(' . trim($call[1]) . ')';
            }
        }
        if ($bad) return [self::res('mark_diary', 'FAIL', 'a call site passes the wrong number of arguments', $bad)];
        return [self::res('mark_diary', 'PASS', "markDiaryCompleted(array &\$dynamics): {$calls} call site(s), one argument each")];
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $enginePath = realpath(__DIR__ . '/../../../') . '/';
    require_once $enginePath . 'lib/logger.php';
    require_once __DIR__ . '/../relationship_dynamics.php';
    require_once __DIR__ . '/../eval_producer.php';
    require_once __DIR__ . '/readonly_pg.php';
    $opts = getopt('', ['json', 'log-dir:']);
    try {
        $db = new RelDynReadOnlyPg(getenv('RELDYN_LIVE_PG_DSN') ?: 'dbname=dwemer');
    } catch (Throwable $e) {
        fwrite(STDERR, 'cannot read the live database: ' . $e->getMessage() . "\n");
        exit(2);
    }
    $GLOBALS['db'] = $db;
    $check = new RelDynPipelineCheck($db, $enginePath, isset($opts['log-dir']) ? ['log_dir' => $opts['log-dir']] : []);
    $results = $check->run();
    echo isset($opts['json']) ? json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n" : RelDynPipelineCheck::format($results);
    exit(RelDynPipelineCheck::exitCode($results));
}
