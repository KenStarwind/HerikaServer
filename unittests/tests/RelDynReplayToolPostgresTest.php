<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/tools/replay_prompt.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/tools/readonly_pg.php';

/**
 * replay-integration-testing, the configured connector: the replay tool finds the connector the way the
 * server is configured (core_llm_connector row + core_api_badge key, read through core's own LLMConnector
 * class, SELECTs only, through the read-only adapter the live tools use) and sends the captured request
 * to it. Production-faithful tables (lib/core/database_schema/core_llm_connector.sql, core_api_badge.sql);
 * the connector's HTTP end is a stub transport, so no LLM is called. The four test beds' real turns
 * replayed through the tool are in RelDynPromptGatingTestBedsPostgresTest.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynReplayToolPostgresTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../../ext/relationship_dynamics/tools/fixtures/context_sent_to_llm.sample.log';
    private const KEY_OR = 'sk-or-test-7f3a-never-printed';
    private const KEY_OAI = 'sk-oai-test-91bc-never-printed';

    private string $dsn;
    private string $schema;
    private $link;
    private array $tmp = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_replay_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($this->link, "CREATE SCHEMA {$this->schema}");
        pg_query($this->link, "SET search_path TO {$this->schema}");
        // lib/core/database_schema/core_llm_connector.sql, core_api_badge.sql
        pg_query($this->link, 'CREATE TABLE core_llm_connector (id integer NOT NULL, label text, metadata jsonb, url text, model text,
            provider text, driver text, reasoning_model integer, max_tokens integer, enforce_json integer DEFAULT 1,
            prefill_json integer DEFAULT 0, api_badge_id integer, json_schema integer, temperature numeric,
            presence_penalty numeric, frequency_penalty numeric, repetition_penalty numeric, top_p numeric, top_k integer,
            min_p numeric, top_a numeric, service text, PRIMARY KEY (id))');
        pg_query($this->link, 'CREATE TABLE core_api_badge (id integer NOT NULL, label text NOT NULL, api_key text NOT NULL, PRIMARY KEY (id))');
        pg_query($this->link, "INSERT INTO core_api_badge VALUES (1, 'OpenRouter', '" . self::KEY_OR . "'), (2, 'OpenAI', '" . self::KEY_OAI . "')");
        pg_query($this->link, "INSERT INTO core_llm_connector (id, label, metadata, url, model, driver, max_tokens, api_badge_id) VALUES
            (1, 'Main', '{}', 'https://openrouter.ai/api/v1/chat/completions', 'deepseek/deepseek-v4-flash', 'openrouterjson', 1024, 1),
            (2, 'Backup', '{\"seed\": 7}', 'https://api.openai.com/v1/chat/completions', 'gpt-5-mini', 'openaijson', 1024, 2)");
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) @unlink($f);
        if (!isset($this->schema)) return;
        pg_close($this->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** The adapter the live tools use (read-only session), in the test schema. */
    private function db(): RelDynReadOnlyPg
    {
        return new RelDynReadOnlyPg($this->dsn . " options='-c search_path=" . $this->schema . "'");
    }

    private function stub(array &$sent): callable
    {
        return function (string $url, array $headers, string $body, int $timeout) use (&$sent): array {
            $sent = ['url' => $url, 'headers' => $headers, 'json' => json_decode($body, true)];
            return ['status' => 200, 'body' => 'data: ' . json_encode(['choices' => [['delta' => ['content' => '{"character":"Aela the Huntress","message":"Aye."}']]]]) . "\n\ndata: [DONE]\n", 'error' => null];
        };
    }

    private function engine(): string
    {
        return realpath(__DIR__ . '/../../') . '/';
    }

    public function testTheConnectorWhoseModelWasCapturedIsTheOneUsed(): void
    {
        $e = RelDynReplay::resolveEndpoint($this->db(), null, 'deepseek/deepseek-v4-flash', $this->engine());
        $this->assertSame([1, 'Main', 'openrouterjson', 'https://openrouter.ai/api/v1/chat/completions'], [$e['id'], $e['label'], $e['driver'], $e['url']]);
        $this->assertSame(self::KEY_OR, $e['api_key'], "core's own LLMConnector read the badge's key");
        $this->assertContains('Authorization: Bearer ' . self::KEY_OR, $e['headers']);
        $this->assertContains('HTTP-Referer: https://dwemerdynamics.com/', $e['headers'], 'the headers the openrouter connector sends');
        $this->assertContains('X-Title: Dwemer Dynamics', $e['headers']);
        $this->assertContains('Content-Type: application/json', $e['headers']);

        $e2 = RelDynReplay::resolveEndpoint($this->db(), null, 'GPT-5-MINI', $this->engine());
        $this->assertSame([2, 'openaijson'], [$e2['id'], $e2['driver']], 'the model matches case-insensitively');
        $this->assertContains('Authorization: Bearer ' . self::KEY_OAI, $e2['headers']);
        $this->assertNotContains('X-Title: Dwemer Dynamics', $e2['headers'], "another driver's headers");
    }

    public function testAConnectorCanBePickedByIdAndAnAmbiguityIsNamed(): void
    {
        $e = RelDynReplay::resolveEndpoint($this->db(), 2, 'deepseek/deepseek-v4-flash', $this->engine());
        $this->assertSame(2, $e['id'], '--connector wins over the model match');
        try {
            RelDynReplay::resolveEndpoint($this->db(), null, 'some/other-model', $this->engine());
            $this->fail('two connectors, none the capture\'s model: it must ask');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('pass --connector=ID', $ex->getMessage());
            $this->assertStringContainsString('#1 Main (openrouterjson / deepseek/deepseek-v4-flash)', $ex->getMessage());
            $this->assertStringContainsString('#2 Backup (openaijson / gpt-5-mini)', $ex->getMessage());
            $this->assertStringNotContainsString(self::KEY_OR, $ex->getMessage());
        }
        try {
            RelDynReplay::resolveEndpoint($this->db(), 99, 'x', $this->engine());
            $this->fail('no such row');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('no core_llm_connector row 99', $ex->getMessage());
        }
        // one connector only: that one, whatever the capture's model was
        pg_query($this->link, 'DELETE FROM core_llm_connector WHERE id = 2');
        $this->assertSame(1, RelDynReplay::resolveEndpoint($this->db(), null, 'some/other-model', $this->engine())['id']);
        pg_query($this->link, "UPDATE core_llm_connector SET url = '' WHERE id = 1");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no usable url');
        RelDynReplay::resolveEndpoint($this->db(), 1, 'x', $this->engine());
    }

    /** The whole command: the captured request goes to the configured connector; the key stays out of every output. */
    public function testReplayThroughTheConfiguredConnectorEndToEnd(): void
    {
        $sent = [];
        $out = '';
        $saved = tempnam(sys_get_temp_dir(), 'rdreplayout') ?: $this->fail('temp');
        $this->tmp[] = $saved;
        $code = RelDynReplay::main(['replay_prompt.php', 'replay', '1', '--log=' . self::SAMPLE, '--model', 'x-ai/grok-4.3', '--without=felt', "--out={$saved}"],
            function (string $s) use (&$out): void { $out .= $s; }, $this->stub($sent), $this->db());
        $this->assertSame(0, $code, $out);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $sent['url'], 'the connector of the captured model');
        $this->assertContains('Authorization: Bearer ' . self::KEY_OR, $sent['headers']);
        $this->assertSame('x-ai/grok-4.3', $sent['json']['model']);
        $this->assertStringNotContainsString('<felt>', $sent['json']['messages'][0]['content']);
        $this->assertStringContainsString('<knowledge_of_player>', $sent['json']['messages'][0]['content']);
        $this->assertSame(RelDynReplay::pick(RelDynReplay::loadLog(self::SAMPLE), 1)['payload']['messages'][1], $sent['json']['messages'][1]);
        $this->assertStringContainsString('model: x-ai/grok-4.3 (captured: deepseek/deepseek-v4-flash)', $out);
        $this->assertStringContainsString('message: Aye.', $out);
        $this->assertStringContainsString('connector #1 Main (openrouterjson) openrouter.ai', $out);
        $this->assertStringContainsString("saved {$saved}", $out);
        $this->assertStringNotContainsString(self::KEY_OR, $out);
        $this->assertStringNotContainsString(self::KEY_OR, (string) file_get_contents($saved), 'nor in the saved result');
        $this->assertSame('Aye.', json_decode((string) file_get_contents($saved), true)['fields']['message']);
    }

    /** Nothing here writes: the adapter refuses a write, and the replay only ever SELECTs. */
    public function testTheReplayOnlyReadsTheDatabase(): void
    {
        $before = pg_fetch_row(pg_query($this->link, 'SELECT (SELECT count(*) FROM core_llm_connector), (SELECT count(*) FROM core_api_badge), (SELECT md5(string_agg(api_key, \',\')) FROM core_api_badge)'));
        $sent = [];
        $out = '';
        RelDynReplay::main(['replay_prompt.php', 'replay', '2', '--log=' . self::SAMPLE], function (string $s) use (&$out): void {}, $this->stub($sent), $this->db());
        $after = pg_fetch_row(pg_query($this->link, 'SELECT (SELECT count(*) FROM core_llm_connector), (SELECT count(*) FROM core_api_badge), (SELECT md5(string_agg(api_key, \',\')) FROM core_api_badge)'));
        $this->assertSame($before, $after);
        $db = $this->db();
        foreach (['INSERT INTO core_api_badge VALUES (9, \'x\', \'y\')', 'UPDATE core_api_badge SET api_key = \'z\'', 'DELETE FROM core_api_badge', 'DROP TABLE core_api_badge'] as $write) {
            try {
                $db->fetchAll($write);
                $this->fail("the adapter ran: {$write}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('read-only adapter refused', $e->getMessage());
            }
        }
        $this->assertSame(2, intval(pg_fetch_row(pg_query($this->link, 'SELECT count(*) FROM core_api_badge'))[0]));
    }
}
