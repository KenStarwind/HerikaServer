<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCrossLaneV015PgDb
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
 * reldyn-v0.15 across its lanes, end to end with the four test beds (standing rule
 * feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector, never read),
 * Muiri and Lynly Star-Sung, each the player's partner (core Player.type romantic, affinity 60),
 * on CHIM 3.4.1 core-shaped rows and the committed seed's reads, through the real hooks as
 * main.php runs them (prerequest -> core's action list with the ext functions.php -> context_pre
 * -> context -> postrequest), the real eval producer and worker (LLM stubbed at the connector
 * boundary) and core's relationship writes. No LLM call. They stay home at Breezehome; the player
 * spends the same nights at the Bannered Mare (strangers there, Mikael among them, a mead) and
 * comes home at 01:00, as in RelDynConcernTestBedsPostgresTest: the protective three (Aela, Ashe,
 * Muiri) worry, state the calm §9 boundary on the third night and step back from the romance on
 * the fourth; for Lynly the inn is her world. Where the lanes meet:
 *   - natural exclusivity (decisions §17) x jealousy and concern: Mikael's romantic move on her
 *     is no rival of the player's (her jealousy does not move), and her jealousy over the
 *     player's nights is not a crack in her own pull (§5: jealousy feeds resentment, not the
 *     pull): through the probation she still turns him aside and names the player. The concern
 *     lane's deliberate step-back releases the pull ("kind, but that closeness is over"):
 *     still fond of the player, the three no longer hold themselves for anyone, while Lynly,
 *     still his partner, does. The step-back is a moment for her diary (diary-trigger).
 *   - command denial (autonomy) x boundaries: the same grievance during the probation, and the
 *     same order: a refusal is her own (Aela directly, Muiri says yes and means no, Lynly
 *     swallows it), it takes the follow / trade / give actions off her list, and it neither
 *     steps the bond back early nor ends the probation; the calm boundary is not said again and
 *     no one walks away over it. The step-back is not a refusal either: once the grievance has
 *     eased, the order is obeyed by the ones who stepped back, the actions stay on their list.
 * Feelings, never numbers, in front of the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCrossLaneV015TestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const SUITOR = 'Mikael';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the first night out
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    private const WORRIERS = ['Aela the Huntress', 'Ashe', 'Muiri'];
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const CORE_ACTIONS = ['MoveTo', 'OpenInventory', 'OpenInventory2', 'Attack', 'Follow', 'Inspect', 'TravelTo', 'FollowPlayer',
        'ComeCloser', 'ReturnBackHome', 'GiveGoldTo', 'GiveItemTo', 'MakeFollower', 'EndConversation'];
    private const MARE_PEOPLE = '|Hulda|Mikael|Jon Battle-Born|Olfina Gray-Mane|Ysolda|Kaida|';
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';
    private const MARE = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 9:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynCrossLaneV015PgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** npc => label => the action list core kept after the ext functions.php */
    private array $actions = [];
    /** the exclusivity blocks put in front of the LLM for the NPC-NPC exchanges */
    private array $exchanges = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_x15_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // core's diary (data/database_default.sql diarylog): the diary lane reads it at prerequest
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

        $this->db = new RelDynCrossLaneV015PgDb($dsn, $this->schema);
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdx15beds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_x15_beds_test.log');
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

    /** Core rows (partners: Player romantic, affinity 60), the suitor, voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic'], self::SUITOR => ['aff' => 10, 'type' => 'neutral']]])]);
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
        // Core's locations rows: the Bannered Mare is an Inn, Breezehome a player house
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld'), ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
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
     * $npc's request through the real hooks as main.php runs them: the prerequest, core's action
     * list (functions/functions.php loads the enabled codes and requires every ext
     * functions.php), context_pre, context, postrequest. Records the felt lines, the action list
     * kept and (for an NPC-NPC exchange) the exclusivity block.
     */
    private function request(string $npc, array $request, string $listener, string $label, ?string $previousSpeaker = null): ?string
    {
        $block = null;
        foreach (['prerequest.php', 'functions.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to {$listener})", (int) $request[2], $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = $listener;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            // core resolves a rechat's previous speaker after the prerequest hooks (main.php)
            if ($previousSpeaker !== null && $hook !== 'prerequest.php') $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = $previousSpeaker;
            if ($hook === 'functions.php') $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_ACTIONS;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'functions.php') $this->actions[$npc][$label] = $GLOBALS['ENABLED_FUNCTIONS'];
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                foreach ((array) $GLOBALS['contextDataFull'] as $m) {
                    if (str_contains((string) ($m['content'] ?? ''), 'right now, with ' . self::SUITOR)) $block = (string) $m['content'];
                }
            }
            if ($hook !== 'functions.php') RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
        if ($block !== null) $this->exchanges[] = $block;
        return $block;
    }

    /** One player line to $npc at $gamets, logged as core logs it (input row, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], self::PLAYER, $label);
    }

    /**
     * Mikael's line to $npc (logged as core logs an NPC line), then her rechat turn. Returns the
     * exclusivity block the context hook put in front of the LLM, or null.
     */
    private function flirt(string $npc, string $line, int $gamets, string $label): ?string
    {
        $this->event('chat', self::SUITOR . ": {$line} (talking to {$npc})", $gamets, $this->home(), 'emitted');
        $request = ['rechat', (string) $this->realTs, (string) ($gamets + 20), json_encode(['speaker' => self::SUITOR, 'listener_hint' => $npc])];
        return $this->request($npc, $request, self::SUITOR, $label, self::SUITOR);
    }

    /**
     * The eval LLM at the connector boundary: the Bannered Mare line carries the exposure field
     * (strangers, the drink); anything else is small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $told = str_contains($exchange, 'Bannered Mare last night');
            return json_encode([
                'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => [], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => $told ? ['flag' => true, 'kinds' => ['rival_exposure', 'place', 'vice'], 'intensity' => 2, 'when' => 'last_night']
                                    : ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $told ? 0.4 : 0.1, 'summary' => $told ? 'The player told her about a night at the tavern.' : 'Small talk.',
                'romantic_intent' => 0,
            ]);
        };
    }

    /** The same night for all four: they stay home, the player is at the Mare 21:00-00:30 and has a mead at 23:30. */
    private function nightAtTheMare(int $day): void
    {
        $this->event('infoloc', self::HOME, self::at($day, 20.5), $this->home());
        foreach ([21.0, 22.5, 0.5 + 24] as $h) $this->event('infoloc', self::MARE, self::at($day, $h), self::MARE_PEOPLE);
        $this->event('itemfound', self::PLAYER . ' drank Nord Mead', self::at($day, 23.5), self::MARE_PEOPLE);
        $this->event('infoloc', self::HOME, self::at($day + 1, 0.9), $this->home());
    }

    /** Home at 01:00 (what she perceives), then next morning "at the Mare last night with some friends" (the real eval worker). */
    private function night(int $n, int $day): void
    {
        $this->nightAtTheMare($day);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, "I'm home.", self::at($day + 1, 1.0) + 600 * $i++, "return{$n}");
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'I was at the Bannered Mare last night with some friends.', self::at($day + 1, 9.0) + 600 * $i++, "told{$n}");
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    /** Evening: everyone at home with the player. */
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

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Edit $npc's stored RelDyn state (as the NPC editor would). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    private function coreType(string $npc): string
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode($r['extended_data'], true) ?: []);
        return (string) ($rel['type'] ?? '');
    }

    /** Her feelings for the player, as the editor would set them (passion points). */
    private function fond(float $passion): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d) use ($passion): void { RelationshipDynamics::setPassion($d, $passion); });
        }
    }

    /** The pull toward the player Mikael met at her last exchange with him. */
    private function metPull(string $npc): float
    {
        return floatval(RelDynExclusivity::suitor($this->dynamics($npc), self::SUITOR)['pull_seen'] ?? -1.0);
    }

    private function interestIn(string $npc, int $gamets): float
    {
        $e = RelDynExclusivity::suitor($this->dynamics($npc), self::SUITOR);
        return $e === null ? 0.0 : RelDynExclusivity::interestAt($e, (float) $gamets);
    }

    /** Mikael flirts with each in turn from $t; returns npc => [block, pull he met, interest gained, jealousy before, after]. */
    private function suitorRound(int $t, string $line, string $label): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $k => $npc) {
            $at = $t + 600 * $k;
            $before = $this->dynamics($npc);
            $interest = $this->interestIn($npc, $at + 20);
            $block = $this->flirt($npc, $line, $at, $label);
            $after = $this->dynamics($npc);
            $out[$npc] = ['block' => $block, 'pull' => $this->metPull($npc), 'gain' => $this->interestIn($npc, $at + 20) - $interest,
                          'jealousy' => [floatval($before['jealousy_anger'] ?? 0), floatval($after['jealousy_anger'] ?? 0)],
                          'rival' => [$before['jealousy_trigger_npc'] ?? null, $after['jealousy_trigger_npc'] ?? null]];
        }
        return $out;
    }

    /** The shared grievance the editor sets on every bed: distrust, disrespect, resentment (RelDynP3oGoalsTestBeds). */
    private function aggrieved(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d) use ($npc): void {
                $d['dimensions']['trust']['x'] = 5.0;
                $d['dimensions']['respect']['x'] = 15.0;
                $d['dimensions']['resentment']['x'] = 60.0;
                if ($npc === 'Muiri') $d['profile_overrides']['attachment_style'] = 'toxic';
                if ($npc === self::LYNLY) {
                    // the shy bard at her lowest: unsure of herself, not grown into it
                    $d['dimensions']['self_confidence']['x'] = 25.0;
                    $d['dimensions']['maturity']['x'] = 35.0;
                }
            });
        }
    }

    /** The grievance eased (the editor): trust and respect back, resentment down. */
    private function eased(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d): void {
                $d['dimensions']['trust']['x'] = 60.0;
                $d['dimensions']['respect']['x'] = 60.0;
                $d['dimensions']['resentment']['x'] = 10.0;
            });
        }
    }

    private function autonomy(string $npc): array
    {
        $d = $this->dynamics($npc);
        return RelationshipDynamics::evaluateAutonomyState($d, (string) ($d['inferred_temperament'] ?? 'Stoic'));
    }

    /** No digit in anything the felt steering or the exclusivity put in front of the LLM. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
        foreach ($this->exchanges as $block) $this->assertDoesNotMatchRegularExpression('/\d/', $block);
    }

    // ------------------------------------------------------------------ exclusivity x jealousy / concern

    public function testTheSuitorTheRivalsAndTheStepBack(): void
    {
        $this->hello();
        $this->fond(45.0);

        // Before any night out: each turns Mikael aside in her own way, and names the player
        $calm = $this->suitorRound(self::at(self::N0, 19.0), 'You look lovely today. Share a drink with me?', 'suitor0');
        foreach ($calm as $npc => $r) {
            $this->assertNotNull($r['block'], "{$npc}: steered");
            $this->assertStringContainsString(self::PLAYER, $r['block'], "{$npc}: a partner names the player");
            $this->assertContains(RelDynExclusivity::bandOf($r['pull']), [RelDynExclusivity::BAND_DEVOTED, RelDynExclusivity::BAND_TAKEN], "{$npc}: holds");
            $this->assertSame($r['jealousy'][0], $r['jealousy'][1], "{$npc}: his move on her is no rival of the player's");
            $this->assertSame($r['rival'][0], $r['rival'][1], $npc);
            $this->assertSame([], array_values(array_filter(array_keys($this->felt[$npc]['suitor0']), fn($k) => str_starts_with($k, 'concern_'))),
                "{$npc}: nothing to worry about in a suitor's line");
        }

        // Three nights at the Mare: the player's rivals there make them jealous; the protective three
        // worry and state the calm boundary on the third return (probation)
        $this->night(1, self::N0);
        $this->night(2, self::N0 + 2);
        $this->night(3, self::N0 + 4);
        foreach (self::WORRIERS as $npc) {
            $this->assertArrayHasKey('concern_boundary', $this->felt[$npc]['return3'], "{$npc}: the calm boundary");
            $this->assertSame('probation', $this->dynamics($npc)['_concern']['boundary']['state'], $npc);
        }
        $this->fond(45.0);   // the same fondness as before the nights, so the pull compares
        $jealous = $this->suitorRound(self::at(self::N0 + 5, 15.0), 'Come now, one dance with me. You are stunning.', 'suitor1');
        foreach ($jealous as $npc => $r) {
            $d = $this->dynamics($npc);
            $this->assertGreaterThan(0.0, $r['jealousy'][0], "{$npc}: the strangers at the Mare made her jealous");
            // (it only decays over the exchange)
            $this->assertLessThanOrEqual($r['jealousy'][0], $r['jealousy'][1], "{$npc}: Mikael's move on her adds none");
            $this->assertGreaterThan(0.99 * $r['jealousy'][0], $r['jealousy'][1], $npc);
            $this->assertSame($r['rival'][0], $r['rival'][1], "{$npc}: the rival is still the player's");
            // §5: jealousy feeds resentment, not the pull
            $calmD = $d;
            $calmD['jealousy_anger'] = 0.0;
            $this->assertSame(RelDynExclusivity::pull($calmD, (float) self::at(self::N0 + 5, 15.0))['pull'],
                RelDynExclusivity::pull($d, (float) self::at(self::N0 + 5, 15.0))['pull'], "{$npc}: jealousy is no input to her pull");
            $this->assertNotNull($r['block'], "{$npc}: still turns him aside");
            $this->assertStringContainsString(self::PLAYER, $r['block'], "{$npc}: in the probation she is still his partner");
            if ($npc === 'Muiri') {
                // Rulings 2026-09-25 §18 #9: Muiri attaches fearful (her preset), a wavering pull
                // (exclusivity attachment_mult toxic): with the player away at night she leans
                $this->assertSame(RelDynExclusivity::BAND_LEANING, RelDynExclusivity::bandOf($r['pull']), "{$npc}: leans " . json_encode($r));
                continue;
            }
            $this->assertContains(RelDynExclusivity::bandOf($r['pull']), [RelDynExclusivity::BAND_DEVOTED, RelDynExclusivity::BAND_TAKEN],
                "{$npc}: holds " . json_encode($r));
        }
        $pulls = array_map(fn($r) => $r['pull'], $jealous);
        asort($pulls);
        $this->assertSame('Muiri', array_key_first($pulls), 'Muiri: the weakest pull of the four ' . json_encode($pulls));
        $j = array_map(fn($r) => $r['jealousy'][1], $jealous);
        arsort($j);
        $this->assertSame('Muiri', array_key_first($j), 'Muiri: the most jealous ' . json_encode($j));

        // The fourth night, inside the probation: the protective three step back (romantic -> platonic)
        $this->night(4, self::N0 + 6);
        foreach (self::WORRIERS as $npc) {
            $this->assertSame('platonic', $this->coreType($npc), "{$npc}: stepped back");
            $this->assertStringContainsString('stepping back from a romance to friendship', $this->felt[$npc]['return4']['concern_step_back'] ?? '');
            // a moment for her diary (diary-trigger: a boundary lane moved)
            $this->assertContains('boundary:concern', RelationshipDynamics::detectDiaryContentTriggers($this->dynamics($npc)), $npc);
        }
        $this->assertSame('romantic', $this->coreType(self::LYNLY), 'Lynly: nothing to step back from');
        $this->assertNotContains('boundary:concern', RelationshipDynamics::detectDiaryContentTriggers($this->dynamics(self::LYNLY)));

        // Still fond of the player (passion 60): the three who stepped back hold themselves for no one;
        // Lynly, still his partner, turns Mikael aside and names the player
        $this->fond(60.0);
        $after = $this->suitorRound(self::at(self::N0 + 7, 15.0), 'You look lovely today. Share a drink with me?', 'suitor2');
        foreach (self::WORRIERS as $npc) {
            $r = $after[$npc];
            $p = RelDynExclusivity::pull($this->dynamics($npc), (float) self::at(self::N0 + 7, 16.0));
            $this->assertTrue($p['stepped_back'], $npc);
            $this->assertSame(0.0, $r['pull'], "{$npc}: released " . json_encode($p));
            $this->assertNull($r['block'], "{$npc}: nothing to hold, nothing to drift from");
            $this->assertEqualsWithDelta(RelDynExclusivity::configDefaults()['interest_per_move'], $r['gain'], 1e-3, "{$npc}: his attention is not damped");
            $this->assertGreaterThan($after[self::LYNLY]['gain'], $r['gain'], "{$npc}: more of him gets through than to Lynly");
        }
        $this->assertNotNull($after[self::LYNLY]['block']);
        $this->assertStringContainsString(self::PLAYER, $after[self::LYNLY]['block'], 'Lynly: still his');
        $this->assertFalse(RelDynExclusivity::pull($this->dynamics(self::LYNLY), (float) self::at(self::N0 + 7, 16.0))['stepped_back']);
        $jev = RelDynJev::state('Ashe', $this->dynamics('Ashe'), (float) self::at(self::N0 + 7, 16.0));
        $this->assertTrue($jev['exclusivity']['stepped_back'], 'Jev gets the numbers');
        $this->assertSame(0.0, $jev['exclusivity']['pull']);

        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertGreaterThan(0, $this->evalCalls, 'the eval worker scored the mornings (stubbed)');
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ an NPC-NPC rechat is no turn of the player pair

    /**
     * batch O review: a rechat with Mikael is an NPC-to-NPC exchange. Her reply gets his
     * exclusivity line and nothing player-directed (no passion toward the player, no refusal of
     * the player's demands, no contempt for the player beside "their heart is with Kaida"), and his
     * flirts feed nothing of the player pair (passion, interaction count, contact, fulfillment,
     * so neither the pull he meets). The player's own word afterwards is a turn of the pair again.
     */
    public function testARechatWithTheSuitorIsNoTurnOfThePlayerPair(): void
    {
        $this->hello();
        $this->fond(45.0);
        $this->aggrieved();
        $beds = array_keys(self::BEDS);
        $before = array_combine($beds, array_map(fn($npc) => $this->dynamics($npc), $beds));
        $t = self::at(self::N0, 19.0);
        $lines = ['You look lovely today. Share a drink with me?', 'How was the hunt?',
                  'Come now, one dance with me. You are stunning.', 'How was the hunt?'];
        $pulls = [];
        foreach ($lines as $i => $line) {
            foreach ($beds as $k => $npc) {
                $block = $this->flirt($npc, $line, $t + 3600 * $i + 600 * $k, "rechat{$i}");
                $this->assertSame([], $this->felt[$npc]["rechat{$i}"], "{$npc}: nothing player-directed in her reply to Mikael ({$line})");
                if (RelDynExclusivity::isRomanticLine($line)) {
                    $this->assertNotNull($block, "{$npc}: his move is turned aside");
                    $this->assertStringContainsString(self::PLAYER, $block, "{$npc}: a partner names the player");
                }
                $pulls[$npc][] = $this->metPull($npc);
            }
        }
        foreach ($beds as $npc) {
            $after = $this->dynamics($npc);
            $this->assertNotNull(RelDynExclusivity::suitor($after, self::SUITOR), "{$npc}: her ledger of him");
            $b = $before[$npc];
            unset($after[RelDynExclusivity::STATE_KEY], $b[RelDynExclusivity::STATE_KEY]);
            $this->assertEquals($b, $after, "{$npc}: four rechats with Mikael moved nothing of the player pair");
            $this->assertLessThanOrEqual($pulls[$npc][0] + 1e-9, max($pulls[$npc]), "{$npc}: his flirts do not raise the pull he meets " . json_encode($pulls[$npc]));
        }

        // The player's word: a turn of the pair, steered toward the player again
        $i = 0;
        foreach ($beds as $npc) $this->turn($npc, 'I am back, love.', self::at(self::N0 + 1, 9.0) + 600 * $i++, 'back');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame(intval($before[$npc]['interaction_count'] ?? 0) + 1, intval($d['interaction_count'] ?? 0), $npc);
            $this->assertGreaterThan(floatval($before[$npc]['_last_contact_gamets'] ?? 0), floatval($d['_last_contact_gamets'] ?? 0), $npc);
            $this->assertNotSame([], $this->felt[$npc]['back'], "{$npc}: the player's turn is steered");
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ command denial x boundaries

    public function testTheSameOrderMeetsTheBoundaryInHerOwnWay(): void
    {
        $this->hello();
        $this->night(1, self::N0);
        $this->night(2, self::N0 + 2);
        $this->night(3, self::N0 + 4);
        foreach (self::WORRIERS as $npc) $this->assertSame('probation', $this->dynamics($npc)['_concern']['boundary']['state'], $npc);

        // Inside the probation, the same grievance and the same order
        $this->aggrieved();
        $selfBefore = floatval($this->dynamics(self::LYNLY)['dimensions']['resentment_self']['x'] ?? 0);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Follow me. Now.', self::at(self::N0 + 5, 12.0) + 600 * $i++, 'order');
        $eval = array_combine(array_keys(self::BEDS), array_map(fn($npc) => $this->autonomy($npc), array_keys(self::BEDS)));
        $why = json_encode(array_map(fn($e) => [$e['state'], $e['refusal_type'], $e['autonomy_score'], $e['people_pleaser']], $eval));
        $this->assertSame('direct', $eval[self::AELA]['refusal_type'], "Aela: a clean no {$why}");
        $this->assertSame('manipulative', $eval['Muiri']['refusal_type'], "Muiri: says yes and means no {$why}");
        $this->assertSame('compliant', $eval[self::LYNLY]['state'], "Lynly swallows it {$why}");
        $this->assertTrue($eval[self::LYNLY]['swallowed']);
        foreach (self::WORRIERS as $npc) {
            $this->assertSame('refusing', $eval[$npc]['state'], "{$npc}: {$why}");
            $this->assertNotContains('FollowPlayer', $this->actions[$npc]['order'], "{$npc}: the follow action is off her list");
            $this->assertNotContains('GiveItemTo', $this->actions[$npc]['order'], $npc);
            $this->assertContains('EndConversation', $this->actions[$npc]['order'], "{$npc}: she can still end it");
            $this->assertArrayHasKey('autonomy', $this->felt[$npc]['order'], "{$npc}: the refusal speaks");
            // a refusal is not a step-back, and the boundary is not a walkaway
            $d = $this->dynamics($npc);
            $this->assertSame('romantic', $this->coreType($npc), "{$npc}: the refusal steps nothing back early");
            $this->assertSame('probation', $d['_concern']['boundary']['state'], "{$npc}: the probation runs on");
            $this->assertArrayNotHasKey('concern_boundary', $this->felt[$npc]['order'], "{$npc}: the boundary is said once");
            $this->assertSame('normal', (string) ($d['_walkaway_state'] ?? 'normal'), "{$npc}: nobody walks away over it");
        }
        $this->assertSame(self::CORE_ACTIONS, $this->actions[self::LYNLY]['order'], 'Lynly complies: nothing taken off');
        $this->assertStringContainsString("I'm not going to do that", $this->felt[self::AELA]['order']['autonomy'] ?? '');
        $this->assertStringContainsString('the eyes are wrong', $this->felt['Muiri']['order']['autonomy'] ?? '');
        $this->assertStringContainsString('says nothing', $this->felt[self::LYNLY]['order']['autonomy'] ?? '');
        $this->assertGreaterThan($selfBefore, floatval($this->dynamics(self::LYNLY)['dimensions']['resentment_self']['x'] ?? 0), 'the resentment turns inward');

        // The fourth night: the pattern goes on, the three step back. Then, the grievance eased,
        // the same order: a step-back is not a refusal
        $this->night(4, self::N0 + 6);
        foreach (self::WORRIERS as $npc) $this->assertSame('platonic', $this->coreType($npc), "{$npc}: stepped back");
        $this->eased();
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Follow me, would you?', self::at(self::N0 + 7, 12.0) + 600 * $i++, 'after');
        foreach (array_keys(self::BEDS) as $npc) {
            $e = $this->autonomy($npc);
            $this->assertContains($e['state'], ['compliant', 'resistant'], "{$npc}: " . json_encode($e));
            $this->assertSame(self::CORE_ACTIONS, $this->actions[$npc]['after'], "{$npc}: nothing taken off her list");
            $this->assertArrayNotHasKey('autonomy', $this->felt[$npc]['after'], "{$npc}: no refusal to speak");
        }

        $this->assertFeelingsNotNumbers();
        $jev = RelationshipDynamics::jevStateBlock('Muiri');
        $this->assertArrayHasKey('autonomy', $jev);
        $this->assertArrayHasKey('concern', $jev);
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures);
    }
}
