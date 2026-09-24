<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

// MinAI's actor-value functions, present as if MinAI were loaded. RelDyn must never call
// them (minai-sensor-bridge: core features use CHIM core data); a call fails the test.
if (!function_exists('GetActorValue')) {
    function GetActorValue(...$args) { throw new LogicException('RelDyn called MinAI GetActorValue(' . json_encode($args) . ')'); }
}
if (!function_exists('IsEnabled')) {
    function IsEnabled(...$args) { throw new LogicException('RelDyn called MinAI IsEnabled(' . json_encode($args) . ')'); }
}
if (!function_exists('IsInFaction')) {
    function IsInFaction(...$args) { throw new LogicException('RelDyn called MinAI IsInFaction(' . json_encode($args) . ')'); }
}

/** `sql`-compatible adapter over one pg connection; a failed query throws like CHIM's. */
final class RelDynSensorPgDb
{
    public $link;
    public array $statements = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function run(string $q, array $params = [])
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
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
 * minai-sensor-bridge: every signal RelDyn used to read from MinAI now comes from CHIM
 * 3.4.1 core data (eventlog combat events, location context, weather, game clock) or is an
 * honest "unknown". MinAI's conf_opts keys and functions are present here and must be
 * ignored. Real PostgreSQL with core's eventlog / locations / conf_opts columns.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCoreSensorsPostgresTest extends TestCase
{
    private const DAY = 10000000;
    private const NOW = 201 * self::DAY + 5000000;   // noon, game day 201
    private const PLAY_SECOND = RelationshipDynamics::GAMETS_PER_REAL_SECOND;

    private string $dsn;
    private string $schema;
    private RelDynSensorPgDb $db;
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
        $this->schema = 'reldyn_sens' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigint NOT NULL, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE oghma (topic text, topic_desc text, knowledge_class text, tags text, category text)");
        // What MinAI would have written: RelDyn must not read any of it.
        foreach ([
            ['minai_combat_lydia', '{"inCombat":true,"healthPct":0.1,"bleedingOut":true}'],
            ['inCombat', '1'],
            ['_minai_lydia//incombat', 'true'],
            ['_minai_lydia//locationkeywords', 'loctypedwarvenruin~loctypedungeon'],
            ['_minai_lydia//isinterior', 'true'],
            ['_minai_lydia//issneaking', 'true'],
            ['_minai_kaida//currentgamehour', '23'],
        ] as [$id, $value]) {
            pg_query_params($admin, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', [$id, $value]);
        }
        pg_close($admin);

        $this->db = new RelDynSensorPgDb($dsn, $this->schema);
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'HERIKA_NAME', 'RELDYN_NPC_NAME', 'LAST_LLM_RESPONSE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['HERIKA_NAME'] = 'Lydia';
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::NOW, 'Kaida: are you hurt?'];
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_core_sensors_test.log');
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

    private function event(string $type, int $gamets, string $data, string $people = ''): void
    {
        $this->rowid++;
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, gamets, localts, ts, rowid, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            [$type, $data, $gamets, 1727000000 + $this->rowid, 1727000000 + $this->rowid, $this->rowid, $people, '']);
    }

    private function location(string $name, string $tags, string $world): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO locations (name, region, hold, tags, factions, is_interior, vanilla_location, world, cleared, chim_added) VALUES ($1, $2, $2, $3, $4, 5, true, $5, false, 0)',
            [$name, 'The Pale', $tags, '', $world]);
    }

    private function assertNoMinaiRead(): void
    {
        foreach ($this->db->statements as $sql) {
            $this->assertDoesNotMatchRegularExpression('/minai|incombat/i', $sql, 'RelDyn read a MinAI source');
        }
    }

    // ------------------------------------------------------------------ combat

    /** Core 3.4.1 combat: combatbark is logged as infoaction "(X shouts during combat)". */
    public function testCombatBarkMeansInCombatFromCoreEventlog(): void
    {
        $this->event('infoaction', self::NOW - 20 * self::PLAY_SECOND, 'Lydia: For Whiterun! (Lydia shouts during combat)');

        $ctx = RelationshipDynamics::getCombatContext('Lydia');
        $this->assertTrue($ctx['in_combat']);
        $this->assertFalse($ctx['bleeding_out']);
        $this->assertNull($ctx['health_pct'], 'core 3.4.1 has no NPC health: unknown, not 100%');
        $this->assertSame('eventlog', $ctx['source']);
        // Talking right after fighting together is acts of service (classifyInteraction).
        $this->assertSame(RelationshipDynamics::LL_SERVICE, RelationshipDynamics::classifyInteraction($GLOBALS['gameRequest']));
        $this->assertNoMinaiRead();
    }

    public function testCombatEndClosesTheFightButItStaysRecent(): void
    {
        $this->event('infoaction', self::NOW - 90 * self::PLAY_SECOND, 'Lydia: Die! (Lydia shouts during combat)');
        $this->event('death', self::NOW - 80 * self::PLAY_SECOND, 'Lydia has defeated Bandit Marauder', '|Lydia|Kaida|');
        $this->event('combatend', self::NOW - 60 * self::PLAY_SECOND, '(Context location: Bleak Falls Barrow interior ,Hold: Whiterun)');

        $ctx = RelationshipDynamics::getCombatContext('Lydia');
        $this->assertFalse($ctx['in_combat']);
        $this->assertSame(1, $ctx['recent_kills']);
        // "In combat recently" (post-combat talk is acts of service): yes, 60 s ago.
        $this->assertSame(RelationshipDynamics::LL_SERVICE, RelationshipDynamics::classifyInteraction($GLOBALS['gameRequest']));
        $this->assertNoMinaiRead();
    }

    public function testBleedoutOfThisNpcFromCoreEvents(): void
    {
        $this->event('infoaction', self::NOW - 30 * self::PLAY_SECOND, 'Lydia: Aargh (Lydia shouts during combat)');
        $this->event('bleedout', self::NOW - 10 * self::PLAY_SECOND, '(Context location: Bleak Falls Barrow interior ,Hold: Whiterun)Lydia falls to the ground almost unconscious');
        $ctx = RelationshipDynamics::getCombatContext('Lydia');
        $this->assertTrue($ctx['in_combat']);
        $this->assertTrue($ctx['bleeding_out']);

        $this->assertFalse(RelationshipDynamics::getCombatContext('Faendal')['bleeding_out'] ?? false, 'someone else went down');

        $this->event('instruction', self::NOW - 5 * self::PLAY_SECOND, 'Faendal has lost combat and is wounded bleedingout.');
        $this->assertTrue(RelationshipDynamics::getCombatContext('Faendal')['bleeding_out']);
    }

    public function testOldCombatAndMinaiFlagsAreNotCombat(): void
    {
        $window = RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS;
        $this->event('infoaction', (int) (self::NOW - $window - 1000), 'Lydia: Die! (Lydia shouts during combat)');

        // conf_opts holds MinAI's minai_combat_lydia (bleeding out, 10% HP), inCombat=1 and
        // _minai_lydia//incombat=true: none of it counts.
        $this->assertNull(RelationshipDynamics::getCombatContext('Lydia'));
        $this->assertSame(RelationshipDynamics::LL_TIME, RelationshipDynamics::classifyInteraction($GLOBALS['gameRequest']), 'plain talk, not post-combat');
        $this->assertNoMinaiRead();
    }

    public function testCombatRequestTypeStillMeansCombat(): void
    {
        $GLOBALS['gameRequest'] = ['combatend', '1727000000', (string) self::NOW, '(Context location: Riverwood outdoors ,Hold: Whiterun)'];
        $ctx = RelationshipDynamics::getCombatContext('Lydia');
        $this->assertTrue($ctx['in_combat']);
        $this->assertSame('event', $ctx['source']);
    }

    // ------------------------------------------------------------------ place / interest

    public function testPlaceFacetsComeFromTheCorePlace(): void
    {
        $this->location('Mzinchaleft', 'Dungeon,Dwarven Ruin,', 'Skyrim');
        $this->event('infoloc', self::NOW - 1000, '(Context location: Mzinchaleft ,Hold: The Pale, Buildings to go:, Current Date in Skyrim World: ...)');
        $GLOBALS['RELDYN_NPC_NAME'] = 'Lydia';

        $facets = RelDynFacets::placeFacets(RelDynFacets::currentPlaceContext('Lydia'));
        $this->assertSame(0.8, $facets['adventure']);
        $this->assertSame(0.6, $facets['scholarly']);
        $this->assertNoMinaiRead();
    }

    public function testPlaceIsUnknownWithoutCoreLocation(): void
    {
        // Only MinAI's _minai_lydia//locationkeywords / isinterior / issneaking exist.
        $GLOBALS['RELDYN_NPC_NAME'] = 'Lydia';
        $ctx = RelDynFacets::currentPlaceContext('Lydia');
        $this->assertFalse($ctx['known']);
        $this->assertSame([], RelDynFacets::placeFacets($ctx));
        $this->assertNoMinaiRead();
    }

    // ------------------------------------------------------------------ physical states

    public function testWeatherStatesFromCoreWeatherOutside(): void
    {
        $this->event('request', self::NOW - 1000, '(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Snowing)');
        $states = RelationshipDynamics::detectPhysicalStates('Lydia', 'Kaida');
        $this->assertSame(['snowing', 'cold'], $states);

        $this->event('request', self::NOW - 500, '(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Cloudy, Raining)');
        $this->assertSame(['raining'], RelationshipDynamics::detectPhysicalStates('Lydia', 'Kaida'));
        $this->assertNoMinaiRead();
    }

    public function testWeatherOutsideDoesNotReachIndoors(): void
    {
        $this->event('request', self::NOW - 1000, '(Context location: The Bannered Mare ,Hold: Whiterun, current date ..., current weather: outdoors it is Snowing)');
        $this->assertSame([], RelationshipDynamics::detectPhysicalStates('Lydia', 'Kaida'));
    }

    public function testClearNightNeedsKnownClearWeatherOutsideAtNight(): void
    {
        $night = 201 * self::DAY + 9166667;   // 22:00
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) $night, 'Kaida: look up'];
        $this->event('request', $night - 1000, '(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Pleasant)');
        $this->assertSame(['clear_night'], RelationshipDynamics::detectPhysicalStates('Lydia', 'Kaida'));

        // Noon (the MinAI key says hour 23, ignored): no clear night.
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::NOW, 'Kaida: look up'];
        $this->assertSame([], RelationshipDynamics::detectPhysicalStates('Lydia', 'Kaida'));
    }

    // ------------------------------------------------------------------ items / reputation

    public function testItemClassificationSkipsTheMissingMinaiItemsTable(): void
    {
        pg_query($this->db->link, "INSERT INTO oghma (topic, knowledge_class, category) VALUES ('ebony_blade', 'blacksmith', 'artifacts')");
        $this->assertSame('crafting', RelationshipDynamics::classifyItemInterest('Ebony Blade'));
        $this->assertSame('alchemy', RelationshipDynamics::classifyItemInterest('Potion of Healing'));
        $this->assertNoMinaiRead();
        $this->assertStringNotContainsString('ERROR', (string) @file_get_contents(sys_get_temp_dir() . '/reldyn_core_sensors_test.log'));
    }

    public function testConsumablesComeFromTheEventlogNotMinaiFlags(): void
    {
        // IsEnabled() exists (it throws if called); the only consume signal is core's itemfound.
        $events = RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'Lydia', 'Kaida');
        $this->assertSame([], $events);

        $this->event('itemfound', self::NOW - 1000, 'Lydia drank Nord Mead');
        $events = RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'Lydia', 'Kaida');
        $this->assertSame('consume', $events[0]['action'] ?? null);
    }

    public function testReputationHasNoMinaiSource(): void
    {
        $dyn = ['interaction_count' => 0, 'dimensions' => ['respect' => ['x' => 50], 'trust' => ['x' => 50], 'comfort' => ['x' => 50]]];
        RelationshipDynamics::applyReputationModifiers($dyn, 'Kaida', 'Lydia', 'Stoic');
        $this->assertSame(50, $dyn['dimensions']['respect']['x'], 'no core player-stats source yet: unknown, nothing applied');
        $this->assertNull(RelationshipDynamics::detectNpcHold('Lydia'), 'no location context: unknown');
        $this->assertSame(0.0, RelationshipDynamics::calculateFactionReputation([], []), 'memberships unknown');
        $this->assertSame(8.0, RelationshipDynamics::calculateFactionReputation(['The Companions'], ['The Circle']));
        $this->assertSame(-4.0, RelationshipDynamics::calculateFactionReputation(['Stormcloaks'], ['Imperial Legion']));
        $this->assertSame(['trust' => 0.0, 'respect' => 0.0, 'comfort' => 0.0],
            RelationshipDynamics::calculateReputation('Kaida', 'Lydia', 'Stoic', []));
        $this->assertNoMinaiRead();
    }

    public function testReputationStatsMathAndThaneHoldFromCorePlace(): void
    {
        $this->event('request', self::NOW - 1000, '(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Pleasant)');
        $this->assertSame('Whiterun', RelationshipDynamics::detectNpcHold('Lydia'));
        $mods = RelationshipDynamics::calculateReputation('Kaida', 'Lydia', 'Stoic',
            ['dragon_kills' => 2, 'thane_holds' => ['Whiterun'], 'bounty_gold' => 250]);
        // dragons 2 x (respect 2, trust 1); thane respect 5 comfort 5; crimes 2 x (trust -1, comfort -0.5)
        $this->assertEqualsWithDelta(['trust' => 0.0, 'respect' => 9.0, 'comfort' => 4.0], $mods, 1e-9);
    }
}
