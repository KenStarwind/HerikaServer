<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php';
require_once __DIR__ . '/RelDynFacetPreferencesTest.php';   // RelDynFacetProfiles, RelDynFacetPrefsDb
require_once __DIR__ . '/RelDynFacetAppraisalTest.php';     // example vectors

/**
 * appraisal-valence (felt read): the LLM gets the dominant facet as a feeling, worded by
 * its sign and intensity, never numbers (decisions §3, §6).
 */
final class RelDynFacetFeltTextTest extends TestCase
{
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

    private static function appraisal(array $row, array $facets): array
    {
        $prefs = RelDynFacets::derivePreferences($row, RelDynFacetProfiles::profileFor($row))['prefs'];
        return RelDynFacets::appraise($prefs, $facets);
    }

    public function testSameRuinReadsAsScholarshipOrKillZone(): void
    {
        $scholar = RelDynFacets::feltText('Ashe', self::appraisal(RelDynFacetProfiles::scholarRow(), RelDynFacetAppraisalTest::DWEMER_RUIN),
            'place', 'Mzinchaleft');
        $this->assertStringContainsString('wants to understand', $scholar);
        $this->assertStringContainsString('Ashe', $scholar);

        $huntress = RelDynFacets::feltText('Aela', self::appraisal(RelDynFacetProfiles::huntressRow(), RelDynFacetAppraisalTest::DWEMER_RUIN),
            'place', 'Mzinchaleft');
        $this->assertStringContainsString('kill zone', $huntress);
    }

    public function testHuntressInLibraryIsRestlessAndWantsOut(): void
    {
        $text = RelDynFacets::feltText('Aela', self::appraisal(RelDynFacetProfiles::huntressRow(), RelDynFacetAppraisalTest::LIBRARY),
            'place', 'The Arcanaeum');
        $this->assertStringContainsString('restless', $text);
        $this->assertStringContainsString('door', $text);
    }

    public function testMildAndStrongWordingFollowTheDominantContribution(): void
    {
        $prefs = RelDynFacets::neutralPreferences();
        $prefs['confined'] = -1.0;
        $strong = RelDynFacets::feltText('Aela', RelDynFacets::appraise($prefs, ['confined' => 0.9]), 'place', 'Dragonsreach');
        $mild = RelDynFacets::feltText('Aela', RelDynFacets::appraise($prefs, ['confined' => 0.3]), 'place', 'Dragonsreach');
        $cfg = RelDynFacets::appraisalDefaults()['felt_text']['place']['confined']['-'];
        $this->assertSame(str_replace(['{NAME}', '{THING}'], ['Aela', 'Dragonsreach'], $cfg['strong']), $strong);
        $this->assertSame(str_replace(['{NAME}', '{THING}'], ['Aela', 'Dragonsreach'], $cfg['mild']), $mild);
        $this->assertStringContainsString('walls press in', $strong);
    }

    public function testNothingStandsOutSaysNothing(): void
    {
        $this->assertNull(RelDynFacets::feltText('Aela', RelDynFacets::appraise(RelDynFacets::neutralPreferences(), ['scholarly' => 1.0]), 'place', 'x'));
        $prefs = RelDynFacets::neutralPreferences();
        $prefs['quiet'] = 0.1;
        $this->assertNull(RelDynFacets::feltText('Aela', RelDynFacets::appraise($prefs, ['quiet' => 1.0]), 'place', 'x'),
            'a faint preference is below felt_min_contribution');
    }

    public function testOtherKindsUseTheThingTableWithTheirName(): void
    {
        $prefs = RelDynFacets::neutralPreferences();
        $prefs['scholarly'] = 0.9;
        $text = RelDynFacets::feltText('Ashe', RelDynFacets::appraise($prefs, ['scholarly' => 1.0]), 'item', 'Ancient Falmer Tome');
        $this->assertStringContainsString('Ancient Falmer Tome', $text);
        $this->assertStringContainsString('Ashe', $text);
    }

    public function testEveryFacetSignAndBandHasWordingWithoutNumbers(): void
    {
        $felt = RelDynFacets::appraisalDefaults()['felt_text'];
        foreach (RelDynFacets::FACETS as $facet) {
            foreach (['+', '-'] as $sign) {
                foreach (['mild', 'strong'] as $band) {
                    $t = $felt['place'][$facet][$sign][$band] ?? '';
                    $this->assertNotSame('', $t, "place {$facet}{$sign} {$band}");
                    $this->assertStringContainsString('{NAME}', $t);
                    $this->assertDoesNotMatchRegularExpression('/\d/', $t);
                }
                $t = $felt['thing'][$facet][$sign] ?? '';
                $this->assertStringContainsString('{THING}', $t, "thing {$facet}{$sign}");
                $this->assertDoesNotMatchRegularExpression('/\d/', $t);
            }
        }
    }

    public function testWordingIsEditableInConfig(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->configValue = ['facet_appraisal' => ['felt_text' => [
            'place' => ['confined' => ['-' => ['mild' => '{NAME} hates {THING}.', 'strong' => '{NAME} really hates {THING}.']]],
        ]]];
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();

        $prefs = RelDynFacets::neutralPreferences();
        $prefs['confined'] = -1.0;
        $this->assertSame('Aela really hates the cellar.',
            RelDynFacets::feltText('Aela', RelDynFacets::appraise($prefs, ['confined' => 0.9]), 'place', 'the cellar'));
        $prefs['scholarly'] = 1.0;
        $default = RelDynFacets::appraisalDefaults()['felt_text']['thing']['scholarly']['+'];
        $this->assertSame(str_replace(['{NAME}', '{THING}'], ['Aela', 'a book'], $default),
            RelDynFacets::feltText('Aela', RelDynFacets::appraise($prefs, ['scholarly' => 0.9]), 'item', 'a book'),
            'wording the stored felt_text leaves out keeps its default (merged per line)');
    }
}
