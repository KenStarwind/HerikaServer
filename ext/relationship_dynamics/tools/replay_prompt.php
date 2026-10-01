<?php
/**
 * RelDyn tools: replay_prompt, the replay tool for CHIM 3.4.1 (integration-testing rule: real
 * captured prompts through the real configured connector; a stubbed LLM is a unit test, not a
 * replay). The 2026-04-18 tool did not survive the 3.4.1 reinstall; this one reads the 3.4.1
 * connectors' capture format.
 *
 * The capture (every 3.4.1 chat connector, connector/<driver>.php open()):
 *     file_put_contents(log/context_sent_to_llm[_fast].log, date(DATE_ATOM)."\n=\n".var_export($data, true)."\n=\n", FILE_APPEND)
 * so a log is a run of entries: an ISO-8601 line, a line holding "=", the request body exactly as
 * the connector built it (model, messages, tools, params) as PHP var_export text, a line "=".
 * The fast log's request-id markers ("Request id:N" in place of the body) are listed as markers.
 * The var_export text is parsed by a strict reader here, never eval'd (a log is data).
 *
 *   php ext/relationship_dynamics/tools/replay_prompt.php list [N] [--log=FILE] [--fast]
 *   php ext/relationship_dynamics/tools/replay_prompt.php show <i> [--system] [--tools] [--json] [--log=FILE]
 *   php ext/relationship_dynamics/tools/replay_prompt.php diff <a> <b> [--log=FILE]
 *   php ext/relationship_dynamics/tools/replay_prompt.php replay <i> [--model SLUG] [--patch FILE.php]
 *                      [--without=TAG,TAG] [--connector=ID] [--dry-run] [--out=FILE] [--timeout=SECONDS]
 *
 *   <i>, <a>, <b>   1-based capture number as 'list' prints it; negative counts from the newest (-1)
 *   list [N]        the newest N captures (default 20): time, model, messages, ~tokens, who speaks, last line
 *   show            the request: parameters, every message in order (--system: the system prompt only,
 *                   --tools: the tool names, --json: the whole payload as JSON)
 *   diff            the system prompt of two captures line by line, then the parameter and message changes
 *   replay          send capture <i> again. The request body is the captured one, changed only by
 *                   --model (the model slug), --without (the named <tag>...</tag> blocks cut out of every message:
 *                   an A/B of one RelDyn block) and --patch. It goes to the connector the server is
 *                   configured with (core's core_llm_connector row and its API badge, read through core's
 *                   own LLMConnector class; --connector=ID picks the row, default: the one whose model is
 *                   the capture's, else the only one). The reply is parsed for its JSON fields and printed.
 *                   --dry-run builds and prints everything and sends nothing.
 *
 * --patch FILE.php: a PHP file that returns a function (array $payload, array $meta): array, which gets
 * the request (after --model / --without) and returns the request to send, e.g.
 *     <?php return function (array $payload, array $meta): array {
 *         $payload['messages'][0]['content'] = str_replace('Cold:', 'Warm:', $payload['messages'][0]['content']);
 *         $payload['temperature'] = 0.2;
 *         return $payload;
 *     };
 * $meta: ['index', 'ts', 'model' (the capture's)]. The patch file runs with this process's rights
 * and is read from the command line: only pass files you wrote.
 *
 * Database use is READ ONLY (RELDYN_LIVE_PG_DSN, default dbname=dwemer; the SELECT-only adapter of
 * readonly_pg.php), and only for the connector row. The API key is never printed.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class RelDynReplay
{
    // =====================================================================
    // var_export reader (strict: arrays, strings, numbers, bool, NULL)
    // =====================================================================

    /** The value of a var_export() text. Throws on anything var_export does not write. */
    public static function parseExport(string $text)
    {
        $p = 0;
        $v = self::readValue($text, $p, 0);
        self::skipWs($text, $p);
        if ($p < strlen($text)) throw new RuntimeException("trailing text after the value at offset {$p}");
        return $v;
    }

    private static function skipWs(string $t, int &$p): void
    {
        $n = strlen($t);
        while ($p < $n && ($t[$p] === ' ' || $t[$p] === "\n" || $t[$p] === "\r" || $t[$p] === "\t")) $p++;
    }

    private static function readValue(string $t, int &$p, int $depth)
    {
        if ($depth > 64) throw new RuntimeException('nested too deep');
        self::skipWs($t, $p);
        if ($p >= strlen($t)) throw new RuntimeException('unexpected end');
        $c = $t[$p];
        if ($c === "'" || $c === '"') return self::readString($t, $p);
        if (substr($t, $p, 5) === 'array') {
            $p += 5;
            return self::readArrayBody($t, $p, $depth);
        }
        if (substr($t, $p, 9) === '(object) ') {
            $p += 9;
            self::skipWs($t, $p);
            if (substr($t, $p, 5) !== 'array') throw new RuntimeException("expected array after (object) at offset {$p}");
            $p += 5;
            return self::readArrayBody($t, $p, $depth);
        }
        // \Class::__set_state(array(...))
        if (preg_match('/\G\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::__set_state\(\s*array/', $t, $m, 0, $p)) {
            $p += strlen($m[0]);
            $arr = self::readArrayBody($t, $p, $depth);
            self::skipWs($t, $p);
            if (($t[$p] ?? '') !== ')') throw new RuntimeException("expected ) after __set_state at offset {$p}");
            $p++;
            return $arr;
        }
        if (preg_match('/\G(?:NULL|null)\b/', $t, $m, 0, $p)) { $p += strlen($m[0]); return null; }
        if (preg_match('/\G(?:true|false)\b/', $t, $m, 0, $p)) { $p += strlen($m[0]); return $m[0] === 'true'; }
        if (preg_match('/\G-?(?:INF|NAN)\b/', $t, $m, 0, $p)) {
            $p += strlen($m[0]);
            return $m[0] === 'NAN' ? NAN : ($m[0][0] === '-' ? -INF : INF);
        }
        if (preg_match('/\G-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', $t, $m, 0, $p)) {
            $p += strlen($m[0]);
            return (strpbrk($m[0], '.eE') === false) ? (int) $m[0] : (float) $m[0];
        }
        throw new RuntimeException('unexpected ' . json_encode(substr($t, $p, 20)) . " at offset {$p}");
    }

    /** After the word "array": ' (' ... ')' (or '(' ... ')'). */
    private static function readArrayBody(string $t, int &$p, int $depth): array
    {
        self::skipWs($t, $p);
        if (($t[$p] ?? '') !== '(') throw new RuntimeException("expected ( at offset {$p}");
        $p++;
        $out = [];
        while (true) {
            self::skipWs($t, $p);
            if ($p >= strlen($t)) throw new RuntimeException('unterminated array');
            if ($t[$p] === ')') { $p++; return $out; }
            $key = self::readValue($t, $p, $depth + 1);
            if (!is_int($key) && !is_string($key)) throw new RuntimeException("bad array key at offset {$p}");
            self::skipWs($t, $p);
            if (substr($t, $p, 2) !== '=>') throw new RuntimeException("expected => at offset {$p}");
            $p += 2;
            $out[$key] = self::readValue($t, $p, $depth + 1);
            self::skipWs($t, $p);
            if (($t[$p] ?? '') === ',') $p++;
        }
    }

    /** 'text' with \\ and \' escapes (var_export), or "\0" pieces joined with . (var_export of a NUL byte). */
    private static function readString(string $t, int &$p): string
    {
        $out = '';
        while (true) {
            $q = $t[$p] ?? '';
            if ($q === "'") {
                $p++;
                $n = strlen($t);
                $start = $p;
                $buf = '';
                while ($p < $n) {
                    $ch = $t[$p];
                    if ($ch === '\\' && $p + 1 < $n && ($t[$p + 1] === '\\' || $t[$p + 1] === "'")) {
                        $buf .= substr($t, $start, $p - $start) . $t[$p + 1];
                        $p += 2;
                        $start = $p;
                        continue;
                    }
                    if ($ch === "'") break;
                    $p++;
                }
                if ($p >= $n) throw new RuntimeException('unterminated string');
                $buf .= substr($t, $start, $p - $start);
                $p++;
                $out .= $buf;
            } elseif ($q === '"') {
                // only "\0" is written double-quoted by var_export
                if (substr($t, $p, 4) !== '"\0"') throw new RuntimeException("unexpected double-quoted string at offset {$p}");
                $p += 4;
                $out .= "\0";
            } else {
                throw new RuntimeException("expected a string at offset {$p}");
            }
            $save = $p;
            self::skipWs($t, $p);
            if (($t[$p] ?? '') === '.') {
                $p++;
                self::skipWs($t, $p);
                continue;
            }
            $p = $save;
            return $out;
        }
    }

    // =====================================================================
    // THE LOG
    // =====================================================================

    /**
     * The captures of a log's text, oldest first: ['n' => 1-based, 'ts', 'kind' => 'request' | 'marker',
     * 'payload' => array|null, 'note' => marker text or parse error, 'raw' => the body text].
     */
    public static function parseLog(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        if (!preg_match_all('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z))\n=\n/m', $text, $m, PREG_OFFSET_CAPTURE)) return [];
        $out = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $bodyStart = $m[0][$i][1] + strlen($m[0][$i][0]);
            $end = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($text);
            $body = substr($text, $bodyStart, $end - $bodyStart);
            // the entry ends "\n=\n" (the next header follows at once, or the file ends)
            $body = rtrim((string) preg_replace('/\n=\n?\z/', '', $body), "\n");
            $e = ['n' => $i + 1, 'ts' => $m[1][$i][0], 'kind' => 'request', 'payload' => null, 'note' => '', 'raw' => $body];
            if (preg_match('/^Request id:\s*(\S+)/', $body, $r)) {
                $e['kind'] = 'marker';
                $e['note'] = 'Request id ' . $r[1];
            } else {
                try {
                    $payload = self::parseExport($body);
                    if (!is_array($payload)) throw new RuntimeException('the body is not an array');
                    $e['payload'] = $payload;
                } catch (Throwable $ex) {
                    $e['note'] = 'unreadable: ' . $ex->getMessage();
                }
            }
            $out[] = $e;
        }
        return $out;
    }

    public static function loadLog(string $path): array
    {
        if (!is_file($path)) throw new RuntimeException("no such log: {$path}");
        $text = file_get_contents($path);
        if ($text === false) throw new RuntimeException("cannot read {$path}");
        return self::parseLog($text);
    }

    /** A capture by number: 1-based, negative from the newest. Throws when there is none. */
    public static function pick(array $captures, int $i): array
    {
        $n = count($captures);
        $idx = $i < 0 ? $n + $i : $i - 1;
        if ($idx < 0 || $idx >= $n) throw new RuntimeException("no capture {$i} (the log has {$n})");
        return $captures[$idx];
    }

    /** A request capture (not a marker or an unreadable one). */
    private static function pickRequest(array $captures, int $i): array
    {
        $c = self::pick($captures, $i);
        if ($c['kind'] !== 'request' || !is_array($c['payload'])) throw new RuntimeException("capture {$c['n']} is not a readable request ({$c['note']})");
        return $c;
    }

    // =====================================================================
    // READING A REQUEST
    // =====================================================================

    /** A message's text: a string content, or the text parts of a content array. */
    public static function contentText($content): string
    {
        if (is_string($content)) return $content;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) $parts[] = (string) $part['text'];
                elseif (is_string($part)) $parts[] = $part;
            }
            return implode("\n", $parts);
        }
        return '';
    }

    /** The system prompt: every system message's text, in order. */
    public static function systemPrompt(array $payload): string
    {
        $parts = [];
        foreach ((array) ($payload['messages'] ?? []) as $m) {
            if (is_array($m) && ($m['role'] ?? '') === 'system') $parts[] = self::contentText($m['content'] ?? '');
        }
        return implode("\n", $parts);
    }

    /** Who speaks: the system prompt's "Roleplay as X" / "You are X", '' when it says neither. */
    public static function speaker(array $payload): string
    {
        $sys = self::systemPrompt($payload);
        if (preg_match('/Roleplay as ([^\n\[<]+?)\s*(?:\n|$|\[|<)/', $sys, $m)) return trim($m[1]);
        if (preg_match('/You are ([A-Z][^\n,.]{0,60})/', $sys, $m)) return trim($m[1]);
        return '';
    }

    public static function chars(array $payload): int
    {
        $n = 0;
        foreach ((array) ($payload['messages'] ?? []) as $m) if (is_array($m)) $n += strlen(self::contentText($m['content'] ?? ''));
        return $n;
    }

    private static function lastUserLine(array $payload): string
    {
        $messages = array_values((array) ($payload['messages'] ?? []));
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if (is_array($m) && ($m['role'] ?? '') === 'user') {
                $t = trim((string) preg_replace('/\s+/', ' ', self::contentText($m['content'] ?? '')));
                // the connector's closing JSON-format instruction is not what was said
                if (stripos($t, 'Use ONLY this JSON object') !== false || stripos($t, 'Always use this JSON object') !== false) continue;
                return $t;
            }
        }
        return '';
    }

    private static function clip(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '~' : $s;
    }

    // =====================================================================
    // COMMANDS (each returns the text to print)
    // =====================================================================

    public static function cmdList(array $captures, int $limit = 20): string
    {
        $total = count($captures);
        if ($total === 0) return "no captures in this log\n";
        $rows = array_slice($captures, max(0, $total - max(1, $limit)));
        $out = sprintf("%-4s %-19s %-34s %4s %6s  %-18s %s\n", '#', 'time', 'model', 'msgs', '~tok', 'speaker', 'last line');
        foreach ($rows as $c) {
            if ($c['kind'] !== 'request' || !is_array($c['payload'])) {
                $out .= sprintf("%-4d %-19s %s\n", $c['n'], substr($c['ts'], 0, 19), $c['kind'] === 'marker' ? '(' . $c['note'] . ')' : '(' . $c['note'] . ')');
                continue;
            }
            $p = $c['payload'];
            $out .= sprintf("%-4d %-19s %-34s %4d %6d  %-18s %s\n", $c['n'], str_replace('T', ' ', substr($c['ts'], 0, 19)),
                self::clip((string) ($p['model'] ?? '?'), 34), count((array) ($p['messages'] ?? [])), intdiv(self::chars($p), 4),
                self::clip(self::speaker($p), 18), self::clip(self::lastUserLine($p), 60));
        }
        return $out . "{$total} captures in the log\n";
    }

    public static function cmdShow(array $captures, int $i, array $opts = []): string
    {
        $c = self::pickRequest($captures, $i);
        $p = $c['payload'];
        if (!empty($opts['json'])) return json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        if (!empty($opts['system'])) return self::systemPrompt($p) . "\n";
        if (!empty($opts['tools'])) {
            $names = [];
            foreach ((array) ($p['tools'] ?? []) as $t) $names[] = (string) ($t['function']['name'] ?? $t['name'] ?? '?');
            return $names ? implode("\n", $names) . "\n" : "no tools in this request\n";
        }
        $out = "capture {$c['n']}  {$c['ts']}\n";
        foreach ($p as $k => $v) {
            if (in_array($k, ['messages', 'tools'], true)) continue;
            $out .= "  {$k}: " . (is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
        }
        if (isset($p['tools'])) $out .= '  tools: ' . count((array) $p['tools']) . " (show --tools lists them)\n";
        foreach (array_values((array) ($p['messages'] ?? [])) as $n => $m) {
            if (!is_array($m)) continue;
            $out .= "\n--- message " . ($n + 1) . ' [' . ($m['role'] ?? '?') . '] ---' . "\n" . self::contentText($m['content'] ?? '') . "\n";
            if (isset($m['tool_calls'])) $out .= '(tool_calls: ' . json_encode($m['tool_calls'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ")\n";
        }
        return $out;
    }

    /** Line diff of two texts (LCS), as hunks with two lines of context; '' when equal. */
    public static function lineDiff(string $a, string $b, int $context = 2): string
    {
        $x = $a === '' ? [] : explode("\n", $a);
        $y = $b === '' ? [] : explode("\n", $b);
        $n = count($x);
        $m = count($y);
        if ($n * $m > 4000000) {
            // too large for the table: a set difference, unordered
            $ops = [];
            foreach (array_diff($x, $y) as $l) $ops[] = ['-', $l];
            foreach (array_diff($y, $x) as $l) $ops[] = ['+', $l];
            $s = '';
            foreach ($ops as [$o, $l]) $s .= $o . ' ' . $l . "\n";
            return $s === '' ? '' : "(large texts: lines only on one side, not in order)\n" . $s;
        }
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $x[$i] === $y[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $x[$i] === $y[$j]) { $ops[] = [' ', $x[$i]]; $i++; $j++; }
            elseif ($i < $n && ($j >= $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) { $ops[] = ['-', $x[$i]]; $i++; }
            else { $ops[] = ['+', $y[$j]]; $j++; }
        }
        $keep = array_fill(0, count($ops), false);
        foreach ($ops as $k => [$o]) {
            if ($o === ' ') continue;
            for ($d = max(0, $k - $context); $d <= min(count($ops) - 1, $k + $context); $d++) $keep[$d] = true;
        }
        $out = '';
        $gap = false;
        foreach ($ops as $k => [$o, $l]) {
            if (!$keep[$k]) { $gap = true; continue; }
            if ($gap && $out !== '') $out .= "...\n";
            $gap = false;
            $out .= $o . ' ' . $l . "\n";
        }
        return $out;
    }

    public static function cmdDiff(array $captures, int $a, int $b): string
    {
        $ca = self::pickRequest($captures, $a);
        $cb = self::pickRequest($captures, $b);
        $pa = $ca['payload'];
        $pb = $cb['payload'];
        $out = "capture {$ca['n']} ({$ca['ts']}, " . self::speaker($pa) . ") against capture {$cb['n']} ({$cb['ts']}, " . self::speaker($pb) . ")\n";
        $params = [];
        foreach (array_unique(array_merge(array_keys($pa), array_keys($pb))) as $k) {
            if (in_array($k, ['messages', 'tools'], true)) continue;
            $va = json_encode($pa[$k] ?? null);
            $vb = json_encode($pb[$k] ?? null);
            if ($va !== $vb) $params[] = "  {$k}: " . (array_key_exists($k, $pa) ? $va : '(none)') . ' -> ' . (array_key_exists($k, $pb) ? $vb : '(none)');
        }
        $out .= $params ? "request parameters that differ:\n" . implode("\n", $params) . "\n" : "request parameters: the same\n";
        $out .= 'messages: ' . count((array) ($pa['messages'] ?? [])) . ' -> ' . count((array) ($pb['messages'] ?? []))
            . '; system prompt ' . strlen(self::systemPrompt($pa)) . ' -> ' . strlen(self::systemPrompt($pb)) . " characters\n";
        $d = self::lineDiff(self::systemPrompt($pa), self::systemPrompt($pb));
        $out .= $d === '' ? "system prompt: identical\n" : "system prompt:\n" . $d;
        return $out;
    }

    // =====================================================================
    // REPLAY
    // =====================================================================

    /** Cut each <tag>...</tag> block out of every message (A/B one block). Returns the new payload and what was cut. */
    public static function withoutBlocks(array $payload, array $tags, array &$cut = []): array
    {
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*\z/', $tag)) throw new RuntimeException("--without: '{$tag}' is not a tag name");
            $cut[$tag] = 0;
            foreach ((array) ($payload['messages'] ?? []) as $k => $m) {
                if (!is_array($m) || !is_string($m['content'] ?? null)) continue;
                $n = 0;
                $payload['messages'][$k]['content'] = (string) preg_replace('~[ \t]*<' . preg_quote($tag, '~') . '>.*?</' . preg_quote($tag, '~') . '>[ \t]*\n?~s', '', $m['content'], -1, $n);
                $cut[$tag] += $n;
            }
        }
        return $payload;
    }

    /**
     * The request to send: the capture's payload, then --model, --without, --patch.
     * @return array{0: array, 1: array} [payload, notes]
     */
    public static function buildRequest(array $capture, array $opts): array
    {
        $payload = $capture['payload'];
        $notes = [];
        if (!empty($opts['model'])) {
            if (!preg_match('#^[A-Za-z0-9._:/@+~\-]+\z#', (string) $opts['model'])) throw new RuntimeException('--model: not a model slug');
            $notes[] = "model {$payload['model']} -> {$opts['model']}";
            $payload['model'] = (string) $opts['model'];
        }
        if (!empty($opts['without'])) {
            $cut = [];
            $payload = self::withoutBlocks($payload, is_array($opts['without']) ? $opts['without'] : explode(',', (string) $opts['without']), $cut);
            foreach ($cut as $tag => $n) $notes[] = "cut <{$tag}> from {$n} message(s)";
        }
        if (!empty($opts['patch'])) {
            $file = (string) $opts['patch'];
            if (!is_file($file)) throw new RuntimeException("no such patch file: {$file}");
            $fn = (static function (string $f) { return require $f; })($file);
            if (!is_callable($fn)) throw new RuntimeException('the patch file must return a function (array $payload, array $meta): array');
            $before = json_encode($payload);
            $patched = $fn($payload, ['index' => $capture['n'], 'ts' => $capture['ts'], 'model' => $capture['payload']['model'] ?? null]);
            if (!is_array($patched) || !isset($patched['messages']) || !is_array($patched['messages'])) throw new RuntimeException('the patch must return the request array with its messages');
            if (json_encode($patched) !== $before) $notes[] = 'patched by ' . basename($file);
            $payload = $patched;
        }
        return [$payload, $notes];
    }

    /**
     * The configured connector, from core's own LLMConnector (core_llm_connector row + API badge, SELECTs):
     * ['id', 'label', 'driver', 'model', 'url', 'api_key', 'headers']. $db: a SELECT-capable adapter.
     */
    public static function resolveEndpoint($db, ?int $connectorId, string $captureModel, string $enginePath): array
    {
        $GLOBALS['db'] = $db;
        $GLOBALS['ENGINE_PATH'] = $GLOBALS['ENGINE_PATH'] ?? $enginePath;
        require_once $enginePath . 'lib/core/llm_connector.class.php';
        $c = new LLMConnector();
        if ($connectorId !== null) {
            $row = $c->getById($connectorId);
            if (!is_array($row)) throw new RuntimeException("no core_llm_connector row {$connectorId}");
        } else {
            $rows = array_values(array_filter((array) $c->readAll(), 'is_array'));
            $same = array_values(array_filter($rows, fn($r) => strcasecmp((string) ($r['model'] ?? ''), $captureModel) === 0));
            if (count($same) >= 1) $row = $same[0];
            elseif (count($rows) === 1) $row = $rows[0];
            else {
                $list = array_map(fn($r) => '#' . $r['id'] . ' ' . ($r['label'] ?? '') . ' (' . ($r['driver'] ?? '?') . ' / ' . ($r['model'] ?? '?') . ')', $rows);
                throw new RuntimeException('which connector? none has the capture\'s model (' . $captureModel . '); pass --connector=ID: ' . implode('; ', $list));
            }
        }
        $c->setOldGlobals($row);
        $driver = (string) ($row['driver'] ?? '');
        $cfg = (array) ($GLOBALS['CONNECTOR'][$driver] ?? []);
        $url = trim((string) ($cfg['url'] ?? $row['url'] ?? ''));
        if (strlen($url) < 8) throw new RuntimeException("connector #{$row['id']} ({$driver}) has no usable url");
        $key = (string) ($cfg['API_KEY'] ?? '');
        $headers = ['Content-Type: application/json'];
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        if ($driver === 'openrouterjson' || stripos($url, 'openrouter.ai') !== false) {
            $headers[] = 'HTTP-Referer: https://dwemerdynamics.com/';
            $headers[] = 'X-Title: Dwemer Dynamics';
        }
        return ['id' => intval($row['id'] ?? 0), 'label' => (string) ($row['label'] ?? ''), 'driver' => $driver,
            'model' => (string) ($row['model'] ?? ''), 'url' => $url, 'api_key' => $key, 'headers' => $headers];
    }

    /** The real HTTP transport: POST $body, read the reply to the end. ['status' => int, 'body' => string, 'error' => ?string]. */
    public static function httpTransport(string $url, array $headers, string $body, int $timeout = 120): array
    {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => $timeout, 'ignore_errors' => true]]);
        $h = @fopen($url, 'r', false, $ctx);
        if (!$h) {
            $e = error_get_last();
            return ['status' => 0, 'body' => '', 'error' => (string) ($e['message'] ?? 'request failed')];
        }
        $text = (string) stream_get_contents($h);
        $meta = stream_get_meta_data($h);
        fclose($h);
        $status = 0;
        if (preg_match('/\b(\d{3})\b/', (string) ($meta['wrapper_data'][0] ?? ''), $m)) $status = intval($m[1]);
        return ['status' => $status, 'body' => $text, 'error' => null];
    }

    /** A chat reply: an SSE stream ("data: {...}" lines) or one JSON body. ['text', 'finish', 'usage', 'error']. */
    public static function parseReply(string $body): array
    {
        $out = ['text' => '', 'finish' => null, 'usage' => null, 'error' => null];
        $consume = function (array $d) use (&$out): void {
            if (isset($d['error'])) $out['error'] = is_array($d['error']) ? (string) ($d['error']['message'] ?? json_encode($d['error'])) : (string) $d['error'];
            if (isset($d['usage']) && is_array($d['usage'])) $out['usage'] = $d['usage'];
            $ch = $d['choices'][0] ?? null;
            if (!is_array($ch)) return;
            $piece = $ch['delta']['content'] ?? $ch['message']['content'] ?? $ch['text'] ?? '';
            if (is_string($piece)) $out['text'] .= $piece;
            if (!empty($ch['finish_reason'])) $out['finish'] = (string) $ch['finish_reason'];
        };
        $trim = trim($body);
        if ($trim !== '' && $trim[0] === '{' && ($j = json_decode($trim, true)) !== null) {
            $consume($j);
            return $out;
        }
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (strncmp($line, 'data:', 5) !== 0) continue;
            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') continue;
            $d = json_decode($data, true);
            if (is_array($d)) $consume($d);
        }
        return $out;
    }

    /** The JSON object in the model's reply (it may wrap it in prose or a code fence), or null. */
    public static function replyFields(string $text): ?array
    {
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a === false || $b === false || $b < $a) return null;
        $j = json_decode(substr($text, $a, $b - $a + 1), true);
        return is_array($j) ? $j : null;
    }

    /**
     * Send capture $c again. $transport: (url, headers, body, timeout) => ['status', 'body', 'error'].
     * Returns ['request' => the payload sent, 'notes', 'endpoint' => (no key), 'http', 'reply', 'fields', 'ms'].
     */
    public static function replay(array $capture, array $opts, array $endpoint, callable $transport): array
    {
        [$payload, $notes] = self::buildRequest($capture, $opts);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) throw new RuntimeException('the request does not encode as JSON');
        $t0 = microtime(true);
        $res = $transport($endpoint['url'], $endpoint['headers'], $body, intval($opts['timeout'] ?? 120));
        $ms = intval(round((microtime(true) - $t0) * 1000));
        $reply = self::parseReply((string) ($res['body'] ?? ''));
        if (!empty($res['error'])) $reply['error'] = (string) $res['error'];
        elseif (intval($res['status'] ?? 0) >= 300 && $reply['error'] === null) $reply['error'] = 'HTTP ' . intval($res['status']) . ': ' . substr((string) $res['body'], 0, 300);
        return ['request' => $payload, 'notes' => $notes, 'endpoint' => array_diff_key($endpoint, ['api_key' => 1, 'headers' => 1]),
            'http' => intval($res['status'] ?? 0), 'reply' => $reply, 'fields' => self::replyFields($reply['text']), 'ms' => $ms];
    }

    public static function formatReplay(array $capture, array $r): string
    {
        $out = "replay of capture {$capture['n']} ({$capture['ts']}, " . self::speaker($capture['payload']) . ")\n";
        $out .= 'connector #' . $r['endpoint']['id'] . ' ' . $r['endpoint']['label'] . ' (' . $r['endpoint']['driver'] . ') ' . parse_url($r['endpoint']['url'], PHP_URL_HOST) . "\n";
        $out .= 'model: ' . ($r['request']['model'] ?? '?') . ($capture['payload']['model'] !== ($r['request']['model'] ?? null) ? ' (captured: ' . $capture['payload']['model'] . ')' : '') . "\n";
        foreach ($r['notes'] as $n) $out .= "  changed: {$n}\n";
        $out .= 'HTTP ' . $r['http'] . ', ' . $r['ms'] . " ms\n";
        if ($r['reply']['error'] !== null) return $out . 'ERROR: ' . $r['reply']['error'] . "\n";
        $out .= "reply:\n" . trim($r['reply']['text']) . "\n";
        if (is_array($r['fields'])) {
            $out .= "fields:\n";
            foreach ($r['fields'] as $k => $v) $out .= "  {$k}: " . (is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n";
        }
        if (is_array($r['reply']['usage'])) $out .= 'usage: ' . json_encode($r['reply']['usage']) . "\n";
        return $out;
    }

    // =====================================================================
    // CLI
    // =====================================================================

    private const VALUE_OPTS = ['model', 'patch', 'log', 'connector', 'timeout', 'out', 'without'];

    /** @return array{0: string, 1: array, 2: array} [command, positional, options] */
    public static function parseArgs(array $argv): array
    {
        array_shift($argv);
        $cmd = (string) (array_shift($argv) ?? '');
        $pos = [];
        $opts = [];
        while ($argv) {
            $a = array_shift($argv);
            if (strncmp($a, '--', 2) === 0) {
                $name = substr($a, 2);
                if (($eq = strpos($name, '=')) !== false) { $opts[substr($name, 0, $eq)] = substr($name, $eq + 1); continue; }
                if (in_array($name, self::VALUE_OPTS, true) && $argv) { $opts[$name] = array_shift($argv); continue; }
                $opts[$name] = true;
            } else {
                $pos[] = $a;
            }
        }
        return [$cmd, $pos, $opts];
    }

    public static function usage(): string
    {
        return "usage: replay_prompt.php list [N] | show <i> | diff <a> <b> | replay <i> [--model SLUG] [--patch FILE] [--without=TAG] [--connector=ID] [--dry-run]\n"
            . "       options: --log=FILE (default log/context_sent_to_llm.log) --fast (the fast-request log)\n";
    }

    /** The whole CLI as a function (tests call it). Returns the exit code; prints through $print. */
    public static function main(array $argv, callable $print, ?callable $transport = null, $db = null): int
    {
        [$cmd, $pos, $opts] = self::parseArgs($argv);
        $enginePath = realpath(__DIR__ . '/../../../') . '/';
        try {
            if (!in_array($cmd, ['list', 'show', 'diff', 'replay'], true)) { $print(self::usage()); return 2; }
            $log = isset($opts['log']) && is_string($opts['log']) ? $opts['log']
                : $enginePath . 'log/' . (!empty($opts['fast']) ? 'context_sent_to_llm_fast.log' : 'context_sent_to_llm.log');
            $captures = self::loadLog($log);
            if ($cmd === 'list') { $print(self::cmdList($captures, isset($pos[0]) ? intval($pos[0]) : 20)); return 0; }
            if ($cmd === 'show') {
                if (!isset($pos[0])) { $print(self::usage()); return 2; }
                $print(self::cmdShow($captures, intval($pos[0]), $opts));
                return 0;
            }
            if ($cmd === 'diff') {
                if (!isset($pos[1])) { $print(self::usage()); return 2; }
                $print(self::cmdDiff($captures, intval($pos[0]), intval($pos[1])));
                return 0;
            }
            if (!isset($pos[0])) { $print(self::usage()); return 2; }
            $c = self::pickRequest($captures, intval($pos[0]));
            if (!empty($opts['dry-run'])) {
                [$payload, $notes] = self::buildRequest($c, $opts);
                $print("dry run of capture {$c['n']}: nothing is sent\nmodel: " . ($payload['model'] ?? '?') . "\n");
                foreach ($notes as $n) $print("  changed: {$n}\n");
                $d = self::lineDiff(self::systemPrompt($c['payload']), self::systemPrompt($payload));
                $print($d === '' ? "system prompt: unchanged\n" : "system prompt, captured -> sent:\n" . $d);
                return 0;
            }
            if ($db === null) {
                require_once __DIR__ . '/readonly_pg.php';
                $db = new RelDynReadOnlyPg(getenv('RELDYN_LIVE_PG_DSN') ?: 'dbname=dwemer');
            }
            $endpoint = self::resolveEndpoint($db, isset($opts['connector']) ? intval($opts['connector']) : null, (string) ($c['payload']['model'] ?? ''), $enginePath);
            $r = self::replay($c, $opts, $endpoint, $transport ?? [self::class, 'httpTransport']);
            $print(self::formatReplay($c, $r));
            if (!empty($opts['out']) && is_string($opts['out'])) {
                file_put_contents($opts['out'], json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
                $print("saved {$opts['out']}\n");
            }
            return $r['reply']['error'] === null ? 0 : 1;
        } catch (Throwable $e) {
            $print('error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(RelDynReplay::main($argv, function (string $s): void { fwrite(STDOUT, $s); }));
}
