<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 * Every failure is recorded.
 */
final class RelDynAttractionPgDb
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
 * Rulings 2026-09-24 §9, end to end: "Aela would have an affinity for a strong warrior type or
 * a formidable druid, but a bard or a scholar she could tolerate but probably wouldn't feel
 * passion towards."
 *
 * Real PostgreSQL, real hooks (prerequest -> context -> postrequest per turn), CHIM 3.4.1
 * core-shaped rows only: Aela's core_npc_master row (class, factions, skills; no RelDyn state),
 * the player's core_player 'skills' / 'stats' rows as the plugin writes them, the NPC's mood in
 * moods_issued (a flirty answer is words of affirmation, the legacy passion path), eval contract
 * items through the real inbox (queuePendingEval -> postrequest applyEvalInbox). No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAttractionPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const MINUTE = RelationshipDynamics::GAMETS_PER_DAY / 1440;
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynAttractionPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY;
    private array $contexts = [];

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
        $this->schema = 'reldyn_attr' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // lib/core/database_schema/core_npc_master.sql / core_npc_master_history.sql columns.
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
        // data/database_default.sql eventlog / responselog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_close($admin);

        $this->db = new RelDynAttractionPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdattrpg');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attraction_pg_test.log');

        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();

        // Aela: the Companions' huntress, as the plugin registers her (no RelDyn state).
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $factions = [];
        foreach (['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'] as $i => $f) {
            $factions[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $f];
        }
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb)',
            [self::AELA, '1A696', 'female', 'NordRace', 'FemaleEvenToned', 'Roleplay as Aela the Huntress', '',
             json_encode(['skills' => array_merge($all, ['archery' => '72', 'sneak' => '56', 'lightarmor' => '52', 'onehanded' => '45'])]),
             json_encode(['class' => ['name' => 'Hunter', 'formid' => '0x0001317f'], 'factions' => $factions,
                 'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'platonic']]])]);
    }

    protected function tearDown(): void
    {
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

    /** The player's core_player rows as the plugin writes them (skill levels 0..100, stats). */
    private function playerBuild(array $skills, int $level): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 15);
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2), ($3, $4)',
            ['skills', json_encode(array_merge($all, $skills)), 'stats', json_encode(['level' => $level, 'health' => 300])]);
    }

    /** One player line to Aela through the real hooks; she answers in $mood. Returns the context. */
    private function turn(string $line, string $mood = 'flirty'): string
    {
        $this->gamets += 5 * self::MINUTE;
        $this->realTs += 60;
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)',
            [self::AELA, $mood, $this->realTs]);
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . self::AELA . '|' . self::PLAYER . '|';
        $run = function (string $hook) use ($request, $party): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = self::AELA;
            $GLOBALS['RELDYN_NPC_NAME'] = self::AELA;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
        };
        $run('prerequest.php');
        $this->clearReldynGlobals();
        $run('context.php');
        $context = implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []));
        $this->clearReldynGlobals();
        $run('postrequest.php');
        $this->clearReldynGlobals();
        $this->contexts[] = $context;
        return $context;
    }

    private function dynamics(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function coreAff(): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        return intval(json_decode($r['extended_data'], true)['relationships']['Player']['aff']
            ?? json_decode($r['extended_data'], true)['relationships'][self::PLAYER]['aff'] ?? 0);
    }

    /** A shared-contract eval item, queued the way the eval worker queues it. */
    private function queueEval(array $signals, float $significance, bool $positive): void
    {
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::AELA, [
            'v' => 1, 'npc' => self::AELA, 'npc_id' => 1, 'gamets' => (int) $this->gamets + random_int(1, 999), 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => ['quality_time'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => $positive,
            'summary' => 'eval ' . bin2hex(random_bytes(3)),
        ]));
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    // ------------------------------------------------------------------ the scenarios

    public function testAWarriorStirsAelaAndEarnsHerCrushThroughDefiningMoments(): void
    {
        // A warrior: one-handed / two-handed / heavy armor, level 40
        $this->playerBuild(['onehanded' => 90, 'twohanded' => 85, 'block' => 70, 'heavyarmor' => 80], 40);

        $this->turn('That was a fine kill. You have a good eye.');
        $d = $this->dynamics();
        $this->assertTrue($d['_attraction']['passes'], json_encode($d['_attraction']));
        $this->assertSame('warrior', $d['_attraction']['valued'], 'Aela sees the way the player fights');
        $this->assertContains('crush', $d['_attraction']['blocked_types'], 'the ceiling lifted; not yet earned');
        $p0 = RelationshipDynamics::getPassion($d);

        for ($i = 0; $i < 5; $i++) {
            $this->turn('Hunt with me again tomorrow.');
        }
        $d = $this->dynamics();
        $this->assertGreaterThan($p0, RelationshipDynamics::getPassion($d), 'legacy passion path: a flirty answer stirs passion');
        $this->assertStringContainsString('drawn to the player', end($this->contexts));
        $this->assertDoesNotMatchRegularExpression('/<attraction_context>[^<]*\d/', end($this->contexts), 'feelings, not numbers');

        // The eval scores defining moments (significance 0.8, positive): the lifted ceiling is reached
        $need = $d['_attraction_state']['pending']['need'];
        for ($i = 0; $i < $need; $i++) {
            $this->queueEval(['passion' => 6, 'affinity' => 3], 0.8, true);
        }
        $this->turn('I would follow you into any hunt.');
        $d = $this->dynamics();
        $this->assertNotContains('crush', $d['_attraction']['blocked_types'], json_encode($d['_attraction_state']));
        $this->assertTrue(RelationshipDynamics::attractionAllowsType(self::AELA, $d, 'crush'));
        $this->assertNoDbFailures();
    }

    /** The bard scenario: 13 flirty turns, then six passionate defining moments from the eval. */
    private function bardEvening(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->turn('Another verse, then.');
        }
        for ($i = 0; $i < 6; $i++) {
            $this->queueEval(['passion' => 10, 'affinity' => 4], 0.8, true);
        }
        $this->turn('You liked that one, admit it.');
    }

    /** Aela forgets this player: her RelDyn state and core relationship back to the start. */
    private function resetAela(): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET plugin_extended_data = '{}'::jsonb,
            extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb) WHERE npc_name = $1",
            [self::AELA, json_encode([self::PLAYER => ['aff' => 10, 'type' => 'platonic']])]);
        $this->contexts = [];
    }

    private function setMatrix(bool $on): void
    {
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true, 'attraction_matrix_enabled' => $on]))]);
        RelationshipDynamics::clearConfigCache();
    }

    public function testABardIsToleratedButStirsNoPassionWhileAffinityStillGrows(): void
    {
        // A bard: speech / illusion, level 40, no fighting skill
        $this->playerBuild(['speech' => 95, 'illusion' => 75], 40);

        // Control: the Matrix off, the same evening
        $this->setMatrix(false);
        $aff0 = $this->coreAff();
        $this->bardEvening();
        $ungated = RelationshipDynamics::getPassion($this->dynamics());
        $this->assertFalse($this->dynamics()['_attraction']['enabled']);
        $this->assertStringNotContainsString('<attraction_context>', end($this->contexts));

        // Aela as she is
        $this->resetAela();
        $this->setMatrix(true);
        $this->bardEvening();
        $d = $this->dynamics();
        $this->assertFalse($d['_attraction']['passes'], json_encode($d['_attraction']));
        $this->assertSame(20.0, floatval($d['_attraction']['passion_cap']));
        $gated = RelationshipDynamics::getPassion($d);
        $this->assertGreaterThan(3.0 * $gated, $ungated, "the same evening, ungated {$ungated} vs gated {$gated}: no passion for a bard");
        $this->assertSame(['crush', 'romantic'], $d['_attraction']['blocked_types']);
        $this->assertGreaterThan($aff0, $this->coreAff(), 'affinity can still grow');
        $this->assertStringContainsString('<attraction_context>', end($this->contexts));
        $this->assertStringNotContainsString('drawn to the player', end($this->contexts));

        // Passion she had before (the Matrix was off, or an old save) drops to the cap on the next
        // request, and no eval can push it past (MDD 6.2 hard cap)
        $raw = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        $ped = json_decode($raw['plugin_extended_data'], true);
        // written raw, as an older save holds it (setPassion itself already respects the cap)
        $ped['reldyn']['dynamics']['dimensions']['passion']['x'] = 60.0;
        $ped['reldyn']['dynamics']['passion'] = 60.0;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1',
            [self::AELA, json_encode($ped)]);
        for ($i = 0; $i < 4; $i++) {
            $this->queueEval(['passion' => 10], 0.8, true);
        }
        $this->turn('Just one more song.');
        $this->assertLessThanOrEqual(20.0, RelationshipDynamics::getPassion($this->dynamics()));
        $this->assertNoDbFailures();
    }
}
