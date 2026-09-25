<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynPerBondConfigDb
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
 * Per-bond display multiplier and relationship-type modifier layer (roadmap
 * per-bond-type-modifiers; memory notes feedback_baseline_vs_perbond, feedback_perbond_tuning;
 * session 2026-03-30 / recap 2026-03-31):
 *   - baselines are GLOBAL (who the NPC is with everyone); the bond with the player is a
 *     multiplier applied at display time, never persisted;
 *   - effective = x x affinity bonus (1.0 .. 1.15 over core affinity 0..100) x type^exponent:
 *     sqrt for passion / warmth / respect (crush 2.0 -> 1.41), pow 0.75 for trust and comfort;
 *   - global dimensions (maturity, self-confidence, M/F, arousal / valence) pass through, and so
 *     do affinity (the bond itself) and resentment (no type column);
 *   - a type change snaps the effective values (they are computed from the current type);
 *   - the type table has the draft's nine types, RelDyn's friendzone / parasite overlays and
 *     Grieving.
 * Units: dimension points 0..100 (coord / valence -100..100), core affinity -100..100.
 */
final class RelDynPerBondDisplayTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
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

    /** A Guarded NPC whose core Player.type is $coreType at core affinity $coreAff, with $dims set. */
    private function npc(string $coreType, float $coreAff, array $dims = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Guarded',
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        foreach ($dims as $dim => $x) {
            if ($dim === 'passion') { RelationshipDynamics::setPassion($d, $x); continue; }
            $d['dimensions'][$dim]['x'] = $x;
        }
        return $d;
    }

    public function testTheSoftCurvesOfTheTunedFormula(): void
    {
        // crush (core type crush): passion x sqrt(2.0) = 1.414 at core affinity 0 (bonus 1.0)
        $crush = $this->npc('crush', 0.0, ['passion' => 38.0]);
        $this->assertSame('crush', RelationshipDynamics::getRelationshipType('Tester', $crush));
        $this->assertEqualsWithDelta(sqrt(2.0), RelationshipDynamics::perBondMultiplier($crush, 'passion'), 1e-9);
        $this->assertEqualsWithDelta(38.0 * sqrt(2.0), RelationshipDynamics::getEffectiveDimensionValue($crush, 'passion'), 1e-6);
        // ... at core affinity 100 the bonus is the full 1.15: 38 x 1.15 x 1.414 = 61.8, not the
        // flat product's 38 x 1.3 x 2.0 = 98.8 "Redline" (feedback_perbond_tuning)
        $crush100 = $this->npc('crush', 100.0, ['passion' => 38.0]);
        $eff = RelationshipDynamics::getEffectiveDimensionValue($crush100, 'passion');
        $this->assertEqualsWithDelta(38.0 * 1.15 * sqrt(2.0), $eff, 1e-6);
        $this->assertLessThan(76.0, $eff, 'room to breathe: not Burning, never Redline from the multiplier alone');

        // warmth: crush baseline 25 x 1.15 x 1.41 = 40.6, a guarded crush reads Guarded / Cautious, not Comfortable
        $w = RelationshipDynamics::getEffectiveDimensionValue($crush100, 'warmth', 25.0);
        $this->assertEqualsWithDelta(25.0 * 1.15 * sqrt(2.0), $w, 1e-6);
        $this->assertNotSame('Comfortable', RelationshipDynamics::getDimensionBand('warmth', $w)['label']);

        // trust / comfort: pow 0.75 (bonded 2.0 -> 1.68, 2.5 -> 1.99); affinity bonus linear over 0..100
        $bonded = $this->npc('romantic', 60.0, ['trust' => 30.0, 'comfort' => 20.0]);
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType('Tester', $bonded));
        $bonus = 1.0 + 0.15 * 0.60;
        $this->assertEqualsWithDelta(30.0 * $bonus * pow(2.0, 0.75), RelationshipDynamics::getEffectiveDimensionValue($bonded, 'trust'), 1e-6);
        $this->assertEqualsWithDelta(20.0 * $bonus * pow(2.5, 0.75), RelationshipDynamics::getEffectiveDimensionValue($bonded, 'comfort'), 1e-6);
        // respect keeps the default sqrt curve (bonded 1.5)
        $this->assertEqualsWithDelta($bonus * sqrt(1.5), RelationshipDynamics::perBondMultiplier($bonded, 'respect'), 1e-9);

        // clamped to the dimension's range
        $close = $this->npc('romantic', 100.0, ['comfort' => 80.0]);
        $this->assertSame(100.0, RelationshipDynamics::getEffectiveDimensionValue($close, 'comfort'));

        // a negative core affinity gives no bonus (1.0), never a penalty below the type curve
        $enemy = $this->npc('enemy', -80.0, ['trust' => 40.0, 'warmth' => 30.0]);
        $this->assertSame('hostile', RelationshipDynamics::getRelationshipType('Tester', $enemy));
        $this->assertEqualsWithDelta(40.0 * pow(0.1, 0.75), RelationshipDynamics::getEffectiveDimensionValue($enemy, 'trust'), 1e-6);
        $this->assertSame(0.0, RelationshipDynamics::getEffectiveDimensionValue($enemy, 'warmth'), 'Hostile warmth x0');
    }

    public function testGlobalDimensionsAffinityAndResentmentPassThrough(): void
    {
        $d = $this->npc('romantic', 90.0, ['maturity' => 62.0, 'self_confidence' => 41.0, 'arousal' => 33.0,
            'valence' => -12.0, 'coord_m' => 20.0, 'coord_f' => -5.0, 'resentment' => 27.0]);
        foreach (['maturity' => 62.0, 'self_confidence' => 41.0, 'arousal' => 33.0, 'valence' => -12.0,
                  'coord_m' => 20.0, 'coord_f' => -5.0, 'resentment' => 27.0] as $dim => $x) {
            $this->assertSame(1.0, RelationshipDynamics::perBondMultiplier($d, $dim), $dim);
            $this->assertEqualsWithDelta($x, RelationshipDynamics::getEffectiveDimensionValue($d, $dim), 1e-9, $dim);
        }
        // affinity: the bond itself (its x is the core mirror), no multiplier on it
        $this->assertSame(1.0, RelationshipDynamics::perBondMultiplier($d, 'affinity'));
        // an unset dimension has no display value
        $d['dimensions']['trust']['x'] = null;
        $this->assertNull(RelationshipDynamics::getEffectiveDimensionValue($d, 'trust'));
    }

    public function testATypeChangeSnapsTheEffectiveValuesAndNothingIsStored(): void
    {
        $d = $this->npc('platonic', 45.0, ['trust' => 50.0, 'comfort' => 40.0, 'warmth' => 45.0, 'passion' => 30.0]);
        $d['dimensions']['trust']['baseline'] = 28.0;
        $before = $d;
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType('Tester', $d));
        $friendTrust = RelationshipDynamics::getEffectiveDimensionValue($d, 'trust');
        $friendBase = RelationshipDynamics::getEffectiveDimensionValue($d, 'trust', 28.0);
        $this->assertEqualsWithDelta(50.0 * 1.0675 * pow(1.2, 0.75), $friendTrust, 1e-6);
        $this->assertEqualsWithDelta(28.0 * 1.0675 * pow(1.2, 0.75), $friendBase, 1e-6);
        $this->assertSame($before, $d, 'reading the display value stores nothing');

        // betrayal: core writes 'betrayed' (hostile); the same stored state now reads as ice
        RelationshipDynamics::setCoreRelationshipType($d, 'betrayed');
        $this->assertSame('hostile', RelationshipDynamics::getRelationshipType('Tester', $d));
        $hostileTrust = RelationshipDynamics::getEffectiveDimensionValue($d, 'trust');
        $this->assertEqualsWithDelta(50.0 * 1.0675 * pow(0.1, 0.75), $hostileTrust, 1e-6);
        $this->assertEqualsWithDelta(28.0 * 1.0675 * pow(0.1, 0.75), RelationshipDynamics::getEffectiveDimensionValue($d, 'trust', 28.0), 1e-6,
            'the effective baseline snaps to the new type');
        $this->assertSame('Distrustful', RelationshipDynamics::getDimensionBand('trust', $hostileTrust)['label']);
        $this->assertSame('Established', RelationshipDynamics::getDimensionBand('trust', $friendTrust)['label']);
        // the stored (global) baseline and x never moved
        $this->assertSame(28.0, $d['dimensions']['trust']['baseline']);
        $this->assertSame(50.0, $d['dimensions']['trust']['x']);
    }

    public function testTheTypeTableHasGrievingAndTheDeadPerBondBaselineWriterIsGone(): void
    {
        $this->assertSame(0.0, RelationshipDynamics::getTypeModifier('grieving', 'trust'), "can't build trust with a memory");
        $this->assertSame(1.5, RelationshipDynamics::getTypeModifier('grieving', 'comfort'));
        $this->assertSame(2.0, RelationshipDynamics::getTypeModifier('grieving', 'respect'));
        $this->assertSame(2.0, RelationshipDynamics::getTypeModifier('grieving', 'warmth'));
        $this->assertSame(0.0, RelationshipDynamics::getTypeModifier('grieving', 'passion'));
        $this->assertSame(0.3, RelationshipDynamics::getTypeModifier('grieving', 'resistance'));
        foreach (['stranger', 'acquaintance', 'friend', 'crush', 'bonded', 'sworn', 'rival', 'hostile', 'mercenary', 'grieving'] as $type) {
            $this->assertArrayHasKey($type, RelationshipDynamics::RELATIONSHIP_TYPE_MODIFIERS, $type);
        }
        // Baselines are global: nothing may write a type-modified baseline into the stored state
        // (the April applyDeltaWithContext did; its helpers read only for it)
        foreach (['applyDeltaWithContext', 'getEffectiveBaseline', 'getEffectiveResistance'] as $dead) {
            $this->assertFalse(method_exists(RelationshipDynamics::class, $dead), "{$dead}: retired (per-bond is display-time only)");
        }
    }

    public function testTheCurvesAreConfig(): void
    {
        $GLOBALS['db'] = new RelDynPerBondConfigDb(['per_bond_display' => [
            'affinity_bonus_max' => 0.3,
            'type_curve_exponent' => ['trust' => 1.0, 'comfort' => 0.75, 'passion' => 0.5, 'warmth' => 0.5, 'respect' => 0.5],
        ]]);
        RelationshipDynamics::clearConfigCache();
        $d = $this->npc('romantic', 100.0, ['trust' => 30.0]);
        $this->assertEqualsWithDelta(1.3 * 2.0, RelationshipDynamics::perBondMultiplier($d, 'trust'), 1e-9, "Ken's first, flat formula");
    }
}
