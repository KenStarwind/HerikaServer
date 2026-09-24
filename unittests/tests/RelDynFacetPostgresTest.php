<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php';
require_once __DIR__ . '/RelDynFacetPreferencesTest.php';   // RelDynFacetProfiles

/** Minimal `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynFacetPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) {
            throw new RuntimeException("cannot connect to {$dsn}");
        }
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function execQuery($q) { return pg_query($this->link, $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * signed-preferences / internal-weather / appraisal-effects end to end on a real
 * PostgreSQL: preferences derived from the NPC's core_npc_master row and stored through the
 * merged save by the context hook; weather state and deprivation carried across prerequest
 * hooks on the game calendar.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynFacetPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 200 * self::DAY + 9 * self::HOUR;
    private const NPC = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynFacetPgDb $db;
    private int $id = 0;
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_facet' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Column types as lib/core/database_schema/core_npc_master.sql for what RelDyn touches.
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            refid text,
            personality text, speechstyle text, core text, npc_static_bio text,
            voiceid text, gender text, race text,
            metadata jsonb,
            extended_data jsonb,
            gamets_last_updated numeric,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE eventlog (rowid bigserial, ts bigint, gamets bigint, type text, data text, people text, localts bigint, location text)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_close($admin);

        $this->db = new RelDynFacetPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'CACHE_PEOPLE', 'CACHE_PARTY', 'contextDataFull'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_facet_pg_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function config(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['relationship_dynamics_config', json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** The huntress as core stores her, with RelDyn state (contact and calendar at T0). */
    private function seedHuntress(array $extraDynamics = []): void
    {
        $row = RelDynFacetProfiles::huntressRow();
        $ext = json_decode($row['extended_data'], true);
        $ext['relationships'] = ['Player' => ['aff' => 30, 'type' => 'platonic']];
        $dims = [];
        foreach (['comfort', 'trust', 'respect', 'maturity'] as $dim) {
            $x = RelationshipDynamics::getTemperamentBaseline('Independent', $dim);
            $dims[$dim] = ['x' => $x, 'baseline' => $x];
        }
        $dynamics = $extraDynamics + [
            'inferred_temperament' => 'Independent',
            'attachment_style' => 'secure',
            'traits' => [],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_interest_vector' => [0.1, 0.2, 0.3],
            '_profile_autogen' => ['version' => 999],
            '_core_rel_type' => 'platonic',
            '_last_contact_gamets' => self::T0,
            '_decay_last_game_gamets' => self::T0,
            'dimensions' => $dims,
        ];
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, personality, speechstyle, core, npc_static_bio, metadata, extended_data, plugin_extended_data)'
            . ' VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10::jsonb, $11::jsonb, $12::jsonb) RETURNING id',
            [self::NPC, '1a2b', $row['gender'], $row['race'], $row['voiceid'], $row['personality'], $row['speechstyle'], $row['core'],
             $row['npc_static_bio'], $row['metadata'], json_encode($ext),
             json_encode(['reldyn' => ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]]])]));
        $this->id = (int) $r['id'];
    }

    private function stored(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    private function hook(string $file, float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) $gamets, 'Kaida: what a place'];
        $GLOBALS['HERIKA_NAME'] = self::NPC;
        $GLOBALS['contextDataFull'] = [];
        (static function () use ($file) { require __DIR__ . "/../../ext/relationship_dynamics/{$file}"; })();
        RelationshipDynamics::endRequest();
    }

    public function testContextHookStoresPreferencesDerivedFromTheCoreRowAndTheFeeling(): void
    {
        $this->config([]);
        // The place read of her previous turn (the places lane fills it each turn): a library.
        $prefs = RelDynFacets::derivePreferences(RelDynFacetProfiles::huntressRow(), ['temperament' => 'Independent', 'traits' => []])['prefs'];
        $seed = ['inferred_temperament' => 'Independent'];
        RelDynFacets::placeTurn(self::NPC, $seed, 'The Arcanaeum', ['scholarly' => 1.0, 'confined' => 0.4, 'quiet' => 0.8], $prefs, self::T0);
        $this->seedHuntress(['_place_appraisal' => $seed['_place_appraisal']]);

        $this->hook('context.php', self::T0 + self::HOUR);

        $d = $this->stored();
        $this->assertEqualsWithDelta(1.0, $d['_facet_prefs']['prefs']['nature'], 0.1, 'derived from the core row in PostgreSQL');
        $this->assertEqualsWithDelta(-0.6, $d['_facet_prefs']['prefs']['scholarly'], 0.1);
        $feeling = implode("\n", array_map(fn($m) => (string) $m['content'], $GLOBALS['contextDataFull']));
        $this->assertStringContainsString('<place_feeling>', $feeling);
        $this->assertStringContainsString('restless', $feeling);
    }

    public function testWeatherAndDeprivationPersistAcrossPrerequestsOnTheGameCalendar(): void
    {
        $this->config(['facet_appraisal' => ['weather_roll_amplitude' => 0.0]]);
        $this->seedHuntress();

        $this->hook('prerequest.php', self::T0);
        $d = $this->stored();
        $this->assertSame('clear', $d['_internal_weather'], 'fresh NPC: nothing unfed yet');
        $this->assertArrayHasKey('nature', $d['_facet_fed'], 'loved facets start fed');
        $this->assertArrayHasKey('combat', $d['_facet_fed']);

        // Four game days without anything she loves (MDD 4.1 deprivation)
        $this->hook('prerequest.php', self::T0 + 4 * self::DAY);
        $d = $this->stored();
        $this->assertContains($d['_internal_weather'], ['overcast', 'stormy']);
        $this->assertEqualsWithDelta(1.0, $d['_weather_state']['deprivation'], 1e-9);
    }
}
