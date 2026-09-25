<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Personality traits phase 3 (D:\docs\reldyn-personality-traits-design.md §6.1 item 3;
 * decisions 2026-09-23 §16): the deliberate fixes, one test per fix, on the real consumers.
 * No database: shipped config defaults. The four test beds end to end (real hooks, real
 * PostgreSQL) are in RelDynTraitTestBedsPostgresTest.
 */
final class RelDynTraitPhase3Test extends TestCase
{
    private $savedDb;
    private $prevErrorLog;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);   // shipped defaults
        RelDynTraits::$assignmentOverride = 'read';
        RelationshipDynamics::clearConfigCache();
        $this->prevErrorLog = ini_set('error_log', '/dev/null');   // consumers log what they do
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        RelationshipDynamics::clearConfigCache();
    }

    /** A fresh NPC state whose own vector (read assignment) is $vector; label = nearest preset. */
    private static function at(array $vector, array $extra = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $x = RelDynTraits::normalizeVector($vector);
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src'] = ['assignment' => 'read'];
        $d['inferred_temperament'] = RelDynTraits::nearestPreset($x)['name'];
        return array_replace_recursive($d, $extra);
    }

    /** An NPC sitting exactly on a preset point, with extra state. */
    private static function preset(string $name, array $extra = []): array
    {
        return self::at(RelDynTraits::points()[$name], $extra);
    }

    /** Serene's hand-set, spoiler-free Ashe vector (config npc_overrides; decisions §16 #4). */
    private static function asheVector(): array
    {
        $o = RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides']['ashe'];
        return $o['trait_vector'] + ['maturity_start' => $o['maturity_start']];
    }

    private static function style(string $style): array
    {
        return ['profile_overrides' => ['attachment_style' => $style]];
    }

    // =========================================================================
    // §16 #5: stacked romance momentum capped at 2.5x
    // =========================================================================

    public function testStackedRomanceMomentumIsCappedAtTwoAndAHalf(): void
    {
        $cfg = RelDynRomance::config();
        $this->assertSame(2.5, $cfg['momentum_stack_cap']);

        // Guarded (x2.0) with an avoidant attachment (x2.0): 4x before, 2.5x now
        $m = RelDynRomance::momentumMult(self::preset('Guarded', self::style('avoidant')), $cfg);
        $this->assertEqualsWithDelta(2.0, $m['attachment'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $m['temperament'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $m['stacked'], 1e-9);
        $this->assertEqualsWithDelta(2.5, $m['mult'], 1e-9);
        // a single factor is not capped below 2.5; the cap never raises anything
        $this->assertEqualsWithDelta(2.0, RelDynRomance::momentumMult(self::preset('Guarded', self::style('secure')), $cfg)['mult'], 1e-9);
        $this->assertEqualsWithDelta(1.0, RelDynRomance::momentumMult(self::preset('Guarded', self::style('anxious')), $cfg)['mult'], 1e-9);
        $this->assertEqualsWithDelta(2.0, RelDynRomance::momentumMult(self::preset('Stoic', self::style('avoidant')), $cfg)['mult'], 1e-9);
        $this->assertEqualsWithDelta(0.5, RelDynRomance::momentumMult(self::preset('Playful', self::style('anxious')), $cfg)['mult'], 1e-9);

        // Ashe: her own vector (momentum 1.6) and her own axes (moderate avoidance): not capped;
        // at the avoidant corner she would be 3.2x, capped to 2.5x
        $ashe = self::at(self::asheVector(), ['profile_overrides' => ['attachment_axes' => ['anxiety' => 0.3, 'avoidance' => 0.5]]]);
        $a = RelDynRomance::momentumMult($ashe, $cfg);
        $this->assertEqualsWithDelta(1.6, $a['temperament'], 1e-9);
        $this->assertLessThan(2.5, $a['stacked']);
        $this->assertEqualsWithDelta($a['stacked'], $a['mult'], 1e-12);
        $this->assertEqualsWithDelta(2.5, RelDynRomance::momentumMult(self::at(self::asheVector(), self::style('avoidant')), $cfg)['mult'], 1e-9);

        // the cap is config: removing it gives the old product back
        $this->assertEqualsWithDelta(4.0, RelDynRomance::momentumMult(self::preset('Guarded', self::style('avoidant')),
            ['momentum_stack_cap' => null] + $cfg)['mult'], 1e-9);
    }

    // =========================================================================
    // §16 #6: MDD 15.4 edits (R maturity retired, Volatile row deleted, Humble 1.0)
    // =========================================================================

    public function testMddFifteenFourEdits(): void
    {
        $rows = RelationshipDynamics::TEMPERAMENT_SIGNAL_RESISTANCE;
        $this->assertArrayNotHasKey('Volatile', $rows, 'the unreachable row is gone');
        $this->assertArrayNotHasKey('Humble', $rows, 'Humble still has no row');
        foreach ($rows as $name => $row) {
            $this->assertTrue(RelDynTraits::isPreset($name), "{$name}: every row is a temperament");
            $this->assertSame(['affinity', 'trust', 'comfort', 'respect'], array_keys($row), "{$name}: no maturity column");
        }
        $this->assertFalse(RelDynTraits::hasColumn('resist_maturity'));

        // R maturity is 1.0 for everyone: presets (label and point), Ashe's vector, no label, 'Volatile'
        foreach (array_keys(RelDynTraits::PRESET_TRAITS) as $p) {
            $this->assertSame(1.0, RelationshipDynamics::getSignalResistance($p, 'maturity'), $p);
            $this->assertSame(1.0, RelationshipDynamics::getSignalResistance($p, 'maturity', self::preset($p)), "{$p} point");
        }
        $ashe = self::at(self::asheVector());
        $this->assertSame(1.0, RelationshipDynamics::getSignalResistance($ashe['inferred_temperament'], 'maturity', $ashe));
        foreach ([null, '', 'Volatile'] as $label) {
            foreach (['affinity', 'trust', 'comfort', 'respect', 'maturity'] as $sig) {
                $this->assertSame(1.0, RelationshipDynamics::getSignalResistance($label, $sig), json_encode($label) . " {$sig}");
            }
        }
        // Humble resists nothing, on every signal, as a label and as a point (decisions §16 #6)
        foreach (['affinity', 'trust', 'comfort', 'respect', 'maturity'] as $sig) {
            $this->assertSame(1.0, RelationshipDynamics::getSignalResistance('Humble', $sig), "Humble {$sig}");
            $this->assertSame(1.0, RelationshipDynamics::getSignalResistance('Humble', $sig, self::preset('Humble')), "Humble point {$sig}");
        }
        // the other signals are untouched at the presets
        $this->assertSame(0.4, RelationshipDynamics::getSignalResistance('Guarded', 'trust'));
        $this->assertSame(1.5, RelationshipDynamics::getSignalResistance('Proud', 'respect'));

        // End to end through the eval: a maturity signal is moved by the maturity type alone
        // (Stoic was R 0.5 x Resilient 0.5 = 0.25 on a loss; now 0.5)
        foreach (['Stoic' => ['Resilient', -10.0, -5.0], 'Anxious' => ['Volatile', -10.0, -15.0], 'Nurturing' => ['Growth', 10.0, 13.0]] as $p => [$type, $raw, $want]) {
            $d = self::preset($p);
            $d['dimensions']['maturity']['plasticity_type'] = $type;
            $d['dimensions']['maturity']['x'] = $d['dimensions']['maturity']['baseline'];
            $d['dimensions']['comfort']['x'] = 50;   // no low-comfort halving of maturity gains
            $r = RelationshipDynamics::applyEvalSignal('Npc', $d, 'maturity', $raw, [], 1.0);
            $this->assertEqualsWithDelta($want, $r['actual'], 1e-6, "{$p} {$type}");
        }
    }

    // =========================================================================
    // A16 split: trust and comfort resist gains and losses separately
    // =========================================================================

    public function testTrustAndComfortResistGainsAndLossesSeparately(): void
    {
        $R = fn($d, string $sig, bool $loss) => RelationshipDynamics::getSignalResistance($d['inferred_temperament'], $sig, $d, $loss);
        // at the presets: the gain is today's MDD 15.4 row, the loss the MDD's Y_down column
        $loss = ['Guarded' => [1.5, 0.3], 'Jealous' => [1.8, 1.3], 'Proud' => [1.8, 1.0], 'Stoic' => [1.0, 0.4],
                 'Anxious' => [1.5, 1.3], 'Bold' => [1.0, 0.7], 'Humble' => [1.0, 1.0]];
        foreach ($loss as $p => [$trustDown, $comfortDown]) {
            $d = self::preset($p);
            $this->assertEqualsWithDelta(RelationshipDynamics::TEMPERAMENT_SIGNAL_RESISTANCE[$p]['trust'] ?? 1.0, $R($d, 'trust', false), 1e-12, "{$p} trust gain");
            $this->assertEqualsWithDelta(RelationshipDynamics::TEMPERAMENT_SIGNAL_RESISTANCE[$p]['comfort'] ?? 1.0, $R($d, 'comfort', false), 1e-12, "{$p} comfort gain");
            $this->assertEqualsWithDelta($trustDown, $R($d, 'trust', true), 1e-12, "{$p} trust loss");
            $this->assertEqualsWithDelta($comfortDown, $R($d, 'comfort', true), 1e-12, "{$p} comfort loss");
            foreach (['affinity', 'respect'] as $sig) {
                $this->assertSame($R($d, $sig, false), $R($d, $sig, true), "{$p} {$sig}: symmetric");
            }
        }
        // the label path (no vector) reads the same tables
        $this->assertSame(1.5, RelationshipDynamics::getSignalResistance('Guarded', 'trust', null, true));
        $this->assertSame(0.4, RelationshipDynamics::getSignalResistance('Guarded', 'trust', null, false));
        $this->assertSame(1.0, RelationshipDynamics::getSignalResistance(null, 'trust', null, true), 'no temperament: 1.0 both ways');

        // slow gain, fast loss for the guarded: Guarded wins trust at x0.4 and loses it at x1.5
        $g = self::preset('Guarded');
        $this->assertGreaterThan(3.0 * $R($g, 'trust', false), $R($g, 'trust', true));
        // Ashe (her own vector, far from every preset: the pure models): slow to trust
        // (guard .75), and losing it is betrayal-sensitive but moderate (low possessiveness)
        $ashe = self::at(self::asheVector());
        $this->assertEqualsWithDelta(1.19 - 0.86 * 0.75, $R($ashe, 'trust', false), 1e-9);
        $this->assertEqualsWithDelta(0.80 + 0.93 * 0.20 + 0.40 * 0.40, $R($ashe, 'trust', true), 1e-9);
        // between presets the loss side rises with possessiveness and pride (betrayal sensitivity)
        $prev = -INF;
        foreach ([0.05, 0.25, 0.45, 0.65, 0.85] as $po) {
            $v = $R(self::at(['possessiveness' => $po, 'guard' => 0.5, 'pride' => 0.5]), 'trust', true);
            $this->assertGreaterThan($prev, $v, "trust loss rises with Po ({$po})");
            $prev = $v;
        }

        // End to end through the eval (Guarded, Brittle, at baseline): a gain is unchanged
        // (10 x 0.4 x 0.7 = 2.8); a loss is x1.5 x 1.3 = -19.5 (was 0.4 x 1.3 = -5.2)
        foreach ([[10.0, 2.8], [-10.0, -19.5]] as [$raw, $want]) {
            $d = self::preset('Guarded');
            $d['dimensions']['maturity']['plasticity_type'] = 'Brittle';
            $d['dimensions']['maturity']['x'] = 45;
            $d['dimensions']['trust']['x'] = $d['dimensions']['trust']['baseline'];
            $r = RelationshipDynamics::applyEvalSignal('Npc', $d, 'trust', $raw, [], 1.0);
            $this->assertEqualsWithDelta($want, $r['actual'], 1e-3, "Guarded trust {$raw}");
        }
    }
}
