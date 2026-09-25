<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynTraitBedsPgDb
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
 * Personality traits phase 2, the four test beds end to end (task 6; decisions §16): Aela the
 * Huntress, Ashe, Muiri and Lynly Star-Sung met through the real hooks (prerequest -> context
 * -> postrequest) on a real PostgreSQL, from CHIM 3.4.1 core-shaped rows only (class, factions,
 * skills, race; npc_templates_v2 voice types; bio templates) and the committed seed's reads.
 *
 * The seed was read from the live bio templates; this test does not commit bios, so each read
 * NPC's template here holds placeholder text and its reldyn_trait_reads row is keyed to that
 * text's hash with the seed's own result (quotes, values, confidences). Ashe is skip-listed:
 * she has a template, is never queued or read, and is her hand-set conclusion. No LLM call.
 *
 * What the reads give (the report, D:\docs\reldyn-trait-read-report.md, has the numbers):
 *   - Aela: the most confident; low possessiveness; resists collapse (Y_down < 1); openness
 *     medium from her traits (the won-over switch is on; ruling #8 itself is tested on her
 *     read in RelDynAttractionUphillPostgresTest::testRulingEightUnderTheReadAssignment).
 *   - Muiri: the most reactive (her read), with the largest swings of the trait-derived
 *     maturity types (Adaptive-leaning, both ways over 1; Lynly's Bard class rule makes hers
 *     Volatile and larger). Her low confidence (fearful-leaning) and top jealousy come from her
 *     PRIORS (YoungEager voice, the race pull, the base possessiveness of low confidence), not
 *     from her bio; the ten traits have no vengeance.
 *   - Ashe: exactly Serene's hand-set vector (Stoic-leaning, Resilient, maturity 75,
 *     romance momentum 1.6), the most guarded and the least open.
 *   - Lynly: the most resilient of the three reads ("remarkable resilience"). The
 *     shy / anxious / masking profile is NOT reached by her v1 read: its guard and
 *     expressiveness quotes were rejected (over 12 words), so her Bard prior leaves her open
 *     and expressive. Flagged in the report with the prompt fix that would change it.
 * All four are clearly apart (pairwise trait distance >= 0.25).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynTraitTestBedsPostgresTest extends TestCase
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
    private RelDynTraitBedsPgDb $db;
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
        $this->schema = 'reldyn_beds' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
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

        $this->db = new RelDynTraitBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_trait_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };

        // Shipped defaults, stored as the config page stores them (traits.assignment 'read')
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seed();
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

    /** Core rows, voice types, placeholder templates, and the seed's reads keyed to them. */
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
                     'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'platonic']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;
            $e = $seed['reads'][$key];
            $this->assertSame('done', $e['status'], $key);
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
    }

    /** One player line to $npc through the real hooks: prerequest, context, postrequest. */
    private function turn(string $npc, string $line): void
    {
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

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** The four meet the player at Jorrvaskr: two lines each through the real hooks. */
    private function meetAll(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['infoloc', '(Context location: Jorrvaskr ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Morndas, 10:00 AM, 17th of Last Seed, 4E 201, current weather: Pleasant)',
             'pending', (int) $this->gamets, $this->realTs, (int) $this->gamets, '|' . implode('|', array_keys(self::BEDS)) . '|', '']);
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met. Have a moment?');
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'How have you been keeping?');
    }

    private function npcId(string $npc): int
    {
        return intval(pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', [$npc]))['id']);
    }

    public function testTheFourTestBedsDivergeThroughTheRealHooks(): void
    {
        $this->meetAll();

        $seed = RelDynTraitRead::loadSeedFile();
        $d = [];
        $x = [];
        foreach (self::BEDS as $npc => [$key]) {
            $d[$npc] = $this->dynamics($npc);
            $src = $d[$npc]['_trait_vector_src'] ?? null;
            $this->assertIsArray($src, "{$npc}: resolved through the hooks");
            $this->assertSame('read', $src['assignment'], $npc);
            $x[$npc] = RelDynTraits::readVector($d[$npc]);
            $this->assertNotNull($x[$npc], $npc);
            $this->assertSame($d[$npc]['trait_preset']['nearest'], $d[$npc]['inferred_temperament'], "{$npc}: the label is the nearest preset");
            if ($key === 'ashe') continue;
            $this->assertSame('done', $src['read_status'], $npc);
            $this->assertSame('read', $src['auto_source'], $npc);
            $this->assertStringStartsWith('seed:', (string) $src['model'], $npc);
            foreach ($seed['reads'][$key]['result']['traits'] as $name => $t) {
                if ($t['conf'] > 0) $this->assertSame($t['evidence'], $src['traits'][$name]['evidence'] ?? null, "{$npc}.{$name}: the seed's quote");
            }
            $this->assertEqualsWithDelta(floatval($x[$npc]['maturity_start']), floatval($d[$npc]['dimensions']['maturity']['baseline']), 1e-6, "{$npc}: maturity starts at her level");
        }
        $this->assertSame(0, $this->llmCalls, 'everything came from the seed: no read was made');
        $this->assertSame([], $this->db->failures, 'no failed statement');

        // ---- all four clearly apart
        $names = array_keys($x);
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                $this->assertGreaterThanOrEqual(0.25, RelDynTraits::distance($x[$a], $x[$b]), "{$a} vs {$b}");
            }
        }
        $by = function (string $code) use ($x): array {
            $v = array_map(fn($v) => floatval($v[$code]), $x);
            arsort($v);
            return array_keys($v);
        };
        // the maturity Y the engine applies (review 2026-09-25: not the formula at (Rs, L) alone:
        // a class rule such as Bard -> Volatile, or a preset type, replaces it)
        $Y = array_map(fn($dd) => RelationshipDynamics::effectiveMaturityY($dd), $d);

        // ---- Ashe: Serene's hand-set conclusion, never read
        $ashe = $d['Ashe'];
        $this->assertSame('hand-set', $ashe['_trait_vector_src']['auto_source']);
        $this->assertSame('skip', $ashe['_trait_vector_src']['read_status']);
        foreach (RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides']['ashe']['trait_vector'] as $name => $v) {
            $this->assertEqualsWithDelta($v, $ashe['trait_vector'][$name], 1e-12, "Ashe {$name}");
        }
        $this->assertSame('Stoic', $ashe['inferred_temperament'], 'Stoic-leaning');
        $this->assertEquals(75, $ashe['dimensions']['maturity']['baseline']);
        $this->assertSame(['Y_up' => 1.0, 'Y_down' => 0.5], RelationshipDynamics::effectiveMaturityY($ashe), 'Resilient');
        $this->assertEqualsWithDelta(1.6, RelDynTraits::tableParam('Stoic', RelDynRomance::configDefaults()['momentum_temperament_mult'], 1.0, 'R',
            fn(array $v) => 1.0 + 4.0 * max(0.0, $v['G'] - 0.6), 'mult', $ashe), 1e-9, 'romance momentum 1.6');
        $this->assertSame('Ashe', $by('G')[0], 'the most guarded');
        $this->assertSame(['n' => '0'], pg_fetch_assoc(pg_query($this->db->link, "SELECT count(*) AS n FROM reldyn_trait_reads WHERE template_key = 'ashe'")));

        // ---- Aela: confident, resilient-leaning, low possessive
        $this->assertSame('Aela the Huntress', $by('C')[0], 'Aela: the most confident');
        $this->assertLessThan(0.3, $x['Aela the Huntress']['Po'], 'Aela: low possessiveness');
        $this->assertSame('traits', $d['Aela the Huntress']['_profile_autogen']['maturity_type_origin']);
        $this->assertLessThan(1.0, $Y['Aela the Huntress']['Y_down'], 'Aela: resists collapse');
        $this->assertGreaterThan(1.0, $Y['Aela the Huntress']['Y_up'] / $Y['Aela the Huntress']['Y_down']);
        $this->assertGreaterThan(0.45, RelDynTraits::opennessAt($x['Aela the Huntress'], RelDynAttraction::defaults()['temperament_openness'],
            RelDynAttraction::defaults()['openness_levels'])['o'], 'her openness from her traits sits just above the won-over switch (ruling #8: steep, not closed)');
        $this->assertSame('medium', RelDynAttraction::definition('Aela the Huntress', $d['Aela the Huntress'])['openness']);

        // ---- Muiri: volatile-leaning (her read), fearful-leaning and jealousy-prone (her PRIORS)
        $muiri = $d['Muiri']['_trait_vector_src'];
        $this->assertSame('Muiri', $by('L')[0], 'Muiri: the most reactive (her read: reactivity 0.7)');
        $this->assertSame('bio', $muiri['traits']['reactivity']['source']);
        // her swings are the largest of the maturity types that come from the traits (Aela's and
        // hers); Lynly's are larger still, but from her class (Bard -> Volatile), not her traits
        $this->assertSame('traits', $d['Muiri']['_profile_autogen']['maturity_type_origin']);
        $swing = fn(string $n) => $Y[$n]['Y_up'] * $Y[$n]['Y_down'];
        $this->assertGreaterThan($swing('Aela the Huntress'), $swing('Muiri'));
        $this->assertGreaterThan(1.0, $Y['Muiri']['Y_up']);
        $this->assertGreaterThan(1.0, $Y['Muiri']['Y_down'], 'Muiri: swings both ways (Adaptive-leaning, not Volatile)');
        $this->assertSame('class', $d['Lynly Star-Sung']['_profile_autogen']['maturity_type_origin']);
        $this->assertSame(['Y_up' => 1.5, 'Y_down' => 1.5], $Y['Lynly Star-Sung'], "Lynly: the Bard class rule's Volatile outranks her traits");
        $this->assertGreaterThan($swing('Muiri'), $swing('Lynly Star-Sung'));
        // the least confident, from her priors (YoungEager voice, the race pull): her read's
        // confidence quote moved her UP, toward 0.6
        $this->assertSame('Muiri', array_reverse($by('C'))[0], 'Muiri: the least confident (fearful-leaning)');
        $this->assertContains('voice:YoungEager', $muiri['prior']['signals']);
        $this->assertLessThan(0.45, $muiri['traits']['confidence']['prior']);
        $this->assertGreaterThan($muiri['traits']['confidence']['prior'], $x['Muiri']['C']);
        // the most jealousy-prone, also from her priors: no possessiveness quote, the base
        // Po = 0.15 + 0.35 (1 - C) of that low confidence (the traits have no vengeance)
        $jeal = array_map(fn($v) => RelDynTraits::value($v, 'jealousy_mult'), $x);
        arsort($jeal);
        $this->assertSame('Muiri', array_key_first($jeal), 'Muiri: the most jealousy-prone');
        $this->assertSame('prior', $muiri['traits']['possessiveness']['source']);

        // ---- Lynly: her read's resilience; the shy / anxious / masking target is not met (class doc, report)
        $read = array_diff_key($x, ['Ashe' => 1]);
        $rs = array_map(fn($v) => floatval($v['Rs']), $read);
        arsort($rs);
        $this->assertSame('Lynly Star-Sung', array_key_first($rs), 'Lynly: "remarkable resilience"');
        $this->assertSame('bio', $d['Lynly Star-Sung']['_trait_vector_src']['traits']['resilience']['source']);
    }

    /**
     * Phase 3, decisions §16 #5: the stacked romance momentum (attachment x temperament) is
     * capped at 2.5x, on each bed's own stored state through requiredMomentum. Ashe, the most
     * guarded, needs the most momentum (her 1.6 from guard x her moderate avoidance); the cap
     * is what an avoidant Ashe would hit.
     */
    public function testPhaseThreeMomentumCapOnTheFourBeds(): void
    {
        $this->meetAll();
        $cfg = RelDynRomance::config();
        $req = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $m = RelDynRomance::momentumMult($d, $cfg);
            $this->assertLessThanOrEqual(2.5 + 1e-12, $m['mult'], $npc);
            $req[$npc] = RelDynRomance::requiredMomentum($d, $this->npcId($npc), $cfg);
            $this->assertEqualsWithDelta(floatval($cfg['momentum_required']) * $m['mult'], $req[$npc], 1e-9, "{$npc}: no step-back");
        }
        arsort($req);
        $this->assertSame('Ashe', array_key_first($req), 'Ashe needs the most momentum');
        $ashe = $this->dynamics('Ashe');
        $this->assertEqualsWithDelta(1.6, RelDynRomance::momentumMult($ashe, $cfg)['temperament'], 1e-9);
        $ashe['profile_overrides']['attachment_style'] = 'avoidant';
        $m = RelDynRomance::momentumMult($ashe, $cfg);
        $this->assertEqualsWithDelta(3.2, $m['stacked'], 1e-9, 'an avoidant Ashe: 1.6 x 2.0');
        $this->assertEqualsWithDelta(2.5, $m['mult'], 1e-9, 'capped');
        $this->assertSame([], $this->db->failures);
    }

    /**
     * Phase 3, MDD 15.4 edits (decisions §16 #6): an eval maturity signal is moved by each bed's
     * own maturity type alone (R maturity retired), so the four diverge by who they are: Ashe
     * Resilient (a loss x0.5), Lynly Volatile from her Bard class (x1.5), Aela resisting collapse,
     * Muiri swinging both ways (her read).
     */
    public function testPhaseThreeMaturityMovesByTheMaturityTypeAloneOnTheFourBeds(): void
    {
        $this->meetAll();
        $loss = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame(1.0, RelationshipDynamics::getSignalResistance($d['inferred_temperament'] ?? null, 'maturity', $d), $npc);
            $d['dimensions']['maturity']['x'] = $d['dimensions']['maturity']['baseline'];
            $d['dimensions']['comfort']['x'] = 50;
            $r = RelationshipDynamics::applyEvalSignal($npc, $d, 'maturity', -4.0, [], 1.0);
            $this->assertEqualsWithDelta(-4.0 * RelationshipDynamics::effectiveMaturityY($d)['Y_down'], $r['actual'], 1e-3, "{$npc}: raw x P only (applyDelta rounds to 4 places)");
            $loss[$npc] = -$r['actual'];
        }
        $this->assertEqualsWithDelta(2.0, $loss['Ashe'], 1e-3, 'Ashe: Resilient, x0.5');
        $this->assertEqualsWithDelta(6.0, $loss['Lynly Star-Sung'], 1e-3, 'Lynly: Volatile (Bard), x1.5');
        $this->assertLessThan(4.0, $loss['Aela the Huntress'], 'Aela resists collapse');
        $this->assertGreaterThan(4.0, $loss['Muiri'], 'Muiri swings');
        $this->assertSame([], $this->db->failures);
    }
}
