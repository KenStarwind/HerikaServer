<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// What a production request has loaded: core's logger, game clock, relationship manager and
// NPC master helpers (the timeline stamp after a core write), RelDyn, and the eval worker.
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws,
 * insert / updateRow are parameterized (a PHP null stays SQL NULL). Every failed statement
 * is recorded, so the test shows the schema holds every table the production path touches.
 */
final class RelDynAttractionRomancePgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function record(string $what): void
    {
        $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $what), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) {
            $this->record((string) $q);
            return [];
        }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) {
            $this->record((string) $q);
            throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        }
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }

    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) $this->record((string) $q);
        return $res;
    }

    public function insert($table, $data)
    {
        $ph = [];
        foreach (array_keys($data) as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->record("insert {$table}");
        return $res;
    }

    public function updateRow($table, $data, $where)
    {
        $set = [];
        $i = 0;
        foreach (array_keys($data) as $col) $set[] = "{$col} = $" . (++$i);
        $res = @pg_query_params($this->link, "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}", array_values($data));
        if (!$res) {
            $this->record("updateRow {$table}");
            return false;
        }
        return true;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * The batch end to end (rulings 2026-09-24 §9; MDD §2, 6.2), on a real PostgreSQL with
 * core-shaped rows, through the real hooks (prerequest.php, context.php, postrequest.php)
 * and shared-eval-contract items applied the way the eval worker applies them
 * (plugin_extended_data.reldyn.eval_inbox -> RelDynEval::applyInboxInWorker), over several
 * game days on fixed game timestamps. Only the LLM is absent: the items are its output.
 *
 * Ken: "Aela would have an affinity for a strong warrior type or a formidable druid, but a
 * bard or a scholar she could tolerate but probably wouldn't feel passion towards."
 *   - The warrior / druid (RelDynPlayer::profile from core_player skills and tracked stats)
 *     passes her NPC-subjective pillars: he earns passion, earns her crush through
 *     significant interactions, and after a significant romantic exchange at high affinity
 *     core's relationships.Player.type becomes romantic (what Sharmat's consent gate reads).
 *   - The bard / scholar earns her affinity (sociological pillars pass) but is friendzoned:
 *     passion never above 20, never promoted.
 * "Neglect is the absence of fulfillment" / mature: "that's not what I want out of a
 * relationship; if that is going to change it needs to be rewritten consistently."
 *   - The romance then goes unfulfilled for game days while the player is still around:
 *     mature Aela states a calm boundary once, the probation fails, and she steps back to
 *     friend (core type platonic) under core's lock, stamped on core's timeline.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynAttractionRomanceTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;       // raw gamets per game day
    private const HOUR = self::DAY / 24;
    private const MINUTE = self::DAY / 1440;
    private const T0 = 300 * self::DAY + 9 * self::HOUR;           // game day 300, 09:00
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    /**
     * A warrior who knows the wild: blade, shield and heavy armor, a bow, alchemy and
     * restoration; level 42; two finished questlines, a house, dragons, a long hunting trail.
     * As CHIM core holds it: core_player skills / stats, Skyrim tracked stats, RelDyn's ledger.
     */
    private const WARRIOR_DRUID = [
        'skills' => ['onehanded' => 88, 'twohanded' => 70, 'block' => 65, 'heavyarmor' => 72, 'archery' => 60,
            'alchemy' => 58, 'restoration' => 50, 'speechcraft' => 30],
        'level' => 42,
        'stats' => ['People Killed' => 90, 'Animals Killed' => 110, 'Creatures Killed' => 45, 'Undead Killed' => 60,
            'Dragon Souls Collected' => 4, 'Quests Completed' => 45, 'Questlines Completed' => 2, 'Houses Owned' => 1,
            'Dungeons Cleared' => 24, 'Locations Discovered' => 90, 'Ingredients Harvested' => 300, 'Potions Mixed' => 40,
            'Most Gold Carried' => 9000],
        'gold_moved' => 30000,
    ];

    /**
     * A bard and scholar: voice, illusion, enchanting; level 34; as many quests, two
     * questlines, a house and as much gold moved - but barely a fighter, no hunter, and no
     * nature in his magic (rulings §10: a bard whose magic is nature-heavy may pass her test).
     */
    private const BARD_SCHOLAR = [
        'skills' => ['speechcraft' => 92, 'illusion' => 75, 'enchanting' => 62, 'onehanded' => 18, 'archery' => 15],
        'level' => 34,
        'stats' => ['People Killed' => 4, 'Animals Killed' => 3, 'Creatures Killed' => 2, 'Undead Killed' => 5,
            'Quests Completed' => 45, 'Questlines Completed' => 2, 'Houses Owned' => 1, 'Dungeons Cleared' => 8,
            'Locations Discovered' => 70, 'Books Read' => 160, 'Magic Items Made' => 25, 'Most Gold Carried' => 9000],
        'gold_moved' => 30000,
    ];

    private string $dsn;
    private string $schema;
    private RelDynAttractionRomancePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $npcId = 0;
    private int $realTs = 1727000000;
    private float $gamets = self::T0;
    private array $contexts = [];

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
        $this->schema = 'reldyn_e2e' . getmypid() . '_' . bin2hex(random_bytes(3));

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
        // data/database_default.sql eventlog / responselog / moods_issued
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        // locations as 3.4.1 core has it (the place read); oghma (the context hook's topic read)
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        // lib/core/database_schema/core_player.sql and the quests journal (RelDynPlayer::profile)
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_close($admin);

        $this->db = new RelDynAttractionRomancePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rde2e');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attraction_romance_test.log');
        // Defaults as shipped; the daily weather roll off so the weather reads only what is tested
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), [
                'log_enabled' => true,
                'facet_appraisal' => ['weather_roll_amplitude' => 0.0] + RelDynFacets::appraisalDefaults(),
            ]))]);
        RelationshipDynamics::clearConfigCache();
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

    // ------------------------------------------------------------------ fixture

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    /**
     * Aela as the plugin registers her (class, factions, skills; no RelDyn state yet), with a
     * core Player relationship: an old friend of the player (core aff / type). Maturity is
     * pinned high (the mature boundary is what is tested), everything else autogenerated.
     */
    private function seedAela(int $aff, string $type): void
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $factions = [];
        foreach (['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'] as $i => $f) {
            $factions[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $f];
        }
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data, plugin_extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb, $10::jsonb) RETURNING id',
            [self::AELA, '1A696', 'female', 'NordRace', 'FemaleEvenToned', 'Roleplay as Aela the Huntress', '',
             json_encode(['skills' => array_merge($all, ['archery' => '72', 'sneak' => '56', 'lightarmor' => '52', 'onehanded' => '45'])]),
             json_encode(['class' => ['name' => 'Hunter', 'formid' => '0x0001317f'], 'factions' => $factions,
                 'relationships' => ['Player' => ['aff' => $aff, 'type' => $type]]]),
             json_encode(['reldyn' => ['dynamics' => ['dimensions' => ['maturity' => ['x' => 80, 'baseline' => 80]]]]])]));
        $this->npcId = (int) $row['id'];
    }

    /** The player as CHIM core holds it: core_player skills / stats / tracked stats, RelDyn's gold ledger. */
    private function player(array $p): void
    {
        $rows = [
            'skills' => json_encode(array_merge(array_fill_keys(RelDynPlayer::SKILLS, 15), $p['skills'])),
            'stats' => json_encode(['level' => $p['level'], 'health' => 10 * $p['level'], 'magicka' => 150, 'stamina' => 200]),
            RelDynPlayer::LEDGER_KEY => json_encode(['moved' => $p['gold_moved'], 'gained' => $p['gold_moved'], 'spent' => 0, 'snapshots' => 12]),
        ];
        foreach ($p['stats'] as $name => $v) $rows[$name] = (string) $v;
        foreach ($rows as $id => $value) {
            pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', [$id, $value]);
        }
    }

    /** One player line to Aela through the real hooks at the next game minute; she answers in $mood. Returns the context. */
    private function turn(string $line, string $mood = 'neutral', ?float $at = null): string
    {
        $this->gamets = $at ?? $this->gamets + 5 * self::MINUTE;
        $this->realTs += 60;
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)',
            [self::AELA, $mood, $this->realTs]);
        // Core logs the line (the game clock and the place read come from eventlog)
        pg_query_params($this->db->link,
            "INSERT INTO eventlog (type, data, gamets, localts, ts, people, location) VALUES ('inputtext', $1, $2, $3, $3, $4, $5)",
            [self::PLAYER . ": {$line}", (int) $this->gamets, $this->realTs, '|' . self::AELA . '|', 'Jorrvaskr, Whiterun']);
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . self::AELA . '|' . self::PLAYER . '|';
        $run = function (string $hook) use ($request, $party): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = self::AELA;
            $GLOBALS['RELDYN_NPC_NAME'] = self::AELA;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
        };
        $run('prerequest.php');
        $this->clearReldynGlobals();
        $run('context.php');
        $context = implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []));
        $this->clearReldynGlobals();
        $run('postrequest.php');
        $this->clearReldynGlobals();
        $this->contexts[] = $context;
        return $context;
    }

    /**
     * An exchange the eval scored (shared contract v1), applied as the eval worker applies it:
     * appended to plugin_extended_data.reldyn.eval_inbox, then RelDynEval::applyInboxInWorker.
     */
    private function evalItem(array $o): void
    {
        $this->gamets += 2 * self::MINUTE;
        $item = array_replace([
            'v' => 1, 'npc' => self::AELA, 'npc_id' => $this->npcId, 'gamets' => (int) $this->gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 3, 'trust' => 2, 'comfort' => 2, 'respect' => 1, 'passion' => 0, 'maturity' => 0],
            'tags' => ['quality_time'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5, 'positive_interaction' => true,
            'summary' => 'an evening at Jorrvaskr',
            'witnesses' => [],
        ], $o);
        pg_query_params($this->db->link,
            "INSERT INTO eventlog (type, data, gamets, localts, ts, people, location) VALUES ('chat', $1, $2, $3, $3, $4, $5)",
            [self::AELA . ': ' . $item['summary'], (int) $this->gamets, $this->realTs, '|' . self::AELA . '|', 'Jorrvaskr, Whiterun']);
        $this->assertTrue(RelDynStorage::appendItem($this->npcId, RelDynStorage::KEY_EVAL_INBOX, $item));
        $GLOBALS['gameRequest'] = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ': ...'];
        $this->assertNotNull(RelDynEval::applyInboxInWorker(self::AELA), 'the worker applied the inbox');
        unset($GLOBALS['gameRequest']);
    }

    /** A day at her side: talk, an eval-scored hunt with praise, and a warm evening by the fire. */
    private function dayTogether(int $day, int $passion, string $mood): void
    {
        $this->gamets = self::T0 + $day * self::DAY;
        $this->turn('Good hunting today. Your aim is true.', $mood, $this->gamets);
        $this->evalItem(['tags' => ['quality_time', 'praise', 'competence'], 'significance' => 0.7,
            'signals' => ['affinity' => 3, 'trust' => 2, 'comfort' => 2, 'respect' => 2, 'passion' => $passion, 'maturity' => 0],
            'summary' => "day {$day}: a hunt on the tundra, he praised her aim"]);
        $this->turn('Sit with me by the fire a while.', $mood);
        $this->evalItem(['tags' => ['quality_time', 'reassurance'], 'significance' => 0.6,
            'signals' => ['affinity' => 2, 'trust' => 2, 'comfort' => 2, 'respect' => 0, 'passion' => $passion, 'maturity' => 0],
            'summary' => "day {$day}: an evening by the fire at Jorrvaskr"]);
        $this->turn('Rest well, Aela.', $mood);
    }

    private function dynamics(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->npcId]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function reldyn(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->npcId]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    /** Core Player relationship as Sharmat's consent gate reads it (RelationshipManager::getPlayerRelationship). */
    private function core(): array
    {
        return RelationshipManager::getPlayerRelationship(self::AELA);
    }

    private function historyTypes(): array
    {
        $res = pg_query_params($this->db->link,
            "SELECT extended_data #>> '{relationships,Player,type}' AS type FROM core_npc_master_history WHERE npc_id = $1 ORDER BY history_id",
            [$this->npcId]);
        return array_column(pg_fetch_all($res) ?: [], 'type');
    }

    private function passion(): float
    {
        return RelationshipDynamics::getPassion($this->dynamics());
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'the schema holds every table the production path touches');
    }

    // ------------------------------------------------------------------ the scenarios

    public function testTheWarriorWinsHerThenAnUnfulfilledRomanceStepsBackToFriends(): void
    {
        $this->seedAela(64, 'platonic');
        $this->player(self::WARRIOR_DRUID);

        // The player data she judges comes from core (RelDynPlayer::profile)
        $profile = RelDynPlayer::profile();
        $this->assertTrue($profile['known']);
        $this->assertGreaterThan($profile['archetypes']['bard'], $profile['archetypes']['warrior']);

        // ---- Day 0: she sees him. Her pillars pass (warrior / druid through her hunter's lens)
        $ctx = $this->turn('Aela. The Circle said you wanted to see me.', 'neutral', self::T0);
        $d = $this->dynamics();
        $a = $d['_attraction'];
        $this->assertTrue($a['passes'], json_encode($a));
        $this->assertFalse($a['friendzoned']);
        $this->assertSame('drawn', $a['outcome'], 'visceral and sociological pillars pass');
        $this->assertContains($a['valued'], ['warrior', 'hunter', 'druid'], 'what she values in him');
        $this->assertContains('crush', $a['blocked_types'], 'the ceiling lifted; a crush is not yet earned');
        $this->assertStringContainsString('drawn to the player', $ctx);
        $this->assertDoesNotMatchRegularExpression('/<attraction_context>[^<]*\d/', $ctx, 'feelings, never numbers');
        $passion0 = $this->passion();
        $aff0 = intval($this->core()['aff']);

        // ---- Days 1-3 at her side: passion grows (attraction x attachment), the crush is earned
        // through significant interactions, and a significant intimate moment makes it core's
        $this->dayTogether(1, 6, 'flirty');
        $this->dayTogether(2, 6, 'flirty');
        $this->assertGreaterThan($passion0 + 5.0, $this->passion(), 'the warrior earns passion');
        // rulings §9: passion by attraction x attachment (Aela: Independent -> avoidant 0.7,
        // x lerp(passing_min_mult, 1, score)); a friendzoned player gets unattracted_mult
        $this->assertGreaterThanOrEqual(0.5, $this->dynamics()['_attraction']['passion_mult']);
        $this->assertNotContains('crush', $this->dynamics()['_attraction']['blocked_types'], 'the crush is earned');

        $this->gamets = self::T0 + 3 * self::DAY;
        $this->turn('I keep thinking about last night.', 'flirty', $this->gamets);
        $this->evalItem(['tags' => ['intimacy', 'quality_time'], 'significance' => 0.9,
            'signals' => ['affinity' => 4, 'trust' => 3, 'comfort' => 3, 'respect' => 1, 'passion' => 8, 'maturity' => 0],
            'summary' => 'after the hunt she let him close; a kiss under the Skyforge']);
        $this->assertSame('crush', $this->core()['type'], "one rung: platonic -> crush in core's Player.type");
        $this->assertSame('promotion', $this->reldyn()['core_type_change']['direction']);
        $this->assertSame('crush', $this->reldyn()['romance']['core_type'], 'published for the Sharmat handoff');

        // ---- The next days: affinity climbs through the eval (RelDyn owns core aff) to the bonded tier
        for ($day = 4; $day <= 14 && intval($this->core()['aff']) < 78; $day++) {
            $this->dayTogether($day, 6, 'flirty');
        }
        $aff = intval($this->core()['aff']);
        $this->assertGreaterThan($aff0, $aff);
        $this->assertGreaterThanOrEqual(76, $aff, 'high affinity: the bonded tier');
        $this->assertSame('crush', $this->core()['type'], 'affinity alone promotes nothing');
        $this->assertGreaterThan(8.0, $this->passion(), 'passion held through the days (XYZ pulls it toward its baseline)');

        // ---- A significant romantic exchange at high affinity: romantic
        $this->turn('Aela... I love you. I want you at my side, always.', 'lovely');
        $this->evalItem(['tags' => ['confession', 'intimacy'], 'significance' => 1.0,
            'signals' => ['affinity' => 4, 'trust' => 3, 'comfort' => 3, 'respect' => 1, 'passion' => 10, 'maturity' => 0],
            'summary' => 'he told her he loves her; she took his hand and said the wolf in her chose him too']);
        $core = $this->core();
        $this->assertSame('romantic', $core['type'], 'crush -> romantic after a significant romantic exchange');
        $this->assertGreaterThanOrEqual(76, intval($core['aff']));
        $this->assertSame('romantic', end($this->historyTypes()) ?: null, "core's timeline snapshot holds it (a save load restores it)");
        $romanceDay = (int) floor(($this->gamets - self::T0) / self::DAY);
        $this->assertStringNotContainsString('ERROR', (string) file_get_contents($this->errorLog));

        // ---- Then he is around every day but gives her nothing she needs: the absence of
        // fulfillment. Mature Aela does not fester; she states a boundary, calmly, once.
        $stated = null;
        $boundaryText = '';
        for ($k = 1; $k <= 14 && $stated === null; $k++) {
            $ctx = $this->turn('Morning.', 'neutral', self::T0 + ($romanceDay + $k) * self::DAY);
            if (str_contains($ctx, '<relationship_boundary>')) {
                $stated = $this->gamets;
                $boundaryText = $ctx;
            }
        }
        $this->assertNotNull($stated, 'a boundary was stated within two game weeks of low fulfillment: '
            . json_encode($this->dynamics()['_fulfillment']['boundary'] ?? null));
        $this->assertStringContainsString('has thought about this calmly', $boundaryText);
        $this->assertDoesNotMatchRegularExpression('/<relationship_boundary>[^<]*\d/', $boundaryText, 'a feeling, never a number');
        $f = RelationshipDynamics::fulfillment(self::AELA, $this->dynamics(), $stated);
        $this->assertLessThan(-0.25, $f['band'], 'low fulfillment is what she is answering');
        $this->assertSame('probation', $this->dynamics()['_fulfillment']['boundary']['state']);
        $this->assertSame('romantic', $this->core()['type'], 'stating it changes nothing yet');

        // ---- The probation: one warm evening, then the old pattern. It does not hold.
        $this->turn('I heard you. Let us walk the tundra tonight.', 'neutral', $stated + self::DAY);
        $this->evalItem(['tags' => ['quality_time', 'reassurance'], 'significance' => 0.7,
            'signals' => ['affinity' => 2, 'trust' => 1, 'comfort' => 2, 'respect' => 0, 'passion' => 2, 'maturity' => 0],
            'summary' => 'one evening walk on the tundra']);
        $this->assertSame('romantic', $this->core()['type'], 'no re-promotion games during probation');
        for ($k = 2; $k <= 8 && $this->core()['type'] === 'romantic'; $k++) {
            $this->turn('Morning.', 'neutral', $stated + $k * self::DAY);
        }

        // ---- She steps back to friend, deliberately, through core's lock and timeline
        $core = $this->core();
        $this->assertSame('platonic', $core['type'], 'romantic -> friend when the change did not hold');
        $this->assertSame('platonic', end($this->historyTypes()) ?: null, "core's timeline stamped the step-back");
        $rd = $this->reldyn();
        $this->assertSame('step_back', $rd['core_type_change']['direction']);
        $this->assertStringContainsString('mature boundary', $rd['core_type_change']['reason']);
        $d = $this->dynamics();
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType(self::AELA, $d));
        $this->assertSame('normal', $d['_walkaway_state'] ?? 'normal', 'a calm decision, not a walkaway');
        $this->assertLessThanOrEqual(RelationshipDynamics::getNeglectProfile($d)['ceiling'] + 1e-6,
            floatval($d['dimensions']['resentment']['x'] ?? 0), 'mature: resentment stays under her neglect ceiling');
        // The turn whose prerequest stepped back is the one whose context says it, once
        $said = (string) end($this->contexts);
        $this->assertStringContainsString('stepping back from a romance to friendship', $said);
        $this->assertDoesNotMatchRegularExpression('/<relationship_boundary>[^<]*\d/', $said, 'a feeling, never a number');
        $this->assertStringNotContainsString('stepping back', $this->turn('Aela?', 'neutral'), 'said once');
        $this->assertNoDbFailures();
    }

    public function testTheBardEarnsHerAffinityButStaysFriendzoned(): void
    {
        $this->seedAela(64, 'platonic');
        $this->player(self::BARD_SCHOLAR);

        $ctx = $this->turn('Aela. I wrote a verse about the Companions.', 'neutral', self::T0);
        $a = $this->dynamics()['_attraction'];
        $this->assertFalse($a['passes'], json_encode($a));
        $this->assertTrue($a['friendzoned'], 'tolerated, valued - no pull');
        $this->assertSame(20.0, floatval($a['passion_cap']), 'MDD 6.2: passion hard-capped at 20');
        $this->assertStringContainsString('warm deflection', $ctx);
        $aff0 = intval($this->core()['aff']);

        // The same days the warrior gets (significant evenings, passion in the eval, a
        // confession); she answers with warm deflection (the context told her so), not flirting.
        $maxPassion = 0.0;
        for ($day = 1; $day <= 6; $day++) {
            $this->dayTogether($day, 6, 'amused');
            $maxPassion = max($maxPassion, $this->passion());
            $this->assertSame('platonic', $this->core()['type'], "day {$day}: never promoted");
        }
        $this->turn('Aela... I love you.', 'amused');
        $this->evalItem(['tags' => ['confession', 'intimacy'], 'significance' => 1.0,
            'signals' => ['affinity' => 4, 'trust' => 3, 'comfort' => 3, 'respect' => 1, 'passion' => 10, 'maturity' => 0],
            'summary' => 'he told her he loves her; she smiled and called him a dear friend']);
        $maxPassion = max($maxPassion, $this->passion());

        $this->assertLessThanOrEqual(20.0, $maxPassion, 'passion never above 20');
        $this->assertLessThanOrEqual(0.1, $this->dynamics()['_attraction']['passion_mult'], 'unattracted: passion barely moves');
        $this->assertGreaterThan($aff0 + 5, intval($this->core()['aff']), 'she still grows fond of him');
        $this->assertSame('platonic', $this->core()['type'], 'never promoted');
        $d = $this->dynamics();
        $this->assertTrue($d['_attraction']['friendzoned']);
        $this->assertStringStartsWith('friendzoned', (string) ($d['_romance']['last_block'] ?? ''));
        $this->assertSame(0.0, (float) ($d['_romance']['momentum'] ?? 0.0), 'no momentum banks while the gate is closed');
        $this->assertArrayNotHasKey('core_type_change', $this->reldyn(), 'RelDyn never wrote a type');
        $state = $this->reldyn()['romance'];
        $this->assertTrue($state['friendzoned']);
        $this->assertTrue($state['consent_block'], 'the Sharmat handoff state says no');
        $this->assertStringContainsString('warm deflection', (string) end($this->contexts));
        $this->assertNoDbFailures();
    }
}
