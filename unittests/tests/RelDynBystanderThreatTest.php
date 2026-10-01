<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Bystander jealousy, rulings 2026-10-01 §20 #7, the parts that need no database: the rival's threat (how close
 * she is to the player), and maturity's softening as ONE curve with the one the affinity pipeline already has,
 * floored so that no one is immune. (The story on PostgreSQL with the test beds is
 * RelDynBystanderThreatTestBedsPostgresTest.)
 */
final class RelDynBystanderThreatTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PEOPLE'] as $k) {
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

    /** The stored config row is $stored (laid over the defaults), through a stub database. */
    private function store(array $stored): void
    {
        $GLOBALS['db'] = new class($stored) {
            public function __construct(private array $stored) {}
            public function fetchOne($q, array $params = []) { return ['value' => json_encode($this->stored + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA])]; }
            public function fetchAll($q, $log = false) { return []; }
            public function escape($s) { return str_replace("'", "''", (string) $s); }
        };
        RelationshipDynamics::clearConfigCache();
    }

    /** A committed (romantic) observer of the given maturity, trust 0 so only the factors under test move the gain. */
    private function observer(float $maturity, array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => 'Romantic', '_core_rel_type' => 'romantic', 'profile_overrides' => ['attachment_style' => 'secure'],
                       'jealousy_anger' => 0.0, 'dimensions' => []];
        foreach (['maturity' => $maturity, 'trust' => 0.0, 'self_confidence' => 50.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    // ------------------------------------------------------------------ the rival: how close she is to the player

    public function testTheRivalIsCloserByAffinityBondTypeAndPassion(): void
    {
        $c = fn(?array $rel, ?array $dyn = null) => RelationshipDynamics::rivalCloseness($rel, $dyn);
        $this->assertSame(0.0, $c(null), 'a stranger, or no data');
        $this->assertSame(0.0, $c(['aff' => 0, 'type' => 'neutral']));
        $this->assertSame(0.0, $c(['aff' => -60, 'type' => 'rival']), 'an enemy of the player is no threat to his partner');
        $this->assertEqualsWithDelta(0.5, $c(['aff' => 50, 'type' => 'neutral']), 1e-9);
        $this->assertEqualsWithDelta(1.0, $c(['aff' => 250]), 1e-9, 'capped');
        // the bond type is a floor: a lover at a modest number is still a lover
        $this->assertEqualsWithDelta(0.85, $c(['aff' => 20, 'type' => 'romantic']), 1e-9);
        $this->assertEqualsWithDelta(0.95, $c(['aff' => 95, 'type' => 'romantic']), 1e-9, 'and the number counts when it is higher');
        // her own passion for the player counts, weighted
        $this->assertEqualsWithDelta(0.8, $c(['aff' => 10], ['dimensions' => ['passion' => ['x' => 100.0]]]), 1e-9);
        $this->assertEqualsWithDelta(0.4, $c(['aff' => 10], ['passion' => 50.0]), 1e-9);
    }

    public function testACloserRivalStingsMoreAndAStrangerStillStings(): void
    {
        $t = fn(?float $c) => RelationshipDynamics::bystanderThreat($c);
        $this->assertSame(1.0, $t(null), 'the rival unknown: the old fixed rate');
        $this->assertEqualsWithDelta(1.0, $t(0.5), 1e-9, 'a rival of middling closeness is the old fixed rate');
        $this->assertLessThan($t(0.5), $t(0.0));
        $this->assertGreaterThan($t(0.5), $t(1.0));
        $this->assertGreaterThan(0.0, $t(0.0), 'a stranger still stings, only less');
        $this->assertEqualsWithDelta(0.6, $t(0.0), 1e-9);
        $this->assertEqualsWithDelta(1.4, $t(1.0), 1e-9);
        $this->assertSame($t(1.0), $t(7.0), 'closeness is capped');
    }

    // ------------------------------------------------------------------ the bystander: maturity, one curve, floored

    public function testMaturitySoftensItOnTheSameCurveThatBluntsHerHurt(): void
    {
        foreach ([0.0, 20.0, 30.0, 50.0, 60.0, 80.0, 100.0] as $m) {
            $obs = $this->observer($m);
            $hurt = RelationshipDynamics::affinityModifiers($obs, -10.0, [])['rows']['maturity_losses'];
            $this->assertEqualsWithDelta($hurt, RelationshipDynamics::bystanderMaturityFactor($obs), 1e-9, "maturity {$m}: the one curve");
        }
        $this->assertEqualsWithDelta(1.5, RelationshipDynamics::bystanderMaturityFactor($this->observer(0.0)), 1e-9);
        $this->assertEqualsWithDelta(1.0, RelationshipDynamics::bystanderMaturityFactor($this->observer(50.0)), 1e-9);
        $this->assertEqualsWithDelta(0.5, RelationshipDynamics::bystanderMaturityFactor($this->observer(100.0)), 1e-9);
        $this->assertGreaterThan(RelationshipDynamics::bystanderMaturityFactor($this->observer(80.0)), RelationshipDynamics::bystanderMaturityFactor($this->observer(20.0)),
            'the immature are more rattled than the mature');
    }

    public function testMaturityIsNotAppliedASecondTimeAndNoOneIsImmune(): void
    {
        // the draft's (1 - maturity/100) on top of the curve: a maturity-80 observer would be left with 0.7 x 0.2 = 0.14
        $obs = $this->observer(80.0);
        $this->assertEqualsWithDelta(0.7, RelationshipDynamics::bystanderMaturityFactor($obs), 1e-9, 'the curve alone');
        // the most mature there is: softened to the floor of the curve, not to nothing
        $saint = $this->observer(100.0);
        $base = RelationshipDynamics::bystanderJealousyGain($saint);
        $this->assertGreaterThan(0.0, $base);
        $felt = $base * RelationshipDynamics::bystanderJealousyFactor($saint, 0.5);
        $this->assertGreaterThan(0.4 * $base - 1e-9, $felt, 'at least the floor share of what an unsoftened observer would feel');
        $this->assertEqualsWithDelta(0.5 * $base, $felt, 1e-9, 'a mature observer feels half, at a rival of middling closeness');
        // even a close rival and a maturity of 100 leave a jealousy that moves her
        $this->assertGreaterThan(1.0, $base * RelationshipDynamics::bystanderJealousyFactor($saint, 1.0));
    }

    public function testTheFloorHoldsWhateverTheTableSays(): void
    {
        // the one curve is edited so far down it would be zero: the floor still leaves the bystander jealous
        $this->store(['affinity_modifiers' => [['id' => 'maturity_losses', 'sign' => 'loss', 'tags' => [], 'when' => [], 'mult' => 0.05]]]);
        $obs = $this->observer(90.0);
        $this->assertEqualsWithDelta(0.4, RelationshipDynamics::bystanderMaturityFactor($obs), 1e-9, 'jealousy_bystander.maturity_floor');
        $this->assertEqualsWithDelta(0.05, RelationshipDynamics::affinityModifiers($obs, -10.0, [])['rows']['maturity_losses'], 1e-9, 'the same edit really did reach the curve');
    }

    public function testEditingTheCurveMovesBothBecauseThereIsOnlyOne(): void
    {
        $this->store(['affinity_modifiers' => [['id' => 'maturity_losses', 'sign' => 'loss', 'tags' => [], 'when' => [], 'mult' => 0.8]]]);
        $obs = $this->observer(10.0);
        $this->assertEqualsWithDelta(0.8, RelationshipDynamics::affinityModifiers($obs, -10.0, [])['rows']['maturity_losses'], 1e-9);
        $this->assertEqualsWithDelta(0.8, RelationshipDynamics::bystanderMaturityFactor($obs), 1e-9);
        // a table that has no such row: no softening, and no error
        $this->store(['affinity_modifiers' => [['id' => 'something_else', 'sign' => 'loss', 'tags' => [], 'when' => [], 'mult' => 0.5]]]);
        $this->assertSame(1.0, RelationshipDynamics::bystanderMaturityFactor($obs));
    }

    public function testTheSettingsMoveTheThreatAndTheFloor(): void
    {
        $this->store(['jealousy_bystander' => ['threat' => ['at0' => 1.0, 'at100' => 2.0], 'maturity_floor' => 0.7]]);
        $this->assertEqualsWithDelta(1.5, RelationshipDynamics::bystanderThreat(0.5), 1e-9);
        $this->assertEqualsWithDelta(0.7, RelationshipDynamics::bystanderMaturityFactor($this->observer(100.0)), 1e-9, 'a higher floor');
        // a stored section that names one key only keeps the others' defaults
        $this->store(['jealousy_bystander' => ['passion_weight' => 1.0]]);
        $this->assertEqualsWithDelta(0.6, RelationshipDynamics::bystanderThreat(0.0), 1e-9);
        $this->assertEqualsWithDelta(0.85, RelationshipDynamics::rivalCloseness(['aff' => 0, 'type' => 'romantic']), 1e-9);
    }

    public function testTheCommitmentStillGatesWhoMindsAtAll(): void
    {
        $friend = $this->observer(20.0, ['_core_rel_type' => 'platonic']);
        $this->assertSame(0.0, RelationshipDynamics::bystanderJealousyGain($friend), 'a friend is not jealous: the factor never lifts a zero');
    }

    public function testTheSameRivalLandsHarderOnTheImmatureJealousOneThanOnTheSteadyOne(): void
    {
        $immature = $this->observer(20.0, ['inferred_temperament' => 'Jealous']);
        $steady = $this->observer(85.0, ['inferred_temperament' => 'Romantic']);
        $feel = fn(array $o, float $c) => RelationshipDynamics::bystanderJealousyGain($o) * RelationshipDynamics::bystanderJealousyFactor($o, $c);
        $this->assertGreaterThan($feel($steady, 0.9), $feel($immature, 0.9));
        foreach ([$immature, $steady] as $o) {
            $this->assertGreaterThan($feel($o, 0.2), $feel($o, 0.9), 'and each of them minds a close rival more than a stranger');
            $this->assertGreaterThan(0.0, $feel($o, 0.0), 'but never nothing');
        }
    }
}
