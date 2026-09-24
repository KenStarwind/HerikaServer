<?php
/**
 * RelDyn facets (decisions 2026-09-23 §6): anything an NPC experiences (a place, item,
 * topic, creature, activity) becomes a facet vector, facet => weight 0..1, over the 11 MDD
 * interests (MDD 1.2) plus situational facets. NPC preferences are signed per facet and the
 * appraisal (valence, dominant facet) is plain math over the two vectors.
 *
 * Places (this part): the current place comes from CHIM core data only (eventlog location
 * context, the core `locations` table, the game clock), never from MinAI. Every mapping
 * lives in the editable config key 'place_facets' (RelationshipDynamics::defaultConfig()).
 */

final class RelDynFacets
{
    /** The 11 MDD interest categories (MDD 1.2). */
    const INTERESTS = ['combat', 'crafting', 'alchemy', 'enchanting', 'scholarly', 'nature',
        'social', 'domestic', 'adventure', 'spiritual', 'wealth'];

    /** Situational facets: what a situation is like, whatever the NPC's interests. */
    const SITUATIONAL = ['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'];

    const FACETS = ['combat', 'crafting', 'alchemy', 'enchanting', 'scholarly', 'nature',
        'social', 'domestic', 'adventure', 'spiritual', 'wealth',
        'danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'];

    /** Worldspace names of the plugin's main Skyrim worldspace (0x3C) in locations.world. */
    const MAIN_WORLDSPACES = ['skyrim', 'tamriel'];

    // =========================================================================
    // Place facets
    // =========================================================================

    /**
     * Facet vector of a place. $placeContext as currentPlaceContext() returns it:
     *   'name'        location name without the plugin's 'outdoors'/'interior' suffix ('' = wilderness)
     *   'tags'        core locations.tags, as a list or core's comma string ('Dungeon,Draugr Crypt,')
     *   'is_interior' true / false / null (unknown)
     *   'time_of_day' dawn|day|dusk|night|null
     *   'weather'     list of weather keys (clear, pleasant, cloudy, rain, snow, fog), [] = unknown
     * ('hold' is accepted and not used for facets; 'known' => false, core has no location
     * context at all, gives [] rather than a guess.)
     *
     * Sources are combined by taking the highest weight per facet, so a place carries every
     * facet any source gives it (a Dwemer ruin is scholarly AND combat AND confined) without
     * piling up past 1.0:
     *   tags          place_facets.tags[tag]
     *   name keywords place_facets.name_keywords[keyword] (a keyword matches a whole word, the
     *                 start or the end of a word: 'hearth' in Candlehearth); the value is a
     *                 facet vector or the name of a tag whose vector it reuses
     *   inside/out    place_facets.interior / .exterior
     *   wilderness    place_facets.wilderness, for an exterior (or unnamed) place no tag or
     *                 keyword describes
     *   time of day   place_facets.time_of_day[period], outside or unknown only (night -> dark)
     *   weather       place_facets.weather[key], outside only
     *
     * @return array<string,float> facet => weight (0..1], only facets above 0, FACETS order
     */
    public static function placeFacets(array $placeContext): array
    {
        if (($placeContext['known'] ?? true) === false) return [];   // core has no location at all
        $cfg = self::placeFacetConfig();
        $vec = [];
        $take = function ($facets) use (&$vec) {
            foreach ((array) $facets as $facet => $w) {
                if (!in_array($facet, self::FACETS, true)) continue;
                $w = max(0.0, min(1.0, floatval($w)));
                if ($w > ($vec[$facet] ?? 0.0)) $vec[$facet] = $w;
            }
        };

        $tagTable = [];
        foreach ((array) ($cfg['tags'] ?? []) as $tag => $facets) {
            $tagTable[strtolower(trim((string) $tag))] = $facets;
        }
        foreach (self::parseTags($placeContext['tags'] ?? []) as $tag) {
            $take($tagTable[strtolower($tag)] ?? []);
        }

        $name = trim((string) ($placeContext['name'] ?? ''));
        if ($name !== '') {
            foreach ((array) ($cfg['name_keywords'] ?? []) as $keyword => $facets) {
                if (!self::nameHasKeyword($name, (string) $keyword)) continue;
                $take(is_string($facets) ? ($tagTable[strtolower(trim($facets))] ?? []) : $facets);
            }
        }
        $described = !empty($vec);

        $interior = $placeContext['is_interior'] ?? null;
        $interior = is_bool($interior) ? $interior : null;
        if ($interior === true) {
            $take($cfg['interior'] ?? []);
        } elseif ($interior === false) {
            $take($cfg['exterior'] ?? []);
        }
        if (!$described && ($interior === false || ($interior === null && $name === ''))) {
            $take($cfg['wilderness'] ?? []);
        }

        if ($interior !== true) {
            $period = $placeContext['time_of_day'] ?? null;
            if (is_string($period)) $take(($cfg['time_of_day'] ?? [])[$period] ?? []);
        }
        if ($interior === false) {
            foreach ((array) ($placeContext['weather'] ?? []) as $key) {
                $take(($cfg['weather'] ?? [])[$key] ?? []);
            }
        }

        $out = [];
        foreach (self::FACETS as $facet) {
            if (($vec[$facet] ?? 0.0) > 0.0) $out[$facet] = round($vec[$facet], 3);
        }
        return $out;
    }

    /**
     * The NPC's current place from CHIM core data: the newest eventlog location context
     * (types infoloc/location/request, the rows core's DataLastKnownLocationContextParts
     * reads), the core `locations` row of that name, the game clock and the newest reported
     * weather. RelDyn's hooks run for the NPC in the player's scene, so the player's place is
     * the NPC's place; $npcName names whose place is asked.
     *
     * @return array{name: string, raw_name: string, hold: string, region: string, tags: string[],
     *   factions: string, is_interior: ?bool, hour: ?float, time_of_day: ?string,
     *   weather: string[], known: bool}
     *   known = false when core has no location context at all (nothing below is real).
     */
    public static function currentPlaceContext(string $npcName): array
    {
        $ctx = ['name' => '', 'raw_name' => '', 'hold' => '', 'region' => '', 'tags' => [], 'factions' => '',
            'is_interior' => null, 'hour' => null, 'time_of_day' => null, 'weather' => [], 'known' => false];
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return $ctx;

        $row = null;
        try {
            $row = $db->fetchOne("SELECT data, gamets FROM eventlog WHERE type IN ('infoloc','location','request') "
                . "AND data LIKE '%(Context%' ORDER BY gamets DESC, ts DESC LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('currentPlaceContext location', $e);
        }

        $gamets = RelationshipDynamics::currentGamets();
        if (is_array($row) && !empty($row['data'])) {
            $parsed = self::parseLocationContext((string) $row['data']);
            $ctx['known'] = true;
            $ctx['name'] = $parsed['name'];
            $ctx['raw_name'] = $parsed['raw_name'];
            $ctx['hold'] = $parsed['hold'];
            $ctx['weather'] = $parsed['weather'];
            $ctx['is_interior'] = $parsed['is_interior'];
            if ($gamets <= 0) $gamets = floatval($row['gamets'] ?? 0);

            if ($parsed['name'] !== '') {
                $loc = self::locationRow($parsed['name']);
                if ($loc !== null) {
                    $ctx['tags'] = self::parseTags($loc['tags'] ?? '');
                    $ctx['region'] = (string) ($loc['region'] ?? '');
                    $ctx['factions'] = (string) ($loc['factions'] ?? '');
                    if ($ctx['hold'] === '') $ctx['hold'] = (string) ($loc['hold'] ?? '');
                }
                if ($ctx['is_interior'] === null) {
                    // No 'outdoors'/'interior' suffix and no weather in the row: the plugin
                    // (GetPlayerLocation) leaves the suffix off for an interior cell and for an
                    // exterior in a worldspace other than Skyrim's (a walled city). Only the
                    // exact location row of a city worldspace says exterior.
                    $world = strtolower(trim((string) ($loc['world'] ?? '')));
                    $exact = $loc !== null && strcasecmp(trim((string) $loc['name']), $parsed['name']) === 0;
                    $ctx['is_interior'] = !($exact && $world !== '' && !in_array($world, self::MAIN_WORLDSPACES, true));
                }
            } elseif ($ctx['is_interior'] === null) {
                // Empty name: an exterior cell with no location (the wilderness).
                $ctx['is_interior'] = false;
            }

            if (empty($ctx['weather'])) {
                $ctx['weather'] = self::lastReportedWeather();
            }
        }

        $hour = $gamets > 0 ? RelationshipDynamics::gameHourOfDay($gamets) : null;
        $ctx['hour'] = $hour === null ? null : round($hour, 2);
        $ctx['time_of_day'] = self::timeOfDay($hour);
        return $ctx;
    }

    /**
     * One eventlog location context string, parsed the way the plugin writes it:
     *   "(Context location: Riverwood outdoors ,Hold: Whiterun, current date ..., current weather: Pleasant)"
     *   "(Context location: The Bannered Mare ,Hold: Whiterun, ..., current weather: outdoors it is Raining)"
     * The location regexes are core's (DataLastKnownLocationContextParts). Inside/outside:
     * the 'outdoors'/'interior' suffix, else the weather prefix 'outdoors it is ' that the
     * plugin (GetCurrentWeatherDescription) adds only in an interior cell.
     *
     * @return array{name: string, raw_name: string, hold: string, weather: string[], is_interior: ?bool}
     */
    public static function parseLocationContext(string $data): array
    {
        $raw = '';
        if (preg_match('/Context\s*(?:new\s*)?location:\s*([^,]+?)(?:,|$)/u', $data, $m)) {
            $raw = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t\n\r\0\x0B,)");
        }
        $interior = null;
        $name = $raw;
        if (preg_match('/^(.*?)\s+(outdoors|interior)$/iu', $raw, $sm)) {
            $name = trim($sm[1]);
            $interior = strtolower($sm[2]) === 'interior';
        }
        $hold = '';
        if (preg_match('/Hold:\s*([^,\)]+?)(?:,|\)|$)/u', $data, $hm)) {
            $hold = trim($hm[1]);
        }
        $weather = [];
        if (preg_match('/current weather:\s*([^\)]+)/iu', $data, $wm)) {
            $w = trim($wm[1]);
            $inside = stripos($w, 'outdoors it is ') === 0;
            if ($inside) $w = substr($w, strlen('outdoors it is '));
            $weather = self::weatherKeys($w);
            if ($interior === null) $interior = $inside;
        }
        return ['name' => $name, 'raw_name' => $raw, 'hold' => $hold, 'weather' => $weather, 'is_interior' => $interior];
    }

    /**
     * Weather labels (the plugin's Pleasant / Cloudy / Raining / Snowing / Foggy, core's
     * Clear / Raining / Snowning / ...) to keys: clear, pleasant, cloudy, rain, snow, fog.
     * @return string[]
     */
    public static function weatherKeys(string $labels): array
    {
        $map = ['clear' => 'clear', 'pleasant' => 'pleasant', 'cloudy' => 'cloudy',
            'rain' => 'rain', 'rainy' => 'rain', 'raining' => 'rain',
            'snow' => 'snow', 'snowy' => 'snow', 'snowing' => 'snow', 'snowning' => 'snow',
            'fog' => 'fog', 'foggy' => 'fog'];
        $keys = [];
        foreach (explode(',', strtolower($labels)) as $part) {
            $key = $map[trim($part)] ?? null;
            if ($key !== null && !in_array($key, $keys, true)) $keys[] = $key;
        }
        return $keys;
    }

    /** Time-of-day period for a game hour (0 <= h < 24) from place_facets.time_of_day_hours. */
    public static function timeOfDay(?float $hour): ?string
    {
        if ($hour === null) return null;
        $h = fmod(fmod($hour, 24.0) + 24.0, 24.0);
        foreach ((array) (self::placeFacetConfig()['time_of_day_hours'] ?? []) as $period => $range) {
            [$from, $to] = array_map('floatval', array_values((array) $range) + [0, 0]);
            $in = $from <= $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to);
            if ($in) return (string) $period;
        }
        return null;
    }

    /**
     * The strongest interest facet (one of the 11) of a facet vector, or null when none
     * reaches place_facets.dominant_interest_min. Ties go to INTERESTS order.
     */
    public static function dominantInterest(array $facets): ?string
    {
        $min = floatval(self::placeFacetConfig()['dominant_interest_min'] ?? 0.0);
        $best = null;
        $bestW = 0.0;
        foreach (self::INTERESTS as $interest) {
            $w = floatval($facets[$interest] ?? 0.0);
            if ($w > $bestW) { $best = $interest; $bestW = $w; }
        }
        return ($best !== null && $bestW >= $min) ? $best : null;
    }

    /** core locations.tags ('Dungeon,Draugr Crypt,Nordic Ruin,') or a list -> trimmed tag list. */
    public static function parseTags($tags): array
    {
        $list = is_array($tags) ? $tags : explode(',', (string) $tags);
        $out = [];
        foreach ($list as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && !in_array($tag, $out, true)) $out[] = $tag;
        }
        return $out;
    }

    /** A keyword matches a whole word, the start or the end of a word of the name. */
    public static function nameHasKeyword(string $name, string $keyword): bool
    {
        $keyword = trim($keyword);
        if ($keyword === '') return false;
        $q = preg_quote($keyword, '/');
        return (bool) preg_match('/(?:\b' . $q . '|' . $q . '\b)/iu', $name);
    }

    /** place_facets config: stored sub-tables replace the default sub-tables one by one. */
    public static function placeFacetConfig(): array
    {
        $cfg = class_exists('RelationshipDynamics', false)
            ? (RelationshipDynamics::getConfig()['place_facets'] ?? []) : [];
        return array_replace(self::placeFacetDefaults(), is_array($cfg) ? $cfg : []);
    }

    /**
     * The core locations row for a place name: the exact name, else the longest location
     * name the place name starts with ("Mzinchaleft Depths" -> Mzinchaleft). null when none.
     */
    private static function locationRow(string $name): ?array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return null;
        $lit = $db->escapeLiteral(strtolower($name));
        try {
            $row = $db->fetchOne("SELECT name, region, hold, tags, factions, world FROM locations "
                . "WHERE name <> '' AND (lower(name) = {$lit} OR left({$lit}, length(name) + 1) = lower(name) || ' ') "
                . "ORDER BY (lower(name) = {$lit}) DESC, length(name) DESC LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('currentPlaceContext locations', $e);
            return null;
        }
        return (is_array($row) && isset($row['name'])) ? $row : null;
    }

    /** Newest weather core reported (core DataLastKnownWeatherHuman's rows), as keys. */
    private static function lastReportedWeather(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [];
        try {
            $row = $db->fetchOne("SELECT data FROM eventlog WHERE type IN ('location','infoloc','request') "
                . "AND lower(data) LIKE '%current weather:%' ORDER BY gamets DESC, ts DESC LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('currentPlaceContext weather', $e);
            return [];
        }
        if (!is_array($row) || empty($row['data'])) return [];
        return self::parseLocationContext((string) $row['data'])['weather'];
    }

    /**
     * Default place mappings (editable config 'place_facets'). Tags are the 36 location
     * keyword tags the CHIM plugin writes into locations.tags (Plugin/Papyrus.h
     * kLocationKeywords). Weights are facet weights 0..1; the Dwarven Ruin, library and
     * forest rows are decisions §6's examples.
     */
    public static function placeFacetDefaults(): array
    {
        $library = ['scholarly' => 1.0, 'confined' => 0.4, 'quiet' => 0.8];
        $inn = ['social' => 0.9, 'crowd' => 0.6, 'domestic' => 0.3];
        $shrine = ['spiritual' => 0.9, 'sacred' => 0.9, 'quiet' => 0.6];
        $forest = ['nature' => 1.0, 'wild' => 0.8];
        return [
            'tags' => [
                'Cave'          => ['adventure' => 0.7, 'danger' => 0.6, 'confined' => 0.6, 'dark' => 0.5, 'nature' => 0.2],
                'Dungeon'       => ['adventure' => 0.7, 'combat' => 0.5, 'danger' => 0.6, 'confined' => 0.6, 'dark' => 0.5],
                'Inn'           => $inn,
                'Town'          => ['social' => 0.6, 'domestic' => 0.5, 'crowd' => 0.3],
                'City'          => ['social' => 0.7, 'wealth' => 0.5, 'crowd' => 0.8],
                'Hold'          => [],
                'Farm'          => ['domestic' => 0.7, 'nature' => 0.6],
                'Mine'          => ['crafting' => 0.5, 'wealth' => 0.4, 'confined' => 0.6, 'dark' => 0.4],
                'Jail'          => ['confined' => 1.0, 'danger' => 0.3],
                'Ship'          => ['adventure' => 0.5, 'confined' => 0.4],
                'House'         => ['domestic' => 0.9, 'quiet' => 0.5, 'confined' => 0.3],
                'Store'         => ['wealth' => 0.7, 'social' => 0.4, 'crafting' => 0.2],
                'Guild'         => ['social' => 0.5],
                'Temple'        => ['spiritual' => 1.0, 'sacred' => 1.0, 'quiet' => 0.7],
                'Castle'        => ['wealth' => 0.6, 'social' => 0.5, 'luxury' => 0.6, 'crowd' => 0.3],
                'Nordic Ruin'   => ['adventure' => 0.8, 'combat' => 0.5, 'danger' => 0.7, 'spiritual' => 0.3, 'scholarly' => 0.2, 'confined' => 0.6, 'dark' => 0.5],
                'Dwelling'      => ['domestic' => 0.6, 'confined' => 0.2],
                'Bandit Camp'   => ['combat' => 0.8, 'danger' => 0.8, 'adventure' => 0.4, 'wild' => 0.5],
                'Dragon Lair'   => ['combat' => 1.0, 'danger' => 1.0, 'adventure' => 0.7, 'wild' => 0.6],
                'Falmer Hive'   => ['combat' => 0.7, 'adventure' => 0.6, 'danger' => 0.9, 'confined' => 0.7, 'dark' => 0.8],
                'Dwarven Ruin'  => ['scholarly' => 0.6, 'crafting' => 0.4, 'enchanting' => 0.3, 'adventure' => 0.8, 'combat' => 0.6, 'danger' => 0.7, 'confined' => 0.6],
                'Settlement'    => ['domestic' => 0.5, 'social' => 0.4],
                'Lumber Mill'   => ['crafting' => 0.6, 'nature' => 0.5, 'wild' => 0.3],
                'Habitation'    => ['social' => 0.4, 'domestic' => 0.4],
                'Draugr Crypt'  => ['combat' => 0.6, 'adventure' => 0.6, 'spiritual' => 0.4, 'danger' => 0.7, 'confined' => 0.7, 'dark' => 0.7],
                'Vampire Lair'  => ['combat' => 0.7, 'danger' => 0.9, 'confined' => 0.6, 'dark' => 0.8],
                'Warlock Lair'  => ['enchanting' => 0.6, 'combat' => 0.6, 'scholarly' => 0.3, 'danger' => 0.8, 'confined' => 0.5],
                'Military Fort' => ['combat' => 0.8, 'danger' => 0.5, 'confined' => 0.3],
                'Military Camp' => ['combat' => 0.7, 'social' => 0.2, 'wild' => 0.4],
                'Werewolf Lair' => ['combat' => 0.7, 'nature' => 0.5, 'danger' => 0.8, 'wild' => 0.7],
                'Forsworn Camp' => ['combat' => 0.8, 'nature' => 0.4, 'spiritual' => 0.2, 'danger' => 0.8, 'wild' => 0.6],
                'Giant Camp'    => ['combat' => 0.4, 'nature' => 0.6, 'danger' => 0.7, 'wild' => 0.8],
                'Animal Den'    => ['nature' => 0.7, 'combat' => 0.3, 'danger' => 0.5, 'wild' => 0.8],
                'Cemetery'      => ['spiritual' => 0.7, 'sacred' => 0.6, 'quiet' => 0.8],
                'Shipwreck'     => ['adventure' => 0.7, 'wealth' => 0.3, 'danger' => 0.3],
                'Player House'  => ['domestic' => 1.0, 'luxury' => 0.3, 'quiet' => 0.5],
            ],
            // Interior cells carry their own names (the Arcanaeum inside the College), so
            // names matter as much as tags. A string value reuses that tag's vector.
            'name_keywords' => [
                'arcanaeum' => $library, 'library' => $library, 'archive' => $library,
                'college' => ['scholarly' => 0.8, 'enchanting' => 0.6, 'social' => 0.2],
                'museum' => ['scholarly' => 0.7, 'wealth' => 0.3, 'quiet' => 0.5],
                'temple' => 'Temple', 'shrine' => $shrine, 'chapel' => $shrine, 'sanctuary' => $shrine,
                'hall of the dead' => ['spiritual' => 0.8, 'sacred' => 0.8, 'quiet' => 0.9],
                'catacombs' => 'Draugr Crypt', 'crypt' => 'Draugr Crypt', 'tomb' => 'Draugr Crypt',
                'barrow' => 'Nordic Ruin',
                'forge' => ['crafting' => 1.0], 'smithy' => ['crafting' => 0.9], 'blacksmith' => ['crafting' => 0.9],
                'warmaiden' => ['crafting' => 0.9],
                'apothecary' => ['alchemy' => 0.9], 'alchemist' => ['alchemy' => 0.9], 'cauldron' => ['alchemy' => 0.9],
                'white phial' => ['alchemy' => 0.9], 'aromatics' => ['alchemy' => 0.9],
                'inn' => $inn, 'tavern' => $inn, 'pub' => $inn, 'mead' => $inn, 'mare' => $inn, 'hearth' => $inn,
                'skeever' => $inn,
                'jorrvaskr' => ['combat' => 0.8, 'social' => 0.7],
                'palace' => ['wealth' => 0.7, 'luxury' => 0.8, 'social' => 0.5],
                'dragonsreach' => ['wealth' => 0.6, 'luxury' => 0.6, 'social' => 0.5],
                'market' => ['wealth' => 0.7, 'social' => 0.6, 'crowd' => 0.7],
                'farm' => 'Farm', 'mill' => 'Lumber Mill', 'mine' => 'Mine',
                'stables' => ['nature' => 0.3, 'domestic' => 0.3],
                'camp' => ['wild' => 0.5, 'adventure' => 0.3],
                'forest' => $forest, 'woods' => $forest, 'grove' => $forest, 'glade' => $forest,
                'marsh' => $forest, 'swamp' => $forest, 'hot springs' => $forest,
                'blackreach' => 'Dwarven Ruin', 'mzinchaleft' => 'Dwarven Ruin', 'alftand' => 'Dwarven Ruin',
                'mzulft' => 'Dwarven Ruin', 'raldbthar' => 'Dwarven Ruin', 'arkngthamz' => 'Dwarven Ruin',
                'nchuand-zel' => 'Dwarven Ruin', 'kagrenzel' => 'Dwarven Ruin', 'irkngthand' => 'Dwarven Ruin',
                'bthardamz' => 'Dwarven Ruin', 'avanchnzel' => 'Dwarven Ruin', 'nchardak' => 'Dwarven Ruin',
                'dwemer' => 'Dwarven Ruin',
            ],
            'interior' => ['confined' => 0.5],
            'exterior' => ['wild' => 0.3],
            // An exterior place no tag or keyword describes: the open wild.
            'wilderness' => ['nature' => 0.8, 'wild' => 0.8],
            // Outside (or unknown) only: indoors, the hour does not make a room dark.
            'time_of_day' => ['night' => ['dark' => 0.7], 'dusk' => ['dark' => 0.3]],
            // Outside only.
            'weather' => ['fog' => ['dark' => 0.3], 'snow' => ['wild' => 0.4], 'rain' => ['wild' => 0.3]],
            // Game hours [from, to), wrapping past midnight (the April TIME_MODIFIERS hours).
            'time_of_day_hours' => ['dawn' => [5, 8], 'day' => [8, 17], 'dusk' => [17, 20], 'night' => [20, 5]],
            // detectCurrentInterest() names the place's strongest interest facet from this weight.
            'dominant_interest_min' => 0.3,
        ];
    }
}
