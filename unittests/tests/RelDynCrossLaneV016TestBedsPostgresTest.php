<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCrossLaneV016PgDb
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
 * Integration v0.16: where the rulings lane (decisions §18) and the protocols review meet, end to
 * end with the four test beds (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe
 * (Serene's hand-set vector, never read), Muiri (fearful from her preset) and Lynly Star-Sung, each
 * the player's partner (core Player.type romantic, affinity 60), on CHIM 3.4.1 core-shaped rows
 * (moods_issued of data/table_moods_issued.sql, core's speech table) and the committed seed's
 * reads, through the real hooks, the real eval producer and worker (LLM stubbed at the connector
 * boundary). No LLM call.
 *   - The charisma the eval grades (§18 #11) is what the Ick reads: a player graded the Catalyst
 *     who then presses for kisses reaches the Ick sooner with the mature (MDD 5.1: the Catalyst's
 *     push is felt as pressure by a grounded NPC), and only there.
 *   - A continue answering Mikael's flirting (the continue fix) is his exchange with her: no Ick
 *     attempt of the player's, no interaction of the player pair; the player's own pressing is.
 * Feelings, never numbers, in front of the LLM.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCrossLaneV016TestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const SUITOR = 'Mikael';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the hello
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
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynCrossLaneV016PgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_x16_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog; moods_issued of data/table_moods_issued.sql
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE moods_issued (sess character varying(1024), speaker text, mood text, listener text,
            localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY)");
        // core's speech table (data/database_default.sql plus the live 3.4.1 mood / emotion columns):
        // main.php reads its last row's speaker for a continue
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
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

        $this->db = new RelDynCrossLaneV016PgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'RECHAT_PREVIOUS_SPEAKER', 'RECHAT_REQUEST_PAYLOAD'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdx16beds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_x16_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
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

    /** Core rows (partners: Player romantic, affinity 60), Mikael, voice types, placeholder templates and the seed's reads. */
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
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
            [self::SUITOR, 'male', 'ImperialRace', '', 'Roleplay as Mikael', '', json_encode(['skills' => $all]),
             json_encode(['class' => ['name' => 'Bard', 'formid' => '0x0001317f'], 'factions' => [], 'relationships' => []])]);
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
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

    /** A spoken line as core records it in its speech table (what a continue answers). */
    private function speech(string $speaker, string $listener, string $text, int $gamets): void
    {
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', ['pending', $speaker, $text, 'Breezehome', $listener, $this->realTs, $gamets, $gamets]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::SUITOR . '|' . self::PLAYER . '|';
    }

    /**
     * $npc's request through the real hooks (prerequest, context, postrequest), her reply logged
     * as core logs it (the chat row and the mood she said it in, to $listener). Core resolves a
     * continue's previous speaker only after the prerequest hooks ($previousSpeaker).
     */
    private function request(string $npc, array $request, string $listener, string $label, ?string $previousSpeaker = null): void
    {
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to {$listener})", (int) $request[2], 'emitted');
                pg_query_params($this->db->link, 'INSERT INTO moods_issued (sess, speaker, mood, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                    ['pending', $npc, 'default', $listener, $this->realTs, (int) $request[2], (int) $request[2]]);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = $listener;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            if ($previousSpeaker !== null && $hook !== 'prerequest.php') $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = $previousSpeaker;
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** One player line to $npc at $gamets, logged as core logs it (input row, spoken line, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets);
        $this->speech(self::PLAYER, $npc, $line, $gamets);
        $this->request($npc, ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"], self::PLAYER, $label);
    }

    /** The same line to all four, 100 game seconds apart from $t, then the eval worker (stubbed LLM). Returns the next free gamets. */
    private function round(string $line, int $t, string $label): int
    {
        foreach (array_keys(self::BEDS) as $i => $npc) $this->turn($npc, $line, $t + 100 * $i, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
        return $t + 100 * count(self::BEDS);
    }

    /** $minutes of play (core 'request' rows 5 real seconds apart): the play clock runs on it. */
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
     * The eval LLM at the connector boundary, by the player's line in THIS EXCHANGE:
     *   "I dare you" (he challenges her to be more than she is): graded catalyst;
     *   "kiss me" (open romantic pursuit, romantic_intent 3), with "I dare you" graded catalyst too;
     *   anything else: small talk, no particular approach.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $from = (int) strpos($content, 'THIS EXCHANGE');
            $exchange = substr($content, $from, max(0, (int) strpos($content, "\nTASK:", $from) - $from));
            $dare = str_contains($exchange, 'I dare you');
            $kiss = str_contains($exchange, 'kiss me');
            return json_encode([
                'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => $dare ? 1 : 0, 'passion' => $kiss ? 3 : 0, 'maturity' => 0],
                'tags' => [],
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $kiss ? 0.3 : 0.1,
                'summary' => $kiss ? 'The player pressed for a kiss.' : ($dare ? 'The player challenged her.' : 'Small talk.'),
                'romantic_intent' => $kiss ? 3 : 0,
                'charisma' => $dare ? 'catalyst' : 'none',
            ]);
        };
    }

    /** Evening at Breezehome: everyone with the player. */
    private function hello(): int
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0));
        $t = $this->round('Well met, love.', self::at(self::N0, 18.0), 'hello');
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d['_core_rel_type'], "{$npc}: a partner");
        }
        return $t;
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

    /** Each partner cold toward the player, from her side: comfort pushed below where she rests, no passion. */
    private function coldPartners(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$dd): void {
                $dd['dimensions']['comfort']['x'] = min(25.0, floatval($dd['dimensions']['comfort']['baseline']) - 8.0);
                $dd['dimensions']['passion']['x'] = 5.0;
            });
        }
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /** The Ick's threshold for $d as checkIckTrigger reads it (maturity, attachment; $catalyst: the mature NPC's Catalyst cut). */
    private static function ickThreshold(array $d, bool $catalyst): float
    {
        $maturity = self::x($d, 'maturity');
        $t = floatval(RelationshipDynamics::getConfig()['ick_base_threshold'] ?? RelationshipDynamics::ICK_BASE_THRESHOLD) * (1 + $maturity / 100.0);
        if ($catalyst && $maturity > 60) $t *= 0.7;
        return $t * RelDynProtocols::ickAvoidanceMult($d);
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

    // ------------------------------------------------------------------ charisma grade x the Ick

    /**
     * §18 #11 x the protocols review: the style the Ick reads is the one the eval grades. Four
     * cold partners (comfort pushed below where each rests, no passion). The player challenges
     * them twice, then presses each for kisses, four times, all graded catalyst by the eval: the
     * Catalyst with all four once the window holds enough grades. The Ick comes where the design
     * puts it: Ashe, the mature one (maturity above the Catalyst's line), feels the Catalyst's
     * push as pressure at a share of courting her own threshold would not have reached; for the
     * others the same share is below their own thresholds (maturity, attachment), and they do not
     * feel it yet. The next evening the LLM hears it from Ashe only, as a feeling.
     */
    public function testTheCatalystsPushReachesTheMatureSooner(): void
    {
        $t = $this->hello();
        $this->coldPartners();
        $t = $this->play($t + 600, 20.0);
        for ($k = 0; $k < 2; $k++) $t = $this->round('I dare you to prove me wrong about you.', $t + 600, "dare{$k}");
        $all = array_keys(self::BEDS);
        foreach ($all as $npc) {
            $this->assertNull(RelationshipDynamics::charismaStyle($this->dynamics($npc)), "{$npc}: too few graded exchanges for a style yet");
        }
        // Pressing for kisses, graded catalyst: the share of courting in her window climbs
        $trace = [];
        $firstAt = [];
        for ($k = 0; $k < 4; $k++) {
            $t = $this->round('Come here and kiss me. I dare you.', $t + 600, "kiss{$k}");
            foreach ($all as $npc) {
                $tr = $this->dynamics($npc)['_ick_tracker'] ?? [];
                $trace[$npc][] = [intval($tr['romantic_count'] ?? 0), intval($tr['total_count'] ?? 0), !empty($tr['ick_active'])];
                if (!empty($tr['ick_active']) && !isset($firstAt[$npc])) {
                    $firstAt[$npc] = intval($tr['romantic_count']) / max(1, intval($tr['total_count']));
                }
            }
        }
        $threshold = [];
        $plain = [];
        $active = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $threshold[$npc] = self::ickThreshold($d, true);
            $plain[$npc] = self::ickThreshold($d, false);
            $active[$npc] = !empty($d['_ick_tracker']['ick_active']);
        }
        $why = json_encode(['trace' => $trace, 'threshold' => $threshold, 'plain' => $plain, 'first' => $firstAt]);
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('catalyst', RelationshipDynamics::charismaStyle($d), "{$npc}: " . json_encode($d['_charisma_tracker'] ?? null));
            $this->assertSame([4, 7], array_slice(end($trace[$npc]), 0, 2), "{$npc}: every kiss counted once {$why}");
        }
        $this->assertSame(['Aela the Huntress' => false, 'Ashe' => true, 'Muiri' => false, 'Lynly Star-Sung' => false], $active, $why);
        $this->assertGreaterThan(60.0, self::x($this->dynamics('Ashe'), 'maturity'), 'Ashe is past the Catalyst line');
        $this->assertLessThan($plain['Ashe'], $firstAt['Ashe'], "the Catalyst cut is what reached her {$why}");
        $this->assertGreaterThanOrEqual($threshold['Ashe'], $firstAt['Ashe'], $why);
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $npc) {
            $this->assertLessThanOrEqual(60.0, self::x($this->dynamics($npc), 'maturity'), $npc);
            $this->assertSame($plain[$npc], $threshold[$npc], "{$npc}: the Catalyst does not cut hers");
            $this->assertLessThan($plain[$npc], 4 / 7, "{$npc}: the same share is below her own threshold {$why}");
        }

        // The next evening: the LLM hears it from Ashe, as a feeling
        $this->round('Good evening.', $t + self::DAY, 'evening');
        $this->assertStringContainsString('steps back when Kaida leans in', (string) ($this->felt['Ashe']['evening']['ick'] ?? ''), $why);
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $npc) $this->assertArrayNotHasKey('ick', $this->felt[$npc]['evening'], $npc);
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertGreaterThan(0, $this->evalCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the continue fix x the Ick

    /**
     * The continue fix x the protocols review: Mikael presses each partner for a kiss, three
     * times, and she answers him (a continue; core's last speech row is his line). That is his
     * exchange with her: no interaction of the player pair, no attempt of the player's on her Ick
     * window, nothing for the eval to score between them. The player pressing her himself is his,
     * and counts.
     */
    public function testMikaelsCourtingIsNoPressureOfThePlayers(): void
    {
        $t = $this->hello();
        $this->coldPartners();
        $t = $this->play($t + 600, 20.0);
        $all = array_keys(self::BEDS);
        $before = [];
        foreach ($all as $npc) $before[$npc] = $this->dynamics($npc);
        $evalBefore = $this->evalCalls;
        foreach ($all as $k => $npc) {
            for ($j = 0; $j < 3; $j++) {
                $at = $t + 600 * (3 * $k + $j);
                $this->event('chat', self::SUITOR . ": Come here and kiss me. (talking to {$npc})", $at, 'emitted');
                $this->speech(self::SUITOR, $npc, 'Come here and kiss me.', $at);
                $this->request($npc, ['continue', (string) $this->realTs, (string) ($at + 20), ''], self::SUITOR, "mikael{$j}", self::SUITOR);
            }
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $this->assertSame($evalBefore, $this->evalCalls, 'nothing of the player pair to score');
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame($before[$npc]['_ick_tracker'] ?? null, $d['_ick_tracker'] ?? null, "{$npc}: his courting is not on the player's window");
            $this->assertSame(intval($before[$npc]['interaction_count'] ?? 0), intval($d['interaction_count'] ?? 0), $npc);
            $this->assertSame($before[$npc]['_last_contact_gamets'] ?? null, $d['_last_contact_gamets'] ?? null, $npc);
            for ($j = 0; $j < 3; $j++) $this->assertSame([], $this->felt[$npc]["mikael{$j}"], "{$npc}: nothing player-directed");
        }
        // The player's own pressing is his
        $this->round('Come here and kiss me.', $t + self::DAY, 'kaida');
        foreach ($all as $npc) {
            $tr = $this->dynamics($npc)['_ick_tracker'] ?? [];
            $this->assertSame(intval($before[$npc]['_ick_tracker']['romantic_count'] ?? 0) + 1, intval($tr['romantic_count'] ?? 0), "{$npc}: " . json_encode($tr));
            $this->assertSame(intval($before[$npc]['_ick_tracker']['total_count'] ?? 0) + 1, intval($tr['total_count'] ?? 0), $npc);
        }
        $this->assertFeelingsNotNumbers();
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertSame([], $this->db->failures);
    }
}
