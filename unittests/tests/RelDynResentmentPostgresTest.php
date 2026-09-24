<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynResentmentPgDb
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
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) $rows[] = $row;
        return $res ? $rows : false;
    }

    public function execQuery($q) { return @pg_query($this->link, $q); }

    public function insert($table, $data)
    {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(fn($v) => pg_escape_literal($this->link, (string) $v), array_values($data)));
        return @pg_query($this->link, "INSERT INTO {$table} ({$cols}) VALUES ({$vals})");
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Resentment / jealousy / conflict on a real PostgreSQL through the real hooks:
 *  - contract v1 eval items queued in the inbox are applied by postrequest (grievance with
 *    power gap from core data, bystander jealousy saved on the bystander's own row);
 *  - the calendar scan converts sustained jealousy into resentment for an NPC the player
 *    is not talking to;
 *  - prerequest opens a conflict when core lowered Player.aff by 10 within a session.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynResentmentPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;          // raw gamets per game day
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;    // raw gamets per game hour
    private const T0 = 300 * self::DAY;                                // game day 300

    private string $dsn;
    private string $schema;
    private RelDynResentmentPgDb $db;
    private array $ids = [];
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
        $this->schema = 'reldyn_resent' . getmypid() . '_' . bin2hex(random_bytes(3));

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
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))"); // prerequest gold ledger + profile the Attraction Matrix reads (RelDynPlayer)
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE eventlog (ts bigint, gamets bigint, type text, data text, people text, localts bigint, location text)");
        pg_close($admin);

        $this->db = new RelDynResentmentPgDb($dsn, $this->schema);

        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'CACHE_PEOPLE', 'CACHE_PARTY'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_resentment_pg_test.log');
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

    /** An NPC with RelDyn state; last contact and calendar step at T0. */
    private function seed(string $name, int $coreAff, string $coreType, array $dims, array $dynamics = [], array $ext = []): void
    {
        $dimensions = [];
        foreach ($dims as $dim => $x) {
            $dimensions[$dim] = ['x' => $x, 'baseline' => in_array($dim, ['resentment', 'resentment_self'], true) ? 0 : $x];
        }
        $dynamics += [
            'inferred_temperament' => 'Romantic',
            'attachment_style' => 'secure',
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_interest_vector' => [0.1, 0.2, 0.3],
            '_profile_autogen' => ['version' => 999],
            '_core_rel_type' => $coreType,
            '_last_contact_gamets' => self::T0,
            '_decay_last_game_gamets' => self::T0,
            'dimensions' => $dimensions,
        ];
        $ns = ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]];
        $ext += ['relationships' => ['Player' => ['aff' => $coreAff, 'type' => $coreType]]];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, (string) (100 + count($this->ids)), json_encode($ext), json_encode(['reldyn' => $ns])]));
        $this->ids[$name] = (int) $row['id'];
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    private function inbox(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'][RelDynStorage::KEY_EVAL_INBOX] ?? [];
    }

    private function setCoreAff(string $name, int $aff): void
    {
        pg_query_params($this->db->link,
            "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', $2::jsonb) WHERE id = $1",
            [$this->ids[$name], json_encode($aff)]);
    }

    private function item(string $npc, array $over): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => $npc, 'npc_id' => $this->ids[$npc], 'gamets' => (int) self::T0, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5,
            'positive_interaction' => false,
            'summary' => 'fixture',
        ], $over);
    }

    private function prerequest(string $name, float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) $gamets, 'Kaida: hello'];
        $GLOBALS['HERIKA_NAME'] = $name;
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    private function postrequest(string $name, float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) $gamets, 'Kaida: hello'];
        $GLOBALS['HERIKA_NAME'] = $name;
        $GLOBALS['RELDYN_NPC_NAME'] = $name;
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /**
     * A servant's grievance is weighted by the power gap read from core (Player.type and the
     * thrall faction), applied from the inbox by the postrequest hook and saved.
     */
    public function testQueuedGrievanceIsWeightedByPowerGapFromCoreData(): void
    {
        $dims = ['maturity' => 60.0, 'self_confidence' => 50.0, 'resentment' => 10.0];
        $this->seed('Lydia', 20, 'platonic', $dims, [],
            ['factions' => [['name' => 'DLC1ThrallFaction', 'rank' => 0]]]);
        $this->seed('Mjoll', 20, 'platonic', $dims);
        $this->assertTrue(RelationshipDynamics::queuePendingEval('Lydia', $this->item('Lydia',
            ['grievance' => ['flag' => true, 'kind' => 'being used', 'severity' => 1], 'summary' => 'ordered around'])));
        $this->assertTrue(RelationshipDynamics::queuePendingEval('Mjoll', $this->item('Mjoll',
            ['grievance' => ['flag' => true, 'kind' => 'being used', 'severity' => 1]])));

        $this->postrequest('Lydia', self::T0 + self::HOUR);
        $this->postrequest('Mjoll', self::T0 + self::HOUR);

        $lydia = $this->dynamics('Lydia');
        $mjoll = $this->dynamics('Mjoll');
        $lLog = end($lydia['dimensions']['resentment']['grievance_log']);
        $mLog = end($mjoll['dimensions']['resentment']['grievance_log']);
        $this->assertSame(1.0, (float) $lLog['power_gap'], 'thrall faction from core_npc_master');
        $this->assertEqualsWithDelta(10.0, (float) $lLog['raw'], 1e-9, '5 x (1 + 1)');
        $this->assertSame(0.0, (float) $mLog['power_gap']);
        $this->assertEqualsWithDelta(5.0, (float) $mLog['raw'], 1e-9);
        $this->assertGreaterThan((float) $mjoll['dimensions']['resentment']['x'], (float) $lydia['dimensions']['resentment']['x']);
        $this->assertSame([], $this->inbox('Lydia'), 'the item was consumed');
        $this->assertArrayNotHasKey('_eval_feelings_seen', $lydia, 'no permanent flag that would silence the local heuristics');
    }

    /**
     * The player is intimate with Lydia while Aela (core Player.type romantic) and Mjoll
     * (platonic) stand nearby: Aela's own row gains jealousy with Lydia as the rival. The
     * witnesses are the ones the exchange's eventlog rows recorded (item.witnesses), not
     * whoever is near Lydia when her next request applies the item days later: Muiri
     * (romantic) is there by then, Aela is not.
     */
    public function testIntimacyWithOneNpcMakesTheCommittedWitnessesJealous(): void
    {
        $dims = ['maturity' => 60.0, 'resentment' => 0.0];
        $this->seed('Lydia', 40, 'romantic', $dims);
        $this->seed('Aela', 60, 'romantic', $dims, ['inferred_temperament' => 'Jealous']);
        $this->seed('Mjoll', 60, 'platonic', $dims, ['inferred_temperament' => 'Jealous']);
        $this->seed('Muiri', 60, 'romantic', $dims, ['inferred_temperament' => 'Jealous']);
        RelationshipDynamics::queuePendingEval('Lydia', $this->item('Lydia',
            ['tags' => ['intimacy', 'touch'], 'positive_interaction' => true, 'witnesses' => ['Aela', 'Mjoll']]));
        $GLOBALS['CACHE_PEOPLE'] = '|Lydia|Muiri|Kaida|';   // who is around at consume time

        $this->postrequest('Lydia', self::T0 + 3 * self::DAY);

        $aela = $this->dynamics('Aela');
        $base = (float) RelationshipDynamics::defaultConfig()['jealousy_eval_gain'];
        $this->assertEqualsWithDelta($base * 2.0, (float) $aela['jealousy_anger'], 1e-9, 'intensity-1 event x Jealous 2.0');
        $this->assertSame('Lydia', $aela['jealousy_trigger_npc']);
        $this->assertSame(0.0, (float) ($this->dynamics('Mjoll')['jealousy_anger'] ?? 0.0), 'platonic: not jealous');
        $this->assertSame(0.0, (float) ($this->dynamics('Muiri')['jealousy_anger'] ?? 0.0), 'Muiri did not see it');
    }

    /** An item without witnesses (produced before they were recorded) makes nobody jealous. */
    public function testAnItemWithoutWitnessesMakesNobodyJealous(): void
    {
        $dims = ['maturity' => 60.0, 'resentment' => 0.0];
        $this->seed('Lydia', 40, 'romantic', $dims);
        $this->seed('Aela', 60, 'romantic', $dims, ['inferred_temperament' => 'Jealous']);
        RelationshipDynamics::queuePendingEval('Lydia', $this->item('Lydia',
            ['tags' => ['intimacy'], 'positive_interaction' => true]));
        $GLOBALS['CACHE_PEOPLE'] = '|Lydia|Aela|Kaida|';

        $this->postrequest('Lydia', self::T0 + self::HOUR);

        $this->assertSame(0.0, (float) ($this->dynamics('Aela')['jealousy_anger'] ?? 0.0), 'the people present now are not witnesses');
    }

    /** Aela stays jealous (65) while the player is away: the calendar scan turns it into resentment. */
    public function testCalendarScanConvertsJealousyIntoResentmentWhileThePlayerIsElsewhere(): void
    {
        $this->config(['neglect_enabled' => false]);
        $dims = ['maturity' => 60.0, 'resentment' => 5.0];
        $this->seed('Aela', 60, 'romantic', $dims, ['jealousy_anger' => 65.0]);
        $this->seed('Lydia', 20, 'platonic', $dims);

        $this->prerequest('Lydia', self::T0 + 4 * self::DAY);   // the scan steps Aela's calendar

        $aela = $this->dynamics('Aela');
        $this->assertEqualsWithDelta(9.0, (float) $aela['dimensions']['resentment']['x'], 1e-6, '5 + 4 days x 2 x 35/70 = 9 (decisions §5, as is)');
        $this->assertSame(65.0, (float) $aela['jealousy_anger'], 'jealousy does not cool with absence');
        $this->assertSame(5.0, (float) $this->dynamics('Lydia')['dimensions']['resentment']['x'], 'no jealousy, no conversion');
    }

    /** Core's eval lowered Player.aff by 12 within a session: the next prerequest opens a conflict. */
    public function testCoreAffinityDropWithinASessionOpensAConflictOnPrerequest(): void
    {
        $this->config(['neglect_enabled' => false]);
        $this->seed('Lydia', 30, 'platonic', ['maturity' => 60.0, 'resentment' => 0.0]);

        $this->prerequest('Lydia', self::T0 + self::HOUR);
        $this->assertEmpty($this->dynamics('Lydia')['in_conflict'] ?? false);

        $this->setCoreAff('Lydia', 18);                          // core relationship_system eval
        $this->prerequest('Lydia', self::T0 + 2 * self::HOUR);

        $this->assertTrue($this->dynamics('Lydia')['in_conflict'] ?? false);
    }
}
