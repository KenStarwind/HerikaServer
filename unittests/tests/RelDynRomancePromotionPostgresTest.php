<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once __DIR__ . '/../../lib/logger.php';
// Loaded as in production (main.php / the worker bootstrap): the game clock outside a request
// (DataLastKnownGameTS, an eventlog query) and core's timeline stamp run their real paths.
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** Minimal `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynRomancePgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) {
            throw new RuntimeException("cannot connect to {$dsn}");
        }
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function execQuery($q) { return pg_query($this->link, $q); }

    public function insert($table, $data)
    {
        $ph = [];
        foreach (array_keys($data) as $i => $_) $ph[] = '$' . ($i + 1);
        $params = array_map(fn($v) => (is_array($v) || is_object($v)) ? json_encode($v) : $v, array_values($data));
        return pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $ph) . ')', $params);
    }

    public function updateRow($table, $data, $where)
    {
        $set = [];
        $i = 0;
        foreach (array_keys($data) as $col) $set[] = "{$col} = $" . (++$i);
        return (bool) pg_query_params($this->link, "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}", array_values($data));
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * romance-promotion (rulings 2026-09-24 §9) on a real PostgreSQL, through the eval worker's
 * real path: a shared-eval-contract item in plugin_extended_data.reldyn.eval_inbox ->
 * RelDynEval::applyInboxInWorker() -> applyEvalInbox() (signals, feelings, locked affinity
 * commit) -> RelDynRomance::maybePromote() -> changeCoreRelationshipType() under core's
 * advisory lock -> core's timeline snapshot. Core Player.type is what Sharmat's consent gate
 * reads (RelationshipManager::getPlayerRelationship), so it is asserted through that reader.
 *
 * Ken: "Aela would have an affinity for a strong warrior type or a formidable druid, but a
 * bard or a scholar she could tolerate but probably wouldn't feel passion towards."
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it. No LLM or embedding call is made (the
 * items are the eval's output; no player appearance text, so beauty needs no embedding).
 */
final class RelDynRomancePromotionPostgresTest extends TestCase
{
    private const NPC = 'Aela the Huntress';
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 300 * self::DAY + 14 * (self::DAY / 24);

    private string $dsn;
    private string $schema;
    private RelDynRomancePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private int $gamets = 0;

    /** Aela's own attraction profile (MDD 2.2-2.4): martial skill is rigid, she wants hunts behind it. */
    private const AELA_PROFILE = [
        'beauty_keywords' => ['rugged', 'strong', 'scarred'],
        'strength_skills' => ['OneHanded', 'TwoHanded', 'Archery', 'Block', 'LightArmor', 'HeavyArmor'],
        'strength_mode' => 'strict',          // no partial credit for non-martial skills
        'strength_threshold' => 200,
        'status_metrics' => [['type' => 'faction_rank', 'faction' => 'Companions', 'min' => 2]],
        'competence_metrics' => [['type' => 'kill_category', 'category' => 'animals', 'min' => 30]],
        'pillar_rigidity' => ['beauty' => 'soft', 'strength' => 'rigid', 'status' => 'rigid', 'competence' => 'rigid'],
        'intimacy_gate' => 'visceral',
        'gender_pref' => 'bisexual',
    ];

    /**
     * A warrior: blade, bow and armor; a finished questline, a house and a long trail of hunts.
     * Core-shaped (RelDynPlayer::profile reads them): core_player skills / stats as the plugin
     * writes them, Skyrim tracked stats, RelDyn's gold ledger.
     */
    private const WARRIOR = [
        'skills' => ['onehanded' => 80, 'archery' => 75, 'lightarmor' => 60, 'block' => 40, 'speechcraft' => 25, 'illusion' => 15],
        'level' => 38,
        'stats' => ['People Killed' => 40, 'Animals Killed' => 60, 'Creatures Killed' => 20, 'Undead Killed' => 30,
            'Dragon Souls Collected' => 1, 'Quests Completed' => 30, 'Questlines Completed' => 1, 'Houses Owned' => 1,
            'Dungeons Cleared' => 12, 'Most Gold Carried' => 6000],
        'gold_moved' => 15000,
    ];

    /** A bard: silver tongue and illusion, barely a sword arm, no hunter; the same quests, house and questline. */
    private const BARD = [
        'skills' => ['speechcraft' => 95, 'illusion' => 80, 'sneak' => 55, 'onehanded' => 10, 'archery' => 10, 'lightarmor' => 12],
        'level' => 30,
        'stats' => ['People Killed' => 5, 'Animals Killed' => 6, 'Creatures Killed' => 3, 'Undead Killed' => 2,
            'Quests Completed' => 30, 'Questlines Completed' => 1, 'Houses Owned' => 1, 'Dungeons Cleared' => 10,
            'Most Gold Carried' => 6000],
        'gold_moved' => 15000,
    ];

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
        $this->schema = 'reldyn_romance' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and core_npc_master_history.sql.
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
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        // data/database_default.sql eventlog: the worker's game clock (DataLastKnownGameTS) reads it.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        // The attraction matrix reads the player's appearance from core_player (none here).
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_close($admin);

        $this->db = new RelDynRomancePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELDYN_PLAYER_NAME', 'PLAYER_BIOS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        // The eval worker runs outside a game request (eval_worker.php).
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-romance-');
        ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_romance_pg_test.log');
        $this->gamets = (int) self::T0;
        $this->config([]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) {
            return;
        }
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
        @unlink($this->errorLog);
    }

    private function config(array $overrides): void
    {
        $cfg = array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $overrides);
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['relationship_dynamics_config', json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
    }

    /** The player as CHIM core holds it: core_player skills / stats / tracked stats, RelDyn's gold ledger. */
    private function playerStats(array $p): void
    {
        $skills = array_merge(array_fill_keys(RelDynPlayer::SKILLS, 15), $p['skills'] ?? []);
        $rows = ['skills' => json_encode($skills), 'stats' => json_encode(['level' => $p['level'] ?? 1, 'health' => 200])];
        foreach ($p['stats'] ?? [] as $name => $v) $rows[$name] = (string) $v;
        if (isset($p['gold_moved'])) {
            $rows[RelDynPlayer::LEDGER_KEY] = json_encode(['moved' => $p['gold_moved'], 'gained' => $p['gold_moved'], 'spent' => 0, 'snapshots' => 5]);
        }
        foreach ($rows as $id => $value) {
            pg_query_params($this->db->link,
                'INSERT INTO core_player (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, $value]);
        }
    }

    /**
     * Aela's core row (Companions huntress) with core Player aff/type, and her stored dynamics:
     * her attraction profile and the temperament / attachment the test names.
     */
    private function seedAela(int $aff, string $type, array $dyn = [], array $ext = []): int
    {
        $extended = array_replace(['class' => ['name' => 'Hunter'],
            'relationships' => ['Player' => ['aff' => $aff, 'type' => $type]]], $ext);
        $dynamics = array_replace([
            'profile_overrides' => ['temperament' => 'Bold', 'attachment_style' => 'secure'],
            'attraction_profile' => self::AELA_PROFILE,
        ], $dyn);
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, personality, speechstyle, core, npc_static_bio, gender, race, metadata, extended_data, plugin_extended_data)'
            . " VALUES ($1, '', '', $2, '', 'female', 'NordRace', $3::jsonb, $4::jsonb, $5::jsonb) RETURNING id",
            [self::NPC, 'Roleplay as ' . self::NPC, json_encode(['skills' => []]), json_encode($extended),
             json_encode(['reldyn' => ['dynamics' => $dynamics]])]));
        // The game clock the worker reads (DataLastKnownGameTS: newest eventlog row).
        pg_query_params($this->db->link, "INSERT INTO eventlog (type, data, gamets, localts, ts) VALUES ('inputtext', 'hi', $1, $2, $2)",
            [$this->gamets, time()]);
        return (int) $row['id'];
    }

    /** A shared-eval-contract v1 item, as the eval worker produces it. */
    private function item(int $npcId, array $o = []): array
    {
        $this->gamets += 1000;
        return array_replace([
            'v' => 1, 'npc' => self::NPC, 'npc_id' => $npcId, 'gamets' => $this->gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 2, 'trust' => 2, 'comfort' => 2, 'respect' => 1, 'passion' => 6, 'maturity' => 0],
            'tags' => ['quality_time', 'intimacy'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 1.0,
            'positive_interaction' => true,
            'summary' => 'They sat by the fire after the hunt and she let him close',
            'witnesses' => [],
        ], $o);
    }

    /** The eval worker applying what it just queued (RelDynEval::applyInboxInWorker). */
    private function exchange(int $npcId, array $o = []): void
    {
        $item = $this->item($npcId, $o);
        // The exchange's own eventlog row: the worker's game clock reads the newest one.
        pg_query_params($this->db->link, "INSERT INTO eventlog (type, data, gamets, localts, ts, people) VALUES ('chat', $1, $2, $3, $3, '')",
            [self::NPC . ': ' . $item['summary'], $item['gamets'], time()]);
        $this->assertTrue(RelDynStorage::appendItem($npcId, RelDynStorage::KEY_EVAL_INBOX, $item));
        $this->assertNotNull(RelDynEval::applyInboxInWorker(self::NPC), 'the worker applied the inbox');
    }

    /**
     * The attraction lane's tier lift (attraction design: "tier unlock needs a significant
     * interaction"): significant, positive heart-to-hearts without passion (no romance
     * moment) until the lifted ceiling is earned. Returns how many it took.
     */
    private function earnAttraction(int $id): int
    {
        for ($n = 1; $n <= 10; $n++) {
            $this->exchange($id, ['tags' => ['quality_time'], 'significance' => 0.8, 'summary' => 'a long talk after the hunt',
                'signals' => ['affinity' => 1, 'trust' => 2, 'comfort' => 2, 'respect' => 1, 'passion' => 0, 'maturity' => 0]]);
            $state = $this->reldyn($id)['dynamics']['_attraction_state'] ?? null;
            $this->assertIsArray($state, 'the Matrix tracks this bond');
            if (empty($state['pending'])) return $n;
        }
        $this->fail('the attraction lift was never earned');
    }

    /** Core Player.type as Sharmat's consent gate reads it (common.php aiagentNsfwRelTypeSexEligible). */
    private function coreType(): string
    {
        return (string) RelationshipManager::getPlayerRelationship(self::NPC)['type'];
    }

    private function reldyn(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    // =========================================================================

    public function testAWarriorWinsAelaThroughSignificantMoments(): void
    {
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(60, 'platonic');

        // Her attraction ceiling lifts for him at once, but the crush is earned only through
        // significant interactions (attraction lane: Aela needs 2-3); the moment that earns it
        // is the one that promotes (momentum counts only while the gate is open).
        $this->exchange($id);
        $state = $this->reldyn($id)['dynamics']['_attraction_state'];
        $need = intval($state['pending']['need']);
        $this->assertGreaterThanOrEqual(2, $need);
        $this->assertLessThanOrEqual(3, $need);
        for ($i = 2; $i < $need; $i++) {
            $this->assertSame('platonic', $this->coreType(), "moment {$i} of {$need}: not yet");
            $this->exchange($id);
        }
        $this->assertSame('platonic', $this->coreType(), 'not before the crush is earned');
        $this->assertStringStartsWith('attraction has not opened crush yet', $this->reldyn($id)['dynamics']['_romance']['last_block']);
        $this->exchange($id);

        $this->assertSame('crush', $this->coreType(), 'promoted one rung: platonic -> crush');
        $rd = $this->reldyn($id);
        $this->assertSame('platonic', $rd['core_type_change']['from']);
        $this->assertSame('crush', $rd['core_type_change']['to']);
        $this->assertSame('promotion', $rd['core_type_change']['direction']);
        $this->assertSame((float) $this->gamets, (float) $rd['core_type_change']['gamets'], 'stamped on the game clock');
        $this->assertSame(0.0, (float) $rd['dynamics']['_romance']['momentum'], 'momentum spent');
        $this->assertArrayNotHasKey('pending', $rd['dynamics']['_romance'], 'moments consumed');
        $this->assertSame('crush', $rd['romance']['core_type'], 'the handoff state is published at once');
        $this->assertTrue($rd['romance']['romantic']);

        $hist = pg_fetch_assoc(pg_query_params($this->db->link,
            "SELECT extended_data->'relationships'->'Player'->>'type' AS t FROM core_npc_master_history WHERE npc_id = $1 ORDER BY history_id DESC LIMIT 1", [$id]));
        $this->assertSame('crush', $hist['t'], "core's timeline snapshot holds the promotion (a save load restores it)");
        $this->assertStringContainsString('[RelDyn-TYPE] Aela the Huntress -> Player: type platonic -> crush (romance: They sat by the fire', $this->log());
        $this->assertStringContainsString('[promotion]', $this->log());
        $this->assertStringNotContainsString('ERROR', $this->log());
    }

    public function testABardSheToleratesIsNeverPromoted(): void
    {
        // Sociological pillars pass (a Circle member who hunts), the visceral ones do not
        // (no sword arm): the Matrix friendzones him. MDD 6.2: passion capped, never romance.
        $this->playerStats(self::BARD);
        $id = $this->seedAela(90, 'platonic');

        $a = RelationshipDynamics::attractionFor(self::NPC, RelationshipDynamics::getDynamics(self::NPC));
        $this->assertTrue($a['friendzoned'], $a['reason']);
        $this->assertFalse($a['pillars']['strength']['pass']);
        $this->assertTrue($a['pillars']['competence']['pass']);

        for ($i = 0; $i < 4; $i++) {
            $this->exchange($id, ['tags' => ['quality_time', 'intimacy', 'confession']]);
        }

        $this->assertSame('platonic', $this->coreType(), 'four confessions at Bonded tier: still platonic');
        $rd = $this->reldyn($id);
        $this->assertSame(0.0, (float) $rd['dynamics']['_romance']['momentum'], 'no momentum banks while the gate is closed');
        $this->assertStringStartsWith('friendzoned', $rd['dynamics']['_romance']['last_block']);
        $this->assertArrayNotHasKey('core_type_change', $rd);
    }

    public function testTheWarriorWhoFailsHerAttractionIsNotPromotedEither(): void
    {
        // Neither axis: a weak fighter who never hunted. Not friendzoned, just not her type.
        $this->playerStats(['skills' => ['speechcraft' => 50, 'onehanded' => 15], 'level' => 4, 'stats' => ['Animals Killed' => 2]]);
        $id = $this->seedAela(70, 'platonic');
        $this->exchange($id);
        $this->assertSame('platonic', $this->coreType());
        $this->assertStringStartsWith('attraction does not pass', $this->reldyn($id)['dynamics']['_romance']['last_block']);
    }

    public function testNoPromotionBelowCoresFondTier(): void
    {
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(40, 'platonic');   // Friendly
        $this->exchange($id);
        $this->assertSame('platonic', $this->coreType());
        $this->assertStringContainsString('tier friend (core aff 4', $this->reldyn($id)['dynamics']['_romance']['last_block']);
    }

    public function testSmallTalkIsNoMomentButAConfessionIs(): void
    {
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(60, 'platonic');
        $this->earnAttraction($id);
        $this->assertSame('platonic', $this->coreType(), 'earning her attraction is not yet romance');

        $this->exchange($id, ['significance' => 0.2, 'summary' => 'small talk about the weather']);
        $this->exchange($id, ['tags' => ['quality_time'], 'significance' => 0.8, 'summary' => 'a long talk about Skjor',
            'signals' => ['affinity' => 2, 'trust' => 3, 'comfort' => 2, 'respect' => 0, 'passion' => 0, 'maturity' => 0]]);
        $this->assertSame('platonic', $this->coreType(), 'small talk, and a heart-to-heart without passion, are not romance');
        $this->assertSame(0.0, (float) ($this->reldyn($id)['dynamics']['_romance']['momentum'] ?? 0.0), 'no romance moment yet');

        $this->exchange($id, ['tags' => ['confession'], 'significance' => 0.4, 'summary' => 'he told her he cannot stop thinking of her',
            'signals' => ['affinity' => 1, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0]]);
        $this->assertSame('crush', $this->coreType(), 'an explicit confession she welcomed is the significant interaction');
    }

    public function testCrushBecomesRomanticOnlyAtTheBondedTier(): void
    {
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(70, 'crush');
        $this->exchange($id);
        $this->assertSame('crush', $this->coreType(), 'Fond is not enough for romantic');
        $this->assertStringContainsString('below bonded', $this->reldyn($id)['dynamics']['_romance']['last_block']);

        pg_query($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', '80')");
        $this->earnAttraction($id);
        $this->assertSame('crush', $this->coreType(), 'the romantic lift is earned, no romantic moment yet');
        $this->exchange($id);
        $this->assertSame('romantic', $this->coreType(), 'crush -> romantic at Devoted (RelDyn bonded)');
        $this->assertSame('romantic', $this->reldyn($id)['romance']['core_type']);
    }

    public function testRomanticNeedsTheSociologicalPillars(): void
    {
        // MDD 2.6: visceral only = hookup material; commitment blocked. Here status is soft
        // for the crush but she wants a Circle member for more (rigid status, rank 0).
        // The same sword arm with no standing: no questline, no house, no gold moved.
        $this->playerStats(['stats' => ['People Killed' => 40, 'Animals Killed' => 60, 'Creatures Killed' => 20,
            'Undead Killed' => 30, 'Dragon Souls Collected' => 1, 'Quests Completed' => 30, 'Questlines Completed' => 0,
            'Houses Owned' => 0, 'Stores Invested In' => 0, 'Main Quests Completed' => 0, 'Dungeons Cleared' => 12,
            'Most Gold Carried' => 300, 'Gold Found' => 400, 'Barters' => 5], 'gold_moved' => 0] + self::WARRIOR);
        $id = $this->seedAela(85, 'crush');
        $this->exchange($id);
        $this->assertSame('crush', $this->coreType());
        $this->assertStringContainsString('sociological', $this->reldyn($id)['dynamics']['_romance']['last_block']);
    }

    public function testAGuardedNpcNeedsSeveralMomentsAndASetbackResetsThem(): void
    {
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(70, 'platonic', ['profile_overrides' => ['temperament' => 'Guarded', 'attachment_style' => 'secure']]);
        $this->earnAttraction($id);

        $this->exchange($id, ['significance' => 0.8]);
        $this->exchange($id, ['significance' => 0.8]);
        $this->assertSame('platonic', $this->coreType(), 'Guarded: 1.6 of 2.0');
        $this->assertEqualsWithDelta(1.6, (float) $this->reldyn($id)['dynamics']['_romance']['momentum'], 1e-9);

        $this->exchange($id, ['tags' => ['insult'], 'significance' => 0.6, 'positive_interaction' => false,
            'grievance' => ['flag' => true, 'kind' => 'mocked', 'severity' => 1],
            'signals' => ['affinity' => -3, 'trust' => -2, 'comfort' => -2, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'summary' => 'he mocked her in front of the Circle']);
        $this->assertSame(0.0, (float) $this->reldyn($id)['dynamics']['_romance']['momentum'], 'the pattern broke');

        $this->exchange($id, ['significance' => 1.0]);
        $this->assertSame('platonic', $this->coreType());
        $this->exchange($id, ['significance' => 1.0]);
        $this->assertSame('crush', $this->coreType(), 'two unbroken significant moments');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockers')]
    public function testStatesThatHoldAPromotionBack(array $dyn, array $ext, string $reason, string $type = 'platonic'): void
    {
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(60, 'platonic', $dyn, $ext);
        $this->exchange($id);
        $this->assertSame($type, $this->coreType(), 'not promoted');
        $this->assertStringContainsString($reason, $this->reldyn($id)['dynamics']['_romance']['last_block']);
    }

    public static function blockers(): array
    {
        return [
            'editor lock' => [[], ['relationships_locked' => true], 'relationships_locked'],
            'walkaway' => [['_walkaway_state' => 'active'], [], 'state: walkaway'],
            'open conflict' => [['in_conflict' => true, 'conflict_positive_count' => 0], [], 'state: conflict'],
            'aromantic' => [['relationship_preference' => 'aromantic'], [], "preference 'aromantic' blocks crush"],
            // Mature boundary (fulfillment lane): stated, the pattern on probation - no romance games meanwhile
            'boundary on probation' => [['_fulfillment' => ['boundary' => ['state' => 'probation', 'decided_gamets' => 1, 'until_gamets' => 9e12]]], [], 'state: boundary'],
            'familial' => [[], ['relationships' => ['Player' => ['aff' => 60, 'type' => 'familial']]], "no romance step from core type 'familial'", 'familial'],
            'ex' => [[], ['relationships' => ['Player' => ['aff' => 60, 'type' => 'ex']]], "no romance step from core type 'ex'", 'ex'],
        ];
    }

    public function testADeliberateStepBackNeedsTheChangeRewrittenConsistently(): void
    {
        // Mature boundary (rulings §9): the fulfillment lane stepped the romance back to friends.
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(60, 'romantic');
        $this->assertTrue(RelationshipDynamics::changeCoreRelationshipType(self::NPC, 'platonic', 'boundary not honored; stepping back'));
        $this->assertSame('step_back', $this->reldyn($id)['core_type_change']['direction']);
        $this->earnAttraction($id);

        $this->exchange($id);
        $this->exchange($id);
        $this->assertSame('platonic', $this->coreType(), 'two good evenings do not undo a step-back');
        $this->exchange($id);
        $this->assertSame('crush', $this->coreType(), 'three unbroken significant moments (x3) do');
    }

    public function testChangeCoreRelationshipTypeIsLockedCheckedAndStamped(): void
    {
        $id = $this->seedAela(60, 'platonic');
        $this->assertFalse(RelationshipDynamics::changeCoreRelationshipType(self::NPC, 'soulmate', 'invented'));
        $this->assertFalse(RelationshipDynamics::changeCoreRelationshipType(self::NPC, 'romantic', 'stale look', ['crush']),
            'compare-and-set: core is platonic, not crush');
        $this->assertSame('platonic', $this->coreType());

        $this->assertTrue(RelationshipDynamics::changeCoreRelationshipType(self::NPC, 'Lover', 'alias'));
        $this->assertSame('romantic', $this->coreType(), "core's alias table: stored canonically");
        $ext = json_decode(pg_fetch_result(pg_query($this->db->link, 'SELECT extended_data FROM core_npc_master'), 0, 0), true);
        $this->assertSame(['aff' => 60, 'type' => 'romantic'], $ext['relationships']['Player'], 'only the type changed');
        $this->assertSame('promotion', $this->reldyn($id)['core_type_change']['direction']);

        pg_query($this->db->link, "UPDATE core_npc_master SET extended_data = extended_data || '{\"relationships_locked\": true}'");
        $this->assertFalse(RelationshipDynamics::changeCoreRelationshipType(self::NPC, 'platonic', 'locked'));
        $this->assertSame('romantic', $this->coreType());
        $this->assertStringContainsString('relationships_locked', $this->log());
    }

    public function testNothingPromotesWithRomancePromotionOff(): void
    {
        $this->config(['romance_promotion' => ['enabled' => false]]);
        $this->playerStats(self::WARRIOR);
        $id = $this->seedAela(60, 'platonic');
        $this->exchange($id);
        $this->assertSame('platonic', $this->coreType());
        $this->assertArrayNotHasKey('_romance', $this->reldyn($id)['dynamics']);
    }
}
