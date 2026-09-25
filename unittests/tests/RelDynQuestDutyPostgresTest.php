<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (fetchOne returns [] on failure, CHIM's convention). */
final class RelDynQuestDutyPgDb
{
    public $link;
    public array $failures = [];
    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($this->link, "SET search_path TO {$schema}");
    }
    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link); return []; }
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
    public function execQuery($q) { $r = @pg_query($this->link, $q); if (!$r) $this->failures[] = pg_last_error($this->link); return $r; }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * duty-override (MDD 9) and quest-event-hook on core's own quest data (CHIM 3.4.1 quests /
 * questlog, live dwemer columns), no MinAI, no LLM:
 *   - the journal names an NPC through its resolved aliases (core's currentbrief2 in quests.data)
 *     or its displayed objective, as a whole name;
 *   - duty applies to a HOSTILE NPC the journal names (autonomy refusing / walkaway, a hostile core
 *     type, or core affinity in the hostile tier), with the game-side conf_opts flag kept;
 *   - an eval item of a duty exchange carries duty_factor and its negative signals land dampened,
 *     its positive ones in full;
 *   - questlog stage changes reach onQuestEvent once each for the NPCs the quest names; background
 *     quests (not in the journal) name nobody; a configured life-changing stage is a divine
 *     intervention.
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynQuestDutyPostgresTest extends TestCase
{
    private const NOW = 5000000000;
    private string $dsn;
    private string $schema;
    private RelDynQuestDutyPgDb $db;
    private array $saved = [];
    private string $log;
    private $prevLog = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_qd_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, npc_name text NOT NULL, npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0, prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text,
            personality text, relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text)");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE questlog (ts text, sess varchar(1024), id_quest varchar(1024), name text,
            editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text, stage integer,
            briefing text, briefing2 text, localts bigint, gamets bigint, data text, status text, rowid serial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text)");
        pg_close($admin);
        $this->db = new RelDynQuestDutyPgDb($dsn, $this->schema);
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RELDYN_DUTY_FACTOR', 'RELDYN_DUTY'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) self::NOW, 'Kaida: hello'];
        $this->log = tempnam(sys_get_temp_dir(), 'rdqd');
        $this->prevLog = ini_set('error_log', $this->log);
        pg_query($this->db->link, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        foreach (['Aela the Huntress', 'Ashe', 'Muiri'] as $npc) {
            // a bond with the player (core affinity 40): her words land (social sensitivity)
            pg_query_params($this->db->link, "INSERT INTO core_npc_master (npc_name, metadata, extended_data) VALUES ($1, '{}', $2::jsonb)",
                [$npc, json_encode(['relationships' => ['Kaida' => ['aff' => 40, 'type' => 'platonic']]])]);
        }
        RelDynTraitRead::reset();   // its table-existence memo is per db object
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->log);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function config(array $over): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace(RelationshipDynamics::defaultConfig(), $over))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** A journal row as comm.php '_quest' writes it (data = currentbrief2: alias => resolved name). */
    private function journal(string $id, string $name, int $stage, string $briefing, array $aliases): void
    {
        pg_query_params($this->db->link, "INSERT INTO quests (ts, gamets, name, briefing, data, stage, giver_actor_id, id_quest, sess, status, localts)
            VALUES ('1', $1, $2, $3, $4, $5, '', $6, 'pending', '', 1)", [self::NOW - 1000, $name, $briefing, json_encode($aliases), $stage, $id]);
    }

    /** A questlog row as comm.php '_uquest' writes it (briefing = the objective's raw text). */
    private function stage(string $id, int $stage, string $objective): void
    {
        pg_query_params($this->db->link, "INSERT INTO questlog (ts, gamets, localts, briefing, data, id_quest, stage) VALUES ('1', $1, 1, $2, $2, $3, $4)",
            [self::NOW - 500, $objective, $id, $stage]);
    }

    private static function npc(array $dims, array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => 'Guarded', 'profile_overrides' => ['attachment_style' => 'secure'], 'dimensions' => []];
        foreach ($dims + ['maturity' => 55.0, 'self_confidence' => 60.0, 'trust' => 60.0, 'respect' => 60.0,
                          'comfort' => 50.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    public function testTheJournalNamesAnNpcByItsResolvedAliasesOrObjectiveAsAWholeName(): void
    {
        $this->journal('C01', 'Proving Honor', 20, 'Talk to Aela the Huntress.', ['QuestGiver' => 'Aela the Huntress', 'Talk to Aela the Huntress' => 'Talk to Aela the Huntress']);
        $this->journal('MQ101', 'Unbound', 10, 'Scatter the ashes of the fallen.', ['Loc' => 'Helgen']);
        $this->journal('X01', 'A Test of Wildcards', 5, 'Find M_iri.', ['Target' => 'M_iri']);
        $this->assertSame([['id' => 'C01', 'name' => 'Proving Honor', 'stage' => 20]], RelDynQuests::questsNaming('Aela the Huntress'));
        $this->assertSame([], RelDynQuests::questsNaming('Ashe'), '"ashes" is not Ashe');
        $this->assertSame([], RelDynQuests::questsNaming('Muiri'), "'_' in another name is not a LIKE wildcard for her");
        $this->assertSame([], $this->db->failures);
    }

    public function testDutyIsForAHostileNpcTheJournalNames(): void
    {
        $this->journal('C01', 'Proving Honor', 20, 'Talk to Aela the Huntress.', ['QuestGiver' => 'Aela the Huntress']);
        $friendly = self::npc([]);
        $hostile = self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0]);
        $this->assertSame('refusing', RelationshipDynamics::evaluateAutonomyState($hostile, 'Guarded')['state']);

        $this->assertNull(RelDynQuests::dutyState('Aela the Huntress', $friendly), 'a friendly NPC talks as herself');
        $duty = RelDynQuests::dutyState('Aela the Huntress', $hostile);
        $this->assertSame(['factor' => 0.1, 'quest' => 'Proving Honor', 'quest_id' => 'C01', 'hostile' => 'autonomy:refusing', 'source' => 'journal'], $duty);
        $this->assertNull(RelDynQuests::dutyState('Muiri', $hostile), 'no quest names Muiri: her hostility is her own');

        $enemy = self::npc([], ['_core_rel_type' => 'enemy']);
        $this->assertSame('core_type:enemy', RelDynQuests::dutyState('Aela the Huntress', $enemy)['hostile'] ?? null);
        $cold = self::npc([], ['_aff_mirror_x' => 40.0]);
        $cold['dimensions']['affinity'] = ['x' => 40.0, 'baseline' => 50.0];   // core -20: the hostile tier
        $this->assertSame('affinity', RelDynQuests::dutyState('Aela the Huntress', $cold)['hostile'] ?? null);

        $this->config(['duty_override_enabled' => false]);
        $this->assertNull(RelDynQuests::dutyState('Aela the Huntress', $hostile), 'switched off');
        $this->config(['quests' => ['duty_dampen' => 0.0]]);
        $this->assertSame(0.0, RelDynQuests::dutyState('Aela the Huntress', $hostile)['factor'], 'config: paused entirely');
        $this->assertSame([], $this->db->failures);
    }

    public function testTheGameSideFlagStillOverrides(): void
    {
        pg_query($this->db->link, "INSERT INTO conf_opts (id, value) VALUES ('_duty_override_active', '{\"active\":true,\"dampening\":0.3}')");
        $duty = RelDynQuests::dutyState('Muiri', self::npc([]));
        $this->assertSame(0.3, $duty['factor'] ?? null);
        $this->assertSame('flag', $duty['source'] ?? null);
    }

    public function testPrerequestStateReachesTheEvalJobAndOnlyNegativeSignalsAreDampened(): void
    {
        $this->journal('C01', 'Proving Honor', 20, 'Talk to Aela the Huntress.', ['QuestGiver' => 'Aela the Huntress']);
        $d = self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0]);
        $this->assertNotNull(RelDynQuests::onPrerequest('Aela the Huntress', $d));
        $this->assertSame(0.1, $GLOBALS['RELDYN_DUTY_FACTOR']);
        $this->assertSame('Proving Honor', $d[RelDynQuests::DUTY_KEY]['quest']);
        $this->assertSame(['quest' => 'Proving Honor', 'factor' => 0.1, 'hostile' => 'autonomy:refusing'], RelDynQuests::jev($d));

        // The parsed eval item carries the job's factor (code-written, never the LLM's)
        $raw = json_encode(['signals' => ['affinity' => -4, 'trust' => -6, 'comfort' => 3, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['insult'], 'grievance' => ['flag' => false], 'jealousy' => ['flag' => false], 'significance' => 0.4, 'summary' => 'Cold words over the quest.']);
        require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';
        $item = RelDynEval::parseResponse($raw, ['npc' => 'Aela the Huntress', 'npc_id' => 1, 'player_name' => 'Kaida', 'gamets' => self::NOW, 'duty_factor' => 0.1]);
        $this->assertSame(0.1, $item['duty_factor']);
        $plain = RelDynEval::parseResponse($raw, ['npc' => 'Aela the Huntress', 'npc_id' => 1, 'player_name' => 'Kaida', 'gamets' => self::NOW]);
        $this->assertArrayNotHasKey('duty_factor', $plain);

        $onDuty = self::npc(['trust' => 50.0, 'comfort' => 50.0]);
        $off = $onDuty;
        $dutyTotals = RelationshipDynamics::processEvalContractItem('Aela the Huntress', $item, $onDuty);
        $plainTotals = RelationshipDynamics::processEvalContractItem('Aela the Huntress', $plain, $off);
        $this->assertLessThan(0.0, $plainTotals['trust'] ?? 0.0, json_encode([$plainTotals, $this->db->failures]));
        $this->assertEqualsWithDelta($plainTotals['trust'] * 0.1, $dutyTotals['trust'], abs($plainTotals['trust']) * 0.15,
            'the quest-scripted friction lands at a tenth ' . json_encode([$dutyTotals, $plainTotals]));
        $this->assertEqualsWithDelta($plainTotals['comfort'], $dutyTotals['comfort'], 1e-9, 'a positive signal counts in full');

        $friendly = self::npc([]);
        $this->assertNull(RelDynQuests::onPrerequest('Aela the Huntress', $friendly));
        $this->assertArrayNotHasKey(RelDynQuests::DUTY_KEY, $friendly);
    }

    public function testQuestStagesReachTheNpcsTheJournalNamesOnceEach(): void
    {
        $this->config(['quests' => ['life_changing' => [['quest' => 'C06', 'stage_min' => 10, 'npcs' => ['Aela the Huntress'], 'severity' => 4]]]]);
        $this->journal('C06', 'Glory of the Dead', 10, 'Speak with Aela the Huntress.', ['Circle' => 'Aela the Huntress', 'Leader' => 'Kaida']);
        $this->journal('MG01', 'First Lessons', 5, 'Speak to Mirabelle.', ['Teacher' => 'Mirabelle Ervine']);
        $this->assertSame(0, RelDynQuests::consumeQuestlog(), 'the first run anchors at the newest row');

        $this->stage('C06', 10, 'Speak with <Alias=Circle>');
        $this->stage('MG01', 5, 'Speak to <Alias=Teacher>');
        $this->stage('DialogueGeneric', 0, '');   // an engine quest: not in the journal, names nobody
        $this->assertSame(3, RelDynQuests::consumeQuestlog());
        $this->assertSame(0, RelDynQuests::consumeQuestlog(), 'each row once');

        $aela = RelationshipDynamics::getDynamics('Aela the Huntress');
        $events = $aela[RelDynQuests::EVENTS_KEY] ?? [];
        $this->assertCount(1, $events, json_encode($events));
        $this->assertSame(['C06', 'Glory of the Dead', 10], [$events[0]['id'], $events[0]['name'], $events[0]['stage']]);
        $this->assertTrue($events[0]['divine'] ?? false, 'a life-changing stage for her');
        $this->assertSame(1, intval($aela['_divine_intervention_count'] ?? 0));
        $this->assertArrayNotHasKey(RelDynQuests::EVENTS_KEY, RelationshipDynamics::getDynamics('Muiri'));

        // The same stage again (a reload replays the journal) reaches her once
        $again = $aela;
        $this->assertFalse(RelationshipDynamics::onQuestEvent('Aela the Huntress', 'C06', 10, $again, ['name' => 'Glory of the Dead'])['recorded']);
        $this->assertSame([], $this->db->failures);
        $this->assertStringNotContainsString('ERROR', (string) file_get_contents($this->log));
    }
}
