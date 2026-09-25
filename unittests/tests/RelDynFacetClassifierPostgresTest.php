<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynClassifierPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('SQL error: ' . pg_last_error($this->link) . ' in ' . $q);
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('SQL error: ' . pg_last_error($this->link) . ' in ' . $q);
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('SQL error: ' . pg_last_error($this->link) . ' in ' . $q);
        return $res;
    }

    public function insert($table, $data)
    {
        // As lib/postgresql.class.php insert(): pg_query_params, so a PHP null stays SQL NULL
        $ph = [];
        foreach (array_keys($data) as $i => $_) $ph[] = '$' . ($i + 1);
        $q = "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $ph) . ')';
        $res = @pg_query_params($this->link, $q, array_values($data));
        if (!$res) throw new RuntimeException('SQL error: ' . pg_last_error($this->link) . ' in ' . $q);
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * The facet classifier on a real PostgreSQL (oghma rows as on 3.4.1):
 *  - oghma-facet-classifier: the builder stores one facet vector per Oghma entry in RelDyn's
 *    own table (embedding + prior, versioned, idempotent, calibrated on the corpus), falls back
 *    to the knowledge_class / category / tags prior when the embedding service is down, and
 *    reuses core's oghma.vector384 when it is filled;
 *  - thingFacets finds a stored row by topic or alias, else the live prior, else the keyword tables;
 *  - topic-talk-bonus / flirt-in-context: the real postrequest hook appraises this turn's
 *    Oghma topics per NPC (Dwemer lore warms Ashe and bores Aela) and context.php says so;
 *  - item-interest-classification: a gift is appraised by who receives it.
 *
 * Only the embedding HTTP call is stubbed: a fixed "model" that says which facet directions a
 * text has (anchors are one-hot, entries are what $model says). Everything else is the real code.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynFacetClassifierPostgresTest extends TestCase
{
    /** topic, knowledge_class, category, aliases, tags, topic_desc (live 3.4.1 rows, desc cut). */
    private const OGHMA = [
        ['dwemer', 'scholar', 'lore', 'dwarves, Deep Elves', 'Mer, Morrowind, Red Mountain, Velothi Mountains, Chimer, Heart of Lorkhan, disappearance, underground cities, Dwarven ruins, First Era, Rourken', 'The Dwemer, often referred to as Deep-Elves or Deep Folk, are a legendary race of Mer originating from Dwemereth, predominantly located in modern-day Morrowind.'],
        ['dwemer_museum', 'reach, dwemer, traveler', 'reach', '', 'Understone Keep, Markarth, Dwemer, artifacts, Reach, museum', 'The Dwemer Museum is located in Understone Keep, in Markath in the Reach Hold. It holds various artifacts belonging to the Dwemer.'],
        ['ebony_mace', 'blacksmith', 'equipment', '', '', 'Ebony Mace is a blunt ebony weapon which deals fantastic damage. Its visual design is sleek and black, with smooth, angular lines and silver accents that give i'],
        ['ebony_equipment', 'blacksmith, warrior, merchant', 'equipment', 'ebony armor, ebony boots, ebony gauntlets, ebony helmet, ebony shield, ebony sword, ebony war axe, ebony dagger, ebony mace, ebony greatsword, ebony battleaxe, ebony warhammer, ebony bow, ebony arrow', 'Ebony, heavy armor, blacksmithing, rare materials, wealthy warriors, weapons, shields, arrows', 'Ebony equipment includes dense black weapons, arrows, heavy armor, and shields. Rare ebony is difficult to work but supports exceptionally strong, carefully fin'],
        ['sabre_cat', 'hunter', 'creatures', '', 'Skyrim, pelt, alchemy eyes, Witbane, magicka regeneration, aggressive hunters, stealthy predators, water evasion', ' Sabre Cats are large, fast, and powerful felines found in the wilds of Skyrim. Known for their sharp front teeth, they are aggressive hunters that will attack '],
        ['temple_of_mara', 'rift, priest, traveler', 'rift', '', 'Mara, Riften, Maramal, Dinya Balu, Briehl, Alessandra, Arkay, marriage rites, Hall of the Dead, Aedric goddess', 'The Temple of Mara is a sacred temple in Riften dedicated to Mara, the Aedric goddess of love, compassion, and understanding. It serves as a spiritual center fo'],
        ['soul_gems', 'mage, scholar', 'lore', 'Soul gem', 'enchanting, necromancy, Azura\'s Star, soul trapping, Black soul gems, Daedric artifacts, magical construction, conjuration, Molag Bal', 'Soul gems are crystals capable of holding a captured soul. Filled gems provide power for creating enchantments, restoring the charge of enchanted objects, and c'],
        ['college_of_winterhold', 'collegeofwinterhold, scholar, mage', 'winterhold', '', '', 'The College of Winterhold is a renowned school for magic located in the city of Winterhold, perched on a rocky outcrop, and reachable only by crossing a narrow '],
        ['falmer', 'snow_elf, scholar', 'lore', 'Snow Ghosts, the Betrayed', 'Snow Elves, Dwemer, Ysgramor, Chaurus, Knight-Paladin Gelebor, Nord-Falmer War, toxic fungi, Dwemer ruins, subterranean adaptation, slavery, blindness', 'The Falmer, also called Snow Ghosts or Betrayed, are blind, degenerated descendants of the ancient Snow Elves who now dwell in Skyrim\'s dark depths. Once a prou'],
        ['whiterun_stables', 'whiterun, traveler', 'whiterun', '', 'Whiterun, horses, mounts, Lillith Maiden-Loom, Skulvar Sable-Hilt, Jervar, stables, lodging, travelers', 'Whiterun Stables is a small but essential location just outside the gates of Whiterun, providing lodging for horses and a place for travelers to acquire mounts.'],
    ];

    /** What the stub "model" says each entry is about (facet directions); prefix of the entry text. */
    private const MODEL = [
        'dwemer. '           => ['scholarly' => 1.0, 'crafting' => 0.97],
        'dwemer museum. '    => ['scholarly' => 1.0, 'quiet' => 0.5],
        'ebony mace. '       => ['combat' => 1.0, 'crafting' => 0.6],
        'ebony equipment. '  => ['combat' => 1.0, 'crafting' => 0.9],
        'sabre cat. '        => ['nature' => 1.0, 'danger' => 0.98, 'wild' => 0.96],
        'temple of mara. '   => ['sacred' => 1.0, 'spiritual' => 0.99],
        'soul gems. '        => ['enchanting' => 1.0],
        'college of winterhold. ' => ['scholarly' => 1.0, 'enchanting' => 0.98],
        'falmer. '           => ['dark' => 1.0, 'danger' => 0.97],
        'whiterun stables. ' => ['nature' => 0.6, 'domestic' => 0.6],
    ];

    private string $dsn;
    private string $schema;
    private RelDynClassifierPgDb $db;
    private bool $pgvector = false;
    private array $savedGlobals = [];
    private array $embedCalls = [];
    private bool $serviceDown = false;
    private array $failTexts = [];
    private string $logFile;
    private $prevLog;

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
        $this->schema = 'reldyn_facet' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $this->pgvector = (bool) @pg_query($admin, 'CREATE EXTENSION IF NOT EXISTS vector SCHEMA public');
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Column types as the 3.4.1 oghma table (lib/core/database_schema; vector384 is pgvector).
        pg_query($admin, 'CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text,
            ' . ($this->pgvector ? 'vector384 public.vector(384), ' : '') . 'aliases text)');
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0,
            prompt_head text,
            oghma_knowledge_tags text,
            emote_moods text,
            relationships text,
            occupation text,
            appearance text,
            skills text,
            goals text,
            profile_id integer,
            dynamic_profile integer,
            md5 text,
            gamets_last_updated numeric,
            base text,
            tags text,
            refid text,
            personality text, speechstyle text, core text, npc_static_bio text,
            voiceid text, gender text, race text,
            metadata jsonb,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        // lib/core/database_schema/core_npc_master_history.sql: core's timeline stamp after a relationship write
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(),
            npc_name text, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0, prompt_head text,
            npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16),
            profile_id integer, dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb, md5 text, gamets_last_updated numeric,
            core text, base text, tags text)");
        pg_query($admin, 'CREATE TABLE conf_opts (id text PRIMARY KEY, value text)');
        // lib/core/database_schema/core_player.sql (RelDynPlayer::profile)
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, 'CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)');
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, 'CREATE TABLE moods_issued (speaker text, mood text, localts bigint)');
        pg_close($admin);

        $this->db = new RelDynClassifierPgDb($dsn, $this->schema);
        foreach (self::OGHMA as [$topic, $kc, $cat, $aliases, $tags, $desc]) {
            pg_query_params($this->db->link,
                'INSERT INTO oghma (topic, knowledge_class, category, aliases, tags, topic_desc) VALUES ($1, $2, $3, $4, $5, $6)',
                [$topic, $kc, $cat, $aliases, $tags, $desc]);
        }

        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'CACHE_PEOPLE', 'CACHE_PARTY', 'OGHMA_PARITY_RESULT', 'contextDataFull', 'RELLLM_CONNECTOR'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        RelDynFacetClassifier::setEmbedder(fn(string $text) => $this->model($text));
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_facet_pg_test.log');
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdfacetpg');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelDynFacetClassifier::setEmbedder(null);
        RelDynFacetClassifier::setBuildLauncher(null);
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    private static function vec(array $weights): array
    {
        $v = array_fill(0, 384, 0.0);
        foreach ($weights as $facet => $w) {
            $v[array_search($facet, RelDynFacets::FACETS, true)] = $w;
        }
        return $v;
    }

    /** The stubbed embedding call: anchors are one-hot on their facet, entries are what MODEL says. */
    private function model(string $text): ?array
    {
        $this->embedCalls[] = $text;
        if ($this->serviceDown) return null;
        foreach (RelDynFacets::FACETS as $facet) {
            if (str_starts_with($text, "{$facet}:")) return self::vec([$facet => 1.0]);
        }
        foreach ($this->failTexts as $prefix) {
            if (str_starts_with($text, $prefix)) return null;
        }
        foreach (self::MODEL as $prefix => $weights) {
            if (str_starts_with($text, $prefix)) return self::vec($weights);
        }
        $v = array_fill(0, 384, 0.0);
        $v[383] = 1.0;   // about nothing any anchor is about
        return $v;
    }

    /** Entry embeddings of this build (anchor calls left out). */
    private function entryCalls(): array
    {
        return array_values(array_filter($this->embedCalls, function ($t) {
            foreach (RelDynFacets::FACETS as $facet) {
                if (str_starts_with($t, "{$facet}:")) return false;
            }
            return true;
        }));
    }

    private function config(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['relationship_dynamics_config', json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    private function oghmaRow(string $topic): array
    {
        return pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT * FROM oghma WHERE topic = $1', [$topic]));
    }

    private function stored(string $topic): ?array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT facets::text AS facets, method, version FROM ' . RelDynFacetClassifier::TABLE . ' WHERE topic = $1', [$topic]));
        if (!$r) return null;
        $r['facets'] = json_decode($r['facets'], true);
        return $r;
    }

    private function build(array $opts = []): array
    {
        $this->embedCalls = [];
        return RelDynFacetClassifier::build($this->db, $opts);
    }

    /** Expected stored facets of an entry: the classifier's own pieces for this row (the math is RelDynFacetClassifierTest). */
    private function expected(string $topic, ?array $cal = null): array
    {
        $cfg = RelDynFacetClassifier::config();
        $row = $this->oghmaRow($topic);
        $anchors = [];
        foreach (RelDynFacets::FACETS as $facet) $anchors[$facet] = self::vec([$facet => 1.0]);
        $prefix = str_replace('_', ' ', $topic) . '. ';
        $vec = self::vec(self::MODEL[$prefix]);
        return RelDynFacetClassifier::combine(
            RelDynFacetClassifier::embeddingFacets($vec, $anchors, $cfg['embedding'], $cal),
            RelDynFacetClassifier::priorFacets($row, $cfg),
            $cfg['embedding']);
    }

    // ------------------------------------------------------------------
    // oghma-facet-classifier: the builder
    // ------------------------------------------------------------------

    public function testTheBuilderStoresOneFacetVectorPerOghmaEntryAndRerunsOnlyWhatChanged(): void
    {
        $s = $this->build();
        $this->assertSame('embedding_raw', $s['method'], '10 entries: below calibration_min_entries');
        $this->assertSame(10, $s['rows']);
        $this->assertSame(10, $s['written']);
        $this->assertSame(10, $s['embedded']);
        $this->assertCount(19, array_diff($this->embedCalls, $this->entryCalls()), 'each anchor embedded once');
        $this->assertSame('19', pg_fetch_result(pg_query($this->db->link, 'SELECT count(*) FROM ' . RelDynFacetClassifier::ANCHOR_TABLE), 0, 0));

        $dwemer = $this->stored('dwemer');
        $this->assertSame('embedding_raw', $dwemer['method']);
        $this->assertSame($s['version'], $dwemer['version']);
        $this->assertEquals($this->expected('dwemer'), $dwemer['facets']);
        $prior = RelDynFacetClassifier::priorFacets($this->oghmaRow('dwemer'));
        $this->assertGreaterThan($prior['crafting'], $dwemer['facets']['crafting'], 'the model agrees on crafting: noisy-OR raises it');
        $this->assertGreaterThanOrEqual(0.8, $dwemer['facets']['scholarly']);
        $this->assertArrayHasKey('sacred', $this->stored('temple_of_mara')['facets'], 'what only the embedding says still counts');

        // Nothing changed: nothing is embedded or written again
        $s = $this->build();
        $this->assertSame(10, $s['skipped_current']);
        $this->assertSame(0, $s['written']);
        $this->assertSame([], $this->entryCalls());

        // One entry's tags change: that row alone
        pg_query($this->db->link, "UPDATE oghma SET tags = tags || ', forest' WHERE topic = 'sabre_cat'");
        $s = $this->build();
        $this->assertSame(1, $s['written']);
        $this->assertCount(1, $this->entryCalls());
        $this->assertStringStartsWith('sabre cat. ', $this->entryCalls()[0]);

        // A topic gone from oghma is removed; --force recomputes every row
        pg_query($this->db->link, "DELETE FROM oghma WHERE topic = 'whiterun_stables'");
        $s = $this->build(['force' => true]);
        $this->assertSame(1, $s['removed']);
        $this->assertSame(9, $s['written']);
        $this->assertNull($this->stored('whiterun_stables'));
    }

    public function testACalibratedBuildStoresTheAnchorStatisticsAndReusesThem(): void
    {
        $this->config(['facet_classifier' => ['embedding' => ['calibration_min_entries' => 5]]]);
        $s = $this->build();
        $this->assertSame('embedding', $s['method']);
        $this->assertSame(10, $s['calibrated_on']);
        $this->assertSame(10, $s['embedded'], 'every entry embedded once, for the calibration and its own row');
        $cal = [];
        foreach (pg_fetch_all(pg_query($this->db->link, 'SELECT facet, mean, std, version FROM ' . RelDynFacetClassifier::ANCHOR_TABLE)) as $r) {
            $this->assertSame($s['version'], $r['version']);
            $cal[$r['facet']] = ['mean' => (float) $r['mean'], 'std' => (float) $r['std']];
        }
        $anchors = [];
        foreach (RelDynFacets::FACETS as $facet) $anchors[$facet] = self::vec([$facet => 1.0]);
        $vectors = array_map(fn($w) => self::vec($w), array_values(self::MODEL));
        $expectedCal = RelDynFacetClassifier::calibrate($vectors, $anchors);
        foreach ($expectedCal as $facet => $c) {
            $this->assertEqualsWithDelta($c['mean'], $cal[$facet]['mean'], 1e-9, $facet);
            $this->assertEqualsWithDelta($c['std'], $cal[$facet]['std'], 1e-9, $facet);
        }
        $this->assertEquals($this->expected('falmer', $expectedCal), $this->stored('falmer')['facets']);

        // The next run reuses the stored calibration: no corpus pass, nothing re-embedded
        $s = $this->build();
        $this->assertSame(0, $s['calibrated_on']);
        $this->assertSame(10, $s['skipped_current']);
        $this->assertSame([], $this->entryCalls());
    }

    public function testWithTheServiceDownEveryRowGetsThePriorAndTheNextRunEmbedsThem(): void
    {
        $this->serviceDown = true;
        $s = $this->build();
        $this->assertSame('prior', $s['method']);
        $this->assertSame(10, $s['written']);
        $this->assertSame([], $this->entryCalls(), 'no entry sent to a service that did not answer the anchors');
        foreach (self::OGHMA as [$topic]) {
            $row = $this->stored($topic);
            $this->assertSame('prior', $row['method']);
            $this->assertEquals(RelDynFacetClassifier::priorFacets($this->oghmaRow($topic)), $row['facets'], $topic);
        }
        $this->assertStringContainsString('embedding service unavailable', (string) file_get_contents($this->logFile));

        $this->serviceDown = false;
        $s = $this->build();
        $this->assertSame(10, $s['written'], 'prior rows carry the prior version: the embedding run redoes them');
        $this->assertSame('embedding_raw', $this->stored('dwemer')['method']);
    }

    public function testAnEntryTheServiceCannotEmbedKeepsThePriorAndIsRetriedNextRun(): void
    {
        $this->failTexts = ['falmer. '];
        $s = $this->build();
        $this->assertSame(1, $s['embed_failed']);
        $this->assertSame('prior', $this->stored('falmer')['method']);
        $this->assertSame('embedding_raw', $this->stored('dwemer')['method']);

        $this->failTexts = [];
        $s = $this->build();
        $this->assertSame(1, $s['written']);
        $this->assertSame('embedding_raw', $this->stored('falmer')['method']);
    }

    public function testCoresVector384IsReusedInsteadOfEmbedding(): void
    {
        if (!$this->pgvector) {
            $this->markTestSkipped('pgvector is not available in the throwaway cluster (vector math covered in PHP)');
        }
        $vec = self::vec(self::MODEL['dwemer. ']);
        pg_query_params($this->db->link, "UPDATE oghma SET vector384 = $1::public.vector WHERE topic = 'dwemer'",
            ['[' . implode(',', $vec) . ']']);
        $s = $this->build();
        $this->assertSame(1, $s['reused_vectors']);
        $this->assertSame(9, $s['embedded']);
        foreach ($this->entryCalls() as $text) {
            $this->assertStringStartsNotWith('dwemer. ', $text);
        }
        $this->assertEquals($this->expected('dwemer'), $this->stored('dwemer')['facets']);
    }

    // ------------------------------------------------------------------
    // thingFacets on stored rows / live prior / keyword tables
    // ------------------------------------------------------------------

    public function testThingFacetsReadsTheStoredRowByTopicOrAlias(): void
    {
        $this->build();
        $this->assertEquals($this->stored('dwemer')['facets'], RelDynFacets::thingFacets('topic', 'dwemer'));
        $this->assertEquals($this->stored('dwemer')['facets'], RelDynFacets::thingFacets('topic', 'Deep Elves'), 'alias');
        $this->assertEquals($this->stored('falmer')['facets'], RelDynFacets::thingFacets('creature', 'Snow Ghosts'));
        $this->assertEquals($this->stored('ebony_mace')['facets'], RelDynFacets::thingFacets('item', 'Ebony Mace'),
            "the entry named Ebony Mace, not ebony_equipment's alias 'ebony mace'");
        $this->assertEquals($this->stored('ebony_equipment')['facets'], RelDynFacets::thingFacets('item', 'Ebony Sword'));
        $this->assertEquals($this->stored('sabre_cat')['facets'], RelDynFacets::thingFacets('creature', 'Sabre Cat'));
        $this->assertSame(['combat' => 1.0, 'crafting' => 0.3], RelDynFacets::thingFacets('item', 'Steel Sword'), 'not in Oghma: item keywords');
        $this->assertSame([], RelDynFacets::thingFacets('item', "Kaida's Lucky Pebble"));
    }

    public function testWithoutABuildThingFacetsUsesTheLivePriorFromCoresOghmaRow(): void
    {
        $this->assertEquals(RelDynFacetClassifier::priorFacets($this->oghmaRow('dwemer')), RelDynFacets::thingFacets('topic', 'dwemer'));
        $this->assertEquals(RelDynFacetClassifier::priorFacets($this->oghmaRow('falmer')), RelDynFacets::thingFacets('topic', 'the Betrayed'));
        $this->assertSame('live_prior', RelDynFacetClassifier::oghmaFacets('Soul gem')['source']);
        $this->assertSame('soul_gems', RelDynFacetClassifier::oghmaFacets('Soul gem')['topic']);
        $this->assertNull(RelDynFacetClassifier::oghmaFacets('Soul'), 'a part of an alias is not the alias');
    }

    public function testClassifyItemInterestUsesOghmaBeforeTheKeywordTable(): void
    {
        $this->build();
        // ebony_mace: combat (equipment) vs crafting (blacksmith + the model) -- whatever the stored row says wins
        $f = $this->stored('ebony_mace')['facets'];
        $this->assertSame(RelDynFacets::dominantInterest($f), RelationshipDynamics::classifyItemInterest('Ebony Mace'));
        $this->assertSame('enchanting', RelationshipDynamics::classifyItemInterest('Soul Gem'));
    }

    // ------------------------------------------------------------------
    // topic-talk-bonus / flirt-in-context / item-interest-classification through the hooks
    // ------------------------------------------------------------------

    private const ASHE = ['scholarly' => 0.9, 'enchanting' => 0.6, 'adventure' => 0.4, 'crowd' => -0.3];
    private const AELA = ['nature' => 1.0, 'combat' => 0.8, 'scholarly' => -0.6, 'confined' => -0.5];

    private function seed(string $name, array $facetPreferences): void
    {
        $dynamics = [
            'inferred_temperament' => 'Romantic',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_profile_autogen' => ['version' => 999],
            'facet_pref_overrides' => $facetPreferences,
            'passion' => 10.0,
        ];
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb)',
            [$name, (string) crc32($name), json_encode(['relationships' => ['Player' => ['aff' => 20, 'type' => 'platonic']]]),
                json_encode(['reldyn' => ['dynamics' => $dynamics]])]);
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$name]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    /** One player line to $name through the real postrequest hook, core's Oghma having grounded $topics. */
    private function postrequest(string $name, array $topics, string $line = 'Kaida: Tell me what you know of the Dwemer.'): array
    {
        $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => $topics];
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), '60000000', $line];
        $GLOBALS['HERIKA_NAME'] = $name;
        $GLOBALS['RELDYN_NPC_NAME'] = $name;
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        $out = ['topic_bonus' => $GLOBALS['RELDYN_TOPIC_BONUS'] ?? null, 'flirt_bonus' => $GLOBALS['RELDYN_FLIRT_BONUS'] ?? null];
        RelationshipDynamics::endRequest();
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        return $out;
    }

    private function contextFor(string $name): string
    {
        $GLOBALS['HERIKA_NAME'] = $name;
        $GLOBALS['contextDataFull'] = [];
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/context.php'; })();
        RelationshipDynamics::endRequest();
        return implode("\n", array_column($GLOBALS['contextDataFull'], 'content'));
    }

    public function testDwemerLoreWarmsAsheAndBoresAelaThroughThePostrequestHook(): void
    {
        $this->build();
        $this->seed('Ashe', self::ASHE);
        $this->seed('Aela the Huntress', self::AELA);
        foreach (['Ashe', 'Aela the Huntress'] as $npc) {
            pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)', [$npc, 'flirty', time()]);
        }

        $ashe = $this->postrequest('Ashe', ['dwemer']);
        $aela = $this->postrequest('Aela the Huntress', ['dwemer']);

        $facets = $this->stored('dwemer')['facets'];
        $vAshe = RelDynFacets::appraise(RelDynFacets::preferences($this->dynamics('Ashe'), 'Ashe'), $facets)['valence'];
        $vAela = RelDynFacets::appraise(RelDynFacets::preferences($this->dynamics('Aela the Huntress'), 'Aela the Huntress'), $facets)['valence'];
        // Valence = weighted mean preference over ALL the topic's facets (appraise(), decisions §6):
        // Ashe warms to it past the match threshold, Aela is put off.
        $this->assertGreaterThan(0.3, $vAshe);
        $this->assertLessThan(0.0, $vAela);
        $this->assertEqualsWithDelta(RelDynFacets::interestMultiplier($vAshe), $ashe['topic_bonus'], 1e-9);
        $this->assertEqualsWithDelta(RelDynFacets::interestMultiplier($vAela), $aela['topic_bonus'], 1e-9);
        $this->assertGreaterThan(1.0, $ashe['topic_bonus']);
        $this->assertLessThan(1.0, $aela['topic_bonus']);
        $this->assertSame(1.2, $ashe['flirt_bonus'], 'flirty mood + a topic she warms to');
        $this->assertSame(1.0, $aela['flirt_bonus'], 'flirty mood, but the topic bores her');

        $a = $this->dynamics('Ashe');
        $b = $this->dynamics('Aela the Huntress');
        $this->assertSame('dwemer', $a['_last_topic_match']);
        $this->assertArrayNotHasKey('_last_topic_match', $b);
        $this->assertGreaterThan((float) $b['passion'], (float) $a['passion'], 'same exchange, same temperament: the topic decides');

        $ctxAshe = $this->contextFor('Ashe');
        $ctxAela = $this->contextFor('Aela the Huntress');
        // The felt read is the appraisal lane's feltText of this NPC's own appraisal: warm for
        // Ashe (a loved facet leads), cool for Aela (a disliked facet leads).
        $aAshe = RelDynFacetClassifier::thingAppraisal(RelDynFacets::preferences($a, 'Ashe'), 'topic', 'dwemer');
        $aAela = RelDynFacetClassifier::thingAppraisal(RelDynFacets::preferences($b, 'Aela the Huntress'), 'topic', 'dwemer');
        $this->assertSame(1, $aAshe['dominant_sign']);
        $this->assertSame(-1, $aAela['dominant_sign']);
        $this->assertStringContainsString('- ' . RelDynFacets::feltText('Ashe', $aAshe, 'topic', 'dwemer') . "\n", $ctxAshe);
        $this->assertStringContainsString('- ' . RelDynFacets::feltText('Aela the Huntress', $aAela, 'topic', 'dwemer') . "\n", $ctxAela);
        $this->assertDoesNotMatchRegularExpression('/\d/', $ctxAshe . $ctxAela, 'feelings, never numbers');

        // Next turn grounds no topic: the read clears (a stale conf_opts current_oghma_topic is not read)
        pg_query($this->db->link, "INSERT INTO conf_opts (id, value) VALUES ('current_oghma_topic', 'dwemer')");
        $again = $this->postrequest('Ashe', [], 'Kaida: Lovely weather.');
        $this->assertSame(1.0, $again['topic_bonus']);
        $this->assertArrayNotHasKey('_last_topic_felt', $this->dynamics('Ashe'));
        $this->contextFor('Ashe');
        $this->assertArrayNotHasKey('topic', RelDynFelt::lastRendered());
    }

    public function testTheStrongestFeltTopicOfTheTurnSpeaks(): void
    {
        $this->build();
        $prefs = RelDynFacets::preferences(['facet_pref_overrides' => self::AELA], 'Aela the Huntress');
        $t = RelDynFacetClassifier::topicTurn(['facet_pref_overrides' => self::AELA], 'Aela the Huntress', ['dwemer', 'sabre_cat']);
        $vD = RelDynFacetClassifier::thingAppraisal($prefs, 'topic', 'dwemer')['valence'];
        $vS = RelDynFacetClassifier::thingAppraisal($prefs, 'topic', 'sabre_cat')['valence'];
        $this->assertGreaterThan(abs($vD), abs($vS));
        $this->assertSame('sabre_cat', $t['appraisal']['name']);
        $this->assertSame('sabre_cat', $t['match']);
        $this->assertStringContainsString('sabre cat', (string) $t['felt']);
        $this->assertStringContainsString('Aela the Huntress', (string) $t['felt']);
    }

    public function testAGiftIsAppraisedByWhoReceivesIt(): void
    {
        $this->build();
        $this->config([]);
        $this->seed('Ashe', self::ASHE);
        $this->seed('Aela the Huntress', self::AELA);
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), '60000000', 'Kaida: For you.'];

        $give = function (string $npc, string $item): array {
            $d = $this->dynamics($npc);
            $r = RelationshipDynamics::processGift($d, $item, 'Kaida', 'Romantic', $npc);
            return [$r, $d];
        };
        [$aelaMace, $dAela] = $give('Aela the Huntress', 'Ebony Mace');
        [$asheMace, $dAshe] = $give('Ashe', 'Ebony Mace');
        [$asheGem, $dAsheGem] = $give('Ashe', 'Soul Gem');

        $gAela = RelDynFacetClassifier::giftAppraisal($this->dynamics('Aela the Huntress'), 'Aela the Huntress', 'Ebony Mace');
        $this->assertGreaterThan(1.0, $gAela['mult'], 'a weapon for the huntress');
        $this->assertGreaterThan($asheMace['affinity'], $aelaMace['affinity']);
        $this->assertGreaterThan($asheMace['affinity'], $asheGem['affinity'], 'Ashe would rather have the soul gem');
        // Felt reads (appraisal lane wording): the huntress sees the fighter's weapon, Ashe the magic.
        $this->assertSame(RelDynFacets::feltText('Aela the Huntress', $gAela['appraisal'], 'item', 'Ebony Mace'), $dAela['_last_gift_felt']);
        $this->assertStringContainsString('Ebony Mace speaks to the fighter in Aela the Huntress', $dAela['_last_gift_felt']);
        $gAsheGem = RelDynFacetClassifier::giftAppraisal($this->dynamics('Ashe'), 'Ashe', 'Soul Gem');
        $this->assertSame(1, $gAsheGem['appraisal']['dominant_sign']);
        $this->assertSame(RelDynFacets::feltText('Ashe', $gAsheGem['appraisal'], 'item', 'Soul Gem'), $dAsheGem['_last_gift_felt']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $dAela['_last_gift_felt'] . $dAsheGem['_last_gift_felt']);
    }

    // ------------------------------------------------------------------
    // The build runs by itself (review 2026-09-24: only a manual CLI tool ever ran it)
    // ------------------------------------------------------------------

    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 90 * RelationshipDynamics::GAMETS_PER_DAY;

    private array $launched = [];

    /** Every background build the trigger launches runs for real, in-process (only the process spawn is stubbed). */
    private function runLaunchedBuilds(): void
    {
        $this->launched = [];
        RelDynFacetClassifier::setBuildLauncher(function (string $reason) {
            $this->launched[] = $reason;
            $this->build();
        });
    }

    public function testTheBuildIsLaunchedInTheBackgroundWhenTheTableIsMissingOrStale(): void
    {
        $this->runLaunchedBuilds();
        $this->assertTrue(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0));
        $this->assertSame(['missing'], $this->launched);
        $this->assertSame('embedding_raw', $this->stored('dwemer')['method'], 'the launched build embedded');

        // current: nothing to do, however often it is asked
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 2 * self::HOUR));
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 50 * self::HOUR));

        // core added an Oghma entry: rebuilt (the builder only computes what changed)
        pg_query($this->db->link, "INSERT INTO oghma (topic, knowledge_class, category, aliases, tags, topic_desc) VALUES ('mead', 'innkeeper', 'items', '', 'tavern', 'Honey mead.')");
        $this->assertTrue(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 51 * self::HOUR));
        $this->assertSame(['missing', 'rows'], $this->launched);
        $this->assertNotNull($this->stored('mead'));

        // a mapping table edited in config: rebuilt
        $cfg = RelDynFacetClassifier::configDefaults();
        $cfg['tag_keywords']['museum'] = ['scholarly' => 0.9];
        $this->config(['facet_classifier' => ['tag_keywords' => $cfg['tag_keywords']]]);
        $this->assertTrue(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 53 * self::HOUR));
        $this->assertSame(['missing', 'rows', 'config'], $this->launched);
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 55 * self::HOUR));
    }

    public function testAPriorOnlyBuildRetriesTheEmbeddingOnTheGameCalendarNotEveryRequest(): void
    {
        $this->runLaunchedBuilds();
        $this->serviceDown = true;
        $this->assertTrue(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0));
        $this->assertSame('prior', $this->stored('dwemer')['method']);
        $retry = RelDynFacetClassifier::config()['build']['embedding_retry_game_hours'];
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + self::HOUR), 'no respawn while the service is down');
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + ($retry - 1) * self::HOUR));

        $this->serviceDown = false;
        $this->assertTrue(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + ($retry + 1) * self::HOUR));
        $this->assertSame(['missing', 'embedding'], $this->launched);
        $this->assertSame('embedding_raw', $this->stored('dwemer')['method']);
    }

    public function testNoLaunchWhileOneRunsOrWhenSwitchedOffAndOneBuildAtATime(): void
    {
        $launched = [];
        RelDynFacetClassifier::setBuildLauncher(function (string $reason) use (&$launched) { $launched[] = $reason; });
        $this->assertTrue(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0));
        // the launched build has not finished yet: a request a few game minutes later does not start another
        $gap = RelDynFacetClassifier::config()['build']['min_gap_game_hours'];
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 0.5 * $gap * self::HOUR));
        $this->assertSame(['missing'], $launched);

        // two builds never run together (advisory lock)
        $other = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($other, "SELECT pg_advisory_lock(hashtext('" . RelDynFacetClassifier::TABLE . "'))");
        $this->assertSame('locked', $this->build()['skipped'] ?? null);
        pg_close($other);
        $this->assertArrayNotHasKey('skipped', $this->build());

        $this->config(['facet_classifier' => ['build' => ['auto' => false]]]);
        pg_query($this->db->link, 'DROP TABLE ' . RelDynFacetClassifier::TABLE);
        $this->assertFalse(RelDynFacetClassifier::maybeLaunchBuild($this->db, self::T0 + 100 * self::HOUR), 'auto build off');
    }

    public function testThePostrequestHookAsksForTheBuild(): void
    {
        $hook = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/postrequest.php');
        $this->assertStringContainsString('RelDynFacetClassifier::maybeLaunchBuild(', $hook);
    }
}
