<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Shared fixture of the cascade-network and npc-npc-tiered-eval tests (RelDynNpcFactsTestBedsPostgresTest
 * requires this file for it). The four standing test beds (feedback_reldyn_testbeds): Aela the Huntress,
 * Ashe (Serene's hand-set, spoiler-free vector: never read, nothing of her story anywhere), Muiri (toxic,
 * nudged fearful) and Lynly Star-Sung (the shy bard), plus three ordinary core NPCs with the committed
 * seed's reads (Farkas, Ysolda, Sven), on CHIM 3.4.1 core-shaped rows, a throwaway schema per kit, the real
 * hooks as main.php runs them (prerequest -> functions -> context_pre -> context -> postrequest), the real
 * eval producer and worker with the LLM stubbed at the connector boundary. No LLM call is ever made.
 */

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynNetworkPgDb
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
        if (!$res) $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
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

final class RelDynNetworkBedsKit
{
    const PLAYER = 'Kaida';
    const AELA = 'Aela the Huntress';
    const ASHE = 'Ashe';
    const MUIRI = 'Muiri';
    const LYNLY = 'Lynly Star-Sung';
    const FARKAS = 'Farkas';
    const YSOLDA = 'Ysolda';
    const SVEN = 'Sven';
    const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    const N0 = 210;   // game day of the first evening

    /** name => [template key, race, class, factions, skills, voice, gender] */
    const NPCS = [
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander', 'female'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null, 'female'],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager', 'female'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord', 'female'],
        'Farkas'            => ['farkas', 'NordRace', 'Warrior', ['CompanionsFaction'], ['twohanded' => 70, 'heavyarmor' => 55], 'sk_malenord', 'male'],
        'Ysolda'            => ['ysolda', 'NordRace', 'Merchant', [], ['speech' => 50], 'sk_femalenord', 'female'],
        'Sven'              => ['sven', 'NordRace', 'Bard', [], ['speech' => 40], 'sk_malenord', 'male'],
    ];
    const BEDS = ['Aela the Huntress', 'Ashe', 'Muiri', 'Lynly Star-Sung'];
    const CORE_ACTIONS = ['MoveTo', 'OpenInventory', 'OpenInventory2', 'Attack', 'Follow', 'Inspect', 'TravelTo', 'FollowPlayer',
        'ComeCloser', 'ReturnBackHome', 'GiveGoldTo', 'GiveItemTo', 'MakeFollower', 'EndConversation'];
    const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    public string $dsn;
    public string $schema;
    public RelDynNetworkPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private string $logName;
    public int $realTs = 1727000000;
    public int $llmCalls = 0;
    public int $evalCalls = 0;
    /** npc => label => key => the felt text the context hook put in front of the LLM */
    public array $felt = [];
    /** CACHE_PEOPLE for the next requests (everyone, by default) */
    public ?string $people = null;
    /** callable(string $exchange): array|null the eval LLM's reply for one exchange (see evalLlm()) */
    public $evalReply = null;

    public function __construct(string $dsn, string $tag, array $configOverrides = [])
    {
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) throw new RuntimeException('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_' . $tag . '_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (sess character varying(1024), speaker text, mood text, listener text,
            localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY)");
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
        // core's relationship worker (ext/relationship_system): prompts the evaluation reads, its audit trail
        pg_query($admin, "CREATE TABLE prompts (prompt_key text PRIMARY KEY, custom_prompt text, default_prompt text)");
        pg_query($admin, "CREATE TABLE audit_request (id serial PRIMARY KEY, request text, result text, connector text, url text)");
        pg_close($admin);

        $this->db = new RelDynNetworkPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'ENABLED_FUNCTIONS',
                     'RECHAT_PREVIOUS_SPEAKER', 'RECHAT_REQUEST_PAYLOAD'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdnetbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        $this->logName = sys_get_temp_dir() . '/reldyn_network_beds_test.log';
        Logger::setCustomLog($this->logName);
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'internal_weather_enabled' => false], $configOverrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    public function destroy(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
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

    /** What the error log caught since the kit was built (the test shows nothing unexpected went wrong). */
    public function errorLog(): string
    {
        return (string) @file_get_contents($this->errorLog);
    }

    public function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    // ------------------------------------------------------------------ world

    /**
     * Seed every NPC of NPCS. $relationships: npc name => [target => [aff, type]] (core's
     * extended_data.relationships; an NPC not listed has none). $speech: npc name => speechstyle.
     */
    public function seed(array $relationships, array $speech = []): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::NPCS as $name => [$key, $race, $class, $factions, $skills, $voice, $gender]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            $rels = [];
            foreach ((array) ($relationships[$name] ?? []) as $target => [$aff, $type]) $rels[$target] = ['aff' => $aff, 'type' => $type];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, speechstyle, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb)',
                [$name, $gender, $race, '', "Roleplay as {$name}", '', $speech[$name] ?? '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f, 'relationships' => $rels])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;   // Serene's hand-set vector, never read
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    public static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    public function home(): string
    {
        return '|' . implode('|', array_keys(self::NPCS)) . '|' . self::PLAYER . '|';
    }

    public function event(string $type, string $data, int $gamets, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $this->people ?? $this->home(), '', $state]);
    }

    public function speech(string $speaker, string $listener, string $text, int $gamets): void
    {
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', $speaker, $text, 'Breezehome', $listener, $this->realTs, $gamets, $gamets]);
    }

    /** $npc's request through the real hooks as main.php runs them. */
    public function request(string $npc, array $request, string $listener, string $label): void
    {
        foreach (['prerequest.php', 'functions.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to {$listener})", (int) $request[2], 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, 'default', $listener, $this->realTs, (int) $request[2], (int) $request[2]]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people ?? $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = $listener;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'functions.php') $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_ACTIONS;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            if ($hook !== 'functions.php') RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['ENABLED_FUNCTIONS']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** One player line to $npc at $gamets, logged as core logs it (input row, spoken line, then the reply). */
    public function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        $this->speech(self::PLAYER, $npc, $line, $gamets);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], self::PLAYER, $label);
    }

    /** The eval worker over everything queued (LLM stubbed). */
    public function worker(): array
    {
        $stats = RelDynEval::runWorker($this->evalLlm());
        if (($stats['failed'] ?? 0) !== 0) throw new RuntimeException('eval worker: ' . json_encode($stats));
        return $stats;
    }

    /**
     * The eval LLM at the connector boundary: $this->evalReply gets the text of THIS EXCHANGE and
     * returns the reply's fields (signals, tags, significance, summary...) laid over a quiet default.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "\nTASK:", $from) - $from));
            $mine = $this->evalReply !== null ? ($this->evalReply)($exchange) : null;
            return json_encode(array_replace_recursive([
                'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => [],
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => 0.1,
                'summary' => 'Small talk.',
                'romantic_intent' => 0,
                'charisma' => 'none',
            ], is_array($mine) ? $mine : []));
        };
    }

    // ------------------------------------------------------------------ reading state

    public function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** A key of plugin_extended_data.reldyn (the inboxes). */
    public function pluginKey(string $npc, string $key)
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'][$key] ?? null;
    }

    /** $npc's core relationship entry toward $target (extended_data.relationships). */
    public function core(string $npc, string $target = 'Player'): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['extended_data'], true)['relationships'][$target] ?? [];
    }

    public function coreAff(string $npc): int
    {
        return intval($this->core($npc)['aff'] ?? 0);
    }

    public function setCoreAff(string $npc, int $aff): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player}',
            coalesce(extended_data -> 'relationships' -> 'Player', '{\"type\": \"friend\"}'::jsonb) || jsonb_build_object('aff', \$2::int), true) WHERE npc_name = \$1", [$npc, $aff]);
    }

    public function id(string $npc): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return intval($r['id']);
    }
}

/**
 * cascade-network (MDD 10, lazy), end to end on a real PostgreSQL with the four test beds
 * (standing rule feedback_reldyn_testbeds: Aela the Huntress, Ashe (Serene's hand-set vector,
 * never read, nothing of her story anywhere), Muiri (toxic) and Lynly Star-Sung (the shy bard)),
 * plus Farkas (Aela's Companions brother), Ysolda (no bond to Aela) and Sven (a bond too weak to hear).
 *
 * The player mocks Aela's hunt in front of her shield-siblings; the eval (LLM stubbed at the
 * connector boundary) scores it a big loss. Through the real hooks and the real eval producer and
 * worker:
 *   - the loss is noted on Aela with the eval item and queued on every NPC who holds a bond to her
 *     (strongest first; a bond of 30 or less does not hear; no bond, nothing);
 *   - NOTHING is applied to any of them then (lazy: their core Player.aff is untouched);
 *   - each target applies it at ITS next prerequest, through its own social sensitivity curve, into
 *     core's relationships.Player.aff (not overwritten by the mirror refresh), with a one-shot felt
 *     line "heard what the player did to Aela", said once;
 *   - an ally takes her side (loses), an enemy the opposite (the inverted ripple), and the same
 *     news lands differently on each of them by who they are.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCascadeNetworkTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const YSOLDA = RelDynNetworkBedsKit::YSOLDA;
    private const SVEN = RelDynNetworkBedsKit::SVEN;
    private const INSULT = 'You call that a hunt? I mock your whole pack of hunters.';
    private const SUMMARY = 'The player mocked her hunt in front of her shield-siblings.';

    private RelDynNetworkBedsKit $kit;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->kit)) $this->kit->destroy();
    }

    private function world(array $config = [], int $aelaAff = 30, array $ysolda = ['Player' => [40, 'friend']]): RelDynNetworkBedsKit
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'cascade', $config);
        $this->kit->seed([
            self::AELA => ['Player' => [$aelaAff, 'platonic'], self::FARKAS => [70, 'friend'], self::LYNLY => [20, 'neutral'],
                           self::ASHE => [10, 'neutral'], self::MUIRI => [-30, 'rival']],
            self::FARKAS => ['Player' => [40, 'friend'], self::AELA => [60, 'friend']],
            self::LYNLY => ['Player' => [40, 'friend'], self::AELA => [45, 'friend']],
            self::ASHE => ['Player' => [40, 'friend'], self::AELA => [35, 'neutral']],
            self::MUIRI => ['Player' => [40, 'friend'], self::AELA => [-50, 'rival']],
            self::YSOLDA => $ysolda,
            self::SVEN => ['Player' => [40, 'friend'], self::AELA => [20, 'neutral']],
        ]);
        $this->kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        return $this->kit;
    }

    /** The LLM at the connector boundary: the insult is a big loss, a rescue a gain, anything else small talk. */
    private function scoreInsult(array $loss = ['affinity' => -30, 'trust' => -4], float $significance = 1.0): void
    {
        $this->kit->evalReply = function (string $exchange) use ($loss, $significance): ?array {
            if (str_contains($exchange, 'mock your whole pack')) {
                return ['signals' => $loss, 'tags' => ['insult'], 'significance' => $significance, 'summary' => self::SUMMARY,
                        'grievance' => ['flag' => true, 'kind' => 'disrespect', 'severity' => 2]];
            }
            return null;
        };
    }

    private function insultAela(int $gamets): void
    {
        $this->kit->turn(self::AELA, self::INSULT, $gamets, 'insult');
        $this->kit->worker();
    }

    private function inbox(string $npc): array
    {
        $v = $this->kit->pluginKey($npc, RelDynCascade::INBOX_KEY);
        return is_array($v) ? $v : [];
    }

    private function t(int $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    /** RELDYN_PROBE=1: print a scene's numbers (tuning aid, no effect on the test). */
    private function probe(string $label, array $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "
=== {$label}
" . json_encode($data, JSON_PRETTY_PRINT));
    }

    /** No error the code logged, no failed query. */
    private function assertClean(): void
    {
        $this->assertSame([], $this->kit->db->failures, 'failed SQL statements');
        $this->assertSame(0, $this->kit->llmCalls, 'no trait read');
        $log = $this->kit->errorLog();
        $this->assertStringNotContainsString('ERROR', $log, $log);
    }

    // ------------------------------------------------------------------ the story

    public function testInsultingAelaRipplesToHerFriendsLazilyAndEachHearsOnTheirNextTurn(): void
    {
        $this->world();
        $this->scoreInsult();
        $before = [];
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI, self::YSOLDA, self::SVEN] as $npc) $before[$npc] = $this->kit->coreAff($npc);

        $this->insultAela($this->t(0));

        // Aela took it: her own affinity fell, and the loss was noted and queued
        $aela = $this->kit->dynamics(self::AELA);
        $this->assertLessThan(30, $this->kit->coreAff(self::AELA), 'she lost affinity toward the player');
        $this->assertArrayNotHasKey(RelDynCascade::OUT_KEY, $aela, 'every noted ripple was queued and cleared');
        $lost = 30 - $this->kit->coreAff(self::AELA);
        $this->probe('insult', ['lost' => $lost, 'inboxes' => array_map(fn($n) => $this->inbox($n), [self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI])]);

        // Queued on everyone who holds a bond above 30 to her; nobody else
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertCount(1, $this->inbox($npc), "{$npc} was told");
        $this->assertSame([], $this->inbox(self::YSOLDA), 'no bond to her: nothing');
        $this->assertSame([], $this->inbox(self::SVEN), 'a bond of 20 is under the filter: nothing');
        $this->assertSame([], $this->inbox(self::AELA), 'not told of her own');
        $farkas = $this->inbox(self::FARKAS)[0];
        $this->assertSame(self::AELA, $farkas['source']);
        $this->assertSame('ally_hurt', $farkas['kind']);
        $this->assertSame(rtrim(self::SUMMARY, '.'), $farkas['anchor'], 'the reason anchor travels, cleaned of its full stop');
        // ripple = change x bond/100 x decay: Farkas (60) hears more than Lynly (45), more than Ashe (35); Muiri (-50) the inverse, weaker
        $ripple = fn(string $npc) => floatval($this->inbox($npc)[0]['delta']);
        $this->assertEqualsWithDelta(-$lost * 0.60 * 0.3, $ripple(self::FARKAS), 0.6);
        $this->assertLessThan($ripple(self::LYNLY), $ripple(self::FARKAS), 'a closer friend hears more (more negative)');
        $this->assertLessThan($ripple(self::ASHE), $ripple(self::LYNLY));
        $this->assertGreaterThan(0.0, $ripple(self::MUIRI), 'an enemy of hers is glad: the ripple is inverted');
        $this->assertSame('rival_hurt', $this->inbox(self::MUIRI)[0]['kind']);
        $this->assertLessThan(abs($ripple(self::FARKAS)), abs($ripple(self::MUIRI)), 'and weaker (enemy_mult)');

        // LAZY: not one of them has been touched
        foreach ($before as $npc => $aff) $this->assertSame($aff, $this->kit->coreAff($npc), "{$npc}: core affinity untouched until her next turn");
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) {
            $this->assertArrayNotHasKey(RelDynCascade::APPLIED_KEY, $this->kit->dynamics($npc), $npc);
        }

        // Farkas's next turn: the ripple lands in core's Player.aff, through his own curve, and he says so once
        $this->kit->turn(self::FARKAS, 'Well met, Farkas.', $this->t(2), 'hear');
        $farkasAff = $this->kit->coreAff(self::FARKAS);
        $this->assertLessThan($before[self::FARKAS], $farkasAff, 'Farkas now holds less for the player in core');
        $this->assertSame([], $this->inbox(self::FARKAS), 'the inbox was trimmed after the save');
        $d = $this->kit->dynamics(self::FARKAS);
        $this->assertCount(1, $d[RelDynCascade::APPLIED_KEY], 'the ripple is remembered by id');
        $this->assertSame($farkasAff, intval(round(RelationshipDynamics::getCoreAffinity($d))), 'the mirror holds what core holds');
        $this->assertArrayHasKey('cascade_ally_hurt', $this->kit->felt[self::FARKAS]['hear'], json_encode($this->kit->felt[self::FARKAS]['hear']));
        $line = $this->kit->felt[self::FARKAS]['hear']['cascade_ally_hurt'];
        $this->assertStringContainsString(self::AELA, $line);
        $this->assertStringContainsString('mocked her hunt', $line);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line, 'feelings, never numbers');
        // said once
        $this->kit->turn(self::FARKAS, 'Anything to drink?', $this->t(3), 'again');
        $this->assertArrayNotHasKey('cascade_ally_hurt', $this->kit->felt[self::FARKAS]['again']);
        $this->assertSame($farkasAff, $this->kit->coreAff(self::FARKAS), 'and nothing is applied twice');

        $this->assertClean();
    }

    public function testTheSameNewsLandsDifferentlyOnEachOfThemByWhoTheyAre(): void
    {
        $this->world();
        $this->scoreInsult();
        $before = [self::FARKAS => 40, self::LYNLY => 40, self::ASHE => 40, self::MUIRI => 40];
        $this->insultAela($this->t(0));
        $raw = [];
        foreach (array_keys($before) as $npc) $raw[$npc] = floatval($this->inbox($npc)[0]['delta']);
        foreach (array_keys($before) as $i => $npc) $this->kit->turn($npc, 'Good evening.', $this->t(2 + $i), 'hear');

        $moved = [];
        foreach ($before as $npc => $aff) {
            $d = $this->kit->dynamics($npc);
            // whole points to core, the fraction waits in her pending delta: what she heard is both
            $moved[$npc] = round($this->kit->coreAff($npc) - $aff + floatval($d['_pending_aff_delta'] ?? 0), 3);
            $this->assertCount(1, $d[RelDynCascade::APPLIED_KEY], $npc);
            $this->assertSame([], $this->inbox($npc), $npc);
        }
        $this->probe('moved', ['raw' => $raw, 'moved' => $moved]);
        foreach ([self::FARKAS, self::LYNLY, self::ASHE] as $npc) {
            $this->assertLessThan(0, $moved[$npc], "{$npc} (an ally of hers) thinks less of the player: " . json_encode($moved));
            $this->assertArrayHasKey('cascade_ally_hurt', $this->kit->felt[$npc]['hear'], $npc);
        }
        $this->assertGreaterThan(0, $moved[self::MUIRI], 'Muiri (her enemy) is not sorry: ' . json_encode($moved));
        $this->assertArrayHasKey('cascade_rival_hurt', $this->kit->felt[self::MUIRI]['hear']);
        $this->assertStringContainsString('not sorry', $this->kit->felt[self::MUIRI]['hear']['cascade_rival_hurt']);
        // Three allies, three different bonds and curves, three different drops; the same word through each one's curve
        $this->assertCount(3, array_unique([$moved[self::FARKAS], $moved[self::LYNLY], $moved[self::ASHE]]), json_encode($moved));
        $kept = fn(string $npc) => $moved[$npc] / $raw[$npc];
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) {
            $this->assertGreaterThan(0.0, $kept($npc), "{$npc}: the curve damps, never flips");
            $this->assertLessThan(1.0, $kept($npc), "{$npc}: a rumour is never louder than the news");
        }
        $this->assertLessThan($kept(self::LYNLY) - 0.05, $kept(self::ASHE), 'Ashe (guarded, stoic-leaning) takes the same word in less than Lynly (the open-hearted bard)');
        $this->assertClean();
    }

    public function testASmallChangeDoesNotRipple(): void
    {
        $this->world();
        $this->scoreInsult(['affinity' => -3], 0.2);
        $this->insultAela($this->t(0));
        $this->assertLessThan(30, $this->kit->coreAff(self::AELA), 'she did feel it');
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertSame([], $this->inbox($npc), $npc);
        $this->assertClean();
    }

    public function testADefiningMomentRipplesFromAMuchSmallerChange(): void
    {
        $this->world();
        // a loss under the ordinary threshold, at significance 0.9: the moment people retell
        $this->scoreInsult(['affinity' => -14], 0.9);
        $this->insultAela($this->t(0));
        $lost = 30 - $this->kit->coreAff(self::AELA);
        $this->assertGreaterThanOrEqual(5, $lost);
        $this->assertLessThan(15, $lost, 'under the ordinary threshold');
        $this->assertCount(1, $this->inbox(self::FARKAS), 'but a defining moment carries it');
        $this->assertTrue($this->inbox(self::FARKAS)[0]['defining']);
        $this->assertClean();
    }

    public function testTheSameLossOfAnOrdinaryExchangeStaysWithHer(): void
    {
        $this->world();
        $this->scoreInsult(['affinity' => -14], 0.3);   // the same loss, an everyday exchange
        $this->insultAela($this->t(0));
        $this->assertGreaterThanOrEqual(5, 30 - $this->kit->coreAff(self::AELA));
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertSame([], $this->inbox($npc), $npc);
        $this->assertClean();
    }

    public function testARescueRipplesWarmthToWhoLovesHer(): void
    {
        $this->world();
        $rescue = fn(string $e) => str_contains($e, 'burning barn')
            ? ['signals' => ['affinity' => 30, 'trust' => 6], 'tags' => ['rescue', 'help'], 'significance' => 0.6, 'summary' => 'The player pulled her out of the fire.']
            : null;
        $this->kit->evalReply = $rescue;
        $this->kit->turn(self::AELA, 'I pulled you out of the burning barn, remember?', $this->t(0), 'rescue');
        $this->kit->worker();
        $gain = $this->kit->coreAff(self::AELA) - 30;
        $this->probe('rescue', ['gain' => $gain, 'inboxes' => array_map(fn($n) => $this->inbox($n), [self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI])]);
        $this->assertGreaterThanOrEqual(5, $gain, 'the rescue moved her');
        // a rescue is a defining moment (significance 0.6 >= 0.4): the two who love her best hear it; the rest of the
        // ripple is under a point and not worth carrying
        $this->assertCount(1, $this->inbox(self::FARKAS));
        $this->assertSame('ally_helped', $this->inbox(self::FARKAS)[0]['kind']);
        $this->assertTrue($this->inbox(self::FARKAS)[0]['defining']);
        $this->assertCount(1, $this->inbox(self::LYNLY));
        $this->assertSame([], $this->inbox(self::MUIRI), 'the inverted ripple of an enemy at this size is under a point');
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $this->assertGreaterThan(40, $this->kit->coreAff(self::FARKAS), 'Farkas thinks better of the player');
        $this->assertStringContainsString('thinks better', $this->kit->felt[self::FARKAS]['hear']['cascade_ally_helped']);
        $this->assertStringContainsString('pulled her out of the fire', $this->kit->felt[self::FARKAS]['hear']['cascade_ally_helped']);
        $this->assertClean();
    }

    public function testSomeoneWhoHasNotMetThePlayerStillHearsButHasNothingToSayToThem(): void
    {
        // Ysolda holds a bond to Aela (60) and has never spoken to the player (affinity 0, a stranger's context tier)
        $this->world([], 30, ['Player' => [0, 'neutral'], self::AELA => [60, 'friend']]);
        $this->scoreInsult();
        $this->insultAela($this->t(0));
        $this->assertCount(1, $this->inbox(self::YSOLDA), 'the word reaches her');
        $this->kit->turn(self::YSOLDA, 'Hello there.', $this->t(2), 'hear');
        $this->assertLessThan(0, $this->kit->coreAff(self::YSOLDA), 'and counts for something: hearsay');
        $this->assertSame([], $this->inbox(self::YSOLDA));
        $this->assertCount(1, $this->kit->dynamics(self::YSOLDA)[RelDynCascade::APPLIED_KEY]);
        $this->assertSame([], array_filter(array_keys($this->kit->felt[self::YSOLDA]['hear']), fn($k) => str_starts_with((string) $k, 'cascade_')),
            'a stranger does not greet the player with it: nothing is kept to say');
        $this->assertArrayNotHasKey(RelDynCascade::FELT_KEY, $this->kit->dynamics(self::YSOLDA));
        $this->assertClean();
    }

    public function testARippleQueuedTwiceLandsOnce(): void
    {
        $this->world();
        $this->scoreInsult();
        $this->insultAela($this->t(0));
        $item = $this->inbox(self::FARKAS)[0];
        // a crash between queueing and the source's save: the same ripple is appended again
        RelDynStorage::appendItem($this->kit->id(self::FARKAS), RelDynCascade::INBOX_KEY, $item);
        $this->assertCount(2, $this->inbox(self::FARKAS));
        $reference = $this->kit->coreAff(self::FARKAS);
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $once = $this->kit->coreAff(self::FARKAS);
        $this->assertLessThan($reference, $once);
        $this->assertCount(1, $this->kit->dynamics(self::FARKAS)[RelDynCascade::APPLIED_KEY], 'one id');
        $this->assertSame([], $this->inbox(self::FARKAS));
        $this->assertClean();
    }

    public function testWithTheNetworkOffNothingIsQueuedAndNothingPendingIsApplied(): void
    {
        $this->world(['cascade_network_enabled' => false]);
        $this->scoreInsult();
        $this->insultAela($this->t(0));
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertSame([], $this->inbox($npc), $npc);
        $this->assertArrayNotHasKey(RelDynCascade::OUT_KEY, $this->kit->dynamics(self::AELA), 'nothing is held back to ripple later');
        // a ripple already waiting (queued while it was on) is not applied while it is off
        RelDynStorage::appendItem($this->kit->id(self::FARKAS), RelDynCascade::INBOX_KEY,
            ['id' => 'abc', 'source' => self::AELA, 'delta' => -5.0, 'kind' => 'ally_hurt', 'gamets' => 1.0, 'anchor' => null, 'defining' => false]);
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $this->assertSame(40, $this->kit->coreAff(self::FARKAS));
        $this->assertCount(1, $this->inbox(self::FARKAS), 'it waits');
        $this->assertClean();
    }

    public function testAnEditorLockedNpcKeepsWhatTheEditorPinned(): void
    {
        $this->world();
        $this->scoreInsult();
        pg_query_params($this->kit->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships_locked}', 'true'::jsonb) WHERE npc_name = \$1", [self::FARKAS]);
        $this->insultAela($this->t(0));
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $this->assertSame(40, $this->kit->coreAff(self::FARKAS), 'manual edits are protected, ripple or not');
        $this->assertSame([], $this->inbox(self::FARKAS), 'and it does not come back');
        $this->assertClean();
    }
}
