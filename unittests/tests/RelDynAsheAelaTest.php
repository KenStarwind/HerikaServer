<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 * Every statement and every failure is recorded.
 */
final class RelDynAsheAelaPgDb
{
    public $link;
    public array $failures = [];
    public array $statements = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function note(string $q): void
    {
        $this->statements[] = substr(preg_replace('/\s+/', ' ', $q), 0, 300);
    }

    public function fetchOne($q, array $params = [])
    {
        $this->note($q);
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) {
            $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
            return [];
        }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }

    public function execQuery($q)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $this->note("INSERT INTO {$table}");
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Ken's example, end to end (decisions 2026-09-23 §6): "If I take Ashe to a Dwemer ruin or a
 * library she should be more interested or at ease, where Aela the huntress would hate to be
 * in the library, would see the Dwemer ruins as a battlefield and would rather be in the woods."
 *
 * Real PostgreSQL, real hooks (prerequest -> context -> postrequest per turn), CHIM 3.4.1
 * core-shaped rows only:
 *   - core_npc_master rows as the plugin registers them (class, factions, skills; no RelDyn
 *     state): temperament, traits and facet preferences are all auto-derived;
 *   - core `locations` rows with the plugin's location keyword tags, and eventlog infoloc
 *     context strings as the plugin writes them (interior cells mark their weather
 *     "outdoors it is ...");
 *   - the game clock in gameRequest[2].
 * No stubs: no LLM or embedding call is involved (the classifier reads the live Oghma prior).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAsheAelaTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const MINUTE = self::HOUR / 60;
    private const PLAYER = 'Kaida';
    private const ASHE = 'Ashe';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynAsheAelaPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY + 10 * self::HOUR;   // game day 200, 10:00
    /** Every <place_feeling> the context hook gave the LLM: [npc, place, text]. */
    private array $felt = [];
    /** [PLACE] log lines seen so far per NPC. */
    private array $placeReads = [self::ASHE => 0, self::AELA => 0];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_asheaela' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // lib/core/database_schema/core_npc_master.sql / core_npc_master_history.sql columns.
        $columns = "npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text";
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, {$columns})");
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(), " . str_replace('npc_name text NOT NULL', 'npc_name text', $columns) . ")");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        // data/database_default.sql eventlog / responselog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // Core 3.4.1 locations (debug/db_updates.php columns), filled as the player visits places.
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // Core 3.4.1 oghma text columns (vector384 needs pgvector and is empty on the live install).
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_close($admin);

        $this->db = new RelDynAsheAelaPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdasheaela');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_ashe_aela_test.log');

        // Shipped defaults, stored the way the config page stores them; RelDyn's log on (it
        // changes nothing but lets the test count what each turn applied).
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();

        $this->seedNpcs();
        $this->seedPlaces();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    // ------------------------------------------------------------------ fixture

    /** A core_npc_master row as CHIM 3.4.1 registers an NPC (processor/comm.php addnpc): no RelDyn state. */
    private function npc(string $name, string $refid, string $race, string $class, array $factions, array $skills): void
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $faction) {
            $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
        }
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb)',
            [$name, $refid, 'female', $race, 'FemaleEvenToned', "Roleplay as {$name}", '',
             json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
             json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                 'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'platonic']]])]);
    }

    private function seedNpcs(): void
    {
        // Ashe: a scholar-mage follower (destruction / alteration / enchanting).
        $this->npc(self::ASHE, 'FE0019C2', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
            ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55, 'conjuration' => 40, 'restoration' => 35, 'lightarmor' => 30]);
        // Aela: the Companions' huntress (archery / sneak / light armor).
        $this->npc(self::AELA, '1A696', 'NordRace', 'Hunter',
            ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction', 'PotentialFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]);
    }

    /**
     * Core locations rows (the plugin's location keyword tags, as the live table stores them).
     * The Arcanaeum is a cell of the College with no row of its own: its name is what speaks.
     */
    private function seedPlaces(): void
    {
        foreach ([
            ['Mzinchaleft', 'The Pale', 'Dungeon,Dwarven Ruin,', 'Skyrim'],
            ['College of Winterhold', 'Winterhold', 'Guild,', 'Skyrim'],
        ] as [$name, $hold, $tags, $world]) {
            pg_query_params($this->db->link,
                'INSERT INTO locations (name, region, hold, tags, factions, is_interior, world, chim_added) VALUES ($1, $2, $2, $3, $4, $5, $6, 1)',
                [$name, $hold, $tags, '', 5, $world]);
        }
    }

    // ------------------------------------------------------------------ turns

    /** The plugin's location context line for the player entering / standing in a place. */
    private function arrive(string $context): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['infoloc', $context, 'pending', (int) $this->gamets, $this->realTs, (int) $this->gamets,
             '|' . self::ASHE . '|' . self::AELA . '|' . self::PLAYER . '|', '']);
    }

    /** Game time passes (walking, waiting, reading); $realSeconds of it is play at the keyboard. */
    private function pass(float $gameGamets, int $realSeconds): void
    {
        $this->gamets += $gameGamets;
        $this->realTs += $realSeconds;
    }

    /**
     * One player line to $npc through the real hooks, in CHIM's order: prerequest, context
     * (after core has set its caches), postrequest. Returns this turn's <place_feeling>.
     */
    private function turn(string $npc, string $line, array $topics = []): ?string
    {
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . self::ASHE . '|' . self::AELA . '|' . self::PLAYER . '|';

        $run = function (string $hook) use ($npc, $request, $party, $topics): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => $topics];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
        };

        $run('prerequest.php');
        $this->clearReldynGlobals();
        $comfortBefore = $this->comfort($npc);
        $run('context.php');
        $this->assertOnePlaceReadApplied($npc, $this->comfort($npc) - $comfortBefore);
        $context = implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []));
        $this->clearReldynGlobals();
        $run('postrequest.php');
        $this->clearReldynGlobals();

        $felt = preg_match('/<place_feeling>(.*?)<\/place_feeling>/s', $context, $m) ? $m[1] : null;
        $this->felt[] = [$npc, $this->dynamics($npc)['_place_appraisal']['place'] ?? null, $felt];
        return $felt;
    }

    /**
     * Effects once per turn: the context hook moved the NPC's comfort by exactly what its one
     * place read reported ([PLACE] log line of RelDynFacets::contextTurn), not twice that.
     */
    private function assertOnePlaceReadApplied(string $npc, float $comfortChange): void
    {
        $log = (string) file_get_contents($this->errorLog);
        $count = preg_match_all('/\[PLACE\] ' . preg_quote($npc, '/') . ' @ [^\n]*comfort ([+-][0-9.]+)/', $log, $m);
        $this->assertSame(1, $count - $this->placeReads[$npc], "{$npc}: one place read this turn");
        $this->placeReads[$npc]++;
        $this->assertEqualsWithDelta((float) end($m[1]), $comfortChange, 0.006, "{$npc}: the read's comfort nudge, applied once");
    }

    /** Both companions are spoken to, one line each, then a few minutes of play pass. */
    private function talkToBoth(string $line): array
    {
        $a = $this->turn(self::ASHE, $line);
        $b = $this->turn(self::AELA, $line);
        $this->pass(5 * self::MINUTE, 120);
        return [self::ASHE => $a, self::AELA => $b];
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function comfort(string $npc): float
    {
        return floatval($this->dynamics($npc)['dimensions']['comfort']['x'] ?? NAN);
    }

    private function mood(string $npc): float
    {
        return floatval($this->dynamics($npc)['dimensions']['valence']['x'] ?? NAN);
    }

    private function read(string $npc): array
    {
        return $this->dynamics($npc)['_place_appraisal'] ?? [];
    }

    // ------------------------------------------------------------------ the scenario

    public function testAsheAndAelaFeelTheRuinTheLibraryAndTheForestDifferently(): void
    {
        // ---- The woods south of Falkreath: an exterior with no location (the wilderness).
        $this->arrive('(Context location: ,Hold: Falkreath, Buildings to go:, Current Date in Skyrim World: Morndas, 10:00 AM, 17th of Last Seed, 4E 201, current weather: Pleasant)');
        $this->talkToBoth('Smell that pine. Good hunting country.');   // first turn: profiles derived
        $forest = [];
        foreach ([self::ASHE, self::AELA] as $npc) {
            $forest[$npc] = ['comfort' => $this->comfort($npc), 'mood' => $this->mood($npc)];
        }
        $this->talkToBoth('Let us rest here a while.');
        $this->talkToBoth('Did you hear that? Elk, maybe.');
        $forestRead = [self::ASHE => $this->read(self::ASHE), self::AELA => $this->read(self::AELA)];

        // Auto-derived, not seeded: the core rows alone made these two people.
        // (Ashe's temperament comes from the named-NPC preset table, which rulings §8 moves to
        // Guarded on another lane; her facet preferences come from her class and skills either way.)
        $this->assertSame('Independent', $this->dynamics(self::AELA)['inferred_temperament'], 'hunter class -> Independent (MDD 1.3)');
        $prefsAshe = $this->dynamics(self::ASHE)['_facet_prefs']['prefs'];
        $prefsAela = $this->dynamics(self::AELA)['_facet_prefs']['prefs'];
        $this->assertGreaterThan(0.5, $prefsAshe['scholarly']);
        $this->assertGreaterThan(0.5, $prefsAela['nature']);
        $this->assertLessThan(0.0, $prefsAela['scholarly']);
        $this->assertArrayNotHasKey('facet_pref_overrides', $this->dynamics(self::AELA), 'no hand-set preferences');

        // Aela is where she belongs: the wild, loved, and it shows in her comfort and mood.
        $this->assertSame('', $forestRead[self::AELA]['place'], 'the wilderness has no name');
        $this->assertSame('nature', $forestRead[self::AELA]['dominant']);
        $this->assertSame(1, $forestRead[self::AELA]['dominant_sign']);
        $this->assertGreaterThan(0.5, $forestRead[self::AELA]['valence']);
        $this->assertGreaterThan($forest[self::AELA]['comfort'], $this->comfort(self::AELA), 'Aela eases in the woods');
        $this->assertGreaterThan($forest[self::AELA]['mood'], $this->mood(self::AELA));
        $this->assertGreaterThan($forestRead[self::ASHE]['valence'], $forestRead[self::AELA]['valence'], 'the woods are hers more than Ashe\'s');

        // ---- Mzinchaleft, a Dwemer ruin (interior: the plugin reports the weather as "outdoors it is").
        $this->pass(8 * self::HOUR, 300);   // the road north, mostly fast travel
        $this->arrive('(Context location: Mzinchaleft ,Hold: The Pale, Buildings to go:, Current Date in Skyrim World: Morndas, 06:05 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Cloudy)');
        $ruinMood = [self::ASHE => $this->mood(self::ASHE), self::AELA => $this->mood(self::AELA)];
        $ruinFelt = $this->talkToBoth('Careful. Dwemer machines still guard these halls.');
        // Entering, the dark and the danger startle anyone once (environmental modifiers:
        // arousal up, comfort down); from then on it is her own read of the place that counts.
        $ruinStart = [self::ASHE => $this->comfort(self::ASHE), self::AELA => $this->comfort(self::AELA)];
        $this->talkToBoth('Look at these gears. Still turning after all this time.');
        $this->talkToBoth('Centurion ahead. Stay close.');
        $ruinRead = [self::ASHE => $this->read(self::ASHE), self::AELA => $this->read(self::AELA)];

        // Same ruin, two different places: Ashe sees the machinery she wants to understand...
        $this->assertSame('Mzinchaleft', $ruinRead[self::ASHE]['place']);
        $this->assertSame('scholarly', $ruinRead[self::ASHE]['dominant']);
        $this->assertSame(1, $ruinRead[self::ASHE]['dominant_sign']);
        $this->assertGreaterThan(0.0, $ruinRead[self::ASHE]['valence']);
        $this->assertGreaterThan($ruinStart[self::ASHE], $this->comfort(self::ASHE), 'Ashe settles in: more at ease the longer she is there');
        $this->assertGreaterThan($ruinMood[self::ASHE], $this->mood(self::ASHE), 'and interested');
        // ...Aela sees a battlefield.
        $this->assertSame('combat', $ruinRead[self::AELA]['dominant']);
        $this->assertSame(1, $ruinRead[self::AELA]['dominant_sign']);
        $this->assertNotNull($ruinFelt[self::ASHE]);
        $this->assertNotNull($ruinFelt[self::AELA]);
        $this->assertNotSame($ruinFelt[self::ASHE], str_replace(self::AELA, self::ASHE, (string) $ruinFelt[self::AELA]),
            'two different reads of one place');

        // ---- The Arcanaeum: the College's library, an interior cell with no locations row of its own.
        // Two days on the road to Winterhold; arrive in the morning (game day 202, 10:00).
        $this->gamets = 202 * self::DAY + 10 * self::HOUR;
        $this->realTs += 600;
        $this->arrive('(Context location: The Arcanaeum ,Hold: Winterhold, Buildings to go:, Current Date in Skyrim World: Wednesday, 10:00 AM, 19th of Last Seed, 4E 201, current weather: outdoors it is Snowing)');
        $this->talkToBoth('Urag will not like us talking in here.');
        // The first turn after two days apart also carries the absence (MDD attachment: the
        // huntress is comfortable apart); from here on the library is what moves them.
        $libStart = [self::ASHE => $this->comfort(self::ASHE), self::AELA => $this->comfort(self::AELA)];
        $libMood = [self::ASHE => $this->mood(self::ASHE), self::AELA => $this->mood(self::AELA)];
        $libRead = [self::ASHE => $this->read(self::ASHE), self::AELA => $this->read(self::AELA)];

        $this->assertSame('The Arcanaeum', $libRead[self::ASHE]['place']);
        $this->assertSame('scholarly', $libRead[self::ASHE]['dominant']);
        $this->assertGreaterThan(0.3, $libRead[self::ASHE]['valence'], 'Ashe loves the library');
        $this->assertLessThan(-0.15, $libRead[self::AELA]['valence'], 'Aela hates it');
        $this->assertSame(-1, $libRead[self::AELA]['dominant_sign']);

        // Hours among the books (reading, waiting; still daytime): the game calendar moves, a
        // line now and then.
        $discomfort = [];
        $aelaComfort = [$this->comfort(self::AELA)];
        for ($i = 0; $i < 4; $i++) {
            $this->pass(1.5 * self::HOUR, 90);
            $this->talkToBoth('Found anything yet?');
            $discomfort[] = floatval($this->dynamics(self::AELA)['_place_discomfort']['points'] ?? 0);
            $aelaComfort[] = $this->comfort(self::AELA);
        }
        for ($i = 1; $i < count($discomfort); $i++) {
            $this->assertGreaterThan($discomfort[$i - 1], $discomfort[$i], 'staying wears on her, game hour by game hour');
        }
        for ($i = 1; $i < count($aelaComfort); $i++) {
            $this->assertLessThan($aelaComfort[$i - 1], $aelaComfort[$i], 'her comfort keeps dropping the longer she stays');
        }
        $this->assertLessThan($libStart[self::AELA], $this->comfort(self::AELA));
        $this->assertLessThan($libMood[self::AELA], $this->mood(self::AELA));
        $this->assertEqualsWithDelta(0.0, floatval($this->dynamics(self::ASHE)['_place_discomfort']['points'] ?? 0), 1e-9,
            'no discomfort for Ashe in a place she loves');
        $this->assertGreaterThan($libStart[self::ASHE], $this->comfort(self::ASHE), 'Ashe at ease among the books');
        $this->assertGreaterThan($libMood[self::ASHE], $this->mood(self::ASHE));

        // Effects are applied once per turn: a second line in the same game minute adds no
        // discomfort (time spent, not lines spoken, is what wears on her).
        $this->turn(self::AELA, 'Just a moment longer.');
        $before = floatval($this->dynamics(self::AELA)['_place_discomfort']['points']);
        $comfortBefore = $this->comfort(self::AELA);
        $this->turn(self::AELA, 'Truly, one more moment.');   // same game minute
        $this->assertEqualsWithDelta($before, floatval($this->dynamics(self::AELA)['_place_discomfort']['points']), 1e-9);
        // ...and nor do comfort and mood move by more than that minute's worth (review
        // 2026-09-24: the place nudges follow game hours spent there, not lines spoken).
        $this->assertEqualsWithDelta($comfortBefore, $this->comfort(self::AELA), 0.02);

        // Where is each happiest? Aela: the woods. Ashe: the library.
        $this->assertGreaterThan($ruinRead[self::AELA]['valence'], $forestRead[self::AELA]['valence']);
        $this->assertGreaterThan($libRead[self::AELA]['valence'], $forestRead[self::AELA]['valence']);
        $this->assertGreaterThan($forestRead[self::ASHE]['valence'], $libRead[self::ASHE]['valence']);

        // ---- The LLM only ever got feelings: every place read is prose, never a number.
        $texts = array_values(array_filter(array_column($this->felt, 2)));
        $this->assertNotEmpty($texts);
        foreach ($this->felt as [$npc, $place, $text]) {
            if ($text === null) continue;
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$npc} @ {$place}: feelings, not numbers");
            $this->assertStringContainsString($npc, $text);
        }
        $aelaLibrary = array_values(array_filter($this->felt, fn($f) => $f[0] === self::AELA && $f[1] === 'The Arcanaeum' && $f[2] !== null));
        $this->assertNotEmpty($aelaLibrary, 'Aela\'s restlessness in the library reaches the LLM');

        // Effects once per turn (also checked turn by turn in turn()): one place read per NPC
        // turn, and the environment's effects only when the place or the hour changed.
        $log = (string) file_get_contents($this->errorLog);
        foreach ([self::ASHE, self::AELA] as $npc) {
            $turns = count(array_filter($this->felt, fn($f) => $f[0] === $npc));
            $this->assertSame($turns, substr_count($log, "[PLACE] {$npc} @"), "{$npc}: one place read per turn");
            $this->assertLessThanOrEqual(3, substr_count($log, "[RelDyn-ENV] {$npc} @"), "{$npc}: environment applied on change only");
        }

        // Real SQL only; no MinAI source was read.
        $this->assertSame([], $this->db->failures, 'no failed SQL statement');
        foreach ($this->db->statements as $sql) {
            $this->assertDoesNotMatchRegularExpression('/_minai_|minai_items|minai_combat/i', $sql);
        }
    }

    /**
     * Places and things go through one appraisal: a topic of talk (core's grounded Oghma
     * topic, classified from core's own oghma row) is read with the same preferences as the
     * place it is talked about in, and the facet vocabulary is the same everywhere.
     */
    public function testTalkOfTheCollegeLandsLikeTheLibraryDoes(): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO oghma (topic, topic_desc, knowledge_class, tags, category, aliases) VALUES ($1, $2, $3, $4, $5, $6)',
            ['college_of_winterhold', 'The College of Winterhold is the center of magical learning in Skyrim.',
             'scholar, mage, college_of_winterhold', 'magic,college,winterhold,arcanaeum,mages', 'locations', 'the College']);
        $this->arrive('(Context location: The Arcanaeum ,Hold: Winterhold, Buildings to go:, Current Date in Skyrim World: Wednesday, 10:00 AM, 19th of Last Seed, 4E 201, current weather: outdoors it is Snowing)');
        $this->turn(self::ASHE, 'Tell me about the College.', ['college_of_winterhold']);
        $this->turn(self::AELA, 'Tell me about the College.', ['college_of_winterhold']);
        // The topic's felt read reaches the next turn's context.
        $GLOBALS['gameRequest'] = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, 'Kaida: hm'];
        $topicText = [];
        foreach ([self::ASHE, self::AELA] as $npc) {
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['contextDataFull'] = [];
            (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/context.php'; })();
            RelationshipDynamics::endRequest();
            $ctx = implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull']));
            $topicText[$npc] = preg_match('/<topic_resonance>(.*?)<\/topic_resonance>/s', $ctx, $m) ? $m[1] : null;
        }

        $ashe = $this->dynamics(self::ASHE);
        $aela = $this->dynamics(self::AELA);
        $this->assertSame('college_of_winterhold', $ashe['_last_topic_match'] ?? null, 'Ashe warms to the talk');
        $this->assertArrayNotHasKey('_last_topic_match', $aela, 'Aela does not');
        $this->assertNotNull($topicText[self::ASHE]);
        $this->assertDoesNotMatchRegularExpression('/\d/', (string) $topicText[self::ASHE] . (string) $topicText[self::AELA]);

        // The same preferences read the place and the topic, and they agree.
        $topic = RelDynFacetClassifier::thingAppraisal(RelDynFacets::preferences($ashe, self::ASHE), 'topic', 'college_of_winterhold');
        $topicAela = RelDynFacetClassifier::thingAppraisal(RelDynFacets::preferences($aela, self::AELA), 'topic', 'college_of_winterhold');
        $this->assertGreaterThan(0.0, $topic['valence']);
        $this->assertLessThan(0.0, $topicAela['valence']);
        $this->assertGreaterThan(0.0, $ashe['_place_appraisal']['valence']);
        $this->assertLessThan(0.0, $aela['_place_appraisal']['valence']);

        // One facet vocabulary: every facet any source produces or any table names is a FACETS entry.
        $facetKeys = [];
        $collect = function ($vector) use (&$facetKeys): void {
            foreach (array_keys((array) $vector) as $k) $facetKeys[(string) $k] = true;
        };
        $collect($topic['facets']);
        $collect(RelDynFacets::placeFacets(RelDynFacets::currentPlaceContext(self::ASHE)));
        $collect($ashe['_facet_prefs']['prefs']);
        $placeCfg = RelDynFacets::placeFacetConfig();
        foreach (['tags', 'time_of_day', 'weather'] as $table) {
            foreach ((array) $placeCfg[$table] as $vector) $collect($vector);
        }
        foreach ((array) $placeCfg['name_keywords'] as $vector) if (is_array($vector)) $collect($vector);
        foreach (['interior', 'exterior', 'wilderness'] as $row) $collect($placeCfg[$row]);
        $classifier = RelDynFacetClassifier::config();
        $collect($classifier['anchors']);
        foreach (['knowledge_class_prior', 'category_prior', 'tag_keywords', 'item_keywords', 'creature_keywords', 'activity_keywords'] as $table) {
            foreach ((array) ($classifier[$table] ?? []) as $vector) $collect($vector);
        }
        foreach (['archetype_prefs', 'skill_facets', 'temperament_prefs', 'trait_prefs'] as $table) {
            foreach ((array) (RelDynFacets::getPreferenceConfig()[$table] ?? []) as $vector) $collect($vector);
        }
        foreach ((array) RelDynFacets::getAppraisalConfig()['event_facets'] as $vector) $collect($vector);
        $collect(RelDynFacets::getAppraisalConfig()['felt_text']['place']);
        $collect(RelDynFacets::getAppraisalConfig()['felt_text']['thing']);
        $collect(RelationshipDynamics::getConfig()['environment_facet_effects']);
        $this->assertSame([], array_values(array_diff(array_keys($facetKeys), RelDynFacets::FACETS)), 'one facet vocabulary');
        $this->assertSame([], $this->db->failures, 'no failed SQL statement');
    }
}
