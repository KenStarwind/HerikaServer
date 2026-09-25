<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynP3oGoalsPgDb
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
 * The p3o goals lane end to end with the four test beds (standing rule): Aela the Huntress, Ashe
 * (Serene's hand-set vector, never read: her bio template is never scanned), Muiri (toxic: the
 * editor sets the attachment her story shows, as the v0.13 beds do) and Lynly Star-Sung (the shy
 * bard), on CHIM 3.4.1 core-shaped rows, through the real hooks (prerequest -> core's action-list
 * hook -> context_pre -> context -> postrequest), the real eval worker (LLM stubbed at the
 * connector boundary) and core's own quest / questlog / core_player / eventlog / equipment rows.
 * No LLM call; feelings, never numbers, in front of the LLM.
 *
 *   autonomy-command-denial  the same grievance, four ways: Aela refuses outright, Muiri says yes
 *                            and means no (manipulative), Lynly swallows it (people-pleaser:
 *                            compliant, the resentment turns inward); a refusal takes the
 *                            follow / trade / give actions off her list, a swallowed one does not
 *   duty-override            a hostile NPC the journal names does the quest's business coldly
 *                            (duty line, no refusal line) and her negative eval signals land at
 *                            a tenth; the same hostility without a quest is her own; a quest that
 *                            names a friendly NPC changes nothing
 *   quest-event-hook +       stage changes reach the NPCs a journal quest names, once; Aela's
 *   intrinsic-goals          backstory revenge on the Silver Hand advances with the Silver Hand
 *                            quest; each forms goals from who she is (mastery by her own interests)
 *   reputation-layer         a famous and infamous player: every stranger wary, respect split by
 *                            how each regards danger; it fades with meaningful interactions
 *   item-modifiers           the same mead, book and amulet appraised four ways
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynP3oGoalsTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
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
     * The template 'goals' text each bed gets (the live bios are not committed). Aela's is the
     * test's own backstory sentence; Ashe's carries a trigger phrase on purpose, to show her
     * template is never scanned (nothing of her story is in it).
     */
    private const GOALS_TEXT = [
        'Aela the Huntress' => 'Placeholder goals text. She swore to hunt down the Silver Hand.',
        'Ashe'              => 'Placeholder goals text: searching for a test marker.',
        'Muiri'             => 'Placeholder goals text.',
        'Lynly Star-Sung'   => 'Placeholder goals text.',
    ];
    private const CORE_ACTIONS = ['MoveTo', 'OpenInventory', 'OpenInventory2', 'Attack', 'Follow', 'Inspect', 'TravelTo', 'FollowPlayer',
        'ComeCloser', 'ReturnBackHome', 'GiveGoldTo', 'GiveItemTo', 'MakeFollower', 'EndConversation'];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynP3oGoalsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => felt text key => text */
    private array $felt = [];
    /** npc => label => the action list core would send after this plugin's functions.php hook */
    private array $actions = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_p3og_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core 3.4.1 quests / questlog (live dwemer \d, 2026-09-25)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE questlog (ts text, sess varchar(1024), id_quest varchar(1024), name text,
            editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text, stage integer,
            briefing text, briefing2 text, localts bigint, gamets bigint, data text, status text, rowid serial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynP3oGoalsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'ENABLED_FUNCTIONS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdp3og');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_p3og_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
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
     * Core rows (partners: Player romantic, affinity 60; strangers: no Player entry), voice types,
     * templates and the committed seed's reads.
     */
    private function seed(bool $partners = true): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            $ext = ['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f];
            if ($partners) $ext['relationships'] = [self::PLAYER => ['aff' => 60, 'type' => 'romantic']];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]), json_encode($ext)]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            $fields['goals'] = self::GOALS_TEXT[$name];
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
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld'), ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $this->home(), '', $state]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /**
     * One player line to $npc through the real hooks at $gamets, as main.php runs them: the
     * prerequest, then core's action list (functions/functions.php loads the enabled codes and
     * requires every ext functions.php), context_pre, context, postrequest.
     */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        foreach (['prerequest.php', 'functions.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'functions.php') $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_ACTIONS;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'functions.php') $this->actions[$npc][$label] = $GLOBALS['ENABLED_FUNCTIONS'];
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            if ($hook !== 'functions.php') RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['ENABLED_FUNCTIONS']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
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

    /** Evening: everyone at home with the player. */
    private function hello(string $label = 'hello'): void
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0));
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met.', self::at(self::N0, 18.0) + 600 * $i++, $label);
    }

    /**
     * The eval LLM at the connector boundary: an insult is a grievance with trust / comfort
     * losses; a day together is meaningful quality time; anything else small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $insult = str_contains($exchange, 'useless');
            $together = str_contains($exchange, 'about your day');
            return json_encode([
                'signals' => ['affinity' => $insult ? -2 : ($together ? 2 : 0), 'trust' => $insult ? -6 : ($together ? 4 : 0),
                              'comfort' => $insult ? -4 : ($together ? 3 : 0), 'respect' => $insult ? -3 : ($together ? 2 : 0),
                              'passion' => 0, 'maturity' => 0],
                'tags' => $insult ? ['insult'] : ($together ? ['quality_time'] : []),
                'grievance' => $insult ? ['flag' => true, 'kind' => 'insult', 'severity' => 1] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $insult ? 0.4 : ($together ? 0.5 : 0.1),
                'summary' => $insult ? 'The player called her useless.' : ($together ? 'A quiet day together.' : 'Small talk.'),
                'romantic_intent' => 0,
            ]);
        };
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** The shared grievance the editor sets on every bed: distrust, disrespect, resentment. */
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

    /** No digit in anything the felt steering put in front of the LLM. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
    }

    // ------------------------------------------------------------------ autonomy-command-denial

    public function testTheSameGrievanceIsRefusedFourWays(): void
    {
        $this->seed();
        $this->hello();
        $this->aggrieved();
        $selfBefore = self::x($this->dynamics(self::LYNLY), 'resentment_self');
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Follow me. Now.', self::at(self::N0 + 1, 12.0) + 600 * $i++, 'order');

        $eval = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $eval[$npc] = RelationshipDynamics::evaluateAutonomyState($d, (string) ($d['inferred_temperament'] ?? 'Stoic'));
        }
        $why = json_encode(array_map(fn($e) => [$e['state'], $e['refusal_type'], $e['autonomy_score'], $e['people_pleaser']], $eval));
        $this->assertSame('refusing', $eval[self::AELA]['state'], $why);
        $this->assertSame('direct', $eval[self::AELA]['refusal_type'], "Aela: confident and grown: a clean no {$why}");
        $this->assertSame('manipulative', $eval['Muiri']['refusal_type'], "Muiri: agrees and means no {$why}");
        $this->assertSame('compliant', $eval[self::LYNLY]['state'], "Lynly: a people-pleaser swallows it {$why}");
        $this->assertTrue($eval[self::LYNLY]['swallowed']);

        foreach ([self::AELA, 'Muiri', 'Ashe'] as $npc) {
            $this->assertNotContains('FollowPlayer', $this->actions[$npc]['order'], "{$npc}: refusing, the follow action is off her list");
            $this->assertNotContains('GiveItemTo', $this->actions[$npc]['order'], $npc);
            $this->assertContains('EndConversation', $this->actions[$npc]['order'], "{$npc}: she can still end it");
        }
        $this->assertSame(self::CORE_ACTIONS, $this->actions[self::LYNLY]['order'], 'Lynly complies: nothing taken off');
        $this->assertSame(self::CORE_ACTIONS, $this->actions[self::AELA]['hello'], 'before the grievance nothing was');

        $this->assertStringContainsString("I'm not going to do that", $this->felt[self::AELA]['order']['autonomy'] ?? '');
        $this->assertStringContainsString('the eyes are wrong', $this->felt['Muiri']['order']['autonomy'] ?? '');
        $this->assertStringContainsString('says nothing', $this->felt[self::LYNLY]['order']['autonomy'] ?? '');
        $this->assertGreaterThan($selfBefore, self::x($this->dynamics(self::LYNLY), 'resentment_self'), 'the resentment turns inward');
        $this->assertFeelingsNotNumbers();
        $jev = RelationshipDynamics::jevStateBlock('Muiri');
        $this->assertSame('manipulative', $jev['autonomy']['refusal']);
        $this->assertContains('FollowPlayer', $jev['autonomy']['denied_actions']);
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ duty-override

    public function testAHostileNpcTheQuestNamesDoesTheQuestsBusinessColdly(): void
    {
        $this->seed();
        $this->hello();
        $this->aggrieved();
        // Lynly is named too, but only the grievance's four are hostile; Lynly is set back to her calm self
        $this->editDynamics(self::LYNLY, function (array &$d): void {
            foreach (['trust' => 60.0, 'respect' => 60.0, 'resentment' => 0.0, 'self_confidence' => 55.0, 'maturity' => 50.0] as $k => $v) $d['dimensions'][$k]['x'] = $v;
        });
        pg_query_params($this->db->link, "INSERT INTO quests (ts, gamets, name, briefing, data, stage, giver_actor_id, id_quest, sess, status, localts)
            VALUES ('1', $1, 'Proving Honor', 'Speak to Aela the Huntress.', $2, 20, '', 'C01', 'pending', '', 1)",
            [self::at(self::N0 + 1, 8.0), json_encode(['QuestGiver' => self::AELA, 'Witness' => self::LYNLY])]);

        $t = self::at(self::N0 + 1, 12.0);
        $before = [];
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $i => $npc) {
            $this->turn($npc, "You're useless, you know that?", $t + 600 * $i, 'quest');
            $before[$npc] = $this->dynamics($npc);
        }
        $this->assertArrayHasKey('duty', $this->felt[self::AELA]['quest'], json_encode(array_keys($this->felt[self::AELA]['quest'])));
        $this->assertStringContainsString('Proving Honor', $this->felt[self::AELA]['quest']['duty']);
        $this->assertStringContainsString('strictly business', $this->felt[self::AELA]['quest']['duty']);
        $this->assertArrayNotHasKey('autonomy', $this->felt[self::AELA]['quest'], 'on duty she complies coldly instead of refusing');
        $this->assertNotContains('FollowPlayer', $this->actions[self::AELA]['quest'], 'the quest does not make her follow');
        $this->assertArrayNotHasKey('duty', $this->felt['Muiri']['quest'], 'no quest names Muiri: her hostility is her own');
        $this->assertArrayHasKey('autonomy', $this->felt['Muiri']['quest']);
        $this->assertArrayNotHasKey('duty', $this->felt[self::LYNLY]['quest'], 'named, but not hostile: she talks as herself');

        // The eval job carries the duty; the insult lands on Aela at a tenth (her own
        // counterfactual: the same item without the duty, on the same state)
        $jobs = [];
        foreach ($this->db->fetchAll("SELECT npc_name, job FROM reldyn_eval_queue ORDER BY id") as $r) $jobs[$r['npc_name']] = json_decode($r['job'], true);
        $this->assertSame(0.1, $jobs[self::AELA]['duty_factor'] ?? null);
        $this->assertArrayNotHasKey('duty_factor', $jobs['Muiri']);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $raw = ($this->evalLlm())([['content' => ''], ['content' => "THIS EXCHANGE You're useless"]], []);
        $plainItem = RelDynEval::parseResponse($raw, array_diff_key($jobs[self::AELA], ['duty_factor' => 1]));
        $counterfactual = $before[self::AELA];
        $plain = RelationshipDynamics::processEvalContractItem(self::AELA, $plainItem, $counterfactual);
        $actual = self::x($this->dynamics(self::AELA), 'trust') - self::x($before[self::AELA], 'trust');
        $this->assertLessThan(0.0, $plain['trust'] ?? 0.0);
        $this->assertEqualsWithDelta(0.1 * $plain['trust'], $actual, abs($plain['trust']) * 0.05,
            'quest-scripted friction is not held against the bond ' . json_encode([$actual, $plain]));
        $muiri = self::x($this->dynamics('Muiri'), 'trust') - self::x($before['Muiri'], 'trust');
        $this->assertLessThan($actual, $muiri, 'Muiri takes the insult in full');
        $this->assertSame('Proving Honor', RelationshipDynamics::jevStateBlock(self::AELA)['duty']['quest'] ?? null);
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ quest-event-hook + intrinsic-goals

    public function testEachFormsGoalsFromWhoSheIsAndTheSilverHandQuestMovesAelas(): void
    {
        $this->seed();
        $this->hello();
        $goals = [];
        foreach (array_keys(self::BEDS) as $npc) $goals[$npc] = array_column(RelDynGoals::active($this->dynamics($npc)), null, 'type');
        $why = json_encode(array_map(fn($g) => array_map(fn($x) => [$x['priority'], $x['facets'], $x['keywords']], $g), $goals));
        $this->assertArrayHasKey('revenge', $goals[self::AELA], "Aela's backstory: the Silver Hand {$why}");
        $this->assertSame(['Silver Hand'], $goals[self::AELA]['revenge']['keywords']);
        foreach (['Ashe', 'Muiri', self::LYNLY] as $npc) $this->assertArrayNotHasKey('revenge', $goals[$npc], $npc);
        $this->assertArrayNotHasKey('purpose', $goals['Ashe'], "Ashe's template is never scanned (spoiler screen), whatever it says {$why}");
        $this->assertEquals(['social' => 1.0], $goals[self::LYNLY]['mastery']['facets'] ?? null, "Lynly: people and song {$why}");
        $this->assertEquals(['alchemy' => 1.0], $goals['Muiri']['mastery']['facets'] ?? null, "Muiri: alchemy {$why}");
        $this->assertStringContainsString('Silver Hand', $this->felt[self::AELA]['hello']['intrinsic_goal'] ?? '', json_encode(array_keys($this->felt[self::AELA]['hello'])));
        $this->assertStringContainsString('people and song', $this->felt[self::LYNLY]['hello']['intrinsic_goal'] ?? '');

        // The Silver Hand quest in the journal names Aela; its stage change reaches her (once)
        // on the next request, whoever it is to, and moves her revenge; Muiri's alchemy it does not
        pg_query_params($this->db->link, "INSERT INTO quests (ts, gamets, name, briefing, data, stage, giver_actor_id, id_quest, sess, status, localts)
            VALUES ('1', $1, 'The Silver Hand', 'Speak with Aela the Huntress.', $2, 10, '', 'C03', 'pending', '', 1)",
            [self::at(self::N0 + 1, 8.0), json_encode(['Questgiver' => self::AELA, 'Speak with Aela the Huntress.' => 'Speak with Aela the Huntress.'])]);
        pg_query_params($this->db->link, "INSERT INTO questlog (ts, gamets, localts, briefing, data, id_quest, stage) VALUES ('1', $1, 1, $2, $2, 'C03', 10)",
            [self::at(self::N0 + 1, 8.0), 'Speak with <Alias=Questgiver>']);
        $this->turn('Muiri', 'Morning.', self::at(self::N0 + 1, 9.0), 'morning');
        $this->turn(self::LYNLY, 'Morning.', self::at(self::N0 + 1, 9.1), 'morning');
        $aela = $this->dynamics(self::AELA);
        $this->assertCount(1, $aela[RelDynQuests::EVENTS_KEY] ?? [], json_encode($aela[RelDynQuests::EVENTS_KEY] ?? null));
        $revenge = array_column(RelDynGoals::active($aela), null, 'type')['revenge'];
        $this->assertEqualsWithDelta(0.25, $revenge['progress'], 1e-9);
        $this->assertEqualsWithDelta(0.0, array_column(RelDynGoals::active($this->dynamics('Muiri')), null, 'type')['mastery']['progress'], 1e-9);
        $this->assertArrayNotHasKey(RelDynQuests::EVENTS_KEY, $this->dynamics('Muiri'), 'the quest does not name her');
        $jev = RelationshipDynamics::jevStateBlock(self::AELA);
        $this->assertSame('revenge', $jev['intrinsic_goals'][0]['type']);
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ reputation-layer

    public function testAFamousAndInfamousStrangerIsMetWarilyAndDifferently(): void
    {
        $this->seed(false);
        // Core's tracked stats (gamedata skyrim_stats -> core_player): dragon souls and murders
        foreach (['Dragon Souls Collected' => '4', 'Murders' => '3', 'Quests Completed' => '30'] as $stat => $v) {
            pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', [$stat, $v]);
        }
        $this->hello();
        $rep = [];
        foreach (array_keys(self::BEDS) as $npc) $rep[$npc] = $this->dynamics($npc)[RelDynReputation::KEY] ?? [];
        $why = json_encode(array_map(fn($r) => [$r['fame'] ?? null, $r['infamy'] ?? null, $r['raw'] ?? null], $rep));
        foreach ($rep as $npc => $r) {
            $this->assertGreaterThan(0.5, $r['fame'] ?? 0, "{$npc} {$why}");
            $this->assertGreaterThan(0.5, $r['infamy'] ?? 0, "{$npc} {$why}");
            $this->assertLessThan(0.0, $r['raw']['trust'], "{$npc}: murders weigh more than dragons for trust {$why}");
            $this->assertStringContainsString('some admiring and some dark', $this->felt[$npc]['hello']['reputation'] ?? '', $npc . ' ' . json_encode($this->felt[$npc]['hello']));
        }
        $this->assertGreaterThan($rep['Muiri']['raw']['respect'], $rep[self::AELA]['raw']['respect'],
            "Aela, drawn to danger, respects the killer more than Muiri, who shrinks from it {$why}");

        // Five meaningful evenings together: what she heard hardly matters any more
        for ($day = 1; $day <= 5; $day++) {
            $this->event('infoloc', self::HOME, self::at(self::N0 + $day, 17.9));
            $this->turn(self::AELA, 'Tell me about your day.', self::at(self::N0 + $day, 18.0), "day{$day}");
            $stats = RelDynEval::runWorker($this->evalLlm());
            $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        }
        $this->turn(self::AELA, 'Good night.', self::at(self::N0 + 5, 22.0), 'night');
        $r = $this->dynamics(self::AELA)[RelDynReputation::KEY];
        $this->assertSame(5, $r['meaningful']);
        $this->assertLessThan(0.1, RelDynReputation::weight($r));
        $this->assertArrayNotHasKey('reputation', $this->felt[self::AELA]['night']);
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ item-modifiers

    public function testTheSameMeadBookAndAmuletAreFeltFourWays(): void
    {
        $this->seed();
        $this->hello();
        $t = self::at(self::N0 + 1, 19.0);
        $before = [];
        foreach (array_keys(self::BEDS) as $npc) $before[$npc] = $this->dynamics($npc);
        // Each drinks a mead and is given a book; Muiri and Aela wear an Amulet of Mara
        $mara = ['equipment' => ['amulet' => 'Amulet of Mara', 'amulet_baseid' => '000C891B', 'amulet_keywords' => ['ArmorJewelry']]];
        foreach (['Muiri', self::AELA] as $npc) {
            pg_query_params($this->db->link, "UPDATE core_npc_master SET metadata = metadata || $2::jsonb WHERE npc_name = $1", [$npc, json_encode($mara)]);
        }
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) {
            $this->event('infoaction', "{$npc} consumes Nord Mead.", $t + 600 * $i - 30);
            $this->event('itemfound', self::PLAYER . " gave 1 Book of Old Lore to {$npc},(value 25 gold)", $t + 600 * $i - 20);
            $this->turn($npc, 'Here, for you.', $t + 600 * $i, 'gift');
            $this->turn($npc, 'Do you like it?', $t + 600 * $i + 100, 'after');
            $i++;
        }
        $spike = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $spike[$npc] = floatval($d['_active_consumables'][0]['immediate']['comfort'] ?? 0);
        }
        $this->assertGreaterThan($spike[self::AELA], $spike[self::LYNLY], 'the bard loves a tavern mead, the huntress barely notices it ' . json_encode($spike));
        $this->assertStringContainsString('Book of Old Lore', $this->felt['Ashe']['after']['gift'] ?? '', json_encode($this->felt['Ashe']['after']));
        $this->assertNotSame($this->felt['Ashe']['after']['gift'] ?? null, $this->felt[self::AELA]['after']['gift'] ?? null,
            'the scholar and the huntress feel the same book differently');
        $amulet = [];
        foreach (['Muiri', self::AELA] as $npc) {
            $amulet[$npc] = RelationshipDynamics::equippedBaselineOffset($this->dynamics($npc), 'comfort');
        }
        $this->assertGreaterThan($amulet[self::AELA], $amulet['Muiri'], 'Mara\'s amulet means more to the devout apothecary ' . json_encode($amulet));
        $this->assertSame(0.0, RelationshipDynamics::equippedBaselineOffset($this->dynamics('Ashe'), 'comfort'), 'Ashe wears none');
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }
}
