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
final class RelDynArchetypeBlendPgDb
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
 * archetype-blend-attraction / spell-subject-facets / player-stats-pipeline (druid inference),
 * decisions 2026-09-24 §10 (Ken): "Nature spells no, hence the druid or a bard who communes
 * with animals." Spells read by their subject; the player's druid side comes from nature magic
 * and deeds, never from a magic school; Aela's lens reads the player's whole archetype mix.
 *
 * Real PostgreSQL through the real hooks (prerequest -> context -> postrequest), the player built
 * from CHIM 3.4.1 core rows only: core_player skills / stats / equipment / transformation_state,
 * conf_opts tracked stats, the eventlog's 'npcspellcast' lines the plugin writes for the player's
 * casts ("<player> casts <spell>[ on <target>]", Plugin.cpp TESSpellCastEvent), and the live
 * 3.4.1 Oghma rows for the topic side.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynArchetypeBlendPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const MINUTE = self::DAY / 1440;
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynArchetypeBlendPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY;
    /** Oghma topics core grounds for each hooked turn ($GLOBALS['OGHMA_PARITY_RESULT']['topics']). */
    private array $turnTopics = [];

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
        $this->schema = 'reldyn_blend' . getmypid() . '_' . bin2hex(random_bytes(3));

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

        $this->db = new RelDynArchetypeBlendPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdblend');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_archetype_blend_test.log');
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
        $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => $this->turnTopics];
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

    /** The player's casts as the plugin logs them: "<caster> casts <spell> " or "... casts <spell> on <target>". */
    private function casts(array $spells, string $caster = self::PLAYER, string $target = ''): void
    {
        foreach ($spells as $spell => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->eventRow('npcspellcast', $target !== '' ? "{$caster} casts {$spell} on {$target}" : "{$caster} casts {$spell} ");
            }
        }
    }

    /** Live 3.4.1 Oghma rows (dwemer.oghma 2026-09-24, SELECT-only), as core stores them. */
    private function oghmaRows(): void
    {
        $rows = [
            ["kyne's_peace", 'kaan drem ov', 'dragon', 'spells', "Dragons, Thu'um, Kyne, Nature, Beasts, Pacification, Words of Power, Goddess"],
            ['conjure_familiar', '', 'mage, companions', 'spells', 'Conjuration, spectral wolf, Novice, summoning, companions, temporary effects, wolves'],
            ['conjure_dremora_lord', '', 'mage', 'spells', 'Conjuration, Dremora, Daedra, Oblivion, summoning, Expert magic, combat allies'],
            ['healing', '', 'mage, healer', 'spells', 'Restoration, restore health, Novice spells, healing magic, mages, allied targets'],
            ['the_red_year', '', 'scholar', 'lore', 'Red Mountain, Morrowind, Dunmer, Melis Ravel, Tear, Vivec City, Mournhold, eruption, survival, resilience, catastrophe'],
        ];
        foreach ($rows as [$topic, $aliases, $class, $category, $tags]) {
            pg_query_params($this->db->link, 'INSERT INTO oghma (topic, topic_desc, knowledge_class, category, tags, aliases) VALUES ($1, $2, $3, $4, $5, $6)',
                [$topic, "About {$topic}.", $class, $category, $tags, $aliases]);
        }
    }

    /**
     * The builds, as the plugin reports them (core_player skills / stats / equipment, tracked
     * stats, the player's spell casts in the eventlog). The hands hold what the plugin can
     * report there: inventory items (Plugin.cpp fills left_hand / right_hand from
     * GetInventory(), so a staff or a weapon, never a spell). Every build has harvested
     * ingredients the way any mid-game character has (Ingredients Harvested counts every
     * plant picked on the road): the druid must come from nature magic or beast blood, not
     * from picking flowers.
     */
    private function blendBuild(string $kind, int $harvested = 0): void
    {
        switch ($kind) {
            case 'warrior':
                $this->playerBuild(['onehanded' => 90, 'twohanded' => 85, 'heavyarmor' => 80, 'block' => 60], 45);
                $this->equipmentRow([
                    'armor' => ['name' => 'Steel Plate Armor', 'baseid' => '0001395F', 'keywords' => ['ArmorHeavy', 'ArmorCuirass']],
                    'right_hand' => ['name' => 'Steel Greatsword', 'baseid' => '00013989', 'keywords' => ['WeapTypeGreatsword', 'WeapMaterialSteel']],
                ]);
                $this->trackedStat('People Killed', 120);
                break;
            case 'druid':        // herb lore, harvesting, beast blood, nature magic held and cast
                $this->playerBuild(['alchemy' => 80, 'restoration' => 45, 'archery' => 40, 'lightarmor' => 40], 38);
                $this->equipmentRow([
                    'armor' => ['name' => 'Forsworn Armor', 'baseid' => '000D8D50', 'keywords' => ['ArmorLight', 'ArmorMaterialForsworn']],
                ]);
                $this->trackedStat('Ingredients Harvested', max(350, $harvested));
                $this->trackedStat('Nirnroots Found', 10);
                $this->trackedStat('Werewolf Transformations', 4);
                $this->casts(["Kyne's Peace" => 6, 'Animal Allegiance' => 4, 'Conjure Familiar' => 10]);
                break;
            case 'nature_bard':  // a bard who communes with animals: a silver tongue, and nature magic
                $this->playerBuild(['speechcraft' => 90, 'illusion' => 55, 'archery' => 30, 'alchemy' => 35], 30);
                $this->equipmentRow([]);
                $this->trackedStat('Persuasions', 30);
                $this->trackedStat('Ingredients Harvested', max(150, $harvested));
                $this->casts(["Kyne's Peace" => 8, 'Conjure Familiar' => 6]);
                $this->casts(['Animal Allegiance' => 6], self::PLAYER, 'Cave Bear');
                break;
            case 'ranger_bard':  // a bard who hunts and communes with animals: two sides Aela values
                $this->playerBuild(['speechcraft' => 85, 'archery' => 65, 'sneak' => 50, 'alchemy' => 35], 30);
                $this->equipmentRow([]);
                $this->trackedStat('Persuasions', 30);
                $this->trackedStat('Ingredients Harvested', max(150, $harvested));
                $this->casts(["Kyne's Peace" => 8, 'Animal Allegiance' => 6]);
                break;
            case 'bard':         // the same bard without the wild: illusion and a silver tongue
                $this->playerBuild(['speechcraft' => 90, 'illusion' => 55, 'archery' => 30, 'alchemy' => 35], 30);
                $this->equipmentRow(['right_hand' => ['name' => 'Steel Dagger', 'baseid' => '00013987', 'keywords' => ['WeapTypeDagger', 'WeapMaterialSteel']]]);
                $this->trackedStat('Persuasions', 30);
                $this->trackedStat('Ingredients Harvested', $harvested);
                $this->casts(['Calm' => 8, 'Courage' => 6]);
                break;
            case 'scholar':
                $this->playerBuild(['enchanting' => 80, 'alteration' => 70, 'illusion' => 50], 30);
                $this->equipmentRow([]);
                $this->trackedStat('Books Read', 200);
                $this->trackedStat('Ingredients Harvested', $harvested);
                $this->casts(['Candlelight' => 12, 'Telekinesis' => 6]);
                break;
            case 'conjurer':     // a conjurer-scholar: daedra, souls and the dead (and the novice familiar)
                $this->playerBuild(['conjuration' => 85, 'enchanting' => 60, 'alteration' => 55, 'destruction' => 40], 35);
                $this->equipmentRow([
                    'right_hand' => ['name' => 'Staff of the Flame Atronach', 'baseid' => '000BF9FB', 'keywords' => ['WeapTypeStaff']],
                ]);
                $this->trackedStat('Books Read', 150);
                $this->trackedStat('Ingredients Harvested', $harvested);
                $this->trackedStat('Souls Trapped', 40);
                $this->casts(['Conjure Flame Atronach' => 20, 'Soul Trap' => 8, 'Raise Zombie' => 5, 'Conjure Familiar' => 2]);
                break;
            default:
                $this->fail("unknown build {$kind}");
        }
    }

    // ------------------------------------------------------------------ archetype-blend-attraction

    /**
     * Aela vs (a) a warrior, (b) a druid, (c) a bard who communes with animals, (d) a plain bard
     * and a scholar, (e) a conjurer-scholar, each built from core rows and read through
     * RelDynPlayer::profile() and the real hooks. The bard, the scholar and the conjurer have
     * harvested ingredients on the road like any mid-game character.
     */
    public function testAelaReadsThePlayersBlendedMix(): void
    {
        $seen = [];
        foreach (['warrior' => 0, 'druid' => 0, 'nature_bard' => 0, 'bard' => 300, 'scholar' => 150, 'conjurer' => 300] as $kind => $harvested) {
            $this->freshMeeting();
            $this->blendBuild($kind, $harvested);
            $ctx = $this->turn('Good hunting today.');
            $a = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
            $seen[$kind] = ['a' => $a, 'p' => RelDynPlayer::profile(), 'ctx' => $ctx, 'sum' => $this->dynamics()['_attraction']];
        }
        $why = fn(string $k) => "{$k}: {$seen[$k]['a']['reason']} " . json_encode($seen[$k]['a']['pillars']['strength'])
            . ' raw ' . json_encode($seen[$k]['p']['archetype_raw']);

        // (a), (b): drawn, and she knows what she values
        foreach (['warrior' => 'warrior', 'druid' => 'druid'] as $kind => $valued) {
            $this->assertTrue($seen[$kind]['a']['passes'], $why($kind));
            $this->assertNull($seen[$kind]['a']['passion_cap'], $why($kind));
            $this->assertSame($valued, $seen[$kind]['a']['valued'], $why($kind));
            $this->assertStringContainsString('eyes keep finding the player', $seen[$kind]['ctx']);
        }
        $this->assertEqualsWithDelta(1.0, $seen['druid']['p']['archetypes']['druid'], 1e-9, 'the druid is a druid first');
        $this->assertStringContainsString('bond with the wild', $seen['druid']['ctx']);

        // (c) the bard who communes with animals: a bard by label, attracting through the druid share
        $nb = $seen['nature_bard'];
        $this->assertEqualsWithDelta(1.0, $nb['p']['archetypes']['bard'], 1e-9, 'a bard first: ' . json_encode($nb['p']['archetypes']));
        $this->assertLessThan(1.0, $nb['p']['archetypes']['druid']);
        $this->assertGreaterThan(RelDynPlayer::config()['archetype_identity_floor'], $nb['p']['archetype_raw']['druid'],
            'a formed druid side: ' . $why('nature_bard'));
        $this->assertTrue($nb['a']['passes'], $why('nature_bard'));
        $this->assertNull($nb['a']['passion_cap'], $why('nature_bard'));
        $this->assertSame('druid', $nb['a']['valued'], $why('nature_bard'));
        $this->assertArrayHasKey('druid', $nb['a']['pillars']['strength']['mix']);
        $this->assertStringContainsString('eyes keep finding the player', $nb['ctx']);
        $this->assertStringContainsString('bond with the wild', $nb['ctx']);

        // (d) the same bard without the wild, and a scholar: tolerated, no passion
        foreach (['bard', 'scholar'] as $kind) {
            $this->assertFalse($seen[$kind]['a']['passes'], $why($kind));
            $this->assertContains($seen[$kind]['a']['outcome'], ['friendzone', 'unattracted'], $why($kind));
            $this->assertSame(20.0, floatval($seen[$kind]['a']['passion_cap']), $why($kind));
            $this->assertSame(0, $seen[$kind]['a']['romance']['allowed'], $why($kind));
            $this->assertNotSame('druid', $seen[$kind]['a']['valued'], $why($kind));
            $this->assertStringNotContainsString('eyes keep finding the player', $seen[$kind]['ctx']);
            $this->assertLessThan(0.1, $seen[$kind]['p']['archetype_raw']['druid'], $why($kind));
        }
        // Everything but the nature magic and the harvesting is the same between (c) and (d)
        // (the nature side is what lifts her strength read over her bar; the plain bard stays under it)
        $this->assertGreaterThanOrEqual($nb['a']['pillars']['strength']['bar'], $nb['a']['pillars']['strength']['score'], $why('nature_bard'));
        $this->assertLessThan($seen['bard']['a']['pillars']['strength']['bar'], $seen['bard']['a']['pillars']['strength']['score'], $why('bard'));

        // (e) the conjurer-scholar is a scholar and a mage, not a druid, and she tolerates him
        $c = $seen['conjurer'];
        $this->assertEqualsWithDelta(1.0, max($c['p']['archetypes']['mage'], $c['p']['archetypes']['scholar']), 1e-9, json_encode($c['p']['archetypes']));
        $this->assertGreaterThan(0.6, $c['p']['archetypes']['mage'], json_encode($c['p']['archetypes']));
        $this->assertLessThan(0.1, $c['p']['archetype_raw']['druid'], $why('conjurer'));
        $this->assertLessThan(0.1, $c['p']['derivation']['archetypes']['druid']['spells'], 'a novice familiar among the daedra is no nature magic');
        $this->assertFalse($c['a']['passes'], $why('conjurer'));
        $this->assertSame(20.0, floatval($c['a']['passion_cap']), $why('conjurer'));
        $this->assertNotSame('druid', $c['a']['valued'], $why('conjurer'));
        $this->assertStringNotContainsString('bond with the wild', $c['ctx']);
        $this->assertNoDbFailures();
    }

    /**
     * Ken: "Nature spells no, hence the druid or a bard who communes with animals." Herb lore and
     * a full satchel only support a druid; without nature magic or beast blood the player is an
     * herbalist, and Aela is not drawn to one. Realistic harvest counts (150-500), through the
     * real hooks.
     */
    public function testHarvestingWithoutNatureMagicNeverMakesADruid(): void
    {
        $cases = [['bard', 150], ['bard', 300], ['bard', 500], ['conjurer', 300], ['conjurer', 500], ['herbalist', 500]];
        foreach ($cases as [$kind, $harvested]) {
            $this->freshMeeting();
            if ($kind === 'herbalist') {
                // herb lore at its fullest, no magic, no beast blood
                $this->playerBuild(['alchemy' => 85, 'archery' => 35, 'sneak' => 30], 30);
                $this->equipmentRow([]);
                $this->trackedStat('Nirnroots Found', 12);
                $this->trackedStat('Wings Plucked', 40);
                $this->trackedStat('Ingredients Harvested', $harvested);
            } else {
                $this->blendBuild($kind, $harvested);
            }
            $ctx = $this->turn('Good hunting today.');
            $a = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics());
            $p = RelDynPlayer::profile();
            $why = "{$kind} harvested {$harvested}: {$a['reason']} raw " . json_encode($p['archetype_raw'])
                . ' druid ' . json_encode($p['derivation']['archetypes']['druid']);
            $this->assertLessThan(0.1, $p['archetype_raw']['druid'], $why);
            $this->assertNotSame('druid', $a['valued'], $why);
            $this->assertStringNotContainsString('bond with the wild', $ctx, $why);
            if ($kind !== 'herbalist') {   // the bard and the conjurer-scholar stay tolerated, no passion
                $this->assertFalse($a['passes'], $why);
                $this->assertSame(20.0, floatval($a['passion_cap']), $why);
                $this->assertStringNotContainsString('eyes keep finding the player', $ctx, $why);
            }
        }

        // Beast blood is the druid's without a single spell (werewolf transformations, beast form)
        $this->freshMeeting();
        $this->playerBuild(['alchemy' => 40, 'archery' => 40, 'twohanded' => 45], 30);
        $this->equipmentRow([]);
        $this->trackedStat('Ingredients Harvested', 300);
        $this->trackedStat('Werewolf Transformations', 6);
        $w = RelDynPlayer::profile();
        $this->assertNull($w['facts']['spells']['value']);
        $this->assertGreaterThan(0.2, $w['archetype_raw']['druid'], json_encode($w['derivation']['archetypes']['druid']));
        $this->assertNoDbFailures();
    }

    /**
     * lens_blend is config: 0 reads the single best archetype; the default adds a second formed
     * side the NPC values (a bard who hunts and communes with animals) on top of the best one.
     */
    public function testTheLensBlendReadsSecondFormedSides(): void
    {
        $this->blendBuild('ranger_bard');
        $this->turn('Good hunting today.');
        $mixed = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics())['pillars']['strength'];
        $min = RelDynAttraction::config()['lens_blend_min_contribution'];
        $formed = array_filter($mixed['mix'], fn($c) => $c >= $min);
        $this->assertEqualsCanonicalizing(['hunter', 'druid'], array_keys($formed), json_encode($mixed['mix']));
        $this->setConfig(['attraction' => ['lens_blend' => 0.0]]);
        $single = RelationshipDynamics::attractionFor(self::AELA, $this->dynamics())['pillars']['strength'];
        $this->assertSame($single['mix'], $mixed['mix'], 'the same player, the same contributions');
        $this->assertGreaterThan($single['score'] + 0.05, $mixed['score'], 'the second side adds to the best one');
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ player-stats-pipeline: druid inference

    public function testDruidInferenceComesFromNatureMagicAndDeedsNotFromSchools(): void
    {
        // Nature magic cast (no equipment snapshot at all), herb lore and harvesting
        $this->playerBuild(['alchemy' => 50, 'conjuration' => 40], 25);
        $this->trackedStat('Ingredients Harvested', 100);
        $this->casts(["Kyne's Peace" => 6, 'Animal Allegiance' => 6]);
        $this->casts(['Healing' => 30], self::AELA);                 // her casts are not the player's
        $p = RelDynPlayer::profile();
        $spells = $p['facts']['spells'];
        $this->assertEqualsCanonicalizing(["Kyne's Peace", 'Animal Allegiance'], array_keys($spells['value']), json_encode($spells));
        $this->assertSame(6, $spells['value']["Kyne's Peace"]['casts']);
        $this->assertFalse($spells['value']["Kyne's Peace"]['held']);
        $this->assertSame('nature', array_key_first($spells['facets']));
        $this->assertEqualsWithDelta(1.0, $p['archetypes']['druid'], 1e-9, json_encode($p['archetype_raw']));
        $this->assertGreaterThan(0.4, $p['derivation']['archetypes']['druid']['spells']);

        // Beast form (core_player.transformation_state) is a druid deed
        $this->corePlayerRow('transformation_state', ['state' => 'werewolf', 'is_werewolf_form' => true, 'is_vampire_lord_form' => false,
            'race_name' => 'Werewolf', 'race_editor_id' => 'WerewolfBeastRace']);
        $beast = RelDynPlayer::profile();
        $this->assertSame(1, $beast['evidence']['form:beast']);
        $this->assertGreaterThan($p['derivation']['archetypes']['druid']['deeds'], $beast['derivation']['archetypes']['druid']['deeds']);

        // A conjurer of daedra with a staff: magic, but not nature magic; a sword is not magic at
        // all, and neither is anything else in a hand that is not a staff (the plugin fills the
        // hands from the inventory: a torch, never a spell)
        $this->freshMeeting();
        $this->playerBuild(['conjuration' => 80, 'destruction' => 50], 30);
        $this->equipmentRow([
            'left_hand' => ['name' => 'Staff of Fireballs', 'baseid' => '0002AC6F', 'keywords' => ['WeapTypeStaff']],
            'right_hand' => ['name' => 'Torch', 'baseid' => '0001D4EC', 'keywords' => []],
        ]);
        $this->casts(['Conjure Dremora Lord' => 15, "Vaermina's Torpor" => 2]);
        $c = RelDynPlayer::profile();
        $this->assertSame(['Staff of Fireballs', 'Conjure Dremora Lord'], array_keys($c['facts']['spells']['value']));
        $this->assertTrue($c['facts']['spells']['value']['Staff of Fireballs']['held']);
        $this->equipmentRow([
            'left_hand' => ['name' => 'Conjure Familiar', 'baseid' => '000640B6', 'keywords' => []],
            'right_hand' => ['name' => 'Iron Sword', 'baseid' => '00012EB7', 'keywords' => ['WeapTypeSword', 'WeapMaterialIron']],
        ]);
        $this->assertSame(['Conjure Dremora Lord'], array_keys(RelDynPlayer::profile()['facts']['spells']['value']),
            'a hand item without the staff keyword is no spell, whatever its name');
        $this->equipmentRow([
            'left_hand' => ['name' => 'Staff of Fireballs', 'baseid' => '0002AC6F', 'keywords' => ['WeapTypeStaff']],
            'right_hand' => ['name' => 'Iron Sword', 'baseid' => '00012EB7', 'keywords' => ['WeapTypeSword', 'WeapMaterialIron']],
        ]);
        $c = RelDynPlayer::profile();
        $this->assertSame(["Vaermina's Torpor"], $c['facts']['spells']['unread'], 'a name nobody can read counts for nothing');
        $this->assertSame(0.0, $c['derivation']['archetypes']['druid']['spells']);
        $this->assertLessThan(0.05, $c['archetype_raw']['druid']);
        $this->assertGreaterThan(0.5, $c['derivation']['archetypes']['mage']['spells']);
        $this->assertEqualsWithDelta(1.0, $c['archetypes']['mage'], 1e-9);

        // No magic read at all: the spells component is unknown, not zero
        $this->freshMeeting();
        $this->blendBuild('warrior');
        $w = RelDynPlayer::profile();
        $this->assertNull($w['facts']['spells']['value']);
        $this->assertNull($w['derivation']['archetypes']['druid']['spells']);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ spell-subject-facets on Oghma (live prior and the builder)

    /** The heaviest facet (stored vectors come back from jsonb in key order, not weight order). */
    private static function top(array $facets): ?string
    {
        arsort($facets);
        return array_key_first($facets);
    }

    public function testSpellTopicsReadBySubjectInTheLivePriorAndTheBuilder(): void
    {
        $this->oghmaRows();
        $prefs = RelDynFacets::preferences([], self::AELA);
        foreach (['live' => null, 'built' => true] as $mode => $build) {
            if ($build) {
                $stats = RelDynFacetClassifier::build($this->db, ['prior_only' => true]);
                $this->assertSame(5, $stats['written'], json_encode($stats));
                $this->assertSame('prior', RelDynFacetClassifier::oghmaFacets("Kyne's Peace")['source']);
            } else {
                $this->assertSame('live_prior', RelDynFacetClassifier::oghmaFacets("Kyne's Peace")['source']);
            }
            $kyne = RelDynFacets::thingFacets('topic', "Kyne's Peace");
            $this->assertSame('nature', self::top($kyne), "{$mode}: " . json_encode($kyne));
            $this->assertSame('nature', self::top(RelDynFacets::thingFacets('topic', 'conjure familiar')), $mode);
            $this->assertSame('spiritual', self::top(RelDynFacets::thingFacets('topic', 'healing')), $mode);
            $this->assertSame('scholarly', self::top(RelDynFacets::thingFacets('topic', 'the red year')), "{$mode}: lore stays lore");

            $t = RelDynFacetClassifier::topicTurn([], self::AELA, ["kyne's_peace"]);
            $this->assertSame("kyne's_peace", $t['match'], "{$mode}: a topic she warms to");
            $this->assertStringNotContainsString('bookish', (string) $t['felt']);
            $this->assertGreaterThan(1.0, $t['bonus']);
        }
        $this->assertLessThan(0.0, RelDynFacets::appraise($prefs, RelDynFacets::thingFacets('topic', 'the red year'))['valence']);

        // Through the hooks: the topic core grounded this turn is felt as the wild, not as a book
        $this->turnTopics = ["kyne's_peace"];
        $this->turn('The Greybeards taught me this one.');
        $felt = (string) ($this->dynamics()['_last_topic_felt'] ?? '');
        $this->assertStringContainsString('wild', $felt);
        $this->assertStringNotContainsString('bookish', $felt);
        $this->assertNoDbFailures();
    }
}
