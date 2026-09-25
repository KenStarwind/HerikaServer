<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCombatBedsPgDb
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
 * combat-passion end to end on the four test beds (Aela the Huntress, Ashe with Serene's hand-set
 * vector, Muiri, Lynly Star-Sung) through the real hooks (prerequest -> context -> postrequest),
 * CHIM 3.4.1 core-shaped rows on a real PostgreSQL, no LLM call.
 *
 * Core's own signals only (no MinAI): main.php logs 'death' and 'bleedout' and ends the request
 * before any ext hook, so RelDyn reads those eventlog rows on its next turn (RelDynCombat::
 * consumeEventlog, once each); core's combat bark ("(X shouts during combat)") says who fought;
 * the RPG combat end reaches postrequest. Who the NPC is decides what a fight does to her:
 * Aela, a hunter of the Circle, is fed by it and her fall is fight; Lynly, a shy bard, falls in
 * fear. The glow after a fight lasts five minutes of play, not forever.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCombatTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const AELA = 'Aela the Huntress';
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
    private ?RelDynCombatBedsPgDb $db = null;
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdcombat');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_combat_beds_test.log');
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
        $this->schema = 'reldyn_combat_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynCombatBedsPgDb($this->dsn, $this->schema);
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

    /** A core combat request (the RPG combat end) through the real hooks, voiced by $speaker. */
    private function combatRequest(string $type, string $data, int $gamets, string $speaker, string $people): void
    {
        $this->event($type, $data, $gamets, $people);
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = [$type, (string) $this->realTs, (string) $gamets, $data];
            $GLOBALS['HERIKA_NAME'] = $speaker;
            $GLOBALS['RELDYN_NPC_NAME'] = $speaker;
            $GLOBALS['CACHE_PEOPLE'] = $people;
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    private function bark(string $npc, int $gamets): void
    {
        $this->event('infoaction', self::PLAYER . ": Behind you! ({$npc} shouts during combat)", $gamets, $this->people());
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private static function combatSource(array $d): float
    {
        return floatval($d['passion_sources']['combat'] ?? 0);
    }

    /** npc => [gain, fought, witness] of each "COMBAT EVENT" log line of $type, in order. */
    private function combatEvents(string $type): array
    {
        preg_match_all('/COMBAT EVENT: (.+?) type=' . preg_quote($type, '/') . ' gain=(-?[\d.]+) passion=[-\d.]+( \[FOUGHT\])?( \[WITNESS\])?/',
            $this->log(), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) $out[$row[1]][] = ['gain' => floatval($row[2]), 'fought' => !empty($row[3]), 'witness' => !empty($row[4])];
        return $out;
    }

    /** npc => the first logged appraisal of a shared fight ["valence", "dominant"]. */
    private function appraisals(): array
    {
        preg_match_all('/Combat appraisal: (.+?) valence=([+-][\d.]+) dominant=(\S+)/', $this->log(), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) $out[$row[1]] ??= ['valence' => floatval($row[2]), 'dominant' => $row[3]];
        return $out;
    }

    /** The logged falls of $npc (RelDynCombat::route's "Bleedout:" line): [passion, valence] each. */
    private function falls(string $npc): array
    {
        preg_match_all('/Bleedout: ' . preg_quote($npc, '/') . ' fight=\S+ fear=\S+ passion=([+-][\d.]+) valence=([+-][\d.]+)/',
            $this->log(), $m, PREG_SET_ORDER);
        return array_map(fn($r) => [floatval($r[1]), floatval($r[2])], $m);
    }

    // ------------------------------------------------------------------ the fights

    public function testCoresDeathAndFallRowsReachTheBedsOnceOnTheNextTurn(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(100, 12.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        $before = [];
        foreach ($beds as $npc) $before[$npc] = $this->dynamics($npc);

        // A fight two game hours later, logged by core only (main.php ends death / bleedout
        // requests before any ext hook; Papyrus RecoverFromCombat reports the same fall again)
        $t1 = self::at(100, 14.0);
        $this->bark(self::AELA, $t1);
        $this->event('death', 'Aela the Huntress has defeated Bandit Chief', $t1 + 20000, $this->people());
        $this->event('bleedout', 'Lynly Star-Sung falls to the ground almost unconscious', $t1 + 40000, $this->people());
        $this->event('instruction', 'Lynly Star-Sung has lost combat and is wounded bleedingout.', $t1 + 41000, $this->people());
        $this->event('bleedout', 'Aela the Huntress falls to the ground almost unconscious', $t1 + 60000, $this->people());
        // The player's own fall is nobody else's fall
        $this->event('bleedout', 'Kaida falls to the ground almost unconscious', $t1 + 70000, $this->people());
        $this->assertSame([], $this->combatEvents('death'), 'nothing moves before RelDyn runs');

        // The next turn (to Ashe) routes them, before Ashe's own dynamics load
        $this->turn('Ashe', 'Is everyone alive?', $t1 + 120000, 'after');
        $after = [];
        foreach ($beds as $npc) $after[$npc] = $this->dynamics($npc);

        // The kill: Aela made it and fought (her bark); the others saw it, at half credit
        $deaths = $this->combatEvents('death');
        $this->assertCount(1, $deaths[self::AELA] ?? [], json_encode($deaths));
        $this->assertTrue($deaths[self::AELA][0]['fought']);
        $this->assertFalse($deaths[self::AELA][0]['witness']);
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertCount(1, $deaths[$npc] ?? [], $npc . ' ' . json_encode($deaths));
            $this->assertTrue($deaths[$npc][0]['witness'], $npc);
            $this->assertFalse($deaths[$npc][0]['fought'], "{$npc} only saw it");
            $this->assertGreaterThan($deaths[$npc][0]['gain'], $deaths[self::AELA][0]['gain'], "the killer gains more than {$npc}");
        }
        $this->assertStringContainsString('DEATH WITNESSES: ', $this->log());
        $this->assertGreaterThan(self::combatSource($before[self::AELA]), self::combatSource($after[self::AELA]));
        $this->assertGreaterThan(self::combatSource($before['Ashe']), self::combatSource($after['Ashe']));

        // The falls are who they are: Aela's is fight (valence up), Lynly's fear (valence down);
        // Lynly's fall reported twice (bleedout + the instruction) counts once
        $aelaFalls = $this->falls(self::AELA);
        $lynlyFalls = $this->falls('Lynly Star-Sung');
        $this->assertCount(1, $aelaFalls);
        $this->assertCount(1, $lynlyFalls, 'one fall, two reports');
        $this->assertGreaterThan(0.0, $aelaFalls[0][1], 'Aela: rage, not panic');
        $this->assertLessThan(0.0, $lynlyFalls[0][1], 'Lynly: fear');
        $this->assertGreaterThanOrEqual(0.0, $aelaFalls[0][0]);
        $this->assertLessThanOrEqual(0.0, $lynlyFalls[0][0]);
        $probeA = $after[self::AELA];
        $probeL = $after['Lynly Star-Sung'];
        $this->assertGreaterThan(0.0, RelationshipDynamics::bleedoutResponse($probeA)['net']);
        $this->assertLessThan(0.0, RelationshipDynamics::bleedoutResponse($probeL)['net']);
        $this->assertEqualsWithDelta($t1 + 40000, $after['Lynly Star-Sung']['_combat_last_fall_gamets'], 1.0);
        foreach (['Ashe', 'Muiri'] as $npc) {
            $this->assertSame([], $this->falls($npc), $npc);
            $this->assertArrayNotHasKey('_combat_last_fall_gamets', $after[$npc], $npc);
        }

        // Once: later turns route nothing again
        $events = substr_count($this->log(), 'COMBAT EVENT: ');
        $this->round(['Muiri', self::AELA], 'Let us move on.', $t1 + 200000, 'later');
        $this->assertSame($events, substr_count($this->log(), 'COMBAT EVENT: '));
        $this->assertSame(self::combatSource($after['Ashe']), self::combatSource($this->dynamics('Ashe')));
        $this->assertSame(self::combatSource($after['Lynly Star-Sung']), self::combatSource($this->dynamics('Lynly Star-Sung')));
        $mark = json_decode((string) pg_fetch_result(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelDynCombat::WATERMARK_ROW_ID]), 0, 0), true);
        $newest = intval(pg_fetch_result(pg_query($this->db->link, 'SELECT max(rowid) FROM eventlog'), 0, 0));
        $this->assertGreaterThanOrEqual($newest - 2, intval($mark['rowid']), 'the watermark follows the eventlog');
        $this->assertNoFailures();
    }

    public function testCombatEndCreditsWhoFoughtAndWhoTheyAre(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(120, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Draw steel.', $t0, 'before');

        // All four fight (each barks), then core's RPG combat end reaches postrequest
        $t1 = self::at(120, 12.0);
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $t1 + 30000, self::AELA, $this->people());
        $ends = $this->combatEvents('combatend');
        foreach ($beds as $npc) {
            $this->assertCount(1, $ends[$npc] ?? [], $npc . ' ' . json_encode($ends));
            $this->assertTrue($ends[$npc][0]['fought'], $npc);
        }
        // The same fight side by side, appraised by who each is (MDD 1.2, decisions §6): the
        // hunter of the Circle loves it, the toxic apothecary hates it, the bard and the
        // sorceress hardly care
        $appraisal = $this->appraisals();
        $this->assertSame('combat', $appraisal[self::AELA]['dominant']);
        $this->assertGreaterThan(0.3, $appraisal[self::AELA]['valence']);
        $this->assertLessThan(0.0, $appraisal['Muiri']['valence']);
        foreach (['Ashe', 'Lynly Star-Sung'] as $npc) {
            $this->assertLessThan($appraisal[self::AELA]['valence'], $appraisal[$npc]['valence'], $npc);
            $this->assertGreaterThan($appraisal['Muiri']['valence'], $appraisal[$npc]['valence'], $npc);
        }
        $this->assertGreaterThan($ends['Ashe'][0]['gain'], $ends[self::AELA][0]['gain'], json_encode($ends));

        // Two game hours later only Aela barks: only she fought (a bystander is not "confirmed")
        $t2 = self::at(120, 14.0);
        $this->bark(self::AELA, $t2);
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $t2 + 30000, 'Lynly Star-Sung', $this->people());
        $ends = $this->combatEvents('combatend');
        $this->assertTrue($ends[self::AELA][1]['fought']);
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertCount(2, $ends[$npc], $npc);
            $this->assertFalse($ends[$npc][1]['fought'], "{$npc} stood by");
        }
        $this->assertNoFailures();
    }

    public function testKillStreakWithinFiveMinutesOfPlay(): void
    {
        $t0 = self::at(130, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round(array_keys(self::BEDS), 'Wolves.', $t0, 'before');

        $t1 = self::at(130, 12.0);
        $this->bark(self::AELA, $t1);
        foreach ([1, 2, 3] as $k) $this->event('death', "Aela the Huntress has defeated Wolf {$k}", $t1 + 30000 * $k, $this->people());
        // A fourth kill two game hours later, far past the five-minute window, stands alone
        $late = $t1 + 2 * (int) self::HOUR;
        $this->event('death', 'Aela the Huntress has defeated Wolf 4', $late, $this->people());
        $this->turn('Muiri', 'Is it over?', $late + 60000, 'after');

        $deaths = $this->combatEvents('death');
        $this->assertCount(4, $deaths[self::AELA]);
        // kills 2 and 3 add +0.5 and +1.0 (0.5 per extra kill, cap 2.0); kill 4 has none
        $log = $this->log();
        $this->assertStringContainsString('Kill streak bonus: +0.5 (2 kills)', $log);
        $this->assertStringContainsString('Kill streak bonus: +1 (3 kills)', $log);
        $this->assertStringNotContainsString('(4 kills)', $log);
        $this->assertGreaterThan($deaths[self::AELA][0]['gain'], $deaths[self::AELA][2]['gain'], 'the third kill of a streak moves her more');
        $this->assertGreaterThan($deaths[self::AELA][3]['gain'], $deaths[self::AELA][2]['gain'], 'a lone kill has no streak');
        $this->assertNoFailures();
    }

    public function testThePostCombatGlowFadesAfterFiveMinutesOfPlay(): void
    {
        $t0 = self::at(140, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round(array_keys(self::BEDS), 'Hello.', $t0, 'before');

        // Aela fights beside the player; Lynly is not there (not in the rows' people)
        $t1 = self::at(140, 12.0);
        $there = '|' . self::AELA . '|' . self::PLAYER . '|';
        $this->event('infoaction', self::PLAYER . ': Now! (Aela the Huntress shouts during combat)', $t1, $there);
        $this->event('death', 'Aela the Huntress has defeated Draugr', $t1 + 20000, $there);
        $this->event('combatend', '(Context location: Whiterun outdoors)', $t1 + 40000, $there);

        $GLOBALS['gameRequest'] = ['inputtext', '0', (string) ($t1 + 100000), 'probe'];
        $this->assertNotNull(RelationshipDynamics::getRecentCombatSummary(self::AELA), 'fresh from the fight');
        $this->assertNull(RelationshipDynamics::getRecentCombatSummary('Lynly Star-Sung'), 'she was not there');
        $GLOBALS['gameRequest'] = ['inputtext', '0', (string) ($t1 + 40000 + RelationshipDynamics::POST_COMBAT_GLOW_GAMETS + 1000), 'probe'];
        $this->assertNull(RelationshipDynamics::getRecentCombatSummary(self::AELA), 'five minutes of play later the glow is gone');
        unset($GLOBALS['gameRequest']);

        // Felt: the glow shows on the turn after the fight, not hours later, not for Lynly
        $this->turn(self::AELA, 'Good fight.', $t1 + 100000, 'glow');
        $this->assertArrayHasKey('post_combat', $this->felt[self::AELA]['glow'], json_encode($this->felt[self::AELA]['glow']));
        $this->assertDoesNotMatchRegularExpression('/\d/', $this->felt[self::AELA]['glow']['post_combat']);
        $this->turn(self::AELA, 'Onward.', $t1 + 3 * (int) self::HOUR, 'later');
        $this->assertArrayNotHasKey('post_combat', $this->felt[self::AELA]['later']);
        $this->turn('Lynly Star-Sung', 'Did I miss something?', $t1 + 100600, 'glow');
        $this->assertArrayNotHasKey('post_combat', $this->felt['Lynly Star-Sung']['glow']);
        $this->assertNoFailures();
    }

    /** The plugin's live stats report for an NPC (gamedata.php 'stats' -> core_npc_master.metadata.stats). */
    private function stats(string $npc, float $health, float $max): void
    {
        $stats = ['level' => 20, 'health' => $health, 'health_max' => $max, 'magicka' => 100.0, 'magicka_max' => 100.0,
                  'stamina' => 100.0, 'stamina_max' => 100.0, 'scale' => 1.0];
        pg_query_params($this->db->link, "UPDATE core_npc_master SET metadata = COALESCE(metadata, '{}'::jsonb)
            || jsonb_build_object('stats', \$2::jsonb) WHERE npc_name = \$1", [$npc, json_encode($stats)]);
    }

    public function testSharedDangerReadsLiveHealthAndWhoTheNpcIs(): void
    {
        $t0 = self::at(150, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round(array_keys(self::BEDS), 'Careful.', $t0, 'before');
        $thr = [];
        foreach ([self::AELA, 'Muiri'] as $npc) {
            $d = $this->dynamics($npc);
            $thr[$npc] = RelDynCombat::dangerThreshold(floatval(RelDynFacets::preferences($d, $npc)['combat'] ?? 0.0));
        }
        // MDD 3.3: the more she loves a fight, the nearer death it takes to feel it as danger
        $this->assertGreaterThan($thr[self::AELA], $thr['Muiri']);
        $mid = ($thr[self::AELA] + $thr['Muiri']) / 2;

        // Both at the same live HP (the plugin's stats report), between the two thresholds; Lynly
        // fights with no report at all: unknown, never danger
        $t1 = self::at(150, 12.0);
        $this->bark(self::AELA, $t1);
        $this->bark('Muiri', $t1 + 1000);
        $this->bark('Lynly Star-Sung', $t1 + 2000);
        $this->stats(self::AELA, 200 * $mid, 200);
        $this->stats('Muiri', 100 * $mid, 100);
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $t1 + 30000, 'Muiri', $this->people());
        $log = $this->log();
        $this->assertStringContainsString('Shared danger: Muiri', $log);
        $this->assertStringNotContainsString('Shared danger: Aela the Huntress', $log);
        $this->assertStringNotContainsString('Shared danger: Lynly', $log);

        // Nearer death, the hunter feels it too
        $t2 = self::at(150, 14.0);
        $this->bark(self::AELA, $t2);
        $this->stats(self::AELA, 200 * $thr[self::AELA] * 0.5, 200);
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $t2 + 30000, self::AELA, $this->people());
        $this->assertStringContainsString('Shared danger: Aela the Huntress', $this->log());

        // A core row routed two game hours later: her HP now is not her HP then
        $n = substr_count($this->log(), 'Shared danger: ');
        $t3 = self::at(150, 16.0);
        $this->bark('Muiri', $t3);
        $this->event('death', 'Muiri has defeated Skeever', $t3 + 20000, $this->people());
        $this->turn('Ashe', 'Moving on.', $t3 + 2 * (int) self::HOUR, 'later');
        $deaths = $this->combatEvents('death');
        $this->assertCount(1, $deaths['Muiri'] ?? [], json_encode($deaths));
        $this->assertTrue($deaths['Muiri'][0]['fought']);
        $this->assertSame($n, substr_count($this->log(), 'Shared danger: '));
        $this->assertNoFailures();
    }

    public function testMidFightFeltReadsHerLiveWounds(): void
    {
        $t0 = self::at(160, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round(array_keys(self::BEDS), 'Ready.', $t0, 'before');

        // Mid-fight (barks within the last real minute of play): Aela's live report says 20%;
        // Lynly has none (unknown: she just fights)
        $t1 = self::at(160, 12.0);
        $this->bark(self::AELA, $t1);
        $this->bark('Lynly Star-Sung', $t1 + 1000);
        $this->stats(self::AELA, 40.0, 200.0);
        $this->turn(self::AELA, 'Hold on!', $t1 + 20000, 'fight');
        $this->turn('Lynly Star-Sung', 'Stay behind me!', $t1 + 21000, 'fight');
        $this->assertStringContainsString('badly hurt and still fighting', $this->felt[self::AELA]['fight']['combat'] ?? '', json_encode($this->felt[self::AELA]['fight']));
        $this->assertStringContainsString('fights beside', $this->felt['Lynly Star-Sung']['fight']['combat'] ?? '', json_encode($this->felt['Lynly Star-Sung']['fight']));
        $this->assertDoesNotMatchRegularExpression('/\d/', $this->felt[self::AELA]['fight']['combat']);
        $this->assertNoFailures();
    }
}
