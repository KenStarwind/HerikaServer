<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
// Production requests have core's game-clock helpers and the Player class loaded.
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/player.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_dynamics.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/reldyn_player.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws,
 * query / execQuery return false. Every statement and every failure is recorded.
 */
final class RelDynPlayerPgDb
{
    public $link;
    public array $statements = [];
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function run(string $q, array $params = [])
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 200);
        return $res;
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $this->run($q, $params);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = $this->run($q);
        if (!$res) throw new Exception("SQL: FetchAll query failed '{$q}' " . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->run($q); }
    public function execQuery($q) { return $this->run($q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * player-stats-pipeline: RelDynPlayer::profile() reads what CHIM 3.4.1 actually knows about
 * the player (core_player rows written by gamedata.php from the AIAgent plugin, Skyrim tracked
 * stats from the plugin's OnTrackedStatsEvent -> setconf, eventlog deaths, questlog) and
 * derives archetypes and the strength / status / competence pillars. Every value names its
 * source; what CHIM does not know stays unknown. Real PostgreSQL with core's column shapes.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynPlayerProfilePostgresTest extends TestCase
{
    private const DAY = 10000000;
    private const NOW = 120 * self::DAY + 5000000;

    private string $dsn;
    private string $schema;
    private RelDynPlayerPgDb $db;
    private array $saved = [];
    private int $rowid = 0;

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
        $this->schema = 'reldyn_player' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Core 3.4.1 shapes (dwemer \d): core_player / conf_opts key-value, eventlog, quests, questlog.
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text, dynamic_profile_pending boolean NOT NULL DEFAULT true)");
        pg_query($admin, "CREATE TABLE questlog (ts text, sess varchar(1024), id_quest varchar(1024), name text,
            editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint, gamets bigint, data text, status text,
            rowid serial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // What the prerequest hook also touches (lib/core/database_schema/core_npc_master*.sql,
        // data/database_default.sql responselog, core 3.4.1 locations / oghma text columns).
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
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_close($admin);

        $this->db = new RelDynPlayerPgDb($dsn, $this->schema);
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'HERIKA_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['HERIKA_NAME'] = 'Aela the Huntress';
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::NOW, 'Kaida: hello'];
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_player_profile_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixtures (core writers' shapes)

    private const SKILL_NAMES = ['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction',
        'enchanting', 'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket',
        'restoration', 'smithing', 'sneak', 'speechcraft', 'twohanded'];

    /** gamedata.php handleSkillsUpdate for actor_type player: Player::setJson('skills', floats). */
    private function skills(array $overrides, float $base = 15.0): void
    {
        $skills = [];
        foreach (self::SKILL_NAMES as $s) $skills[$s] = floatval($overrides[$s] ?? $base);
        (new Player())->setJson('skills', $skills);
    }

    /** gamedata.php handleStatsUpdate for the player (buildStatsMetadataValue shape). */
    private function stats(int $level): void
    {
        (new Player())->setJson('stats', ['level' => $level, 'health' => 300.0, 'health_max' => 300.0,
            'magicka' => 100.0, 'magicka_max' => 100.0, 'stamina' => 180.0, 'stamina_max' => 180.0, 'scale' => 1.03]);
    }

    /** gamedata.php handleEquipmentUpdate (buildEquipmentMetadataValue: slot, slot_baseid, slot_keywords). */
    private function equipment(array $slots): void
    {
        $out = [];
        foreach (['amulet', 'armor', 'boots', 'gloves', 'helmet', 'left_hand', 'right_hand', 'ring', 'shirt'] as $slot) {
            $item = $slots[$slot] ?? null;
            $out[$slot] = $item['name'] ?? '';
            $out[$slot . '_baseid'] = $item['baseid'] ?? '';
            $out[$slot . '_keywords'] = $item['keywords'] ?? [];
        }
        (new Player())->setJson('equipment', $out);
    }

    /** gamedata.php handleInventoryUpdate (buildInventoryMetadataValue list; gold is baseid 0000000F). */
    private function inventory(int $gold, array $extra = []): void
    {
        $items = $extra;
        if ($gold > 0) {
            $items[] = ['name' => 'Gold', 'baseid' => '0000000F', 'count' => $gold, 'keywords' => [], 'goldvalue' => 1];
        }
        (new Player())->setJson('inventory', $items);
    }

    /** AIAgentPapyrusFunctions OnTrackedStatsEvent -> logMessage("<stat>@<n>", "setconf") -> comm.php upsert into conf_opts. */
    private function trackedStat(string $name, int $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$name, (string) $value]);
    }

    /** gamedata.php handleSkyrimStatsUpdate: Player::set(stat, value) into core_player (playthrough-scoped). */
    private function playerStat(string $name, int $value): void
    {
        (new Player())->set($name, (string) $value);
    }

    /** logEvent(): the raw plugin line, e.g. "(Context location: X)Kaida has defeated Y with Z". */
    private function event(string $type, string $data, int $gamets = self::NOW - 1000): void
    {
        $this->rowid++;
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, party) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)',
            [$type, $data, 'pending', $gamets, 1727000000 + $this->rowid, 1727000000 + $this->rowid, '|Kaida|', '', '']);
    }

    /**
     * comm.php _quest (plugin ProcedureSendActiveQuests): one journal row per active quest with a
     * displayed objective; delete-then-insert by id_quest, editor id in id_quest.
     */
    private function journalQuest(string $editorId, int $stage, string $name = 'Quest'): void
    {
        $this->rowid++;
        pg_query_params($this->db->link, 'DELETE FROM quests WHERE id_quest = $1', [$editorId]);
        pg_query_params($this->db->link,
            'INSERT INTO quests (ts, gamets, name, briefing, data, stage, giver_actor_id, id_quest, sess, status, localts)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)',
            [(string) (1727000000 + $this->rowid), self::NOW - 5000, $name, "Objective.\n", '{}', $stage, '', $editorId,
             'pending', '', 1727000000 + $this->rowid]);
    }

    /** comm.php _uquest: questlog row (editor id in id_quest, objective in briefing/data, stage). */
    private function questlogUpdate(string $editorId, int $stage, string $briefing): void
    {
        $this->rowid++;
        pg_query_params($this->db->link,
            'INSERT INTO questlog (ts, gamets, localts, briefing, data, id_quest, stage) VALUES ($1, $2, $3, $4, $4, $5, $6)',
            [(string) (1727000000 + $this->rowid), self::NOW - 5000, 1727000000 + $this->rowid, $briefing, $editorId, $stage]);
    }

    private function profile(): array
    {
        RelationshipDynamics::clearConfigCache();
        return RelDynPlayer::profile();
    }

    private function assertNoSqlFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'every statement must run on core-shaped tables');
    }

    // ------------------------------------------------------------------ nothing known

    public function testEmptyGameKnowsNothingAndGuessesNothing(): void
    {
        $p = $this->profile();
        $this->assertFalse($p['known']);
        foreach (RelDynPlayer::ARCHETYPES as $a) {
            $this->assertArrayHasKey($a, $p['archetypes']);
            $this->assertNull($p['archetypes'][$a], "{$a}: no data -> unknown, not 0");
        }
        foreach (['strength', 'status', 'competence', 'beauty'] as $pillar) {
            $this->assertArrayHasKey($pillar, $p['pillars']);
            $this->assertNull($p['pillars'][$pillar], "{$pillar}: no data -> unknown");
        }
        // Honest unknowns CHIM 3.4.1 has no source for at all.
        $this->assertNull($p['facts']['thane_holds']['value']);
        $this->assertNull($p['facts']['faction_ranks']['value']);
        $this->assertNotEmpty($p['facts']['thane_holds']['note']);
        $this->assertNoSqlFailures();
    }

    // ------------------------------------------------------------------ archetypes (Ken: Aela)

    /** A two-handed Companion in steel plate who has killed a lot reads as a warrior. */
    public function testWarriorFromSkillsDeedsAndGear(): void
    {
        $this->skills(['twohanded' => 78, 'heavyarmor' => 70, 'block' => 45, 'onehanded' => 40, 'smithing' => 35]);
        $this->stats(34);
        $this->equipment([
            'armor' => ['name' => 'Steel Plate Armor', 'baseid' => '0001395D', 'keywords' => ['ArmorHeavy', 'ArmorCuirass', 'ArmorMaterialSteelPlate']],
            'right_hand' => ['name' => 'Skyforge Steel Greatsword', 'baseid' => '0009F25F', 'keywords' => ['WeapTypeGreatsword', 'VendorItemWeapon']],
        ]);
        $this->trackedStat('People Killed', 140);
        $this->trackedStat('Creatures Killed', 210);
        $this->trackedStat('The Companions Quests Completed', 6);
        foreach (['C00', 'C01', 'C03', 'C04'] as $q) $this->journalQuest($q, 10);

        $p = $this->profile();
        $this->assertTrue($p['known']);
        $this->assertEqualsWithDelta(1.0, $p['archetypes']['warrior'], 1e-9, 'the dominant identity reads 1.0');
        foreach (['bard', 'scholar', 'thief', 'healer'] as $weak) {
            $this->assertLessThan(0.35, $p['archetypes'][$weak], "{$weak} is not who this player is");
        }
        $this->assertSame('core_player.skills', $p['facts']['skills']['source']);
        $this->assertSame(140, $p['facts']['stat:People Killed']['value']);
        $this->assertStringContainsString('conf_opts', $p['facts']['stat:People Killed']['source']);
        $this->assertSame(4, $p['facts']['questlines']['value']['companions']);
        $this->assertContains('ArmorHeavy', $p['facts']['equipment_keywords']['value']);
        // The derivation names its parts.
        $this->assertArrayHasKey('skills', $p['derivation']['archetypes']['warrior']);
        $this->assertArrayHasKey('deeds', $p['derivation']['archetypes']['warrior']);
        $this->assertArrayHasKey('gear', $p['derivation']['archetypes']['warrior']);
        $this->assertNoSqlFailures();
    }

    /** Ken: "a bard or a scholar she could tolerate" -> speech, books, enchanting read as bard/scholar. */
    public function testBardAndScholarAreNotWarriors(): void
    {
        $this->skills(['speechcraft' => 82, 'illusion' => 55, 'enchanting' => 60, 'alteration' => 50]);
        $this->stats(22);
        $this->equipment([
            'armor' => ['name' => 'Fine Clothes', 'baseid' => '000CEE80', 'keywords' => ['ArmorClothing', 'ClothingBody', 'ClothingRich']],
        ]);
        $this->trackedStat('Persuasions', 25);
        $this->trackedStat('Books Read', 90);
        $this->trackedStat('Skill Books Read', 12);

        $p = $this->profile();
        $this->assertGreaterThan(0.8, $p['archetypes']['bard']);
        $this->assertGreaterThan(0.6, $p['archetypes']['scholar']);
        $this->assertLessThan(0.2, $p['archetypes']['warrior']);
        $this->assertLessThan(0.2, $p['archetypes']['hunter']);
        // Fine clothes and a silver tongue are not standing: with no house / wealth evidence
        // noble is unknown, not read off the outfit.
        $this->assertNull($p['archetypes']['noble']);
        $this->assertNoSqlFailures();
    }

    /** A formidable druid: alchemy + restoration + alteration, harvesting, Forsworn gear. */
    public function testDruidFromNatureMagicAndHarvesting(): void
    {
        $this->skills(['alchemy' => 85, 'restoration' => 70, 'alteration' => 65, 'conjuration' => 55]);
        $this->stats(30);
        $this->equipment([
            'armor' => ['name' => 'Forsworn Armor', 'baseid' => '000D8D50', 'keywords' => ['ArmorLight', 'ArmorMaterialForsworn', 'ArmorCuirass']],
        ]);
        $this->trackedStat('Ingredients Harvested', 400);
        $this->trackedStat('Nirnroots Found', 12);

        $p = $this->profile();
        $this->assertEqualsWithDelta(1.0, $p['archetypes']['druid'], 1e-9);
        $this->assertLessThan(0.3, $p['archetypes']['warrior']);
        $this->assertLessThan(0.3, $p['archetypes']['bard']);
        $this->assertNoSqlFailures();
    }

    /** An undeveloped character (fresh level 1, starting skills) is weak everywhere, not "1.0 of something". */
    public function testUndevelopedCharacterHasNoInflatedIdentity(): void
    {
        $this->skills(['onehanded' => 20, 'twohanded' => 17]);   // a few points above the 15 base
        $this->stats(1);
        $p = $this->profile();
        $this->assertTrue($p['known']);
        foreach (array_diff(RelDynPlayer::ARCHETYPES, ['noble']) as $a) {
            $this->assertNotNull($p['archetypes'][$a]);
            $this->assertLessThan(0.3, $p['archetypes'][$a], "{$a}: a fresh character is not formed yet");
        }
        $this->assertNull($p['archetypes']['noble'], 'no standing evidence yet');
        $this->assertLessThan(0.1, $p['pillars']['strength']);
        $this->assertNoSqlFailures();
    }

    // ------------------------------------------------------------------ pillars

    public function testStrengthFromLevelCombatSkillsKillsAndDragons(): void
    {
        $this->skills(['twohanded' => 90, 'heavyarmor' => 85, 'block' => 70]);
        $this->stats(48);
        $this->trackedStat('People Killed', 300);
        $this->trackedStat('Creatures Killed', 400);
        $this->trackedStat('Dragon Souls Collected', 9);
        $strong = $this->profile()['pillars']['strength'];

        // Same build, but weak: low level, low skills, no dragons, few kills.
        pg_query($this->db->link, 'DELETE FROM core_player; DELETE FROM conf_opts');
        $this->skills(['twohanded' => 30, 'heavyarmor' => 25]);
        $this->stats(6);
        $this->trackedStat('People Killed', 4);
        $p = $this->profile();

        $this->assertGreaterThan(0.75, $strong);
        $this->assertLessThan(0.2, $p['pillars']['strength']);
        $this->assertSame(['level', 'combat_skills', 'kills'], array_keys(array_filter(
            $p['derivation']['pillars']['strength']['components'], fn($v) => $v !== null)),
            'dragons unknown (no stat, no eventlog dragon kill): excluded, not zero');
        $this->assertNoSqlFailures();
    }

    /** Without tracked stats, kills and dragons come from core eventlog death lines of the player. */
    public function testKillsAndDragonsFallBackToEventlogDeathLines(): void
    {
        $this->skills(['archery' => 60]);
        $this->stats(20);
        $this->event('death', '(Context location: Bleak Falls Barrow interior ,Hold: Whiterun)Kaida has defeated Draugr Wight(powerful enemy) with Hunting Bow');
        $this->event('death', '(Context location: Western Watchtower outdoors ,Hold: Whiterun)Kaida has defeated Mirmulnir(powerful enemy)(powerful DRAGON) with Hunting Bow');
        $this->event('death', '(Context location: Riverwood outdoors ,Hold: Whiterun)Kaida has defeated Wolf with Hunting Bow');
        // Not the player: a follower's kill, a death with no killer, and an NPC whose name ends in the player's.
        $this->event('death', '(Context location: Riverwood outdoors ,Hold: Whiterun)Lydia has defeated Bandit using weapon Steel Sword');
        $this->event('death', '(Context location: Riverwood outdoors ,Hold: Whiterun)Skeever died');
        $this->event('death', '(Context location: Riverwood outdoors ,Hold: Whiterun)Mikaida has defeated Mudcrab with Iron Dagger');

        $p = $this->profile();
        $this->assertSame(3, $p['facts']['eventlog_player_kills']['value']);
        $this->assertSame(1, $p['facts']['eventlog_dragon_kills']['value']);
        $this->assertSame(2, $p['facts']['eventlog_powerful_kills']['value']);
        $this->assertStringContainsString('eventlog', $p['facts']['eventlog_player_kills']['source']);
        $this->assertNotNull($p['derivation']['pillars']['strength']['components']['dragons']);
        $this->assertNoSqlFailures();
    }

    /**
     * Ken / attraction memory: wealth = economic footprint (gold moved), not the wallet. A player
     * who moved a lot of gold but carries little outranks one sitting on a pile they never used.
     */
    public function testStatusIsEconomicFootprintNotWallet(): void
    {
        $this->skills([]);
        $this->stats(20);
        // Trader: moved gold through many snapshots, ends with 200 in the pocket.
        foreach ([500, 9000, 1200, 15000, 800, 22000, 200] as $i => $gold) {
            $this->inventory($gold);
            $GLOBALS['gameRequest'][2] = (string) (self::NOW + $i * 100000);
            RelDynPlayer::recordGoldSnapshot();
        }
        $this->trackedStat('Houses Owned', 2);
        $trader = $this->profile();

        // Hoarder: one snapshot, 40 000 in the pocket, nothing moved, no houses.
        pg_query($this->db->link, 'DELETE FROM core_player; DELETE FROM conf_opts');
        $this->skills([]);
        $this->stats(20);
        $this->inventory(40000);
        RelDynPlayer::recordGoldSnapshot();
        $hoarder = $this->profile();

        $this->assertSame(200, $trader['facts']['gold_carried']['value']);
        $this->assertSame(87300, $trader['facts']['gold_moved']['value']);   // 8500+7800+13800+14200+21200+21800
        $this->assertStringContainsString('not used', $trader['facts']['gold_carried']['note']);
        $this->assertSame(40000, $hoarder['facts']['gold_carried']['value']);
        $this->assertSame(0, $hoarder['facts']['gold_moved']['value']);
        $this->assertGreaterThan(0.5, $trader['pillars']['status']);
        $this->assertEqualsWithDelta(1.0, $trader['archetypes']['noble'], 1e-9, 'houses + gold moved: a person of means');
        $this->assertLessThan(0.05, $hoarder['pillars']['status'], 'a full wallet alone is not status');
        $this->assertNoSqlFailures();
    }

    /** Reloading an older save rewinds the game clock: the gold jump is a rollback, not trade. */
    public function testGoldLedgerIgnoresSaveReloads(): void
    {
        $this->inventory(1000);
        $GLOBALS['gameRequest'][2] = (string) (self::NOW);
        RelDynPlayer::recordGoldSnapshot();
        $this->inventory(3000);
        $GLOBALS['gameRequest'][2] = (string) (self::NOW + 200000);
        RelDynPlayer::recordGoldSnapshot();           // +2000 moved
        $this->inventory(1000);
        $GLOBALS['gameRequest'][2] = (string) (self::NOW + 50000);   // reload: clock went back
        RelDynPlayer::recordGoldSnapshot();           // re-baseline, nothing moved
        $this->inventory(1500);
        $GLOBALS['gameRequest'][2] = (string) (self::NOW + 90000);
        RelDynPlayer::recordGoldSnapshot();           // +500 moved
        // Same wallet on the next request: nothing to write.
        $before = count($this->db->statements);
        RelDynPlayer::recordGoldSnapshot();

        $ledger = json_decode((new Player())->get(RelDynPlayer::LEDGER_KEY), true);
        $this->assertSame(2500, (int) $ledger['moved']);
        $this->assertSame(2500, (int) $ledger['gained']);
        $this->assertSame(0, (int) $ledger['spent']);
        $this->assertSame(1, (int) $ledger['rebaselines']);
        $this->assertSame(1500, (int) $ledger['last_gold']);
        $this->assertSame(0, count(array_filter(array_slice($this->db->statements, $before),
            fn($s) => stripos($s, 'INSERT INTO core_player') === 0)), 'unchanged wallet: no write');
        $this->assertNoSqlFailures();
    }

    public function testCompetenceFromQuestsDungeonsMasteryAndCraft(): void
    {
        $this->skills(['smithing' => 90, 'enchanting' => 75, 'onehanded' => 70, 'archery' => 60, 'sneak' => 60]);
        $this->stats(40);
        $this->trackedStat('Quests Completed', 60);
        $this->trackedStat('Questlines Completed', 3);
        $this->trackedStat('Dungeons Cleared', 40);
        $this->trackedStat('Locations Discovered', 180);
        $this->trackedStat('Weapons Made', 50);
        $accomplished = $this->profile()['pillars']['competence'];

        // Trained to the same skill levels on dummies, never did anything: MDD 2.4.
        pg_query($this->db->link, 'DELETE FROM conf_opts');
        $this->trackedStat('Quests Completed', 0);
        $this->trackedStat('Dungeons Cleared', 0);
        $this->trackedStat('Weapons Made', 0);
        $dummy = $this->profile()['pillars']['competence'];

        $this->assertGreaterThan(0.7, $accomplished);
        $this->assertLessThan(0.3, $dummy, 'skills without deeds are not competence');
        $this->assertNoSqlFailures();
    }

    /** Journal quests count as engagement when no "Quests Completed" stat has arrived. */
    public function testJournalQuestsCountWhenNoCompletionStat(): void
    {
        $this->skills([]);
        foreach (['MQ101', 'MQ102', 'MQ103', 'MG01', 'MG02', 'TG01', 'CR02'] as $q) {
            $this->journalQuest($q, 10);
            $this->journalQuest($q, 20);   // the plugin re-sends a quest: one row per quest
        }
        $p = $this->profile();
        $this->assertSame(7, $p['facts']['questlines']['journal_quests']);
        $this->assertEquals(['main' => 3, 'college' => 2, 'thieves_guild' => 1, 'companions' => 1],
            array_filter($p['facts']['questlines']['value']));
        $this->assertNotNull($p['derivation']['pillars']['competence']['components']['quests']);
        $this->assertNoSqlFailures();
    }

    /**
     * Live 2026-09-24: questlog holds quests the engine starts on its own (Companions radiants at
     * stage 0/1, unresolved <Alias> text) for a level-1 prisoner. They are not the player's deeds.
     */
    public function testEngineStartedQuestlogRowsAreNotDeeds(): void
    {
        $this->skills([]);
        foreach (['CR02', 'CR05', 'CR08', 'CR06', 'CR04', 'CR07'] as $q) {
            $this->questlogUpdate($q, 0, 'Return to <Alias.ShortName=Questgiver>');
            $this->questlogUpdate($q, 1, 'Return to <Alias.ShortName=Questgiver>');
        }
        $this->questlogUpdate('C00', 1, 'Follow Farkas to your quarters');
        $p = $this->profile();
        $this->assertNull($p['facts']['questlines']['value'], 'no journal quest: questlines unknown');
        $this->assertLessThan(0.05, $p['archetypes']['warrior']);
        $this->assertNoSqlFailures();
    }

    /** core_player (playthrough-scoped, gamedata skyrim_stats) wins over the global conf_opts row. */
    public function testPlaythroughScopedStatWinsOverGlobalConfOpts(): void
    {
        $this->trackedStat('Dungeons Cleared', 3);      // older character's row, global
        $this->playerStat('Dungeons Cleared', 17);      // this playthrough
        $this->trackedStat('dragon souls collected', 4); // stat names match case-insensitively
        $p = $this->profile();
        $this->assertSame(17, $p['facts']['stat:Dungeons Cleared']['value']);
        $this->assertStringStartsWith('core_player', $p['facts']['stat:Dungeons Cleared']['source']);
        $this->assertSame(4, $p['facts']['stat:Dragon Souls Collected']['value']);
        $this->assertNoSqlFailures();
    }

    /** Beauty is NPC-subjective (MDD 2.1): no player-only number. The appearance text is a fact. */
    public function testBeautyStaysNullAppearanceIsAFact(): void
    {
        (new Player())->set('appearance', 'ruggedly handsome Nord, auburn hair, war paint');
        $this->eventPlayerInfo();
        $p = $this->profile();
        $this->assertNull($p['pillars']['beauty']);
        $this->assertSame('ruggedly handsome Nord, auburn hair, war paint', $p['facts']['appearance']['value']);
        $this->assertSame('core_player.appearance', $p['facts']['appearance']['source']);
        $this->assertSame('Male', $p['facts']['gender']['value']);
        $this->assertSame('Nord', $p['facts']['race']['value']);
        $this->assertSame(12, $p['facts']['level']['value']);
        $this->assertStringContainsString('eventlog', $p['facts']['level']['source'], 'no core_player.stats: level from infoplayer');
        $this->assertNoSqlFailures();
    }

    private function eventPlayerInfo(): void
    {
        $this->event('infoplayer', 'level:12,name:"Kaida",race:"Nord",gender:"Male"');
    }

    /** The prerequest hook folds each request's wallet into the ledger (real hook, real tables). */
    public function testPrerequestHookRecordsTheGoldLedger(): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, extended_data) VALUES ($1, $2, $3, $4, $5)',
            ['Aela the Huntress', '0001A696', 'female', 'Nord', '{}']);
        $hook = function (int $gamets): void {
            $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) $gamets, 'Kaida: hello'];
            $GLOBALS['HERIKA_NAME'] = 'Aela the Huntress';
            (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
            RelationshipDynamics::endRequest();
            foreach (array_keys($GLOBALS) as $k) {
                if (str_starts_with((string) $k, 'RELDYN_')) unset($GLOBALS[$k]);
            }
        };
        $this->inventory(1000);
        $hook(self::NOW);
        $this->inventory(4000);                          // sold loot: +3000
        $hook(self::NOW + intdiv(self::DAY, 24));
        $this->inventory(2500);                          // bought a horse: -1500
        $hook(self::NOW + intdiv(self::DAY, 12));

        $ledger = json_decode((new Player())->get(RelDynPlayer::LEDGER_KEY), true);
        $this->assertSame(4500, (int) $ledger['moved']);
        $this->assertSame(3000, (int) $ledger['gained']);
        $this->assertSame(1500, (int) $ledger['spent']);
        $this->assertSame(3, (int) $ledger['snapshots']);
        $this->assertSame(self::NOW, (int) $ledger['since_gamets']);
        $this->assertSame(4500, $this->profile()['facts']['gold_moved']['value']);
        $this->assertNoSqlFailures();
    }

    // ------------------------------------------------------------------ caching, config, robustness

    public function testCachedPerRequestScopeOnly(): void
    {
        $this->skills(['archery' => 70]);
        RelationshipDynamics::beginRequest();
        $first = RelDynPlayer::profile();
        $n = count($this->db->statements);
        $again = RelDynPlayer::profile();
        $this->assertSame($first, $again);
        $this->assertSame($n, count($this->db->statements), 'second call in the same request reads nothing');

        RelationshipDynamics::endRequest();
        $this->skills(['archery' => 20]);
        $fresh = RelDynPlayer::profile();          // no scope: always reads
        $this->assertGreaterThan($n, count($this->db->statements));
        $this->assertSame(70.0, $first['facts']['skills']['value']['archery']);
        $this->assertSame(20.0, $fresh['facts']['skills']['value']['archery']);
        $this->assertNoSqlFailures();
    }

    public function testTablesComeFromConfig(): void
    {
        $this->skills(['speechcraft' => 90]);
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['player_profile']['archetypes']['bard']['skills'] = ['archery' => 1.0];
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
        $p = $this->profile();
        $this->assertLessThan(0.05, $p['archetypes']['bard'], 'bard now keyed on archery, which is at base');
        $this->assertNoSqlFailures();
    }

    public function testMalformedCoreRowIsUnknownAndLogged(): void
    {
        (new Player())->set('skills', 'not json');
        $log = tempnam(sys_get_temp_dir(), 'rdp');
        $prev = ini_set('error_log', $log);
        try {
            $p = $this->profile();
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        $this->assertNull($p['facts']['skills']['value']);
        $this->assertStringContainsString('core_player.skills', (string) file_get_contents($log));
        @unlink($log);
    }
}
