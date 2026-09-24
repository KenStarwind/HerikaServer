<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection; a failed query throws like CHIM's. */
final class RelDynEnvPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function run(string $q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException(pg_last_error($this->link));
        return $res;
    }

    public function fetchOne($q, array $params = []) { return pg_fetch_assoc($this->run($q, $params)) ?: []; }

    public function fetchAll($q, $log = false)
    {
        $res = $this->run($q);
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function execQuery($q) { return $this->run($q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * environmental-modifiers: the April dead code, rewired on the core place facets and the
 * game clock. Danger and dark move arousal / comfort for anyone; dawn and dusk their rows.
 * Applied once per environment change, the previous effects reversed first.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynEnvironmentModifiersPostgresTest extends TestCase
{
    private const DAY = 10000000;
    private const HOUR = self::DAY / 24;

    private string $dsn;
    private string $schema;
    private RelDynEnvPgDb $db;
    private array $saved = [];
    private int $rowid = 0;

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
        $this->schema = 'reldyn_env' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigint NOT NULL, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "INSERT INTO locations (name, region, hold, tags, is_interior, world) VALUES
            ('Bleak Falls Barrow', 'Whiterun', 'Tamriel', 'Dungeon,Draugr Crypt,Nordic Ruin,', 5, 'Skyrim'),
            ('The Bannered Mare', 'Whiterun', 'Whiterun', 'Dwelling,Inn,', 150, ''),
            ('Riverwood', 'Whiterun', 'Tamriel', 'Town,Habitation,', 130, 'Skyrim')");
        pg_close($admin);

        $this->db = new RelDynEnvPgDb($dsn, $this->schema);
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'HERIKA_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_env_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** The player is at $place at game hour $hour of day 201 (request clock + eventlog context). */
    private function at(string $contextData, float $hour): void
    {
        $g = (int) round(201 * self::DAY + $hour * self::HOUR);
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) $g, 'Kaida: hm'];
        $this->rowid++;
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, gamets, localts, ts, rowid, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['request', $contextData, $g - 100, 1727000000 + $this->rowid, 1727000000 + $this->rowid, $this->rowid, '', '']);
    }

    private static function dynamics(): array
    {
        return ['dimensions' => [
            'arousal' => ['x' => 10.0, 'baseline' => 10], 'comfort' => ['x' => 50.0, 'baseline' => 50],
            'valence' => ['x' => 0.0, 'baseline' => 0], 'warmth' => ['x' => 40.0, 'baseline' => 40],
            'passion' => ['x' => 20.0, 'baseline' => 0], 'trust' => ['x' => 50.0, 'baseline' => 50],
        ]];
    }

    public function testEffectsFromFacetsAndHourUseConfigValues(): void
    {
        // Draugr crypt: danger 0.7, dark 0.7 -> arousal 0.7x15 + 0.7x5, comfort 0.7x-3
        $crypt = RelDynFacets::placeFacets(['name' => 'Bleak Falls Barrow', 'tags' => 'Dungeon,Draugr Crypt,Nordic Ruin,',
            'is_interior' => true, 'time_of_day' => 'night', 'weather' => []]);
        $this->assertSame(['arousal' => 14.0, 'comfort' => -2.1], RelationshipDynamics::environmentEffects($crypt, 'night'));

        // Riverwood at dusk: dark 0.3 outside -> arousal 1.5, comfort -0.9; dusk row passion +3 warmth +2
        $town = RelDynFacets::placeFacets(['name' => 'Riverwood', 'tags' => 'Town,Habitation,',
            'is_interior' => false, 'time_of_day' => 'dusk', 'weather' => []]);
        $this->assertSame(['arousal' => 1.5, 'comfort' => -0.9, 'passion' => 3.0, 'warmth' => 2.0],
            RelationshipDynamics::environmentEffects($town, 'dusk'));

        // An inn at noon: nothing universal about it (liking it is the appraisal's job)
        $inn = RelDynFacets::placeFacets(['name' => 'The Bannered Mare', 'tags' => 'Dwelling,Inn,',
            'is_interior' => true, 'time_of_day' => 'day', 'weather' => []]);
        $this->assertSame([], RelationshipDynamics::environmentEffects($inn, 'day'));
    }

    public function testAppliedOnceThenReversedWhenThePlaceChanges(): void
    {
        $dyn = self::dynamics();
        $this->at('(Context location: Bleak Falls Barrow interior ,Hold: Whiterun, current date ..., current weather: outdoors it is Cloudy)', 23.0);

        $applied = RelationshipDynamics::applyEnvironmentalModifiers($dyn, 'Lydia', 'Kaida', 'Stoic');
        $this->assertGreaterThan(0.0, $applied['arousal'] ?? 0.0, 'the crypt keeps her alert');
        $this->assertLessThan(0.0, $applied['comfort'] ?? 0.0, 'and uneasy in the dark');
        $this->assertGreaterThan(10.0, $dyn['dimensions']['arousal']['x']);
        $this->assertLessThan(50.0, $dyn['dimensions']['comfort']['x']);
        $this->assertSame($applied, $dyn['_env_applied_effects']);
        $this->assertStringStartsWith('Bleak Falls Barrow|night|', $dyn['_active_environment']);

        // Same place, same hour band: not applied again
        $arousal = $dyn['dimensions']['arousal']['x'];
        $this->assertSame([], RelationshipDynamics::applyEnvironmentalModifiers($dyn, 'Lydia', 'Kaida', 'Stoic'));
        $this->assertSame($arousal, $dyn['dimensions']['arousal']['x']);

        // Into the inn: the crypt's effects are reversed, the inn adds none
        $this->at('(Context location: The Bannered Mare ,Hold: Whiterun, current date ..., current weather: outdoors it is Cloudy)', 23.5);
        $this->assertSame([], RelationshipDynamics::applyEnvironmentalModifiers($dyn, 'Lydia', 'Kaida', 'Stoic'));
        $this->assertSame([], $dyn['_env_applied_effects']);
        $this->assertEqualsWithDelta(10.0, $dyn['dimensions']['arousal']['x'], 1e-9, 'arousal back exactly: no residue');
        $this->assertEqualsWithDelta(50.0, $dyn['dimensions']['comfort']['x'], 1e-9);
        $this->assertStringStartsWith('The Bannered Mare|night|', $dyn['_active_environment']);
    }

    public function testDawnOutsideLiftsMood(): void
    {
        $dyn = self::dynamics();
        $this->at('(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Pleasant)', 6.0);
        $applied = RelationshipDynamics::applyEnvironmentalModifiers($dyn, 'Lydia', 'Kaida', 'Stoic');
        $this->assertGreaterThan(0.0, $applied['valence'] ?? 0.0);
        $this->assertGreaterThan(0.0, $applied['comfort'] ?? 0.0);
        $this->assertArrayNotHasKey('arousal', $applied);
    }

    public function testSwitchedOffOrNoCoreLocationChangesNothing(): void
    {
        $dyn = self::dynamics();
        $this->assertSame([], RelationshipDynamics::applyEnvironmentalModifiers($dyn, 'Lydia', 'Kaida', 'Stoic'), 'no location context');
        $this->assertSame(10.0, $dyn['dimensions']['arousal']['x']);

        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', ['relationship_dynamics_config',
            json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'environment_modifiers_enabled' => false])]);
        RelationshipDynamics::clearConfigCache();
        $this->at('(Context location: Bleak Falls Barrow interior ,Hold: Whiterun, current date ..., current weather: outdoors it is Cloudy)', 23.0);
        $this->assertSame([], RelationshipDynamics::applyEnvironmentalModifiers($dyn, 'Lydia', 'Kaida', 'Stoic'));
        $this->assertSame(10.0, $dyn['dimensions']['arousal']['x']);
    }
}
