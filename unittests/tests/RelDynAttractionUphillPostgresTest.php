<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 * Every failure is recorded.
 */
final class RelDynAttractionUphillPgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) {
            $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
            return [];
        }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }

    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Decisions 2026-09-24 §13, "an uphill, not a wall" (Ken: "maybe 20 is the min and it uncaps
 * passion but until you hit the 45 your gains are less than 1 ... this could let a super
 * charming bard woo an atypical interest. To use Aela it'd be a substantial uphill; let's say
 * her floor is high 60s or so in martial"):
 *   passion below 20: raw x attachment (anyone); from 20: raw x curve x attachment [x prebond]
 *   curve: per required pillar below its floor m_min + (1 - m_min)(s/F)^k, 1.0 at F, +1%/point above
 *   hard zero (spark included) only for orientation, passion-free preferences and rigid pillars at 0
 *   no attraction cap at 20; balanced NPCs: the bond flattens the visceral hill
 *
 * Real PostgreSQL, CHIM 3.4.1 core-shaped rows only: Aela's core_npc_master row (class,
 * factions, skills; no RelDyn state), the player's core_player rows as the plugin writes them
 * (skills with core's speechcraft key, stats, tracked Skyrim stats, gender), read by
 * RelDynPlayer::profile(). Passion paths run through the real hooks (prerequest -> context ->
 * postrequest), the real eval inbox and the real hoover. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAttractionUphillPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const MINUTE = RelationshipDynamics::GAMETS_PER_DAY / 1440;
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynAttractionUphillPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY;

    protected function setUp(): void
    {
        // Personality traits phase 2: this class pins the LABEL assignment (the phase-1 legacy path):
        // its NPCs' temperaments are the old core-data vote's, so these are the attraction
        // mechanics on the Independent preset. Ruling #8 on the shipped read assignment (her own
        // vector from the seed's read) is testRulingEightUnderTheReadAssignment.
        RelDynTraits::$assignmentOverride = 'label';
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_uphill' . getmypid() . '_' . bin2hex(random_bytes(3));

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
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_close($admin);

        $this->db = new RelDynAttractionUphillPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rduphillpg');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attraction_uphill_pg_test.log');

        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'attraction' => self::labelPathAttraction()]))]);
        RelationshipDynamics::clearConfigCache();

        // Aela: the Companions' huntress, as the plugin registers her (no RelDyn state).
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $factions = [];
        foreach (['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'] as $i => $f) {
            $factions[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $f];
        }
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb)',
            [self::AELA, '1A696', 'female', 'NordRace', 'FemaleEvenToned', 'Roleplay as Aela the Huntress', '',
             json_encode(['skills' => array_merge($all, ['archery' => '72', 'sneak' => '56', 'lightarmor' => '52', 'onehanded' => '45'])]),
             json_encode(['class' => ['name' => 'Hunter', 'formid' => '0x0001317f'], 'factions' => $factions,
                 'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'platonic']]])]);
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
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

    /** A core_player row as core writes it (gamedata.php: one row per id, value text). */
    private function corePlayer(string $id, $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /**
     * The player's build as the plugin reports it: skills (raw 0..100), stats (level) and the
     * tracked Skyrim stats (gamedata skyrim_stats -> core_player.<stat name>).
     */
    private function playerBuild(array $skills, int $level, array $trackedStats = []): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 15);
        $this->corePlayer('skills', array_merge($all, $skills));
        $this->corePlayer('stats', ['level' => $level, 'health' => 100 + 10 * $level]);
        foreach ($trackedStats as $name => $n) {
            $this->corePlayer($name, (string) $n);
        }
    }

    /**
     * Step $k of a warrior's career: a level-10 sellsword at k = 0, then more levels, sharper
     * blades and more deeds (kills, quests, dungeons, the Companions' questline) each step.
     */
    private function warriorAtStep(int $k, int $extraCreatures = 0): void
    {
        $this->playerBuild(
            ['onehanded' => 30 + 12 * $k, 'twohanded' => 25 + 10 * $k, 'block' => 25 + 8 * $k, 'heavyarmor' => 25 + 10 * $k],
            10 + 6 * $k,
            [
                'People Killed' => 10 + 25 * $k, 'Creatures Killed' => 15 + 30 * $k + $extraCreatures,
                'Quests Completed' => 3 + 6 * $k, 'Dungeons Cleared' => 1 + 4 * $k, 'Locations Discovered' => 10 + 15 * $k,
                'The Companions Quests Completed' => $k,
            ]);
    }

    /**
     * A bard: speech / illusion, level 40, no fighting skill, a well-travelled, accomplished
     * town figure (quests, dungeons, houses, gold found). $companions: Companions quests reported (Aela's own status marker, MDD 2.3);
     * null = the game never reported the stat (unknown: her status reads the generic standing).
     */
    private function bard(?int $companions = null): void
    {
        $stats = ['Persuasions' => 30, 'Quests Completed' => 200, 'Locations Discovered' => 250, 'Dungeons Cleared' => 90,
            'Houses Owned' => 2, 'Gold Found' => 30000, 'Questlines Completed' => 6];
        if ($companions !== null) $stats['The Companions Quests Completed'] = $companions;
        $this->playerBuild(['speech' => 95, 'illusion' => 75], 40, $stats);
    }

    /** One player line to Aela through the real hooks; she answers in $mood. */
    private function turn(string $line, string $mood = 'flirty', string $type = 'inputtext'): void
    {
        $this->gamets += 5 * self::MINUTE;
        $this->realTs += 60;
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)',
            [self::AELA, $mood, $this->realTs]);
        $request = [$type, (string) $this->realTs, (string) (int) $this->gamets, $type === 'inputtext' ? self::PLAYER . ": {$line}" : $line];
        $party = '|' . self::AELA . '|' . self::PLAYER . '|';
        $hooks = $type === 'inputtext' ? ['prerequest.php', 'context.php', 'postrequest.php'] : ['postrequest.php'];
        foreach ($hooks as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $type === 'inputtext' ? self::AELA : 'The Narrator';
            if ($type === 'inputtext') $GLOBALS['RELDYN_NPC_NAME'] = self::AELA;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
    }

    private function dynamics(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Edit Aela's stored RelDyn state (as an older save or the editor would hold it). */
    private function editDynamics(callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $d = $ped['reldyn']['dynamics'];
        $edit($d);
        $ped['reldyn']['dynamics'] = $d;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1',
            [self::AELA, json_encode($ped)]);
    }

    /** Core's relationship to the player (CHIM 3.4.1 key "Player", RelationshipDynamics::PLAYER_RELATIONSHIP_KEY). */
    private function setCoreAff(int $aff): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb)
            WHERE npc_name = $1", [self::AELA, json_encode([RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => $aff, 'type' => 'platonic']])]);
    }

    /** A shared-contract eval item, queued the way the eval worker queues it. */
    private function queueEval(array $signals, array $tags, float $significance = 0.8): void
    {
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::AELA, [
            'v' => 1, 'npc' => self::AELA, 'npc_id' => 1, 'gamets' => (int) $this->gamets + random_int(1, 999), 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => true,
            'summary' => 'eval ' . bin2hex(random_bytes(3)),
        ]));
    }

    /** Aela's attraction for the player as the core rows stand now (RelDynPlayer::profile()). */
    private function attractionNow(array &$d): array
    {
        return RelationshipDynamics::updateAttraction(self::AELA, $d, RelDynPlayer::profile());
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    // ------------------------------------------------------------------ uphill fixtures

    /**
     * A bard: speechcraft (core's key) / illusion, level 40, no fighting skill, a
     * well-travelled, accomplished town figure. $speech: the speechcraft skill (charm, MDD 2.5).
     */
    private function bardWith(int $speech, ?int $companions = null): void
    {
        $stats = ['Persuasions' => 30, 'Quests Completed' => 200, 'Locations Discovered' => 250, 'Dungeons Cleared' => 90,
            'Houses Owned' => 2, 'Gold Found' => 30000, 'Questlines Completed' => 6];
        if ($companions !== null) $stats['The Companions Quests Completed'] = $companions;
        $this->playerBuild(['speechcraft' => $speech, 'illusion' => 75], 40, $stats);
    }

    /** A warrior's career at a fractional step (warriorAtStep's line, finer grained). */
    private function warriorAt(float $k): void
    {
        $this->playerBuild(
            ['onehanded' => (int) round(30 + 12 * $k), 'twohanded' => (int) round(25 + 10 * $k),
             'block' => (int) round(25 + 8 * $k), 'heavyarmor' => (int) round(25 + 10 * $k)],
            (int) round(10 + 6 * $k),
            [
                'People Killed' => (int) round(10 + 25 * $k), 'Creatures Killed' => (int) round(15 + 30 * $k),
                'Quests Completed' => (int) round(3 + 6 * $k), 'Dungeons Cleared' => (int) round(1 + 4 * $k),
                'Locations Discovered' => (int) round(10 + 15 * $k), 'The Companions Quests Completed' => (int) floor($k),
            ]);
    }

    /**
     * The attraction table these label-path mechanics run on: the shipped defaults plus Aela's
     * martial floor of 68 as a named preset (config npc_overrides floors, the per-NPC key that
     * still wins over the standards floor). Decisions §15 retired the hand-set 68 from the
     * defaults: her floor falls out of her traits (67.6 from her read, asserted in
     * testRulingEightUnderTheReadAssignment on the shipped config); on this label path she is
     * the Independent preset with her named 'medium' openness, whose standards floor is lower,
     * so the hill these tests measure is pinned where her read puts it. Her other pillars keep
     * her standards floor.
     */
    private static function labelPathAttraction(): array
    {
        $att = RelDynAttraction::defaults();
        $att['npc_overrides']['aela the huntress']['floors'] = ['strength' => 68.0];
        return $att;
    }

    /** Aela's config: the label-path table with $edit applied (the settings page's store). */
    private function attractionConfig(callable $edit, array $top = []): void
    {
        $att = self::labelPathAttraction();
        $edit($att);
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'attraction' => $att], $top))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Evaluate a copy of $d as a bond the Matrix first sees at core affinity $aff (grandfathered depth). */
    private function atAffinity(array $d, int $aff): array
    {
        $this->setCoreAff($aff);
        unset($d['_attraction_state'], $d['_attraction']);
        // the core affinity mirror the Matrix reads (getCoreAffinity), as a request refreshes it
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        RelationshipDynamics::updateAttraction(self::AELA, $d, RelDynPlayer::profile());
        return $d;
    }

    private function errorLogText(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    // ------------------------------------------------------------------ the spark, then the hill

    /**
     * Decisions §13: "a spark is open to anyone". A plain bard (Aela's lens sees no fighter in
     * him) warms her to 20 exactly as fast as a Companion does; past 20 his gains run at the
     * foot of her martial hill, about a tenth.
     */
    public function testSparkToTwentyForAPlainBardThenAboutATenth(): void
    {
        $this->bardWith(15);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $a = $this->attractionNow($d);

        $this->assertNull($a['hard_zero'], $a['reason']);
        $this->assertSame(20.0, $a['spark'], 'the spark: 20 passion points');
        $this->assertEqualsWithDelta($a['passion']['attachment'], $a['spark_mult'], 1e-4, 'below the spark: attachment only, no attraction factor');
        // Aela's martial floor: her derived definition (medium openness) -> 68 pillar points
        $unit = $a['passion']['units']['flexible:visceral'] ?? null;
        $this->assertNotNull($unit, json_encode($a['passion']));
        $this->assertSame(['strength'], $unit['pillars']);
        $this->assertSame(68.0, $unit['floor']);
        $def = RelDynAttraction::definition(self::AELA, $d);
        $this->assertSame('preset', $def['sources']['floors.strength'], 'Ken: her floor is high 60s in martial (pinned: labelPathAttraction)');
        $this->assertSame($def['standards']['floor'], $def['floors']['beauty'], "Aela's other pillars keep her standards floor");
        $this->assertLessThan(20.0, $unit['score'], 'a bard is far down her martial hill: ' . json_encode($unit));
        // Ken: "you'd be heavily penalized for being outside her attraction zone": ~0.1..0.12
        $this->assertGreaterThanOrEqual(0.1, $a['passion']['curve']);
        $this->assertLessThanOrEqual(0.125, $a['passion']['curve']);
        $this->assertEqualsWithDelta($a['passion']['curve'] * $a['passion']['attachment'], $a['passion_mult'], 1e-3);
        $this->assertTrue($a['friendzoned'], 'the label stays: her friend, her standing is met');
        $this->assertArrayNotHasKey('passion_cap', $a, 'no attraction cap anywhere (decisions §13)');

        // A warrior (step 4) for comparison, the same NPC: the spark phase is identical
        $bard = $d;
        $this->warriorAtStep(4);
        $warrior = $d;
        $w = $this->attractionNow($warrior);
        $this->assertEqualsWithDelta($a['spark_mult'], $w['spark_mult'], 1e-9, 'the spark does not ask who you are');
        $this->assertGreaterThan(3.0 * $a['passion_mult'], $w['passion_mult']);
        RelationshipDynamics::setPassion($bard, 0.0);
        RelationshipDynamics::setPassion($warrior, 0.0);
        // The real eval consumer, the same passionate moments for both, until each passes 20
        $steps = 0;
        $compared = 0;
        while (RelationshipDynamics::getPassion($bard) < 20.0 && $steps < 50) {
            // a step whose raw stays within the spark (raw x spark_mult below 20) runs at the spark rate for both
            $within = RelationshipDynamics::getPassion($bard) + 6.0 * $a['spark_mult'] <= 20.0;
            $lb = RelationshipDynamics::applyEvalSignal(self::AELA, $bard, 'passion', 6.0, ['quality_time'], 0.8);
            $lw = RelationshipDynamics::applyEvalSignal(self::AELA, $warrior, 'passion', 6.0, ['quality_time'], 0.8);
            if ($within) {
                $this->assertEqualsWithDelta($lw['actual'], $lb['actual'], 1e-9, "below the spark the bard's gain is the warrior's ({$lb['line']} / {$lw['line']})");
                $this->assertGreaterThan(0.0, $lb['actual']);
                $compared++;
            }
            $steps++;
        }
        $this->assertGreaterThanOrEqual(2, $compared, 'several spark steps compared');
        $this->assertGreaterThanOrEqual(20.0, RelationshipDynamics::getPassion($bard), 'the bard reached the spark');
        $this->assertLessThan(20.5, RelationshipDynamics::getPassion($bard), 'the crossing step is split at 20: little past it');

        // Past the spark: the bard's gain is ~0.12x the raw, a fraction of the warrior's
        RelationshipDynamics::setPassion($bard, 30.0);
        RelationshipDynamics::setPassion($warrior, 30.0);
        $gb = RelationshipDynamics::gainPassion(self::AELA, $bard, 10.0, 'reunion');
        $gw = RelationshipDynamics::gainPassion(self::AELA, $warrior, 10.0, 'reunion');
        $this->assertEqualsWithDelta(10.0 * $a['passion_mult'], $gb, 1e-6, 'above the spark: raw x curve x attachment');
        $this->assertEqualsWithDelta(10.0 * $w['passion_mult'], $gw, 1e-6);
        $this->assertLessThan(0.3 * $gw, $gb);

        // A gain that crosses the spark is split there (gainFactor): 15 -> 20 at the spark rate,
        // the rest of the raw at the hill's
        RelationshipDynamics::setPassion($bard, 15.0);
        $raw = 12.0;
        $toSpark = 5.0 / $a['spark_mult'];
        $expected = 5.0 + ($raw - $toSpark) * $a['passion_mult'];
        $this->assertEqualsWithDelta($expected, RelationshipDynamics::gainPassion(self::AELA, $bard, $raw, 'repair'), 1e-6);
        $this->assertEqualsWithDelta(15.0 + $expected, RelationshipDynamics::getPassion($bard), 1e-6);
        $this->assertNoDbFailures();
    }

    /**
     * Charm climbs the hill (MDD 2.5, decisions §13: "a super-charming bard can win an atypical
     * interest, slowly"). The same bard, speechcraft 15 or 100, courts Aela one evening a game
     * day. Charm does not change his martial score (it is not substance); it closes up to 15%
     * of the gap between the foot of her hill and her floor, which on the hill's flat foot is
     * what decides whether he climbs at all: the plain bard hovers at the spark, the silver
     * tongue climbs, slowly, and after game months wins her over (MDD 8.1's "Friendzone
     * limit"). Every visit comes after half an hour of play on the play clock (so the passion
     * fade and the session's diminishing returns run as in play).
     */
    public function testASilverTongueClimbsFasterAndWinsHerOverSlowly(): void
    {
        $runs = [];
        [$g0, $r0] = [$this->gamets, $this->realTs];
        foreach (['plain' => [15, 30], 'silver' => [100, 150]] as $who => [$speech, $maxDays]) {
            // the same evenings for both: Aela's RelDyn state, the logs and the clocks start over
            pg_query_params($this->db->link, "UPDATE core_npc_master SET plugin_extended_data = '{}'::jsonb WHERE npc_name = $1", [self::AELA]);
            pg_query($this->db->link, 'DELETE FROM eventlog');
            pg_query($this->db->link, 'DELETE FROM moods_issued');
            [$this->gamets, $this->realTs] = [$g0, $r0];
            $this->bardWith($speech);
            $this->setCoreAff(40);
            $this->turn('A song for the Huntress?');
            $d = $this->dynamics();
            $a = $this->attractionNow($d);
            $runs[$who] = ['a' => $a, 'passion' => [], 'won_day' => null];
            // The spark is behind them: passion at 20 (an older evening), then one visit a game
            // day (20 game hours apart: no absence fade), each with a passionate moment the eval scores
            $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 20.0); });
            for ($day = 1; $day <= $maxDays; $day++) {
                $this->gamets += 20 * self::DAY / 24;
                $this->realTs += 3600;
                // half an hour of play since the last visit (the play heartbeat reads core's
                // eventlog poll rows, which this test does not log)
                $this->editDynamics(function (array &$d): void {
                    $d['_accumulated_play_gamets'] = floatval($d['_accumulated_play_gamets'] ?? 0) + 0.5 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
                });
                $this->queueEval(['passion' => 12], ['quality_time']);
                $this->turn('Walk with me a while?', 'default');
                $now = $this->dynamics();
                $runs[$who]['passion'][$day] = RelationshipDynamics::getPassion($now);
                if (!empty($now['_attraction']['won_over'])) {
                    $runs[$who]['won_day'] = $day;
                    break;
                }
            }
        }
        $plain = $runs['plain'];
        $silver = $runs['silver'];
        $why = json_encode(['plain' => $plain['passion'], 'silver' => $silver['passion']]);
        // Charm climbs the hill: the same martial score, a better curve (15% of the gap to her
        // floor), still far below her floor
        $pu = $plain['a']['passion']['units']['flexible:visceral'];
        $su = $silver['a']['passion']['units']['flexible:visceral'];
        $this->assertSame($pu['score'], $su['score'], 'the curve reads the lens score before the speech lift');
        $this->assertSame($pu['m_hill'], $su['m_hill']);
        $this->assertEqualsWithDelta($su['m_hill'] + (1 - $su['m_hill']) * 0.15, $su['m'], 1e-3, 'speech 100 closes 15% of the gap');
        $this->assertGreaterThan(1.5 * $plain['a']['passion']['curve'], $silver['a']['passion']['curve'], 'charm is a real lever, not 0.25%');
        $this->assertLessThan(0.3, $silver['a']['passion']['curve'], 'a silver tongue does not replace substance');
        $this->assertGreaterThan($plain['a']['pillars']['strength']['score'], $silver['a']['pillars']['strength']['score'],
            'the pillar score keeps the MDD 2.5 lift (bars, tiers)');
        // The plain bard: the spark, and the foot of her hill barely holds him above it
        $this->assertNull($plain['won_day'], $why);
        $this->assertLessThan(22.0, max($plain['passion']), 'the plain bard hovers at the spark: ' . $why);
        // The silver tongue: past the spark and still climbing a month on, slowly
        $p = $silver['passion'];
        $this->assertGreaterThan($plain['passion'][30] + 3.0, $p[30], 'charm climbs: ' . $why);
        $this->assertGreaterThan($p[10], $p[30], 'still climbing');
        $this->assertLessThan(30.0, $p[30], 'slowly (an uphill): ' . $why);
        // ... and wins her over after game months of courting (decisions §13): past the MDD 8.1
        // "Friendzone limit" (40) her passion reads as drawn, not a friend's
        $this->assertNotNull($silver['won_day'], 'won over within 150 game days: ' . $why);
        $this->assertGreaterThan(60, $silver['won_day'], 'not quickly: ' . $why);
        $this->assertGreaterThanOrEqual(40.0, end($p));
        $won = $this->dynamics()['_attraction'];
        $this->assertTrue($won['won_over'], json_encode($won));
        $this->assertTrue($won['attracted']);
        $this->assertFalse($won['friendzoned']);
        $this->assertSame('drawn', $won['outcome'], 'her Companions standing unknown: her generic standing holds');
        $this->assertLessThan(1.0, $won['passion']['curve'], 'still an uphill: being won over does not change the rate');
        $this->assertEquals(50.0, $won['passion_ceiling'], 'MDD 1.4 medium: a failed pillar cuts the passion ceiling 50%');
        $this->assertSame(2, $won['romance']['allowed'], 'the romance axis opens (her standing met); lifts still wait on significant moments');
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ ruling #8 under the read assignment

    /**
     * Personality traits phase 2 (the shipped default, traits.assignment 'read'): Aela is her
     * own trait vector, the committed seed's bio read over her priors (Commander voice from
     * npc_templates_v2, Ranger class, the Companions, Nord). Her template here is placeholder
     * text (no bio is committed) and her read row is keyed to it with the seed's own result.
     */
    private function aelaUnderTheReadAssignment(): void
    {
        RelDynTraits::$assignmentOverride = 'read';
        // the shipped config: no pinned floor, hers falls out of her read (decisions §15)
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function (): void {};
        RelDynTraitRead::$llm = function () { $this->fail('no LLM call: her read is the seed'); };
        pg_query($this->db->link, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($this->db->link, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        $key = 'aela_the_huntress';
        $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
        pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
            $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
        pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, 'sk_femalecommander']);
        RelDynTraitRead::ensureTable();
        $e = RelDynTraitRead::loadSeedFile()['reads'][$key];
        pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
            VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
            [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
    }

    /** A charming fighter: speechcraft $speech on top of the warrior's career at step $k. */
    private function charmingFighter(int $speech, float $k): void
    {
        $this->warriorAt($k);
        $skills = json_decode(pg_fetch_assoc(pg_query($this->db->link, "SELECT value FROM core_player WHERE id = 'skills'"))['value'], true);
        $this->corePlayer('skills', array_merge($skills, ['speechcraft' => $speech, 'illusion' => 75]));
    }

    /**
     * One evening a game day of courting from passion 20 (the loop of the silver-tongue test).
     * Returns ['a' => attraction at the start, 'passion' => day => passion, 'won_day' => ?int].
     */
    private function courtAela(callable $build, int $maxDays): array
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET plugin_extended_data = '{}'::jsonb WHERE npc_name = $1", [self::AELA]);
        pg_query($this->db->link, 'DELETE FROM eventlog');
        pg_query($this->db->link, 'DELETE FROM moods_issued');
        pg_query($this->db->link, 'DELETE FROM core_player');
        $build();
        $this->setCoreAff(40);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $run = ['a' => $this->attractionNow($d), 'd' => $d, 'passion' => [], 'won_day' => null];
        $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 20.0); });
        for ($day = 1; $day <= $maxDays; $day++) {
            $this->gamets += 20 * self::DAY / 24;
            $this->realTs += 3600;
            $this->editDynamics(function (array &$d): void {
                $d['_accumulated_play_gamets'] = floatval($d['_accumulated_play_gamets'] ?? 0) + 0.5 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
            });
            $this->queueEval(['passion' => 12], ['quality_time']);
            $this->turn('Walk with me a while?', 'default');
            $now = $this->dynamics();
            $run['passion'][$day] = round(RelationshipDynamics::getPassion($now), 2);
            $run['outcome'][$day] = $now['_attraction']['outcome'] ?? null;
            if (!empty($now['_attraction']['won_over'])) {
                $run['won_day'] = $day;
                $run['won'] = $now['_attraction'];
                break;
            }
        }
        return $run;
    }

    /**
     * Ruling #8 under the shipped read assignment (review 2026-09-25: the silver-tongue test
     * above pins the phase-1 label path, where Aela is the Independent preset; there a pure
     * silver tongue wins her over on day ~115). Ken: "must be steep, not everyone is for
     * everyone. Won-over stays possible but steep; a plain bard doesn't get there, only strong
     * charm plus partial fit might." Her own vector (openness medium from her traits, so the
     * won-over switch is on) loses passion faster than the Independent preset between evenings
     * (passion Y_down about 0.88 against 0.70), so:
     *   - the plain bard hovers at the spark and is never won over;
     *   - a pure silver tongue climbs (charm is a real lever) but levels off in the mid 30s,
     *     below the won-over line of 40, in 150 game days: charm alone does not get there;
     *   - strong charm plus a partial martial fit (a green sellsword, far below her floor in the 60s)
     *     draws her: attracted from the first evening, below her floor, and passion past 40
     *     only after weeks of courting; the same fighter without the charm is still short of
     *     40 after two months.
     */
    public function testRulingEightUnderTheReadAssignment(): void
    {
        $this->aelaUnderTheReadAssignment();
        $clock = [$this->gamets, $this->realTs];
        $run = function (callable $build, int $days) use ($clock): array {
            [$this->gamets, $this->realTs] = $clock;
            return $this->courtAela($build, $days);
        };
        $plain = $run(fn() => $this->bardWith(15), 150);
        $silver = $run(fn() => $this->bardWith(100), 150);
        $fit = $run(fn() => $this->charmingFighter(15, 1.0), 60);
        $charmFit = $run(fn() => $this->charmingFighter(100, 1.0), 60);
        $why = json_encode(['plain' => $plain['passion'], 'silver' => $silver['passion'], 'fit' => $fit['passion'], 'charm+fit' => $charmFit['passion']]);

        // her own vector, from her read (no LLM call: the stub fails the test on one)
        $src = $plain['d']['_trait_vector_src'];
        $this->assertSame('read', $src['assignment']);
        $this->assertSame('read', $src['auto_source']);
        $this->assertSame('medium', RelDynAttraction::definition(self::AELA, $plain['d'])['openness'], 'openness from her traits: the won-over switch is on');
        // her floor falls out of her traits (decisions §15, design §5.1's formula and weights), on
        // every pillar: her centred read (o .462, M 54.5, C .736) gives 61.8, below the design's
        // assumed-vector 67.6 (design §6.2's 66-70 was that vector's; flagged for Ken)
        $def = RelDynAttraction::definition(self::AELA, $plain['d']);
        $this->assertSame('standards', $def['sources']['floors']);
        $this->assertArrayNotHasKey('floors.strength', $def['sources'], 'no hand-set floor');
        $this->assertEqualsWithDelta(61.8, $def['floors']['strength'], 0.5);
        $this->assertEqualsWithDelta($def['floors']['strength'], $charmFit['a']['passion']['units']['flexible:visceral']['floor'], 0.01, 'the unit reads it');
        $this->assertGreaterThan(0.8, RelDynTraits::value(RelDynTraits::readVector($plain['d']), 'y_passion_down'));

        // a plain bard never gets there
        foreach (['plain' => $plain, 'silver' => $silver] as $who => $r) {
            $this->assertTrue($r['a']['friendzoned'], "{$who}: no fit at all");
            $this->assertNull($r['won_day'], "{$who}: {$why}");
        }
        $this->assertLessThan(22.0, max($plain['passion']), 'the plain bard hovers at the spark: ' . $why);
        // charm alone climbs, but not to the line
        $this->assertGreaterThan(max($plain['passion']) + 8.0, max($silver['passion']), 'charm is a real lever: ' . $why);
        $this->assertLessThan(40.0, max($silver['passion']), 'charm alone does not win her over: ' . $why);
        $this->assertLessThan(3.0, max($silver['passion']) - $silver['passion'][150], 'it levels off: ' . $why);
        // strong charm plus partial fit might: drawn, far below her floor, and slowly past 40
        $u = $charmFit['a']['passion']['units']['flexible:visceral'];
        $this->assertTrue($charmFit['a']['attracted'], $charmFit['a']['reason']);
        $this->assertSame('drawn', $charmFit['a']['outcome'], $charmFit['a']['reason']);
        $this->assertTrue($charmFit['a']['below_floor']);
        $this->assertLessThan(45.0, $u['score'], 'a partial fit: far below her martial floor in the 60s');
        $first40 = array_key_first(array_filter($charmFit['passion'], fn($p) => $p >= 40.0));
        $this->assertNotNull($first40, 'strong charm plus partial fit gets there: ' . $why);
        $this->assertGreaterThan(20, $first40, 'steep: weeks of courting: ' . $why);
        // ... the fit without the charm does not, in the same two months
        $this->assertTrue($fit['a']['attracted']);
        $this->assertNotSame('drawn', $fit['a']['outcome'], $fit['a']['reason']);
        $this->assertLessThan(40.0, max($fit['passion']), $why);
        $this->assertLessThan($charmFit['passion'][60] - 5.0, $fit['passion'][60], $why);
        $this->assertNoDbFailures();
    }

    /**
     * A growing warrior rises smoothly through Aela's floor: the multiplier never jumps, it is
     * below 1 below the floor, exactly 1.0 at it and ~1.05 five points above.
     */
    public function testAGrowingWarriorRisesSmoothlyThroughTheFloor(): void
    {
        $this->warriorAt(0.0);
        $this->turn('I heard the Companions take on sellswords.');
        $d = $this->dynamics();
        $cc = RelDynAttraction::curveConfig();
        $rows = [];
        for ($k = 0.0; $k <= 9.0001; $k += 0.5) {
            $this->warriorAt($k);
            $a = $this->attractionNow($d);
            $u = $a['passion']['units']['flexible:visceral'];
            $rows[] = ['k' => $k, 'score' => $u['score'], 'm' => $u['m'], 'curve' => $a['passion']['curve'], 'mult' => $a['passion_mult']];
        }
        $crossed = false;
        for ($i = 1; $i < count($rows); $i++) {
            [$p, $c] = [$rows[$i - 1], $rows[$i]];
            $why = json_encode([$p, $c]);
            $this->assertGreaterThanOrEqual($p['score'], $c['score'], $why);
            $this->assertGreaterThanOrEqual($p['m'], $c['m'], 'never falls as she grows: ' . $why);
            // Smooth: no step larger than the hill's steepest slope (at the floor:
            // (1 - m_min) x steepness / floor per point) times the score step
            $slope = (1.0 - $cc['m_min']) * $cc['steepness'] / 68.0;
            $this->assertLessThanOrEqual($slope * ($c['score'] - $p['score']) + 2e-4, $c['m'] - $p['m'], 'no jump: ' . $why);
            if ($c['score'] < 68.0) $this->assertLessThan(1.0, $c['m'], $why);
            if ($c['score'] >= 68.0) {
                $crossed = true;
                $this->assertEqualsWithDelta(min($cc['surplus_max'], 1.0 + 0.01 * ($c['score'] - 68.0)), $c['m'], 2e-4, $why);
            }
        }
        $this->assertTrue($crossed, 'the warrior reaches her floor: ' . json_encode($rows));
        $this->assertLessThan(0.5, $rows[0]['m'], 'the green sellsword starts well down the hill');

        // Exactly at the floor: 1.0x; five points above it: 1.05x (the floor set to the warrior's
        // own score, the editor's per-NPC floor)
        $this->warriorAt(5.0);
        $s = $this->attractionNow($d)['passion']['units']['flexible:visceral']['score'];
        $d['attraction_overrides'] = ['floors' => ['strength' => $s]];
        $at = $this->attractionNow($d);
        $this->assertSame(1.0, $at['passion']['units']['flexible:visceral']['m'], json_encode($at['passion']));
        $this->assertSame(1.0, $at['passion']['curve'], 'beauty unknown (no appearance): strength is the only unit');
        $this->assertEqualsWithDelta($at['passion']['attachment'], $at['passion_mult'], 1e-4, '1.0x at the floor, x attachment');
        $d['attraction_overrides'] = ['floors' => ['strength' => $s - 5.0]];
        $plus5 = $this->attractionNow($d);
        $this->assertEqualsWithDelta(1.05, $plus5['passion']['units']['flexible:visceral']['m'], 1e-4);
        $this->assertEqualsWithDelta(1.05, $plus5['passion']['curve'], 1e-4);
        $this->assertFalse($plus5['below_floor']);
        $this->assertTrue($plus5['passes']);

        // Ken's reference points on the pure curve (floor 68, m_min 0.1, steepness 3)
        $this->assertEqualsWithDelta(0.12, RelDynAttraction::pillarMult(20.0, 68.0), 0.01);
        $this->assertEqualsWithDelta(0.37, RelDynAttraction::pillarMult(45.0, 68.0), 0.015);
        $this->assertEqualsWithDelta(0.71, RelDynAttraction::pillarMult(60.0, 68.0), 0.015);
        $this->assertSame(1.0, RelDynAttraction::pillarMult(68.0, 68.0));
        $this->assertEqualsWithDelta(1.05, RelDynAttraction::pillarMult(73.0, 68.0), 1e-9);
        $this->assertEqualsWithDelta(1.25, RelDynAttraction::pillarMult(100.0, 68.0), 1e-9, 'the surplus cap');
        // Ken's "at 45 you get 1x, at 50 1.05, 55 1.10"
        $this->assertEqualsWithDelta(1.10, RelDynAttraction::pillarMult(55.0, 45.0), 1e-9);
        // Several units: all met = the mean surplus; one below = the weakest sets the scale
        $this->assertEqualsWithDelta(1.1, RelDynAttraction::combineUnits([1.0, 1.2]), 1e-9);
        $this->assertEqualsWithDelta(0.3 * 1.1, RelDynAttraction::combineUnits([0.3, 1.2]), 1e-9);
        $this->assertEqualsWithDelta(0.3, RelDynAttraction::combineUnits([0.3, 0.8]), 1e-9);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ non-negotiables

    /**
     * Hard zero only for the non-negotiable (decisions §13), and it takes the spark too: an
     * NPC who cannot be drawn to the player at all (MDD 1.4 low openness = "hard block"; the
     * attraction memory: rigid = "must pass, non-negotiable"; plan §3 gender preference) feels
     * no passion from 0 either. Everything else climbs the hill.
     */
    public function testNonNegotiablesAreExactlyZeroFromZeroAndAboveTheSpark(): void
    {
        $this->warriorAtStep(5);                       // a Companion she would otherwise want
        $this->corePlayer('gender', 'female');
        $this->turn('Good hunting today.');

        $cases = [
            'orientation' => function (array &$d): void { $d['attraction_overrides'] = ['gender_pref' => 'heterosexual']; },
            'rigid:status' => function (array &$d): void {
                // bond-gated: every pillar moves passion (MDD 8.2 A); status rigid, no Companions standing
                $d['attraction_overrides'] = ['gate' => 'bond'];
            },
            // (asexual is no longer one: its passion is emotional, decisions §15, below)
            'preference:aromantic' => function (array &$d): void { $d['relationship_preference'] = 'aromantic'; },
        ];
        foreach ($cases as $expect => $setup) {
            if ($expect === 'rigid:status') $this->corePlayer('The Companions Quests Completed', '0');
            if ($expect === 'preference:aromantic') {
                $this->attractionConfig(function (array &$att): void {}, ['type_filter_enabled' => true]);
            }
            $d = $this->dynamics();
            $setup($d);
            $a = $this->attractionNow($d);
            $this->assertSame($expect, $a['hard_zero'], $a['reason']);
            $this->assertSame(0.0, $a['spark_mult'], "{$expect}: no spark");
            $this->assertSame(0.0, $a['passion_mult'], "{$expect}: nothing above it");
            foreach ([0.0, 30.0] as $from) {
                RelationshipDynamics::setPassion($d, $from);
                $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 15.0, 'reunion'), "{$expect} from {$from}");
                $line = RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 20.0, ['rescue'], 0.9);
                $this->assertSame(0.0, $line['actual'], "{$expect} from {$from}: {$line['line']}");
                $this->assertSame(0.0, RelationshipDynamics::applyDelta('passion', $d, 5.0, 'Guarded'), "{$expect} from {$from}");
                RelationshipDynamics::executeHoover($d, self::AELA, 'Guarded');
                $this->assertSame($from, RelationshipDynamics::getPassion($d), "{$expect} from {$from}: exactly no gain on any path");
            }
            $this->assertStringContainsString("hard zero: {$expect}", $this->errorLogText(), 'the passion log names why');
        }

        // Asexual (decisions §15): passion is emotional, not zero. Every path that is no emotional
        // channel adds exactly nothing (a reunion, a rescue, the untagged dimension engine, the
        // hoover, sex), the spark included; the emotional channels move it like anyone's.
        $d = $this->dynamics();
        $d['relationship_preference'] = 'asexual';
        $a = $this->attractionNow($d);
        $this->assertNull($a['hard_zero'], $a['reason']);
        $this->assertSame('emotional', $a['passion_channel']);
        $this->assertGreaterThan(0.0, $a['spark_mult']);
        foreach ([0.0, 30.0] as $from) {
            RelationshipDynamics::setPassion($d, $from);
            $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 15.0, 'reunion'), "asexual from {$from}");
            foreach ([['rescue'], ['intimacy'], ['touch', 'intimacy']] as $tags) {
                $line = RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 20.0, $tags, 0.9);
                $this->assertSame(0.0, $line['actual'], "asexual from {$from}: {$line['line']}");
                $this->assertStringContainsString('emotional passion only', $line['line']);
            }
            $this->assertSame(0.0, RelationshipDynamics::applyDelta('passion', $d, 5.0, 'Guarded'), "asexual from {$from}: untagged");
            RelationshipDynamics::executeHoover($d, self::AELA, 'Guarded');
            $this->assertSame($from, RelationshipDynamics::getPassion($d), "asexual from {$from}: nothing from the closed paths");
            $line = RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 8.0, ['quality_time'], 0.9);
            $this->assertGreaterThan(0.0, $line['actual'], "asexual from {$from}: quality time moves her ({$line['line']})");
            $this->assertGreaterThan(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 5.0, 'love_match', ['praise']), "asexual from {$from}: words");
        }
        $this->assertStringContainsString('channel closed: emotional passion only', $this->errorLogText(), 'the passion log names why');

        // A rigid pillar is a gate on its bar, not a hill (Ken: rigid, non-negotiable pillars
        // stay a hard zero; memory "Rigid: must pass. Non-negotiable"): one Companions quest is
        // some standing, still below her bar, so still exactly zero; enough standing to pass
        // opens it, and a passed rigid pillar counts as her floor met (x1.0), not a hill
        $this->attractionConfig(function (array &$att): void {});
        $d = $this->dynamics();
        $d['attraction_overrides'] = ['gate' => 'bond'];
        foreach (['1' => false, '12' => true] as $quests => $opens) {
            $this->corePlayer('The Companions Quests Completed', $quests);
            $a = $this->attractionNow($d);
            $status = $a['pillars']['status'];
            $why = $a['reason'] . ' ' . json_encode([$status, $a['passion']['units']['status'] ?? null]);
            $this->assertGreaterThan(0.0, $status['score'], $why);
            if (!$opens) {
                $this->assertFalse($status['pass'], $why);
                $this->assertSame('rigid:status', $a['hard_zero'], "{$quests} quest(s): below the bar is a hard zero, not a steep hill: {$why}");
                $this->assertSame(0.0, $a['spark_mult']);
                $this->assertSame(0.0, $a['passion_mult']);
                $this->assertFalse($a['passion']['units']['status']['met']);
            } else {
                $this->assertTrue($status['pass'], $why);
                $this->assertNull($a['hard_zero'], $why);
                $this->assertGreaterThan(0.0, $a['spark_mult']);
                $this->assertGreaterThanOrEqual(1.0, $a['passion']['units']['status']['m'], 'a passed rigid pillar is met: ' . $why);
            }
        }
        $this->assertNoDbFailures();
    }

    /** The MDD 6.2 hard cap of 20 is retired (decisions §13): passion is never cut back for attraction reasons. */
    public function testNoHardCapAtTwentyForAttractionAnywhere(): void
    {
        $this->bardWith(30);
        $this->setCoreAff(45);
        $this->turn('A song for the Huntress?');
        $a = $this->dynamics()['_attraction'];
        $this->assertTrue($a['friendzoned'], 'the label is kept');
        $this->assertArrayNotHasKey('passion_cap', $a);
        $this->assertSame('friendzone', RelationshipDynamics::getRelationshipType(self::AELA, $this->dynamics()),
            'the friendzone type overlay (friend tier) still reads the label');

        // An older save holds passion 60: the next requests keep it (only decay moves it)
        $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 60.0); });
        $this->queueEval(['passion' => 10], ['quality_time']);
        $this->turn('Just one more song.');
        $d = $this->dynamics();
        $this->assertGreaterThan(55.0, RelationshipDynamics::getPassion($d), 'not dropped to 20');
        RelationshipDynamics::setPassion($d, 55.0);
        $this->assertSame(55.0, RelationshipDynamics::getPassion($d), 'setPassion holds no attraction cap');
        $d['stage'] = RelationshipDynamics::STAGE_EARLY;
        RelationshipDynamics::addPassion($d, 30.0, 'love_match');
        $this->assertSame(85.0, RelationshipDynamics::getPassion($d), 'addPassion: only the stage ceiling (early 100) and passion_max');
        RelationshipDynamics::setPassion($d, 25.0);
        $this->assertGreaterThan(0.0, RelationshipDynamics::applyDelta('passion', $d, 5.0, 'Guarded'), 'the dimension engine past 20');
        $this->assertGreaterThan(25.0, RelationshipDynamics::getPassion($d));
        // Other caps stay: the deep stage's ceiling (50)
        $d['stage'] = RelationshipDynamics::STAGE_DEEP;
        RelationshipDynamics::setPassion($d, 45.0);
        RelationshipDynamics::addPassion($d, 30.0, 'love_match');
        $this->assertSame(50.0, RelationshipDynamics::getPassion($d), 'stage caps intact');
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ bond relief

    /**
     * A bond grown the way play grows it: core affinity moves, the Matrix re-evaluates with
     * its tracked state (no grandfathering), and each lift it opens is earned through
     * significant interactions (recordSignificance, as the eval consumer calls it).
     */
    private function growBondTo(array &$d, int $aff): array
    {
        $this->setCoreAff($aff);
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        $a = $this->attractionNow($d);
        for ($i = 0; $i < 60 && !empty($d['_attraction_state']['pending']); $i++) {
            RelDynAttraction::recordSignificance(self::AELA, $d, 0.9, true);
            $a = $this->attractionNow($d);
        }
        return $a;
    }

    /** Aela as a balanced, Guard-like NPC in the editor (plan §3 Guard row), floor 45 on strength. */
    private function guardLike(array &$d, array $extra = []): void
    {
        $d['attraction_overrides'] = array_replace([
            'gate' => 'balanced', 'openness' => 'low', 'floors' => ['strength' => 45.0],
            'rigidity' => ['beauty' => 'soft', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'rigid'],
        ], $extra);
        unset($d['_attraction_state'], $d['_attraction']);
    }

    /**
     * Balanced NPCs (decisions §13): the bond flattens the visceral hill, gradually, and the
     * bond can actually get there in play: a balanced NPC's visceral pillars never hold its
     * depth (plan §7 "visceral pass OR bonded tier"), so the bonded tier is reachable through
     * earned lifts, not only grandfathered, and there the visceral pillar counts as met.
     */
    public function testBalancedNpcBondFlattensTheVisceralHillAndReachesBondedInPlay(): void
    {
        $this->bardWith(15);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        // Her competence rigid (the Guard row): the bard's deeds pass its bar (0.35) but not
        // the bonded tier's 0.5, a SOCIOLOGICAL gate (MDD 2.6: tier advancement past Fond),
        // which the bond does not replace: the bond stops at close friend, and so does relief
        $this->guardLike($d);
        foreach ([10, 45, 76, 90] as $aff) $a = $this->growBondTo($d, $aff);
        $this->assertSame('', $a['grandfathered']['depth']);
        $this->assertSame('close_friend', $a['allowed_tier'], $a['reason']);
        $this->assertFalse(RelDynAttraction::definition(self::AELA, $d)['rigidity']['competence'] !== 'rigid');
        $this->assertLessThan(1.0, $a['passion']['relief']);
        $this->assertSame('friendzone', $a['outcome']);
        // Competence soft: nothing sociological holds the bond, and the visceral side never did
        $this->guardLike($d, ['rigidity' => ['beauty' => 'soft', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'soft']]);
        $rows = [];
        foreach ([10, 31, 45, 60, 70, 76, 90] as $aff) {
            $a = $this->growBondTo($d, $aff);
            $rows[$aff] = ['vis' => $a['passion']['units']['flexible:visceral'], 'relief' => $a['passion']['relief'],
                'curve' => $a['passion']['curve'], 'allowed' => $a['allowed_tier'], 'eff' => $a['ceiling_tier'],
                'outcome' => $a['outcome'], 'grandfathered' => $a['grandfathered']];
        }
        $why = json_encode($rows);
        foreach ($rows as $r) $this->assertSame('', $r['grandfathered']['depth'], 'nothing grandfathered: ' . $why);
        $this->assertFalse($rows[10]['vis']['met'], 'the bard fails her strength bar: ' . $why);
        $this->assertSame(0.0, $rows[10]['relief'], 'an acquaintance: no relief');
        $this->assertSame(0.0, $rows[31]['relief'], 'relief starts at the friend tier');
        $prev = null;
        foreach ([31, 45, 60, 70, 76] as $aff) {
            if ($prev !== null) {
                $this->assertGreaterThan($rows[$prev]['vis']['m'], $rows[$aff]['vis']['m'], "gradual: {$prev} -> {$aff} {$why}");
                $this->assertGreaterThan($rows[$prev]['relief'], $rows[$aff]['relief']);
            }
            $prev = $aff;
        }
        $this->assertSame(1.0, $rows[76]['relief'], 'the bonded tier, reached in play: ' . $why);
        $this->assertSame(1.0, $rows[76]['vis']['m'], 'at the bonded tier the visceral pillar counts as met');
        $this->assertSame(1.0, $rows[90]['vis']['m']);
        foreach ($rows as $r) $this->assertSame($rows[10]['vis']['m_hill'], $r['vis']['m_hill'], 'the hill itself is unchanged; the bond eases its penalty');
        foreach ([10, 31, 45, 60, 70] as $aff) $this->assertSame('friendzone', $rows[$aff]['outcome'], "the label holds below bonded ({$aff}): {$why}");
        $this->assertSame('drawn', $rows[76]['outcome'], 'bonded: her visceral pillars count as met: ' . $why);

        // The sociological hill keeps its curve: her passion read on strength and her
        // Companions standing (one quest, far below her floor; soft, so it holds no depth and
        // the bond can grow), the bond grown the same way
        $this->bardWith(15, 1);
        $d = $this->dynamics();
        $this->guardLike($d, ['passion_pillars' => ['strength', 'status'],
            'rigidity' => ['beauty' => 'soft', 'strength' => 'flexible', 'status' => 'soft', 'competence' => 'soft']]);
        $soc = [];
        foreach ([10, 45, 76, 90] as $aff) {
            $a = $this->growBondTo($d, $aff);
            $soc[$aff] = ['soc' => $a['passion']['units']['soft:sociological'] ?? null, 'vis' => $a['passion']['units']['flexible:visceral'],
                'curve' => $a['passion']['curve'], 'relief' => $a['passion']['relief']];
        }
        $why = json_encode($soc);
        $this->assertNotNull($soc[10]['soc'], $why);
        $this->assertLessThan(0.7, $soc[10]['soc']['m'], 'her Companions standing is well below her floor: ' . $why);
        $this->assertSame(1.0, $soc[90]['relief'], $why);
        foreach ($soc as $aff => $r) $this->assertSame($soc[10]['soc']['m'], $r['soc']['m'], "the sociological hill keeps its curve at {$aff}");
        $this->assertGreaterThan($soc[10]['vis']['m'], $soc[45]['vis']['m'], 'the visceral hill eases: ' . $why);
        $this->assertNoDbFailures();
    }

    /**
     * The bond relief eases the RATE of passion, not the label (review: at core affinity 34 a
     * relief of ~0.07 used to lift the curve over the friendzone line, switching off the
     * friendzone, the friendzone type and Sharmat's consent block while the player still failed
     * her visceral pass and romance stayed at 0). Below the bonded tier the balanced NPC stays
     * friendzoned: no intimacy, the consent block holds, flirtation is kindly deflected.
     */
    public function testBalancedBondReliefEasesTheRateNotTheLabel(): void
    {
        $this->bardWith(15);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $this->guardLike($d);
        $prevMult = null;
        foreach ([31, 33, 34, 36, 45, 60, 70, 75] as $aff) {
            $a = $this->growBondTo($d, $aff);
            $why = "aff {$aff}: {$a['reason']} " . json_encode($a['passion']);
            $this->assertSame('friendzone', $a['outcome'], $why);
            $this->assertTrue($a['friendzoned'], $why);
            $this->assertFalse($a['attracted'], $why);
            $this->assertFalse($a['intimacy_allowed'], $why);
            $this->assertSame(0, $a['romance']['allowed'], $why);
            $state = RelDynRomance::buildState(self::AELA, $d, null, null);
            $this->assertTrue($state['consent_block'], $why);
            $this->assertContains('friendzoned', $state['block_reasons'], $why);
            $this->assertFalse($state['attraction_pass'], $why);
            $felt = (string) RelDynAttraction::feltText(self::AELA, $d['_attraction'], ['tier' => 2, 'passion' => 15.0]);
            $this->assertStringContainsString('kind deflection', $felt, $why);
            if ($aff >= 34 && $prevMult !== null) $this->assertGreaterThan($prevMult, $a['passion_mult'], 'the rate eases: ' . $why);
            $prevMult = $a['passion_mult'];
        }
        $this->assertGreaterThan(0.9, $d['_attraction']['passion']['relief'], 'nearly bonded: the rate nearly met, the label still holds');
        $this->assertNoDbFailures();
    }

    /** Visceral-type NPCs get no bond relief (decisions §13): Aela as she is, the bard bonded or not. */
    public function testVisceralNpcGetsNoBondRelief(): void
    {
        $this->bardWith(15);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $low = $this->atAffinity($d, 10)['_attraction'];
        $high = $this->atAffinity($d, 90)['_attraction'];
        $this->assertSame('visceral', $low['gate']);
        $this->assertSame('visceral', $high['gate']);
        $this->assertSame(0.0, $high['passion']['relief']);
        $this->assertSame($low['passion']['units']['flexible:visceral']['m'], $high['passion']['units']['flexible:visceral']['m'],
            'devoted or a stranger, her martial hill is the same');
        $this->assertLessThan(0.13, $high['passion']['curve']);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ review fixes (rigid, floors, label, ceiling)

    /**
     * A rigid pillar is a gate on its MDD bar, not a steep hill (Ken: rigid, non-negotiable
     * pillars stay a hard zero; memory: "Rigid: must pass. Non-negotiable."). Aela's beauty is
     * rigid: at low openness (no tolerance) a described player with none of her words fails
     * its bar = exactly 0, spark included; one of her words passes it and the unit counts as
     * met (x1.0, surplus above its floor), not as a hill.
     */
    public function testARigidPillarBelowItsBarIsAHardZeroAndAPassedOneIsMet(): void
    {
        $this->warriorAtStep(5);
        $this->corePlayer('appearance', 'A quiet man with ink-stained fingers and a soft voice.');
        $this->turn('Good hunting today.');
        $d = $this->dynamics();
        $d['attraction_overrides'] = ['openness' => 'low'];
        $a = $this->attractionNow($d);
        $why = $a['reason'] . ' ' . json_encode([$a['pillars']['beauty'], $a['passion']['units']]);
        $this->assertGreaterThan(0.0, $a['pillars']['beauty']['score'], 'above zero, below the bar: ' . $why);
        $this->assertFalse($a['pillars']['beauty']['pass'], $why);
        $this->assertSame('rigid:beauty', $a['hard_zero'], $why);
        $this->assertSame(0.0, $a['spark_mult']);
        $this->assertSame(0.0, $a['passion_mult']);
        $this->assertFalse($a['attracted']);
        RelationshipDynamics::setPassion($d, 0.0);
        $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 10.0, 'reunion'), 'no spark either');

        // One of her words: the bar is passed, the gate is open and met (55 points: a passed
        // rigid pillar is met, x1.0 below her floor, the surplus above it)
        $this->corePlayer('appearance', 'A rugged man with ink-stained fingers.');
        $a = $this->attractionNow($d);
        $why = $a['reason'] . ' ' . json_encode($a['passion']['units']);
        $this->assertTrue($a['pillars']['beauty']['pass'], $why);
        $this->assertNull($a['hard_zero'], $why);
        $this->assertTrue($a['passion']['units']['beauty']['met']);
        $beautyFloor = RelDynAttraction::definition(self::AELA, $d)['floors']['beauty'];
        $this->assertEqualsWithDelta(max(1.0, RelDynAttraction::pillarMult(55.0, $beautyFloor)), $a['passion']['units']['beauty']['m'], 1e-4, $why);
        // at Ken's generic 45 that is x1.10 (the editor's per-pillar floor)
        $d['attraction_overrides']['floors'] = ['beauty' => 45.0];
        $this->assertEqualsWithDelta(1.10, $this->attractionNow($d)['passion']['units']['beauty']['m'], 1e-4, $why);
        $this->assertSame('drawn', $a['outcome'], $why);
        $this->assertNoDbFailures();
    }

    /**
     * Each pillar has its own floor: the pinned 68 is Aela's martial floor only; beauty keeps
     * her standards floor, and a rigid beauty that passes its bar is met whatever its floor. A warrior
     * well past her martial floor reads as drawn whether or not an appearance text exists
     * (review: with every pillar on 68, one keyword hit, 55, friendzoned him).
     */
    public function testAWarriorPastHerMartialFloorIsDrawnWhateverHisLooksText(): void
    {
        $this->warriorAtStep(5);
        $this->turn('Good hunting today.');
        $d = $this->dynamics();
        $def = RelDynAttraction::definition(self::AELA, $d);
        $this->assertSame(68.0, $def['floors']['strength']);
        foreach (['beauty', 'status', 'competence'] as $p) $this->assertSame($def['standards']['floor'], $def['floors'][$p], $p);
        $outcomes = [];
        foreach (['none' => null, 'no words' => 'A tall man with dark hair and a calm voice.',
                     'one word' => 'A rugged man with dark hair.', 'two words' => 'A rugged, scarred man.'] as $label => $text) {
            pg_query_params($this->db->link, 'DELETE FROM core_player WHERE id = $1', ['appearance']);
            if ($text !== null) $this->corePlayer('appearance', $text);
            $a = $this->attractionNow($d);
            $why = "{$label}: {$a['reason']} " . json_encode($a['passion']['units']);
            $this->assertSame('drawn', $a['outcome'], $why);
            $this->assertFalse($a['friendzoned'], $why);
            $this->assertGreaterThanOrEqual(1.0, $a['passion']['curve'], "past her martial floor, beauty met: {$why}");
            $outcomes[$label] = $a['passion']['curve'];
        }
        $this->assertGreaterThan($outcomes['one word'], $outcomes['two words'], 'more of her words: surplus above the beauty floor');
        $this->assertNoDbFailures();
    }

    /**
     * The attraction label is the MDD bar, not a tuned constant (review: friendzone_below 0.15
     * was chosen to sort the fixtures). A growing warrior reads as attracted exactly when his
     * passion unit reaches its bar, and the curve there is the hill's own value at the bar.
     */
    public function testTheLabelIsTheUnitsBarNotATunedConstant(): void
    {
        $cc = RelDynAttraction::curveConfig();
        foreach (['friendzone_below', 'floor_by_openness', 'rigid_m_min'] as $gone) {
            $this->assertArrayNotHasKey($gone, $cc, "{$gone}: retired");
        }
        $this->assertEquals(45.0, $cc['floor'], "Ken's generic floor: 'at 45 you get 1x'");
        // A stricter bar in the settings (pass_threshold 0.8: flexible bar 0.48), so the green
        // sellsword starts below it; low openness: no near-miss tolerance, never won over
        $this->attractionConfig(function (array &$att): void { $att['pass_threshold'] = 0.8; });
        $this->warriorAt(0.0);
        $this->turn('I heard the Companions take on sellswords.');
        $d = $this->dynamics();
        $d['attraction_overrides'] = ['openness' => 'low'];
        $seen = [];
        for ($k = 0.0; $k <= 3.0001; $k += 0.25) {
            $this->warriorAt($k);
            $a = $this->attractionNow($d);
            $u = $a['passion']['units']['flexible:visceral'];
            $why = "k {$k}: {$a['reason']} " . json_encode([$u, $a['pillars']['strength']]);
            $this->assertSame($u['met'], $a['attracted'], $why);
            $this->assertSame(!$u['met'], $a['friendzoned'] || $a['outcome'] === 'unattracted', $why);
            $seen[$u['met'] ? 'met' : 'below'] = true;
        }
        $this->assertCount(2, $seen, 'the warrior crosses her bar on the way: ' . json_encode($seen));
        $this->assertNoDbFailures();
    }

    /**
     * MDD 1.4's "Failed Pillar Effect" stays (only MDD 6.2's cap of 20 was retired): while a
     * passion pillar is below its bar, medium openness cuts passion's ceiling 50%, high 20%,
     * low none (a hard block: never won over). Gains stop at the ceiling on every path; passion
     * already above it is not cut. Past her bars there is no ceiling.
     */
    public function testTheMdd14CeilingForAPillarBelowItsBar(): void
    {
        $this->bardWith(15);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        foreach (['medium' => 50.0, 'high' => 80.0, 'low' => null] as $openness => $ceiling) {
            $d['attraction_overrides'] = ['openness' => $openness];
            $a = $this->attractionNow($d);
            $this->assertEquals($ceiling, $a['passion_ceiling'], "{$openness}: {$a['reason']}");
            if ($ceiling === null) continue;
            RelationshipDynamics::setPassion($d, $ceiling - 0.5);
            $gain = RelationshipDynamics::gainPassion(self::AELA, $d, 100.0, 'reunion');
            $this->assertEqualsWithDelta(0.5, $gain, 1e-9, "{$openness}: up to the ceiling");
            $this->assertEqualsWithDelta($ceiling, RelationshipDynamics::getPassion($d), 1e-9);
            $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 5.0, 'repair'), "{$openness}: at the ceiling");
            $this->assertSame(0.0, RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 10.0, ['quality_time'], 0.9)['actual']);
            $this->assertSame(0.0, RelationshipDynamics::applyDelta('passion', $d, 5.0, 'Guarded'));
            // Passion above it (an older save) is not cut, it only stops rising
            RelationshipDynamics::setPassion($d, $ceiling + 5.0);
            $this->assertSame($ceiling + 5.0, RelationshipDynamics::getPassion($d));
            $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 5.0, 'repair'));
            $this->assertEqualsWithDelta($ceiling + 5.0, RelationshipDynamics::getPassion($d), 1e-9);
        }
        $this->assertStringContainsString('at the MDD 1.4 passion ceiling 50', $this->errorLogText(), 'the log names why');
        // Low openness: no ceiling, the uphill still scales gains, and never won over
        RelationshipDynamics::setPassion($d, 60.0);
        $low = $this->attractionNow($d);
        $this->assertFalse($low['won_over'], $low['reason']);
        $this->assertSame('friendzone', $low['outcome']);
        $this->assertGreaterThan(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 5.0, 'repair'));
        // A warrior past her bars: no ceiling
        unset($d['attraction_overrides']);
        $this->warriorAtStep(5);
        $w = $this->attractionNow($d);
        $this->assertTrue($w['pillars']['strength']['pass'] && !$w['pillars']['strength']['tolerated'], $w['reason']);
        $this->assertNull($w['passion_ceiling'], $w['reason']);
        $this->assertNoDbFailures();
    }

    /**
     * No contradiction in the felt context (review: with the cap of 20 gone, a friendzoned
     * Aela at passion 65 got "drifts closer ... wanting to close it" and "idealizes Kaida" next
     * to "meets flirtation with warm, kind deflection"). Not drawn = a friend's intensity, never
     * desire; won over = drawn, and the desire lines agree with it.
     */
    public function testFeltPassionAgreesWithTheAttractionLine(): void
    {
        $this->bardWith(15);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $d['love_language_primary'] = 'physical_touch';
        $d['dimensions']['trust']['x'] = 30.0;   // with affinity high: infatuation's shape
        $lines = function (array $dyn): array {
            $out = [];
            foreach (RelDynFelt::compose(self::AELA, self::PLAYER, $dyn, $this->gamets, [])['lines'] as $l) $out[$l['key']] = $l['text'];
            return $out;
        };
        $desire = '/touch|close it|gaze|idealizes|voice drops|excuses/i';

        // Low openness (a hard block): never won over, however high passion has climbed
        $low = $d;
        $low['attraction_overrides'] = ['openness' => 'low'];
        foreach ([65.0, 85.0] as $p) {
            RelationshipDynamics::setPassion($low, $p);
            $a = $this->attractionNow($low);
            $this->assertSame('friendzone', $a['outcome'], $a['reason']);
            $l = $lines($low);
            $all = implode(' | ', $l);
            $this->assertStringContainsString('kind deflection', $l['attraction'] ?? '', $all);
            $this->assertArrayHasKey('passion', $l, $all);
            // the platonic reading, at the band the bond shows (the per-bond display multiplier)
            $this->assertContains($l['passion'], RelDynFelt::TEXT_DEFAULTS['passion_platonic'], 'a friend intensity: ' . $all);
            $this->assertDoesNotMatchRegularExpression($desire, $all, "passion {$p}: no desire next to a deflection");
        }

        // An attraction hard zero with passion from an older save: the same, never desire
        $hz = $d;
        $hz['attraction_overrides'] = ['gender_pref' => 'heterosexual'];
        $this->corePlayer('gender', 'female');
        RelationshipDynamics::setPassion($hz, 70.0);
        $a = $this->attractionNow($hz);
        $this->assertSame('orientation', $a['hard_zero']);
        $this->assertDoesNotMatchRegularExpression($desire, implode(' | ', $lines($hz)));
        pg_query_params($this->db->link, 'DELETE FROM core_player WHERE id = $1', ['gender']);

        // Medium openness at 65: won over, drawn; the desire lines and the attraction line agree
        $won = $d;
        RelationshipDynamics::setPassion($won, 65.0);
        $a = $this->attractionNow($won);
        $this->assertTrue($a['won_over'], $a['reason']);
        $this->assertSame('drawn', $a['outcome']);
        $l = $lines($won);
        $all = implode(' | ', $l);
        $this->assertStringContainsString('won', $l['attraction'] ?? '', $all);
        $this->assertStringNotContainsString('deflection', $all);
        // the desire at the band the bond shows (the per-bond display multiplier on passion 65)
        $band = RelationshipDynamics::getPassionBand(RelationshipDynamics::getEffectiveDimensionValue($won, 'passion'));
        $this->assertStringStartsWith(RelDynFelt::TEXT_DEFAULTS['passion'][$band], $l['passion'] ?? '', $all);
        $this->assertMatchesRegularExpression($desire, $l['passion'] ?? '', $all);
        foreach ($l as $t) $this->assertDoesNotMatchRegularExpression('/\d/', $t, 'feelings, never numbers');
        $this->assertNoDbFailures();
    }

    /**
     * A hard zero takes passion, not the friendship (review: MDD 1.1 makes passion the affinity
     * speed, so an aromantic or orientation-mismatched NPC idled every affinity gain at x0.3
     * forever). The drive reads the spark instead (what anyone else gets freely). An asexual
     * NPC's passion is emotional (decisions §15: "their affinity growth is not slowed"): her
     * drive reads at least the spark too, and her own passion above it.
     */
    public function testAHardZeroDoesNotIdleTheFriendship(): void
    {
        $this->warriorAtStep(5);
        $this->turn('Good hunting today.');
        $this->attractionConfig(function (array &$att): void {}, ['type_filter_enabled' => true]);
        $d = $this->dynamics();
        $d['relationship_preference'] = 'aromantic';
        RelationshipDynamics::setPassion($d, 0.0);
        $a = $this->attractionNow($d);
        $this->assertSame('preference:aromantic', $a['hard_zero'], $a['reason']);
        $drive = RelationshipDynamics::affinityModifiers($d, 5.0, ['quality_time'])['rows']['passion_drives_gains'] ?? null;
        $this->assertEqualsWithDelta(0.3 + 0.017 * 20.0, $drive, 1e-9, 'the drive at the spark, not idling at x0.3');
        $this->assertEqualsWithDelta(0.3 + 1.7 * 0.2, RelationshipDynamics::getAffinityGainMultiplier($d), 1e-9);
        $d['relationship_preference'] = 'asexual';
        $a = $this->attractionNow($d);
        $this->assertNull($a['hard_zero'], $a['reason']);
        $this->assertTrue($a['attracted'], 'asexual (romance_max 2) still judges the player; her passion is emotional');
        $this->assertEqualsWithDelta(0.3 + 0.017 * 20.0, RelationshipDynamics::affinityModifiers($d, 5.0, ['quality_time'])['rows']['passion_drives_gains'], 1e-9,
            'asexual at passion 0: the drive at the spark');
        RelationshipDynamics::setPassion($d, 50.0);
        $this->assertEqualsWithDelta(0.3 + 0.017 * 50.0, RelationshipDynamics::affinityModifiers($d, 5.0, ['quality_time'])['rows']['passion_drives_gains'], 1e-9,
            'asexual at passion 50: her own emotional passion drives it');
        RelationshipDynamics::setPassion($d, 0.0);
        // The same NPC with no hard zero and no passion: MDD 1.1's idle x0.3 as written
        unset($d['relationship_preference']);
        $open = $this->attractionNow($d);
        $this->assertNull($open['hard_zero']);
        $this->assertEqualsWithDelta(0.3, RelationshipDynamics::affinityModifiers($d, 5.0, ['quality_time'])['rows']['passion_drives_gains'], 1e-9);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ one factor, every path

    /**
     * Every passion path uses the same factor (RelationshipDynamics::attractionPassionFactor ->
     * RelDynAttraction::gainFactor): the legacy classifier, the eval inbox, reunion, combat,
     * conflict repair, the hoover snap, a loved place's floor and the dimension engine. For
     * the bard above the spark each path's gain is its raw x the same passion_mult: the same
     * evening replayed with the Matrix switched off (factor 1) gains 1 / passion_mult as much.
     */
    public function testEveryPassionPathUsesTheSameFactor(): void
    {
        $this->bardWith(30);
        $this->setCoreAff(60);
        $this->turn('A song for the Huntress?');
        $mult = floatval($this->dynamics()['_attraction']['passion_mult']);
        $this->assertGreaterThan(0.05, $mult);
        $this->assertLessThan(0.15, $mult);

        $evening = function (): array {
            $gains = [];
            // legacy classifier: a flirty answer
            $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 30.0); });
            $before = floatval($this->dynamics()['passion_sources']['love_match'] ?? 0);
            $this->turn('You liked that verse, admit it.', 'flirty');
            $gains['legacy'] = floatval($this->dynamics()['passion_sources']['love_match'] ?? 0) - $before;
            // reunion: 30 game hours apart
            $this->editDynamics(function (array &$d): void {
                RelationshipDynamics::setPassion($d, 30.0);
                $atContact = 50.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
                $d['_last_contact_play_gamets'] = $atContact;
                $d['_accumulated_play_gamets'] = $atContact + 25 * 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
                unset($d['reunion_spike_given'], $d['_reunion_hours_apart']);
            });
            $this->gamets += 30 * self::DAY / 24;
            $before = floatval($this->dynamics()['passion_sources']['reunion'] ?? 0);
            $this->turn('I am back.', 'default');
            $gains['reunion'] = floatval($this->dynamics()['passion_sources']['reunion'] ?? 0) - $before;
            // combat side by side
            $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 30.0); $d['passion_sources']['combat'] = 0.0; });
            $this->turn(self::AELA . ' is teamed up with ' . self::PLAYER . ' against a Bandit Marauder', 'default', 'radiantcombatfriend');
            $gains['combat'] = floatval($this->dynamics()['passion_sources']['combat'] ?? 0);
            // the direct writers on one copy
            $d = $this->dynamics();
            RelationshipDynamics::setPassion($d, 30.0);
            $gains['repair'] = RelationshipDynamics::gainPassion(self::AELA, $d, 12.0, 'repair');
            RelationshipDynamics::setPassion($d, 30.0);
            $gains['eval'] = RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 10.0, ['quality_time'], 0.9)['actual'];
            RelationshipDynamics::setPassion($d, 30.0);
            $gains['dimension'] = RelationshipDynamics::applyDelta('passion', $d, 5.0, 'Guarded');
            RelationshipDynamics::setPassion($d, 30.0);
            RelationshipDynamics::executeHoover($d, self::AELA, 'Guarded');
            $gains['hoover'] = RelationshipDynamics::getPassion($d) - 30.0;
            return $gains;
        };
        $snapshot = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]))['plugin_extended_data'];
        [$g0, $r0] = [$this->gamets, $this->realTs];
        $on = $evening();

        // The same evening, the same state and clocks, the Matrix off (factor 1 everywhere)
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [self::AELA, $snapshot]);
        [$this->gamets, $this->realTs] = [$g0, $r0];
        pg_query($this->db->link, 'DELETE FROM moods_issued');
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'attraction_matrix_enabled' => false]))]);
        RelationshipDynamics::clearConfigCache();
        $off = $evening();

        foreach ($on as $path => $gain) {
            $this->assertGreaterThan(0.0, $off[$path], "{$path}: the path fires ({$off[$path]})");
            $this->assertEqualsWithDelta($mult, $gain / $off[$path], 0.002, "{$path}: gain x the same factor " . json_encode([$on, $off]));
        }
        // The one factor names every path in the log
        $log = $this->errorLogText();
        foreach (['love_match', 'reunion', 'combat', 'repair', 'eval', 'dimension engine', 'hoover'] as $source) {
            $this->assertMatchesRegularExpression('/\[ATTRACTION\] (' . preg_quote(self::AELA, '/') . ': )?' . preg_quote($source, '/') . ' passion \+[0-9.]+ at [0-9.]+ x' . preg_quote(number_format($mult, 4, '.', ''), '/') . '/',
                $log, "{$source} logged through the one factor");
        }
        $this->assertNoDbFailures();
    }

    /**
     * A loved place's floor rises through the same factor (MDD 1.5, RelDynFacets::holdPoiFloor
     * calls attractionPassionFactor): its default floor sits inside the spark (anyone warms
     * there), a floor configured above it rises at the hill's rate.
     */
    public function testTheFactorKnowsTheSparkForAPlacesFloor(): void
    {
        $this->bardWith(30);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $a = $d['_attraction'];
        $floor = floatval(RelDynFacets::getAppraisalConfig()['poi_passion_floor']);
        $this->assertLessThanOrEqual(20.0, $floor, 'the default place floor sits inside the spark: ' . $floor);
        RelationshipDynamics::setPassion($d, 21.0);
        $factor = RelationshipDynamics::attractionPassionFactor(self::AELA, $d, 1.0, 'poi_floor');
        $this->assertEqualsWithDelta($a['passion_mult'], $factor, 1e-9, 'above the spark: passion_mult');
        RelationshipDynamics::setPassion($d, 5.0);
        $this->assertEqualsWithDelta($a['spark_mult'], RelationshipDynamics::attractionPassionFactor(self::AELA, $d, 1.0, 'poi_floor'), 1e-9, 'below it: the spark');
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ felt and Jev

    /** The LLM gets feelings (never numbers); Jev gets the factors. */
    public function testFeltTextAndJevReflectTheUphill(): void
    {
        // The warrior below her floor: drawn, with the uphill in the words
        $this->warriorAtStep(3);
        $this->setCoreAff(45);
        $this->turn('Good hunting today.');
        $d = $this->dynamics();
        $a = $d['_attraction'];
        $this->assertTrue($a['attracted']);
        $this->assertTrue($a['below_floor'], json_encode($a['passion']));
        $felt = (string) RelDynAttraction::feltText(self::AELA, $a, ['tier' => 2, 'passion' => 25.0]);
        $this->assertStringContainsString('falls short of what', $felt);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt);

        // The friendzoned bard whose passion has climbed past the spark: deflection slows
        pg_query($this->db->link, 'DELETE FROM core_player');   // a new player: none of the warrior's deeds
        $this->bardWith(30);
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $b = $d['_attraction'];
        $this->assertTrue($b['friendzoned'], json_encode($b));
        $below = (string) RelDynAttraction::feltText(self::AELA, $b, ['tier' => 2, 'passion' => 12.0]);
        $above = (string) RelDynAttraction::feltText(self::AELA, $b, ['tier' => 2, 'passion' => 26.0]);
        $this->assertStringContainsString('kind deflection', $below);
        $this->assertStringNotContainsString('begun to get through', $below);
        $this->assertStringContainsString('begun to get through', $above);
        foreach ([$below, $above] as $t) $this->assertDoesNotMatchRegularExpression('/\d/', $t);
        // ... never for a hard zero
        $hz = $b;
        $hz['hard_zero'] = 'orientation';
        $this->assertStringNotContainsString('begun to get through', (string) RelDynAttraction::feltText(self::AELA, $hz, ['tier' => 2, 'passion' => 26.0]));

        // Jev: the numbers
        $jev = RelationshipDynamics::jevStateBlock(self::AELA);
        $ja = $jev['attraction'];
        $this->assertEqualsWithDelta($b['passion']['curve'], $ja['curve'], 1e-4);
        $this->assertSame(20.0, $ja['spark']);
        $this->assertEqualsWithDelta($b['spark_mult'], $ja['spark_mult'], 1e-4);
        $this->assertEqualsWithDelta($b['passion_mult'], $ja['passion_mult'], 1e-4);
        $this->assertNull($ja['hard_zero']);
        $this->assertFalse($ja['attracted']);
        $this->assertSame(68.0, $ja['units']['flexible:visceral']['floor']);
        $this->assertArrayNotHasKey('passion_cap', $ja);
        $this->assertArrayNotHasKey('gate_product', $ja);
        $this->assertMatchesRegularExpression('/attraction=friendzone curve=0\.1\d\[flexible:visceral [0-9.]+\/68 x0\.1\d\] spark=20\(x[0-9.]+\) passion_mult=0\.\d\d/', $jev['text']);
        $this->assertArrayHasKey('attraction.spark', $jev['units']);
        // the review fixes' factors: won over, the MDD 1.4 ceiling, charm, each unit's bar
        $this->assertFalse($ja['won_over']);
        $this->assertEquals(50.0, $ja['passion_ceiling'], 'MDD 1.4 medium: her martial bar failed');
        $this->assertEqualsWithDelta(0.30, $ja['charm'], 1e-4, 'speechcraft 30');
        $this->assertFalse($ja['units']['flexible:visceral']['met']);
        $this->assertStringContainsString(' passion_ceiling=50', $jev['text']);
        $this->assertArrayHasKey('attraction.passion_ceiling', $jev['units']);
        $this->assertNoDbFailures();
    }
}
