<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Resentment accumulator (MDD 15.5, decisions 2026-09-23 §5), fed by contract-shaped eval
 * items (the shared eval contract v1; fixtures here, not the eval lane's producer).
 * No database: $GLOBALS['db'] is unset, so config is the defaults and nothing is stored.
 *
 * Expected values come from applyDelta() on a copy of the same state: the accumulator
 * physics (inverted rubber band, maturity-derived Y) is the engine's, the tests check what
 * raw amount each rule hands to it.
 */
final class RelDynResentmentAccumulatorTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE'] as $k) {
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

    /** Minimal NPC state; $dims maps dimension => x (0..100). */
    private function npc(array $extra = [], array $dims = []): array
    {
        $d = $extra + [
            'inferred_temperament' => 'Stoic',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
        ];
        $dims += ['maturity' => 60.0, 'self_confidence' => 50.0, 'resentment' => 0.0, 'resentment_self' => 0.0];
        foreach ($dims as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => in_array($dim, ['resentment', 'resentment_self'], true) ? 0 : $x];
        }
        return $d;
    }

    private static function res(array $d, string $dim = 'resentment'): float
    {
        return (float) ($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** A contract v1 inbox item with neutral defaults. */
    private function item(array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => 'Lydia', 'npc_id' => 7, 'gamets' => 1000000, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5,
            'positive_interaction' => false,
            'summary' => 'fixture',
        ], $over);
    }

    // ------------------------------------------------------------ suppressed buildup (MDD 15.5)

    /**
     * "If resentment > 30 and NPC maturity < 50, resentment gains +50%." Y is pinned so
     * maturity only acts through this rule.
     */
    public function testSuppressedBuildupNeedsResentmentAbove30AndMaturityBelow50(): void
    {
        $pin = ['Y_up' => 1.0, 'Y_down' => 1.0];
        $gain = function (float $resentment, float $maturity) use ($pin): float {
            $d = $this->npc([], ['resentment' => $resentment, 'maturity' => $maturity]);
            return RelationshipDynamics::applyDelta('resentment', $d, 2.0, 'Stoic', $pin);
        };

        $this->assertEqualsWithDelta(1.5, $gain(40.0, 45.0) / $gain(40.0, 55.0), 1e-9, 'resentment 40, maturity 45: +50%');
        $this->assertEqualsWithDelta(1.0, $gain(20.0, 45.0) / $gain(20.0, 55.0), 1e-9, 'resentment 20: not yet bottled up');
        $this->assertEqualsWithDelta(1.0, $gain(40.0, 50.0) / $gain(40.0, 55.0), 1e-9, 'maturity 50 is not below 50');
    }

    // ------------------------------------------------------------ effects table (MDD 15.5 / 15.4)

    public function testResentmentEffectsFollowTheMddThresholds(): void
    {
        $fx = fn(float $r) => RelationshipDynamics::getResentmentEffects($this->npc([], ['resentment' => $r]));

        $none = $fx(29.0);
        $this->assertFalse($none['passive_aggressive']);
        $this->assertSame(1.0, $none['affinity_gain_mult']);

        $this->assertTrue($fx(30.0)['passive_aggressive'], '30: passive-aggressive tone');
        $this->assertFalse($fx(49.9)['confrontation_due']);
        $this->assertTrue($fx(50.0)['confrontation_due'], '50: NPC initiates confrontation (P3 reads this)');
        $this->assertSame(1.0, $fx(50.0)['affinity_gain_mult'], '15.4: gains halved only above 50');
        $this->assertSame(0.5, $fx(50.5)['affinity_gain_mult']);
        $this->assertTrue($fx(70.0)['withdrawn'], '70: emotional withdrawal');
        $this->assertSame(0.0, $fx(70.0)['affinity_gain_mult'], '70: affinity frozen');
        $this->assertFalse($fx(89.0)['walkaway']);
        $this->assertTrue($fx(90.0)['walkaway'], '90: the walkaway');
    }

    /** MDD 15.5: the walkaway is at 90, not 70 (70 is withdrawal). The autonomy state reads the accessor. */
    public function testTheNpcWalksAwayAtNinetyNotSeventy(): void
    {
        $dims = ['trust' => 70.0, 'respect' => 70.0, 'comfort' => 0.0];
        $withdrawn = $this->npc([], ['resentment' => 75.0] + $dims);
        $this->assertTrue(RelationshipDynamics::getResentmentEffects($withdrawn)['withdrawn']);
        $this->assertNotSame('walkaway', RelationshipDynamics::evaluateAutonomyState($withdrawn, 'Stoic')['state'],
            'withdrawn at 75, still there');
        $gone = $this->npc([], ['resentment' => 90.0] + $dims);
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($gone, 'Stoic')['state'], '90: the walkaway');
    }

    /** MDD 15.5: at 70 the NPC withdraws, comfort drops to 0 and stays there while withdrawn. */
    public function testWithdrawalDropsComfortToZeroUntilResentmentFalls(): void
    {
        $d = $this->npc([], ['resentment' => 66.0, 'maturity' => 40.0, 'comfort' => 55.0]);
        RelationshipDynamics::recordGrievance($d, ['flag' => true, 'kind' => 'insult', 'severity' => 3], []);
        $this->assertGreaterThanOrEqual(70.0, self::res($d), 'the grievance crosses 70');
        $this->assertSame(0.0, (float) $d['dimensions']['comfort']['x'], 'comfort drops to 0');

        RelationshipDynamics::applyDelta('comfort', $d, 10.0, 'Stoic');
        $this->assertSame(0.0, (float) $d['dimensions']['comfort']['x'], 'no comfort while withdrawn');

        $d['dimensions']['resentment']['x'] = 60.0;   // worked through (confrontation, positive interactions)
        RelationshipDynamics::applyDelta('comfort', $d, 10.0, 'Stoic');
        $this->assertGreaterThan(0.0, (float) $d['dimensions']['comfort']['x'], 'comfort can grow again below 70');
    }

    /** The cross-signal cap on affinity gains reads the same accessor (one number). */
    public function testAffinityGainCapReadsTheEffectsAccessor(): void
    {
        foreach ([0.0 => 1.0, 60.0 => 0.5, 75.0 => 0.0] as $resentment => $mult) {
            $d = $this->npc([], ['resentment' => (float) $resentment, 'affinity' => 50.0]);
            $this->assertSame($mult, RelationshipDynamics::getResentmentEffects($d)['affinity_gain_mult']);
            $this->assertEqualsWithDelta(4.0 * $mult, RelationshipDynamics::applyCrossSignalCaps($d, 'affinity', 4.0), 1e-9,
                "resentment {$resentment}");
            $this->assertEqualsWithDelta(-4.0, RelationshipDynamics::applyCrossSignalCaps($d, 'affinity', -4.0), 1e-9,
                'losses are not capped');
        }
    }

    // ------------------------------------------------------------ contract grievances

    public function testContractGrievanceAddsFiveRawPerSeverityGrade(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $this->assertSame(5.0, (float) $cfg['grievance_resentment_raw'], 'MDD 15.5: +5');
        foreach ([0 => 5.0, 1 => 5.0, 2 => 7.5, 3 => 10.0] as $severity => $raw) {
            $d = $this->npc([], ['resentment' => 20.0]);
            $expected = $d;
            RelationshipDynamics::applyDelta('resentment', $expected, $raw, 'Stoic');

            $out = RelationshipDynamics::applyEvalFeelings('Lydia',
                $this->item(['grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => $severity]]), $d);

            $this->assertEqualsWithDelta(self::res($expected), self::res($d), 1e-9, "severity {$severity}");
            $this->assertEqualsWithDelta($raw, $out['grievance']['raw'], 1e-9);
            $log = end($d['dimensions']['resentment']['grievance_log']);
            $this->assertSame('insult', $log['kind']);
            $this->assertSame($severity, $log['severity']);
        }
    }

    public function testUnflaggedGrievanceAndLegacyItemsChangeNothing(): void
    {
        $d = $this->npc([], ['resentment' => 20.0]);
        $before = $d;
        $out = RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['grievance' => ['flag' => false, 'kind' => 'insult', 'severity' => 3]]), $d);
        $this->assertNull($out['grievance']);
        $this->assertSame(self::res($before), self::res($d));

        $legacy = ['affinity_delta' => -5, 'grievance' => 'said something cruel'];
        $this->assertSame([], RelationshipDynamics::applyEvalFeelings('Lydia', $legacy, $d), 'not a contract item');
        $this->assertFalse(RelationshipDynamics::isEvalContractItem($legacy));
        $this->assertTrue(RelationshipDynamics::isEvalContractItem($this->item()));
    }

    /** The contract's grievance object is not the legacy pending-grievance string. */
    public function testProcessEvalDeltasDoesNotQueueTheContractGrievanceObject(): void
    {
        $d = $this->npc([], ['resentment' => 20.0]);
        RelationshipDynamics::processEvalDeltas('Lydia',
            $this->item(['grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 1]]), $d);
        $this->assertSame([], $d['dimensions']['resentment']['pending_grievances'] ?? []);
    }

    public function testPostHooverResentmentRebuildsFaster(): void
    {
        $d = $this->npc(['_hoover_resentment_mult' => 1.5], ['resentment' => 20.0]);
        $out = RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 1]]), $d);
        $this->assertEqualsWithDelta(7.5, $out['grievance']['raw'], 1e-9, '5 x hoover 1.5');
    }

    // ------------------------------------------------------------ power gap (decisions §5)

    public function testPowerGapIsTheLargestSourceFromCoreData(): void
    {
        $gap = fn(array $facts) => RelationshipDynamics::computePowerGap($facts)['gap'];

        $this->assertSame(0.0, $gap(['core_type' => 'platonic', 'in_party' => false, 'factions' => []]));
        $this->assertSame(1.0, $gap(['core_type' => 'servant', 'in_party' => false, 'factions' => []]), 'servant');
        $this->assertSame(0.8, $gap(['core_type' => 'fanatical', 'in_party' => false, 'factions' => []]), 'housecarl-like loyalty');
        $this->assertSame(0.5, $gap(['core_type' => 'platonic', 'in_party' => true, 'factions' => []]), 'commanded follower');
        $this->assertSame(1.0, $gap(['core_type' => null, 'in_party' => true,
            'factions' => [['name' => 'DLC1ThrallFaction', 'rank' => 0]]]), 'thrall, the largest source counts');
        $this->assertSame(0.8, $gap(['core_type' => null, 'in_party' => false,
            'factions' => [['name' => 'HousecarlWhiterunFaction', 'rank' => 0]]]));
        $this->assertSame(0.0, $gap(['core_type' => null, 'in_party' => false,
            'factions' => [['name' => 'DLC1ThrallFaction', 'rank' => -1]]]), 'rank -1 is not a member');
    }

    /** grievance x (1 + power_gap): a servant who cannot leave takes an insult twice as hard. */
    public function testGrievanceIsWeightedByOnePlusPowerGap(): void
    {
        $free = $this->npc(['_core_rel_type' => 'platonic'], ['resentment' => 20.0]);
        $bound = $this->npc(['_core_rel_type' => 'servant'], ['resentment' => 20.0]);
        $expected = $bound;
        RelationshipDynamics::applyDelta('resentment', $expected, 10.0, 'Stoic');

        $g = ['grievance' => ['flag' => true, 'kind' => 'being used', 'severity' => 1]];
        $outFree = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item($g), $free);
        $outBound = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item($g), $bound);

        $this->assertEqualsWithDelta(5.0, $outFree['grievance']['raw'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $outBound['grievance']['raw'], 1e-9, '5 x (1 + 1.0)');
        $this->assertSame(1.0, $outBound['grievance']['power_gap']);
        $this->assertEqualsWithDelta(self::res($expected), self::res($bound), 1e-9);
    }

    public function testCommandedFollowerFromTheCurrentParty(): void
    {
        $GLOBALS['CACHE_PARTY'] = json_encode(['Lydia' => ['name' => 'Lydia'], 'Kaida' => ['name' => 'Kaida']]);
        $facts = RelationshipDynamics::powerGapFacts('lydia', ['_core_rel_type' => 'platonic']);
        $this->assertTrue($facts['in_party']);
        $this->assertFalse(RelationshipDynamics::powerGapFacts('Aela', [])['in_party']);
    }

    /** Low self-respect + low maturity (autonomy design): the grievance turns inward. */
    public function testPeoplePleaserInternalizesAsResentmentSelf(): void
    {
        $d = $this->npc(['_core_rel_type' => 'servant'], ['resentment' => 20.0, 'self_confidence' => 20.0, 'maturity' => 30.0]);
        $this->assertTrue(RelationshipDynamics::isPeoplePleaser($d));
        $expected = $d;
        RelationshipDynamics::applyDelta('resentment_self', $expected, 10.0, 'Stoic');

        $out = RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 1]]), $d);

        $this->assertSame('resentment_self', $out['grievance']['target']);
        $this->assertSame(20.0, self::res($d), 'resentment itself untouched');
        $this->assertEqualsWithDelta(self::res($expected, 'resentment_self'), self::res($d, 'resentment_self'), 1e-9);
        $this->assertFalse(RelationshipDynamics::isPeoplePleaser($this->npc([], ['self_confidence' => 20.0, 'maturity' => 45.0])));
    }

    // ------------------------------------------------------------ positive interactions

    /** MDD 15.5: -1 per meaningful positive interaction, each evaluated one, no play-time throttle. */
    public function testEachPositiveInteractionTakesOneRawPoint(): void
    {
        $d = $this->npc(['_accumulated_play_gamets' => 5.0e6, '_resentment_last_play_gamets' => 5.0e6], ['resentment' => 40.0]);
        $expected = $d;
        RelationshipDynamics::applyDelta('resentment', $expected, -1.0, 'Stoic');
        RelationshipDynamics::applyDelta('resentment', $expected, -1.0, 'Stoic');

        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true]), $d);
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true]), $d);

        $this->assertEqualsWithDelta(self::res($expected), self::res($d), 1e-9);
        $this->assertLessThan(40.0, self::res($d));
        $this->assertArrayNotHasKey('_eval_feelings_seen', $d, 'no permanent flag: the eval owns decay per scored exchange (postrequest)');
        $this->assertSame(2, (int) $d['total_positive_interactions'], 'each positive exchange counts for the passion stages');
    }

    public function testResentmentNeverDecaysFromTimeAlone(): void
    {
        $d = $this->npc(['jealousy_anger' => 20.0], ['resentment' => 45.0]);
        RelationshipDynamics::advanceCalendar($d, 300 * RelationshipDynamics::GAMETS_PER_DAY, 400 * RelationshipDynamics::GAMETS_PER_DAY);
        $this->assertSame(45.0, self::res($d));
    }

    /** processGrievances (legacy pending list) no longer stacks its own x1.5 on the rule. */
    public function testLegacyPendingGrievanceIsFivePointsThroughThePhysicsOnce(): void
    {
        $d = $this->npc([], ['resentment' => 40.0, 'maturity' => 30.0]);
        $expected = $d;
        RelationshipDynamics::applyDelta('resentment', $expected, 5.0, 'Stoic');

        $d['dimensions']['resentment']['pending_grievances'] = ['changed the subject when she was hurt'];
        $this->assertSame(1, RelationshipDynamics::processGrievances($d, 'Stoic'));

        $this->assertEqualsWithDelta(self::res($expected), self::res($d), 1e-9);
        $this->assertSame([], $d['dimensions']['resentment']['pending_grievances']);
    }
}
