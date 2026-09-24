<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection; a failed query throws like CHIM's. */
final class RelDynPlacePgDb
{
    public $link;
    public array $statements = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function run(string $q)
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException(pg_last_error($this->link));
        return $res;
    }

    public function fetchOne($q, array $params = [])
    {
        if ($params) {
            $res = @pg_query_params($this->link, $q, $params);
            if (!$res) throw new RuntimeException(pg_last_error($this->link));
        } else {
            $res = $this->run($q);
        }
        return pg_fetch_assoc($res) ?: [];
    }

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
 * place-facets-core: the current place from CHIM core data only, on a real PostgreSQL with
 * core's eventlog and locations columns and the context strings the 3.4.1 plugin writes.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynPlaceContextPostgresTest extends TestCase
{
    private const DAY = 10000000;

    private string $dsn;
    private string $schema;
    private RelDynPlacePgDb $db;
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
        $this->schema = 'reldyn_place' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // data/database_default.sql eventlog; locations as 3.4.1 core has it (debug/db_updates.php).
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigint NOT NULL, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_close($admin);

        $this->db = new RelDynPlacePgDb($dsn, $this->schema);
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_place_context_test.log');
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

    private function event(string $type, int $gamets, string $data): void
    {
        $this->rowid++;
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, gamets, localts, ts, rowid, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            [$type, $data, $gamets, 1727000000 + $this->rowid, 1727000000 + $this->rowid, $this->rowid, '', '']);
    }

    /** A locations row the way the plugin fills it (live dwemer shapes, is_interior bitwise). */
    private function location(string $name, string $hold, string $tags, int $bits, string $world): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO locations (name, region, hold, tags, factions, is_interior, vanilla_location, world, cleared, chim_added) VALUES ($1, $2, $3, $4, $5, $6, true, $7, false, 0)',
            [$name, $hold, $hold, $tags, '', $bits, $world]);
    }

    public function testRiverwoodOutdoorsFromLiveRowShapes(): void
    {
        $this->location('Riverwood', 'Tamriel', 'Town,Habitation,', 130, 'Skyrim');
        $this->location('The Bannered Mare', 'Whiterun', 'Dwelling,Inn,', 150, '');
        $this->event('infoloc', 4124960, "(Context location: Riverwood outdoors ,Hold: Whiterun, Buildings to go:Embershard Mine (door/passage),Faendal's House (door/passage), Current Date in Skyrim World: Sundas, 9:53 AM, 17th of Last Seed, 4E 201)");
        $this->event('request', 4126140, '(Context location: Riverwood outdoors ,Hold: Whiterun, current date Sundas, 9:54 AM, 17th of Last Seed, 4E 201, current weather: Pleasant)');

        $ctx = RelDynFacets::currentPlaceContext('Faendal');
        $this->assertTrue($ctx['known']);
        $this->assertSame('Riverwood', $ctx['name']);
        $this->assertSame('Whiterun', $ctx['hold']);
        $this->assertSame(['Town', 'Habitation'], $ctx['tags']);
        $this->assertFalse($ctx['is_interior']);
        $this->assertSame(9.9, $ctx['hour'], 'gamets 4126140 = 9:54 AM, as the row says');
        $this->assertSame('day', $ctx['time_of_day']);
        $this->assertSame(['pleasant'], $ctx['weather']);

        $facets = RelDynFacets::placeFacets($ctx);
        $this->assertSame(0.6, $facets['social']);
        $this->assertSame(0.3, $facets['wild']);
    }

    public function testInteriorCellFromWeatherPrefixAndGameClock(): void
    {
        $this->location('The Bannered Mare', 'Whiterun', 'Dwelling,Inn,', 150, '');
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (201 * self::DAY + 9166667), 'Kaida: ale?'];   // 22:00
        $this->event('request', 201 * self::DAY + 9100000, '(Context location: The Bannered Mare ,Hold: Whiterun, current date Sundas, 9:50 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Cloudy, Raining)');

        $ctx = RelDynFacets::currentPlaceContext('Hulda');
        $this->assertSame('The Bannered Mare', $ctx['name']);
        $this->assertTrue($ctx['is_interior'], "the plugin prefixes the weather with 'outdoors it is' only indoors");
        $this->assertSame(['cloudy', 'rain'], $ctx['weather']);
        $this->assertSame('night', $ctx['time_of_day'], 'from the request game clock');
        $this->assertSame(['Dwelling', 'Inn'], $ctx['tags']);

        $facets = RelDynFacets::placeFacets($ctx);
        $this->assertSame(0.9, $facets['social']);
        $this->assertArrayNotHasKey('dark', $facets, 'night outside does not darken the inn');
        $this->assertArrayNotHasKey('wild', $facets, 'rain outside does not reach the inn');
    }

    public function testNewestContextRowWinsAndWeatherComesFromTheLastReport(): void
    {
        $this->location('Bleak Falls Barrow', 'Whiterun', 'Dungeon,Draugr Crypt,Nordic Ruin,', 5, 'Skyrim');
        $this->event('request', 4000000, '(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Snowing)');
        $this->event('infoloc', 4100000, '(Context location: Bleak Falls Barrow interior ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ...)');

        $ctx = RelDynFacets::currentPlaceContext('Lydia');
        $this->assertSame('Bleak Falls Barrow', $ctx['name']);
        $this->assertTrue($ctx['is_interior']);
        $this->assertSame(['Dungeon', 'Draugr Crypt', 'Nordic Ruin'], $ctx['tags']);
        $this->assertSame(['snow'], $ctx['weather'], 'last reported weather');

        $facets = RelDynFacets::placeFacets($ctx);
        foreach (['adventure', 'combat', 'danger', 'confined', 'dark', 'spiritual'] as $f) {
            $this->assertArrayHasKey($f, $facets, $f);
        }
        $this->assertArrayNotHasKey('wild', $facets, 'snow outside does not reach the crypt');
    }

    /** No suffix, no weather: interior cell unless the exact row is a city worldspace. */
    public function testSuffixlessNamesUseTheLocationsWorld(): void
    {
        $this->location('Whiterun', 'Tamriel', 'Habitation,City,', 130, 'Whiterun');
        $this->location('Embershard Mine', 'Falkreath', 'Dungeon,Bandit Camp,Cave,', 5, 'Skyrim');

        $this->event('infoloc', 4000000, '(Context location: Whiterun ,Hold: Whiterun Hold, Buildings to go:Dragonsreach (door/passage), Current Date in Skyrim World: ...)');
        $city = RelDynFacets::currentPlaceContext('Lydia');
        $this->assertFalse($city['is_interior'], 'Whiterun city worldspace is outside');
        $this->assertSame(0.8, RelDynFacets::placeFacets($city)['crowd']);

        $this->event('infoloc', 4000100, '(Context location: Embershard Mine ,Hold: Falkreath Hold, Buildings to go:, Current Date in Skyrim World: ...)');
        $mine = RelDynFacets::currentPlaceContext('Lydia');
        $this->assertTrue($mine['is_interior'], 'a Skyrim-worldspace location without the outdoors suffix is its interior');

        $this->event('infoloc', 4000200, '(Context location: Dragonsreach ,Hold: Whiterun Hold, Buildings to go:, Current Date in Skyrim World: ...)');
        $keep = RelDynFacets::currentPlaceContext('Lydia');
        $this->assertTrue($keep['is_interior'], 'an interior cell with no locations row');
        $this->assertSame([], $keep['tags']);
        $this->assertSame(0.6, RelDynFacets::placeFacets($keep)['luxury'], 'described by its name');
    }

    public function testInteriorSubCellInheritsItsLocationRowAndQuotesAreSafe(): void
    {
        $this->location('Mzinchaleft', 'The Pale', 'Dungeon,Dwarven Ruin,', 5, 'Skyrim');
        $this->location("Faendal's House", 'Whiterun', 'House,Dwelling,', 150, '');

        $this->event('infoloc', 4000000, '(Context location: Mzinchaleft Depths ,Hold: The Pale, Buildings to go:, Current Date in Skyrim World: ...)');
        $ctx = RelDynFacets::currentPlaceContext('Ashe');
        $this->assertSame(['Dungeon', 'Dwarven Ruin'], $ctx['tags']);
        $this->assertTrue($ctx['is_interior']);
        $v = RelDynFacets::placeFacets($ctx);
        foreach (['scholarly', 'crafting', 'enchanting', 'adventure', 'combat', 'danger', 'confined'] as $f) {
            $this->assertArrayHasKey($f, $v, $f);
        }

        $this->event('infoloc', 4000100, "(Context location: Faendal's House ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ...)");
        $house = RelDynFacets::currentPlaceContext('Ashe');
        $this->assertSame(['House', 'Dwelling'], $house['tags']);
        $this->assertSame(0.9, RelDynFacets::placeFacets($house)['domestic']);
    }

    /**
     * The place read (RelDynFacets::contextTurn -> placeTurn) reads the core place straight from
     * the eventlog / locations tables, so it does not need core's CACHE_LOCATION; loved facets
     * the place carries strongly enough are stamped fed (internal weather's deprivation).
     */
    public function testPlaceReadFeedsLovedFacetsWithoutCacheLocation(): void
    {
        // core_npc_master with the columns core 3.4.1 has and fetchCoreProfileRow reads.
        pg_query($this->db->link, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, npc_name text, gender text, race text,
            voiceid text, personality text, speechstyle text, core text, npc_static_bio text, metadata text, extended_data text)");
        pg_query($this->db->link, "INSERT INTO core_npc_master (npc_name, extended_data) VALUES ('Ashe', '{}')");
        $this->location('Mzinchaleft', 'The Pale', 'Dungeon,Dwarven Ruin,', 5, 'Skyrim');
        $this->event('infoloc', 4000000, '(Context location: Mzinchaleft ,Hold: The Pale, Buildings to go:, Current Date in Skyrim World: ...)');
        unset($GLOBALS['CACHE_LOCATION']);

        $dyn = ['facet_pref_overrides' => ['scholarly' => 0.9, 'adventure' => 0.6, 'crafting' => 0.8, 'nature' => 0.9]];
        $turn = RelDynFacets::contextTurn('Ashe', $dyn, 4000000.0);
        $this->assertSame('Mzinchaleft', $turn['place']);
        $this->assertSame('Mzinchaleft', $dyn['_place_appraisal']['place']);
        $this->assertGreaterThan(0.0, $dyn['_place_appraisal']['valence']);
        foreach (['adventure', 'scholarly', 'crafting'] as $fed) {
            $this->assertSame(4000000.0, $dyn['_facet_fed'][$fed] ?? null, $fed);
        }
        // nature is loved but the ruin has none of it: not fed by being here (first sight only
        // starts its clock in updateWeather, never here).
        $this->assertArrayNotHasKey('nature', $dyn['_facet_fed']);
    }

    public function testWildernessHasNoNameAndIsOutside(): void
    {
        $this->event('infoloc', 4000000, '(Context location: , Buildings to go:, Current Date in Skyrim World: ...)');
        $ctx = RelDynFacets::currentPlaceContext('Aela');
        $this->assertTrue($ctx['known']);
        $this->assertSame('', $ctx['name']);
        $this->assertFalse($ctx['is_interior']);
        $this->assertSame(['nature' => 0.8, 'wild' => 0.8], RelDynFacets::placeFacets($ctx));
    }

    public function testNoLocationDataIsUnknownNotAGuess(): void
    {
        $ctx = RelDynFacets::currentPlaceContext('Aela');
        $this->assertFalse($ctx['known']);
        $this->assertNull($ctx['is_interior']);
        $this->assertNull($ctx['time_of_day']);
        $this->assertSame([], RelDynFacets::placeFacets($ctx));
    }
}
