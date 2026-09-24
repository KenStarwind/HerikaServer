<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
// Loaded as in production (main.php), so currentGamets() takes the same path whatever ran before.
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Minimal `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynEvalConsumerPgDb
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

    /** Test seam: callable(string $sql): ?array. A non-null return replaces running the statement; it may throw. */
    public $beforeQuery = null;

    public function fetchOne($q, array $params = [])
    {
        if ($this->beforeQuery !== null && ($replaced = ($this->beforeQuery)((string) $q)) !== null) {
            return $replaced;
        }
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        if ($this->beforeQuery !== null && ($replaced = ($this->beforeQuery)((string) $q)) !== null) {
            return $replaced;
        }
        $res = pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function execQuery($q)
    {
        return pg_query($this->link, $q);
    }

    /** lib/postgresql.class.php insert(): parameterized INSERT of an assoc row. */
    public function insert($table, $data)
    {
        $placeholders = [];
        foreach (array_keys($data) as $i => $_) {
            $placeholders[] = '$' . ($i + 1);
        }
        $params = array_map(fn($v) => (is_array($v) || is_object($v)) ? json_encode($v) : $v, array_values($data));
        return pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $placeholders) . ')', $params);
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * eval-consumer end to end on a real PostgreSQL: a shared-eval-contract item in
 * plugin_extended_data.reldyn.eval_inbox -> processPendingEvalDeltas() (the MDD 15.4 pipeline
 * with the decisions §1 relative affinity multipliers) -> commitPlayerAffinity() (locked
 * delta on core relationships.Player.aff) -> saveDynamics(), in the order postrequest.php
 * runs them. The NPC's profile (temperament, traits, maturity type) comes from the real
 * auto-generation on the core row.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynEvalConsumerPostgresTest extends TestCase
{
    private const NPC = 'Nazeem';

    private string $dsn;
    private string $schema;
    private RelDynEvalConsumerPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;

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
        $this->schema = 'reldyn_tmp' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns as in lib/core/database_schema/core_npc_master.sql and core_npc_master_history.sql
        // (no core_profiles FK): core's relationship timeline stamp runs after the affinity write.
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
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        // core_player: the player profile the Attraction Matrix reads each request (RelDynPlayer::profile)
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // Same columns as data/database_default.sql eventlog: the game clock outside a request
        // (DataLastKnownGameTS) reads it.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_close($admin);

        $this->db = new RelDynEvalConsumerPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELDYN_PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['gameRequest'], $GLOBALS['HERIKA_NAME'], $GLOBALS['RELDYN_PLAYER_NAME']);
        // The consuming postrequest's own request (raw gamets just after the items' exchange).
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', '987654400', 'Kaida: hello'];
        $GLOBALS['db'] = $this->db;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-evalpg-');
        ini_set('error_log', $this->errorLog);

        // Debug log on (the math lines); passion off so gains carry no passion multiplier here
        // (the passion row is covered in RelDynEvalPipelineTest).
        $cfg = array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'passion_enabled' => false]);
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            ['relationship_dynamics_config', json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) {
            return;
        }
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
        @unlink($this->errorLog);
    }

    /** A noble High Elf with a condescending voice (auto-generates Proud + egocentric; Nazeem is MDD 15.6 Rigid), core aff given. */
    private function seedNazeem(int $coreAff): int
    {
        $ext = ['class' => ['name' => 'Noble', 'formid' => '0x00013180'],
                'relationships' => ['Player' => ['aff' => $coreAff, 'type' => 'neutral']]];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, personality, speechstyle, core, npc_static_bio, voiceid, gender, race, metadata, extended_data)'
            . ' VALUES ($1, \'\', \'\', $2, \'\', $3, \'male\', $4, $5::jsonb, $6::jsonb) RETURNING id',
            [self::NPC, 'Roleplay as ' . self::NPC, 'sk_malecondescending', 'HighElfRace', json_encode(['skills' => []]), json_encode($ext)]));
        return (int) $row['id'];
    }

    private function coreAff(int $id): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return (int) json_decode($r['extended_data'], true)['relationships']['Player']['aff'];
    }

    private function plugin(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    /** A shared-eval-contract v1 item, as the eval lane produces it. */
    private function item(int $npcId, array $o = []): array
    {
        return array_replace([
            'v' => 1, 'npc' => self::NPC, 'npc_id' => $npcId, 'gamets' => 987654321, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 10, 'trust' => 10, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => -4],
            'tags' => ['praise', 'gift'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.8,
            'positive_interaction' => true,
            'summary' => 'Kaida praised his fine clothes and gave him a gift',
        ], $o);
    }

    /** What postrequest.php does with the dimension engine on (XYZ EVAL DELTA PROCESSING). */
    private function consume(array &$dyn): array
    {
        $results = RelationshipDynamics::processPendingEvalDeltas(self::NPC, $dyn);
        if (!empty($results)) {
            RelationshipDynamics::commitPlayerAffinity(self::NPC, $dyn);
            RelationshipDynamics::saveDynamics(self::NPC, $dyn);
        }
        return $results;
    }

    private function assertProudEgocentricBrittle(array $dyn): void
    {
        $this->assertSame('Proud', $dyn['inferred_temperament']);
        $this->assertTrue(RelationshipDynamics::hasTrait($dyn, 'egocentric'));
        $this->assertSame('Rigid', RelationshipDynamics::resolveMaturityType($dyn), 'MDD 15.6 preset: Nazeem will not change');
        $this->assertEqualsWithDelta(35.0, (float) $dyn['dimensions']['maturity']['x'], 1e-9, 'Proud maturity baseline');
        $this->assertEqualsWithDelta(30.0, (float) $dyn['dimensions']['trust']['x'], 1e-9, 'Proud trust baseline');
    }

    public function testInboxItemReachesCoreAffinityThroughTheModifierPipeline(): void
    {
        $id = $this->seedNazeem(15);   // core aff 15 = Proud affinity baseline (core units): decay 1
        $this->assertTrue(RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $this->item($id)));

        $dyn = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertProudEgocentricBrittle($dyn);
        $results = $this->consume($dyn);

        // affinity: +10 x R Proud 0.7 x P Rigid up 0.3 x M 1.5 (egocentric flattery) = +3.15 core
        // -> core gets the whole 3 under the advisory lock, 0.15 stays queued.
        $this->assertSame(18, $this->coreAff($id));
        $stored = $this->plugin($id);
        $this->assertEqualsWithDelta(0.15, (float) $stored['dynamics']['_pending_aff_delta'], 1e-3, 'mirror rounding: 4 places x2');
        $this->assertEqualsWithDelta(3.15 / 2.0, $results['affinity'], 1e-4, 'result in mirror units (4 places)');
        // trust: +10 x R Proud 0.6 x Rigid up 0.3 = +1.8 (no M: tags only shape affinity)
        $this->assertEqualsWithDelta(31.8, (float) $stored['dynamics']['dimensions']['trust']['x'], 1e-6);
        // maturity: -4 x R Proud 0.7 x Rigid down 0.3 = -0.84
        $this->assertEqualsWithDelta(34.16, (float) $stored['dynamics']['dimensions']['maturity']['x'], 1e-6);
        $this->assertArrayNotHasKey('eval_inbox', $stored, 'inbox consumed');

        $log = (string) file_get_contents($this->errorLog);
        $this->assertMatchesRegularExpression(
            '/\[EVAL-MATH\] Nazeem affinity: raw \+10\.00 tags=\[praise,gift\] x R\(Proud\)=0\.70 x P\(Rigid up\)=0\.30 x M=1\.500 \[egocentric_flattery 1\.500\] = \+3\.150; x decay\/caps 1\.000, \|d\|<=24\.0 \(sig 0\.80\) => \+3\.150 core/',
            $log);
        $this->assertStringContainsString('[AFF] Nazeem -> Player: +3 (aff 15 -> 18)', $log);
        $this->assertStringContainsString("[REL] Timeline snapshot for npc_id {$id}", $log, 'core timeline stamp after the write');
        $this->assertStringNotContainsString('ERROR', $log);
    }

    public function testEachInboxItemIsAppliedExactlyOnce(): void
    {
        $id = $this->seedNazeem(15);
        $insult = $this->item($id, [
            'signals' => ['affinity' => -10, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['insult'], 'significance' => 1.0, 'positive_interaction' => false,
            'grievance' => ['flag' => true, 'kind' => 'mocked', 'severity' => 1],
            'summary' => 'Kaida mocked him in front of the market',
        ]);
        // Queued twice: once through the producer API (wrapped), once as a bare retry.
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::NPC, $insult));
        $this->assertTrue(RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $insult));

        // Two overlapping requests load the NPC before either consumes.
        $first = RelationshipDynamics::getDynamics(self::NPC);
        $second = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertProudEgocentricBrittle($first);

        $r1 = $this->consume($first);
        $r2 = $this->consume($second);

        // -10 x R 0.7 x P Rigid down 0.3 x M (maturity 35: 1.15 x egocentric slight 1.5 = 1.725)
        // = -3.6225 core -> core 15 - 3 = 12, -0.6225 stays queued. Once, not twice.
        $this->assertEqualsWithDelta(-3.6225 / 2.0, $r1['affinity'], 1e-4, 'mirror units, rounded to 4 places');
        $this->assertSame([], $r2, 'the overlapping consumer gets nothing');
        $this->assertSame(12, $this->coreAff($id));
        $stored = $this->plugin($id)['dynamics'];
        $this->assertEqualsWithDelta(-0.6225, (float) $stored['_pending_aff_delta'], 1e-3, 'mirror rounding: 4 places x2');
        $this->assertSame([], $stored['dimensions']['resentment']['pending_grievances'] ?? [], 'not on the legacy list');
        $this->assertCount(1, $stored['dimensions']['resentment']['grievance_log'], 'grievance recorded once');
        $this->assertSame('mocked', $stored['dimensions']['resentment']['grievance_log'][0]['kind']);
        $this->assertStringContainsString('eval item already applied', (string) file_get_contents($this->errorLog));

        // A later request finds nothing to apply and leaves core alone.
        $third = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertSame([], $this->consume($third));
        $this->assertSame(12, $this->coreAff($id));
    }

    private function inbox(int $id): array
    {
        return $this->plugin($id)['eval_inbox'] ?? [];
    }

    public function testAnItemIsNotLostWhenTheConsumingSaveFails(): void
    {
        $id = $this->seedNazeem(15);
        $this->assertTrue(RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $this->item($id)));
        $dyn = RelationshipDynamics::getDynamics(self::NPC);

        // Every compare-and-set write of the dynamics loses (saveDynamics gives up after 5).
        $this->db->beforeQuery = fn(string $q): ?array => str_contains($q, 'IS NOT DISTINCT FROM') ? [] : null;
        $results = $this->consume($dyn);
        $this->db->beforeQuery = null;

        $this->assertSame([], $results, 'nothing counts as applied');
        $this->assertCount(1, $this->inbox($id), 'the item stays in the inbox');
        $this->assertSame(15, $this->coreAff($id));
        $this->assertStringContainsString('ERROR', (string) file_get_contents($this->errorLog));

        // The next request applies it, once.
        $next = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertArrayHasKey('affinity', $this->consume($next));
        $this->assertSame(18, $this->coreAff($id));
        $this->assertSame([], $this->inbox($id));
        $again = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertSame([], $this->consume($again));
        $this->assertSame(18, $this->coreAff($id));
    }

    public function testAnItemThatThrowsIsRetriedThenDeadLetteredNeverLost(): void
    {
        $id = $this->seedNazeem(15);
        $insult = $this->item($id, [
            'signals' => ['affinity' => -10, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['insult'], 'significance' => 1.0, 'positive_interaction' => false,
            'grievance' => ['flag' => true, 'kind' => 'mocked', 'severity' => 1],
            'summary' => 'Kaida mocked him',
        ]);
        $praise = $this->item($id, ['gamets' => 987654399, 'summary' => 'Kaida praised him later']);
        RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $insult);
        RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $praise);

        // Recording the grievance hits a database error (the grievance log reads the game clock).
        unset($GLOBALS['gameRequest']);
        $this->db->beforeQuery = function (string $q): ?array {
            if (str_contains($q, 'MAX(gamets)')) throw new RuntimeException('connection lost');
            return null;
        };
        for ($request = 1; $request <= 2; $request++) {
            $dyn = RelationshipDynamics::getDynamics(self::NPC);
            $this->assertSame([], $this->consume($dyn), "request {$request}: nothing applied");
            $this->assertCount(2, $this->inbox($id), "request {$request}: both items stay, in order");
            $this->assertSame(15, $this->coreAff($id), 'no half-applied item reaches core');
        }
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringContainsString('connection lost', $log);

        // Third failure: dead-lettered (kept for inspection), and the next item goes through.
        $dyn = RelationshipDynamics::getDynamics(self::NPC);
        $results = RelationshipDynamics::processPendingEvalDeltas(self::NPC, $dyn);
        $this->db->beforeQuery = null;
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', '987654400', 'Kaida: hello'];
        RelationshipDynamics::commitPlayerAffinity(self::NPC, $dyn);
        RelationshipDynamics::saveDynamics(self::NPC, $dyn);
        $this->assertArrayHasKey('affinity', $results, 'the praise behind it is applied');
        $this->assertSame([], $this->inbox($id));
        $dead = $this->plugin($id)['eval_inbox_dead'] ?? [];
        $this->assertCount(1, $dead);
        $this->assertSame('Kaida mocked him', $dead[0]['eval']['summary']);
        $this->assertStringContainsString('connection lost', $dead[0]['error']);
        $stored = $this->plugin($id)['dynamics'];
        $this->assertSame([], $stored['dimensions']['resentment']['grievance_log'] ?? [], 'the failed item left no trace');
    }

    public function testWhileAnotherRequestAppliesTheInboxThisOneLeavesItAlone(): void
    {
        $id = $this->seedNazeem(15);
        RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $this->item($id));
        $other = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($other, 'SELECT pg_advisory_lock(' . RelDynStorage::INBOX_LOCK_CLASS . ", {$id})");

        $dyn = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertSame([], $this->consume($dyn));
        $this->assertCount(1, $this->inbox($id), 'left for the request holding the lock');
        pg_close($other);   // that request ends; its lock goes with its session

        $dyn = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertArrayHasKey('affinity', $this->consume($dyn));
        $this->assertSame([], $this->inbox($id));
    }

    public function testItemAddressedToAnotherNpcIdIsDropped(): void
    {
        $id = $this->seedNazeem(15);
        $this->assertTrue(RelDynStorage::appendItem($id, RelDynStorage::KEY_EVAL_INBOX, $this->item($id + 1000)));

        $dyn = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertSame([], RelationshipDynamics::processPendingEvalDeltas(self::NPC, $dyn));
        $this->assertSame(15, $this->coreAff($id));
        $this->assertArrayNotHasKey('eval_inbox', $this->plugin($id));
        $this->assertStringContainsString('dropped', (string) file_get_contents($this->errorLog));
    }
}
