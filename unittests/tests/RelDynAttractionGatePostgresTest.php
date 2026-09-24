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
final class RelDynAttractionGatePgDb
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
 * Rulings 2026-09-24 §11: "Attraction should be a modifier and a gate. As a player's stats and
 * achievements accumulate it increases the attraction multiplier of passion gains, and the
 * gate where there may be zeros, 100 x 0 is still 0."
 *   passion_gain = raw x attraction_modifier x product(required-pillar gates) x attachment
 *
 * Real PostgreSQL, CHIM 3.4.1 core-shaped rows only: Aela's core_npc_master row (class,
 * factions, skills; no RelDyn state), the player's core_player rows as the plugin writes them
 * (skills, stats, tracked Skyrim stats), read by RelDynPlayer::profile(). Passion paths run
 * through the real hooks (prerequest -> context -> postrequest), the real eval inbox and the
 * real hoover. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAttractionGatePostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const MINUTE = RelationshipDynamics::GAMETS_PER_DAY / 1440;
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynAttractionGatePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY;

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
        $this->schema = 'reldyn_gate' . getmypid() . '_' . bin2hex(random_bytes(3));

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

        $this->db = new RelDynAttractionGatePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdgatepg');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attraction_gate_pg_test.log');

        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
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

    // ------------------------------------------------------------------ the modifier

    public function testAelasMultiplierKeepsGrowingAsAWarriorAccumulatesDeeds(): void
    {
        $this->warriorAtStep(0);
        $this->turn('I heard the Companions take on sellswords.');   // Aela auto-generates from her core row
        $d = $this->dynamics();

        $steps = [];
        $felt = [];
        for ($k = 0; $k <= 5; $k++) {
            $this->warriorAtStep($k);
            $a = $this->attractionNow($d);
            $steps[$k] = $a;
            $felt[$k] = (string) RelDynAttraction::feltText(self::AELA, $d['_attraction']);
            $this->assertTrue($a['passes'], "step {$k}: a warrior's passion gate is open for Aela: " . $a['reason']);
            $this->assertSame(1.0, floatval($a['passion']['gate_product']), "step {$k}");
            if ($a['passion']['tolerated']) {
                // the sellsword's strength is a near miss her (medium) openness forgives: open,
                // the passion ceiling cut by MDD 1.4's 50%
                $this->assertSame(0, $k, 'only the green sellsword is a near miss');
                $this->assertEqualsWithDelta(50.0, floatval($a['passion_cap']), 1e-6, "step {$k}");
            } else {
                $this->assertNull($a['passion_cap'], "step {$k}: no friendzone cap for a warrior");
            }
        }
        // Strictly increasing, step after step: the score, the modifier and the passion multiplier
        for ($k = 1; $k <= 5; $k++) {
            $why = sprintf('step %d vs %d: %s -> %s', $k - 1, $k, json_encode($steps[$k - 1]['passion']), json_encode($steps[$k]['passion']));
            $this->assertGreaterThan($steps[$k - 1]['score'], $steps[$k]['score'], $why);
            $this->assertGreaterThan($steps[$k - 1]['passion']['modifier'], $steps[$k]['passion']['modifier'], $why);
            $this->assertGreaterThan($steps[$k - 1]['passion_mult'], $steps[$k]['passion_mult'], $why);
        }
        // The level-10 sellsword: a small multiplier, a fraction of what the Companion becomes
        $this->assertLessThan(0.25, $steps[0]['passion_mult'], json_encode($steps[0]['passion']));
        $this->assertGreaterThan(2.0 * $steps[0]['passion_mult'], $steps[5]['passion_mult']);
        $this->assertLessThanOrEqual(1.0, $steps[5]['passion']['modifier'], 'attraction never speeds passion past its raw rate');
        // The modifier is the documented curve on the score (floor + span x S^curve)
        $pc = RelDynAttraction::config()['passion'];
        foreach ($steps as $k => $a) {
            $this->assertEqualsWithDelta($pc['modifier_floor'] + ($pc['modifier_ceiling'] - $pc['modifier_floor']) * pow($a['score'], $pc['modifier_curve']),
                $a['passion']['modifier'], 1e-3, "step {$k}");
            $this->assertEqualsWithDelta($a['passion']['modifier'] * $a['passion']['gate_product'] * $a['passion']['attachment'],
                $a['passion_mult'], 1e-3, "step {$k}: passion_mult = modifier x gates x attachment");
        }

        // Felt, never numbers (decisions §3): the pull grows in the words too
        // (as behavior, never a verdict: passing glances for the sellsword, lingering looks and
        // eager answers for the Companion; the felt lane's wording)
        $this->assertMatchesRegularExpression('/passing glance|flirts back lightly/', $felt[0]);
        $this->assertDoesNotMatchRegularExpression('/passing glance|flirts back lightly/', $felt[5]);
        $this->warriorAtStep(8);                  // a legend of the Companions (read on a copy)
        $legend = $d;
        $this->attractionNow($legend);
        $legendFelt = (string) RelDynAttraction::feltText(self::AELA, $legend['_attraction']);
        $this->assertMatchesRegularExpression('/linger there|flirts back boldly/', $legendFelt);
        $this->warriorAtStep(5);
        foreach ($felt as $k => $text) $this->assertDoesNotMatchRegularExpression('/\bis (only faintly |strongly )?drawn to\b/', $text, "step {$k}");
        foreach ($felt as $k => $text) $this->assertDoesNotMatchRegularExpression('/\d/', $text, "step {$k}");

        // No bar anywhere: a few more sabre cats move the multiplier a little, never a jump
        // (one kill moves it by less than the stored 4-decimal rounding: the modifier is linear)
        $this->warriorAtStep(5, 5);
        $plusOne = $this->attractionNow($d);
        $this->assertGreaterThan($steps[5]['passion_mult'], $plusOne['passion_mult'], 'every deed counts');
        $this->assertLessThan(0.01, $plusOne['passion_mult'] - $steps[5]['passion_mult'], 'continuous: a few kills are a small step');

        // What the hooks store is what the passion paths read
        $this->turn('Skjor says you did well at Dustman\'s Cairn.');
        $stored = $this->dynamics()['_attraction'];
        $this->assertEqualsWithDelta($plusOne['passion_mult'], $stored['passion_mult'], 1e-9);
        $this->assertEqualsWithDelta($plusOne['passion']['modifier'], $stored['passion']['modifier'], 1e-9);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ the gate

    /**
     * A bard: Aela's required (flexible) strength reads as absent (her lens sees no warrior,
     * hunter or druid in him), so the gate is 0 and every passion path adds exactly 0.
     */
    public function testAZeroedRequiredPillarGivesExactlyZeroPassionGainOnEveryPath(): void
    {
        $this->bard();
        $this->setCoreAff(60);                     // a friend: reunion needs core affinity 40+
        $this->turn('A song for the Huntress?');
        $d = $this->dynamics();
        $a = $d['_attraction'];
        $this->assertSame(0.0, floatval($a['passion']['gates']['flexible']), json_encode($a['passion']));
        $this->assertSame(0.0, floatval($a['passion_mult']), 'the gate zeroes the product');
        $this->assertGreaterThan(0.0, $a['passion']['modifier'], 'the modifier alone is not zero: the gate is');
        $this->assertTrue($a['friendzoned'], 'tolerated: her friend, no passion ' . json_encode(RelationshipDynamics::attractionFor(self::AELA, $d)['pillars']));
        $this->assertSame(20.0, floatval($a['passion_cap']));
        $this->assertSame(0.0, RelationshipDynamics::attractionPassionMult(self::AELA, $d));

        // Passion she already has, below the cap, so any gain would show
        $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 10.0); });

        // 1. legacy classifier: a flirty answer (words of affirmation)
        $before = $this->dynamics();
        $this->turn('You liked that verse, admit it.', 'flirty');
        $after = $this->dynamics();
        $this->assertLessThanOrEqual(RelationshipDynamics::getPassion($before), RelationshipDynamics::getPassion($after), 'legacy: no gain');
        $this->assertSame(floatval($before['passion_sources']['love_match'] ?? 0), floatval($after['passion_sources']['love_match'] ?? 0), 'legacy: nothing added');

        // 2. eval signal, through the real inbox: a defining, passionate moment, a rescue too
        $this->queueEval(['passion' => 20, 'affinity' => 4], ['quality_time']);
        $this->queueEval(['passion' => 25], ['rescue']);
        $before = $this->dynamics();
        $this->turn('I would sing for you every night.', 'default');
        $after = $this->dynamics();
        $this->assertLessThanOrEqual(RelationshipDynamics::getPassion($before), RelationshipDynamics::getPassion($after), 'eval: no gain');
        $d = $this->dynamics();
        $line = RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 20.0, ['rescue'], 0.9);
        $this->assertSame(0.0, $line['actual'], 'eval signal: exactly 0 (' . $line['line'] . ')');

        // 3. reunion: 30 game hours apart, 25 real minutes of it played (the play clock as a
        // session 50 real hours in holds it at the last contact)
        $this->editDynamics(function (array &$d): void {
            RelationshipDynamics::setPassion($d, 10.0);
            $atContact = 50.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
            $d['_last_contact_play_gamets'] = $atContact;
            $d['_accumulated_play_gamets'] = $atContact + 25 * 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
            unset($d['reunion_spike_given'], $d['_reunion_hours_apart']);
        });
        $this->gamets += 30 * self::DAY / 24;
        $this->turn('I am back.', 'default');
        $after = $this->dynamics();
        $this->assertEqualsWithDelta(30.0, floatval($after['_reunion_hours_apart'] ?? 0), 0.5, 'the reunion fired (checkReunion stamps the hours apart)');
        $this->assertLessThanOrEqual(10.0, RelationshipDynamics::getPassion($after), 'reunion: no gain');
        $this->assertSame(0.0, floatval($after['passion_sources']['reunion'] ?? 0), 'reunion: nothing added');

        // 4. combat: fighting side by side (core radiantcombatfriend)
        $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 10.0); });
        $this->turn(self::AELA . ' is teamed up with ' . self::PLAYER . ' against a Bandit Marauder', 'default', 'radiantcombatfriend');
        $after = $this->dynamics();
        $this->assertSame(10.0, RelationshipDynamics::getPassion($after), 'combat: exactly no gain');
        $this->assertSame(0.0, floatval($after['passion_sources']['combat'] ?? 0));

        // 5. hoover: the toxic snap to "passion maxed" (MDD 6.6)
        $d = $this->dynamics();
        RelationshipDynamics::setPassion($d, 10.0);
        $snap = RelationshipDynamics::executeHoover($d, self::AELA, 'Independent');
        $this->assertSame(10.0, RelationshipDynamics::getPassion($d), 'hoover: exactly no gain ' . json_encode($snap));

        // Any other passion writer (conflict repair, a place's floor, a physical state): the
        // gain helper and the dimension engine hold the same zero
        $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 12.0, 'repair'));
        $this->assertSame(0.0, RelationshipDynamics::applyDelta('passion', $d, 5.0, 'Independent'));
        $this->assertSame(10.0, RelationshipDynamics::getPassion($d));
        $this->assertNoDbFailures();
    }

    /** Control for the paths above: the same evening with a warrior moves passion on every path. */
    public function testTheSamePathsStirPassionForAWarrior(): void
    {
        $this->warriorAtStep(4);
        $this->setCoreAff(60);
        $this->turn('Good hunting today.');
        $d = $this->dynamics();
        $this->assertGreaterThan(0.0, $d['_attraction']['passion_mult'], json_encode($d['_attraction']['passion']));

        $before = $this->dynamics();
        $this->turn('That was a clean kill.', 'flirty');
        $this->assertGreaterThan(floatval($before['passion_sources']['love_match'] ?? 0),
            floatval($this->dynamics()['passion_sources']['love_match'] ?? 0), 'legacy');

        $this->editDynamics(function (array &$d): void {
            RelationshipDynamics::setPassion($d, 10.0);
            $atContact = 50.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
            $d['_last_contact_play_gamets'] = $atContact;
            $d['_accumulated_play_gamets'] = $atContact + 25 * 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
            unset($d['reunion_spike_given'], $d['_reunion_hours_apart']);
        });
        $this->gamets += 30 * self::DAY / 24;
        $this->turn('I am back.', 'default');
        $after = $this->dynamics();
        $this->assertEqualsWithDelta(30.0, floatval($after['_reunion_hours_apart'] ?? 0), 0.5);
        $this->assertGreaterThan(0.0, floatval($after['passion_sources']['reunion'] ?? 0), 'reunion');

        $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 10.0); });
        $this->turn(self::AELA . ' is teamed up with ' . self::PLAYER . ' against a Bandit Marauder', 'default', 'radiantcombatfriend');
        $this->assertGreaterThan(10.0, RelationshipDynamics::getPassion($this->dynamics()), 'combat');

        $d = $this->dynamics();
        $line = RelationshipDynamics::applyEvalSignal(self::AELA, $d, 'passion', 20.0, ['rescue'], 0.9);
        $this->assertGreaterThan(0.0, $line['actual'], 'eval signal: ' . $line['line']);

        RelationshipDynamics::setPassion($d, 10.0);
        RelationshipDynamics::executeHoover($d, self::AELA, 'Independent');
        $this->assertGreaterThan(10.0, RelationshipDynamics::getPassion($d), 'hoover');
        $this->assertNoDbFailures();
    }

    /** "100 x 0 is still 0": a superb warrior, one required pillar at exactly 0. */
    public function testOneRequiredPillarAtZeroZeroesEvenAPerfectScoreElsewhere(): void
    {
        // Top of the warrior's career, but no Companions standing at all: Aela's status (her
        // own marker only, MDD 2.3) reads exactly 0. Bond-gated (every pillar gates passion,
        // MDD 8.2 A), bonded by core affinity, status rigid (her archetype preset).
        $this->playerBuild(['onehanded' => 100, 'twohanded' => 95, 'block' => 90, 'heavyarmor' => 95], 60,
            ['People Killed' => 400, 'Creatures Killed' => 500, 'Quests Completed' => 120, 'Dungeons Cleared' => 80,
             'The Companions Quests Completed' => 0]);
        $this->setCoreAff(90);
        $this->turn('Your champion is here.');
        $this->editDynamics(function (array &$d): void { $d['attraction_overrides'] = ['gate' => 'bond']; });
        $d = $this->dynamics();
        $a = $this->attractionNow($d);
        $this->assertSame('bond', $a['gate']);
        $this->assertSame(0.0, $a['pillars']['status']['score'], 'no Companions standing: status is 0 to Aela');
        $this->assertSame('rigid', $a['pillars']['status']['rigidity']);
        $this->assertSame(1.0, floatval($a['passion']['gates']['flexible']), 'strength fully open');
        $this->assertSame(0.0, floatval($a['passion']['gates']['status']));
        $this->assertSame(0.0, $a['passion_mult'], '100 x 0 is still 0');
        $this->assertSame(0.0, RelationshipDynamics::gainPassion(self::AELA, $d, 25.0, 'reunion'));

        // One Companions quest is still short of her bar: the gate stays shut; her standing
        // met (three), it opens
        $this->corePlayer('The Companions Quests Completed', '1');
        $one = $this->attractionNow($d);
        $this->assertSame(0.0, floatval($one['passion']['gates']['status']), json_encode($one['pillars']['status']));
        $this->assertSame(0.0, $one['passion_mult']);
        $this->corePlayer('The Companions Quests Completed', '3');
        $b = $this->attractionNow($d);
        $this->assertSame(1.0, floatval($b['passion']['gates']['status']), json_encode($b['pillars']['status']));
        $this->assertGreaterThan(0.0, $b['passion_mult']);
        $this->assertNoDbFailures();
    }

    public function testSoftPillarsNeverZeroPassion(): void
    {
        // A bard with no Companions standing, Aela's strength and status made soft: they only
        // move the modifier
        $this->bard(0);
        $this->turn('A song for the Huntress?');
        $this->editDynamics(function (array &$d): void {
            $d['attraction_overrides'] = ['rigidity' => ['strength' => 'soft', 'status' => 'soft', 'beauty' => 'soft', 'competence' => 'soft']];
        });
        $d = $this->dynamics();
        $a = $this->attractionNow($d);
        $this->assertLessThanOrEqual(0.1, $a['pillars']['strength']['score'], 'strength as absent as before');
        $this->assertSame(0.0, $a['pillars']['status']['score'], 'status exactly 0');
        $this->assertSame([], $a['passion']['gates'], 'soft pillars add no gate');
        $this->assertSame(1.0, floatval($a['passion']['gate_product']));
        $this->assertGreaterThan(0.0, $a['passion_mult']);
        $this->assertTrue($a['passes']);
        $this->assertNull($a['passion_cap']);

        // ... and a soft pillar still moves the modifier: Companions standing raises it
        $this->corePlayer('The Companions Quests Completed', '3');
        $b = $this->attractionNow($d);
        $this->assertGreaterThan($a['passion']['modifier'], $b['passion']['modifier']);
        $this->assertNoDbFailures();
    }

    public function testFriendzoneCapStillHoldsOnEveryWriter(): void
    {
        $this->bard();
        $this->turn('A song for the Huntress?');
        $this->assertTrue($this->dynamics()['_attraction']['friendzoned']);
        // An older save holds passion 60: the next request drops it to the MDD 6.2 cap
        $this->editDynamics(function (array &$d): void {
            $d['dimensions']['passion']['x'] = 60.0;
            $d['passion'] = 60.0;
        });
        $this->queueEval(['passion' => 10], ['quality_time']);
        $this->turn('Just one more song.');
        $d = $this->dynamics();
        $this->assertLessThanOrEqual(20.0, RelationshipDynamics::getPassion($d), 'the friendzone cap');
        $this->assertGreaterThan(19.0, RelationshipDynamics::getPassion($d), 'dropped to the cap (then the usual decay)');
        RelationshipDynamics::setPassion($d, 55.0);
        $this->assertSame(20.0, RelationshipDynamics::getPassion($d), 'setPassion holds the cap');
        RelationshipDynamics::addPassion($d, 30.0, 'love_match');
        $this->assertSame(20.0, RelationshipDynamics::getPassion($d));
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ respect_mult (plan §4)

    public function testRespectGainsScaleWithCompetenceAndStatus(): void
    {
        $this->warriorAtStep(0);
        $this->turn('I heard the Companions take on sellswords.');
        $green = $this->dynamics();
        $ga = $this->attractionNow($green);
        $this->assertEqualsWithDelta(($ga['pillars']['competence']['score'] + $ga['pillars']['status']['score']) / 2.0,
            $ga['respect_rate'], 1e-3, 'plan §4: (competence + status) / 2');
        $this->assertEqualsWithDelta(max(0.5, min(2.0, $ga['respect_rate'] / 0.5)), $ga['respect_mult'], 1e-3,
            'the rate against the neutral pillar score, within MDD 1.2 0.5x..2.0x');

        $this->warriorAtStep(5);
        $vet = $this->dynamics();
        $va = $this->attractionNow($vet);
        $this->assertGreaterThan($ga['respect_rate'], $va['respect_rate']);
        $this->assertGreaterThan($ga['respect_mult'], $va['respect_mult']);

        $rg = RelationshipDynamics::applyEvalSignal(self::AELA, $green, 'respect', 6.0, ['competence'], 0.9);
        $rv = RelationshipDynamics::applyEvalSignal(self::AELA, $vet, 'respect', 6.0, ['competence'], 0.9);
        $this->assertStringContainsString('respect_mult', $rv['line']);
        $this->assertGreaterThan($rg['actual'], $rv['actual'], "the Companion's deeds earn more respect: {$rg['line']} / {$rv['line']}");
        // Losses are not scaled
        $lg = RelationshipDynamics::applyEvalSignal(self::AELA, $green, 'respect', -6.0, ['insult'], 0.9);
        $this->assertStringNotContainsString('respect_mult', $lg['line']);

        // Off in config: respect ignores it
        $cfg = RelDynAttraction::defaults();
        $cfg['respect_mult_enabled'] = false;
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'attraction' => $cfg]))]);
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(1.0, RelationshipDynamics::attractionRespectMult(self::AELA, $green));
        $this->assertNoDbFailures();
    }
}
