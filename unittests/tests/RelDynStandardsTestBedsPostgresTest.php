<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynStandardsBedsPgDb
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
 * Personality traits phase 3, items 10 and 11 (design §6.1), on the four test beds through the
 * real hooks (prerequest -> context -> postrequest), the real eval inbox and the committed
 * seed's bio reads, on a real PostgreSQL from CHIM 3.4.1 core-shaped rows. No LLM call.
 *
 * Standards floors (decisions §15, design §5.1): each NPC's pillar floor falls out of her
 * traits (effective openness, maturity baseline, max(confidence, pride)); the flat 45 and
 * Aela's hand-set 68 are gone. From the reads (the numbers the batch report gives):
 *   Ashe 81.5 (Serene's hand-set vector, maturity 75, the least open)  >  Aela 67.6 (Ken's
 *   "high 60s")  >  Muiri 57.7  >  Lynly 47.2 (the open bard, near Ken's generic 45).
 *
 * Asexual (decisions §15): passion is emotional, not zero. It grows through the emotional
 * channels (quality time, words, reassurance, confiding, non-sexual touch) at each NPC's own
 * rate, never through intimacy, a rescue or any untagged path; the physical paths and
 * Sharmat's consent stay closed, even in a romance; the friendship grows at least at the spark.
 * Aromantic: romance closed, passion a hard zero, the friendship at the spark, Matrix on or off.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynStandardsTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];

    private string $dsn;
    private string $schema;
    private RelDynStandardsBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY + 10 * self::HOUR;
    private int $llmCalls = 0;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_stdbeds' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynStandardsBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdstdbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_standards_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };

        // Shipped defaults, stored as the config page stores them
        $this->storeConfig([]);
        $this->seed();
        $this->warrior();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
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

    // ------------------------------------------------------------------ fixture

    private function storeConfig(array $top): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $top))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows, voice types, placeholder templates, and the seed's reads keyed to them (Ashe: skip-listed, hand-set). */
    private function seed(): void
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
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 10, 'type' => 'platonic']]])]);
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
    }

    /** A core_player row as core writes it (gamedata.php: one row per id, value text). */
    private function corePlayer(string $id, $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /** The player: a seasoned warrior and Companion (skills, level, tracked Skyrim stats), as the plugin reports it. */
    private function warrior(): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speechcraft', 'twohanded'], 15);
        $this->corePlayer('skills', array_merge($all, ['onehanded' => 70, 'twohanded' => 60, 'block' => 55, 'heavyarmor' => 60]));
        $this->corePlayer('stats', ['level' => 30, 'health' => 400]);
        foreach (['People Killed' => 110, 'Creatures Killed' => 135, 'Quests Completed' => 27, 'Dungeons Cleared' => 17,
                     'Locations Discovered' => 70, 'The Companions Quests Completed' => 4] as $stat => $n) {
            $this->corePlayer($stat, (string) $n);
        }
        $this->corePlayer('gender', 'male');
    }

    /** One player line to $npc through the real hooks: prerequest, context, postrequest; she answers in $mood. */
    private function turn(string $npc, string $line, ?string $mood = null): void
    {
        if ($mood !== null) {
            pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)', [$npc, $mood, $this->realTs]);
        }
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->gamets += 5 * 60 * RelationshipDynamics::GAMETS_PER_DAY / 1440;
        $this->realTs += 60;
    }

    private function ped(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true) ?? [];
    }

    private function dynamics(string $npc): array
    {
        return $this->ped($npc)['reldyn']['dynamics'] ?? [];
    }

    /** Edit $npc's stored RelDyn state (as the editor or an older save would hold it). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $ped = $this->ped($npc);
        $d = $ped['reldyn']['dynamics'];
        $edit($d);
        $ped['reldyn']['dynamics'] = $d;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /** Core's relationship to the player (CHIM 3.4.1 key "Player"). */
    private function setCore(string $npc, int $aff, string $type = 'platonic'): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb)
            WHERE npc_name = $1", [$npc, json_encode([RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => $aff, 'type' => $type]])]);
    }

    /** A shared-contract eval item, queued the way the eval worker queues it. */
    private function queueEval(string $npc, array $signals, array $tags, float $significance = 0.8): void
    {
        $this->assertTrue(RelationshipDynamics::queuePendingEval($npc, [
            'v' => 1, 'npc' => $npc, 'npc_id' => RelDynStorage::resolveNpcId($npc), 'gamets' => (int) $this->gamets + random_int(1, 999),
            'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => true,
            'summary' => 'eval ' . implode('+', $tags) . ' ' . bin2hex(random_bytes(3)),
        ]));
    }

    /**
     * One eval item applied through the real inbox (this request's postrequest); the passion it
     * moved. She answers 'grateful', so the local classifier reads the exchange as acts of
     * service (classifyInteraction), no emotional channel: its own passion gain is closed for
     * an asexual NPC and the item's tags alone decide.
     */
    private function passionFrom(string $npc, array $tags, float $passionSignal = 12.0): float
    {
        $before = RelationshipDynamics::getPassion($this->dynamics($npc));
        $this->queueEval($npc, ['passion' => $passionSignal], $tags);
        $this->turn($npc, 'Hm.', 'grateful');
        return RelationshipDynamics::getPassion($this->dynamics($npc)) - $before;
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    // ------------------------------------------------------------------ standards floors

    public function testEachTestBedsFloorFallsOutOfHerTraits(): void
    {
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met. Have a moment?');
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'How have you been keeping?');
        $this->assertSame(0, $this->llmCalls, 'everything came from the seed: no read was made');

        $floor = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'], $npc);
            $x = RelDynTraits::readVector($d);
            $def = RelDynAttraction::definition($npc, $d);
            $this->assertSame('standards', $def['sources']['floors'], $npc);
            $f = $def['standards']['floor'];
            // the formula on her own read: effective openness, maturity BASELINE, max(C, Pd)
            $this->assertEqualsWithDelta(RelDynAttraction::standardsFloor(['o' => RelDynTraits::opennessAt($x,
                RelDynAttraction::defaults()['temperament_openness'], RelDynAttraction::defaults()['openness_levels'])['o'],
                'M' => floatval($d['dimensions']['maturity']['baseline']), 'C' => $x['C'], 'Pd' => $x['Pd']])['floor'], $f, 1e-4, $npc);
            foreach (RelDynAttraction::PILLARS as $p) $this->assertSame($f, $def['floors'][$p], "{$npc} {$p}: no hand-set floor");
            // the floor the request's own evaluation used (the stored summary's passion units)
            $a = $d['_attraction'];
            $this->assertTrue($a['enabled'], $npc);
            foreach ((array) $a['passion']['units'] as $key => $u) {
                $this->assertEqualsWithDelta($f, $u['floor'], 0.01, "{$npc} {$key}: the hooks read the standards floor");
            }
            // the band read from the traits is recorded for the hysteresis
            $this->assertSame($def['openness'], $d['_attraction_state']['openness_band'] ?? null, $npc);
            $floor[$npc] = $f;
        }
        $why = json_encode($floor);

        // Aela: Ken's "high 60s", from her traits (design §6.2: 66-70)
        $this->assertGreaterThanOrEqual(66.0, $floor['Aela the Huntress'], $why);
        $this->assertLessThanOrEqual(70.0, $floor['Aela the Huntress'], $why);
        $this->assertSame('medium', $this->dynamics('Aela the Huntress')['_attraction_state']['openness_band'], 'her read 0.462: medium, the won-over switch on');
        // Ashe (ruling §16 #4: "high floor"): the highest; Lynly, the open bard, the lowest, near Ken's 45
        $this->assertSame('low', $this->dynamics('Ashe')['_attraction_state']['openness_band']);
        $this->assertGreaterThan(78.0, $floor['Ashe'], $why);
        $this->assertEqualsWithDelta(45.0, $floor['Lynly Star-Sung'], 5.0, $why);
        // they diverge: every pair at least 5 pillar points apart, in the standards order
        $this->assertGreaterThan($floor['Aela the Huntress'] + 5.0, $floor['Ashe'], $why);
        $this->assertGreaterThan($floor['Muiri'] + 5.0, $floor['Aela the Huntress'], $why);
        $this->assertGreaterThan($floor['Lynly Star-Sung'] + 5.0, $floor['Muiri'], $why);
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ asexual: emotional passion

    public function testAsexualPassionGrowsOnlyThroughTheEmotionalChannels(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Well met.');
            $this->setCore($npc, 45);
            $this->editDynamics($npc, function (array &$d): void {
                $d['relationship_preference'] = 'asexual';
                RelationshipDynamics::setPassion($d, 10.0);
            });
        }
        $gain = [];
        foreach (array_keys(self::BEDS) as $npc) {
            // Ashe is bond-gated with rigid standing and competence (Mage profile): this warrior
            // fails her competence bar, a non-negotiable whatever the channel (ruling §16 #9:
            // for an asexual NPC beauty / strength count as met, competence / standing apply)
            $gated = $npc === 'Ashe';
            // closed: sex, a rescue (no emotional channel), touch that is also sexual
            foreach ([['intimacy'], ['rescue'], ['touch', 'intimacy']] as $tags) {
                $moved = $this->passionFrom($npc, $tags);
                $this->assertLessThanOrEqual(0.0, $moved, "{$npc} " . implode('+', $tags) . ': no passion from it');
            }
            // open: quality time, words, reassurance, confiding, non-sexual touch
            foreach ([['quality_time'], ['praise'], ['reassurance'], ['confiding'], ['touch']] as $tags) {
                $moved = $this->passionFrom($npc, $tags);
                if ($gated) {
                    $this->assertLessThanOrEqual(0.0, $moved, "{$npc} " . implode('+', $tags) . ': her rigid competence bar holds');
                } else {
                    $this->assertGreaterThan(0.05, $moved, "{$npc} " . implode('+', $tags) . ': emotional passion');
                }
                $gain[$npc][$tags[0]] = round($moved, 3);
            }
            $d = $this->dynamics($npc);
            $a = $d['_attraction'];
            if ($gated) {
                $this->assertSame('rigid:competence', $a['hard_zero'], "{$npc}: {$a['outcome']}");
            } else {
                $this->assertNull($a['hard_zero'], "{$npc}: asexual is no longer a hard zero");
            }
            $this->assertSame('emotional', $a['passion_channel'], $npc);
            $this->assertFalse($a['intimacy_allowed'], "{$npc}: no handoff to Sharmat");
            $this->assertTrue($a['visceral_met'], "{$npc}: beauty / strength count as met (ruling §16 #9)");
            $this->assertStringContainsString('channel closed: emotional passion only', (string) file_get_contents($this->errorLog), 'the log names why');
        }
        $why = json_encode($gain);
        // each at her own rate (expressiveness A1, attachment, the stage), Ashe not at all
        $total = array_map(fn($g) => array_sum($g), $gain);
        $this->assertSame(0.0, max(0.0, $total['Ashe']), $why);
        $open = array_diff_key($total, ['Ashe' => 1]);
        $this->assertGreaterThan(0.1 * max($open), max($open) - min($open), 'the three who can feel it diverge: ' . $why);
        $this->assertNoDbFailures();
    }

    public function testAsexualPhysicalPathsAndSharmatConsentStayClosedWhileTheFriendshipKeepsPace(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            // deep in a romance core already held when RelDyn first met her (grandfathered: the
            // ownership guard keeps it), passion high: the case where physical intimacy would
            // otherwise be in play and Sharmat's consent gate would open (core type romantic)
            $this->setCore($npc, 80, 'romantic');
            $this->turn($npc, 'Well met.');
            $this->editDynamics($npc, function (array &$d): void {
                $d['relationship_preference'] = 'asexual';
                RelationshipDynamics::setPassion($d, 60.0);
            });
            $this->turn($npc, 'I missed you.');
            $d = $this->dynamics($npc);
            $this->assertSame('emotional', $d['_attraction']['passion_channel'], $npc);
            $this->assertGreaterThan(40.0, RelationshipDynamics::getPassion($d), "{$npc}: her passion is real");
            // the physical paths: no physical need, never in play (a romance and passion 60 notwithstanding)
            $this->assertSame(0.0, floatval(RelDynIntimacy::need($d)['physical'] ?? -1), "{$npc}: no physical need");
            $this->assertFalse(RelDynIntimacy::physicalInPlay($d), "{$npc}: physical intimacy never in play");
            // Sharmat: the published consent block, and her passion adds nothing to arousal
            $state = $this->ped($npc)['reldyn']['romance'] ?? null;
            $this->assertIsArray($state, "{$npc}: the romantic state is published");
            $this->assertSame('romantic', $state['core_type']);
            $this->assertTrue($state['consent_block'], "{$npc}: Sharmat's consent stays closed");
            $this->assertContains('intimacy_not_allowed', $state['block_reasons'], $npc);
            $this->assertFalse($state['intimacy_allowed'], $npc);
            $this->assertSame('emotional', $state['passion_channel'], $npc);
            $this->assertSame(RelationshipDynamics::getEffectiveDisposition(10, array_diff_key($d, ['passion' => 1]) + ['passion' => 0.0]),
                RelationshipDynamics::getEffectiveDisposition(10, $d), "{$npc}: emotional passion is not arousal");
            // felt steering: romantic longing without desire, feelings never numbers; Jev gets the channel
            $felt = [];
            $fd = $d;
            foreach (RelDynFelt::compose($npc, self::PLAYER, $fd, $this->gamets, [])['lines'] as $l) $felt[$l['key']] = $l['text'];
            $this->assertArrayHasKey('passion', $felt, $npc);
            // (Ashe: this warrior fails her rigid competence bar, a hard zero: the platonic reading)
            $this->assertMatchesRegularExpression(!empty($d['_attraction']['hard_zero']) ? '/not romantic|nothing romantic/'
                : '/nothing of the body in it|tender, not physical/', $felt['passion'], $npc);
            $this->assertDoesNotMatchRegularExpression('/wanting to close it|finds excuses to touch/', $felt['passion'], $npc);
            $this->assertDoesNotMatchRegularExpression('/\d/', $felt['passion'], $npc);
            $this->assertSame('emotional', RelationshipDynamics::jevStateBlock($npc)['attraction']['channel'], $npc);
            $this->assertStringContainsString('channel=emotional', RelationshipDynamics::jevStateBlock($npc)['text'], $npc);
            // the friendship: the affinity drive never idles below the spark
            RelationshipDynamics::setPassion($d, 0.0);
            $this->assertEqualsWithDelta(20.0, RelationshipDynamics::affinityDrivePassion($d), 1e-9, "{$npc}: affinity growth not slowed");
        }
        // The same romance with no preference: the consent gate is open and the passion is arousal
        $this->editDynamics('Aela the Huntress', function (array &$d): void { unset($d['relationship_preference']); });
        $this->turn('Aela the Huntress', 'I missed you.');
        $d = $this->dynamics('Aela the Huntress');
        $this->assertNull($d['_attraction']['passion_channel']);
        $this->assertNotContains('intimacy_not_allowed', $this->ped('Aela the Huntress')['reldyn']['romance']['block_reasons']);
        $this->assertGreaterThan(RelationshipDynamics::getEffectiveDisposition(10, ['passion' => 0.0]), RelationshipDynamics::getEffectiveDisposition(10, $d));
        $this->assertNoDbFailures();
    }

    /**
     * Romance promotion (rulings §9, RelDyn owns romance) for an asexual NPC: the moments that
     * count are emotional ones. Intimacy-tagged moments never move her toward a romance;
     * quality-time moments do, for whoever her attraction lets romance (Aela and Lynly; Ashe is
     * bond-gated and holds this warrior below her rigid competence bar: never; Muiri is
     * bond-gated, a slow burn before the bond), and the promoted romance hands Sharmat a
     * closed consent.
     */
    public function testAsexualRomanceIsPromotedOnlyByEmotionalMoments(): void
    {
        $result = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $this->setCore($npc, 60);
            $this->turn($npc, 'Well met.');
            $this->editDynamics($npc, function (array &$d): void { $d['relationship_preference'] = 'asexual'; });
            for ($i = 0; $i < 4; $i++) $this->passionFrom($npc, ['intimacy'], 5.0);
            $d = $this->dynamics($npc);
            $this->assertSame('platonic', $d['_core_rel_type'] ?? null, "{$npc}: sex is no romantic moment for her");
            $this->assertEquals(0.0, floatval($d['_romance']['momentum'] ?? 0.0), "{$npc}: no momentum from intimacy");
            for ($i = 0; $i < 12 && ($this->dynamics($npc)['_core_rel_type'] ?? '') === 'platonic'; $i++) {
                $this->passionFrom($npc, ['quality_time'], 5.0);
            }
            $d = $this->dynamics($npc);
            $result[$npc] = $d['_core_rel_type'] ?? null;
            if ($result[$npc] === 'crush') {
                $state = $this->ped($npc)['reldyn']['romance'];
                $this->assertTrue($state['romantic'], $npc);
                $this->assertTrue($state['consent_block'], "{$npc}: a crush, and Sharmat's consent still closed");
                $this->assertContains('intimacy_not_allowed', $state['block_reasons'], $npc);
            }
        }
        $why = json_encode($result);
        $this->assertSame('platonic', $result['Ashe'], 'Ashe: her rigid competence bar holds: ' . $why);
        $this->assertSame('crush', $result['Aela the Huntress'], 'Aela: emotional moments promote her: ' . $why);
        $this->assertSame('crush', $result['Lynly Star-Sung'], 'Lynly: emotional moments promote her: ' . $why);
        // Muiri (Healer profile) is bond-gated too: before the bond she is a slow burn (prebond)
        $this->assertSame('platonic', $result['Muiri'], 'Muiri: prebond: ' . $why);
        $this->assertSame('prebond', $this->dynamics('Muiri')['_attraction']['outcome']);
        $this->assertNoDbFailures();
    }

    /** Sharmat's consent holds for an asexual NPC with the Matrix off too (the preference is hers, not the Matrix's). */
    public function testAsexualConsentHoldsWithTheMatrixOff(): void
    {
        $this->storeConfig(['attraction_matrix_enabled' => false]);
        foreach (['Aela the Huntress', 'Lynly Star-Sung'] as $npc) {
            $this->setCore($npc, 80, 'romantic');
            $this->turn($npc, 'Well met.');
            $this->editDynamics($npc, function (array &$d): void { $d['relationship_preference'] = 'asexual'; });
            $this->turn($npc, 'I missed you.');
            $d = $this->dynamics($npc);
            $this->assertFalse($d['_attraction']['enabled'], $npc);
            $this->assertSame('emotional', $d['_attraction']['passion_channel'], $npc);
            $state = $this->ped($npc)['reldyn']['romance'];
            $this->assertTrue($state['consent_block'], "{$npc}: consent closed with the Matrix off");
            $this->assertContains('intimacy_not_allowed', $state['block_reasons'], $npc);
            $this->assertGreaterThan(0.2, $this->passionFrom($npc, ['quality_time']), "{$npc}: emotional passion");
            $this->assertLessThanOrEqual(0.0, $this->passionFrom($npc, ['intimacy']), $npc);
        }
        $this->assertNoDbFailures();
    }

    // ------------------------------------------------------------------ aromantic

    public function testAromanticKeepsRomanceClosedAndTheFriendshipAtPace(): void
    {
        foreach ([true, false] as $matrix) {
            $this->storeConfig(['attraction_matrix_enabled' => $matrix]);
            foreach (array_keys(self::BEDS) as $npc) {
                $this->turn($npc, 'Well met.');
                $this->setCore($npc, 60);
                $this->editDynamics($npc, function (array &$d): void {
                    $d['relationship_preference'] = 'aromantic';
                    RelationshipDynamics::setPassion($d, 0.0);
                });
                $this->assertLessThanOrEqual(0.0, $this->passionFrom($npc, ['quality_time']), "{$npc}: no passion at all");
                $d = $this->dynamics($npc);
                $a = $d['_attraction'];
                $m = $matrix ? 'matrix on' : 'matrix off';
                // a hard zero: the preference's (a failed rigid pillar reads first when the Matrix judges)
                $this->assertNotNull($a['hard_zero'], "{$npc} {$m}");
                if (!$matrix) $this->assertSame('preference:aromantic', $a['hard_zero'], "{$npc} {$m}");
                $this->assertEquals(0.0, $a['spark_mult'], "{$npc} {$m}");
                $this->assertSame(['crush', 'romantic'], $a['blocked_types'], "{$npc} {$m}: romance closed");
                $this->assertFalse(RelationshipDynamics::attractionAllowsType($npc, $d, 'crush'), "{$npc} {$m}");
                $this->assertFalse(RelDynRomance::gate($npc, $d)['open'], "{$npc} {$m}: no promotion");
                $this->assertEqualsWithDelta(20.0, RelationshipDynamics::affinityDrivePassion($d), 1e-9, "{$npc} {$m}: friendship not slowed");
                $this->assertEqualsWithDelta(0.3 + 1.7 * 0.2, RelationshipDynamics::getAffinityGainMultiplier($d), 1e-9, "{$npc} {$m}");
            }
        }
        $this->assertNoDbFailures();
    }
}
