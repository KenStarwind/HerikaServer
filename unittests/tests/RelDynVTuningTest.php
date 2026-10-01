<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynVTuningConfigDb
{
    public function __construct(public array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Batch V, the tuning lane (decisions 2026-10-01 §21 #10, §22, and the review queue's FIX NEXT), the pure parts, with the
 * four test beds as real trait vectors (no database, no LLM):
 *   - neutral multipliers: a middle-of-the-road NPC (every trait 0.5) feels passion and jealousy at exactly x1.0; slopes,
 *     presets and every other multiplier are as they were; the beds still diverge (Ashe's hand-set vector is spoiler-free:
 *     never her bio, nothing of her story);
 *   - the toxic affinity multiplier is asymmetric: x1.2 on gains, x1.6 on losses, by the NPC's own toxic weight;
 *   - one passion band set, "faint to burning": the older "cold to redline" labels are gone;
 *   - the stored config of an older install reads the new defaults (config_schema 5), and a choice stays a choice.
 * Units: traits 0..1; multipliers unitless; passion points 0..100.
 */
final class RelDynVTuningTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = null;
        RelDynTraits::$readCalibrationOverride = null;
        RelDynTraits::resetReadCalibrationCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelDynTraits::$assignmentOverride = null;
        RelDynTraits::$readCalibrationOverride = null;
        RelDynTraits::resetReadCalibrationCache();
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ the beds as real vectors

    private static function middle(): array
    {
        return array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.5) + ['maturity_start' => 50.0];
    }

    /** A bed from the committed seed's read of its bio (the vector the read assignment gives it); no LLM. */
    private static function bedFromSeed(string $key): array
    {
        $e = RelDynTraitRead::loadSeedFile()['reads'][$key];
        $auto = RelDynTraitAssign::resolve(['read' => $e['result'], 'prior_in' => []]);
        $stored = RelDynTraits::toStored($auto['x']);
        return ['trait_vector' => $stored, 'inferred_temperament' => $auto['label'],
            '_trait_vector_src' => ['assignment' => 'read', 'auto' => $stored, 'auto_label' => $auto['label'], 'traits' => $auto['src'], 'composed' => 'auto', 'overridden' => []]];
    }

    /** The four test beds' state: Aela, Muiri, Lynly Star-Sung from their reads, Ashe from the hand-set vector (never read). */
    private static function beds(): array
    {
        $hand = RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides']['ashe']['trait_vector'];
        return [
            'Aela the Huntress' => self::bedFromSeed('aela_the_huntress'),
            'Ashe' => ['trait_vector' => $hand, 'inferred_temperament' => 'Stoic',
                '_trait_vector_src' => ['assignment' => 'read', 'auto' => $hand, 'auto_label' => 'Stoic', 'traits' => [], 'composed' => 'auto',
                    'overridden' => [], 'auto_source' => 'hand-set']],
            'Muiri' => self::bedFromSeed('muiri'),
            'Lynly Star-Sung' => self::bedFromSeed('lynly_star-sung'),
        ];
    }

    // ------------------------------------------------------------------ §21 #10: neutral multipliers

    public function testAMiddleOfTheRoadNpcFeelsPassionAndJealousyAtFaceValue(): void
    {
        $half = self::middle();
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'passion_mult'), 1e-12, 'passion at the all-0.5 vector');
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'jealousy_mult'), 1e-12, 'jealousy at the all-0.5 vector');
        // through the consumers' own path (an NPC whose stored read is the middle)
        $stored = RelDynTraits::toStored($half);
        $d = ['trait_vector' => $stored, 'inferred_temperament' => 'Humble',
            '_trait_vector_src' => ['assignment' => 'read', 'auto' => $stored, 'auto_label' => 'Humble', 'traits' => [], 'composed' => 'auto', 'overridden' => []]];
        $this->assertEqualsWithDelta(1.0, RelationshipDynamics::getSignalResistance('Humble', 'passion', $d), 1e-12);
        $this->assertEqualsWithDelta(1.0, RelDynTraits::param('Humble', 'jealousy_mult', 1.0, $d), 1e-12);
        // the shipped switch is the pair, and only the pair
        $shipped = RelationshipDynamics::defaultConfig()['traits']['read_calibration'];
        $this->assertSame(['passion_mult', 'jealousy_mult'], $shipped['relevel_mult']);
        $this->assertSame(['passion_mult', 'jealousy_mult'], RelDynTraits::relevelMultSetting());
        // every other multiplier keeps its fitted middle (they are tuned against rulings; only these two were ruled neutral)
        foreach (['y_warmth_up' => 0.91, 'y_arousal_up' => 0.865, 'resist_affinity' => 0.945] as $id => $middle) {
            $this->assertEqualsWithDelta($middle, RelDynTraits::value($half, $id), 1e-12, "{$id} is as it was");
        }
    }

    public function testSlopesPresetsAndTheOffSwitchAreAsTheyWere(): void
    {
        $oldMiddle = ['passion_mult' => 0.925, 'jealousy_mult' => 1.185];
        mt_srand(2027);
        $rho = RelDynTraits::residualReach();
        $vectors = [];
        while (count($vectors) < 20) {
            $x = [];
            foreach (RelDynTraits::TRAITS as $code => $_) $x[$code] = 0.5 + (mt_rand(-1000, 1000) / 1000.0) * 0.15;
            $x['maturity_start'] = 50.0;
            if (RelDynTraits::nearestPreset($x)['distance'] >= $rho) $vectors[] = $x;
        }
        foreach ($oldMiddle as $id => $was) {
            foreach ($vectors as $x) {
                RelDynTraits::$readCalibrationOverride = null;
                $on = RelDynTraits::value($x, $id);
                RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'relevel_mult' => false];
                $off = RelDynTraits::value($x, $id);
                $this->assertEqualsWithDelta($off - ($was - 1.0), $on, 1e-9, "{$id}: the same slopes, the middle moved to 1.0");
            }
        }
        // every preset still returns its table value exactly, on or off
        foreach ([null, ['relevel' => true, 'relevel_mult' => false], ['relevel' => true, 'relevel_mult' => true]] as $cal) {
            RelDynTraits::$readCalibrationOverride = $cal;
            foreach (RelDynTraits::points() as $preset => $p) {
                foreach (array_keys($oldMiddle) as $id) {
                    $this->assertSame(RelDynTraits::table($id)[$preset], RelDynTraits::value($p, $id), "{$id} at {$preset}");
                }
            }
        }
        // the switch: false restores today's, a list is exactly those columns, true is every re-levellable multiplier
        $half = self::middle();
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'relevel_mult' => false];
        $this->assertEqualsWithDelta(0.925, RelDynTraits::value($half, 'passion_mult'), 1e-12);
        $this->assertEqualsWithDelta(1.185, RelDynTraits::value($half, 'jealousy_mult'), 1e-12);
        $this->assertFalse(RelDynTraits::relevelMultEnabled());
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'relevel_mult' => ['jealousy_mult']];
        $this->assertEqualsWithDelta(0.925, RelDynTraits::value($half, 'passion_mult'), 1e-12, 'only the named column');
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'jealousy_mult'), 1e-12);
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'relevel_mult' => true];
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'passion_mult'), 1e-12);
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'y_warmth_up'), 1e-12, 'true: every re-levellable multiplier');
        // a stored row without the key takes the shipped pair
        RelDynTraits::$readCalibrationOverride = ['relevel' => true];
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'passion_mult'), 1e-12);
        RelDynTraits::$readCalibrationOverride = [];
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value($half, 'jealousy_mult'), 1e-12);
    }

    public function testTheBedsStillDivergeAndTheMiddleNoLongerLeans(): void
    {
        $passion = $jealousy = [];
        foreach (self::beds() as $name => $d) {
            $x = RelDynTraits::readVector($d);
            $this->assertNotNull($x, "{$name}: the read assignment gives a vector");
            $passion[$name] = RelDynTraits::value($x, 'passion_mult');
            $jealousy[$name] = RelDynTraits::value($x, 'jealousy_mult');
        }
        $why = json_encode(['passion' => $passion, 'jealousy' => $jealousy]);
        // meaningful divergence: the guarded, restrained hand-set vector feels passion least and is least jealous; the warm,
        // expressive, possessive-leaning bed feels most
        $this->assertGreaterThan(0.25, max($passion) - min($passion), 'passion spread across the beds: ' . $why);
        $this->assertGreaterThan(0.25, max($jealousy) - min($jealousy), 'jealousy spread across the beds: ' . $why);
        $this->assertSame('Ashe', array_search(min($passion), $passion), $why);
        $this->assertSame(array_search(min($passion), $passion), array_search(min($jealousy), $jealousy), 'the same bed is the coolest on both: ' . $why);
        $this->assertSame('Muiri', array_search(max($passion), $passion), $why);
        // against the textbook intercept the beds sit lower on jealousy by the middle's lean (1.185 -> 1.0) and higher on
        // passion (0.925 -> 1.0), whoever they are: the shift is the same for every NPC outside a preset's reach
        foreach (self::beds() as $name => $d) {
            $x = RelDynTraits::readVector($d);
            RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'relevel_mult' => false];
            $oldP = RelDynTraits::value($x, 'passion_mult');
            $oldJ = RelDynTraits::value($x, 'jealousy_mult');
            RelDynTraits::$readCalibrationOverride = null;
            if (RelDynTraits::nearestPreset($x)['distance'] >= RelDynTraits::residualReach()) {
                $this->assertEqualsWithDelta($oldP + 0.075, $passion[$name], 1e-9, "{$name} passion: the middle's +0.075");
                $this->assertEqualsWithDelta($oldJ - 0.185, $jealousy[$name], 1e-9, "{$name} jealousy: the middle's -0.185");
            }
        }
    }

    // ------------------------------------------------------------------ §22: toxic, x1.2 gains and x1.6 losses

    private static function bedWith(string $name, array $extra = []): array
    {
        $d = self::beds()[$name];
        return array_replace_recursive(RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics()), $d, $extra);
    }

    public function testToxicIsAsymmetricIdealiseThenDevalue(): void
    {
        $rows = RelationshipDynamics::affinityModifierDefaults();
        $ids = array_column($rows, 'id');
        $this->assertNotContains('toxic_all', $ids, 'the one x1.4 row is gone');
        $this->assertContains('toxic_gains', $ids);
        $this->assertContains('toxic_losses', $ids);
        // Muiri (toxic) against the three secure beds: the same help, the same insult
        $muiri = self::bedWith('Muiri', ['profile_overrides' => ['attachment_style' => 'toxic'], 'dimensions' => ['maturity' => ['x' => 50.0]]]);
        $this->assertEqualsWithDelta(1.2, RelationshipDynamics::affinityModifiers($muiri, 10, ['help'])['rows']['toxic_gains'], 1e-9);
        $loss = RelationshipDynamics::affinityModifiers($muiri, -10, ['insult']);
        $this->assertEqualsWithDelta(1.6, $loss['rows']['toxic_losses'], 1e-9);
        $this->assertArrayNotHasKey('toxic_gains', $loss['rows']);
        $this->assertArrayNotHasKey('toxic_losses', RelationshipDynamics::affinityModifiers($muiri, 10, ['help'])['rows'], 'gains carry the gain row only');
        $steep = [];
        foreach (['Aela the Huntress', 'Ashe', 'Lynly Star-Sung', 'Muiri'] as $name) {
            $d = self::bedWith($name, $name === 'Muiri' ? ['profile_overrides' => ['attachment_style' => 'toxic']] : ['profile_overrides' => ['attachment_style' => 'secure']]);
            $d['dimensions']['maturity']['x'] = 50.0;
            $loss = RelationshipDynamics::affinityModifiers($d, -10, ['insult']);
            $gain = RelationshipDynamics::affinityModifiers($d, 10, ['help']);
            $steep[$name] = ['loss' => $loss['M'], 'gain' => $gain['M'], 'toxic' => array_intersect_key($loss['rows'] + $gain['rows'], ['toxic_gains' => 1, 'toxic_losses' => 1])];
        }
        $why = json_encode($steep);
        foreach (['Aela the Huntress', 'Ashe', 'Lynly Star-Sung'] as $secure) {
            $this->assertEqualsWithDelta(1.0, $steep[$secure]['loss'], 1e-9, "{$secure}: a secure NPC takes a loss at face value " . $why);
            $this->assertSame([], $steep[$secure]['toxic'], "{$secure}: no toxic row at all");
        }
        $this->assertEqualsWithDelta(1.6, $steep['Muiri']['loss'], 1e-9, 'Muiri is devalued hard: ' . $why);
        // the same help lifts Muiri 1.2x as far as it lifts a secure bed (both ride the same passion-drives-gains row)
        $this->assertEqualsWithDelta(1.2, $steep['Muiri']['gain'] / $steep['Aela the Huntress']['gain'], 1e-9, 'and idealises more softly: ' . $why);
        $this->assertEqualsWithDelta($steep['Aela the Huntress']['gain'], $steep['Ashe']['gain'], 1e-9);
        $this->assertGreaterThan(1.2, $steep['Muiri']['loss'], 'the loss lands harder than the gain lifts');
    }

    public function testTheToxicRowAppliesByTheNpcsOwnToxicWeight(): void
    {
        // Between the corners an NPC reads the effect as 1 + weight x (mult - 1): half toxic, half the swing
        $half = self::bedWith('Aela the Huntress', ['profile_overrides' => ['attachment_axes' => ['anxiety' => 0.85, 'avoidance' => 0.5]],
            'dimensions' => ['maturity' => ['x' => 50.0]]]);
        $w = RelationshipDynamics::attachmentWeights($half)['toxic'];
        $this->assertEqualsWithDelta(0.5, $w, 1e-6, 'a fully anxious, half avoidant NPC is half toxic');
        $this->assertEqualsWithDelta(1.0 + $w * 0.6, RelationshipDynamics::affinityModifiers($half, -10, ['insult'])['rows']['toxic_losses'], 1e-9);
        $this->assertEqualsWithDelta(1.0 + $w * 0.2, RelationshipDynamics::affinityModifiers($half, 10, ['help'])['rows']['toxic_gains'], 1e-9);
        // no toxic weight, no row effect (never a hard gate either way)
        $none = self::bedWith('Aela the Huntress', ['profile_overrides' => ['attachment_style' => 'secure'], 'dimensions' => ['maturity' => ['x' => 50.0]]]);
        $this->assertEqualsWithDelta(1.0, RelationshipDynamics::affinityModifiers($none, -10, ['insult'])['M'], 1e-9);
    }

    // ------------------------------------------------------------------ §22: one passion band set, faint to burning

    public function testThereIsOnePassionBandSetFaintToBurning(): void
    {
        $labels = array_column(RelationshipDynamics::DIMENSION_BANDS['passion'], 'label');
        $this->assertSame(['None', 'Faint', 'Stirring', 'Warm', 'Intense', 'Burning'], $labels);
        foreach (['Cold', 'Tepid', 'Heated', 'Redline'] as $retired) {
            $this->assertNotContains($retired, $labels, "'{$retired}' belonged to the older set");
        }
        // the two readers agree at every point, edges included
        $edges = [[-5, 'none'], [0, 'none'], [0.4, 'faint'], [1, 'faint'], [19.99, 'faint'], [20, 'stirring'], [39.9, 'stirring'], [40, 'warm'],
            [59.9, 'warm'], [60, 'intense'], [79.9, 'intense'], [80, 'burning'], [91, 'burning'], [100, 'burning'], [140, 'burning']];
        foreach ($edges as [$p, $band]) {
            $this->assertSame($band, RelationshipDynamics::getPassionBand($p), "getPassionBand({$p})");
            $row = RelationshipDynamics::getDimensionBand('passion', $p);
            $this->assertSame($band, strtolower($row['label']), "getDimensionBand('passion', {$p})");
            $this->assertNotSame('', trim($row['keywords']), "{$p}: a band that says what the NPC does");
        }
        for ($p = 0.0; $p <= 100.0; $p += 0.5) {
            $this->assertSame(strtolower(RelationshipDynamics::getDimensionBand('passion', $p)['label']), RelationshipDynamics::getPassionBand($p), "at {$p}");
        }
        // the felt words are keyed by the same set (reldyn_felt's passion lines)
        $felt = RelDynFelt::config()['text']['passion'] ?? null;
        $this->assertIsArray($felt);
        $keys = array_keys($felt);
        foreach (['faint', 'stirring', 'warm', 'intense', 'burning'] as $band) {
            $this->assertContains($band, $keys, "felt text for {$band}");
        }
    }

    // ------------------------------------------------------------------ config rows of an older install

    private function store(array $row): void
    {
        $GLOBALS['db'] = new RelDynVTuningConfigDb($row);
        RelationshipDynamics::clearConfigCache();
    }

    /** The row install.php wrote at v0.23 (stamped 4): today's defaults with the four values batch V changed put back. */
    private static function rowOfV023(): array
    {
        $row = RelationshipDynamics::defaultConfig();
        $row['config_schema'] = 4;
        $row['traits']['read_calibration']['relevel_mult'] = false;
        $row['facet_appraisal']['weather_gravity']['targets'] = RelationshipDynamics::RETIRED_WEATHER_TARGETS_V5;
        $out = [];
        foreach ($row['affinity_modifiers'] as $r) {
            if ($r['id'] === 'toxic_gains') { $out[] = RelationshipDynamics::RETIRED_TOXIC_ROW_V5; continue; }
            if ($r['id'] === 'toxic_losses') continue;
            $out[] = $r;
        }
        $row['affinity_modifiers'] = $out;
        return $row;
    }

    public function testAnOldRowReadsTheNewDefaults(): void
    {
        $old = self::rowOfV023();
        $this->assertContains('toxic_all', array_column($old['affinity_modifiers'], 'id'), 'the fixture is the old row');
        $this->store($old);
        $new = RelationshipDynamics::defaultConfig();
        $this->assertSame(['passion_mult', 'jealousy_mult'], RelDynTraits::relevelMultSetting(), 'the multipliers are neutral at the middle');
        $this->assertEqualsWithDelta(1.0, RelDynTraits::value(self::middle(), 'passion_mult'), 1e-12);
        $this->assertEquals($new['facet_appraisal']['weather_gravity']['targets'], RelDynFacets::getAppraisalConfig()['weather_gravity']['targets']);
        $ids = array_column(RelationshipDynamics::getConfig()['affinity_modifiers'], 'id');
        $this->assertNotContains('toxic_all', $ids);
        $this->assertSame(array_search('toxic_gains', array_column($new['affinity_modifiers'], 'id')), array_search('toxic_gains', $ids), 'split in place');
        $this->assertEquals($new['affinity_modifiers'], RelationshipDynamics::getConfig()['affinity_modifiers']);
        $this->assertSame(RelationshipDynamics::CONFIG_SCHEMA, RelationshipDynamics::loadStoredConfig()['config_schema'], 'migrated in memory');
        // the same row stamped 5 or later is a choice and stays
        $choice = $old;
        $choice['config_schema'] = RelationshipDynamics::CONFIG_SCHEMA;
        $this->store($choice);
        $this->assertSame([], RelDynTraits::relevelMultSetting(), 'a stamped false is a choice');
        $this->assertEquals(RelationshipDynamics::RETIRED_WEATHER_TARGETS_V5, RelDynFacets::getAppraisalConfig()['weather_gravity']['targets']);
        $this->assertContains('toxic_all', array_column(RelationshipDynamics::getConfig()['affinity_modifiers'], 'id'));
    }

    public function testAChoiceOnAnOldRowStays(): void
    {
        $old = self::rowOfV023();
        $old['traits']['read_calibration']['relevel_mult'] = true;                      // someone turned every multiplier on
        $old['facet_appraisal']['weather_gravity']['targets']['overcast']['passion'] = -6.0;   // and tuned the grey day
        foreach ($old['affinity_modifiers'] as &$r) if ($r['id'] === 'toxic_all') $r['mult'] = 1.5;   // and the toxic row
        unset($r);
        $this->store($old);
        $this->assertTrue(RelDynTraits::relevelMultSetting());
        $this->assertEqualsWithDelta(-6.0, RelDynFacets::getAppraisalConfig()['weather_gravity']['targets']['overcast']['passion'], 1e-9);
        $rows = RelationshipDynamics::getConfig()['affinity_modifiers'];
        $this->assertContains('toxic_all', array_column($rows, 'id'), 'an edited row is theirs');
        $this->assertNotContains('toxic_gains', array_column($rows, 'id'));
    }
}
