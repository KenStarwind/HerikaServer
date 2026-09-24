<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** conf_opts holding only the RelDyn config row (everything else absent). */
final class RelDynPlaceConfigDb
{
    public function __construct(private array $config) {}
    public function fetchOne($q, array $params = [])
    {
        if (strpos($q, "FROM conf_opts WHERE id = 'relationship_dynamics_config'") !== false) {
            return ['value' => json_encode($this->config)];
        }
        return [];
    }
    public function fetchAll($q, $log = false) { return []; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * place-facets-core (decisions 2026-09-23 §6): a place from CHIM core data (location tags,
 * name, inside/outside, time of day, weather) becomes a multi-facet vector, from editable
 * config. The context strings are the ones the 3.4.1 plugin writes (live eventlog shapes).
 */
final class RelDynPlaceFacetsTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
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

    private static function place(array $ctx): array
    {
        return RelDynFacets::placeFacets($ctx + ['name' => '', 'tags' => [], 'is_interior' => null,
            'time_of_day' => 'day', 'weather' => []]);
    }

    public function testFacetListIsTheElevenInterestsPlusSituational(): void
    {
        $this->assertSame(['combat', 'crafting', 'alchemy', 'enchanting', 'scholarly', 'nature', 'social',
            'domestic', 'adventure', 'spiritual', 'wealth'], RelDynFacets::INTERESTS);
        $this->assertSame(array_merge(RelDynFacets::INTERESTS, RelDynFacets::SITUATIONAL), RelDynFacets::FACETS);
        foreach (['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'] as $f) {
            $this->assertContains($f, RelDynFacets::SITUATIONAL);
        }
    }

    /** Every tag the plugin can write (Plugin/Papyrus.h kLocationKeywords) has a config row. */
    public function testEveryPluginLocationTagIsMapped(): void
    {
        $pluginTags = ['Cave', 'Dungeon', 'Inn', 'Town', 'City', 'Hold', 'Farm', 'Mine', 'Jail', 'Ship', 'House',
            'Store', 'Guild', 'Temple', 'Castle', 'Nordic Ruin', 'Dwelling', 'Bandit Camp', 'Dragon Lair',
            'Falmer Hive', 'Dwarven Ruin', 'Settlement', 'Lumber Mill', 'Habitation', 'Draugr Crypt',
            'Vampire Lair', 'Warlock Lair', 'Military Fort', 'Military Camp', 'Werewolf Lair', 'Forsworn Camp',
            'Giant Camp', 'Animal Den', 'Cemetery', 'Shipwreck', 'Player House'];
        $table = RelDynFacets::placeFacetDefaults()['tags'];
        $this->assertSame([], array_values(array_diff($pluginTags, array_keys($table))));
        foreach ($table as $tag => $facets) {
            $this->assertSame([], array_values(array_diff(array_keys($facets), RelDynFacets::FACETS)), $tag);
        }
    }

    public function testDwemerRuinCarriesEveryFacetOfDecisionsExample(): void
    {
        $v = self::place(['name' => 'Mzinchaleft', 'tags' => 'Dungeon,Dwarven Ruin,', 'is_interior' => true]);
        foreach (['scholarly', 'crafting', 'enchanting', 'adventure', 'combat', 'danger', 'confined'] as $f) {
            $this->assertArrayHasKey($f, $v, $f);
        }
        $this->assertSame(0.6, $v['scholarly']);
        $this->assertSame(0.8, $v['adventure']);
        $this->assertSame(0.7, $v['danger']);
        // Dungeon and Dwarven Ruin both give combat: the higher weight counts, nothing piles up.
        $this->assertSame(0.6, $v['combat']);
        $this->assertLessThanOrEqual(1.0, max($v));
    }

    public function testDwemerRuinByNameWhenNoLocationRowIsKnown(): void
    {
        // An interior cell of a ruin core has no locations row for yet.
        $v = self::place(['name' => 'Mzinchaleft Depths', 'is_interior' => true]);
        $this->assertSame(0.6, $v['scholarly']);
        $this->assertSame(0.6, $v['combat']);
    }

    public function testLibraryIsScholarlyQuietConfined(): void
    {
        $v = self::place(['name' => 'Arcanaeum', 'is_interior' => true]);
        $this->assertSame(1.0, $v['scholarly']);
        $this->assertSame(0.8, $v['quiet']);
        $this->assertSame(0.5, $v['confined'], 'the interior row (0.5) beats the library row (0.4)');
        $this->assertArrayNotHasKey('combat', $v);
        $this->assertArrayNotHasKey('nature', $v);
    }

    public function testForestAndUnknownWildernessAreNatureAndWild(): void
    {
        $forest = self::place(['name' => 'Falkreath Forest', 'is_interior' => false]);
        $this->assertSame(1.0, $forest['nature']);
        $this->assertSame(0.8, $forest['wild']);

        // The plugin's wilderness: an exterior cell with no location name.
        $wild = self::place(['name' => '', 'is_interior' => false]);
        $this->assertSame(['nature' => 0.8, 'wild' => 0.8], $wild);

        // A named exterior nothing describes degrades to the wild too.
        $unknown = self::place(['name' => 'Sundered Hilltop', 'is_interior' => false]);
        $this->assertSame(['nature' => 0.8, 'wild' => 0.8], $unknown);

        // A named place with nothing known about it at all: no guesses.
        $this->assertSame([], self::place(['name' => 'Sundered Hilltop', 'is_interior' => null]));
    }

    public function testInnAndTownFromLiveTags(): void
    {
        $inn = self::place(['name' => 'The Bannered Mare', 'tags' => 'Dwelling,Inn,', 'is_interior' => true]);
        $this->assertSame(0.9, $inn['social']);
        $this->assertSame(0.6, $inn['crowd']);
        $this->assertSame(0.5, $inn['confined']);

        $town = self::place(['name' => 'Riverwood', 'tags' => 'Town,Habitation,', 'is_interior' => false]);
        $this->assertSame(0.6, $town['social']);
        $this->assertSame(0.3, $town['wild'], 'outside');
        $this->assertArrayNotHasKey('nature', $town, 'a described town is not the wilderness');
    }

    public function testNameKeywordsMatchWordsAndWordEndsNotInsides(): void
    {
        $this->assertTrue(RelDynFacets::nameHasKeyword('Candlehearth Hall', 'hearth'));
        $this->assertTrue(RelDynFacets::nameHasKeyword('The Bannered Mare', 'mare'));
        $this->assertTrue(RelDynFacets::nameHasKeyword('Honningbrew Meadery', 'mead'));
        $this->assertTrue(RelDynFacets::nameHasKeyword('Hall of the Dead', 'hall of the dead'));
        $this->assertFalse(RelDynFacets::nameHasKeyword('Winterhold', 'inter'));
        $this->assertFalse(RelDynFacets::nameHasKeyword('Pinnacle', 'inn'));
        // Word ends do match, which is why 'wood' is not a default keyword (Riverwood is a town).
        $this->assertTrue(RelDynFacets::nameHasKeyword('Riverwood', 'wood'));
        $this->assertArrayNotHasKey('nature', self::place(['name' => 'Riverwood', 'tags' => ['Town'], 'is_interior' => false]));
        $this->assertSame(0.9, self::place(['name' => 'Candlehearth Hall', 'is_interior' => true])['social']);
    }

    public function testNightIsDarkOutsideButNotIndoors(): void
    {
        $out = self::place(['name' => 'Riverwood', 'tags' => ['Town'], 'is_interior' => false, 'time_of_day' => 'night']);
        $this->assertSame(0.7, $out['dark']);
        $in = self::place(['name' => 'The Bannered Mare', 'tags' => ['Inn'], 'is_interior' => true, 'time_of_day' => 'night']);
        $this->assertArrayNotHasKey('dark', $in);
        $day = self::place(['name' => 'Riverwood', 'tags' => ['Town'], 'is_interior' => false, 'time_of_day' => 'day']);
        $this->assertArrayNotHasKey('dark', $day);
    }

    public function testWeatherCountsOnlyOutside(): void
    {
        $fogOut = self::place(['name' => 'Riverwood', 'tags' => ['Town'], 'is_interior' => false, 'weather' => ['fog']]);
        $this->assertSame(0.3, $fogOut['dark']);
        $fogIn = self::place(['name' => 'The Bannered Mare', 'tags' => ['Inn'], 'is_interior' => true, 'weather' => ['fog']]);
        $this->assertArrayNotHasKey('dark', $fogIn);
    }

    public function testParsesPluginContextStrings(): void
    {
        $out = RelDynFacets::parseLocationContext('(Context location: Riverwood outdoors ,Hold: Whiterun, current date Sundas, 9:54 AM, 17th of Last Seed, 4E 201, current weather: Pleasant)');
        $this->assertSame(['name' => 'Riverwood', 'raw_name' => 'Riverwood outdoors', 'hold' => 'Whiterun',
            'weather' => ['pleasant'], 'is_interior' => false], $out);

        // Interior cell: no suffix, the weather is what it is outdoors.
        $in = RelDynFacets::parseLocationContext('(Context location: The Bannered Mare ,Hold: Whiterun, current date Sundas, 9:54 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Cloudy, Raining)');
        $this->assertSame('The Bannered Mare', $in['name']);
        $this->assertTrue($in['is_interior']);
        $this->assertSame(['cloudy', 'rain'], $in['weather']);

        $suffix = RelDynFacets::parseLocationContext('(Context location: Bleak Falls Barrow interior ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ...)');
        $this->assertSame('Bleak Falls Barrow', $suffix['name']);
        $this->assertTrue($suffix['is_interior']);
        $this->assertSame([], $suffix['weather']);

        $wild = RelDynFacets::parseLocationContext('(Context location: , Buildings to go:, Current Date in Skyrim World: ...)');
        $this->assertSame('', $wild['name']);
        $this->assertNull($wild['is_interior']);
    }

    public function testWeatherLabelsFromPluginAndCore(): void
    {
        $this->assertSame(['snow'], RelDynFacets::weatherKeys('Snowning'));
        $this->assertSame(['pleasant', 'fog'], RelDynFacets::weatherKeys('Pleasant, Foggy'));
        $this->assertSame([], RelDynFacets::weatherKeys('Unknown'));
    }

    public function testTimeOfDayPeriodsWrapMidnight(): void
    {
        $this->assertSame('dawn', RelDynFacets::timeOfDay(5.0));
        $this->assertSame('day', RelDynFacets::timeOfDay(9.9));
        $this->assertSame('dusk', RelDynFacets::timeOfDay(19.99));
        $this->assertSame('night', RelDynFacets::timeOfDay(20.0));
        $this->assertSame('night', RelDynFacets::timeOfDay(2.5));
        $this->assertNull(RelDynFacets::timeOfDay(null));
    }

    public function testDominantInterestIgnoresSituationalFacets(): void
    {
        $this->assertSame('adventure', RelDynFacets::dominantInterest(['danger' => 1.0, 'adventure' => 0.8, 'combat' => 0.6]));
        $this->assertSame('nature', RelDynFacets::dominantInterest(['nature' => 0.8, 'wild' => 0.8]));
        $this->assertNull(RelDynFacets::dominantInterest(['confined' => 0.5, 'social' => 0.2]));
    }

    /** The mapping is config: an edited tag row changes the vector without a code change. */
    public function testMappingsComeFromEditableConfig(): void
    {
        $GLOBALS['db'] = new RelDynPlaceConfigDb(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA,
            'place_facets' => ['tags' => ['Inn' => ['social' => 0.2, 'quiet' => 0.9]]]]);
        $v = self::place(['name' => 'Somewhere', 'tags' => ['Inn'], 'is_interior' => true]);
        $this->assertSame(0.2, $v['social']);
        $this->assertSame(0.9, $v['quiet']);
        $this->assertSame(0.5, $v['confined'], 'sub-tables not in the stored row keep their defaults');
        $this->assertArrayNotHasKey('crowd', $v);
    }
}
