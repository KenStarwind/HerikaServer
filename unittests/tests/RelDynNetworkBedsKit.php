<?php declare(strict_types=1);

/**
 * Shared fixture of the cascade-network and npc-npc-tiered-eval tests (not a test itself: no
 * "Test" suffix). The four standing test beds (feedback_reldyn_testbeds): Aela the Huntress, Ashe
 * (Serene's hand-set, spoiler-free vector: never read, nothing of her story anywhere), Muiri (toxic,
 * nudged fearful) and Lynly Star-Sung (the shy bard), plus three ordinary core NPCs with the
 * committed seed's reads (Farkas, Ysolda, Sven), on CHIM 3.4.1 core-shaped rows, a throwaway
 * schema per kit, the real hooks as main.php runs them (prerequest -> functions -> context_pre ->
 * context -> postrequest), the real eval producer and worker with the LLM stubbed at the connector
 * boundary. No LLM call is ever made.
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
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', to_jsonb(\$2::int)) WHERE npc_name = \$1", [$npc, $aff]);
    }

    public function id(string $npc): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return intval($r['id']);
    }
}
