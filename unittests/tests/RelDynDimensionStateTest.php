<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory stand-in for CHIM's $db. Stores core_npc_master.extended_data and
 * plugin_extended_data as decoded JSON so saveDynamics() -> getDynamics() is a real
 * round trip through json_encode/json_decode, the same way the jsonb columns behave.
 * Emulates the RelDynStorage / NpcMaster::getPluginData query shapes (plugin 'reldyn').
 */
final class RelDynStateFakeDb
{
    /** @var array<string, array> lower(npc_name) => extended_data (legacy April location) */
    public array $extended = [];
    /** @var array<string, array> lower(npc_name) => plugin_extended_data */
    public array $plugin = [];
    public ?array $config = null;
    public array $executed = [];
    public array $unhandled = [];

    private function names(): array
    {
        return array_values(array_unique(array_merge(array_keys($this->extended), array_keys($this->plugin))));
    }

    /** Stable fake core_npc_master.id per known NPC name. */
    private function idOf(string $key): ?int
    {
        $i = array_search($key, $this->names(), true);
        return $i === false ? null : $i + 1;
    }

    private function keyOf(int $id): ?string
    {
        return $this->names()[$id - 1] ?? null;
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

    public function fetchOne($sql, $params = null)
    {
        $sql = preg_replace('/\s+/', ' ', trim((string)$sql));
        if (preg_match("/FROM conf_opts WHERE id = 'relationship_dynamics_config'/", $sql)) {
            return $this->config === null ? false : ['value' => json_encode($this->config)];
        }
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $id = $this->idOf(strtolower((string)$params[0]));
            return $id === null ? false : ['id' => (string)$id];
        }
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $key = $this->keyOf((int)$params[0]);
            if ($key === null) {
                return false;
            }
            $ns = $this->plugin[$key][$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object)$ns)];
        }
        if (strpos($sql, 'WITH cur AS (') === 0 && strpos($sql, '#-') !== false) {
            $key = $this->keyOf((int)$params[0]);
            if ($key === null) {
                return false;
            }
            $value = $this->plugin[$key][$params[1]][$params[2]] ?? null;
            unset($this->plugin[$key][$params[1]][$params[2]]);
            return ['inbox' => $value === null ? null : json_encode($value)];
        }
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'plugin_extended_data') !== false) {
            $key = $this->keyOf((int)$params[0]);
            if ($key === null) {
                return false;
            }
            $ns = $params[1];
            $field = $params[2];
            if (strpos($sql, 'jsonb_build_array($4::jsonb)') !== false) {
                $this->plugin[$key][$ns][$field][] = json_decode($params[3], true);
            } elseif (strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
                $this->plugin[$key][$ns][$field] = json_decode($params[3], true);
            } else {
                $this->unhandled[] = $sql;
                return false;
            }
            return ['id' => (string)$params[0]];
        }
        if (preg_match("/SELECT extended_data FROM core_npc_master WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)/", $sql, $m)) {
            $key = strtolower(self::unescape($m[1]));
            return isset($this->extended[$key]) ? ['extended_data' => json_encode($this->extended[$key])] : false;
        }
        return false;
    }

    public function fetchAll($sql, $params = null)
    {
        return [];
    }

    public function execQuery($sql, $params = null)
    {
        $this->executed[] = $sql;
        $pattern = "/UPDATE core_npc_master SET extended_data = jsonb_set\(COALESCE\(extended_data, '\{\}'::jsonb\), '\{relationship_dynamics\}', '((?:[^']|'')*)'::jsonb\) WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)/s";
        if (preg_match($pattern, $sql, $m)) {
            $key = strtolower(self::unescape($m[2]));
            if (isset($this->extended[$key])) {
                $this->extended[$key]['relationship_dynamics'] = json_decode(self::unescape($m[1]), true);
            }
        }
        return true;
    }
}

final class RelDynDimensionStateTest extends TestCase
{
    private const NPC = 'Test Npc';

    private RelDynStateFakeDb $db;
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? $GLOBALS[$key] : null;
        }
        $this->db = new RelDynStateFakeDb();
        $GLOBALS['db'] = $this->db;
        unset($GLOBALS['PLAYER_NAME']);
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::clearNpcCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::clearNpcCache();
    }

    /** Stored plugin state; a flat 'passion' is also written to its canonical dimensions.passion.x. */
    private function seed(array $dynamics): void
    {
        if (isset($dynamics['passion'])) {
            $dynamics['dimensions']['passion'] = ['x' => $dynamics['passion'], 'baseline' => 0];
        }
        $this->db->extended[strtolower(self::NPC)] = [];
        $this->db->plugin[strtolower(self::NPC)] = ['reldyn' => ['dynamics' => $dynamics]];
    }

    private function saveAndReload(array $dynamics): array
    {
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $dynamics));
        RelationshipDynamics::clearNpcCache();
        return RelationshipDynamics::getDynamics(self::NPC);
    }

    // ------------------------------------------------------------------
    // A1: passion
    // ------------------------------------------------------------------

    public function testAddPassionSurvivesSaveAndReload(): void
    {
        $this->seed(['passion' => 10.0, 'stage' => 'early']);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        $this->assertEqualsWithDelta(10.0, RelationshipDynamics::getPassion($dynamics), 0.0001);

        RelationshipDynamics::addPassion($dynamics, 5.0, 'love_match');
        $this->assertEqualsWithDelta(15.0, $dynamics['passion'], 0.0001);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(15.0, $reloaded['passion'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $reloaded['dimensions']['passion']['x'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $this->db->plugin['test npc']['reldyn']['dynamics']['dimensions']['passion']['x'], 0.0001);
    }

    public function testDecayPassionSurvivesSaveAndReload(): void
    {
        $this->seed([
            'passion' => 20.0,
            'passion_updated_at' => 1,
            '_accumulated_play_gamets' => 1 + 0.1 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
            'warmth_curve' => 'moderate',
        ]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::decayPassion($dynamics);
        // moderate curve: 3.0/hour * 0.1h = 0.3
        $this->assertEqualsWithDelta(19.7, $dynamics['passion'], 0.0001);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(19.7, $reloaded['passion'], 0.0001);
        $this->assertEqualsWithDelta(19.7, $reloaded['dimensions']['passion']['x'], 0.0001);
    }

    public function testSetPassionSurvivesSaveAndReload(): void
    {
        // Direct writers (ambient trickle, combat drain, friendzone cap, hoover) use setPassion.
        $this->seed(['passion' => 8.0]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::setPassion($dynamics, 11.5);
        $this->assertEqualsWithDelta(11.5, $dynamics['passion'], 0.0001);
        $this->assertEqualsWithDelta(11.5, $dynamics['dimensions']['passion']['x'], 0.0001);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(11.5, $reloaded['passion'], 0.0001);
    }

    public function testApplyDeltaOnPassionKeepsLegacyMirrorInSync(): void
    {
        $this->seed(['passion' => 10.0]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        $actual = RelationshipDynamics::applyDelta('passion', $dynamics, 5.0, 'Stoic');
        $this->assertNotEquals(0.0, $actual);
        $this->assertEqualsWithDelta($dynamics['dimensions']['passion']['x'], $dynamics['passion'], 0.0001);

        $expected = $dynamics['dimensions']['passion']['x'];
        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta($expected, $reloaded['passion'], 0.0001);
        $this->assertEqualsWithDelta($expected, $reloaded['dimensions']['passion']['x'], 0.0001);
    }

    public function testSyncDerivesLegacyPassionFromCanonical(): void
    {
        $this->seed(['passion' => 5.0]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::setPassion($dynamics, 5.0);
        $this->assertEqualsWithDelta(5.0, $dynamics['dimensions']['passion']['x'], 0.0001);
        // Mirror written by an old-style direct assignment must not win over the canonical value.
        $dynamics['passion'] = 99.0;
        $synced = RelationshipDynamics::syncLegacyFromDimensions($dynamics);
        $this->assertEqualsWithDelta(5.0, $synced['passion'], 0.0001);
    }

    // ------------------------------------------------------------------
    // A1: jealousy
    // ------------------------------------------------------------------

    public function testAddJealousySurvivesSaveAndReload(): void
    {
        $this->seed(['jealousy_anger' => 0.0]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::addJealousy($dynamics, 12.0, 'Rival');
        $this->assertEqualsWithDelta(12.0, $dynamics['jealousy_anger'], 0.0001);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(12.0, $reloaded['jealousy_anger'], 0.0001);
        $this->assertSame('Rival', $reloaded['jealousy_trigger_npc']);
    }

    public function testDecayJealousySurvivesSaveAndReload(): void
    {
        $this->seed([
            'jealousy_anger' => 10.0,
            'jealousy_updated_at' => 1,
            '_accumulated_play_gamets' => 1 + 0.1 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,   // 6 play minutes, in contact
        ]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::decayJealousy($dynamics);
        // default jealousy_decay_per_hour 1.5 * 0.1 h = 0.15
        $this->assertEqualsWithDelta(9.85, $dynamics['jealousy_anger'], 0.0001);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(9.85, $reloaded['jealousy_anger'], 0.0001);
    }

    // ------------------------------------------------------------------
    // Jealousy and resentment are separate (MDD 6.5 vs 15.5)
    // ------------------------------------------------------------------

    public function testJealousyAndResentmentAreIndependent(): void
    {
        $this->seed(['jealousy_anger' => 0.0]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        RelationshipDynamics::addJealousy($dynamics, 12.0);
        $this->assertEqualsWithDelta(0.0, $dynamics['dimensions']['resentment']['x'], 0.0001,
            'jealousy must not leak into resentment');

        $dynamics['dimensions']['resentment']['x'] = 7.0;

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(12.0, $reloaded['jealousy_anger'], 0.0001);
        $this->assertEqualsWithDelta(7.0, $reloaded['dimensions']['resentment']['x'], 0.0001);

        // And a second round trip keeps both.
        $again = $this->saveAndReload($reloaded);
        $this->assertEqualsWithDelta(12.0, $again['jealousy_anger'], 0.0001);
        $this->assertEqualsWithDelta(7.0, $again['dimensions']['resentment']['x'], 0.0001);
    }

    public function testGrievanceResentmentDoesNotTouchJealousy(): void
    {
        $this->seed(['jealousy_anger' => 20.0]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        $dynamics['dimensions']['resentment']['x'] = 0.0;
        $dynamics['dimensions']['resentment']['pending_grievances'] = [['value' => 5, 'reason' => 'ignored']];

        RelationshipDynamics::processGrievances($dynamics, 'Stoic');
        $resentment = floatval($dynamics['dimensions']['resentment']['x']);
        $this->assertGreaterThan(0.0, $resentment);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(20.0, $reloaded['jealousy_anger'], 0.0001);
        $this->assertEqualsWithDelta($resentment, $reloaded['dimensions']['resentment']['x'], 0.0001);
    }

    // ------------------------------------------------------------------
    // Fresh start: no April conversion (see RelDynFreshStartTest)
    // ------------------------------------------------------------------

    public function testStoredJealousyAndResentmentLoadAsStored(): void
    {
        $this->seed([
            'passion' => 30.0,
            'jealousy_anger' => 25.0,
            'dimensions' => [
                'resentment' => ['x' => 25.0, 'baseline' => 0, 'active' => true,
                    'pending_grievances' => [], 'grievance_log' => [], 'last_decay_tick' => 0],
            ],
        ]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);

        $this->assertEqualsWithDelta(30.0, $dynamics['passion'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $dynamics['jealousy_anger'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $dynamics['dimensions']['resentment']['x'], 0.0001);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(30.0, $reloaded['passion'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $reloaded['jealousy_anger'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $reloaded['dimensions']['resentment']['x'], 0.0001);
    }

    public function testNewNpcFirstSaveKeepsResentment(): void
    {
        // No stored blob: defaults, then first save, then reload must not look like April data.
        $this->seed([]);
        $dynamics = RelationshipDynamics::getDynamics(self::NPC);
        $dynamics['dimensions']['resentment']['x'] = 9.0;
        RelationshipDynamics::addJealousy($dynamics, 4.0);
        RelationshipDynamics::addPassion($dynamics, 6.0);

        $reloaded = $this->saveAndReload($dynamics);
        $this->assertEqualsWithDelta(9.0, $reloaded['dimensions']['resentment']['x'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $reloaded['jealousy_anger'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $reloaded['passion'], 0.0001);
    }

    // ------------------------------------------------------------------
    // A5: dimension engine default + stale pending evals
    // ------------------------------------------------------------------

    public function testDimensionEngineEnabledByDefault(): void
    {
        $this->assertTrue(RelationshipDynamics::defaultConfig()['dimension_engine_enabled']);
        $this->db->config = null; // no stored config row -> defaults
        $this->assertNotEmpty(RelationshipDynamics::getConfig()['dimension_engine_enabled']);
    }

    public function testDisabledEngineDropsStalePendingEval(): void
    {
        $this->db->config = array_merge(RelationshipDynamics::defaultConfig(), ['dimension_engine_enabled' => false]);
        RelationshipDynamics::clearConfigCache();

        $dynamics = RelationshipDynamics::defaultDynamics();
        $dynamics['_pending_xyz_eval'] = ['romantic_intent' => 3, 'goal_addressed' => true, 'affinity_delta' => 5];
        $dynamics['_pending_eval'] = ['romantic_intent' => 2];

        $this->seed([]);
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::NPC, ['romantic_intent' => 4]));

        $this->assertSame([], RelationshipDynamics::pendingEvalForRequest(self::NPC, $dynamics));
        $this->assertArrayNotHasKey('_pending_xyz_eval', $dynamics);
        $this->assertArrayNotHasKey('_pending_eval', $dynamics);
        // The unconsumed inbox is dropped too, so it cannot grow while the engine is off.
        $this->assertArrayNotHasKey('eval_inbox', $this->db->plugin['test npc']['reldyn'] ?? []);
        // Next request sees nothing either.
        $this->assertSame([], RelationshipDynamics::pendingEvalForRequest(self::NPC, $dynamics));
    }

    public function testEnabledEngineReturnsPendingEvalWithoutConsumingIt(): void
    {
        $this->db->config = array_merge(RelationshipDynamics::defaultConfig(), ['dimension_engine_enabled' => true]);
        RelationshipDynamics::clearConfigCache();

        $dynamics = RelationshipDynamics::defaultDynamics();
        $pending = ['romantic_intent' => 3, 'goal_addressed' => true];
        $dynamics['_pending_xyz_eval'] = $pending;

        $this->assertSame($pending, RelationshipDynamics::pendingEvalForRequest(self::NPC, $dynamics));
        // processPendingEvalDeltas consumes it later in the same request.
        $this->assertSame($pending, $dynamics['_pending_xyz_eval']);

        // Queued evals in the plugin inbox win over the legacy key, and peeking keeps them.
        $this->seed([]);
        $queued = ['romantic_intent' => 1, 'goal_addressed' => false];
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::NPC, $queued));
        $this->assertSame($queued, RelationshipDynamics::pendingEvalForRequest(self::NPC, $dynamics));
        $this->assertCount(1, $this->db->plugin['test npc']['reldyn']['eval_inbox']);
    }

    public function testLegacyPendingEvalKeyIsNeverReadBecauseNothingConsumesIt(): void
    {
        $this->db->config = array_merge(RelationshipDynamics::defaultConfig(), ['dimension_engine_enabled' => true]);
        RelationshipDynamics::clearConfigCache();

        $dynamics = RelationshipDynamics::defaultDynamics();
        $dynamics['_pending_eval'] = ['romantic_intent' => 2, 'goal_addressed' => true];

        $this->assertSame([], RelationshipDynamics::pendingEvalForRequest(self::NPC, $dynamics));
        $this->assertArrayNotHasKey('_pending_eval', $dynamics);
    }
}
