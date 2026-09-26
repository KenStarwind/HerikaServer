<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!isset($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 */
final class RelDynFeltCoherencePgDb
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
 * felt-steering / context-pre-steering review findings, end to end: the prompt RelDyn builds
 * must agree with itself, with the bond it describes and with what core puts beside it.
 *   - a newly met NPC is not 'Walled' (warmth starts at its temperament baseline);
 *   - the attraction read follows tier and bond (no answered flirtation at acquaintance, none
 *     while the bond is strained, no pending "could change what they are" at first sight,
 *     never the meta term "the player");
 *   - the eval's free-text summaries reach the memory line without numbers or stated feelings;
 *   - NPC-to-NPC radiant requests get no player steering and leave the player's one-shots;
 *   - with core's relationship_system on (it names the player to every NPC), the stranger line
 *     does not claim the name is unknown, and RelDyn and core name the same person.
 *
 * Real PostgreSQL, CHIM 3.4.1 core-shaped rows, the real hooks in main.php's order (prerequest,
 * context_pre of every ext in alphabetical order, context, postrequest); nothing stubbed (no LLM
 * or embedding call on this path; one eval inbox item is queued as the eval worker queues it).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynFeltCoherencePostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const MINUTE = self::HOUR / 60;
    private const PLAYER = 'Kaida';
    private const BIO = 'Roleplay as this character.';

    private string $dsn;
    private string $schema;
    private RelDynFeltCoherencePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY + 10 * self::HOUR;

    protected function setUp(): void
    {
        // Personality traits phase 2: this class pins the LABEL assignment (the phase-1 legacy path):
        // its NPCs' temperaments are the old core-data vote's. The read assignment has its own tests.
        RelDynTraits::$assignmentOverride = 'label';
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_coh' . getmypid() . '_' . bin2hex(random_bytes(3));

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
        // core's speech table (data/database_default.sql): prompt gating reads who has talked with the player
        pg_query($admin, "CREATE TABLE speech (sess varchar(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY,
            companions text, audios text, utterance_id text)");
        // data/database_default.sql eventlog / responselog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // Core 3.4.1 locations (debug/db_updates.php columns).
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
        pg_close($admin);

        $this->db = new RelDynFeltCoherencePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'HERIKA_PERS', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE',
                     'CACHE_PARTY', 'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'RELATIONSHIP_SYSTEM_ENABLED', 'HERIKA_CONTEXT',
                     'COMMAND_PROMPT', 'startTime'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdcoh');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_felt_coherence_test.log');

        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();

        // Kaida, a male warrior (level 40, a Companion), as the plugin writes core_player.
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 15);
        foreach (['gender' => 'male',
                     'skills' => json_encode(array_merge($all, ['onehanded' => 90, 'twohanded' => 80, 'block' => 70, 'heavyarmor' => 80])),
                     'stats' => json_encode(['level' => 40, 'health' => 300]),
                     'People Killed' => '120', 'Creatures Killed' => '200', 'Quests Completed' => '40', 'Dungeons Cleared' => '25',
                     'The Companions Quests Completed' => '4'] as $id => $value) {
            pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', [$id, $value]);
        }
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
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

    /** A core_npc_master row as CHIM 3.4.1 registers an NPC, core's relationship to the player, optional stored RelDyn state. */
    private function npc(string $name, string $class, array $factions, array $skills, int $aff, string $type, array $dynamics = []): void
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $faction) {
            $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
        }
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data, plugin_extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb, $10::jsonb)',
            [$name, strtoupper(substr(md5($name), 0, 6)), 'female', 'NordRace', 'FemaleEvenToned', "Roleplay as {$name}", '',
             json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
             json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                 'relationships' => ['Player' => ['aff' => $aff, 'type' => $type]]]),
             json_encode($dynamics === [] ? new stdClass() : ['reldyn' => ['dynamics' => $dynamics]])]);
    }

    /** RelDyn's config row the way the config page stores it (shipped defaults, then $overrides). */
    private function storeConfig(array $overrides): void
    {
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$name]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function patchDynamics(string $name, callable $patch): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$name]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $ped['reldyn']['dynamics'] = $patch($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1',
            [$name, json_encode($ped)]);
    }

    private function dims(array $x): array
    {
        $out = [];
        foreach ($x as $dim => $v) $out[$dim] = ['x' => $v];
        return $out;
    }

    /**
     * One request to $npc in main.php's order: prerequest, every ext context_pre (RelDyn's, then
     * core's relationship_system's when RELATIONSHIP_SYSTEM_ENABLED is on), context.
     * Returns ['pers' => everything added to HERIKA_PERS, 'blocks' => RelDyn's context messages].
     */
    private function turn(string $npc, string $line, string $type = 'inputtext'): array
    {
        $this->gamets += 10 * self::MINUTE;
        $this->realTs += 30;
        $party = '|' . $npc . '|' . self::PLAYER . '|';
        $data = $type === 'inputtext' ? self::PLAYER . ": {$line}" : $line;
        $request = [$type, (string) $this->realTs, (string) (int) $this->gamets, $data];
        $run = function (string $path) use ($npc, $request, $party): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            (static function () use ($path): void { require $path; })();
            RelationshipDynamics::endRequest();
        };
        $ext = __DIR__ . '/../../ext/';
        $run($ext . 'relationship_dynamics/prerequest.php');
        $this->clearReldynGlobals();
        $GLOBALS['HERIKA_PERS'] = self::BIO;
        $GLOBALS['contextDataFull'] = [];
        $GLOBALS['HERIKA_CONTEXT'] = '';
        $GLOBALS['COMMAND_PROMPT'] = '';
        $GLOBALS['startTime'] = microtime(true);
        $run($ext . 'relationship_dynamics/context_pre.php');
        if (!empty($GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'])) {
            if (!function_exists('_relEnsureWorkerRunning')) {
                $run($ext . 'relationship_system/context_pre.php');
            } else {
                // core's file declares a function, so one PHP process can require it once (a
                // request is one process in production): later turns run its injection statement
                $GLOBALS['HERIKA_PERS'] .= "

" . RelationshipManager::buildContext($npc, array_map('trim', explode(',', $party)));
            }
        }
        $pers = substr((string) $GLOBALS['HERIKA_PERS'], strlen(self::BIO));
        $run($ext . 'relationship_dynamics/context.php');
        $blocks = array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []);
        $this->clearReldynGlobals();
        return ['pers' => $pers, 'blocks' => $blocks];
    }

    private static function text(array $t): string
    {
        return trim($t['pers'] . "\n" . implode("\n", $t['blocks']));
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    // ------------------------------------------------------------------ tests

    /**
     * A newly met NPC is not 'Walled': warmth starts at the temperament baseline, and an extreme
     * band an NPC merely rests in says nothing (the bio carries the nature, steering the change).
     */
    public function testANewlyMetNpcIsNotWalled(): void
    {
        // Aela (Hunter: temperament from her core row), Brelyna (Sorcerer), Lydia (Stoic: warmth
        // baseline 20 lies in the 'Walled' band itself)
        $this->npc('Aela the Huntress', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 10, 'platonic');
        $this->npc('Brelyna Maryon', 'Sorcerer', ['CollegeofWinterholdFaction'], ['destruction' => 45, 'conjuration' => 40], 0, 'professional');
        $this->npc('Lydia', 'Warrior', ['PotentialFollowerFaction'], ['onehanded' => 60, 'heavyarmor' => 58], 5, 'neutral',
            ['profile_overrides' => ['temperament' => 'Stoic', 'attachment_style' => 'secure', 'traits' => []]]);

        $walled = RelationshipDynamics::DIMENSION_BANDS['warmth'][0]['keywords'];
        foreach (['Aela the Huntress', 'Brelyna Maryon', 'Lydia'] as $npc) {
            $t = self::text($this->turn($npc, 'Well met.'));
            $t2 = self::text($this->turn($npc, 'How are you?'));
            $d = $this->dynamics($npc);
            $temperament = $d['inferred_temperament'];
            $base = RelationshipDynamics::getTemperamentBaseline($temperament, 'warmth');
            $this->assertEqualsWithDelta($base, floatval($d['dimensions']['warmth']['x']), 1e-9, "{$npc} ({$temperament}): warmth starts at the baseline");
            $this->assertEqualsWithDelta($base, floatval($d['dimensions']['warmth']['baseline']), 1e-9, "{$npc}: the baseline is set");
            foreach ([$t, $t2] as $text) {
                $this->assertStringNotContainsStringIgnoringCase('answers in single words', $text, "{$npc}: not Walled:\n{$text}");
                $this->assertStringNotContainsStringIgnoringCase(substr($walled, 0, 20), $text, "{$npc}:\n{$text}");
            }
        }
        $this->assertSame('Stoic', $this->dynamics('Lydia')['inferred_temperament']);

        // A warmth really pushed into 'Walled' still speaks (from tier 1: a stranger-tier NPC
        // shows only its own state)
        $this->patchDynamics('Lydia', function (array $d): array { $d['dimensions']['warmth']['x'] = 4.0; return $d; });
        pg_query($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', '30') WHERE npc_name = 'Lydia'");
        $this->assertStringContainsStringIgnoringCase('answers in single words', self::text($this->turn('Lydia', 'Say something.')));
        $this->assertNoDbFailures();
    }

    /**
     * The attraction read follows the tier and the bond: an acquaintance's eyes may find the
     * player, but flirtation is not answered (MDD 8.1); a strained bond says nothing of the pull;
     * a stranger gets no pending "could change what they are to each other"; a friendzoned
     * acquaintance is not a "trusted friend"; the text names the player, never "the player".
     */
    public function testTheAttractionReadFollowsTierAndBond(): void
    {
        $this->npc('Aela the Huntress', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 10, 'platonic');
        $this->npc('Uthgerd the Unbroken', 'Warrior', [], ['twohanded' => 60, 'heavyarmor' => 50, 'block' => 40], 0, 'neutral');
        $this->npc('Ysolda', 'Citizen', [], ['speech' => 45], 50, 'romantic', [
            'profile_overrides' => ['temperament' => 'Jealous', 'attachment_style' => 'anxious', 'traits' => ['insecure']],
            'dimensions' => $this->dims(['maturity' => 14.0, 'warmth' => 24.0, 'resentment' => 66.0, 'passion' => 64.0]),
            'passion' => 64.0, 'jealousy_anger' => 74.0, 'jealousy_trigger_npc' => 'Camilla Valerius',
            'in_conflict' => true, 'conflict_positive_count' => 0,
        ]);
        $this->npc('Camilla Valerius', 'Citizen', [], ['speech' => 40], 20, 'platonic', [
            'profile_overrides' => ['temperament' => 'Romantic', 'attachment_style' => 'secure', 'traits' => []],
            'attraction_overrides' => ['gender_pref' => 'homosexual'],
        ]);

        // Aela, tier 1 (core 10): drawn to a strong Companion, but an acquaintance
        $this->turn('Aela the Huntress', 'Good hunting?');
        $aela = self::text($this->turn('Aela the Huntress', 'You shoot well.'));
        $att = $this->dynamics('Aela the Huntress')['_attraction'];
        $this->assertTrue($att['passes'], $att['outcome'] ?? '');
        $this->assertSame(1, RelationshipDynamics::getContextTier($this->dynamics('Aela the Huntress')));
        $this->assertMatchesRegularExpression('/eyes keep finding ' . self::PLAYER . '/', $aela, "the pull shows as looks:\n{$aela}");
        $this->assertDoesNotMatchRegularExpression('/flirtation gets|flirts back|trusted friend/', $aela, "acquaintance:\n{$aela}");

        // Uthgerd, tier 0: a first-sight read at most, nothing pending to a stranger
        $this->turn('Uthgerd the Unbroken', 'Hello.');
        $uth = self::text($this->turn('Uthgerd the Unbroken', 'Want to spar?'));
        $this->assertSame(0, RelationshipDynamics::getContextTier($this->dynamics('Uthgerd the Unbroken')));
        $this->assertStringNotContainsString('could change what they are', $uth, "stranger:\n{$uth}");
        $this->assertStringNotContainsString('see more in', $uth);
        $this->assertDoesNotMatchRegularExpression('/flirtation gets|flirts back/', $uth, "stranger:\n{$uth}");

        // Ysolda: open conflict, resentment 66, jealousy 74: the pull says nothing
        $ys = self::text($this->turn('Ysolda', 'Ysolda, can we talk?'));
        $this->assertDoesNotMatchRegularExpression('/eyes keep finding|flirtation gets|flirts back|could change what they are/', $ys, "strained:\n{$ys}");
        $this->assertMatchesRegularExpression('/walls up|clipped|brittle/i', $ys, 'the strain itself still speaks');

        // Camilla, friendzoned acquaintance (tier 1): deflection, not "a trusted friend"
        $this->turn('Camilla Valerius', 'Hello, Camilla.');
        $cam = self::text($this->turn('Camilla Valerius', 'You look lovely today.'));
        $this->assertTrue($this->dynamics('Camilla Valerius')['_attraction']['friendzoned']);
        $this->assertStringNotContainsString('trusted friend', $cam, "acquaintance:\n{$cam}");

        // Aela with the Ick active (the stored tracker: postrequest sets the request global only
        // after the context hooks): she steps back, and nothing says her flirtation is answered
        $this->patchDynamics('Aela the Huntress', function (array $d): array {
            $d['_ick_tracker'] = ['romantic_count' => 4, 'total_count' => 5, 'window_start' => 0, 'ick_active' => true,
                'ick_triggered_at' => 0, 'ick_cooldown_until_play_gamets' => 0];
            return $d;
        });
        $ick = self::text($this->turn('Aela the Huntress', 'Come on, one dance.'));
        $this->assertMatchesRegularExpression('/steps back when ' . self::PLAYER . ' leans in/', $ick, "the ick shows:
{$ick}");
        $this->assertDoesNotMatchRegularExpression('/eyes keep finding|flirtation gets|flirts back/', $ick, "ick:
{$ick}");

        foreach (['aela' => $aela, 'uthgerd' => $uth, 'ysolda' => $ys, 'camilla' => $cam, 'ick' => $ick] as $label => $text) {
            $this->assertStringNotContainsString('the player', $text, "{$label}: the meta term reached the LLM:\n{$text}");
        }
        $this->assertNoDbFailures();
    }

    /**
     * The eval LLM's one-line summary becomes the memory line of a tier-2 NPC: what happened
     * survives, its scores and stated feelings do not (decisions §3).
     */
    public function testEvalSummariesReachTheMemoryLineWithoutNumbersOrStatedFeelings(): void
    {
        $this->npc('Lydia', 'Warrior', ['CurrentFollowerFaction'], ['onehanded' => 60, 'heavyarmor' => 58], 45, 'platonic', [
            'profile_overrides' => ['temperament' => 'Stoic', 'attachment_style' => 'secure', 'traits' => []],
            'dimensions' => $this->dims(['trust' => 64.0, 'comfort' => 70.0, 'warmth' => 64.0]),
        ]);
        $this->turn('Lydia', 'Ready?');
        $id = (int) pg_fetch_result(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', ['Lydia']), 0, 0);
        $this->assertTrue(RelationshipDynamics::queuePendingEval('Lydia', [
            'v' => 1, 'npc' => 'Lydia', 'npc_id' => $id, 'gamets' => (int) $this->gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 2, 'trust' => 6, 'comfort' => 0, 'respect' => 3, 'passion' => 0, 'maturity' => 0],
            'tags' => ['rescue'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0], 'significance' => 0.7,
            'positive_interaction' => true,
            'summary' => 'Kaida saved Lydia from 3 bandits; she now trusts Kaida more (trust +6).',
        ]));
        // Lydia's postrequest applies the inbox (the eval summary becomes last_reason / a memory)
        $GLOBALS['gameRequest'] = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ': Ready?'];
        $GLOBALS['HERIKA_NAME'] = 'Lydia';
        $GLOBALS['RELDYN_NPC_NAME'] = 'Lydia';
        $GLOBALS['CACHE_PEOPLE'] = '|Lydia|' . self::PLAYER . '|';
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
        $this->clearReldynGlobals();
        // The reason anchor is stored cleaned (dimensional memory): the event, never its scores or feelings
        $this->assertSame('Kaida saved Lydia from three bandits',
            $this->dynamics('Lydia')['dimensions']['trust']['last_reason'] ?? null, 'the item was applied');

        $t = $this->turn('Lydia', 'You did well back there.');
        $all = self::text($t);
        $this->assertGreaterThanOrEqual(2, RelationshipDynamics::getContextTier($this->dynamics('Lydia')));
        $this->assertStringContainsString('saved Lydia from three bandits', $all, "the event survives:\n{$all}");
        $this->assertDoesNotMatchRegularExpression('/\d/', $all, "a digit reached the LLM:\n{$all}");
        $this->assertDoesNotMatchRegularExpression('/\btrusts?\b/i', $all, "a stated feeling reached the LLM:\n{$all}");
        $this->assertNoDbFailures();
    }

    /**
     * NPC-to-NPC radiant dialogue: prerequest already stands down; context_pre and context
     * must too: no knowledge of the player, no emotional core aimed at "them", and the blush
     * meant for the player is not spent on Farkas.
     */
    public function testRadiantRequestsGetNoPlayerSteeringAndKeepTheOneShots(): void
    {
        $this->npc('Lydia', 'Warrior', ['CurrentFollowerFaction'], ['onehanded' => 60], 60, 'romantic', [
            'profile_overrides' => ['temperament' => 'Stoic', 'attachment_style' => 'secure', 'traits' => []],
            'dimensions' => $this->dims(['trust' => 70.0, 'warmth' => 70.0, 'passion' => 66.0]),
            'passion' => 66.0,
        ]);
        $this->turn('Lydia', 'Hello.');
        $this->patchDynamics('Lydia', function (array $d): array {
            $d['_last_passion_delta'] = 8.0;
            $d['pending_blush_mult'] = 2.0;
            return $d;
        });

        $radiant = $this->turn('Lydia', 'Lydia: Farkas, how goes the hunt?', 'radiant');
        $this->assertSame('', trim($radiant['pers']), "no RelDyn block in <character>:\n{$radiant['pers']}");
        $this->assertSame([], $radiant['blocks'], 'no <subtext>');
        $d = $this->dynamics('Lydia');
        $this->assertEquals(8.0, floatval($d['_last_passion_delta']), 'the blush waits for the player');
        $this->assertEquals(2.0, floatval($d['pending_blush_mult']));

        // ... and lands on the player's next line
        $next = self::text($this->turn('Lydia', 'Lydia?'));
        $this->assertMatchesRegularExpression('/heat floods|colour rises/i', $next, $next);
        $this->assertEquals(0.0, floatval($this->dynamics('Lydia')['_last_passion_delta']));
        $this->assertNoDbFailures();
    }

    /**
     * Core's relationship_system (enabled live, tier-only or full) appends a block that names
     * the player to every NPC. With prompt gating (the CHIM fork hook, decisions §18 #6) core
     * leaves the player's line out for an NPC who has never met them, and RelDyn's stranger line
     * says the name is unknown: both agree, one referent. With prompt gating off, core names the
     * player to a stranger too; RelDyn's stranger line must not claim the name is unknown then,
     * and both name the same person.
     */
    public function testWithCoreRelationshipsOnTheStrangerLineAgreesWithCore(): void
    {
        $this->npc('Brelyna Maryon', 'Sorcerer', ['CollegeofWinterholdFaction'], ['destruction' => 45], 0, 'professional');
        $this->turn('Brelyna Maryon', 'Excuse me.');
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $t = $this->turn('Brelyna Maryon', 'Is this the College?');
        $this->assertSame(0, RelationshipDynamics::getContextTier($this->dynamics('Brelyna Maryon')));
        $this->assertStringContainsString("[Brelyna Maryon's RELATIONSHIPS]", $t['pers'], "core's block is there");
        $this->assertStringNotContainsString(self::PLAYER, $t['pers'], "neither core nor RelDyn names a stranger:\n{$t['pers']}");
        // a stranger (or, where his deeds are heard, one who knows the stories and not the name)
        $this->assertMatchesRegularExpression('/knows nothing of their name|has never met this stranger, only heard the stories/', $t['pers']);
        $this->assertDoesNotMatchRegularExpression('/' . self::PLAYER . '/', self::text($t), 'one referent for the player');

        // Prompt gating off: core names the player to everyone, and RelDyn agrees
        $this->storeConfig(['prompt_gating' => ['enabled' => false]]);
        $t = $this->turn('Brelyna Maryon', 'Is this the Arcanaeum?');
        $this->assertStringContainsString(self::PLAYER, $t['pers'], "core names the player:\n{$t['pers']}");
        $this->assertStringContainsString('<knowledge_of_player>', $t['pers']);
        $know = substr($t['pers'], strpos($t['pers'], '<knowledge_of_player>'));
        $know = substr($know, 0, strpos($know, '</knowledge_of_player>'));
        $this->assertStringNotContainsString('knows nothing of their name', $know, "contradicts core's block:\n{$t['pers']}");
        $this->assertStringContainsString(self::PLAYER, $know, 'RelDyn names the same person core names');
        $this->assertDoesNotMatchRegularExpression('/this stranger/', self::text($t), 'one referent for the player');
        $this->storeConfig([]);

        // Core off: the stranger stays unnamed in RelDyn's text
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = false;
        $off = $this->turn('Brelyna Maryon', 'Sorry, one more thing.');
        $this->assertStringNotContainsString(self::PLAYER, $off['pers']);
        $this->assertMatchesRegularExpression('/knows nothing of their name|has never met this stranger, only heard the stories/', $off['pers']);
        $this->assertNoDbFailures();
    }
}
