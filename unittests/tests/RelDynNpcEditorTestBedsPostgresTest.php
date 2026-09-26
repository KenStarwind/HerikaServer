<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynNpcEditorPgDb
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
 * The per-NPC parameter editor (decisions 2026-09-23 §4, roadmap full-parameter-editor) on the four
 * test beds: Aela the Huntress, Ashe (Serene's hand-set vector, never read; nothing of her story
 * here, placeholders only), Muiri and Lynly Star-Sung, on CHIM 3.4.1 core-shaped rows and the
 * committed trait-read seed. The page model and its HTML, round-trip saves through the engine's own
 * APIs (setProfileOverride, the facet / intimacy / attraction override layers, saveDynamics CAS),
 * per-field / per-section / whole-NPC resets, CSRF rejection, escaping, and that a page view
 * neither writes RelDyn state nor queues a trait read (no LLM work). No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynNpcEditorTestBedsPostgresTest extends TestCase
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
    private RelDynNpcEditorPgDb $db;
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
        $this->schema = 'reldyn_npceditor_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        self::createSchema($admin);
        pg_close($admin);

        $this->db = new RelDynNpcEditorPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (170 * RelationshipDynamics::GAMETS_PER_DAY), ''];
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdnpceditor');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_npc_editor_test.log');
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

    // ------------------------------------------------------------------ list

    public function testTheListShowsEveryBedAndSearchesByName(): void
    {
        $this->track(self::AELA);
        $r = $this->get([]);
        $this->assertSame(200, $r['status']);
        foreach (array_keys(self::BEDS) as $name) {
            $this->assertStringContainsString('href="npc.php?npc=' . htmlspecialchars(rawurlencode($name), ENT_QUOTES) . '"', $r['body'], $name);
        }
        $this->assertLessThan(strpos($r['body'], '>Ashe<'), strpos($r['body'], '>Aela the Huntress<'), 'tracked NPCs first');
        $this->assertStringContainsString('tracked', $r['body']);

        $r = $this->get(['q' => 'star']);
        $this->assertStringContainsString('>Lynly Star-Sung<', $r['body']);
        $this->assertStringNotContainsString('>Aela the Huntress<', $r['body']);
        // LIKE wildcards in the search are literal, and quotes are escaped into the literal
        $this->assertStringNotContainsString('>Muiri<', $this->get(['q' => '%'])['body']);
        $r = $this->get(['q' => "x' OR '1'='1"]);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('No NPC matches', $r['body']);
        $this->assertSame([], $this->db->failures);
    }

    public function testNamesAndSearchTermsAreEscapedEverywhere(): void
    {
        $list = $this->get(['q' => '<b'])['body'];
        $this->assertStringNotContainsString(self::EVIL, $list);
        $this->assertStringContainsString(htmlspecialchars(self::EVIL, ENT_QUOTES), $list);
        $this->assertStringNotContainsString('value="<b"', $list);

        $page = $this->get(['npc' => self::EVIL]);
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('<b onmouseover', $page['body']);
        $this->assertStringContainsString('<h1>' . htmlspecialchars(self::EVIL, ENT_QUOTES) . '</h1>', $page['body']);
        $this->assertStringContainsString('name="npc" value="' . htmlspecialchars(self::EVIL, ENT_QUOTES) . '"', $page['body']);

        // A value that came from the game (the rival's name) is escaped in its input
        $this->mutate(self::AELA, function (array &$d) { $d['jealousy_trigger_npc'] = '"><script>alert(2)</script>'; });
        $page = $this->get(['npc' => self::AELA])['body'];
        $this->assertStringNotContainsString('<script>alert(2)', $page);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;"', $page);
    }

    public function testAnUnknownNpcIs404(): void
    {
        $r = $this->get(['npc' => 'Nobody Here']);
        $this->assertSame(404, $r['status']);
        $this->assertNull($this->stored('Nobody Here'));
    }

    // ------------------------------------------------------------------ view

    public function testEverySectionRendersForEveryBed(): void
    {
        foreach (array_keys(self::BEDS) as $name) {
            $r = $this->get(['npc' => $name]);
            $this->assertSame(200, $r['status'], $name);
            $html = $r['body'];
            foreach (array_keys(RelDynEditor::SECTIONS) as $sec) {
                $this->assertStringContainsString('id="sec-' . $sec . '"', $html, "{$name}: {$sec}");
            }
            // one POST form per section plus the fresh-start form, each with the session's token
            $token = RelDynEditor::csrfToken($this->session);
            $this->assertSame(count(RelDynEditor::SECTIONS) + 1, substr_count($html, '<form method="post"'), $name);
            $this->assertSame(count(RelDynEditor::SECTIONS) + 1, substr_count($html, '<input type="hidden" name="csrf_token" value="' . $token . '">'), $name);
            $this->assertStringNotContainsString('method="get" action="npc.php"', $html, 'no GET form on an NPC page');
            // every dimension has a row; warmth is read-only; affinity reads in core units
            foreach (RelDynEditor::DIMENSIONS as $dim) $this->assertStringContainsString('data-dim="' . $dim . '"', $html, "{$name}: {$dim}");
            $this->assertStringNotContainsString('name="f[dim:warmth:x]"', $html);
            $this->assertStringContainsString('name="f[dim:affinity:x]" value="40"', $html, 'core affinity 40');
            // 10 trait sliders and the preset picker listing all 13 presets
            foreach (RelDynTraits::TRAITS as $trait) $this->assertStringContainsString('name="f[trait:' . $trait . ']"', $html);
            foreach (array_keys(RelDynTraits::PRESET_TRAITS) as $preset) $this->assertStringContainsString('<option value="' . $preset . '"', $html);
            // the fulfillment spider of the player pair
            $this->assertMatchesRegularExpression('/<div class="rd-spider" data-target="Player"><svg viewBox/', $html, $name);
            $this->assertStringContainsString('Reset the whole NPC', $html);
        }
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    public function testTraitSourcesShowTheBioQuoteForAelaAndNothingReadForAshe(): void
    {
        $aela = RelDynEditor::model(self::AELA);
        $b = $aela['sections']['personality']['blocks'][0];
        $this->assertSame('trait_sources', $b['type']);
        $this->assertSame('bio', $b['traits']['confidence']['source']);
        $this->assertSame('Aela speaks directly and with confidence', $b['traits']['confidence']['evidence']);
        $html = $this->get(['npc' => self::AELA])['body'];
        $this->assertStringContainsString('&ldquo;Aela speaks directly and with confidence&rdquo;', $html);
        $this->assertSame('derived', $this->field($aela, 'personality', 'trait:confidence')['state']);

        $ashe = RelDynEditor::model('Ashe');
        $b = $ashe['sections']['personality']['blocks'][0];
        $this->assertTrue($b['screened']);
        foreach ($b['traits'] as $name => $s) {
            $this->assertArrayNotHasKey('evidence', $s, $name);
            if ($name !== 'maturity_start') $this->assertSame('preset', $s['source'], $name);
        }
        // her hand-set vector is what the sliders show
        $this->assertEqualsWithDelta(0.75, $this->field($ashe, 'personality', 'trait:guard')['value'], 1e-9);
        $this->assertEqualsWithDelta(0.55, $this->field($ashe, 'personality', 'trait:protectiveness')['value'], 1e-9);
        $this->assertSame('preset', $this->field($ashe, 'personality', 'trait:guard')['state']);
        $html = $this->get(['npc' => 'Ashe'])['body'];
        $this->assertStringContainsString('on the read skip list', $html);
        $this->assertStringNotContainsString('class="rd-quote"', $html);
        // Ashe has no read row and none was queued
        $n = pg_fetch_assoc(pg_query($this->db->link, "SELECT count(*) AS n FROM reldyn_trait_reads WHERE template_key = 'ashe'"));
        $this->assertSame('0', $n['n']);
    }

    public function testAPageViewWritesNothingAndQueuesNoTraitRead(): void
    {
        $this->track(self::LYNLY);
        $before = $this->rawBlob(self::LYNLY);
        $reads = pg_fetch_assoc(pg_query($this->db->link, 'SELECT count(*) AS n FROM reldyn_trait_reads'))['n'];
        $this->db->writes = [];
        foreach ([self::LYNLY, 'Test Wanderer', 'Muiri'] as $name) {
            $this->assertSame(200, $this->get(['npc' => $name])['status']);
        }
        $this->get([]);
        $this->assertSame($before, $this->rawBlob(self::LYNLY), 'the stored state is untouched');
        $this->assertNull($this->stored('Test Wanderer'), 'an unseen NPC stays unseen');
        $this->assertNull($this->stored('Muiri'));
        $this->assertSame($reads, pg_fetch_assoc(pg_query($this->db->link, 'SELECT count(*) AS n FROM reldyn_trait_reads'))['n'], 'no read queued');
        $this->assertSame(0, $this->launches, 'no trait-read worker launched');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], array_values(array_filter($this->db->writes, fn($w) => stripos($w, 'core_npc_master') !== false || stripos($w, 'reldyn_trait_reads') !== false)));
    }

    // ------------------------------------------------------------------ CSRF

    public function testAPostWithoutTheSessionTokenIsRejectedAndChangesNothing(): void
    {
        $this->track(self::AELA);
        $before = $this->rawBlob(self::AELA);
        $model = RelDynEditor::model(self::AELA);
        $fields = ['dim:trust:x' => '99'] + $this->asRendered($model, 'dimensions');
        $fields['dim:trust:x'] = '99';
        foreach (['missing' => '', 'wrong' => str_repeat('0', 64), 'array' => null] as $case => $token) {
            $post = ['npc' => self::AELA, 'op' => 'save', 'section' => 'dimensions', 'f' => $fields];
            if ($case === 'wrong') $post['csrf_token'] = $token;
            if ($case === 'array') $post['csrf_token'] = [RelDynEditor::csrfToken($this->session)];
            RelDynEditor::csrfToken($this->session);
            $r = RelDynEditor::handle('POST', [], $post, $this->session);
            $this->assertSame(403, $r['status'], $case);
            $this->assertStringContainsString('Security check failed', $r['body']);
            $this->assertNull($r['location']);
        }
        foreach (['reset_npc' => ['confirm' => 'yes'], 'reset_section' => ['section' => 'dimensions']] as $op => $extra) {
            $r = RelDynEditor::handle('POST', [], ['npc' => self::AELA, 'op' => $op, 'csrf_token' => 'nope'] + $extra, $this->session);
            $this->assertSame(403, $r['status'], $op);
        }
        // a token from another session does not work either
        $other = [];
        $r = RelDynEditor::handle('POST', [], ['npc' => self::AELA, 'op' => 'reset_npc', 'confirm' => 'yes', 'csrf_token' => RelDynEditor::csrfToken($other)], $this->session);
        $this->assertSame(403, $r['status']);
        $this->assertSame($before, $this->rawBlob(self::AELA));
        // GET never writes, even with write parameters in the query string
        $this->get(['npc' => self::AELA, 'op' => 'reset_npc', 'confirm' => 'yes', 'csrf_token' => RelDynEditor::csrfToken($this->session)]);
        $this->assertSame($before, $this->rawBlob(self::AELA));
    }

    // ------------------------------------------------------------------ round trips

    public function testSavingASectionUnchangedIsNoEditAndKeepsEverythingDerived(): void
    {
        $this->track('Muiri');
        $before = $this->rawBlob('Muiri');
        $model = RelDynEditor::model('Muiri');
        foreach (array_keys($model['sections']) as $sec) {
            $fields = $this->asRendered($model, $sec);
            if ($fields === []) continue;
            $flash = $this->save('Muiri', $sec, $fields);
            $this->assertSame('No changes.', $flash['message'], $sec);
        }
        $this->assertSame($before, $this->rawBlob('Muiri'));
        $d = $this->stored('Muiri');
        $this->assertSame([], (array) ($d['profile_overrides'] ?? []));
        $this->assertSame([], (array) ($d['facet_pref_overrides'] ?? []));
        $this->assertArrayNotHasKey('attraction_overrides', $d);
        $this->assertArrayNotHasKey('intimacy_need_overrides', $d);
    }

    public function testDimensionRawAndBaselineRoundTripAndReset(): void
    {
        $this->track(self::LYNLY);
        $model = RelDynEditor::model(self::LYNLY);
        $derived = $this->field($model, 'dimensions', 'dim:trust:baseline')['derived'];
        $fields = $this->asRendered($model, 'dimensions');
        $fields['dim:trust:x'] = '71.5';
        $fields['dim:comfort:baseline'] = '12';
        $fields['dim:affinity:x'] = '55';
        $flash = $this->save(self::LYNLY, 'dimensions', $fields);
        $this->assertTrue($flash['ok'], $flash['message'] ?? '');
        $this->assertSame('Saved 3 changes.', $flash['message']);
        $d = $this->stored(self::LYNLY);
        $this->assertEqualsWithDelta(71.5, $d['dimensions']['trust']['x'], 1e-9);
        $this->assertEqualsWithDelta(12.0, $d['dimensions']['comfort']['baseline'], 1e-9);
        $this->assertEqualsWithDelta(55.0, RelationshipDynamics::getCoreAffinity($d), 1e-6, 'core affinity moves through the mirror (committed on her next turn)');

        $m2 = RelDynEditor::model(self::LYNLY);
        $this->assertSame('differs', $this->field($m2, 'dimensions', 'dim:comfort:baseline')['state']);
        $html = $this->get(['npc' => self::LYNLY])['body'];
        $this->assertStringContainsString('name="f[dim:trust:x]" value="71.5"', $html);

        // out of range: rejected, nothing else lost
        $fields = $this->asRendered($m2, 'dimensions');
        $fields['dim:respect:x'] = '140';
        $flash = $this->save(self::LYNLY, 'dimensions', $fields);
        $this->assertFalse($flash['ok']);
        $this->assertStringContainsString('dim:respect:x: outside 0..100', $flash['message']);
        $this->assertEqualsWithDelta(71.5, $this->stored(self::LYNLY)['dimensions']['trust']['x'], 1e-9);

        // per-field reset: raw back to the baseline, baseline back to the derivation
        $this->post(['npc' => self::LYNLY, 'op' => 'reset_field|dim:comfort:baseline']);
        $this->assertEqualsWithDelta(RelDynEditor::model(self::LYNLY)['sections']['dimensions']['fields']['dim:comfort:baseline']['derived'],
            $this->stored(self::LYNLY)['dimensions']['comfort']['baseline'], 1e-9);
        $this->post(['npc' => self::LYNLY, 'op' => 'reset_field|dim:trust:x']);
        $d = $this->stored(self::LYNLY);
        $this->assertEqualsWithDelta($d['dimensions']['trust']['baseline'], $d['dimensions']['trust']['x'], 1e-9);
        // section reset: every dimension on its derived baseline, affinity untouched
        $this->mutate(self::LYNLY, function (array &$d) { $d['dimensions']['respect']['x'] = 3; $d['dimensions']['respect']['baseline'] = 4; });
        $r = $this->post(['npc' => self::LYNLY, 'op' => 'reset_section', 'section' => 'dimensions']);
        $this->assertSame('npc.php?npc=' . rawurlencode(self::LYNLY) . '#sec-dimensions', $r['location']);
        $d = $this->stored(self::LYNLY);
        $this->assertEqualsWithDelta($derived, $d['dimensions']['trust']['x'], 1e-9);
        $this->assertNotEquals(3.0, floatval($d['dimensions']['respect']['x']));
        $this->assertEqualsWithDelta(55.0, RelationshipDynamics::getCoreAffinity($d), 1e-6);
    }

    public function testTraitSliderPresetTagsAndMaturityTypeAreProfileOverrides(): void
    {
        $model = RelDynEditor::model(self::AELA);
        $fields = $this->asRendered($model, 'personality');
        $fields['trait:warmth'] = '0.9';
        $flash = $this->save(self::AELA, 'personality', $fields);
        $this->assertSame('Saved 1 change.', $flash['message'], 'an untouched slider is no edit (the save stores an unseen NPC)');
        $d = $this->stored(self::AELA);
        $this->assertSame(['warmth' => 0.9], $d['profile_overrides']['trait_vector']);
        $this->assertEqualsWithDelta(0.9, RelDynTraits::readVector($d)['W'], 1e-9, 'the read vector recomposes with the override');
        $m = RelDynEditor::model(self::AELA);
        $this->assertSame('override', $this->field($m, 'personality', 'trait:warmth')['state']);
        $this->assertSame('derived', $this->field($m, 'personality', 'trait:guard')['state']);

        // a second slider joins the first
        $fields = $this->asRendered($m, 'personality');
        $fields['trait:guard'] = '0.2';
        $fields['prof:maturity_type'] = 'Brittle';
        $fields['prof:traits'] = 'insecure';
        $this->save(self::AELA, 'personality', $fields);
        $d = $this->stored(self::AELA);
        $this->assertSame(['guard' => 0.2, 'warmth' => 0.9], $d['profile_overrides']['trait_vector']);
        $this->assertSame('Brittle', $d['profile_overrides']['maturity_type']);
        $this->assertSame('Brittle', $d['dimensions']['maturity']['plasticity_type']);
        $this->assertSame(['insecure'], $d['profile_overrides']['traits']);
        $this->assertSame(['insecure'], RelationshipDynamics::getTraits($d));

        // unknown tag: refused
        $fields = $this->asRendered(RelDynEditor::model(self::AELA), 'personality');
        $fields['prof:traits'] = 'insecure, <b>villain</b>';
        $flash = $this->save(self::AELA, 'personality', $fields);
        $this->assertStringContainsString('prof:traits: unknown tag', $flash['message']);
        $this->assertSame(['insecure'], $this->stored(self::AELA)['profile_overrides']['traits']);

        // per-field reset of one slider keeps the other
        $this->post(['npc' => self::AELA, 'op' => 'reset_field|trait:warmth']);
        $this->assertSame(['guard' => 0.2], $this->stored(self::AELA)['profile_overrides']['trait_vector']);

        // the preset picker
        $fields = $this->asRendered(RelDynEditor::model(self::AELA), 'personality');
        $fields['prof:temperament'] = 'Playful';
        $this->save(self::AELA, 'personality', $fields);
        $d = $this->stored(self::AELA);
        $this->assertSame('Playful', $d['profile_overrides']['temperament']);
        $this->assertSame('override', $this->field(RelDynEditor::model(self::AELA), 'personality', 'prof:temperament')['state']);

        // section reset: every personality override gone, the read vector back
        $this->post(['npc' => self::AELA, 'op' => 'reset_section', 'section' => 'personality']);
        $d = $this->stored(self::AELA);
        $this->assertSame([], (array) ($d['profile_overrides'] ?? []));
        $m = RelDynEditor::model(self::AELA);
        foreach (RelDynTraits::TRAITS as $t) $this->assertSame('derived', $this->field($m, 'personality', "trait:{$t}")['state'], $t);
        $this->assertSame(0, $this->llmCalls);
    }

    public function testAttachmentAxesOverrideAndDriftReset(): void
    {
        $model = RelDynEditor::model('Muiri');
        $fields = $this->asRendered($model, 'attachment');
        $fields['att:anxiety'] = '0.8';
        $this->save('Muiri', 'attachment', $fields);
        $d = $this->stored('Muiri');
        $this->assertEqualsWithDelta(0.8, $d['profile_overrides']['attachment_axes']['anxiety'], 1e-9);
        $this->assertEqualsWithDelta($this->field($model, 'attachment', 'att:avoidance')['value'], $d['profile_overrides']['attachment_axes']['avoidance'], 1e-3,
            'the untouched axis keeps its value');
        $this->mutate('Muiri', function (array &$d) {
            $d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] = ['anxiety' => 0.05, 'avoidance' => -0.02, 'day' => 170, 'moved' => ['anxiety' => 0.01, 'avoidance' => 0.0]];
        });
        $this->assertStringContainsString('anxiety 0.05', $this->field(RelDynEditor::model('Muiri'), 'attachment', 'att:drift')['value']);
        $this->post(['npc' => 'Muiri', 'op' => 'reset_section', 'section' => 'attachment']);
        $d = $this->stored('Muiri');
        $this->assertArrayNotHasKey('attachment_axes', (array) ($d['profile_overrides'] ?? []));
        $this->assertNull($d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY]);
    }

    public function testFacetIntimacyAndLoveLanguageOverridesRoundTrip(): void
    {
        $model = RelDynEditor::model(self::AELA);
        $scholarly = $this->field($model, 'facets', 'facet:scholarly');
        $this->assertSame('derived', $scholarly['state']);
        $fields = $this->asRendered($model, 'facets');
        $fields['facet:scholarly'] = '0.6';
        $fields['facet:crowd'] = '-0.85';
        $this->save(self::AELA, 'facets', $fields);
        $d = $this->stored(self::AELA);
        $this->assertEqualsWithDelta(0.6, $d['facet_pref_overrides']['scholarly'], 1e-9);
        $this->assertEqualsWithDelta(-0.85, $d['facet_pref_overrides']['crowd'], 1e-9);
        $this->assertCount(2, $d['facet_pref_overrides'], 'only the two moved facets');
        $this->assertEqualsWithDelta(0.6, RelDynFacets::preferences($d, self::AELA)['scholarly'], 1e-9);
        $this->post(['npc' => self::AELA, 'op' => 'reset_field|facet:scholarly']);
        $this->assertSame(['crowd' => -0.85], $this->stored(self::AELA)['facet_pref_overrides']);

        $fields = $this->asRendered(RelDynEditor::model(self::AELA), 'intimacy');
        $fields['need:physical'] = '0.25';
        $this->save(self::AELA, 'intimacy', $fields);
        $d = $this->stored(self::AELA);
        $this->assertSame(['physical' => 0.25], $d['intimacy_need_overrides']);
        $this->assertEqualsWithDelta(0.25, RelDynIntimacy::need($d)['physical'], 1e-9);
        $this->post(['npc' => self::AELA, 'op' => 'reset_section', 'section' => 'intimacy']);
        $this->assertArrayNotHasKey('intimacy_need_overrides', $this->stored(self::AELA));

        $m = RelDynEditor::model(self::AELA);
        $primary = $this->field($m, 'love', 'love:love_language_primary');
        $fields = $this->asRendered($m, 'love');
        $other = $primary['value'] === RelationshipDynamics::LL_GIFTS ? RelationshipDynamics::LL_TOUCH : RelationshipDynamics::LL_GIFTS;
        $fields['love:love_language_primary'] = $other;
        $fields['love:relationship_preference'] = 'demisexual';
        $this->save(self::AELA, 'love', $fields);
        $d = $this->stored(self::AELA);
        $this->assertSame($other, $d['love_language_primary']);
        $this->assertSame('demisexual', $d['relationship_preference']);
        $this->assertSame('edited', $this->field(RelDynEditor::model(self::AELA), 'love', 'love:love_language_primary')['state']);
        $fields['love:love_language_primary'] = 'telepathy';
        $flash = $this->save(self::AELA, 'love', $fields);
        $this->assertStringContainsString('unknown value', $flash['message']);
        $this->post(['npc' => self::AELA, 'op' => 'reset_section', 'section' => 'love']);
        $d = $this->stored(self::AELA);
        $this->assertSame($primary['derived'], $d['love_language_primary']);
        $this->assertNull($d['relationship_preference']);
    }

    public function testAttractionOverridesReachTheMatrixAndResetCleanly(): void
    {
        $model = RelDynEditor::model(self::AELA);
        $floor = $this->field($model, 'attraction', 'attr:floor:strength');
        $this->assertSame('derived', $floor['state']);
        $fields = $this->asRendered($model, 'attraction');
        $fields['attr:floor:strength'] = '80';
        $fields['attr:rigidity:beauty'] = 'irrelevant';
        $fields['attr:gate'] = 'bond';
        $fields['attr:beauty_keywords'] = 'scarred, Strong';
        $this->save(self::AELA, 'attraction', $fields);
        $d = $this->stored(self::AELA);
        $this->assertEquals(['strength' => 80.0], $d['attraction_overrides']['floors']);
        $def = RelDynAttraction::definition(self::AELA, $d);
        $this->assertEqualsWithDelta(80.0, $def['floors']['strength'], 1e-9);
        $this->assertSame('irrelevant', $def['rigidity']['beauty']);
        $this->assertSame(0.0, $def['weights']['beauty'], 'irrelevant zeroes the weight');
        $this->assertSame('bond', $def['gate']);
        $this->assertSame(['scarred', 'strong'], $def['beauty_keywords']);
        $html = $this->get(['npc' => self::AELA])['body'];
        $this->assertMatchesRegularExpression('/name="f\[attr:floor:strength\]" value="80"[^>]*> <span class="rd-badge override"/', $html);

        $fields = $this->asRendered(RelDynEditor::model(self::AELA), 'attraction');
        $fields['attr:passion_pillars'] = 'strength, charm';
        $flash = $this->save(self::AELA, 'attraction', $fields);
        $this->assertStringContainsString('attr:passion_pillars: list pillars from', $flash['message']);

        $this->post(['npc' => self::AELA, 'op' => 'reset_field|attr:floor:strength']);
        $this->assertArrayNotHasKey('floors', $this->stored(self::AELA)['attraction_overrides']);
        $this->post(['npc' => self::AELA, 'op' => 'reset_section', 'section' => 'attraction']);
        $d = $this->stored(self::AELA);
        $this->assertArrayNotHasKey('attraction_overrides', $d);
        $this->assertEqualsWithDelta($floor['value'], RelDynAttraction::definition(self::AELA, $d)['floors']['strength'], 0.05);
    }

    public function testJealousyFlagsAndTheirResets(): void
    {
        $this->track(self::LYNLY);
        $fields = $this->asRendered(RelDynEditor::model(self::LYNLY), 'jealousy');
        $fields['jeal:level'] = '45';
        $fields['jeal:rival'] = 'Nazeem';
        $this->save(self::LYNLY, 'jealousy', $fields);
        $d = $this->stored(self::LYNLY);
        $this->assertEqualsWithDelta(45.0, $d['jealousy_anger'], 1e-9);
        $this->assertSame('Nazeem', $d['jealousy_trigger_npc']);
        $this->post(['npc' => self::LYNLY, 'op' => 'reset_section', 'section' => 'jealousy']);
        $d = $this->stored(self::LYNLY);
        $this->assertEqualsWithDelta(0.0, $d['jealousy_anger'], 1e-9);
        $this->assertNull($d['jealousy_trigger_npc']);

        $fields = $this->asRendered(RelDynEditor::model(self::LYNLY), 'flags');
        $fields['flag:in_conflict'] = '1';
        $fields['flag:stage'] = 'deep';
        $fields['flag:type_override'] = 'rival';
        $fields['flag:context_tier_hwm'] = '9';
        $flash = $this->save(self::LYNLY, 'flags', $fields);
        $this->assertStringContainsString('flag:context_tier_hwm: outside 0..3', $flash['message']);
        $d = $this->stored(self::LYNLY);
        $this->assertTrue($d['in_conflict']);
        $this->assertSame('deep', $d['stage']);
        $this->assertSame('rival', RelationshipDynamics::getRelationshipType(self::LYNLY, $d));
        // unticking the checkbox posts the hidden "" (false)
        $fields = $this->asRendered(RelDynEditor::model(self::LYNLY), 'flags');
        $fields['flag:in_conflict'] = '';
        $this->save(self::LYNLY, 'flags', $fields);
        $this->assertFalse($this->stored(self::LYNLY)['in_conflict']);
        $this->post(['npc' => self::LYNLY, 'op' => 'reset_section', 'section' => 'flags']);
        $d = $this->stored(self::LYNLY);
        $this->assertSame('early', $d['stage']);
        $this->assertNull($d['_relationship_type_override']);
    }

    public function testHeldOffsetsAreReleasedOutOfTheDimensionsAndStatesReset(): void
    {
        $this->track('Muiri');
        $comfort0 = floatval($this->stored('Muiri')['dimensions']['comfort']['x']);
        $trust0 = floatval($this->stored('Muiri')['dimensions']['trust']['x']);
        $this->mutate('Muiri', function (array &$d) use ($comfort0, $trust0) {
            // a creature row holds -6 comfort, the weather +3 trust (both already inside x, as their owners apply them)
            $d[RelDynCreatures::STATE_KEY] = ['applied' => ['comfort' => -6.0]];
            $d['_weather_gravity'] = ['applied' => ['trust' => 3.0], 'offsets' => ['trust' => 3.0]];
            $d['dimensions']['comfort']['x'] = $comfort0 - 6.0;
            $d['dimensions']['trust']['x'] = $trust0 + 3.0;
            $d['_walkaway_state'] = 'active';
            $d['_walkaway_reason'] = 'neglect';
            $d[RelDynConcern::STATE_KEY] = ['level' => 40.0];
            $d[RelDynPassion::SPIKE_KEY] = 12.0;
        });
        $m = RelDynEditor::model('Muiri');
        $this->assertEqualsWithDelta(-6.0, $m['sections']['dimensions']['fields']['dim:comfort:x']['held'], 1e-9);
        $this->assertArrayHasKey('state:held:creature', $m['sections']['states']['fields']);
        $this->assertTrue($this->field($m, 'states', 'state:walkaway')['resettable']);

        $this->post(['npc' => 'Muiri', 'op' => 'reset_field|state:held:creature']);
        $d = $this->stored('Muiri');
        $this->assertEqualsWithDelta($comfort0, $d['dimensions']['comfort']['x'], 1e-6, 'the creature hold is taken back out');
        $this->assertSame([], $d[RelDynCreatures::STATE_KEY]['applied']);
        $this->assertEqualsWithDelta($trust0 + 3.0, $d['dimensions']['trust']['x'], 1e-6, 'the other source stays');

        $this->post(['npc' => 'Muiri', 'op' => 'reset_field|state:walkaway']);
        $d = $this->stored('Muiri');
        $this->assertSame('normal', $d['_walkaway_state']);
        $this->assertArrayNotHasKey('_walkaway_reason', $d);

        $this->post(['npc' => 'Muiri', 'op' => 'reset_section', 'section' => 'states']);
        $d = $this->stored('Muiri');
        $this->assertEqualsWithDelta($trust0, $d['dimensions']['trust']['x'], 1e-6);
        $this->assertArrayNotHasKey(RelDynConcern::STATE_KEY, $d);
        $this->assertArrayNotHasKey(RelDynPassion::SPIKE_KEY, $d);
        $this->assertSame([], RelDynEditor::heldBySource($d));
    }

    public function testFulfillmentPairAndBoundaryReset(): void
    {
        $this->track(self::AELA);
        $this->mutate(self::AELA, function (array &$d) {
            $now = RelationshipDynamics::currentGamets();
            RelDynFulfillment::ensure($d, RelDynFacets::preferences($d, self::AELA), $now);
            RelDynFulfillment::deliver($d, ['combat' => 2.0, 'nature' => 1.5], $now);
            $s = RelDynFulfillment::pairState($d);
            $s['boundary'] = ['state' => 'probation', 'until_gamets' => $now + RelationshipDynamics::GAMETS_PER_DAY * 3, 'streak' => 1];
            RelDynFulfillment::setPairState($d, 'Player', $s);
        });
        $m = RelDynEditor::model(self::AELA);
        $spider = $m['sections']['fulfillment']['blocks'][0];
        $this->assertSame('Player', $spider['target']);
        $this->assertGreaterThanOrEqual(3, count($spider['graph']['axes']));
        $this->assertSame('probation', $spider['graph']['boundary']['state']);
        $html = $this->get(['npc' => self::AELA])['body'];
        $this->assertSame(1, preg_match('/<div class="rd-spider" data-target="Player">(<svg.*?<\/svg>)/s', $html, $svg));
        $this->assertSame(2 + 4, substr_count($svg[1], '<polygon'), '4 rings, the need and the coverage polygons');
        $this->assertTrue($this->field($m, 'fulfillment', 'ful:boundary:Player')['resettable']);

        $this->post(['npc' => self::AELA, 'op' => 'reset_field|ful:boundary:Player']);
        $s = RelDynFulfillment::pairState($this->stored(self::AELA));
        $this->assertSame('none', $s['boundary']['state']);
        $this->assertNotEmpty($s['lv'] ?? [], 'what was delivered stays');
        $this->post(['npc' => self::AELA, 'op' => 'reset_field|ful:pair:Player']);
        $this->assertNull(RelDynFulfillment::pairState($this->stored(self::AELA)));
    }

    public function testTheWholeNpcResetNeedsTheConfirmationAndKeepsCoreAffinity(): void
    {
        $fields = $this->asRendered(RelDynEditor::model(self::LYNLY), 'personality');
        $fields['prof:temperament'] = 'Bold';
        $this->save(self::LYNLY, 'personality', $fields);
        $this->mutate(self::LYNLY, function (array &$d) {
            RelationshipDynamics::setCoreAffinityValue($d, 62.0);
            $d['interaction_count'] = 40;
            $d['_aff_mirror_x'] = $d['dimensions']['affinity']['x'];   // committed to core already
        });
        $before = $this->rawBlob(self::LYNLY);
        $this->post(['npc' => self::LYNLY, 'op' => 'reset_npc']);
        $this->assertFalse($this->session[RelDynEditor::FLASH_SESSION_KEY]['ok']);
        $this->assertSame($before, $this->rawBlob(self::LYNLY), 'unconfirmed: nothing happens');

        $this->post(['npc' => self::LYNLY, 'op' => 'reset_npc', 'confirm' => 'yes']);
        $this->assertTrue($this->session[RelDynEditor::FLASH_SESSION_KEY]['ok']);
        $d = $this->stored(self::LYNLY);
        $this->assertSame([], (array) ($d['profile_overrides'] ?? []));
        $this->assertSame(0, intval($d['interaction_count']));
        $this->assertEqualsWithDelta(62.0, RelationshipDynamics::getCoreAffinity($d), 1e-6, 'core affinity is not RelDyn\'s to reset');
        // the next view derives her again
        $m = RelDynEditor::model(self::LYNLY);
        $this->assertSame('derived', $this->field($m, 'personality', 'prof:temperament')['state']);
    }

    // ------------------------------------------------------------------ the page itself (php-cli)

    /**
     * npc.php run as CHIM runs it (its own PHP process: runtime bootstrap, session, core's head /
     * navbar / footer) against this schema. Returns [exit code, stdout, stderr].
     */
    private function runPage(string $method, array $get, array $post, string $sessionToken): array
    {
        $engine = dirname(__DIR__, 2);
        $harness = tempnam(sys_get_temp_dir(), 'rdnpcpage_') . '.php';
        file_put_contents($harness, <<<'PHP'
<?php
[$self, $page, $dsn, $schema, $method, $query, $postJson, $token] = $argv;
$GLOBALS['chim_interaction_generation'] = 0;
final class RelDynNpcPageHarnessDb
{
    public $link;
    public function __construct(string $dsn, string $schema) { $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW); pg_query($this->link, "SET search_path TO {$schema}"); }
    public function fetchOne($q, array $params = []) { $r = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q); return $r ? (pg_fetch_assoc($r) ?: []) : []; }
    public function fetchAll($q, $log = false) { $r = @pg_query($this->link, $q); $out = []; if ($r) while ($row = pg_fetch_assoc($r)) $out[] = $row; return $out; }
    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q) { return @pg_query($this->link, $q); }
    public function insert($t, $d) { return false; }
    public function escape($s) { return pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return pg_escape_literal($this->link, (string) $s); }
    public function close() {}
}
$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = new RelDynNpcPageHarnessDb($dsn, $schema);
$GLOBALS['PLAYER_NAME'] = 'Kaida';
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['SCRIPT_NAME'] = '/HerikaServer/ext/relationship_dynamics/npc.php';
parse_str($query, $_GET);
$_POST = json_decode($postJson, true) ?: [];
session_save_path(sys_get_temp_dir());
session_id('rdnpcpage' . getmypid());
session_start();
$_SESSION['reldyn_editor_csrf'] = $token;
register_shutdown_function(function () { echo "\n__STATUS__ " . var_export(http_response_code(), true) . "\n"; @session_destroy(); });
require $page;
PHP);
        try {
            $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $harness, $engine . '/ext/relationship_dynamics/npc.php',
                $this->dsn, $this->schema, $method, http_build_query($get), json_encode($post), $sessionToken];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $engine);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($proc), $out, $err];
        } finally {
            @unlink($harness);
        }
    }

    public function testThePageRunsInCoreChromeAndItsPostsNeedTheToken(): void
    {
        $token = bin2hex(random_bytes(32));
        [$code, $out, $err] = $this->runPage('GET', ['npc' => self::AELA], [], $token);
        $this->assertSame(0, $code, $err);
        $this->assertStringNotContainsString('Fatal error', $err);
        $this->assertStringContainsString('<title>RelDyn: Aela the Huntress</title>', $out);
        $this->assertStringContainsString('chim-navbar', $out, 'core navbar');
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $out, 'core head (phone width)');
        $this->assertStringContainsString('<h1>Aela the Huntress</h1>', $out);
        $this->assertStringContainsString('<input type="hidden" name="csrf_token" value="' . $token . '">', $out);
        $this->assertStringContainsString('__STATUS__ 200', $out);
        // no external asset beyond what core's own head already loads
        preg_match_all('#(?:src|href)="(https?://[^"]+)"#', $out, $ext);
        $head = (string) file_get_contents(dirname(__DIR__, 2) . '/ui/tmpl/head.html') . (string) file_get_contents(dirname(__DIR__, 2) . '/ui/tmpl/navbar.php');
        foreach (array_unique($ext[1]) as $url) $this->assertStringContainsString($url, $head, "{$url} is not one of core's own assets");

        [$code, $out] = $this->runPage('GET', [], [], $token);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('<title>RelDyn NPCs</title>', $out);
        $this->assertStringContainsString('>Lynly Star-Sung<', $out);

        // a POST with a wrong token: 403, nothing stored
        [$code, $out] = $this->runPage('POST', [], ['npc' => self::AELA, 'op' => 'save', 'section' => 'jealousy',
            'f' => ['jeal:level' => '50'], 'csrf_token' => str_repeat('a', 64)], $token);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Security check failed', $out);
        $this->assertStringContainsString('__STATUS__ 403', $out);
        $this->assertNull($this->stored(self::AELA));

        // with the session's token: the save lands and the page redirects (no body)
        [$code, $out] = $this->runPage('POST', [], ['npc' => self::AELA, 'op' => 'save', 'section' => 'jealousy',
            'f' => ['jeal:level' => '50', 'jeal:rival' => ''], 'csrf_token' => $token], $token);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('<html', $out);
        $this->assertStringContainsString('__STATUS__ 303', $out);
        $this->assertEqualsWithDelta(50.0, $this->stored(self::AELA)['jealousy_anger'], 1e-9);
    }

    public function testUnknownOpsSectionsAndFieldsAreRefused(): void
    {
        $this->track('Muiri');
        $before = $this->rawBlob('Muiri');
        foreach ([
            ['op' => 'drop_table'], ['op' => 'save', 'section' => 'nope', 'f' => ['x' => '1']],
            ['op' => 'reset_field|dim:nope:x'], ['op' => 'reset_field|ov:type'], ['op' => 'reset_section', 'section' => 'overview'],
            ['op' => 'save', 'section' => 'dimensions', 'f' => ['dim:warmth:x' => '99', 'ov:type' => 'rival']],
        ] as $post) {
            $this->post(['npc' => 'Muiri'] + $post);
            $this->assertSame($before, $this->rawBlob('Muiri'), json_encode($post));
        }
        // the redirect carries a known section anchor only, never posted text
        $r = $this->post(['npc' => 'Muiri', 'op' => 'save', 'section' => "x\r\nSet-Cookie: a=b", 'f' => []]);
        $this->assertSame('npc.php?npc=Muiri', $r['location']);
        $r = $this->post(['npc' => 'Muiri', 'op' => 'save', 'section' => 'jealousy', 'f' => []]);
        $this->assertSame('npc.php?npc=Muiri#sec-jealousy', $r['location']);
        $this->assertSame([], $this->db->failures);
    }
}
