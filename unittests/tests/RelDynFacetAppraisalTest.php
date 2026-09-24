<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php';
require_once __DIR__ . '/RelDynFacetPreferencesTest.php';   // RelDynFacetProfiles

/**
 * appraisal-valence (decisions 2026-09-23 §6): valence = normalised sum(facet x preference),
 * the facet contributing most decides what the NPC sees, a negative dominant facet is the
 * hated read. Preferences come from the real derivation over core-shaped rows.
 */
final class RelDynFacetAppraisalTest extends TestCase
{
    /** Decisions §6 example vectors. */
    const DWEMER_RUIN = ['scholarly' => 0.6, 'crafting' => 0.4, 'enchanting' => 0.3, 'adventure' => 0.8,
                         'combat' => 0.6, 'danger' => 0.7, 'confined' => 0.6];
    const LIBRARY = ['scholarly' => 1.0, 'confined' => 0.4, 'quiet' => 0.8];
    const FOREST = ['nature' => 1.0, 'wild' => 0.8];

    private $savedDb = null;
    private bool $hadDb = false;

    protected function setUp(): void
    {
        $this->hadDb = array_key_exists('db', $GLOBALS);
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->hadDb) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    private static function prefs(array $row): array
    {
        return RelDynFacets::derivePreferences($row, RelDynFacetProfiles::profileFor($row))['prefs'];
    }

    public function testSameDwemerRuinScholarSeesScholarshipHuntressSeesBattlefield(): void
    {
        $scholar = RelDynFacets::appraise(self::prefs(RelDynFacetProfiles::scholarRow()), self::DWEMER_RUIN);
        $this->assertSame('scholarly', $scholar['dominant']);
        $this->assertSame(1, $scholar['dominant_sign']);
        $this->assertGreaterThan(0.0, $scholar['valence']);

        $huntress = RelDynFacets::appraise(self::prefs(RelDynFacetProfiles::huntressRow()), self::DWEMER_RUIN);
        $this->assertSame('combat', $huntress['dominant'], 'the ruin reads as a battlefield');
        $this->assertSame(1, $huntress['dominant_sign']);
    }

    public function testHuntressInLibraryIsTheHatedRead(): void
    {
        $a = RelDynFacets::appraise(self::prefs(RelDynFacetProfiles::huntressRow()), self::LIBRARY);
        $this->assertContains($a['dominant'], ['scholarly', 'confined']);
        $this->assertSame(-1, $a['dominant_sign']);
        $this->assertLessThan(-0.2, $a['valence'], 'restless, wants out');

        $s = RelDynFacets::appraise(self::prefs(RelDynFacetProfiles::scholarRow()), self::LIBRARY);
        $this->assertGreaterThan(0.4, $s['valence'], 'the scholar is at ease among books');
    }

    public function testForestFeedsTheHuntress(): void
    {
        $a = RelDynFacets::appraise(self::prefs(RelDynFacetProfiles::huntressRow()), self::FOREST);
        $this->assertSame('nature', $a['dominant']);
        $this->assertGreaterThan(0.7, $a['valence']);
    }

    public function testValenceIsWeightedMeanOfPreferencesAndIntensityTheMeanMagnitude(): void
    {
        $prefs = RelDynFacets::neutralPreferences();
        $prefs['scholarly'] = 1.0;
        $prefs['confined'] = -0.5;
        $a = RelDynFacets::appraise($prefs, ['scholarly' => 0.5, 'confined' => 1.0, 'quiet' => 0.5]);
        // contributions: scholarly +0.5, confined -0.5, quiet 0; total facet weight 2.0
        $this->assertEqualsWithDelta(0.0, $a['valence'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $a['intensity'], 1e-9, 'ambivalent is intense, not neutral');
        $this->assertEqualsWithDelta(0.5, $a['contributions']['scholarly'], 1e-9);
        $this->assertEqualsWithDelta(-0.5, $a['contributions']['confined'], 1e-9);
        $this->assertSame('scholarly', $a['dominant'], 'a tie goes to the earlier facet (FACETS order)');
    }

    public function testRangeHoldsForExtremes(): void
    {
        $love = array_fill_keys(RelDynFacets::FACETS, 1.0);
        $hate = array_fill_keys(RelDynFacets::FACETS, -1.0);
        $all = array_fill_keys(RelDynFacets::FACETS, 1.0);
        $this->assertEqualsWithDelta(1.0, RelDynFacets::appraise($love, $all)['valence'], 1e-9);
        $this->assertEqualsWithDelta(-1.0, RelDynFacets::appraise($hate, $all)['valence'], 1e-9);
        $this->assertEqualsWithDelta(1.0, RelDynFacets::appraise($hate, $all)['intensity'], 1e-9);
        // weights outside 0..1 and unknown facets are clamped / ignored
        $a = RelDynFacets::appraise($love, ['scholarly' => 3.0, 'gardening' => 1.0, 'dark' => -2.0]);
        $this->assertEqualsWithDelta(1.0, $a['valence'], 1e-9);
        $this->assertSame(['scholarly'], array_keys($a['contributions']));
    }

    public function testNothingToAppraiseIsNeutral(): void
    {
        $a = RelDynFacets::appraise(RelDynFacets::neutralPreferences(), self::DWEMER_RUIN);
        $this->assertSame(0.0, $a['valence']);
        $this->assertSame(0.0, $a['intensity']);
        $this->assertNull($a['dominant'], 'indifferent: nothing stands out');

        $b = RelDynFacets::appraise(self::prefs(RelDynFacetProfiles::huntressRow()), []);
        $this->assertSame(['valence' => 0.0, 'intensity' => 0.0, 'dominant' => null, 'dominant_sign' => 1, 'contributions' => []], $b);
    }
}
