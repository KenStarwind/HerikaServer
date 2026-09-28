<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$GLOBALS['chim_interaction_generation'] = $GLOBALS['chim_interaction_generation'] ?? 0;
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/** `sql`-compatible adapter over one pg connection that logs every statement (CHIM conventions). */
final class RelDynSettingsHubPgDb
{
    public $link;
    public array $log = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $this->log[] = (string) $q;
        $res = $params ? @pg_query_params($this->link, (string) $q, $params) : @pg_query($this->link, (string) $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->log[] = (string) $q;
        $res = @pg_query($this->link, (string) $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q) { $this->log[] = (string) $q; return @pg_query($this->link, (string) $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }

    public function writes(): array
    {
        return array_values(array_filter($this->log, fn($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)/i', $q)));
    }
}

/**
 * The settings hub against a real PostgreSQL with CHIM 3.4.1 core-shaped rows and the four test
 * beds: Aela the Huntress (a friend), Ashe (Serene's hand-set vector, never read; she knew the player
 * once and the bond has fallen away since), Muiri (an acquaintance) and Lynly Star-Sung (never met).
 * The prompt-gating preview reads what the runtime reads and writes nothing; the page saves through
 * the config store (the stamped conf_opts row) and the runtime reads the edit at once; a reset puts
 * the row back. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynSettingsHubTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    /** name => [race, core affinity toward the player (null: no Player entry)] */
    private const BEDS = [
        'Aela the Huntress' => ['NordRace', 45],
        'Ashe'              => ['BretonRace', 0],
        'Muiri'             => ['BretonRace', 20],
        'Lynly Star-Sung'   => ['NordRace', null],
    ];
    private const TOKEN = 'beds-test-token-0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $dsn;
    private string $schema;
    private RelDynSettingsHubPgDb $db;
    private array $savedGlobals = [];
    private int $llmCalls = 0;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_hub_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // lib/core/database_schema/core_npc_master.sql
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, npc_name text NOT NULL, npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0, prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text,
            personality text, relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text)");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        // data/database_default.sql eventlog, speech; core 3.4.1 locations and core_player; quests
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE speech (sess varchar(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY,
            companions text, audios text, utterance_id text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_close($admin);

        $this->db = new RelDynSettingsHubPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', '_SESSION', 'RELATIONSHIP_SYSTEM_ENABLED'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $_SESSION = [RelDynSettings::CSRF_KEY => self::TOKEN];
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
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
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function seed(): void
    {
        foreach (self::BEDS as $name => [$race, $aff]) {
            $rel = $aff === null ? [] : ['Player' => ['aff' => $aff, 'type' => $aff >= 31 ? 'platonic' : 'neutral']];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '', '{}', json_encode(['relationships' => $rel])]);
        }
        // Whiterun, where the Companions are home, and the player one of them (tracked stat)
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES ('Jorrvaskr', 'Whiterun', 'Inn,', 1, 'Tamriel')");
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people) VALUES ($1, $2, $3, $4, $5, $6, $7)',
            ['infoloc', '(Context location: Jorrvaskr ,Hold: Whiterun, current weather: outdoors it is Pleasant)', 'pending', 1000, 1727100000, 1000,
             '|' . self::AELA . '|' . self::PLAYER . '|']);
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', ['The Companions Quests Completed', '3']);
        // Ashe knew him once (the tier floor: her peak), the bond has fallen away since. Written through
        // the runtime's own path: her stored state, the peak noted, saveDynamics.
        $ashe = RelationshipDynamics::getDynamics('Ashe');
        $ashe['_aff_mirror_x'] = 60.0;
        $ashe['dimensions']['affinity']['x'] = 60.0;   // core 20: an acquaintance
        RelDynGating::notePeak($ashe);
        $ashe['dimensions']['affinity']['x'] = 50.0;   // core 0 now
        RelationshipDynamics::saveDynamics('Ashe', $ashe);
        RelationshipDynamics::clearConfigCache();
    }

    /** md5 of the tables a preview could touch. */
    private function snapshot(): string
    {
        $out = '';
        foreach (['core_npc_master' => 'id', 'conf_opts' => 'id', 'core_player' => 'id', 'eventlog' => 'rowid', 'speech' => 'rowid'] as $t => $order) {
            $res = pg_query($this->db->link, "SELECT * FROM {$t} ORDER BY {$order}");
            $out .= $t . json_encode(pg_fetch_all($res) ?: []);
        }
        return md5($out);
    }

    private function storedRow(): ?array
    {
        $res = pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID]);
        $row = pg_fetch_assoc($res);
        return $row ? json_decode($row['value'], true) : null;
    }

    // ------------------------------------------------------------------

    public function testThePreviewReadsWhatTheRuntimeReadsAndWritesNothing(): void
    {
        $before = $this->snapshot();
        $this->db->log = [];
        $want = [
            // name => [level, knows the name, knows the story]
            self::AELA  => ['personal', true, true],
            'Ashe'      => ['lapsed', true, false],
            'Muiri'     => ['personal', true, false],
            self::LYNLY => ['renowned', false, false],   // never met, but in Whiterun the Companions are heard of
        ];
        foreach ($want as $npc => [$level, $name, $bio]) {
            $p = RelDynSettings::gatingPreview($npc);
            $runtime = RelDynGating::knowledge($npc);
            $this->assertSame($level, $p['knowledge']['level'], $npc);
            $this->assertSame([$runtime['level'], $runtime['name'], $runtime['bio']], [$p['knowledge']['level'], $p['knowledge']['name'], $p['knowledge']['bio']],
                "{$npc}: the preview reads what the runtime reads");
            $this->assertSame([$name, $bio], [$p['knowledge']['name'], $p['knowledge']['bio']], $npc);
            $this->assertSame($name ? null : "{$npc} does not know this person's name. Never call them \"Kaida\": address them as {$npc} would a stranger, unless they give their name in this conversation.",
                $p['name_unknown'], $npc);
            $this->assertSame($name, str_contains($p['text'], self::PLAYER), "{$npc}: the name in the text only when she knows it");
            $this->assertDoesNotMatchRegularExpression('/\d/', $p['text'], "{$npc}: feelings, not numbers");
        }
        $this->assertSame('stored', RelDynSettings::gatingPreview('Ashe')['state']);
        $this->assertSame('core', RelDynSettings::gatingPreview(self::AELA)['state']);
        $this->assertStringContainsString('Companions', RelDynSettings::gatingPreview(self::LYNLY)['text'], 'the rumour reaches the stranger');
        // every tier for every bed: still nothing written
        foreach (array_keys(self::BEDS) as $npc) {
            $tiers = RelDynSettings::gatingTierPreview($npc);
            $this->assertSame(array_keys(RelationshipDynamics::RELATIONSHIP_TIERS), array_keys($tiers));
            $this->assertTrue($tiers['devoted']['knowledge']['name']);
        }
        // a hostile name from the query string is data, never SQL
        $odd = RelDynSettings::gatingPreview("Ashe'); DELETE FROM conf_opts; --");
        $this->assertSame('renowned', $odd['knowledge']['level']);
        $this->assertSame('none', $odd['state']);
        // an override below her peak: Ashe still remembers him (you can only be unknown once)
        $this->assertSame('lapsed', RelDynSettings::gatingPreview('Ashe', 0.0)['knowledge']['level']);
        $this->assertSame([], $this->db->writes());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $this->llmCalls);
    }

    public function testAGatingEditReachesTheRuntimeAndAResetRestoresIt(): void
    {
        $note = RelDynSettings::encodePath(['prompt_gating', 'text', 'note', 'renowned']);
        $stranger = RelDynSettings::encodePath(['felt_steering', 'text', 'knowledge', 'renowned']);
        $r = RelDynSettings::handlePost(['csrf_token' => self::TOKEN, '_complete' => '1', 'f' => [
            $note => '{NAME} has only heard the stories.',
            $stranger => '{NAME} knows {PLAYER} only from the stories told in the mead hall.',
        ]]);
        $this->assertTrue($r['ok'] && $r['saved'], implode(' ', $r['errors']));
        $this->assertSame([
            'prompt_gating' => ['text' => ['note' => ['renowned' => '{NAME} has only heard the stories.']]],
            'felt_steering' => ['text' => ['knowledge' => ['renowned' => '{NAME} knows {PLAYER} only from the stories told in the mead hall.']]],
            'config_schema' => RelationshipDynamics::CONFIG_SCHEMA,
        ], $this->storedRow());

        // the runtime reads it at once: Lynly (never met, renowned here) gets the edited note and text
        $k = RelDynGating::knowledge(self::LYNLY);
        $this->assertSame('Lynly Star-Sung has only heard the stories.', RelDynGating::gateAnswer($k, self::LYNLY)['note']);
        $p = RelDynSettings::gatingPreview(self::LYNLY);
        $this->assertStringStartsWith('Lynly Star-Sung knows this stranger only from the stories', $p['text'], 'a stranger is not named');
        $this->assertStringContainsString('only from the stories told in the mead hall', $p['text']);
        $this->assertSame(RelDynGating::configDefaults()['text']['note']['stranger'], RelDynGating::config()['text']['note']['stranger'],
            'the notes not edited follow the defaults');

        $r = RelDynSettings::handlePost(['csrf_token' => self::TOKEN, '_complete' => '1', 'reset' => RelDynSettings::encodePath(['prompt_gating'])]);
        $this->assertTrue($r['ok']);
        $r = RelDynSettings::handlePost(['csrf_token' => self::TOKEN, '_complete' => '1', 'reset' => $stranger]);
        $this->assertTrue($r['ok']);
        $this->assertSame(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $this->storedRow());
        $this->assertSame(RelDynGating::configDefaults(), RelDynGating::config());

        // a forged POST writes nothing
        $this->db->log = [];
        $r = RelDynSettings::handlePost(['csrf_token' => 'forged', '_complete' => '1', 'f' => [$note => 'x']]);
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->db->writes());
    }

    public function testThePageSavesThroughPostgresAndItsPreviewWritesNothing(): void
    {
        $before = $this->snapshot();
        $r = $this->page('GET', ['tab' => 'gating', 'npc' => 'Ashe']);
        $this->assertSame(0, $r['code'], $r['stderr']);
        $this->assertStringContainsString('<td>lapsed</td>', $r['html']);
        $this->assertStringContainsString('Ashe', $r['html']);
        $this->assertSame($before, $this->snapshot(), 'a GET with a preview writes nothing');

        $mask = RelDynSettings::encodePath(['social_masking_enabled']);
        $budget = RelDynSettings::encodePath(['prompt_gating', 'token_budget']);
        $r = $this->page('POST', ['tab' => 'settings'], ['csrf_token' => self::TOKEN, '_complete' => '1',
            'f' => [$mask => '1', $budget => '120']]);
        $this->assertSame(0, $r['code'], $r['stderr']);
        $this->assertStringContainsString('Saved 2 settings', $r['html']);
        $this->assertSame(['social_masking_enabled' => true, 'prompt_gating' => ['token_budget' => 120],
            'config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $this->storedRow());
        RelationshipDynamics::clearConfigCache();
        $this->assertTrue(RelationshipDynamics::getConfig()['social_masking_enabled']);
        $this->assertSame(120, RelDynGating::config()['token_budget']);

        $r = $this->page('POST', ['tab' => 'settings'], ['csrf_token' => 'nope', '_complete' => '1', 'reset' => $mask]);
        $this->assertStringContainsString('Security check failed', $r['html']);
        $this->assertTrue($this->storedRow()['social_masking_enabled'], 'refused: still stored');
        $r = $this->page('POST', ['tab' => 'settings'], ['csrf_token' => self::TOKEN, '_complete' => '1', 'reset' => $mask]);
        $this->assertSame(['prompt_gating' => ['token_budget' => 120], 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA], $this->storedRow());
    }

    /** settings.php in its own php-cli process against this test's schema. */
    private function page(string $method, array $get, array $post = []): array
    {
        $spec = tempnam(sys_get_temp_dir(), 'rdhubpg_spec_');
        file_put_contents($spec, json_encode(['dsn' => $this->dsn, 'schema' => $this->schema, 'method' => $method, 'get' => $get,
            'post' => $post, 'token' => self::TOKEN, 'sessdir' => sys_get_temp_dir()]));
        $base = tempnam(sys_get_temp_dir(), 'rdhubpg_');
        @unlink($base);
        $harness = $base . '.php';
        file_put_contents($harness, <<<'PHP'
<?php
[$self, $page, $specFile] = $argv;
$spec = json_decode(file_get_contents($specFile), true);
$GLOBALS['chim_interaction_generation'] = 0;
final class RelDynHubPagePg
{
    public $link;
    public function __construct(string $dsn, string $schema) { $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW); pg_query($this->link, "SET search_path TO {$schema}"); }
    public function fetchOne($q, array $p = []) { $r = $p ? @pg_query_params($this->link, (string) $q, $p) : @pg_query($this->link, (string) $q); return $r ? (pg_fetch_assoc($r) ?: []) : []; }
    public function fetchAll($q) { $r = @pg_query($this->link, (string) $q); if (!$r) throw new RuntimeException(pg_last_error($this->link)); return pg_fetch_all($r) ?: []; }
    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q) { return @pg_query($this->link, (string) $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
    public function close() {}
}
$GLOBALS['DBDRIVER'] = 'postgresql';
$GLOBALS['db'] = new RelDynHubPagePg($spec['dsn'], $spec['schema']);
$GLOBALS['PLAYER_NAME'] = 'Kaida';
session_save_path($spec['sessdir']);
session_id('rdhubpg' . bin2hex(random_bytes(6)));
@session_start();
$_SESSION = ['reldyn_csrf' => $spec['token']];
$_SERVER['REQUEST_METHOD'] = $spec['method'];
$_SERVER['SCRIPT_NAME'] = '/HerikaServer/ext/relationship_dynamics/settings.php';
$_GET = $spec['get'];
$_POST = $spec['post'];
register_shutdown_function(function () { @session_destroy(); });
require $page;
PHP);
        try {
            $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $harness,
                dirname(__DIR__, 2) . '/ext/relationship_dynamics/settings.php', $spec], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
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
        return ['html' => $out, 'stderr' => $err, 'code' => $code];
    }
}
