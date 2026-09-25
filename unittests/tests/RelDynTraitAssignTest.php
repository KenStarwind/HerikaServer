<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Personality traits phase 2, assignment (design §4.1, §4.3, §4.4; decisions §16): the priors,
 * the combination rule, the precedence, the composed vector (editor preset, stored label,
 * per-trait override), and the engine switch (the NPC's own vector under the read assignment,
 * the preset point under the label assignment). Pure: no database.
 * Units: traits 0..1; offsets in trait units; maturity_start 0..100.
 */
final class RelDynTraitAssignTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = null;
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    private static function read(array $traits, ?array $maturity = null): array
    {
        $out = ['v' => 1, 'traits' => []];
        foreach (RelDynTraitRead::TRAIT_KEYS as $k) {
            [$v, $c] = $traits[$k] ?? [0.5, 0.0];
            $out['traits'][$k] = ['value' => $v, 'conf' => $c, 'field' => $c > 0 ? 'personality' : null, 'evidence' => $c > 0 ? 'some words' : null];
        }
        [$mv, $mc] = $maturity ?? [50.0, 0.0];
        $out['maturity_start'] = ['value' => $mv, 'conf' => $mc, 'field' => null, 'evidence' => null];
        return $out;
    }

    // ------------------------------------------------------------------ priors (§4.4)

    public function testVoiceFamiliesAliasesAndVoicesWithoutAPrior(): void
    {
        $this->assertSame('Commander', RelDynTraitAssign::voiceFamily('sk_femalecommander'));
        $this->assertSame('Commander', RelDynTraitAssign::voiceFamily(' SK_MaleCommander '));
        $this->assertSame('EvenToned', RelDynTraitAssign::voiceFamily('sk_maleeventonedaccented'));
        $this->assertSame('Nord', RelDynTraitAssign::voiceFamily('sk_malenordcommander'));
        $this->assertSame('DarkElfCynical', RelDynTraitAssign::voiceFamily('sk_maledarkelfcynical'), 'before plain DarkElf');
        $this->assertSame('DarkElf', RelDynTraitAssign::voiceFamily('sk_maledunmer'));
        $this->assertSame('YoungEager', RelDynTraitAssign::voiceFamily('sk_maleyoungeage'), 'typo aliased');
        $this->assertSame('Soldier', RelDynTraitAssign::voiceFamily('sk_maleguard'));
        foreach (['sk_serana', 'sk_malekhajiit', 'sk_malehajiit', 'sk_femalearognian', 'sk_dragon', 'sk_femalevampire', '', null] as $none) {
            $this->assertNull(RelDynTraitAssign::voiceFamily($none), var_export($none, true) . ': unique, race-only or creature voice');
        }
        $p = RelDynTraitAssign::prior(['voice' => 'sk_femalecommander']);
        $this->assertEqualsWithDelta(0.65, $p['x']['C'], 1e-9);
        $this->assertEqualsWithDelta(0.60, $p['x']['D'], 1e-9);
        $this->assertSame(['voice:Commander'], $p['signals']);
    }

    public function testBasesAndPossessivenessFromConfidence(): void
    {
        $p = RelDynTraitAssign::prior([]);
        foreach (RelDynTraits::TRAITS as $code => $_) {
            if ($code !== 'Po') $this->assertSame(0.5, $p['x'][$code], $code);
        }
        $this->assertEqualsWithDelta(0.325, $p['x']['Po'], 1e-9, 'Po_base = 0.15 + 0.35 (1 - 0.5)');
        $bold = RelDynTraitAssign::prior(['voice' => 'sk_malecommander']);   // C 0.65
        $this->assertEqualsWithDelta(0.15 + 0.35 * 0.35, $bold['x']['Po'], 1e-9, 'a confident prior is less possessive');
    }

    public function testClassFactionSkillOffsetsAndTheCap(): void
    {
        $p = RelDynTraitAssign::prior(['voice' => 'sk_malecommander', 'class' => 'Guard',
            'factions' => ['HousecarlWhiterunFaction', 'WhiterunGuardFaction', 'DawnguardFaction'], 'skills' => ['block' => 60, 'onehanded' => 40]]);
        // D: commander +.10, class Guard +.10, housecarl +.10, guard faction +.05 = +.35 -> capped +.20
        $this->assertEqualsWithDelta(0.70, $p['x']['D'], 1e-9, 'the non-bio stack is capped at +-0.20');
        // Pr: commander +.10, Guard +.05, housecarl +.10, block +.05 = +.30 -> +.20
        $this->assertEqualsWithDelta(0.70, $p['x']['Pr'], 1e-9);
        $this->assertContains('faction:Housecarl', $p['signals']);
        $this->assertContains('faction:Guard', $p['signals']);
        $this->assertContains('skill:block', $p['signals']);
        $this->assertSame(['Housecarl', 'Guard'], RelDynTraitAssign::factionGroups(['HousecarlWhiterunFaction', 'WhiterunGuardFaction', 'DawnguardFaction']),
            'Dawnguard is not a guard faction');
        $this->assertNull(RelDynTraitAssign::topSkill(['block' => 40, 'archery' => 40]), 'a tie names none');
        $this->assertNull(RelDynTraitAssign::topSkill(['block' => 20]), 'below skills_min_level');
        $this->assertSame('sneak', RelDynTraitAssign::topSkill(['Sneak' => '56', 'archery' => '50']));
    }

    /** Ruling #7: race is minor (a 0.10 pull toward the retired race vote's preset), never zero, never large. */
    public function testRaceIsAMinorPrior(): void
    {
        $nord = RelDynTraitAssign::prior(['race' => 'NordRace']);
        $this->assertContains('race:Bold', $nord['signals']);
        $moved = false;
        foreach (RelDynTraits::TRAITS as $code => $_) {
            $d = abs($nord['sum'][$code]);
            $this->assertLessThanOrEqual(0.05 + 1e-9, $d, "{$code}: at most 0.05");
            if ($d > 1e-9) $moved = true;
        }
        $this->assertTrue($moved, 'minor, not zero');
        $this->assertEqualsWithDelta(0.5 + 0.1 * (0.65 - 0.5), $nord['x']['C'], 1e-9);
        $this->assertNull(RelDynTraitAssign::racePreset('SkeeverRace'));
        $this->assertSame('Guarded', RelDynTraitAssign::racePreset('DarkElfRaceVampire'));
    }

    // ------------------------------------------------------------------ combination (§4.3)

    public function testReadBlendsOverThePriorByConfidence(): void
    {
        $prior = RelDynTraitAssign::prior([])['x'];
        $r = RelDynTraitAssign::combine($prior, self::read(['guard' => [0.9, 1.0], 'warmth' => [0.1, 0.5], 'pride' => [0.9, 0.0]], [80.0, 1.0]));
        $this->assertEqualsWithDelta(0.5 + 0.8 * 0.4, $r['x']['G'], 1e-9, 'conf 1 moves 80% of the way');
        $this->assertEqualsWithDelta(0.5 + 0.4 * -0.4, $r['x']['W'], 1e-9, 'conf 0.5 moves 40%');
        $this->assertSame(0.5, $r['x']['Pd'], 'no evidence leaves the prior');
        $this->assertSame('bio', $r['src']['guard']['source']);
        $this->assertSame('prior', $r['src']['pride']['source']);
        $model = RelDynTraitAssign::maturityModel($r['x']);
        $this->assertEqualsWithDelta($model + 0.8 * (80 - $model), $r['x']['maturity_start'], 1e-9, 'maturity blends over the A9 model');
        $none = RelDynTraitAssign::combine($prior, null);
        $this->assertEqualsWithDelta(17 + 26 * 0.5 + 30 * 0.5, $none['x']['maturity_start'], 1e-9);
        $this->assertNull(RelDynTraits::presetAt($r['x']), 'a blended vector is never exactly a preset (§4.3)');
    }

    // ------------------------------------------------------------------ precedence (§4.1)

    public function testPrecedenceOverridePresetReadPrior(): void
    {
        $read = self::read(['guard' => [0.9, 1.0]]);
        $auto = RelDynTraitAssign::resolve(['prior_in' => ['voice' => 'sk_femalecommander'], 'read' => $read]);
        $this->assertSame('read', $auto['label_source']);
        $this->assertSame($auto['nearest']['name'], $auto['label']);

        $preset = RelDynTraitAssign::resolve(['prior_in' => [], 'read' => $read, 'preset' => 'Proud', 'preset_source' => 'sharmat']);
        $this->assertSame('Proud', $preset['label']);
        $this->assertSame('sharmat', $preset['label_source']);
        $this->assertSame(0.0, RelDynTraits::distance($preset['x'], RelDynTraits::points()['Proud']), 'a preset beats the read');

        $over = RelDynTraitAssign::resolve(['prior_in' => [], 'read' => $read, 'preset' => 'Proud', 'trait_override' => ['guard' => 0.1]]);
        $this->assertSame(0.1, $over['x']['G'], 'the per-trait override sits on top');
        $this->assertSame('override', $over['src']['guard']['source']);
        $this->assertSame(RelDynTraits::points()['Proud']['C'], $over['x']['C']);

        $prior = RelDynTraitAssign::resolve(['prior_in' => []]);
        $this->assertSame('prior', $prior['label_source']);
        $this->assertSame('Gentle', $prior['label'], 'design §4.5: the base vector is nearest Gentle (0.451)');
    }

    /** Decisions §16 #4: Ashe is her hand-set, spoiler-free conclusion; maturity 75; momentum 1.6. */
    public function testAsheIsTheHandSetConclusion(): void
    {
        $cfg = RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides'];
        $this->assertArrayHasKey('trait_vector', $cfg['ashe']);
        $this->assertSame(75, $cfg['ashe']['maturity_start']);
        $this->assertArrayNotHasKey('ysolda', $cfg, 'decisions §16 #2');
        $r = RelDynTraitAssign::resolve(['hand_set' => $cfg['ashe']['trait_vector'], 'maturity_start' => $cfg['ashe']['maturity_start'],
            'read' => self::read(['guard' => [0.1, 1.0]]), 'screened' => true]);
        $this->assertSame('hand-set', $r['label_source']);
        $this->assertSame('Stoic', $r['label'], 'Stoic-leaning');
        $this->assertEqualsWithDelta(0.38, $r['nearest']['distance'], 0.01);
        $this->assertSame(0.75, $r['x']['G'], 'the hand-set conclusion beats any read');
        $this->assertSame(75.0, $r['x']['maturity_start']);
        $this->assertEqualsWithDelta(0.75, $r['x']['Rs'], 1e-9);
        [$up, $down] = RelDynTraits::maturityY($r['x']['Rs'], $r['x']['L']);
        $this->assertEqualsWithDelta(1.0, $up, 1e-4, 'Resilient corner');
        $this->assertEqualsWithDelta(0.5, $down, 1e-4);
        $this->assertEqualsWithDelta(1.6, RelDynTraits::tableAt($r['x'], RelDynRomance::configDefaults()['momentum_temperament_mult'], 1.0, 'R',
            fn(array $x) => 1.0 + 4.0 * max(0.0, $x['G'] - 0.6), 'mult'), 1e-9, 'romance momentum 1.6');
        foreach ($r['src'] as $name => $s) $this->assertArrayNotHasKey('evidence', $s, "{$name}: a screened NPC stores no evidence");
    }

    // ------------------------------------------------------------------ the composed vector

    public function testComposeEditorPresetStoredLabelAndClearing(): void
    {
        $auto = RelDynTraitAssign::resolve(['prior_in' => ['voice' => 'sk_femalecommander'], 'read' => self::read(['warmth' => [0.8, 0.9]])]);
        $x = $auto['x'];
        $same = RelDynTraits::composeRead($x, $auto['label'], $auto['label'], null, null);
        $this->assertSame('auto', $same['source']);
        $this->assertSame($x, $same['x']);
        $editor = RelDynTraits::composeRead($x, $auto['label'], 'Proud', 'Proud', null);
        $this->assertSame('override', $editor['source']);
        $this->assertSame(RelDynTraits::points()['Proud'], $editor['x']);
        $picked = RelDynTraits::composeRead($x, $auto['label'], $auto['label'], $auto['label'], null);
        $this->assertSame(RelDynTraits::points()[$auto['label']], $picked['x'], 'an editor pick of the auto label is still that preset');
        $stored = RelDynTraits::composeRead($x, $auto['label'], 'Romantic', null, null);
        $this->assertSame('stored', $stored['source'], 'a label written on the NPC since (Sharmat, an arc) is a preset');
        $per = RelDynTraits::composeRead($x, $auto['label'], $auto['label'], null, ['guard' => 0.2]);
        $this->assertSame(0.2, $per['x']['G']);
        $this->assertSame(['guard'], $per['overridden']);
    }

    public function testSyncStoredRecomposesAndTheEngineReadsTheNpcsOwnVector(): void
    {
        $auto = RelDynTraitAssign::resolve(['prior_in' => ['voice' => 'sk_femalecommander'], 'read' => self::read(['warmth' => [0.8, 0.9], 'guard' => [0.8, 0.9]])]);
        $d = ['inferred_temperament' => $auto['label'], '_trait_vector_src' => ['assignment' => 'read',
            'auto' => RelDynTraits::toStored($auto['x']), 'auto_label' => $auto['label']]];
        $d = RelDynTraits::syncStored($d);
        $this->assertSame('read', $d['_trait_vector_src']['assignment']);
        $own = RelDynTraits::readVector($d);
        $this->assertEqualsWithDelta(0.0, RelDynTraits::distance($own, $auto['x']), 1e-12);
        $this->assertSame($auto['nearest']['name'], $d['trait_preset']['nearest']);

        // the engine: the NPC's own vector, not the label's preset point
        $mine = RelDynTraits::param($auto['label'], 'passion_mult', 1.0, $d);
        $this->assertEqualsWithDelta(RelDynTraits::value($auto['x'], 'passion_mult'), $mine, 1e-12);
        $this->assertNotEquals(RelDynTraits::param($auto['label'], 'passion_mult', 1.0), $mine, 'label-only is the preset point');
        $this->assertSame(RelationshipDynamics::getTemperamentBaseline($auto['label'], 'trust', $d), (float) RelDynTraits::value($auto['x'], 'baseline_trust'));

        // editor preset, then cleared: back to the read vector
        $d['profile_overrides'] = ['temperament' => 'Stoic'];
        $d['inferred_temperament'] = 'Stoic';
        $d = RelDynTraits::syncStored($d);
        $this->assertSame(0.0, RelDynTraits::distance(RelDynTraits::readVector($d), RelDynTraits::points()['Stoic']));
        unset($d['profile_overrides']);
        $d['inferred_temperament'] = $auto['label'];
        $d = RelDynTraits::syncStored($d);
        $this->assertEqualsWithDelta(0.0, RelDynTraits::distance(RelDynTraits::readVector($d), $auto['x']), 1e-12);

        // label assignment (the phase-1 legacy path): the stored vector is not read
        RelDynTraits::$assignmentOverride = 'label';
        $this->assertNull(RelDynTraits::readVector($d));
        $this->assertSame(RelDynTraits::param($auto['label'], 'passion_mult', 1.0), RelDynTraits::param($auto['label'], 'passion_mult', 1.0, $d));
    }

    public function testMaturityPhysicsIsTheExactFormulaWhileTheTypeIsTheTraits(): void
    {
        $auto = RelDynTraitAssign::resolve(['prior_in' => [], 'read' => self::read(['resilience' => [0.9, 1.0], 'reactivity' => [0.3, 1.0]])]);
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['inferred_temperament'] = $auto['label'];
        $d['_trait_vector_src'] = ['assignment' => 'read', 'auto' => RelDynTraits::toStored($auto['x']), 'auto_label' => $auto['label']];
        $d = RelDynTraits::syncStored($d);
        $corner = RelDynTraits::maturityCorner($auto['x']);
        $d['dimensions']['maturity']['plasticity_type'] = $corner;
        $d['_profile_autogen'] = ['maturity_type_origin' => 'traits', 'auto' => ['maturity_type' => $corner]];
        [$up, $down] = RelDynTraits::maturityY($auto['x']['Rs'], $auto['x']['L']);
        $this->assertEqualsWithDelta($up, RelationshipDynamics::effectiveMaturityY($d)['Y_up'], 1e-12);
        $this->assertEqualsWithDelta($down, RelationshipDynamics::effectiveMaturityY($d)['Y_down'], 1e-12);
        $this->assertNotEquals(RelationshipDynamics::MATURITY_PLASTICITY_VALUES[$corner]['Y_down'], $down, 'not the corner');
        // an arc's temporary override, a set type, a class rule: the corner applies
        $arc = array_replace($d, ['_plasticity_override' => 'Volatile', '_plasticity_override_expires_gamets' => 1e12, '_last_gamets' => 1.0]);
        $this->assertSame(RelationshipDynamics::MATURITY_PLASTICITY_VALUES['Volatile'], RelationshipDynamics::effectiveMaturityY($arc));
        $set = $d;
        $set['dimensions']['maturity']['plasticity_type'] = 'Rigid';
        $this->assertSame(RelationshipDynamics::MATURITY_PLASTICITY_VALUES['Rigid'], RelationshipDynamics::effectiveMaturityY($set));
        $class = $d;
        $class['_profile_autogen']['maturity_type_origin'] = 'class';
        $this->assertNull(RelationshipDynamics::continuousMaturityY($class));
        // through the physics (applyDelta): the continuous Y
        $ctx = $d;
        $before = floatval($ctx['dimensions']['maturity']['x']);
        RelationshipDynamics::applyDelta('maturity', $ctx, -5.0, $auto['label']);
        $cornerCtx = $set;
        $cornerCtx['dimensions']['maturity']['plasticity_type'] = $corner;
        $cornerCtx['_profile_autogen']['maturity_type_origin'] = 'preset';
        RelationshipDynamics::applyDelta('maturity', $cornerCtx, -5.0, $auto['label']);
        $this->assertNotEqualsWithDelta(floatval($cornerCtx['dimensions']['maturity']['x']), floatval($ctx['dimensions']['maturity']['x']), 1e-6);
        $this->assertLessThan($before, floatval($ctx['dimensions']['maturity']['x']));
    }

    /** C1 tags from pride and confidence: exact at the presets (the old temperament_traits table). */
    public function testTagsFromTheVectorAreExactAtThePresets(): void
    {
        $table = RelationshipDynamics::temperamentAutogenDefaults()['temperament_traits'];
        foreach (RelDynTraits::points() as $name => $p) {
            $this->assertSame($table[$name] ?? [], RelDynTraitAssign::tagsOf($p), $name);
        }
    }
}
