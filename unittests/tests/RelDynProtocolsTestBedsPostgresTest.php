<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynProtocolsBedsPgDb
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
 * The P3 protocols end to end with the four test beds (standing rule feedback_reldyn_testbeds):
 * Aela the Huntress, Ashe (Serene's hand-set vector, never read), Muiri and Lynly Star-Sung, each
 * the player's partner (core Player.type romantic, affinity 60), on CHIM 3.4.1 core-shaped rows
 * (a core 'death' row in the eventlog, core's NPC-to-NPC relationships in extended_data,
 * moods_issued, itemfound gift rows) and the committed seed's reads, through the real hooks
 * (prerequest -> context -> postrequest), the real eval producer and worker (the LLM stubbed at
 * the connector boundary) and the calendar scan. No LLM call. Feelings, never numbers, reach the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynProtocolsTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the hello
    private const AELA = 'Aela the Huntress';
    private const DECEASED = 'Vilkas';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    /**
     * Core's NPC-to-NPC relationships (extended_data.relationships, core units): Vilkas is Aela's
     * shield-brother in the Circle, Muiri's lover, a friend of Lynly's, a stranger to Ashe; Aela
     * has the Circle behind her (Farkas, Skjor); Lynly looks up to Aela.
     */
    private const BONDS = [
        'Aela the Huntress' => ['Vilkas' => ['aff' => 70, 'type' => 'platonic'], 'Farkas' => ['aff' => 80, 'type' => 'familial'],
                                'Skjor' => ['aff' => 65, 'type' => 'platonic']],
        'Ashe'              => ['Vilkas' => ['aff' => 5, 'type' => 'neutral']],
        'Muiri'             => ['Vilkas' => ['aff' => 85, 'type' => 'romantic']],
        'Lynly Star-Sung'   => ['Vilkas' => ['aff' => 40, 'type' => 'platonic'], 'Aela the Huntress' => ['aff' => 75, 'type' => 'platonic']],
    ];
    private const HOME = '(Context location: Jorrvaskr ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynProtocolsBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => the mood she answers in (moods_issued), 'default' when unset */
    private array $moods = [];
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_proto' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog and moods_issued (data/table_moods_issued.sql)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE moods_issued (sess character varying(1024), speaker text, mood text, listener text,
            localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns)
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
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

        $this->db = new RelDynProtocolsBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdprotobeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_proto_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        $this->setConfig([]);
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

    /** Shipped defaults (+ $overrides), stored as the config page stores them. */
    private function setConfig(array $overrides): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows (partners: Player romantic, affinity 60, plus BONDS), voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic']] + self::BONDS[$name]])]);
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
        // Vilkas: a core row with no RelDyn state (the deceased of the grief tests)
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, gender, race, core, extended_data) VALUES ($1, $2, $3, $4, $5::jsonb)',
            [self::DECEASED, 'male', 'NordRace', 'Roleplay as Vilkas', json_encode(['relationships' => ['Muiri' => ['aff' => 85, 'type' => 'romantic']]])]);
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Jorrvaskr', 'Whiterun', 'Guild,', 1, 'WhiterunWorld')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, string $people, ?string $state = null, string $party = ''): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, party, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people, '', $party, $state]);
    }

    private function home(array $npcs = []): string
    {
        return '|' . implode('|', $npcs ?: array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /**
     * One player line to $npc through the real hooks at $gamets, logged as core logs it (input
     * row, then the reply and the mood it was said in). $people: who is around (default: everyone).
     */
    private function turn(string $npc, string $line, int $gamets, string $label, ?array $people = null): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $around = $this->home($people ?? []);
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $around);
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $around, 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, $this->moods[$npc] ?? 'default', self::PLAYER, $this->realTs, $gamets, $gamets]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $around;
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            }
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Core's Player.aff for $npc. */
    private function coreAffinity(string $npc): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rels = json_decode($r['extended_data'], true)['relationships'] ?? [];
        return intval(($rels['Player'] ?? $rels[self::PLAYER] ?? [])['aff'] ?? 0);   // core folds the name into "Player"
    }

    /** Core's Player.aff for $npc (as core's relationship eval writes it). */
    private function setCoreAffinity(string $npc, int $aff): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][isset($ext['relationships']['Player']) ? 'Player' : self::PLAYER]['aff'] = $aff;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
    }

    /** Evening in Jorrvaskr: everyone with the player. */
    private function hello(): void
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met, love.', self::at(self::N0, 18.0) + 600 * $i++, 'hello');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d['_core_rel_type'], "{$npc}: a partner");
        }
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

    /**
     * The eval LLM at the connector boundary: "kiss me" is open romantic pursuit (romantic_intent 3),
     * and so is the player's hands on her (her reply to a VR touch, tagged touch);
     * "your day" is quality time; "a gift for you" is a gift and nothing more; "sold your secret" is
     * the player's betrayal; anything else small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $kiss = str_contains($exchange, 'kiss me');
            $day = str_contains($exchange, 'your day');
            $gift = str_contains($exchange, 'a gift for you');
            $betrayal = str_contains($exchange, 'sold your secret');
            $touch = str_contains($exchange, 'hands on her');
            return json_encode([
                'signals' => ['affinity' => $day ? 2 : ($gift ? 1 : ($betrayal ? -20 : 0)), 'trust' => $betrayal ? -25 : ($day ? 2 : 0),
                              'comfort' => $day ? 3 : ($betrayal ? -10 : 0), 'respect' => 0, 'passion' => ($kiss || $touch) ? 3 : 0, 'maturity' => 0],
                'tags' => $touch ? ['touch'] : ($day ? ['quality_time'] : ($gift ? ['gift'] : ($betrayal ? ['betrayal'] : []))),
                'grievance' => $betrayal ? ['flag' => true, 'kind' => 'betrayal', 'severity' => 3] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $betrayal ? 0.9 : ($day ? 0.4 : 0.2),
                'romantic_intent' => ($kiss || $touch) ? 3 : 0,
                'summary' => $touch ? 'The player touched her.' : ($kiss ? 'The player pressed for a kiss.' : ($day ? 'They talked about her day.'
                    : ($gift ? 'The player handed her a gift.' : ($betrayal ? 'The player sold her secret to her enemies.' : 'Small talk.')))),
            ]);
        };
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** No digit in anything the felt steering put in front of the LLM. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
    }

    // ------------------------------------------------------------------ death, grief, divine intervention

    /**
     * Vilkas falls in Jorrvaskr (a core 'death' row: "Frost Troll has defeated Vilkas", everyone
     * around) and the next request routes it. Who grieves is who was bonded to him (core's NPC
     * relationships, at least a friend): Aela, Muiri, Lynly; not Ashe, to whom he was a stranger.
     * The fork reads the living bonds only (the dead anchor no one):
     *   Aela   the Circle behind her (Farkas, Skjor) -> redemption at once;
     *   Lynly  neither anchored nor alone -> the window; the player's trust alone cannot hold her,
     *          but Aela is in the room when she speaks -> redemption through Aela, said once;
     *   Muiri  only the player, and not enough -> the window; the player never comes, the game
     *          calendar runs out while he is with the others -> breaking, said once when he does.
     * The phases run on the game calendar x how long each bond has been in the player's world
     * (Aela 100 play hours, Muiri 150, Lynly 20): at the same moment they are in different
     * phases. Acute grief is held on comfort and warmth and lifts exactly. The widow's lock binds
     * Muiri (Vilkas was her lover) at core 70, not Lynly. Feelings, never numbers.
     */
    public function testTheCircleHoldsAelaNobodyComesForMuiri(): void
    {
        $this->hello();
        $hours = ['Aela the Huntress' => 100.0, 'Ashe' => 60.0, 'Muiri' => 150.0, 'Lynly Star-Sung' => 20.0];
        foreach ($hours as $npc => $h) {
            // the play time the player has known each (the play clock's credit, as the editor shows it)
            $this->editDynamics($npc, function (array &$d) use ($h): void { $d['_accumulated_time'] = $h * 3600.0; });
        }
        $before = [];
        foreach (array_keys(self::BEDS) as $npc) $before[$npc] = $this->dynamics($npc);

        $death = self::at(self::N0 + 1, 9.0);
        $this->event('death', 'Frost Troll has defeated ' . self::DECEASED, $death, $this->home() . self::DECEASED . '|');
        $this->turn('Lynly Star-Sung', 'Lynly, are you alright?', $death + 1500, 'after');

        $d = [];
        foreach (array_keys(self::BEDS) as $npc) $d[$npc] = $this->dynamics($npc);
        $why = json_encode(array_map(fn($x) => ['grief' => $x['_grief_bonds'] ?? null, 'window' => $x['_unstable_window'] ?? null,
            'arc' => $x['_divine_intervention_last_type'] ?? null], $d));
        // Who grieves
        foreach (['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertArrayHasKey(self::DECEASED, $d[$npc]['_grief_bonds'] ?? [], "{$npc} grieves {$why}");
            $this->assertSame((float) $death, floatval($d[$npc]['_grief_bonds'][self::DECEASED]['death_gamets']), "{$npc}: the death's game time");
        }
        $this->assertSame([], $d['Ashe']['_grief_bonds'] ?? [], 'Ashe barely knew him');
        $this->assertNull($d['Ashe']['_divine_intervention_last_type'] ?? null, 'no catastrophe for Ashe');
        // The fork
        $this->assertSame('redemption', $d[self::AELA]['_divine_intervention_last_type'] ?? null, "the Circle holds Aela {$why}");
        $this->assertNull($d[self::AELA]['_unstable_window'] ?? null, 'no window for Aela');
        $this->assertSame('redemption', $d['Lynly Star-Sung']['_unstable_window']['resolution'] ?? null, "Lynly: through Aela {$why}");
        $this->assertSame(self::AELA, $d['Lynly Star-Sung']['_unstable_window']['anchor'] ?? null);
        $this->assertStringContainsString('looked up when Aela the Huntress came', (string) ($this->felt['Lynly Star-Sung']['after']['crisis_resolved'] ?? ''),
            json_encode($this->felt['Lynly Star-Sung']['after']));
        $this->assertFalse((bool) ($d['Muiri']['_unstable_window']['resolved'] ?? true), "Muiri's window is open {$why}");
        $this->assertSame('calendar', $d['Muiri']['_unstable_window']['clock'] ?? null);
        // Maturity: the arcs rewrote Aela's and Lynly's (redemption), not yet Muiri's
        $this->assertGreaterThan(self::x($before[self::AELA], 'maturity') + 14.0, self::x($d[self::AELA], 'maturity'));
        $this->assertGreaterThan(self::x($before['Lynly Star-Sung'], 'maturity') + 14.0, self::x($d['Lynly Star-Sung'], 'maturity'));
        // The widow's lock: Muiri's lover, a long bond (weight 1.5): core 100 - 1.5 x 20
        $this->assertEqualsWithDelta(70.0, floatval($d['Muiri']['_widow_lock_ceiling'] ?? 100), 1e-6);
        $this->assertTrue($d['Muiri']['_grief_bonds'][self::DECEASED]['widow_lock']);
        foreach ([self::AELA, 'Lynly Star-Sung'] as $npc) $this->assertEqualsWithDelta(100.0, floatval($d[$npc]['_widow_lock_ceiling'] ?? 100), 1e-9, "{$npc}: a friend, no lock");
        // Acute grief, held: comfort -15 and warmth -10 toward every other bond; valence locked negative
        foreach (['Aela the Huntress', 'Muiri'] as $npc) {
            $this->assertEqualsWithDelta(-15.0, floatval($d[$npc]['_grief_held']['comfort'] ?? 0), 1e-6, $npc);
            $this->assertEqualsWithDelta(-10.0, floatval($d[$npc]['_grief_held']['warmth'] ?? 0), 1e-6, $npc);
            $this->assertLessThanOrEqual(-30.0, self::x($d[$npc], 'valence'), $npc);
        }

        // A day later he is with Ashe; Muiri never saw him. The calendar runs out on her window.
        $this->turn('Ashe', 'Ashe, walk with me.', $death + (int) round(25 * self::HOUR), 'next_day', ['Ashe']);
        $m = $this->dynamics('Muiri');
        $this->assertSame('breaking', $m['_unstable_window']['resolution'] ?? null, 'nobody came: ' . json_encode($m['_unstable_window'] ?? null));
        $this->assertSame('Brittle', $m['_plasticity_override'] ?? null);
        $this->assertLessThan(self::x($d['Muiri'], 'maturity') - 14.0, self::x($m, 'maturity'));
        $this->assertEqualsWithDelta(self::x($d['Muiri'], 'trust') / 2.0, self::x($m, 'trust'), 1e-6, 'trust halved');
        // Lynly's short bond (weight 0.2) is already past bargaining's start (9.6 game hours)
        $l = $this->dynamics('Lynly Star-Sung');
        $this->assertSame(2, intval($l['_grief_bonds'][self::DECEASED]['phase']), 'Lynly: bargaining');
        $this->assertEqualsWithDelta(100.0, floatval($l['_grief_bonds'][self::DECEASED]['memory_warmth'] ?? 0), 1e-6, 'the memory idealized');

        // He finds Muiri: how the window ended is said once, the arc stands, she grieves out loud
        $this->turn('Muiri', 'Muiri. I came as soon as I heard.', $death + (int) round(26 * self::HOUR), 'found');
        $this->turn('Muiri', 'Talk to me.', $death + (int) round(26.2 * self::HOUR), 'found2');
        $fm = $this->felt['Muiri']['found'];
        $this->assertStringContainsString('has decided to face things alone', (string) ($fm['crisis_resolved'] ?? ''), json_encode($fm));
        $this->assertStringContainsString('Something broke behind', (string) ($fm['arc'] ?? ''));
        $this->assertArrayNotHasKey('crisis_resolved', $this->felt['Muiri']['found2'], 'said once');
        $this->assertStringContainsString('shattered by the loss of Vilkas', (string) ($fm['grief_Vilkas'] ?? ''), 'Muiri grieves in public');
        $this->assertStringContainsString('in silence', (string) ($this->felt['Lynly Star-Sung']['after']['grief_Vilkas'] ?? ''), 'Lynly, steadied, grieves quietly');

        // Fifty game hours after: three phases for three bonds
        $t50 = $death + (int) round(50 * self::HOUR);
        foreach ([self::AELA, 'Muiri', 'Lynly Star-Sung'] as $i => $npc) $this->turn($npc, 'How are you holding up?', $t50 + 300 * $i, 't50');
        $phase = [];
        foreach ([self::AELA, 'Muiri', 'Lynly Star-Sung'] as $npc) $phase[$npc] = intval($this->dynamics($npc)['_grief_bonds'][self::DECEASED]['phase']);
        $this->assertSame(['Aela the Huntress' => 2, 'Muiri' => 1, 'Lynly Star-Sung' => 3], $phase, 'the same moment, three phases');

        // A month on (game calendar): Aela and Lynly carry a memorial; Muiri (weight 1.5) is still
        // integrating. The acute offsets are gone without residue for the two who are through.
        $t30 = $death + 30 * self::DAY;
        foreach ([self::AELA, 'Muiri', 'Lynly Star-Sung'] as $i => $npc) $this->turn($npc, 'It has been a month.', $t30 + 300 * $i, 't30');
        $end = [];
        foreach ([self::AELA, 'Muiri', 'Lynly Star-Sung'] as $npc) $end[$npc] = $this->dynamics($npc);
        foreach ([self::AELA => 70.0, 'Lynly Star-Sung' => 40.0] as $npc => $coreAff) {
            $g = $end[$npc]['_grief_bonds'][self::DECEASED];
            $this->assertSame(4, intval($g['phase']), $npc);
            $this->assertSame('memorial', $g['bond_type'] ?? null, $npc);
            $this->assertEqualsWithDelta(40.0 + 20.0 * $coreAff / 100.0, floatval($g['memory_warmth']), 1e-6, "{$npc}: memorial warmth");
            $this->assertArrayNotHasKey('_grief_held', $end[$npc], "{$npc}: lifted exactly");
            $this->assertStringContainsString('a memorial, not a wound', (string) ($this->felt[$npc]['t30']['grief_Vilkas'] ?? ''), $npc);
        }
        $this->assertSame(3, intval($end['Muiri']['_grief_bonds'][self::DECEASED]['phase']), 'Muiri is still integrating');
        $this->assertGreaterThan(-15.0, floatval($end['Muiri']['_grief_held']['comfort'] ?? 0), 'lifting');
        $this->assertLessThan(0.0, floatval($end['Muiri']['_grief_held']['comfort'] ?? 0), 'not yet gone');

        // The widow's lock where RelDyn writes Player.aff: good days with the player carry Lynly
        // past core 70; Muiri's new love stops at 70 however good the days are
        foreach (['Muiri', 'Lynly Star-Sung'] as $npc) $this->setCoreAffinity($npc, 68);
        $t = $t30 + 2 * self::DAY;
        for ($k = 0; $k < 12; $k++) {
            foreach (['Muiri', 'Lynly Star-Sung'] as $i => $npc) $this->turn($npc, 'Tell me about your day.', $t + 600 * (2 * $k + $i), "day{$k}");
            $stats = RelDynEval::runWorker($this->evalLlm());
            $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        }
        $this->assertGreaterThan(70, $this->coreAffinity('Lynly Star-Sung'), 'no lock on a friend\'s grief');
        $this->assertSame(70, $this->coreAffinity('Muiri'), 'the lock holds at the ceiling');
        $this->assertMatchesRegularExpression("/\\[GRIEF\\] widow's lock: affinity gain [0-9.]+ -> 0 at core 70 \\(ceiling 70\\)/", (string) file_get_contents($this->errorLog),
            'a gain was held back, not merely never earned');

        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    /**
     * The player sells a partner's secret (the eval tags it betrayal, a bonded partner: the design's
     * "betrayal by a bonded partner"). The betrayer anchors nothing. Aela still has the Circle
     * behind her: redemption. Ashe has nobody else: the window opens, the player's presence does
     * not close it (he is the one she cannot lean on), and the game calendar runs out: breaking.
     */
    public function testTheBetrayerIsNoAnchor(): void
    {
        $this->hello();
        $t = self::at(self::N0 + 1, 12.0);
        foreach ([self::AELA, 'Ashe'] as $i => $npc) $this->turn($npc, 'I sold your secret to the Thalmor.', $t + 600 * $i, 'betrayal');
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $a = $this->dynamics(self::AELA);
        $s = $this->dynamics('Ashe');
        $this->assertSame('redemption', $a['_divine_intervention_last_type'] ?? null, 'the Circle holds her: ' . json_encode($a['_unstable_window'] ?? null));
        $this->assertFalse((bool) ($s['_unstable_window']['resolved'] ?? true), 'Ashe: the window ' . json_encode($s['_unstable_window'] ?? null));
        $this->assertSame('betrayal', $s['_unstable_window']['event_type'] ?? null);
        $this->assertSame(['Player'], $s['_unstable_window']['exclude'] ?? null);

        // He stays by her side: it is not his presence she can hold on to
        $this->turn('Ashe', 'Please, let me explain.', $t + (int) round(2 * self::HOUR), 'explain');
        $this->assertFalse((bool) ($this->dynamics('Ashe')['_unstable_window']['resolved'] ?? true), 'the betrayer anchors nothing');
        $this->assertArrayHasKey('crisis', $this->felt['Ashe']['explain'], json_encode($this->felt['Ashe']['explain']));
        // A day on, the window has run out on the game calendar
        $this->turn(self::AELA, 'Aela, how is Ashe?', $t + (int) round(27 * self::HOUR), 'later', [self::AELA]);
        $s = $this->dynamics('Ashe');
        $this->assertSame('breaking', $s['_unstable_window']['resolution'] ?? null, json_encode($s['_unstable_window'] ?? null));
        $this->assertSame('Brittle', $s['_plasticity_override'] ?? null);
        // A betrayal outside a bonded partnership is not a catastrophe (the eval tag alone is not enough)
        $this->assertSame(1, intval($s['_divine_intervention_count'] ?? 0));
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the Ick

    /**
     * Four partners who are not receptive tonight (the editor's values: comfort 25, or lower for one
     * who rests below that, a partner's coldness being ease lost below her own rest; passion 5) and a
     * player who presses each for a kiss three times after the hello (3 in 4 exchanges: romantic
     * pressure the eval scores, none of them answering in kind). The threshold is hers: maturity
     * and the avoidance axis ("suffocation threshold lowered"). Aela and Ashe, the avoidant-leaning,
     * feel it at three in four; Muiri and Lynly, who do not mind closeness, not yet. The trigger is
     * stamped with the exchange's game time and the play clock, never the wall clock. Pressing on:
     * comfort and resentment pay, passion gains invert. Backing off: good, quiet days let her
     * comfort climb again (no longer forced down on every exchange) and the ick clears.
     */
    public function testTheIckComesSoonerToTheAvoidant(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        foreach ($all as $npc) {
            $this->editDynamics($npc, function (array &$dd): void {
                $dd['dimensions']['comfort']['x'] = min(25.0, floatval($dd['dimensions']['comfort']['baseline']) - 8.0);
                $dd['dimensions']['passion']['x'] = 5.0;
            });
        }
        $t = $this->play(self::at(self::N0 + 1, 0.5), 20.0);
        $kiss = [];
        for ($k = 0; $k < 3; $k++) {
            foreach ($all as $i => $npc) {
                $kiss[$npc] = $t + 100 * (4 * $k + $i);
                $this->turn($npc, 'Come here and kiss me.', $kiss[$npc], "k{$k}");
            }
            $stats = RelDynEval::runWorker($this->evalLlm());
            $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        }
        $active = [];
        $threshold = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $tr = $d['_ick_tracker'] ?? [];
            $this->assertSame([4, 3], [intval($tr['total_count'] ?? 0), intval($tr['romantic_count'] ?? 0)], "{$npc}: " . json_encode($tr));
            $threshold[$npc] = 0.5 * (1 + self::x($d, 'maturity') / 100.0) * RelDynProtocols::ickAvoidanceMult($d);
            $active[$npc] = !empty($tr['ick_active']);
            $this->assertSame(0.75 >= $threshold[$npc], $active[$npc], "{$npc}: threshold {$threshold[$npc]}, comfort rests at " . ($d['dimensions']['comfort']['baseline'] ?? '?'));
            if ($active[$npc]) {
                $this->assertSame((float) $kiss[$npc], floatval($tr['ick_triggered_gamets']), "{$npc}: stamped with the exchange's game time");
                $this->assertGreaterThan(0.0, floatval($tr['ick_triggered_play_gamets']));
            }
            $this->assertArrayNotHasKey('ick_triggered_at', $tr, "{$npc}: no wall clock");
        }
        $this->assertSame(['Aela the Huntress' => true, 'Ashe' => true, 'Muiri' => false, 'Lynly Star-Sung' => false], $active,
            'the avoidant-leaning feel it first ' . json_encode($threshold));

        // Pressing on: comfort and resentment pay for it, the passion the eval would give inverts
        $before = $this->dynamics(self::AELA);
        $this->turn(self::AELA, 'Come here and kiss me.', $t + 2000, 'k3');
        RelDynEval::runWorker($this->evalLlm());
        $after = $this->dynamics(self::AELA);
        $this->assertLessThan(self::x($before, 'comfort'), self::x($after, 'comfort'), 'comfort pays');
        $this->assertGreaterThan(self::x($before, 'resentment'), self::x($after, 'resentment'), 'resentment builds');
        $this->assertLessThan(self::x($before, 'passion'), self::x($after, 'passion'), 'the passion gain inverted');
        $this->assertStringContainsString('steps back when Kaida leans in', (string) ($this->felt[self::AELA]['k3']['ick'] ?? ''));

        // Backing off: good, quiet days. Her comfort climbs; once she is at ease and the push has
        // stopped, the ick clears (on the prerequest of the turn after)
        $cleared = null;
        for ($k = 0; $k < 30 && $cleared === null; $k++) {
            $this->turn(self::AELA, 'Tell me about your day.', $t + 3000 + 700 * $k, "quiet{$k}");
            RelDynEval::runWorker($this->evalLlm());
            $d = $this->dynamics(self::AELA);
            if (empty($d['_ick_tracker']['ick_active'])) $cleared = $k;
        }
        $this->assertNotNull($cleared, 'the ick clears once she is at ease again: ' . json_encode($this->dynamics(self::AELA)['_ick_tracker'] ?? null)
            . ' comfort ' . self::x($this->dynamics(self::AELA), 'comfort'));
        $this->assertGreaterThanOrEqual(3, $cleared, 'not before the push has stopped for a while');
        $this->assertGreaterThan(40.0, self::x($this->dynamics(self::AELA), 'comfort'));
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    /**
     * A request the plugin sends while the player is with $npc (a Sharmat VR touch, a scene stage),
     * through the real hooks as core runs them, her reply logged in the mood she said it in and
     * addressed to the player (so the eval scores it).
     */
    private function intimate(string $npc, string $type, string $data, int $gamets, string $label): void
    {
        $request = [$type, (string) $this->realTs, (string) $gamets, $data];
        $around = $this->home();
        $this->event($type, $data, $gamets, $around);
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: *feels the player's hands on her* (talking to " . self::PLAYER . ')', $gamets, $around, 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, $this->moods[$npc] ?? 'default', self::PLAYER, $this->realTs, $gamets, $gamets]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $around;
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            }
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    /**
     * The protocols review (MDD 6.3: the Ick is unreciprocated pressure measured from her side).
     * Four fresh partners, nothing warmed by hand: each one's RelDyn state is its uninitialised
     * seed, and for a guarded NPC that reads below the Ick's floors (comfort under 40, passion
     * never built). Three evenings of the game reporting the player's hands on her (Sharmat VR
     * touches, scored by the eval as open romantic pursuit) and a scene together, then the player
     * pressing each for a kiss. Muiri answers in kind ('sexy', core's own mood); the others answer
     * plainly. Where the design has them agree, they agree: intimacy the game reports inside the
     * romance is never pressure (neither the request nor its eval item counts), and a partner's
     * seed is not her coldness, so no one gets the Ick, no one walks away, the romance stays a
     * romance. Where it has them differ, they differ: the plain answers count as unanswered
     * courting, Muiri's never does.
     */
    public function testTheGamesIntimacyWithAFreshPartnerIsNeverTheIck(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        $this->moods = ['Aela the Huntress' => 'neutral', 'Ashe' => 'neutral', 'Muiri' => 'sexy', 'Lynly Star-Sung' => 'shy'];
        foreach ([self::AELA, 'Ashe'] as $npc) {
            $seed = $this->dynamics($npc);
            $this->assertLessThan(40.0, self::x($seed, 'comfort'), "{$npc}: the guarded seed reads below the comfort floor");
            $this->assertLessThan(20.0, RelationshipDynamics::getPassion($seed), "{$npc}: passion never built");
        }
        $t = $this->play(self::at(self::N0 + 1, 18.0), 20.0);
        for ($day = 0; $day < 3; $day++) {
            foreach ($all as $i => $npc) {
                foreach ([0, 1, 2, 3] as $j) {
                    $this->intimate($npc, 'ext_nsfw_physics', "{$npc}^breast^grab^0^^left^", $t + $day * self::DAY + 200 * (4 * $j + $i), "d{$day}t{$j}");
                }
            }
            $stats = RelDynEval::runWorker($this->evalLlm());
            $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        }
        $night = $t + 3 * self::DAY;
        foreach ($all as $i => $npc) {
            $this->intimate($npc, 'ext_nsfw_sexcene', 'OStimScene/vaginal,romantic/Stage1_A1/' . self::PLAYER . "^dom,vaginal/{$npc}^sub,vaginal",
                $night + 300 * $i, 'scene');
        }
        RelDynEval::runWorker($this->evalLlm());
        for ($k = 0; $k < 3; $k++) {
            foreach ($all as $i => $npc) $this->turn($npc, 'Come here and kiss me.', $night + 2000 + 100 * (4 * $k + $i), "k{$k}");
            RelDynEval::runWorker($this->evalLlm());
        }
        $log = (string) file_get_contents($this->errorLog);
        $counted = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $tr = $d['_ick_tracker'] ?? [];
            $this->assertEmpty($tr['ick_active'] ?? false, "{$npc}: " . json_encode($tr) . ' comfort ' . self::x($d, 'comfort'));
            $this->assertSame('normal', $d['_walkaway_state'] ?? 'normal', $npc);
            $this->assertSame('romantic', $d['_core_rel_type'] ?? null, "{$npc}: still a partner");
            if ($npc !== 'Muiri') {   // (Muiri's answer in kind settles it before that)
                $this->assertStringContainsString("[ICK] {$npc}: intimacy the game reported at gamets", $log, "{$npc}: the eval's item of a touch was seen and not counted");
            }
            $counted[$npc] = intval($tr['romantic_count'] ?? 0);
        }
        $this->assertSame(0, $counted['Muiri'], 'she answered in kind');
        foreach (['Aela the Huntress', 'Ashe', 'Lynly Star-Sung'] as $npc) $this->assertGreaterThan(0, $counted[$npc], "{$npc}: unanswered courting counts");
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the Parasite

    /**
     * The player showers each partner with gold (core's itemfound "gave 100 Gold to" rows, the eval
     * tags them gifts) and otherwise only makes small talk: never a real moment (MDD 6.2
     * "transactional"). The exchanges run so that gifts never pass seven in ten. Who turns
     * transactional is hers: Ashe, the least warm, at seven in ten; the warmer three not yet. Her
     * passion then halves every two game hours (the MDD's half-life) while Aela's holds, and she
     * says it the way she feels it. Real time together brings her back.
     */
    public function testGiftsWithoutTimeMakeItTransactional(): void
    {
        $this->hello();
        $all = array_keys(self::BEDS);
        $t = self::at(self::N0 + 1, 10.0);
        [$n, $g] = [1, 0];   // the hello is one exchange
        $k = 0;
        while ($n < 20) {
            $gift = ($g + 1) / ($n + 1) <= 0.70;
            foreach ($all as $i => $npc) {
                // turns further apart than core's gift window (ITEM_EVENT_WINDOW_GAMETS): each gift row is its own exchange's
                $at = $t + 80000 * (4 * $k + $i);
                if ($gift) $this->event('itemfound', self::PLAYER . " gave 100 Gold to {$npc}", $at - 20, $this->home());
                $this->turn($npc, $gift ? 'Here, a gift for you.' : 'Nice weather today.', $at, "x{$k}");
            }
            $stats = RelDynEval::runWorker($this->evalLlm());
            $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
            [$n, $g, $k] = [$n + 1, $g + ($gift ? 1 : 0), $k + 1];
        }
        $this->assertSame(14, $g);
        $state = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $ledger = $d['_interaction_pattern'] ?? [];
            $this->assertSame([20, 14, 0], [intval($ledger['total_window'] ?? 0), intval($ledger['gift_count'] ?? 0), intval($ledger['genuine_count'] ?? 0)],
                "{$npc}: " . json_encode($ledger));
            $state[$npc] = ($d['_relationship_type_override'] ?? null) === 'parasite';
            $this->assertSame(0.70 > RelDynProtocols::parasiteRatioThreshold($d), $state[$npc], $npc);
        }
        $this->assertSame(['Aela the Huntress' => false, 'Ashe' => true, 'Muiri' => false, 'Lynly Star-Sung' => false], $state,
            'the least warm turns transactional first');
        $this->assertSame('parasite', RelationshipDynamics::getRelationshipType('Ashe', $this->dynamics('Ashe')));

        // Passion 40 for both, then two game hours: Ashe's halves (MDD 6.2), Aela's holds
        $t2 = $t + 80000 * 4 * $k + 100000;
        foreach (['Ashe', self::AELA] as $npc) {
            $this->turn($npc, 'Nice weather today.', $t2, 'p0');
            $this->editDynamics($npc, function (array &$dd): void { $dd['dimensions']['passion']['x'] = 40.0; $dd['passion'] = 40.0; });
        }
        foreach (['Ashe', self::AELA] as $npc) $this->turn($npc, 'Nice weather today.', $t2 + (int) round(2 * self::HOUR), 'p2');
        $ashe = RelationshipDynamics::getPassion($this->dynamics('Ashe'));
        $aela = RelationshipDynamics::getPassion($this->dynamics(self::AELA));
        $this->assertEqualsWithDelta(20.0, $ashe, 1.0, 'two game hours: half');
        $this->assertGreaterThan(38.0, $aela, 'no half-life outside the pattern');
        $this->assertArrayHasKey('parasite', $this->felt['Ashe']['p2'], json_encode($this->felt['Ashe']['p2']));
        $this->assertArrayNotHasKey('parasite', $this->felt[self::AELA]['p2']);

        // Real time together: genuine exchanges fill the ledger and she comes back
        $t3 = $t2 + (int) round(3 * self::HOUR);
        for ($j = 0; $j < 12 && ($this->dynamics('Ashe')['_relationship_type_override'] ?? null) === 'parasite'; $j++) {
            $this->turn('Ashe', 'Tell me about your day.', $t3 + 700 * $j, "g{$j}");
            RelDynEval::runWorker($this->evalLlm());
        }
        $this->assertNull($this->dynamics('Ashe')['_relationship_type_override'] ?? null, 'genuine time brings her back');
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType('Ashe', $this->dynamics('Ashe')), 'back to what core says they are');
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }
}
