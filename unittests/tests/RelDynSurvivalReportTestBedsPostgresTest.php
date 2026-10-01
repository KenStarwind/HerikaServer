<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynSurvivalBedsPgDb
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
 * The survival rows made live (Ken 2026-10-01 §21), on the four test beds (Aela the Huntress, Ashe with
 * Serene's hand-set vector, Muiri, Lynly Star-Sung) and a man (Hadvar), through the real hooks, CHIM
 * 3.4.1 core-shaped rows on a real PostgreSQL, no LLM call. The AIAgent fork's info_survival report is
 * written to the eventlog the way the plugin sends it; without one every row is inert.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynSurvivalReportTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const AELA = 'Aela the Huntress';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice, gender]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander', 'female'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null, 'female'],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager', 'female'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord', 'female'],
        'Hadvar'            => ['hadvar', 'NordRace', 'Soldier', [], ['onehanded' => 50, 'block' => 40], null, 'male'],
    ];
    private const OUTSIDE = '(Context location: Whiterun outdoors ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ..., current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private ?RelDynSurvivalBedsPgDb $db = null;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    /** npc => label => felt lines (key => text) */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdsurv');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_survival_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        $this->world();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        if (!isset($this->dsn)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        if ($this->db !== null) pg_close($this->db->link);
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

    private function world(): void
    {
        $this->schema = 'reldyn_surv_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and its history table
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

        $this->db = new RelDynSurvivalBedsPgDb($this->dsn, $this->schema);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;
        RelationshipDynamics::endRequest();
        RelDynTraitRead::reset();
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();

        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice, $gender]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, $gender, $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if (!isset($seed['reads'][$key])) continue;   // Ashe: Serene's hand-set vector, never read; Hadvar: no seed
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode(['level' => 20])]);
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, string $people, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people, '', $state]);
    }

    private function people(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /** The plugin's report (SurvivalReportPolicy::BuildReport) at $gamets: every bed within reach, Aela and Ashe following. */
    private function report(int $gamets, array $over = []): void
    {
        $report = array_replace_recursive([
            'v' => 1,
            'mods' => ['lastseed', 'frostfall', 'campfire', 'dirtandblood'],
            'player' => ['hunger' => 0, 'thirst' => 0, 'fatigue' => 1, 'exposure' => 1, 'wet' => 0, 'dirt' => 0, 'blood' => 0],
            'fire' => ['near' => false, 'heat' => 0, 'builder' => 'other'],
            'actors' => [
                'Aela the Huntress' => ['follower' => true, 'd' => 120],
                'Ashe' => ['follower' => true, 'd' => 180],
                'Muiri' => ['follower' => false, 'd' => 250],
                'Lynly Star-Sung' => ['follower' => false, 'd' => 300],
                'Hadvar' => ['follower' => false, 'd' => 350],
            ],
        ], $over);
        $this->event('info_survival', json_encode($report), $gamets, $this->people());
    }

    /** One player line to $npc through the real hooks at $gamets, logged as core logs it. */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->people());
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->people(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    /** Every NPC hears $line, ten game minutes apart from $gamets. */
    private function round(array $npcs, string $line, int $gamets, string $label): void
    {
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 600 * $i++, $label);
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    private function assertNoFailures(): void
    {
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertStringNotContainsString('ERROR', (string) file_get_contents($this->errorLog));
    }

    private const SURVIVAL = ['hungry', 'exhausted', 'well_rested', 'warm_fire', 'warm_fire_other', 'dirty', 'bloody', 'cold', 'wet'];

    // ------------------------------------------------------------------ inert without a report

    public function testWithoutAReportEveryRowStaysInertOnEveryBed(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(60, 12.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'A fine day.', $t0, 'quiet');
        foreach ($beds as $npc) {
            $active = $this->dynamics($npc)['_active_physical_states'] ?? [];
            $this->assertSame([], array_intersect(self::SURVIVAL, $active), "{$npc}: nothing is assumed");
        }
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ the rows go live

    public function testTheRowsGoLiveByWhoIsTravellingAndWhatTheReportSaysAndClearWhenItChanges(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(61, 20.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->report($t0 - 500);
        $this->round($beds, 'Evening.', $t0, 'before');
        $before = [];
        foreach ($beds as $npc) $before[$npc] = $this->dynamics($npc);
        foreach ($beds as $npc) $this->assertSame([], array_intersect(self::SURVIVAL, $before[$npc]['_active_physical_states'] ?? []), $npc);

        // The party is hungry and worn out, a fire the player built burns beside them, the player is filthy and bloody
        $t1 = $t0 + 2 * (int) self::HOUR;
        $this->report($t1 - 500, [
            'player' => ['hunger' => 3, 'fatigue' => 4, 'dirt' => 4, 'blood' => 3],
            'fire' => ['near' => true, 'heat' => 2, 'dist' => 200, 'builder' => 'player'],
            'actors' => ['Muiri' => ['hunger' => 0, 'dirty' => true]],
        ]);
        $this->round($beds, 'We should make camp.', $t1, 'camp');
        $now = [];
        foreach ($beds as $npc) $now[$npc] = $this->dynamics($npc);
        foreach (['Aela the Huntress', 'Ashe'] as $follower) {
            foreach (['hungry', 'exhausted', 'warm_fire', 'dirty', 'bloody'] as $s) {
                $this->assertContains($s, $now[$follower]['_active_physical_states'], "{$follower}: {$s}");
            }
        }
        // Not travelling with the player: their own body, not the party's. Muiri is tracked fed and carries a mark of their own
        $this->assertNotContains('hungry', $now['Muiri']['_active_physical_states']);
        $this->assertNotContains('exhausted', $now['Muiri']['_active_physical_states']);
        $this->assertContains('warm_fire', $now['Muiri']['_active_physical_states'], 'the fire is within reach of anyone beside the player');
        $this->assertContains('dirty', $now['Muiri']['_active_physical_states']);
        foreach (['Lynly Star-Sung', 'Hadvar'] as $bystander) {
            $this->assertNotContains('hungry', $now[$bystander]['_active_physical_states'], $bystander);
            $this->assertNotContains('exhausted', $now[$bystander]['_active_physical_states'], $bystander);
            $this->assertContains('dirty', $now[$bystander]['_active_physical_states'], "{$bystander} sees the player's grime");
            $this->assertContains('bloody', $now[$bystander]['_active_physical_states'], "{$bystander} sees the blood");
        }

        // The warmth of the fire the player built goes to the player; the hunger takes some composure
        foreach (['Aela the Huntress', 'Ashe'] as $follower) {
            $applied = $now[$follower]['_applied_physical_deltas'];
            $this->assertLessThan(0.0, $applied['hungry']['maturity'], $follower);
            $this->assertLessThan(0.0, $applied['exhausted']['comfort'], $follower);
            $this->assertGreaterThan(0.0, $applied['warm_fire']['comfort'], $follower);
            $this->assertGreaterThan(0.0, $applied['warm_fire']['warmth'], "{$follower}: warmth toward whoever built it");
        }

        // Who they are scales it: the same report does not move everyone the same
        $hungryMaturity = [];
        $fireWarmth = [];
        foreach (['Aela the Huntress', 'Ashe'] as $f) {
            $hungryMaturity[$f] = round($now[$f]['_applied_physical_deltas']['hungry']['maturity'], 3);
            $fireWarmth[$f] = round($now[$f]['_applied_physical_deltas']['warm_fire']['warmth'], 3);
        }
        $comfortHit = [];
        foreach ($beds as $npc) $comfortHit[$npc] = round($now[$npc]['_applied_physical_deltas']['dirty']['comfort'] ?? 0.0, 3);
        $this->assertGreaterThan(1, count(array_unique($comfortHit)), 'grime lands differently on different people: ' . json_encode($comfortHit));

        // Pride is their own trait: only the proud lose face before the filthy (the existing gate, now reachable)
        foreach ($beds as $npc) {
            $d = $now[$npc];
            $px = RelDynTraits::vectorFor($d['inferred_temperament'] ?? 'Stoic', $d);
            $proud = $px !== null && floatval($px['Pd'] ?? 0.0) >= 0.5;
            $this->assertSame($proud, isset($d['_applied_physical_deltas']['dirty']['respect']), "{$npc}: pride " . ($px['Pd'] ?? 'n/a'));
        }
        // A warrior's blood-respect only for those the trait engine puts on that side
        $warriors = array_filter($beds, fn($npc) => isset($now[$npc]['_applied_physical_deltas']['bloody']['respect']));
        $this->assertNotSame(count($beds), count($warriors), 'not everyone respects the blood: ' . json_encode(array_values($warriors)));

        // Held while it holds
        $held = $this->dynamics(self::AELA)['_applied_physical_deltas'];
        $this->report($t1 + 3000, [
            'player' => ['hunger' => 3, 'fatigue' => 4, 'dirt' => 4, 'blood' => 3],
            'fire' => ['near' => true, 'heat' => 2, 'dist' => 200, 'builder' => 'player'],
        ]);
        $this->turn(self::AELA, 'Still hungry.', $t1 + 6000, 'held');
        $this->assertSame($held, $this->dynamics(self::AELA)['_applied_physical_deltas']);

        // Fed, rested, washed and the fire gone: what was applied is taken back
        $t2 = $t1 + 4 * (int) self::HOUR;
        $this->report($t2 - 500, ['player' => ['hunger' => 0, 'fatigue' => 0, 'dirt' => 0, 'blood' => 0], 'fire' => ['near' => false]]);
        $this->round($beds, 'That was a good meal.', $t2, 'fed');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame([], array_intersect(['hungry', 'exhausted', 'warm_fire', 'warm_fire_other', 'dirty', 'bloody'], $d['_active_physical_states'] ?? []), "{$npc}: cleared");
            $this->assertSame([], array_intersect(['hungry', 'exhausted', 'warm_fire', 'dirty', 'bloody'], array_keys($d['_applied_physical_deltas'] ?? [])), $npc);
        }
        foreach (['Aela the Huntress', 'Ashe'] as $f) {
            $this->assertContains('well_rested', $this->dynamics($f)['_active_physical_states'], "{$f}: rested");
        }
        $this->assertNotContains('well_rested', $this->dynamics('Hadvar')['_active_physical_states'] ?? [], 'a bystander has not shared the rest');
        $this->assertNoFailures();
    }

    public function testAFireSomeoneElseBuiltWarmsTheRoomAndNobodyInParticular(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(62, 20.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Evening.', $t0, 'before');
        $before = [];
        foreach ($beds as $npc) $before[$npc] = $this->dynamics($npc);

        $this->report($t0 + 2000, ['fire' => ['near' => true, 'heat' => 3, 'dist' => 150, 'builder' => 'other']]);
        $this->round($beds, 'What a fire.', $t0 + 4000, 'other');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertContains('warm_fire_other', $d['_active_physical_states'], $npc);
            $this->assertNotContains('warm_fire', $d['_active_physical_states'], $npc);
            $this->assertSame(['comfort'], array_keys($d['_applied_physical_deltas']['warm_fire_other']), "{$npc}: comfort only");
            $this->assertGreaterThan(0.0, $d['_applied_physical_deltas']['warm_fire_other']['comfort'], $npc);
            $this->assertArrayNotHasKey('warmth', $d['_applied_physical_deltas']['warm_fire_other'] ?? [], $npc);
        }
        // The player builds a fire of their own: the warmth goes to the bond now
        $this->report($t0 + 8000, ['fire' => ['near' => true, 'heat' => 2, 'dist' => 150, 'builder' => 'player']]);
        $this->round($beds, 'I built one.', $t0 + 10000, 'mine');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertContains('warm_fire', $d['_active_physical_states'], $npc);
            $this->assertNotContains('warm_fire_other', $d['_active_physical_states'], $npc);
            $this->assertGreaterThan(0.0, $d['_applied_physical_deltas']['warm_fire']['warmth'], $npc);
        }
        $this->assertNoFailures();
    }

    public function testAStaleReportIsUnknownAgainAndAMenRowsLandLikeAnyoneElses(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(63, 12.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->report($t0 - 500, ['player' => ['hunger' => 4]]);
        $this->round($beds, 'Morning.', $t0, 'hungry');
        $this->assertContains('hungry', $this->dynamics(self::AELA)['_active_physical_states']);
        $this->assertNotContains('hungry', $this->dynamics('Hadvar')['_active_physical_states'] ?? [], 'a man out of the party: not the party\'s hunger');
        // Hadvar's own level, tracked by the mod: a man is as hungry as anyone
        $this->report($t0 + 2 * (int) self::HOUR - 500, ['player' => ['hunger' => 0], 'actors' => ['Hadvar' => ['hunger' => 4]]]);
        $this->turn('Hadvar', 'Not much of a ration, is it?', $t0 + 2 * (int) self::HOUR, 'own');
        $this->assertContains('hungry', $this->dynamics('Hadvar')['_active_physical_states']);
        $this->assertLessThan(0.0, $this->dynamics('Hadvar')['_applied_physical_deltas']['hungry']['maturity']);

        // The plugin stops reporting (the mod is gone, the game is closed): after the limit nothing is assumed
        $this->round($beds, 'Anyone there?', $t0 + 8 * (int) self::HOUR, 'stale');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame([], array_intersect(self::SURVIVAL, $d['_active_physical_states'] ?? []), "{$npc}: back to unknown");
        }
        $this->assertNoFailures();
    }
}
