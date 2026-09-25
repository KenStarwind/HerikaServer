<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory stand-in for the CHIM `sql` class.
 *
 * Stores core_npc_master rows as decoded JSON (extended_data + plugin_extended_data) and
 * conf_opts values, and emulates the exact query shapes RelDyn and NpcMaster issue, so
 * save -> reload round trips go through real JSON encoding. Each statement is applied
 * atomically, the same guarantee PostgreSQL gives a single-row UPDATE.
 */
final class RelDynStorageFakeDb
{
    /** @var array<int, array{id:int,npc_name:string,extended_data:array,plugin_extended_data:array}> */
    public array $npcs = [];
    public array $confOpts = [];
    public int $configReads = 0;
    public array $unhandled = [];
    /** held pg advisory locks: 'class:key' => count */
    public array $advisoryLocks = [];
    /** @var callable|null Called after each statement with the normalized SQL (simulates other processes). */
    public $afterQuery = null;

    public function addNpc(int $id, string $name, array $extended = [], array $plugin = []): void
    {
        $this->npcs[$id] = [
            'id' => $id,
            'npc_name' => $name,
            'extended_data' => $extended,
            'plugin_extended_data' => $plugin,
        ];
    }

    public function escape($string)
    {
        return str_replace("'", "''", (string) $string);
    }

    public function escapeLiteral($string)
    {
        return "'" . $this->escape($string) . "'";
    }

    public function execQuery($q)
    {
        $this->fetchOne($q);
        return true;
    }

    public function query($q)
    {
        return $this->fetchOne($q);
    }

    public function fetchAll($q, $log = false)
    {
        $row = $this->fetchOne($q);
        return $row ? [$row] : [];
    }

    public function fetchOne($q, array $params = [])
    {
        $sql = preg_replace('/\s+/', ' ', trim((string) $q));
        $result = $this->run($sql, $params);
        if ($this->afterQuery !== null) {
            ($this->afterQuery)($sql);
        }
        return $result;
    }

    private function run(string $sql, array $params)
    {
        // ---- save-load generation (RelDynTimeline::freshLoadGeneration): no load in this eventlog ----
        if ($sql === "SELECT max(rowid) AS r FROM eventlog WHERE type = 'init'") {
            return ['r' => null];
        }
        // ---- conf_opts ----
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", $sql, $m)) {
            if ($m[1] === 'relationship_dynamics_config') {
                $this->configReads++;
            }
            return array_key_exists($m[1], $this->confOpts) ? ['value' => $this->confOpts[$m[1]]] : [];
        }

        // ---- RelDyn storage: resolve id by name ----
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $row = $this->findByName((string) $params[0]);
            return $row ? ['id' => (string) $row['id']] : [];
        }

        // ---- NpcMaster::getPluginData ----
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $id = (int) $params[0];
            if (!isset($this->npcs[$id])) {
                return [];
            }
            $ns = $this->npcs[$id]['plugin_extended_data'][$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object) $ns)];
        }

        // ---- table probes (personality trait reads: bio templates / voice types): not in this fake ----
        if ($sql === 'SELECT to_regclass($1) IS NOT NULL AS present') {
            return ['present' => 'f'];
        }
        // ---- pg advisory locks (session-level, re-entrant within one session, as in PostgreSQL) ----
        if (strpos($sql, 'SELECT pg_try_advisory_lock($1::int, $2::int)') === 0) {
            $k = $params[0] . ':' . $params[1];
            $this->advisoryLocks[$k] = ($this->advisoryLocks[$k] ?? 0) + 1;
            return ['got' => 't'];
        }
        if (strpos($sql, 'SELECT pg_advisory_unlock($1::int, $2::int)') === 0) {
            $k = $params[0] . ':' . $params[1];
            if (empty($this->advisoryLocks[$k])) return ['released' => 'f'];
            if (--$this->advisoryLocks[$k] === 0) unset($this->advisoryLocks[$k]);
            return ['released' => 't'];
        }
        // ---- RelDyn storage: drop the first N items of a list key ----
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'WITH ORDINALITY') !== false) {
            $id = (int) $params[0];
            $list = $this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]] ?? null;
            if (!is_array($list)) return [];
            $rest = array_slice($list, (int) $params[3]);
            if ($rest === []) {
                unset($this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]]);
            } else {
                $this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]] = $rest;
            }
            return ['id' => (string) $id];
        }
        // ---- RelDyn storage: atomic take of a list key ----
        if (strpos($sql, 'WITH cur AS (') === 0 && strpos($sql, 'FOR UPDATE') !== false && strpos($sql, '#-') !== false) {
            $id = (int) $params[0];
            if (!isset($this->npcs[$id])) {
                return [];
            }
            $ns = $params[1];
            $key = $params[2];
            $value = $this->npcs[$id]['plugin_extended_data'][$ns][$key] ?? null;
            unset($this->npcs[$id]['plugin_extended_data'][$ns][$key]);
            return ['inbox' => $value === null ? null : json_encode($value)];
        }

        // ---- RelDyn storage: atomic append to a list key ----
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'jsonb_build_array($4::jsonb)') !== false) {
            $id = (int) $params[0];
            if (!isset($this->npcs[$id])) {
                return [];
            }
            $ns = &$this->namespaceRef($id, $params[1]);
            $list = (isset($ns[$params[2]]) && is_array($ns[$params[2]]) && array_is_list($ns[$params[2]])) ? $ns[$params[2]] : [];
            $list[] = json_decode($params[3], true, 512, JSON_THROW_ON_ERROR);
            $ns[$params[2]] = $list;
            return ['id' => (string) $id];
        }

        // ---- RelDyn storage: set one top-level key inside the namespace ----
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
            $id = (int) $params[0];
            if (!isset($this->npcs[$id])) {
                return [];
            }
            $ns = &$this->namespaceRef($id, $params[1]);
            $ns[$params[2]] = json_decode($params[3], true, 512, JSON_THROW_ON_ERROR);
            return ['id' => (string) $id];
        }

        // ---- Temperament auto-generation: the NPC's core profile columns ----
        if (strpos($sql, 'SELECT npc_name, gender, race, voiceid, personality, speechstyle, core, npc_static_bio, metadata, extended_data FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $row = $this->findByName((string) $params[0]);
            return $row ? ['npc_name' => $row['npc_name'], 'extended_data' => json_encode((object) $row['extended_data'])] : [];
        }

        // ---- Pre-3.4.1 storage (April engine): whole blob in extended_data ----
        if (preg_match("/^SELECT (?:id, )?extended_data FROM core_npc_master WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)/", $sql, $m)) {
            $row = $this->findByName(str_replace("''", "'", $m[1]));
            if (!$row) {
                return [];
            }
            return ['id' => (string) $row['id'], 'extended_data' => json_encode((object) $row['extended_data'])];
        }
        if (preg_match("/^UPDATE core_npc_master SET extended_data = jsonb_set\(COALESCE\(extended_data, '\{\}'::jsonb\), '\{relationship_dynamics\}', '(.*)'::jsonb\) WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)$/", $sql, $m)) {
            $row = $this->findByName(str_replace("''", "'", $m[2]));
            if ($row) {
                $this->npcs[$row['id']]['extended_data']['relationship_dynamics'] = json_decode(str_replace("''", "'", $m[1]), true);
            }
            return [];
        }

        $this->unhandled[] = $sql;
        return [];
    }

    private function findByName(string $name): ?array
    {
        foreach ($this->npcs as $row) {
            if (strtolower($row['npc_name']) === strtolower($name)) {
                return $row;
            }
        }
        return null;
    }

    private function &namespaceRef(int $id, string $ns): array
    {
        if (!isset($this->npcs[$id]['plugin_extended_data'][$ns]) || !is_array($this->npcs[$id]['plugin_extended_data'][$ns])) {
            $this->npcs[$id]['plugin_extended_data'][$ns] = [];
        }
        return $this->npcs[$id]['plugin_extended_data'][$ns];
    }
}

final class RelDynStorageTest extends TestCase
{
    private RelDynStorageFakeDb $db;
    private array $savedGlobals = [];
    private array $globalKeysBefore = [];

    protected function setUp(): void
    {
        $this->globalKeysBefore = array_keys($GLOBALS);
        foreach (['db', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = ['exists' => array_key_exists($key, $GLOBALS), 'value' => $GLOBALS[$key] ?? null];
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['RELDYN_PLAYER_NAME']);

        $this->db = new RelDynStorageFakeDb();
        $this->db->confOpts['relationship_dynamics_config'] = json_encode(
            array_merge(RelationshipDynamics::defaultConfig(), ['dimension_engine_enabled' => true])
        );
        $GLOBALS['db'] = $this->db;

        // Every test starts like a fresh request in a long-lived process.
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::clearNpcCache();
    }

    protected function tearDown(): void
    {
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::clearNpcCache();
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved['exists']) {
                $GLOBALS[$key] = $saved['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }
        // Drop globals the hook run introduced (RELDYN_*, gameRequest, ...).
        foreach (array_diff(array_keys($GLOBALS), $this->globalKeysBefore) as $key) {
            unset($GLOBALS[$key]);
        }
    }

    private function storedDynamics(int $id): ?array
    {
        return $this->db->npcs[$id]['plugin_extended_data']['reldyn']['dynamics'] ?? null;
    }

    /** Simulates a write by a different PHP process (web request or worker) to whatever holds the state. */
    private function otherProcessWrites(int $id, array $changes): void
    {
        if (isset($this->db->npcs[$id]['plugin_extended_data']['reldyn']['dynamics'])) {
            $this->db->npcs[$id]['plugin_extended_data']['reldyn']['dynamics'] =
                array_merge($this->db->npcs[$id]['plugin_extended_data']['reldyn']['dynamics'], $changes);
        }
    }

    // ------------------------------------------------------------------
    // Storage move: plugin_extended_data 'reldyn'
    // ------------------------------------------------------------------

    public function testSaveWritesPluginNamespaceAndReloads(): void
    {
        $this->db->addNpc(7, 'Ashe');

        $dyn = RelationshipDynamics::getDynamics('Ashe');
        $dyn['love_language_primary'] = RelationshipDynamics::LL_GIFTS;
        $dyn['warmth_curve'] = RelationshipDynamics::CURVE_SLOW_BURN;
        $dyn['total_positive_interactions'] = 12;
        $this->assertTrue(RelationshipDynamics::saveDynamics('Ashe', $dyn));

        $stored = $this->storedDynamics(7);
        $this->assertIsArray($stored, 'dynamics must live in plugin_extended_data.reldyn.dynamics');
        $this->assertSame('gifts', $stored['love_language_primary']);
        $this->assertArrayNotHasKey('relationship_dynamics', $this->db->npcs[7]['extended_data'],
            'the old extended_data key must not be written any more');

        RelationshipDynamics::clearNpcCache();
        $reloaded = RelationshipDynamics::getDynamics('ashe');
        $this->assertSame('gifts', $reloaded['love_language_primary']);
        $this->assertSame('slow_burn', $reloaded['warmth_curve']);
        $this->assertSame(12, $reloaded['total_positive_interactions']);
        $this->assertSame([], $this->db->unhandled);
    }

    public function testAprilExtendedBlobIsNotCarriedOver(): void
    {
        // Fresh start (decisions 2026-09-23 section 3): the April location is never read.
        $legacy = [
            'love_language_primary' => 'physical_touch',
            'warmth_curve' => 'guarded',
            'total_positive_interactions' => 40,
        ];
        $this->db->addNpc(9, 'Lydia', ['relationship_dynamics' => $legacy, 'relationships' => ['Player' => ['aff' => 20]]]);

        $dyn = RelationshipDynamics::getDynamics('Lydia');
        $this->assertNull($dyn['love_language_primary']);
        $this->assertSame(0, $dyn['total_positive_interactions']);
        $this->assertNull($this->storedDynamics(9), 'nothing copied into the plugin namespace');

        $dyn['total_positive_interactions'] = 1;
        RelationshipDynamics::saveDynamics('Lydia', $dyn);
        $this->assertSame(1, $this->storedDynamics(9)['total_positive_interactions']);
        $this->assertSame($legacy, $this->db->npcs[9]['extended_data']['relationship_dynamics'], 'old key left alone');
        $this->assertSame([], $this->db->unhandled);
    }

    public function testNpcWithoutAnyStateGetsDefaultsAndNoWrite(): void
    {
        $this->db->addNpc(11, 'Faendal', ['relationships' => []]);
        $dyn = RelationshipDynamics::getDynamics('Faendal');
        $this->assertNull($dyn['love_language_primary']);
        $this->assertSame([], $this->db->npcs[11]['plugin_extended_data']);
    }

    // ------------------------------------------------------------------
    // A3: no process-level caches in long-lived processes
    // ------------------------------------------------------------------

    public function testLongLivedProcessSeesWritesFromOtherProcesses(): void
    {
        $this->db->addNpc(7, 'Ashe');
        $dyn = RelationshipDynamics::getDynamics('Ashe');
        $dyn['love_language_primary'] = 'gifts';
        $dyn['total_positive_interactions'] = 5;
        RelationshipDynamics::saveDynamics('Ashe', $dyn);

        // Worker job 1 loads the NPC.
        RelationshipDynamics::getDynamics('Ashe');

        // Meanwhile a web request (another process) records more interactions.
        $this->otherProcessWrites(7, ['total_positive_interactions' => 9, 'warmth_curve' => 'quick_warmth']);

        // Worker job 2 loads, changes something unrelated and saves.
        $job2 = RelationshipDynamics::getDynamics('Ashe');
        $this->assertSame(9, $job2['total_positive_interactions'], 'worker must read fresh state, not a process cache');
        $job2['_last_topic_match'] = 'combat';
        RelationshipDynamics::saveDynamics('Ashe', $job2);

        $stored = $this->storedDynamics(7);
        $this->assertSame(9, $stored['total_positive_interactions'], 'stale blob must not overwrite the newer write');
        $this->assertSame('quick_warmth', $stored['warmth_curve']);
    }

    public function testConfigIsReReadOutsideARequestScope(): void
    {
        $this->assertFalse((bool) RelationshipDynamics::getConfig()['log_enabled']);

        $this->db->confOpts['relationship_dynamics_config'] = json_encode(
            array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true])
        );

        $this->assertTrue((bool) RelationshipDynamics::getConfig()['log_enabled'],
            'a long-lived worker must see config changes on its next job');
    }

    public function testConfigIsCachedWithinARequestAndReReadForTheNext(): void
    {
        RelationshipDynamics::beginRequest();
        RelationshipDynamics::getConfig();
        RelationshipDynamics::getConfig();
        RelationshipDynamics::isEnabled();
        $this->assertSame(1, $this->db->configReads, 'one read per request scope');

        $this->db->confOpts['relationship_dynamics_config'] = json_encode(
            array_merge(RelationshipDynamics::defaultConfig(), ['enabled' => false])
        );
        RelationshipDynamics::beginRequest();
        $this->assertFalse(RelationshipDynamics::isEnabled());
        $this->assertSame(2, $this->db->configReads);

        RelationshipDynamics::endRequest();
        RelationshipDynamics::getConfig();
        $this->assertSame(3, $this->db->configReads, 'no caching once the scope ended');
    }

    public function testBondCacheDoesNotOutliveARequest(): void
    {
        $this->db->addNpc(7, 'Ashe', ['relationships' => ['Lydia' => ['aff' => 10, 'type' => 'friend']]]);
        $this->assertSame(10.0, RelationshipDynamics::getAllBondsForNpc('Ashe')['Lydia']['aff']);

        $this->db->npcs[7]['extended_data']['relationships']['Lydia']['aff'] = 45;
        $this->assertSame(45.0, RelationshipDynamics::getAllBondsForNpc('Ashe')['Lydia']['aff']);
    }

    // ------------------------------------------------------------------
    // A4: pending-eval inbox separate from the dynamics state
    // ------------------------------------------------------------------

    public function testQueuedEvalSurvivesAConcurrentDynamicsSave(): void
    {
        $this->db->addNpc(7, 'Ashe');
        $seed = RelationshipDynamics::getDynamics('Ashe');
        $seed['love_language_primary'] = 'gifts';
        RelationshipDynamics::saveDynamics('Ashe', $seed);

        // postrequest loads the row early (postrequest.php ~268) ...
        $request = RelationshipDynamics::getDynamics('Ashe');

        // ... the eval worker queues a result while the request is still running ...
        $this->assertTrue(RelationshipDynamics::queuePendingEval('Ashe', ['trust_delta' => 6, 'romantic_intent' => 2]));

        // ... and the request saves its (older) copy of the dynamics (postrequest.php ~700).
        $request['total_positive_interactions'] = 3;
        RelationshipDynamics::saveDynamics('Ashe', $request);

        $inbox = $this->db->npcs[7]['plugin_extended_data']['reldyn']['eval_inbox'] ?? [];
        $this->assertCount(1, $inbox, 'the dynamics save must not clobber the pending eval');
        $this->assertSame(3, $this->storedDynamics(7)['total_positive_interactions']);

        // Peek does not consume.
        $this->assertSame(2, RelationshipDynamics::peekPendingEval('Ashe', $request)['romantic_intent']);
        $this->assertCount(1, $this->db->npcs[7]['plugin_extended_data']['reldyn']['eval_inbox']);

        $trustBefore = (float) $request['dimensions']['trust']['x'];
        $results = RelationshipDynamics::processPendingEvalDeltas('Ashe', $request);
        $this->assertArrayHasKey('trust', $results);
        $this->assertGreaterThan($trustBefore, (float) $request['dimensions']['trust']['x']);
        $this->assertArrayNotHasKey('eval_inbox', $this->db->npcs[7]['plugin_extended_data']['reldyn'], 'inbox consumed');
        $this->assertSame([], RelationshipDynamics::processPendingEvalDeltas('Ashe', $request), 'never applied twice');
    }

    public function testOverlappingEvalsAreAllAppliedExactlyOnce(): void
    {
        $this->db->addNpc(7, 'Ashe');
        $seed = RelationshipDynamics::getDynamics('Ashe');
        RelationshipDynamics::saveDynamics('Ashe', $seed);

        RelationshipDynamics::queuePendingEval('Ashe', ['trust_delta' => 3, 'goal_addressed' => false]);
        RelationshipDynamics::queuePendingEval('Ashe', ['respect_delta' => 4, 'goal_addressed' => true]);
        $this->assertCount(2, $this->db->npcs[7]['plugin_extended_data']['reldyn']['eval_inbox']);

        // Latest eval is what the hook peeks at.
        $this->assertTrue(RelationshipDynamics::peekPendingEval('Ashe', $seed)['goal_addressed']);

        $first = RelationshipDynamics::getDynamics('Ashe');
        $second = RelationshipDynamics::getDynamics('Ashe');
        $r1 = RelationshipDynamics::processPendingEvalDeltas('Ashe', $first);
        $r2 = RelationshipDynamics::processPendingEvalDeltas('Ashe', $second);

        $this->assertEqualsCanonicalizing(['trust', 'respect'], array_keys($r1), 'first consumer takes both evals');
        $this->assertSame([], $r2, 'second overlapping consumer gets nothing');
    }

    public function testLegacyPendingEvalKeyInStoredBlobIsStillConsumedOnce(): void
    {
        $this->db->addNpc(9, 'Lydia', [], ['reldyn' => ['dynamics' => [
            'love_language_primary' => 'gifts',
            '_pending_xyz_eval' => ['respect_delta' => 5, 'romantic_intent' => 1],
        ]]]);

        $dyn = RelationshipDynamics::getDynamics('Lydia');
        $this->assertSame(1, RelationshipDynamics::peekPendingEval('Lydia', $dyn)['romantic_intent']);
        $results = RelationshipDynamics::processPendingEvalDeltas('Lydia', $dyn);
        $this->assertArrayHasKey('respect', $results);
        $this->assertArrayNotHasKey('_pending_xyz_eval', $dyn);
        RelationshipDynamics::saveDynamics('Lydia', $dyn);
        $this->assertArrayNotHasKey('_pending_xyz_eval', $this->storedDynamics(9));
    }

    public function testPostrequestHookAppliesQueuedEvalsAndLosesNoneQueuedMidRequest(): void
    {
        $this->db->addNpc(7, 'Ashe', ['relationships' => []]);
        $seed = RelationshipDynamics::getDynamics('Ashe');
        $seed['love_language_primary'] = RelationshipDynamics::LL_GIFTS;
        $seed['love_language_secondary'] = RelationshipDynamics::LL_TIME;
        $seed['warmth_curve'] = RelationshipDynamics::CURVE_MODERATE;
        RelationshipDynamics::saveDynamics('Ashe', $seed);
        $trustBefore = (float) $seed['dimensions']['trust']['x'];
        $respectBefore = (float) $seed['dimensions']['respect']['x'];

        RelationshipDynamics::queuePendingEval('Ashe', ['trust_delta' => 6]);

        // The eval worker queues another result while the hook is running, right after the
        // hook's first dynamics save (the old code lost it at the whole-blob save ~700).
        $fired = false;
        $this->db->afterQuery = function (string $sql) use (&$fired): void {
            if (!$fired && strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
                $fired = true;
                RelationshipDynamics::queuePendingEval('Ashe', ['respect_delta' => 4]);
            }
        };

        $GLOBALS['gameRequest'] = ['inputtext', time(), 12345, 'Kaida: I brought you a gift.'];
        $GLOBALS['RELDYN_NPC_NAME'] = 'Ashe';
        $GLOBALS['HERIKA_NAME'] = 'Ashe';
        (static function (): void {
            require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php';
        })();
        $this->db->afterQuery = null;

        $this->assertTrue($fired, 'the hook saved its dynamics at least once');
        $stored = $this->storedDynamics(7);
        $this->assertGreaterThan($trustBefore, (float) $stored['dimensions']['trust']['x'], 'queued eval applied and saved');

        $stillQueued = $this->db->npcs[7]['plugin_extended_data']['reldyn']['eval_inbox'] ?? [];
        $respectApplied = (float) $stored['dimensions']['respect']['x'] > $respectBefore;
        $this->assertTrue($respectApplied || count($stillQueued) === 1,
            'the mid-request eval is either applied or still queued, never lost');
        $this->assertSame('gifts', $stored['love_language_primary']);
    }

    public function testQueueForUnknownNpcFailsLoudlyWithoutWriting(): void
    {
        $this->assertFalse(RelationshipDynamics::queuePendingEval('Nobody', ['trust_delta' => 1]));
        $this->assertSame([], $this->db->unhandled);
    }
}
