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
}
