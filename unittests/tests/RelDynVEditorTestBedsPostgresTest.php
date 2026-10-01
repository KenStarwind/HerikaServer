<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynVEditorPgDb
{
    public $link;
    public array $failures = [];
    public array $writes = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function note(string $q): void
    {
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $q)) $this->writes[] = substr(preg_replace('/\s+/', ' ', $q), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $this->note($q);
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
        $this->writes[] = "INSERT {$table}";
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * The per-NPC parameter editor, batch V (rulings 2026-10-01 §23, Ken): the NPC's own standing is an editor field now
 * (attraction section), writing attraction_overrides.standing and shown with its derived value; the thane / rank reporter comes
 * later. On the four test beds (Aela the Huntress, Ashe hand-set and never read, Muiri, Lynly Star-Sung), CHIM 3.4.1 core-shaped
 * rows, the committed trait-read seed; the real editor model, the real saves. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynVEditorTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    private const EVIL = '<b onmouseover="alert(1)">Eve & \'Co\'</b>';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];

    private string $dsn;
    private string $schema;
    private RelDynVEditorPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $llmCalls = 0;
    private int $launches = 0;
    private array $session = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_veditor_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        self::createSchema($admin);
        pg_close($admin);

        $this->db = new RelDynVEditorPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (170 * RelationshipDynamics::GAMETS_PER_DAY), ''];
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdveditor');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_veditor_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () { $this->launches++; };
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelationshipDynamics::clearConfigCache();
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** Production-shaped tables (lib/core/database_schema/core_npc_master.sql, data/database_default.sql). */
    private static function createSchema($admin): void
    {
        $columns = "npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text";
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, {$columns})");
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(), " . str_replace('npc_name text NOT NULL', 'npc_name text', $columns) . ")");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
    }

    /** The four beds (core rows, voice types, placeholder templates, the seed's reads; Ashe never read) plus two list-only rows. */
    private function seed(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace(RelationshipDynamics::defaultConfig(), ['internal_weather_enabled' => false]))]);
        RelationshipDynamics::clearConfigCache();
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $beds = self::BEDS + ['Test Wanderer' => ['test_wanderer', 'ImperialRace', 'Pilgrim', [], [], null]];
        foreach ($beds as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe' || $key === 'test_wanderer') continue;   // Ashe: hand-set, never read; the wanderer: no read yet
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, gender, race, extended_data) VALUES ($1, $2, $3, $4::jsonb)',
            [self::EVIL, 'female', 'NordRace', json_encode(['class' => ['name' => 'Citizen']])]);
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, gender, race) VALUES ($1, $2, $3)', ['Nazeem', 'male', 'RedguardRace']);
    }

    // ------------------------------------------------------------------ helpers

    private function get(array $get): array
    {
        return RelDynEditor::handle('GET', $get, [], $this->session);
    }

    private function post(array $post, ?string $token = null): array
    {
        $token = $token ?? RelDynEditor::csrfToken($this->session);
        return RelDynEditor::handle('POST', [], $post + ['csrf_token' => $token], $this->session);
    }

    private function stored(string $npc): ?array
    {
        return RelationshipDynamics::loadStoredDynamics($npc);
    }

    private function rawBlob(string $npc): ?string
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data::text AS p FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1', [$npc]));
        return $r['p'] ?? null;
    }

    /** Store the NPC's resolved state (what her first turn would store), through the engine's own save. */
    private function track(string $npc): array
    {
        RelationshipDynamics::beginRequest();
        try {
            RelDynTraitRead::stateFor($npc, false);
            $d = RelationshipDynamics::getDynamics($npc);
            $this->assertTrue(RelationshipDynamics::saveDynamics($npc, $d), "first save of {$npc}");
        } finally {
            RelationshipDynamics::endRequest();
        }
        return $this->stored($npc);
    }

    /** Change the stored state as a hook would (load, change, CAS save). */
    private function mutate(string $npc, callable $fn): void
    {
        RelationshipDynamics::beginRequest();
        try {
            RelDynTraitRead::stateFor($npc, false);
            $d = RelationshipDynamics::getDynamics($npc);
            $fn($d);
            $this->assertTrue(RelationshipDynamics::saveDynamics($npc, $d));
        } finally {
            RelationshipDynamics::endRequest();
        }
    }

    private function field(array $model, string $section, string $id): array
    {
        $this->assertArrayHasKey($id, $model['sections'][$section]['fields'], "{$section} has {$id}");
        return $model['sections'][$section]['fields'][$id];
    }

    /** Every editable field of a section posted exactly as rendered (what a Save with nothing moved sends). */
    private function asRendered(array $model, string $section): array
    {
        $f = [];
        foreach ($model['sections'][$section]['fields'] as $id => $fld) {
            if (!$fld['editable']) continue;
            $f[$id] = $fld['type'] === 'bool' ? ($fld['value'] ? '1' : '') : (string) ($fld['value'] ?? '');
        }
        return $f;
    }

    private function save(string $npc, string $section, array $fields): array
    {
        $r = $this->post(['npc' => $npc, 'op' => 'save', 'section' => $section, 'f' => $fields]);
        $this->assertSame(303, $r['status']);
        return $this->session[RelDynEditor::FLASH_SESSION_KEY] ?? [];
    }

    // ------------------------------------------------------------------ §23 the NPC's own standing

    /**
     * attraction section x editor (decisions §23): the NPC's own standing is a field. It shows the value in effect (derived from
     * the archetype and a status faction, a stand-in until the thane / rank reporter exists), the derived value beside an edit,
     * writes attraction_overrides.standing (what RelDynAttraction::definition reads over the preset and the derivation) and
     * resets to the derivation; nobody else's changes.
     */
    public function testTheNpcStandingIsAnEditorFieldShownWithItsDerivedValueAndWritesTheOverride(): void
    {
        $derived = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->track($npc);
            $f = $this->field(RelDynEditor::model($npc), 'attraction', 'attr:standing');
            $this->assertSame('derived', $f['state'], "{$npc}: nothing edited yet");
            $this->assertTrue($f['editable'], $npc);
            $this->assertSame('number', $f['type']);
            $def = RelDynAttraction::definition($npc, $d);
            $this->assertEqualsWithDelta($def['own_standing'], $f['value'], 5e-4, "{$npc}: the value in effect is the definition's");
            $this->assertEqualsWithDelta($f['value'], $f['derived'], 1e-9, "{$npc}: the derived value is shown");
            $this->assertSame('archetype', $def['sources']['standing'], $npc);
            $this->assertEqualsWithDelta(0.95, $f['max'], 1e-9, 'the range the definition keeps it in');
            $derived[$npc] = $f['value'];
        }
        $this->assertGreaterThan($derived[self::LYNLY], $derived[self::AELA], 'a Companion of the Circle stands higher than a wandering bard: apart by who they are');
        $this->assertGreaterThan(0.0, min($derived));

        // an edit writes the override and moves the status gap the spike's prerequisite reads
        $fields = $this->asRendered(RelDynEditor::model(self::LYNLY), 'attraction');
        $this->assertArrayHasKey('attr:standing', $fields, 'it is posted with the rest of the section');
        $fields['attr:standing'] = '0.9';
        $flash = $this->save(self::LYNLY, 'attraction', $fields);
        $this->assertSame([], array_filter((array) ($flash['errors'] ?? [])), 'accepted');
        $stored = $this->stored(self::LYNLY);
        $this->assertEquals(['standing' => 0.9], $stored['attraction_overrides'], 'only the field that moved is written');
        $def = RelDynAttraction::definition(self::LYNLY, $stored);
        $this->assertEqualsWithDelta(0.9, $def['own_standing'], 1e-9);
        $this->assertSame('override', $def['sources']['standing']);
        $f = $this->field(RelDynEditor::model(self::LYNLY), 'attraction', 'attr:standing');
        $this->assertSame('override', $f['state']);
        $this->assertEqualsWithDelta(0.9, $f['value'], 1e-9);
        $this->assertEqualsWithDelta($derived[self::LYNLY], $f['derived'], 5e-4, 'the derived value stays beside the edit');
        $html = $this->get(['npc' => self::LYNLY])['body'];
        $this->assertMatchesRegularExpression('/name="f\[attr:standing\]" value="0\.9"[^>]*> <span class="rd-badge override"/', $html);
        $this->assertStringContainsString('derived: ' . rtrim(rtrim(number_format($derived[self::LYNLY], 3), '0'), '.'), $html);
        // the status gap to a great name: a bard below it, the same bard standing high is not looking up at them
        $low = RelDynAttraction::spikePrerequisite([], 0.85, $derived[self::LYNLY], 'rigid', true);
        $high = RelDynAttraction::spikePrerequisite([], 0.85, 0.9, 'rigid', true);
        $this->assertGreaterThan(0.0, $low['gap_weight'], 'looks up at them');
        $this->assertSame(0.0, $high['gap_weight'], 'stands as high: nothing to admire in the gap');
        // nobody else's changed
        $this->assertArrayNotHasKey('attraction_overrides', $this->stored('Muiri') ?? []);

        // posted back as shown is no edit; a value out of range is refused by field and range
        $fields = $this->asRendered(RelDynEditor::model(self::LYNLY), 'attraction');
        $this->save(self::LYNLY, 'attraction', $fields);
        $this->assertEquals(['standing' => 0.9], $this->stored(self::LYNLY)['attraction_overrides']);
        $fields['attr:standing'] = '1.5';
        $flash = $this->save(self::LYNLY, 'attraction', $fields);
        $this->assertStringContainsString('attr:standing: outside', $flash['message']);
        $this->assertEquals(['standing' => 0.9], $this->stored(self::LYNLY)['attraction_overrides']);
        $fields['attr:standing'] = 'tall';
        $flash = $this->save(self::LYNLY, 'attraction', $fields);
        $this->assertStringContainsString('attr:standing: not a valid value', $flash['message']);

        // a reset follows the derivation again, per field and with the whole section
        $this->post(['npc' => self::LYNLY, 'op' => 'reset_field|attr:standing']);
        $this->assertSame([], $this->stored(self::LYNLY)['attraction_overrides'] ?? [], 'the override is gone');
        $f = $this->field(RelDynEditor::model(self::LYNLY), 'attraction', 'attr:standing');
        $this->assertSame('derived', $f['state']);
        $this->assertEqualsWithDelta($derived[self::LYNLY], $f['value'], 5e-4);
        $fields = $this->asRendered(RelDynEditor::model(self::AELA), 'attraction');
        $fields['attr:standing'] = '0.2';
        $this->save(self::AELA, 'attraction', $fields);
        $this->assertEqualsWithDelta(0.2, RelDynAttraction::definition(self::AELA, $this->stored(self::AELA))['own_standing'], 1e-9);
        $this->post(['npc' => self::AELA, 'op' => 'reset_section', 'section' => 'attraction']);
        $this->assertArrayNotHasKey('attraction_overrides', $this->stored(self::AELA));
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertSame(0, $this->launches, 'no trait read queued');
        $this->assertSame([], $this->db->failures);
    }

    public function testAPresetsStandingIsShownAsOneAndTheEditWinsOverIt(): void
    {
        // a named preset (attraction npc_overrides) that sets a standing: the editor shows it as the preset's, and an edit wins
        $row = json_decode((string) pg_fetch_result(pg_query($this->db->link, "SELECT value FROM conf_opts WHERE id = '" . RelationshipDynamics::CONFIG_ROW_ID . "'"), 0, 0), true);
        $row['attraction'] = ['npc_overrides' => [strtolower(self::AELA) => ['standing' => 0.7]]];
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($row)]);
        RelationshipDynamics::clearConfigCache();
        $this->track(self::AELA);
        $f = $this->field(RelDynEditor::model(self::AELA), 'attraction', 'attr:standing');
        $this->assertSame('preset', $f['state']);
        $this->assertEqualsWithDelta(0.7, $f['value'], 1e-9);
        $fields = $this->asRendered(RelDynEditor::model(self::AELA), 'attraction');
        $fields['attr:standing'] = '0.4';
        $this->save(self::AELA, 'attraction', $fields);
        $f = $this->field(RelDynEditor::model(self::AELA), 'attraction', 'attr:standing');
        $this->assertSame('override', $f['state']);
        $this->assertEqualsWithDelta(0.4, $f['value'], 1e-9);
        $this->post(['npc' => self::AELA, 'op' => 'reset_field|attr:standing']);
        $this->assertSame('preset', $this->field(RelDynEditor::model(self::AELA), 'attraction', 'attr:standing')['state'], 'back to the preset, not the derivation');
    }
}
