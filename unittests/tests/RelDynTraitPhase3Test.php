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
}
