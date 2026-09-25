<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynExclusivityPgDb
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
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
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
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Natural exclusivity (decisions 2026-09-24 §17) and fulfillment per relationship pair (§11), end
 * to end with the four test beds (feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's
 * hand-set vector, never read), Muiri and Lynly Star-Sung, on CHIM 3.4.1 core-shaped rows, through
 * the real hooks (prerequest -> context_pre -> context -> postrequest). The same suitor (Mikael,
 * the Bannered Mare's bard) flirts with each of them in an NPC-to-NPC exchange CHIM logs (his line
 * in the eventlog, then her rechat turn): before a title, after one, and after the player has
 * neglected them. No LLM call. Feelings, never numbers, in front of the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynExclusivityTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const SUITOR = 'Mikael';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 200;   // game day of the hello; every exchange is by day (no full-moon night)
    private const AELA = 'Aela the Huntress';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const HOME = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 1:00 PM, 7th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynExclusivityPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => key => felt text the context hook put in front of the LLM, per turn label */
    private array $felt = [];
    /** the exclusivity blocks put in front of the LLM for the NPC-NPC exchanges */
    private array $exchanges = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_excl_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns)
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynExclusivityPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS',
                     'RECHAT_PREVIOUS_SPEAKER', 'RECHAT_REQUEST_PAYLOAD'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdexclbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_excl_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        // Shipped defaults, stored as the config page stores them
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
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

    /**
     * Core rows: the four beds (the player's entry at affinity 60, type platonic: no title yet),
     * the suitor Mikael, voice types, placeholder templates and the committed seed's reads.
     */
    private function seed(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'platonic'], self::SUITOR => ['aff' => 10, 'type' => 'neutral']]])]);
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
        // The suitor (core's NPC-NPC eval has judged him toward no one yet)
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
            [self::SUITOR, 'male', 'ImperialRace', '', 'Roleplay as Mikael', '', json_encode(['skills' => $all]),
             json_encode(['class' => ['name' => 'Bard', 'formid' => '0x0001317f'], 'factions' => [], 'relationships' => []])]);
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, string $people, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people, '', $state]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /**
     * One player line to $npc through the real hooks at $gamets, logged as core logs it (input
     * row, then the reply). The RELDYN_* globals live for the whole request, as in main.php
     * (prerequest hands the diary trigger to postrequest).
     */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
        }
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Evening: everyone at home with the player; their dynamics, after the hello. */
    private function hello(): array
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met, love.', self::at(self::N0, 18.0) + 600 * $i++, 'hello');
        $d = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $this->assertSame('read', $d[$npc]['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d[$npc]['_core_rel_type'], "{$npc}: a partner");
        }
        return $d;
    }

    /** Edit $npc's stored RelDyn state (as the NPC editor would). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /** $minutes of play (core 'request' rows 5 real seconds apart): the play clock's cooldowns run on it. */
    private function play(int $fromGamets, float $minutes): int
    {
        $step = 5 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        $n = (int) ceil($minutes * 12);
        pg_query_params($this->db->link,
            "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
             SELECT 'request', '', 'web', \$1::bigint + i * \$2::bigint, 0, \$1::bigint + i * \$2::bigint, '', '' FROM generate_series(1, \$3) AS i",
            [(string) $fromGamets, (string) $step, (string) $n]);
        return $fromGamets + $n * $step;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The suitor's line to $npc (logged as core logs an NPC line), then her rechat turn through
     * the real hooks (core resolves the rechat's previous speaker after the prerequest hooks,
     * before the context hooks). Returns the exclusivity block the context hook put in front of
     * the LLM (the <subtext> "with Mikael"), or null.
     */
    private function flirt(string $npc, string $line, int $gamets): ?string
    {
        $this->event('chat', self::SUITOR . ": {$line} (talking to {$npc})", $gamets, $this->home(), 'emitted');
        $request = ['rechat', (string) $this->realTs, (string) ($gamets + 20), json_encode(['speaker' => self::SUITOR, 'listener_hint' => $npc])];
        return $this->npcRequest($npc, $request, $gamets + 20, ['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php']);
    }

    /** One NPC-to-NPC request for $npc through $hooks; returns the exclusivity block or null. */
    private function npcRequest(string $npc, array $request, int $gamets, array $hooks): ?string
    {
        $block = null;
        foreach ($hooks as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::SUITOR . ')', $gamets, $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::SUITOR;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($request[0] === 'rechat' && $hook !== 'prerequest.php') $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = self::SUITOR;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                foreach ((array) $GLOBALS['contextDataFull'] as $m) {
                    if (str_contains((string) ($m['content'] ?? ''), 'right now, with ' . self::SUITOR)) $block = (string) $m['content'];
                }
            }
            RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
        if ($block !== null) $this->exchanges[] = $block;
        return $block;
    }

    /**
     * The hello (each bed meets the player at the Mare), then the editor sets who the story says
     * they are where their read does not: Muiri volatile (maturity 30, a toxic attachment), Lynly
     * anxious. Aela keeps her read (secure-leaning), Ashe her hand-set vector.
     */
    private function meet(int $day = self::N0): void
    {
        $this->event('infoloc', self::HOME, self::at($day, 12.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met.', self::at($day, 12.0) + 600 * $i++, 'hello');
        $this->editDynamics('Muiri', function (array &$d): void {
            $d['dimensions']['maturity']['x'] = 30.0;
            $d['dimensions']['maturity']['baseline'] = 30.0;
            $d['profile_overrides']['attachment_style'] = 'toxic';
        });
        $this->editDynamics('Lynly Star-Sung', function (array &$d): void { $d['profile_overrides']['attachment_style'] = 'anxious'; });
    }

    /** Her feelings for the player, as the editor would set them: passion (points) and a well-covered player pair at $gamets. */
    private function feelings(string $npc, float $passion, int $gamets): void
    {
        $this->editDynamics($npc, function (array &$d) use ($passion, $gamets): void {
            RelationshipDynamics::setPassion($d, $passion);
            $s = RelDynFulfillment::pairState($d);
            $s['lv'] = array_map(fn() => 2.25, (array) $s['lv']);   // three quarters of target_units on every need
            $s['gamets'] = $gamets;
            RelDynFulfillment::setPairState($d, RelDynFulfillment::PLAYER, $s);
        });
    }

    private function pullOf(string $npc, int $gamets): array
    {
        return RelDynExclusivity::pull($this->dynamics($npc), (float) $gamets);
    }

    /** The pull toward the player Mikael met at her last exchange with him (what the context hook steered by). */
    private function metPull(string $npc): float
    {
        return floatval(RelDynExclusivity::suitor($this->dynamics($npc), self::SUITOR)['pull_seen'] ?? -1.0);
    }

    /** The felt text of $band in $style for $npc, as the context hook fills it. */
    private static function feltText(string $band, string $style, string $npc, string $someone): string
    {
        return strtr(RelDynExclusivity::configDefaults()['felt_text'][$band][$style],
            ['{NAME}' => $npc, '{SUITOR}' => self::SUITOR, '{PLAYER}' => self::PLAYER, '{SOMEONE}' => $someone]);
    }

    private function interestIn(string $npc, int $gamets): float
    {
        $e = RelDynExclusivity::suitor($this->dynamics($npc), self::SUITOR);
        return $e === null ? 0.0 : RelDynExclusivity::interestAt($e, (float) $gamets);
    }

    /** Core's relationships.Player.type for $npc (the title), as core's own eval would write it. */
    private function setCoreType(string $npc, string $type): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][self::PLAYER]['type'] = $type;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
    }

    /** No digit in anything put in front of the LLM for the exchanges. */
    private function assertFeelingsNotNumbers(): void
    {
        $this->assertNotSame([], $this->exchanges);
        foreach ($this->exchanges as $block) $this->assertDoesNotMatchRegularExpression('/\d/', $block);
    }

    private const EXPECTED_STYLE = ['Aela the Huntress' => 'plain', 'Ashe' => 'cool', 'Muiri' => 'sharp', 'Lynly Star-Sung' => 'flustered'];

    // ------------------------------------------------------------------ before a title

    /**
     * No title yet, the same feelings for the player in all four (passion 45, core affinity 60,
     * a well-covered player pair): Mikael's flirt meets a pull that already holds ("not official,
     * but I'm not entertaining anyone else"), each turning him aside in her own way: Aela plainly,
     * Ashe goes cool, the volatile Muiri with an edge, the anxious Lynly flustered. Without a
     * title nobody names the player. Who they are sets how strong the pull is: Muiri's
     * volatility loosens it most, and the weaker the pull, the more his attention gets through.
     */
    public function testBeforeATitleEachTurnsTheSameSuitorAsideInHerOwnWay(): void
    {
        $this->meet();
        $t = self::at(self::N0, 14.0);
        $pull = $blocks = $interest = [];
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $this->feelings($npc, 45.0, $t);
            $at = $t + 600 * ($k + 1);
            $blocks[$npc] = $this->flirt($npc, 'You look lovely today. Share a drink with me?', $at);
            $pull[$npc] = $this->metPull($npc);
            $interest[$npc] = $this->interestIn($npc, $at + 20);
        }
        $untitled = RelDynExclusivity::configDefaults()['someone_untitled'];
        foreach (self::EXPECTED_STYLE as $npc => $style) {
            $band = RelDynExclusivity::bandOf($pull[$npc]);
            $this->assertContains($band, [RelDynExclusivity::BAND_TAKEN, RelDynExclusivity::BAND_DEVOTED], "{$npc}: holds at {$pull[$npc]}");
            $this->assertFalse($this->pullOf($npc, $t)['titled'], $npc);
            $this->assertSame($style, RelDynExclusivity::style($this->dynamics($npc)), $npc);
            $this->assertNotNull($blocks[$npc], "{$npc}: steered");
            $this->assertStringContainsString(self::feltText($band, $style, $npc, $untitled), $blocks[$npc], "{$npc}: her own way");
            $this->assertStringContainsString('someone they have not named out loud', $blocks[$npc], "{$npc}: not official");
            $this->assertStringNotContainsString(self::PLAYER, $blocks[$npc], "{$npc}: no title, no name");
            $this->assertGreaterThan(0.0, $interest[$npc], "{$npc}: his attention registers");
            $this->assertLessThan(RelDynExclusivity::configDefaults()['interest_per_move'], $interest[$npc], "{$npc}: damped");
        }
        // Who she is: the same feelings, and the volatile Muiri's pull is the loosest
        $disposition = [];
        foreach (array_keys(self::BEDS) as $npc) $disposition[$npc] = RelDynExclusivity::disposition($this->dynamics($npc));
        foreach (['Aela the Huntress', 'Ashe', 'Lynly Star-Sung'] as $npc) {
            $this->assertLessThan($disposition[$npc], $disposition['Muiri'], "Muiri's disposition below {$npc}'s " . json_encode($disposition));
            $this->assertLessThan($pull[$npc], $pull['Muiri'], "Muiri holds less than {$npc} " . json_encode($pull));
        }
        // Damping follows the pull: whoever holds tighter lets less of him in
        $order = array_keys($pull);
        usort($order, fn($a, $b) => $pull[$b] <=> $pull[$a]);
        for ($i = 1; $i < count($order); $i++) {
            $this->assertLessThanOrEqual($interest[$order[$i]] + 1e-9, $interest[$order[$i - 1]],
                'interest ' . json_encode($interest) . ' vs pull ' . json_encode($pull));
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ a title

    /**
     * A title strengthens the pull, it does not create it. With softer feelings (passion 30)
     * each bed's pull toward the player rises once core holds the romance, she now names the
     * player, and his second flirt gets less of her than the first. A title with no romantic
     * spark behind it (passion 0) holds nothing: no pull, no deflection.
     */
    public function testATitleStrengthensThePullButDoesNotCreateIt(): void
    {
        $this->meet();
        $t = self::at(self::N0, 14.0);
        $before = $after = $gain1 = $gain2 = [];
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $this->feelings($npc, 30.0, $t);
            $at = $t + 600 * ($k + 1);
            $this->flirt($npc, 'You look lovely today. Share a drink with me?', $at);
            $before[$npc] = $this->metPull($npc);
            $gain1[$npc] = $this->interestIn($npc, $at + 20);
        }
        foreach (array_keys(self::BEDS) as $npc) $this->setCoreType($npc, 'romantic');   // core's eval: a couple now
        $t2 = self::at(self::N0, 16.0);
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $at = $t2 + 600 * ($k + 1);
            $block = $this->flirt($npc, 'Come now, one dance with me. You are stunning.', $at);
            $after[$npc] = $this->metPull($npc);
            $gain2[$npc] = $this->interestIn($npc, $at + 20) - RelDynExclusivity::interestAt(
                ['interest' => $gain1[$npc], 'gamets' => $t + 600 * ($k + 1) + 20], (float) ($at + 20));
            // The same dynamics without the title: the title is the difference
            $d = $this->dynamics($npc);
            $titled = RelDynExclusivity::pull($d, (float) ($at + 20));
            $d['_core_rel_type'] = 'platonic';
            $plain = RelDynExclusivity::pull($d, (float) ($at + 20));
            $this->assertTrue($titled['titled'], $npc);
            $this->assertEqualsWithDelta(min(1.0, 1.35 * $plain['pull']), $titled['pull'], 1e-3, "{$npc}: the title multiplies the pull");
            $this->assertGreaterThan($before[$npc] + 0.05, $after[$npc], "{$npc}: the title strengthens it");
            $this->assertNotNull($block, $npc);
            $this->assertStringContainsString(self::PLAYER, $block, "{$npc}: a title, a name");
            $this->assertLessThan($gain1[$npc], $gain2[$npc], "{$npc}: less of his attention gets through");
        }

        // No spark, a title: nothing to be exclusive with
        $this->editDynamics('Ashe', function (array &$d): void { RelationshipDynamics::setPassion($d, 0.0); });
        $at = self::at(self::N0, 18.0);
        $block = $this->flirt('Ashe', 'Your eyes, Ashe. I could write a song about them.', $at);
        $this->assertTrue($this->pullOf('Ashe', $at + 20)['titled']);
        $this->assertSame(RelDynExclusivity::BAND_OPEN, RelDynExclusivity::bandOf($this->metPull('Ashe')), 'no spark: nothing holds');
        $d = $this->dynamics('Ashe');
        RelationshipDynamics::setPassion($d, 0.0);
        $this->assertSame(0.0, RelDynExclusivity::pull($d, (float) ($at + 20))['pull'], 'no spark at all: no pull, title or not');
        $this->assertNull($block, 'a title alone creates no pull');
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ neglect

    /**
     * Titled and devoted, then the player stays away (no word with any of them for days): the
     * pull loosens with the neglect and the unmet needs. Five days on, the anxious Lynly, who
     * minds absence most, is listening to Mikael, and so is the volatile Muiri; the independent
     * warriors, Aela and Ashe, still hold. Nine days on all four are drifting, and each lets in
     * more of his attention than when they were held.
     */
    public function testNeglectLoosensThePullAnxiousFirst(): void
    {
        $this->meet();
        foreach (array_keys(self::BEDS) as $npc) $this->setCoreType($npc, 'romantic');
        $t = self::at(self::N0, 14.0);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'I missed you.', $t + 600 * $i++, 'together');
        $held = $gainHeld = [];
        $t = self::at(self::N0, 16.0);
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $this->feelings($npc, 55.0, $t);
            $at = $t + 600 * ($k + 1);
            $block = $this->flirt($npc, 'You look lovely today. Share a drink with me?', $at);
            $held[$npc] = $this->metPull($npc);
            $gainHeld[$npc] = $this->interestIn($npc, $at + 20);
            $this->assertSame(RelDynExclusivity::BAND_DEVOTED, RelDynExclusivity::bandOf($held[$npc]), "{$npc}: " . $held[$npc]);
            $this->assertStringContainsString(self::PLAYER, (string) $block);
        }

        // Five game days without a word from the player
        $five = [];
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $at = self::at(self::N0 + 5, 12.0) + 600 * $k;
            $before = $this->interestIn($npc, $at);
            $block = $this->flirt($npc, 'Still alone? Walk with me by the river.', $at);
            $five[$npc] = ['pull' => $this->metPull($npc), 'block' => $block, 'gain' => $this->interestIn($npc, $at + 20) - $before];
            $this->assertLessThan($held[$npc], $five[$npc]['pull'], "{$npc}: absence loosens it");
        }
        $this->assertStringContainsString(self::feltText('drifting', 'flustered', 'Lynly Star-Sung', self::PLAYER),
            (string) $five['Lynly Star-Sung']['block'], 'Lynly drifts first: ' . json_encode($five));
        $this->assertStringContainsString(self::feltText('drifting', 'sharp', 'Muiri', self::PLAYER), (string) $five['Muiri']['block'],
            'the volatile Muiri too');
        foreach (['Aela the Huntress', 'Ashe'] as $npc) {
            $this->assertContains(RelDynExclusivity::bandOf($five[$npc]['pull']), [RelDynExclusivity::BAND_DEVOTED, RelDynExclusivity::BAND_TAKEN],
                "{$npc} still holds: " . json_encode($five[$npc]));
            $this->assertStringContainsString(self::PLAYER, (string) $five[$npc]['block']);
            $this->assertStringNotContainsString('far away', (string) $five[$npc]['block']);
            $this->assertGreaterThan($five['Lynly Star-Sung']['pull'], $five[$npc]['pull'], "{$npc} holds tighter than Lynly");
        }

        // Nine game days: every one of them is drifting, and more of him gets in than when held
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $at = self::at(self::N0 + 9, 12.0) + 600 * $k;
            $before = $this->interestIn($npc, $at);
            $block = $this->flirt($npc, 'You look lovely today. Share a drink with me?', $at);
            $this->assertLessThan($five[$npc]['pull'], $this->metPull($npc), "{$npc}: looser still");
            $this->assertNotNull($block, $npc);
            $this->assertMatchesRegularExpression('/far away|distant/', $block, "{$npc}: drifting");
            $this->assertGreaterThan($gainHeld[$npc], $this->interestIn($npc, $at + 20) - $before, "{$npc}: less damped");
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the other routes

    /**
     * A radiant round (no rechat) carries the move too, and core's NPC-NPC eval alone is enough:
     * with Mikael's core entry for Aela romantic ('crush'), her radiant line to him steers her
     * even without a romantic word in the log.
     */
    public function testARadiantRoundAndCoresNpcEvalCarryTheMove(): void
    {
        $this->meet();
        $t = self::at(self::N0, 14.0);
        $this->feelings(self::AELA, 45.0, $t);
        // His line in the log, then her radiant turn to him (the plugin's NPC-to-NPC round)
        $this->event('chat', self::SUITOR . ': Aela, you are a beautiful sight after a long day. (talking to Aela the Huntress)', $t + 600, $this->home(), 'emitted');
        $radiant = ['radiant', (string) $this->realTs, (string) ($t + 620), self::AELA . ': Mikael. (talking to Mikael)'];
        $block = $this->npcRequest(self::AELA, $radiant, $t + 620, ['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php']);
        $this->assertNotNull($block, 'the radiant round');
        $this->assertStringContainsString('deflects it', $block);
        $this->assertSame(1, RelDynExclusivity::suitor($this->dynamics(self::AELA), self::SUITOR)['moves']);

        // Hours later (past the move window), no romantic word; core's NPC-NPC eval has him sweet on her
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [self::SUITOR]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][self::AELA] = ['aff' => 40, 'type' => 'crush'];
        $t2 = self::at(self::N0 + 1, 13.0);
        $this->event('chat', self::SUITOR . ': Good hunting today? (talking to Aela the Huntress)', $t2, $this->home(), 'emitted');
        $plain = ['radiant', (string) $this->realTs, (string) ($t2 + 20), self::AELA . ': Mikael. (talking to Mikael)'];
        $this->assertNull($this->npcRequest(self::AELA, $plain, $t2 + 20, ['context_pre.php', 'context.php']), 'a plain chat is no move');
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [self::SUITOR, json_encode($ext)]);
        $block = $this->npcRequest(self::AELA, $plain, $t2 + 40, ['context_pre.php', 'context.php']);
        $this->assertNotNull($block, "core's NPC-NPC eval: he is sweet on her");
        $this->assertSame(1, RelDynExclusivity::suitor($this->dynamics(self::AELA), self::SUITOR)['moves'], 'no new line counted');

        // Jev gets the numbers
        $jev = RelDynJev::state(self::AELA, $this->dynamics(self::AELA), (float) ($t2 + 40));
        $this->assertIsFloat($jev['exclusivity']['pull']);
        $this->assertArrayHasKey(self::SUITOR, $jev['exclusivity']['suitor_interest']);
        $this->assertStringContainsString('exclusivity=', $jev['text']);
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ fulfillment per pair

    /**
     * Fulfillment per relationship pair through real storage (rulings §11): a blob stored before
     * the pairs is Aela's player pair and is rewritten as the pair container by the next save,
     * the state unchanged; Mikael's rechat with her is no day with the player; the player's own
     * word the next day is.
     */
    public function testThePrePairBlobMigratesAndPresenceIsThePairsOwn(): void
    {
        $this->meet();
        $state = RelDynFulfillment::pairState($this->dynamics(self::AELA));
        $this->assertIsArray($state);
        $this->editDynamics(self::AELA, function (array &$d) use ($state): void { $d['_fulfillment'] = $state; });   // the pre-pair shape
        $this->assertArrayNotHasKey('pairs', $this->dynamics(self::AELA)['_fulfillment']);

        $day = self::N0 + 1;
        $this->flirt(self::AELA, 'Good morning, Aela.', self::at($day, 10.0));
        $stored = $this->dynamics(self::AELA)['_fulfillment'];
        $this->assertSame(RelDynFulfillment::CONTAINER_VERSION, $stored['v']);
        $this->assertSame(['Player'], array_keys($stored['pairs']), 'migrated: one pair, the player');
        $this->assertEquals($state['w'], $stored['pairs']['Player']['w'], 'the same needs');
        $this->assertEquals($state['since'], $stored['pairs']['Player']['since']);
        $this->assertNotContains($day, $stored['pairs']['Player']['contact_days'] ?? [], "Mikael's rechat is no day with the player");

        $this->event('infoloc', self::HOME, self::at($day, 11.0), $this->home());
        $this->turn(self::AELA, 'Morning, Aela.', self::at($day, 11.0), 'morning');
        $this->assertContains($day, RelDynFulfillment::pairState($this->dynamics(self::AELA))['contact_days'], 'the player speaking to her is');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }
}
