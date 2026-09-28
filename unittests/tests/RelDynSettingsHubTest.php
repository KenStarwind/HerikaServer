<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/**
 * Stand-in for $db holding core's conf_opts rows in memory: the SELECT the config store reads, the
 * parameterized upsert saveConfig writes, the settings hub's row check. Every statement is logged.
 */
final class RelDynSettingsHubRowDb
{
    public array $rows = [];
    public array $log = [];

    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        $this->log[] = $sql;
        if (stripos($sql, 'INSERT INTO conf_opts') !== false) {
            $this->rows[(string) $params[0]] = (string) $params[1];
            return ['id' => (string) $params[0]];
        }
        if (stripos($sql, 'FROM conf_opts') !== false) {
            $id = is_array($params) ? (string) $params[0]
                : (preg_match("/id = '([^']+)'/", $sql, $m) ? $m[1] : '');
            if (!isset($this->rows[$id])) return [];
            return stripos($sql, 'SELECT id') !== false ? ['id' => $id] : ['value' => $this->rows[$id]];
        }
        return [];
    }

    public function fetchAll($sql) { $this->log[] = (string) $sql; return []; }
    public function execQuery($sql) { $this->log[] = (string) $sql; return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }

    public function writes(): array
    {
        return array_values(array_filter($this->log, fn($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $q)));
    }

    public function row(): ?array
    {
        $v = $this->rows[RelationshipDynamics::CONFIG_ROW_ID] ?? null;
        return $v === null ? null : json_decode($v, true);
    }
}

/**
 * The settings hub's engine (roadmap settings-page, prompt-gating-admin): the form generated from
 * defaultConfig() covers every key, saves store only what differs from the defaults at the depth
 * each section's reader merges, a key resets on its own, and a POST without the session's CSRF token
 * (or cut short by max_input_vars) writes nothing.
 */
final class RelDynSettingsHubTest extends TestCase
{
    private array $savedGlobals = [];
    private RelDynSettingsHubRowDb $db;

    protected function setUp(): void
    {
        foreach (['db', '_SESSION'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        $this->db = new RelDynSettingsHubRowDb();
        $GLOBALS['db'] = $this->db;
        $_SESSION = [RelDynSettings::CSRF_KEY => 'tok-' . str_repeat('a', 60)];
        RelDynTraits::$assignmentOverride = null;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function storeRow(array $row): void
    {
        $this->db->rows[RelationshipDynamics::CONFIG_ROW_ID] = json_encode($row);
        RelationshipDynamics::clearConfigCache();
    }

    private static function code(string ...$path): string
    {
        return RelDynSettings::encodePath($path);
    }

    private function post(array $fields, array $extra = []): array
    {
        $f = [];
        foreach ($fields as $dotted => $value) $f[RelDynSettings::encodePath(explode('.', $dotted))] = $value;
        return array_merge(['csrf_token' => $_SESSION[RelDynSettings::CSRF_KEY] ?? '', 'f' => $f, '_complete' => '1'], $extra);
    }

    /** Leaf paths of $v (a list or a scalar is a leaf, a non-empty table is walked). */
    private static function leafPaths($v, array $path = []): array
    {
        if (is_array($v) && $v !== [] && !array_is_list($v)) {
            $out = [];
            foreach ($v as $k => $x) $out = array_merge($out, self::leafPaths($x, array_merge($path, [$k])));
            return $out;
        }
        return [$path];
    }

    /** A value of the same kind that differs from $v. */
    private static function mutate($v)
    {
        if (is_bool($v)) return !$v;
        if (is_int($v)) return $v + 3;
        if (is_float($v)) return $v + 0.375;
        if (is_string($v)) return $v . ' (edited)';
        if ($v === null) return 'edited';
        if (is_array($v) && array_is_list($v) && $v !== []) {
            $first = $v[0];
            return array_merge($v, [is_array($first) ? $first : self::mutate($first)]);
        }
        return ['edited' => 1];
    }

    // ------------------------------------------------------------------
    // The generated form
    // ------------------------------------------------------------------

    public function testEveryDefaultLeafIsExactlyOneField(): void
    {
        $defaults = RelationshipDynamics::defaultConfig();
        unset($defaults['config_schema']);
        $want = array_map(fn($p) => json_encode($p), self::leafPaths($defaults));
        $have = array_map(fn($f) => json_encode($f['path']), RelDynSettings::fields());
        sort($want);
        sort($have);
        $this->assertSame($want, $have, 'every key RelDyn has is on the page, once');
        $this->assertGreaterThan(1000, count($have));
        $this->assertArrayNotHasKey(self::code('config_schema'), RelDynSettings::fields(), 'the row stamp is not a setting');
        foreach (RelDynSettings::fields() as $code => $f) {
            $this->assertSame($f['default'], RelDynSettings::valueAt($defaults, $f['path']), $f['dotted']);
            $this->assertNotSame('', trim($f['label']), $f['dotted']);
        }
    }

    public function testEveryTopLevelKeyIsInOneGroupAndSwitchesAreCollected(): void
    {
        $groups = RelDynSettings::groupKeys();
        $this->assertSame(array_keys(RelDynSettings::GROUPS), array_keys($groups));
        $all = array_merge(...array_values($groups));
        $defaults = RelDynSettings::defaults();
        $this->assertEqualsCanonicalizing(array_keys($defaults), $all);
        $this->assertCount(count($all), array_unique($all), 'no key in two groups');
        $this->assertSame([], $groups['features'], 'the switches panel is built from the switches, not keys');
        // a key the grouping has never seen still gets a home
        $this->assertSame('other', RelDynSettings::groupOf('brand_new_subsystem'));
        $this->assertSame('conflict', RelDynSettings::groupOf('jealousy_brand_new_knob'));

        $switches = array_column(RelDynSettings::featureFields(), 'default', 'dotted');
        $this->assertArrayHasKey('social_masking_enabled', $switches);
        $this->assertFalse($switches['social_masking_enabled'], 'masking ships off (rulings 2026-09-25 §18 #9)');
        $this->assertArrayHasKey('prompt_gating.enabled', $switches, 'a section\'s own switch is a feature switch too');
        foreach (RelationshipDynamics::CONFIG_FORM_TOGGLES as $key) {
            if ($key === 'enabled' || str_ends_with($key, '_enabled')) $this->assertArrayHasKey($key, $switches);
        }
        foreach ($switches as $dotted => $default) $this->assertIsBool($default, $dotted);
    }

    public function testFieldKindsBoundsAndChoices(): void
    {
        $f = RelDynSettings::fields();
        $this->assertSame('bool', $f[self::code('social_masking_enabled')]['kind']);
        $this->assertSame('float', $f[self::code('base_passion_gain')]['kind']);
        $this->assertSame([0.1, 10.0], [$f[self::code('base_passion_gain')]['min'], $f[self::code('base_passion_gain')]['max']],
            'the old page\'s bounds still apply');
        $this->assertSame('int', $f[self::code('reunion_min_hours')]['kind']);
        $this->assertSame('enum', $f[self::code('diary_reflection_mode')]['kind']);
        $this->assertSame(['baseline', 'trajectory'], $f[self::code('diary_reflection_mode')]['options']);
        $this->assertSame(array_keys(RelationshipDynamics::RELATIONSHIP_TIERS), $f[self::code('prompt_gating', 'name_min_tier')]['options']);
        $this->assertSame('lines', $f[self::code('social_sensitivity_signals')]['kind']);
        $this->assertSame('numbers', $f[self::code('grievance_severity_mult')]['kind']);
        $this->assertSame('json', $f[self::code('affinity_modifiers')]['kind']);
        $this->assertSame('text', $f[self::code('prompt_gating', 'text', 'name_unknown')]['kind']);
        $this->assertSame('any', $f[self::code('reputation', 'fames', 'dragonborn', 'home')]['kind']);
    }

    public function testPathCodesRoundTripAndForgedCodesAreRejected(): void
    {
        foreach (RelDynSettings::fields() as $code => $field) {
            $this->assertSame($field['path'], RelDynSettings::decodePath((string) $code));
        }
        $this->assertSame(['prompt_gating'], RelDynSettings::decodePath(self::code('prompt_gating')), 'a section resets whole');
        foreach (['', 'garbage!', '../etc', str_repeat('A', 3000), self::code('no_such_key'), self::code('prompt_gating', 'nope'),
                     rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '='), rtrim(strtr(base64_encode('[]'), '+/', '-_'), '='),
                     rtrim(strtr(base64_encode('[["x"]]'), '+/', '-_'), '=')] as $bad) {
            $this->assertNull(RelDynSettings::decodePath($bad), $bad);
        }
        $weird = ['reputation', 'fames', 'thieves_guild', 'evidence', "stat:Thieves' Guild Quests Completed"];
        $this->assertSame($weird, RelDynSettings::decodePath(RelDynSettings::encodePath($weird)), 'keys with quotes and spaces survive');
    }

    public function testValuesParseByKindAndRoundTripThroughTheForm(): void
    {
        $f = RelDynSettings::fields();
        $p = fn(string $code, $raw) => RelDynSettings::parse($f[$code], $raw);
        $this->assertTrue($p(self::code('social_masking_enabled'), '1')['value']);
        $this->assertFalse($p(self::code('social_masking_enabled'), '')['value']);
        $this->assertFalse($p(self::code('social_masking_enabled'), ['', '0'])['value'], 'hidden twin then an unticked box');
        $this->assertSame(12, $p(self::code('reunion_min_hours'), ' 12 ')['value']);
        $this->assertFalse($p(self::code('reunion_min_hours'), '12.5')['ok']);
        $this->assertFalse($p(self::code('cascade_decay'), 'abc')['ok']);
        $this->assertFalse($p(self::code('cascade_decay'), 'INF')['ok']);
        $clamped = $p(self::code('base_passion_gain'), '99');
        $this->assertSame(10.0, $clamped['value']);
        $this->assertNotNull($clamped['note']);
        $this->assertFalse($p(self::code('diary_reflection_mode'), 'nonsense')['ok']);
        $this->assertSame(['trust', 'respect'], $p(self::code('social_sensitivity_signals'), "trust\r\n\r\n respect \n")['value']);
        $this->assertSame([1.0, 2.5], $p(self::code('grievance_severity_mult'), '1, 2.5')['value']);
        $this->assertFalse($p(self::code('grievance_severity_mult'), '1, x')['ok']);
        $this->assertFalse($p(self::code('affinity_modifiers'), '{broken')['ok']);
        $this->assertFalse($p(self::code('affinity_modifiers'), '"a string"')['ok']);
        $this->assertNull($p(self::code('reputation', 'fames', 'dragonborn', 'home'), '')['value']);
        $this->assertSame('Whiterun Hold', $p(self::code('reputation', 'fames', 'dragonborn', 'home'), 'Whiterun Hold')['value']);
        $this->assertSame("a\nb", $p(self::code('prompt_gating', 'text', 'name_unknown'), "a\r\nb")['value']);

        // every default survives formValue -> parse unchanged (floats in their exact shortest form)
        foreach ($f as $code => $field) {
            $back = RelDynSettings::parse($field, RelDynSettings::formValue($field, $field['default']));
            $this->assertTrue($back['ok'], $field['dotted']);
            $this->assertTrue(RelDynSettings::valuesEqual($field['default'], $back['value']), $field['dotted'] . ' round trip');
            $this->assertNull($back['note'], $field['dotted'] . ' default within its bounds');
        }
        $this->assertSame('0.014285714285714285', RelDynSettings::numberText(1 / 70));
    }

    // ------------------------------------------------------------------
    // Storage depth: the readers see a partial row as the whole section
    // ------------------------------------------------------------------

    public function testMergeNodesSitInSectionsWithReaders(): void
    {
        $defaults = RelDynSettings::defaults();
        $readers = RelDynSettings::readers();
        foreach ($readers as $key => $reader) {
            $this->assertArrayHasKey($key, $defaults);
            $this->assertTrue(is_callable($reader), $key);
        }
        foreach (array_merge(RelDynSettings::keysNodes(), RelDynSettings::deepNodes()) as $node) {
            $path = explode('.', $node);
            $this->assertArrayHasKey($path[0], $readers, "{$node}: only a reader's section may be stored in part");
            if (end($path) === '*') array_pop($path);
            $v = RelDynSettings::valueAt($defaults, $path, $found);
            $this->assertTrue($found && RelDynSettings::isAssoc($v), "{$node} is a table in the defaults");
        }
        $this->assertFalse(RelDynSettings::isKeysNode(['neglect_bond_types']), 'a raw table is stored whole');
        $this->assertFalse(RelDynSettings::isKeysNode(['reputation', 'fames']));
        $this->assertTrue(RelDynSettings::isKeysNode(['protocols', array_key_first($defaults['protocols'])]));
        $this->assertTrue(RelDynSettings::isKeysNode(['felt_steering', 'text', 'knowledge']));
    }

    public function testReadersSeeThePartialRowAsTheWholeSection(): void
    {
        $defaults = RelDynSettings::defaults();
        $checked = 0;
        foreach (RelDynSettings::readers() as $key => $reader) {
            foreach (self::leafPaths($defaults[$key]) as $sub) {
                $section = $defaults[$key];
                $value = self::mutate(RelDynSettings::valueAt($section, $sub));
                $ref = &$section;
                foreach ($sub as $k) $ref = &$ref[$k];
                $ref = $value;
                unset($ref);
                $minimal = RelDynSettings::minimalDiff($defaults[$key], $section, null, [$key]);
                $where = $key . '.' . implode('.', $sub);
                $this->assertNotSame(RelDynSettings::NO_DIFF, $minimal, $where);

                $this->storeRow([$key => $minimal, 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
                $partial = call_user_func($reader);
                $this->storeRow([$key => $section, 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
                $whole = call_user_func($reader);
                $this->assertTrue(RelDynSettings::valuesEqual($whole, $partial), "{$where}: the reader reads the partial row differently");
                $this->assertTrue(RelDynSettings::valuesEqual(
                    RelDynSettings::overlay($defaults[$key], $minimal, [$key]), $section), "{$where}: overlay restores the section");
                $checked++;
            }
        }
        $this->assertGreaterThan(3000, $checked);
    }

    // ------------------------------------------------------------------
    // Saving and resetting
    // ------------------------------------------------------------------

    public function testSaveStoresOnlyTheDifferenceWithTheSchemaStamp(): void
    {
        $r = RelDynSettings::apply([[['prompt_gating', 'text', 'note', 'stranger'], '{NAME} has no idea who this is.']], []);
        $this->assertTrue($r['ok'] && $r['saved']);
        $this->assertSame(['prompt_gating' => ['text' => ['note' => ['stranger' => '{NAME} has no idea who this is.']]],
            'config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $this->db->row());
        $cfg = RelDynGating::config();
        $this->assertSame('{NAME} has no idea who this is.', $cfg['text']['note']['stranger']);
        $this->assertSame(RelDynGating::configDefaults()['text']['note']['renowned'], $cfg['text']['note']['renowned']);
        $this->assertSame(RelDynGating::configDefaults()['name_min_tier'], $cfg['name_min_tier']);

        // a table its reader takes whole is stored whole
        RelDynSettings::apply([[['neglect_bond_types', 'bonded', 'grace_game_days'], 9]], []);
        $row = $this->db->row();
        $want = RelationshipDynamics::defaultConfig()['neglect_bond_types'];
        $want['bonded']['grace_game_days'] = 9;
        $this->assertEquals($want, $row['neglect_bond_types'], 'saveConfig stores 1.0 as 1');
        // under a keys section, a whole child table (the fames) is stored whole, its siblings not at all
        RelDynSettings::apply([[['reputation', 'fames', 'companions', 'reach'], 3]], []);
        $row = $this->db->row();
        $this->assertSame(['fames'], array_keys($row['reputation']));
        $this->assertSame(3, $row['reputation']['fames']['companions']['reach']);
        $this->assertEquals(RelDynReputation::configDefaults()['fames']['college'], $row['reputation']['fames']['college']);
        $this->assertSame(3, RelDynReputation::config()['fames']['companions']['reach']);
        $this->assertArrayHasKey('prompt_gating', $row, 'other sections are left as they were');
    }

    public function testResetPerKeyAndPerSectionPrunesTheRow(): void
    {
        RelDynSettings::apply([
            [['prompt_gating', 'text', 'note', 'stranger'], 'x'],
            [['prompt_gating', 'token_budget'], 99],
            [['social_masking_enabled'], true],
        ], []);
        $this->assertSame(99, $this->db->row()['prompt_gating']['token_budget']);

        RelDynSettings::apply([], [['prompt_gating', 'token_budget']]);
        $row = $this->db->row();
        $this->assertSame(['text' => ['note' => ['stranger' => 'x']]], $row['prompt_gating']);
        $this->assertTrue($row['social_masking_enabled']);

        RelDynSettings::apply([], [['prompt_gating']]);
        $row = $this->db->row();
        $this->assertArrayNotHasKey('prompt_gating', $row, 'a section back at its defaults leaves the row');
        $this->assertSame(RelDynGating::configDefaults(), RelDynGating::config());

        RelDynSettings::apply([], [['social_masking_enabled']]);
        $this->assertSame(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $this->db->row());
        $this->assertFalse(RelationshipDynamics::getConfig()['social_masking_enabled']);

        $writes = count($this->db->writes());
        $r = RelDynSettings::apply([], [['social_masking_enabled']]);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['saved'], 'resetting a key already at its default writes nothing');
        $this->assertCount($writes, $this->db->writes());
    }

    public function testUntouchedKeysOfAnOldRowStayAsStored(): void
    {
        // the old page stored every form key, defaults included, plus a non-default knob
        $old = array_intersect_key(RelationshipDynamics::defaultConfig(), array_flip(array_merge(
            RelationshipDynamics::CONFIG_FORM_TOGGLES, array_keys(RelationshipDynamics::CONFIG_FORM_NUMBERS))));
        $old['base_passion_gain'] = 3.5;
        $old['config_schema'] = 3;
        $this->storeRow($old);
        RelDynSettings::apply([[['jealousy_max'], 150.0]], []);
        $row = $this->db->row();
        $this->assertEquals(150.0, $row['jealousy_max']);
        $this->assertSame(3.5, $row['base_passion_gain']);
        $this->assertArrayHasKey('passion_max', $row, 'an untouched key keeps its stored value');
        // touching a key that sits at its default takes it out of the row
        RelDynSettings::apply([], [['passion_max']]);
        $this->assertArrayNotHasKey('passion_max', $this->db->row());
        $this->assertSame(100.0, RelationshipDynamics::getConfig()['passion_max']);
    }

    public function testExtraKeysTheRowAlreadyHoldsSurviveAnEdit(): void
    {
        // a hand-added fame (a table the defaults do not have) is kept when another fame is edited
        $fames = RelDynReputation::configDefaults()['fames'];
        $fames['bards'] = ['evidence' => ['stat:Bards College Quests Completed' => [3, 1.0]], 'min_score' => 0.3,
                           'home' => 'Haafingar', 'reach' => 2, 'text' => '{NAME} has heard that {PLAYER} sings at the Bards College.'];
        $this->storeRow(['reputation' => ['fames' => $fames], 'config_schema' => 3]);
        RelDynSettings::apply([[['reputation', 'fames', 'college', 'reach'], 4]], []);
        $row = $this->db->row();
        $this->assertSame('Haafingar', $row['reputation']['fames']['bards']['home']);
        $this->assertSame(4, $row['reputation']['fames']['college']['reach']);
    }

    // ------------------------------------------------------------------
    // The POST handler
    // ------------------------------------------------------------------

    public function testPostWithoutTheSessionTokenWritesNothing(): void
    {
        foreach ([null, '', 'wrong', $_SESSION[RelDynSettings::CSRF_KEY] . 'x'] as $token) {
            $post = $this->post(['social_masking_enabled' => '1']);
            if ($token === null) unset($post['csrf_token']); else $post['csrf_token'] = $token;
            $r = RelDynSettings::handlePost($post);
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('Security check failed', $r['errors'][0]);
        }
        $_SESSION = [];
        $r = RelDynSettings::handlePost($this->post(['social_masking_enabled' => '1'], ['csrf_token' => '']));
        $this->assertFalse($r['ok'], 'no session token: nothing matches');
        $this->assertSame([], $this->db->writes());
        $this->assertNull($this->db->row());
    }

    public function testPostCutShortWritesNothing(): void
    {
        $post = $this->post(['social_masking_enabled' => '1']);
        unset($post['_complete']);
        $r = RelDynSettings::handlePost($post);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('incomplete', $r['errors'][0]);
        $this->assertSame([], $this->db->writes());
    }

    public function testPostSavesOnlyWhatChangedAndRefusesBadValues(): void
    {
        // every field of the prompt_gating section posted as shown, one changed
        $fields = [];
        foreach (RelDynSettings::fields() as $code => $f) {
            if ($f['path'][0] !== 'prompt_gating') continue;
            $fields[$f['dotted']] = RelDynSettings::formValue($f, $f['default']);
        }
        $fields['prompt_gating.fame_max_lines'] = '3';
        $r = RelDynSettings::handlePost($this->post($fields));
        $this->assertTrue($r['ok'] && $r['saved'], implode(' ', $r['errors']));
        $this->assertSame(['fame_max_lines' => 3], $this->db->row()['prompt_gating']);

        $writes = count($this->db->writes());
        $r = RelDynSettings::handlePost($this->post($fields));
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['saved']);
        $this->assertContains('No changes to save.', $r['messages']);
        $this->assertCount($writes, $this->db->writes());

        $r = RelDynSettings::handlePost($this->post(['prompt_gating.token_budget' => 'lots', 'prompt_gating.fame_max_lines' => '4']));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('prompt_gating.token_budget must be a number', implode(' ', $r['errors']));
        $this->assertSame(3, $this->db->row()['prompt_gating']['fame_max_lines'], 'one bad value: nothing saved');

        $r = RelDynSettings::handlePost(array_merge($this->post([]), ['f' => ['bm9wZQ' => '1']]));
        $this->assertFalse($r['ok'], 'a field code the defaults do not know');
    }

    public function testAStoredValueOutsideTheChoicesDoesNotBlockTheFieldsAroundIt(): void
    {
        // a row written by hand (or by an older RelDyn) with a mode today's choices do not have
        $this->storeRow(['diary_reflection_mode' => 'legacy_mode', 'base_passion_gain' => 50.0, 'config_schema' => 3]);
        $fields = RelDynSettings::fields();
        $display = RelDynSettings::effective();
        $shown = fn(string $key) => RelDynSettings::formValue($fields[self::code($key)], $display[$key]);
        $r = RelDynSettings::handlePost($this->post([
            'diary_reflection_mode' => $shown('diary_reflection_mode'),   // posted back as shown
            'base_passion_gain' => $shown('base_passion_gain'),           // outside today's bounds, as shown
            'diary_interaction_gap' => '20',
        ]));
        $this->assertTrue($r['ok'] && $r['saved'], implode(' ', $r['errors']));
        $row = $this->db->row();
        $this->assertSame('legacy_mode', $row['diary_reflection_mode'], 'kept as it was');
        $this->assertEquals(50.0, $row['base_passion_gain'], 'kept as it was, not clamped behind the user\'s back');
        $this->assertSame(20, $row['diary_interaction_gap']);
        // changing it does go through the choices
        $r = RelDynSettings::handlePost($this->post(['diary_reflection_mode' => 'other_legacy']));
        $this->assertFalse($r['ok']);
    }

    public function testPostResetTouchesOnlyThatKey(): void
    {
        RelDynSettings::apply([[['prompt_gating', 'fame_max_lines'], 5], [['prompt_gating', 'token_budget'], 99]], []);
        $r = RelDynSettings::handlePost($this->post(['prompt_gating.token_budget' => '7'],
            ['reset' => self::code('prompt_gating', 'fame_max_lines')]));
        $this->assertTrue($r['ok']);
        $this->assertSame(['token_budget' => 99], $this->db->row()['prompt_gating'], 'the reset only; the edit beside it is not saved');
        $this->assertStringContainsString('prompt_gating.token_budget', implode(' ', $r['messages']), 'and the page says so');
        $r = RelDynSettings::handlePost($this->post([], ['reset' => 'bogus']));
        $this->assertFalse($r['ok']);
    }

    public function testClampedValueIsSavedWithANote(): void
    {
        $r = RelDynSettings::handlePost($this->post(['base_passion_gain' => '99']));
        $this->assertTrue($r['ok']);
        $this->assertEquals(10.0, $this->db->row()['base_passion_gain']);
        $this->assertStringContainsString('kept within 0.1..10.0', implode(' ', $r['messages']));
    }
}
