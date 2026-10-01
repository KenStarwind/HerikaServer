<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php';

/** Records every query; answers nothing (a request that reaches the database is visible). */
final class RelDynNpcEditorStubDb
{
    public array $queries = [];
    public function fetchOne($q, array $params = []) { $this->queries[] = $q; return []; }
    public function fetchAll($q, $log = false) { $this->queries[] = $q; return []; }
    public function execQuery($q) { $this->queries[] = $q; return true; }
    public function query($q) { $this->queries[] = $q; return false; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * The per-NPC editor's request layer without a database (decisions 2026-09-23 §4): the CSRF token
 * (core's pattern), escaping, the spider graph, and that a POST without the session's token or a
 * non-GET/POST method never reaches the database. The round trips on the four test beds are in
 * RelDynNpcEditorTestBedsPostgresTest.
 */
final class RelDynNpcEditorTest extends TestCase
{
    private $savedDb;
    private RelDynNpcEditorStubDb $db;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->db = new RelDynNpcEditorStubDb();
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb === null) unset($GLOBALS['db']); else $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    public function testTheCsrfTokenIsPerSessionRandomAndComparedInConstantTime(): void
    {
        $a = [];
        $b = [];
        $ta = RelDynEditor::csrfToken($a);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ta);
        $this->assertSame($ta, RelDynEditor::csrfToken($a), 'stable within the session');
        $this->assertNotSame($ta, RelDynEditor::csrfToken($b), 'another session, another token');
        $this->assertTrue(RelDynEditor::csrfValid($a, $ta));
        foreach (['', null, [$ta], strtoupper($ta), substr($ta, 1), RelDynEditor::csrfToken($b)] as $bad) {
            $this->assertFalse(RelDynEditor::csrfValid($a, $bad), var_export($bad, true));
        }
        $this->assertFalse(RelDynEditor::csrfValid([], ''), 'no token in the session: nothing validates');
        $src = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php');
        $this->assertStringContainsString('hash_equals($token, $posted)', $src);
        $this->assertStringContainsString('bin2hex(random_bytes(32))', $src);
    }

    public function testAPostWithoutTheTokenNeverReachesTheDatabase(): void
    {
        $session = [];
        RelDynEditor::csrfToken($session);
        foreach ([
            ['npc' => 'Aela the Huntress', 'op' => 'reset_npc', 'confirm' => 'yes'],
            ['npc' => 'Aela the Huntress', 'op' => 'save', 'section' => 'dimensions', 'f' => ['dim:trust:x' => '1'], 'csrf_token' => 'forged'],
        ] as $post) {
            $r = RelDynEditor::handle('POST', [], $post, $session);
            $this->assertSame(403, $r['status']);
            $this->assertNull($r['location']);
            $this->assertStringContainsString('Security check failed', $r['body']);
        }
        $this->assertSame([], $this->db->queries);
        $this->assertArrayNotHasKey(RelDynEditor::FLASH_SESSION_KEY, $session);

        $r = RelDynEditor::handle('PUT', [], ['csrf_token' => RelDynEditor::csrfToken($session)], $session);
        $this->assertSame(405, $r['status']);
        $this->assertSame([], $this->db->queries);
    }

    public function testTheFlashIsShownOnceAndEscaped(): void
    {
        $session = [RelDynEditor::FLASH_SESSION_KEY => ['ok' => false, 'message' => 'Not saved: <img src=x onerror=alert(1)>']];
        $r = RelDynEditor::handle('GET', ['q' => '"><svg onload=alert(1)>'], [], $session);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Not saved: &lt;img src=x onerror=alert(1)&gt;', $r['body']);
        $this->assertStringNotContainsString('<img src=x', $r['body']);
        $this->assertStringNotContainsString('<svg onload', $r['body']);
        $this->assertStringContainsString('value="&quot;&gt;&lt;svg onload=alert(1)&gt;"', $r['body']);
        $this->assertArrayNotHasKey(RelDynEditor::FLASH_SESSION_KEY, $session, 'shown once');
        // the search reached the list query as an escaped literal, its LIKE wildcards literal
        $this->assertStringContainsString("ILIKE '%\"><svg onload=alert(1)>%' ESCAPE", $this->db->queries[0]);
        RelDynEditor::handle('GET', ['q' => "50%_o'k"], [], $session);
        $this->assertStringContainsString("ILIKE '%50\\%\\_o''k%' ESCAPE", end($this->db->queries));
    }

    public function testEscaping(): void
    {
        $this->assertSame('&lt;b&gt; &amp; &quot;x&quot; &#039;y&#039;', RelDynEditor::h('<b> & "x" \'y\''));
        $this->assertSame('yes', RelDynEditor::h(true));
        $this->assertSame('{&quot;a&quot;:&quot;&lt;i&gt;&quot;}', RelDynEditor::h(['a' => '<i>']));
        $this->assertSame('', RelDynEditor::h(null));
    }

    public function testTheSpiderGraphIsTheSharedInlineSvgChartNotACopyOfIt(): void
    {
        // one drawing for one pair on both pages (npc.php and player.php): RelDynUiCharts::fulfillmentSpider, whose
        // escaping, bounds and phone sizing RelDynUiChartsTest covers
        $editor = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php');
        $this->assertStringContainsString('RelDynUiCharts::fulfillmentSpider(', $editor);
        $this->assertStringNotContainsString('function spiderSvg', $editor, 'the editor draws no spider of its own');
        $axes = [
            ['axis' => 'nature', 'label' => 'the <wild>', 'need' => 0.9, 'coverage' => 0.5],
            ['axis' => 'combat', 'label' => 'a good fight', 'need' => 0.5, 'coverage' => -1.0],
            ['axis' => 'words', 'label' => 'kind words', 'need' => 2.0, 'coverage' => 3.0],
        ];
        $svg = RelDynUiCharts::fulfillmentSpider(['npc' => 'Muiri', 'axes' => $axes]);
        $this->assertStringContainsString('the &lt;wild&gt;', $svg);
        $this->assertStringNotContainsString('<wild>', $svg);
    }

    public function testThePageIsTheOnlyEntryAndWritesGoThroughTheEngine(): void
    {
        $dir = __DIR__ . '/../../ext/relationship_dynamics/';
        $editor = (string) file_get_contents($dir . 'reldyn_editor.php');
        $page = (string) file_get_contents($dir . 'npc.php');
        // no raw blob writes: every write is a getDynamics() copy saved with saveDynamics (CAS merge)
        $this->assertStringContainsString('RelationshipDynamics::saveDynamics($name, $d)', $editor);
        foreach (['RelDynStorage::', 'setKeyIfUnchanged', 'plugin_extended_data =', 'UPDATE ', 'INSERT ', 'DELETE ', '$_POST', '$_GET', '$_SESSION'] as $raw) {
            $this->assertStringNotContainsString($raw, $editor, $raw);
        }
        $this->assertStringContainsString('RelDynEditor::handle(', $page);
        $this->assertStringContainsString("include \$enginePath . 'ui/tmpl/navbar.php';", $page, 'core chrome');
        $this->assertStringContainsString('session_start()', $page);
        $this->assertLessThan(strpos($page, 'chimRuntimeBootstrapIfNeeded('), strpos($page, 'session_start()'), 'session before any output');
    }
}
