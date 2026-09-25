<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCreaturesBedsPgDb
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
 * creature-moodifications end to end on the four test beds (Aela the Huntress, Ashe with Serene's
 * hand-set vector, Muiri, Lynly Star-Sung) plus Serana, through the real hooks (prerequest ->
 * context -> postrequest), core's request poll, CHIM 3.4.1 core-shaped rows on a real PostgreSQL,
 * no LLM call (trait reads come from the committed seed; Ashe's vector is never read).
 *
 * Aela is in CompanionsCircle (Skyrim.esm AelaTheHuntress SNAM), so she carries the beast blood:
 *   - a full-moon night in Skyrim's own cycle (days 22-24 of 24) is her arousal spike, maturity
 *     down, coord_m up and coord_f down; she is a fighter (her fall is fight, not fear), so the
 *     moon reads as thrill; another night is half of that; by day the blood only simmers;
 *   - the offsets are held, not re-added: more turns the same night move nothing more;
 *   - back from beast form (core's transformation report, seen by the poll) she takes the shame.
 * Ashe, Muiri and Lynly are not creatures: the moon touches none of them (divergence). Serana
 * (a vampire by script, NordRace in her record) is ascendant at night and worn by day.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCreaturesTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const AELA = 'Aela the Huntress';
    // Game day 214 is cycle day 22: its evening is the first of three full-moon nights.
    private const FULL = 214;
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const OUTSIDE = '(Context location: Whiterun outdoors ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ..., current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private ?RelDynCreaturesBedsPgDb $db = null;
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdcreatures');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_creatures_beds_test.log');
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

    /** A fresh schema with the CHIM 3.4.1 tables RelDyn touches, the shipped config, the beds and Serana. */
    private function world(): void
    {
        $this->schema = 'reldyn_creat_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynCreaturesBedsPgDb($this->dsn, $this->schema);
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
        $npcs = self::BEDS + ['Serana' => ['serana', 'NordRace', 'Vampire', ['DLC1SeranaFaction', 'DLC1SeranaCrimeFaction', 'CurrentFollowerFaction'],
                                           ['destruction' => 50, 'conjuration' => 45, 'sneak' => 40], null]];
        foreach ($npcs as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if (!isset($seed['reads'][$key])) continue;   // Ashe: Serene's hand-set vector, never read; Serana: no seed
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
        return '|' . implode('|', array_keys(self::BEDS)) . '|Serana|' . self::PLAYER . '|';
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

    /** Core's request poll (every POLINT real seconds): no profile, HERIKA_NAME is conf.php's default. */
    private function poll(int $gamets): void
    {
        unset($GLOBALS['CACHE_PEOPLE'], $GLOBALS['CACHE_PARTY'], $GLOBALS['CACHE_LOCATION'], $GLOBALS['contextDataFull']);
        $GLOBALS['gameRequest'] = ['request', (string) ($this->realTs * 1000), (string) $gamets, self::OUTSIDE, '0x0001a279'];
        $GLOBALS['HERIKA_NAME'] = 'Lynly Star-Sung';
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
        $this->clearReldynGlobals();
        $this->realTs += 1;
    }

    /** The plugin's transformation report for an NPC (gamedata.php transformation_state -> metadata). */
    private function form(string $npc, string $state, int $gamets): void
    {
        $ts = ['state' => $state, 'is_werewolf_form' => $state === 'werewolf', 'is_vampire_lord_form' => $state === 'vampire_lord',
               'race_name' => $state === 'werewolf' ? 'Werewolf' : 'Nord', 'race_editor_id' => $state === 'werewolf' ? 'WerewolfBeastRace' : 'NordRace',
               'timestamp' => $this->realTs * 1000, 'gamets' => $gamets];
        pg_query_params($this->db->link, "UPDATE core_npc_master SET metadata = COALESCE(metadata, '{}'::jsonb)
            || jsonb_build_object('transformation_state', \$2::jsonb, 'transformation_state_type', \$3::text) WHERE npc_name = \$1",
            [$npc, json_encode($ts), $state]);
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

    // ------------------------------------------------------------------ the story

    public function testAelasBeastBloodFollowsSkyrimsMoonAndTheOthersDoNot(): void
    {
        $beds = array_keys(self::BEDS);
        $this->event('infoloc', self::OUTSIDE, self::at(self::FULL - 1, 11.0), $this->people());
        // Day 213, noon: cycle day 22 (the moon is full from this midday), but the sun is up
        $this->round($beds, 'Good hunting weather.', self::at(self::FULL - 1, 13.0), 'noon');
        $noon = [];
        foreach ($beds as $npc) $noon[$npc] = $this->dynamics($npc);
        $this->assertSame('werewolf', $noon[self::AELA]['_creature']['type']);
        $this->assertSame('faction', $noon[self::AELA]['_creature']['source'], 'CompanionsCircle');
        $this->assertSame('werewolf_day', $noon[self::AELA]['_creature']['state'], 'a full-moon day: the blood only simmers');
        $this->assertSame('full', $noon[self::AELA]['_creature']['moon']);
        $this->assertGreaterThan(0.0, $noon[self::AELA]['_creature']['applied']['arousal']);
        $this->assertLessThan(0.0, $noon[self::AELA]['_creature']['applied']['maturity']);

        // Day 214, 22:00: the full moon is up
        $this->round($beds, 'Look at that moon.', self::at(self::FULL, 22.0), 'full_moon');
        $moon = [];
        foreach ($beds as $npc) $moon[$npc] = $this->dynamics($npc);
        $a = $moon[self::AELA]['_creature'];
        $this->assertSame('werewolf_moon', $a['state']);
        $this->assertGreaterThan($noon[self::AELA]['_creature']['applied']['arousal'], $a['applied']['arousal'], 'the spike');
        $this->assertLessThan($noon[self::AELA]['_creature']['applied']['maturity'], $a['applied']['maturity'], 'beast blood overrides composure');
        $this->assertGreaterThan(0.0, $a['applied']['coord_m'], 'aggressive');
        $this->assertLessThan(0.0, $a['applied']['coord_f'], 'the soft side suppressed');
        // Aela's own fall is fight (RelDynTraits::bleedout net > 0): the moon is a thrill for her
        $probe = $moon[self::AELA];
        $this->assertGreaterThan(0.0, RelationshipDynamics::bleedoutResponse($probe)['net']);
        $this->assertSame('thrill', $a['valence']);
        $this->assertGreaterThan(0.0, $a['applied']['valence']);
        // Maturity is lower under the moon than at noon by the offsets (the day row taken back, the moon's applied)
        $this->assertLessThan(self::x($noon[self::AELA], 'maturity'), self::x($moon[self::AELA], 'maturity'));
        // Felt steering: the moon line, as a feeling
        $felt = $this->felt[self::AELA]['full_moon'];
        $this->assertArrayHasKey('creature', $felt, json_encode($felt));
        $this->assertStringContainsString('full moon sings', $felt['creature']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt['creature']);
        // Jev gets the numbers
        $jev = RelationshipDynamics::jevStateBlock(self::AELA);
        $this->assertSame('werewolf', $jev['creature']['type']);
        $this->assertSame('werewolf_moon', $jev['creature']['state']);
        $this->assertSame('full', $jev['creature']['moon']);
        $this->assertStringContainsString('creature=werewolf(werewolf_moon) moon=full', $jev['text']);

        // The others: not creatures; the moon touches none of them
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertNull($moon[$npc]['_creature']['type'], $npc);
            $this->assertSame([], $moon[$npc]['_creature']['applied'] ?? [], $npc);
            $this->assertArrayNotHasKey('creature', $this->felt[$npc]['full_moon'], $npc);
            $this->assertNull(RelationshipDynamics::jevStateBlock($npc)['creature'], $npc);
        }

        // Held, not re-added: three more turns under the same moon move the creature part nothing
        for ($i = 1; $i <= 3; $i++) $this->turn(self::AELA, 'Stay close tonight.', self::at(self::FULL, 22.0) + $i * 1200, "again{$i}");
        $again = $this->dynamics(self::AELA);
        $this->assertSame($a['applied'], $again['_creature']['applied']);
        $this->assertSame($a['key'], $again['_creature']['key']);

        // Day 220, 22:00 (a waning quarter): another night, half the pull
        $this->round($beds, 'Quiet night.', self::at(self::FULL + 6, 22.0), 'plain_night');
        $plain = $this->dynamics(self::AELA)['_creature'];
        $this->assertSame('werewolf_night', $plain['state']);
        $this->assertSame('waning_quarter', $plain['moon']);
        $this->assertLessThan(abs($a['applied']['maturity']), abs($plain['applied']['maturity']));
        $this->assertLessThan(0.0, $plain['applied']['maturity']);
        $this->assertStringContainsString('beast blood stirs', $this->felt[self::AELA]['plain_night']['creature'] ?? '');
        $this->assertNoFailures();
    }

    public function testBackFromBeastFormAelaTakesTheShameSeenByThePoll(): void
    {
        $beds = array_keys(self::BEDS);
        $this->event('infoloc', self::OUTSIDE, self::at(self::FULL, 20.0), $this->people());
        $this->round($beds, 'Evening.', self::at(self::FULL, 21.0), 'before');
        $before = [];
        foreach ($beds as $npc) $before[$npc] = $this->dynamics($npc);

        // She changes at 23:00; core's poll sees it; she is back in her skin by 23:40, before
        // the next word with her (the latest report the core row keeps is 'normal')
        $this->form(self::AELA, 'werewolf', self::at(self::FULL, 23.0));
        $this->poll(self::at(self::FULL, 23.1));
        $watch = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynCreatures::FORM_WATCH_ROW_ID]), 0, 0), true);
        $this->assertSame('werewolf', $watch['aela the huntress']['state'], 'the poll noticed the change');
        $this->form(self::AELA, 'normal', self::at(self::FULL, 23.67));
        $this->poll(self::at(self::FULL, 23.7));

        $this->round($beds, 'Are you all right?', self::at(self::FULL, 23.8), 'after');
        $after = [];
        foreach ($beds as $npc) $after[$npc] = $this->dynamics($npc);
        $this->assertGreaterThan(self::x($before[self::AELA], 'resentment_self'), self::x($after[self::AELA], 'resentment_self'), 'shame');
        $this->assertEqualsWithDelta(self::at(self::FULL, 23.67), $after[self::AELA]['_creature']['shame_gamets'], 1.0);
        $this->assertNull($after[self::AELA]['_creature']['form']);
        $this->assertStringContainsString('raw after the change', $this->felt[self::AELA]['after']['creature'] ?? '', json_encode($this->felt[self::AELA]['after']));
        $watch = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynCreatures::FORM_WATCH_ROW_ID]), 0, 0), true);
        $this->assertArrayNotHasKey('aela the huntress', $watch, 'the return is consumed');
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertEqualsWithDelta(self::x($before[$npc], 'resentment_self'), self::x($after[$npc], 'resentment_self'), 1e-9, $npc);
            $this->assertArrayNotHasKey('shame_gamets', $after[$npc]['_creature'], $npc);
        }

        // Once: the next turn does not shame her again
        $this->turn(self::AELA, 'Get some sleep.', self::at(self::FULL + 1, 1.0), 'later');
        $this->assertEqualsWithDelta(self::x($after[self::AELA], 'resentment_self'), self::x($this->dynamics(self::AELA), 'resentment_self'), 1e-9);
        $this->assertNoFailures();
    }

    public function testSeranaIsAscendantAtNightAndWornByDay(): void
    {
        $this->event('infoloc', self::OUTSIDE, self::at(self::FULL + 2, 20.0), $this->people());
        $this->turn('Serana', 'Beautiful night.', self::at(self::FULL + 2, 23.0), 'night');
        $night = $this->dynamics('Serana')['_creature'];
        $this->assertSame(['vampire', 'name', 'vampire_night'], [$night['type'], $night['source'], $night['state']]);
        $this->assertGreaterThan(0.0, $night['applied']['self_confidence']);
        $this->assertStringContainsString('vampiric nature is ascendant', $this->felt['Serana']['night']['creature'] ?? '');

        $this->turn('Serana', 'Morning.', self::at(self::FULL + 3, 10.0), 'day');
        $day = $this->dynamics('Serana');
        $this->assertSame('vampire_day', $day['_creature']['state']);
        $this->assertLessThan(0.0, $day['_creature']['applied']['valence']);
        $this->assertLessThan(0.0, $day['_creature']['applied']['comfort']);
        $this->assertArrayNotHasKey('self_confidence', $day['_creature']['applied'], 'the night confidence is taken back');
        $this->assertStringContainsString('the day weighs on Serana', $this->felt['Serana']['day']['creature'] ?? '');
        $this->assertNoFailures();
    }
}
