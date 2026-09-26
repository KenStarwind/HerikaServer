<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure, recorded). */
final class RelDynMemoryMirrorPgDb
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
    public function execQuery($q) { $r = @pg_query($this->link, $q); if (!$r) $this->failures[] = pg_last_error($this->link); return $r; }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * memory-translation-layer + player-profile-mirror, their writes on real PostgreSQL with CHIM
 * 3.4.1's shapes: core_player (the mirror's row, a playthrough table), core's memory table and the
 * memory_v view its summary packer reads (debug/db_updates.php), eventlog for the place.
 *   - the mirror: one statement per observation, the window kept, a re-applied item recorded once,
 *     a trajectory snapshot per game day, a save load's rewind;
 *   - the translation note: opt-in, once per item, at the exchange's game time, where core's packer
 *     reads it beside the exchange's speech; words only;
 *   - anchors: the place from core's location context, kept once, their tagged note.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynMemoryMirrorPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 150 * RelationshipDynamics::GAMETS_PER_DAY;
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynMemoryMirrorPgDb $db;
    private array $saved = [];
    private string $errorLog;
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_memmir_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // data/database_default.sql memory + speech, eventlog
        pg_query($admin, "CREATE SEQUENCE memory_rowid_seq");
        pg_query($admin, "CREATE TABLE memory (speaker text, message text, session text, uid serial NOT NULL, listener text,
            localts bigint, gamets bigint NOT NULL, momentum text, rowid bigint NOT NULL DEFAULT nextval('memory_rowid_seq'),
            event character varying(64), ts bigint)");
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        // lib/core/database_schema/core_npc_master.sql (the save-load reconcile walks it)
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, npc_name text NOT NULL, npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0, prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text,
            personality text, relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        // debug/db_updates.php memory_v (the BgL patch, 20251122001): what PackIntoSummary packs
        pg_query($admin, "CREATE VIEW memory_v AS SELECT message, uid, gamets, speaker, listener, ts FROM (
              SELECT memory.message, memory.uid, memory.gamets, '-'::text AS speaker, '-'::text AS listener, memory.ts FROM memory
               WHERE memory.message !~~ 'Dear Diary%'::text AND memory.message <> ''::text AND event <> 'backgroundlife_diary'::text
            UNION
              SELECT '(Context Location:' || speech.location || ') ' || speech.speaker || ': ' || speech.speech,
                speech.rowid::integer, speech.gamets, speech.speaker, speech.listener, speech.ts FROM speech WHERE speech.speech <> ''::text
            UNION
              SELECT eventlog.data, eventlog.rowid::integer, eventlog.gamets, '-'::text, '-'::text, eventlog.ts FROM eventlog
               WHERE eventlog.type::text = ANY (ARRAY['death'::character varying::text, 'location'::character varying::text])) subquery
            ORDER BY gamets, ts");
        pg_close($admin);
        $this->db = new RelDynMemoryMirrorPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::T0, 'Kaida: hi'];
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdmemmir');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        pg_query_params($this->db->link, 'INSERT INTO locations (name, hold, tags, is_interior, world) VALUES ($1, $2, $3, 1, $4)',
            ['Breezehome', 'Whiterun', 'House,Player House,', 'WhiterunWorld']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function config(array $cfg): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
        RelationshipDynamics::clearConfigCache();
    }

    private function mirrorRow(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT value FROM core_player WHERE id = $1', [RelDynMirror::ROW_ID]));
        return $r ? json_decode($r['value'], true) : [];
    }

    /** A normalized eval contract item. */
    private static function item(string $npc, int $gamets, array $signals, array $tags = [], array $extra = []): array
    {
        return array_replace(['v' => 1, 'npc' => $npc, 'gamets' => $gamets, 'source' => 'reldyn_eval',
            'signals' => array_replace(array_fill_keys(array_keys(RelationshipDynamics::EVAL_CONTRACT_SIGNALS), 0.0), $signals),
            'tags' => $tags, 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0], 'significance' => 0.5,
            'positive_interaction' => true, 'summary' => 'The player helped carry the firewood.'], $extra);
    }

    private static function dyn(array $dims, float $aff): array
    {
        $d = ['dimensions' => [], '_aff_mirror_x' => ($aff + 100) / 2];
        foreach ($dims as $k => $x) $d['dimensions'][$k] = ['x' => $x, 'baseline' => $x];
        $d['dimensions']['affinity'] = ['x' => ($aff + 100) / 2, 'baseline' => 50];
        return $d;
    }

    // ------------------------------------------------------------------ the mirror's row

    public function testObservationsAppendOnceKeepTheWindowAndSnapshotOncePerGameDay(): void
    {
        $this->config(['player_mirror' => ['window' => 5]]);
        $ctx = ['cf' => false, 'cm' => false, 'pa' => false, 'b' => 40];
        for ($i = 0; $i < 7; $i++) {
            $n = self::item($i % 2 ? 'Ashe' : 'Muiri', self::T0 + $i * 1000, ['trust' => 2.0, 'affinity' => 1.0], ['help'], ['charisma' => 'rock']);
            $this->assertTrue(RelDynMirror::observe($n['npc'], $n, $ctx, floatval($n['gamets']), "fp{$i}"), "observation {$i}");
        }
        $this->assertFalse(RelDynMirror::observe('Ashe', self::item('Ashe', self::T0 + 6000, []), $ctx, self::T0 + 6000, 'fp6'), 'a re-applied item: once');
        $row = $this->mirrorRow();
        $this->assertSame(7, $row['total']);
        $this->assertSame(['fp2', 'fp3', 'fp4', 'fp5', 'fp6'], array_column($row['obs'], 'fp'), 'the newest window, in order');
        $this->assertSame([3, 4, 5, 6, 7], array_column($row['obs'], 'q'));
        $this->assertEquals([1.0, 2.0], array_slice($row['obs'][0]['s'], 0, 2), 'raw signals in contract order (affinity, trust)');
        $this->assertSame('rock', $row['obs'][4]['ch']);
        $this->assertCount(1, $row['history'], 'one snapshot for the first game day');
        $n = self::item('Ashe', self::T0 + self::DAY + 10, ['trust' => 2.0]);
        RelDynMirror::observe('Ashe', $n, $ctx, self::T0 + self::DAY + 10, 'fp-next-day');
        $this->assertCount(2, $this->mirrorRow()['history'], 'a game day later, the next');
        $this->assertSame([], $this->db->failures);
    }

    public function testASaveLoadRewindsTheMirror(): void
    {
        $ctx = ['cf' => false, 'cm' => false, 'pa' => false, 'b' => 0];
        foreach ([0, 2, 4] as $d) {
            $g = self::T0 + $d * self::DAY;
            RelDynMirror::observe('Aela the Huntress', self::item('Aela the Huntress', $g, ['trust' => 3.0]), $ctx, $g, "day{$d}");
        }
        $this->assertSame('rewound', RelDynMirror::rewind(self::T0 + 3 * self::DAY));
        $row = $this->mirrorRow();
        $this->assertSame(['day0', 'day2'], array_column($row['obs'], 'fp'));
        $this->assertSame(2, $row['total']);
        $this->assertSame([self::T0, self::T0 + 2 * self::DAY], array_column($row['history'], 'g'));
        $this->assertSame('current', RelDynMirror::rewind(self::T0 + 3 * self::DAY));
        // After the load the next observation takes the next sequence number
        RelDynMirror::observe('Aela the Huntress', self::item('Aela the Huntress', self::T0 + 3 * self::DAY, []), $ctx, self::T0 + 3 * self::DAY, 'after');
        $this->assertSame(3, $this->mirrorRow()['obs'][2]['q']);
        $this->assertSame([], $this->db->failures);
    }

    /** The same through RelDynTimeline's reconcile, as core's load signal (the eventlog 'init' row) drives it. */
    public function testTheSaveLoadReconcileRewindsTheMirror(): void
    {
        $init = function (int $gamets): void {
            pg_query_params($this->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts) VALUES ('init', '', 'x', $1, $2, $1)",
                [$gamets, time()]);
        };
        $init(self::T0 - self::DAY);
        $this->assertSame(['adopted' => true], RelDynTimeline::reconcileIfLoaded(), 'the first load RelDyn sees is adopted');
        $ctx = ['cf' => false, 'cm' => false, 'pa' => false, 'b' => 0];
        foreach ([0, 2, 4] as $d) {
            $g = self::T0 + $d * self::DAY;
            RelDynMirror::observe('Muiri', self::item('Muiri', $g, ['trust' => -3.0]), $ctx, $g, "d{$d}");
        }
        $init(self::T0 + 3 * self::DAY);
        $summary = RelDynTimeline::reconcileIfLoaded();
        $this->assertSame('rewound', $summary['player_mirror'] ?? null, json_encode($summary));
        $this->assertSame(['d0', 'd2'], array_column($this->mirrorRow()['obs'], 'fp'));
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the translation note

    public function testTheSubtextNoteIsOptInOncePerItemAndPackedBesideTheExchange(): void
    {
        $g = self::T0 + 5000;
        pg_query_params($this->db->link, 'INSERT INTO speech (sess, speaker, speech, location, listener, localts, gamets, ts) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['pending', 'Kaida', 'Any work for me?', 'Jorrvaskr', 'Aela the Huntress', 1727000000, $g - 10, $g - 10]);
        $d = self::dyn(['resentment' => 70, 'comfort' => 15], -40.0);
        $n = self::item('Aela the Huntress', $g, ['affinity' => -2.0], ['command'], ['summary' => 'Kaida asked for work; she named the bandit camp.',
            'duty_factor' => 0.5, 'significance' => 0.6]);

        RelDynMemory::onEvalItem('Aela the Huntress', $n, $d, $g, 'fpA');
        $this->assertSame('0', pg_fetch_result(pg_query($this->db->link, 'SELECT count(*) FROM memory'), 0, 0), 'commit is off by default');

        $this->config(['memory_translation' => ['commit' => ['enabled' => true]]]);
        RelDynMemory::onEvalItem('Aela the Huntress', $n, $d, $g, 'fpA');
        RelDynMemory::onEvalItem('Aela the Huntress', $n, $d, $g, 'fpA');   // re-applied: once
        $this->assertSame([], $this->db->failures);
        $rows = pg_fetch_all(pg_query($this->db->link, "SELECT speaker, listener, message, gamets, event, session FROM memory ORDER BY rowid"));
        $this->assertCount(1, $rows);
        $this->assertSame(['Aela the Huntress', 'Kaida', (string) ($g + 1), 'reldyn_subtext', 'reldyn:fpA'],
            [$rows[0]['speaker'], $rows[0]['listener'], $rows[0]['gamets'], $rows[0]['event'], $rows[0]['session']]);
        // the exchange's location as core's speech rows carry it (batch-R: PackIntoSummary cuts a queue at a row without one)
        $this->assertSame('(Context Location:Jorrvaskr) (Beneath this moment with Kaida, Aela the Huntress was operating strictly out of begrudging duty, harbouring a deep, '
            . 'unresolved resentment toward Kaida and keeping an icy, transactional distance. What happened: Kaida asked for work; she named the bandit camp.)',
            $rows[0]['message']);
        // Core's packer reads it right after the exchange's speech (memory_v, ordered by game time)
        $packed = array_column(pg_fetch_all(pg_query($this->db->link, 'SELECT message FROM memory_v ORDER BY gamets, ts')), 'message');
        $this->assertSame(['(Context Location:Jorrvaskr) Kaida: Any work for me?', $rows[0]['message']], $packed);
        // A trivial exchange gets none
        RelDynMemory::onEvalItem('Aela the Huntress', self::item('Aela the Huntress', $g + 50, [], [], ['significance' => 0.1]), $d, $g + 50, 'fpB');
        $this->assertSame('1', pg_fetch_result(pg_query($this->db->link, 'SELECT count(*) FROM memory'), 0, 0));
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ anchors

    public function testAnAnchorKeepsCoresPlaceOnceAndTagsItsNote(): void
    {
        $this->config(['memory_translation' => ['commit' => ['enabled' => true]]]);
        pg_query_params($this->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts) VALUES ('infoloc', $1, 'x', $2, 0, $2)",
            [self::HOME, self::T0 - 100]);
        $d = self::dyn(['resentment' => 5], 60.0);
        $n = self::item('Ashe', self::T0, ['affinity' => 2.0], ['gift'], ['summary' => 'The player gave her an old Dwemer gyro.']);
        RelDynMemory::onEvalItem('Ashe', $n, $d, self::T0, 'fpG');
        $a = RelDynMemory::anchors($d)['first_gift'] ?? null;
        $this->assertIsArray($a);
        $this->assertEquals(['name' => 'Breezehome', 'hold' => 'Whiterun'], $a['place']);
        $this->assertSame('The player gave her an old Dwemer gyro', $a['event'], 'the eval summary, cleaned (RelDynFelt::sanitizeReason)');
        $this->assertSame('breezehome', $d[RelDynMemory::PLACE_KEY], 'made here: being here is no arrival');
        // The first stays
        $later = self::item('Ashe', self::T0 + self::DAY, ['affinity' => 2.0], ['gift'], ['summary' => 'Another gift.']);
        RelDynMemory::onEvalItem('Ashe', $later, $d, self::T0 + self::DAY, 'fpG2');
        $this->assertSame(self::T0, (int) RelDynMemory::anchors($d)['first_gift']['gamets']);
        $notes = pg_fetch_all(pg_query($this->db->link, "SELECT message, gamets FROM memory WHERE event = 'reldyn_anchor'"));
        $this->assertCount(1, $notes);
        $this->assertSame('(Important note: Kaida gave Ashe a gift for the first time at Breezehome. This is a defining moment between Ashe and Kaida, so use tag #FirstGift.)',
            $notes[0]['message']);
        $this->assertSame((string) (self::T0 + 1), $notes[0]['gamets'], 'a fraction of a game second after the moment');
        $this->assertSame([], $this->db->failures);
    }

    public function testPartnersIsAChangeObservedNotATitleAlreadyHeld(): void
    {
        $d = self::dyn([], 70.0) + ['_core_rel_type' => 'romantic'];
        $this->assertFalse(RelDynMemory::notePartners('Muiri', $d, 'romantic', self::T0), 'already partners before: no false history');
        $this->assertFalse(RelDynMemory::notePartners('Muiri', $d, null, self::T0));
        $this->assertTrue(RelDynMemory::notePartners('Muiri', $d, 'crush', self::T0));
        $this->assertArrayHasKey('partners', RelDynMemory::anchors($d));
        $this->assertNull(RelDynMemory::anchors($d)['partners']['place'], 'no location context in core: no place');
        $this->assertSame([], $this->db->failures);
    }
}
