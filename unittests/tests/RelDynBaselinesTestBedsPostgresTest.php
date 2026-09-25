<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynBaselinesBedsPgDb
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
 * Baselines, the per-bond layer, social sensitivity and self-confidence end to end with the four
 * test beds (standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set
 * vector), Muiri and Lynly Star-Sung on their CHIM 3.4.1 core-shaped rows and the committed seed's
 * reads, through the real hooks (prerequest -> context -> postrequest), the real eval producer and
 * worker (LLM stubbed at the connector boundary) and core's relationship row. No LLM call.
 *
 *   social sensitivity  the same insult from an acquaintance (core affinity 20): who the NPC is
 *                       decides how much it lands (the dimension draft's curves at the bond level,
 *                       each through her own trait vector); affinity itself is not scaled.
 *   per-bond display    baselines are global, the bond is a display-time multiplier: the same
 *                       stored trust reads by the bond type (partner vs betrayed), nothing stored
 *                       moves, and the type change snaps at once; knowledge-of-player tension
 *                       bridges keep reading the raw values.
 *   baseline drift      days of a close partnership (one sample per game day of contact, the diary
 *                       applying 5% of the sustained gap) move each NPC's global affinity baseline
 *                       toward the bond, by how far it sat from who she was; twenty game days of
 *                       waiting move nobody's (time does not heal, and it does not bond either).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBaselinesTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const D0 = 300;   // first game day
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
    private RelDynBaselinesBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** The eval item the stub returns for the next exchanges (signals, tags, significance). */
    private array $evalReply = [];
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];
    /** npc => label => the <knowledge_of_player> / <subtext> blocks */
    private array $blocks = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_baselines' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynBaselinesBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'RELATIONSHIP_SYSTEM_ENABLED'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbaselinesbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_baselines_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        // Shipped defaults, stored as the config page stores them
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

    /** Core rows (Player at core affinity $aff, type $type), voice types, placeholder templates and the seed's reads. */
    private function seed(int $aff, string $type): void
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
                     'relationships' => [self::PLAYER => ['aff' => $aff, 'type' => $type]]])]);
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
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                $this->blocks[$npc][$label] = (string) ($GLOBALS['HERIKA_PERS'] ?? '') . "\n"
                    . implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), (array) $GLOBALS['contextDataFull']));
            }
            RelationshipDynamics::endRequest();
        }
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** The eval LLM at the connector boundary: $this->evalReply for every exchange. */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $r = $this->evalReply;
            return json_encode([
                'signals' => array_merge(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $r['signals'] ?? []),
                'tags' => $r['tags'] ?? [], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $r['significance'] ?? 0.1, 'positive_interaction' => $r['positive'] ?? false,
                'summary' => $r['summary'] ?? 'Small talk.',
            ]);
        };
    }

    private function runEval(): void
    {
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
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

    /** Core writes the player relationship (its relationship eval, a quest, the editor). */
    private function setCore(string $npc, int $aff, string $type): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ext = json_decode($r['extended_data'], true);
        $ext['relationships'][self::PLAYER] = ['aff' => $aff, 'type' => $type];
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ext)]);
    }

    /** Everyone greets the player at $gamets; returns their dynamics. */
    private function hello(int $gamets, string $label = 'hello'): array
    {
        $this->event('infoloc', self::HOME, $gamets - 600, $this->home());
        $this->evalReply = [];
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met.', $gamets + 600 * $i++, $label);
        $this->runEval();
        $d = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $this->assertSame('read', $d[$npc]['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
        }
        return $d;
    }

    public function testTheSameInsultLandsByWhoTheNpcIs(): void
    {
        $this->seed(20, 'neutral');   // an acquaintance: core affinity 20
        $before = $this->hello(self::at(self::D0, 18.0));
        $factor = [];
        foreach ($before as $npc => $d) {
            $this->assertEqualsWithDelta(20.0, RelationshipDynamics::socialSensitivityBondLevel($d), 1e-6, "{$npc}: bond level = core affinity");
            $factor[$npc] = RelationshipDynamics::socialSensitivityFactor($d, 'comfort', true);
            $this->assertGreaterThan(0.0, $factor[$npc], $npc);
            $this->assertLessThan(1.0, $factor[$npc], "{$npc}: an acquaintance's words do not land whole");
        }
        // each through her own vector: the guarded take far less of it than the open-hearted
        $this->assertGreaterThan(2.0, max($factor) / min($factor), json_encode($factor));

        // "You're useless.": the eval scores the same insult for all four
        $this->evalReply = ['signals' => ['affinity' => -6, 'trust' => -6, 'comfort' => -8, 'respect' => -5],
            'tags' => ['insult'], 'significance' => 0.8, 'summary' => 'The player called her useless.'];
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, "You're useless.", self::at(self::D0, 19.0) + 600 * $i++, 'insult');
        $this->runEval();
        $move = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $after = $this->dynamics($npc);
            foreach (['comfort', 'trust', 'respect'] as $dim) {
                $move[$dim][$npc] = floatval($after['dimensions'][$dim]['x']) - floatval($before[$npc]['dimensions'][$dim]['x']);
                $this->assertLessThan(0.0, $move[$dim][$npc], "{$npc} {$dim}: it still hurts");
            }
        }
        // who she is decides how much it lands: the hurt follows her curve, NPC by NPC
        asort($factor);
        $order = array_keys($factor);
        foreach ($move as $dim => $byNpc) {
            for ($k = 1; $k < count($order); $k++) {
                $this->assertGreaterThan(abs($byNpc[$order[$k - 1]]), abs($byNpc[$order[$k]]),
                    "{$dim}: {$order[$k]} (curve {$factor[$order[$k]]}) is hurt more than {$order[$k - 1]}; " . json_encode($move));
            }
            $this->assertGreaterThan(3.0, abs($byNpc[$order[count($order) - 1]]) / abs($byNpc[$order[0]]), "{$dim}: " . json_encode($byNpc));
        }
        $this->assertSame(0, $this->llmCalls, 'no trait-read LLM call');
        $this->assertSame([], $this->db->failures);
    }

    public function testTheBondIsADisplayMultiplierAndBaselinesStayGlobal(): void
    {
        $this->seed(60, 'romantic');   // partners
        $this->hello(self::at(self::D0, 18.0));
        $seeded = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d): void {
                $d['dimensions']['trust']['x'] = 45.0;
            });
            $seeded[$npc] = floatval($this->dynamics($npc)['dimensions']['trust']['baseline']);
        }
        $rawKeywords = RelationshipDynamics::getDimensionBand('trust', 45.0)['keywords'];
        $shown = [];
        foreach (['partner' => [19.0, null, 'bonded'], 'betrayed' => [20.0, 'betrayed', 'hostile']] as $label => [$hour, $coreType, $type]) {
            if ($coreType !== null) {
                foreach (array_keys(self::BEDS) as $npc) $this->setCore($npc, 60, $coreType);   // core flips the bond
            }
            $i = 0;
            foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Stay a while.', self::at(self::D0, $hour) + 600 * $i++, $label);
            $this->runEval();
            foreach (array_keys(self::BEDS) as $npc) {
                $d = $this->dynamics($npc);
                // nothing stored moves by the bond: the raw trust and its GLOBAL baseline stay
                $this->assertEqualsWithDelta(45.0, floatval($d['dimensions']['trust']['x']), 1e-6, "{$npc} {$label}: raw trust");
                $this->assertEqualsWithDelta($seeded[$npc], floatval($d['dimensions']['trust']['baseline']), 1e-9, "{$npc} {$label}: global baseline");
                $this->assertSame($type, RelationshipDynamics::getRelationshipType($npc, $d), "{$npc} {$label}");
                $shown[$label][$npc] = RelationshipDynamics::getEffectiveDimensionValue($d, 'trust');
                $felt = $this->felt[$npc][$label]['trust'] ?? null;
                // the context speaks the per-bond value, not the raw one
                $this->assertNotSame($rawKeywords, $felt, "{$npc} {$label}: the felt trust reads the bond");
                if ($label === 'partner') {
                    $this->assertSame(RelationshipDynamics::getDimensionBand('trust', $shown[$label][$npc])['keywords'], $felt, "{$npc}: a partner's trust");
                }
            }
        }
        foreach (array_keys(self::BEDS) as $npc) {
            // the type change snaps at once: the same stored 45 reads high with a partner, low with a betrayer
            $this->assertGreaterThan(45.0, $shown['partner'][$npc], $npc);
            $this->assertLessThan(45.0, $shown['betrayed'][$npc], $npc);
            $this->assertNull($this->felt[$npc]['betrayed']['trust'] ?? null, "{$npc}: no trust to speak of toward a betrayer");
        }
        $this->assertSame([], $this->db->failures);
    }

    /** One day of $rounds rounds (every NPC in $who once per round, 10 game minutes apart). */
    private function day(int $day, array $who, int $rounds, string $label): void
    {
        $t = self::at($day, 8.0);
        $this->event('infoloc', self::HOME, $t - 600, $this->home());
        for ($r = 0; $r < $rounds; $r++) {
            foreach ($who as $npc) {
                $this->turn($npc, 'Tell me about your day.', $t, "{$label}_{$r}");
                $t += (int) round(self::HOUR / 6);   // 10 game minutes
            }
            $this->runEval();
        }
    }

    public function testSustainedContactDriftsTheGlobalBaselinesAndWaitingDoesNot(): void
    {
        $dims = ['affinity', 'trust', 'comfort', 'respect'];
        $this->seed(60, 'romantic');   // partners
        $start = $this->hello(self::at(self::D0, 18.0));
        $base0 = [];
        foreach ($start as $npc => $d) {
            foreach (array_merge($dims, ['maturity']) as $dim) $base0[$npc][$dim] = RelationshipDynamics::driftBaseline($d, $dim);
            $base0[$npc]['warmth'] = $d['dimensions']['warmth']['baseline'] ?? null;
        }
        $this->evalReply = ['signals' => ['affinity' => 2, 'trust' => 4, 'comfort' => 3, 'respect' => 2],
            'tags' => ['quality_time'], 'significance' => 0.5, 'positive' => true, 'summary' => 'A quiet day together.'];
        $all = array_keys(self::BEDS);
        for ($k = 1; $k <= 4; $k++) $this->day(self::D0 + $k, $all, 20, "day{$k}");

        $window = RelationshipDynamics::defaultConfig()['baseline_drift']['window'];
        $drift = [];
        $held = [];
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            foreach ($dims as $dim) {
                $b = RelationshipDynamics::driftBaseline($d, $dim);
                $drift[$dim][$npc] = $b - $base0[$npc][$dim];
                // days of a close partnership moved who she is toward the bond, bounded
                $this->assertGreaterThan(1.0, $drift[$dim][$npc], "{$npc} {$dim}: " . json_encode($d['_baseline_drift_origin'] ?? null));
                $this->assertEqualsWithDelta($base0[$npc][$dim], floatval($d['_baseline_drift_origin'][$dim]['origin']), 1e-9, "{$npc} {$dim}: origin = seed");
                $this->assertLessThanOrEqual(20.0 + 1e-9, $drift[$dim][$npc], "{$npc} {$dim}");
                // one sample per game day of contact, however many talks the day held
                $days = array_column($d['_baseline_drift_samples'][$dim], 'day');
                $this->assertSame(range(self::D0, self::D0 + 4), $days, "{$npc} {$dim}");
                $this->assertCount($window, $days);
            }
            // maturity was not held away from who she is: it stays; warmth is not a drift dimension
            $this->assertEqualsWithDelta($base0[$npc]['maturity'], RelationshipDynamics::driftBaseline($d, 'maturity'), 1e-9, "{$npc} maturity");
            $this->assertSame($base0[$npc]['warmth'], $d['dimensions']['warmth']['baseline'] ?? null, "{$npc} warmth");
            $this->assertArrayNotHasKey('warmth', $d['_baseline_drift_samples']);
            $held[$npc] = $d;
        }
        // by how far the bond sat from who she was: the guarded one's trust moved least
        $this->assertSame('Ashe', array_keys($drift['trust'], min($drift['trust']))[0], json_encode($drift['trust']));
        $this->assertGreaterThan(2.0, max($drift['trust']) - min($drift['trust']), json_encode($drift['trust']));

        // twenty game days pass with nobody talking, then one greeting: the wait added no sample
        // and healed nothing (no baseline slid back toward where it started)
        $this->hello(self::at(self::D0 + 24, 18.0), 'after the wait');
        foreach ($all as $npc) {
            $d = $this->dynamics($npc);
            foreach ($dims as $dim) {
                $days = array_column($d['_baseline_drift_samples'][$dim], 'day');
                $this->assertSame([self::D0 + 1, self::D0 + 2, self::D0 + 3, self::D0 + 4, self::D0 + 24], $days, "{$npc} {$dim}");
                $this->assertGreaterThanOrEqual(RelationshipDynamics::driftBaseline($held[$npc], $dim) - 1e-9,
                    RelationshipDynamics::driftBaseline($d, $dim), "{$npc} {$dim}: waiting does not heal");
            }
        }
        $this->assertSame(0, $this->llmCalls, 'no trait-read LLM call');
        $this->assertSame([], $this->db->failures);
    }
}
