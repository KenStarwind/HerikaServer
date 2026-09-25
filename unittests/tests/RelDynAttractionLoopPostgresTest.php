<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 * Every failure is recorded.
 */
final class RelDynAttractionLoopPgDb
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
 * attraction-modifier-and-gate end to end (review findings): the attraction must hold across
 * the whole passion loop, not only in RelDynAttraction::evaluate.
 *   - A warrior Aela is drawn to gains passion from eval-scored exchanges while she flirts back:
 *     her own flirty answer is reciprocity, never the Ick's "unreciprocated pressure" (MDD 6.3),
 *     with the eval owning the exchange (RELLLM_CONNECTOR set, as live).
 *   - The relationship stage's passion floor goes through the gate: a bard at a deep stage with
 *     a closed gate keeps passion 0 and gets no passion line.
 *
 * Real PostgreSQL, CHIM 3.4.1 core-shaped rows (Aela's core_npc_master row, core_player as the
 * plugin writes it), the real hooks (prerequest -> context_pre -> context -> postrequest), the
 * real eval producer (its worker launch stubbed) and the real eval inbox; no LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynAttractionLoopPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const MINUTE = RelationshipDynamics::GAMETS_PER_DAY / 1440;
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynAttractionLoopPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY;

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
        $this->schema = 'reldyn_aloop' . getmypid() . '_' . bin2hex(random_bytes(3));

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

        $this->db = new RelDynAttractionLoopPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdalooppg');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attraction_loop_pg_test.log');

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
        RelDynEval::$launcher = null;
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

    // ------------------------------------------------------------------ fixture

    /** A core_player row as core writes it (gamedata.php: one row per id, value text). */
    private function corePlayer(string $id, $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /**
     * The player's build as the plugin reports it: skills (raw 0..100), stats (level) and the
     * tracked Skyrim stats (gamedata skyrim_stats -> core_player.<stat name>).
     */
    private function playerBuild(array $skills, int $level, array $trackedStats = []): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 15);
        $this->corePlayer('skills', array_merge($all, $skills));
        $this->corePlayer('stats', ['level' => $level, 'health' => 100 + 10 * $level]);
        foreach ($trackedStats as $name => $n) {
            $this->corePlayer($name, (string) $n);
        }
    }

    /**
     * Step $k of a warrior's career: a level-10 sellsword at k = 0, then more levels, sharper
     * blades and more deeds (kills, quests, dungeons, the Companions' questline) each step.
     */
    private function warriorAtStep(int $k, int $extraCreatures = 0): void
    {
        $this->playerBuild(
            ['onehanded' => 30 + 12 * $k, 'twohanded' => 25 + 10 * $k, 'block' => 25 + 8 * $k, 'heavyarmor' => 25 + 10 * $k],
            10 + 6 * $k,
            [
                'People Killed' => 10 + 25 * $k, 'Creatures Killed' => 15 + 30 * $k + $extraCreatures,
                'Quests Completed' => 3 + 6 * $k, 'Dungeons Cleared' => 1 + 4 * $k, 'Locations Discovered' => 10 + 15 * $k,
                'The Companions Quests Completed' => $k,
            ]);
    }

    /**
     * A bard: speech / illusion, level 40, no fighting skill, a well-travelled, accomplished
     * town figure (quests, dungeons, houses, gold found). $companions: Companions quests reported (Aela's own status marker, MDD 2.3);
     * null = the game never reported the stat (unknown: her status reads the generic standing).
     */
    private function bard(?int $companions = null): void
    {
        $stats = ['Persuasions' => 30, 'Quests Completed' => 200, 'Locations Discovered' => 250, 'Dungeons Cleared' => 90,
            'Houses Owned' => 2, 'Gold Found' => 30000, 'Questlines Completed' => 6];
        if ($companions !== null) $stats['The Companions Quests Completed'] = $companions;
        $this->playerBuild(['speech' => 95, 'illusion' => 75], 40, $stats);
    }

    /** One player line to Aela through the real hooks; she answers in $mood. */
    private function turn(string $line, string $mood = 'flirty', string $type = 'inputtext'): void
    {
        $this->gamets += 5 * self::MINUTE;
        $this->realTs += 60;
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)',
            [self::AELA, $mood, $this->realTs]);
        $request = [$type, (string) $this->realTs, (string) (int) $this->gamets, $type === 'inputtext' ? self::PLAYER . ": {$line}" : $line];
        $party = '|' . self::AELA . '|' . self::PLAYER . '|';
        $hooks = $type === 'inputtext' ? ['prerequest.php', 'context.php', 'postrequest.php'] : ['postrequest.php'];
        foreach ($hooks as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $type === 'inputtext' ? self::AELA : 'The Narrator';
            if ($type === 'inputtext') $GLOBALS['RELDYN_NPC_NAME'] = self::AELA;
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

    private function dynamics(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Edit Aela's stored RelDyn state (as an older save or the editor would hold it). */
    private function editDynamics(callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [self::AELA]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $d = $ped['reldyn']['dynamics'];
        $edit($d);
        $ped['reldyn']['dynamics'] = $d;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1',
            [self::AELA, json_encode($ped)]);
    }

    /** Core's relationship to the player (CHIM 3.4.1 key "Player", RelationshipDynamics::PLAYER_RELATIONSHIP_KEY). */
    private function setCoreAff(int $aff): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb)
            WHERE npc_name = $1", [self::AELA, json_encode([RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => $aff, 'type' => 'platonic']])]);
    }

    /** A shared-contract eval item, queued the way the eval worker queues it. */
    private function queueEval(array $signals, array $tags, float $significance = 0.8): void
    {
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::AELA, [
            'v' => 1, 'npc' => self::AELA, 'npc_id' => 1, 'gamets' => (int) $this->gamets + random_int(1, 999), 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => true,
            'summary' => 'eval ' . bin2hex(random_bytes(3)),
        ]));
    }

    /** Aela's attraction for the player as the core rows stand now (RelDynPlayer::profile()). */
    private function attractionNow(array &$d): array
    {
        return RelationshipDynamics::updateAttraction(self::AELA, $d, RelDynPlayer::profile());
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    /** The request's context blocks from the last turn (context_pre's HERIKA_PERS and context's messages). */
    private string $lastPrompt = '';

    /** One player line to Aela through every hook main.php runs; she answers in $mood. */
    private function fullTurn(string $line, string $mood): void
    {
        $this->gamets += 5 * self::MINUTE;
        $this->realTs += 60;
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . self::AELA . '|' . self::PLAYER . '|';
        // core logs the exchange (the eval producer anchors to it) and the mood Aela answered in
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['inputtext', self::PLAYER . ": {$line} (Talking to " . self::AELA . ')', 'pending', (int) $this->gamets, $this->realTs, (int) $this->gamets, $party, 'Jorrvaskr']);
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['chat', self::AELA . ': Is that so? (talking to ' . self::PLAYER . ')', 'pending', (int) $this->gamets + 1, $this->realTs + 1, (int) $this->gamets + 1, $party, 'Jorrvaskr']);
        pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)',
            [self::AELA, $mood, $this->realTs + 1]);
        $GLOBALS['HERIKA_PERS'] = '';
        $GLOBALS['contextDataFull'] = [];
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = self::AELA;
            $GLOBALS['RELDYN_NPC_NAME'] = self::AELA;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            if ($hook === 'context.php') {
                $this->lastPrompt = (string) $GLOBALS['HERIKA_PERS'] . "\n"
                    . implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []));
            }
            if ($hook !== 'context_pre.php') $this->clearReldynGlobals();
        }
    }

    private function aelaId(): int
    {
        return (int) pg_fetch_result(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', [self::AELA]), 0, 0);
    }

    /** The eval worker's inbox item for the exchange just logged (what the eval LLM scored). */
    private function scoredExchange(array $signals): void
    {
        $this->assertTrue(RelationshipDynamics::queuePendingEval(self::AELA, [
            'v' => 1, 'npc' => self::AELA, 'npc_id' => $this->aelaId(), 'gamets' => (int) $this->gamets, 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => ['praise'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.4, 'positive_interaction' => true,
            'summary' => 'They traded boasts about the hunt ' . bin2hex(random_bytes(2)),
        ]));
    }

    /**
     * A strong warrior Aela is drawn to flirts; she flirts back, six exchanges the eval scores at
     * passion +6. Her flirty answers are reciprocity: passion grows, the Ick never triggers and
     * adds no resentment, and the prompt does not tell her to answer flirtation while pulling
     * away.
     */
    public function testAWarriorGainsPassionWhileAelaFlirtsBack(): void
    {
        $GLOBALS['RELLLM_CONNECTOR'] = 5;              // an eval connector (the eval owns the exchange)
        RelDynEval::$launcher = function (): void {};  // the worker is not launched; its item is queued below
        $this->warriorAtStep(4);
        $this->setCoreAff(10);                         // an acquaintance, as in the finding
        $this->fullTurn('Good hunting today.', 'default');
        $this->editDynamics(function (array &$d): void { RelationshipDynamics::setPassion($d, 10.0); });
        $start = $this->dynamics();
        $this->assertTrue($start['_attraction']['passes'], json_encode($start['_attraction']['passion']));
        $this->assertGreaterThan(0.0, $start['_attraction']['passion_mult']);
        $resentment0 = floatval($start['dimensions']['resentment']['x'] ?? 0);

        for ($i = 0; $i < 6; $i++) {
            $this->scoredExchange(['passion' => 6]);
            $this->fullTurn('You fight like a wolf. I like that.', 'flirty');
        }
        $end = $this->dynamics();
        $this->assertStringContainsString('stands down', (string) file_get_contents($this->errorLog) . (string) @file_get_contents(sys_get_temp_dir() . '/reldyn_attraction_loop_pg_test.log'),
            'the eval owned these exchanges');
        $this->assertEmpty($end['_ick_tracker']['ick_active'] ?? false, 'her own flirty answers are not unreciprocated pressure: ' . json_encode($end['_ick_tracker'] ?? null));
        $this->assertSame(0, intval($end['_ick_tracker']['romantic_count'] ?? 0), 'no exchange counted as pressure');
        $this->assertGreaterThan(10.0, RelationshipDynamics::getPassion($end), 'passion grows with a warrior she is drawn to');
        $this->assertLessThanOrEqual($resentment0 + 1e-6, floatval($end['dimensions']['resentment']['x'] ?? 0), 'no Ick resentment');
        $this->assertDoesNotMatchRegularExpression('/steps back when/i', $this->lastPrompt);

        // The same exchanges without her flirting back, pressed by touch: that is pressure the
        // Ick counts (the control)
        $this->assertTrue(RelationshipDynamics::isRomanticAttempt(RelationshipDynamics::LL_TOUCH, 'default', []));
        $this->assertFalse(RelationshipDynamics::isRomanticAttempt(RelationshipDynamics::LL_TOUCH, 'flirty', []), 'touch she answers in kind');
        $this->assertFalse(RelationshipDynamics::isRomanticAttempt(RelationshipDynamics::LL_WORDS, 'Flirty', []));
        $this->assertNoDbFailures();
    }

    /**
     * The stage's passion floor is a passion writer too (decisions §13): a non-negotiable (her
     * orientation against the player's) holds no floor, so at a deep stage (floor 15) passion
     * stays at 0 and no passion line reaches the prompt; the deep floor sits inside the spark,
     * so it holds for anyone else, the bard far down her hill as much as the warrior.
     */
    public function testTheStageFloorHoldsInsideTheSparkButNotThroughAHardZero(): void
    {
        $this->bard();
        $this->corePlayer('gender', 'female');
        $this->fullTurn('A song for the Huntress?', 'default');
        $deep = function (array &$d): void {
            $d['stage'] = 'deep';
            $d['total_positive_interactions'] = 200;
            RelationshipDynamics::setPassion($d, 0.0);
            // 50 real hours played with her, the last passion update 20 real minutes of play ago
            $at = 50.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
            $d['passion_updated_at'] = $at;
            $d['_accumulated_play_gamets'] = $at + 20 * 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
            $d['_last_contact_play_gamets'] = $d['_accumulated_play_gamets'];
        };
        // the editor sets her orientation; the next request reads it (the summary the passion paths use)
        $this->editDynamics(function (array &$d): void { $d['attraction_overrides'] = ['gender_pref' => 'heterosexual']; });
        $this->fullTurn('Another verse?', 'default');
        $this->editDynamics($deep);
        $this->fullTurn('Another verse?', 'default');
        $d = $this->dynamics();
        $this->assertSame('orientation', $d['_attraction']['hard_zero'], json_encode($d['_attraction']['passion']));
        $this->assertSame(0.0, floatval($d['_attraction']['passion_mult']));
        $this->assertSame('deep', $d['stage']);
        $this->assertLessThan(0.001, RelationshipDynamics::getPassion($d), 'the stage floor does not lift a hard zero');
        $this->assertSame(0.0, RelationshipDynamics::passionStageFloor($d));
        $this->assertDoesNotMatchRegularExpression('/unexamined glance|small smile when they come near/', $this->lastPrompt);

        // The bard she could be drawn to, however far down her hill: the floor is inside the spark
        $this->editDynamics(function (array &$d) use ($deep): void {
            $deep($d);
            unset($d['attraction_overrides']);
        });
        $this->fullTurn('Another verse?', 'default');
        $b = $this->dynamics();
        $this->assertNull($b['_attraction']['hard_zero']);
        $this->assertLessThan(0.15, $b['_attraction']['passion_mult'], 'far down her hill');
        $this->assertSame(floatval(RelationshipDynamics::STAGE_PARAMS['deep']['floor']), RelationshipDynamics::passionStageFloor($b));

        // The same floor holds for a warrior she is drawn to
        $this->warriorAtStep(4);
        $this->fullTurn('I took the Companions\' oath.', 'default');
        $w = $this->dynamics();
        $this->assertTrue($w['_attraction']['passes']);
        $this->assertSame(floatval(RelationshipDynamics::STAGE_PARAMS['deep']['floor']), RelationshipDynamics::passionStageFloor($w));
        $this->assertNoDbFailures();
    }
}
