<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (fetchOne returns [] on failure, CHIM's convention). */
final class RelDynItemModifiersPgDb
{
    public $link;
    public array $failures = [];
    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($this->link, "SET search_path TO {$schema}");
    }
    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link); return []; }
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
    public function execQuery($q) { $r = @pg_query($this->link, $q); if (!$r) $this->failures[] = pg_last_error($this->link); return $r; }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * item-modifiers on CHIM 3.4.1's own lines (dimension draft "Item -> Dimension Modifiers"),
 * through the facet appraisal (decisions §6), no MinAI, no LLM:
 *   - a gift is core's itemfound "<player> gave <n> <item> to <NPC>,(value <v> gold)"
 *     (Plugin.cpp TESContainerChangedEvent); a consume is the Consume action's infoaction
 *     "<NPC> consumes <item>." (Commands.cpp), or her own itemfound drank / ate line; each row
 *     counts once (her eventlog watermark), someone else's line is not hers;
 *   - her appraisal of what she drinks scales the spike (a drink she likes feels better), the
 *     spike is a held offset taken back exactly when it wears off (game hours on the eventlog
 *     clock), the permanent cost stays;
 *   - her own gear is core's metadata.equipment: what she wears holds its baseline offset as she
 *     values it, taking it off takes it back and stings; baseline drift runs without it.
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynItemModifiersPostgresTest extends TestCase
{
    private const NOW = 5000000000;
    private string $dsn;
    private string $schema;
    private RelDynItemModifiersPgDb $db;
    private array $saved = [];
    private string $log;
    private $prevLog = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_items_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, npc_name text NOT NULL, npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0, prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text,
            personality text, relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text)");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_close($admin);
        $this->db = new RelDynItemModifiersPgDb($dsn, $this->schema);
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->clock(self::NOW);
        $this->log = tempnam(sys_get_temp_dir(), 'rditems');
        $this->prevLog = ini_set('error_log', $this->log);
        foreach (['Lynly Star-Sung', 'Aela the Huntress', 'Muiri'] as $npc) {
            pg_query_params($this->db->link, "INSERT INTO core_npc_master (npc_name, metadata, extended_data) VALUES ($1, '{}', '{}')", [$npc]);
        }
        RelDynTraitRead::reset();   // its table-existence memo is per db object
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->log);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function clock(int $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) $gamets, 'Kaida: hm'];
    }

    private function event(string $type, string $data, int $gamets): void
    {
        pg_query_params($this->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, 'pending', $3, 1, $3, '', '')",
            [$type, $data, $gamets]);
    }

    /** An NPC with the given signed preferences (the editor's overrides), comfort / warmth at 50. */
    private static function npc(array $prefs): array
    {
        $d = ['inferred_temperament' => 'Stoic', 'profile_overrides' => ['attachment_style' => 'secure'], 'facet_pref_overrides' => $prefs, 'dimensions' => []];
        foreach (['comfort' => 50.0, 'warmth' => 50.0, 'passion' => 20.0, 'arousal' => 20.0, 'maturity' => 55.0, 'trust' => 50.0,
                  'respect' => 50.0, 'affinity' => 50.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    public function testGiftsAndConsumesAreHerOwnLinesAndCountOnce(): void
    {
        $this->event('itemfound', 'Kaida gave 1 Sweetroll to Lynly Star-Sung,(value 2 gold)', self::NOW - 1000);
        $this->event('itemfound', 'Kaida gave 1 Iron Dagger to Muiri,(value 10 gold)', self::NOW - 900);
        $this->event('itemfound', 'Ralof gave 1 Honey to Lynly Star-Sung', self::NOW - 800);
        $this->event('infoaction', 'Lynly Star-Sung consumes Nord Mead.', self::NOW - 700);
        $this->event('infoaction', 'Kaida consumes Skooma.', self::NOW - 600);
        $this->event('itemfound', 'Lynly Star-Sung drank Honningbrew Mead', self::NOW - 500);

        $events = RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'Lynly Star-Sung', 'Kaida');
        $gifts = array_values(array_filter($events, fn($e) => $e['action'] === 'gift'));
        $this->assertSame(['Sweetroll'], array_column($gifts, 'item'), 'only the player\'s gift to her');
        $this->assertSame(2, $gifts[0]['value']);
        $consumed = array_column(array_filter($events, fn($e) => $e['action'] === 'consume'), 'item');
        sort($consumed);
        $this->assertSame(['Honningbrew Mead', 'Nord Mead'], $consumed, 'what she drank; the player\'s skooma is not hers');

        $d = self::npc(['social' => 0.8]);
        $first = RelationshipDynamics::processItemEvents($d, $GLOBALS['gameRequest'], 'Lynly Star-Sung', 'Kaida', 'Stoic');
        $this->assertCount(1, $first['gift']);
        $this->assertCount(2, $first['consumable']);
        $this->assertSame([], RelationshipDynamics::processItemEvents($d, $GLOBALS['gameRequest'], 'Lynly Star-Sung', 'Kaida', 'Stoic'),
            'the same rows the next request: counted once');
        $this->event('itemfound', 'Kaida gave 1 Lavender to Lynly Star-Sung,(value 1 gold)', self::NOW - 100);
        $again = RelationshipDynamics::processItemEvents($d, $GLOBALS['gameRequest'], 'Lynly Star-Sung', 'Kaida', 'Stoic');
        $this->assertSame(['Lavender'], array_column($again['gift'], 'item'), 'a new row after her watermark');
        $this->assertSame([], $this->db->failures);
    }

    public function testADrinkSheLikesFeelsBetterAndWearsOffExactly(): void
    {
        // Mead reads social .8 / domestic .3: Lynly (social +.8) enjoys it, a loner (social -.8) does not
        $fan = self::npc(['social' => 0.8]);
        $loner = self::npc(['social' => -0.8]);
        $a = RelationshipDynamics::processConsumable($fan, 'Nord Mead', 'Stoic', 'Lynly Star-Sung');
        $b = RelationshipDynamics::processConsumable($loner, 'Nord Mead', 'Stoic', 'Aela the Huntress');
        $this->assertGreaterThan($b['comfort'] * 1.5, $a['comfort'], 'the same ale, felt differently ' . json_encode([$a, $b]));
        $this->assertEqualsWithDelta(54.5, $fan['dimensions']['maturity']['baseline'], 1e-9, 'the permanent cost (ale -0.5) stays either way');
        $this->assertEqualsWithDelta($a['comfort'], RelationshipDynamics::heldTemporaryOffset($fan, 'comfort'), 1e-9, 'held until it wears off');

        // Half an hour of game time: gone, exactly (ale lasts 0.5 game hours)
        $before = $fan['dimensions']['comfort']['x'];
        $this->clock(self::NOW + RelationshipDynamics::GAMETS_PER_HOUR);
        $this->assertSame(1, RelationshipDynamics::tickConsumableExpiry($fan));
        $this->assertEqualsWithDelta($before - $a['comfort'], $fan['dimensions']['comfort']['x'], 1e-9);
        $this->assertSame([], $fan['_active_consumables']);
        $this->assertEqualsWithDelta(0.0, RelationshipDynamics::heldTemporaryOffset($fan, 'comfort'), 1e-9);
    }

    public function testWhatSheWearsHoldsItsBaselineAsSheValuesItAndTakingItOffStings(): void
    {
        $mara = json_encode(['equipment' => ['amulet' => 'Amulet of Mara', 'amulet_baseid' => '000C891B', 'amulet_keywords' => ['ArmorJewelry'],
            'body' => 'Fur Armor']]);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET metadata = $2::jsonb WHERE npc_name = $1', ['Muiri', $mara]);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET metadata = $2::jsonb WHERE npc_name = $1', ['Aela the Huntress', $mara]);
        $devout = self::npc(['spiritual' => 0.8, 'sacred' => 0.6]);
        $cold = self::npc(['spiritual' => -0.6]);
        $r1 = RelationshipDynamics::processItemEvents($devout, $GLOBALS['gameRequest'], 'Muiri', 'Kaida', 'Stoic');
        $r2 = RelationshipDynamics::processItemEvents($cold, $GLOBALS['gameRequest'], 'Aela the Huntress', 'Kaida', 'Stoic');
        $this->assertSame('equip', $r1['equip'][0]['action']);
        $this->assertGreaterThan(5.0, $devout['dimensions']['comfort']['baseline'] - 50.0, 'the amulet of her faith weighs more (draft: comfort +5)');
        $this->assertLessThan(5.0, $cold['dimensions']['comfort']['baseline'] - 50.0, 'a trinket to her ' . json_encode($r2));
        $held = RelationshipDynamics::heldBaselineOffset($devout, 'comfort');
        $this->assertEqualsWithDelta($devout['dimensions']['comfort']['baseline'] - 50.0, $held, 1e-9, 'baseline drift runs without it');

        // She takes it off: the baseline comes back exactly and the removal stings (on_removal)
        $comfortBefore = $devout['dimensions']['comfort']['x'];
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET metadata = $2::jsonb WHERE npc_name = $1', ['Muiri', json_encode(['equipment' => ['body' => 'Fur Armor']])]);
        $off = RelationshipDynamics::processItemEvents($devout, $GLOBALS['gameRequest'], 'Muiri', 'Kaida', 'Stoic');
        $this->assertSame('unequip', $off['equip'][0]['action']);
        $this->assertEqualsWithDelta(50.0, $devout['dimensions']['comfort']['baseline'], 1e-9);
        $this->assertLessThan($comfortBefore, $devout['dimensions']['comfort']['x']);
        $this->assertSame([], $devout['_equipped_modifiers']);
        $this->assertSame([], $this->db->failures);
    }
}
