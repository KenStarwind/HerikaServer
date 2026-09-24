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
final class RelDynAttractionReviewPgDb
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

    public function updateRow($table, $data, $where)
    {
        $set = [];
        $i = 0;
        foreach (array_keys($data) as $col) $set[] = "{$col} = $" . (++$i);
        $res = @pg_query_params($this->link, "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}", array_values($data));
        if (!$res) {
            $this->failures[] = pg_last_error($this->link) . " :: updateRow {$table}";
            return false;
        }
        return true;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Review findings 2026-09-24 on the batch E lanes (attraction, player stats, romance), on a
 * real PostgreSQL through the real hooks (prerequest -> context -> postrequest, the calendar
 * scan), with the player built from CHIM 3.4.1 core rows only (core_player skills / stats /
 * inventory, conf_opts tracked stats, eventlog infoplayer / playerinfo, the quests journal).
 * Rulings §9: "Aela would have an affinity for a strong warrior type or a formidable druid,
 * but a bard or a scholar she could tolerate but probably wouldn't feel passion towards."
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAttractionReviewPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const MINUTE = self::DAY / 1440;
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynAttractionReviewPgDb $db;
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
        $this->schema = 'reldyn_attrrev' . getmypid() . '_' . bin2hex(random_bytes(3));

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

        $this->db = new RelDynAttractionReviewPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdattrrev');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attraction_review_test.log');
        $this->setConfig([]);

        // Aela: the Companions' huntress, as the plugin registers her (no RelDyn state).
        $this->addNpc(self::AELA, 'Hunter', 'female', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => '72', 'sneak' => '56', 'lightarmor' => '52', 'onehanded' => '45'], ['aff' => 10, 'type' => 'platonic']);
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

    // ------------------------------------------------------------------ fixture helpers

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    private function setConfig(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC as the plugin registers it: class, factions, skills, core Player relationship. */
    private function addNpc(string $name, string $class, string $gender, array $factionNames, array $skills, array $playerRel): void
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $factions = [];
        foreach ($factionNames as $i => $f) {
            $factions[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $f];
        }
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb)',
            [$name, (string) (0x1A696 + crc32($name) % 1000), $gender, 'NordRace', 'FemaleEvenToned', "Roleplay as {$name}", '',
             json_encode(['skills' => array_merge($all, $skills)]),
             json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $factions,
                 'relationships' => [self::PLAYER => $playerRel]])]);
    }

    /** The player's core_player rows as the plugin writes them (skill levels 0..100, stats). */
    private function playerBuild(array $skills, int $level): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speechcraft', 'twohanded'], 15);
        $this->corePlayerRow('skills', array_merge($all, $skills));
        $this->corePlayerRow('stats', ['level' => $level, 'health' => 300]);
    }

    private function corePlayerRow(string $id, $value): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO core_player (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /** A tracked stat as the game sends it (AIAgentPapyrusFunctions OnTrackedStatsEvent -> setconf). */
    private function trackedStat(string $name, int $value): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$name, (string) $value]);
    }

    private function eventRow(string $type, string $data): void
    {
        $this->gamets += self::MINUTE;
        $this->realTs += 1;
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts) VALUES ($1, $2, $3, $4, $5, $6)',
            [$type, $data, 'pc', (int) $this->gamets, $this->realTs, $this->realTs]);
    }

    private function hook(string $hook, string $npc, array $request): void
    {
        $party = '|' . $npc . '|' . self::PLAYER . '|';
        $GLOBALS['gameRequest'] = $request;
        $GLOBALS['HERIKA_NAME'] = $npc;
        $GLOBALS['RELDYN_NPC_NAME'] = $npc;
        $GLOBALS['CACHE_PEOPLE'] = $party;
        $GLOBALS['CACHE_PARTY'] = $party;
        $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
        $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
        $GLOBALS['contextDataFull'] = [];
        (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
        RelationshipDynamics::endRequest();
        if ($hook !== 'context.php') $this->clearReldynGlobals();
    }

    private function request(string $line): array
    {
        $this->gamets += 5 * self::MINUTE;
        $this->realTs += 60;
        return ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
    }

    /** One player line to $npc through the real hooks; the NPC answers in $mood. Returns the context. */
    private function turn(string $line, string $npc = self::AELA, string $mood = 'flirty'): string
    {
        $request = $this->request($line);
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)', [$npc, $mood, $this->realTs]);
        $this->hook('prerequest.php', $npc, $request);
        $this->hook('context.php', $npc, $request);
        $context = implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []));
        $this->clearReldynGlobals();
        $this->hook('postrequest.php', $npc, $request);
        return $context;
    }

    private function prerequestOnly(string $line, string $npc = self::AELA): void
    {
        $this->hook('prerequest.php', $npc, $this->request($line));
    }

    private function pluginData(string $npc = self::AELA): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    private function dynamics(string $npc = self::AELA): array
    {
        return $this->pluginData($npc)['dynamics'] ?? [];
    }

    /** Edit the stored dynamics the way an older save or another writer left them. */
    private function editDynamics(callable $edit, string $npc = self::AELA): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    private function corePlayerRel(string $npc = self::AELA): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rels = json_decode($r['extended_data'], true)['relationships'] ?? [];
        return $rels['Player'] ?? $rels[self::PLAYER] ?? [];
    }

    private function setCorePlayerRel(array $rel, string $npc = self::AELA): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb) WHERE npc_name = $1",
            [$npc, json_encode(['Player' => $rel])]);
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    /** core_player 'equipment' as gamedata.php writes it (slot, slot_baseid, slot_keywords). */
    private function equipmentRow(array $slots): void
    {
        $out = [];
        foreach (['amulet', 'armor', 'boots', 'gloves', 'helmet', 'left_hand', 'right_hand', 'ring', 'shirt'] as $slot) {
            $item = $slots[$slot] ?? null;
            $out[$slot] = $item['name'] ?? '';
            $out[$slot . '_baseid'] = $item['baseid'] ?? '';
            $out[$slot . '_keywords'] = $item['keywords'] ?? [];
        }
        $this->corePlayerRow('equipment', $out);
    }

    /** core_player 'inventory' with $gold septims (baseid 0000000F), as gamedata.php writes it. */
    private function wallet(int $gold): void
    {
        $this->corePlayerRow('inventory', $gold > 0
            ? [['name' => 'Gold', 'baseid' => '0000000F', 'count' => $gold, 'keywords' => [], 'goldvalue' => 1]] : []);
    }

    /** Forget the player and Aela's state (a different character meets her for the first time). */
    private function freshMeeting(array $playerRel = ['aff' => 10, 'type' => 'platonic'], string $npc = self::AELA): void
    {
        pg_query($this->db->link, "DELETE FROM core_player; DELETE FROM eventlog; DELETE FROM quests;
            DELETE FROM conf_opts WHERE id <> '" . RelationshipDynamics::CONFIG_ROW_ID . "'");
        pg_query_params($this->db->link, "UPDATE core_npc_master SET plugin_extended_data = '{}'::jsonb WHERE npc_name = $1", [$npc]);
        $this->setCorePlayerRel($playerRel, $npc);
    }

    /** The builds of the review probes, as the plugin reports them. */
    private function build(string $kind): void
    {
        switch ($kind) {
            case 'warrior':    // accomplished: one-handed / two-handed / heavy armor, level 45
                $this->playerBuild(['onehanded' => 90, 'twohanded' => 85, 'heavyarmor' => 80, 'block' => 60], 45);
                break;
            case 'druid':      // formidable: herb lore, nature magic cast, Forsworn hide, harvesting
                $this->playerBuild(['alchemy' => 85, 'restoration' => 70, 'alteration' => 65, 'conjuration' => 70], 40);
                // the plugin reports the hands from the inventory, never a spell: her nature
                // magic is known from her casts (eventlog npcspellcast)
                $this->equipmentRow([
                    'armor' => ['name' => 'Forsworn Armor', 'baseid' => '000D8D50', 'keywords' => ['ArmorLight', 'ArmorMaterialForsworn']],
                ]);
                for ($i = 0; $i < 12; $i++) $this->eventRow('npcspellcast', self::PLAYER . ' casts Conjure Familiar ');
                for ($i = 0; $i < 6; $i++) $this->eventRow('npcspellcast', self::PLAYER . ' casts Animal Allegiance on Cave Bear');
                $this->trackedStat('Ingredients Harvested', 400);
                $this->trackedStat('Nirnroots Found', 12);
                break;
            case 'scholar':
                $this->playerBuild(['enchanting' => 80, 'alteration' => 70, 'illusion' => 50], 30);
                $this->trackedStat('Books Read', 200);
                break;
            case 'bard':
                $this->playerBuild(['speechcraft' => 95, 'illusion' => 60, 'onehanded' => 30], 30);
                break;
            case 'prisoner':   // level 1 Nord, vanilla starting skills
                $this->playerBuild(['twohanded' => 25, 'onehanded' => 20], 1);
                break;
            case 'young_warrior':
                $this->playerBuild(['onehanded' => 40, 'block' => 30], 10);
                break;
            default:
                $this->fail("unknown build {$kind}");
        }
    }

    /** Toxic, immature, walked away $hours game hours ago: the hoover is due. */
    private function walkedAwayToxic(float $hours): void
    {
        $this->editDynamics(function (array &$d) use ($hours): void {
            $d['profile_overrides']['attachment_style'] = 'toxic';
            $d['attachment_style'] = 'toxic';
            $d['dimensions']['maturity']['x'] = 20;
            $d['dimensions']['maturity']['baseline'] = 20;
            $d['_walkaway_state'] = 'active';
            $d['_walkaway_activated_calendar_gamets'] = (int) ($this->gamets - $hours * self::HOUR);
        });
    }

    // ------------------------------------------------------------------ attraction-gated-passion / friendzone: the hard cap

    public function testHooverInTheCalendarScanStaysUnderTheAttractionCap(): void
    {
        $this->playerBuild(['speechcraft' => 95, 'illusion' => 75], 40);   // a bard: no passion for Aela
        $this->turn('Another verse, then.');
        $cap = $this->dynamics()['_attraction']['passion_cap'];
        $this->assertSame(20.0, floatval($cap), json_encode($this->dynamics()['_attraction']));
        $this->walkedAwayToxic(100);

        $this->gamets += 7 * self::HOUR;   // past the calendar step interval
        $GLOBALS['gameRequest'] = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, 'Kaida: (elsewhere)'];
        $done = RelationshipDynamics::runCalendarScan(null);
        RelationshipDynamics::endRequest();
        $this->assertTrue($done[self::AELA]['hoover'] ?? false, 'the Toxic sleeper hoovers back: ' . json_encode($done));
        $this->assertLessThanOrEqual(20.0, RelationshipDynamics::getPassion($this->dynamics()), 'the hoover snap stays under the hard cap');
        $this->assertNoDbFailures();
    }

    public function testHooverInPrerequestPublishesNoPassionPastTheCap(): void
    {
        $this->playerBuild(['speechcraft' => 95, 'illusion' => 75], 40);
        $this->turn('Another verse, then.');
        $this->walkedAwayToxic(100);

        $this->prerequestOnly('Where have you been?');   // her own request: the hoover runs in it
        $d = $this->dynamics();
        $this->assertSame(1, intval($d['_hoover_count'] ?? 0), 'hoovered in prerequest');
        $this->assertLessThanOrEqual(floatval($d['_attraction']['passion_cap']), RelationshipDynamics::getPassion($d));
        $romance = $this->pluginData()['romance'] ?? [];
        $this->assertNotSame('burning', $romance['passion_band'] ?? null, 'the Sharmat handoff never shows burning passion without attraction');
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ attraction-matrix on real player data (rulings §9)

    public function testAelaReadsRealBuildsTheWayKenDescribesHer(): void
    {
        $seen = [];
        foreach (['warrior', 'druid', 'scholar', 'bard', 'prisoner', 'young_warrior'] as $kind) {
            $this->freshMeeting();
            $this->build($kind);
            $ctx = $this->turn('Good hunting today.');
            $a = $this->dynamics()['_attraction'];
            $seen[$kind] = $a + ['strength' => RelationshipDynamics::attractionFor(self::AELA, $this->dynamics())['pillars']['strength']['score'], 'ctx' => $ctx];
        }
        $why = fn(string $k) => "{$k}: " . json_encode(array_diff_key($seen[$k], ['ctx' => 1]));
        foreach (['warrior' => 'warrior', 'druid' => 'druid'] as $kind => $valued) {
            $this->assertTrue($seen[$kind]['passes'], $why($kind));
            $this->assertFalse($seen[$kind]['tolerated'], $why($kind));
            $this->assertNull($seen[$kind]['passion_cap'], $why($kind));
            $this->assertSame($valued, $seen[$kind]['valued'], $why($kind));
            $this->assertStringContainsString('drawn to the player', $seen[$kind]['ctx']);
        }
        // "a bard or a scholar she could tolerate but probably wouldn't feel passion towards"
        foreach (['scholar', 'bard', 'prisoner'] as $kind) {
            $this->assertFalse($seen[$kind]['passes'], $why($kind));
            $this->assertSame(20.0, floatval($seen[$kind]['passion_cap']), $why($kind));
            $this->assertSame(0, $seen[$kind]['romance']['allowed'], $why($kind));
            $this->assertStringNotContainsString('drawn to the player', $seen[$kind]['ctx']);
            $this->assertStringNotContainsString('exactly what', $seen[$kind]['ctx'], 'no "bond with the wild" for a scholar');
        }
        // A weak warrior is still weak: the lens reads magnitude, not what kind of fighter
        $this->assertLessThan(0.3, $seen['young_warrior']['strength'], $why('young_warrior'));
        $this->assertGreaterThan(0.7, $seen['warrior']['strength'], $why('warrior'));
        // ... at most noticed at her (flexible) strength bar: never drawn with commitment on the table
        $this->assertNotSame('drawn', $seen['young_warrior']['outcome'], $why('young_warrior'));
        $this->assertLessThanOrEqual(1, $seen['young_warrior']['romance']['allowed'], $why('young_warrior'));
        $this->assertNoDbFailures();
    }

    /** MDD 2.3 "Aela: only Companions rank": her status is the Companions, not the wallet RelDyn just started watching. */
    public function testAVeteranCompanionHasStandingInAelasEyes(): void
    {
        $this->build('warrior');
        $this->wallet(0);                 // the ledger's first snapshot: nothing moved yet
        $this->trackedStat('The Companions Quests Completed', 8);
        $this->trackedStat('Dragon Souls Collected', 8);
        $this->trackedStat('Quests Completed', 60);
        $this->turn('The Circle has spoken.');
        $a = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
        $this->assertTrue($a['pillars']['status']['known']);
        $this->assertTrue($a['pillars']['status']['pass'], json_encode($a['pillars']['status']));
        $this->assertSame('drawn', $a['outcome'], $a['reason']);
        $this->assertSame(2, $a['romance']['allowed'], 'commitment is on the table');

        // The same veteran without the Circle behind him: she does not know his standing, and the
        // gold RelDyn has seen move is not what she measures
        $this->freshMeeting();
        $this->build('warrior');
        $this->wallet(0);
        $this->turn('Good hunting today.');
        $a = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
        $this->assertFalse($a['pillars']['status']['known'], 'no Companions evidence: unknown, never a known zero');
        $this->assertSame('drawn', $a['outcome'], $a['reason']);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ attraction-tier-ceiling

    /** An NPC the Matrix does not judge (all pillars soft) grows with affinity: no lift to earn, no freeze. */
    public function testNoBouncerNoFreeze(): void
    {
        $this->addNpc('Hulda', 'Citizen', 'female', [], [], ['aff' => 0, 'type' => 'neutral']);
        $this->build('warrior');
        $this->turn('A room for the night.', 'Hulda', 'default');
        $d = $this->dynamics('Hulda');
        $this->assertTrue($d['_attraction']['enabled']);
        $this->assertFalse($d['_attraction']['gating'], 'no rigid / flexible pillar: no bouncer');
        $tiers = [];
        foreach ([35 => 2, 60 => 2, 80 => 3, 95 => 3] as $aff => $contextTier) {
            $this->setCorePlayerRel(['aff' => $aff, 'type' => 'neutral'], 'Hulda');
            $this->turn('Good to see you again.', 'Hulda', 'default');
            $d = $this->dynamics('Hulda');
            $tiers[$aff] = RelationshipDynamics::getAffinityContextTier($d);
            $this->assertSame($contextTier, $tiers[$aff], "core affinity {$aff}: " . json_encode($d['_attraction_state']));
            $this->assertNull($d['_attraction']['ceiling_tier']);
            $this->assertFalse($d['_attraction']['pending']);
        }
        // Married after the first evaluation (core type romantic at 80): not blocked, and her
        // neglect / fulfillment still see the bond
        $this->setCorePlayerRel(['aff' => 80, 'type' => 'romantic'], 'Hulda');
        $this->turn('I missed you.', 'Hulda', 'default');
        $d = $this->dynamics('Hulda');
        $this->assertSame([], $d['_attraction']['blocked_types']);
        $this->assertSame('romantic', $this->corePlayerRel('Hulda')['type'], 'the ownership guard keeps what attraction allows');
        $this->assertNotSame('acquaintance', RelationshipDynamics::getRelationshipType('Hulda', $d));
        $this->assertNotNull(RelationshipDynamics::neglectBond($d));
        $this->assertNoDbFailures();
    }

    /** What the relationship was when the Matrix first saw it is not taken away by one sparse stat. */
    public function testAnExistingRomanceIsGrandfathered(): void
    {
        $this->addNpc('Belethor', 'Merchant', 'male', [], ['speech' => '60'], ['aff' => 80, 'type' => 'romantic']);
        $this->setCorePlayerRel(['aff' => 80, 'type' => 'romantic']);
        $this->build('warrior');
        foreach ([self::AELA, 'Belethor'] as $npc) {
            $this->turn('I am home.', $npc, 'default');
        }
        $before = [];
        foreach ([self::AELA, 'Belethor'] as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame([], $d['_attraction']['blocked_types'], "{$npc}: " . json_encode($d['_attraction']));
            $before[$npc] = RelationshipDynamics::getRelationshipType($npc, $d);
        }
        // The game reports one trade stat: Belethor's (generic) status becomes known and low
        $this->trackedStat('Barters', 5);
        foreach ([self::AELA, 'Belethor'] as $npc) {
            $this->turn('I am home.', $npc, 'default');
            $d = $this->dynamics($npc);
            $a = RelationshipDynamics::attractionFor($npc, $d);
            $this->assertSame([], $d['_attraction']['blocked_types'], "{$npc}: " . json_encode($d['_attraction_state']));
            $this->assertSame(2, $d['_attraction']['romance']['effective']);
            $this->assertSame($before[$npc], RelationshipDynamics::getRelationshipType($npc, $d), "{$npc}: modifiers, neglect bond and context tier unchanged");
            $this->assertSame('romantic', $this->corePlayerRel($npc)['type']);
        }
        $this->assertFalse(RelationshipDynamics::attractionFor('Belethor', $this->dynamics('Belethor'))['pillars']['status']['pass'],
            'the status pillar did fail for him: the grandfathering held, not the pillar');

        // The romance ends in core (a step-back): the protection ends with it, and a romance core
        // writes again later is the Matrix's to judge
        $this->setCorePlayerRel(['aff' => 80, 'type' => 'platonic'], 'Belethor');
        $this->turn('We should talk.', 'Belethor', 'default');
        $this->setCorePlayerRel(['aff' => 80, 'type' => 'romantic'], 'Belethor');
        $this->turn('Please.', 'Belethor', 'default');
        $this->assertSame('platonic', $this->corePlayerRel('Belethor')['type'], 'core wrote romance again; attraction says no');
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ romance-promotion (ownership)

    /** Core's MODE 2 #TYPE write of a romance for an NPC who feels no pull is stepped back; locked edits are not. */
    public function testCoreWrittenRomanceWithoutAttractionIsSteppedBack(): void
    {
        $this->setCorePlayerRel(['aff' => 60, 'type' => 'platonic']);
        $this->build('bard');
        $this->turn('Another verse, then.');
        $this->assertFalse($this->dynamics()['_attraction']['passes']);

        // core's relationship_system postrequest (MODE 2) parses "#TYPE:Player=Romantic#"
        $this->setCorePlayerRel(['aff' => 60, 'type' => 'romantic']);
        $this->turn('You liked that one, admit it.');
        $this->assertSame('platonic', $this->corePlayerRel()['type'], 'RelDyn owns romance promotion');
        $this->assertStringContainsString('RelDyn owns romance', (string) file_get_contents($this->errorLog));
        $romance = $this->pluginData()['romance'] ?? [];
        $this->assertFalse($romance['romantic'] ?? true, 'Sharmat never sees her as romantic');

        // An editor-locked manual choice stands
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships_locked}', 'true'::jsonb) WHERE npc_name = $1", [self::AELA]);
        $this->setCorePlayerRel(['aff' => 60, 'type' => 'romantic']);
        $this->turn('For you.');
        $this->assertSame('romantic', $this->corePlayerRel()['type'], 'relationships_locked: manual edits protected');
        $this->assertNoDbFailures();
    }

    /** Mode 'strict': only RelDyn's own moments promote; a core write is stepped back even where attraction would allow it. */
    public function testStrictOwnershipStepsBackEveryCoreRomanceWrite(): void
    {
        $this->setConfig(['romance_promotion' => ['guard_core_promotions' => 'strict'] + RelDynRomance::configDefaults()]);
        $this->addNpc('Hulda', 'Citizen', 'female', [], [], ['aff' => 80, 'type' => 'neutral']);
        $this->build('warrior');
        $this->turn('A room for the night.', 'Hulda', 'default');
        $this->assertSame([], $this->dynamics('Hulda')['_attraction']['blocked_types'], 'attraction would allow it');
        $this->setCorePlayerRel(['aff' => 80, 'type' => 'romantic'], 'Hulda');
        $this->turn('I missed you.', 'Hulda', 'default');
        $this->assertSame('neutral', $this->corePlayerRel('Hulda')['type']);
        $this->assertStringContainsString('strict: only RelDyn promotes', (string) file_get_contents($this->errorLog));
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ attraction-gated-passion: beauty (MDD 2.1)

    public function testBeautyIsTheAppearanceTextThroughHerEyes(): void
    {
        $this->build('warrior');
        $this->turn('Good hunting today.');
        $a = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
        $this->assertFalse($a['pillars']['beauty']['known'], 'no appearance text: unknown, never gates');

        $this->corePlayerRow('appearance', 'A ruggedly handsome Nord with a muscular, broad-shouldered build, a scar over one eye and war paint.');
        $this->turn('Good hunting today.');
        $rugged = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
        $this->assertTrue($rugged['pillars']['beauty']['known']);
        $this->assertGreaterThanOrEqual(0.9, $rugged['pillars']['beauty']['score']);
        $this->assertTrue($rugged['passes']);
        $this->assertFalse($rugged['tolerated']);

        $this->corePlayerRow('appearance', 'A delicate, perfumed courtier with soft hands and powdered cheeks.');
        $this->turn('Good hunting today.');
        $courtier = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
        $this->assertTrue($courtier['pillars']['beauty']['known']);
        $this->assertLessThan(0.4, $courtier['pillars']['beauty']['score'], 'none of her words');
        $this->assertTrue(!$courtier['passes'] || $courtier['tolerated'], 'beauty (rigid for her) now weighs: ' . $courtier['reason']);
        $this->assertNotNull($this->dynamics()['_attraction']['passion_cap'], 'passion is capped for a player she only tolerates');
        $this->assertNoDbFailures();
    }
}
