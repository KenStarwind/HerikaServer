<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynConsentBedsPgDb
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
 * Consent (Ken 2026-10-01 §21), the four test beds through the real hooks (prerequest -> context -> postrequest), the real
 * stores and the committed seed's bio reads, on a real PostgreSQL from CHIM 3.4.1 core-shaped rows. No LLM call.
 *
 * RelDyn decides whether intimacy happens at all, one decision published to plugin_extended_data.reldyn.consent for
 * Sharmat to read: closed for an asexual or aromantic NPC, a friendzone and a walkaway; otherwise what the NPC wants less
 * what weighs on it (an open conflict, the ick, withdrawal, pulling back, not being let in, fear), against the NPC's own
 * bar, and the NPC who gives in anyway. The beds are Aela (bold, visceral), Ashe (hand-set vector, bond-first), Muiri
 * (toxic) and Lynly (a bard, her read as it stands: the bio does not establish shyness). Text never assumes a pronoun.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynConsentTestBedsPostgresTest extends TestCase
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
    private RelDynConsentBedsPgDb $db;
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
        $this->schema = 'reldyn_consentbeds' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynConsentBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdconsentbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_consent_beds_test.log');
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

    /** One player line to $npc through the real hooks: prerequest, context, postrequest; the NPC answers in $mood. */
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

    // ------------------------------------------------------------------ helpers of this test

    /** What Sharmat reads: plugin_extended_data.reldyn.consent. */
    private function consent(string $npc): array
    {
        return $this->ped($npc)['reldyn']['consent'] ?? [];
    }

    /** A romance that has earned intimacy: core romantic, deep affinity, passion, comfort and trust (the bond RelDyn would open to). */
    private function deepRomance(string $npc, int $aff = 80): void
    {
        $this->setCore($npc, $aff, 'romantic');
        $this->turn($npc, 'Well met.');
        $this->editDynamics($npc, function (array &$d): void {
            RelationshipDynamics::setPassion($d, 75.0);
            foreach (['comfort' => 82.0, 'trust' => 84.0, 'resentment' => 0.0] as $dim => $v) {
                $d['dimensions'][$dim]['x'] = $v;
                $d['dimensions'][$dim]['baseline'] = $dim === 'resentment' ? 0.0 : $v;
            }
            $d['in_conflict'] = false;
        });
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    private function noPronoun(?string $text, string $why): void
    {
        $this->assertIsString($text, $why);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his|herself|himself)\b/i', $text, $why);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text, $why);
    }

    // ------------------------------------------------------------------ the tests

    public function testEveryBedPublishesTheDecisionForSharmatAndAStrangerIsRefused(): void
    {
        $felt = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Well met.');
            $c = $this->consent($npc);
            $this->assertSame(1, $c['v'] ?? null, "{$npc}: published to plugin_extended_data.reldyn.consent");
            $this->assertTrue($c['enabled'], $npc);
            $this->assertFalse($c['allow'], "{$npc}: a first meeting does not get there");
            $this->assertSame('declines', $c['stance'], $npc);
            $this->assertNotSame([], $c['reasons'], "{$npc}: says why");
            $this->assertEqualsWithDelta($this->gamets - 5 * 60 * self::DAY / 1440, $c['gamets'], self::HOUR, "{$npc}: stamped on the game clock");
            $this->assertStringContainsString($npc, (string) $c['felt'], $npc);
            $this->noPronoun($c['felt'], $npc);
            $felt[$npc] = $c;
            // the older romance state keeps its flag; the consent key is the decision that supersedes it
            $this->assertArrayHasKey('romance', $this->ped($npc)['reldyn'], $npc);
        }
        $this->assertNull(pg_fetch_result(pg_query($this->db->link, "SELECT to_regclass('nsfw_npc_data')"), 0, 0), "RelDyn never creates Sharmat's store");
        $this->assertNoDbFailures();
    }

    /**
     * One scene at a bond part of the way there (a crush, mid affinity and passion, comfort and trust to match): $apply sets
     * the state on the NPC's stored dynamics (everything volatile is cleared first, so scenes do not carry over), $dims the
     * dimension points, then a turn through the real hooks. Returns what Sharmat reads.
     */
    private function scene(string $npc, ?callable $apply = null, array $dims = [], int $aff = 45, string $type = 'crush', float $passion = 35.0): array
    {
        $this->setCore($npc, $aff, $type);
        $this->turn($npc, 'Well met.');
        $this->editDynamics($npc, function (array &$d) use ($apply, $dims, $passion): void {
            RelationshipDynamics::setPassion($d, $passion);
            foreach (array_replace(['comfort' => 60.0, 'trust' => 65.0, 'resentment' => 0.0], $dims) as $dim => $v) {
                $d['dimensions'][$dim]['x'] = $v;
                $d['dimensions'][$dim]['baseline'] = $dim === 'resentment' ? 0.0 : $v;
            }
            $d['in_conflict'] = false;
            unset($d['_consent'], $d['_pullback'], $d['_ick_tracker'], $d['_keeping'], $d['_walkaway_state']);
            if ($apply !== null) $apply($d);
        });
        $this->turn($npc, 'Hm.');
        return $this->consent($npc);
    }

    /** The states the soft factors read, as the editor or an older save would hold them. */
    private function states(): array
    {
        return [
            'conflict'    => function (array &$d): void { $d['in_conflict'] = true; },
            'ick'         => function (array &$d): void {
                $d['_ick_tracker'] = ['ick_active' => true, 'quiet' => 0, 'romantic_count' => 3, 'total_count' => 3, 'counted_gamets' => []];
                $d['dimensions']['comfort']['x'] = $d['dimensions']['comfort']['baseline'] = 25.0;
            },
            'withdrawn'   => function (array &$d): void { $d['dimensions']['resentment']['x'] = 80.0; },
            'pulled_back' => function (array &$d): void {
                $d['_pullback'] = ['v' => RelDynPullback::VERSION, 'pressure' => 0.8, 'active' => true, 'since_gamets' => $this->gamets, 'gamets' => $this->gamets, 'say' => [], 'episodes' => 1];
            },
            'not_let_in'  => function (array &$d): void {
                $d['dimensions']['comfort']['x'] = $d['dimensions']['comfort']['baseline'] = 25.0;
                $d['dimensions']['trust']['x'] = $d['dimensions']['trust']['baseline'] = 30.0;
            },
        ];
    }

    /** Aela, Ashe, Muiri and Lynly in one state: the people they are, at the same bond. */
    private function allBeds(?callable $apply = null, array $dims = [], int $aff = 45, string $type = 'crush', float $passion = 35.0): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) $out[$npc] = $this->scene($npc, $apply, $dims, $aff, $type, $passion);
        return $out;
    }

    public function testAMidBondDivergesByWhoTheNPCIs(): void
    {
        $c = $this->allBeds();
        $margin = array_map(fn($x) => $x['willingness'] - $x['bar'], $c);
        // Aela (visceral, bold) and Lynly (open) are there; Ashe (bond-first, guarded, mature) needs more; Muiri is held back by fear
        $this->assertTrue($c['Aela the Huntress']['allow'], json_encode($c['Aela the Huntress']));
        $this->assertTrue($c['Lynly Star-Sung']['allow'], json_encode($c['Lynly Star-Sung']));
        $this->assertFalse($c['Ashe']['allow'], json_encode($c['Ashe']));
        $this->assertSame('declines', $c['Ashe']['stance']);
        $this->assertGreaterThan($c['Aela the Huntress']['bar'] + 0.15, $c['Ashe']['bar'], 'a bond-first, guarded NPC asks more of the same bond');
        $this->assertGreaterThan($c['Muiri']['bar'], $c['Ashe']['bar']);
        $this->assertLessThan($margin['Aela the Huntress'] - 0.1, $margin['Muiri'], 'the toxic NPC is nearer the line than the bold one');
        $this->assertGreaterThan(0.3, max($margin) - min($margin), 'meaningful divergence: ' . json_encode($margin));
        foreach ($c as $npc => $x) $this->assertSame([], array_diff($x['reasons'], ['fear']), "{$npc}: nothing but a trace of fear weighs on it");
        $this->assertNoDbFailures();
    }

    public function testEachSoftStateLowersEveryBedsWillingnessAndIsNamed(): void
    {
        $none = $this->allBeds();
        foreach ($this->states() as $state => $apply) {
            $c = $this->allBeds($apply);
            foreach ($c as $npc => $x) {
                $this->assertLessThan($none[$npc]['willingness'] - 0.05, $x['willingness'], "{$npc}: {$state} lowers it");
                $this->assertContains($state, $x['reasons'], "{$npc}: {$state} is named: " . json_encode($x['reasons']));
                $this->assertFalse($x['closed'], "{$npc}: {$state} is a weight, not a wall");
                $this->assertDoesNotMatchRegularExpression('/\d/', (string) $x['felt'], $npc);
                if (in_array($state, ['conflict', 'ick', 'withdrawn'], true)) $this->assertFalse($x['allow'], "{$npc}: {$state} says no");
            }
        }
        $this->assertNoDbFailures();
    }

    public function testTheSameQuarrelWeighsDifferentlyOnEachBedAndTheToxicOneFearsLosingThePlayer(): void
    {
        $c = $this->allBeds($this->states()['conflict']);
        $effect = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $r = RelDynConsent::decide($npc, $this->dynamics($npc), null, self::PLAYER);
            $effect[$npc] = $r['effects']['conflict'];
            $this->assertGreaterThan(0.2, $effect[$npc], "{$npc}: no one is immune to a quarrel");
        }
        $this->assertGreaterThan(0.04, max($effect) - min($effect), 'who the NPC is decides how hard it weighs: ' . json_encode($effect));
        $this->assertContains('fear', $c['Muiri']['reasons'], 'a quarrel is the fear of losing the player for the toxic NPC');
        $this->assertNotContains('fear', $c['Aela the Huntress']['reasons'], 'and not for the secure one');
        foreach ($c as $npc => $x) $this->assertFalse($x['allow'], "{$npc}: in a quarrel at this bond the answer is no");
        $this->assertNoDbFailures();
    }

    public function testAQuarrelThenRepairReopensTheDoor(): void
    {
        $npc = 'Aela the Huntress';
        $this->assertSame('willing', $this->scene($npc)['stance']);
        $this->assertSame('declines', $this->scene($npc, $this->states()['conflict'])['stance']);
        $after = $this->scene($npc);
        $this->assertTrue($after['allow'], 'a closed door is not permanent: ' . json_encode($after));
        $this->assertNoDbFailures();
    }

    public function testAsexualAndAromanticAreClosedOnEveryBedAtADeepBondAndNobodyIsAppeased(): void
    {
        $felt = [];
        foreach (['asexual', 'aromantic'] as $pref) {
            foreach ([false, true] as $pleaser) {
                $dims = ['comfort' => 82.0, 'trust' => 84.0] + ($pleaser ? ['self_confidence' => 12.0, 'maturity' => 22.0] : []);
                $c = $this->allBeds(function (array &$d) use ($pref, $pleaser): void {
                    $d['relationship_preference'] = $pref;
                    if ($pleaser) $d['profile_overrides']['attachment_style'] = 'anxious';
                }, $dims, 85, 'romantic', 80.0);
                foreach ($c as $npc => $x) {
                    $this->assertFalse($x['allow'], "{$npc}: {$pref} refuses, a romance and passion notwithstanding");
                    $this->assertTrue($x['closed'], $npc);
                    $this->assertSame('closed', $x['stance'], $npc);
                    $this->assertSame($pref, $x['reasons'][0], $npc);
                    $this->assertSame([], array_diff($x['reasons'], [$pref, 'friendzoned']), "{$npc}: nothing else closes it (an aromantic NPC reads no one as attractive)");
                    $this->assertFalse($x['appeasing'], "{$npc}: nobody is argued out of who they are");
                    $this->noPronoun($x['felt'], $npc);
                    $this->assertStringContainsString($npc, (string) $x['felt']);
                    $felt[$pref] = str_replace($npc, '{NAME}', $x['felt']);
                }
            }
        }
        $this->assertNotSame($felt['asexual'], $felt['aromantic'], 'each says it in its own words');
        $this->assertNoDbFailures();
    }

    public function testTheSameDeepRomanceWithNoPreferenceIsOpenOnEveryBed(): void
    {
        $c = $this->allBeds(null, ['comfort' => 82.0, 'trust' => 84.0], 85, 'romantic', 80.0);
        foreach ($c as $npc => $x) {
            $this->assertTrue($x['allow'], "{$npc}: " . json_encode($x));
            $this->assertFalse($x['closed'], $npc);
            $this->assertContains($x['stance'], ['willing', 'hesitant'], $npc);
        }
        $this->assertSame('willing', $c['Aela the Huntress']['stance']);
        $this->assertNoDbFailures();
    }

    public function testAFriendzoneAndAWalkawayCloseTheDoorAndItReopens(): void
    {
        // the player is a bard; Aela's rigid strength and competence bars fail: the Matrix friendzones the player
        $skills = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting', 'heavyarmor', 'illusion',
            'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing', 'sneak', 'speechcraft', 'twohanded'], 15);
        $this->corePlayer('skills', array_merge($skills, ['speechcraft' => 95, 'illusion' => 80]));
        foreach (['People Killed' => 5, 'Creatures Killed' => 6] as $stat => $n) $this->corePlayer($stat, (string) $n);
        $profile = function (array &$d): void {
            $d['attraction_profile'] = [
                'beauty_keywords' => ['rugged'], 'strength_skills' => ['OneHanded', 'Archery', 'LightArmor', 'Block'],
                'strength_mode' => 'strict', 'strength_threshold' => 200,
                'status_metrics' => [['type' => 'faction_rank', 'faction' => 'Companions', 'min' => 1]],
                'competence_metrics' => [['type' => 'kill_category', 'category' => 'animals', 'min' => 30]],
                'pillar_rigidity' => ['beauty' => 'soft', 'strength' => 'rigid', 'status' => 'soft', 'competence' => 'rigid'],
                'intimacy_gate' => 'visceral', 'gender_pref' => 'bisexual',
            ];
        };
        $npc = 'Aela the Huntress';
        $c = $this->scene($npc, $profile, [], 62, 'crush', 45.0);
        $this->assertTrue($this->dynamics($npc)['_attraction_friendzoned'], 'the fixture: a friendzone');
        $this->assertSame('closed', $c['stance']);
        $this->assertSame(['friendzoned'], $c['reasons']);
        $this->assertFalse($c['allow']);
        $this->assertStringContainsString('as a friend', (string) $c['felt']);
        // a walkaway closes it too (the boundary test ends with the state)
        $w = $this->scene('Muiri', function (array &$d): void { $d['_walkaway_state'] = 'walking'; });
        $this->assertSame('closed', $w['stance']);
        $this->assertContains('walked_away', $w['reasons']);
        $this->assertFalse($w['allow']);
        $back = $this->scene('Muiri');
        $this->assertNotSame('closed', $back['stance'], 'a walkaway is a state: ' . json_encode($back));
        $this->assertNoDbFailures();
    }

    public function testAPeoplePleaserVersionOfABedSaysYesWhereTheOriginalSaysNoAndNotEverywhere(): void
    {
        $pleaser = function (?callable $state): callable {
            return function (array &$d) use ($state): void {
                $d['profile_overrides']['attachment_style'] = 'anxious';
                if ($state !== null) $state($d);
            };
        };
        $pleaserDims = ['self_confidence' => 15.0, 'maturity' => 25.0];
        $states = $this->states();
        $natural = $this->allBeds($states['conflict']);
        $pleased = $this->allBeds($pleaser($states['conflict']), $pleaserDims);
        $flipped = [];
        foreach ($natural as $npc => $x) {
            $this->assertFalse($x['allow'], "{$npc}: as they are, the quarrel says no");
            if ($pleased[$npc]['allow']) {
                $flipped[] = $npc;
                $this->assertSame('appeasing', $pleased[$npc]['stance'], $npc);
                $this->assertTrue($pleased[$npc]['appeasing'], $npc);
                $this->assertContains('conflict', $pleased[$npc]['reasons'], "{$npc}: it was a state that should have stopped them");
                $this->assertLessThan($pleased[$npc]['bar'], $pleased[$npc]['willingness'], "{$npc}: short of their own bar");
                $this->assertMatchesRegularExpression('/going along with it anyway|says yes out of that fear/', (string) $pleased[$npc]['felt'], $npc);
                $this->noPronoun($pleased[$npc]['felt'], $npc);
            }
        }
        $this->assertGreaterThanOrEqual(2, count($flipped), 'the people-pleaser gives in: ' . json_encode($pleased));
        $this->assertLessThan(4, count($flipped), 'but not everyone is the same: who they are decides');
        // a limit: with the ick and not being let in, even the people-pleaser says no
        foreach ($this->allBeds($pleaser($states['ick']), $pleaserDims) as $npc => $x) {
            $this->assertFalse($x['allow'], "{$npc}: the shortfall is too great to give in to");
        }
        $this->assertNoDbFailures();
    }

    public function testTheSwitchOffPublishesDisabledOnceAndBackOnPublishesAgain(): void
    {
        $npc = 'Aela the Huntress';
        $this->assertTrue($this->scene($npc)['enabled']);
        $this->storeConfig(['consent' => ['enabled' => false]]);
        $this->turn($npc, 'Still here.');
        $c = $this->consent($npc);
        $this->assertFalse($c['enabled'], 'Sharmat reads this as no decision');
        $this->assertArrayNotHasKey('allow', $c);
        $this->assertArrayNotHasKey(RelDynConsent::KEY, $this->dynamics($npc), 'and the hysteresis state is forgotten');
        $stamp = $c['gamets'];
        $this->turn($npc, 'Still here.');
        $this->assertSame($stamp, $this->consent($npc)['gamets'], 'published once, not every request');
        $this->storeConfig([]);
        $this->turn($npc, 'And again.');
        $this->assertTrue($this->consent($npc)['enabled']);
        $this->assertArrayHasKey('allow', $this->consent($npc));
        $this->assertNoDbFailures();
    }

    public function testAnUnchangedDecisionIsNotRewritten(): void
    {
        $npc = 'Lynly Star-Sung';
        $this->scene($npc);
        $d = $this->dynamics($npc);
        RelDynConsent::publish($npc, $d, self::PLAYER);
        $first = $this->consent($npc);
        $this->gamets += 3 * self::HOUR;
        RelDynConsent::publish($npc, $d, self::PLAYER);
        $this->assertSame($first, $this->consent($npc), 'the same decision: the same row, the same stamp');
        $this->assertNoDbFailures();
    }

    /**
     * The contract, end to end: Sharmat's local bridge (Sharmat-Alpha branch reldyn-consent, reldyn_consent_policy.php, a read-only
     * reference like nsfw_data.php in the handoff test) reads from the real database exactly what RelDyn's real hooks published, for
     * every bed, and its eligibility answer is RelDyn's. Skipped when that checkout is not on the branch. In its own process: the
     * bridge's functions are global.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testSharmatsBridgeReadsExactlyWhatRelDynPublished(): void
    {
        $bridge = __DIR__ . '/../../../Sharmat-Alpha/reldyn_consent_policy.php';
        if (!is_file($bridge)) {
            $this->markTestSkipped('Sharmat-Alpha is not on its local branch reldyn-consent next to this worktree (' . $bridge . ')');
        }
        require_once $bridge;
        $c = $this->allBeds();
        foreach (array_keys(self::BEDS) as $npc) {
            aiagentNsfwRelDynConsentReset();
            $read = aiagentNsfwRelDynConsentDecision($npc);
            $this->assertNotNull($read, "{$npc}: the bridge finds RelDyn's decision");
            $this->assertSame($c[$npc]['allow'], $read['allow'], $npc);
            $this->assertSame($c[$npc]['stance'], $read['stance'], $npc);
            $this->assertSame($c[$npc]['reasons'], $read['reasons'], $npc);
            $this->assertSame((string) $c[$npc]['felt'], $read['felt'], $npc);
            $this->assertSame($c[$npc]['allow'], aiagentNsfwRelDynConsentEligibility($npc), "{$npc}: Sharmat's answer is RelDyn's");
            $this->assertSame(!$c[$npc]['allow'], aiagentNsfwRelDynRefuses($npc), $npc);
        }
        $this->assertTrue($c['Aela the Huntress']['allow']);
        $this->assertFalse($c['Ashe']['allow'], 'the beds differ, and Sharmat follows each');
        // RelDyn off: the bridge goes inert
        $this->storeConfig(['enabled' => false]);
        aiagentNsfwRelDynConsentReset();
        $this->assertNull(aiagentNsfwRelDynConsentDecision('Aela the Huntress'), 'RelDyn switched off in its config: no decision');
        $this->assertNoDbFailures();
    }

    public function testTheTextNeverAssumesAPronounWhateverTheNPCsGender(): void
    {
        $npc = 'Aela the Huntress';
        foreach (['female', 'male', 'nonbinary'] as $gender) {
            pg_query_params($this->db->link, 'UPDATE core_npc_master SET gender = $2 WHERE npc_name = $1', [$npc, $gender]);
            foreach (['conflict' => $this->states()['conflict'], 'asexual' => function (array &$d): void { $d['relationship_preference'] = 'asexual'; }] as $why => $apply) {
                $c = $this->scene($npc, $apply);
                $this->noPronoun($c['felt'], "{$gender} {$why}");
                $this->assertStringContainsString($npc, (string) $c['felt'], "{$gender} {$why}: the name, never a pronoun");
            }
        }
        $this->assertNoDbFailures();
    }
}
