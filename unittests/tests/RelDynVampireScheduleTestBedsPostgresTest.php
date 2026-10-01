<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynVampireScheduleBedsPgDb
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
 * The vampire's inverted schedule, thirst and direct sunlight (Ken 2026-10-01 §21) on a real PostgreSQL, through
 * the real hooks: Serana (a vampire by script) and Harkon (a man, a vampire by faction) beside the four test
 * beds (Aela the Huntress, Ashe with Serene's hand-set vector, Muiri, Lynly Star-Sung), CHIM 3.4.1 core-shaped
 * rows, no LLM call. Cranky from dawn to dusk wherever they are, worse unfed, worse again in the sun; the
 * thirst grows per game day and a night kill (the default feeding signal) sates it; a feeding mod is wired in
 * by config. Nobody else is touched.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynVampireScheduleTestBedsPostgresTest extends TestCase
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
        'Serana'            => ['serana', 'NordRace', 'Vampire', ['DLC1SeranaFaction', 'DLC1SeranaCrimeFaction', 'CurrentFollowerFaction'],
                                ['destruction' => 50, 'conjuration' => 45, 'sneak' => 40], null, 'female'],
        'Harkon'            => ['harkon', 'NordRace', 'Warrior', ['DLC1VampireFaction'],
                                ['onehanded' => 60, 'destruction' => 55, 'speech' => 50], null, 'male'],
    ];
    private const VAMPIRES = ['Serana', 'Harkon'];
    private const OUTSIDE = '(Context location: Whiterun outdoors ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ..., current weather: outdoors it is Pleasant)';
    private const OVERCAST = '(Context location: Whiterun outdoors ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ..., current weather: outdoors it is Cloudy)';
    private const INSIDE = '(Context location: Volkihar Keep ,Hold: Haafingar, Buildings to go:, Current Date in Skyrim World: ..., current weather: indoors)';

    private string $dsn;
    private string $schema;
    private ?RelDynVampireScheduleBedsPgDb $db = null;
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdvamp');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_vampire_beds_test.log');
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
        $this->schema = 'reldyn_vamp_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynVampireScheduleBedsPgDb($this->dsn, $this->schema);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;
        RelationshipDynamics::endRequest();
        RelDynTraitRead::reset();
        $this->storeConfig([]);

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
            if (!isset($seed['reads'][$key])) continue;   // Ashe: Serene's hand-set vector, never read; Serana and Harkon: no seed
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode(['level' => 20])]);
    }

    /** The stored config row: the shipped defaults with $over laid over (replacing whole top-level keys). */
    private function storeConfig(array $over): void
    {
        pg_query_params($this->db->link, 'DELETE FROM conf_opts WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID]);
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $over))]);
        RelationshipDynamics::clearConfigCache();
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

    private function creature(string $npc): array
    {
        return $this->dynamics($npc)['_creature'] ?? [];
    }

    private function assertNoFailures(): void
    {
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertStringNotContainsString('ERROR', (string) file_get_contents($this->errorLog));
    }

    // ------------------------------------------------------------------ the inverted schedule

    public function testTheVampiresAreCrankyFromDawnToDuskWhereverTheyAreAndAscendantAfterDusk(): void
    {
        $npcs = array_keys(self::BEDS);
        $d = 60;
        // 19:00 and 5:30 were "day" under the old 20:00-5:00 night; now they are the night
        $this->event('infoloc', self::INSIDE, self::at($d, 4.0), $this->people());
        $this->round($npcs, 'You are up late.', self::at($d, 5.5), 'dawn');
        foreach (self::VAMPIRES as $v) {
            $this->assertSame('vampire_night', $this->creature($v)['state'], "{$v} at 5:30");
            $this->assertFalse($this->creature($v)['sun']);
        }
        // Mid-morning, indoors in a keep: the day still weighs, wherever they are
        $this->round($npcs, 'Good morning.', self::at($d, 10.0), 'morning');
        $morning = [];
        foreach (self::VAMPIRES as $v) {
            $morning[$v] = $this->creature($v);
            $this->assertSame('vampire_day', $morning[$v]['state'], "{$v} indoors by day");
            $this->assertFalse($morning[$v]['sun'], "{$v} is under a roof");
            $this->assertLessThan(0.0, $morning[$v]['applied']['valence'], $v);
            $this->assertStringContainsString("the day weighs on {$v}", $this->felt[$v]['morning']['creature'] ?? '');
            $this->assertStringNotContainsString('open sun', $this->felt[$v]['morning']['creature'] ?? '');
        }
        // After dusk (19:00): the night row, self-confidence back up
        $this->round($npcs, 'Evening.', self::at($d, 19.0), 'dusk');
        foreach (self::VAMPIRES as $v) {
            $this->assertSame('vampire_night', $this->creature($v)['state'], "{$v} at 19:00");
            $this->assertGreaterThan(0.0, $this->creature($v)['applied']['self_confidence'], $v);
            $this->assertGreaterThan($morning[$v]['applied']['valence'] + 5.0, $this->creature($v)['applied']['valence'] ?? 0.0, "{$v}: the day's valence is taken back");
        }
        // Everyone else: no creature, no thirst, nothing of the vampire's schedule
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $c = $this->creature($npc);
            $this->assertNull($c['type'] ?? null, $npc);
            $this->assertArrayNotHasKey('thirst', $c, $npc);
            $this->assertFalse($c['sun'] ?? false, $npc);
        }
        $this->assertNoFailures();
    }

    public function testTheWerewolfKeepsItsOwnTwentyToFiveNightAndGainsNoThirst(): void
    {
        $this->event('infoloc', self::OUTSIDE, self::at(61, 17.0), $this->people());
        $this->turn(self::AELA, 'Hello.', self::at(61, 19.0), 'seven');
        $this->assertSame('werewolf', $this->creature(self::AELA)['type']);
        $this->assertSame('werewolf_day', $this->creature(self::AELA)['state'], '19:00 is still the werewolf day (the 18:00 dusk belongs to the vampires)');
        $this->turn(self::AELA, 'Hello again.', self::at(61, 21.0), 'nine');
        $this->assertContains($this->creature(self::AELA)['state'], ['werewolf_night', 'werewolf_moon']);
        $this->assertArrayNotHasKey('thirst', $this->creature(self::AELA));
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ sunlight

    public function testDirectSunlightMakesTheDayWorseOnlyOutdoorsUnderAClearSky(): void
    {
        $npcs = self::VAMPIRES;
        $noon = self::at(62, 12.0);
        $this->event('infoloc', self::INSIDE, $noon - 1000, $this->people());
        $this->round($npcs, 'Inside.', $noon, 'inside');
        $inside = [];
        foreach ($npcs as $v) $inside[$v] = $this->creature($v)['applied'];

        $this->event('infoloc', self::OUTSIDE, $noon + (int) self::HOUR - 1000, $this->people());
        $this->round($npcs, 'Out in the open.', $noon + (int) self::HOUR, 'sun');
        foreach ($npcs as $v) {
            $c = $this->creature($v);
            $this->assertTrue($c['sun'], "{$v} in direct sunlight");
            $this->assertSame('vampire_day', $c['state']);
            $this->assertLessThan($inside[$v]['comfort'], $c['applied']['comfort'], "{$v}: the sun is worse than the day");
            $this->assertLessThan($inside[$v]['valence'], $c['applied']['valence'], $v);
            $this->assertStringContainsString('the open sun is worse', $this->felt[$v]['sun']['creature'] ?? '');
        }

        // Cloud over: the sun is no longer direct
        $this->event('infoloc', self::OVERCAST, $noon + 2 * (int) self::HOUR - 1000, $this->people());
        $this->round($npcs, 'Clouds.', $noon + 2 * (int) self::HOUR, 'cloud');
        foreach ($npcs as $v) {
            $this->assertFalse($this->creature($v)['sun'], $v);
            $this->assertEqualsWithDelta($inside[$v]['comfort'], $this->creature($v)['applied']['comfort'], 1e-9, "{$v}: back to the plain day");
        }
        // Dusk, clear sky outdoors: the sun is down for them (their night)
        $this->event('infoloc', self::OUTSIDE, self::at(62, 18.5) - 1000, $this->people());
        $this->round($npcs, 'Dusk.', self::at(62, 18.5), 'dusk');
        foreach ($npcs as $v) {
            $this->assertFalse($this->creature($v)['sun'], $v);
            $this->assertSame('vampire_night', $this->creature($v)['state'], $v);
        }
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ thirst

    public function testThirstGrowsPerGameDayAndANightKillSatesIt(): void
    {
        $npcs = array_keys(self::BEDS);
        $t0 = self::at(70, 10.0);
        $this->event('infoloc', self::INSIDE, $t0 - 1000, $this->people());
        $this->round($npcs, 'Morning.', $t0, 'fed');
        $day0 = [];
        foreach (self::VAMPIRES as $v) {
            $c = $this->creature($v);
            $this->assertSame(0.0, floatval($c['thirst_level']), "{$v}: first sight is fed");
            $day0[$v] = $c['applied'];
        }

        // Two game days later, same hour: no feeding seen, half a thirst, the day is worse
        $t2 = $t0 + 2 * self::DAY;
        $this->round($npcs, 'Two days on.', $t2, 'thirsty');
        $thirsty = [];
        foreach (self::VAMPIRES as $v) {
            $c = $this->creature($v);
            $thirsty[$v] = $c['applied'];
            $this->assertEqualsWithDelta(0.5, floatval($c['thirst_level']), 1e-9, $v);
            $this->assertLessThan($day0[$v]['comfort'], $c['applied']['comfort'], "{$v}: unfed makes the day worse");
            $this->assertLessThan($day0[$v]['valence'], $c['applied']['valence'], $v);
            $this->assertArrayHasKey('arousal', $c['applied'], "{$v}: unfed has an edge");
            $this->assertStringContainsString("too long since {$v} fed", $this->felt[$v]['thirsty']['creature'] ?? '', $v);
        }
        // Who they are scales it: the same thirst does not move both the same
        $this->assertNotEquals(round($thirsty['Serana']['comfort'], 3), round($thirsty['Harkon']['comfort'], 3), 'two vampires, two reactions');
        // The others have no thirst at all
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung', self::AELA] as $npc) $this->assertArrayNotHasKey('thirst', $this->creature($npc), $npc);

        // Serana fights and kills that night (22:00); Harkon does not; someone else's kill is not Serana's
        $this->event('death', 'Serana has defeated Bandit Chief with Dagger', self::at(72, 22.0), $this->people());
        $this->event('death', 'Aela the Huntress has defeated Wolf', self::at(72, 22.5), $this->people());
        $this->round($npcs, 'Quiet night.', self::at(72, 23.0), 'sated');
        $this->assertSame(0.0, floatval($this->creature('Serana')['thirst_level']), 'sated by the kill');
        $this->assertEqualsWithDelta(self::at(72, 22.0), $this->creature('Serana')['thirst']['fed_at'], 1.0);
        $this->assertGreaterThan(0.0, floatval($this->creature('Harkon')['thirst_level']), 'Harkon did not feed');
        $this->assertSame('vampire_night', $this->creature('Harkon')['state']);
        $this->assertGreaterThan(0.0, floatval($this->creature('Serana')['applied']['self_confidence']));
        $this->assertNotContains('thirst', array_keys($this->creature(self::AELA)), 'a kill does not give the werewolf a thirst');

        // A kill in the middle of the day does not feed (the proxy is a NIGHT kill)
        $this->event('death', 'Harkon has defeated Skeever', self::at(73, 12.0), $this->people());
        $this->round($npcs, 'Noon.', self::at(73, 13.0), 'daykill');
        $this->assertGreaterThan(0.0, floatval($this->creature('Harkon')['thirst_level']), 'a daytime kill is not a feeding');
        $this->assertLessThan(self::at(73, 12.0), $this->creature('Harkon')['thirst']['fed_at']);

        // Days on without feeding: a full thirst, and no more
        $this->round($npcs, 'Long days.', self::at(78, 13.0), 'ravenous');
        $this->assertEqualsWithDelta(1.0, floatval($this->creature('Harkon')['thirst_level']), 1e-9);
        $this->assertNoFailures();
    }

    public function testAFeedingModIsWiredInByConfigAlone(): void
    {
        $npcs = self::VAMPIRES;
        $t0 = self::at(80, 10.0);
        $this->event('infoloc', self::INSIDE, $t0 - 1000, $this->people());
        $this->round($npcs, 'Morning.', $t0, 'fed');
        // The mod logs "<NAME> drinks deeply from <victim>" as an infoaction, at any hour; the default (a night kill) is replaced by it
        $this->storeConfig(['creatures' => array_replace(RelDynCreatures::configDefaults(), ['vampire' => array_replace(RelDynCreatures::configDefaults()['vampire'], [
            'thirst' => array_replace(RelDynCreatures::configDefaults()['vampire']['thirst'], ['signals' => [
                ['name' => 'feeding_mod', 'event_types' => ['infoaction'], 'match' => '{NAME} drinks deeply from'],
            ]])])])]);
        $this->round($npcs, 'Two days on.', $t0 + 2 * self::DAY, 'thirsty');
        foreach ($npcs as $v) $this->assertEqualsWithDelta(0.5, floatval($this->creature($v)['thirst_level']), 1e-9, $v);
        // a night kill no longer counts; the mod's event does, by day too
        $this->event('death', 'Serana has defeated Bandit', self::at(82, 21.0), $this->people());
        $this->event('infoaction', 'Harkon drinks deeply from a captive thrall', self::at(82, 14.0), $this->people());
        $this->round($npcs, 'Afternoon.', self::at(82, 15.0), 'fed again');
        $this->assertGreaterThan(0.0, floatval($this->creature('Serana')['thirst_level']), 'the default signal is gone');
        $this->assertSame(0.0, floatval($this->creature('Harkon')['thirst_level']), 'sated by the mod\'s own event');
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ words

    public function testTheFeltLinesSayWhoNotHeOrShe(): void
    {
        $npcs = self::VAMPIRES;
        $noon = self::at(90, 12.0);
        $this->event('infoloc', self::OUTSIDE, $noon - 4 * self::DAY, $this->people());
        $this->round($npcs, 'Morning.', $noon - 4 * self::DAY, 'first');
        $this->event('infoloc', self::OUTSIDE, $noon - 1000, $this->people());
        $this->round($npcs, 'Four days on, in the sun.', $noon, 'worst');
        foreach ($npcs as $v) {
            $line = $this->felt[$v]['worst']['creature'] ?? '';
            $this->assertStringContainsString("the day weighs on {$v}", $line);
            $this->assertStringContainsString('open sun', $line);
            $this->assertStringContainsString("{$v} fed", $line);
            $this->assertSame(0, preg_match('/\b(she|her|hers|he|his|him|herself|himself)\b/i', $line), "{$v}: no gendered pronoun in: {$line}");
            // a full thirst in the sun is the worst a day gets
            $c = $this->creature($v);
            $this->assertEqualsWithDelta(1.0, floatval($c['thirst_level']), 1e-9, $v);
            $this->assertTrue($c['sun'], $v);
        }
        $this->assertNoFailures();
    }
}
