<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynDiaryConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}

/**
 * Self-reflection that rides on core's diary (roadmap diary-trigger, diary-reflection-eval;
 * decisions 2026-09-23 §7; memory feedback_diary_system; dimension draft "Dimensional
 * Self-Reflection"), the parts that need no database:
 *   - the trigger's consumable gate reads the consumables consumeItem keeps (_active_consumables);
 *   - meaningful moments (significance, conflict, boundary, bond change) are marked once each and
 *     kept for the next diary entry (at most max_moments);
 *   - depth by her own maturity (shallow at or below 20, pattern, examination above 40; a held
 *     offset such as the full moon is not who she is);
 *   - the baseline verdict from the snapshots, each dimension in its growth direction;
 *   - the verdict's deltas (growing maturity +1, stagnating resentment_self +2, spiraling
 *     maturity -1), the growth relief on resentment_self, self-confidence by its evidence;
 *   - the trajectory call: feelings, never numbers, and a strictly validated signal.
 */
final class RelDynDiaryReflectionTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'RELDYN_INTERACTION_SIGNIFICANCE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** A Guarded NPC at core affinity 20, calm (no crisis), mature enough to reflect. */
    private function npc(): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Guarded',
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, 20.0);
        foreach (['maturity' => 60.0, 'trust' => 50.0, 'comfort' => 50.0, 'respect' => 50.0, 'warmth' => 40.0,
                  'resentment' => 0.0, 'resentment_self' => 0.0, 'self_confidence' => 50.0] as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        return $d;
    }

    /** Past the trigger's play-time cooldown and interaction gap since its last mark. */
    private function due(array &$d): void
    {
        $d['_accumulated_time'] = floatval($d['_diary_last_accumulated'] ?? 0) + RelationshipDynamics::DIARY_REFLECTION_COOLDOWN + 1;
        $d['interaction_count'] = intval($d['_diary_last_interaction'] ?? 0) + 20;
    }

    private function crisis(array &$d): void
    {
        $d['dimensions']['resentment']['x'] = 40.0;
        $d['dimensions']['trust']['x'] = 10.0;
    }

    // ------------------------------------------------------------------ trigger + moments

    public function testTheConsumableGateReadsTheConsumablesConsumeItemKeeps(): void
    {
        $d = $this->npc();
        $this->crisis($d);
        $this->due($d);
        $d['_active_consumables'] = [['key' => 'ale', 'item_name' => 'Nord Mead', 'immediate' => ['comfort' => 10.0], 'expires_gamets' => 5000000]];
        $this->assertTrue(RelDynDiary::intoxicated($d));
        $this->assertFalse(RelationshipDynamics::checkDiaryTrigger('Tester', $d), 'nothing is marked while the mead is on her');
        $d['_active_consumables'] = [];
        $this->assertTrue(RelationshipDynamics::checkDiaryTrigger('Tester', $d), 'sober: the crisis is a moment');
        $this->assertContains('crisis_indicators:2', $d['_diary_pending_triggers']);
    }

    public function testMarkedMomentsWaitForTheNextDiaryEntry(): void
    {
        $d = $this->npc();
        $this->crisis($d);
        for ($i = 1; $i <= 12; $i++) {
            $this->due($d);
            $this->assertTrue(RelationshipDynamics::checkDiaryTrigger('Tester', $d), "mark {$i}");
            RelationshipDynamics::markDiaryCompleted($d);
            $this->assertSame([], $d['_diary_pending_triggers']);
            $this->assertCount(min($i, 10), $d['_diary_moments'], 'kept, at most max_moments');
        }
        $last = end($d['_diary_moments']);
        $this->assertContains('crisis_indicators:2', $last['triggers']);
        RelDynDiary::keepMoments($d, ['', ''], 1.0);
        $this->assertCount(10, $d['_diary_moments'], 'nothing to keep, nothing kept');
    }

    public function testSignificanceConflictBoundaryAndBondChangeAreMomentsOnce(): void
    {
        $d = $this->npc();
        $d['_core_rel_type'] = 'friend';
        $this->due($d);
        RelationshipDynamics::checkDiaryTrigger('Tester', $d);
        $this->assertSame('friend', $d['_diary_last_rel_type'], 'the bond she had when her moments were first watched');
        $calm = RelationshipDynamics::detectDiaryContentTriggers($d);

        $d['_core_rel_type'] = 'romantic';
        $d['in_conflict'] = true;
        $d['conflict_entered_at'] = 123456.0;
        $d[RelDynFulfillment::STATE_KEY]['boundary'] = ['state' => 'probation'];
        $d[RelDynConcern::STATE_KEY]['boundary'] = ['state' => 'none', 'stepped_back_gamets' => 2000000.0, 'from' => 'romantic', 'to' => 'friend'];
        $d['_diary_significance_peak'] = 3;
        $moments = array_values(array_diff(RelationshipDynamics::detectDiaryContentTriggers($d), $calm));
        $this->assertEqualsCanonicalizing(['defining_moment:significance_3', 'conflict_opened', 'boundary:fulfillment',
            'boundary:concern', 'bond_changed:friend->romantic'], $moments);

        $d['_diary_pending_triggers'] = $moments;
        RelationshipDynamics::markDiaryCompleted($d);
        $this->assertSame([], array_values(array_diff(RelationshipDynamics::detectDiaryContentTriggers($d), $calm)),
            'each marked once: the bookmarks moved to now');
        $this->assertSame($moments, end($d['_diary_moments'])['triggers']);
        $this->assertSame(0, $d['_diary_significance_peak']);

        // the same conflict still open is not a new one; a new one is
        $d['conflict_entered_at'] = 999999.0;
        $this->assertContains('conflict_opened', RelationshipDynamics::detectDiaryContentTriggers($d));
    }

    public function testTheFulfillmentBoundaryIsReadFromThePlayerPair(): void
    {
        // Stored fulfillment is per relationship pair (rulings §11): {v, pairs: {Player: state}}.
        // The player pair's boundary is a moment, as the pre-pair blob's was.
        $d = $this->npc();
        $this->due($d);
        RelationshipDynamics::checkDiaryTrigger('Tester', $d);
        $calm = RelationshipDynamics::detectDiaryContentTriggers($d);
        RelDynFulfillment::setPairState($d, RelDynFulfillment::PLAYER, ['boundary' => ['state' => 'probation']]);
        $this->assertArrayHasKey('pairs', $d[RelDynFulfillment::STATE_KEY]);
        $this->assertSame(['boundary:fulfillment'],
            array_values(array_diff(RelationshipDynamics::detectDiaryContentTriggers($d), $calm)));
        $this->assertSame('probation', RelationshipDynamics::diaryBoundarySignatures($d)['fulfillment']);
    }

    public function testASignificanceSeenInTheSameRequestStillCounts(): void
    {
        $d = $this->npc();
        $GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] = 3;
        $this->assertContains('defining_moment:significance_3', RelationshipDynamics::detectDiaryContentTriggers($d));
    }

    // ------------------------------------------------------------------ depth

    public function testDepthIsHerOwnMaturity(): void
    {
        $this->assertSame('shallow', RelDynDiary::depth(20.0));
        $this->assertSame('pattern', RelDynDiary::depth(20.5));
        $this->assertSame('pattern', RelDynDiary::depth(40.0));
        $this->assertSame('examination', RelDynDiary::depth(40.5));
        $d = $this->npc();
        $d['dimensions']['maturity']['x'] = 35.0;
        $d[RelDynCreatures::STATE_KEY]['applied']['maturity'] = -10.0;   // the full moon's pull, held
        $this->assertEqualsWithDelta(45.0, RelDynDiary::ownMaturity($d), 1e-9);
        $this->assertSame('examination', RelDynDiary::depth(RelDynDiary::ownMaturity($d)), 'the moon is not who she is');
    }

    // ------------------------------------------------------------------ baseline verdict

    public function testTheBaselineVerdictReadsEachDimensionInItsGrowthDirection(): void
    {
        $d = $this->npc();
        $d['dimensions']['resentment']['x'] = 10.0;
        $snap = RelDynDiary::snapshot($d, 1000.0);
        $this->assertSame(['affinity', 'trust', 'comfort', 'respect', 'warmth', 'resentment'], array_keys($snap['values']));
        $this->assertSame(20.0, $snap['values']['affinity'], 'affinity in core points, as its drift samples');

        $d['dimensions']['trust']['x'] = 60.0;        // +10: growth
        $d['dimensions']['resentment']['x'] = 20.0;   // resentment up 10: regression
        $d['dimensions']['comfort']['x'] = 52.0;      // within 3: stagnation
        $cmp = RelDynDiary::compare($d, $snap);
        $this->assertSame(['trust' => 10.0], $cmp['growth']);
        $this->assertSame(['resentment' => -10.0], $cmp['regression']);
        $this->assertContains('comfort', $cmp['stagnation']);
        $this->assertSame('stagnating', RelDynDiary::baselineVerdict($cmp), 'one up, one down');

        $d['dimensions']['respect']['x'] = 58.0;
        $this->assertSame('growing', RelDynDiary::baselineVerdict(RelDynDiary::compare($d, $snap)));
        $d['dimensions']['trust']['x'] = 40.0;
        $d['dimensions']['respect']['x'] = 45.0;
        $this->assertSame('spiraling', RelDynDiary::baselineVerdict(RelDynDiary::compare($d, $snap)));
        $calm = $this->npc();
        $this->assertSame('stagnating', RelDynDiary::baselineVerdict(RelDynDiary::compare($calm, RelDynDiary::snapshot($calm, 1.0))), 'nothing moved');
    }

    public function testSnapshotsFromTheWallClockEraAreNotRead(): void
    {
        $d = $this->npc();
        $d['_diary_snapshots'] = [['ts' => 1727000000, 'values' => ['trust' => 10.0]]];
        $this->assertNull(RelDynDiary::lastSnapshot($d));
        for ($i = 1; $i <= 7; $i++) RelDynDiary::storeSnapshot($d, $i * 1000.0);
        $this->assertCount(5, RelDynDiary::snapshots($d));
        $this->assertSame(7000.0, RelDynDiary::lastSnapshot($d)['gamets']);
    }

    // ------------------------------------------------------------------ applying a verdict

    public function testTheVerdictsMoveMaturityAndResentmentSelf(): void
    {
        $base = $this->npc();
        $base['dimensions']['resentment_self']['x'] = 10.0;

        $d = $base;
        $a = RelDynDiary::applyReflection('Tester', $d, 'growing', 1, null, 'baseline');
        $this->assertGreaterThan(0.0, $a['maturity']);
        $this->assertEqualsWithDelta(-1.0, $a['resentment_self'], 1e-9, 'growth relief, as is');
        $this->assertEqualsWithDelta(9.0, floatval($d['dimensions']['resentment_self']['x']), 1e-9);
        $this->assertSame('growing', $d['_diary_last_verdict']['verdict']);
        $this->assertArrayNotHasKey('self_confidence', $a, 'no earlier entry: no evidence');

        $d = $base;
        $a = RelDynDiary::applyReflection('Tester', $d, 'stagnating', 1, null, 'baseline');
        $this->assertSame(['resentment_self'], array_keys($a));
        $this->assertGreaterThan(0.0, $a['resentment_self']);

        $d = $base;
        $one = RelDynDiary::applyReflection('Tester', $d, 'spiraling', 1, null, 'baseline');
        $this->assertLessThan(0.0, $one['maturity']);
        $this->assertArrayNotHasKey('resentment_self', $one, 'no relief without growth');
        $d = $base;
        $two = RelDynDiary::applyReflection('Tester', $d, 'spiraling', 2, null, 'trajectory');
        $this->assertLessThan($one['maturity'], $two['maturity'], 'an unmistakable spiral costs more');

        $this->expectException(InvalidArgumentException::class);
        RelDynDiary::applyReflection('Tester', $d, 'fine', 1, null, 'baseline');
    }

    public function testSelfConfidenceFollowsItsOwnEvidence(): void
    {
        $base = $this->npc();
        $input = floatval(RelationshipDynamics::deriveConfidenceInput($base));

        $d = $base;
        $a = RelDynDiary::applyReflection('Tester', $d, 'stagnating', 1, $input - 5.0, 'baseline');
        $this->assertGreaterThan(0.0, $a['self_confidence'], 'respected, steadier since the last entry');

        $d = $base;
        $a = RelDynDiary::applyReflection('Tester', $d, 'stagnating', 1, $input + 5.0, 'baseline');
        $this->assertLessThan(0.0, $a['self_confidence'], 'the evidence eroded');

        $d = $base;
        $a = RelDynDiary::applyReflection('Tester', $d, 'spiraling', 1, $input, 'baseline');
        $this->assertArrayNotHasKey('self_confidence', $a, 'only her own lapse moved it: within the deadband');
    }

    // ------------------------------------------------------------------ trajectory call

    public function testTheTrajectoryCallSeesFeelingsNotNumbers(): void
    {
        $moments = [
            ['gamets' => 2000000.0, 'triggers' => ['conflict_opened', 'crisis_indicators:2']],
            ['gamets' => 2100000.0, 'triggers' => ['defining_moment:significance_3', 'conflict_opened', 'no_such_kind:9']],
        ];
        $entries = [
            ['rowid' => 3, 'content' => 'Long day on the road. I said too little again when Kaida asked.'],
            ['rowid' => 7, 'content' => str_repeat('I keep turning it over. ', 100)],
        ];
        $state = ['Maturity: Grounded (composed under pressure)', 'Trust in the player: Wary (watches before believing)'];
        $m = RelDynDiary::buildMessages('Lynly Star-Sung', 'Kaida', $entries, $state, $moments, 'pattern');
        $this->assertSame('system', $m[0]['role']);
        $this->assertStringContainsString('"trajectory"', $m[0]['content']);
        $user = $m[1]['content'];
        $this->assertStringContainsString('Writer: Lynly Star-Sung', $user);
        $this->assertStringContainsString('Lynly Star-Sung is only beginning to notice their own patterns', $user);
        $this->assertStringContainsString('Since they last took stock: a quarrel with Kaida that is not settled; '
            . 'feeling cornered and sore, several things going wrong at once; something with Kaida that mattered a great deal.', $user);
        $this->assertStringContainsString('- Trust in the player: Wary (watches before believing)', $user);
        $this->assertLessThan(strpos($user, 'I keep turning'), strpos($user, 'Long day on the road'), 'oldest entry first');
        $this->assertStringContainsString(' ...', $user, 'a long entry is clipped');
        $this->assertLessThan(1500 + 700, strlen($user));
        $this->assertDoesNotMatchRegularExpression('/\d/', $m[0]['content'] . $user, 'feelings, never numbers');
    }

    public function testTheReflectionSignalIsValidatedStrictly(): void
    {
        $this->assertSame(['trajectory' => 'growing', 'strength' => 2], RelDynDiary::parse('{"trajectory":"growing","strength":"strong"}', 'examination'));
        $this->assertSame(['trajectory' => 'growing', 'strength' => 1], RelDynDiary::parse('{"trajectory":"growing","strength":"strong"}', 'pattern'),
            'noticing patterns reaches a light verdict only');
        $this->assertSame(['trajectory' => 'spiraling', 'strength' => 1], RelDynDiary::parse("```json\n{\"trajectory\": \"Spiraling\"}\n```", 'examination'));
        $this->assertSame(['trajectory' => 'stagnating', 'strength' => 1], RelDynDiary::parse('{"trajectory":"stagnating","strength":" Light "}', 'examination'));
        foreach (['{"trajectory":"better"}' => 'trajectory', '{"trajectory":"growing","strength":2}' => 'strength',
                  '{"trajectory":"growing","strength":"huge"}' => 'strength', 'She is clearly growing.' => 'JSON',
                  '["growing"]' => 'JSON', '{"strength":1}' => 'trajectory'] as $raw => $why) {
            $reason = null;
            $this->assertNull(RelDynDiary::parse($raw, 'examination', $reason), $raw);
            $this->assertStringContainsStringIgnoringCase($why, (string) $reason, $raw);
        }
    }

    // ------------------------------------------------------------------ config

    public function testConfigMergesTextsAndReplacesListPicks(): void
    {
        $GLOBALS['db'] = new RelDynDiaryConfigDb(array_merge(RelationshipDynamics::defaultConfig(), [
            'diary_reflection_mode' => 'trajectory',
            'diary_reflection' => [
                'verdict_deltas' => ['growing' => ['maturity' => 2.0]],
                'moment_text' => ['boundary' => 'a line drawn with {PLAYER}'],
                'trajectory_dimensions' => ['trust' => 1],
            ],
        ]));
        RelationshipDynamics::clearConfigCache();
        $cfg = RelDynDiary::config();
        $this->assertSame('trajectory', RelDynDiary::mode());
        $this->assertEquals(['growing' => ['maturity' => 2.0]], $cfg['verdict_deltas'], 'a list pick is replaced whole');
        $this->assertSame(['trust' => 1], $cfg['trajectory_dimensions']);
        $this->assertSame('a line drawn with {PLAYER}', $cfg['moment_text']['boundary']);
        $this->assertSame(RelDynDiary::configDefaults()['moment_text']['conflict_opened'], $cfg['moment_text']['conflict_opened']);
        $this->assertSame(RelDynDiary::configDefaults()['max_strength'], $cfg['max_strength']);
        $this->assertSame('baseline', RelationshipDynamics::defaultConfig()['diary_reflection_mode'], 'cheap by default');
    }
}
