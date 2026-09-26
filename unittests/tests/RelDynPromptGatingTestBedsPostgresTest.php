<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// data_functions.php pulls in chim_interaction.php, which would create conf/chim_interaction/.
$GLOBALS['chim_interaction_generation'] = $GLOBALS['chim_interaction_generation'] ?? 0;
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/chat_helper_functions.php';   // main.php loads it: DataLastInfoFor's helpers
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on a failed
 * statement, fetchAll throws). Core's prompt builders name a few tables schema-qualified
 * (public.named_cell, public.visual_context): the test's own schema stands in for public.
 */
final class RelDynPromptGatingPgDb
{
    public $link;
    public array $failures = [];
    private string $schema;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        $this->schema = $schema;
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function q(string $q): string
    {
        return (string) preg_replace('/\bpublic\./', $this->schema . '.', $q);
    }

    public function fetchOne($q, array $params = [])
    {
        $q = $this->q((string) $q);
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $this->q((string) $q));
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q)
    {
        $res = @pg_query($this->link, $this->q((string) $q));
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link, $this->q("INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')'), array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Prompt gating end to end (prompt-gating-core-hook, -tier-knowledge, -tier-floor, -fame and the
 * reputation layer; decisions §7, §18 #6) with the four test beds: Aela the Huntress, Ashe (Serene's
 * hand-set vector, never read), Muiri and Lynly Star-Sung, plus three strangers, on CHIM 3.4.1
 * core-shaped rows and the committed seed's reads. Each turn runs as main.php runs it: RelDyn's
 * prerequest, core's DataLastInfoFor (the nearby actors, where core puts the player's name and bio),
 * RelDyn's context_pre, core's relationship block (RelationshipManager::buildContext, as core's
 * relationship_system context_pre injects it), RelDyn's context. No LLM call.
 *
 * The player (Kaida) is a Companion of some standing: the questline's tracked stat and journal.
 *   - Whiterun, Jorrvaskr: Aela (a friend) knows him, his name and his story; Hulda, who has never
 *     met him, has heard of a Companion of his description, not his name (the Companions are home
 *     here). The April design's reach (two hold steps) carries it to the Pale, Winterhold and
 *     Haafingar too; off the map (Solstheim) nothing is heard until he is the Dragonborn, and then
 *     only tales of a Dragonborn.
 *   - Ashe (an acquaintance) knows the name, not the story; when the bond falls away she still
 *     knows who he is (the tier floor). Muiri (toxic) met him once and turned cold: she knows who,
 *     and it shows as hostility.
 *   - Met, never warm (you can only be unknown once): an NPC with core speech rows with him, or a
 *     start in hostility, knows him whatever her affinity, and core's own familiarity note and
 *     relationship line stay (Brina, three exchanges and hostile; Birna, a dozen and indifferent;
 *     the four test beds each with her own history).
 * Core's relationship block shows the player only to those who met him; the nearby-actors entry
 * shows the bio only to the one who knows his story and says who knows him where core cannot; RelDyn's
 * text agrees and tells the ones who do not know the name not to use it. Feelings, never numbers.
 * With prompt gating off, or no gate registered, core behaves as it always did.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynPromptGatingTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const BIO = 'Kaida grew up on a farm outside Rorikstead and swore to hunt dragons after one burned it.';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const D0 = 150;
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice, core affinity toward the player]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander', 45],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null, 20],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager', 25],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord', 0],
    ];
    /** Strangers: name => [race, class] */
    private const STRANGERS = ['Hulda' => ['NordRace', 'Citizen'], 'Brina Merilis' => ['NordRace', 'Soldier'], 'Birna' => ['NordRace', 'Citizen'],
        'Geldis Sadri' => ['DarkElfRace', 'Citizen']];
    private const PLACES = [
        'jorrvaskr'   => ['Jorrvaskr', 'Whiterun'],
        'mare'        => ['The Bannered Mare', 'Whiterun'],
        'dawnstar'    => ['Windpeak Inn', 'The Pale'],
        'winterhold'  => ['Birna\'s Oddments', 'Winterhold'],
        'dragonbridge' => ['Four Shields Tavern', 'Haafingar'],
        'markarth'    => ['Arnleif and Sons Trading Company', 'The Reach'],
        // not one of the nine holds (off the hold map)
        'ravenrock'   => ['The Retching Netch', 'Solstheim'],
    ];

    private string $dsn;
    private string $schema;
    private RelDynPromptGatingPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727100000;
    private int $gamets;
    private int $llmCalls = 0;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_gate_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and its history table
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
        // data/database_default.sql eventlog, speech, rolemaster
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE speech (sess varchar(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY,
            companions text, audios text, utterance_id text)");
        pg_query($admin, "CREATE TABLE rolemaster (localts bigint NOT NULL, ttl bigint NOT NULL, type varchar(128), data text,
            rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns) and its view; data/named_cell.sql
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE VIEW locations_v AS SELECT * FROM locations
            WHERE CASE WHEN formid = 102771 AND cleared = FALSE THEN FALSE ELSE TRUE END");
        pg_query($admin, "CREATE TABLE named_cell (id bigint NOT NULL, cell_name text, location_id bigint, interior integer,
            dest_door_cell_id bigint, dest_door_exterior bigint, door_id bigint NOT NULL, vanilla_cell boolean, statics_list text,
            worldspace text, closed int, door_name text, door_x numeric, door_y numeric, gamets bigint)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        // core's general settings (lib/settings.php) and prompt overrides (rel_tier_reference)
        pg_query($admin, "CREATE TABLE general_settings (id text PRIMARY KEY, value text, description text, updated_at timestamp DEFAULT now())");
        pg_query($admin, "CREATE TABLE prompts (prompt_key text PRIMARY KEY, custom_prompt text, default_prompt text)");
        // lib/visual_context.php chimEnsureVisualContextTable() (it creates the table once per process)
        pg_query($admin, "CREATE TABLE visual_context (id bigserial PRIMARY KEY, subject_type text NOT NULL DEFAULT 'scene',
            subject_key text NOT NULL, subject_name text NOT NULL DEFAULT '', plugin text NOT NULL DEFAULT '',
            baseid text NOT NULL DEFAULT '', refid text NOT NULL DEFAULT '', cell_id text NOT NULL DEFAULT '',
            location_name text NOT NULL DEFAULT '', image_path text NOT NULL DEFAULT '', image_sha256 text NOT NULL DEFAULT '',
            description text NOT NULL DEFAULT '', perspective text NOT NULL DEFAULT 'first_person', provider text NOT NULL DEFAULT '',
            model text NOT NULL DEFAULT '', metadata jsonb NOT NULL DEFAULT '{}'::jsonb, locked boolean NOT NULL DEFAULT FALSE,
            active boolean NOT NULL DEFAULT TRUE, user_edited boolean NOT NULL DEFAULT FALSE,
            captured_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynPromptGatingPgDb($dsn, $this->schema);
        // Core loads the gate files on its first question; load them now so tearDown restores the registration
        chimPlayerKnowledgeFor('');
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'COMMAND_PROMPT', 'PROMPT_NEARBY_SECTIONS',
                     'RELATIONSHIP_SYSTEM_ENABLED', 'CHIM_PLAYER_KNOWLEDGE_GATES', 'PLAYER_BIOS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            if ($key !== 'CHIM_PLAYER_KNOWLEDGE_GATES') unset($GLOBALS[$key]);
        }
        $this->clearCaches();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // core's relationship LLM configured: its block is tier-only
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdgatebeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_gate_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        // Shipped defaults, stored as the config page stores them
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        $this->gamets = self::D0 * self::DAY + (int) (10 * self::HOUR);
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearCaches();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** RelDyn's request globals and core's per-request location / actor caches. */
    private function clearCaches(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_') || str_starts_with((string) $key, 'CACHE_')) unset($GLOBALS[$key]);
        }
        unset($GLOBALS['PROMPT_NEARBY_SECTIONS']);
    }

    /** Core rows, the seed's reads, the player profile (a Companion) and core's player bio. */
    private function seed(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice, $aff]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            $this->npcRow($name, $race, array_merge($all, array_map('strval', $skills)), $class, $f,
                $aff === 0 ? [] : ['Player' => ['aff' => $aff, 'type' => $aff >= 31 ? 'platonic' : 'neutral']]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        foreach (self::STRANGERS as $name => [$race, $class]) $this->npcRow($name, $race, $all, $class, [], []);
        foreach (self::PLACES as [$place, $hold]) {
            pg_query_params($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES ($1, $2, 'Inn,', 1, 'Tamriel')", [$place, $hold]);
        }
        // Core's player: Player Management's bio (not known by all) and the plugin's gender / race
        foreach (['bio' => self::BIO, 'bio_known_by_all' => 'false', 'gender' => 'male', 'race' => 'Nord'] as $id => $value) {
            pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', [$id, $value]);
        }
        // What the player is known for (RelDynPlayer's profile): the Companions questline done in
        // part (tracked stat, conf_opts as setconf writes it) and two of its quests in the journal
        $this->trackedStat('The Companions Quests Completed', 3);
        $this->trackedStat('Quests Completed', 12);
        foreach (['C01', 'C02'] as $i => $quest) {
            pg_query_params($this->db->link, "INSERT INTO quests (ts, id_quest, name, stage, localts, gamets) VALUES ('0', $1, $2, 10, $3, $4)",
                [$quest, "Companions quest {$i}", $this->realTs, $this->gamets]);
        }
    }

    private function npcRow(string $name, string $race, array $skills, string $class, array $factions, array $relationships): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
            [$name, 'female', $race, '', "Roleplay as {$name}", '', json_encode(['skills' => $skills]),
             json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $factions, 'relationships' => $relationships])]);
    }

    private function trackedStat(string $stat, int $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [$stat, (string) $value]);
    }

    private function setCoreAffinity(string $npc, int $aff): void
    {
        // a stranger's row stores relationships as an empty list: start an object there
        pg_query_params($this->db->link,
            "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}',
                (CASE WHEN jsonb_typeof(extended_data->'relationships') = 'object' THEN extended_data->'relationships' ELSE '{}'::jsonb END)
                || jsonb_build_object('Player', $2::jsonb)) WHERE npc_name = $1",
            [$npc, json_encode(['aff' => $aff, 'type' => 'neutral'])]);
    }

    /** $n earlier lines between $npc and the player in core's speech table (as core logs them), alternating who speaks. */
    private function speech(string $npc, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            [$speaker, $listener] = $i % 2 === 0 ? [$npc, self::PLAYER] : [self::PLAYER, $npc];
            $gamets = $this->gamets - (int) ((30 - $i) * self::DAY);
            pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, topic, localts, gamets, ts)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', ['pending', $speaker, "An earlier word ({$i}).", '', $listener, '',
                $this->realTs - 86400 * (30 - $i), $gamets, $gamets]);
        }
    }

    private function storeGating(array $gating): void
    {
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true,
                'prompt_gating' => array_replace(RelDynGating::configDefaults(), $gating)]))]);
        RelationshipDynamics::clearConfigCache();
    }

    private function event(string $type, string $data, int $gamets, string $people = ''): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people, '', null]);
    }

    /**
     * The player says $line to $npc at $place, run as main.php runs the turn. Returns what reached
     * the LLM: 'nearby' (core's nearby-actors entry for the player), 'relationships' (core's block),
     * 'pers' (what RelDyn added to <character>), 'command' (what was added to COMMAND_PROMPT),
     * 'blocks' (RelDyn's context messages), 'felt' (the felt lines), 'gate' (the hook's answer).
     */
    private function turn(string $npc, string $placeKey, string $line): array
    {
        [$place, $hold] = self::PLACES[$placeKey];
        $this->gamets += (int) (20 * self::HOUR / 60);
        $this->realTs += 60;
        $people = '|' . $npc . '|' . self::PLAYER . '|';
        $this->event('infoloc', "(Context location: {$place} ,Hold: {$hold}, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)",
            $this->gamets, $people);
        $this->event('infonpc_close', "beings in range:{$npc}/" . self::PLAYER, $this->gamets, $people);
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $this->gamets, $people);
        $request = ['inputtext', (string) $this->realTs, (string) $this->gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $set = function () use ($npc, $request, $people): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $people;
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
        };
        $hook = function (string $file) use ($set): void {
            $set();
            (static function () use ($file): void { require __DIR__ . "/../../ext/relationship_dynamics/{$file}"; })();
            RelationshipDynamics::endRequest();
        };
        $this->clearCaches();
        $hook('prerequest.php');
        // main.php: DataLastInfoFor builds the nearby actors (the player's name and bio)
        $set();
        DataLastInfoFor('', -2, true);
        $nearby = (string) ($GLOBALS['PROMPT_NEARBY_SECTIONS'] ?? '');
        $entry = '';
        foreach (explode("\n", $nearby) as $l) {
            if (str_starts_with(ltrim($l, '# '), self::PLAYER)) $entry = ltrim($l, '# ');
        }
        // main.php: the context_pre hooks (RelDyn's, then core's relationship block), then context
        $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
        $GLOBALS['COMMAND_PROMPT'] = '';
        $GLOBALS['contextDataFull'] = [];
        $hook('context_pre.php');
        $set();
        $relationships = RelationshipManager::buildContext($npc, [$npc, self::PLAYER]);
        $pers = substr((string) $GLOBALS['HERIKA_PERS'], strlen("Roleplay as {$npc}."));
        $command = (string) $GLOBALS['COMMAND_PROMPT'];
        $hook('context.php');
        $blocks = array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []);
        $felt = RelDynFelt::lastRendered();
        $set();
        $gate = chimPlayerKnowledgeFor($npc);
        RelationshipDynamics::endRequest();
        $this->clearCaches();
        return ['nearby' => $entry, 'relationships' => $relationships, 'pers' => $pers, 'command' => $command,
            'blocks' => $blocks, 'felt' => $felt, 'gate' => $gate];
    }

    private static function knowledgeBlock(array $t): string
    {
        $p = $t['pers'];
        $a = strpos($p, '<knowledge_of_player>');
        if ($a === false) return '';
        return substr($p, $a, strpos($p, '</knowledge_of_player>') - $a);
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'failed SQL statements');
    }

    // ------------------------------------------------------------------ tests

    /** Everything RelDyn put in front of the dialogue model, the COMMAND_PROMPT instruction aside. */
    private static function reldynText(array $t): string
    {
        return $t['pers'] . "\n" . implode("\n", $t['blocks']);
    }

    /**
     * $npc does not know him: $level 'stranger' (nothing heard) or 'renowned' (his deeds heard where
     * she is, never his name). No name from RelDyn, the instruction not to use it, core's
     * familiarity note replaced by RelDyn's, no story, no player line in core's block.
     */
    private function assertStrangerTo(array $t, string $npc, string $why, string $level = 'stranger'): void
    {
        $this->assertSame($level, $t['gate']['level'] ?? null, $why);
        $this->assertStringNotContainsString(self::PLAYER, self::reldynText($t), "{$why}: RelDyn never names him\n" . self::reldynText($t));
        $this->assertStringContainsString("{$npc} does not know this person's name. Never call them \"" . self::PLAYER . '"', $t['command'], $why);
        $this->assertSame(self::PLAYER . ' (Male Nord) (' . ($level === 'renowned'
            ? "{$npc} has never met this person and does not know their name, but has heard of their deeds)"
            : "{$npc} has never met this person and does not know their name, past or deeds)"), $t['nearby'], $why);
        $this->assertStringNotContainsString(self::PLAYER . ':', $t['relationships'], "{$why}: no player line in core's block");
    }

    public function testStrangersHearOfHisDeedsWhereTheyReachButNeverLearnHisName(): void
    {
        // Whiterun: the Companions are home; what is said of a Companion is heard, not his name
        $hulda = $this->turn('Hulda', 'mare', 'A room for the night.');
        $this->assertSame(['bio' => false, 'relationship' => false, 'level' => 'renowned'],
            array_intersect_key($hulda['gate'], ['bio' => 1, 'relationship' => 1, 'level' => 1]));
        $this->assertStrangerTo($hulda, 'Hulda', 'Whiterun', 'renowned');
        $know = self::knowledgeBlock($hulda);
        $this->assertStringContainsString('Hulda has never met this stranger, only heard the stories told about someone of their description', $know);
        $this->assertStringContainsString("Hulda has heard that someone of this stranger's description runs with the Companions of Jorrvaskr.", $know);

        // The April design's reach (two hold steps from Whiterun): the Pale, Winterhold, Haafingar
        foreach ([['Brina Merilis', 'dawnstar', 'Dawnstar, one hold out'], ['Birna', 'winterhold', 'Winterhold, two holds out'],
                     [self::LYNLY, 'dragonbridge', 'Dragon Bridge, two holds out']] as [$npc, $place, $why]) {
            $t = $this->turn($npc, $place, 'Any news?');
            $this->assertStrangerTo($t, $npc, $why, 'renowned');
            $this->assertStringContainsString("{$npc} has heard that someone of this stranger's description runs with the Companions", self::knowledgeBlock($t), $why);
        }
        // Solstheim (off the hold map): nothing reaches Raven Rock
        $geldis = $this->turn('Geldis Sadri', 'ravenrock', 'A room, please.');
        $this->assertStrangerTo($geldis, 'Geldis Sadri', 'Raven Rock');
        $this->assertStringContainsString('knows nothing of their name, past or deeds', self::knowledgeBlock($geldis));
        $this->assertStringNotContainsString('heard', self::knowledgeBlock($geldis));

        // He becomes the Dragonborn (the plugin reports the tracked stat): tales of a Dragonborn
        // are heard everywhere; they do not give her his name (the design's fragment)
        $this->trackedStat('Dragon Souls Collected', 6);
        $geldis = $this->turn('Geldis Sadri', 'ravenrock', 'Any news from the mainland?');
        $this->assertStrangerTo($geldis, 'Geldis Sadri', 'Raven Rock, the Dragonborn', 'renowned');
        $this->assertStringContainsString('Geldis Sadri has heard tales of a Dragonborn', self::knowledgeBlock($geldis));
        $this->assertStringNotContainsString('Companions', self::knowledgeBlock($geldis), 'still beyond their reach');
        $this->assertStringNotContainsString(self::BIO, $geldis['nearby'], 'renown is not his story');
        $this->assertNoDbFailures();
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
    }

    /**
     * You can only be unknown once, warm or not (the tier-floor and tier-knowledge review): an NPC
     * with a history with him is never told she has never met him, and core's own familiarity
     * note and relationship line (her hostility with them) stay. Brina Merilis: three exchanges in
     * core's speech table, core affinity -40, never positive. Birna: a dozen, affinity 3. Hulda:
     * turned hostile without a word spoken (core affinity -50). The four test beds, each with her
     * own history: Aela (a friend, many words), Ashe (an acquaintance, a couple), Muiri (toxic:
     * hostile from the first, two exchanges), Lynly (six exchanges, never warmed): four readings.
     */
    public function testWhoeverHasMetHimIsNeverToldSheHasNot(): void
    {
        $this->speech('Brina Merilis', 3);
        $this->setCoreAffinity('Brina Merilis', -40);
        $brina = $this->turn('Brina Merilis', 'dawnstar', 'Any work for a sellsword?');
        $this->assertSame(['bio' => false, 'relationship' => true, 'level' => 'met'],
            array_intersect_key($brina['gate'], ['bio' => 1, 'relationship' => 1, 'level' => 1]));
        $this->assertNull($brina['gate']['note'], "core's own note");
        $this->assertSame('Kaida (Male Nord) (Brina Merilis has talked to Kaida a couple of times before)', $brina['nearby']);
        $this->assertStringContainsString('Kaida: Cold', $brina['relationships'], "her hostility in core's block");
        $this->assertSame('', $brina['command'], 'core names him to her: no instruction against it');
        $this->assertStringContainsString('Brina Merilis knows Kaida only as trouble', self::knowledgeBlock($brina));
        $this->assertStringNotContainsString('Companions', self::knowledgeBlock($brina), 'below Wary she wants none of his fame');
        foreach ([$brina['nearby'], $brina['pers'], $brina['command']] as $text) $this->assertStringNotContainsString('never met', $text);

        // With gating off core's block and note are the same: the gate leaves a met NPC's alone
        $this->storeGating(['enabled' => false]);
        $off = $this->turn('Brina Merilis', 'dawnstar', 'Any work for a sellsword?');
        $this->assertSame($off['relationships'], $brina['relationships']);
        $this->assertSame($off['nearby'], $brina['nearby']);
        $this->storeGating([]);

        $this->speech('Birna', 12);
        $birna = $this->turn('Birna', 'winterhold', 'What do you sell?');
        $this->assertSame(['relationship' => true, 'level' => 'met'], array_intersect_key($birna['gate'], ['relationship' => 1, 'level' => 1]));
        $this->assertSame('Kaida (Male Nord)', $birna['nearby'], 'core adds nothing after five exchanges, and neither does the gate');
        $this->assertStringContainsString('Kaida: Neutral', $birna['relationships']);
        $this->assertStringContainsString('Birna has crossed paths with Kaida before and knows the name', self::knowledgeBlock($birna));
        $this->assertStringContainsString('Birna has heard that Kaida runs with the Companions', self::knowledgeBlock($birna), 'the rumour, named');
        $this->assertSame('', $birna['command']);

        $this->setCoreAffinity('Hulda', -50);
        $hulda = $this->turn('Hulda', 'mare', 'You again.');
        $this->assertSame(['bio' => false, 'relationship' => true, 'level' => 'met'],
            array_intersect_key($hulda['gate'], ['bio' => 1, 'relationship' => 1, 'level' => 1]));
        $this->assertSame('Kaida (Male Nord) (Hulda has crossed paths with this person before)', $hulda['nearby'],
            'no speech row: core would call her a stranger');
        $this->assertStringContainsString('Kaida: Cold', $hulda['relationships']);
        $this->assertEqualsWithDelta(-50.0, $this->dynamics('Hulda')[RelDynGating::KEY]['peak_core_aff'], 1e-6, 'no floor ever reached');

        // The four test beds, each with her own history
        $this->speech(self::AELA, 7);
        $this->speech('Ashe', 2);
        $this->speech('Muiri', 2);
        $this->setCoreAffinity('Muiri', -40);
        $this->speech(self::LYNLY, 6);
        $beds = [self::AELA => $this->turn(self::AELA, 'jorrvaskr', 'Good hunt today.'),
                 'Ashe' => $this->turn('Ashe', 'jorrvaskr', 'Ready to move out?'),
                 'Muiri' => $this->turn('Muiri', 'markarth', 'How is the shop?'),
                 self::LYNLY => $this->turn(self::LYNLY, 'dragonbridge', 'Play something cheerful.')];
        $this->assertSame(['personal', 'personal', 'met', 'met'], array_values(array_map(fn($t) => $t['gate']['level'], $beds)));
        $this->assertSame('Kaida (Male Nord): ' . self::BIO, $beds[self::AELA]['nearby'], 'her story; core adds no note after many words');
        $this->assertSame('Kaida (Male Nord) (Ashe has talked to Kaida a couple of times before)', $beds['Ashe']['nearby']);
        $this->assertSame('Kaida (Male Nord) (Muiri has talked to Kaida a couple of times before)', $beds['Muiri']['nearby']);
        $this->assertSame('Kaida (Male Nord)', $beds[self::LYNLY]['nearby']);
        $this->assertStringContainsString('Muiri knows Kaida only as trouble', self::knowledgeBlock($beds['Muiri']));
        $this->assertStringContainsString('Kaida: Cold', $beds['Muiri']['relationships']);
        $this->assertStringContainsString('Lynly Star-Sung has crossed paths with Kaida before', self::knowledgeBlock($beds[self::LYNLY]));
        $this->assertStringContainsString('Kaida: Neutral', $beds[self::LYNLY]['relationships']);
        foreach ($beds as $npc => $t) {
            $this->assertTrue($t['gate']['relationship'], "{$npc}: core's line stays");
            $this->assertSame('', $t['command'], "{$npc}: she knows the name");
            $this->assertStringNotContainsString('never met', $t['nearby'] . $t['pers'], $npc);
        }
        $texts = array_map(fn($t) => self::knowledgeBlock($t), $beds);
        $this->assertCount(4, array_unique($texts), implode("\n", $texts));
        foreach ($texts as $text) $this->assertDoesNotMatchRegularExpression('/\d/', $text, 'feelings, never numbers');
        $this->assertNoDbFailures();
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
    }

    public function testTheTestBedsKnowHimAsFarAsTheBondGoesAndTheFloorHolds(): void
    {
        $aela = $this->turn(self::AELA, 'jorrvaskr', 'Good hunt today.');
        $ashe = $this->turn('Ashe', 'jorrvaskr', 'Ready to move out?');
        $muiri = $this->turn('Muiri', 'markarth', 'How is the shop?');
        $lynly = $this->turn(self::LYNLY, 'dragonbridge', 'Play something cheerful.');

        // Aela, a friend: his name, his story, their bond in core's block
        $this->assertSame(['bio' => true, 'relationship' => true, 'level' => 'personal'], array_intersect_key($aela['gate'], ['bio' => 1, 'relationship' => 1, 'level' => 1]));
        $this->assertSame('Kaida (Male Nord): ' . self::BIO . ' (Aela the Huntress knows this person)', $aela['nearby'],
            "his story, and not core's 'never talked to' stranger hint");
        $this->assertStringContainsString('Kaida: Friendly', $aela['relationships']);
        $this->assertStringContainsString('Aela the Huntress knows Kaida well', self::knowledgeBlock($aela));
        $this->assertStringNotContainsString('Companions', self::knowledgeBlock($aela), 'no rumours about someone she knows well');
        $this->assertSame('', $aela['command']);
        // Ashe, an acquaintance: the name, not the story, and what is said of him in Whiterun
        $this->assertSame(['bio' => false, 'relationship' => true, 'level' => 'personal'], array_intersect_key($ashe['gate'], ['bio' => 1, 'relationship' => 1, 'level' => 1]));
        $this->assertSame('Kaida (Male Nord) (Ashe knows this person)', $ashe['nearby']);
        $this->assertStringContainsString('Kaida: Acquaintance', $ashe['relationships']);
        $this->assertStringContainsString('Ashe knows Kaida by name and a few shared words', self::knowledgeBlock($ashe));
        $this->assertStringContainsString('Ashe has heard that Kaida runs with the Companions of Jorrvaskr.', self::knowledgeBlock($ashe));
        // Muiri met him (an acquaintance) in the Reach, one hold from Jorrvaskr: a rumour, named
        $this->assertSame('personal', $muiri['gate']['level']);
        $this->assertStringContainsString('Muiri has heard that Kaida runs with the Companions', self::knowledgeBlock($muiri));
        // Lynly: a stranger in Haafingar, where what is said of a Companion is heard (two holds out)
        $this->assertStrangerTo($lynly, self::LYNLY, 'Lynly', 'renowned');

        // Four bonds, four readings of who he is
        $texts = array_map(fn($t) => self::knowledgeBlock($t), [$aela, $ashe, $muiri, $lynly]);
        $this->assertCount(4, array_unique($texts), implode("\n", $texts));
        foreach ($texts as $text) $this->assertDoesNotMatchRegularExpression('/\d/', $text, 'feelings, never numbers');

        // The floor. Muiri (toxic) turns on him: cold, but she knows exactly who he is
        $this->setCoreAffinity('Muiri', -40);
        $muiri = $this->turn('Muiri', 'markarth', 'I only came for the potions.');
        $this->assertSame(['bio' => false, 'relationship' => true, 'level' => 'lapsed'], array_intersect_key($muiri['gate'], ['bio' => 1, 'relationship' => 1, 'level' => 1]));
        $this->assertStringContainsString('Muiri knows Kaida only as trouble', self::knowledgeBlock($muiri));
        $this->assertStringNotContainsString('Companions', self::knowledgeBlock($muiri), 'she wants none of his fame');
        $this->assertStringContainsString('Kaida: Cold', $muiri['relationships']);
        $this->assertSame('', $muiri['command'], 'she knows the name');
        $this->assertSame('Kaida (Male Nord) (Muiri has met this person before)', $muiri['nearby']);
        // Ashe drifts back to indifference: she still remembers him
        $this->setCoreAffinity('Ashe', 0);
        $ashe = $this->turn('Ashe', 'jorrvaskr', 'Still here?');
        $this->assertSame('lapsed', $ashe['gate']['level']);
        $this->assertEqualsWithDelta(20.0, $this->dynamics('Ashe')[RelDynGating::KEY]['peak_core_aff'], 1e-6, 'the peak she held');
        $this->assertStringContainsString('Ashe has met Kaida before and remembers the name', self::knowledgeBlock($ashe));
        $this->assertStringContainsString('Kaida: Neutral', $ashe['relationships']);
        $this->assertSame('', $ashe['command']);
        // Aela's story knowledge stays when a quarrel drops the bond to an acquaintance's
        $this->setCoreAffinity(self::AELA, 10);
        $aela = $this->turn(self::AELA, 'jorrvaskr', 'About earlier...');
        $this->assertTrue($aela['gate']['bio'], 'what she learned stays learned');
        $this->assertStringContainsString(self::BIO, $aela['nearby']);
        $this->assertNoDbFailures();
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
    }

    public function testWithGatingOffOrNoGateCoreBehavesAsItAlwaysDid(): void
    {
        // RelDyn's prompt gating off: core decides, and RelDyn's text agrees with core naming him
        $this->storeGating(['enabled' => false]);
        $lynly = $this->turn(self::LYNLY, 'dragonbridge', 'Play something cheerful.');
        $this->assertNull($lynly['gate']);
        $this->assertStringContainsString('Kaida: Neutral', $lynly['relationships']);
        $this->assertStringContainsString('Lynly Star-Sung never talked to Kaida before', $lynly['nearby'], "core's own hint");
        $this->assertStringNotContainsString(self::BIO, $lynly['nearby'], 'bio_known_by_all is off');
        $this->assertStringContainsString('Lynly Star-Sung has barely met Kaida', self::knowledgeBlock($lynly));
        $this->assertSame('', $lynly['command']);
        // Core's bio_known_by_all is core's switch while gating is off...
        pg_query($this->db->link, "UPDATE core_player SET value = 'true' WHERE id = 'bio_known_by_all'");
        $this->assertStringContainsString(self::BIO, $this->turn(self::LYNLY, 'dragonbridge', 'Again?')['nearby']);
        // ... and RelDyn decides who knows his story while it is on
        $this->storeGating([]);
        $this->assertStringNotContainsString(self::BIO, $this->turn(self::LYNLY, 'dragonbridge', 'Once more?')['nearby']);

        // RelDyn not installed (no gate registered): core's blocks as they always were
        unset($GLOBALS['CHIM_PLAYER_KNOWLEDGE_GATES']);
        $this->assertNull(chimPlayerKnowledgeFor(self::LYNLY));
        $GLOBALS['HERIKA_NAME'] = self::LYNLY;
        $GLOBALS['gameRequest'] = ['inputtext', (string) $this->realTs, (string) $this->gamets, 'Kaida: Hello.'];
        $this->clearCaches();
        DataLastInfoFor('', -2, true);
        $this->assertStringContainsString(self::BIO, (string) $GLOBALS['PROMPT_NEARBY_SECTIONS']);
        $this->assertStringContainsString('Lynly Star-Sung never talked to Kaida before', (string) $GLOBALS['PROMPT_NEARBY_SECTIONS']);
        $this->assertStringContainsString('Kaida: Neutral', RelationshipManager::buildContext(self::LYNLY, [self::LYNLY, self::PLAYER]));
        $this->assertNoDbFailures();
    }
}
