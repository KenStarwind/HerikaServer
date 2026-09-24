<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory stand-in for the CHIM `sql` class for overlapping-request tests.
 *
 * core_npc_master rows keep extended_data and plugin_extended_data as decoded JSON.
 * Handles the RelDynStorage / NpcMaster statement shapes (including the compare-and-set
 * condition on the dynamics key) and the affinity bridge's transaction + jsonb_set on
 * extended_data. Every statement is applied atomically, like a single-row UPDATE in
 * PostgreSQL. $afterQuery lets a test run "another process" between two statements.
 */
final class RelDynConcurrentFakeDb
{
    public array $npcs = [];
    public array $confOpts = [];
    public array $unhandled = [];
    public int $casFailures = 0;
    /** @var callable|null */
    public $afterQuery = null;

    public function addNpc(int $id, string $name, array $extended = [], array $plugin = []): void
    {
        $this->npcs[$id] = ['id' => $id, 'npc_name' => $name, 'extended_data' => $extended, 'plugin_extended_data' => $plugin];
    }

    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
    public function fetchAll($q, $log = false) { $r = $this->fetchOne($q); return $r ? [$r] : []; }

    public function execQuery($q)
    {
        $r = $this->fetchOne($q);
        return $r === false ? false : true;
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
        if (in_array(strtoupper($sql), ['BEGIN', 'COMMIT', 'ROLLBACK'], true) || stripos($sql, 'pg_advisory_xact_lock') !== false) {
            return [];
        }
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", $sql, $m)) {
            return array_key_exists($m[1], $this->confOpts) ? ['value' => $this->confOpts[$m[1]]] : [];
        }
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            foreach ($this->npcs as $row) {
                if (strtolower($row['npc_name']) === strtolower((string) $params[0])) {
                    return ['id' => (string) $row['id']];
                }
            }
            return [];
        }
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $id = (int) $params[0];
            if (!isset($this->npcs[$id])) return [];
            $ns = $this->npcs[$id]['plugin_extended_data'][$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object) $ns)];
        }
        if (strpos($sql, 'WITH cur AS (') === 0 && strpos($sql, '#-') !== false) {
            $id = (int) $params[0];
            $value = $this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]] ?? null;
            unset($this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]]);
            return ['inbox' => $value === null ? null : json_encode($value)];
        }
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'jsonb_build_array($4::jsonb)') !== false) {
            $id = (int) $params[0];
            $this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]][] = json_decode($params[3], true);
            return ['id' => (string) $id];
        }
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
            $id = (int) $params[0];
            if (!isset($this->npcs[$id])) return [];
            $current = $this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]] ?? null;
            if (strpos($sql, 'IS NOT DISTINCT FROM $5::jsonb') !== false) {
                // jsonb equality: compare decoded values (1 == 1.0, key order irrelevant)
                $expected = $params[4] === null ? null : json_decode($params[4], true);
                if (!self::jsonEquals($current, $expected)) {
                    $this->casFailures++;
                    return [];
                }
            }
            $this->npcs[$id]['plugin_extended_data'][$params[1]][$params[2]] = json_decode($params[3], true);
            return ['id' => (string) $id];
        }
        if (preg_match('/^SELECT extended_data FROM core_npc_master WHERE id = (\d+) FOR UPDATE$/', $sql, $m)) {
            $id = (int) $m[1];
            return isset($this->npcs[$id]) ? ['extended_data' => json_encode((object) $this->npcs[$id]['extended_data'])] : [];
        }
        if (preg_match("/^UPDATE core_npc_master SET extended_data = jsonb_set\(COALESCE\(extended_data, '\{\}'::jsonb\), '\{([^}]*)\}', '((?:[^']|'')*)'::jsonb, true\) WHERE id = (\d+)$/", $sql, $m)) {
            $id = (int) $m[3];
            $node = &$this->npcs[$id]['extended_data'];
            $path = explode(',', $m[1]);
            $last = array_pop($path);
            foreach ($path as $key) {
                if (!isset($node[$key]) || !is_array($node[$key])) return []; // jsonb_set no-op
                $node = &$node[$key];
            }
            $node[$last] = json_decode(str_replace("''", "'", $m[2]), true);
            return [];
        }
        if (preg_match('/FROM core_npc_master WHERE lower\(npc_name\) = lower/', $sql)) {
            return [];
        }
        $this->unhandled[] = $sql;
        return [];
    }

    private static function jsonEquals($a, $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return $a == $b;
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) return false;
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::jsonEquals($v, $b[$k])) return false;
            }
            return true;
        }
        return $a === $b;
    }
}

/**
 * Overlapping requests for the same NPC (a hook, the eval consumer, the editor) each
 * load the dynamics blob, change it and save it. A save from a stale copy must only
 * apply what that copy changed; it must never throw away what another request saved
 * in the meantime, and never re-queue an affinity fraction that was already committed.
 */
final class RelDynConcurrentSaveTest extends TestCase
{
    private const ID = 9;
    private const NPC = 'Lydia';

    private RelDynConcurrentFakeDb $db;
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['gameRequest']);
        $this->db = new RelDynConcurrentFakeDb();
        $this->db->confOpts['relationship_dynamics_config'] = json_encode(RelationshipDynamics::defaultConfig());
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function seed(array $dynamics, int $coreAff = 20): void
    {
        $this->db->addNpc(self::ID, self::NPC,
            ['relationships' => ['Player' => ['aff' => $coreAff, 'type' => 'platonic']]],
            ['reldyn' => ['dynamics' => array_merge([
                'love_language_primary' => 'quality_time',
                'warmth_curve' => 'moderate',
                'inferred_temperament' => 'Stoic',
            ], $dynamics)]]);
    }

    private function stored(): array
    {
        return $this->db->npcs[self::ID]['plugin_extended_data']['reldyn']['dynamics'];
    }

    private function coreAff(): int
    {
        return (int) $this->db->npcs[self::ID]['extended_data']['relationships']['Player']['aff'];
    }

    public function testStaleSaveKeepsJealousySavedByAnotherRequest(): void
    {
        $this->seed(['jealousy_anger' => 0.0, 'interaction_count' => 2]);

        $lydiaPrerequest = RelationshipDynamics::getDynamics(self::NPC);   // loads, then pauses

        // Ashe's request (flirting while Lydia is nearby) makes Lydia jealous and saves.
        $asheRequest = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::setJealousy($asheRequest, 3.0);
        $asheRequest['jealousy_trigger_npc'] = 'Ashe';
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $asheRequest));

        // Lydia's prerequest resumes with its old copy and saves its own change.
        $lydiaPrerequest['interaction_count'] = 3;
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $lydiaPrerequest));

        $stored = $this->stored();
        $this->assertEqualsWithDelta(3.0, (float) $stored['jealousy_anger'], 0.0001, 'jealousy saved by the other request survives');
        $this->assertSame('Ashe', $stored['jealousy_trigger_npc']);
        $this->assertSame(3, $stored['interaction_count']);
        $this->assertEqualsWithDelta(3.0, (float) $lydiaPrerequest['jealousy_anger'], 0.0001,
            'the caller\'s copy is refreshed with the merged state');
    }

    public function testConsumedEvalIsNotRevertedByAStaleSave(): void
    {
        $this->seed(['dimensions' => ['trust' => ['x' => 35, 'baseline' => 35, 'active' => true]]]);
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::NPC, ['trust_delta' => -6]));

        $nextPrerequest = RelationshipDynamics::getDynamics(self::NPC);     // N+1 loads, pauses

        $postrequest = RelationshipDynamics::getDynamics(self::NPC);        // N consumes the eval
        $results = RelationshipDynamics::processPendingEvalDeltas(self::NPC, $postrequest);
        $this->assertArrayHasKey('trust', $results);
        $trustAfterEval = (float) $postrequest['dimensions']['trust']['x'];
        $this->assertLessThan(35.0, $trustAfterEval);
        RelationshipDynamics::saveDynamics(self::NPC, $postrequest);

        $nextPrerequest['last_interaction_at'] = 123.0;                     // N+1 resumes and saves
        RelationshipDynamics::saveDynamics(self::NPC, $nextPrerequest);

        $stored = $this->stored();
        $this->assertEqualsWithDelta($trustAfterEval, (float) $stored['dimensions']['trust']['x'], 0.0001,
            'the applied eval must not be reverted (the inbox is already empty, so it would be lost for good)');
        $this->assertArrayNotHasKey('eval_inbox', $this->db->npcs[self::ID]['plugin_extended_data']['reldyn']);
    }

    public function testCommittedAffinityFractionIsNeverCommittedAgain(): void
    {
        // Mirror of core aff 20 is x=60; -0.95 core points waiting to be committed.
        $this->seed([
            '_pending_aff_delta' => -0.95,
            '_aff_mirror_x' => 60.0,
            'dimensions' => ['affinity' => ['x' => 60.0, 'baseline' => null]],
            '_accumulated_play_gamets' => 1000000.0,
            '_decay_last_play_gamets' => 1000000.0,
        ]);

        $postrequestN = RelationshipDynamics::getDynamics(self::NPC);       // loads, pauses before saving

        // Prerequest N+1, five play-minutes later: decay adds -0.21 and commits -1.
        $prerequestN1 = RelationshipDynamics::getDynamics(self::NPC);
        $prerequestN1['_accumulated_play_gamets'] = 1694500.0;
        $prerequestN1['_decay_last_play_gamets'] = 1694500.0;
        RelationshipDynamics::queueAffinityDelta($prerequestN1, -0.21);
        $commit = RelationshipDynamics::commitPlayerAffinity(self::NPC, $prerequestN1);
        $this->assertSame(-1, $commit['delta']);
        RelationshipDynamics::saveDynamics(self::NPC, $prerequestN1);
        $this->assertSame(19, $this->coreAff());

        // Postrequest N resumes: +0.53 passion bonus, no whole point, saves its stale copy.
        $postrequestN['_accumulated_play_gamets'] = 1300000.0;
        RelationshipDynamics::queueAffinityDelta($postrequestN, 0.53);
        $this->assertNull(RelationshipDynamics::commitPlayerAffinity(self::NPC, $postrequestN));
        RelationshipDynamics::saveDynamics(self::NPC, $postrequestN);

        $stored = $this->stored();
        // Everything RelDyn asked for: -0.95 - 0.21 + 0.53 = -0.63 = committed -1 + pending 0.37
        $this->assertEqualsWithDelta(0.37, (float) $stored['_pending_aff_delta'], 0.0001,
            'the -0.95 already written to core must not be queued again');
        $this->assertEqualsWithDelta(1694500.0, (float) $stored['_accumulated_play_gamets'], 0.001, 'play clock never rewinds');
        $this->assertEqualsWithDelta(1694500.0, (float) $stored['_decay_last_play_gamets'], 0.001, 'decay interval not counted again');
        $this->assertEqualsWithDelta(59.5, (float) $stored['dimensions']['affinity']['x'], 0.001, 'mirror of core 19');
        $this->assertEqualsWithDelta(59.5, (float) $stored['_aff_mirror_x'], 0.001);
    }

    public function testTwoRequestsCarryingTheSameFractionDoNotOverApply(): void
    {
        $this->seed([
            '_pending_aff_delta' => 0.8,
            '_aff_mirror_x' => 60.0,
            'dimensions' => ['affinity' => ['x' => 60.0, 'baseline' => null]],
        ]);

        $a = RelationshipDynamics::getDynamics(self::NPC);
        $b = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::queueAffinityDelta($a, 0.5);
        RelationshipDynamics::queueAffinityDelta($b, 0.5);
        $this->assertSame(1, RelationshipDynamics::commitPlayerAffinity(self::NPC, $a)['delta']);
        $this->assertSame(1, RelationshipDynamics::commitPlayerAffinity(self::NPC, $b)['delta']);
        RelationshipDynamics::saveDynamics(self::NPC, $a);
        RelationshipDynamics::saveDynamics(self::NPC, $b);

        // 0.8 + 0.5 + 0.5 = 1.8 was asked for; core got +2, so -0.2 must stay queued.
        $this->assertSame(22, $this->coreAff());
        $this->assertEqualsWithDelta(-0.2, (float) $this->stored()['_pending_aff_delta'], 0.0001);
    }

    public function testUncommittedAffinityChangeSurvivesAnotherRequestsCommit(): void
    {
        $this->seed([
            '_aff_mirror_x' => 60.0,
            'dimensions' => ['affinity' => ['x' => 60.0, 'baseline' => null]],
        ]);

        $mine = RelationshipDynamics::getDynamics(self::NPC);
        $mine['dimensions']['affinity']['x'] = 61.5;                     // +3 core points, not committed yet

        $other = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::queueAffinityDelta($other, -2);
        RelationshipDynamics::commitPlayerAffinity(self::NPC, $other);   // core 20 -> 18, mirror 59
        RelationshipDynamics::saveDynamics(self::NPC, $other);

        RelationshipDynamics::saveDynamics(self::NPC, $mine);
        $stored = $this->stored();
        $this->assertEqualsWithDelta(59.0, (float) $stored['_aff_mirror_x'], 0.001);
        $this->assertEqualsWithDelta(60.5, (float) $stored['dimensions']['affinity']['x'], 0.001,
            'mirror 59 plus this copy\'s uncommitted +1.5; the other commit is not queued again');

        $reloaded = RelationshipDynamics::getDynamics(self::NPC);
        RelationshipDynamics::commitPlayerAffinity(self::NPC, $reloaded);
        $this->assertSame(21, $this->coreAff(), '18 + 3');
    }

    public function testSavingTheSameCopyTwiceDoesNotApplyItsChangesTwice(): void
    {
        $this->seed(['total_positive_interactions' => 10]);

        $copy = RelationshipDynamics::getDynamics(self::NPC);
        $copy['total_positive_interactions'] = 11;
        RelationshipDynamics::saveDynamics(self::NPC, $copy);

        // Another request records an interaction in between.
        $other = RelationshipDynamics::getDynamics(self::NPC);
        $other['total_positive_interactions'] = 12;
        RelationshipDynamics::saveDynamics(self::NPC, $other);

        $copy['total_positive_interactions'] += 1;   // postrequest saves the same variable again
        RelationshipDynamics::saveDynamics(self::NPC, $copy);

        $this->assertSame(13, $this->stored()['total_positive_interactions'], '10 +1 +1 (other) +1');
    }

    public function testWriteBetweenReadAndCompareAndSetIsRetriedNotLost(): void
    {
        $this->seed(['total_positive_interactions' => 10, 'jealousy_anger' => 0.0]);
        $copy = RelationshipDynamics::getDynamics(self::NPC);
        $copy['total_positive_interactions'] = 11;

        $fired = false;
        $this->db->afterQuery = function (string $sql) use (&$fired): void {
            if (!$fired && strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data') === 0) {
                $fired = true;   // another process saves right after this save read the row
                $this->db->npcs[self::ID]['plugin_extended_data']['reldyn']['dynamics']['jealousy_anger'] = 7.0;
            }
        };
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $copy));
        $this->db->afterQuery = null;

        $this->assertSame(1, $this->db->casFailures, 'first write refused because the row changed');
        $this->assertEqualsWithDelta(7.0, (float) $this->stored()['jealousy_anger'], 0.0001);
        $this->assertSame(11, $this->stored()['total_positive_interactions']);
        $this->assertSame([], $this->db->unhandled);
    }

    public function testMergeRules(): void
    {
        $base   = ['passion' => 10.0, 'passion_updated_at' => 100.0, 'stage' => 'early', 'context_tier_hwm' => 1,
                   'dimensions' => ['trust' => ['x' => 40.0, 'baseline' => 40]], 'history' => ['a']];
        $theirs = ['passion' => 15.0, 'passion_updated_at' => 200.0, 'stage' => 'established', 'context_tier_hwm' => 2,
                   'dimensions' => ['trust' => ['x' => 45.0, 'baseline' => 40]], 'history' => ['a', 'b']];
        $mine   = ['passion' => 12.0, 'passion_updated_at' => 150.0, 'stage' => 'deep', 'context_tier_hwm' => 3,
                   'dimensions' => ['trust' => ['x' => 99.0, 'baseline' => 40]], 'history' => ['a', 'c']];

        $out = RelationshipDynamics::mergeDynamics($base, $mine, $theirs);

        $this->assertEqualsWithDelta(17.0, $out['passion'], 0.0001, 'accumulator: both gains kept');
        $this->assertSame(200.0, $out['passion_updated_at'], 'clock: never rewound');
        $this->assertSame(3, $out['context_tier_hwm'], 'high-water mark: max');
        $this->assertSame('deep', $out['stage'], 'plain value: this copy wins');
        $this->assertEqualsWithDelta(100.0, $out['dimensions']['trust']['x'], 0.0001, 'clamped to the dimension range');
        $this->assertSame(['a', 'c'], $out['history'], 'lists: this copy wins');
    }
}
