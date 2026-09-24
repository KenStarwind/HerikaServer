<?php
/**
 * Relationship Dynamics — Facets (decisions 2026-09-23 §6: facets -> appraisal -> feeling)
 *
 * One model for everything an NPC experiences (place, item, topic, creature, activity):
 *   - a FACET VECTOR: facet => weight 0..1 over the 11 MDD 1.2 interests plus the
 *     situational facets (danger, crowd, wild, confined, dark, sacred, luxury, quiet);
 *   - the NPC's signed PREFERENCES: facet => -1 (hates) .. 0 (indifferent) .. +1 (loves);
 *   - the APPRAISAL: valence = normalised sum(facet x preference), intensity, and the
 *     dominant facet (biggest |contribution|, sign kept) that decides what the NPC *sees*;
 *   - the FELT TEXT: prose for the LLM from the dominant facet, sign and intensity, never
 *     numbers (decisions §3; Jev gets the numbers).
 *
 * Shared API (lane ownership, 2026-09-24 batch):
 *   placeFacets / currentPlaceContext  places lane (CHIM core locations + eventlog)
 *   thingFacets                        classifier lane (Oghma / keyword tables)
 *   preferences / appraise / feltText  appraisal lane
 * All mapping tables live in editable config, not in code.
 */

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/reldyn_facet_classifier.php';

class RelDynFacets
{
    /** MDD 1.2: the 11 interest categories. */
    const INTERESTS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
    ];

    /** Decisions §6: situational facets (what a place or thing is like, not what it is about). */
    const SITUATIONAL = ['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'];

    /** Every facet, interests first. A facet vector is facet => weight 0..1. */
    const FACETS = [
        'combat', 'crafting', 'alchemy', 'enchanting', 'scholarly',
        'nature', 'social', 'domestic', 'adventure', 'spiritual', 'wealth',
        'danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet',
    ];

    /** Worldspace names of the plugin's main Skyrim worldspace (0x3C) in locations.world. */
    const MAIN_WORLDSPACES = ['skyrim', 'tamriel'];

    // =====================================================================
    // PLACE FACETS (places lane; CHIM core locations + eventlog, never MinAI)
    // =====================================================================

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
            if ($gamets <= 0) $gamets = floatval($row['gamets'] ?? 0);
            $ctx = self::placeContextFromLocation((string) $row['data'], $gamets, true);
        } else {
            $hour = $gamets > 0 ? RelationshipDynamics::gameHourOfDay($gamets) : null;
            $ctx['hour'] = $hour === null ? null : round($hour, 2);
            $ctx['time_of_day'] = self::timeOfDay($hour);
        }
        return $ctx;
    }

    /**
     * The place context of one core location string ("(Context location: X ,Hold: Y, ...)",
     * an eventlog data or location column) at game time $gamets: the parsed name, hold,
     * weather and inside/outside, the core `locations` row's tags, the time of day. With
     * $reportedWeather, an empty weather falls back to the newest weather core reported (only
     * right for the place the NPC is in now).
     */
    public static function placeContextFromLocation(string $data, float $gamets, bool $reportedWeather = false): array
    {
        $ctx = ['name' => '', 'raw_name' => '', 'hold' => '', 'region' => '', 'tags' => [], 'factions' => '',
            'is_interior' => null, 'hour' => null, 'time_of_day' => null, 'weather' => [], 'known' => true];
        $parsed = self::parseLocationContext($data);
        $ctx['name'] = $parsed['name'];
        $ctx['raw_name'] = $parsed['raw_name'];
        $ctx['hold'] = $parsed['hold'];
        $ctx['weather'] = $parsed['weather'];
        $ctx['is_interior'] = $parsed['is_interior'];

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

        if (empty($ctx['weather']) && $reportedWeather) {
            $ctx['weather'] = self::lastReportedWeather();
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
     * The strongest interest facet (one of the 11 MDD 1.2 interests; situational facets never
     * count) of a facet vector, for the legacy interest-string callers; null when none is
     * above 0 or reaches $min. Ties go to INTERESTS order.
     */
    public static function dominantInterest(array $facets, float $min = 0.0): ?string
    {
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
                // Homes and shops (most interiors have no locations row: the live table had 9)
                'house' => 'House', 'home' => 'House', 'shack' => 'Dwelling', 'cabin' => 'Dwelling',
                'hut' => 'Dwelling', 'cottage' => 'Dwelling', 'hovel' => 'Dwelling',
                'trader' => 'Store', 'goods' => 'Store', 'shop' => 'Store', 'store' => 'Store', 'outfitter' => 'Store',
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
            // Indoors and nothing else known: faintly enclosed, not a crypt (appraise() reads a
            // vector this thin as weak evidence).
            'interior' => ['confined' => 0.25],
            'exterior' => ['wild' => 0.3],
            // An exterior place no tag or keyword describes: the open wild.
            'wilderness' => ['nature' => 0.8, 'wild' => 0.8],
            // Outside (or unknown) only: indoors, the hour does not make a room dark.
            'time_of_day' => ['night' => ['dark' => 0.7], 'dusk' => ['dark' => 0.3]],
            // Outside only.
            'weather' => ['fog' => ['dark' => 0.3], 'snow' => ['wild' => 0.4], 'rain' => ['wild' => 0.3]],
            // Game hours [from, to), wrapping past midnight (the April TIME_MODIFIERS hours).
            'time_of_day_hours' => ['dawn' => [5, 8], 'day' => [8, 17], 'dusk' => [17, 20], 'night' => [20, 5]],
        ];
    }

    // =====================================================================
    // THING FACETS (classifier lane)
    // =====================================================================

    /**
     * Facet vector of a thing, [] when unknown. Classifier lane. $kind: item|topic|creature|place|activity.
     * Oghma entries come precomputed (embedding + prior, tools/build_oghma_facets.php) or as the
     * live knowledge_class/category/tags prior; everything else from the kind's keyword table
     * (config facet_classifier). See RelDynFacetClassifier::thingFacets().
     */
    public static function thingFacets(string $kind, string $name): array
    {
        return RelDynFacetClassifier::thingFacets($kind, $name);
    }

    // =====================================================================
    // SIGNED PREFERENCES (appraisal lane; decisions §6, replaces MDD 1.2's 0.5-2.0 interests)
    // =====================================================================

    /** Bump when the derivation itself changes, so stored preferences are derived again. */
    const PREFS_VERSION = 1;

    /**
     * Default tables for the preference auto-derivation (config key 'facet_preferences'; a
     * stored config replaces whole tables, tables it leaves out keep these). Every number
     * is a signed preference contribution on the -1 (hates) .. +1 (loves) scale; the sum
     * of all contributions per facet is clamped to -1..+1.
     *
     *   archetype_prefs    class archetype (the temperament auto-generation's class_archetypes
     *                      / faction_archetypes resolve it) => facet => contribution
     *   class_weight       multiplier for the class archetype's row
     *   faction_weight     multiplier for each distinct faction archetype's row
     *   skill_facets       metadata.skills name => facet => contribution at skill level 100;
     *                      a skill counts from skills_min_level (Skyrim skill level 0-100)
     *                      and scales linearly to 1 at level 100
     *   temperament_prefs  MDD 1.3 temperament => facet => contribution
     *   trait_prefs        trait tag (decisions §1) => facet => contribution
     */
    public static function preferenceDefaults(): array
    {
        return [
            'class_weight'   => 1.0,
            'faction_weight' => 0.5,
            'skills_min_level' => 25,
            'archetype_prefs' => [
                'Warrior'   => ['combat' => 0.6, 'crafting' => 0.2, 'adventure' => 0.2, 'danger' => 0.2, 'social' => 0.1,
                                'scholarly' => -0.2, 'quiet' => -0.1],
                'Barbarian' => ['combat' => 0.7, 'adventure' => 0.4, 'nature' => 0.3, 'wild' => 0.3, 'danger' => 0.3,
                                'scholarly' => -0.4, 'luxury' => -0.3, 'confined' => -0.2],
                'Ranger'    => ['nature' => 0.7, 'wild' => 0.5, 'combat' => 0.2, 'adventure' => 0.1, 'danger' => 0.1,
                                'scholarly' => -0.5, 'confined' => -0.4, 'crowd' => -0.3, 'luxury' => -0.2],
                'Mage'      => ['scholarly' => 0.6, 'enchanting' => 0.4, 'alchemy' => 0.2, 'adventure' => 0.2, 'quiet' => 0.3,
                                'crowd' => -0.2, 'wild' => -0.2, 'combat' => -0.1],
                'Thief'     => ['adventure' => 0.5, 'wealth' => 0.5, 'dark' => 0.3, 'social' => 0.2, 'crowd' => 0.1,
                                'sacred' => -0.2, 'spiritual' => -0.1],
                'Assassin'  => ['combat' => 0.5, 'adventure' => 0.3, 'dark' => 0.5, 'quiet' => 0.2, 'crowd' => -0.3, 'sacred' => -0.2],
                'Healer'    => ['alchemy' => 0.5, 'spiritual' => 0.5, 'sacred' => 0.4, 'domestic' => 0.2, 'nature' => 0.2,
                                'quiet' => 0.2, 'combat' => -0.3, 'danger' => -0.3],
                'Noble'     => ['social' => 0.4, 'wealth' => 0.6, 'luxury' => 0.6, 'scholarly' => 0.1,
                                'wild' => -0.4, 'dark' => -0.2, 'danger' => -0.2],
                'Merchant'  => ['wealth' => 0.6, 'social' => 0.5, 'crowd' => 0.3, 'domestic' => 0.2, 'crafting' => 0.1,
                                'danger' => -0.4, 'wild' => -0.3],
                'Guard'     => ['combat' => 0.5, 'social' => 0.1, 'danger' => 0.1, 'domestic' => 0.1],
                'Bard'      => ['social' => 0.7, 'crowd' => 0.5, 'scholarly' => 0.2, 'luxury' => 0.2, 'quiet' => -0.2, 'confined' => -0.1],
            ],
            'skill_facets' => [
                'archery'     => ['combat' => 0.3, 'nature' => 0.3],   // hunting
                'onehanded'   => ['combat' => 0.4],
                'twohanded'   => ['combat' => 0.4],
                'block'       => ['combat' => 0.3],
                'heavyarmor'  => ['combat' => 0.3],
                'lightarmor'  => ['adventure' => 0.2, 'combat' => 0.1],
                'sneak'       => ['adventure' => 0.2, 'dark' => 0.2],
                'lockpicking' => ['adventure' => 0.2, 'wealth' => 0.1],
                'pickpocket'  => ['wealth' => 0.2, 'crowd' => 0.1],
                'speech'      => ['social' => 0.4, 'crowd' => 0.2],
                'smithing'    => ['crafting' => 0.5],
                'alchemy'     => ['alchemy' => 0.5, 'nature' => 0.1],
                'enchanting'  => ['enchanting' => 0.5, 'scholarly' => 0.1],
                'destruction' => ['scholarly' => 0.2, 'combat' => 0.2, 'adventure' => 0.1],
                'conjuration' => ['scholarly' => 0.3, 'dark' => 0.1],
                'alteration'  => ['scholarly' => 0.3, 'adventure' => 0.2],
                'illusion'    => ['scholarly' => 0.2, 'social' => 0.1],
                'restoration' => ['spiritual' => 0.3, 'scholarly' => 0.1],
            ],
            // MDD 1.3 characters, read as where each temperament is at ease or not
            'temperament_prefs' => [
                'Romantic'    => ['social' => 0.2, 'luxury' => 0.2],
                'Anxious'     => ['danger' => -0.4, 'crowd' => -0.2, 'dark' => -0.2, 'confined' => -0.1],
                'Bold'        => ['danger' => 0.3, 'combat' => 0.1, 'adventure' => 0.1],
                'Playful'     => ['social' => 0.3, 'crowd' => 0.2, 'quiet' => -0.2],
                'Humble'      => ['domestic' => 0.2, 'luxury' => -0.1],
                'Nurturing'   => ['domestic' => 0.3, 'social' => 0.1, 'danger' => -0.2],
                'Gentle'      => ['quiet' => 0.2, 'nature' => 0.1, 'danger' => -0.3, 'combat' => -0.2],
                'Jealous'     => ['crowd' => -0.1],
                'Proud'       => ['luxury' => 0.2, 'wealth' => 0.1],
                'Defiant'     => ['danger' => 0.2, 'sacred' => -0.1],
                'Guarded'     => ['crowd' => -0.1, 'quiet' => 0.1, 'danger' => -0.1],
                'Independent' => ['nature' => 0.1, 'wild' => 0.2, 'crowd' => -0.1, 'confined' => -0.1],
                'Stoic'       => ['quiet' => 0.1],
            ],
            'trait_prefs' => [
                'egocentric' => ['luxury' => 0.3, 'wealth' => 0.2],
                'insecure'   => ['crowd' => -0.2],
            ],
        ];
    }

    /** The derivation tables: stored config per table, defaults for the rest. */
    public static function getPreferenceConfig(): array
    {
        $defaults = self::preferenceDefaults();
        $stored = RelationshipDynamics::getConfig()['facet_preferences'] ?? null;
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** A zero preference for every facet, in FACETS order. */
    public static function neutralPreferences(): array
    {
        return array_fill_keys(self::FACETS, 0.0);
    }

    /**
     * Derive signed preferences from a core_npc_master row (as PostgreSQL returns it, jsonb
     * as text; [] when the NPC has none) and the NPC's profile ['temperament', 'traits'].
     * Pure: the same row, profile and config give the same result.
     *
     * @return array ['prefs' => facet => -1..+1 (every facet, FACETS order), 'signals' => string[]]
     */
    public static function derivePreferences(array $row, array $profile, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::getPreferenceConfig();
        $sum = self::neutralPreferences();
        $signals = [];
        $add = function (array $contrib, float $weight, string $signal) use (&$sum, &$signals) {
            if ($weight <= 0) return;
            $used = false;
            foreach ($contrib as $facet => $v) {
                if (!array_key_exists($facet, $sum) || !is_numeric($v)) continue;
                $sum[$facet] += floatval($v) * $weight;
                $used = true;
            }
            if ($used) $signals[] = $weight == 1.0 ? $signal : sprintf('%s x%.2f', $signal, $weight);
        };

        $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
        $meta = RelationshipDynamics::decodeProfileJson($row['metadata'] ?? null);
        [$classArch, $factionArchs] = RelationshipDynamics::profileArchetypes($ext, $meta, RelationshipDynamics::getTemperamentAutogenConfig());
        $archPrefs = (array) ($cfg['archetype_prefs'] ?? []);
        if ($classArch !== null) {
            $add((array) ($archPrefs[$classArch] ?? []), floatval($cfg['class_weight'] ?? 1.0), "class:{$classArch}");
        }
        foreach ($factionArchs as $fa) {
            if ($fa === $classArch) continue;   // the class already speaks for this archetype
            $add((array) ($archPrefs[$fa] ?? []), floatval($cfg['faction_weight'] ?? 0.5), "faction:{$fa}");
        }

        // Skill levels are Skyrim skill levels (0-100) sent as strings.
        $skills = array_change_key_case((array) ($meta['skills'] ?? []), CASE_LOWER);
        $minLevel = floatval($cfg['skills_min_level'] ?? 25);
        foreach ((array) ($cfg['skill_facets'] ?? []) as $skill => $contrib) {
            $level = floatval($skills[strtolower((string) $skill)] ?? 0);
            if ($level <= $minLevel || $minLevel >= 100) continue;
            $add((array) $contrib, min(1.0, ($level - $minLevel) / (100.0 - $minLevel)), "skill:{$skill}");
        }

        $temperament = RelationshipDynamics::validTemperament($profile['temperament'] ?? null);
        if ($temperament !== null) {
            $add((array) (((array) ($cfg['temperament_prefs'] ?? []))[$temperament] ?? []), 1.0, "temperament:{$temperament}");
        }
        foreach ((array) ($profile['traits'] ?? []) as $trait) {
            $trait = strtolower(trim((string) $trait));
            $add((array) (((array) ($cfg['trait_prefs'] ?? []))[$trait] ?? []), 1.0, "trait:{$trait}");
        }

        foreach ($sum as $facet => $v) {
            $sum[$facet] = round(max(-1.0, min(1.0, $v)), 3);
        }
        return ['prefs' => $sum, 'signals' => $signals];
    }

    /** What the stored derivation depends on besides the core row: profile and config. */
    private static function preferenceBasis(array $dynamics, array $cfg): string
    {
        return md5(json_encode([
            self::PREFS_VERSION,
            RelationshipDynamics::validTemperament($dynamics['inferred_temperament'] ?? null),
            RelationshipDynamics::getTraits($dynamics),
            $cfg,
        ]));
    }

    /** The profile the derivation reads from RelDyn state. */
    private static function preferenceProfile(array $dynamics): array
    {
        return ['temperament' => $dynamics['inferred_temperament'] ?? null, 'traits' => RelationshipDynamics::getTraits($dynamics)];
    }

    /**
     * Derive the NPC's automatic preferences and store them in $dynamics['_facet_prefs']
     * (version, basis, prefs, signals) when missing or out of date (temperament, traits or
     * config changed). Returns true when it changed $dynamics. A failed core read is logged
     * and nothing is stored, so the next call tries again.
     */
    public static function ensurePreferences(string $npcName, array &$dynamics): bool
    {
        $cfg = self::getPreferenceConfig();
        $basis = self::preferenceBasis($dynamics, $cfg);
        if (($dynamics['_facet_prefs']['basis'] ?? null) === $basis && is_array($dynamics['_facet_prefs']['prefs'] ?? null)) {
            return false;
        }
        try {
            $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
        } catch (Throwable $e) {
            error_log("[RelDyn] facet preferences: core_npc_master read failed for {$npcName}: " . $e->getMessage());
            return false;
        }
        $d = self::derivePreferences($row, self::preferenceProfile($dynamics), $cfg);
        $dynamics['_facet_prefs'] = ['version' => self::PREFS_VERSION, 'basis' => $basis, 'prefs' => $d['prefs'], 'signals' => $d['signals']];
        RelationshipDynamics::log("Facet preferences for {$npcName}: [" . implode(' ', $d['signals']) . ']');
        return true;
    }

    /**
     * The NPC's signed preferences, facet => -1..+1: auto-derived from class, skills,
     * temperament and traits, with the per-NPC override on top. Appraisal lane.
     *
     * Uses the stored derivation when it is current (ensurePreferences), else derives now
     * (one core_npc_master read; a failed read is logged and the derivation uses the
     * profile alone). Overrides: $dynamics['facet_pref_overrides'] facet => -1..+1.
     */
    public static function preferences(array $dynamics, string $npcName): array
    {
        $cfg = self::getPreferenceConfig();
        $stored = $dynamics['_facet_prefs'] ?? null;
        if (is_array($stored) && ($stored['basis'] ?? null) === self::preferenceBasis($dynamics, $cfg) && is_array($stored['prefs'] ?? null)) {
            $prefs = array_replace(self::neutralPreferences(), array_intersect_key(array_map('floatval', $stored['prefs']), self::neutralPreferences()));
        } else {
            $row = [];
            try {
                $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
            } catch (Throwable $e) {
                error_log("[RelDyn] facet preferences: core_npc_master read failed for {$npcName}, deriving from the profile only: " . $e->getMessage());
            }
            $prefs = self::derivePreferences($row, self::preferenceProfile($dynamics), $cfg)['prefs'];
        }
        foreach ((array) ($dynamics['facet_pref_overrides'] ?? []) as $facet => $v) {
            if (array_key_exists($facet, $prefs) && is_numeric($v)) {
                $prefs[$facet] = max(-1.0, min(1.0, floatval($v)));
            }
        }
        return $prefs;
    }

    /**
     * Set (or with null, clear) one per-NPC preference override. Returns false and changes
     * nothing for an unknown facet or a value outside -1..+1.
     */
    public static function setPreferenceOverride(array &$dynamics, string $facet, ?float $value): bool
    {
        if (!in_array($facet, self::FACETS, true)) return false;
        if ($value !== null && ($value < -1.0 || $value > 1.0 || is_nan($value))) return false;
        $overrides = (array) ($dynamics['facet_pref_overrides'] ?? []);
        if ($value === null) unset($overrides[$facet]); else $overrides[$facet] = $value;
        $dynamics['facet_pref_overrides'] = $overrides;
        return true;
    }

    /** The NPC editor's interest sliders: range 0.5..2.0, step 0.1 (npc_editor_section.php). */
    const INTEREST_SLIDER_STEP = 0.1;

    /**
     * The NPC editor's interest sliders (MDD 1.2 multipliers) as per-NPC overrides, for the
     * sliders that were actually moved: a posted value equal, at the slider's step, to what the
     * slider showed (the current effective multiplier) is no edit and leaves the facet as it
     * is (derived, or its existing override). Returns the interests it overrode.
     */
    public static function applyInterestSliders(array &$dynamics, string $npcName, array $interests): array
    {
        $prefs = self::preferences($dynamics, $npcName);
        $snap = fn(float $m) => round(max(0.5, min(2.0, $m)) / self::INTEREST_SLIDER_STEP);
        $changed = [];
        foreach ($interests as $interest => $mult) {
            if (!in_array($interest, self::INTERESTS, true) || !is_numeric($mult)) continue;
            if ($snap(floatval($mult)) === $snap(self::interestMultiplier(floatval($prefs[$interest] ?? 0.0)))) continue;
            if (self::setPreferenceOverride($dynamics, $interest, self::preferenceFromInterestMultiplier(floatval($mult)))) {
                $changed[] = $interest;
            }
        }
        return $changed;
    }

    /**
     * Drop the per-NPC overrides of the 11 interests (the editor's Auto-Generate), so they
     * follow the derivation again; situational overrides stay. Returns the interests cleared.
     */
    public static function clearInterestOverrides(array &$dynamics): array
    {
        $overrides = (array) ($dynamics['facet_pref_overrides'] ?? []);
        $cleared = array_values(array_intersect(self::INTERESTS, array_keys($overrides)));
        foreach ($cleared as $interest) {
            unset($overrides[$interest]);
        }
        $dynamics['facet_pref_overrides'] = $overrides;
        return $cleared;
    }

    /**
     * MDD 1.2 interest multiplier (0.5x .. 2.0x, 1.0 = indifferent) from a signed preference
     * or appraisal valence p (-1..+1). The documented mapping, piecewise linear:
     *   p >= 0: 1 + p        (+1 -> 2.0x, +0.5 -> 1.5x)
     *   p <  0: 1 + p / 2    (-1 -> 0.5x, -0.6 -> 0.7x)
     */
    public static function interestMultiplier(float $p): float
    {
        $p = max(-1.0, min(1.0, $p));
        return $p >= 0 ? 1.0 + $p : 1.0 + $p / 2.0;
    }

    /** The inverse of interestMultiplier(): an MDD 1.2 multiplier (0.5..2.0) as a signed preference. */
    public static function preferenceFromInterestMultiplier(float $m): float
    {
        $m = max(0.5, min(2.0, $m));
        return $m >= 1.0 ? $m - 1.0 : 2.0 * ($m - 1.0);
    }

    /**
     * Appraise a facet vector against preferences. Appraisal lane.
     *
     * @return array ['valence' => -1..1, 'intensity' => 0..1, 'dominant' => facet|null,
     *                'dominant_sign' => 1|-1, 'contributions' => facet => number]
     */
    public static function appraise(array $prefs, array $facets): array
    {
        // contribution = facet weight (clamped 0..1) x preference (clamped -1..+1)
        // valence   = sum(contributions) / D   -> -1..+1 (weighted mean preference)
        // intensity = sum(|contributions|) / D ->  0..1  (how strongly it is felt:
        //             a place that is both loved and hated is intense even when valence is ~0)
        //   D = max(sum(facet weights), appraise_min_weight): a thin vector is thin evidence (a
        //       bare interior {confined .3} is not a full-strength read of the confined preference);
        //       a vector with at least that much total weight is its weighted mean as before
        // dominant  = facet with the biggest |contribution|, ties to the earlier FACETS entry
        $contributions = [];
        $weight = 0.0;
        $sum = 0.0;
        $abs = 0.0;
        $dominant = null;
        $best = 0.0;
        foreach (self::FACETS as $facet) {
            if (!isset($facets[$facet]) || !is_numeric($facets[$facet])) continue;
            $w = max(0.0, min(1.0, floatval($facets[$facet])));
            if ($w <= 0.0) continue;
            $p = max(-1.0, min(1.0, floatval($prefs[$facet] ?? 0.0)));
            $c = $w * $p;
            $contributions[$facet] = $c;
            $weight += $w;
            $sum += $c;
            $abs += abs($c);
            if (abs($c) > $best + 1e-12) {
                $best = abs($c);
                $dominant = $facet;
            }
        }
        if ($weight <= 0.0) {
            return ['valence' => 0.0, 'intensity' => 0.0, 'dominant' => null, 'dominant_sign' => 1, 'contributions' => []];
        }
        $divisor = max($weight, floatval(self::getAppraisalConfig()['appraise_min_weight']));
        return [
            'valence'       => max(-1.0, min(1.0, $sum / $divisor)),
            'intensity'     => min(1.0, $abs / $divisor),
            'dominant'      => $dominant,
            'dominant_sign' => ($dominant !== null && $contributions[$dominant] < 0) ? -1 : 1,
            'contributions' => $contributions,
        ];
    }

    /**
     * Prose for the LLM (a feeling, never numbers) from the appraisal's dominant facet,
     * sign and intensity; null when there is nothing worth saying. Appraisal lane.
     *
     * @param string $kind item|topic|creature|place|activity
     */
    public static function feltText(string $npcName, array $appraisal, string $kind, string $name): ?string
    {
        $cfg = self::getAppraisalConfig();
        $dominant = $appraisal['dominant'] ?? null;
        if (!is_string($dominant) || !in_array($dominant, self::FACETS, true)) return null;
        if (floatval($appraisal['intensity'] ?? 0) < floatval($cfg['felt_min_intensity'])) return null;
        $strength = abs(floatval($appraisal['contributions'][$dominant] ?? 0));
        if ($strength < floatval($cfg['felt_min_contribution'])) return null;

        $sign = intval($appraisal['dominant_sign'] ?? 1) < 0 ? '-' : '+';
        $felt = (array) ($cfg['felt_text'] ?? []);
        if ($kind === 'place') {
            $band = $strength >= floatval($cfg['felt_strong_at']) ? 'strong' : 'mild';
            $text = $felt['place'][$dominant][$sign][$band] ?? null;
        } else {
            // A kind-specific table (e.g. 'item') when config has one, else the generic 'thing' table.
            $text = $felt[$kind][$dominant][$sign] ?? $felt['thing'][$dominant][$sign] ?? null;
        }
        if (!is_string($text) || trim($text) === '') return null;
        $thing = trim($name) !== '' ? trim($name) : ($kind === 'place' ? 'this place' : 'it');
        return str_replace(['{NAME}', '{THING}'], [$npcName, $thing], $text);
    }

    // =====================================================================
    // APPRAISAL CONFIG (appraisal lane)
    // =====================================================================

    /**
     * Default appraisal settings (config key 'facet_appraisal'). A stored config replaces
     * whole settings/tables, except felt_text, which is merged per line so editing one
     * wording keeps the rest.
     *
     * Felt read (decisions §3: a feeling, never numbers):
     *   felt_min_intensity     appraisal intensity (0..1) below which nothing is said
     *   felt_min_contribution  |dominant contribution| (facet weight x preference, 0..1)
     *                          below which nothing stands out
     *   felt_strong_at         |dominant contribution| from which a place gets the strong wording
     *   felt_text              place: facet => sign => mild|strong; thing (items, topics,
     *                          creatures, activities): facet => sign. {NAME} = the NPC,
     *                          {THING} = the place or thing's name.
     *
     * Units: valence -1..+1 (appraise()); comfort 0..100 and mood (dimension 'valence')
     * -100..100 dimension points, raw through applyDelta (rubber band, resistance);
     * discomfort 0..100 points; weather pressure -1..+1; game hours / game days are the game
     * calendar (raw gamets, GAMETS_PER_DAY); play minutes are the filtered play clock.
     */
    public static function appraisalDefaults(): array
    {
        return [
            // total facet weight (sum of 0..1 weights) below which a facet vector is thin
            // evidence: appraise() divides by this instead of the vector's own total
            'appraise_min_weight'   => 1.0,
            'felt_min_intensity'    => 0.1,
            'felt_min_contribution' => 0.15,
            'felt_strong_at'        => 0.45,
            'felt_text' => [
                'place' => self::PLACE_FELT_TEXT,
                'thing' => self::THING_FELT_TEXT,
            ],

            // --- time spent in a place (placeTurn), on the game calendar ---
            // Every place effect below counts the game hours since the previous turn in the SAME
            // place (at most exposure_max_gap_game_hours of them), never the lines spoken: thirty
            // lines in a minute are a minute (review 2026-09-24).
            'exposure_max_gap_game_hours' => 3.0, // longest gap between two turns counted as time spent there
            'nudge_deadband'       => 0.05,   // |valence| below this nudges nothing
            'comfort_per_game_hour' => 1.0,   // raw comfort points per game hour at valence +-1
            'mood_per_game_hour'    => 3.0,   // raw mood (valence dimension) points per game hour at valence +-1
            // A place read older than this (game hours) no longer counts: not for passion, not for the felt read.
            'place_appraisal_max_age_game_hours' => 2.0,

            // --- sustained exposure to a hated place (game calendar) ---
            'discomfort_valence_below'        => -0.15, // a place appraised below this valence is hated
            'discomfort_per_game_hour'        => 8.0,   // discomfort points per game hour at valence -1 (x |valence|)
            'discomfort_relief_per_game_hour' => 15.0,  // discomfort points shed per game hour anywhere not hated
            'discomfort_drain_at'             => 30.0,  // discomfort points from which comfort drains
            'discomfort_comfort_per_game_hour' => 2.0,  // raw comfort points drained per game hour at discomfort 100 (linear)
            'discomfort_felt_at'              => 50.0,  // discomfort points from which the felt read says it is wearing on them
            'discomfort_text' => "{NAME} has been in {THING} too long now; it is wearing on them and their patience is thinning.",

            // --- shared-activity passion (MDD 1.2 interests 0.5x-2.0x, MDD 1.5 bad date) ---
            // Interest multiplier = interestMultiplier(activity valence), tempered per love
            // language: effective = 1 + (raw - 1) x weight. The activity is the gift for gifts
            // when its facets are known, else the place being shared.
            'll_interest_weight' => [
                RelationshipDynamics::LL_TIME    => 1.0,
                RelationshipDynamics::LL_GIFTS   => 0.8,
                RelationshipDynamics::LL_SERVICE => 0.6,
                RelationshipDynamics::LL_WORDS   => 0.4,
                RelationshipDynamics::LL_TOUCH   => 0.15,
                'default'                        => 0.5,   // a passion gain with no love-language tag
            ],
            'bad_date_valence' => -0.2,   // place valence at or below this is a bad date
            'bad_date_mult'    => 0.7,    // MDD 1.5

            // --- ambient presence (MDD 1.5 Points of Interest) ---
            'poi_valence_min'          => 0.3,   // place valence from which the place is a Point of Interest
            'poi_passion_floor'        => 15.0,  // passion points (0..100) held while there; in-contact decay halts
            'poi_rise_per_play_minute' => 0.5,   // passion points per filtered play minute while below the floor

            // --- internal weather (MDD 4.1; decisions §6: fed both ways) ---
            // Pressure fed per game hour spent in a place at valence +-1 (placeTurn). With the
            // 12 h half-life a place held all day settles near valence x 0.05 x 17.3: half a day in
            // the woods makes Aela sunny, half a day in the Arcanaeum overcast.
            'weather_feed_per_game_hour'            => 0.05,
            'weather_feed_per_experience'           => 0.08, // pressure per discrete experience (a fight) at valence +-1
            'weather_pressure_half_life_game_hours' => 12.0, // pressure relaxes toward 0 on the game calendar
            'weather_loved_at'                      => 0.5,  // preference from which a facet can be deprived
            'weather_fed_min_weight'                => 0.3,  // facet weight from which an experience feeds a loved facet
            'deprivation_grace_game_days'           => 1.0,
            'deprivation_full_game_days'            => 3.0,  // MDD 4.1: Aela 3 days without combat -> withdrawal
            'deprivation_weight'                    => 0.6,  // score points at full deprivation
            'presence_scan_max_places'              => 50,   // distinct places read per catch-up (catchUpPresence)
            // (intimacy deprivation: config intimacy_need, reldyn_intimacy.php)
            'weather_roll_amplitude'                => 0.2,  // the daily roll: +- this, fixed per NPC and game day
            // score = pressure + roll - deprivation x weight; weather = first threshold the score reaches
            'weather_thresholds' => ['sunny' => 0.3, 'clear' => -0.1, 'overcast' => -0.45],   // below: stormy
            // Emotional gravity (MDD 4.1, a constant pull): raw dimension points x
            // weather_modifier_per_game_hour, per game hour since the last pull (at most
            // exposure_max_gap_game_hours of them), never per request
            'weather_modifiers' => [
                'sunny'    => ['comfort' => 3, 'warmth' => 2, 'valence' => 5],
                'clear'    => [],
                'overcast' => ['comfort' => -2, 'passion' => -1, 'valence' => -5],
                'stormy'   => ['comfort' => -5, 'warmth' => -3, 'valence' => -10, 'arousal' => 5],
            ],
            'weather_modifier_per_game_hour' => 0.1,
            // Activities RelDyn itself sees (combat events) when thingFacets('activity', ...) knows nothing
            'event_facets' => [
                'combat' => ['combat' => 1.0, 'danger' => 0.7, 'adventure' => 0.3],
            ],
        ];
    }

    /** The appraisal settings: stored config per setting/table (felt_text per line), defaults for the rest. */
    public static function getAppraisalConfig(): array
    {
        $defaults = self::appraisalDefaults();
        $stored = RelationshipDynamics::getConfig()['facet_appraisal'] ?? null;
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        if (is_array($stored['felt_text'] ?? null)) {
            $cfg['felt_text'] = array_replace_recursive($defaults['felt_text'], $stored['felt_text']);
        }
        return $cfg;
    }

    // =====================================================================
    // APPRAISAL EFFECTS (appraisal lane; decisions §6 "Effects", MDD 1.2, 1.5, 4.1)
    // =====================================================================

    private static function gameHours(float $gamets): float
    {
        return $gamets / (RelationshipDynamics::GAMETS_PER_DAY / 24.0);
    }

    /**
     * One turn of the NPC in a place, after CHIM core has set its caches (context hook).
     * Appraises the place, stores the read (_place_appraisal: numbers for Jev and for the
     * passion multiplier, never for the LLM) and applies its effects for the game hours spent
     * there since the previous turn in the same place (exposureHours; a first turn in a place
     * is arrival, 0 hours), never per line spoken:
     *   - comfort and mood nudged by valence x hours (dimension engine);
     *   - discomfort built up on the game calendar while the place is hated, relieved elsewhere;
     *   - internal weather pressure fed by valence x hours, loved facets marked as fed (deprivation);
     *   - the Point-of-Interest passion floor while the place is loved (ambient presence).
     *
     * @param array $facets place facet vector (placeFacets)
     * @param array $prefs  the NPC's preferences (preferences())
     * @param float $now    raw game timestamp (currentGamets()); <= 0 = clock unknown
     * @return array ['appraisal' => appraise() result, 'comfort' => change, 'mood' => change,
     *                'discomfort' => points, 'pressure' => weather pressure, 'poi_floor' => ?float]
     */
    public static function placeTurn(string $npcName, array &$dynamics, string $placeName, array $facets, array $prefs, float $now): array
    {
        $cfg = self::getAppraisalConfig();
        $appraisal = self::appraise($prefs, $facets);
        $v = $appraisal['valence'];
        $out = ['appraisal' => $appraisal, 'comfort' => 0.0, 'mood' => 0.0, 'discomfort' => 0.0, 'pressure' => 0.0, 'poi_floor' => null];
        $hours = self::exposureHours($dynamics['_place_appraisal'] ?? null, $placeName, $now, $cfg);

        $dynamics['_place_appraisal'] = [
            'place' => $placeName, 'gamets' => $now,
            'valence' => $v, 'intensity' => $appraisal['intensity'],
            'dominant' => $appraisal['dominant'], 'dominant_sign' => $appraisal['dominant_sign'],
            'contributions' => $appraisal['contributions'],
        ];

        $temperament = $dynamics['inferred_temperament'] ?? null;
        $dimensionsOn = (bool) RelationshipDynamics::configValue('dimension_engine_enabled');
        if ($dimensionsOn && $hours > 0 && abs($v) >= floatval($cfg['nudge_deadband'])) {
            $out['comfort'] += RelationshipDynamics::applyDelta('comfort', $dynamics, $v * floatval($cfg['comfort_per_game_hour']) * $hours, $temperament);
            $out['mood'] = RelationshipDynamics::applyDelta('valence', $dynamics, $v * floatval($cfg['mood_per_game_hour']) * $hours, $temperament);
        }

        $out['discomfort'] = self::updateDiscomfort($dynamics, $placeName, $v, $now, $cfg);
        if ($dimensionsOn && $hours > 0 && $out['discomfort'] >= floatval($cfg['discomfort_drain_at'])) {
            $drain = -floatval($cfg['discomfort_comfort_per_game_hour']) * $out['discomfort'] / 100.0 * $hours;
            $out['comfort'] += RelationshipDynamics::applyDelta('comfort', $dynamics, $drain, $temperament);
        }

        if (RelationshipDynamics::configValue('internal_weather_enabled')) {
            self::markFed($dynamics, $facets, $prefs, $now, $cfg);
            $out['pressure'] = self::feedWeather($dynamics, $v * floatval($cfg['weather_feed_per_game_hour']) * $hours, $now, $cfg);
        }
        // Time in a place together covers the NPC's facet needs (rulings §9 fulfillment).
        if ($hours > 0) {
            RelDynFulfillment::recordFacets($dynamics, $facets,
                floatval(RelDynFulfillment::config()['place_units_per_game_hour']) * $hours, $now);
        }

        if (RelationshipDynamics::configValue('ambient_enabled') && RelationshipDynamics::configValue('passion_enabled')) {
            $out['poi_floor'] = self::holdPoiFloor($dynamics, $v, $cfg);
        } else {
            unset($dynamics['_poi_passion_floor'], $dynamics['_poi_updated_play_gamets']);
        }
        return $out;
    }

    /**
     * Game hours spent in $placeName since the previous place read $previous (the stored
     * _place_appraisal): 0 when that read was elsewhere, missing or not earlier (arrival, or no
     * game time passed), at most exposure_max_gap_game_hours (a long gap is not known to have
     * been spent there).
     */
    private static function exposureHours($previous, string $placeName, float $now, array $cfg): float
    {
        if (!is_array($previous) || ($previous['place'] ?? null) !== $placeName || $now <= 0) return 0.0;
        $last = floatval($previous['gamets'] ?? 0);
        if ($last <= 0 || $now <= $last) return 0.0;
        return min(self::gameHours($now - $last), floatval($cfg['exposure_max_gap_game_hours']));
    }

    /**
     * Discomfort (0..100 points) from sustained exposure to a hated place, on the game
     * calendar: each turn in a hated place adds |valence| x discomfort_per_game_hour per game
     * hour since the last turn (at most exposure_max_gap_game_hours of it); a turn anywhere
     * not hated sheds discomfort_relief_per_game_hour per game hour. Turns without game time
     * passing change nothing, so talking a lot does not count as staying longer.
     */
    private static function updateDiscomfort(array &$dynamics, string $placeName, float $valence, float $now, array $cfg): float
    {
        $state = is_array($dynamics['_place_discomfort'] ?? null) ? $dynamics['_place_discomfort'] : [];
        $points = max(0.0, min(100.0, floatval($state['points'] ?? 0.0)));
        $last = floatval($state['gamets'] ?? 0);
        if ($now <= 0) {
            return $points;   // game clock unknown: no time can be credited
        }
        $hours = ($last > 0 && $now > $last) ? self::gameHours($now - $last) : 0.0;
        $hated = $valence < floatval($cfg['discomfort_valence_below']);
        if ($hated && ($state['place'] ?? null) === $placeName) {
            $hours = min($hours, floatval($cfg['exposure_max_gap_game_hours']));
            $points += abs($valence) * floatval($cfg['discomfort_per_game_hour']) * $hours;
        } elseif (!$hated) {
            $points -= floatval($cfg['discomfort_relief_per_game_hour']) * $hours;
        }
        // A hated place entered from elsewhere starts its exposure now (the time before was spent elsewhere).
        $points = max(0.0, min(100.0, $points));
        $dynamics['_place_discomfort'] = ['place' => $placeName, 'points' => $points, 'gamets' => $now];
        return $points;
    }

    /**
     * Stamp every loved facet an experience touches (weight >= weather_fed_min_weight) as fed
     * at $now; a stamp never moves back in time (the presence catch-up feeds past moments).
     */
    private static function markFed(array &$dynamics, array $facets, array $prefs, float $now, array $cfg): void
    {
        if ($now <= 0) return;
        $fed = is_array($dynamics['_facet_fed'] ?? null) ? $dynamics['_facet_fed'] : [];
        foreach ($facets as $facet => $w) {
            if (!in_array($facet, self::FACETS, true) || !is_numeric($w)) continue;
            if (floatval($w) >= floatval($cfg['weather_fed_min_weight']) && floatval($prefs[$facet] ?? 0) >= floatval($cfg['weather_loved_at'])) {
                $fed[$facet] = max(floatval($fed[$facet] ?? 0), $now);
            }
        }
        $dynamics['_facet_fed'] = $fed;
    }

    /** Weather pressure (-1..+1) as it stands at $now: the stored value relaxed on the game calendar. */
    private static function pressureAt(array $dynamics, float $now, array $cfg): float
    {
        $state = is_array($dynamics['_weather_state'] ?? null) ? $dynamics['_weather_state'] : [];
        $p = max(-1.0, min(1.0, floatval($state['pressure'] ?? 0.0)));
        $last = floatval($state['gamets'] ?? 0);
        $halfLife = floatval($cfg['weather_pressure_half_life_game_hours']);
        if ($now > $last && $last > 0 && $halfLife > 0) {
            $p *= pow(0.5, self::gameHours($now - $last) / $halfLife);
        }
        return $p;
    }

    /**
     * Loved things feed the weather, hated things drain it (decisions §6): $delta pressure
     * (valence x the per-game-hour or per-experience rate, signed) added to the relaxed
     * pressure. Returns the new pressure.
     */
    private static function feedWeather(array &$dynamics, float $delta, float $now, array $cfg): float
    {
        if ($now <= 0) return floatval($dynamics['_weather_state']['pressure'] ?? 0.0);
        $p = max(-1.0, min(1.0, self::pressureAt($dynamics, $now, $cfg) + $delta));
        $state = is_array($dynamics['_weather_state'] ?? null) ? $dynamics['_weather_state'] : [];
        $dynamics['_weather_state'] = array_merge($state, ['pressure' => $p, 'gamets' => $now]);
        return $p;
    }

    /**
     * Something other than a place (an activity, an item) experienced now, a discrete event:
     * appraised, its loved facets marked fed and the weather fed by its valence x
     * weather_feed_per_experience. $facets defaults to
     * thingFacets($kind, $name), then config event_facets for activities RelDyn sees itself.
     */
    public static function experienceThing(string $npcName, array &$dynamics, string $kind, string $name, array $prefs, float $now, ?array $facets = null): array
    {
        $cfg = self::getAppraisalConfig();
        $facets = $facets ?? self::thingFacets($kind, $name);
        if (!$facets && $kind === 'activity') {
            $facets = (array) (((array) ($cfg['event_facets'] ?? []))[strtolower($name)] ?? []);
        }
        $appraisal = self::appraise($prefs, $facets);
        if ($facets && RelationshipDynamics::configValue('internal_weather_enabled')) {
            self::markFed($dynamics, $facets, $prefs, $now, $cfg);
            self::feedWeather($dynamics, $appraisal['valence'] * floatval($cfg['weather_feed_per_experience']), $now, $cfg);
        }
        // An experience shared covers the NPC's facet needs (rulings §9 fulfillment).
        if ($facets) {
            RelDynFulfillment::recordFacets($dynamics, $facets, floatval(RelDynFulfillment::config()['experience_units']), $now);
        }
        return $appraisal;
    }

    /**
     * Where the NPC has been since the last catch-up (MDD 4.1 deprivation counts what she went
     * without, not how long since she was last spoken to): core logs every event with the
     * beings nearby (eventlog.people '|A|B|') and the location context (eventlog.location). Each
     * place she was near the player in since _presence_scan_gamets (at most
     * deprivation_full_game_days back, presence_scan_max_places places) feeds her loved facets
     * at the last time she was there; then the place she is in now (currentPlaceContext) feeds
     * them at $now, before this turn's roll (the context hook's place read runs after the
     * prerequest). '(far away)' does not count as there. Returns the number of places read.
     */
    public static function catchUpPresence(string $npcName, array &$dynamics, array $prefs, float $now): int
    {
        if ($now <= 0 || trim($npcName) === '') return 0;
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return 0;
        $cfg = self::getAppraisalConfig();
        $window = floatval($cfg['deprivation_full_game_days']) * RelationshipDynamics::GAMETS_PER_DAY;
        $since = max(floatval($dynamics['_presence_scan_gamets'] ?? 0), $now - $window);
        $places = 0;
        if ($since < $now) {
            $people = '%|' . RelationshipDynamics::escapeLike(trim($npcName)) . '|%';
            try {
                $rows = $db->fetchAll("SELECT split_part(location, ',', 1) AS head, max(gamets) AS last_seen FROM eventlog"
                    . ' WHERE gamets > ' . intval(floor($since)) . ' AND gamets <= ' . intval(floor($now))
                    . " AND location LIKE '%(Context%' AND people LIKE " . $db->escapeLiteral($people) . " ESCAPE '\\'"
                    . ' GROUP BY 1 ORDER BY 2 DESC LIMIT ' . max(1, intval($cfg['presence_scan_max_places'])));
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("catchUpPresence({$npcName})", $e);
                return 0;   // the scan stamp stays: the next turn tries this stretch again
            }
            foreach (array_reverse((array) $rows) as $r) {
                $at = floatval($r['last_seen'] ?? 0);
                $ctx = self::placeContextFromLocation((string) ($r['head'] ?? '') . ',', $at);
                self::markFed($dynamics, self::placeFacets($ctx), $prefs, $at, $cfg);
                $places++;
            }
        }
        $here = static::currentPlaceContext($npcName);
        if (($here['known'] ?? false) === true) {
            self::markFed($dynamics, self::placeFacets($here), $prefs, $now, $cfg);
            $places++;
        }
        $dynamics['_presence_scan_gamets'] = $now;
        return $places;
    }

    /**
     * Internal weather (MDD 4.1), recomputed from state on every request:
     *   score = pressure (fed both ways, relaxing on the game calendar)
     *         + the daily roll (fixed per NPC and game day, +- weather_roll_amplitude)
     *         - deprivation x deprivation_weight
     * deprivation (0..1) = preference-weighted mean, over loved facets (preference >=
     * weather_loved_at), of how long each went unfed: 0 within deprivation_grace_game_days,
     * 1 from deprivation_full_game_days. A loved facet first seen now starts fed. The
     * relationship's own deprivation (RelDynFulfillment::weatherDeprivation, from the
     * fulfillment band) counts instead when it is the larger. _weather_state keeps both
     * ('deprivation' = loved facets, 'relationship_deprivation').
     * Weather = first of weather_thresholds the score reaches, else 'stormy'. Numbers stay in
     * _weather_state (for Jev and debugging); the LLM only ever gets the weather's feeling.
     */
    public static function updateWeather(string $npcName, array &$dynamics, array $prefs, float $now): string
    {
        $current = is_string($dynamics['_internal_weather'] ?? null) ? $dynamics['_internal_weather'] : 'clear';
        if ($now <= 0) return $current;   // game clock unknown: keep the weather
        $cfg = self::getAppraisalConfig();

        $pressure = self::pressureAt($dynamics, $now, $cfg);

        $fed = is_array($dynamics['_facet_fed'] ?? null) ? $dynamics['_facet_fed'] : [];
        $grace = floatval($cfg['deprivation_grace_game_days']);
        $full = max($grace + 0.001, floatval($cfg['deprivation_full_game_days']));
        $wSum = 0.0;
        $dSum = 0.0;
        foreach (self::FACETS as $facet) {
            $p = floatval($prefs[$facet] ?? 0);
            if ($p < floatval($cfg['weather_loved_at'])) continue;
            $stamp = floatval($fed[$facet] ?? 0);
            if ($stamp <= 0 || $stamp > $now) {
                $fed[$facet] = $stamp = $now;
            }
            $days = ($now - $stamp) / RelationshipDynamics::GAMETS_PER_DAY;
            $dSum += $p * max(0.0, min(1.0, ($days - $grace) / ($full - $grace)));
            $wSum += $p;
        }
        $dynamics['_facet_fed'] = $fed;
        $deprivation = $wSum > 0 ? $dSum / $wSum : 0.0;
        // The relationship's fulfillment band is deprivation too (rulings §9): the larger counts.
        $relDeprivation = RelDynFulfillment::weatherDeprivation($dynamics, $now);
        $feltDeprivation = max($deprivation, $relDeprivation ?? 0.0);

        $day = (int) floor($now / RelationshipDynamics::GAMETS_PER_DAY);
        $unit = crc32(strtolower(trim($npcName)) . '|' . $day) / 4294967295.0;   // 0..1, fixed per NPC and day
        $roll = (2.0 * $unit - 1.0) * floatval($cfg['weather_roll_amplitude']);

        $score = $pressure + $roll - $feltDeprivation * floatval($cfg['deprivation_weight']);
        $weather = 'stormy';
        foreach ((array) $cfg['weather_thresholds'] as $name => $min) {
            if ($score >= floatval($min)) { $weather = (string) $name; break; }
        }

        $dynamics['_weather_state'] = [
            'pressure' => $pressure, 'gamets' => $now, 'day' => $day,
            'roll' => round($roll, 4), 'deprivation' => round($deprivation, 4), 'score' => round($score, 4),
        ];
        if ($relDeprivation !== null) {
            $dynamics['_weather_state']['relationship_deprivation'] = round($relDeprivation, 4);
        }
        $dynamics['_internal_weather'] = $weather;
        if ($weather !== $current) {
            RelationshipDynamics::log("[WEATHER] {$npcName}: {$current} -> {$weather} (pressure=" . round($pressure, 2)
                . ' roll=' . round($roll, 2) . ' deprivation=' . round($deprivation, 2) . ')');
        }
        return $weather;
    }

    /**
     * MDD 1.5 Point of Interest: in a place loved at poi_valence_min or more, passion is held
     * at poi_passion_floor. Passion below it rises toward it on the filtered play clock (no
     * jump, nothing from waiting); decayPassion() halts while the floor holds. Leaving clears it.
     */
    private static function holdPoiFloor(array &$dynamics, float $valence, array $cfg): ?float
    {
        if ($valence < floatval($cfg['poi_valence_min'])) {
            unset($dynamics['_poi_passion_floor'], $dynamics['_poi_updated_play_gamets']);
            return null;
        }
        $floor = max(0.0, floatval($cfg['poi_passion_floor']));
        $dynamics['_poi_passion_floor'] = $floor;
        $since = RelationshipDynamics::playGametsSince($dynamics, '_poi_updated_play_gamets');
        $passion = RelationshipDynamics::getPassion($dynamics);
        if ($since !== null && $passion < $floor) {
            $minutes = $since / (RelationshipDynamics::GAMETS_PER_REAL_SECOND * 60.0);
            RelationshipDynamics::setPassion($dynamics, min($floor, $passion + $minutes * floatval($cfg['poi_rise_per_play_minute'])));
        }
        RelationshipDynamics::markPlayCheckpoint($dynamics, '_poi_updated_play_gamets');
        return $floor;
    }

    /** The place read if it is recent enough to count at $now (place_appraisal_max_age_game_hours), else null. */
    public static function freshPlaceAppraisal(array $dynamics, float $now): ?array
    {
        $pa = $dynamics['_place_appraisal'] ?? null;
        if (!is_array($pa) || $now <= 0) return null;
        $at = floatval($pa['gamets'] ?? 0);
        if ($at <= 0 || $at > $now) return null;
        $maxAge = floatval(self::getAppraisalConfig()['place_appraisal_max_age_game_hours']);
        return self::gameHours($now - $at) <= $maxAge ? $pa : null;
    }

    /** The Point-of-Interest passion floor in force at $now (the place read must be fresh), or null. */
    public static function poiPassionFloor(array $dynamics, float $now): ?float
    {
        if (!isset($dynamics['_poi_passion_floor']) || self::freshPlaceAppraisal($dynamics, $now) === null) return null;
        return floatval($dynamics['_poi_passion_floor']);
    }

    /**
     * Passion multiplier for a shared activity (MDD 1.2 interests 0.5x-2.0x, MDD 1.5 bad date):
     *   interest = interestMultiplier(activity valence), tempered by the love language:
     *              1 + (interest - 1) x ll_interest_weight[LL]
     *   x bad_date_mult when the fresh place read is at or below bad_date_valence.
     * The activity is $activityAppraisal (a gift's appraisal) when given, else the place being
     * shared. No fresh place read and no activity: 1.0.
     */
    public static function sharedActivityPassionMult(array $dynamics, ?string $loveLanguage, ?array $activityAppraisal = null, ?float $now = null): float
    {
        $cfg = self::getAppraisalConfig();
        $place = self::freshPlaceAppraisal($dynamics, $now ?? RelationshipDynamics::currentGamets());
        $activity = $activityAppraisal ?? $place;
        $mult = 1.0;
        if ($activity !== null) {
            $weights = (array) $cfg['ll_interest_weight'];
            $w = floatval($weights[$loveLanguage ?? ''] ?? $weights['default'] ?? 0.5);
            $mult = 1.0 + (self::interestMultiplier(floatval($activity['valence'] ?? 0)) - 1.0) * $w;
        }
        if ($place !== null && floatval($place['valence'] ?? 0) <= floatval($cfg['bad_date_valence'])) {
            $mult *= floatval($cfg['bad_date_mult']);
        }
        return $mult;
    }

    /**
     * The context hook's turn (context.php runs after CHIM core has set CACHE_LOCATION and
     * CACHE_PEOPLE, which the prerequest hooks run before): store the NPC's preferences,
     * read the current place from core (currentPlaceContext / placeFacets), run placeTurn()
     * and return the felt read for this turn's context.
     *
     * A place core knows but has no facets for is appraised as neutral (the NPC has left
     * wherever it was). When core gives no place at all, the last read stays in force until
     * it goes stale (place_appraisal_max_age_game_hours).
     *
     * @return array ['text' => ?string felt read, 'changed' => bool (save $dynamics), 'place' => ?string]
     */
    public static function contextTurn(string $npcName, array &$dynamics, float $now): array
    {
        $changed = self::ensurePreferences($npcName, $dynamics);
        $ctx = static::currentPlaceContext($npcName);
        $place = null;
        // known = false: core has logged no location context at all (not even the wilderness).
        if ($ctx && ($ctx['known'] ?? true) !== false) {
            $place = trim((string) ($ctx['name'] ?? ''));
            $facets = static::placeFacets($ctx);
            $r = self::placeTurn($npcName, $dynamics, $place, $facets, self::preferences($dynamics, $npcName), $now);
            $changed = true;
            $a = $r['appraisal'];
            RelationshipDynamics::log(sprintf('[PLACE] %s @ %s: valence %+.2f intensity %.2f dominant %s%s, comfort %+.2f mood %+.2f, discomfort %.1f, pressure %+.2f%s',
                $npcName, $place, $a['valence'], $a['intensity'], $a['dominant'] ?? '-', $a['dominant'] ? ($a['dominant_sign'] < 0 ? '-' : '+') : '',
                $r['comfort'], $r['mood'], $r['discomfort'], $r['pressure'], $r['poi_floor'] !== null ? ', POI floor' : ''));
        }
        return ['text' => self::placeFeltText($npcName, $dynamics, $now), 'changed' => $changed, 'place' => $place];
    }

    /**
     * The felt read of the current place for the LLM (no numbers): the dominant facet's
     * wording, plus the discomfort line once staying has worn on them. Null when the place
     * read is stale or nothing stands out.
     */
    public static function placeFeltText(string $npcName, array $dynamics, float $now): ?string
    {
        $pa = self::freshPlaceAppraisal($dynamics, $now);
        if ($pa === null) return null;
        $place = (string) ($pa['place'] ?? '');
        $lines = [];
        $felt = self::feltText($npcName, $pa, 'place', $place);
        if ($felt !== null) $lines[] = $felt;
        $cfg = self::getAppraisalConfig();
        $d = $dynamics['_place_discomfort'] ?? null;
        if (is_array($d) && ($d['place'] ?? null) === $place && floatval($d['points'] ?? 0) >= floatval($cfg['discomfort_felt_at'])
            && is_string($cfg['discomfort_text'] ?? null) && trim($cfg['discomfort_text']) !== '') {
            $lines[] = str_replace(['{NAME}', '{THING}'], [$npcName, $place !== '' ? $place : 'this place'], $cfg['discomfort_text']);
        }
        return $lines ? implode(' ', $lines) : null;
    }

    /** Default place wording: facet => '+'|'-' => mild|strong. */
    const PLACE_FELT_TEXT = [
        'combat' => [
            '+' => ['mild'   => "{NAME} walks {THING} like a fighter, noting cover, footing and where a blade would swing.",
                    'strong' => "To {NAME}, every corridor of {THING} reads like a kill zone; a hand keeps drifting to their weapon, eager rather than afraid."],
            '-' => ['mild'   => "{NAME} would rather no blood was spilled here; the violence this place invites sits badly with them.",
                    'strong' => "Everything about {THING} promises a fight and {NAME} wants none of it: tense, reluctant, looking for any way around one."],
        ],
        'crafting' => [
            '+' => ['mild'   => "{NAME} eyes the tools and worked materials here with a maker's quiet interest.",
                    'strong' => "{NAME} can't keep their hands still around the work here; they keep studying how things were made and how they would make them better."],
            '-' => ['mild'   => "The clutter of tools and half-finished work here holds nothing for {NAME}.",
                    'strong' => "The heat, noise and grime of craftwork grate on {NAME}; they would leave the moment they could."],
        ],
        'alchemy' => [
            '+' => ['mild'   => "{NAME} keeps noticing the herbs and reagents here, naming them under their breath.",
                    'strong' => "{NAME} is absorbed by the reagents and apparatus here, sniffing, sorting, already imagining what could be brewed."],
            '-' => ['mild'   => "The sour reek of reagents here wrinkles {NAME}'s nose.",
                    'strong' => "The fumes and bubbling concoctions here unsettle {NAME}; they keep well back from all of it."],
        ],
        'enchanting' => [
            '+' => ['mild'   => "{NAME} feels the faint hum of bound magic here and it keeps pulling at their attention.",
                    'strong' => "The enchantments here sing to {NAME}; they keep reaching toward the glow, wanting to know how it was bound."],
            '-' => ['mild'   => "The hum of bound magic here puts {NAME} slightly on edge.",
                    'strong' => "{NAME} distrusts the magic soaked into this place; the hum of it crawls under their skin."],
        ],
        'scholarly' => [
            '+' => ['mild'   => "{NAME} lingers over the writings and old things here, curious.",
                    'strong' => "{THING} hums with things {NAME} wants to understand; they keep drifting toward the old writings and workings here, eyes bright."],
            '-' => ['mild'   => "{NAME} has little patience for the dusty learning this place is built around.",
                    'strong' => "All this dusty learning makes {NAME} restless; they pace, keep glancing at the door, and want to be anywhere but here."],
        ],
        'nature' => [
            '+' => ['mild'   => "{NAME} breathes easier out here, eyes following tracks and treelines.",
                    'strong' => "{NAME} is at home in {THING}: loose-limbed, alert, more themselves than behind any wall."],
            '-' => ['mild'   => "{NAME} would rather have a roof and a road than all this wild growth.",
                    'strong' => "The raw wilderness wears on {NAME}: mud, insects, cold. They want walls and a fire."],
        ],
        'social' => [
            '+' => ['mild'   => "{NAME} warms to the voices and bustle around them.",
                    'strong' => "{NAME} comes alive among the people here, quick to smile, quick to talk, drinking in the company."],
            '-' => ['mild'   => "The chatter here tires {NAME}; their answers come shorter.",
                    'strong' => "{NAME} scans for the exit; idle talk and pressing company are draining their patience fast."],
        ],
        'domestic' => [
            '+' => ['mild'   => "{NAME} settles into the homely comfort of this place.",
                    'strong' => "The hearth-and-home feel of {THING} softens {NAME}; they look almost at rest."],
            '-' => ['mild'   => "The domestic fuss here doesn't land for {NAME}.",
                    'strong' => "{NAME} feels hemmed in by the chores and small comforts here; it all feels like a cage."],
        ],
        'adventure' => [
            '+' => ['mild'   => "{NAME} is alert and keen, eager to see what lies deeper in.",
                    'strong' => "{NAME} is electric with the thrill of {THING}; every unexplored passage is a promise."],
            '-' => ['mild'   => "{NAME} would rather be somewhere settled than poking into unknown places.",
                    'strong' => "{NAME} hates delving into the unknown like this; every new passage is one more reason to turn back."],
        ],
        'spiritual' => [
            '+' => ['mild'   => "{NAME} grows quieter here, touched by something reverent.",
                    'strong' => "A deep reverence settles over {NAME} in {THING}; they speak softly, as if the place is listening."],
            '-' => ['mild'   => "The devotion and ritual here leave {NAME} cold.",
                    'strong' => "The piety of this place grates on {NAME}; they are impatient with the prayers and the incense."],
        ],
        'wealth' => [
            '+' => ['mild'   => "{NAME}'s eye lingers on the fine and valuable things here.",
                    'strong' => "{NAME} is dazzled by the riches of {THING}: appraising, admiring, a little hungry."],
            '-' => ['mild'   => "The display of wealth here leaves {NAME} unimpressed.",
                    'strong' => "The flaunted riches here offend {NAME}; the whole display feels hollow and grasping."],
        ],
        'danger' => [
            '+' => ['mild'   => "The danger here sharpens {NAME} rather than scaring them.",
                    'strong' => "Danger thrums through {THING} and {NAME} loves it: pulse up, grin tight, fully awake."],
            '-' => ['mild'   => "{NAME} is uneasy, watching for whatever threat this place hides.",
                    'strong' => "{NAME} is frightened here and trying not to show it; every sound makes them flinch."],
        ],
        'crowd' => [
            '+' => ['mild'   => "{NAME} enjoys the press of people here.",
                    'strong' => "{NAME} thrives in the crush of the crowd, buoyed by the noise and bodies."],
            '-' => ['mild'   => "The press of people here makes {NAME} tense.",
                    'strong' => "Too many people, too close: {NAME} is wound tight and edging toward open space."],
        ],
        'wild' => [
            '+' => ['mild'   => "The untamed land here suits {NAME}.",
                    'strong' => "{NAME} feels free out here: no walls, no rules, just open, untamed land."],
            '-' => ['mild'   => "{NAME} is wary of how untamed this place is.",
                    'strong' => "The lawless wild here unnerves {NAME}; they keep close and watch the treeline."],
        ],
        'confined' => [
            '+' => ['mild'   => "{NAME} finds the close walls here reassuring.",
                    'strong' => "{NAME} is comforted by the tight, enclosed space: protected, contained."],
            '-' => ['mild'   => "The walls here feel close to {NAME}.",
                    'strong' => "The walls press in; {NAME} keeps glancing at the door and breathing like there isn't enough air."],
        ],
        'dark' => [
            '+' => ['mild'   => "{NAME} is comfortable in the shadows here.",
                    'strong' => "{NAME} moves through the dark of {THING} like it belongs to them."],
            '-' => ['mild'   => "The darkness here makes {NAME} uneasy.",
                    'strong' => "The dark gets under {NAME}'s skin; they stay close to any light."],
        ],
        'sacred' => [
            '+' => ['mild'   => "{NAME} feels the holiness of this place and honors it.",
                    'strong' => "{NAME} is deeply moved by the sanctity of {THING}; they carry themselves with care."],
            '-' => ['mild'   => "The hallowed air here makes {NAME} uncomfortable.",
                    'strong' => "{NAME} feels judged by the sanctity of this place and wants out."],
        ],
        'luxury' => [
            '+' => ['mild'   => "{NAME} enjoys the comfort and finery here.",
                    'strong' => "{NAME} basks in the luxury of {THING}, savoring every soft and gilded thing."],
            '-' => ['mild'   => "The finery here seems excessive to {NAME}.",
                    'strong' => "The opulence here disgusts {NAME}; the gilt and silk feel like a lie."],
        ],
        'quiet' => [
            '+' => ['mild'   => "The quiet here settles {NAME}.",
                    'strong' => "The deep quiet of {THING} calms {NAME}; their shoulders drop and their voice softens."],
            '-' => ['mild'   => "The silence here makes {NAME} fidgety.",
                    'strong' => "The heavy silence unsettles {NAME}; they fill it with talk or restless movement."],
        ],
    ];

    /** Default wording for items, topics, creatures and activities: facet => '+'|'-'. */
    const THING_FELT_TEXT = [
        'combat'     => ['+' => "{THING} speaks to the fighter in {NAME}; they take a real interest in it.",
                         '-' => "{THING} smells of violence to {NAME}, and it puts them off."],
        'crafting'   => ['+' => "{NAME} appreciates the craft in {THING}, the kind of work they respect.",
                         '-' => "{NAME} cares little for the craftwork side of {THING}."],
        'alchemy'    => ['+' => "{THING} catches {NAME}'s alchemist's curiosity.",
                         '-' => "{NAME} is wary of anything to do with potions and reagents, {THING} included."],
        'enchanting' => ['+' => "{NAME} is fascinated by the magic bound up in {THING}.",
                         '-' => "The enchantment in {THING} makes {NAME} uneasy."],
        'scholarly'  => ['+' => "{THING} is exactly the kind of thing {NAME} wants to understand; they light up.",
                         '-' => "{THING} is too bookish for {NAME}; their attention slides away from it."],
        'nature'     => ['+' => "{THING} carries a breath of the wild that {NAME} loves.",
                         '-' => "{NAME} finds nothing to like in the wild-country side of {THING}."],
        'social'     => ['+' => "{THING} is the kind of thing {NAME} enjoys sharing with people.",
                         '-' => "{THING} feels like idle social chatter to {NAME}, and it bores them."],
        'domestic'   => ['+' => "{THING} has a homely comfort that warms {NAME}.",
                         '-' => "{THING} feels like household drudgery to {NAME}."],
        'adventure'  => ['+' => "{THING} stirs {NAME}'s appetite for adventure.",
                         '-' => "{THING} smacks of reckless adventuring to {NAME}, and they want no part of it."],
        'spiritual'  => ['+' => "{THING} touches something reverent in {NAME}.",
                         '-' => "The piety around {THING} leaves {NAME} cold."],
        'wealth'     => ['+' => "{NAME} appreciates the value of {THING}; their eyes linger on it.",
                         '-' => "{NAME} is unmoved by what {THING} is worth; the fuss seems hollow to them."],
        'danger'     => ['+' => "The danger in {THING} excites {NAME}.",
                         '-' => "{THING} feels dangerous to {NAME}, and they keep their distance."],
        'crowd'      => ['+' => "{THING} makes {NAME} think of good company and busy halls.",
                         '-' => "{THING} reminds {NAME} of crowds, and they bristle."],
        'wild'       => ['+' => "{THING} has the untamed feel {NAME} loves.",
                         '-' => "{THING} feels too untamed for {NAME}'s liking."],
        'confined'   => ['+' => "{THING} has a snug, enclosed feel that comforts {NAME}.",
                         '-' => "{THING} feels cramped and stifling to {NAME}."],
        'dark'       => ['+' => "{NAME} is drawn to the shadowed side of {THING}.",
                         '-' => "The darkness around {THING} unsettles {NAME}."],
        'sacred'     => ['+' => "{NAME} treats {THING} as something holy.",
                         '-' => "The holiness around {THING} makes {NAME} uncomfortable."],
        'luxury'     => ['+' => "{NAME} savors the finery of {THING}.",
                         '-' => "{THING} feels ostentatious to {NAME}."],
        'quiet'      => ['+' => "{THING} has a calm to it that settles {NAME}.",
                         '-' => "{THING} feels too still and hushed for {NAME}."],
    ];
}
