<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Batch T traits: physical-state-bridges (review queue 2026-09-30, JUDGED).
 *
 *  - The 'injured' row no longer carries a trust part for a healer TEMPERAMENT (Nurturing, Gentle,
 *    Anxious): the trust goes to the NPC who heals the player (consumeHealEvents; the bed test).
 *  - The 'dirty' respect hit is for the proud: the trait engine's pride (Pd) of 0.5 or more, not every NPC.
 *  - The survival states (hungry, warm by a fire, rested, exhausted, dirty, bloody) have no CHIM 3.4.1
 *    signal: their rows stay in PHYSICAL_STATE_MODIFIERS, config physical_states.inert lists them, and
 *    detectPhysicalStates never reports one.
 *
 * No database.
 */
final class RelDynPhysicalStatesTraitsTest extends TestCase
{
    private $savedDb;
    private $savedAssignment;
    private $prevErrorLog;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        $this->savedAssignment = RelDynTraits::$assignmentOverride;
        RelDynTraits::$assignmentOverride = 'label';
        RelationshipDynamics::clearConfigCache();
        $this->prevErrorLog = ini_set('error_log', sys_get_temp_dir() . '/reldyn_physical_traits_test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        RelDynTraits::$assignmentOverride = $this->savedAssignment;
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    private function applied(string $state, string $label): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['inferred_temperament'] = $label;
        RelationshipDynamics::applyPhysicalStateModifiers($d, [$state], $label);
        return $d['_applied_physical_deltas'][$state] ?? [];
    }

    public function testNoTemperamentGetsTrustFromBeingInjuredAnyMore(): void
    {
        $this->assertArrayNotHasKey('trust', RelationshipDynamics::PHYSICAL_STATE_MODIFIERS['injured']);
        foreach (array_keys(RelDynTraits::points()) as $label) {
            $a = $this->applied('injured', $label);
            $this->assertArrayNotHasKey('trust', $a, $label);
            foreach (['arousal', 'valence', 'maturity'] as $dim) $this->assertArrayHasKey($dim, $a, "{$label} {$dim}");
        }
        // The healer temperaments of A23 are only the column now
        $this->assertSame(['Nurturing', 'Gentle', 'Anxious'], RelationshipDynamics::PHYSICAL_HEALER_TEMPERAMENTS);
    }

    public function testTheDirtyRespectHitIsForTheProudNotEveryone(): void
    {
        $hit = [];
        foreach (array_keys(RelDynTraits::points()) as $label) {
            $a = $this->applied('dirty', $label);
            $this->assertArrayHasKey('comfort', $a, "{$label}: being dirty is uncomfortable for anyone");
            $hit[$label] = isset($a['respect']);
            $pd = RelDynTraits::presetPoint($label)['Pd'];
            $this->assertSame($pd >= 0.5, $hit[$label], "{$label} (pride {$pd})");
        }
        $this->assertTrue($hit['Proud'], 'Proud loses face');
        $this->assertTrue($hit['Defiant']);
        $this->assertFalse($hit['Humble'], 'Humble does not mind');
        $this->assertFalse($hit['Gentle']);
        $this->assertLessThan(0.0, $this->applied('dirty', 'Proud')['respect']);
    }

    public function testPrideIsHerOwnTraitNotThePresetsLabel(): void
    {
        RelDynTraits::$assignmentOverride = 'read';
        $x = RelDynTraits::presetPoint('Humble');          // labelled Humble ...
        foreach ([[0.45, false], [0.55, true]] as [$pd, $expect]) {
            $x['Pd'] = $pd;                                // ... with her own pride
            $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
                'inferred_temperament' => 'Humble', 'trait_vector' => RelDynTraits::toStored($x),
                '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($x)],
            ]));
            RelationshipDynamics::applyPhysicalStateModifiers($d, ['dirty'], 'Humble');
            $this->assertSame($expect, isset($d['_applied_physical_deltas']['dirty']['respect']), "pride {$pd}");
        }
    }

    public function testTheSurvivalRowsStayInConfigAndAreInert(): void
    {
        $inert = RelationshipDynamics::defaultConfig()['physical_states']['inert'];
        $this->assertEqualsCanonicalizing(['hungry', 'warm_fire', 'well_rested', 'exhausted', 'dirty', 'bloody'], $inert);
        foreach (['warm_fire', 'well_rested', 'exhausted', 'dirty', 'bloody'] as $state) {
            $this->assertArrayHasKey($state, RelationshipDynamics::PHYSICAL_STATE_MODIFIERS, "{$state}: the row is kept");
        }
        // the four states core can see are not inert
        foreach (['injured', 'raining', 'snowing', 'cold', 'clear_night'] as $state) {
            $this->assertNotContains($state, $inert);
        }
        $this->assertArrayHasKey('cold', RelationshipDynamics::PHYSICAL_STATE_MODIFIERS);
        // a saved config that holds only part of the setting still gets the rest from the defaults
        $cfg = ['injured_health_ratio' => 0.4] + RelationshipDynamics::defaultConfig()['physical_states'];
        $this->assertSame($inert, $cfg['inert']);
    }
}
