<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/tools/readonly_pg.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/tools/test_pipeline_live.php';

/**
 * replay-integration-testing, the seam check (ext/relationship_dynamics/tools/test_pipeline_live.php, the
 * script the April test_pipeline_live.php covered): PLAYER_NAME, core's affinity against RelDyn's mirror,
 * the context tier, PHP fatals in the log, the eval worker, the connector, the context log, markDiary,
 * _relIsPlayer. It reads the live server read-only; here it reads a throwaway database through the same
 * read-only adapter (a SELECT that the adapter refuses would fail these tests), core 3.4.1-shaped tables,
 * the test beds' bonds (Aela a friend, Muiri an acquaintance gone sour, Lynly a stranger: three different
 * tiers), logs written to a temp directory. No LLM, no network (the TCP connect is a stub).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynPipelineCheckPostgresTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../../ext/relationship_dynamics/tools/fixtures/context_sent_to_llm.sample.log';

    private string $dsn;
    private string $schema;
    private $link;
    private string $logDir;
    private array $dirs = [];
    private $lockLink = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_pipe_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($this->link, "CREATE SCHEMA {$this->schema}");
        pg_query($this->link, "SET search_path TO {$this->schema}");
        $columns = "npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text";
        pg_query($this->link, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, {$columns})");
        pg_query($this->link, 'CREATE TABLE core_player (id text PRIMARY KEY, value text)');
        pg_query($this->link, 'CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))');
        pg_query($this->link, 'CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)');
        pg_query($this->link, 'CREATE TABLE core_llm_connector (id integer NOT NULL, label text, metadata jsonb, url text, model text,
            provider text, driver text, reasoning_model integer, max_tokens integer, enforce_json integer DEFAULT 1,
            prefill_json integer DEFAULT 0, api_badge_id integer, json_schema integer, temperature numeric,
            presence_penalty numeric, frequency_penalty numeric, repetition_penalty numeric, top_p numeric, top_k integer,
            min_p numeric, top_a numeric, service text, PRIMARY KEY (id))');
        $this->logDir = $this->tempDir();
        $this->seedHealthy();
    }

    protected function tearDown(): void
    {
        if ($this->lockLink) pg_close($this->lockLink);
        foreach ($this->dirs as $d) self::rrmdir($d);
        if (!isset($this->schema)) return;
        pg_close($this->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function tempDir(): string
    {
        $d = sys_get_temp_dir() . '/rdpipe_' . bin2hex(random_bytes(4));
        mkdir($d, 0777, true);
        $this->dirs[] = $d;
        return $d;
    }

    private static function rrmdir(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) continue;
            is_dir($f) ? self::rrmdir($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function db(): RelDynReadOnlyPg
    {
        return new RelDynReadOnlyPg($this->dsn . " options='-c search_path=" . $this->schema . "'");
    }

    private function check(array $opts = [], ?string $engine = null): array
    {
        $opts += ['log_dir' => $this->logDir, 'tcp' => fn() => true];
        $rows = (new RelDynPipelineCheck($this->db(), $engine ?? (realpath(__DIR__ . '/../../') . '/'), $opts))->run();
        $by = [];
        foreach ($rows as $r) $by[$r['id']] = $r;
        return $by;
    }

    /** A dynamics blob as prerequest leaves it: core's affinity mirrored in (x = (aff + 100) / 2), a context tier mark. */
    private function npc(string $name, float $coreAff, ?float $mirrorAff = null, int $hwm = 0, bool $coreEntry = true): void
    {
        $mirrorAff = $mirrorAff ?? $coreAff;
        $x = ($mirrorAff + 100.0) / 2.0;
        $dyn = ['_aff_mirror_x' => $x, 'dimensions' => ['affinity' => ['x' => $x, 'baseline' => 50.0]], 'context_tier_hwm' => $hwm];
        pg_query_params($this->link, 'INSERT INTO core_npc_master (npc_name, gender, race, extended_data, plugin_extended_data) VALUES ($1, $2, $3, $4::jsonb, $5::jsonb)',
            [$name, 'female', 'NordRace', json_encode(['relationships' => $coreEntry ? ['Player' => ['aff' => $coreAff, 'type' => 'neutral']] : []]),
                json_encode(['reldyn' => ['dynamics' => $dyn]])]);
    }

    private function seedHealthy(): void
    {
        pg_query($this->link, "INSERT INTO core_player (id, value) VALUES ('player_name', 'Kaida')");
        pg_query_params($this->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts) VALUES ('infoplayer', $1, 'pending', 1000, 1727100000, 1000)",
            ['level:12,name:"Kaida",race:"Nord",gender:"Male"']);
        // the beds: a friend (tier 2), an acquaintance (tier 1), a stranger (tier 0)
        $this->npc('Aela the Huntress', 45.0, null, 2);
        $this->npc('Muiri', 25.0, null, 1);
        $this->npc('Lynly Star-Sung', 0.0, null, 0);
        pg_query($this->link, "INSERT INTO core_llm_connector (id, label, url, model, driver) VALUES
            (1, 'Main', 'https://openrouter.ai/api/v1/chat/completions', 'deepseek/deepseek-v4-flash', 'openrouterjson')");
        copy(self::SAMPLE, $this->logDir . '/context_sent_to_llm.log');
        file_put_contents($this->logDir . '/apache_error.log', "[Wed Sep 30 18:00:00 2026] [php:notice] some notice\n");
        // production's eval queue (RelDynEval::ensureQueueTable)
        pg_query($this->link, "CREATE TABLE reldyn_eval_queue (id bigserial PRIMARY KEY, npc_id integer NOT NULL, npc_name text NOT NULL,
            job jsonb NOT NULL, status text NOT NULL DEFAULT 'pending', attempts integer NOT NULL DEFAULT 0, last_error text)");
    }

    // ------------------------------------------------------------------ a healthy server

    public function testAHealthyServerPassesEveryCheck(): void
    {
        $r = $this->check();
        $this->assertSame(['player_name', 'rel_is_player', 'affinity_mirror', 'context_tier', 'php_fatals', 'eval_worker', 'connector', 'context_log', 'mark_diary'], array_keys($r));
        foreach ($r as $id => $row) $this->assertSame('PASS', $row['status'], "{$id}: {$row['detail']} " . implode(' | ', $row['items']));
        $this->assertSame(0, RelDynPipelineCheck::exitCode(array_values($r)));
        $this->assertStringContainsString("PLAYER_NAME is 'Kaida', the name the plugin sends", $r['player_name']['detail']);
        $this->assertStringContainsString('3 NPCs', $r['affinity_mirror']['detail']);
        $this->assertStringContainsString('newest request 2026-09-30T18:07:03+02:00: deepseek/deepseek-v4-flash, 4 messages, speaker Lynly Star-Sung', $r['context_log']['detail']);
        $this->assertStringContainsString('1 connector host(s) answer a TCP connect (nothing was sent)', $r['connector']['detail']);
        $this->assertStringContainsString('call site(s), one argument each', $r['mark_diary']['detail']);
        $text = RelDynPipelineCheck::format(array_values($r));
        $this->assertStringContainsString('9 pass, 0 warn, 0 fail, 0 skipped', $text);
    }

    /** Three bonds, three tiers: the report tells them apart. */
    public function testTheTestBedsReadAsThreeDifferentTiers(): void
    {
        $items = $this->check()['context_tier']['items'];
        $this->assertCount(3, $items);
        $by = [];
        foreach ($items as $line) $by[trim(substr($line, 0, 28))] = $line;
        $this->assertStringContainsString('core   45.00  mirror   45.00  tier 2 (supports 2, high-water 2)', $by['Aela the Huntress']);
        $this->assertStringContainsString('core   25.00  mirror   25.00  tier 1 (supports 1, high-water 1)', $by['Muiri']);
        $this->assertStringContainsString('core    0.00  mirror    0.00  tier 0 (supports 0, high-water 0)', $by['Lynly Star-Sung']);
    }

    // ------------------------------------------------------------------ PLAYER_NAME and _relIsPlayer

    public function testPlayerNameFallsBackToConfAndFailsWhenItDoesNotResolveOrDisagrees(): void
    {
        pg_query($this->link, "DELETE FROM core_player WHERE id = 'player_name'");
        $this->assertSame('FAIL', $this->check()['player_name']['status'], 'core_player.player_name and conf_opts both empty');
        $this->assertStringContainsString('does not resolve', $this->check()['player_name']['detail']);
        $this->assertSame('SKIP', $this->check()['rel_is_player']['status'], '_relIsPlayer reads a PLAYER_NAME that does not resolve');
        pg_query($this->link, "INSERT INTO conf_opts (id, value) VALUES ('PLAYER_NAME', 'Kaida')");
        $this->assertSame('PASS', $this->check()['player_name']['status'], "chimLoadPlayerNameIntoGlobals' fallback");
        // the April bug: the name core resolves is not the name the plugin sends
        pg_query($this->link, "UPDATE conf_opts SET value = 'Prisoner' WHERE id = 'PLAYER_NAME'");
        $r = $this->check();
        $this->assertSame('FAIL', $r['player_name']['status']);
        $this->assertStringContainsString("PLAYER_NAME is 'Prisoner' but the plugin's own lines say 'Kaida'", $r['player_name']['detail']);
        $this->assertSame(1, RelDynPipelineCheck::exitCode(array_values($r)));
        // the game's start name, agreed by the plugin: only a warning
        pg_query($this->link, "UPDATE eventlog SET data = 'level:1,name:\"Prisoner\",race:\"Nord\"'");
        $this->assertSame('WARN', $this->check()['player_name']['status']);
        // no plugin line yet: nothing to compare with
        pg_query($this->link, 'DELETE FROM eventlog');
        pg_query($this->link, "UPDATE conf_opts SET value = 'Kaida' WHERE id = 'PLAYER_NAME'");
        $this->assertStringContainsString('no plugin line to compare it with yet', $this->check()['player_name']['detail']);
        // the placeholder RelDyn falls back to is not a name
        pg_query($this->link, "UPDATE conf_opts SET value = 'Player' WHERE id = 'PLAYER_NAME'");
        $this->assertSame('FAIL', $this->check()['player_name']['status']);
    }

    public function testRelIsPlayerIsReadFromCoresSource(): void
    {
        $this->assertSame('PASS', $this->check()['rel_is_player']['status'], "this checkout's ext/relationship_system/postrequest.php");
        $engine = $this->tempDir();
        $this->assertSame('SKIP', $this->check([], $engine . '/')['rel_is_player']['status'], 'no relationship_system installed');
        mkdir($engine . '/ext/relationship_system', 0777, true);
        file_put_contents($engine . '/ext/relationship_system/postrequest.php', "<?php\nfunction _relIsPlayer(\$name) {\n    return \$name === 'Player';\n}\n");
        $r = $this->check([], $engine . '/')['rel_is_player'];
        $this->assertSame('FAIL', $r['status']);
        $this->assertStringContainsString('no longer compares against PLAYER_NAME', $r['detail']);
        file_put_contents($engine . '/ext/relationship_system/postrequest.php', "<?php\n// nothing here\n");
        $this->assertStringContainsString('is not defined', $this->check([], $engine . '/')['rel_is_player']['detail']);
    }

    // ------------------------------------------------------------------ affinity mirror, context tier

    public function testADriftingMirrorIsNamedAndAnUnratchetedMarkToo(): void
    {
        // Muiri turned on him since her last request: core says -40, RelDyn still mirrors 25
        pg_query($this->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', '-40') WHERE npc_name = 'Muiri'");
        $r = $this->check()['affinity_mirror'];
        $this->assertSame('WARN', $r['status']);
        $this->assertStringContainsString('1 of 3 NPCs drift', $r['detail']);
        $this->assertSame(['Muiri: core -40.00, mirror 25.00 (+65.00)'], $r['items']);
        // Aela's high-water mark never ratcheted (updateContextTierHWM has not run since her affinity rose)
        pg_query($this->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics,context_tier_hwm}', '0') WHERE npc_name = 'Aela the Huntress'");
        $t = $this->check()['context_tier'];
        $this->assertSame('WARN', $t['status']);
        $this->assertStringContainsString('Aela the Huntress: high-water 0 below what their affinity supports (2)', $t['items'][0]);
        // a mirror with no core entry to match
        $this->npc('Hulda', 0.0, 12.0, 0, false);
        $this->assertStringContainsString('Hulda: mirror 12 with no core Player entry', implode("\n", $this->check()['affinity_mirror']['items']));
    }

    public function testNoRelDynStateIsSkippedNotFailed(): void
    {
        pg_query($this->link, "UPDATE core_npc_master SET plugin_extended_data = '{}'::jsonb");
        $r = $this->check();
        $this->assertSame(['SKIP', 'SKIP'], [$r['affinity_mirror']['status'], $r['context_tier']['status']]);
        pg_query($this->link, "UPDATE core_npc_master SET plugin_extended_data = '{\"reldyn\":{\"dynamics\":{\"interaction_count\":3}}}'::jsonb");
        $r = $this->check();
        $this->assertSame('WARN', $r['affinity_mirror']['status'], 'state without a mirror: prerequest has not mirrored core in');
        $this->assertSame('SKIP', $r['context_tier']['status']);
    }

    // ------------------------------------------------------------------ logs

    public function testPhpFatalsInTheLogTailsAreFoundAndRelDynsAreFails(): void
    {
        $this->assertSame('PASS', $this->check()['php_fatals']['status']);
        file_put_contents($this->logDir . '/chim.log', "PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /var/www/html/HerikaServer/lib/other.php:12\n");
        $r = $this->check()['php_fatals'];
        $this->assertSame('WARN', $r['status'], 'outside RelDyn');
        $this->assertStringContainsString('chim.log:', $r['items'][0]);
        file_put_contents($this->logDir . '/apache_error.log', "[Wed Sep 30 18:10:00 2026] [php:error] PHP Fatal error:  Uncaught ArgumentCountError: Too few arguments to function RelationshipDynamics::markDiaryCompleted() in /var/www/html/HerikaServer/ext/relationship_dynamics/postrequest.php:454\n", FILE_APPEND);
        $r = $this->check()['php_fatals'];
        $this->assertSame('FAIL', $r['status']);
        $this->assertStringContainsString('1 PHP fatal(s) in RelDyn code', $r['detail']);
        $this->assertSame(1, RelDynPipelineCheck::exitCode(array_values($this->check())));
        // only the tail is read: an old fatal long ago does not count
        file_put_contents($this->logDir . '/apache_error.log', "PHP Fatal error: in relationship_dynamics/old.php\n" . str_repeat("[php:notice] filler line of the log\n", 400));
        unlink($this->logDir . '/chim.log');
        $this->assertSame('PASS', $this->check(['tail_bytes' => 4096])['php_fatals']['status']);
        foreach (glob($this->logDir . '/*.log') as $f) unlink($f);
        $this->assertSame('SKIP', $this->check()['php_fatals']['status'], 'no server log to read');
    }

    public function testTheContextLogMustExistBeRecentAndRead(): void
    {
        $this->assertSame('PASS', $this->check()['context_log']['status']);
        $stale = $this->check(['now' => time() + 3 * 86400])['context_log'];
        $this->assertSame('WARN', $stale['status']);
        $this->assertStringContainsString('hours old', $stale['detail']);
        file_put_contents($this->logDir . '/context_sent_to_llm.log', "garbage\nnot a capture\n");
        $this->assertStringContainsString('no entry in its tail reads as a request', $this->check()['context_log']['detail']);
        unlink($this->logDir . '/context_sent_to_llm.log');
        $this->assertSame('FAIL', $this->check()['context_log']['status']);
    }

    // ------------------------------------------------------------------ eval worker, connector

    public function testTheEvalQueueIsReadForJobsWaitingWithoutADrainerAndDeadLetters(): void
    {
        $this->assertStringContainsString('queue: empty; drainer idle', $this->check()['eval_worker']['detail']);
        pg_query($this->link, "INSERT INTO reldyn_eval_queue (npc_id, npc_name, job) VALUES (1, 'Aela the Huntress', '{}'), (2, 'Muiri', '{}')");
        $r = $this->check()['eval_worker'];
        $this->assertSame('WARN', $r['status']);
        $this->assertStringContainsString('2 job(s) waiting and no drainer running', $r['detail']);
        // a worker holds the single-drainer advisory lock (RelDynEval LOCK_CLASS, LOCK_DRAINER)
        $this->lockLink = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query_params($this->lockLink, 'SELECT pg_advisory_lock($1, $2)', [RelDynEval::LOCK_CLASS, RelDynEval::LOCK_DRAINER]);
        $r = $this->check()['eval_worker'];
        $this->assertSame('PASS', $r['status']);
        $this->assertStringContainsString('2 pending; drainer running', $r['detail']);
        pg_close($this->lockLink);
        $this->lockLink = null;
        pg_query($this->link, "INSERT INTO reldyn_eval_queue (npc_id, npc_name, job, status, attempts, last_error) VALUES (3, 'Lynly Star-Sung', '{}', 'dead', 5, 'RelDynEval: the connector answered 404')");
        file_put_contents($this->logDir . '/reldyn_eval_worker.log', "[RelDyn-EVAL] worker done: {\"jobs\":4,\"failed\":1}\n");
        $r = $this->check()['eval_worker'];
        $this->assertSame('WARN', $r['status']);
        $this->assertStringContainsString('1 eval job(s) dead-lettered', $r['detail']);
        $this->assertStringContainsString('dead: Lynly Star-Sung after 5 attempts: RelDynEval: the connector answered 404', implode("\n", $r['items']));
        $this->assertStringContainsString('last worker run: [RelDyn-EVAL] worker done: {"jobs":4,"failed":1}', implode("\n", $r['items']));
        pg_query($this->link, 'DROP TABLE reldyn_eval_queue');
        $this->assertSame('SKIP', $this->check()['eval_worker']['status'], 'no eval job was ever queued');
    }

    public function testAnUnreachableConnectorIsNamedAndNothingIsSent(): void
    {
        $asked = [];
        $r = $this->check(['tcp' => function (string $host, int $port, int $timeout) use (&$asked): bool { $asked[] = [$host, $port, $timeout]; return false; }])['connector'];
        $this->assertSame('FAIL', $r['status']);
        $this->assertSame([['openrouter.ai', 443, 3]], $asked);
        $this->assertStringContainsString('#1 Main (openrouterjson / deepseek/deepseek-v4-flash) openrouter.ai:443 does not answer', $r['items'][0]);
        pg_query($this->link, "INSERT INTO core_llm_connector (id, label, url, model, driver) VALUES (2, 'Local', 'http://localhost:5001/v1/chat/completions', 'local', 'openaijson'), (3, 'Blank', NULL, 'x', 'openaijson')");
        $asked = [];
        $r = $this->check(['tcp' => function (string $host, int $port) use (&$asked): bool { $asked[] = "{$host}:{$port}"; return $host === 'localhost'; }])['connector'];
        $this->assertSame(['openrouter.ai:443', 'localhost:5001'], $asked, 'the scheme gives the port; a row with no url is not dialled');
        $this->assertStringContainsString('2 of 3 connectors do not answer', $r['detail']);
        $this->assertStringContainsString('#3 Blank (openaijson): no url', implode("\n", $r['items']));
        pg_query($this->link, 'DELETE FROM core_llm_connector');
        $this->assertSame('SKIP', $this->check()['connector']['status']);
    }

    // ------------------------------------------------------------------ markDiary

    public function testMarkDiaryCallSitesAreCountedByArgument(): void
    {
        $engine = $this->tempDir();
        mkdir($engine . '/ext/relationship_dynamics', 0777, true);
        file_put_contents($engine . '/ext/relationship_dynamics/postrequest.php', "<?php\nRelationshipDynamics::markDiaryCompleted(\$dynamics);\nRelationshipDynamics::markDiaryCompleted(\$d2);\n");
        $this->assertStringContainsString('2 call site(s), one argument each', $this->check([], $engine . '/')['mark_diary']['detail']);
        // the April bug: markDiaryCompleted($npcName, $dynamics) against a one-argument function
        file_put_contents($engine . '/ext/relationship_dynamics/bad.php', "<?php\nRelationshipDynamics::markDiaryCompleted(\$npcName, \$dynamics);\nRelationshipDynamics::markDiaryCompleted(foo(\$a, \$b));\n");
        $r = $this->check([], $engine . '/')['mark_diary'];
        $this->assertSame('FAIL', $r['status']);
        $this->assertSame(['bad.php: markDiaryCompleted($npcName, $dynamics)'], $r['items'], 'a comma inside a nested call is one argument');
    }

    /** The adapter would refuse any write the check tried; the database is left exactly as it was. */
    public function testTheCheckWritesNothing(): void
    {
        $snap = fn() => pg_fetch_row(pg_query($this->link, "SELECT (SELECT md5(string_agg(plugin_extended_data::text || extended_data::text, ',' ORDER BY id)) FROM core_npc_master),
            (SELECT count(*) FROM eventlog), (SELECT count(*) FROM conf_opts), (SELECT count(*) FROM reldyn_eval_queue)"));
        $before = $snap();
        $this->check();
        $this->assertSame($before, $snap());
    }
}
