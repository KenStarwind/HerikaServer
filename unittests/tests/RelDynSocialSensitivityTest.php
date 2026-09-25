<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynSensitivityConfigDb
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
 * Social sensitivity (roadmap social-sensitivity; dimension design draft "Social Sensitivity";
 * traits design A22): how much a delta matters depends on who it comes from.
 *   - bond level = the NPC's core affinity toward the player, 0..100 (the draft's worked numbers:
 *     stranger 5, acquaintance 30, friend 60, bonded 85 are core affinity tiers); a hostile bond
 *     is 0, never the 0..100 mirror (where a stranger would read 50);
 *   - the curves: Inner Circle b^2/10000, Open Heart sqrt(b)/10, Uniform, Inverse Tolerance
 *     (negatives only, max(0.1, 1 - b^2/10000)); presets through the trait engine, the per-NPC
 *     override wins;
 *   - live on the eval path for the signals config lists (trust, comfort, respect): the same
 *     insult lands on an Anxious NPC and bounces off a Stoic one from a near stranger.
 *     Affinity (the bond itself) and passion (the attraction spark and uphill own "who") are
 *     not scaled; global dimensions never are.
 */
final class RelDynSocialSensitivityTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'RELDYN_PLAYER_NAME', 'PLAYER_NAME'] as $k) {
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

    private function useConfig(array $config): void
    {
        $GLOBALS['db'] = new RelDynSensitivityConfigDb($config);
        RelationshipDynamics::clearConfigCache();
    }

    /** $temperament preset at core affinity $coreAff; trust / comfort resting at their baselines. */
    private function npc(string $temperament, float $coreAff): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        RelationshipDynamics::setCoreRelationshipType($d, 'neutral');
        foreach (['trust', 'comfort'] as $dim) {
            $d['dimensions'][$dim]['x'] = $d['dimensions'][$dim]['baseline'];
        }
        $d['dimensions']['self_confidence']['x'] = 50.0;
        return $d;
    }

    public function testTheBondLevelIsCoreAffinityNotTheMirror(): void
    {
        // the draft's stranger (aff 5) and bonded (aff 85) numbers, exactly
        $this->assertEqualsWithDelta(0.0025, RelationshipDynamics::socialSensitivityFactor($this->npc('Stoic', 5.0), 'comfort', true), 1e-9);
        $this->assertEqualsWithDelta(0.7225, RelationshipDynamics::socialSensitivityFactor($this->npc('Stoic', 85.0), 'comfort', true), 1e-9);
        $this->assertEqualsWithDelta(sqrt(5.0) / 10, RelationshipDynamics::socialSensitivityFactor($this->npc('Anxious', 5.0), 'comfort', true), 1e-9);
        $this->assertEqualsWithDelta(sqrt(85.0) / 10, RelationshipDynamics::socialSensitivityFactor($this->npc('Anxious', 85.0), 'comfort', false), 1e-9);
        // a hostile bond is bond level 0: an enemy's words do not reach the inner circle...
        $this->assertSame(0.0, RelationshipDynamics::socialSensitivityFactor($this->npc('Stoic', -60.0), 'trust', true));
        // ... and hit the Defiant at full force ("I don't know you, what did you say?")
        $this->assertEqualsWithDelta(1.0, RelationshipDynamics::socialSensitivityFactor($this->npc('Defiant', -60.0), 'trust', true), 1e-9);
        $this->assertEqualsWithDelta(1.0 - 0.7225, RelationshipDynamics::socialSensitivityFactor($this->npc('Defiant', 85.0), 'trust', true), 1e-9);
        $this->assertSame(1.0, RelationshipDynamics::socialSensitivityFactor($this->npc('Defiant', 85.0), 'trust', false), 'positives pass whole');
        // applySocialSensitivity reads the same bond level
        $this->assertEqualsWithDelta(-10.0 * 0.0025, RelationshipDynamics::applySocialSensitivity($this->npc('Stoic', 5.0), 'comfort', -10.0, 'Stoic'), 1e-9);
        // global dimensions are the NPC's own: never scaled
        $this->assertSame(1.0, RelationshipDynamics::socialSensitivityFactor($this->npc('Stoic', 5.0), 'maturity', true));
        // the per-NPC override wins over the preset curve
        $o = $this->npc('Stoic', 5.0);
        $o['social_sensitivity_curve'] = 'uniform_low';
        $this->assertSame(0.5, RelationshipDynamics::socialSensitivityFactor($o, 'comfort', true));
    }

    /** The trust / comfort change one eval item makes, $on = social sensitivity switched on. */
    private function evalMove(string $temperament, float $coreAff, string $signal, float $raw, bool $on, array $cfg = []): float
    {
        $this->useConfig(array_merge(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'social_sensitivity_enabled' => $on], $cfg));
        $d = $this->npc($temperament, $coreAff);
        $r = RelationshipDynamics::applyEvalSignal('Tester', $d, $signal, $raw, ['insult'], 1.0);
        return $r['actual'];
    }

    public function testTheSameInsultDivergesByWhoTheNpcIs(): void
    {
        // an acquaintance (core 20) insults both: the Anxious NPC's comfort takes most of it, the
        // Stoic's barely moves; with the curves off the same item moves both the old way
        $stoic = $this->evalMove('Stoic', 20.0, 'comfort', -8.0, true) / $this->evalMove('Stoic', 20.0, 'comfort', -8.0, false);
        $anxious = $this->evalMove('Anxious', 20.0, 'comfort', -8.0, true) / $this->evalMove('Anxious', 20.0, 'comfort', -8.0, false);
        $this->assertEqualsWithDelta(0.04, $stoic, 1e-6, 'inner circle: 20^2/10000');
        $this->assertEqualsWithDelta(sqrt(20.0) / 10, $anxious, 1e-4, 'open heart: sqrt(20)/10 (applyDelta rounds to 4 places)');
        $this->assertGreaterThan(10.0 * $stoic, $anxious);
        // the partner's words (core 85) cut deep for both
        $this->assertEqualsWithDelta(0.7225, $this->evalMove('Stoic', 85.0, 'trust', -8.0, true) / $this->evalMove('Stoic', 85.0, 'trust', -8.0, false), 1e-6);
        // gains are scaled too (symmetric curves): a stranger's kindness does not build a Stoic's trust
        $this->assertEqualsWithDelta(0.0025, $this->evalMove('Stoic', 5.0, 'trust', 6.0, true) / $this->evalMove('Stoic', 5.0, 'trust', 6.0, false), 1e-6);
    }

    public function testAnItemIsHeardAtTheBondItWasSpokenIn(): void
    {
        // One contract item: a savage insult (affinity -30) and a trust hit. The trust signal is
        // weighed at the bond before the item (core 85, a partner), not at what the same item's
        // affinity loss (applied first) leaves behind.
        $this->useConfig(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        $d = $this->npc('Stoic', 85.0);
        $d['dimensions']['maturity'] = ['x' => 50.0, 'baseline' => 50.0];
        $item = ['v' => 1, 'npc' => 'Tester', 'npc_id' => 7, 'gamets' => 123456, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => -30, 'trust' => -8, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['insult'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 1.0, 'positive_interaction' => false, 'summary' => 'insulted her'];
        $alone = $d;
        $want = RelationshipDynamics::applyEvalSignal('Tester', $alone, 'trust', -8.0, ['insult'], 1.0, 85.0)['actual'];
        $totals = RelationshipDynamics::processEvalContractItem('Tester', $item, $d);
        $this->assertLessThan(0.0, $totals['affinity']);
        $this->assertLessThan(85.0, RelationshipDynamics::getCoreAffinity($d), 'the insult moved the bond first');
        $this->assertEqualsWithDelta($want, $totals['trust'], 1e-9, 'trust weighed at core 85');
        $this->assertLessThan(-1.0, $totals['trust'], "a partner's words cut");
    }

    public function testAffinityPassionAndUnlistedSignalsAreNotScaled(): void
    {
        foreach (['affinity' => -8.0, 'maturity' => 4.0] as $signal => $raw) {
            $on = $this->evalMove('Stoic', 20.0, $signal, $raw, true);
            $off = $this->evalMove('Stoic', 20.0, $signal, $raw, false);
            $this->assertNotEqualsWithDelta(0.0, $off, 1e-9, $signal);
            $this->assertEqualsWithDelta($off, $on, 1e-9, "{$signal}: not a sensitivity signal");
        }
        // the signal list is config: comfort taken off, trust kept
        $cfg = ['social_sensitivity_signals' => ['trust']];
        $this->assertEqualsWithDelta($this->evalMove('Stoic', 20.0, 'comfort', -8.0, false, $cfg),
            $this->evalMove('Stoic', 20.0, 'comfort', -8.0, true, $cfg), 1e-9);
        $this->assertEqualsWithDelta(0.04, $this->evalMove('Stoic', 20.0, 'trust', -8.0, true, $cfg)
            / $this->evalMove('Stoic', 20.0, 'trust', -8.0, false, $cfg), 1e-6);
        $this->assertContains('respect', RelationshipDynamics::defaultConfig()['social_sensitivity_signals']);
        $this->assertNotContains('affinity', RelationshipDynamics::defaultConfig()['social_sensitivity_signals']);
        $this->assertNotContains('passion', RelationshipDynamics::defaultConfig()['social_sensitivity_signals']);
    }
}
