<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Batch T traits: diminishing-returns (1, 2).
 *
 * (1) Her warmth curve and love languages are derived from her trait vector, which moves: her bio
 *     read lands after the first meeting, the editor sets a temperament or a trait. ensureLoveLanguage
 *     derived them once (the first call that found none) and never again, so an NPC met before her
 *     read kept the curve of her priors. What she still holds from the last derivation is derived again
 *     when its inputs change (the _ll_auto record); what was chosen since is kept.
 * (2) The continuous A6 columns (warmth_half_life, _decay_rate, _lambda, _passion_decay) were defined and
 *     read by nothing: the session multiplier, the interaction count's decay and the passion decay read the
 *     named preset curve. They read the columns at her own vector now (an in-between NPC blends; a textbook
 *     preset gets its named curve's numbers exactly), unless the curve was chosen by name.
 *
 * No database: the NPCs are built current (ensureTemperamentProfile has nothing to resolve) with the
 * vector the test hands them; nothing is stored.
 */
final class RelDynWarmthCurveTraitsTest extends TestCase
{
    private $savedDb;
    private $savedAssignment;
    private $prevErrorLog;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        $this->savedAssignment = RelDynTraits::$assignmentOverride;
        RelDynTraits::$assignmentOverride = 'read';
        RelationshipDynamics::clearConfigCache();
        $this->prevErrorLog = ini_set('error_log', sys_get_temp_dir() . '/reldyn_warmth_curve_test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        RelDynTraits::$assignmentOverride = $this->savedAssignment;
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC whose profile is resolved at vector $x (a read assignment), with $label as her nearest temperament. */
    private function npcAt(array $x, string $label, array $extra = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $label,
            'trait_vector' => RelDynTraits::toStored($x),
            'trait_vector_version' => RelDynTraits::VERSION,
            '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($x), 'read_status' => 'done',
                                    'prior' => ['complete' => true]],
            '_profile_autogen' => ['version' => RelationshipDynamics::PROFILE_AUTOGEN_VERSION],
        ], $extra));
        return $d;
    }

    /** Her vector moves to $x (the bio read landed, an editor change): what a re-resolution stores. */
    private function moveTo(array &$d, array $x, string $label): void
    {
        $d['inferred_temperament'] = $label;
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src']['auto'] = RelDynTraits::toStored($x);
    }

    private function p(string $preset): array
    {
        return RelDynTraits::presetPoint($preset);
    }

    // ------------------------------------------------------------------ (1) re-derive

    public function testAnNpcMetBeforeHerReadMovesToHerReadsCurveWhenItLands(): void
    {
        $d = $this->npcAt($this->p('Bold'), 'Bold');   // her priors at the first meeting: open, confident
        RelationshipDynamics::ensureLoveLanguage('Aela the Huntress', $d);
        $first = [$d['love_language_primary'], $d['love_language_secondary'], $d['warmth_curve']];
        $this->assertSame(RelationshipDynamics::CURVE_MODERATE, $d['warmth_curve']);
        $this->assertSame($d['warmth_curve'], $d['_ll_auto']['curve']);

        // The read lands: she is guarded
        $this->moveTo($d, $this->p('Guarded'), 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Aela the Huntress', $d);
        $this->assertSame(RelationshipDynamics::CURVE_GUARDED, $d['warmth_curve'], "her read's curve, not the priors'");
        $this->assertSame($d['love_language_primary'], $d['_ll_auto']['primary']);
        $this->assertNotSame($first, [$d['love_language_primary'], $d['love_language_secondary'], $d['warmth_curve']]);
        // the languages are the ones a fresh derivation at her read gives
        $fresh = $this->npcAt($this->p('Guarded'), 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Aela the Huntress', $fresh);
        $this->assertSame([$fresh['love_language_primary'], $fresh['love_language_secondary'], $fresh['warmth_curve']],
            [$d['love_language_primary'], $d['love_language_secondary'], $d['warmth_curve']]);
    }

    public function testWhatTheEditorChoseIsKeptWhatWasDerivedFollowsTheVector(): void
    {
        $d = $this->npcAt($this->p('Bold'), 'Bold');
        RelationshipDynamics::ensureLoveLanguage('Muiri', $d);
        $d['love_language_primary'] = RelationshipDynamics::LL_GIFTS;     // the editor's choice
        $d['warmth_curve'] = RelationshipDynamics::CURVE_SLOW_BURN;       // ... and its curve
        $secondaryBefore = $d['love_language_secondary'];
        $this->moveTo($d, $this->p('Guarded'), 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Muiri', $d);
        $this->assertSame(RelationshipDynamics::LL_GIFTS, $d['love_language_primary'], 'chosen: kept');
        $this->assertSame(RelationshipDynamics::CURVE_SLOW_BURN, $d['warmth_curve'], 'chosen: kept');
        $guarded = $this->npcAt($this->p('Guarded'), 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Muiri', $guarded);
        $this->assertSame($guarded['love_language_secondary'], $d['love_language_secondary'], 'derived: follows her vector');
        $this->assertNotSame($secondaryBefore, $d['love_language_secondary']);
    }

    public function testAnOlderSavesLoveLanguagesAreKeptWhateverHerVectorDoes(): void
    {
        // Stored before the record existed: nothing says whether the editor chose them
        $d = $this->npcAt($this->p('Bold'), 'Bold', ['love_language_primary' => RelationshipDynamics::LL_SERVICE,
            'love_language_secondary' => RelationshipDynamics::LL_TOUCH, 'warmth_curve' => RelationshipDynamics::CURVE_QUICK]);
        RelationshipDynamics::ensureLoveLanguage('Lynly Star-Sung', $d);
        $this->moveTo($d, $this->p('Guarded'), 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Lynly Star-Sung', $d);
        $this->assertSame([RelationshipDynamics::LL_SERVICE, RelationshipDynamics::LL_TOUCH, RelationshipDynamics::CURVE_QUICK],
            [$d['love_language_primary'], $d['love_language_secondary'], $d['warmth_curve']]);
        // what the record holds is what derivation gave, never what is stored: the kept curve still runs on its named row
        $this->assertSame(RelationshipDynamics::CURVE_GUARDED, $d['_ll_auto']['curve']);
        $this->assertEquals(RelationshipDynamics::CURVE_PARAMS['quick_warmth'], RelationshipDynamics::warmthParams($d));
    }

    public function testAVectorThatMovesInsideItsNeighbourhoodDerivesNothingAgain(): void
    {
        $x = $this->p('Guarded');
        $d = $this->npcAt($x, 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Ashe', $d);
        $sig = $d['_ll_auto']['sig'];
        $x['G'] -= 0.02;
        $x['W'] += 0.03;
        $this->moveTo($d, $x, 'Guarded');
        $d['love_language_secondary'] = RelationshipDynamics::LL_GIFTS;   // would be overwritten by a re-derivation
        RelationshipDynamics::ensureLoveLanguage('Ashe', $d);
        $this->assertSame($sig, $d['_ll_auto']['sig']);
        $this->assertSame(RelationshipDynamics::LL_GIFTS, $d['love_language_secondary']);
    }

    // ------------------------------------------------------------------ (2) the continuous columns

    public function testATextbookPresetRunsOnItsNamedCurvesExactNumbers(): void
    {
        foreach (array_keys(RelDynTraits::points()) as $preset) {
            $d = $this->npcAt($this->p($preset), $preset);
            RelationshipDynamics::ensureLoveLanguage('Test ' . $preset, $d);
            $named = RelationshipDynamics::CURVE_PARAMS[RelationshipDynamics::TEMPERAMENT_WARMTH_CURVES[$preset]];
            $got = RelationshipDynamics::warmthParams($d);
            foreach ($named as $k => $v) $this->assertEqualsWithDelta($v, $got[$k], 1e-9, "{$preset} {$k}");
        }
    }

    public function testAnInBetweenNpcBlendsTheTextbookCurves(): void
    {
        $bold = $this->p('Bold');
        $guarded = $this->p('Guarded');
        $mid = [];
        foreach ($bold as $k => $v) $mid[$k] = ($v + $guarded[$k]) / 2.0;
        $d = $this->npcAt($mid, 'Bold');
        RelationshipDynamics::ensureLoveLanguage('Blend', $d);
        $m = RelationshipDynamics::CURVE_PARAMS['moderate'];
        $g = RelationshipDynamics::CURVE_PARAMS['guarded'];
        $got = RelationshipDynamics::warmthParams($d);
        foreach (['half_life', 'decay_rate', 'passion_decay'] as $k) {
            $this->assertGreaterThan(min($m[$k], $g[$k]) - 1e-9, $got[$k], $k);
            $this->assertLessThan(max($m[$k], $g[$k]) + 1e-9, $got[$k], $k);
        }
        $this->assertGreaterThan($m['half_life'] + 0.2, $got['half_life'], 'not the named curve of her nearest preset');
        $this->assertEqualsWithDelta(M_LN2 / $got['half_life'], $got['lambda'], 0.002, 'lambda is ln2 over the half-life');
    }

    public function testTheSessionMultiplierAndPassionDecayRunOnHerOwnCurve(): void
    {
        $guarded = $this->p('Guarded');
        $bold = $this->p('Bold');
        $mid = [];
        foreach ($bold as $k => $v) $mid[$k] = ($v + $guarded[$k]) / 2.0;
        $at = function (array $x, string $label): array {
            $d = $this->npcAt($x, $label, ['interaction_count' => 6, 'last_interaction_at' => 1000.0, '_accumulated_play_gamets' => 1000.0 + 2315 * 3600 * 2]);
            RelationshipDynamics::ensureLoveLanguage('Curve ' . $label, $d);
            RelationshipDynamics::setPassion($d, 50.0);
            $d['passion_updated_at'] = 1000.0 + 2315 * 3600 * 2 - 2315 * 3600 * 0.1;
            return $d;
        };
        $mult = [];
        $decay = [];
        foreach (['Bold' => $bold, 'Mid' => $mid, 'Guarded' => $guarded] as $name => $x) {
            $d = $at($x, $name === 'Mid' ? 'Bold' : $name);
            $mult[$name] = RelationshipDynamics::getSessionMultiplier($d);
            RelationshipDynamics::decayPassion($d);
            $decay[$name] = 50.0 - RelationshipDynamics::getPassion($d);
        }
        // Guarded: slower recovery (long half-life) and faster passion decay; Mid sits strictly between
        $this->assertLessThan($mult['Bold'], $mult['Guarded']);
        $this->assertGreaterThan($mult['Guarded'], $mult['Mid']);
        $this->assertLessThan($mult['Bold'], $mult['Mid']);
        $this->assertGreaterThan($decay['Bold'], $decay['Guarded']);
        $this->assertGreaterThan($decay['Bold'], $decay['Mid']);
        $this->assertLessThan($decay['Guarded'], $decay['Mid']);
    }

    public function testACurveChosenByNameRunsOnTheNamedRowWhateverTheVectorIs(): void
    {
        $d = $this->npcAt($this->p('Guarded'), 'Guarded');
        RelationshipDynamics::ensureLoveLanguage('Chosen', $d);
        $d['warmth_curve'] = RelationshipDynamics::CURVE_QUICK;       // the editor's choice
        $got = RelationshipDynamics::warmthParams($d);
        foreach (RelationshipDynamics::CURVE_PARAMS['quick_warmth'] as $k => $v) $this->assertEqualsWithDelta($v, $got[$k], 1e-9, $k);
    }

    public function testAnNpcWithNothingDerivedRunsOnTheNamedRowAsBefore(): void
    {
        $d = array_merge(RelationshipDynamics::defaultDynamics(), ['warmth_curve' => 'guarded']);
        $this->assertEquals(RelationshipDynamics::CURVE_PARAMS['guarded'], RelationshipDynamics::warmthParams($d));
        $d['warmth_curve'] = null;
        $this->assertEquals(RelationshipDynamics::CURVE_PARAMS['moderate'], RelationshipDynamics::warmthParams($d));
    }
}
