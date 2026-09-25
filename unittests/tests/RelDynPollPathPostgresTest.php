<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection that also records every statement it runs. */
final class RelDynPollCountingPgDb
{
    public $link;
    public array $failures = [];
    public ?array $recorded = null;   // statements since startRecording(), null = not recording

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function startRecording(): void { $this->recorded = []; }

    public function stopRecording(): array
    {
        $r = $this->recorded ?? [];
        $this->recorded = null;
        return $r;
    }

    private function note(string $q): void
    {
        if ($this->recorded !== null) $this->recorded[] = trim(preg_replace('/\s+/', ' ', $q));
    }

    public function fetchOne($q, array $params = [])
    {
        $this->note($q);
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }

    public function execQuery($q)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $q = "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
        $this->note($q);
        $res = @pg_query_params($this->link, $q, array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * prerequest-on-poll: what RelDyn does for the AIAgent plugin's poll of main.php.
 *
 * On CHIM 3.4.1 the plugin asks main.php for queued responses with a 'request' event every
 * POLINT real seconds (AIAgent.ini; 1 without an ini). The poll carries no NPC profile
 * (HTTPManager::log without an actor sends no &profile=), so main.php loads none and
 * HERIKA_NAME stays conf.php's default ('The Narrator' in conf.sample.php). main.php runs the
 * ext prerequest hooks, then processor/comm.php answers the poll (DataDequeue), logs it to
 * eventlog when time() % 5 == 0 and sets $MUST_END, and main.php terminates before
 * context_pre / context / postrequest: of RelDyn's hooks the poll reaches prerequest.php only.
 *
 * RelDyn's prerequest takes the poll by its type, never by the NPC name core left in place (a
 * conf.php whose default HERIKA_NAME is an NPC must not make every poll a contact with her),
 * and does only what a poll is for:
 *   - the save-load reconcile, on the first poll after core restored a load (poll.save_load);
 *   - the global play heartbeat beat (poll.play_clock), so it is current when anyone speaks;
 * no bond is read or written and nothing is published to other extensions. A dialogue gets
 * the same result whether polls ran before it or not.
 *
 * The four test beds (Aela the Huntress, Ashe, Muiri, Lynly Star-Sung) are met through the real
 * hooks from the committed seed's reads; no LLM call. Opt-in: RELDYN_TEST_PG_DSN must point
 * at a THROWAWAY database (never dbname=dwemer). RELDYN_POLL_REPORT=1 prints the per-poll query
 * counts.
 */
final class RelDynPollPathPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    /** Game time between two logged polls: core logs one about every 5 real seconds. */
    private const STEP = 5 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    /** Statements one poll may run: config, latest load, marker, heartbeat read, eventlog chunk, write or rebuild check. */
    private const POLL_QUERY_BUDGET = 6;
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];

    private string $dsn;
    private array $schemas = [];
    private RelDynPollCountingPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs;
    private float $gamets;
    private int $llmCalls = 0;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;

        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdpoll');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_poll_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function () {};
        $this->useNewSchema();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        if (!isset($this->dsn)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        foreach ($this->schemas as $schema) pg_query($admin, "DROP SCHEMA {$schema} CASCADE");
        pg_close($admin);
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    /** A fresh schema with core's tables (production shapes), the shipped config and the four beds. */
    private function useNewSchema(): void
    {
        $schema = 'reldyn_poll' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->schemas[] = $schema;
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$schema}");
        pg_query($admin, "SET search_path TO {$schema}");
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
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE INDEX event_log_type ON eventlog USING btree (type)");   // debug/db_updates.php
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

        if (isset($this->db)) pg_close($this->db->link);
        $this->db = new RelDynPollCountingPgDb($this->dsn, $schema);
        $GLOBALS['db'] = $this->db;
        $this->realTs = 1727000000;
        $this->gamets = 200 * self::DAY + 10 * self::HOUR;
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seedBeds();
    }

    /** Core rows, voice types, placeholder templates, and the seed's reads keyed to them (as RelDynTraitTestBedsPostgresTest). */
    private function seedBeds(): void
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
                     'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'platonic']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;   // skip-listed: her hand-set vector, never read
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        $this->logRow('infoloc', '(Context location: Jorrvaskr ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Morndas, 10:00 AM, 17th of Last Seed, 4E 201, current weather: Pleasant)');
    }

    /** One eventlog row as core's logEvent writes it, at the current game time. */
    private function logRow(string $type, string $data = ''): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            [$type, $data, 'pending', (int) round($this->gamets), $this->realTs, (int) round($this->gamets),
             '|' . implode('|', array_keys(self::BEDS)) . '|', '']);
    }

    /** One player line to $npc through the real hooks: prerequest, context, postrequest. */
    private function turn(string $npc, string $line): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) (int) round($this->gamets), self::PLAYER . ": {$line}"];
        $party = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
    }

    /**
     * The plugin's poll as main.php runs it: no profile, so HERIKA_NAME is whatever conf.php left
     * ($herikaName); the ext prerequest hooks, then comm.php answers it (and ends the request
     * before context_pre). Returns the statements RelDyn ran for it.
     */
    private function poll(string $herikaName = 'The Narrator'): array
    {
        unset($GLOBALS['CACHE_PEOPLE'], $GLOBALS['CACHE_PARTY'], $GLOBALS['CACHE_LOCATION'], $GLOBALS['contextDataFull']);
        $GLOBALS['gameRequest'] = ['request', (string) ($this->realTs * 1000), (string) (int) round($this->gamets),
            '(Context location: Jorrvaskr, Hold: Whiterun)', '0x0001a279'];
        $GLOBALS['HERIKA_NAME'] = $herikaName;
        $this->db->startRecording();
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        return $this->db->stopRecording();
    }

    /** $n polls of play, each followed by core logging it; $polls false = the same rows with no hook run. */
    private function play(int $n, bool $polls, string $herikaName = 'The Narrator'): array
    {
        $counts = [];
        for ($i = 0; $i < $n; $i++) {
            $this->gamets += self::STEP;
            $this->realTs += 5;
            if ($polls) {
                $counts[] = count($this->poll($herikaName));
                $this->clearReldynGlobals();
            }
            $this->logRow('request', '(Context location: Jorrvaskr, Hold: Whiterun)');
        }
        return $counts;
    }

    private function reldyn(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    /** Every NPC row and RelDyn's own tables, as text. */
    private function bondSnapshot(): array
    {
        $snap = [];
        foreach (['core_npc_master', 'core_npc_master_history', 'core_player', 'reldyn_trait_reads'] as $t) {
            $snap[$t] = pg_fetch_all(pg_query($this->db->link, "SELECT * FROM {$t} ORDER BY 1")) ?: [];
        }
        foreach (['reldyn_eval_queue', 'reldyn_load_stash'] as $t) {
            $exists = pg_fetch_result(pg_query_params($this->db->link, 'SELECT to_regclass($1) IS NOT NULL', [$t]), 0, 0);
            $snap[$t] = $exists === 't' ? (pg_fetch_all(pg_query($this->db->link, "SELECT * FROM {$t} ORDER BY 1")) ?: []) : null;
        }
        $snap['conf_opts'] = pg_fetch_all(pg_query_params($this->db->link,
            'SELECT * FROM conf_opts WHERE id <> $1 ORDER BY id', [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID])) ?: [];
        return $snap;
    }

    private function heartbeat(): ?array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1', [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID]));
        return $r ? json_decode($r['value'], true) : null;
    }

    private function report(string $what, array $counts): void
    {
        if (getenv('RELDYN_POLL_REPORT')) {
            fwrite(STDERR, sprintf("\n[poll report] %s: %d polls, queries per poll min %d max %d mean %.2f\n", $what, count($counts),
                min($counts), max($counts), array_sum($counts) / max(1, count($counts))));
        }
    }

    /** Two turns with each bed, so every bed has a stored bond and the heartbeat has run. */
    private function meetTheBeds(): void
    {
        foreach (['Well met. Have a moment?', 'How have you been keeping?'] as $line) {
            foreach (array_keys(self::BEDS) as $npc) {
                $this->turn($npc, $line);
                $this->play(3, false);
            }
        }
    }

    // ------------------------------------------------------------------ where the poll goes in core

    /** main.php hook order: the poll reaches the ext prerequest hooks and ends before context_pre, context and postrequest. */
    public function testCorePollReachesThePrerequestHookOnly(): void
    {
        $main = file_get_contents(__DIR__ . '/../../main.php');
        $comm = file_get_contents(__DIR__ . '/../../processor/comm.php');
        $at = function (string $needle) use ($main): int {
            $p = strpos($main, $needle);
            $this->assertNotFalse($p, "main.php: {$needle}");
            return $p;
        };
        $this->assertMatchesRegularExpression('/\$fast_commands\s*=\s*\[[^\]]*"request"/s', $main, "'request' is a fast command: no MAIN semaphore");
        $profile = $at('if (isset($_GET["profile"])) {');   // no &profile= (the poll): no NPC is loaded
        $pre = $at('"ext".DIRECTORY_SEPARATOR,"prerequest.php")');
        $commAt = $at('"processor".DIRECTORY_SEPARATOR."comm.php")');
        $end = $at('if ($MUST_END) {');
        $ctxPre = $at('"ext".DIRECTORY_SEPARATOR,"context_pre.php")');
        $ctx = $at('"ext".DIRECTORY_SEPARATOR,"context.php")');
        $post = $at('"ext".DIRECTORY_SEPARATOR,"postrequest.php")');
        $this->assertTrue($profile < $pre && $pre < $commAt && $commAt < $end && $end < $ctxPre && $ctxPre < $ctx && $ctx < $post,
            'profile load, ext prerequest, comm.php, the MUST_END exit, then context_pre / context / postrequest');
        $this->assertMatchesRegularExpression('/\$gameRequest\[0\] == "request"\) \{.*?DataDequeue.*?\$MUST_END = true;/s', $comm,
            "comm.php answers the poll and ends the request");
        $this->assertTrue(RelationshipDynamics::isPollRequest(['request', '1', '2', '']));
        foreach (['inputtext', 'inputtext_s', 'ginputtext', 'chat', 'init', 'infonpc', 'funcret', 'radiant', ''] as $type) {
            $this->assertFalse(RelationshipDynamics::isPollRequest([$type, '1', '2', '']), $type);
        }
    }

    // ------------------------------------------------------------------ the poll path

    /**
     * Polls touch no bond, whatever NPC name core left in HERIKA_NAME, and keep the heartbeat
     * current; each runs at most POLL_QUERY_BUDGET statements, on conf_opts and eventlog, plus the
     * creature form watch (poll.creature_forms): one read of the plugin's form report
     * (core_npc_master metadata transformation_state), never a bond.
     */
    public function testAPollBeatsThePlayClockAndTouchesNoBond(): void
    {
        $this->meetTheBeds();
        $before = $this->bondSnapshot();
        $beat = $this->heartbeat();
        $this->assertIsArray($beat, 'the dialogue turns beat the heartbeat');

        foreach (array_merge(['The Narrator'], array_keys(self::BEDS)) as $name) {
            $counts = $this->play(12, true, $name);
            $this->report("HERIKA_NAME={$name}", $counts);
            $this->assertLessThanOrEqual(self::POLL_QUERY_BUDGET, max($counts), "{$name}: a cheap poll");
            $stmts = $this->poll($name);
            foreach ($stmts as $q) {
                if (str_contains($q, "metadata->'transformation_state'")) {
                    $this->assertMatchesRegularExpression("/^\s*SELECT npc_name, metadata->'transformation_state' AS ts FROM core_npc_master\b/", $q,
                        "{$name}: the form watch reads the plugin's form report");
                    $this->assertDoesNotMatchRegularExpression('/plugin_extended_data|reldyn_|core_player/', $q, "{$name}: no bond is read");
                    continue;
                }
                $this->assertMatchesRegularExpression('/\b(conf_opts|eventlog)\b/', $q, "{$name}: a poll reads only the clock rows");
                $this->assertDoesNotMatchRegularExpression('/core_npc_master|reldyn_|core_player/', $q, "{$name}: no bond is read");
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $q)) {
                    $this->assertStringContainsString('conf_opts', $q, "{$name}: the only write is the heartbeat");
                }
            }
            $this->assertSame([], array_filter(array_keys($GLOBALS), fn($k) => str_starts_with((string) $k, 'RELDYN_')),
                "{$name}: a poll publishes nothing for other extensions");
            $this->clearReldynGlobals();
        }

        $this->assertSame($before, $this->bondSnapshot(), 'no bond, queue, stash or config row changed');
        // Every row since the last turn's beat is STEP after the one before it: all of it was play
        $newest = floatval(pg_fetch_result(pg_query($this->db->link, 'SELECT gamets FROM eventlog ORDER BY rowid DESC LIMIT 1'), 0, 0));
        $this->assertGreaterThan(floatval($beat['gamets']) + 60 * self::STEP, $newest);
        $this->assertEqualsWithDelta(floatval($beat['play']) + $newest - floatval($beat['gamets']), floatval($this->heartbeat()['play']), 1.0,
            'the polls kept the heartbeat current');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    /**
     * The same game played with and without polls gives the four beds the same bonds: a
     * dialogue's result does not depend on the polls before it. The beds still diverge where the
     * design says (their traits and how the turns moved them), and agree where it says (the
     * play clock is game time, not personality).
     */
    public function testDialogueIsTheSameWithOrWithoutPolls(): void
    {
        $runs = [];
        foreach ([false, true] as $polls) {
            if ($polls) $this->useNewSchema();
            foreach (['Well met. Have a moment?', 'How have you been keeping?', 'I brought you something from Riverwood.'] as $round => $line) {
                foreach (array_keys(self::BEDS) as $npc) {
                    $this->turn($npc, $line);
                    $this->play(7, $polls);
                }
                if ($round === 1) {   // a two-hour wait between the rounds (core's waitstop handler)
                    $this->play(2, $polls);
                    $start = $this->gamets;
                    $this->gamets += 2 * self::HOUR;
                    $this->logRow('info_timeforward', sprintf('%s hours have passed. Current date/time: Morndas', ($this->gamets - $start) * 0.0000024));
                    $this->play(2, $polls);
                }
            }
            foreach (array_keys(self::BEDS) as $npc) $runs[(int) $polls][$npc] = $this->reldyn($npc);
            $this->assertSame([], $this->db->failures);
        }
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertEquals($runs[0][$npc], $runs[1][$npc], "{$npc}: the same bond with or without the polls");
        }

        $d = array_map(fn(array $r) => $r['dynamics'], $runs[1]);
        $x = array_map(fn(array $dd) => RelDynTraits::readVector($dd), $d);
        $names = array_keys($x);
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                $this->assertGreaterThanOrEqual(0.25, RelDynTraits::distance($x[$a], $x[$b]), "{$a} vs {$b}: still four people");
            }
        }
        $felt = array_map(fn(array $dd) => json_encode([$dd['dimensions']['maturity']['baseline'] ?? null,
            RelationshipDynamics::effectiveMaturityY($dd), $dd['inferred_temperament'] ?? null]), $d);
        $this->assertCount(4, array_unique($felt), 'the turns moved each bed her own way');
        $play = array_map(fn(array $dd) => round(floatval($dd['_accumulated_play_gamets'] ?? 0), 3), $d);
        $this->assertCount(1, array_unique($play), 'equal gaps, equal play credit for all four: the play clock is not personality');
        $this->assertGreaterThan(0.0, reset($play));
        $this->assertSame(0, $this->llmCalls);
    }

    /**
     * A long stretch with no one spoken to: the polls keep the heartbeat current, so the next
     * turn is credited all of its played time (one beat reads at most PLAY_SCAN_CHUNK x
     * PLAY_SCAN_MAX_CHUNKS rows, and a turn whose beat lags is credited only what it read).
     */
    public function testPollsKeepTheHeartbeatCurrentThroughALongSilence(): void
    {
        $this->meetTheBeds();
        $hb = $this->heartbeat();
        $rows = RelationshipDynamics::PLAY_SCAN_CHUNK * RelationshipDynamics::PLAY_SCAN_MAX_CHUNKS + 2000;
        $perPoll = 1000;   // rows core logs between two polls here (a coarse stand-in for one poll per row)
        $start = $this->gamets;
        for ($done = 0; $done < $rows; $done += $perPoll) {
            pg_query_params($this->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
                SELECT 'request', '', 'pending', ($1::bigint + g * $2::bigint), $3, 0, '', '' FROM generate_series(1, $4) g",
                [(int) round($start + $done * self::STEP), (int) self::STEP, $this->realTs, $perPoll]);
            $this->gamets = $start + ($done + $perPoll) * self::STEP;
            $this->poll();
        }
        // every row since the last turn's beat is STEP after the one before it: all of it was play
        $this->assertEqualsWithDelta(floatval($hb['play']) + $this->gamets - floatval($hb['gamets']), floatval($this->heartbeat()['play']), 1.0,
            'the heartbeat is current when the player speaks again');

        $before = array_map(fn(string $n) => floatval($this->reldyn($n)['dynamics']['_accumulated_play_gamets'] ?? 0), array_keys(self::BEDS));
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'I am back.');
        foreach (array_keys(self::BEDS) as $i => $npc) {
            $gained = floatval($this->reldyn($npc)['dynamics']['_accumulated_play_gamets']) - $before[$i];
            $this->assertGreaterThanOrEqual($rows * self::STEP, $gained + 1.0, "{$npc}: the whole silence was played");
        }
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ save load

    /** The first poll after core restored a load reconciles it; while core is still restoring, polls leave RelDyn alone. */
    public function testTheFirstPollAfterALoadReconcilesIt(): void
    {
        $this->meetTheBeds();
        $loadAt = $this->gamets - 6 * self::STEP;   // the save: a little before now

        // First load RelDyn ever sees: adopted (marker written), as the dialogue path would
        $this->logRow('init');
        $this->poll();
        $marker = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynTimeline::MARKER_ROW_ID]), 0, 0), true);
        $this->assertTrue($marker['summary']['adopted'] ?? false);
        $this->play(4, true);

        // The init request: its prerequest stashes RelDyn and holds the loading lock until core has restored
        $GLOBALS['gameRequest'] = ['init', (string) $this->realTs, (string) (int) round($loadAt), ''];
        $GLOBALS['HERIKA_NAME'] = 'The Narrator';
        $core = new RelDynPollCountingPgDb($this->dsn, end($this->schemas));   // core's init request session
        $GLOBALS['db'] = $core;
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        $GLOBALS['db'] = $this->db;
        $this->assertSame([], $core->failures);
        // comm.php: prune the rows at or after the loaded time, log 'init'
        pg_query_params($this->db->link, 'DELETE FROM eventlog WHERE gamets >= $1', [(int) round($loadAt)]);
        $this->gamets = $loadAt;
        $this->logRow('init');
        $initRowid = (int) pg_fetch_result(pg_query($this->db->link, "SELECT max(rowid) FROM eventlog WHERE type = 'init'"), 0, 0);

        $bonds = $this->bondSnapshot();
        $hb = $this->heartbeat();
        $this->play(3, true);   // core is still restoring: deferred
        $this->assertSame($marker['init_rowid'], json_decode((string) pg_fetch_result(pg_query_params($this->db->link,
            'SELECT value FROM conf_opts WHERE id = $1', [RelDynTimeline::MARKER_ROW_ID]), 0, 0), true)['init_rowid'],
            'not reconciled while core restores');
        $this->assertSame($bonds, $this->bondSnapshot(), 'no bond touched while core restores');
        $this->assertSame($hb, $this->heartbeat(), 'the heartbeat waits for the restore too');

        pg_close($core->link);   // core's init request ends: its session lock is released
        $this->play(1, true);
        $marker = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynTimeline::MARKER_ROW_ID]), 0, 0), true);
        $this->assertSame($initRowid, (int) $marker['init_rowid'], 'the first poll after the restore reconciled the load');
        $this->assertNotSame($hb, $this->heartbeat(), 'and beat the heartbeat');

        // The next dialogue finds it done: no second reconcile, and the bond saves
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Where were we?');
        $again = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynTimeline::MARKER_ROW_ID]), 0, 0), true);
        $this->assertSame($marker, $again, 'reconciled once');
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertEqualsWithDelta(round($this->gamets), floatval($this->reldyn($npc)['dynamics']['_last_contact_gamets'] ?? 0), 1.0,
                "{$npc}: the turn after the load saved her bond");
        }
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ switches

    /** poll.play_clock / poll.save_load / poll.creature_forms switch each part off; RelDyn off leaves only the config read. */
    public function testThePollPathSwitches(): void
    {
        $this->meetTheBeds();
        $store = function (array $over): void {
            $cfg = array_replace_recursive(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $over);
            pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
            RelationshipDynamics::clearConfigCache();
        };
        $this->assertSame(['play_clock' => true, 'save_load' => true, 'creature_forms' => true], RelationshipDynamics::defaultConfig()['poll']);

        $store(['poll' => ['creature_forms' => false]]);
        $stmts = $this->poll('Aela the Huntress');
        $this->assertSame([], array_values(array_filter($stmts, fn($q) => str_contains($q, 'core_npc_master'))),
            'poll.creature_forms off: no form watch read');
        $this->clearReldynGlobals();

        $store(['poll' => ['play_clock' => false]]);
        $hb = $this->heartbeat();
        $this->play(3, true);
        $this->assertSame($hb, $this->heartbeat(), 'poll.play_clock off: no beat');
        $this->logRow('init');
        $this->poll();
        $this->assertSame('1', pg_fetch_result(pg_query_params($this->db->link, 'SELECT count(*) FROM conf_opts WHERE id = $1',
            [RelDynTimeline::MARKER_ROW_ID]), 0, 0), 'the reconcile still runs');

        $store(['poll' => ['save_load' => false]]);
        $this->logRow('init');
        $marker = pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1', [RelDynTimeline::MARKER_ROW_ID]), 0, 0);
        $this->play(2, true);
        $this->assertSame($marker, pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynTimeline::MARKER_ROW_ID]), 0, 0), 'poll.save_load off: no reconcile');
        $this->assertNotSame($hb, $this->heartbeat(), 'the beat still runs');

        $store(['enabled' => false]);
        $hb = $this->heartbeat();
        $stmts = $this->poll('Aela the Huntress');
        $this->assertCount(1, $stmts, 'RelDyn off: the config read only');
        $this->assertSame($hb, $this->heartbeat());
        $this->assertSame([], $this->db->failures);
    }
}
