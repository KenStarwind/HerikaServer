<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/**
 * The RelDyn hub page (ext/relationship_dynamics/settings.php) run as CHIM runs it: php-cli, one
 * process per request, the 3.4.1 runtime bootstrap, core's head / navbar chrome, an in-memory $db
 * holding core's conf_opts rows and core_npc_master names. Asserts the HTML (every key on a form, the
 * CSRF token and the completeness sentinel in every POST form, forms under max_input_vars), escaping of
 * stored text and NPC names, CSRF rejection, and save / reset round trips through the stored row.
 */
final class RelDynSettingsHubPageTest extends TestCase
{
    private const TOKEN = 'page-test-token-0123456789abcdef0123456789abcdef0123456789abcdef';
    private const EVIL = '"><script>alert(1)</script>';
    private const EVIL_NPC = '<img src=x onerror=alert(2)>';

    private static function engineRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * One request against settings.php: ['html', 'stderr', 'code', 'row' (stored conf_opts row or null),
     * 'writes' (INSERT/UPDATE/DELETE statements), 'status' (http_response_code)].
     */
    private static function request(string $method, array $get = [], array $post = [], ?array $row = null,
                                    array $npcs = [], ?string $sessionToken = self::TOKEN): array
    {
        $spec = tempnam(sys_get_temp_dir(), 'rdhub_spec_');
        file_put_contents($spec, json_encode(['method' => $method, 'get' => $get, 'post' => $post, 'npcs' => $npcs,
            'row' => $row === null ? null : json_encode($row), 'token' => $sessionToken, 'sessdir' => sys_get_temp_dir()]));
        $harness = self::writeHarness();
        try {
            $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-d', 'log_errors=0',
                    $harness, self::engineRoot(), self::engineRoot() . '/ext/relationship_dynamics/settings.php', $spec];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::engineRoot());
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
        } finally {
            @unlink($harness);
            @unlink($spec);
        }
        $pos = strrpos($out, "\n__RELDYN_HUB_STATE__");
        $state = $pos === false ? [] : (json_decode(substr($out, $pos + strlen("\n__RELDYN_HUB_STATE__")), true) ?: []);
        $html = $pos === false ? $out : substr($out, 0, $pos);
        // core's navbar reads tables the in-memory $db does not hold: its notices are core's, not the page's
        $pageErr = implode("\n", array_filter(explode("\n", $err), fn($l) => trim($l) !== '' && strpos($l, 'ui/tmpl/navbar.php') === false));
        return ['html' => $html, 'stderr' => $pageErr, 'code' => $code, 'row' => isset($state['row']) ? json_decode($state['row'], true) : null,
                'writes' => $state['writes'] ?? [], 'status' => $state['status'] ?? null];
    }

    private static function writeHarness(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'rdhub_');
        @unlink($base);
        $path = $base . '.php';
        file_put_contents($path, <<<'PHP'
<?php
[$self, $engineRoot, $page, $specFile] = $argv;
$spec = json_decode(file_get_contents($specFile), true);
$GLOBALS['chim_interaction_generation'] = 0;

final class RelDynHubPageDb
{
    public array $rows = [];
    public array $npcs = [];
    public array $log = [];
    public function fetchOne($q, $p = null)
    {
        $q = (string) $q;
        $this->log[] = $q;
        if (stripos($q, 'INSERT INTO conf_opts') !== false) { $this->rows[(string) $p[0]] = (string) $p[1]; return ['id' => (string) $p[0]]; }
        if (stripos($q, 'FROM conf_opts') !== false) {
            $id = is_array($p) ? (string) $p[0] : (preg_match("/id = '([^']+)'/", $q, $m) ? $m[1] : '');
            if (!isset($this->rows[$id])) return [];
            return stripos($q, 'SELECT id') !== false ? ['id' => $id] : ['value' => $this->rows[$id]];
        }
        return [];
    }
    public function fetchAll($q)
    {
        $this->log[] = (string) $q;
        if (stripos((string) $q, 'SELECT npc_name FROM core_npc_master') !== false) return array_map(fn($n) => ['npc_name' => $n], $this->npcs);
        return [];
    }
    public function execQuery($q) { $this->log[] = (string) $q; return true; }
    public function query($q) { $this->log[] = (string) $q; return false; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
    public function close() {}
}

$db = new RelDynHubPageDb();
if ($spec['row'] !== null) $db->rows['relationship_dynamics_config'] = $spec['row'];
$db->npcs = $spec['npcs'];
$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = $db;
$GLOBALS['PLAYER_NAME'] = 'Kaida';
session_save_path($spec['sessdir']);
session_id('rdhub' . bin2hex(random_bytes(6)));
@session_start();
$_SESSION = [];
if ($spec['token'] !== null) $_SESSION['reldyn_csrf'] = $spec['token'];
$_SERVER['REQUEST_METHOD'] = $spec['method'];
$_SERVER['SCRIPT_NAME'] = '/HerikaServer/ext/relationship_dynamics/settings.php';
$_GET = $spec['get'];
$_POST = $spec['post'];

register_shutdown_function(function () use ($db) {
    @session_destroy();
    $writes = array_values(array_filter($db->log, fn($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $q)));
    echo "\n__RELDYN_HUB_STATE__" . json_encode(['row' => $db->rows['relationship_dynamics_config'] ?? null,
        'writes' => $writes, 'status' => http_response_code()]);
});

require $page;
PHP);
        return $path;
    }

    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    /** Field codes posted by the page's POST forms, and per form: vars, token, sentinel last. */
    private function postForms(string $html): array
    {
        $x = self::dom($html);
        $forms = [];
        foreach ($x->query('//form[@method="post"]') as $form) {
            $names = [];
            $last = null;
            foreach ($x->query('.//input|.//textarea|.//select', $form) as $el) {
                $names[] = $el->getAttribute('name');
                $last = $el;
            }
            $token = $x->query('.//input[@name="csrf_token"]', $form)->item(0);
            $firstButton = $x->query('.//button[@type="submit"]', $form)->item(0);
            $forms[] = ['names' => $names, 'token' => $token ? $token->getAttribute('value') : null,
                        'class' => $form->getAttribute('class'),
                        'first_button' => $firstButton ? $firstButton->getAttribute('name') . '=' . $firstButton->getAttribute('value') : null,
                        'last' => $last ? $last->getAttribute('name') . '=' . $last->getAttribute('value') : null,
                        'action' => $form->getAttribute('action')];
        }
        return $forms;
    }

    private static function code(string ...$path): string
    {
        return RelDynSettings::encodePath($path);
    }

    private function assertCleanRun(array $r, string $what): void
    {
        $this->assertSame(0, $r['code'], "{$what} exited {$r['code']}: {$r['stderr']}");
        $this->assertSame('', $r['stderr'], "{$what}: PHP notices from the page");
        $this->assertStringContainsString('</main>', $r['html'], "{$what} rendered to the end");
    }

    // ------------------------------------------------------------------

    public function testEveryGroupRendersEveryKeyInSafeForms(): void
    {
        $seen = [];
        $groups = array_keys(RelDynSettings::GROUPS);
        foreach ($groups as $group) {
            $keys = RelDynSettings::groupKeys()[$group];
            $get = ['tab' => 'settings', 'group' => $group];
            $r = self::request('GET', $get);
            $this->assertCleanRun($r, $group);
            $this->assertSame([], $r['writes'], "GET {$group} writes nothing");
            // big sections load on demand
            foreach ($keys as $key) {
                if (count(RelDynSettingsViewProbe::leaves($key)) > 400) {
                    $this->assertStringContainsString('open=' . $key, $r['html']);
                    $r2 = self::request('GET', $get + ['open' => $key]);
                    $this->assertCleanRun($r2, "{$group} open {$key}");
                    $r['html'] .= $r2['html'];
                }
            }
            foreach ($this->postForms($r['html']) as $form) {
                $this->assertSame(self::TOKEN, $form['token'], "{$group}: every POST form carries the session token");
                $this->assertSame('_complete=1', $form['last'], "{$group}: the sentinel is the form's last input");
                $this->assertLessThanOrEqual(RelDynSettings::MAX_FORM_VARS + 4, count($form['names']), "{$group}: under max_input_vars");
                $this->assertStringStartsWith('settings.php?', $form['action']);
                if ($form['class'] === 'rd-form') {
                    $this->assertSame('save=1', $form['first_button'], "{$group}: Enter in a field saves, it never resets the row above");
                }
                foreach ($form['names'] as $name) {
                    if (preg_match('/^f\[([A-Za-z0-9_-]+)\]$/', $name, $m)) $seen[$m[1]] = true;
                }
            }
        }
        $missing = array_diff(array_keys(RelDynSettings::fields()), array_keys($seen));
        $this->assertSame([], array_values(array_map(fn($c) => RelDynSettings::fields()[$c]['dotted'], $missing)),
            'every config key is editable on some group page');
    }

    public function testFeatureSwitchesShowDefaultsAndMaskingShipsOff(): void
    {
        $r = self::request('GET', ['tab' => 'settings']);
        $this->assertCleanRun($r, 'features');
        $x = self::dom($r['html']);
        $mask = self::code('social_masking_enabled');
        $box = $x->query('//input[@type="checkbox"][@name="f[' . $mask . ']"]')->item(0);
        $this->assertNotNull($box);
        $this->assertFalse($box->hasAttribute('checked'), 'masking is off by default');
        $hidden = $box->previousSibling;
        $this->assertSame('hidden', $hidden->getAttribute('type'), 'each checkbox follows its hidden "" twin');
        $this->assertSame('f[' . $mask . ']', $hidden->getAttribute('name'));
        $row = $x->query('//div[contains(concat(" ",@class," ")," rd-switch ")][.//input[@name="f[' . $mask . ']"]]')->item(0);
        $this->assertStringContainsString('rd-ships-off', $row->getAttribute('class'));
        $this->assertStringContainsString('ships off', $row->textContent);
        $this->assertStringContainsString('Default: off', $row->textContent);
        $this->assertSame(0, $x->query('//*[contains(@class,"rd-changed")]')->length, 'a fresh install has nothing changed');
        // the old settings page's bounds survive as input bounds
        $r = self::request('GET', ['tab' => 'settings', 'group' => 'passion']);
        $x = self::dom($r['html']);
        $bpg = $x->query('//input[@name="f[' . self::code('base_passion_gain') . ']"]')->item(0);
        $this->assertSame(['number', '0.1', '10.0', '2.0'], [$bpg->getAttribute('type'), $bpg->getAttribute('min'),
            $bpg->getAttribute('max'), $bpg->getAttribute('value')]);
    }

    public function testStoredTextAndNpcNamesAreEscaped(): void
    {
        $row = ['prompt_gating' => ['text' => ['note' => ['stranger' => self::EVIL]], 'name_min_tier' => self::EVIL],
                'diary_reflection_mode' => self::EVIL, 'config_schema' => 3];
        foreach ([['tab' => 'gating'], ['tab' => 'settings', 'group' => 'player'], ['tab' => 'settings', 'group' => 'inner'],
                     ['tab' => 'people'], ['tab' => 'gating', 'npc' => self::EVIL_NPC, 'aff' => '40']] as $get) {
            $r = self::request('GET', $get, [], $row, ['Aela the Huntress', self::EVIL_NPC]);
            $this->assertCleanRun($r, json_encode($get));
            $this->assertStringNotContainsString('<script>alert(1)', $r['html']);
            $this->assertStringNotContainsString('<img src=x', $r['html']);
        }
        $r = self::request('GET', ['tab' => 'gating'], [], $row);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $r['html'], 'shown, escaped');
        $x = self::dom($r['html']);
        $ta = $x->query('//textarea[@name="f[' . self::code('prompt_gating', 'text', 'note', 'stranger') . ']"]')->item(0)
            ?? $x->query('//input[@name="f[' . self::code('prompt_gating', 'text', 'note', 'stranger') . ']"]')->item(0);
        $this->assertSame(self::EVIL, $ta->nodeName === 'textarea' ? $ta->textContent : $ta->getAttribute('value'), 'the value round-trips intact');

        $r = self::request('GET', ['tab' => 'people'], [], null, ['Aela the Huntress', self::EVIL_NPC]);
        $x = self::dom($r['html']);
        $hrefs = [];
        foreach ($x->query('//ul[@id="rd-npc-list"]//a') as $a) $hrefs[$a->textContent] = $a->getAttribute('href');
        $this->assertSame('npc.php?npc=Aela%20the%20Huntress', $hrefs['Aela the Huntress']);
        $this->assertSame('npc.php?npc=' . rawurlencode(self::EVIL_NPC), $hrefs[self::EVIL_NPC]);
    }

    public function testPostWithoutTheSessionTokenIsRefused(): void
    {
        $f = ['f' => [self::code('social_masking_enabled') => '1'], '_complete' => '1', 'save' => '1'];
        foreach ([['csrf_token' => 'forged'] + $f, $f] as $post) {
            $r = self::request('POST', ['tab' => 'settings'], $post);
            $this->assertCleanRun($r, 'forged POST');
            $this->assertSame(403, $r['status']);
            $this->assertStringContainsString('Security check failed', $r['html']);
            $this->assertSame([], $r['writes']);
            $this->assertNull($r['row']);
        }
        // a session without a token (the page never shown) refuses any token
        $r = self::request('POST', ['tab' => 'settings'], ['csrf_token' => ''] + $f, null, [], null);
        $this->assertSame(403, $r['status']);
        $this->assertNull($r['row']);
        // a GET never writes, whatever it carries
        $r = self::request('GET', ['tab' => 'settings'] + $f + ['csrf_token' => self::TOKEN]);
        $this->assertSame([], $r['writes']);
    }

    /** What a browser posts from the form holding field $code, with nothing touched (a checkbox only when ticked, its hidden twin otherwise). */
    private function browserPost(string $html, string $code): array
    {
        $x = self::dom($html);
        $form = $x->query('//form[@method="post"][.//*[@name="f[' . $code . ']"]]')->item(0);
        $this->assertNotNull($form, 'the form holding ' . $code);
        $post = ['f' => []];
        foreach ($x->query('.//input|.//textarea|.//select', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '') continue;
            $type = strtolower($el->getAttribute('type') ?: 'text');
            if ($type === 'checkbox' && !$el->hasAttribute('checked')) continue;
            if ($el->nodeName === 'select') {
                $value = '';
                foreach ($x->query('.//option', $el) as $o) {
                    if ($value === '' || $o->hasAttribute('selected')) $value = $o->getAttribute('value');
                    if ($o->hasAttribute('selected')) break;
                }
            } elseif ($el->nodeName === 'textarea') {
                $value = $el->textContent;
            } else {
                $value = $el->getAttribute('value');
            }
            if (preg_match('/^f\[(.*)\]$/', $name, $m)) $post['f'][$m[1]] = $value; else $post[$name] = $value;
        }
        $post['save'] = '1';
        return $post;
    }

    public function testASaveFromAStalePageKeepsWhatAnotherPageChangedMeanwhile(): void
    {
        $mask = self::code('social_masking_enabled');
        $prompt = self::code('player_mirror', 'prompt', 'enabled');
        // the Switches page was loaded on a fresh install ...
        $r = self::request('GET', ['tab' => 'settings']);
        $this->assertCleanRun($r, 'features');
        $post = $this->browserPost($r['html'], $mask);
        $this->assertArrayHasKey('snap', $post, 'the form carries the values it showed');
        $this->assertSame('', $post['f'][$prompt], 'the prompt switch showed off');
        // ... then the Player profile page switched player_mirror.prompt on; now she ticks only masking and saves the stale form
        $post['f'][$mask] = '1';
        $post['csrf_token'] = self::TOKEN;
        $meanwhile = ['player_mirror' => ['prompt' => ['enabled' => true]], 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA];
        $r = self::request('POST', ['tab' => 'settings'], $post, $meanwhile);
        $this->assertCleanRun($r, 'stale save');
        $this->assertTrue($r['row']['social_masking_enabled'], 'her change is saved');
        $this->assertTrue($r['row']['player_mirror']['prompt']['enabled'], 'the profile page\'s switch is not reverted by a form that had not seen it');
        $this->assertStringContainsString('Saved 1 setting: social_masking_enabled.', $r['html']);
    }

    public function testTheSettingsPageShowsPlainWordLabelsAndHints(): void
    {
        $r = self::request('GET', ['tab' => 'settings']);
        $this->assertCleanRun($r, 'features');
        $x = self::dom($r['html']);
        $row = $x->query('//div[contains(@class,"rd-field")][.//input[@name="f[' . self::code('memory_translation', 'commit', 'enabled') . ']"]]')->item(0);
        $this->assertNotNull($row);
        $this->assertStringContainsString('Memory translation › Commit (switch)', $row->textContent);
        $this->assertStringContainsString('Ships off: it is a new write into a core table.', $row->textContent);
        // a section says what it is above its tables
        $r = self::request('GET', ['tab' => 'settings', 'group' => 'inner']);
        $this->assertStringContainsString('The want layer', $r['html']);
        $this->assertStringContainsString('class="rd-section-help"', $r['html']);
    }

    public function testSaveAndResetRoundTrip(): void
    {
        $mask = self::code('social_masking_enabled');
        $post = ['csrf_token' => self::TOKEN, 'f' => [$mask => '1', self::code('enabled') => '1'], 'save' => '1', '_complete' => '1'];
        $r = self::request('POST', ['tab' => 'settings'], $post);
        $this->assertCleanRun($r, 'save');
        $this->assertSame(['social_masking_enabled' => true, 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $r['row'],
            'only the change is stored, with the schema stamp');
        $this->assertStringContainsString('Saved 1 setting: social_masking_enabled.', $r['html']);
        $x = self::dom($r['html']);
        $box = $x->query('//input[@type="checkbox"][@name="f[' . $mask . ']"]')->item(0);
        $this->assertTrue($box->hasAttribute('checked'), 'the page shows what was saved');
        $reset = $x->query('//button[@name="reset"][@value="' . $mask . '"]')->item(0);
        $this->assertNotNull($reset, 'a changed key gets its reset');
        $this->assertStringContainsString('changed', $x->query('ancestor::div[contains(@class,"rd-field")][1]', $reset)->item(0)->textContent);

        // the stored row drives the next GET (a new request)
        $r = self::request('GET', ['tab' => 'settings'], [], $r['row']);
        $x = self::dom($r['html']);
        $this->assertTrue($x->query('//input[@type="checkbox"][@name="f[' . $mask . ']"]')->item(0)->hasAttribute('checked'));
        $this->assertStringContainsString('<span class="rd-pill-count">1</span>', $r['html']);

        $r = self::request('POST', ['tab' => 'settings'], ['csrf_token' => self::TOKEN, 'reset' => $mask, '_complete' => '1'],
            ['social_masking_enabled' => true, 'config_schema' => 3]);
        $this->assertCleanRun($r, 'reset');
        $this->assertSame(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $r['row']);
        $this->assertStringContainsString('social_masking_enabled is back to its default.', $r['html']);
        $x = self::dom($r['html']);
        $this->assertFalse($x->query('//input[@type="checkbox"][@name="f[' . $mask . ']"]')->item(0)->hasAttribute('checked'));

        // a nested gating fragment: stored as the one leaf, reset by its section
        $path = self::code('felt_steering', 'text', 'knowledge', 'stranger');
        $r = self::request('POST', ['tab' => 'gating'], ['csrf_token' => self::TOKEN, 'f' => [$path => 'To {NAME} this is nobody yet.'],
            '_complete' => '1']);
        $this->assertSame(['felt_steering' => ['text' => ['knowledge' => ['stranger' => 'To {NAME} this is nobody yet.']]],
            'config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $r['row']);
        $r = self::request('POST', ['tab' => 'settings', 'group' => 'steering'],
            ['csrf_token' => self::TOKEN, 'reset' => self::code('felt_steering'), '_complete' => '1'], $r['row']);
        $this->assertSame(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $r['row']);
    }

    public function testCutShortOrBadPostsSaveNothing(): void
    {
        $r = self::request('POST', ['tab' => 'settings'], ['csrf_token' => self::TOKEN, 'f' => [self::code('social_masking_enabled') => '1']]);
        $this->assertStringContainsString('arrived incomplete', $r['html']);
        $this->assertNull($r['row']);
        $r = self::request('POST', ['tab' => 'settings', 'group' => 'passion'], ['csrf_token' => self::TOKEN,
            'f' => [self::code('reunion_min_hours') => 'soon', self::code('passion_max') => '120'], '_complete' => '1']);
        $this->assertStringContainsString('reunion_min_hours must be a number', $r['html']);
        $this->assertNull($r['row']);
        $this->assertSame([], $r['writes']);
    }

    public function testGatingTabHasEveryFragmentAndAReadOnlyPreview(): void
    {
        $r = self::request('GET', ['tab' => 'gating', 'npc' => 'Aela the Huntress', 'aff' => '45'], [], null, ['Aela the Huntress']);
        $this->assertCleanRun($r, 'gating');
        $this->assertSame([], $r['writes'], 'the preview writes nothing');
        $x = self::dom($r['html']);
        $names = [];
        foreach ($x->query('//form[@method="post"]//*[@name]') as $el) $names[$el->getAttribute('name')] = true;
        $d = RelDynSettings::defaults();
        foreach (array_keys($d['felt_steering']['text']['knowledge']) as $k) {
            $this->assertArrayHasKey('f[' . self::code('felt_steering', 'text', 'knowledge', $k) . ']', $names, "tier fragment {$k}");
        }
        foreach (array_keys($d['reputation']['fames']) as $k) {
            $this->assertArrayHasKey('f[' . self::code('reputation', 'fames', $k, 'text') . ']', $names, "fame fragment {$k}");
            $this->assertArrayHasKey('f[' . self::code('reputation', 'fames', $k, 'reach') . ']', $names);
        }
        foreach (['enabled', 'name_min_tier', 'floor_tier', 'bio_min_tier', 'fame_max_tier', 'token_budget'] as $k) {
            $this->assertArrayHasKey('f[' . self::code('prompt_gating', $k) . ']', $names, $k);
        }
        foreach (array_keys($d['prompt_gating']['text']['note']) as $k) {
            $this->assertArrayHasKey('f[' . self::code('prompt_gating', 'text', 'note', $k) . ']', $names);
        }
        $this->assertSame(0, $x->query('//section[@id="rd-gating-tiers" or @id="rd-gating-fames"]//details[contains(@class,"rd-depth-1") or contains(@class,"rd-depth-2")][not(@open)]')->length,
            'the fragments are open for editing, not folded away');
        $tier = $x->query('//select[@name="f[' . self::code('prompt_gating', 'name_min_tier') . ']"]/option');
        $this->assertSame(array_keys(RelationshipDynamics::RELATIONSHIP_TIERS), array_map(fn($o) => $o->getAttribute('value'), iterator_to_array($tier)));
        // the preview: at friend affinity she knows him, by name
        $preview = $x->query('//div[@class="rd-preview"]')->item(0);
        $this->assertNotNull($preview);
        $this->assertStringContainsString('personal', $preview->textContent);
        $this->assertStringContainsString('Kaida', $x->query('.//pre', $preview)->item(0)->textContent);
        $this->assertSame(count(RelationshipDynamics::RELATIONSHIP_TIERS) + 1, $x->query('.//table[contains(@class,"rd-tiers")]//tr', $preview)->length);
        $this->assertSame('Aela the Huntress', $x->query('//datalist[@id="rd-npc-names"]/option')->item(0)->getAttribute('value'));
        // as a stranger she does not know the name, and the prompt is told not to use it
        $r = self::request('GET', ['tab' => 'gating', 'npc' => 'Aela the Huntress', 'aff' => '0']);
        $x = self::dom($r['html']);
        $pres = $x->query('//div[@class="rd-preview"]//pre');
        $this->assertStringNotContainsString('Kaida', $pres->item(0)->textContent);
        $this->assertStringContainsString('Never call them "Kaida"', $pres->item(2)->textContent);
    }

    public function testPeopleTabLinksToTheOtherPages(): void
    {
        $r = self::request('GET', ['tab' => 'people'], [], null, ['Aela the Huntress', 'Ashe', 'Muiri', 'Lynly Star-Sung', 'Hulda']);
        $this->assertCleanRun($r, 'people');
        $x = self::dom($r['html']);
        $this->assertSame(1, $x->query('//a[@href="player.php"]')->length);
        foreach (['Aela the Huntress', 'Ashe', 'Muiri', 'Lynly Star-Sung'] as $bed) {
            $this->assertGreaterThanOrEqual(1, $x->query('//ul[contains(@class,"rd-beds")]//a[@href="npc.php?npc=' . rawurlencode($bed) . '"]')->length, $bed);
        }
        $this->assertSame(5, $x->query('//ul[@id="rd-npc-list"]/li')->length);
        $form = $x->query('//form[@action="npc.php"]')->item(0);
        $this->assertSame('get', $form->getAttribute('method'));
        $this->assertSame('npc', $x->query('.//input[@type="text"]', $form)->item(0)->getAttribute('name'));
    }

    public function testManifestOpensTheHubFromServerPlugins(): void
    {
        $m = json_decode((string) file_get_contents(self::engineRoot() . '/ext/relationship_dynamics/manifest.json'), true);
        $this->assertSame('/HerikaServer/ext/relationship_dynamics/settings.php', $m['config_url']);
        $this->assertSame('_blank', $m['config_url_target']);
        preg_match('/^## reldyn-v(\d+)\.(\d+)/m', (string) file_get_contents(self::engineRoot() . '/ext/relationship_dynamics/CHANGELOG.md'), $v);
        $this->assertSame("{$v[1]}.{$v[2]}.0", $m['version'], 'the manifest carries the current RelDyn version');
        $this->assertLessThanOrEqual(10, strlen($m['version']), 'core\'s navbar rejects longer versions');
        $r = self::request('GET', ['tab' => 'settings']);
        $this->assertStringContainsString('<span class="rd-version">v' . $m['version'] . '</span>', $r['html']);
    }

    public function testEmbedModeDropsTheChromeAndKeepsItsLinks(): void
    {
        $r = self::request('GET', ['tab' => 'reference', 'embed' => '1']);
        $this->assertCleanRun($r, 'embed');
        $this->assertStringNotContainsString('<!DOCTYPE', $r['html']);
        $this->assertStringContainsString('href="settings.php?tab=settings&amp;embed=1"', $r['html']);
        $r = self::request('GET', ['tab' => 'reference']);
        $this->assertStringContainsString('<!DOCTYPE', $r['html']);
        $this->assertStringContainsString('<title>Relationship Dynamics</title>', $r['html']);
        // core's chrome (head.html's viewport, main.css, the navbar) and the page's phone layout
        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $r['html']);
        $this->assertStringContainsString('/HerikaServer/ui/css/main.css', $r['html']);
        $this->assertStringContainsString('@media (max-width: 640px)', $r['html']);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!cdn\.jsdelivr\.net|unpkg\.com|fonts\.)#', $r['html'],
            'no external assets beyond the ones core\'s head already loads');
    }
}

/** The leaves of one section (for the on-demand check). */
final class RelDynSettingsViewProbe
{
    public static function leaves(string $key): array
    {
        return array_filter(RelDynSettings::fields(), fn($f) => $f['path'][0] === $key);
    }
}
