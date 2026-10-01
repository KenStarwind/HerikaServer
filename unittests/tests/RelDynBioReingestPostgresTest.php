<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynBioReingestPgDb
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
 * The bio re-ingest (decisions 2026-10-01 §21 #10) on a real PostgreSQL, from core-shaped rows:
 * the check on its cadence and at milestones (no change, no model call), a changed live bio queued
 * once as `live:<npc>` in the trait-read table and read by the existing worker (the LLM is the
 * only stub), the finished read blended into the vector with inertia, kept as a drift over the
 * first read, the old read in a short history; the exempt (Ashe's hand-set vector, an editor
 * preset), the config switch, a dead read, a changed shipped template, and the editor's last-read
 * line and POST-only "read again" button. Bio text here is made up.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBioReingestPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;

    private string $dsn;
    private string $schema;
    private RelDynBioReingestPgDb $db;
    private array $savedGlobals = [];
    private int $llmCalls = 0;
    private int $launches = 0;
    private $llmOut = null;
    private array $llmMessages = [];
    private float $t0 = 200 * self::DAY;

    private const FIELDS = [
        'personality'    => 'Bryn is cheerful and open-hearted, and fiercely protective of the little brother.',
        'relationships'  => '{"Tomas":{"aff":80,"type":"familial","note":"The younger brother, worried about whenever the hunt goes out"}}',
        'npc_static_bio' => 'Born in Riverwood, Bryn took over the forge and never looked back.',
        'speechstyle'    => 'Speaks loudly and laughs often.',
        'goals'          => '* Keep the forge running',
        'occupation'     => 'Blacksmith of Riverwood.',
    ];

    /** The dynamic profile has rewritten the personality: guarded and cold now. */
    private const REWRITTEN = 'Bryn has grown guarded and cold since the fire, and trusts few.';

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_bri' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
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

        $this->db = new RelDynBioReingestPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitReingest::reset();
        RelDynTraitRead::$launcher = function () { $this->launches++; };
        RelDynTraitRead::$llm = function (array $messages, array $params) {
            $this->llmCalls++;
            $this->llmMessages[] = $messages;
            $this->assertStringNotContainsString('Director note', json_encode($messages), 'core is never sent');
            return is_callable($this->llmOut) ? ($this->llmOut)($messages) : $this->llmOut;
        };
        $this->llmOut = self::firstRead();
        RelationshipDynamics::clearConfigCache();
        $this->setTime($this->t0);
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynTraitReingest::reset();
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

    private static function entry($value, float $conf, ?string $field = null, ?string $evidence = null): array
    {
        return ['value' => $value, 'conf' => $conf, 'field' => $field, 'evidence' => $evidence];
    }

    private static function readOf(array $set, array $maturity): string
    {
        $t = [];
        foreach (RelDynTraitRead::TRAIT_KEYS as $k) $t[$k] = self::entry(0.5, 0.0);
        return json_encode(['v' => 1, 'traits' => array_replace($t, $set), 'maturity_start' => $maturity]);
    }

    /** The first read of the shipped bio: warm, open, protective. */
    private static function firstRead(): string
    {
        return self::readOf([
            'warmth' => self::entry(0.85, 0.9, 'personality', 'cheerful and open-hearted'),
            'protectiveness' => self::entry(0.85, 0.9, 'personality', 'fiercely protective of the little brother'),
            'guard' => self::entry(0.2, 0.9, 'personality', 'open-hearted'),
        ], self::entry(60, 0.6, 'npc_static_bio', 'never looked back'));
    }

    /** The read of the rewritten bio: guarded and cold. */
    private static function rewrittenRead(): string
    {
        return self::readOf([
            'guard' => self::entry(0.85, 0.9, 'personality', 'grown guarded and cold'),
            'warmth' => self::entry(0.2, 0.9, 'personality', 'guarded and cold'),
        ], self::entry(50, 0.0));
    }

    private function template(string $key, array $fields = self::FIELDS, string $voice = 'sk_femalecommoner'): void
    {
        pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'Director note: secret plans.', $fields['personality'], $fields['relationships'],
            $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
        pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice . ' ']);
    }

    /** A core row whose live bio starts as the template's text (the dynamic profile rewrites it later). */
    private function npc(string $name, array $fields = self::FIELDS, string $gender = 'female', string $class = 'Blacksmith', string $race = 'NordRace'): int
    {
        $meta = ['skills' => ['smithing' => '60', 'onehanded' => '30']];
        $ext = ['class' => ['name' => $class, 'formid' => '0x00013176'], 'factions' => []];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, personality, relationships, npc_static_bio, speechstyle, goals, occupation, core, voiceid, gender, race, metadata, extended_data)'
            . ' VALUES ($1, $2, $3, $4, $5, $6, $7, $8, \'\', $9, $10, $11::jsonb, $12::jsonb) RETURNING id',
            [$name, $fields['personality'], $fields['relationships'], $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation'],
             "Roleplay as {$name}", $gender, $race, json_encode($meta), json_encode($ext)]));
        return (int) $row['id'];
    }

    private function rewriteBio(string $name, string $personality = self::REWRITTEN): void
    {
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET personality = $2 WHERE npc_name = $1', [$name, $personality]);
    }

    private function stored(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** reldyn_trait_reads rows of one key (template or live). */
    private function rows(string $key): array
    {
        $present = pg_fetch_assoc(pg_query($this->db->link, "SELECT to_regclass('reldyn_trait_reads') IS NOT NULL AS p"));
        if ($present['p'] !== 't') return [];
        $res = pg_query_params($this->db->link, 'SELECT template_key, src_hash, status, attempts, model FROM reldyn_trait_reads WHERE template_key = $1 ORDER BY created', [$key]);
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

    private function setTime(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) $gamets, 'Kaida: hello'];
    }

    /** One request's getDynamics + saveDynamics. */
    private function meet(string $name): array
    {
        RelationshipDynamics::beginRequest();
        $d = RelationshipDynamics::getDynamics($name);
        RelationshipDynamics::saveDynamics($name, $d);
        RelationshipDynamics::endRequest();
        return $d;
    }

    /** The prerequest's bio check inside one request scope. */
    private function look(string $name, ?callable $change = null): array
    {
        RelationshipDynamics::beginRequest();
        $d = RelationshipDynamics::getDynamics($name);
        if ($change !== null) $change($d);
        RelDynTraitReingest::onPrerequest($name, $d);
        RelationshipDynamics::saveDynamics($name, $d);
        RelationshipDynamics::endRequest();
        return $d;
    }

    /** Bryn met, the first read done by the worker and settled: the starting point of most tests. */
    private function brynWithFirstRead(): int
    {
        $this->template('bryn');
        $id = $this->npc('Bryn');
        $this->meet('Bryn');
        $stats = RelDynTraitRead::drain();
        $this->assertSame(1, $stats['done']);
        $d = $this->meet('Bryn');
        $this->assertSame('done', $d['_trait_vector_src']['read_status']);
        return $id;
    }

    private function vector(int $id): array
    {
        return RelDynTraits::readVector($this->stored($id));
    }

    // ------------------------------------------------------------------ the check

    public function testFirstSightStartsTheClockAndAnUnchangedBioCostsNothing(): void
    {
        $id = $this->brynWithFirstRead();
        $llm = $this->llmCalls;
        $d = $this->look('Bryn');
        $s = $d['_bio_reingest'];
        $this->assertEquals($this->t0, $s['last_check'], 'the clock starts at first sight, not at game start');
        $this->assertEquals($this->t0, $s['last_read']);
        $this->assertSame($d['_trait_vector_src']['src_hash'], $s['anchor'], 'anchored to the template the first read came from');
        $this->assertSame([], $this->rows('live:bryn'));

        $this->setTime($this->t0 + 89 * self::DAY);
        $this->look('Bryn');
        $this->assertEquals($this->t0, $this->stored($id)['_bio_reingest']['last_check'], 'not due before ~90 game days');

        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $s = $this->stored($id)['_bio_reingest'];
        $this->assertEquals($this->t0 + 91 * self::DAY, $s['last_check'], 'checked');
        $this->assertNull($s['pending'], 'the live bio is the text the vector was read from: nothing to read');
        $this->assertSame([], $this->rows('live:bryn'));
        $this->assertSame($llm, $this->llmCalls, 'no change, no model call');
        $this->assertSame([], $this->db->failures);
    }

    public function testAChangedBioQueuesOneLiveReadAndTheWorkerSettlesItIntoTheVector(): void
    {
        $id = $this->brynWithFirstRead();
        $before = $this->vector($id);
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $llm = $this->llmCalls;
        $launches = $this->launches;

        $this->setTime($this->t0 + 91 * self::DAY);
        $d = $this->look('Bryn');
        $this->assertSame($llm, $this->llmCalls, 'the read never runs inside a request');
        $rows = $this->rows('live:bryn');
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(RelDynTraitRead::srcHash(array_replace(self::FIELDS, ['personality' => self::REWRITTEN])), $rows[0]['src_hash']);
        $this->assertSame(1, count($this->rows('bryn')), "the template's own row is untouched");
        $this->assertSame($launches + 1, $this->launches, 'the existing worker is asked for');
        $p = $d['_bio_reingest']['pending'];
        $this->assertSame('cadence', $p['reason']);
        $this->assertSame($rows[0]['src_hash'], $p['hash']);
        $this->assertSame($before, $this->vector($id), 'nothing moves until the read is done');

        $this->look('Bryn');   // another request while it waits: still one row, no second queue
        $this->assertCount(1, $this->rows('live:bryn'));

        $this->llmOut = self::rewrittenRead();
        $stats = RelDynTraitRead::drain();
        $this->assertSame(1, $stats['done']);
        $this->assertSame($llm + 1, $this->llmCalls);
        $user = end($this->llmMessages)[1]['content'];
        $this->assertStringContainsString('Character: Bryn', $user);
        $this->assertStringContainsString('grown guarded and cold', $user, 'the live bio is what is read');
        $this->assertStringNotContainsString('cheerful and open-hearted', $user);

        $after = $this->meet('Bryn');
        $s = $after['_bio_reingest'];
        $this->assertNull($s['pending']);
        $this->assertSame(1, $s['reads']);
        $this->assertEquals($this->t0 + 91 * self::DAY, $s['last_read']);
        $this->assertSame($rows[0]['src_hash'], $s['base_hash'], 'the next check compares against the bio just read');
        $this->assertCount(1, $s['history']);
        $this->assertSame([0.85, 0.9], $s['history'][0]['superseded']['read']['warmth'], 'the old read is kept');
        $this->assertSame($d['_trait_vector_src']['src_hash'], $s['history'][0]['superseded']['hash']);
        $k = $s['history'][0]['k'];
        $this->assertGreaterThan(0.0, $k);
        $this->assertLessThan(1.0, $k);

        $x = $this->vector($id);
        $this->assertGreaterThan($before['G'], $x['G'], 'more guarded now');
        $this->assertLessThan($before['W'], $x['W'], 'colder now');
        $this->assertEqualsWithDelta($before['Pr'], $x['Pr'], 1e-9, 'a trait the new read has no evidence for does not move');
        $src = $after['_trait_vector_src'];
        $this->assertSame('bio', $src['traits']['guard']['source']);
        $this->assertSame(round($s['drift']['guard'], 4), $src['traits']['guard']['drift']);
        $this->assertSame($rows[0]['src_hash'], $this->rows('live:bryn')[0]['src_hash']);
        $this->assertSame('done', $this->rows('live:bryn')[0]['status']);

        // the same bio is not read twice: the next cadence finds nothing new
        $this->setTime($this->t0 + 182 * self::DAY);
        $this->look('Bryn');
        $this->assertCount(1, $this->rows('live:bryn'));
        $this->assertNull($this->stored($id)['_bio_reingest']['pending']);
        $calls = $this->llmCalls;
        $this->assertSame(['pending' => 0], ['pending' => RelDynTraitRead::pendingCount()]);
        $this->assertSame($calls, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    public function testTheBlendIsPartialNotAReplacement(): void
    {
        $id = $this->brynWithFirstRead();
        $before = $this->vector($id);
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->llmOut = self::rewrittenRead();
        RelDynTraitRead::drain();
        $this->meet('Bryn');
        $x = $this->vector($id);
        // what the rewritten bio alone would give: the prior blended 72% toward the read
        $d = $this->stored($id);
        $prior = $d['_trait_vector_src']['traits']['guard']['prior'];
        $alone = $prior + 0.72 * (0.85 - $prior);
        $this->assertLessThan($alone, $x['G'], 'not all the way to the new read');
        $this->assertGreaterThan($before['G'], $x['G']);
        $k = $d['_bio_reingest']['history'][0]['k'];
        $known = 0.72;   // the first read's weight on guard (conf 0.9)
        $kEff = $k + (1 - $k) * (1 - $known);
        $this->assertEqualsWithDelta($before['G'] + $kEff * ($alone - $before['G']), $x['G'], 1e-3);
    }

    public function testAMilestoneChecksBeforeTheCadenceButNotWithinTheMinimumGap(): void
    {
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');   // first sight: baselines
        $this->rewriteBio('Bryn');

        // a romance promotion three days on: noted, but the gap since the last look is too short
        $this->setTime($this->t0 + 3 * self::DAY);
        $d = $this->look('Bryn', function (array &$d) {
            $d['_romance']['last_promotion'] = ['from' => 'platonic', 'to' => 'romantic', 'gamets' => $this->t0 + 2.9 * self::DAY];
            $d['_core_rel_type'] = 'romantic';
        });
        $this->assertSame('romance', $d['_bio_reingest']['milestone']['kind']);
        $this->assertSame([], $this->rows('live:bryn'), 'min_gap_days (7) not yet passed');

        $this->setTime($this->t0 + 8 * self::DAY);
        $d = $this->look('Bryn');
        $rows = $this->rows('live:bryn');
        $this->assertCount(1, $rows, 'the milestone brought the check forward from day 90');
        $this->assertSame('milestone: romance', $d['_bio_reingest']['pending']['reason']);
        $this->assertNull($d['_bio_reingest']['milestone'], 'consumed');
    }

    public function testEachNamedMilestoneKindBringsTheCheckForward(): void
    {
        $changes = [
            'bond_break' => function (array &$d) { $d['_bond_break'] = ['count' => 1, 'at_gamets' => 1.0]; },
            'marriage'   => function (array &$d) { $d['_core_rel_type'] = 'romantic'; },
            'betrayal'   => function (array &$d) { RelDynTraitReingest::noteMilestone($d, 'betrayal', $this->t0 + 9 * self::DAY); },
        ];
        $n = 0;
        foreach ($changes as $kind => $change) {
            $n++;
            $name = "Npc{$n}";
            $this->template("npc{$n}", self::FIELDS);
            $this->npc($name);
            $this->meet($name);
            RelDynTraitRead::drain();
            $this->meet($name);
            $this->setTime($this->t0);
            $this->look($name, function (array &$d) { $d['_core_rel_type'] = 'platonic'; });
            $this->rewriteBio($name);
            $this->setTime($this->t0 + 10 * self::DAY);
            $d = $this->look($name, $change);
            $this->assertCount(1, $this->rows('live:' . strtolower($name)), $kind);
            $this->assertSame("milestone: {$kind}", $d['_bio_reingest']['pending']['reason'], $kind);
        }
    }

    public function testMilestonesCanBeSwitchedOffAndStillLeaveTheCadence(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['trait_reader']['reingest']['milestones'] = ['bond_break'];   // (array_replace_recursive would merge the list by index)
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(['bond_break'], RelDynTraitReingest::config()['milestones']);
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 10 * self::DAY);
        $this->look('Bryn', function (array &$d) { RelDynTraitReingest::noteMilestone($d, 'betrayal', $this->t0 + 9 * self::DAY); });
        $this->assertSame([], $this->rows('live:bryn'), 'a milestone kind not in the config does not bring the check forward');
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->assertCount(1, $this->rows('live:bryn'), 'the cadence still does');
    }

    // ------------------------------------------------------------------ failures, switches

    public function testADeadReadClearsQuietlyAndTheNextCheckTriesAgain(): void
    {
        $id = $this->brynWithFirstRead();
        $before = $this->vector($id);
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->llmOut = 'I cannot rate that.';
        foreach ([1, 2, 3] as $_) RelDynTraitRead::drain();
        $this->assertSame('dead', $this->rows('live:bryn')[0]['status']);
        $d = $this->meet('Bryn');
        $this->assertNull($d['_bio_reingest']['pending'], 'a dead read does not leave the NPC waiting forever');
        $this->assertSame('the bio read failed', $d['_bio_reingest']['last_error']);
        $this->assertSame($before, $this->vector($id), 'the vector is as it was');

        $this->llmOut = self::rewrittenRead();
        $this->setTime($this->t0 + 182 * self::DAY);
        $this->look('Bryn');
        $row = $this->rows('live:bryn');
        $this->assertCount(1, $row, 'the same row, queued again');
        $this->assertSame(['pending', '0'], [$row[0]['status'], $row[0]['attempts']]);
        $this->assertSame(1, RelDynTraitRead::drain()['done']);
        $d = $this->meet('Bryn');
        $this->assertNull($d['_bio_reingest']['last_error']);
        $this->assertSame(1, $d['_bio_reingest']['reads']);
    }

    public function testTheConfigSwitchStopsTheCheckTheQueueAndTheWorker(): void
    {
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->config(['trait_reader' => ['reingest' => ['enabled' => false]]]);
        $this->setTime($this->t0 + 91 * self::DAY);
        $d = $this->look('Bryn');
        $this->assertSame([], $this->rows('live:bryn'), 'off: nothing is queued');
        $this->assertEquals($this->t0, $d['_bio_reingest']['last_check'], 'and nothing is checked');

        // a row queued while it was on stays queued while it is off, and the first read's queue is not affected
        $this->config([]);
        $this->look('Bryn');
        $this->assertCount(1, $this->rows('live:bryn'));
        $this->config(['trait_reader' => ['reingest' => ['enabled' => false]]]);
        $llm = $this->llmCalls;
        $stats = RelDynTraitRead::drain();
        $this->assertSame(0, $stats['processed'], 'the worker leaves live re-reads alone while the switch is off');
        $this->assertSame($llm, $this->llmCalls);
        $this->assertSame('pending', $this->rows('live:bryn')[0]['status']);
        $this->meet('Bryn');
        $this->assertNotNull($this->stored($id)['_bio_reingest']['pending'], 'and nothing is applied');

        $this->config([]);
        $this->llmOut = self::rewrittenRead();
        $this->assertSame(1, RelDynTraitRead::drain()['done'], 'switched back on: the queued read goes through');
    }

    public function testTheTraitReaderSwitchAlsoStopsIt(): void
    {
        $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->config(['trait_reader' => ['enabled' => false]]);
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->assertSame([], $this->rows('live:bryn'));
    }

    // ------------------------------------------------------------------ exempt

    public function testAHandSetVectorIsNeverRereadAndNothingIsStoredForIt(): void
    {
        $this->template('ashe', ['personality' => 'Placeholder ashe text.'] + self::FIELDS);
        $id = $this->npc('Ashe', ['personality' => 'Placeholder ashe text.'] + self::FIELDS, 'female', 'Sorcerer', 'BretonRace');
        $this->meet('Ashe');
        $d = $this->look('Ashe');
        $this->assertSame('skip', $d['_trait_vector_src']['read_status']);
        $this->rewriteBio('Ashe', 'A placeholder rewrite of the live profile.');
        $before = $this->vector($id);
        $this->setTime($this->t0 + 400 * self::DAY);
        $d = $this->look('Ashe');
        $this->assertArrayNotHasKey('_bio_reingest', $d, 'nothing is even recorded for a hand-set vector');
        $this->assertSame([], $this->rows('live:ashe'));
        $this->assertSame([], $this->rows('ashe'), 'and no template read either');
        $this->assertSame($before, $this->vector($id));
        $this->assertSame(0, $this->llmCalls);
        // even a forced request is refused
        $r = RelDynTraitReingest::requestRead('Ashe', $d);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('skip list', $r['message']);
        $this->assertSame([], $this->rows('live:ashe'));
    }

    public function testAnEditorPresetIsExemptAndAPartialTraitOverrideKeepsItsTraitWhileTheRestMove(): void
    {
        // preset: the whole vector is the preset point
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->look('Bryn', function (array &$d) { RelationshipDynamics::setProfileOverride($d, 'temperament', 'Bold'); });
        $this->setTime($this->t0 + 91 * self::DAY);
        $d = $this->look('Bryn');
        $this->assertSame([], $this->rows('live:bryn'), 'an editor preset: not re-read');
        $this->assertArrayNotHasKey('_bio_reingest', $d, 'nothing is kept for an exempt NPC');
        $this->look('Bryn', function (array &$d) { RelationshipDynamics::setProfileOverride($d, 'temperament', null); });

        // one trait set in the editor: warmth stays put, the rest of the vector still moves
        $this->look('Bryn', function (array &$d) {
            RelationshipDynamics::setProfileOverride($d, 'trait_vector', ['warmth' => 0.95]);
        });
        $before = $this->vector($id);
        $this->assertEqualsWithDelta(0.95, $before['W'], 1e-9);
        $this->setTime($this->t0 + 182 * self::DAY);
        $this->look('Bryn');
        $this->assertCount(1, $this->rows('live:bryn'));
        $this->llmOut = self::rewrittenRead();
        RelDynTraitRead::drain();
        $this->meet('Bryn');
        $x = $this->vector($id);
        $this->assertEqualsWithDelta(0.95, $x['W'], 1e-9, 'the editor override wins on its trait');
        $this->assertGreaterThan($before['G'], $x['G'], 'the others move');
        $this->assertLessThan(0.0, $this->stored($id)['_bio_reingest']['drift']['warmth'], 'the drift is kept underneath: clearing the override shows it');
    }

    // ------------------------------------------------------------------ persistence

    public function testTheDriftSurvivesALaterResolutionOfTheProfile(): void
    {
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->llmOut = self::rewrittenRead();
        RelDynTraitRead::drain();
        $this->meet('Bryn');
        $x = $this->vector($id);
        $drift = $this->stored($id)['_bio_reingest']['drift'];
        $this->assertNotSame([], $drift);

        // a priors / version change makes the profile resolve again: the drift is still on top
        $this->look('Bryn', function (array &$d) { $d['_profile_autogen']['version'] = 0; });
        $this->assertSame(0, $this->stored($id)['_profile_autogen']['version'], 'the bump is stored');
        $this->meet('Bryn');
        $this->assertSame(RelationshipDynamics::PROFILE_AUTOGEN_VERSION, $this->stored($id)['_profile_autogen']['version'], 'the profile resolved again');
        $y = $this->vector($id);
        foreach ($x as $code => $v) $this->assertEqualsWithDelta($v, $y[$code], 1e-9, $code);
        $this->assertSame($drift, $this->stored($id)['_bio_reingest']['drift']);
    }

    public function testAChangedShippedTemplateStartsTheNpcOverFromTheNewRead(): void
    {
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->llmOut = self::rewrittenRead();
        RelDynTraitRead::drain();
        $this->meet('Bryn');
        $this->assertNotSame([], $this->stored($id)['_bio_reingest']['drift']);

        // a mod update ships a new template: its read is a first read again (the existing template path queues it
        // when the profile next resolves; a version bump makes it resolve now)
        pg_query_params($this->db->link, 'UPDATE combined_bio_templates SET personality = $2 WHERE npc_name = $1', ['bryn', 'Bryn is calm and steady.']);
        RelDynTraitRead::reset();
        $this->look('Bryn', function (array &$d) { $d['_profile_autogen']['version'] = 0; });
        $this->meet('Bryn');
        $s = $this->stored($id)['_bio_reingest'];
        $this->assertSame([], $s['drift'], 'the new first read replaces the old story');
        $this->assertSame([], $s['history']);
        $this->assertNull($s['base_hash']);
        $this->assertSame(RelDynTraitRead::srcHash(array_replace(self::FIELDS, ['personality' => 'Bryn is calm and steady.'])), $s['anchor']);
    }

    public function testNoModelCallEverRunsInsideARequest(): void
    {
        $this->template('bryn');
        $this->npc('Bryn');
        $this->meet('Bryn');
        $this->assertSame(0, $this->llmCalls);
        RelDynTraitRead::drain();
        $this->meet('Bryn');
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $calls = $this->llmCalls;
        foreach ([91, 182, 273] as $d) {
            $this->setTime($this->t0 + $d * self::DAY);
            $this->look('Bryn');
            $this->meet('Bryn');
        }
        $this->assertSame($calls, $this->llmCalls, 'only the worker reads');
    }

    public function testAStoredPartialConfigRowKeepsTheOtherDefaults(): void
    {
        $this->config(['trait_reader' => ['reingest' => ['every_game_days' => 30]]]);
        $cfg = RelDynTraitReingest::config();
        $this->assertSame(30, $cfg['every_game_days']);
        $this->assertTrue($cfg['enabled']);
        $this->assertSame(RelDynTraitReingest::defaultConfig()['floor'], $cfg['floor']);
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 31 * self::DAY);
        $this->look('Bryn');
        $this->assertCount(1, $this->rows('live:bryn'), 'the shorter cadence applies');
    }

    // ------------------------------------------------------------------ the editor

    /** @return array [response, session] */
    private function editorGet(string $npc, array $session = []): array
    {
        $r = RelDynEditor::handle('GET', ['npc' => $npc], [], $session);
        return [$r, $session];
    }

    private function bioBlock(string $html): string
    {
        $a = strpos($html, '<h3 class="rd-intro">Bio read</h3>');
        $this->assertNotFalse($a, 'the bio read block is on the page');
        $b = strpos($html, '</form>', $a);
        return substr($html, $a, $b - $a);
    }

    public function testTheEditorShowsWhenTheProfileWasLastReadAndTheButton(): void
    {
        $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->setTime($this->t0 + 20 * self::DAY);
        [$r] = $this->editorGet('Bryn');
        $this->assertSame(200, $r['status']);
        $block = $this->bioBlock($r['body']);
        $this->assertStringContainsString('Profile last read: game day 200 (20 game days ago)', $block);
        $this->assertStringContainsString('every 90 game days and after a milestone', $block);
        $this->assertStringContainsString('name="op" value="reread"', $block, 'the read again button');
        $this->assertStringNotContainsString('queued', $block);
        $this->assertSame([], $this->rows('live:bryn'), 'opening the page never queues a read');
        $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|he|his|him)\b/i', $block, 'no pronoun in the new copy');
    }

    public function testReadAgainIsPostOnlyWithTheCsrfTokenAndQueuesWithoutRunningAModel(): void
    {
        $id = $this->brynWithFirstRead();
        $this->look('Bryn');
        $calls = $this->llmCalls;
        [$page, $session] = $this->editorGet('Bryn');
        $token = $session[RelDynEditor::CSRF_SESSION_KEY];

        // GET with the op in the query does nothing
        $r = RelDynEditor::handle('GET', ['npc' => 'Bryn', 'op' => 'reread'], [], $session);
        $this->assertSame(200, $r['status']);
        $this->assertSame([], $this->rows('live:bryn'));

        // POST without or with a wrong token: refused, nothing queued
        foreach ([null, 'wrong'] as $bad) {
            $post = ['npc' => 'Bryn', 'op' => 'reread', 'section' => 'personality'] + ($bad === null ? [] : ['csrf_token' => $bad]);
            $r = RelDynEditor::handle('POST', [], $post, $session);
            $this->assertSame(403, $r['status']);
        }
        $this->assertSame([], $this->rows('live:bryn'));

        $post = ['npc' => 'Bryn', 'op' => 'reread', 'section' => 'personality', 'csrf_token' => $token];
        $r = RelDynEditor::handle('POST', [], $post, $session);
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('#sec-personality', $r['location']);
        $this->assertTrue($session[RelDynEditor::FLASH_SESSION_KEY]['ok']);
        $this->assertStringContainsString('queued', $session[RelDynEditor::FLASH_SESSION_KEY]['message']);
        $rows = $this->rows('live:bryn');
        $this->assertCount(1, $rows, 'even though the text did not change: a forced read');
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame($calls, $this->llmCalls, 'the page runs no model');
        $s = $this->stored($id)['_bio_reingest'];
        $this->assertSame('manual', $s['pending']['reason']);
        $this->assertTrue($s['pending']['forced']);

        // the page now says so, and does not offer a second queue
        [$r2] = $this->editorGet('Bryn', $session);
        $block = $this->bioBlock($r2['body']);
        $this->assertStringContainsString('A re-read is queued', $block);
        $this->assertStringNotContainsString('value="reread"', $block);
        $r = RelDynEditor::handle('POST', [], $post, $session);
        $this->assertStringContainsString('already queued', $session[RelDynEditor::FLASH_SESSION_KEY]['message']);
        $this->assertCount(1, $this->rows('live:bryn'));

        // the worker reads it; the same text read again moves the vector only as far as the new read differs
        RelDynTraitRead::drain();
        $this->assertSame($calls + 1, $this->llmCalls);
        $this->meet('Bryn');
        $s = $this->stored($id)['_bio_reingest'];
        $this->assertNull($s['pending']);
        $this->assertSame(1, $s['reads']);
        $this->assertGreaterThan($this->t0 - 1, $s['last_read']);
        $this->assertSame([], $this->db->failures);
    }

    public function testTheButtonRefusesAnExemptNpcAndASwitchedOffFeatureAndTheEditorSaysWhy(): void
    {
        $this->template('ashe', ['personality' => 'Placeholder ashe text.'] + self::FIELDS);
        $this->npc('Ashe', ['personality' => 'Placeholder ashe text.'] + self::FIELDS, 'female', 'Sorcerer', 'BretonRace');
        $this->meet('Ashe');
        [$page, $session] = $this->editorGet('Ashe');
        $block = $this->bioBlock($page['body']);
        $this->assertStringContainsString('This NPC is not re-read: on the read skip list', $block);
        $this->assertStringNotContainsString('value="reread"', $block, 'no button for a hand-set vector');
        $post = ['npc' => 'Ashe', 'op' => 'reread', 'section' => 'personality', 'csrf_token' => $session[RelDynEditor::CSRF_SESSION_KEY]];
        RelDynEditor::handle('POST', [], $post, $session);
        $this->assertFalse($session[RelDynEditor::FLASH_SESSION_KEY]['ok']);
        $this->assertStringContainsString('not re-read', $session[RelDynEditor::FLASH_SESSION_KEY]['message']);
        $this->assertSame([], $this->rows('live:ashe'));

        $this->brynWithFirstRead();
        $this->config(['trait_reader' => ['reingest' => ['enabled' => false]]]);
        [$page2, $session2] = $this->editorGet('Bryn');
        $this->assertStringContainsString('switched off', $this->bioBlock($page2['body']));
        $this->assertStringNotContainsString('value="reread"', $this->bioBlock($page2['body']));
        RelDynEditor::handle('POST', [], ['npc' => 'Bryn', 'op' => 'reread', 'section' => 'personality',
            'csrf_token' => $session2[RelDynEditor::CSRF_SESSION_KEY]], $session2);
        $this->assertFalse($session2[RelDynEditor::FLASH_SESSION_KEY]['ok']);
        $this->assertSame([], $this->rows('live:bryn'));
    }

    public function testTheEditorShowsTheHistoryAfterAReRead(): void
    {
        $this->brynWithFirstRead();
        $this->look('Bryn');
        $this->rewriteBio('Bryn');
        $this->setTime($this->t0 + 91 * self::DAY);
        $this->look('Bryn');
        $this->llmOut = self::rewrittenRead();
        RelDynTraitRead::drain();
        $this->meet('Bryn');
        [$r] = $this->editorGet('Bryn');
        $block = $this->bioBlock($r['body']);
        $this->assertStringContainsString('Profile last read: game day 291', $block);
        $this->assertStringContainsString('1 re-read so far', $block);
        $this->assertStringContainsString('data-reingest="history"', $block);
        $this->assertStringContainsString('guard +', $block, 'what moved, signed');
        $this->assertStringContainsString('warmth -', $block);
        $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|he|his|him)\b/i', $block);
    }
}
