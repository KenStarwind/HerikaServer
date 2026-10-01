<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/tools/replay_prompt.php';

/**
 * replay-integration-testing: the replay tool for CHIM 3.4.1 (ext/relationship_dynamics/tools/replay_prompt.php).
 * The captured-format fixtures are written by the connectors' own writer expression
 * (date . "\n=\n" . var_export($data, true) . "\n=\n"), the committed sample log
 * (tools/fixtures/context_sent_to_llm.sample.log: Aela, Muiri and Lynly Star-Sung turns, synthetic text)
 * and logs this test writes. The connector is a stub transport: NO live LLM call; the live run is Ken's.
 * The database-backed parts (the configured connector through core's LLMConnector, the four test beds'
 * real turns) are in RelDynReplayToolTestBedsPostgresTest.
 */
final class RelDynReplayToolTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../../ext/relationship_dynamics/tools/fixtures/context_sent_to_llm.sample.log';
    private const KEY = 'sk-test-0000-never-printed';

    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        $this->tmp = [];
    }

    private function tmpFile(string $content, string $suffix = '.log'): string
    {
        $f = tempnam(sys_get_temp_dir(), 'rdreplay') ?: $this->fail('no temp file');
        rename($f, $f . $suffix);
        $f .= $suffix;
        file_put_contents($f, $content);
        $this->tmp[] = $f;
        return $f;
    }

    /** One entry as every 3.4.1 connector writes it. */
    private static function entry(array $data, string $ts): string
    {
        return $ts . "\n=\n" . var_export($data, true) . "\n=\n";
    }

    private static function sample(): array
    {
        return RelDynReplay::loadLog(self::SAMPLE);
    }

    private function endpoint(): array
    {
        return ['id' => 3, 'label' => 'Main', 'driver' => 'openrouterjson', 'model' => 'deepseek/deepseek-v4-flash',
            'url' => 'https://openrouter.ai/api/v1/chat/completions', 'api_key' => self::KEY,
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . self::KEY]];
    }

    /** A transport that records what it was sent and answers like the connector's stream. */
    private function stub(array &$sent, ?string $reply = null, int $status = 200): callable
    {
        $reply = $reply ?? '{"character":"Aela the Huntress","listener":"Kaida","mood":"happy","action":"Talk","target":"","message":"Aye. The wind was with us."}';
        return function (string $url, array $headers, string $body, int $timeout) use (&$sent, $reply, $status): array {
            $sent = ['url' => $url, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true), 'timeout' => $timeout];
            $half = intdiv(strlen($reply), 2);
            $chunks = [substr($reply, 0, $half), substr($reply, $half)];
            $sse = '';
            foreach ($chunks as $c) $sse .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $c]]]]) . "\n\n";
            $sse .= 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 812, 'completion_tokens' => 31]]) . "\n\ndata: [DONE]\n\n";
            return ['status' => $status, 'body' => $status >= 300 ? '{"error":{"message":"model not found"}}' : $sse, 'error' => null];
        };
    }

    // ------------------------------------------------------------------ the var_export reader

    public function testTheReaderRoundTripsWhatVarExportWrites(): void
    {
        $values = [
            ['a' => 1, 'b' => -2, 'c' => 1.5, 'd' => 0.0, 'e' => -0.25, 'f' => true, 'g' => false, 'h' => null, 'i' => [], 'j' => [[], [1, [2, [3]]]]],
            ['s' => "it's a 'quoted' word, a back\\slash, a trailing backslash\\", 'n' => "line one\nline two\r\n\ttabbed", 'u' => "naïve \u{200B} café \u{1F409}"],
            ['nul' => "before\0after", 'only' => "\0", 'both' => "a\0b\0c"],
            ['empty' => '', 'quote' => "'", 'two' => "\\'", 'sp' => ' '],
            [0 => 'x', 1 => 'y', 7 => 'z', 'k' => ['deep' => ['deeper' => ['deepest' => 'yes']]]],
            ['big' => PHP_INT_MAX, 'neg' => PHP_INT_MIN + 1, 'exp' => 1.0E+25, 'tiny' => 1.0E-10],
            "a bare string",
            42,
        ];
        foreach ($values as $i => $v) {
            $this->assertSame($v, RelDynReplay::parseExport(var_export($v, true)), "value {$i}");
        }
        $this->assertNan(RelDynReplay::parseExport(var_export(NAN, true)));
        $this->assertSame(INF, RelDynReplay::parseExport(var_export(INF, true)));
        $this->assertSame(-INF, RelDynReplay::parseExport(var_export(-INF, true)));
    }

    public function testTheReaderReadsObjectsAsArrays(): void
    {
        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], RelDynReplay::parseExport(var_export((object) ['a' => 1, 'b' => (object) ['c' => 2]], true)));
        $this->assertSame(['x' => 1], RelDynReplay::parseExport("\\Foo\\Bar::__set_state(array(\n   'x' => 1,\n))"));
    }

    /** A log is data: nothing in it runs, whatever it says. */
    public function testTheReaderNeverRunsAnything(): void
    {
        $evil = ["array ( 'a' => system('echo pwned'), )", "array ( 'a' => 1 ) . phpinfo()", "array ( 'a' => 'x' . phpinfo() . 'y', )",
            "array ( 'a' => \"\$x\", )", "array ( 'a' => 1, ", "array ( 'a' => 'unterminated", "array ( => 1, )", "'a' 'b'", "array ( 'a' => 1 ) trailing", '', 'foo()'];
        foreach ($evil as $text) {
            try {
                RelDynReplay::parseExport($text);
                $this->fail("read as data: {$text}");
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        // text that looks like code, inside a string, is only text
        $v = ['content' => "'; system('x'); // array ( 'a' => 1 ) \\' phpinfo();"];
        $this->assertSame($v, RelDynReplay::parseExport(var_export($v, true)));
        $this->expectOutputString('');
    }

    // ------------------------------------------------------------------ the log

    public function testTheSampleLogReadsAsThreeRequests(): void
    {
        $c = self::sample();
        $this->assertCount(3, $c);
        $this->assertSame([1, 2, 3], array_column($c, 'n'));
        $this->assertSame(['2026-09-30T18:02:11+02:00', '2026-09-30T18:04:40+02:00', '2026-09-30T18:07:03+02:00'], array_column($c, 'ts'));
        foreach ($c as $e) {
            $this->assertSame('request', $e['kind'], $e['note']);
            $this->assertSame('deepseek/deepseek-v4-flash', $e['payload']['model']);
            $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($e['payload']['messages'], 'role'));
            $this->assertTrue($e['payload']['stream']);
            $this->assertSame(['USER'], $e['payload']['stop']);
        }
        $this->assertSame(['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'], array_map(fn($e) => RelDynReplay::speaker($e['payload']), $c));
        // the third has the quirks: quotes, backslash, tab, zero-width space, tools, reasoning, provider
        $p = $c[2]['payload'];
        $this->assertStringContainsString("it's a 'quoted' word, a back\\slash, a tab\there, and a zero-width\u{200B}space", $p['messages'][0]['content']);
        $this->assertSame('chim_response', $p['tools'][0]['function']['name']);
        $this->assertSame(['exclude' => true, 'enabled' => false], $p['reasoning']);
        $this->assertNull($p['provider']['data_collection']);
        $this->assertSame(0.0, $p['presence_penalty']);
    }

    public function testCrlfLineEndingsAndMarkersAndJunkAreHandled(): void
    {
        // a checkout with CRLF line endings must read the same as one without
        $text = str_replace("\r\n", "\n", (string) file_get_contents(self::SAMPLE));
        $crlf = RelDynReplay::parseLog(str_replace("\n", "\r\n", $text));
        $this->assertCount(3, $crlf);
        $this->assertSame(self::sample()[0]['payload'], $crlf[0]['payload'], 'a log copied through Windows reads the same');

        // the fast log's request-id markers, and an entry that does not read (a half-written append)
        $mixed = self::entry(['model' => 'm/a', 'messages' => [['role' => 'user', 'content' => 'hi']]], '2026-09-30T10:00:00+02:00')
            . "2026-09-30T10:00:05+02:00\n=\nRequest id:77\n=\n"
            . "2026-09-30T10:00:09+02:00\n=\narray (\n  'model' => 'cut off\n"
            . self::entry(['model' => 'm/b', 'messages' => []], '2026-09-30T10:01:00Z');
        $c = RelDynReplay::parseLog($mixed);
        $this->assertSame(['request', 'marker', 'request', 'request'], array_column($c, 'kind'));
        $this->assertSame('Request id 77', $c[1]['note']);
        $this->assertNull($c[2]['payload']);
        $this->assertStringContainsString('unreadable', $c[2]['note']);
        $this->assertSame('m/b', $c[3]['payload']['model']);
        $this->assertSame([], RelDynReplay::parseLog("no captures here\n"));
        $this->assertSame([], RelDynReplay::parseLog(''));
    }

    public function testPickCountsFromEitherEnd(): void
    {
        $c = self::sample();
        $this->assertSame(1, RelDynReplay::pick($c, 1)['n']);
        $this->assertSame(3, RelDynReplay::pick($c, -1)['n']);
        $this->assertSame(2, RelDynReplay::pick($c, -2)['n']);
        foreach ([0, 4, -4] as $bad) {
            try {
                RelDynReplay::pick($c, $bad);
                $this->fail("capture {$bad}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('the log has 3', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ list / show / diff

    public function testListShowsTheCapturesNewestN(): void
    {
        $all = RelDynReplay::cmdList(self::sample());
        $this->assertStringContainsString('Aela the Huntress', $all);
        $this->assertStringContainsString('Muiri', $all);
        $this->assertStringContainsString('Lynly Star-Sung', $all);
        $this->assertStringContainsString('deepseek/deepseek-v4-flash', $all);
        $this->assertStringContainsString('Good hunt today.', $all, 'the last thing said, not the connector\'s JSON instruction');
        $this->assertStringNotContainsString('Use ONLY this JSON', $all);
        $this->assertStringContainsString('3 captures in the log', $all);
        $lines = array_values(array_filter(explode("\n", $all)));
        $this->assertCount(5, $lines, 'header, three rows, the count');
        $last = RelDynReplay::cmdList(self::sample(), 1);
        $this->assertStringContainsString('Lynly Star-Sung', $last);
        $this->assertStringNotContainsString('Aela', $last);
        $this->assertSame("no captures in this log\n", RelDynReplay::cmdList([]));
    }

    public function testShowPrintsTheRequest(): void
    {
        $c = self::sample();
        $show = RelDynReplay::cmdShow($c, 1);
        $this->assertStringContainsString('capture 1  2026-09-30T18:02:11+02:00', $show);
        $this->assertStringContainsString('model: \'deepseek/deepseek-v4-flash\'', $show);
        $this->assertStringContainsString('--- message 1 [system] ---', $show);
        $this->assertStringContainsString('Aela the Huntress knows Kaida well', $show);
        $this->assertStringContainsString('--- message 4 [user] ---', $show);
        $sys = RelDynReplay::cmdShow($c, 2, ['system' => true]);
        $this->assertStringContainsString('Muiri keeps a careful distance', $sys);
        $this->assertStringNotContainsString('Kaida: How is the shop?', $sys, 'the system prompt only');
        $this->assertSame("chim_response\n", RelDynReplay::cmdShow($c, -1, ['tools' => true]));
        $this->assertSame("no tools in this request\n", RelDynReplay::cmdShow($c, 1, ['tools' => true]));
        $json = json_decode(RelDynReplay::cmdShow($c, 3, ['json' => true]), true);
        $this->assertSame('chim_response', $json['tools'][0]['function']['name']);
        $this->assertStringContainsString('tools: 1', RelDynReplay::cmdShow($c, 3));
    }

    /** Aela (a friend) against Muiri (toxic): the system prompts diverge exactly where the bond does. */
    public function testDiffShowsWhereTwoPromptsDiverge(): void
    {
        $d = RelDynReplay::cmdDiff(self::sample(), 1, 2);
        $this->assertStringContainsString('capture 1 (2026-09-30T18:02:11+02:00, Aela the Huntress) against capture 2 (2026-09-30T18:04:40+02:00, Muiri)', $d);
        $this->assertStringContainsString('- You are Aela the Huntress', $d);
        $this->assertStringContainsString('+ You are Muiri', $d);
        $this->assertStringContainsString('- Aela the Huntress knows Kaida well: his name, his story, a bond built on shared hunts.', $d);
        $this->assertStringContainsString('+ Muiri knows Kaida only as trouble.', $d);
        $this->assertStringContainsString('request parameters: the same', $d);
        $this->assertStringContainsString('  </felt>', $d, 'unchanged lines next to a change are shown as context');
        $this->assertStringNotContainsString('#Active Quests', $d, 'and the far ones are not');
        // the parameters that differ are listed too (Lynly's request carries reasoning and provider)
        $d3 = RelDynReplay::cmdDiff(self::sample(), 2, 3);
        $this->assertStringContainsString('request parameters that differ:', $d3);
        $this->assertStringContainsString('reasoning: (none) -> {"exclude":true,"enabled":false}', $d3);
        $this->assertStringContainsString('Lynly Star-Sung has never met this stranger', $d3);
        $this->assertSame(1, substr_count(RelDynReplay::cmdDiff(self::sample(), 1, 1), 'identical'));
    }

    public function testLineDiffIsAnLcsWithContext(): void
    {
        $this->assertSame('', RelDynReplay::lineDiff("a\nb\nc", "a\nb\nc"));
        $this->assertSame("  b\n- c\n+ x\n  d\n", RelDynReplay::lineDiff("a\nb\nc\nd\ne", "a\nb\nx\nd\ne", 1));
        $far = RelDynReplay::lineDiff(implode("\n", range(1, 40)), implode("\n", array_merge(range(1, 19), ['X'], range(21, 40))), 2);
        $this->assertSame("  18\n  19\n- 20\n+ X\n  21\n  22\n", $far, 'far lines are not printed');
        $moved = RelDynReplay::lineDiff("one\ntwo", "two\none");
        $this->assertSame("- one\n  two\n+ one\n", $moved);
        $this->assertSame("+ new\n", RelDynReplay::lineDiff('', 'new'));
        $this->assertSame("- old\n", RelDynReplay::lineDiff('old', ''));
    }

    // ------------------------------------------------------------------ building the request

    public function testWithoutCutsTheNamedBlocksAndSaysHowMany(): void
    {
        $c = RelDynReplay::pick(self::sample(), 1);
        [$payload, $notes] = RelDynReplay::buildRequest($c, ['without' => 'knowledge_of_player,felt']);
        $sys = $payload['messages'][0]['content'];
        $this->assertStringNotContainsString('knowledge_of_player', $sys);
        $this->assertStringNotContainsString('Aela trusts Kaida', $sys);
        $this->assertStringContainsString('<character>', $sys, 'only the named blocks go');
        $this->assertStringContainsString('Roleplay as Aela the Huntress', $sys);
        $this->assertSame(['cut <knowledge_of_player> from 1 message(s)', 'cut <felt> from 1 message(s)'], $notes);
        $this->assertSame($c['payload']['messages'][1], $payload['messages'][1], 'the other messages are untouched');
        [$same] = RelDynReplay::buildRequest($c, []);
        $this->assertSame($c['payload'], $same, 'no option: the captured request exactly');
        $this->expectException(RuntimeException::class);
        RelDynReplay::buildRequest($c, ['without' => 'a b;']);
    }

    public function testModelOverrideAndPatchFile(): void
    {
        $c = RelDynReplay::pick(self::sample(), 2);
        [$p, $notes] = RelDynReplay::buildRequest($c, ['model' => 'x-ai/grok-4.3']);
        $this->assertSame('x-ai/grok-4.3', $p['model']);
        $this->assertSame(['model deepseek/deepseek-v4-flash -> x-ai/grok-4.3'], $notes);
        foreach (['bad model', 'a;b', '$(x)', "x\n"] as $bad) {
            try {
                RelDynReplay::buildRequest($c, ['model' => $bad]);
                $this->fail("model {$bad}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('model slug', $e->getMessage());
            }
        }
        $patch = $this->tmpFile("<?php return function (array \$payload, array \$meta): array {\n"
            . "    \$payload['messages'][0]['content'] = str_replace('careful distance', 'warm welcome', \$payload['messages'][0]['content']);\n"
            . "    \$payload['temperature'] = 0.2;\n    \$payload['meta_seen'] = \$meta;\n    return \$payload;\n};\n", '.php');
        [$p, $notes] = RelDynReplay::buildRequest($c, ['patch' => $patch, 'model' => 'a/b']);
        $this->assertStringContainsString('Muiri keeps a warm welcome', $p['messages'][0]['content']);
        $this->assertSame(0.2, $p['temperature']);
        $this->assertSame(['index' => 2, 'ts' => '2026-09-30T18:04:40+02:00', 'model' => 'deepseek/deepseek-v4-flash'], $p['meta_seen'], "the patch sees the capture's own model");
        $this->assertSame('a/b', $p['model'], 'the patch runs after --model');
        $this->assertSame(['model deepseek/deepseek-v4-flash -> a/b', 'patched by ' . basename($patch)], $notes);
        // a patch that is not a function, or breaks the request, is refused
        foreach (["<?php return 5;", "<?php return function (array \$p, array \$m) { return ['no' => 'messages']; };"] as $bad) {
            try {
                RelDynReplay::buildRequest($c, ['patch' => $this->tmpFile($bad, '.php')]);
                $this->fail('bad patch accepted');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(RuntimeException::class);
        RelDynReplay::buildRequest($c, ['patch' => '/no/such/patch.php']);
    }

    // ------------------------------------------------------------------ the reply

    public function testReplyParsesAStreamOrOneBodyOrAnError(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"{\\\"mess\"}}]}\n\ndata: {\"choices\":[{\"delta\":{\"content\":\"age\\\":\\\"Hi\\\"}\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":9}}\n\ndata: [DONE]\n";
        $r = RelDynReplay::parseReply($sse);
        $this->assertSame('{"message":"Hi"}', $r['text']);
        $this->assertSame('stop', $r['finish']);
        $this->assertSame(['total_tokens' => 9], $r['usage']);
        $this->assertNull($r['error']);
        $one = RelDynReplay::parseReply('{"choices":[{"message":{"role":"assistant","content":"plain"},"finish_reason":"length"}],"usage":{"total_tokens":3}}');
        $this->assertSame(['plain', 'length'], [$one['text'], $one['finish']]);
        $this->assertSame('model not found', RelDynReplay::parseReply('{"error":{"message":"model not found","code":404}}')['error']);
        $this->assertSame('', RelDynReplay::parseReply('')['text']);
        // keep-alive comments and garbage lines in the stream are skipped
        $this->assertSame('ok', RelDynReplay::parseReply(": OPENROUTER PROCESSING\n\ndata: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\nnot data\n")['text']);
        $this->assertSame(['message' => 'Hi', 'mood' => 'x'], RelDynReplay::replyFields("Here you go:\n```json\n{\"message\":\"Hi\",\"mood\":\"x\"}\n```"));
        $this->assertNull(RelDynReplay::replyFields('no json here'));
    }

    // ------------------------------------------------------------------ replay, end to end against a stubbed connector

    public function testReplaySendsTheCapturedRequestUnchangedThroughTheConnector(): void
    {
        $c = RelDynReplay::pick(self::sample(), 1);
        $sent = [];
        $r = RelDynReplay::replay($c, [], $this->endpoint(), $this->stub($sent));
        // exactly what was captured: the model, every message, the params (a round trip of the writer and the reader)
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $sent['url']);
        $this->assertSame($c['payload'], $sent['json']);
        $this->assertContains('Authorization: Bearer ' . self::KEY, $sent['headers']);
        $this->assertSame([], $r['notes']);
        $this->assertSame(200, $r['http']);
        $this->assertSame('Aye. The wind was with us.', $r['fields']['message']);
        $this->assertSame('happy', $r['fields']['mood']);
        $this->assertSame(['prompt_tokens' => 812, 'completion_tokens' => 31], $r['reply']['usage']);
        $this->assertNull($r['reply']['error']);
        $this->assertArrayNotHasKey('api_key', $r['endpoint']);
        $this->assertStringNotContainsString(self::KEY, json_encode($r), 'the key never leaves the endpoint');

        $text = RelDynReplay::formatReplay($c, $r);
        $this->assertStringContainsString('replay of capture 1', $text);
        $this->assertStringContainsString('connector #3 Main (openrouterjson) openrouter.ai', $text);
        $this->assertStringContainsString('message: Aye. The wind was with us.', $text);
        $this->assertStringContainsString('usage: {"prompt_tokens":812', $text);
        $this->assertStringNotContainsString(self::KEY, $text);
        $this->assertStringNotContainsString('captured:', $text, 'same model: nothing to say');
    }

    public function testReplayWithAModelAnAbCutAndAPatch(): void
    {
        $c = RelDynReplay::pick(self::sample(), 3);
        $patch = $this->tmpFile("<?php return function (array \$p, array \$m): array { \$p['max_tokens'] = 64; return \$p; };", '.php');
        $sent = [];
        $r = RelDynReplay::replay($c, ['model' => 'google/gemini-3-flash', 'without' => 'felt', 'patch' => $patch, 'timeout' => 9],
            $this->endpoint(), $this->stub($sent, '{"character":"Lynly Star-Sung","message":"Oh! A song, then."}'));
        $this->assertSame('google/gemini-3-flash', $sent['json']['model']);
        $this->assertSame(64, $sent['json']['max_tokens']);
        $this->assertSame(9, $sent['timeout']);
        $this->assertStringNotContainsString('<felt>', $sent['json']['messages'][0]['content']);
        $this->assertStringContainsString('<knowledge_of_player>', $sent['json']['messages'][0]['content']);
        $this->assertSame($c['payload']['tools'], $sent['json']['tools'], 'the tools go too');
        $this->assertSame($c['payload']['messages'][1], $sent['json']['messages'][1]);
        $this->assertCount(3, $r['notes']);
        $text = RelDynReplay::formatReplay($c, $r);
        $this->assertStringContainsString('model: google/gemini-3-flash (captured: deepseek/deepseek-v4-flash)', $text);
        $this->assertStringContainsString('changed: cut <felt> from 1 message(s)', $text);
        $this->assertStringContainsString('message: Oh! A song, then.', $text);
    }

    public function testReplayReportsAnErrorFromTheConnectorAndATransportFailure(): void
    {
        $c = RelDynReplay::pick(self::sample(), 1);
        $sent = [];
        $r = RelDynReplay::replay($c, ['model' => 'nope/none'], $this->endpoint(), $this->stub($sent, null, 404));
        $this->assertSame(404, $r['http']);
        $this->assertSame('model not found', $r['reply']['error']);
        $this->assertStringContainsString('ERROR: model not found', RelDynReplay::formatReplay($c, $r));
        $down = RelDynReplay::replay($c, [], $this->endpoint(), fn() => ['status' => 0, 'body' => '', 'error' => 'Connection refused']);
        $this->assertSame('Connection refused', $down['reply']['error']);
        $odd = RelDynReplay::replay($c, [], $this->endpoint(), fn() => ['status' => 502, 'body' => '<html>bad gateway</html>', 'error' => null]);
        $this->assertStringContainsString('HTTP 502', $odd['reply']['error']);
    }

    // ------------------------------------------------------------------ the command line

    public function testArgumentsTakeAValueAfterASpaceOrAnEquals(): void
    {
        [$cmd, $pos, $o] = RelDynReplay::parseArgs(['replay_prompt.php', 'replay', '-1', '--model', 'a/b', '--patch=/x/p.php', '--dry-run', '--without', 'felt']);
        $this->assertSame('replay', $cmd);
        $this->assertSame(['-1'], $pos);
        $this->assertSame(['model' => 'a/b', 'patch' => '/x/p.php', 'dry-run' => true, 'without' => 'felt'], $o);
        [$cmd, $pos, $o] = RelDynReplay::parseArgs(['replay_prompt.php', 'diff', '1', '2', '--fast']);
        $this->assertSame(['diff', ['1', '2'], ['fast' => true]], [$cmd, $pos, $o]);
    }

    public function testTheCommandLineEndToEndOnTheSampleLog(): void
    {
        $run = function (array $args, ?callable $transport = null, $db = null): array {
            $out = '';
            $code = RelDynReplay::main(array_merge(['replay_prompt.php'], $args, ['--log=' . self::SAMPLE]), function (string $s) use (&$out): void { $out .= $s; }, $transport, $db);
            return [$code, $out];
        };
        [$code, $out] = $run(['list']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('3 captures in the log', $out);
        [$code, $out] = $run(['show', '2', '--system']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Muiri keeps a careful distance', $out);
        [$code, $out] = $run(['diff', '1', '3']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('+ Lynly Star-Sung has never met this stranger', $out);
        // replay, dry run: nothing is sent and the cut is shown as a diff of the system prompt
        $sent = [];
        [$code, $out] = $run(['replay', '1', '--dry-run', '--without=knowledge_of_player', '--model', 'a/b'], $this->stub($sent));
        $this->assertSame(0, $code);
        $this->assertSame([], $sent, 'a dry run sends nothing');
        $this->assertStringContainsString('dry run of capture 1: nothing is sent', $out);
        $this->assertStringContainsString('model: a/b', $out);
        $this->assertStringContainsString('- <knowledge_of_player>', $out);
        $this->assertStringContainsString('- Aela the Huntress knows Kaida well', $out);
        // usage and errors
        [$code, $out] = $run([]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage:', $out);
        [$code] = $run(['show']);
        $this->assertSame(2, $code);
        [$code, $out] = $run(['show', '9']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no capture 9 (the log has 3)', $out);
        $out = '';
        $code = RelDynReplay::main(['replay_prompt.php', 'list', '--log=/no/such/context.log'], function (string $s) use (&$out): void { $out .= $s; });
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no such log', $out);
    }

    public function testTheToolIsReadOnlyAboutTheLog(): void
    {
        $f = $this->tmpFile((string) file_get_contents(self::SAMPLE));
        $before = [md5_file($f), filemtime($f)];
        $sent = [];
        RelDynReplay::main(['replay_prompt.php', 'list', "--log={$f}"], function (string $s): void {});
        RelDynReplay::main(['replay_prompt.php', 'replay', '1', '--dry-run', "--log={$f}"], function (string $s): void {}, $this->stub($sent));
        clearstatcache();
        $this->assertSame($before, [md5_file($f), filemtime($f)]);
    }
}
