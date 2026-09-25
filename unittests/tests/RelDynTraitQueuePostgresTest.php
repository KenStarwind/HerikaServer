<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php: fetchOne returns [] on a failed statement). */
final class RelDynTraitQueuePgDb
{
    public $link;
    public array $statements = [];
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        $res = pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) $rows[] = $row;
        return $rows;
    }

    public function execQuery($q) { $this->statements[] = $q; return pg_query($this->link, $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        return pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
    }
}

/**
 * Personality traits phase 2, the trait-read queue and cache (design §4.7) on a real
 * PostgreSQL: reldyn_trait_reads (no npc_id), the seed load, one pending row per template
 * however often getDynamics runs, the drain after the eval (never before, never in its way),
 * failures and dead rows, switch-off, the cache hit, the skip list, and a done read reaching
 * the NPC on the next load (untouched seeded baselines move, touched values stay).
 * Only the LLM is stubbed. Core-shaped rows; the bio template text here is made up.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynTraitQueuePostgresTest extends TestCase
{
    private string $dsn;
    private string $schema;
    private RelDynTraitQueuePgDb $db;
    private array $savedGlobals = [];
    private int $llmCalls = 0;
    private int $launches = 0;
    private $llmOut = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_tq' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
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
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // core's bio templates (a view over bio_templates_custom / bio_templates on the live install; the columns RelDyn reads)
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynTraitQueuePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () { $this->launches++; };
        RelDynTraitRead::$llm = function (array $messages, array $params) {
            $this->llmCalls++;
            $this->assertStringNotContainsString('Director note', json_encode($messages), 'core is never sent');
            return is_callable($this->llmOut) ? ($this->llmOut)($messages) : $this->llmOut;
        };
        $this->llmOut = self::readJson();
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixture

    private const FIELDS = [
        'personality'    => 'Bryn is cheerful and open-hearted, and fiercely protective of her little brother.',
        'relationships'  => '{"Tomas":{"aff":80,"type":"familial","note":"Her younger brother; she worries whenever he goes hunting"}}',
        'npc_static_bio' => 'Born in Riverwood, Bryn took over the forge and never looked back.',
        'speechstyle'    => 'Speaks loudly and laughs often.',
        'goals'          => '* Keep the forge running',
        'occupation'     => 'Blacksmith of Riverwood.',
    ];

    /** A valid read of the made-up template: warm and protective, open. */
    private static function readJson(): string
    {
        $t = [];
        foreach (RelDynTraitRead::TRAIT_KEYS as $k) $t[$k] = ['value' => 0.5, 'conf' => 0, 'field' => null, 'evidence' => null];
        $t['warmth'] = ['value' => 0.85, 'conf' => 0.9, 'field' => 'personality', 'evidence' => 'cheerful and open-hearted'];
        $t['protectiveness'] = ['value' => 0.85, 'conf' => 0.9, 'field' => 'personality', 'evidence' => 'fiercely protective of her little brother'];
        $t['guard'] = ['value' => 0.2, 'conf' => 0.9, 'field' => 'personality', 'evidence' => 'open-hearted'];
        return json_encode(['v' => 1, 'traits' => $t, 'maturity_start' => ['value' => 60, 'conf' => 0.6, 'field' => 'npc_static_bio', 'evidence' => 'never looked back']]);
    }

    private function template(string $key, array $fields = self::FIELDS, string $voice = 'sk_femalecommoner'): void
    {
        pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'Director note: secret plans.', $fields['personality'], $fields['relationships'],
            $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
        pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice . ' ']);
    }

    private function npc(string $name, string $class = 'Blacksmith', string $race = 'NordRace'): int
    {
        $meta = ['skills' => ['smithing' => '60', 'onehanded' => '30']];
        $ext = ['class' => ['name' => $class, 'formid' => '0x00013176'], 'factions' => []];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, personality, speechstyle, core, npc_static_bio, voiceid, gender, race, metadata, extended_data)'
            . ' VALUES ($1, \'\', \'\', $2, \'\', \'\', \'female\', $3, $4::jsonb, $5::jsonb) RETURNING id',
            [$name, "Roleplay as {$name}", $race, json_encode($meta), json_encode($ext)]));
        return (int) $row['id'];
    }

    private function stored(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** This test's rows (the committed seed is loaded into the table on first use too). */
    private function rows(): array
    {
        $present = pg_fetch_assoc(pg_query($this->db->link, "SELECT to_regclass('reldyn_trait_reads') IS NOT NULL AS p"));
        if ($present['p'] !== 't') return [];
        $res = pg_query($this->db->link, "SELECT template_key, src_hash, status, attempts, model FROM reldyn_trait_reads
            WHERE template_key IN ('bryn', 'ashe') ORDER BY template_key");
        $out = [];
        while ($r = pg_fetch_assoc($res)) $out[] = $r;
        return $out;
    }

    private function config(array $over): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace_recursive(RelationshipDynamics::defaultConfig(), $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** getDynamics + saveDynamics inside one request scope (as the hooks do). */
    private function meet(string $name): array
    {
        RelationshipDynamics::beginRequest();
        $d = RelationshipDynamics::getDynamics($name);
        RelationshipDynamics::saveDynamics($name, $d);
        RelationshipDynamics::endRequest();
        return $d;
    }

    // ------------------------------------------------------------------ tests

    public function testSeedLoadsOnceAndNeverAScreenedNpc(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rdseed');
        $res = RelDynTraitRead::parse(self::readJson(), self::FIELDS);
        file_put_contents($path, json_encode(['reads' => [
            'bryn' => ['src_hash' => RelDynTraitRead::srcHash(self::FIELDS), 'prompt_v' => 1, 'status' => 'done', 'attempts' => 1, 'model' => 'm', 'result' => $res],
            'ashe' => ['src_hash' => 'x', 'prompt_v' => 1, 'status' => 'done', 'attempts' => 1, 'model' => 'm', 'result' => $res],
            'failed_one' => ['src_hash' => 'y', 'prompt_v' => 1, 'status' => 'failed', 'attempts' => 1],
        ]]));
        try {
            $this->assertSame(1, RelDynTraitRead::ensureSeedLoaded($path));
            RelDynTraitRead::reset();
            $this->assertSame(0, RelDynTraitRead::ensureSeedLoaded($path), 'once per seed content (marker row)');
            $rows = $this->rows();
            $this->assertCount(1, $rows);
            $this->assertSame(['bryn', 'done', 'seed:m'], [$rows[0]['template_key'], $rows[0]['status'], $rows[0]['model']]);
        } finally {
            @unlink($path);
        }
        // the committed seed: never Ashe, quotes only (each <= 12 words), no failed rows loaded
        $seed = RelDynTraitRead::loadSeedFile();
        $this->assertArrayNotHasKey('ashe', $seed['reads']);
        foreach ($seed['reads'] as $key => $e) {
            $this->assertSame('done', $e['status'], $key);
            foreach ($e['result']['traits'] + ['maturity_start' => $e['result']['maturity_start']] as $t => $v) {
                if ($v['evidence'] !== null) $this->assertLessThanOrEqual(12, RelDynTraitRead::wordCount($v['evidence']), "{$key}.{$t}");
            }
        }
        $this->assertLessThanOrEqual(110, $seed['llm_calls'], 'the run cap');
    }

    public function testFirstMeetingQueuesOneReadAndPriorsApplyMeanwhile(): void
    {
        $this->template('bryn');
        $id = $this->npc('Bryn');
        foreach ([1, 2, 3] as $_) $d = $this->meet('Bryn');
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'one pending row per template and text, however often getDynamics runs');
        $this->assertSame(['bryn', 'pending'], [$rows[0]['template_key'], $rows[0]['status']]);
        $this->assertSame(RelDynTraitRead::srcHash(self::FIELDS), $rows[0]['src_hash']);
        $this->assertSame(1, $this->launches, 'a worker is asked for once, on the insert');
        $this->assertSame(0, $this->llmCalls, 'the read never runs inside a request');
        $s = $this->stored($id)['_trait_vector_src'];
        $this->assertSame('pending', $s['read_status']);
        $this->assertSame('prior', $s['auto_source']);
        $this->assertSame('sk_femalecommoner', $s['prior']['voice'], 'voice prior from npc_templates_v2, trimmed');
        $this->assertContains('voice:Commoner', $s['prior']['signals']);
        $this->assertContains('class:Merchant', $s['prior']['signals']);
        $this->assertContains('race:Bold', $s['prior']['signals']);
    }

    public function testDrainAfterTheEvalAndTheReadReachesTheNpc(): void
    {
        $this->template('bryn');
        $id = $this->npc('Bryn');
        $before = $this->meet('Bryn');
        $trustSeed = $before['dimensions']['trust']['baseline'];
        // the player has already moved her comfort: a live value the read must not reset
        RelationshipDynamics::beginRequest();
        $d = RelationshipDynamics::getDynamics('Bryn');
        $d['dimensions']['comfort']['x'] = 77.0;
        RelationshipDynamics::saveDynamics('Bryn', $d);
        RelationshipDynamics::endRequest();

        $stats = RelDynEval::drainTraitReadsAfterEval(['locked' => false, 'paused' => false]);
        $this->assertSame(1, $stats['done']);
        $this->assertSame(1, $this->llmCalls);
        $this->assertSame('done', $this->rows()[0]['status']);

        $after = $this->meet('Bryn');
        $src = $this->stored($id)['_trait_vector_src'];
        $this->assertSame('done', $src['read_status']);
        $this->assertSame('read', $src['auto_source']);
        $this->assertSame('bio', $src['traits']['warmth']['source']);
        $this->assertSame('cheerful and open-hearted', $src['traits']['warmth']['evidence']);
        $x = RelDynTraits::readVector($this->stored($id));
        $this->assertGreaterThan(0.7, $x['W'], 'warm: the read moved her 72% of the way from the prior');
        $this->assertLessThan(0.35, $x['G']);
        $this->assertNotEquals($trustSeed, $after['dimensions']['trust']['baseline'], 'an untouched seeded baseline follows the read');
        $this->assertEqualsWithDelta(RelDynTraits::value($x, 'baseline_trust'), $after['dimensions']['trust']['baseline'], 1e-9);
        $this->assertSame(77.0, floatval($this->stored($id)['dimensions']['comfort']['x']), 'a live value stays');
        $this->assertEqualsWithDelta(floatval($x['maturity_start']), floatval($after['dimensions']['maturity']['baseline']), 1e-9, 'maturity starts at the read level');
        $calls = $this->llmCalls;
        $this->meet('Bryn');
        $this->assertSame($calls, $this->llmCalls, 'resolved: no further work');
    }

    public function testTheEvalGoesFirstAndATraitReadNeverBlocksIt(): void
    {
        $this->template('bryn');
        $this->npc('Bryn');
        $this->meet('Bryn');
        RelDynEval::ensureQueueTable();
        pg_query($this->db->link, "INSERT INTO reldyn_eval_queue (npc_id, npc_name, job) VALUES (1, 'Bryn', '{}')");
        $this->assertNull(RelDynEval::drainTraitReadsAfterEval(['locked' => false, 'paused' => false]), 'an eval job pending: no read');
        pg_query($this->db->link, 'DELETE FROM reldyn_eval_queue');
        $this->assertNull(RelDynEval::drainTraitReadsAfterEval(['locked' => true, 'paused' => false]), 'another drainer holds the eval');
        $this->assertNull(RelDynEval::drainTraitReadsAfterEval(['locked' => false, 'paused' => true]), 'a switch is pending');
        $this->assertSame(0, $this->llmCalls);

        // three failures: dead, priors stay, the eval queue untouched
        pg_query($this->db->link, "INSERT INTO reldyn_eval_queue (npc_id, npc_name, job, status) VALUES (1, 'Bryn', '{}', 'dead')");
        $this->llmOut = 'I cannot rate that.';
        foreach ([1, 2, 3] as $i) {
            $stats = RelDynTraitRead::drain();
            $this->assertSame($i < 3 ? 1 : 0, $stats['failed']);
            $this->assertSame($i === 3 ? 1 : 0, $stats['dead']);
        }
        $row = $this->rows()[0];
        $this->assertSame(['dead', '3'], [$row['status'], $row['attempts']]);
        $this->assertSame(['n' => '1'], pg_fetch_assoc(pg_query($this->db->link, "SELECT count(*) AS n FROM reldyn_eval_queue WHERE status = 'dead'")));
        $this->assertSame(0, RelDynTraitRead::drain()['processed'], 'a dead read is not retried by the drain');
    }

    public function testSwitchedOffTheReadsWaitAndTheEvalSwitchOffLeavesThem(): void
    {
        $this->template('bryn');
        $this->npc('Bryn');
        $this->meet('Bryn');
        $this->config(['trait_reader' => ['enabled' => false]]);
        $this->assertTrue(RelDynTraitRead::drain()['off']);
        $this->assertSame('pending', $this->rows()[0]['status'], 'pending rows stay while switched off');
        $this->assertSame(0, $this->llmCalls);
        // the eval switched off drops ITS pending jobs, never trait rows
        $this->config(['eval_producer' => ['enabled' => false]]);
        RelDynEval::ensureQueueTable();
        pg_query($this->db->link, "INSERT INTO reldyn_eval_queue (npc_id, npc_name, job) VALUES (1, 'Bryn', '{}')");
        RelDynEval::drain(fn() => '{}');
        $this->assertSame(['n' => '0'], pg_fetch_assoc(pg_query($this->db->link, "SELECT count(*) AS n FROM reldyn_eval_queue WHERE status = 'pending'")));
        $this->assertSame('pending', $this->rows()[0]['status']);
    }

    public function testACacheHitAppliesWithoutAnLlmCall(): void
    {
        $this->template('bryn');
        RelDynTraitRead::ensureTable();
        pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
            VALUES ('bryn', \$1, 1, 'done', 1, 'seed:x', \$2::jsonb)",
            [RelDynTraitRead::srcHash(self::FIELDS), json_encode(RelDynTraitRead::parse(self::readJson(), self::FIELDS))]);
        $id = $this->npc('Bryn');
        $this->meet('Bryn');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame(0, $this->launches);
        $src = $this->stored($id)['_trait_vector_src'];
        $this->assertSame('done', $src['read_status']);
        $this->assertSame('seed:x', $src['model']);
        $this->assertCount(1, $this->rows());
    }

    public function testAsheIsNeverQueuedNeverReadAndIsHerHandSetVector(): void
    {
        $this->template('ashe', ['personality' => 'Placeholder text for the screen test.'] + self::FIELDS);
        $id = $this->npc('Ashe', 'Sorcerer', 'BretonRace');
        foreach ([1, 2] as $_) $d = $this->meet('Ashe');
        $this->assertSame([], $this->rows(), 'no row, no read, no quote');
        $this->assertSame(0, $this->launches);
        $s = $this->stored($id);
        $this->assertSame('skip', $s['_trait_vector_src']['read_status']);
        $this->assertSame('hand-set', $s['_trait_vector_src']['auto_source']);
        $this->assertSame('Stoic', $s['inferred_temperament']);
        $hand = RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides']['ashe']['trait_vector'];
        foreach ($hand as $name => $v) $this->assertEqualsWithDelta($v, $s['trait_vector'][$name], 1e-12, $name);
        $this->assertEquals(75, $s['dimensions']['maturity']['baseline'], 'MDD 15.4: maturity starts at 75');
        $this->assertSame('Resilient', $s['dimensions']['maturity']['plasticity_type']);
        $this->assertSame(['Y_up' => 1.0, 'Y_down' => 0.5], RelationshipDynamics::effectiveMaturityY($s));
        foreach ($s['_trait_vector_src']['traits'] as $name => $t) $this->assertArrayNotHasKey('evidence', $t, $name);
        $this->assertSame(0, RelDynTraitRead::drain()['processed']);
    }

    /**
     * Review 2026-09-25: a read stored under an older evidence gate (the committed seed before
     * GATE_V 2, or a live row) is screened again when it is looked up; no read is repeated.
     */
    public function testAReadStoredUnderAnOlderGateIsScreenedOnLookup(): void
    {
        $fields = ['goals' => "* Keep the forge running\n* Protect the Skeleton Key"] + self::FIELDS;
        $this->template('bryn', $fields);
        $old = RelDynTraitRead::parse(self::readJson(), $fields);
        unset($old['gate']);
        $old['traits']['possessiveness'] = ['value' => 0.8, 'conf' => 0.9, 'field' => 'goals', 'evidence' => 'Protect the Skeleton Key'];
        RelDynTraitRead::ensureTable();
        pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
            VALUES ('bryn', \$1, 1, 'done', 1, 'seed:x', \$2::jsonb)", [RelDynTraitRead::srcHash($fields), json_encode($old)]);
        $id = $this->npc('Bryn');
        $this->meet('Bryn');
        $src = $this->stored($id)['_trait_vector_src'];
        $this->assertSame('done', $src['read_status']);
        $this->assertSame('prior', $src['traits']['possessiveness']['source'], 'a quest object is not jealousy: the prior stands');
        $this->assertSame('bio', $src['traits']['warmth']['source'], 'the good evidence still counts');
        $this->assertSame(0, $this->llmCalls);
    }

    /**
     * Review 2026-09-25: the trait drain holds the shared work lease like the eval drain, so it
     * checks for a pending Playthrough Save switch before every read and stops there.
     */
    public function testTheTraitDrainStopsBetweenReadsWhenASwitchIsPending(): void
    {
        foreach (['bryn', 'hild', 'sigrid'] as $key) {
            $this->template($key);
            $this->npc(ucfirst($key));
            $this->meet(ucfirst($key));
        }
        $this->assertSame(3, RelDynTraitRead::pendingCount());
        $switch = false;
        RelDynEval::$pauseCheck = function () use (&$switch) { return $switch; };
        $this->llmOut = function () use (&$switch) { $switch = true; return self::readJson(); };   // the switch arrives during the first read
        try {
            $stats = RelDynEval::drainTraitReadsAfterEval(['locked' => false, 'paused' => false]);
        } finally {
            RelDynEval::$pauseCheck = null;
        }
        $this->assertSame(1, $this->llmCalls, 'one read, then it lets go');
        $this->assertSame(1, $stats['done']);
        $this->assertTrue($stats['paused']);
        $this->assertSame(2, RelDynTraitRead::pendingCount(), 'the rest wait for the next worker');
        // a direct drain honours the same check
        RelDynEval::$pauseCheck = fn() => true;
        try {
            $this->assertTrue(RelDynTraitRead::drain()['paused']);
        } finally {
            RelDynEval::$pauseCheck = null;
        }
        $this->assertSame(1, $this->llmCalls);
    }

    /**
     * Review 2026-09-25: switching traits.assignment back to 'label' rolls an NPC resolved under
     * 'read' back to the phase-1 profile: the vote's label, its preset point, its seeded
     * baselines (a baseline still at its read seed); a value the player moved stays.
     */
    public function testSwitchingBackToLabelRestoresThePhaseOneProfile(): void
    {
        $this->template('bryn');
        RelDynTraitRead::ensureTable();
        pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
            VALUES ('bryn', \$1, 1, 'done', 1, 'seed:x', \$2::jsonb)", [RelDynTraitRead::srcHash(self::FIELDS), json_encode(RelDynTraitRead::parse(self::readJson(), self::FIELDS))]);
        $id = $this->npc('Bryn');
        $twin = $this->npc('Bryn Twin');   // the same core row, never read, met only under 'label'
        $this->meet('Bryn');
        $read = $this->stored($id);
        $this->assertSame('read', $read['_trait_vector_src']['assignment']);
        RelationshipDynamics::beginRequest();
        $d = RelationshipDynamics::getDynamics('Bryn');
        $d['dimensions']['comfort']['x'] = 77.0;   // the player moved her comfort
        RelationshipDynamics::saveDynamics('Bryn', $d);
        RelationshipDynamics::endRequest();

        $this->config(['traits' => ['assignment' => 'label']]);
        $this->meet('Bryn');
        $this->meet('Bryn Twin');
        $back = $this->stored($id);
        $fresh = $this->stored($twin);
        $this->assertSame('label', $back['_trait_vector_src']['assignment']);
        $this->assertSame($fresh['inferred_temperament'], $back['inferred_temperament'], 'the vote label again');
        $this->assertSame(0.0, RelDynTraits::distance(RelDynTraits::fromStored($back['trait_vector']), RelDynTraits::points()[$back['inferred_temperament']]));
        foreach (['trust', 'warmth', 'respect', 'self_confidence'] as $dim) {
            $this->assertEqualsWithDelta(floatval($fresh['dimensions'][$dim]['baseline']), floatval($back['dimensions'][$dim]['baseline']), 1e-9, "{$dim}: the preset's seed");
        }
        $this->assertSame(77.0, floatval($back['dimensions']['comfort']['x']), 'a live value stays');
        $this->assertSame($fresh['dimensions']['maturity']['plasticity_type'], $back['dimensions']['maturity']['plasticity_type']);
        $this->assertArrayNotHasKey('seeded', $back['_profile_autogen']);
        $this->assertNull(RelationshipDynamics::continuousMaturityY($back), 'the corner applies again');
    }

    /**
     * Review 2026-09-25: priors resolved from a core row the game had not filled yet (no race,
     * no class) are resolved again once it is, instead of keeping the thinner prior for good.
     */
    public function testPriorsFromAnIncompleteCoreRowAreResolvedAgainWhenItIsFilled(): void
    {
        $this->template('bryn');
        $row = pg_fetch_assoc(pg_query($this->db->link, "INSERT INTO core_npc_master (npc_name, core, gender, race, metadata, extended_data)
            VALUES ('Bryn', 'Roleplay as Bryn', 'female', '', '{}'::jsonb, '{}'::jsonb) RETURNING id"));
        $id = (int) $row['id'];
        $this->meet('Bryn');
        $src = $this->stored($id)['_trait_vector_src'];
        $this->assertFalse($src['prior']['complete']);
        $this->assertNotContains('race:Bold', $src['prior']['signals']);
        $this->meet('Bryn');   // still incomplete: nothing changes
        $this->assertSame($src['prior'], $this->stored($id)['_trait_vector_src']['prior']);

        pg_query_params($this->db->link, 'UPDATE core_npc_master SET race = $2, extended_data = $3::jsonb, metadata = $4::jsonb WHERE id = $1',
            [$id, 'NordRace', json_encode(['class' => ['name' => 'Blacksmith'], 'factions' => []]), json_encode(['skills' => ['smithing' => '60']])]);
        $this->meet('Bryn');
        $src = $this->stored($id)['_trait_vector_src'];
        $this->assertTrue($src['prior']['complete']);
        $this->assertContains('race:Bold', $src['prior']['signals']);
        $this->assertContains('class:Merchant', $src['prior']['signals']);
        $this->assertSame(0, $this->llmCalls);
    }

    public function testLabelAssignmentIsThePhaseOnePath(): void
    {
        $this->config(['traits' => ['assignment' => 'label']]);
        $this->template('bryn');
        $id = $this->npc('Bryn');
        $this->meet('Bryn');
        $s = $this->stored($id);
        $this->assertSame([], $this->rows(), 'no read is queued under the label assignment');
        $this->assertSame('label', $s['_trait_vector_src']['assignment']);
        $this->assertSame(0.0, RelDynTraits::distance(RelDynTraits::fromStored($s['trait_vector']), RelDynTraits::points()[$s['inferred_temperament']]));
    }
}
