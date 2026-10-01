<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * The bio re-ingest (decisions 2026-10-01 §21 #10), pure parts: the config, the live key and the
 * skip list, the inertia (who moves how far), the blend arithmetic and its history, the drift in
 * RelDynTraitAssign::resolve(), the milestone detector and the exemptions. No database, no model.
 * Units: traits 0..1, maturity_start 0..100, k (the share of the way a new read moves an NPC) 0..1.
 */
final class RelDynBioReingestTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitReingest::reset();
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    /** A validated-read shaped result: [value, conf] per trait (default none), maturity [value, conf]. */
    private static function read(array $traits, array $maturity = [50.0, 0.0]): array
    {
        $out = ['v' => 1, 'traits' => []];
        foreach (RelDynTraitRead::TRAIT_KEYS as $k) {
            [$v, $c] = $traits[$k] ?? [0.5, 0.0];
            $out['traits'][$k] = ['value' => $v, 'conf' => $c, 'field' => $c > 0 ? 'personality' : null, 'evidence' => $c > 0 ? 'some words' : null];
        }
        $out['maturity_start'] = ['value' => $maturity[0], 'conf' => $maturity[1], 'field' => null, 'evidence' => null];
        return $out;
    }

    /** A dynamics blob with a plasticity type and a maturity, as the editor / eval leave one. */
    private static function npc(string $type, float $maturity): array
    {
        return ['dimensions' => ['maturity' => ['x' => $maturity, 'baseline' => $maturity, 'plasticity_type' => $type]]];
    }

    // ------------------------------------------------------------------ config, keys

    public function testConfigDefaultsAreOnWithAnNinetyDayCadenceAndAPartialRowKeepsTheRest(): void
    {
        $d = RelDynTraitReingest::defaultConfig();
        $this->assertTrue($d['enabled']);
        $this->assertSame(90, $d['every_game_days']);
        $this->assertSame(['romance', 'bond_break', 'betrayal', 'marriage'], $d['milestones'], 'the milestones Ken named');
        $this->assertGreaterThan(0.0, $d['floor'], 'nobody is immune');
        $this->assertLessThan(1.0, $d['ceiling']);
        $this->assertSame($d, RelDynTraitRead::defaultConfig()['reingest'], 'rides on the trait reader config (one switch page)');
        $this->assertSame($d, RelDynTraitRead::config()['reingest'], 'no stored config: the defaults');
    }

    public function testLiveKeysNeverCollideWithTemplateKeysAndTheSkipListStillCatchesThem(): void
    {
        $this->assertTrue(RelDynTraitReingest::isLiveKey('live:aela the huntress'));
        $this->assertFalse(RelDynTraitReingest::isLiveKey('aela_the_huntress'));
        $this->assertSame('live:aela the huntress', RelDynTraitReingest::liveKey(' Aela The Huntress '));
        $this->assertSame('aela the huntress', RelDynTraitReingest::baseKey('live:aela the huntress'));
        $this->assertTrue(RelDynTraitRead::isSkipped('live:ashe'), "Ashe's live bio is never read either");
        $this->assertFalse(RelDynTraitRead::isSkipped('live:aela the huntress'));
        // the read's own skip also holds when the key is a template key
        $this->assertTrue(RelDynTraitRead::isSkipped('ashe'));
    }

    // ------------------------------------------------------------------ inertia

    public function testSetInTheirWaysNpcsMoveLessAndNobodyIsImmune(): void
    {
        $k = fn(string $type, float $m) => RelDynTraitReingest::inertia(self::npc($type, $m))['k'];
        $this->assertLessThan($k('Resilient', 50), $k('Rigid', 50), 'Rigid barely moves');
        $this->assertLessThan($k('Adaptive', 50), $k('Resilient', 50));
        $this->assertLessThan($k('Volatile', 50), $k('Adaptive', 50), 'Volatile NPCs swing');
        $this->assertLessThan($k('Adaptive', 20), $k('Adaptive', 90), 'the more mature, the more settled');
        $rigidest = $k('Rigid', 100);
        $this->assertGreaterThan(0.0, $rigidest, 'no one is ever fully immune to a changed bio');
        $this->assertGreaterThanOrEqual(RelDynTraitReingest::defaultConfig()['floor'], $rigidest);
        $this->assertLessThanOrEqual(RelDynTraitReingest::defaultConfig()['ceiling'], $k('Volatile', 0));
        $in = RelDynTraitReingest::inertia(self::npc('Rigid', 70));
        $this->assertEqualsWithDelta(0.3, $in['plasticity'], 1e-9);
        $this->assertEqualsWithDelta(70.0, $in['maturity'], 1e-9);
    }

    public function testInertiaFollowsTheConfig(): void
    {
        $cfg = ['floor' => 0.2, 'ceiling' => 0.6] + RelDynTraitReingest::defaultConfig();
        $this->assertEqualsWithDelta(0.6, RelDynTraitReingest::inertia(self::npc('Volatile', 10), $cfg)['k'], 1e-9, 'ceiling');
        $this->assertEqualsWithDelta(0.2, RelDynTraitReingest::inertia(self::npc('Rigid', 90), $cfg)['k'], 1e-9, 'floor');
    }

    // ------------------------------------------------------------------ advance

    private function cur(array $overrides = []): array
    {
        $x = array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.5) + ['maturity_start' => 50.0];
        return array_replace($x, $overrides);
    }

    private function advance(array $s, array $cur, array $read, ?array $first, float $k, float $now = 1000.0, string $hash = 'abcdef0123456789'): array
    {
        $prior = array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.5);
        return RelDynTraitReingest::advance($s, $cur, $prior, $read, $first, $k, $now, $hash, 'cadence', 3);
    }

    public function testANewReadMovesTheVectorTheShareKOfTheWayAndOnlyWhereItHasEvidence(): void
    {
        $s = RelDynTraitReingest::state([]);
        // the vector rests on a first read: warmth 0.8 (conf 0.9 -> weight 0.72 -> 0.5 + 0.72 * 0.3 = 0.716)
        $first = self::read(['warmth' => [0.8, 0.9]]);
        $cur = $this->cur(['W' => 0.716]);
        // the new read: warmth 0.2 (conf 0.9), guard 0.8 (conf 0.9); expressiveness: no evidence
        $new = self::read(['warmth' => [0.2, 0.9], 'guard' => [0.8, 0.9]]);
        $out = $this->advance($s, $cur, $new, $first, 0.5);
        $newW = 0.5 + 0.72 * (0.2 - 0.5);   // what the new read alone would give: 0.284
        // warmth was known (weight 0.72): k 0.5 + 0.5 * (1 - 0.72) = 0.64 of the way from 0.716 to 0.284
        $this->assertEqualsWithDelta($this->kEff(0.5, 0.72) * ($newW - 0.716), $out['drift']['warmth'], 1e-4);
        // guard was unknown (weight 0): the whole way from 0.5 to what the new read alone gives
        $this->assertEqualsWithDelta(0.72 * 0.3, $out['drift']['guard'], 1e-4);
        $this->assertLessThan(0, $out['drift']['warmth'], 'toward the new read');
        $this->assertArrayNotHasKey('expressiveness', $out['drift'], 'no evidence in the new read is not a vote for the prior');
        $this->assertArrayNotHasKey('maturity_start', $out['drift']);
        $this->assertSame('cadence', $out['history'][0]['reason']);
        $this->assertSame(0.5, $out['history'][0]['k']);
        $this->assertNotSame([], $out['last_moved']);
    }

    /** k + (1 - k)(1 - known): inertia belongs to what is established, not to what was unknown. */
    private function kEff(float $k, float $known): float
    {
        return $k + (1.0 - $k) * (1.0 - $known);
    }

    public function testATraitTheVectorKnewNothingAboutMovesMoreThanOneItKnew(): void
    {
        $first = self::read(['warmth' => [0.8, 0.9]]);   // warmth known (weight 0.72); guard unknown
        $cur = $this->cur(['W' => 0.716]);
        $new = self::read(['warmth' => [0.2, 0.9], 'guard' => [0.2, 0.9]]);
        $out = $this->advance(RelDynTraitReingest::state([]), $cur, $new, $first, 0.2);
        // both would go 0.5 + 0.72 * -0.3 = 0.284 under the new read; guard started at 0.5, warmth at 0.716
        $gStep = abs($out['drift']['guard']) / abs(0.284 - 0.5);
        $wStep = abs($out['drift']['warmth']) / abs(0.284 - 0.716);
        $this->assertEqualsWithDelta($this->kEff(0.2, 0.0), $gStep, 1e-3, 'unknown: the whole way');
        $this->assertEqualsWithDelta($this->kEff(0.2, 0.72), $wStep, 1e-3, 'known: inertia applies');
        $this->assertGreaterThan($wStep, $gStep);
    }

    public function testDriftAccumulatesAcrossReadsStaysInRangeAndTheHistoryIsShort(): void
    {
        $s = RelDynTraitReingest::state([]);
        $first = self::read(['warmth' => [0.2, 0.9]]);
        $cur = $this->cur(['W' => 0.284]);
        $new = self::read(['warmth' => [1.0, 1.0]], [95.0, 1.0]);   // the new read alone gives warmth 0.9
        $last = 0.0;
        for ($i = 1; $i <= 5; $i++) {
            $s = $this->advance($s, $cur, $new, $first, 0.8, 1000.0 * $i, str_repeat((string) $i, 16));
            $this->assertGreaterThan($last, $s['drift']['warmth'], "step {$i}: still moving toward the new read");
            $last = $s['drift']['warmth'];
            $cur['W'] = 0.284 + $last;
            $cur['maturity_start'] = min(100.0, 50.0 + ($s['drift']['maturity_start'] ?? 0));
        }
        $this->assertLessThanOrEqual(0.9 + 1e-9, 0.284 + $s['drift']['warmth'], 'never past what the new read gives');
        $this->assertLessThanOrEqual(100.0, 50.0 + $s['drift']['maturity_start']);
        $this->assertCount(3, $s['history'], 'a short history: the newest three');
        $this->assertSame(5, $s['reads']);
        $this->assertSame(str_repeat('5', 12), $s['current']['hash']);
        // the read each step replaced: the first step's was the first read, the next ones' the one before
        $this->assertSame('3333', substr($s['history'][0]['hash'], 0, 4), 'newest three: 3, 4, 5');
        $this->assertSame([1.0, 1.0], $s['history'][1]['superseded']['read']['warmth'], 'the read each step replaced is kept');
        $this->assertSame(str_repeat('4', 12), $s['history'][2]['superseded']['hash'], 'the step replaced the read before it');
    }

    public function testTheFirstStepKeepsTheFirstReadInTheHistory(): void
    {
        $first = self::read(['warmth' => [0.8, 0.9]], [60.0, 0.6]);
        $out = $this->advance(RelDynTraitReingest::state(['_bio_reingest' => ['anchor' => 'tplhash', 'last_read' => 10.0]]),
            $this->cur(['W' => 0.716]), self::read(['warmth' => [0.3, 0.9]]), $first, 0.5);
        $old = $out['history'][0]['superseded'];
        $this->assertSame('tplhash', $old['hash']);
        $this->assertSame(10.0, $old['at']);
        $this->assertSame([0.8, 0.9], $old['read']['warmth']);
        $this->assertSame([60.0, 0.6], $old['read']['maturity_start']);
        $this->assertSame(substr('abcdef0123456789', 0, 12), $out['current']['hash']);
        $this->assertSame('abcdef0123456789', $out['base_hash'], 'the next check compares against the bio just read');
    }

    // ------------------------------------------------------------------ drift in resolve()

    public function testResolveAppliesTheDriftOverTheReadAndPriorsAndShowsIt(): void
    {
        $read = self::read(['warmth' => [0.8, 0.9]]);
        $plain = RelDynTraitAssign::resolve(['read' => $read]);
        $drifted = RelDynTraitAssign::resolve(['read' => $read, 'drift' => ['x' => ['warmth' => -0.2, 'maturity_start' => 5.0], 'known' => []]]);
        $this->assertEqualsWithDelta($plain['x']['W'] - 0.2, $drifted['x']['W'], 1e-9);
        $this->assertEqualsWithDelta($plain['x']['maturity_start'] + 5.0, $drifted['x']['maturity_start'], 1e-9);
        $this->assertSame(-0.2, $drifted['src']['warmth']['drift']);
        $this->assertArrayNotHasKey('drift', $plain['src']['warmth']);
        $this->assertEqualsWithDelta($plain['x']['G'], $drifted['x']['G'], 1e-12, 'untouched traits stay');
        $clamped = RelDynTraitAssign::resolve(['read' => $read, 'drift' => ['x' => ['warmth' => 0.9], 'known' => []]]);
        $this->assertSame(1.0, $clamped['x']['W']);
    }

    public function testNoDriftReachesAPresetAHandSetVectorOrAScreenedNpc(): void
    {
        $drift = ['x' => ['warmth' => -0.3], 'known' => []];
        $preset = RelDynTraitAssign::resolve(['preset' => 'Bold', 'preset_source' => 'preset', 'drift' => $drift]);
        $this->assertEqualsWithDelta(RelDynTraits::points()['Bold']['W'], $preset['x']['W'], 1e-12, 'the preset point is the vector');
        $hand = RelDynTraitAssign::resolve(['hand_set' => ['warmth' => 0.2, 'guard' => 0.8], 'drift' => $drift]);
        $base = RelDynTraitAssign::resolve(['hand_set' => ['warmth' => 0.2, 'guard' => 0.8]]);
        $this->assertEquals($base['x'], $hand['x'], 'a hand-set vector is exactly as set');
        $screened = RelDynTraitAssign::resolve(['screened' => true, 'drift' => $drift]);
        $this->assertEquals(RelDynTraitAssign::resolve(['screened' => true])['x'], $screened['x']);
        // an editor per-trait override still wins over the drifted auto value
        $over = RelDynTraitAssign::resolve(['read' => self::read(['warmth' => [0.8, 0.9]]), 'drift' => $drift, 'trait_override' => ['warmth' => 0.95]]);
        $this->assertSame(0.95, $over['x']['W']);
    }

    // ------------------------------------------------------------------ milestones

    public function testMilestonesAreRecognisedFromTheStateTheFirstLookOnlySetsBaselines(): void
    {
        $cfg = RelDynTraitReingest::defaultConfig();
        $seen = [];
        $d = ['_core_rel_type' => 'platonic', '_romance' => ['last_promotion' => ['gamets' => 50.0]], '_bond_break' => ['count' => 1]];
        $this->assertNull(RelDynTraitReingest::detectMilestone($d, $seen, $cfg), 'the first look sets the baselines, not a milestone');
        $this->assertNull(RelDynTraitReingest::detectMilestone($d, $seen, $cfg), 'nothing new');

        $d2 = $d;
        $d2['_romance']['last_promotion'] = ['from' => 'platonic', 'to' => 'romantic', 'gamets' => 90.0];
        $d2['_core_rel_type'] = 'romantic';
        $s = $seen;
        $this->assertSame('romance', RelDynTraitReingest::detectMilestone($d2, $s, $cfg), 'a romance promotion (also the core type turning romantic, one milestone)');

        $d3 = $d;
        $d3['_core_rel_type'] = 'romantic';   // married through core's own relationship manager, not RelDyn's promotion
        $s = $seen;
        $this->assertSame('marriage', RelDynTraitReingest::detectMilestone($d3, $s, $cfg));

        $d4 = $d;
        $d4['_bond_break']['count'] = 2;
        $s = $seen;
        $this->assertSame('bond_break', RelDynTraitReingest::detectMilestone($d4, $s, $cfg));

        $d5 = $d;
        $d5['_core_rel_type'] = 'betrayed';
        $s = $seen;
        $this->assertSame('betrayal', RelDynTraitReingest::detectMilestone($d5, $s, $cfg));

        $off = ['milestones' => ['bond_break']] + $cfg;
        $s = $seen;
        $this->assertNull(RelDynTraitReingest::detectMilestone($d2, $s, $off), 'a kind switched off in config is not a milestone');
        $this->assertSame(90.0, $s['romance_at'], 'but the baseline still moves on');
    }

    public function testTheBetrayalHookOnlyMarksTheStateAndKnowsNoOtherKind(): void
    {
        $d = [];
        RelDynTraitReingest::noteMilestone($d, 'betrayal', 1234.0);
        $this->assertSame(['kind' => 'betrayal', 'gamets' => 1234.0], $d['_bio_reingest']['milestone']);
        $e = [];
        RelDynTraitReingest::noteMilestone($e, 'sunrise', 1.0);
        $this->assertSame([], $e);
    }

    // ------------------------------------------------------------------ exemptions

    private static function readDynamics(array $srcOver = [], array $over = []): array
    {
        return ['_trait_vector_src' => $srcOver + ['assignment' => 'read', 'auto_source' => 'read', 'composed' => 'auto', 'read_status' => 'done',
            'template_key' => 'bryn']] + ($over ? ['profile_overrides' => $over] : []);
    }

    public function testExemptionsHandSetPresetsAndTheSkipList(): void
    {
        $this->assertNull(RelDynTraitReingest::exemption('Bryn', self::readDynamics()), 'an ordinary read NPC may be re-read');
        $this->assertStringContainsString('skip list', (string) RelDynTraitReingest::exemption('Ashe', self::readDynamics()), 'Ashe, by name');
        $this->assertStringContainsString('skip list', (string) RelDynTraitReingest::exemption('Someone', self::readDynamics(['template_key' => 'ashe'])), 'or by template');
        $this->assertSame('a hand-set vector', RelDynTraitReingest::exemption('Bryn', self::readDynamics(['auto_source' => 'hand-set'])));
        $this->assertSame('a preset sets the vector', RelDynTraitReingest::exemption('Bryn', self::readDynamics(['auto_source' => 'preset'])));
        $this->assertSame('a preset sets the vector', RelDynTraitReingest::exemption('Bryn', self::readDynamics(['auto_source' => 'sharmat'])));
        $this->assertSame('a preset is set in the editor', RelDynTraitReingest::exemption('Bryn', self::readDynamics([], ['temperament' => 'Bold'])));
        $this->assertSame('a preset is set in the editor', RelDynTraitReingest::exemption('Bryn', self::readDynamics(['composed' => 'stored'])));
        $this->assertSame('no read vector yet', RelDynTraitReingest::exemption('Bryn', []));
    }

    public function testEditorTraitOverridesExemptOnlyWhenTheyCoverEveryTrait(): void
    {
        $all = array_fill_keys(RelDynTraits::TRAITS, 0.4);
        $this->assertSame('every trait is set in the editor', RelDynTraitReingest::exemption('Bryn', self::readDynamics([], ['trait_vector' => $all])));
        $part = ['warmth' => 0.9];
        $this->assertNull(RelDynTraitReingest::exemption('Bryn', self::readDynamics([], ['trait_vector' => $part])), 'the other traits can still move; the override wins per trait');
    }

    public function testTheLabelAssignmentHasNoVectorToMove(): void
    {
        RelDynTraits::$assignmentOverride = 'label';
        $this->assertSame('the read assignment is off', RelDynTraitReingest::exemption('Bryn', self::readDynamics()));
    }

    // ------------------------------------------------------------------ anchoring to the shipped template

    public function testAChangedShippedTemplateStartsTheNpcOverFromTheNewRead(): void
    {
        $s = RelDynTraitReingest::state(['_bio_reingest' => ['anchor' => 'old', 'drift' => ['warmth' => 0.1], 'history' => [['x' => 1]], 'base_hash' => 'live1', 'reads' => 2]]);
        $same = RelDynTraitReingest::anchored($s, 'old');
        $this->assertSame(['warmth' => 0.1], $same['drift'], 'the template did not change: the drift stays');
        $over = RelDynTraitReingest::anchored($s, 'new');
        $this->assertSame([], $over['drift']);
        $this->assertSame([], $over['history']);
        $this->assertNull($over['base_hash']);
        $this->assertSame('new', $over['anchor']);
        $this->assertSame($s, RelDynTraitReingest::anchored($s, null), 'no template hash (read pending): left alone');
    }
}
