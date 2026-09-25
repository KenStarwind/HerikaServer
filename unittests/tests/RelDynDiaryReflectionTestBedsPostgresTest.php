<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynDiaryBedsPgDb
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
 * Self-reflection riding on core's diary (roadmap diary-trigger, diary-reflection-eval; decisions
 * 2026-09-23 §7; memory feedback_diary_system) end to end with the four test beds (standing rule
 * feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set vector, never read; her
 * diary pages here say nothing of her story), Muiri and Lynly Star-Sung, each the player's partner,
 * on CHIM 3.4.1 core-shaped rows (diarylog as data/database_default.sql), through the real hooks
 * (prerequest -> context -> postrequest), the real eval producer and worker, and the diary
 * reflection's own worker tail (every LLM stubbed at the connector boundary):
 *   - a day together and one moment that mattered are marked; core's auto diary writes that
 *     night's page; the next morning each NPC takes stock at the depth her own maturity allows;
 *   - baseline mode: pure math, no LLM call; trajectory mode: one call per reflecting NPC, which
 *     sees feelings, never numbers, and whose verdict lands on her next request;
 *   - a page written while a drink is on her waits for the sober self; a failed call falls back to
 *     the baseline verdict; a page a save load discarded is never reflected on.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynDiaryReflectionTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 120;   // game day of the hello
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    /** Each NPC's diary pages as core's diary LLM would write them (no digits, nothing of Ashe's story). */
    private const PAGES = [
        'Aela the Huntress' => ['Kaida came back with the others tonight. Good to have someone at my side on the hunt again.',
                                'A long day at Kaida\'s side, and tonight words I will not forget. I let myself be glad of it.',
                                'Another quiet day. I am learning to rest when the hunt is over.'],
        'Ashe'              => ['Quiet evening. Kaida talked more than I did. I kept my answers short again.',
                                'Another day together. I noticed I answered honestly for once. Strange, how easy it was.',
                                'I said less than I meant to today. Old habit.'],
        'Muiri'             => ['Everyone in this town smiles and lies. Kaida at least says what they want.',
                                'Kaida spent the whole day with me. They will want something for it. Everyone does.',
                                'Nobody here deserves what they have. I will get mine.'],
        'Lynly Star-Sung'   => ['Sang a little for the house tonight. My voice shook at the start, as it always does.',
                                'Kaida listened to every verse today. I could not look up, but I did not stop singing.',
                                'I sang the old song through without stopping. Nobody laughed.'],
    ];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynDiaryBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** The diary reflection calls: npc => list of messages. */
    private array $diaryCalls = [];
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** The editor's change right after the hello (before any moment), or null. */
    private $editDynamicsAfterHello = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_diary_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog and diarylog (rowid from its own sequence)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
        pg_query($admin, "CREATE INDEX idx_diarylog_people_gamets ON diarylog (lower(trim(people)), gamets DESC, localts DESC, rowid DESC)");
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

        $this->db = new RelDynDiaryBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the calls themselves are stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rddiarybeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_diary_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        RelDynDiary::$launcher = function (): void {};
        RelDynDiary::$llm = function () { $this->llmCalls++; return null; };
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        RelDynDiary::$launcher = null;
        RelDynDiary::$llm = null;
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

    /** Shipped defaults (plus $over), stored as the config page stores them. */
    private function config(array $over = []): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows (partners: Player romantic, affinity 60), voice types, placeholder templates and the seed's reads. */
    private function seed(): void
    {
        $this->config();
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
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic']]])]);
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
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
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

    /**
     * A page in $npc's diary as core's generateFollowerDiary stores it (lib/dynamic_update_util.php:
     * the game time of the sleep, "(Auto-diary: goodnight)" topic, people = the NPC). Returns its rowid.
     */
    private function diary(string $npc, int $gamets, string $content): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO diarylog (ts, gamets, topic, content, tags, people, location, sess, localts)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING rowid',
            [(string) $this->realTs, $gamets, 'Sundas, night (Auto-diary: goodnight)', $content, 'Auto-diary,goodnight',
             $npc, 'Breezehome', (string) $this->realTs, $this->realTs]));
        return intval($r['rowid']);
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
     * The eval LLM at the connector boundary: a day together is quality time (trust, comfort,
     * respect, a little affinity); the vow is the moment that matters (significance 1.0); anything
     * else is small talk. Never romantic.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $together = str_contains($exchange, 'about your day');
            $vow = str_contains($exchange, 'come back for you');
            return json_encode([
                'signals' => ['affinity' => $together ? 2 : ($vow ? 4 : 0), 'trust' => $together ? 4 : ($vow ? 6 : 0),
                              'comfort' => $together ? 3 : 0, 'respect' => $together ? 2 : 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $together ? ['quality_time'] : ($vow ? ['praise'] : []),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $vow ? 1.0 : ($together ? 0.4 : 0.1),
                'summary' => $vow ? 'The player promised always to come back for her.' : ($together ? 'A quiet day together.' : 'Small talk.'),
                'romantic_intent' => 0,
            ]);
        };
    }

    /** $line to each of $npcs from $gamets (ten game minutes apart), then the real eval worker. Returns the next free gamets. */
    private function round(int $gamets, string $line, array $npcs, string $label): int
    {
        $this->event('infoloc', self::HOME, $gamets - 100, $this->home());
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 600 * $i++, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
        return $gamets + 600 * $i;
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    /**
     * The hello (evening of N0) and that night's first page for each; a day together (sixteen
     * talks after half an hour of play: past the trigger's play-time cooldown and interaction gap),
     * then the vow, then good night: the moment is marked. Core's auto diary writes that night's
     * page. Returns npc => dynamics before the morning.
     */
    private function dayTogether(): array
    {
        $all = array_keys(self::BEDS);
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        foreach ($all as $npc) $this->turn($npc, 'Well met, love.', self::at(self::N0, 18.0) + 600 * $i++, 'hello');
        if ($this->editDynamicsAfterHello !== null) ($this->editDynamicsAfterHello)();
        foreach ($all as $npc) $this->diary($npc, self::at(self::N0, 23.0), self::PAGES[$npc][0]);

        $t = max($this->play(self::at(self::N0 + 1, 8.0), 31.0), self::at(self::N0 + 1, 9.0));
        $t = $this->round($t, 'Good morning.', $all, 'first_morning') + 60;
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            $this->assertCount(1, RelDynDiary::snapshots($d), "{$npc}: the first page is the reference point");
            $this->assertArrayNotHasKey('_diary_last_verdict', $d, "{$npc}: nothing to take stock of yet");
        }
        for ($r = 0; $r < 16; $r++) $t = $this->round($t, 'Tell me about your day.', $all, "day_{$r}") + 60;
        $t = $this->round($t, 'I will always come back for you.', $all, 'vow') + 60;
        $this->round($t, 'Good night.', $all, 'night');
        $before = [];
        foreach ($all as $npc) {
            $before[$npc] = $this->dynamics($npc);
            $this->diary($npc, self::at(self::N0 + 1, 23.5), self::PAGES[$npc][1]);
        }
        return $before;
    }

    /** No digit in anything the felt steering put in front of the dialogue LLM. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
    }

    /** npc => stored dynamics, all four. */
    private function all(): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) $out[$npc] = $this->dynamics($npc);
        return $out;
    }

    /** The diary reflection LLM at the connector boundary: reads the writer from the call. */
    private function diaryLlm(array $verdicts): callable
    {
        return function (array $messages, array $params) use ($verdicts) {
            $this->assertSame('system', $messages[0]['role']);
            preg_match('/^Writer: (.+)$/m', (string) $messages[1]['content'], $m);
            $npc = $m[1] ?? '?';
            $this->diaryCalls[$npc][] = $messages;
            $v = $verdicts[$npc] ?? null;
            if ($v instanceof \Throwable) throw $v;
            return is_string($v) ? $v : json_encode($v);
        };
    }

    // ------------------------------------------------------------------ baseline mode

    /**
     * Baseline mode (the default, no LLM): Muiri, whom the editor set at maturity 15, marks no
     * moment and takes no stock (the dimension draft: below ~20 the diary is shallow, "cannot
     * self-correct"); the other three read the night's page, find the day grew the bond (trust,
     * comfort, respect, affinity since the first page) and grow: maturity up by who each is
     * (her plasticity, how far from her own baseline), self-confidence after its evidence.
     */
    public function testBaselineReflectionRidesOnCoresDiary(): void
    {
        $this->editDynamicsAfterHello = function (): void {
            $this->editDynamics('Muiri', function (array &$d): void {
                $d['dimensions']['maturity']['x'] = 15.0;
                $d['dimensions']['maturity']['baseline'] = 15.0;
            });
        };
        $before = $this->dayTogether();
        $this->assertSame([], $before['Muiri']['_diary_moments'] ?? [], 'Muiri: nothing marked at maturity fifteen');
        foreach ($before as $npc => $d) {
            $this->assertSame(3, $d['_diary_significance_peak'] ?? null,
                "{$npc}: the vow the eval worker applied waits on her for the next mark");
        }
        foreach (['Aela the Huntress', 'Ashe', 'Lynly Star-Sung'] as $npc) {
            $this->assertNotEmpty($before[$npc]['_diary_moments'], "{$npc}: the day is marked");
            $this->assertSame([], $before[$npc]['_diary_pending_triggers'], "{$npc}: kept, not pending");
        }

        $this->round(self::at(self::N0 + 2, 8.0), 'Good morning.', array_keys(self::BEDS), 'morning');
        $after = $this->all();
        $gain = [];
        foreach ($after as $npc => $d) {
            $this->assertCount(2, RelDynDiary::snapshots($d), "{$npc}: a snapshot at each page read");
            $this->assertSame([], $d['_diary_moments'] ?? [], "{$npc}: the moments are spent");
            $this->assertSame((int) pg_fetch_result(pg_query_params($this->db->link,
                'SELECT max(rowid) FROM diarylog WHERE people = $1', [$npc]), 0, 0), $d['_diary_core_rowid'], "{$npc}: her pages are read");
            if ($npc === 'Muiri') {
                $this->assertArrayNotHasKey('_diary_last_verdict', $d, 'Muiri: a shallow diary');
                $this->assertEqualsWithDelta(self::x($before[$npc], 'maturity'), self::x($d, 'maturity'), 1e-9);
                continue;
            }
            $v = $d['_diary_last_verdict'];
            $this->assertSame(['growing', 1, 'baseline'], [$v['verdict'], $v['strength'], $v['source']], $npc);
            $gain[$npc] = self::x($d, 'maturity') - self::x($before[$npc], 'maturity');
            $this->assertGreaterThan(0.0, $gain[$npc], "{$npc}: maturity grows");
            $this->assertGreaterThan(self::x($before[$npc], 'self_confidence'), self::x($d, 'self_confidence'),
                "{$npc}: respected and steadier: self-confidence follows its evidence");
        }
        $this->assertCount(3, array_unique(array_map(fn($g) => (string) round($g, 3), $gain)), 'who she is decides how much: ' . json_encode($gain));
        $this->assertSame('Ashe', array_search(min($gain), $gain, true), 'Ashe, already at the maturity she is, moves least: ' . json_encode($gain));
        $this->assertSame(0, $this->llmCalls, 'baseline mode: no LLM call');
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ trajectory mode

    /**
     * Trajectory mode: the morning queues one job per reflecting NPC (nothing applied yet); the
     * worker's tail makes one call each, reading her last pages (feelings, never numbers) and
     * validating the verdict; her next request applies it. The stubbed reader hears Muiri's page as
     * a spiral, Ashe's as stagnation, Aela's and Lynly's as growth; the deltas diverge with it.
     */
    public function testTrajectoryReflectionReadsHerPagesOnce(): void
    {
        $this->config(['diary_reflection_mode' => 'trajectory']);
        $before = $this->dayTogether();
        foreach (array_keys(self::BEDS) as $npc) $this->diary($npc, self::at(self::N0 + 1, 23.6), self::PAGES[$npc][2]);
        $t = $this->round(self::at(self::N0 + 2, 8.0), 'Good morning.', array_keys(self::BEDS), 'morning') + 60;
        $queued = pg_fetch_all(pg_query($this->db->link, "SELECT npc_name, status FROM reldyn_diary_reflections ORDER BY id")) ?: [];
        $this->assertSame(array_keys(self::BEDS), array_column($queued, 'npc_name'));
        $this->assertSame(['pending'], array_values(array_unique(array_column($queued, 'status'))));
        foreach ($this->all() as $npc => $d) {
            $this->assertArrayNotHasKey('_diary_last_verdict', $d, "{$npc}: nothing applied before the call");
            $this->assertSame([], $d['_diary_moments'], "{$npc}: the moments went with the job");
        }
        $this->assertSame(0, $this->llmCalls);

        $stats = RelDynDiary::drain($this->diaryLlm([
            'Aela the Huntress' => ['trajectory' => 'growing', 'strength' => 'strong'],
            'Ashe'              => ['trajectory' => 'stagnating', 'strength' => 'light'],
            'Muiri'             => "```json\n{\"trajectory\": \"spiraling\", \"strength\": \"strong\"}\n```",
            'Lynly Star-Sung'   => ['trajectory' => 'growing'],
        ]), fn() => false);
        $this->assertSame(['processed' => 4, 'done' => 4, 'dropped' => 0, 'failed' => 0, 'dead' => 0],
            array_intersect_key($stats, array_flip(['processed', 'done', 'dropped', 'failed', 'dead'])));
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertCount(1, $this->diaryCalls[$npc] ?? [], "{$npc}: one call");
            $user = $this->diaryCalls[$npc][0][1]['content'];
            foreach (self::PAGES[$npc] as $page) $this->assertStringContainsString($page, $user, "{$npc} reads her own pages");
            $this->assertLessThan(strpos($user, self::PAGES[$npc][2]), strpos($user, self::PAGES[$npc][0]), 'oldest first');
            $this->assertStringContainsString('Since they last took stock: ', $user);
            $this->assertStringContainsString("{$npc} is capable of honest self-examination.", $user);
            foreach (array_keys(self::BEDS) as $other) {
                if ($other !== $npc) $this->assertStringNotContainsString(self::PAGES[$other][1], $user, "{$npc}: only her own diary");
            }
            $this->assertDoesNotMatchRegularExpression('/\d/', $this->diaryCalls[$npc][0][0]['content'] . $user, "{$npc}: feelings, never numbers");
        }

        $this->round($t, 'Did you sleep well?', array_keys(self::BEDS), 'second_morning');
        $after = $this->all();
        $this->assertSame(0, intval(pg_fetch_result(pg_query($this->db->link, 'SELECT count(*) FROM reldyn_diary_reflections'), 0, 0)), 'applied once, then gone');
        $expect = ['Aela the Huntress' => ['growing', 2], 'Ashe' => ['stagnating', 1], 'Muiri' => ['spiraling', 2], 'Lynly Star-Sung' => ['growing', 1]];
        foreach ($expect as $npc => [$verdict, $strength]) {
            $v = $after[$npc]['_diary_last_verdict'];
            $this->assertSame([$verdict, $strength, 'trajectory'], [$v['verdict'], $v['strength'], $v['source']], $npc);
        }
        $this->assertGreaterThan(self::x($before['Aela the Huntress'], 'maturity'), self::x($after['Aela the Huntress'], 'maturity'));
        $this->assertGreaterThan(self::x($before['Lynly Star-Sung'], 'maturity'), self::x($after['Lynly Star-Sung'], 'maturity'));
        $this->assertLessThan(self::x($before['Muiri'], 'maturity'), self::x($after['Muiri'], 'maturity'), 'Muiri spirals');
        $this->assertGreaterThan(0.0, self::x($after['Ashe'], 'resentment_self'), 'Ashe, circling: the frustration is her own');
        $this->assertSame(0.0, self::x($after['Lynly Star-Sung'], 'resentment_self'));
        $this->assertSame(4, array_sum(array_map('count', $this->diaryCalls)), 'one stubbed call per reflecting NPC');
        $this->assertSame(0, $this->llmCalls);
        $this->assertFeelingsNotNumbers();
        $this->assertSame([], $this->db->failures);
    }

    /**
     * The edges, in trajectory mode: a page written while a drink is on Aela waits for her sober
     * self; a call that fails (Lynly: the connector throws; Ashe: an answer that is not the signal)
     * dies after max_attempts and her baseline verdict, taken when queued, applies instead; a page
     * a save load discarded (core deletes diarylog rows at and after the loaded time) is never
     * reflected on, and its call is never made.
     */
    public function testTheSoberSelfFailedCallsAndDiscardedPages(): void
    {
        $this->config(['diary_reflection_mode' => 'trajectory', 'diary_reflection' => ['max_attempts' => 1]]);
        $this->dayTogether();
        $this->editDynamics('Aela the Huntress', function (array &$d): void {
            $d['_active_consumables'] = [['key' => 'ale', 'item_name' => 'Nord Mead', 'immediate' => ['comfort' => 10.0],
                'expires_gamets' => self::at(self::N0 + 2, 12.0)]];
        });
        $t = $this->round(self::at(self::N0 + 2, 8.0), 'Good morning.', array_keys(self::BEDS), 'morning') + 60;
        $aela = $this->dynamics('Aela the Huntress');
        $this->assertCount(1, RelDynDiary::snapshots($aela), 'Aela: the page waits while the mead is on her');
        $this->assertNotEmpty($aela['_diary_moments']);
        $this->assertSame(['Ashe', 'Muiri', 'Lynly Star-Sung'],
            array_column(pg_fetch_all(pg_query($this->db->link, 'SELECT npc_name FROM reldyn_diary_reflections ORDER BY id')) ?: [], 'npc_name'));

        // a load back to before Muiri's page: core deletes it (processor/comm.php, gamets >= T)
        pg_query_params($this->db->link, 'DELETE FROM diarylog WHERE people = $1 AND gamets >= $2', ['Muiri', self::at(self::N0 + 1, 23.0)]);
        $stats = RelDynDiary::drain($this->diaryLlm([
            'Ashe' => 'She seems to be doing fine, honestly.',
            'Lynly Star-Sung' => new RuntimeException('connector timeout'),
            'Muiri' => ['trajectory' => 'spiraling', 'strength' => 'strong'],
        ]), fn() => false);
        $this->assertSame(['processed' => 3, 'done' => 0, 'dropped' => 1, 'failed' => 0, 'dead' => 2],
            array_intersect_key($stats, array_flip(['processed', 'done', 'dropped', 'failed', 'dead'])));
        $this->assertArrayNotHasKey('Muiri', $this->diaryCalls, 'no call for a discarded page');
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringContainsString('dropped: a save load discarded its diary entry', $log);
        $this->assertStringContainsString('(dead: the baseline verdict applies): RuntimeException: connector timeout', $log);
        $this->assertStringContainsString('malformed: not a JSON object', $log);

        // the mead wore off (the expiry tick's work); the next talk
        $this->editDynamics('Aela the Huntress', function (array &$d): void { $d['_active_consumables'] = []; });
        $this->round($t, 'Did you sleep well?', array_keys(self::BEDS), 'second_morning');
        $after = $this->all();
        foreach (['Ashe', 'Lynly Star-Sung'] as $npc) {
            $v = $after[$npc]['_diary_last_verdict'];
            $this->assertSame(['growing', 1, 'baseline_fallback'], [$v['verdict'], $v['strength'], $v['source']], $npc);
        }
        $this->assertArrayNotHasKey('_diary_last_verdict', $after['Muiri'], 'Muiri: the discarded page is never reflected on');
        $this->assertCount(2, RelDynDiary::snapshots($after['Aela the Huntress']), 'Aela, sober: her page is read');
        $this->assertSame(['Aela the Huntress'], array_column(pg_fetch_all(pg_query($this->db->link,
            "SELECT npc_name FROM reldyn_diary_reflections WHERE status = 'pending'")) ?: [], 'npc_name'), 'her reflection is queued now');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }
}
