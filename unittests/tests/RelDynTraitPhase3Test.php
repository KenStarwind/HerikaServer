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

    // =========================================================================
    // Attachment de-duplication (design §2.2: A3 / A4 / A15h Anx, A18, A20)
    // =========================================================================

    /** A reunion-ready NPC: 24 game hours apart with real play in between (checkReunion). */
    private static function reunionReady(array $d): array
    {
        $cal = 3.0e9;
        $GLOBALS['gameRequest'] = ['inputtext', 1700000000, $cal, 'Kaida: hello'];
        $d['love_language_primary'] = RelationshipDynamics::LL_TIME;
        $d['_last_contact_gamets'] = $cal - RelationshipDynamics::GAMETS_PER_DAY;
        $d['_accumulated_play_gamets'] = 10 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $d['_last_contact_play_gamets'] = 8 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        return $d;
    }

    public function testAnxietyIsCountedOnceByTheAttachmentNotAgainByTemperament(): void
    {
        $saved = $GLOBALS['gameRequest'] ?? null;
        try {
            // the Anxious preset reads its trait models (the anxiety residual is gone)
            $anx = RelDynTraits::points()['Anxious'];
            $this->assertEqualsWithDelta(1.31, RelDynTraits::value($anx, 'reunion_mult'), 1e-12, 'A3: model 1.31, not MDD 1.8');
            $this->assertEqualsWithDelta(1.39, RelDynTraits::value($anx, 'jealousy_mult'), 1e-12, 'A4: model 1.39, not MDD 1.5');
            $this->assertEqualsWithDelta(0.84, RelDynTraits::value($anx, 'y_trust_up'), 1e-12, 'A15h: model 0.84, not 1.5');
            // A3: the reunion's anxiety part is the attachment's now (anxious corner x1.4)
            $spike = function (string $preset, string $style): float {
                $d = self::reunionReady(self::preset($preset, self::style($style)));
                return RelationshipDynamics::checkReunion($d, 100);
            };
            $this->assertEqualsWithDelta(1.4, $spike('Stoic', 'anxious') / $spike('Stoic', 'secure'), 1e-9, 'any anxious NPC');
            $this->assertEqualsWithDelta(1.8, $spike('Anxious', 'anxious') / $spike('Humble', 'secure'), 0.05,
                'an Anxious NPC with the anxious attachment it implies is back at the MDD 1.8');
            $this->assertEqualsWithDelta(1.31, $spike('Anxious', 'secure') / $spike('Humble', 'secure'), 1e-9, 'secure: temperament alone');
            // A4: jealousy counts anxiety once (attachment x2.0), not twice (1.5 x 2.0 = 3.0 before)
            $jeal = fn(string $p, string $style) => RelationshipDynamics::jealousyEventGain(self::reunionReady(self::preset($p, self::style($style))), 1);
            $this->assertEqualsWithDelta(1.39 * 2.0, $jeal('Anxious', 'anxious') / $jeal('Humble', 'secure') * 0.5, 1e-9);
        } finally {
            if ($saved !== null) $GLOBALS['gameRequest'] = $saved; else unset($GLOBALS['gameRequest']);
        }
    }

    public function testAbsenceDecayIsOwnedByPossessivenessWithoutTheAnxietyBump(): void
    {
        // A18: Rule R over -(0.17 + 1.61 Po); the presets keep their MDD flavour except Anxious,
        // whose -2.0 counted anxiety a third time (the attachment mult x2.0 carries it)
        $col = RelDynTraits::columns()['absence_decay'];
        $this->assertSame(['R', ['Po']], [$col['rule'], $col['owners']]);
        $pts = RelDynTraits::points();
        $this->assertEqualsWithDelta(-1.14, RelDynTraits::value($pts['Anxious'], 'absence_decay'), 1e-12);
        $this->assertEqualsWithDelta(-(0.17 + 1.61 * 0.60), RelDynTraits::value($pts['Anxious'], 'absence_decay'), 0.01, 'Anxious is its model');
        $this->assertEqualsWithDelta(-0.3, RelDynTraits::value($pts['Stoic'], 'absence_decay'), 1e-12);
        $this->assertEqualsWithDelta(-1.8, RelDynTraits::value($pts['Jealous'], 'absence_decay'), 1e-12, 'possessiveness stays');
        // far from every preset (Ashe's vector): the possessiveness model
        $ashe = self::at(self::asheVector());
        $this->assertEqualsWithDelta(-(0.17 + 1.61 * 0.20), RelDynTraits::param($ashe['inferred_temperament'], 'absence_decay', -0.5, $ashe), 1e-9);
        // an anxious attachment doubles it once, for any temperament
        foreach (['Stoic', 'Anxious'] as $p) {
            $this->assertEqualsWithDelta(2.0, RelationshipDynamics::getAttachmentModifier(self::preset($p, self::style('anxious')), 'affinity_absence_mult'), 1e-9);
        }
        // no vector: today's default
        $this->assertSame(-0.5, RelDynTraits::param(null, 'absence_decay', -0.5));
    }

    public function testNeglectCodependenceReadsPossessivenessNotTheAnxiousLabelOrTheInsecureTag(): void
    {
        $c = function (string $p, string $style, array $traits = []): float {
            $d = self::preset($p, self::style($style));
            $d['traits'] = $traits;
            return RelationshipDynamics::getNeglectProfile($d)['codependence'];
        };
        $edges = RelationshipDynamics::neglectSeverityDefaults()['codependence_possessiveness'];
        $T = fn(float $po) => RelationshipDynamics::codependenceFromPossessiveness($po, $edges);
        $this->assertEqualsWithDelta(0.0, $T(0.10), 1e-12, 'Independent / Stoic');
        $this->assertEqualsWithDelta(0.5, $T(0.30), 1e-12);
        $this->assertEqualsWithDelta(0.5, $T(0.55), 1e-12, 'Romantic: the typical middle');
        $this->assertEqualsWithDelta(1.0, $T(0.90), 1e-12, 'Jealous');
        $prev = -INF;
        foreach (range(0, 20) as $i) {
            $this->assertGreaterThanOrEqual($prev, $t = $T($i / 20));
            $prev = $t;
        }
        // c = 0.6 A(attachment) + 0.4 T(Po)
        $this->assertEqualsWithDelta(0.6 * 0.5 + 0.4 * 0.5, $c('Romantic', 'secure'), 1e-9, 'the typical NPC stays in the middle');
        $this->assertEqualsWithDelta(0.6 * 1.0 + 0.4 * 1.0, $c('Jealous', 'anxious'), 1e-9);
        $this->assertEqualsWithDelta(0.6 * 1.0 + 0.4 * $T(0.60), $c('Anxious', 'anxious'), 1e-9,
            'Anxious: its anxiety is the attachment term, its temperament term is its possessiveness');
        $this->assertEqualsWithDelta($c('Romantic', 'secure'), $c('Romantic', 'secure', ['insecure']), 1e-12,
            'insecure no longer adds +0.2 (the anxiety it stands for is the attachment term)');
        $this->assertEqualsWithDelta(0.0, $c('Independent', 'avoidant'), 1e-9);
        // no temperament: this consumer reads Stoic (design §3.6), whose Po .10 gives T 0
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['inferred_temperament'] = null;
        $d['profile_overrides']['attachment_style'] = 'secure';
        $this->assertEqualsWithDelta(0.6 * 0.5, RelationshipDynamics::getNeglectProfile($d)['codependence'], 1e-9);
        // a label with no vector (not a preset): the default T
        $d['inferred_temperament'] = 'Volatile';
        $this->assertEqualsWithDelta(0.6 * 0.5 + 0.4 * 0.5, RelationshipDynamics::getNeglectProfile($d)['codependence'], 1e-9);
    }

    // =========================================================================
    // A2 redesign: the fall of bleedout as passion, valence and arousal (design §2.5)
    // =========================================================================

    public function testBleedoutIsFightOrFearWithValenceAndArousal(): void
    {
        $cfg = RelationshipDynamics::defaultConfig()['bleedout_response'];
        $this->assertFalse(RelDynTraits::hasColumn('bleedout'), 'no longer a preset table');
        $this->assertFalse(defined('RelationshipDynamics::TEMPERAMENT_BLEEDOUT_DRAIN'));
        $fall = function (string $p) {
            $d = self::preset($p);
            return RelationshipDynamics::bleedoutResponse($d);
        };
        // the formula at a point
        foreach (RelDynTraits::points() as $p => $x) {
            $r = $fall($p);
            $fight = $x['C'] * $x['Pd'] * (1 - $x['D']);
            $fear = $x['L'] * (1 - $x['C']);
            $this->assertEqualsWithDelta($fight - $fear, $r['net'], 1e-12, $p);
            $want = max(-5.0, min(5.0, 4.25 * ($fight - $fear)));
            $this->assertEqualsWithDelta(abs($want) < 0.05 ? 0.0 : $want, $r['passion'], 1e-12, "{$p} passion");
            $this->assertEqualsWithDelta(40.0 * ($fight - $fear), $r['valence'], 1e-12, "{$p} valence: the sign of fight - fear");
            $this->assertEqualsWithDelta(20.0 * (0.5 + $x['L']), $r['arousal'], 1e-12, "{$p} arousal spike");
        }
        // MDD 1.3 combat notes: Bold / Defiant fight harder (passion and valence UP; Bold was -0.3),
        // Anxious panics (the MDD's -3.0 kept by calibration), Guarded / Gentle go negative
        $this->assertGreaterThan(0.0, $fall('Bold')['passion'], 'Bold: passion goes up (the MDD), not -0.3');
        $this->assertGreaterThan(0.0, $fall('Defiant')['passion']);
        $this->assertGreaterThan(0.0, $fall('Defiant')['valence']);
        $this->assertEqualsWithDelta(-3.0, $fall('Anxious')['passion'], 0.01);
        $this->assertLessThan(-20.0, $fall('Anxious')['valence'], 'abandonment panic');
        $this->assertLessThan(0.0, $fall('Guarded')['valence']);
        $this->assertLessThan(0.0, $fall('Gentle')['valence']);
        $this->assertEqualsWithDelta(-0.5, $fall('Stoic')['passion'], 0.01, 'Stoic barely registers, as before');
        // arousal: every fall is a spike, the reactive the most
        $this->assertGreaterThan($fall('Stoic')['arousal'], $fall('Anxious')['arousal']);
        $this->assertEqualsWithDelta(10.0, $fall('Independent')['arousal'], 1e-9, 'L 0: the smallest spike');

        // the dead band: a near-balanced fall moves no passion (valence and arousal still move)
        $d = self::at(['confidence' => 0.5, 'pride' => 0.5, 'restraint' => 0.5, 'reactivity' => 0.25]);   // fight .125, fear .125
        $r = RelationshipDynamics::bleedoutResponse($d);
        $this->assertEqualsWithDelta(0.0, $r['net'], 1e-12);
        $this->assertSame(0.0, $r['passion']);
        $this->assertGreaterThan(0.0, $r['arousal']);

        // applied: arousal and valence through applyDelta with Y 1 (no second trait scaling)
        $d = self::preset('Anxious');
        $a0 = floatval($d['dimensions']['arousal']['x']);
        $v0 = floatval($d['dimensions']['valence']['x']);
        $r = RelationshipDynamics::bleedoutResponse($d, true);
        $this->assertGreaterThan($a0, floatval($d['dimensions']['arousal']['x']));
        $this->assertLessThan($v0, floatval($d['dimensions']['valence']['x']));
        $this->assertEqualsWithDelta($r['applied']['arousal'], floatval($d['dimensions']['arousal']['x']) - $a0, 1e-3);
        $this->assertEqualsWithDelta($r['applied']['valence'], floatval($d['dimensions']['valence']['x']) - $v0, 1e-3);

        // Ashe (her own vector): a mild negative fall, no panic
        $ashe = self::at(self::asheVector());
        $ra = RelationshipDynamics::bleedoutResponse($ashe);
        $this->assertLessThan(0.0, $ra['net']);
        $this->assertGreaterThan(-1.0, $ra['passion']);

        // no vector (inferred_temperament null): today's -1.5 drain, nothing else
        $n = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $n['inferred_temperament'] = null;
        $r = RelationshipDynamics::bleedoutResponse($n, true);
        $this->assertSame([-1.5, 0.0, 0.0, false], [$r['passion'], $r['applied']['arousal'], $r['applied']['valence'], $r['vector']]);
        $this->assertSame($cfg['no_vector_passion'], -1.5);
    }
}
