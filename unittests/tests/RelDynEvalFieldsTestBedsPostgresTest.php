<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynEvalFieldsBedsPgDb
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
 * The decisions §8 eval fields end to end with the four test beds (standing rule): Aela the
 * Huntress, Ashe (Serene's hand-set vector), Muiri and Lynly Star-Sung, the player's friends
 * (core Player.type friend, affinity 45), on CHIM 3.4.1 core-shaped rows and the committed
 * seed's reads. Every exchange goes through the real hooks (prerequest -> context ->
 * postrequest), the real eval producer and worker (the LLM stubbed at the connector boundary:
 * it answers the questions the real prompt asks), and the eval inbox. No LLM call.
 *
 *   charisma (MDD 5.1)   an evening of the same flirting with each: romantic_intent 2 and a
 *                        steady +3 affinity grade the player a Charmer with all four (before,
 *                        with no eval, nobody had a style: the old always-Rock is gone); how
 *                        well the Charmer works diverges by who she is (warmth up, pride down):
 *                        the shy bard is charmed, the proud huntress is not;
 *   director goals       each NPC's CHIM goals (HERIKA_GOALS) bridge into her director goal and
 *   (PR 39)              stay active through the evening's play (the April ages expired them
 *                        within seconds of play); the eval sees each purpose, and only Aela's is
 *                        served (the player offers to hunt the Silver Hand): hers is fulfilled,
 *                        the others stay;
 *   social masking       at the Bannered Mare among strangers only an NPC to whom status matters
 *   (MDD 11)             with a Toxic / Avoidant attachment wears the Mask: Muiri does, Aela
 *                        and Lynly do not; the eval sees the front and its truth, is asked
 *                        about masking with the strangers named, and its answer costs Muiri
 *                        maturity and leaves her a slip; home again, her mask drops;
 *   dimensional memory   the eval's summaries carry numbers and named feelings; the reason
 *                        anchors keep the event only, and at resentment 50 they are the
 *                        grievances she is ready to bring up. No digit ever reaches the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynEvalFieldsTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const STEP = 40 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;   // 40 s of play between two lines
    private const N0 = 150;
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice, CHIM goals]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander',
                                'Hunt down the Silver Hand who killed Skjor.'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null,
                                'Study the Dwemer ruins nearby.'],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager',
                                'Make the Shatter-Shields pay for what they did.'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord',
                                'Keep her past quiet and sing at the Vilemyr Inn.'],
    ];
    private const STRANGERS = ['Hulda', 'Mikael'];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';
    private const MARE = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 9:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynEvalFieldsBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $clock;
    private int $llmCalls = 0;
    /** every user prompt the eval LLM got, by NPC */
    private array $prompts = [];
    /** npc => label => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** every block the context hooks added */
    private array $blocks = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_evalfields' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynEvalFieldsBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'HERIKA_GOALS', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdevalfieldsbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_evalfields_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        // Shipped defaults, stored as the config page stores them; social masking switched on
        // (it ships off: a gameplay call)
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'social_masking_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        $this->clock = self::at(self::N0, 18.0);
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

    /** Core rows (friends: Player friend, affinity 45), voice types, placeholder templates and the seed's reads. */
    private function seed(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice, $goals]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, goals, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '', $goals,
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [self::PLAYER => ['aff' => 45, 'type' => 'friend']]])]);
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

    private static function people(array $names): string
    {
        return '|' . implode('|', $names) . '|';
    }

    private function home(): string
    {
        return self::people(array_merge(array_keys(self::BEDS), [self::PLAYER]));
    }

    private function mare(): string
    {
        return self::people(array_merge(array_keys(self::BEDS), self::STRANGERS, [self::PLAYER]));
    }

    /** One player line to $npc through the real hooks, logged as core logs it (input row, then the reply), 40 s of play later. */
    private function turn(string $npc, string $line, string $label, ?string $people = null): void
    {
        $people = $people ?? $this->home();
        $this->clock += self::STEP;
        $gamets = $this->clock;
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $people);
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $people, 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['HERIKA_GOALS'] = self::BEDS[$npc][6];   // core npc_master: the goals column
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $people;
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                foreach ((array) $GLOBALS['contextDataFull'] as $m) $this->blocks[] = (string) ($m['content'] ?? '');
            }
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 40;
    }

    /**
     * The eval LLM at the connector boundary. It answers what the real prompt asks, by the
     * exchange's player line: a flirt (romantic_intent 2, +3), the Silver Hand offer (serves
     * Aela's purpose when it is shown), an insult, and small talk; masking only when asked,
     * and only Muiri keeps a front (it slips). The summaries carry numbers and named feelings.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $user = (string) $messages[1]['content'];
            $npc = preg_match('/^CHARACTER: (.+?) \(whose feelings/m', $user, $m) ? $m[1] : '?';
            $this->prompts[$npc][] = $user;
            $exchange = substr($user, (int) strpos($user, 'THIS EXCHANGE'));
            $exchange = substr($exchange, 0, (int) strpos($exchange, 'TASK:'));
            $out = [
                'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => [], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => 0.2, 'summary' => 'Small talk (0 change).', 'romantic_intent' => 0,
            ];
            if (str_contains($exchange, 'moonlight')) {
                $out['signals']['affinity'] = 3;
                $out['signals']['passion'] = 2;
                $out['tags'] = ['praise'];
                $out['romantic_intent'] = 2;
                $out['summary'] = 'Kaida flirted with her about the moonlight; she liked it (+3)';
            } elseif (str_contains($exchange, 'Silver Hand')) {
                $out['signals']['affinity'] = 4;
                $out['tags'] = ['help'];
                $out['summary'] = 'Kaida offered to hunt the Silver Hand with her';
            } elseif (str_contains($exchange, 'useless')) {
                $out['signals'] = ['affinity' => -10, 'trust' => -6, 'comfort' => -4, 'respect' => -8, 'passion' => 0, 'maturity' => 0];
                $out['tags'] = ['insult'];
                $out['grievance'] = ['flag' => true, 'kind' => 'insult', 'severity' => 2];
                $out['significance'] = 0.7;
                $out['summary'] = 'Kaida called her useless 3 times in front of Hulda; she resents it now (respect -8)';
            }
            if (str_contains($user, 'GOAL_ADDRESSED')) {
                $out['goal_addressed'] = str_contains($exchange, 'Silver Hand') && str_contains($user, 'Silver Hand who killed Skjor');
            }
            if (str_contains($user, 'MASKING (others were present')) {
                $out['masking'] = ['flag' => $npc === 'Muiri', 'slipped' => $npc === 'Muiri' && str_contains($exchange, 'useless')];
            }
            return json_encode($out);
        };
    }

    private function work(): void
    {
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /** Evening at home: the hello, scored. */
    private function hello(): void
    {
        $this->event('infoloc', self::HOME, $this->clock, $this->home());
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Good evening.', 'hello');
        $this->work();
    }

    public function testCharismaIsGradedFromTheEvalAndLandsDifferentlyOnEach(): void
    {
        $this->hello();
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertNull(RelationshipDynamics::charismaStyle($d), "{$npc}: one scored exchange is no style (the old code read everyone as the Rock)");
            $this->assertSame([0], $d['_charisma_tracker']['recent_intents'], "{$npc}: fed by the eval item, once");
        }
        for ($k = 0; $k < 5; $k++) {
            foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'You look lovely in the moonlight.', "flirt{$k}");
            $this->work();
        }
        $mult = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('charmer', RelationshipDynamics::charismaStyle($d), "{$npc}: " . json_encode($d['_charisma_tracker'] ?? null));
            $this->assertSame([0, 2, 2, 2, 2, 2], $d['_charisma_tracker']['recent_intents'], "{$npc}: every exchange once");
            $mult[$npc] = RelationshipDynamics::getCharismaEffectiveness('charmer', $d['inferred_temperament'] ?? null,
                floatval($d['dimensions']['maturity']['x'] ?? 50), 'passion', $d);
            foreach ($this->prompts[$npc] as $p) $this->assertStringContainsString('ROMANTIC_INTENT: 0..3', $p);
        }
        // Who she is decides how the Charmer lands (A24: warmth up, pride down)
        $why = json_encode($mult);
        $this->assertGreaterThan(1.0, $mult['Lynly Star-Sung'], 'the shy, warm bard is charmed ' . $why);
        $this->assertSame(1.0, $mult['Aela the Huntress'], 'the proud huntress is not moved by it ' . $why);
        $this->assertGreaterThan($mult['Aela the Huntress'], $mult['Lynly Star-Sung']);
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures, 'no failed statement');
    }

    public function testEachGoalLastsTheEveningAndOnlyTheOneServedIsFulfilled(): void
    {
        $this->hello();
        $refs = [];
        foreach (self::BEDS as $npc => $bed) {
            $d = $this->dynamics($npc);
            $g = RelationshipDynamics::getActiveDirectorGoal($d);
            $this->assertNotNull($g, "{$npc}: her CHIM goals bridged");
            $this->assertSame($bed[6], $g['text']);
            $refs[$npc] = RelationshipDynamics::directorGoalRef($g);
            $this->assertStringContainsString("{$npc}'S CURRENT PURPOSE: {$bed[6]}", $this->prompts[$npc][0], "{$npc}: the eval sees her purpose");
        }
        // An evening of small talk: 3 rounds, 40 s of play apart (the April 7200 "gamets" lasted ~3 s of play)
        for ($k = 0; $k < 3; $k++) {
            foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Nice weather for it.', "talk{$k}");
            $this->work();
        }
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertGreaterThan(8 * self::STEP, RelationshipDynamics::getPlayGamets($d), "{$npc}: minutes of play went by");
            $this->assertNotNull(RelationshipDynamics::getActiveDirectorGoal($d), "{$npc}: still her purpose");
            $this->assertSame($refs[$npc], RelationshipDynamics::directorGoalRef($d['_director_goal']), "{$npc}: the same goal, never re-bridged");
        }
        // Everyone hears the offer; only Aela's purpose is served by it
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, "I'll help you hunt the Silver Hand tomorrow.", 'offer');
        $this->work();
        $aela = $this->dynamics('Aela the Huntress');
        $this->assertNull(RelationshipDynamics::getActiveDirectorGoal($aela), 'Aela: fulfilled');
        $this->assertSame(['fulfilled', 'eval_confirmed'], [$aela['_director_goal_history'][0]['outcome'], $aela['_director_goal_history'][0]['fulfill_reason']]);
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertNotNull(RelationshipDynamics::getActiveDirectorGoal($this->dynamics($npc)), "{$npc}: not her purpose");
        }
        // The same CHIM goals are not bridged again: done is done until they change
        $this->turn('Aela the Huntress', 'Good hunting today.', 'after');
        $this->assertNull(RelationshipDynamics::getActiveDirectorGoal($this->dynamics('Aela the Huntress')));
        $this->assertArrayNotHasKey('goal', $this->felt['Aela the Huntress']['after']);
        $jev = RelationshipDynamics::jevStateBlock('Muiri');
        $this->assertSame(self::BEDS['Muiri'][6], $jev['goal']['text'] ?? null, 'Jev gets the goal');
        $this->assertSame([], $this->db->failures);
    }

    public function testAtTheMareOnlyMuiriWearsTheMaskAndHomeItDrops(): void
    {
        $this->hello();
        $why = fn() => json_encode(array_map(fn($n) => [RelationshipDynamics::getAttachmentStyle($this->dynamics($n)),
            RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $this->dynamics($n))['Pd'] ?? null], ['Aela the Huntress', 'Muiri', 'Lynly Star-Sung']));
        // Her read gives Muiri a secure attachment (her toxicity is scheming, not how she
        // attaches: an open question for Ken); the editor sets the toxic attachment her story
        // shows, and the same for Lynly, so the two differ only in whether status matters
        foreach (['Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->editDynamics($npc, function (array &$d): void { $d['profile_overrides']['attachment_style'] = 'toxic'; });
        }
        $wears = [];
        foreach (array_keys(self::BEDS) as $npc) $wears[$npc] = RelationshipDynamics::wearsMask($this->dynamics($npc));
        $this->assertSame('toxic', RelationshipDynamics::getAttachmentStyle($this->dynamics('Muiri')));
        $this->assertTrue($wears['Muiri'], 'Muiri: status matters (pride above the centre), toxic attachment ' . $why());
        $this->assertFalse($wears['Lynly Star-Sung'], 'Lynly: toxic too, but status does not matter to her ' . $why());
        $this->assertFalse($wears['Aela the Huntress'], 'Aela: proud, secure-leaning: no Mask ' . $why());

        // Among strangers at the Mare
        $this->event('infoloc', self::MARE, $this->clock, $this->mare());
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Busy in here tonight.', 'mare', $this->mare());
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertSame($wears[$npc], isset($this->felt[$npc]['mare']['mask']), "{$npc}: the mask line follows who she is");
            $this->assertSame($wears[$npc], !empty($this->dynamics($npc)['_was_masking']), "{$npc}: recorded for the eval");
        }
        $this->assertStringContainsString('In front of Aela the Huntress, Ashe, Lynly Star-Sung, Muiri performs ease', $this->felt['Muiri']['mare']['mask']);
        $before = floatval($this->dynamics('Muiri')['dimensions']['maturity']['x']);
        $this->work();
        foreach (array_keys(self::BEDS) as $npc) {
            $p = end($this->prompts[$npc]);
            $this->assertSame($wears[$npc], str_contains($p, 'MASKING (others were present: '), "{$npc}: asked only with the Mask up");
            $this->assertSame($wears[$npc], str_contains($p, 'In front of others: keeps up a front of ease; underneath:'), "{$npc}: the eval sees the front");
        }
        $this->assertStringContainsString('MASKING (others were present: Aela the Huntress, Ashe, Lynly Star-Sung, Hulda, Mikael)', end($this->prompts['Muiri']),
            'the strangers named, from the exchange\'s eventlog rows');
        $this->assertLessThan($before, floatval($this->dynamics('Muiri')['dimensions']['maturity']['x']), 'Muiri: the front costs her');
        $this->assertSame(1, $this->dynamics('Muiri')['_mask_interactions_count']);
        $this->assertSame(0, intval($this->dynamics('Aela the Huntress')['_mask_interactions_count'] ?? 0));

        // Kaida insults her in front of them; the front slips (the eval says so)
        $this->turn('Muiri', "You're useless, Muiri.", 'insult', $this->mare());
        $this->work();
        $this->turn('Muiri', 'Well?', 'after_insult', $this->mare());
        $this->assertArrayHasKey('mask_slip', $this->felt['Muiri']['after_insult'], json_encode(array_keys($this->felt['Muiri']['after_insult'])));
        $this->turn('Muiri', 'Well?', 'again', $this->mare());
        $this->assertArrayNotHasKey('mask_slip', $this->felt['Muiri']['again'], 'a slip is said once');

        // Home, alone with the player: the mask drops, once
        $this->turn('Muiri', 'Let us go home.', 'home', self::people(['Muiri', self::PLAYER]));
        $this->assertArrayHasKey('mask_drop', $this->felt['Muiri']['home']);
        $this->assertArrayNotHasKey('mask', $this->felt['Muiri']['home']);
        $this->turn('Muiri', 'Tea?', 'home2', self::people(['Muiri', self::PLAYER]));
        $this->assertArrayNotHasKey('mask_drop', $this->felt['Muiri']['home2']);
        $this->assertSame([], $this->db->failures);
    }

    public function testReasonAnchorsKeepTheEventAndBecomeGrievancesAtResentmentFifty(): void
    {
        $this->hello();
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, "You're useless.", 'insult');
        $this->work();
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $reasons = array_values(array_unique(array_map(fn($m) => $m['reason'], (array) ($d['dimensional_memory'] ?? []))));
            $this->assertContains('Kaida called her useless three times in front of Hulda', $reasons, "{$npc}: " . json_encode($reasons));
            foreach ((array) $d['dimensional_memory'] as $m) {
                $this->assertDoesNotMatchRegularExpression('/\d|resent|respect/i', $m['reason'], "{$npc}: the event only");
                $this->assertArrayHasKey('gamets', $m);
                $this->assertArrayNotHasKey('ts', $m, 'game time, not the wall clock');
            }
        }
        // At resentment 55 the stings are the grievances. With the resentment arc on (shipped), its
        // confrontation is the one voice: said at each NPC's own attachment threshold, and the
        // memory line leaves out what it said. Ashe, avoidant, holds hers until later.
        $sting = 'Kaida called her useless three times in front of Hulda';
        $said = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d): void { $d['dimensions']['resentment']['x'] = 55.0; });
            $this->turn($npc, 'Can we talk?', 'talk');
            $f = $this->felt[$npc]['talk'];
            $d = $this->dynamics($npc);
            $this->assertArrayNotHasKey('grievances', $f, "{$npc}: one voice, the confrontation carries them");
            $due = RelDynResentment::confrontationThreshold($d) <= 55.0 && !RelationshipDynamics::isPeoplePleaser($d);
            $said[$npc] = isset($f['resentment_confront']);
            $this->assertSame($due, $said[$npc], "{$npc}: said at her own threshold " . json_encode(array_keys($f)));
            if ($said[$npc]) {
                $this->assertStringContainsString($sting, $f['resentment_confront'], "{$npc}: the stored event is what she raises");
                $this->assertStringNotContainsString($sting, (string) ($f['memory'] ?? ''), "{$npc}: not repeated in the memory line");
            }
        }
        $this->assertTrue($said['Aela the Huntress'], 'Aela says it at the secure threshold');
        $this->assertFalse($said['Ashe'], 'Ashe holds it: avoidant, her threshold is higher');

        // With the arc's confrontation switched off, the standing grievances line at the flat
        // MDD 15.5 threshold names the same stings for all four, and the memory line leaves them out
        $this->patchConfig(function (array &$c): void { $c['resentment_arc']['confrontation']['enabled'] = false; });
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d): void { $d['dimensions']['resentment']['x'] = 55.0; });
            $this->turn($npc, 'Can we talk?', 'talk_flat');
            $f = $this->felt[$npc]['talk_flat'];
            $this->assertArrayHasKey('grievances', $f, "{$npc}: " . json_encode(array_keys($f)));
            $this->assertStringContainsString("'{$sting}'", $f['grievances']);
            $this->assertArrayNotHasKey('resentment_confront', $f);
            $this->assertStringNotContainsString($sting, (string) ($f['memory'] ?? ''), "{$npc}: not repeated in the memory line");
        }
        foreach ($this->blocks as $b) $this->assertDoesNotMatchRegularExpression('/\d/', $b, "no number reaches the LLM:\n{$b}");
        $this->assertSame([], $this->db->failures);
    }

    /** Change the stored config the way the config page does (conf_opts row), then reload it. */
    private function patchConfig(callable $edit): void
    {
        $r = pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID]);
        $cfg = json_decode((string) pg_fetch_result($r, 0, 0), true);
        $edit($cfg);
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
    }
}
