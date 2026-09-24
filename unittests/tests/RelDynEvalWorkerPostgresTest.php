<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection, with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement (and core logs it),
 * fetchAll throws. Failed statements are recorded so tests can show none happened.
 */
final class RelDynEvalPgDb
{
    public $link;
    public array $failures = [];
    /** Test seam: callable(string $sql): ?array. A non-null return replaces running the statement. */
    public $beforeQuery = null;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        if ($this->beforeQuery !== null) {
            $replaced = ($this->beforeQuery)((string) $q);
            if ($replaced !== null) return $replaced;
        }
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
    public function execQuery($q) { return @pg_query($this->link, $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * eval-producer-worker / eval-input-quality against a real PostgreSQL:
 *   - a real postrequest.php run queues one job in RelDyn's own table (not for the Narrator,
 *     not for NPC-to-NPC radiant chatter);
 *   - the worker turns it into a contract item in the eval inbox from a realistic eventlog
 *     (prechat duplicates excluded, speakers attributed, other conversations left out);
 *   - malformed LLM output is logged and dropped; an LLM failure keeps the job.
 * Only the LLM call is stubbed (canned JSON at the connector boundary).
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynEvalWorkerPostgresTest extends TestCase
{
    private const T0 = 5000000;        // raw gamets of the exchange under test
    private const NPC = 'Aela the Huntress';
    private const PLAYER = 'Kaida';

    private string $dsn;
    private string $schema;
    private RelDynEvalPgDb $db;
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
        $this->schema = 'reldyn_eval' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql (the profile auto-gen reads them).
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY, npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text, voiceid text,
            metadata jsonb, gender text, race text, refid character varying(16), profile_id integer, dynamic_profile integer,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text)");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        // Same columns as data/database_default.sql eventlog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_close($admin);

        $this->db = new RelDynEvalPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE',
                     'SCRIPTLINE_LISTENER_ATOMIC', 'SCRIPTLINE_LISTENER'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        unset($GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'], $GLOBALS['SCRIPTLINE_LISTENER']);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured (the call itself is stubbed)
        RelationshipDynamics::clearConfigCache();
        RelDynEval::$launcher = function (): void { $this->launches++; };
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdevalpg');
        $this->prevLog = ini_set('error_log', $this->logFile);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_eval_worker_test.log');

        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data) VALUES ($1, $2, $3::jsonb) RETURNING id',
            [self::NPC, '1A3B7', json_encode(['relationships' => ['Player' => ['aff' => 30, 'type' => 'platonic']]])]));
        $this->npcId = (int) $row['id'];
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, refid) VALUES ($1, $2)', ['Farkas', '1A3B8']);
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

    // ------------------------------------------------------------------ fixture helpers

    private function event(string $type, string $data, int $gamets, string $people = '|Aela the Huntress|Kaida|', ?string $state = null): int
    {
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING rowid',
            [$type, $data, 'pending', $gamets, 1727000000 + $gamets, $gamets, $people, 'Jorrvaskr', $state]));
        return (int) $row['rowid'];
    }

    /** An NPC reply the way returnLines() logs it: a prechat copy, then the chat row. */
    private function npcSays(string $speaker, string $text, string $listener, int $gamets, string $people = '|Aela the Huntress|Kaida|'): void
    {
        $line = "{$speaker}: {$text} (talking to {$listener})";
        $this->event('prechat', $line, $gamets + 1, $people);
        $this->event('chat', $line, $gamets + 2, $people, 'emitted');
    }

    /** Earlier conversation, an unrelated one nearby, and the exchange under test at T0. */
    private function seedConversation(): void
    {
        $t = self::T0 - 9000;
        $this->event('inputtext', 'Kaida: Good hunt today? (Talking to Aela the Huntress)', $t);
        $this->npcSays(self::NPC, 'Two deer and a sabre cat. Not bad.', self::PLAYER, $t);
        $this->event('infoloc', '(Context location: Jorrvaskr, Whiterun)', $t + 100);
        // Somebody else's conversation in the same hall: never part of Aela's window.
        $this->event('inputtext', 'Kaida: Farkas, where is Vilkas? (Talking to Farkas)', $t + 200, '|Farkas|Kaida|');
        $this->npcSays('Farkas', 'Training in the yard, as always.', self::PLAYER, $t + 200, '|Farkas|Kaida|');
        // Farkas addresses Aela: other speaker, but it is said to her.
        $this->npcSays('Farkas', 'Aela, Kodlak wants a word later.', self::NPC, $t + 400);

        // The exchange: the player line is also logged a second time with a context prefix.
        $this->event('inputtext', 'Kaida: I kept the best pelt for you. (Talking to Aela the Huntress)', self::T0);
        $this->event('inputtext', '(Context location: Jorrvaskr, Whiterun)Kaida: I kept the best pelt for you. (Talking to Aela the Huntress)', self::T0);
        $this->npcSays(self::NPC, "A fine pelt. You have a hunter's eye, Shield-Sibling.", self::PLAYER, self::T0);
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)', [self::NPC, 'pleased', 1]);
    }

    private function postrequest(string $npcName, array $gameRequest, ?string $listener): void
    {
        $GLOBALS['gameRequest'] = $gameRequest;
        $GLOBALS['RELDYN_NPC_NAME'] = $npcName;
        $GLOBALS['HERIKA_NAME'] = $npcName;
        $GLOBALS['CACHE_PEOPLE'] = '|Aela the Huntress|Farkas|Kaida|';
        if ($listener !== null) {
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = $listener;
        } else {
            unset($GLOBALS['SCRIPTLINE_LISTENER_ATOMIC']);
        }
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    private function jobs(): array
    {
        $res = @pg_query($this->db->link, 'SELECT id, npc_id, npc_name, job::text AS job, status, attempts, last_error FROM reldyn_eval_queue ORDER BY id');
        if (!$res) return [];
        $rows = [];
        while ($r = pg_fetch_assoc($res)) {
            $r['job'] = json_decode($r['job'], true);
            $rows[] = $r;
        }
        return $rows;
    }

    private function inbox(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            "SELECT plugin_extended_data -> 'reldyn' -> 'eval_inbox' AS inbox FROM core_npc_master WHERE id = $1", [$this->npcId]));
        return $r['inbox'] === null ? [] : json_decode($r['inbox'], true);
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    /** Key order does not survive jsonb: sort object keys, keep list order and types. */
    private static function canonical(array $a): array
    {
        if (!array_is_list($a)) ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) $a[$k] = self::canonical($v);
        }
        return $a;
    }

    /** Stub for the eval LLM call: records the messages, returns canned text. */
    private function llm(?string $reply, array &$calls): callable
    {
        return function (array $messages, array $params) use ($reply, &$calls) {
            $calls[] = ['messages' => $messages, 'params' => $params];
            if ($reply === null) throw new RuntimeException('connection refused');
            return $reply;
        };
    }

    private const GOOD_REPLY = '{"signals": {"affinity": 4, "trust": 2, "comfort": 1, "respect": 3, "passion": 1, "maturity": 0},
        "tags": ["gift", "praise", "flattery"], "grievance": {"flag": false, "kind": null, "severity": 0},
        "jealousy": {"flag": false, "rival": null, "intensity": 0}, "significance": 0.4,
        "summary": "Pleased the player thought of her."}';

    // ------------------------------------------------------------------ producer

    public function testARealPostrequestQueuesOneJobForTheExchange(): void
    {
        $this->seedConversation();
        $anchor = (int) pg_fetch_result(pg_query($this->db->link, 'SELECT max(rowid) FROM eventlog'), 0, 0);

        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: I kept the best pelt for you. (Talking to Aela the Huntress)'], self::PLAYER);

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs, 'one job queued by the hook');
        $this->assertSame('pending', $jobs[0]['status']);
        $this->assertSame($this->npcId, (int) $jobs[0]['npc_id']);
        $job = $jobs[0]['job'];
        $this->assertSame(self::NPC, $job['npc']);
        $this->assertSame(self::PLAYER, $job['player_name']);
        $this->assertSame('inputtext', $job['request_type']);
        $this->assertEquals(self::T0, $job['gamets']);
        $this->assertSame($anchor, $job['anchor_rowid'], 'the exchange is everything logged up to the hook');
        $this->assertSame(1, $this->launches, 'the worker was started');
        $this->assertSame([], $this->db->failures, 'no failed statements');

        // Every eligible exchange by default: the next one queues again.
        $this->postrequest(self::NPC, ['inputtext', '1727000200', (string) (self::T0 + 50), 'Kaida: Hunt with me tomorrow?'], self::PLAYER);
        $this->assertCount(2, $this->jobs());
    }

    public function testTheNarratorAndNpcChatterQueueNothing(): void
    {
        $this->seedConversation();
        $this->postrequest('The Narrator', ['inputtext', '1727000123', (string) self::T0, 'Kaida: what now?'], self::PLAYER);
        $this->postrequest(self::NPC, ['radiant', '1727000124', (string) self::T0, ''], 'Farkas');
        $this->postrequest(self::NPC, ['rechat', '1727000125', (string) self::T0, ''], 'Farkas');
        $this->assertSame([], $this->jobs(), 'no job for the Narrator or for NPC-to-NPC talk');
        $this->assertSame(0, $this->launches);

        $this->postrequest(self::NPC, ['rechat', '1727000126', (string) self::T0, ''], self::PLAYER);
        $this->assertCount(1, $this->jobs(), 'an NPC-initiated line addressed to the player is evaluated');
    }

    public function testCooldownAndChanceComeFromConfig(): void
    {
        $this->seedConversation();
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)', [
            RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'eval_producer' => ['cooldown_gamets' => 1000]]),
        ]);
        $req = fn(int $g) => ['inputtext', '1727000123', (string) $g, 'Kaida: hi (Talking to Aela the Huntress)'];
        $this->postrequest(self::NPC, $req(self::T0), self::PLAYER);
        $this->postrequest(self::NPC, $req(self::T0 + 999), self::PLAYER);   // inside 1000 raw gamets
        $this->assertCount(1, $this->jobs(), 'second exchange inside the cooldown');
        $this->postrequest(self::NPC, $req(self::T0 + 1000), self::PLAYER);
        $this->assertCount(2, $this->jobs());
        $this->postrequest(self::NPC, $req(self::T0 - 5000), self::PLAYER);  // clock went back (save load)
        $this->assertCount(3, $this->jobs(), 'a clock that went back never blocks');

        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [
            RelationshipDynamics::CONFIG_ROW_ID,
            json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'eval_producer' => ['chance' => 0]]),
        ]);
        $this->postrequest(self::NPC, $req(self::T0 + 90000), self::PLAYER);
        $this->assertCount(3, $this->jobs(), 'chance 0 queues nothing');
    }

    // ------------------------------------------------------------------ worker

    public function testTheWorkerTurnsAJobIntoAContractItemFromTheEventlogWindow(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: I kept the best pelt for you. (Talking to Aela the Huntress)'], self::PLAYER);
        $this->assertCount(1, $this->jobs());

        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));

        $this->assertSame(1, $stats['queued'], json_encode($stats) . ' ' . $this->log());
        $this->assertSame([], $this->jobs(), 'done jobs leave the queue');
        $this->assertCount(1, $calls, 'one LLM call');
        $this->assertSame(768, $calls[0]['params']['MAX_TOKENS']);

        $inbox = $this->inbox();
        $this->assertCount(1, $inbox);
        $item = self::canonical($inbox[0]['eval']);
        $this->assertSame(self::canonical([
            'v' => 1, 'npc' => self::NPC, 'npc_id' => $this->npcId, 'gamets' => self::T0, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 4, 'trust' => 2, 'comfort' => 1, 'respect' => 3, 'passion' => 1, 'maturity' => 0],
            'tags' => ['gift', 'praise'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.4,
            'positive_interaction' => true,
            'summary' => 'Pleased the player thought of her.',
            'witnesses' => [],   // nobody else was there (eventlog people |Aela the Huntress|Kaida|)
        ]), $item, 'contract item; unknown tag "flattery" dropped');

        // What the eval saw: the real conversation, attributed, once.
        $user = $calls[0]['messages'][1]['content'];
        [$earlier, $current] = explode('THIS EXCHANGE (score only this):', $user, 2);
        $this->assertSame(1, substr_count($user, "A fine pelt. You have a hunter's eye"), 'prechat duplicate excluded');
        $this->assertSame(1, substr_count($user, 'I kept the best pelt for you.'), 'the doubled player row counted once');
        $this->assertStringContainsString("[Kaida] (to Aela the Huntress): I kept the best pelt for you.\n[Aela the Huntress] (to Kaida): A fine pelt. You have a hunter's eye, Shield-Sibling.", $current);
        $this->assertStringContainsString('[Aela the Huntress] (to Kaida): Two deer and a sabre cat. Not bad.', $earlier);
        $this->assertStringContainsString('[Farkas] (to Aela the Huntress): Aela, Kodlak wants a word later.', $earlier);
        $this->assertStringNotContainsString('Vilkas', $user, 'another conversation is not in her window');
        $this->assertStringNotContainsString('Training in the yard', $user);
        $this->assertStringNotContainsString('Context location', $user);
        $this->assertStringContainsString('Current mood: pleased', $user);
        $this->assertStringContainsString('Bond with the player:', $user);
        $this->assertStringContainsString('- jealousy_trigger:', $user, 'tags are defined for the model');
        $this->assertDoesNotMatchRegularExpression('/\[PLAYER\]|\[NPC\]/', $user, 'no placeholder tags that never appear');
        $this->assertSame([], $this->db->failures, 'no failed statements');
    }

    public function testTheWindowKeepsGroupLinesOnlyWhenTheNpcWasPresent(): void
    {
        $t = self::T0;
        $this->event('inputtext', 'Kaida: Drinks are on me! (Talking to everyone)', $t - 300, '|Aela the Huntress|Farkas|Kaida|');
        $this->event('inputtext', 'Kaida: Anyone seen my horse? (Talking to everyone)', $t - 200, '|Lydia|Kaida|');
        $this->event('inputtext', 'Kaida: Hunt with me? (Talking to Aela the Huntress)', $t);
        $this->npcSays(self::NPC, 'Always.', self::PLAYER, $t);
        $anchor = (int) pg_fetch_result(pg_query($this->db->link, 'SELECT max(rowid) FROM eventlog'), 0, 0);

        $w = RelDynEval::conversationWindow(self::NPC, self::PLAYER, $anchor, 12, 200);
        $this->assertSame(['Drinks are on me!'], array_column($w['earlier'], 'text'), 'she was there for the round, not for the horse');
        $this->assertSame(['Hunt with me?', 'Always.'], array_column($w['current'], 'text'));
        $this->assertSame(['player', 'npc'], array_column($w['current'], 'role'));

        // Anchored: a later line is not part of this exchange.
        $this->event('inputtext', 'Kaida: Actually, never mind. (Talking to Aela the Huntress)', $t + 50);
        $this->assertSame($w, RelDynEval::conversationWindow(self::NPC, self::PLAYER, $anchor, 12, 200));
    }

    /** THIS EXCHANGE block of an eval prompt, one line per entry. */
    private static function scoredLines(array $call): array
    {
        $user = $call['messages'][1]['content'];
        $block = explode('THIS EXCHANGE (score only this):', $user, 2)[1];
        $block = preg_split('/\R(?:Observed events|TASK:)/', $block, 2)[0];
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $block))));
    }

    public function testAnNpcFollowUpIsScoredAloneNotWithTheExchangeBeforeIt(): void
    {
        $t = self::T0;
        $this->event('inputtext', 'Kaida: You are a coward, Aela. (Talking to Aela the Huntress)', $t);
        $this->npcSays(self::NPC, 'How dare you say that to me.', self::PLAYER, $t);
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) $t, 'Kaida: You are a coward, Aela.'], self::PLAYER);

        // She speaks again to the player without a new player line (rechat addressed to Kaida).
        $t2 = $t + 5000;
        $this->npcSays(self::NPC, 'And another thing: I am still waiting for an apology.', self::PLAYER, $t2);
        $this->postrequest(self::NPC, ['rechat', '1727000180', (string) $t2, ''], self::PLAYER);
        $this->assertCount(2, $this->jobs());

        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->assertSame(2, $stats['queued'], json_encode($stats));
        $this->assertSame([
            '[Kaida] (to Aela the Huntress): You are a coward, Aela.',
            '[Aela the Huntress] (to Kaida): How dare you say that to me.',
        ], self::scoredLines($calls[0]));
        $this->assertSame(['[Aela the Huntress] (to Kaida): And another thing: I am still waiting for an apology.'],
            self::scoredLines($calls[1]), 'the insult was scored by the first job, never again');
        $this->assertStringContainsString('You are a coward', explode('THIS EXCHANGE', $calls[1]['messages'][1]['content'])[0],
            'it stays visible as earlier context');
    }

    public function testTwoExchangesLoggedBeforeEitherHookRunsAreEachScoredOnce(): void
    {
        // A and B are both in eventlog before postrequest A runs (overlapping requests).
        $tA = self::T0;
        $tB = self::T0 + 3000;
        $this->event('inputtext', 'Kaida: A: I brought you flowers. (Talking to Aela the Huntress)', $tA);
        $this->npcSays(self::NPC, 'RA: How thoughtful.', self::PLAYER, $tA);
        $this->event('inputtext', 'Kaida: B: You smell like a goat. (Talking to Aela the Huntress)', $tB);
        $this->npcSays(self::NPC, 'RB: Excuse me?!', self::PLAYER, $tB);

        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) $tA, 'Kaida: A: I brought you flowers.'], self::PLAYER);
        $this->postrequest(self::NPC, ['inputtext', '1727000124', (string) $tB, 'Kaida: B: You smell like a goat.'], self::PLAYER);

        $calls = [];
        RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->assertCount(2, $calls);
        $this->assertSame(['[Kaida] (to Aela the Huntress): A: I brought you flowers.', '[Aela the Huntress] (to Kaida): RA: How thoughtful.'],
            self::scoredLines($calls[0]), 'job A scores exchange A');
        $this->assertSame(['[Kaida] (to Aela the Huntress): B: You smell like a goat.', '[Aela the Huntress] (to Kaida): RB: Excuse me?!'],
            self::scoredLines($calls[1]), 'job B scores exchange B');
    }

    public function testTheItemNamesTheWitnessesTheExchangeRowsRecorded(): void
    {
        $this->seedConversation();   // the exchange rows record |Aela the Huntress|Kaida|
        $this->event('inputtext', 'Kaida: Come here, you. (Talking to Aela the Huntress)', self::T0 + 900, '|Aela the Huntress|Farkas|Kaida|');
        $this->npcSays(self::NPC, 'Mmh. Not in front of Farkas.', self::PLAYER, self::T0 + 900, '|Aela the Huntress|Farkas (standing)|Njada|Kaida|');
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) (self::T0 + 900), 'Kaida: Come here, you.'], self::PLAYER);

        $calls = [];
        RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $item = $this->inbox()[0]['eval'];
        $this->assertSame(['Farkas', 'Njada'], $item['witnesses'], 'present at the exchange, minus her and the player');
        $this->assertNotNull(RelationshipDynamics::normalizeEvalContractItem($item));
    }

    public function testMalformedOutputIsLoggedAndDroppedNotApplied(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: I kept the best pelt for you.'], self::PLAYER);

        $calls = [];
        $stats = RelDynEval::runWorker($this->llm('Aela seems happy! {"affinity_delta": 5', $calls));

        $this->assertSame(1, $stats['dropped']);
        $this->assertSame([], $this->inbox(), 'nothing reaches the inbox');
        $this->assertSame([], $this->jobs(), 'the job is dropped');
        $this->assertStringContainsString('[RelDyn-EVAL] ERROR malformed eval output for Aela the Huntress dropped (not a JSON object)', $this->log());
    }

    public function testAnLlmFailureKeepsTheJobUntilMaxAttemptsThenDeadLetters(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: pelt'], self::PLAYER);
        $this->postrequest(self::NPC, ['inputtext', '1727000124', (string) (self::T0 + 10), 'Kaida: again'], self::PLAYER);

        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(null, $calls));
        $this->assertSame(1, $stats['failed']);
        $this->assertCount(1, $calls, 'her later job waits behind the failed one (inbox order)');
        $jobs = $this->jobs();
        $this->assertCount(2, $jobs, 'no job lost');
        $this->assertSame(['pending', 'pending'], array_column($jobs, 'status'));
        $this->assertSame('1', $jobs[0]['attempts']);
        $this->assertStringContainsString('connection refused', $jobs[0]['last_error']);

        RelDynEval::runWorker($this->llm(null, $calls));
        RelDynEval::runWorker($this->llm(null, $calls));
        $jobs = $this->jobs();
        $this->assertSame('dead', $jobs[0]['status'], 'dead-lettered after max_attempts (3), still stored');
        $this->assertSame('3', $jobs[0]['attempts']);

        // The next run moves on to her later job.
        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->assertSame(1, $stats['queued']);
        $this->assertCount(1, $this->inbox());
        $this->assertSame(['dead'], array_column($this->jobs(), 'status'));
    }

    public function testAWorkerDyingBetweenInboxWriteAndJobDeleteNeverAppliesTheExchangeTwice(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: I kept the best pelt for you. (Talking to Aela the Huntress)'], self::PLAYER);

        // The worker process dies at the job delete (after the LLM answered).
        $this->db->beforeQuery = function (string $q): ?array {
            if (stripos($q, 'DELETE FROM reldyn_eval_queue') !== false) {
                throw new Error('worker killed');
            }
            return null;
        };
        $calls = [];
        try {
            RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        } catch (Error $e) {
            $this->assertSame('worker killed', $e->getMessage());
        }
        $this->db->beforeQuery = null;
        $this->assertSame([], $this->inbox(), 'no inbox item without the job delete: one statement');
        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('pending', $jobs[0]['status'], 'the job is still there for the next worker');

        // The next worker scores it again (the model words it differently): one item in total.
        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(str_replace('Pleased the player thought of her.', 'She liked the pelt.', self::GOOD_REPLY), $calls));
        $this->assertSame(1, $stats['queued']);
        $this->assertCount(1, $this->inbox(), 'the exchange is in the inbox exactly once');
        $this->assertSame([], $this->jobs());
    }

    public function testAJobDeleteThatDoesNothingIsNotCountedAsQueued(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: pelt'], self::PLAYER);

        // The combined write reports no row (e.g. the statement failed without throwing).
        $this->db->beforeQuery = fn(string $q): ?array => stripos($q, 'DELETE FROM reldyn_eval_queue') !== false ? [] : null;
        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->db->beforeQuery = null;

        $this->assertSame(0, $stats['queued'], json_encode($stats));
        $this->assertSame(1, $stats['failed']);
        $this->assertSame([], $this->inbox());
        $jobs = $this->jobs();
        $this->assertSame('pending', $jobs[0]['status']);
        $this->assertSame('1', $jobs[0]['attempts'], 'retried like any failed inbox write');
    }

    public function testAJobWhoseExchangeASaveLoadRolledBackIsDropped(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: pelt'], self::PLAYER);
        // processor/comm.php on load: DELETE FROM eventlog WHERE gamets >= <loaded gamets>
        pg_query_params($this->db->link, 'DELETE FROM eventlog WHERE gamets >= $1', [self::T0]);

        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->assertSame(1, $stats['dropped']);
        $this->assertSame([], $calls, 'no LLM call for an exchange that no longer happened');
        $this->assertSame([], $this->inbox());
    }

    public function testOnlyOneWorkerDrainsAtATime(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: pelt'], self::PLAYER);

        $other = new RelDynEvalPgDb($this->dsn, $this->schema);
        pg_query($other->link, 'SELECT pg_advisory_lock(' . RelDynEval::LOCK_CLASS . ', ' . RelDynEval::LOCK_DRAINER . ')');
        $calls = [];
        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->assertTrue($stats['locked']);
        $this->assertSame([], $calls);
        $this->assertCount(1, $this->jobs(), 'left for the drainer that holds the lock');
        pg_close($other->link);   // that drainer ends; its lock goes with its session

        $stats = RelDynEval::runWorker($this->llm(self::GOOD_REPLY, $calls));
        $this->assertSame(1, $stats['queued']);
    }

    public function testTheWorkerReadsFreshStateForEveryJob(): void
    {
        $this->seedConversation();
        $this->postrequest(self::NPC, ['inputtext', '1727000123', (string) self::T0, 'Kaida: pelt'], self::PLAYER);
        $this->postrequest(self::NPC, ['inputtext', '1727000124', (string) (self::T0 + 10), 'Kaida: again'], self::PLAYER);

        $calls = [];
        $first = true;
        $llm = function (array $messages, array $params) use (&$calls, &$first) {
            $calls[] = $messages[1]['content'];
            if ($first) {
                $first = false;
                // Between two jobs another process makes her jealous.
                $dyn = RelationshipDynamics::getDynamics(self::NPC);
                RelationshipDynamics::setJealousy($dyn, 70);
                $dyn['jealousy_trigger_npc'] = 'Farkas';
                RelationshipDynamics::saveDynamics(self::NPC, $dyn);
            }
            return self::GOOD_REPLY;
        };
        $stats = RelDynEval::runWorker($llm);
        $this->assertSame(2, $stats['queued']);
        $this->assertStringContainsString('Jealousy: none', $calls[0]);
        $this->assertStringContainsString('Jealousy: hurt (about Farkas)', $calls[1], 'the second job saw the new state');
    }
}
