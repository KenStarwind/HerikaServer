<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 * Failed statements are recorded so the test can show none happened.
 */
final class RelDynEvalE2ePgDb
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
        if (!$res) {
            $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
            return [];
        }
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
        if (!$res) $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
        return $res;
    }

    /** lib/postgresql.class.php insert(): parameterized INSERT of an assoc row. */
    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * The whole Phase 2 eval path on a real PostgreSQL, one exchange:
 *
 *   eventlog (the player insults Muiri, holding Lydia over her)
 *   -> postrequest.php (request 1) queues a job in reldyn_eval_queue, anchored at the exchange
 *   -> RelDynEval::runWorker() reads the eventlog window and calls the eval LLM
 *      (STUBBED: canned contract JSON at the connector boundary, the only fake here)
 *   -> contract v1 item in plugin_extended_data.reldyn.eval_inbox, which the worker applies
 *      right away (eval_producer.apply_in_worker; else the NPC's next postrequest.php does):
 *      signals through the MDD 15.4 pipeline with the
 *      decisions §1 multipliers, grievance -> resentment (MDD 15.5), jealousy event (§5),
 *      affinity committed to core relationships.Player.aff under core's lock.
 *
 * Muiri is seeded as an immature (maturity 20), already jealous (65) Jealous-temperament NPC
 * with secure attachment, Adaptive maturity type, resentment 35, trust 50 at baseline and
 * core affinity 20 (the Jealous affinity baseline, so distance decay is 1).
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * The test works in its own schema and drops it.
 */
final class RelDynEvalEndToEndTest extends TestCase
{
    private const NPC = 'Muiri';
    private const PLAYER = 'Kaida';
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;   // raw gamets per game hour
    private const T0 = 400 * RelationshipDynamics::GAMETS_PER_DAY;    // raw gamets: game day 400

    /** What the eval model answers for this exchange (contract v1 fields the LLM fills). */
    private const LLM_REPLY = '{"signals": {"affinity": -10, "trust": -8, "comfort": 0, "respect": 0, "passion": 0, "maturity": 0},
        "tags": ["insult", "jealousy_trigger"],
        "grievance": {"flag": true, "kind": "insult", "severity": 2},
        "jealousy": {"flag": true, "rival": "Lydia", "intensity": 1},
        "significance": 1.0,
        "summary": "Kaida belittled her and held Lydia up as the better woman."}';

    private string $dsn;
    private string $schema;
    private RelDynEvalE2ePgDb $db;
    private array $savedGlobals = [];
    private string $logFile;
    private $prevLog = null;
    private int $launches = 0;
    private int $npcId = 0;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_e2e' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and core_npc_master_history.sql
        // (no core_profiles FK): core's relationship timeline stamp runs after the affinity write.
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
        // core_player: the player profile the Attraction Matrix reads each request (RelDynPlayer::profile)
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // Same columns as data/database_default.sql eventlog / responselog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // Core 3.4.1 locations (debug/db_updates.php columns): RelDyn reads the current place.
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_close($admin);

        $this->db = new RelDynEvalE2ePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'SCRIPTLINE_LISTENER_ATOMIC', 'SCRIPTLINE_LISTENER'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured (the call itself is stubbed)
        RelDynEval::$launcher = function (): void { $this->launches++; };
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdevale2e');
        $this->prevLog = ini_set('error_log', $this->logFile);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_eval_e2e_test.log');

        // Default config (passion on), debug log on for the [EVAL-MATH] lines. The legacy
        // local classifier stands down for an exchange the eval scores, so the numbers below
        // are the eval's alone.
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', [
            RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true])),
        ]);
        RelationshipDynamics::clearConfigCache();

        $this->npcId = $this->seedMuiri();
        $this->seedLydia();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        RelDynEval::$launcher = null;
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixture

    /** Muiri: core relationships.Player {aff 20, romantic}; RelDyn profile stored (no auto-gen). */
    private function seedMuiri(): int
    {
        $dim = fn(float $x, float $baseline, array $extra = []) => ['x' => $x, 'baseline' => $baseline] + $extra;
        $dynamics = [
            'inferred_temperament' => 'Jealous',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'traits' => [],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_TOUCH,
            '_interest_vector' => [0.1, 0.2, 0.3],
            '_profile_autogen' => ['version' => RelationshipDynamics::PROFILE_AUTOGEN_VERSION],
            '_core_rel_type' => 'romantic',
            '_internal_weather' => 'clear',
            'jealousy_anger' => 65.0,                        // jealousy points 0..100
            '_last_contact_gamets' => self::T0,
            '_decay_last_game_gamets' => self::T0,
            '_aff_mirror_x' => 60.0,                         // mirror of core aff 20: (20 + 100) / 2
            'dimensions' => [
                // baseline null: the Jealous temperament's, in core units (20) as applyDelta reads it
                'affinity' => ['x' => 60.0, 'baseline' => null],
                'maturity' => $dim(20.0, 20.0, ['plasticity_type' => 'Adaptive']),
                'resentment' => $dim(35.0, 0.0),
                'trust' => $dim(50.0, 50.0),
                'comfort' => $dim(50.0, 50.0),
                'respect' => $dim(50.0, 50.0),
                'self_confidence' => $dim(50.0, 50.0),   // >= 30: not a people-pleaser
                'passion' => $dim(0.0, 0.0),
            ],
        ];
        $dynamics['passion'] = 0.0;
        $ns = ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]];
        $ext = ['relationships' => ['Player' => ['aff' => 20, 'type' => 'romantic']]];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, extended_data, plugin_extended_data)
             VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb) RETURNING id',
            [self::NPC, '133A6', 'female', 'BretonRace', json_encode($ext), json_encode(['reldyn' => $ns])]));
        return (int) $row['id'];
    }

    /** Lydia stands nearby (the rival named in the exchange); no RelDyn state of her own. */
    private function seedLydia(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, refid, extended_data) VALUES ($1, $2, $3::jsonb)',
            ['Lydia', 'A2C94', json_encode(['relationships' => ['Player' => ['aff' => 40, 'type' => 'platonic']]])]);
    }

    private function event(string $type, string $data, int $gamets, ?string $state = null): int
    {
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING rowid',
            [$type, $data, 'pending', $gamets, 1727000000 + intdiv($gamets, 1000), $gamets, '|Muiri|Lydia|Kaida|',
             "Markarth, The Hag's Cure", $state]));
        return (int) $row['rowid'];
    }

    /** An NPC reply the way returnLines() logs it: a prechat copy, then the chat row. */
    private function npcSays(string $speaker, string $text, string $listener, int $gamets): void
    {
        $line = "{$speaker}: {$text} (talking to {$listener})";
        $this->event('prechat', $line, $gamets + 1);
        $this->event('chat', $line, $gamets + 2, 'emitted');
    }

    /** Earlier small talk, then the exchange under test at T0 (player row logged twice, as CHIM does). */
    private function seedEventlog(): void
    {
        $t = self::T0 - 2 * (int) self::HOUR;
        $this->event('infoloc', "(Context location: The Hag's Cure, Markarth)", $t);
        $this->event('inputtext', 'Kaida: How are you holding up, Muiri? (Talking to Muiri)', $t + 10);
        $this->npcSays(self::NPC, "Better, now that you're here. I missed you.", self::PLAYER, $t + 10);
        $this->npcSays('Lydia', 'My Thane, we should not linger in Markarth.', self::PLAYER, $t + 50);

        $insult = "Kaida: Lydia is twice the woman you'll ever be. Stop whining at me. (Talking to Muiri)";
        $this->event('inputtext', $insult, self::T0);
        $this->event('inputtext', "(Context location: The Hag's Cure, Markarth){$insult}", self::T0);
        $this->npcSays(self::NPC, 'How could you say that? After everything... Go to her, then.', self::PLAYER, self::T0);
    }

    private function postrequest(array $gameRequest): void
    {
        $GLOBALS['gameRequest'] = $gameRequest;
        $GLOBALS['RELDYN_NPC_NAME'] = self::NPC;
        $GLOBALS['HERIKA_NAME'] = self::NPC;
        $GLOBALS['CACHE_PEOPLE'] = '|Muiri|Lydia|Kaida|';
        $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    private function reldyn(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->npcId]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    private function coreAff(): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT extended_data FROM core_npc_master WHERE id = $1', [$this->npcId]));
        return (int) json_decode($r['extended_data'], true)['relationships']['Player']['aff'];
    }

    private function jobs(): array
    {
        $res = @pg_query($this->db->link, 'SELECT id, npc_id, status, job::text AS job FROM reldyn_eval_queue ORDER BY id');
        if (!$res) return [];   // the queue table is created by the first enqueue
        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $r['job'] = json_decode($r['job'], true);
            $rows[] = $r;
        }
        return $rows;
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    // ------------------------------------------------------------------ the path

    public function testAnInsultToAnImmatureJealousNpcFlowsFromEventlogToCoreAffinity(): void
    {
        $this->seedEventlog();
        $insult = "Kaida: Lydia is twice the woman you'll ever be. Stop whining at me. (Talking to Muiri)";

        // --- request 1: the exchange happens; the hook queues one job anchored at it ---
        $anchor = (int) pg_fetch_result(pg_query($this->db->link, 'SELECT max(rowid) FROM eventlog'), 0, 0);
        $this->postrequest(['inputtext', '1727000400', (string) self::T0, $insult]);
        $jobs = $this->jobs();
        $this->assertCount(1, $jobs, 'one job for the exchange: ' . $this->log());
        $this->assertSame($this->npcId, (int) $jobs[0]['npc_id']);
        $this->assertSame($anchor, $jobs[0]['job']['anchor_rowid']);
        $this->assertSame(1, $this->launches, 'the worker was asked to start');
        $this->assertSame([], $this->reldyn()['eval_inbox'] ?? [], 'nothing evaluated yet');

        $before = $this->reldyn()['dynamics'];
        $coreBefore = $this->coreAff();
        $this->assertSame(20, $coreBefore, 'request 1 has no eval to apply: core untouched');
        // The legacy local classifier (an insult reads as quality_time there) stands down.
        $this->assertEqualsWithDelta(0.0, (float) $before['passion'], 1e-9, 'no legacy passion gain for an insult');
        $this->assertEqualsWithDelta(0.0, (float) ($before['_pending_aff_delta'] ?? 0), 1e-9, 'no legacy affinity speed queued');
        $this->assertStringContainsString('legacy classifier stands down', $this->log());

        // --- the worker: eventlog window -> (stubbed) eval LLM -> contract item in the inbox ---
        $calls = [];
        $stats = RelDynEval::runWorker(function (array $messages, array $params) use (&$calls): string {
            $calls[] = $messages;
            return self::LLM_REPLY;
        });
        $this->assertSame(1, $stats['queued'], json_encode($stats) . ' ' . $this->log());
        $this->assertCount(1, $calls, 'one eval call');
        $prompt = $calls[0][1]['content'];
        $this->assertSame(1, substr_count($prompt, "Lydia is twice the woman you'll ever be"), 'doubled player row read once');
        $this->assertSame(1, substr_count($prompt, 'How could you say that?'), 'prechat copy excluded');
        [, $current] = explode('THIS EXCHANGE (score only this):', $prompt, 2);
        $this->assertStringContainsString("[Kaida] (to Muiri): Lydia is twice the woman you'll ever be. Stop whining at me.", $current);
        $this->assertStringContainsString('[Muiri] (to Kaida): How could you say that? After everything... Go to her, then.', $current);

        // --- the worker applies the item right away (like core's async worker): no later
        //     request of Muiri is needed for the exchange to move core affinity ---
        $stored = $this->reldyn();
        $after = $stored['dynamics'];
        $this->assertSame([], $stored['eval_inbox'] ?? [], 'the worker applied the inbox');
        $log = $this->log();
        // The seam: what the producer wrote is what the consumer validated and applied.
        $this->assertMatchesRegularExpression('/\[RelDyn-EVAL\] job \d+ Muiri: .* tags=insult,jealousy_trigger positive=no/', $log);
        $this->assertMatchesRegularExpression('/\[EVAL\] Muiri item gamets=\S+ sig=1 tags=\[insult,jealousy_trigger\] applied/', $log);
        $this->assertStringContainsString('[RelDyn-EVAL] worker applied the eval inbox of Muiri', $log);

        // Affinity (core points): raw -10 x R(Jealous affinity) 1.3 (MDD 15.4) x P(Adaptive down) 1.0
        //   x M = maturity 20 -> 1 + (50 - 20)/100 = 1.3, x jealousy 65 -> 1 + (65 - 30)/70 = 1.5
        //   (decisions §1) = 1.95; distance decay 1 (core 20 = Jealous baseline); |d| <= 30 x 1.0
        //   = -25.35 core points. Core takes the whole -25 under its lock, -0.35 stays queued.
        $this->assertMatchesRegularExpression(
            '/\[EVAL-MATH\] Muiri affinity: raw -10\.00 tags=\[insult,jealousy_trigger\] x R\(Jealous\)=1\.30 x P\(Adaptive down\)=1\.00 x M=1\.950 \[maturity_losses 1\.300 x jealousy_losses 1\.500\] = -25\.350;.*=> -25\.350 core/',
            $log);
        $this->assertSame(-5, $this->coreAff(), 'core relationships.Player.aff 20 -> -5');
        $this->assertEqualsWithDelta(-0.35, (float) $after['_pending_aff_delta'], 1e-3, 'fraction carried for the next commit');
        $this->assertEqualsWithDelta(60.0 - 12.5, (float) $after['_aff_mirror_x'], 1e-9, 'mirror refreshed from core (-5)');
        $this->assertEqualsWithDelta(0.0, (float) $after['passion'], 1e-9, 'passion moved only by the eval (0 here)');
        $this->assertStringContainsString('[AFF] Muiri -> Player: -25 (aff 20 -> -5)', $log);

        // Trust (0..100): -8 x R(Jealous trust LOSS) 1.8 x P(Adaptive down) 1.0, at baseline (decay 1)
        //   = -14.4 (traits phase 3, A16 split: the gain side is 0.4, a loss the MDD's fast 1.8)
        $this->assertEqualsWithDelta(50.0 - 14.4,
            (float) $after['dimensions']['trust']['x'], 1e-9, 'trust 50 -> 35.6');

        // Resentment (0..100), MDD 15.5 via recordGrievance: raw 5 x severity-2 mult 1.5
        //   x (1 + power_gap 0: romantic, not in party, no factions) = 7.5
        //   -> suppressed buildup x1.5 (resentment 35 > 30, maturity 20 < 50), secure x1.0
        //   -> Y_up = 1.5 - 20/100 = 1.3 (maturity-derived), inverted rubber band away from
        //      baseline 0 at 35: min(3, 1 + 35/8) = 3 => 7.5 x 1.5 x 1.3 x 3 = +43.875
        $this->assertEqualsWithDelta(35.0 + 43.875, (float) $after['dimensions']['resentment']['x'], 1e-4,
            'resentment 35 -> 78.875: one severe insult on an immature NPC');
        $g = end($after['dimensions']['resentment']['grievance_log']);
        $this->assertSame(['insult', 2, 0.0, 'resentment'], [$g['kind'], $g['severity'], (float) $g['power_gap'], $g['target']]);
        $this->assertEqualsWithDelta(7.5, (float) $g['raw'], 1e-9);
        $this->assertCount(1, $after['dimensions']['resentment']['grievance_log'], 'grievance applied exactly once');
        $this->assertSame(0.0, (float) $after['dimensions']['comfort']['x'], 'MDD 15.5: 78.9 >= 70 is withdrawal, comfort drops to 0');
        $this->assertSame([], $after['dimensions']['resentment']['pending_grievances'] ?? [], 'not also on the legacy list');

        // Jealousy (0..100 points), decisions §5: 10 x intensity-1 mult 1.0 x Jealous 2.0 (MDD 1.3)
        //   x secure 1.0 = 20, x the possessive trust damping (traits design §1.4) at the trust
        //   the same item just left, 35.6 (after the phase-3 fast trust loss above):
        //   1 - 0.7 x 0.356 = 0.7508 -> +15.016, rival Lydia; it does not add resentment directly.
        $this->assertEqualsWithDelta(65.0 + 20.0 * (1.0 - 0.7 * 0.356), (float) $after['jealousy_anger'], 1e-9, 'jealousy 65 -> 80.016');
        $this->assertSame('Lydia', $after['jealousy_trigger_npc']);
        $this->assertTrue($after['in_conflict'] ?? false, 'jealousy at 40+ opens a conflict');
        $this->assertEmpty($before['in_conflict'] ?? false);

        // What the worker changed besides the eval: nothing on these four
        $this->assertEqualsWithDelta(65.0, (float) $before['jealousy_anger'], 1e-9);
        $this->assertEqualsWithDelta(35.0, (float) $before['dimensions']['resentment']['x'], 1e-9);
        $this->assertEqualsWithDelta(50.0, (float) $before['dimensions']['trust']['x'], 1e-9);
        $this->assertSame([], $this->jobs(), 'the consumed job is gone');
        $this->assertSame([], $this->db->failures, 'no failed statements');

        // --- request 2 (the NPC's next request) finds nothing left to apply: core and
        //     feelings stay put; it queues its own exchange ---
        $this->postrequest(['inputtext', '1727000460', (string) (self::T0 + 60), 'Kaida: ...']);
        $again = $this->reldyn()['dynamics'];
        $this->assertSame(-5, $this->coreAff());
        $this->assertEqualsWithDelta((float) $after['dimensions']['resentment']['x'], (float) $again['dimensions']['resentment']['x'], 1e-9);
        $this->assertEqualsWithDelta((float) $after['jealousy_anger'], (float) $again['jealousy_anger'], 1e-9);
        $this->assertCount(1, $again['dimensions']['resentment']['grievance_log'], 'the grievance is not applied again');
        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('pending', $jobs[0]['status']);
        $this->assertEquals(self::T0 + 60, $jobs[0]['job']['gamets']);
        $this->assertSame([], $this->db->failures, 'no failed statements');
    }

    /** The worker, as runWorker() runs it, with the canned eval reply. */
    private function runWorker(): array
    {
        $stats = RelDynEval::runWorker(fn(array $messages, array $params): string => self::LLM_REPLY);
        $this->assertSame(1, $stats['queued'], json_encode($stats) . ' ' . $this->log());
        return $stats;
    }

    /**
     * An NPC-initiated line (radiant / rechat) addressed to the player is scored, and the RelDyn
     * postrequest returns before its consumer for such requests. The worker applies it anyway:
     * the player's affinity moves without Muiri ever getting another request.
     */
    public function testAScoredRadiantLineMovesCoreAffinityWithoutAnotherRequest(): void
    {
        $this->seedEventlog();
        $this->postrequest(['radiant', '1727000400', (string) self::T0, '']);
        $this->assertCount(1, $this->jobs(), 'the radiant line addressed to the player is queued: ' . $this->log());

        $this->runWorker();

        $this->assertSame(-5, $this->coreAff(), 'applied by the worker, no later request of Muiri');
        $this->assertSame([], $this->reldyn()['eval_inbox'] ?? []);
        $this->assertSame([], $this->db->failures, 'no failed statements');
    }

    /**
     * A request applying Muiri's inbox holds its lock: the worker leaves the item to it (never
     * applied twice), and the next request applies it.
     */
    public function testWhileARequestHoldsTheInboxTheWorkerLeavesTheItemForTheNextRequest(): void
    {
        $this->seedEventlog();
        $this->postrequest(['inputtext', '1727000400', (string) self::T0, 'Kaida: ...']);
        $other = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($other, 'SELECT pg_advisory_lock(' . RelDynStorage::INBOX_LOCK_CLASS . ', ' . $this->npcId . ')');
        try {
            $this->runWorker();
        } finally {
            pg_close($other);   // the other request ends, its lock goes with its connection
        }
        $this->assertSame(20, $this->coreAff(), 'not applied while another request holds the inbox');
        $this->assertCount(1, $this->reldyn()['eval_inbox'] ?? []);
        $this->assertStringContainsString('another request is applying the eval inbox; left for the next request', $this->log());

        $this->postrequest(['inputtext', '1727000460', (string) (self::T0 + 60), 'Kaida: ...']);

        $this->assertSame(-5, $this->coreAff(), 'the next request applied it');
        $this->assertSame([], $this->reldyn()['eval_inbox'] ?? []);
        $this->assertCount(1, $this->reldyn()['dynamics']['dimensions']['resentment']['grievance_log']);
    }

    /** eval_producer.apply_in_worker off: the worker only fills the inbox; the NPC's next request applies it. */
    public function testWithApplyInWorkerOffTheNextRequestAppliesTheItem(): void
    {
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [
            RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'eval_producer' => ['apply_in_worker' => false]])),
        ]);
        RelationshipDynamics::clearConfigCache();
        $this->seedEventlog();
        $this->postrequest(['inputtext', '1727000400', (string) self::T0, 'Kaida: ...']);

        $this->runWorker();
        $this->assertSame(20, $this->coreAff());
        $this->assertCount(1, $this->reldyn()['eval_inbox'] ?? []);

        $this->postrequest(['inputtext', '1727000460', (string) (self::T0 + 60), 'Kaida: ...']);
        $this->assertSame(-5, $this->coreAff());
        $this->assertSame([], $this->reldyn()['eval_inbox'] ?? []);
    }

    public function testWithTheEvalSwitchedOffTheLocalHeuristicsCarryResentmentDecayAgain(): void
    {
        // Muiri had contract evals before (an old blob may carry the retired flag), then the
        // eval is switched off: nothing will ever arrive in her inbox again.
        $dyn = $this->reldyn()['dynamics'];
        $dyn['_eval_feelings_seen'] = true;
        // Enough filtered play since the last decay for the legacy debounce (raw play gamets)
        $dyn['_accumulated_play_gamets'] = 2 * RelationshipDynamics::GAMETS_RESENTMENT_COOLDOWN;
        pg_query_params($this->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics}', $2::jsonb) WHERE id = $1",
            [$this->npcId, json_encode($dyn)]);
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [
            RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true, 'eval_producer' => ['enabled' => false]])),
        ]);
        RelationshipDynamics::clearConfigCache();
        $this->event('inputtext', 'Kaida: I am sorry, Muiri. (Talking to Muiri)', self::T0);
        $this->npcSays(self::NPC, 'Thank you for saying that.', self::PLAYER, self::T0);

        $this->postrequest(['inputtext', '1727000400', (string) self::T0, 'Kaida: I am sorry, Muiri.']);

        $this->assertSame([], $this->jobs(), 'eval off: nothing queued');
        $after = $this->reldyn()['dynamics'];
        $this->assertGreaterThan(0.0, (float) $after['passion'], 'the local classifier scored the exchange');
        $this->assertLessThan(35.0, (float) $after['dimensions']['resentment']['x'],
            'MDD 15.5 natural decay on a positive interaction still happens without the eval');
    }
}
