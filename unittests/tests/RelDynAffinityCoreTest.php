<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * RelDyn A2 + CHIM 3.4.1 "Player" key.
 *
 * Core's extended_data.relationships.Player.aff is the single source of truth for
 * player affinity. RelDyn must apply its own affinity changes (eval deltas, absence
 * decay, passion-gain bonus) as deltas under core's per-NPC advisory lock
 * (1001000000 + npc id) so affinity can go down, core eval's changes survive, and
 * nothing is applied twice.
 *
 * No real database: RelDynAffinityFakeDb keeps core_npc_master rows as JSON and
 * emulates the handful of SQL shapes RelDyn issues (including jsonb_set semantics:
 * a missing intermediate path element makes jsonb_set a no-op, as in PostgreSQL),
 * plus the RelDynStorage / NpcMaster::getPluginData shapes for
 * plugin_extended_data.reldyn (where RelDyn's own state lives).
 */
final class RelDynAffinityFakeDb
{
    /** @var array<int, array{id:int, npc_name:string, extended_data:string}> */
    public array $rows = [];
    /** @var array<string, string> */
    public array $confOpts = [];
    /** @var string[] */
    public array $queries = [];
    /** Every write that touched extended_data.relationships */
    public array $relationshipWrites = [];
    public array $heldXactLocks = [];
    public bool $inTransaction = false;
    public ?array $snapshot = null;
    public bool $failRelationshipUpdates = false;

    public function addNpc(int $id, string $name, array $extended): void
    {
        $this->rows[$id] = ['id' => $id, 'npc_name' => $name, 'extended_data' => json_encode($extended), 'plugin_extended_data' => []];
    }

    /** plugin_extended_data.reldyn.dynamics, falling back to the pre-migration location. */
    public function dynamics(int $id): array
    {
        return $this->rows[$id]['plugin_extended_data']['reldyn']['dynamics']
            ?? ($this->extended($id)['relationship_dynamics'] ?? []);
    }

    public function patchDynamics(int $id, array $patch): void
    {
        if (isset($this->rows[$id]['plugin_extended_data']['reldyn']['dynamics'])) {
            $this->rows[$id]['plugin_extended_data']['reldyn']['dynamics'] = array_merge($this->rows[$id]['plugin_extended_data']['reldyn']['dynamics'], $patch);
            return;
        }
        $ext = $this->extended($id);
        $ext['relationship_dynamics'] = array_merge($ext['relationship_dynamics'] ?? [], $patch);
        $this->setExtended($id, $ext);
    }

    /** RelDynStorage / NpcMaster plugin-data statements (parameterized). */
    private function pluginQuery(string $q, array $params)
    {
        $sql = preg_replace('/\s+/', ' ', trim($q));
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $row = $this->findByName((string)$params[0]);
            return $row ? ['id' => (string)$row['id']] : [];
        }
        $id = (int)($params[0] ?? 0);
        if (!isset($this->rows[$id])) {
            return [];
        }
        $plugin = &$this->rows[$id]['plugin_extended_data'];
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $ns = $plugin[$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object)$ns)];
        }
        if (strpos($sql, 'WITH cur AS (') === 0 && strpos($sql, '#-') !== false) {
            $value = $plugin[$params[1]][$params[2]] ?? null;
            unset($plugin[$params[1]][$params[2]]);
            return ['inbox' => $value === null ? null : json_encode($value)];
        }
        if (strpos($sql, "extended_data -> 'relationship_dynamics'") !== false) {
            $legacy = $this->extended($id)['relationship_dynamics'] ?? null;
            if (isset($plugin[$params[1]][$params[2]]) || !is_array($legacy) || $legacy === []) {
                return [];
            }
            $plugin[$params[1]][$params[2]] = $legacy;
            return ['id' => (string)$id];
        }
        if (strpos($sql, 'jsonb_build_array($4::jsonb)') !== false) {
            $plugin[$params[1]][$params[2]][] = json_decode($params[3], true);
            return ['id' => (string)$id];
        }
        if (strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
            $plugin[$params[1]][$params[2]] = json_decode($params[3], true);
            return ['id' => (string)$id];
        }
        throw new RuntimeException('RelDynAffinityFakeDb: unhandled parameterized query: ' . $sql);
    }

    public function extended(int $id): array
    {
        return json_decode($this->rows[$id]['extended_data'], true) ?: [];
    }

    public function setExtended(int $id, array $extended): void
    {
        $this->rows[$id]['extended_data'] = json_encode($extended);
    }

    public function escape($value): string
    {
        return str_replace("'", "''", (string)$value);
    }

    public function escapeLiteral($value): string
    {
        return "'" . $this->escape($value) . "'";
    }

    private static function unescape(string $value): string
    {
        return str_replace("''", "'", $value);
    }

    private function findByName(string $name): ?array
    {
        foreach ($this->rows as $row) {
            if (strtolower($row['npc_name']) === strtolower($name)) {
                return $row;
            }
        }
        return null;
    }

    private function selectRow(string $q): ?array
    {
        if (preg_match("/FROM core_npc_master WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)/", $q, $m)) {
            return $this->findByName(self::unescape($m[1]));
        }
        if (preg_match('/FROM core_npc_master WHERE id = (\d+)/', $q, $m)) {
            return $this->rows[(int)$m[1]] ?? null;
        }
        return null;
    }

    public function fetchOne($q, array $params = [])
    {
        $this->queries[] = $q;
        if (!empty($params)) {
            return $this->pluginQuery($q, $params);
        }
        if (preg_match("/FROM conf_opts WHERE (?:lower\()?id\)? = '((?:[^']|'')*)'/", $q, $m)) {
            $id = self::unescape($m[1]);
            return isset($this->confOpts[$id]) ? ['value' => $this->confOpts[$id]] : [];
        }
        if (stripos($q, 'pg_advisory_xact_lock') !== false) {
            $this->takeXactLock($q);
            return ['pg_advisory_xact_lock' => ''];
        }
        if (str_contains($q, 'FROM core_npc_master')) {
            return $this->selectRow($q) ?? [];
        }
        return [];
    }

    public function fetchAll($q, $log = false)
    {
        $row = $this->fetchOne($q);
        return $row ? [$row] : [];
    }

    private function takeXactLock(string $q): void
    {
        if (preg_match('/pg_advisory_xact_lock\((\d+)\)/', $q, $m)) {
            if (!$this->inTransaction) {
                throw new RuntimeException('pg_advisory_xact_lock outside a transaction is released immediately');
            }
            $this->heldXactLocks[(int)$m[1]] = true;
        }
    }

    public function execQuery($q)
    {
        $this->queries[] = $q;
        $trim = strtoupper(trim($q));
        if ($trim === 'BEGIN') {
            $this->inTransaction = true;
            $this->snapshot = $this->rows;
            return true;
        }
        if ($trim === 'COMMIT' || $trim === 'ROLLBACK') {
            if ($trim === 'ROLLBACK' && $this->snapshot !== null) {
                $this->rows = $this->snapshot;
            }
            $this->inTransaction = false;
            $this->snapshot = null;
            $this->heldXactLocks = [];
            return true;
        }
        if (stripos($q, 'pg_advisory_xact_lock') !== false) {
            $this->takeXactLock($q);
            return true;
        }

        // UPDATE ... SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{a,b}', '<json>'::jsonb[, true]) WHERE ...
        if (preg_match("/^UPDATE core_npc_master SET extended_data = jsonb_set\(COALESCE\(extended_data, '\{\}'::jsonb\), '\{([^}]*)\}', '((?:[^']|'')*)'::jsonb(?:, true)?\) WHERE (.+)$/s", trim($q), $m)) {
            $row = $this->selectRow('FROM core_npc_master WHERE ' . $m[3]);
            if ($row === null) {
                return true;
            }
            $path = explode(',', $m[1]);
            if ($path[0] === 'relationships') {
                if ($this->failRelationshipUpdates) {
                    return false;
                }
                $this->recordRelationshipWrite($row['id'], implode(',', $path));
            }
            $ext = json_decode($row['extended_data'], true) ?: [];
            $value = json_decode(self::unescape($m[2]), true);
            if (self::jsonbSet($ext, $path, $value)) {
                $this->rows[$row['id']]['extended_data'] = json_encode($ext);
            }
            return true;
        }

        // Whole-blob write: UPDATE core_npc_master SET extended_data = '<json>'::jsonb WHERE id = N
        if (preg_match("/^UPDATE core_npc_master SET extended_data = '((?:[^']|'')*)'::jsonb WHERE id = (\d+)$/s", trim($q), $m)) {
            $id = (int)$m[2];
            $this->recordRelationshipWrite($id, '(whole extended_data)');
            $this->rows[$id]['extended_data'] = self::unescape($m[1]);
            return true;
        }

        return true;
    }

    private function recordRelationshipWrite(int $id, string $path): void
    {
        $this->relationshipWrites[] = [
            'id' => $id,
            'path' => $path,
            'locked' => $this->inTransaction && !empty($this->heldXactLocks[1001000000 + $id]),
        ];
    }

    /** PostgreSQL jsonb_set(create_missing=true): only the LAST path element may be created. */
    private static function jsonbSet(array &$doc, array $path, $value): bool
    {
        $node = &$doc;
        $last = array_pop($path);
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node) || !is_array($node[$key])) {
                return false;
            }
            $node = &$node[$key];
        }
        $node[$last] = $value;
        return true;
    }

    public function __call($name, $args)
    {
        $this->queries[] = $name . ':' . ($args[0] ?? '');
        return [];
    }
}

final class RelDynAffinityCoreTest extends TestCase
{
    private const NPC_ID = 42;
    private const NPC = 'Lydia';
    private const PLAYER = 'Kaida';

    private RelDynAffinityFakeDb $db;
    private array $savedGlobals = [];
    private string $errorLog;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
    }

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'HERIKA_NAME', 'PLAYER_NAME', 'CACHE_PEOPLE', 'CACHE_PARTY'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string)$key, 'RELDYN_')) {
                unset($GLOBALS[$key]);
            }
        }
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-aff-');
        ini_set('error_log', $this->errorLog);

        $this->db = new RelDynAffinityFakeDb();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['HERIKA_NAME'] = self::NPC;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['CACHE_PEOPLE'] = '|' . self::NPC . '|';
        $GLOBALS['gameRequest'] = ['inputtext', time(), 1000000, self::PLAYER . ': Hello there.'];

        $this->setConfig([]);
        $this->resetEngineCaches();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $saved[0];
            }
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string)$key, 'RELDYN_')) {
                unset($GLOBALS[$key]);
            }
        }
        $this->resetEngineCaches();
        @unlink($this->errorLog);
    }

    private function resetEngineCaches(): void
    {
        $ref = new ReflectionClass('RelationshipDynamics');
        foreach (['config' => null, 'npcCache' => [], 'bondCache' => []] as $prop => $empty) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue(null, $empty);
            }
        }
    }

    /** Full config (getConfig does not merge defaults); everything not under test switched off. */
    private function setConfig(array $overrides): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $off = [
            // topic_bonus_enabled stays on here; the disabled path is covered by testTurnWithTopicBonusDisabledRaisesNoUndefinedVariable
            'ambient_enabled', 'reunion_enabled', 'jealousy_enabled', 'conflict_enabled',
            'flirt_bonus_enabled', 'attraction_matrix_enabled', 'duty_override_enabled', 'internal_weather_enabled',
            'creature_moodifications_enabled', 'social_masking_enabled', 'ick_system_enabled', 'autonomy_enabled',
            'hoover_enabled', 'autonomous_diary_enabled', 'divine_intervention_enabled', 'grief_system_enabled',
            'attachment_style_enabled', 'director_goals_enabled', 'cascade_network_enabled',
            'parasite_detection_enabled', 'charisma_detection_enabled', 'significance_scaling_enabled',
        ];
        foreach ($off as $key) {
            $cfg[$key] = false;
        }
        $cfg['enabled'] = true;
        $cfg['dimension_engine_enabled'] = true;
        $cfg['passion_enabled'] = false;
        $cfg = array_merge($cfg, $overrides);
        $this->db->confOpts['relationship_dynamics_config'] = json_encode($cfg);
        $this->resetEngineCaches();
    }

    private function seedNpc(array $relationships, array $dynamicsOverrides = []): void
    {
        $dynamics = array_merge([
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            'warmth_curve' => RelationshipDynamics::CURVE_MODERATE,
            'inferred_temperament' => 'Stoic',
            '_interest_vector' => [0.1, 0.2, 0.3],
            'passion' => 0.0,
        ], $dynamicsOverrides);
        $this->db->addNpc(self::NPC_ID, self::NPC, [
            'relationships' => $relationships,
            'relationship_dynamics' => $dynamics,
        ]);
    }

    private function coreAff(): int
    {
        $rels = $this->db->extended(self::NPC_ID)['relationships'] ?? [];
        return (int)($rels['Player']['aff'] ?? PHP_INT_MIN);
    }

    private function storedDynamics(): array
    {
        return $this->db->dynamics(self::NPC_ID);
    }

    private function setStoredDynamics(array $patch): void
    {
        $this->db->patchDynamics(self::NPC_ID, $patch);
        $this->resetEngineCaches();
    }

    private function runHook(string $hook): void
    {
        $GLOBALS['gameRequest'][1] = time();
        include __DIR__ . '/../../ext/relationship_dynamics/' . $hook . '.php';
    }

    private function runTurn(): void
    {
        unset($GLOBALS['RELDYN_NPC_NAME'], $GLOBALS['RELDYN_PLAYER_NAME'], $GLOBALS['RELDYN_AFFINITY_DELTA']);
        $this->runHook('prerequest');
        $this->runHook('postrequest');
    }

    private function assertAllRelationshipWritesLocked(): void
    {
        $this->assertNotEmpty($this->db->relationshipWrites, 'expected RelDyn to write core affinity');
        foreach ($this->db->relationshipWrites as $write) {
            $this->assertTrue($write['locked'], 'relationship write without core advisory lock 1001000000+id: ' . json_encode($write));
        }
    }

    public function testNegativeEvalAffinityDeltaLowersCoreAffinity(): void
    {
        $this->seedNpc(['Player' => ['aff' => 40, 'type' => 'platonic']], [
            '_pending_xyz_eval' => ['affinity_delta' => -10, 'affinity_reason' => 'insulted her family'],
        ]);

        $this->runTurn();

        $aff = $this->coreAff();
        $this->assertLessThan(40, $aff, 'eval affinity_delta -10 must lower core relationships.Player.aff');
        $this->assertAllRelationshipWritesLocked();

        $mirror = $this->storedDynamics()['dimensions']['affinity']['x'] ?? null;
        $this->assertEqualsWithDelta(($aff + 100) / 2.0, (float)$mirror, 0.01, 'dimensions.affinity.x must mirror core aff');
    }

    public function testNextTurnNeitherRevertsNorReappliesRelDynDelta(): void
    {
        $this->seedNpc(['Player' => ['aff' => 40, 'type' => 'platonic']], [
            '_pending_xyz_eval' => ['affinity_delta' => -10],
        ]);
        $this->runTurn();
        $afterFirst = $this->coreAff();
        $this->assertLessThan(40, $afterFirst);

        $this->resetEngineCaches();
        $this->runTurn();

        $this->assertSame($afterFirst, $this->coreAff(), 'a turn without new RelDyn affinity changes must leave core aff alone');
    }

    public function testEvalDeltaForNpcWithoutCorePlayerEntryCreatesIt(): void
    {
        // Core treats a missing entry as aff 0 / neutral; RelDyn's first eval must not be lost.
        $this->seedNpc([], ['_pending_xyz_eval' => ['affinity_delta' => -10]]);

        $this->runTurn();

        $rels = $this->db->extended(self::NPC_ID)['relationships'] ?? [];
        $this->assertArrayHasKey('Player', $rels);
        $this->assertLessThan(0, $rels['Player']['aff']);
        $this->assertAllRelationshipWritesLocked();
    }

    public function testCoreEvalChangeBetweenTurnsIsPreserved(): void
    {
        $this->seedNpc(['Player' => ['aff' => 40, 'type' => 'platonic', 'note' => 'core note']]);
        $this->runTurn();
        $this->assertSame(40, $this->coreAff());

        // Core relationship_system eval (worker) applies its own +7 between turns.
        $ext = $this->db->extended(self::NPC_ID);
        $ext['relationships']['Player']['aff'] = 47;
        $this->db->setExtended(self::NPC_ID, $ext);
        // ... and a RelDyn eval with a negative affinity delta is waiting.
        $this->setStoredDynamics(['_pending_xyz_eval' => ['affinity_delta' => -10]]);

        $this->runTurn();

        $aff = $this->coreAff();
        $this->assertLessThan(47, $aff, 'RelDyn delta applies on top of core value');
        $this->assertGreaterThan(40 - 30, $aff, 'core +7 must not be clobbered');
        $rel = $this->db->extended(self::NPC_ID)['relationships']['Player'];
        $this->assertSame('platonic', $rel['type']);
        $this->assertSame('core note', $rel['note']);
    }

    public function testPassionGainBonusRaisesCoreAffinityUnderLockWithPlayerKey(): void
    {
        $this->setConfig(['passion_enabled' => true]);
        // passion 100 -> getAffinityGainMultiplier = 2.0 -> +2 on a positive interaction
        $this->seedNpc(['Player' => ['aff' => 10, 'type' => 'neutral']], ['passion' => 100.0]);

        $this->runTurn();

        $this->assertSame(12, $this->coreAff());
        $this->assertAllRelationshipWritesLocked();
        $this->assertArrayNotHasKey(self::PLAYER, $this->db->extended(self::NPC_ID)['relationships']);
    }

    public function testAffinityDecayLowersCoreAffinity(): void
    {
        $this->seedNpc(['Player' => ['aff' => 60, 'type' => 'platonic']]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::refreshAffinityMirror($dynamics, 60);

        $result = RelationshipDynamics::processAffinityDecay($dynamics, self::NPC, 'Stoic', 'platonic', 10.0);
        $this->assertLessThan(0, $result['decay_amount']);
        RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics);

        $this->assertLessThan(60, $this->coreAff());
        $this->assertAllRelationshipWritesLocked();
        $this->assertEqualsWithDelta(($this->coreAff() + 100) / 2.0, $dynamics['dimensions']['affinity']['x'], 0.01);
    }

    public function testFractionalDeltasAccumulateInsteadOfRounding(): void
    {
        $this->seedNpc(['Player' => ['aff' => 0, 'type' => 'neutral']]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::queueAffinityDelta($dynamics, -0.6);
        $this->assertNull(RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics));
        $this->assertSame(0, $this->coreAff());
        $this->assertSame([], $this->db->relationshipWrites);

        RelationshipDynamics::queueAffinityDelta($dynamics, -0.6);
        $result = RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics);
        $this->assertSame(-1, $result['delta']);
        $this->assertSame(-1, $this->coreAff());
        $this->assertEqualsWithDelta(-0.2, $dynamics['_pending_aff_delta'], 0.0001);
    }

    public function testCoreRangeClamp(): void
    {
        $this->seedNpc(['Player' => ['aff' => 99, 'type' => 'romantic']]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::queueAffinityDelta($dynamics, 5);
        $result = RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics);
        $this->assertSame(100, $this->coreAff());
        $this->assertSame(1, $result['delta']);
        $this->assertEqualsWithDelta(0.0, $dynamics['_pending_aff_delta'], 0.0001, 'clamped remainder is dropped, not retried forever');
    }

    public function testMissingPlayerEntryIsCreatedNotSilentlyDropped(): void
    {
        $this->seedNpc(['Ulfric Stormcloak' => ['aff' => -20, 'type' => 'enemy']]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::queueAffinityDelta($dynamics, 3);
        RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics);

        $rels = $this->db->extended(self::NPC_ID)['relationships'];
        $this->assertSame(3, $rels['Player']['aff']);
        $this->assertSame('neutral', $rels['Player']['type']);
        $this->assertSame(-20, $rels['Ulfric Stormcloak']['aff']);
    }

    public function testLegacyRealNameKeyIsReadAndMigratedToPlayerKey(): void
    {
        $this->seedNpc([self::PLAYER => ['aff' => 30, 'type' => 'platonic', 'note' => 'old']]);

        $rel = RelationshipDynamics::getPlayerRelationship(self::NPC);
        $this->assertSame(30, (int)$rel['aff']);

        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::queueAffinityDelta($dynamics, -4);
        RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics);

        $rels = $this->db->extended(self::NPC_ID)['relationships'];
        $this->assertArrayNotHasKey(self::PLAYER, $rels);
        $this->assertSame(26, $rels['Player']['aff']);
        $this->assertSame('old', $rels['Player']['note']);
        $this->assertAllRelationshipWritesLocked();
    }

    public function testFailedWriteRollsBackAndKeepsDeltaQueued(): void
    {
        $this->seedNpc(['Player' => ['aff' => 20, 'type' => 'neutral']]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        $this->db->failRelationshipUpdates = true;

        RelationshipDynamics::queueAffinityDelta($dynamics, -3);
        $this->assertNull(RelationshipDynamics::commitPlayerAffinity(self::NPC, $dynamics));

        $this->assertSame(20, $this->coreAff());
        $this->assertContains('ROLLBACK', $this->db->queries);
        $this->assertFalse($this->db->inTransaction);
        $this->assertEqualsWithDelta(-3.0, $dynamics['_pending_aff_delta'], 0.0001);
    }

    public function testTurnWithTopicBonusDisabledRaisesNoUndefinedVariable(): void
    {
        $this->setConfig(['topic_bonus_enabled' => false]);
        $this->seedNpc(['Player' => ['aff' => 10, 'type' => 'neutral']]);

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            $this->runTurn();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], array_values(array_filter($warnings, fn($w) => str_contains($w, 'topicMatch'))));
        $this->assertNull($GLOBALS['RELDYN_TOPIC_MATCH'] ?? null);
    }

    public function testPlayerKeyHelpers(): void
    {
        $this->assertSame('Player', RelationshipDynamics::relationshipTargetKey(self::PLAYER));
        $this->assertSame('Player', RelationshipDynamics::relationshipTargetKey('player'));
        $this->assertSame('Ulfric Stormcloak', RelationshipDynamics::relationshipTargetKey('Ulfric Stormcloak'));
        $this->assertTrue(RelationshipDynamics::isPlayerRelationshipKey('Player'));
        $this->assertFalse(RelationshipDynamics::isPlayerRelationshipKey('Lydia'));

        $this->seedNpc(['Player' => ['aff' => 55, 'type' => 'platonic'], 'Farkas' => ['aff' => 70, 'type' => 'platonic']]);
        $bonds = RelationshipDynamics::getAllBondsForNpc(self::NPC);
        $this->assertSame(55.0, $bonds['Player']['aff']);
        $this->assertSame(70.0, $bonds['Farkas']['aff']);
    }
}
