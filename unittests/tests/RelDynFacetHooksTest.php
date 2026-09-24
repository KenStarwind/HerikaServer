<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php';
require_once __DIR__ . '/RelDynFacetPreferencesTest.php';   // RelDynFacetProfiles, RelDynFacetPrefsDb
require_once __DIR__ . '/RelDynFacetAppraisalTest.php';     // example vectors
require_once __DIR__ . '/RelDynContextDefaultsTest.php';    // RelDynContextFakeDb

/**
 * The place lanes' two readers (currentPlaceContext / placeFacets belong to the places
 * lane) answering with stored data: a place context and its facet vector. Everything
 * contextTurn() does with them is the real code (late static binding).
 */
final class RelDynFacetsWithPlace extends RelDynFacets
{
    public static array $ctx = [];
    public static array $facets = [];
    public static array $asked = [];

    public static function currentPlaceContext(string $npcName): array
    {
        self::$asked[] = $npcName;
        return self::$ctx;
    }

    public static function placeFacets(array $placeContext): array
    {
        return self::$facets;
    }
}

/**
 * appraisal-effects / ambient-presence wiring: the context hook (after core has set
 * CACHE_LOCATION) runs the place turn and injects the felt read; combat events and gifts
 * use the activity's own appraisal for passion.
 */
final class RelDynFacetHooksTest extends TestCase
{
    const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    const T0 = 80 * RelationshipDynamics::GAMETS_PER_DAY + 15 * self::HOUR;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'HERIKA_NAME', 'contextDataFull', 'CACHE_PEOPLE',
                  'RELDYN_MASKING_ACTIVE', 'RELDYN_NPC_NAME', 'LAST_LLM_RESPONSE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
        }
        unset($GLOBALS['db'], $GLOBALS['gameRequest'], $GLOBALS['CACHE_PEOPLE'], $GLOBALS['RELDYN_MASKING_ACTIVE'],
              $GLOBALS['RELDYN_NPC_NAME'], $GLOBALS['LAST_LLM_RESPONSE']);
        RelDynFacetsWithPlace::$ctx = [];
        RelDynFacetsWithPlace::$facets = [];
        RelDynFacetsWithPlace::$asked = [];
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private static function huntressState(): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['inferred_temperament'] = 'Independent';
        $d['love_language_primary'] = RelationshipDynamics::LL_TIME;
        $d['love_language_secondary'] = RelationshipDynamics::LL_WORDS;
        foreach (['comfort', 'trust', 'respect', 'maturity'] as $dim) {
            $d['dimensions'][$dim]['x'] = $d['dimensions'][$dim]['baseline'] = RelationshipDynamics::getTemperamentBaseline('Independent', $dim);
        }
        return $d;
    }

    public function testContextTurnReadsThePlaceStoresPreferencesAndReturnsTheFeeling(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->rows['aela the huntress'] = RelDynFacetProfiles::huntressRow();
        $GLOBALS['db'] = $db;
        RelDynFacetsWithPlace::$ctx = ['name' => 'The Arcanaeum', 'hold' => 'Winterhold', 'tags' => 'Dwelling,', 'is_interior' => 1];
        RelDynFacetsWithPlace::$facets = RelDynFacetAppraisalTest::LIBRARY;

        $d = self::huntressState();
        $c0 = $d['dimensions']['comfort']['x'];
        $r = RelDynFacetsWithPlace::contextTurn('Aela the Huntress', $d, self::T0);

        $this->assertSame(['Aela the Huntress'], RelDynFacetsWithPlace::$asked);
        $this->assertTrue($r['changed']);
        $this->assertSame('The Arcanaeum', $r['place']);
        $this->assertEqualsWithDelta(-0.6, $d['_facet_prefs']['prefs']['scholarly'], 0.1, 'preferences derived from core and stored');
        $this->assertSame('The Arcanaeum', $d['_place_appraisal']['place']);
        $this->assertSame($c0, $d['dimensions']['comfort']['x'], 'arrival: no time spent there yet');
        $this->assertStringContainsString('restless', $r['text']);
        $r = RelDynFacetsWithPlace::contextTurn('Aela the Huntress', $d, self::T0 + self::HOUR);
        $this->assertLessThan($c0, $d['dimensions']['comfort']['x'], 'an hour in the library wears on her');
        $this->assertDoesNotMatchRegularExpression('/\d/', $r['text']);
    }

    public function testAKnownPlaceWithoutFacetsIsNeutralAndNoPlaceKeepsTheFreshRead(): void
    {
        $GLOBALS['db'] = new RelDynFacetPrefsDb();
        $d = self::huntressState();
        $prefs = RelDynFacets::neutralPreferences();
        $prefs['scholarly'] = -0.8;
        RelDynFacets::placeTurn('Aela', $d, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);

        // core gives no place this turn: the last read holds while fresh
        $r = RelDynFacetsWithPlace::contextTurn('Aela', $d, self::T0 + self::HOUR);
        $this->assertNull($r['place']);
        $this->assertSame('The Arcanaeum', $d['_place_appraisal']['place']);
        $this->assertNull(RelDynFacetsWithPlace::contextTurn('Aela', $d, self::T0 + 3 * self::HOUR)['text'], 'stale: nothing said');

        // core names a place it has no facets for: she has left the library
        RelDynFacetsWithPlace::$ctx = ['name' => 'Some Unmapped Cave'];
        $r = RelDynFacetsWithPlace::contextTurn('Aela', $d, self::T0 + self::HOUR);
        $this->assertSame('Some Unmapped Cave', $d['_place_appraisal']['place']);
        $this->assertSame(0.0, $d['_place_appraisal']['valence']);
        $this->assertNull($r['text']);
    }

    public function testContextHookInjectsThePlaceFeelingWithoutNumbers(): void
    {
        $db = new RelDynContextFakeDb();
        $GLOBALS['db'] = $db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['HERIKA_NAME'] = 'Aela';
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) self::T0, 'Kaida: what do you think of this place?'];

        $prefs = RelDynFacets::derivePreferences(RelDynFacetProfiles::huntressRow(), ['temperament' => 'Independent', 'traits' => []])['prefs'];
        $state = self::huntressState();
        RelDynFacets::placeTurn('Aela', $state, 'The Arcanaeum', RelDynFacetAppraisalTest::LIBRARY, $prefs, self::T0);
        $state['_place_discomfort'] = ['place' => 'The Arcanaeum', 'points' => 70.0, 'gamets' => self::T0];

        $blocks = $this->runContext($db, $state);
        $feeling = (string) (RelDynFelt::lastRendered()['place'] ?? '');
        $this->assertStringContainsString($feeling, implode("\n", $blocks), 'the place line is in the context');
        $this->assertStringContainsString('restless', $feeling);
        $this->assertStringContainsString('wearing on them', $feeling);
        foreach ($blocks as $b) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $b, "number reached the LLM:\n{$b}");
        }
        $stored = $db->plugin['aela']['reldyn']['dynamics'];
        $this->assertArrayHasKey('_facet_prefs', $stored, 'the turn stored the preferences');

        $GLOBALS['gameRequest'][2] = (string) (self::T0 + 3 * self::HOUR);
        $blocks = $this->runContext($db, $state);
        $this->assertArrayNotHasKey('place', RelDynFelt::lastRendered(), 'stale read says nothing');
    }

    private function runContext(RelDynContextFakeDb $db, array $state): array
    {
        $db->plugin = ['aela' => ['reldyn' => ['dynamics' => $state]]];
        $GLOBALS['contextDataFull'] = [];
        RelationshipDynamics::clearConfigCache();
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/context.php'; })();
        return array_map(fn($m) => (string) $m['content'], $GLOBALS['contextDataFull']);
    }

    public function testCombatAndGiftPassionUseTheActivitysOwnAppraisal(): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) self::T0, 'Kaida: nice shot'];
        $d = self::huntressState();
        $likes = RelDynFacets::neutralPreferences();
        $likes['combat'] = 0.8;
        $hates = RelDynFacets::neutralPreferences();
        $hates['combat'] = -0.8;
        $fightLiked = RelDynFacets::experienceThing('Aela', $d, 'activity', 'combat', $likes, self::T0);
        $fightHated = RelDynFacets::experienceThing('Aela', $d, 'activity', 'combat', $hates, self::T0);

        $gLiked = RelationshipDynamics::calculatePassionGain($d, RelationshipDynamics::LL_SERVICE, $fightLiked);
        $gHated = RelationshipDynamics::calculatePassionGain($d, RelationshipDynamics::LL_SERVICE, $fightHated);
        $w = RelDynFacets::appraisalDefaults()['ll_interest_weight'][RelationshipDynamics::LL_SERVICE];
        $expected = (1 + (RelDynFacets::interestMultiplier($fightLiked['valence']) - 1) * $w)
                  / (1 + (RelDynFacets::interestMultiplier($fightHated['valence']) - 1) * $w);
        $this->assertEqualsWithDelta($expected, $gLiked / $gHated, 1e-6);

        // a gift with no known facets and no place read: neutral
        $GLOBALS['LAST_LLM_RESPONSE'] = ['item' => 'Unknown Trinket'];
        $this->assertSame('Unknown Trinket', RelationshipDynamics::detectGiftItemName());
        $this->assertSame(1.0, RelationshipDynamics::getInterestMultiplier($d, RelationshipDynamics::LL_GIFTS));
    }
}
