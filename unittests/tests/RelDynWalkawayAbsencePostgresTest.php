<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynWalkawayPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) $rows[] = $row;
        return $res ? $rows : false;
    }

    public function execQuery($q) { return @pg_query($this->link, $q); }

    public function insert($table, $data)
    {
        // As lib/postgresql.class.php insert(): pg_query_params, so a PHP null stays SQL NULL
        $ph = [];
        foreach (array_keys($data) as $i => $_) $ph[] = '$' . ($i + 1);
        return @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Walkaway x absence on a real PostgreSQL, through the real prerequest hook and the calendar
 * scan: the walkaway pauses affinity absence decay for the time the NPC was gone, and only
 * that time. The absence before the walkaway is still felt; the time after the walkaway
 * resolved (on the scan, while the player was elsewhere) is not decayed twice.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynWalkawayAbsencePostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;          // raw gamets per game day
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;    // raw gamets per game hour
    private const T0 = 300 * self::DAY;                                // game day 300

    private string $dsn;
    private string $schema;
    private RelDynWalkawayPgDb $db;
    private array $ids = [];
    private array $savedGlobals = [];

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
        $this->schema = 'reldyn_walk' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Column types as lib/core/database_schema/core_npc_master.sql for what RelDyn touches.
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0,
            prompt_head text,
            npc_static_bio text,
            oghma_knowledge_tags text,
            emote_moods text,
            personality text,
            relationships text,
            occupation text,
            appearance text,
            skills text,
            speechstyle text,
            goals text,
            voiceid text,
            metadata jsonb,
            gender text,
            race text,
            profile_id integer,
            dynamic_profile integer,
            md5 text,
            gamets_last_updated numeric,
            core text,
            base text,
            tags text,
            refid text,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        // lib/core/database_schema/core_npc_master_history.sql: core's timeline stamp after a relationship write
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(),
            npc_name text, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0, prompt_head text,
            npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16),
            profile_id integer, dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb, md5 text, gamets_last_updated numeric,
            core text, base text, tags text)");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        // data/database_default.sql eventlog (game clock, place read, player profile reads)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))"); // prerequest gold ledger + profile the Attraction Matrix reads (RelDynPlayer)
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_close($admin);

        $this->db = new RelDynWalkawayPgDb($dsn, $this->schema);

        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_walkaway_absence_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function config(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['relationship_dynamics_config', json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC whose last contact, absence checkpoint and calendar step are all at T0. */
    private function seed(string $name, int $coreAff, string $coreType, array $dims, array $dynamics = []): void
    {
        $dimensions = [];
        foreach ($dims as $dim => $x) {
            $dimensions[$dim] = ['x' => $x];
        }
        $dynamics += [
            'inferred_temperament' => 'Romantic',
            'attachment_style' => 'secure',
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_interest_vector' => [0.1, 0.2, 0.3],
            '_last_contact_gamets' => self::T0,
            '_decay_last_game_gamets' => self::T0,
            'dimensions' => $dimensions,
        ];
        $ns = ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]];
        $ext = ['relationships' => ['Player' => ['aff' => $coreAff, 'type' => $coreType]]];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, (string) (100 + count($this->ids)), json_encode($ext), json_encode(['reldyn' => $ns])]));
        $this->ids[$name] = (int) $row['id'];
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    private function patch(string $name, array $patch): void
    {
        pg_query_params($this->db->link,
            "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics}',
                (plugin_extended_data #> '{reldyn,dynamics}') || $2::jsonb) WHERE id = $1",
            [$this->ids[$name], json_encode($patch)]);
    }

    private function coreAff(string $name): int
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return (int) json_decode($r['extended_data'], true)['relationships']['Player']['aff'];
    }

    private const MINUTE = self::HOUR / 60;                              // raw gamets per game minute

    /** One player line to $name at game time $gamets, through the real prerequest hook. */
    private function talkTo(string $name, float $gamets, string $line = 'hello'): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) $gamets, "Kaida: {$line}"];
        $GLOBALS['HERIKA_NAME'] = $name;
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /**
     * The absence before a walkaway that starts on the return turn is felt: the walkaway
     * pauses decay from the moment the NPC leaves, it does not swallow the month before.
     */
    public function testAWalkawayStartingOnTheReturnTurnStillFeelsTheAbsenceBeforeIt(): void
    {
        $this->config(['neglect_enabled' => false]);   // resentment is seeded, not grown
        $dims = ['trust' => 60.0, 'comfort' => 60.0, 'maturity' => 60.0, 'respect' => 60.0];
        $this->seed('Serana', 85, 'romantic', $dims + ['resentment' => 100.0]);   // walks away on sight
        $this->seed('Mjoll', 85, 'romantic', $dims + ['resentment' => 0.0]);      // same bond, stays

        $now = self::T0 + 30 * self::DAY;
        $this->talkTo('Serana', $now);
        $this->talkTo('Mjoll', $now);

        $serana = $this->dynamics('Serana');
        $this->assertSame('active', $serana['_walkaway_state'] ?? null, 'precondition: she walked away on this turn');
        $this->assertLessThan(85, $this->coreAff('Mjoll'), 'precondition: a month apart decays the bond');
        $this->assertSame($this->coreAff('Mjoll'), $this->coreAff('Serana'),
            'the month before she left decays exactly as for the NPC who stayed');
        $this->assertEqualsWithDelta($now, (float) $serana['_decay_last_game_gamets'], 0.5, 'interval consumed');
    }

    /**
     * A walkaway that resolves on the calendar scan (the player stayed away, as the boundary
     * test asks) ends the pause at that moment: the NPC's next turn decays only the time since
     * the return, not the whole walkaway.
     */
    public function testAWalkawayResolvedOnTheScanDoesNotDecayTheWalkawayAfterwards(): void
    {
        $this->config(['neglect_enabled' => false]);
        $dims = ['trust' => 60.0, 'comfort' => 60.0, 'maturity' => 60.0, 'respect' => 60.0];
        $this->seed('Serana', 60, 'friend', $dims + ['resentment' => 100.0]);
        $this->seed('Lydia', 20, 'friend', $dims + ['resentment' => 0.0]);

        $this->talkTo('Serana', self::T0);                       // she walks away now
        $this->assertSame('active', $this->dynamics('Serana')['_walkaway_state'] ?? null);
        $this->assertSame(60, $this->coreAff('Serana'));
        $this->patch('Serana', ['_walkaway_boundary_test_hours' => 24.0]);

        $this->talkTo('Lydia', self::T0 + 2 * self::HOUR);       // scan: active -> boundary test
        $this->assertSame('boundary_test', $this->dynamics('Serana')['_walkaway_state']);
        $back = self::T0 + 2 * self::DAY;
        $this->talkTo('Lydia', $back);                           // scan: test resolved, she returns
        $this->assertSame('normal', $this->dynamics('Serana')['_walkaway_state'], 'resolved while the player was elsewhere');

        $this->talkTo('Serana', $back + self::HOUR);             // her next turn, one game hour later

        $this->assertGreaterThanOrEqual(59, $this->coreAff('Serana'),
            'one game hour of absence since her return, not two days of walkaway');
        $this->assertEqualsWithDelta($back + self::HOUR, (float) $this->dynamics('Serana')['_decay_last_game_gamets'], 1.0);
    }

    /** While the walkaway lasts, turns (the player following) decay nothing. */
    public function testNoDecayWhileTheNpcIsAway(): void
    {
        $this->config(['neglect_enabled' => false]);
        $dims = ['trust' => 60.0, 'comfort' => 60.0, 'maturity' => 60.0, 'respect' => 60.0];
        $this->seed('Serana', 60, 'friend', $dims + ['resentment' => 100.0]);

        $this->talkTo('Serana', self::T0);
        $this->assertSame('active', $this->dynamics('Serana')['_walkaway_state'] ?? null);
        $this->talkTo('Serana', self::T0 + 3 * self::DAY);

        $this->assertSame(60, $this->coreAff('Serana'), 'she chose to leave: no absence decay while gone');
    }

    // ------------------------------------------------------------ rulings 2026-09-24 §8

    /** The two spouses of the ruling, core type 'romantic' at aff 85, last seen at T0. */
    private function seedSpouses(): void
    {
        $dims = ['trust' => 60.0, 'comfort' => 60.0, 'respect' => 60.0, 'self_confidence' => 60.0, 'resentment' => 0.0];
        // The core type as the prerequest of the last visit (T0) snapshotted it.
        $spouse = ['_core_rel_type' => 'romantic'];
        // Mature, secure, independent.
        $this->seed('Mjoll', 85, 'romantic', $dims + ['maturity' => 80.0],
            $spouse + ['inferred_temperament' => 'Independent', 'attachment_style' => 'secure', 'traits' => []]);
        // Immature, anxious, proud, codependent.
        $this->seed('Serana', 85, 'romantic', $dims + ['maturity' => 20.0],
            $spouse + ['inferred_temperament' => 'Proud', 'attachment_style' => 'anxious', 'traits' => ['egocentric', 'insecure']]);
        $this->seed('Lydia', 0, 'neutral', $dims + ['maturity' => 50.0]);   // someone else to talk to
    }

    /**
     * The 45-game-day spouse scenario end to end through the prerequest hook and the calendar
     * scan. The mature, secure, independent spouse is cool but stays. The immature, anxious,
     * proud, codependent one is furious and walks out on the return greeting; the player's
     * greeting and pleas in that conversation are not pursuit, so leaving her alone resolves
     * the boundary test and she comes back, still resentful. Before the ruling, the second
     * line counted as following her and made the walkaway permanent.
     */
    public function testFortyFiveDaysAwayMatureSpouseStaysCodependentSpouseLeavesAndComesBack(): void
    {
        $this->config([]);
        $this->seedSpouses();
        $back = self::T0 + 45 * self::DAY;

        $this->talkTo('Mjoll', $back);
        $this->talkTo('Mjoll', $back + 2 * self::MINUTE, 'I missed you');
        $mjoll = $this->dynamics('Mjoll');
        $r = (float) $mjoll['dimensions']['resentment']['x'];   // 0..100
        $this->assertGreaterThanOrEqual(10.0, $r, 'the absence is felt');
        $this->assertLessThan(RelationshipDynamics::RESENTMENT_WITHDRAWAL_AT, $r, 'cool, not withdrawn');
        $this->assertSame('normal', $mjoll['_walkaway_state'] ?? 'normal', 'not gone');
        $this->assertCount(1, array_filter($mjoll['dimensions']['resentment']['grievance_log'],
            fn($g) => ($g['tag'] ?? null) === 'neglect'));

        $this->talkTo('Serana', $back);                                        // the first hello
        $serana = $this->dynamics('Serana');
        $this->assertGreaterThanOrEqual(RelationshipDynamics::RESENTMENT_WALKAWAY_AT,
            (float) $serana['dimensions']['resentment']['x'], 'furious');
        $this->assertSame('active', $serana['_walkaway_state'] ?? null, 'she walks out on the greeting');
        $this->assertSame('neglect', $serana['_walkaway_reason'], 'a neglect walkaway: it started on the return');

        $this->talkTo('Serana', $back + 5 * self::MINUTE, 'wait, please');     // the parting conversation
        $this->talkTo('Serana', $back + 20 * self::MINUTE, 'I am sorry');
        $serana = $this->dynamics('Serana');
        $this->assertEmpty($serana['_walkaway_player_followed'] ?? false, 'talking to her on the return is not pursuit');
        $this->assertNotSame('permanent', $serana['_walkaway_state']);

        // The player leaves her alone and talks to someone else two days later: the scan
        // resolves her boundary test (24-48 game hours) and she comes back.
        $this->talkTo('Lydia', $back + 2 * self::DAY);
        $serana = $this->dynamics('Serana');
        $this->assertSame('normal', $serana['_walkaway_state'], 'resolved, she returned');
        $this->assertGreaterThanOrEqual(RelationshipDynamics::RESENTMENT_WALKAWAY_AT,
            (float) $serana['dimensions']['resentment']['x'], 'resolving the walkaway does not clear the resentment');
        $cmd = pg_fetch_all_columns(pg_query($this->db->link, "SELECT action FROM responselog WHERE action LIKE '%MoveToPlayer%'"));
        $this->assertCount(1, $cmd, 'her autonomous return');
    }

    /** Pursuit still ends it: seeking her out after she left, while the walkaway lasts. */
    public function testSeekingOutTheSpouseWhoLeftIsStillPursuit(): void
    {
        $this->config([]);
        $this->seedSpouses();
        $back = self::T0 + 45 * self::DAY;

        $this->talkTo('Serana', $back);
        $this->assertSame('active', $this->dynamics('Serana')['_walkaway_state'] ?? null);
        $this->talkTo('Serana', $back + 3 * self::HOUR, 'I followed you home');   // past the parting window

        $serana = $this->dynamics('Serana');
        $this->assertTrue($serana['_walkaway_player_followed']);
        $this->talkTo('Lydia', $back + 2 * self::DAY);
        $this->assertSame('permanent', $this->dynamics('Serana')['_walkaway_state']);
    }

    /**
     * The parting exemption is the neglect walkaway's alone (rulings §8): a spouse walking out
     * of an argument, with no absence behind it, is followed by the plea that comes after her.
     */
    public function testAPleaAfterASpouseWhoWalksOutOfAFightIsPursuit(): void
    {
        $this->config([]);
        $this->seed('Uthgerd', 85, 'romantic',
            ['trust' => 60.0, 'comfort' => 60.0, 'respect' => 60.0, 'self_confidence' => 60.0, 'maturity' => 50.0, 'resentment' => 95.0],
            ['_core_rel_type' => 'romantic']);
        $now = self::T0 + self::HOUR;                                          // an hour after the last line

        $this->talkTo('Uthgerd', $now, 'you are being unreasonable');
        $u = $this->dynamics('Uthgerd');
        $this->assertSame('active', $u['_walkaway_state'] ?? null, 'she walks out of the argument');
        $this->assertSame('resentment', $u['_walkaway_reason']);

        $this->talkTo('Uthgerd', $now + 5 * self::MINUTE, 'wait, please');
        $this->assertTrue($this->dynamics('Uthgerd')['_walkaway_player_followed'], 'following her out');
    }
}
