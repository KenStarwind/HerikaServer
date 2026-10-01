<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * npc-npc-tiered-eval, end to end on a real PostgreSQL with the four test beds (Aela the Huntress,
 * Ashe (Serene's hand-set vector, never read, nothing of her story anywhere), Muiri (toxic), Lynly
 * Star-Sung (the shy bard)).
 *
 * RelDyn registers a facts source in conf_opts 'chim_character_facts_sources', the seam core's
 * NPC-to-NPC eval already reads (ext/relationship_system/relationship_llm.php
 * extensionCharacterFacts). The registration merges with other extensions' entries, idempotently,
 * from the install path and the settings switch. The facts are tiered by each NPC's own core
 * affinity toward the player: below 20 nothing, 20 to 50 temperament / attachment style / the top
 * trait keywords, above 50 the felt personality and the speech style too.
 *
 * Core's evaluateNpcToNpcContext runs for real, in a PHP process of its own (core reads the sources
 * row once per process, as the relationship worker does), with the connector boundary stubbed: the
 * test reads the prompt core WOULD have sent. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynNpcFactsTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const ID = RelDynNpcFacts::CONF_ID;
    private const AELA_SPEECH = 'Plain-spoken and blunt; says little and means all of it.';
    private const LYNLY_SPEECH = 'Lilting and sing-song, fond of a rhyme and a hush.';

    private RelDynNetworkBedsKit $kit;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->kit)) $this->kit->destroy();
    }

    private function world(array $playerAff = [self::AELA => 70, self::ASHE => 45, self::MUIRI => 5, self::LYNLY => 35, self::FARKAS => 10], array $config = []): RelDynNetworkBedsKit
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'facts', $config);
        $rels = [];
        foreach ($playerAff as $npc => $aff) $rels[$npc] = ['Player' => [$aff, 'friend']];
        $this->kit->seed($rels, [self::AELA => self::AELA_SPEECH, self::LYNLY => self::LYNLY_SPEECH]);
        $this->kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        return $this->kit;
    }

    /** What another extension (Sharmat's shape: a table of its own, facts as SQL over it) already registered in the live row. */
    private function otherEntry(): array
    {
        return ['table' => 'nsfw_npc_data', 'name_column' => 'npc_name',
                'facts' => ['Sexual orientation' => "extended_data -> 'orientation'", 'Relationship preference' => "extended_data -> 'preference'"],
                'skip_values' => ['Sexual orientation' => ['unknown', 'none']]];
    }

    private function storeRow(array $list, bool $pretty = true): void
    {
        pg_query_params($this->kit->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [self::ID, json_encode($list, $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0)]);
    }

    private function rawRow(): ?string
    {
        $r = pg_fetch_assoc(pg_query_params($this->kit->db->link, 'SELECT value FROM conf_opts WHERE id = $1', [self::ID]));
        return $r ? $r['value'] : null;
    }

    private function row(): array
    {
        return json_decode((string) $this->rawRow(), true);
    }

    private function ours(array $row): array
    {
        return array_values(array_filter($row, fn($e) => ($e['owner'] ?? null) === RelDynNpcFacts::OWNER));
    }

    /** RELDYN_PROBE=1: print a scene's numbers (tuning aid, no effect on the test). */
    private function probe(string $label, $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "\n=== {$label}\n" . json_encode($data, JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------------------------ registration

    public function testRegistrationMergesWithAnotherExtensionsEntryAndIsIdempotent(): void
    {
        $this->world();
        $other = $this->otherEntry();
        $this->storeRow([$other]);
        $before = $this->rawRow();

        $this->assertSame('added', RelDynNpcFacts::register($this->kit->db));
        $row = $this->row();
        $this->assertCount(2, $row);
        $this->assertSame($other, $row[0], 'the other extension\'s entry is exactly as it was, first');
        $ours = $this->ours($row);
        $this->assertCount(1, $ours);
        $this->assertSame('core_npc_master', $ours[0]['table']);
        $this->assertSame('npc_name', $ours[0]['name_column']);
        $this->assertSame(['Temperament', 'Attachment style', 'Strongest traits', 'Personality', 'Speech'], array_keys($ours[0]['facts']));
        $this->assertNotSame($before, $this->rawRow());

        // again: one entry, nothing rewritten
        $written = $this->rawRow();
        $this->assertSame('unchanged', RelDynNpcFacts::register($this->kit->db));
        $this->assertSame($written, $this->rawRow(), 'the row is not even rewritten');
        $this->assertCount(2, $this->row());

        // the switch's numbers change: the settings save brings the registration in step (ours replaced in place,
        // never duplicated, the other's untouched) and a registration after it has nothing left to do
        RelationshipDynamics::saveConfig(array_merge(RelationshipDynamics::loadStoredConfig(), ['npc_npc_facts' => ['tier3_min' => 60]]));
        $this->assertStringContainsString('> 60 THEN', $this->ours($this->row())[0]['facts']['Personality']);
        $this->assertSame('unchanged', RelDynNpcFacts::register($this->kit->db));
        // (and with the row as it was before that save, the registration itself is what updates it)
        $this->storeRow(array_merge([$other], [RelDynNpcFacts::sourceEntry(array_replace(RelDynNpcFacts::configDefaults(), ['tier3_min' => 50]))]));
        $this->assertSame('updated', RelDynNpcFacts::register($this->kit->db));
        $row = $this->row();
        $this->assertCount(2, $row);
        $this->assertSame($other, $row[0]);
        $this->assertStringContainsString('> 60 THEN', $this->ours($row)[0]['facts']['Personality']);

        // removal takes only ours
        $this->assertSame('removed', RelDynNpcFacts::unregister($this->kit->db));
        $this->assertSame([$other], $this->row());
        $this->assertSame('absent', RelDynNpcFacts::unregister($this->kit->db));
        $this->assertSame([], $this->kit->db->failures);
    }

    public function testRegistrationCreatesTheRowWhenNoOneHasAndLeavesAnUnreadableRowAlone(): void
    {
        $this->world();
        $this->assertNull($this->rawRow());
        $this->assertSame('added', RelDynNpcFacts::register($this->kit->db));
        $this->assertCount(1, $this->row());
        pg_query($this->kit->db->link, "DELETE FROM conf_opts WHERE id = '" . self::ID . "'");

        // another extension's row that is not JSON: never overwritten
        pg_query_params($this->kit->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', [self::ID, '[{"table": "nsfw_npc_data", ']);
        $this->assertSame('refused', RelDynNpcFacts::register($this->kit->db));
        $this->assertSame('[{"table": "nsfw_npc_data", ', $this->rawRow());
        $this->assertSame('refused', RelDynNpcFacts::unregister($this->kit->db));
        $this->assertSame('[{"table": "nsfw_npc_data", ', $this->rawRow());
        $this->assertStringContainsString('is not valid JSON', $this->kit->errorLog());

        // an empty value (a row someone created and never filled) is an empty list
        pg_query_params($this->kit->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [self::ID, '']);
        $this->assertSame('added', RelDynNpcFacts::register($this->kit->db));
        $this->assertCount(1, $this->row());
        // a row keyed by extension (core reads either shape): ours joins under its own key
        $this->storeRow(['sharmat' => $this->otherEntry()]);
        $this->assertSame('added', RelDynNpcFacts::register($this->kit->db));
        $keyed = $this->row();
        $this->assertSame($this->otherEntry(), $keyed['sharmat']);
        $this->assertSame('core_npc_master', $keyed[RelDynNpcFacts::OWNER]['table']);
        $this->assertSame('unchanged', RelDynNpcFacts::register($this->kit->db));
        $this->assertSame('removed', RelDynNpcFacts::unregister($this->kit->db));
        $this->assertSame(['sharmat' => $this->otherEntry()], $this->row());
    }

    public function testTheSettingsSwitchRegistersAndUnregistersAndOtherSavesLeaveTheRowAlone(): void
    {
        $this->world();
        $other = $this->otherEntry();
        $this->storeRow([$other]);
        // the hub saves only what differs from the defaults; the switch off removes our entry
        $this->assertTrue(RelationshipDynamics::saveConfig(array_merge(RelationshipDynamics::loadStoredConfig(), ['npc_npc_facts' => ['enabled' => false]])));
        $this->assertSame([$other], $this->row(), 'switched off: only the other extension\'s entry is left');
        // an unrelated save does not touch the row
        $written = $this->rawRow();
        $this->assertTrue(RelationshipDynamics::saveConfig(array_merge(RelationshipDynamics::loadStoredConfig(), ['cascade_decay' => 0.25])));
        $this->assertSame($written, $this->rawRow());
        // switched on again: added back after it
        $this->assertTrue(RelationshipDynamics::saveConfig(array_merge(RelationshipDynamics::loadStoredConfig(), ['npc_npc_facts' => ['enabled' => true]])));
        $row = $this->row();
        $this->assertCount(2, $row);
        $this->assertSame($other, $row[0]);
        $this->assertCount(1, $this->ours($row));
        $this->assertSame([], $this->kit->db->failures);
    }

    public function testInstallRegistersEvenWhenTheConfigRowAlreadyExistsAndMergesWithAnotherExtension(): void
    {
        $this->world();
        $other = $this->otherEntry();
        $this->storeRow([$other]);
        // the config row exists (the world() built it): install.php used to stop right there
        $out = $this->runInstall();
        $this->assertStringContainsString('NPC-NPC eval facts source: added', $out);
        $this->assertStringContainsString('already exists', $out);
        $row = $this->row();
        $this->assertSame($other, $row[0]);
        $this->assertCount(1, $this->ours($row));
        // run again: nothing changes
        $written = $this->rawRow();
        $this->assertStringContainsString('NPC-NPC eval facts source: unchanged', $this->runInstall());
        $this->assertSame($written, $this->rawRow());
        // with the switch off (stored row), install removes it
        RelationshipDynamics::saveConfig(array_merge(RelationshipDynamics::loadStoredConfig(), ['npc_npc_facts' => ['enabled' => false]]));
        $this->assertSame([$other], $this->row());
        $this->assertStringContainsString('NPC-NPC eval facts source: absent', $this->runInstall());
        $this->assertSame([$other], $this->row());
    }

    /** install.php as the CLI runs it, in its own process, against this schema. */
    private function runInstall(): string
    {
        $harness = tempnam(sys_get_temp_dir(), 'rdinstall_') . '.php';
        file_put_contents($harness, self::childPrelude() . <<<'PHP'
$GLOBALS['chim_interaction_generation'] = 0;
$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = new RelDynFactsChildDb($argv[2], $argv[3]);
require $argv[1] . '/ext/relationship_dynamics/install.php';
PHP);
        try {
            [$code, $out, $err] = self::runPhp([$harness, dirname(__DIR__, 2), $this->kit->dsn, $this->kit->schema]);
        } finally {
            @unlink($harness);
        }
        $this->assertSame(0, $code, "install.php: {$err}\n{$out}");
        $this->assertStringNotContainsString('Fatal error', $err);
        return $out;
    }

    // ------------------------------------------------------------------ the words

    private function speakOnce(array $npcs): void
    {
        $t = RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0);
        foreach ($npcs as $i => $npc) $this->kit->turn($npc, 'Well met.', $t + 600 * $i, 'hello');
    }

    public function testEachBedsFactsAreItsOwnWordsWrittenAtItsTurn(): void
    {
        $this->world();
        $this->assertSame([], $this->kit->dynamics(self::AELA), 'nothing before her first turn');
        $this->speakOnce([self::AELA, self::ASHE, self::MUIRI, self::LYNLY]);
        $facts = [];
        foreach ([self::AELA, self::ASHE, self::MUIRI, self::LYNLY] as $npc) {
            $f = $this->kit->dynamics($npc)[RelDynNpcFacts::KEY] ?? null;
            $this->assertIsArray($f, $npc);
            foreach (['temperament', 'attachment', 'traits', 'personality'] as $k) {
                $this->assertIsString($f[$k] ?? null, "{$npc} {$k}: " . json_encode($f));
                $this->assertNotSame('', $f[$k]);
                $this->assertDoesNotMatchRegularExpression('/\d/', $f[$k], "{$npc} {$k}: words, never numbers");
            }
            $this->assertLessThanOrEqual(3, count(explode(', ', $f['traits'])), "{$npc}: the top three");
            $facts[$npc] = $f;
        }
        $this->probe('facts', $facts);
        // four women, four different readings
        $this->assertCount(4, array_unique(array_column($facts, 'traits')), 'four trait keyword sets');
        $this->assertCount(4, array_unique(array_column($facts, 'personality')), 'four personalities');
        $this->assertSame('fearful', $facts[self::MUIRI]['attachment'], 'the toxic bed attaches fearfully (the toxic corner reads fearful)');
        $this->assertNotSame($facts[self::MUIRI]['attachment'], $facts[self::AELA]['attachment']);
        // her state, not a copy of a table: the words follow the engine's own vector
        $d = $this->kit->dynamics(self::AELA);
        $this->assertEquals(RelDynNpcFacts::words($d), $f = $d[RelDynNpcFacts::KEY]);
        $this->assertSame($d['inferred_temperament'], $f['temperament']);
        $this->assertSame([], $this->kit->db->failures);
    }

    public function testTheWordsFollowTheSwitch(): void
    {
        $this->world([self::AELA => 70], ['npc_npc_facts' => ['enabled' => false]]);
        $this->speakOnce([self::AELA]);
        $this->assertArrayNotHasKey(RelDynNpcFacts::KEY, $this->kit->dynamics(self::AELA), 'switched off: nothing is kept');
    }

    // ------------------------------------------------------------------ the tiers, through core's real eval

    /** Core's evaluateNpcToNpcContext for $speaker -> $listener, in its own process, with the connector stubbed: the user prompt it built. */
    private function evalPrompt(string $speaker, string $listener): string
    {
        $harness = tempnam(sys_get_temp_dir(), 'rdfacts_') . '.php';
        file_put_contents($harness, self::childPrelude() . <<<'PHP'
[$self, $engine, $dsn, $schema, $speakerId, $listenerId] = $argv;
$GLOBALS['ENGINE_PATH'] = $engine . '/';
$GLOBALS['db'] = new RelDynFactsChildDb($dsn, $schema);
$GLOBALS['PLAYER_NAME'] = 'Kaida';
$GLOBALS['RELLLM_CONNECTOR'] = 5;
require_once $engine . '/lib/logger.php';
Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_facts_child.log');
require_once $engine . '/lib/relationship_manager.php';
require_once $engine . '/lib/core/npc_master.class.php';
require_once $engine . '/ext/relationship_system/relationship_llm.php';

final class RelDynFactsChildDriver
{
    public array $messages = [];
    public function fast_request($messages, $params, $context)
    {
        $this->messages = $messages;
        return '{"speaker": {"delta": 0, "reason": "nothing"}, "listener": {"delta": 0, "reason": "nothing"}}';
    }
}
$driver = new RelDynFactsChildDriver();
$llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
foreach (['db' => $GLOBALS['db'], 'driver' => $driver, 'modelName' => 'stub',
          'connector' => ['id' => 1, 'driver' => 'stub', 'model' => 'stub', 'label' => 'stub']] as $prop => $value) {
    $p = new ReflectionProperty(RelationshipLLM::class, $prop);
    $p->setAccessible(true);
    $p->setValue($llm, $value);
}
$result = $llm->evaluateNpcToNpcContext((int) $speakerId, (int) $listenerId, 'Well met. Did the hunt go well?', []);
echo json_encode(['ok' => $result['ok'] ?? null, 'messages' => $driver->messages]);
PHP);
        try {
            [$code, $out, $err] = self::runPhp([$harness, dirname(__DIR__, 2), $this->kit->dsn, $this->kit->schema,
                (string) $this->kit->id($speaker), (string) $this->kit->id($listener)]);
        } finally {
            @unlink($harness);
        }
        $this->assertSame(0, $code, "core eval child: {$err}\n{$out}");
        $this->assertStringNotContainsString('Fatal error', $err, $err);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, "child output: {$out}\n{$err}");
        $this->assertTrue($decoded['ok'], $out);
        $this->assertSame('system', $decoded['messages'][0]['role']);
        $this->assertSame('user', $decoded['messages'][1]['role']);
        return (string) $decoded['messages'][1]['content'];
    }

    /** The "Character facts for X" line of the prompt, '' when there is none. */
    private static function factsLine(string $prompt, string $npc): string
    {
        foreach (explode("\n", $prompt) as $line) {
            if (str_starts_with($line, "Character facts for {$npc} ")) return $line;
        }
        return '';
    }

    public function testCoresNpcNpcEvalIsToldWhoTheyAreInProportionToHowWellThePlayerKnowsThem(): void
    {
        $this->world();
        $this->storeRow([$this->otherEntry()]);   // another extension's source is in the row too
        $this->assertSame('added', RelDynNpcFacts::register($this->kit->db));
        $this->speakOnce([self::AELA, self::ASHE, self::MUIRI, self::LYNLY]);   // the words are written at each one's turn

        // Aela (70: close) and Lynly (35: acquaintance)
        $prompt = $this->evalPrompt(self::AELA, self::LYNLY);
        $aela = self::factsLine($prompt, self::AELA);
        $lynly = self::factsLine($prompt, self::LYNLY);
        $this->probe('prompt', $prompt);
        $this->assertNotSame('', $aela, $prompt);
        $this->assertNotSame('', $lynly, $prompt);
        foreach (['Temperament: ', 'Attachment style: ', 'Strongest traits: ', 'Personality: ', 'Speech: ' . self::AELA_SPEECH] as $needle) {
            $this->assertStringContainsString($needle, $aela, 'Aela, above 50: everything');
        }
        foreach (['Temperament: ', 'Attachment style: ', 'Strongest traits: '] as $needle) {
            $this->assertStringContainsString($needle, $lynly, 'Lynly, 20 to 50: temperament, attachment, traits');
        }
        foreach (['Personality: ', 'Speech: ', self::LYNLY_SPEECH] as $needle) {
            $this->assertStringNotContainsString($needle, $lynly, 'but not the felt personality or her speech yet');
        }
        $this->assertStringNotContainsString('Sexual orientation', $prompt, 'the other extension\'s source does not apply to these tables, and breaks nothing');
        $this->assertDoesNotMatchRegularExpression('/\d/', $aela . $lynly, 'words, never numbers');
        // the words are the ones RelDyn wrote at their turns
        $f = $this->kit->dynamics(self::AELA)[RelDynNpcFacts::KEY];
        $this->assertStringContainsString("Temperament: {$f['temperament']}", $aela);
        $this->assertStringContainsString("Personality: {$f['personality']}", $aela);

        // Muiri (5) and Farkas (10, never spoke to the player): strangers to the player, a bare prompt
        $bare = $this->evalPrompt(self::MUIRI, self::FARKAS);
        $this->assertStringNotContainsString('Character facts for', $bare, 'strangers: nothing');
        $this->assertStringNotContainsString('Temperament', $bare);
        // one stranger in a pair: only the other one's facts
        $mixed = $this->evalPrompt(self::AELA, self::MUIRI);
        $this->assertNotSame('', self::factsLine($mixed, self::AELA));
        $this->assertSame('', self::factsLine($mixed, self::MUIRI));
        $this->assertSame([], $this->kit->db->failures);
    }

    public function testTheTierEdgesAreExactlyTwentyAndFifty(): void
    {
        $this->world([self::AELA => 70, self::LYNLY => 19, self::FARKAS => 10]);
        $this->assertSame('added', RelDynNpcFacts::register($this->kit->db));
        $this->speakOnce([self::LYNLY]);
        $this->assertSame(19, $this->kit->coreAff(self::LYNLY), 'the turn changed nothing');
        $expect = [19 => [false, false], 20 => [true, false], 50 => [true, false], 51 => [true, true]];
        foreach ($expect as $aff => [$tier2, $tier3]) {
            $this->kit->setCoreAff(self::LYNLY, $aff);
            $prompt = $this->evalPrompt(self::LYNLY, self::FARKAS);
            $this->probe("aff {$aff}", [$prompt, $this->kit->core(self::LYNLY), array_keys($this->kit->dynamics(self::LYNLY))]);
            $line = self::factsLine($prompt, self::LYNLY);
            $this->assertSame($tier2, str_contains($line, 'Temperament: '), "affinity {$aff}: tier 2");
            $this->assertSame($tier3, str_contains($line, 'Personality: '), "affinity {$aff}: tier 3 personality");
            $this->assertSame($tier3, str_contains($line, 'Speech: '), "affinity {$aff}: tier 3 speech");
        }
        // an NPC with no entry for the player at all is a stranger (0)
        pg_query_params($this->kit->db->link, "UPDATE core_npc_master SET extended_data = extended_data #- '{relationships,Player}' WHERE npc_name = \$1", [self::LYNLY]);
        $this->assertSame('', self::factsLine($this->evalPrompt(self::LYNLY, self::FARKAS), self::LYNLY));
        // a tier moved by the switch's numbers
        $this->kit->setCoreAff(self::LYNLY, 35);
        RelationshipDynamics::saveConfig(array_merge(RelationshipDynamics::loadStoredConfig(), ['npc_npc_facts' => ['tier2_min' => 40, 'tier3_min' => 80]]));
        $this->assertSame('', self::factsLine($this->evalPrompt(self::LYNLY, self::FARKAS), self::LYNLY), '35 is under a tier 2 of 40');
        $this->kit->setCoreAff(self::LYNLY, 60);
        $line = self::factsLine($this->evalPrompt(self::LYNLY, self::FARKAS), self::LYNLY);
        $this->assertStringContainsString('Temperament: ', $line);
        $this->assertStringNotContainsString('Personality: ', $line, '60 is under a tier 3 of 80');
        $this->assertSame([], $this->kit->db->failures);
    }

    // ------------------------------------------------------------------ child processes

    private static function childPrelude(): string
    {
        return <<<'PHP'
<?php
final class RelDynFactsChildDb
{
    public $link;
    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }
    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { error_log('FetchOne failed ' . pg_last_error($this->link) . ' :: ' . substr((string) $q, 0, 200)); return []; }
        return pg_fetch_assoc($res) ?: [];
    }
    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }
    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q) { $r = @pg_query($this->link, $q); if (!$r) error_log('execQuery failed ' . pg_last_error($this->link)); return $r; }
    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        return @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
    }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

PHP;
    }

    private static function runPhp(array $args): array
    {
        $proc = proc_open(array_merge([PHP_BINARY, '-d', 'display_errors=stderr'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($proc)) throw new RuntimeException('Could not start PHP subprocess');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string) $out, (string) $err];
    }
}
