<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_system/relationship_llm.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_system/async_queue.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws,
 * updateRow / insert are parameterized. Failed statements are recorded so tests can show
 * none happened.
 */
final class RelDynCoreHookPgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function record(string $what): void
    {
        $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $what), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) {
            $this->record((string) $q);
            return [];
        }
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

    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) $this->record((string) $q);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->record("insert {$table}");
        return $res;
    }

    /** lib/postgresql.class.php updateRow(): parameterized SET, caller-built WHERE. */
    public function updateRow($table, $data, $where)
    {
        $set = [];
        $i = 0;
        foreach (array_keys($data) as $col) $set[] = "{$col} = $" . (++$i);
        $res = @pg_query_params($this->link, "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}", array_values($data));
        if (!$res) {
            $this->record("updateRow {$table}");
            return false;
        }
        return true;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
}

/** The relationship LLM's driver: canned replies at the connector boundary (the only fake). */
final class RelDynCoreHookStubDriver
{
    /** @var string[] */
    public array $replies = [];
    public array $calls = [];
    /** callable(): void run inside the "LLM call" (another writer committing meanwhile). */
    public $during = null;

    public function fast_request($messages, $params, $context)
    {
        $this->calls[] = $context;
        if ($this->during !== null) ($this->during)();
        if (!$this->replies) throw new RuntimeException("unexpected LLM call ({$context})");
        return array_shift($this->replies);
    }
}

/**
 * relative-affinity-multipliers (double count) / affinity-core-bridge: the CHIM fork hook in
 * core's relationship eval (ext/relationship_system/relationship_llm.php applyChanges).
 *
 * Runs core's real worker path, _relProcessQueue() -> evaluateContext() /
 * evaluateNpcToNpcContext() -> applyChanges() -> save + timeline snapshot, on a real
 * PostgreSQL. Only the relationship LLM reply is canned.
 *
 *   - RelDyn on (enabled + dimension engine + eval producer): core leaves
 *     relationships.Player.aff alone, but still applies type, notes and other targets and
 *     writes the timeline snapshot.
 *   - Any of those switches off, or no owner registered: core applies its delta as before.
 *   - NPC-to-NPC relationships are never owned.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynCoreAffinityHookPostgresTest extends TestCase
{
    private const NPC = 'Aela the Huntress';
    private const OTHER = 'Farkas';
    private const PLAYER = 'Kaida';

    private string $dsn;
    private string $schema;
    private RelDynCoreHookPgDb $db;
    private array $savedGlobals = [];
    private RelDynCoreHookStubDriver $driver;
    private RelationshipLLM $llm;
    private int $npcId = 0;
    private int $otherId = 0;
    private string $logFile;
    private $prevLog = null;

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
        $this->schema = 'reldyn_corehook' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and core_npc_master_history.sql.
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
        // ext/relationship_system/async_queue.php _relCreateQueueTable()
        pg_query($admin, "CREATE TABLE relationship_eval_queue (id SERIAL PRIMARY KEY, npc_id INTEGER NOT NULL UNIQUE,
            eval_data JSONB NOT NULL, created_at TIMESTAMP DEFAULT NOW(), retry_count INTEGER DEFAULT 0, last_error TEXT)");
        pg_query($admin, "CREATE TABLE prompts (prompt_key text PRIMARY KEY, custom_prompt text, default_prompt text)");
        pg_query($admin, "CREATE TABLE audit_request (id serial PRIMARY KEY, request text, result text, connector text, url text)");
        pg_close($admin);

        $this->db = new RelDynCoreHookPgDb($dsn, $this->schema);
        // Core loads the owner files on its first eval; load them now so tearDown restores
        // RelDyn's registration after a test that removes it.
        chimRelationshipAffinityOwned(0, '');
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'RELLLM_CONNECTOR', 'CHIM_RELATIONSHIP_AFFINITY_OWNERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        // Core's relationship worker runs without a game request (worker.php bootstrap).
        unset($GLOBALS['gameRequest']);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        // Core's relationship LLM is configured (its worker path runs only then); RelDyn's
        // eval falls back to the same connector (eval_producer.connector_id 0).
        $GLOBALS['RELLLM_CONNECTOR'] = 5;
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_core_hook_test.log');
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdcorehook');
        $this->prevLog = ini_set('error_log', $this->logFile);

        $this->storeRelDynConfig([]);

        $this->npcId = $this->seedNpc(self::NPC, [
            'Player' => ['aff' => 30, 'type' => 'platonic', 'note' => 'shared a hunt'],
            self::OTHER => ['aff' => 40, 'type' => 'familial'],
        ]);
        $this->otherId = $this->seedNpc(self::OTHER, [
            self::NPC => ['aff' => 35, 'type' => 'familial'],
            'Player' => ['aff' => 10, 'type' => 'platonic'],
        ]);

        // Core's RelationshipLLM with its connector boundary replaced by the canned driver.
        $this->driver = new RelDynCoreHookStubDriver();
        $this->llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
        foreach (['db' => $this->db, 'driver' => $this->driver, 'modelName' => 'stub',
                     'connector' => ['id' => 1, 'driver' => 'stub', 'model' => 'stub', 'label' => 'stub']] as $prop => $value) {
            $p = new ReflectionProperty(RelationshipLLM::class, $prop);
            $p->setAccessible(true);
            $p->setValue($this->llm, $value);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixture helpers

    /** RelDyn's config row the way saveConfig() stores it (defaults, then $overrides). */
    private function storeRelDynConfig(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    private function seedNpc(string $name, array $relationships, array $extra = []): int
    {
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, extended_data) VALUES ($1, $2::jsonb) RETURNING id',
            [$name, json_encode(['relationships' => $relationships] + $extra)]));
        return (int) $row['id'];
    }

    private function relationships(int $npcId): array
    {
        $row = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE id = $1', [$npcId]));
        return json_decode($row['extended_data'], true)['relationships'];
    }

    /** Relationship snapshots core wrote to the timeline for this NPC (newest last). */
    private function timeline(int $npcId): array
    {
        $res = pg_query_params($this->db->link,
            "SELECT extended_data FROM core_npc_master_history
             WHERE npc_id = $1 AND extended_data ->> '_chim_history_source' = 'relationship' ORDER BY history_id", [$npcId]);
        $rows = [];
        while ($r = pg_fetch_assoc($res)) $rows[] = json_decode($r['extended_data'], true)['relationships'];
        return $rows;
    }

    /** One job the way core's postrequest queues it (_relQueueEvaluation), for NPC -> Player. */
    private function queuePlayerEval(int $npcId, string $npcName): void
    {
        pg_query_params($this->db->link, 'INSERT INTO relationship_eval_queue (npc_id, eval_data) VALUES ($1, $2::jsonb)', [$npcId, json_encode([
            'npc_id' => $npcId, 'npc_name' => $npcName,
            'dialogue' => 'You call that a hunt? My shield-siblings would laugh.',
            'context' => ['player_action' => 'I let the deer go.'],
            'is_npc2npc' => false, 'listener_npc_id' => null, 'listener_name' => null, 'has_player_action' => true,
        ])]);
    }

    /** Core's worker entry point for one batch (worker.php processOneBatch). */
    private function runCoreWorker(): array
    {
        $result = _relProcessQueue(10, $this->llm);
        $this->assertSame([], $result['errors'], 'core worker errors');
        $this->assertSame([], $this->db->failures, 'failed SQL statements');
        $left = pg_fetch_assoc(pg_query($this->db->link, 'SELECT count(*) AS n FROM relationship_eval_queue'));
        $this->assertSame('0', $left['n'], 'the job was processed and removed');
        return $result;
    }

    private const PLAYER_REPLY = '{"changes": {"Player": {"delta": -12, "type": "rival", "reason": "let the deer go and mocked the hunt"},
        "Farkas": {"delta": 4, "reason": "stood by her in the argument"}}}';

    // ------------------------------------------------------------------ tests

    public function testRelDynOwningPlayerAffinityKeepsItButCoreStillAppliesTypeNotesOtherTargetsAndTimeline(): void
    {
        $this->queuePlayerEval($this->npcId, self::NPC);
        $this->driver->replies = [self::PLAYER_REPLY];

        $this->runCoreWorker();

        $this->assertSame(['relationship_eval'], $this->driver->calls);
        $rels = $this->relationships($this->npcId);
        // Player.aff is RelDyn's: core's -12 is not applied.
        $this->assertSame(30, $rels['Player']['aff']);
        // Everything else about the Player row is core's, as before.
        $this->assertSame('rival', $rels['Player']['type']);
        $this->assertSame('let the deer go and mocked the hunt', $rels['Player']['note']);
        $this->assertSame('let the deer go and mocked the hunt', $rels['Player']['worst']);
        $this->assertSame(-12, $rels['Player']['worst_delta']);
        // Other targets of the same eval keep core's delta.
        $this->assertSame(44, $rels[self::OTHER]['aff']);
        $this->assertSame('stood by her in the argument', $rels[self::OTHER]['note']);
        // Timeline snapshot of the saved state.
        $timeline = $this->timeline($this->npcId);
        $this->assertCount(1, $timeline);
        $this->assertSame(30, $timeline[0]['Player']['aff']);
        $this->assertSame('rival', $timeline[0]['Player']['type']);
        $this->assertSame(44, $timeline[0][self::OTHER]['aff']);
    }

    /** @return array<string, array{array}> */
    public static function switchedOffConfigs(): array
    {
        return [
            'RelDyn disabled'           => [['enabled' => false]],
            'dimension engine disabled' => [['dimension_engine_enabled' => false]],
            'RelDyn eval disabled'      => [['eval_producer' => ['enabled' => false]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('switchedOffConfigs')]
    public function testWithRelDynEvalOffCoreAppliesItsDeltaAsBefore(array $config): void
    {
        $this->storeRelDynConfig($config);
        $this->queuePlayerEval($this->npcId, self::NPC);
        $this->driver->replies = [self::PLAYER_REPLY];

        $this->runCoreWorker();

        $rels = $this->relationships($this->npcId);
        $this->assertSame(18, $rels['Player']['aff']);
        $this->assertSame('rival', $rels['Player']['type']);
        $this->assertSame('let the deer go and mocked the hunt', $rels['Player']['note']);
        $this->assertSame(44, $rels[self::OTHER]['aff']);
        $this->assertSame(18, $this->timeline($this->npcId)[0]['Player']['aff']);
    }

    public function testWithNoOwnerRegisteredCoreBehaviourIsUnchanged(): void
    {
        $this->assertTrue(chimRelationshipAffinityOwned($this->npcId, 'Player'), 'RelDyn is discovered and owns while on');
        unset($GLOBALS['CHIM_RELATIONSHIP_AFFINITY_OWNERS']);
        $this->assertFalse(chimRelationshipAffinityOwned($this->npcId, 'Player'));

        $this->queuePlayerEval($this->npcId, self::NPC);
        $this->driver->replies = [self::PLAYER_REPLY];
        $this->runCoreWorker();

        $this->assertSame(18, $this->relationships($this->npcId)['Player']['aff']);
    }

    public function testCoreDiscoversRelDynsOwnerFileAndRelDynOwnsOnlyThePlayerTarget(): void
    {
        $this->assertTrue(chimRelationshipAffinityOwned($this->npcId, 'Player'));
        $this->assertContains(
            realpath($GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_affinity_owner.php'),
            array_map('realpath', get_included_files()));
        $this->assertArrayHasKey('reldyn', $GLOBALS['CHIM_RELATIONSHIP_AFFINITY_OWNERS']);
        $this->assertFalse(chimRelationshipAffinityOwned($this->npcId, self::OTHER));
        $this->assertFalse(chimRelationshipAffinityOwned($this->npcId, 'Stormcloaks'));

        // The switches are read fresh on every call: eval off hands the number back at once.
        $this->storeRelDynConfig(['eval_producer' => ['enabled' => false]]);
        $this->assertFalse(chimRelationshipAffinityOwned($this->npcId, 'Player'));
        $this->storeRelDynConfig([]);
        $this->assertTrue(chimRelationshipAffinityOwned($this->npcId, 'Player'));
    }

    /**
     * Core's relationship worker (ext/relationship_system/worker.php) loads no extension file.
     * In a fresh process loaded the same way, the owner must still be found and read RelDyn's
     * switches from the database.
     */
    public function testOwnerResolvesInAProcessLoadedLikeCoresRelationshipWorker(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'rdcorehookchild');
        $child = $base . '.php';
        file_put_contents($child, <<<'PHP'
<?php
[, $enginePath, $dsn, $schema, $npcId] = $argv;
$GLOBALS['ENGINE_PATH'] = $enginePath;
require_once $enginePath . 'lib/logger.php';
require_once $enginePath . 'ext/relationship_system/async_queue.php';        // worker.php
require_once $enginePath . 'ext/relationship_system/relationship_llm.php';   // _relProcessQueue()
final class ChildDb {
    public $link;
    public function __construct($dsn, $schema) { $this->link = pg_connect($dsn); pg_query($this->link, "SET search_path TO {$schema}"); }
    public function fetchOne($q) { $r = pg_query($this->link, $q); if (!$r) throw new RuntimeException(pg_last_error($this->link)); return pg_fetch_assoc($r) ?: []; }
    public function escape($s) { return pg_escape_string($this->link, (string) $s); }
}
$GLOBALS['db'] = new ChildDb($dsn, $schema);
$GLOBALS['RELLLM_CONNECTOR'] = 5;   // worker.php: chimLoadGeneralSettingsIntoGlobals()
$before = class_exists('RelDynEval', false);
echo json_encode(['reldyn_loaded_before' => $before, 'player' => chimRelationshipAffinityOwned((int) $npcId, 'Player'),
    'farkas' => chimRelationshipAffinityOwned((int) $npcId, 'Farkas')]);
PHP);
        try {
            $run = function () use ($child): array {
                $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'display_errors=stderr', $child,
                    $GLOBALS['ENGINE_PATH'], $this->dsn, $this->schema, (string) $this->npcId])) . ' 2>&1';
                exec($cmd, $out, $code);
                $this->assertSame(0, $code, implode("\n", $out));
                return json_decode(end($out), true);
            };
            $this->assertSame(['reldyn_loaded_before' => false, 'player' => true, 'farkas' => false], $run());
            $this->storeRelDynConfig(['eval_producer' => ['enabled' => false]]);
            $this->assertSame(['reldyn_loaded_before' => false, 'player' => false, 'farkas' => false], $run());
        } finally {
            @unlink($child);
            @unlink($base);
        }
    }

    public function testNpcToNpcRelationshipsAreNotOwned(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO relationship_eval_queue (npc_id, eval_data) VALUES ($1, $2::jsonb)', [$this->npcId, json_encode([
            'npc_id' => $this->npcId, 'npc_name' => self::NPC,
            'dialogue' => 'Farkas, you fought well today.',
            'context' => [], 'is_npc2npc' => true,
            'listener_npc_id' => $this->otherId, 'listener_name' => self::OTHER, 'has_player_action' => false,
        ])]);
        $this->driver->replies = ['{"speaker": {"delta": 3, "reason": "proud of his fighting"},
            "listener": {"delta": 5, "reason": "praised by his shield-sister"}}'];

        $this->runCoreWorker();

        $this->assertSame(['relationship_npc_to_npc'], $this->driver->calls);
        $this->assertSame(43, $this->relationships($this->npcId)[self::OTHER]['aff']);
        $this->assertSame(40, $this->relationships($this->otherId)[self::NPC]['aff']);
        // Neither NPC's Player row is touched by an NPC-to-NPC eval.
        $this->assertSame(30, $this->relationships($this->npcId)['Player']['aff']);
        $this->assertSame(10, $this->relationships($this->otherId)['Player']['aff']);
    }

    public function testRelDynCommitDuringCoresLlmCallIsKept(): void
    {
        // RelDyn's eval worker commits Player.aff 30 -> 36 while core's eval call is in flight;
        // core's save rebases onto the fresh row and must not undo or re-apply anything.
        $this->driver->during = function (): void {
            pg_query_params($this->db->link,
                "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', '36'::jsonb) WHERE id = $1",
                [$this->npcId]);
        };
        $this->queuePlayerEval($this->npcId, self::NPC);
        $this->driver->replies = [self::PLAYER_REPLY];

        $this->runCoreWorker();

        $rels = $this->relationships($this->npcId);
        $this->assertSame(36, $rels['Player']['aff']);
        $this->assertSame('rival', $rels['Player']['type']);
    }

    public function testAutoTypeFromNeutralFollowsTheStoredAffinityWhenOwned(): void
    {
        // Core infers a type when leaving neutral from the affinity it saves. Owned, that is
        // RelDyn's stored number (0 here), not core's would-be 0 + 8.
        $neutralId = $this->seedNpc('Ria', ['Player' => ['aff' => 0, 'type' => 'neutral']]);
        $this->queuePlayerEval($neutralId, 'Ria');
        $this->driver->replies = ['{"changes": {"Player": {"delta": 8, "reason": "liked the joke"}}}'];

        $this->runCoreWorker();

        $rels = $this->relationships($neutralId);
        $this->assertSame(0, $rels['Player']['aff']);
        $this->assertSame('neutral', $rels['Player']['type']);
        $this->assertSame('liked the joke', $rels['Player']['note']);
    }

    public function testRelationshipsLockedStillBlocksTheWholeEvalWhenOwned(): void
    {
        $lockedId = $this->seedNpc('Njada Stonearm', ['Player' => ['aff' => 20, 'type' => 'platonic']], ['relationships_locked' => true]);
        $this->queuePlayerEval($lockedId, 'Njada Stonearm');
        $this->driver->replies = [self::PLAYER_REPLY];

        $this->runCoreWorker();

        $this->assertSame(['aff' => 20, 'type' => 'platonic'], $this->relationships($lockedId)['Player']);
        $this->assertSame([], $this->timeline($lockedId));
    }

    // ------------------------------------------------------------------ tag mode (core's REL LLM off)

    private const TAG_REPLY = 'Hmph. A fine hunt, I suppose. #REL:Player=+6# #TYPE:Player=rival# #REL:Farkas=+3#';

    /**
     * Core's relationship LLM off (RELLLM_CONNECTOR 0): core's postrequest parses the NPC's
     * own #REL / #TYPE tags with RelationshipManager::parseChanges(). $relDynConnector is
     * RelDyn's eval_producer.connector_id (> 0: RelDyn's eval still runs on its own connector).
     */
    private function tagMode(int $relDynConnector, array $config = []): void
    {
        $GLOBALS['RELLLM_CONNECTOR'] = 0;
        $this->storeRelDynConfig(array_replace_recursive(['eval_producer' => ['connector_id' => $relDynConnector]], $config));
    }

    public function testTagModeLeavesAnOwnedPlayerAffinityToRelDynButAppliesTypeAndOtherTargets(): void
    {
        $this->tagMode(7);
        $this->assertTrue(chimRelationshipAffinityOwned($this->npcId, 'Player'), 'RelDyn eval runs on its own connector');

        $clean = RelationshipManager::parseChanges(self::TAG_REPLY, self::NPC);

        $this->assertSame('Hmph. A fine hunt, I suppose.   ', $clean, 'tags stripped as before');
        $rels = $this->relationships($this->npcId);
        $this->assertSame(30, $rels['Player']['aff'], "core's +6 tag is not counted next to RelDyn's eval");
        $this->assertSame('rival', $rels['Player']['type']);
        $this->assertSame('shared a hunt', $rels['Player']['note']);
        $this->assertSame(43, $rels[self::OTHER]['aff'], 'other targets keep the tag delta');
        $timeline = $this->timeline($this->npcId);
        $this->assertCount(1, $timeline);
        $this->assertSame(30, $timeline[0]['Player']['aff']);
        $this->assertSame('rival', $timeline[0]['Player']['type']);
        $this->assertStringContainsString('aff owned by extension', (string) file_get_contents($this->logFile));
        $this->assertSame([], $this->db->failures, 'failed SQL statements');
    }

    public function testTagModeWithNoEvalConnectorAtAllKeepsCoresTagDelta(): void
    {
        // Neither core's REL LLM nor RelDyn's own connector: RelDyn's eval cannot run
        // (onPostrequest skips 'no eval connector'), so the number stays core's.
        $this->tagMode(0);
        $this->assertFalse(chimRelationshipAffinityOwned($this->npcId, 'Player'));

        RelationshipManager::parseChanges(self::TAG_REPLY, self::NPC);

        $rels = $this->relationships($this->npcId);
        $this->assertSame(36, $rels['Player']['aff']);
        $this->assertSame('rival', $rels['Player']['type']);
        $this->assertSame(43, $rels[self::OTHER]['aff']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('switchedOffConfigs')]
    public function testTagModeWithRelDynEvalOffKeepsCoresTagDelta(array $config): void
    {
        $this->tagMode(7, $config);
        $this->assertFalse(chimRelationshipAffinityOwned($this->npcId, 'Player'));

        RelationshipManager::parseChanges(self::TAG_REPLY, self::NPC);

        $this->assertSame(36, $this->relationships($this->npcId)['Player']['aff']);
    }

    /**
     * parseChanges() rewrites the whole extended_data. It must take core's per-NPC lock
     * (1001000000 + id, the key applyChanges and RelDyn's commitPlayerAffinity use) and read
     * the row under it, so a RelDyn commit made meanwhile is not overwritten.
     */
    public function testTagModeWritesUnderCoresLockFromAFreshRead(): void
    {
        $this->tagMode(0);   // core's tag delta applies, so the fresh read shows in the number
        $lockKey = 1001000000 + $this->npcId;
        $holder = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($holder, "SET search_path TO {$this->schema}");
        pg_query($holder, "SELECT pg_advisory_lock({$lockKey})");

        $base = tempnam(sys_get_temp_dir(), 'rdcorehooktag');
        $child = $base . '.php';
        $out = $base . '.out';
        file_put_contents($child, <<<'PHP'
<?php
[, $unittests, $testFile, $enginePath, $dsn, $schema, $npc, $reply, $log] = $argv;
$GLOBALS['ENGINE_PATH'] = $enginePath;
require $unittests . '/vendor/autoload.php';
require $testFile;   // the pg adapter; core's files load at its top
ini_set('error_log', $log);
$GLOBALS['db'] = new RelDynCoreHookPgDb($dsn, $schema);
$GLOBALS['PLAYER_NAME'] = 'Kaida';
$GLOBALS['RELLLM_CONNECTOR'] = 0;
RelationshipManager::parseChanges($reply, $npc);   // ext/relationship_system/postrequest.php MODE 2
echo json_encode(['failures' => $GLOBALS['db']->failures]);
PHP);
        try {
            $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $child, dirname(__DIR__), __FILE__,
                $GLOBALS['ENGINE_PATH'], $this->dsn, $this->schema, self::NPC, self::TAG_REPLY, $this->logFile]));
            $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes);
            $this->assertIsResource($proc);
            usleep(800000);
            $this->assertTrue(proc_get_status($proc)['running'], "parseChanges waits for core's per-NPC lock: " . file_get_contents($out));

            // RelDyn commits Player.aff 30 -> 36 while holding the lock, then releases it.
            pg_query($holder, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', '36'::jsonb) WHERE id = {$this->npcId}");
            pg_query($holder, "SELECT pg_advisory_unlock({$lockKey})");

            $deadline = microtime(true) + 15;
            while (($status = proc_get_status($proc))['running'] && microtime(true) < $deadline) usleep(50000);
            $this->assertFalse($status['running'], 'parseChanges finished after the lock was released');
            proc_close($proc);
            $this->assertSame(0, $status['exitcode'], (string) file_get_contents($out));
            $this->assertSame(['failures' => []], json_decode((string) file_get_contents($out), true), (string) file_get_contents($out));

            $rels = $this->relationships($this->npcId);
            $this->assertSame(42, $rels['Player']['aff'], "RelDyn's 36 read under the lock, plus the tag's +6");
            $this->assertSame('rival', $rels['Player']['type']);
            $this->assertSame(43, $rels[self::OTHER]['aff']);
        } finally {
            pg_close($holder);
            foreach ([$child, $out, $base] as $f) @unlink($f);
        }
    }
}
