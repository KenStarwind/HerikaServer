<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Answers the reads the attraction path makes outside the code under test: the NPC's
 * core_npc_master row (profile auto-generation, facet preferences, gender), the stored RelDyn
 * config row and core_player (the player profile). It hands back stored rows only.
 */
final class RelDynAttractionRowDb
{
    /** @var array<string, array> lower(npc_name) => core_npc_master row as PostgreSQL returns it */
    public array $rows = [];
    /** @var array<string, string> core_player id => value */
    public array $player = [];
    public ?array $config = null;
    public array $queries = [];

    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }

    public function fetchOne($sql, $params = null)
    {
        $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
        $this->queries[] = $sql;
        if (str_contains($sql, "FROM conf_opts WHERE id = 'relationship_dynamics_config'")) {
            return $this->config === null ? [] : ['value' => json_encode($this->config)];
        }
        if (str_contains($sql, 'FROM core_npc_master') && is_array($params)) {
            return $this->rows[strtolower((string) $params[0])] ?? [];
        }
        if (preg_match("/FROM core_player WHERE id = '(\w+)'/", $sql, $m)) {
            return isset($this->player[$m[1]]) ? ['value' => $this->player[$m[1]]] : [];
        }
        return [];
    }

    public function fetchAll($sql) { $this->queries[] = $sql; return []; }
    public function execQuery($sql) { $this->queries[] = $sql; return false; }
}

/**
 * Attraction Matrix (MDD §2, §1.4, §6.2, §8; decisions 2026-09-24 §9) on NPC x player-profile
 * tables. NPCs are CHIM 3.4.1 core_npc_master rows (class, factions, skills): temperament,
 * attachment, traits and facet preferences all come from the real auto-generation; player
 * profiles follow the RelDynPlayer::profile() contract.
 *
 * Ken: "Aela would have an affinity for a strong warrior type or a formidable druid, but a bard
 * or a scholar she could tolerate but probably wouldn't feel passion towards."
 */
final class RelDynAttractionMatrixTest extends TestCase
{
    private const AELA = 'Aela the Huntress';
    private $savedDb = null;
    private bool $hadDb = false;
    private RelDynAttractionRowDb $db;
    private $prevErrorLog = null;
    private string $errorLog;

    protected function setUp(): void
    {
        $this->hadDb = array_key_exists('db', $GLOBALS);
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->db = new RelDynAttractionRowDb();
        foreach (self::npcRows() as $name => $row) {
            $this->db->rows[strtolower($name)] = array_merge($row, ['npc_name' => $name]);
        }
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdattr');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        if ($this->hadDb) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ fixture

    /** A core_npc_master row as CHIM 3.4.1 registers an NPC (processor/comm.php addnpc). */
    private static function row(string $class, array $factions, array $skills, string $race = 'NordRace', string $personality = '', string $gender = 'female'): array
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $name) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $name];
        return [
            'gender' => $gender, 'race' => $race, 'voiceid' => 'FemaleEvenToned', 'personality' => $personality,
            'speechstyle' => '', 'core' => '', 'npc_static_bio' => '',
            'metadata' => json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
            'extended_data' => json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f]),
        ];
    }

    private static function npcRows(): array
    {
        return [
            // The Companions' huntress (as in RelDynAsheAelaTest): archery / sneak / light armor
            self::AELA => self::row('Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]),
            'Farengar Secret-Fire' => self::row('Spell Vendor', [], ['destruction' => 50, 'conjuration' => 50, 'enchanting' => 60]),
            'Uthgerd the Unbroken' => self::row('Warrior', [], ['twohanded' => 60, 'heavyarmor' => 50, 'block' => 40]),
            'Ysolda' => self::row('Food Vendor', [], ['speech' => 40]),
            // A cautious noble: jarl faction, proud and wary
            'Jarl Hrothmund' => self::row('Citizen', ['JobJarlFaction'], ['speech' => 50], 'NordRace', 'Arrogant, haughty and cautious.', 'male'),
        ];
    }

    /**
     * Player profiles in the RelDynPlayer::profile() contract shape (archetypes = identity,
     * archetype_raw = magnitude as that archetype, pillars 0..1).
     */
    private static function player(string $kind, array $facts = []): array
    {
        $p = [
            'warrior'    => [['warrior' => 0.9, 'hunter' => 0.3], ['strength' => 0.85, 'status' => 0.5, 'competence' => 0.7], ['warrior' => 0.85, 'hunter' => 0.3]],
            'druid'      => [['druid' => 0.85, 'healer' => 0.5, 'mage' => 0.3], ['strength' => 0.6, 'status' => 0.4, 'competence' => 0.6], ['druid' => 0.75, 'healer' => 0.45, 'mage' => 0.25]],
            // accomplished, but a bard / a scholar: Aela tolerates them
            'bard'       => [['bard' => 0.9, 'noble' => 0.3], ['strength' => 0.2, 'status' => 0.6, 'competence' => 0.7], ['bard' => 0.8, 'noble' => 0.25]],
            'scholar'    => [['scholar' => 0.9, 'mage' => 0.7], ['strength' => 0.35, 'status' => 0.6, 'competence' => 0.7], ['scholar' => 0.8, 'mage' => 0.6]],
            'newbie'     => [['warrior' => 0.1], ['strength' => 0.1, 'status' => 0.0, 'competence' => 0.05], ['warrior' => 0.05]],
            // strong, but a nobody yet: hookup material for Aela (MDD 8.2 B)
            'newwarrior' => [['warrior' => 0.8], ['strength' => 0.8, 'status' => 0.0, 'competence' => 0.2], ['warrior' => 0.75]],
        ][$kind];
        // Companions standing (Aela's status marker, MDD 2.3): evidence as RelDynPlayer keys it
        $companions = ['warrior' => 3, 'druid' => 2, 'newbie' => 0, 'newwarrior' => 0][$kind] ?? null;
        return [
            'archetypes' => array_replace(array_fill_keys(RelDynAttraction::PLAYER_ARCHETYPES, 0.0), $p[0]),
            'archetype_raw' => array_replace(array_fill_keys(RelDynAttraction::PLAYER_ARCHETYPES, 0.0), $p[2]),
            'evidence' => $companions === null ? [] : ['stat:the companions quests completed' => $companions],
            'pillars' => $p[1] + ['beauty' => null],
            'facts' => $facts,
            'known' => true,
        ];
    }

    /** The NPC's RelDyn state after the real profile auto-generation (prerequest's ensureLoveLanguage). */
    private function npc(string $name, array $extra = [], ?float $coreAff = null): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::ensureLoveLanguage($name, $d);
        RelDynFacets::ensurePreferences($name, $d);
        if ($coreAff !== null) self::setCoreAff($d, $coreAff);
        return array_replace($d, $extra);
    }

    private static function setCoreAff(array &$d, float $coreAff): void
    {
        $d['_aff_mirror_x'] = ($coreAff + 100.0) / 2.0;
        $d['dimensions']['affinity']['x'] = ($coreAff + 100.0) / 2.0;
    }

    private function storeConfig(array $config): void
    {
        $this->db->config = array_merge(RelationshipDynamics::defaultConfig(), $config);
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ the matrix, NPC x player

    /** [npc, player, passes, friendzoned, outcome] */
    public static function matrix(): array
    {
        return [
            'Aela x warrior: drawn'           => [self::AELA, 'warrior', true, false, 'drawn'],
            'Aela x druid: drawn'             => [self::AELA, 'druid', true, false, 'drawn'],
            'Aela x bard: tolerated, no passion'    => [self::AELA, 'bard', false, true, 'friendzone'],
            'Aela x scholar: tolerated, no passion' => [self::AELA, 'scholar', false, true, 'friendzone'],
            'Aela x newbie: nothing'          => [self::AELA, 'newbie', false, false, 'unattracted'],
            'Aela x new warrior: hookup'      => [self::AELA, 'newwarrior', true, false, 'hookup'],
            'Uthgerd x warrior'               => ['Uthgerd the Unbroken', 'warrior', true, false, 'drawn'],
            'Uthgerd x newbie'                => ['Uthgerd the Unbroken', 'newbie', false, false, 'unattracted'],
            'Farengar x scholar: slow burn'   => ['Farengar Secret-Fire', 'scholar', false, false, 'prebond'],
            'Farengar x bard: friend'         => ['Farengar Secret-Fire', 'bard', false, true, 'friendzone'],
            // Rulings §11: only an absent required pillar zeroes passion. A mage reads some
            // strength in a strong warrior (the generic share of the pillar), so the
            // bond-gated slow burn is open, faintly (testOnlyAnAbsentPillarZeroes)
            'Farengar x warrior: faint slow burn' => ['Farengar Secret-Fire', 'warrior', false, false, 'prebond'],
            'Ysolda x bard'                   => ['Ysolda', 'bard', true, false, 'drawn'],
            'Jarl x newbie'                   => ['Jarl Hrothmund', 'newbie', false, false, 'unattracted'],
        ];
    }

    #[DataProvider('matrix')]
    public function testNpcByPlayerProfile(string $npc, string $player, bool $passes, bool $friendzoned, string $outcome): void
    {
        $a = RelationshipDynamics::attractionFor($npc, $this->npc($npc), self::player($player));
        $this->assertSame($passes, $a['passes'], $a['reason']);
        $this->assertSame($friendzoned, $a['friendzoned'], $a['reason']);
        $this->assertSame($outcome, $a['outcome'], $a['reason']);
        // contract shape
        foreach (['score', 'passes', 'friendzoned', 'pillars', 'ceiling_tier', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $a);
        }
        $this->assertGreaterThanOrEqual(0.0, $a['score']);
        $this->assertLessThanOrEqual(1.0, $a['score']);
        foreach (RelDynAttraction::PILLARS as $p) {
            foreach (['score', 'weight', 'rigidity', 'pass'] as $k) $this->assertArrayHasKey($k, $a['pillars'][$p]);
        }
        $this->assertTrue($a['ceiling_tier'] === null || is_string($a['ceiling_tier']));
    }

    /**
     * Rulings §11: a required pillar closes the gate only where it is absent to the NPC. What a
     * mage reads of a strong warrior's strength is little, not nothing: the slow burn is open,
     * faintly, far below the scholar's; a bard's is absent to him (friendzone, above).
     */
    public function testOnlyAnAbsentPillarZeroes(): void
    {
        $at = fn(string $kind) => RelationshipDynamics::attractionFor('Farengar Secret-Fire', $this->npc('Farengar Secret-Fire'), self::player($kind));
        $scholar = $at('scholar');
        $w = $at('warrior');
        $this->assertGreaterThan(0.0, $w['passion_mult']);
        $this->assertLessThan(0.25 * $scholar['passion_mult'], $w['passion_mult'], 'faint next to the scholar');
        $this->assertFalse($w['pillars']['strength']['pass'], 'still short of the tier bar');
        $b = $at('bard');
        $this->assertLessThanOrEqual(RelDynAttraction::config()['passion']['gate_absent_below'], $b['pillars']['strength']['score']);
        $this->assertSame(0.0, $b['passion_mult']);
        // Aela and the bard: her lens sees no strength in him at all
        $b = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA), self::player('bard'));
        $this->assertLessThanOrEqual(RelDynAttraction::config()['passion']['gate_absent_below'], $b['pillars']['strength']['score']);
        $this->assertSame(0.0, $b['passion']['gates']['flexible']);
        $this->assertSame(0.0, $b['passion_mult']);
    }

    public function testPillarsAreNpcSubjectiveThroughTheArchetypeLens(): void
    {
        $aela = RelDynAttraction::definition(self::AELA, $this->npc(self::AELA));
        $this->assertSame('Ranger', $aela['archetype'], 'Hunter class');
        $this->assertSame('Independent', $this->npc(self::AELA)['inferred_temperament']);
        foreach (['warrior', 'hunter', 'druid'] as $valued) {
            $this->assertEqualsWithDelta(1.0, $aela['lens']['strength'][$valued], 1e-9, "Aela values {$valued} strength");
        }
        foreach (['bard', 'scholar', 'mage'] as $invisible) {
            $this->assertSame(0.0, $aela['lens']['strength'][$invisible], "a {$invisible}'s strength is invisible to Aela");
        }
        $farengar = RelDynAttraction::definition('Farengar Secret-Fire', $this->npc('Farengar Secret-Fire'));
        $this->assertEqualsWithDelta(1.0, $farengar['lens']['strength']['mage'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $farengar['lens']['strength']['scholar'], 1e-9);
        $this->assertLessThan(0.1, $farengar['lens']['strength']['warrior']);

        // Same player, two readers: the warrior is strong to Aela, not to Farengar
        $warrior = self::player('warrior');
        $toAela = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA), $warrior);
        $toFarengar = RelationshipDynamics::attractionFor('Farengar Secret-Fire', $this->npc('Farengar Secret-Fire'), $warrior);
        $this->assertGreaterThan(0.8, $toAela['pillars']['strength']['score']);
        $this->assertLessThan(0.3, $toFarengar['pillars']['strength']['score']);
        $this->assertSame('warrior', $toAela['valued']);
    }

    public function testDeterministic(): void
    {
        $d = $this->npc(self::AELA);
        $a = RelationshipDynamics::attractionFor(self::AELA, $d, self::player('druid'));
        $b = RelationshipDynamics::attractionFor(self::AELA, $d, self::player('druid'));
        $this->assertSame($a, $b);
    }

    public function testSpeechLiftsEveryKnownPillarUpToFifteenPercent(): void
    {
        $d = $this->npc('Ysolda');
        $mute = RelationshipDynamics::attractionFor('Ysolda', $d, self::player('scholar'));
        $silver = RelationshipDynamics::attractionFor('Ysolda', $d,
            self::player('scholar', ['skills' => ['value' => ['speech' => 100], 'source' => 'test']]));
        $half = RelationshipDynamics::attractionFor('Ysolda', $d, array_merge(self::player('scholar'), ['speech' => 0.5]));
        foreach (['strength', 'status', 'competence'] as $p) {
            $base = $mute['pillars'][$p]['score'];
            $this->assertEqualsWithDelta(min(1.0, $base * 1.15), $silver['pillars'][$p]['score'], 1e-3, "{$p} x1.15 at Speech 100");
            $this->assertEqualsWithDelta(min(1.0, $base * 1.075), $half['pillars'][$p]['score'], 1e-3, "{$p} x1.075 at Speech 50");
        }
        $this->assertSame(0.5, $silver['pillars']['beauty']['score'], 'an unknown beauty is not boosted');
        $this->assertFalse($silver['pillars']['beauty']['known']);
    }

    public function testRigidityRules(): void
    {
        $d = $this->npc('Uthgerd the Unbroken');
        $bar = RelDynAttraction::config()['pass_threshold']
            * (1.0 - RelDynAttraction::config()['tolerance_threshold_cut'] * RelationshipDynamics::calculateEffectiveTolerance($d));
        $a = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player('warrior'));
        $this->assertEqualsWithDelta($bar, $a['pillars']['competence']['bar'], 1e-4, 'rigid: the pass threshold (tolerance-adjusted)');
        $this->assertEqualsWithDelta($bar * 0.6, $a['pillars']['strength']['bar'], 1e-4, 'flexible: 0.6 x the bar (plan §4)');

        // soft always passes; irrelevant passes and weighs nothing
        $d['attraction_overrides'] = ['rigidity' => ['strength' => 'irrelevant', 'competence' => 'soft']];
        $b = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player('newbie'));
        $this->assertTrue($b['pillars']['strength']['pass']);
        $this->assertSame(0.0, $b['pillars']['strength']['weight']);
        $this->assertTrue($b['pillars']['competence']['pass']);
        $this->assertLessThan($bar, $b['pillars']['competence']['score']);
    }

    /**
     * MDD 1.4 openness (tolerance of a failed pillar): on the tier axis low = hard block, medium
     * / high tolerate a near miss (2x effort). On passion (rulings §11) it is how soon a
     * required pillar's gate opens fully; no step ceiling cut, and only a pillar that is absent
     * to the NPC closes the gate.
     */
    public function testOpennessTolerance(): void
    {
        $d = $this->npc('Uthgerd the Unbroken', ['attachment_style' => 'secure']);
        $d['attraction_overrides'] = ['rigidity' => ['beauty' => 'soft', 'strength' => 'rigid', 'status' => 'soft', 'competence' => 'soft'],
            'lens_share' => ['strength' => 0.0]];
        $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player('warrior'))['pillars']['strength']['bar'];
        $near = self::player('warrior');
        $near['pillars']['strength'] = $bar - 0.05;       // just short
        $far = self::player('warrior');
        $far['pillars']['strength'] = $bar - 0.3;         // far off the mark

        $at = function (string $band, array $player) use ($d) {
            $d['attraction_overrides']['openness'] = $band;
            return RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, $player);
        };
        $low = $at('low', $near);
        $this->assertFalse($low['pillars']['strength']['pass'], 'low openness: hard block on the tier axis');
        $this->assertFalse($low['pillars']['strength']['tolerated']);

        $medium = $at('medium', $near);
        $this->assertTrue($medium['pillars']['strength']['tolerated']);
        $high = $at('high', $near);
        $this->assertTrue($high['pillars']['strength']['tolerated']);

        // Passion: a near miss is not an absence; the demanding NPC's gate is only partly open
        foreach (['low' => $low, 'medium' => $medium, 'high' => $high] as $band => $r) {
            $this->assertTrue($r['passes'], "{$band}: {$r['reason']}");
            $this->assertNull($r['passion_cap'], "{$band}: no step ceiling cut (rulings §11)");
        }
        $this->assertGreaterThan(0.0, $low['passion']['gates']['strength']);
        $this->assertLessThan($medium['passion']['gates']['strength'], $low['passion']['gates']['strength'], 'low openness opens slower');
        $this->assertLessThanOrEqual($high['passion']['gates']['strength'], $medium['passion']['gates']['strength']);
        $this->assertLessThan($medium['passion_mult'], $low['passion_mult']);

        $farHigh = $at('high', $far);
        $this->assertSame(0.0, $farHigh['passion']['gates']['strength'], 'far off the mark: absent to her, even at high openness');
        $this->assertFalse($farHigh['passes']);
        $this->assertSame(0.0, $farHigh['passion_mult']);

        // 2x the significant interactions to advance with a tolerated fail
        $plain = RelDynAttraction::interactionsNeeded($d, $medium['gate'], 'medium', false);
        $tolerated = RelDynAttraction::interactionsNeeded($d, $medium['gate'], 'medium', true);
        $this->assertEqualsWithDelta(2 * $plain, $tolerated, 1, 'twice the interactions (rounded)');
        $this->assertSame($tolerated, $medium['pending']['need'], 'the lift of a tolerated pass asks for the doubled count');
    }

    public function testOpennessSourcesTemperamentPresetEditorOverride(): void
    {
        $this->assertSame('medium', RelDynAttraction::definition(self::AELA, $this->npc(self::AELA))['openness'],
            'Aela preset (memory: medium, not the Independent default low)');
        $this->assertSame('low', RelDynAttraction::definition('Farengar Secret-Fire', $this->npc('Farengar Secret-Fire'))['openness'],
            'MDD 1.3: Guarded = low');
        $this->assertSame('high', RelDynAttraction::definition('Ysolda', $this->npc('Ysolda'))['openness'], 'MDD 1.3: Anxious = high');
        $this->assertSame('high', RelDynAttraction::definition(self::AELA, $this->npc(self::AELA, ['openness' => 'high']))['openness'], 'editor dropdown');
        $this->assertSame('low', RelDynAttraction::definition(self::AELA, $this->npc(self::AELA, ['openness' => 0.25]))['openness'], 'numeric -> nearest band');
    }

    // ------------------------------------------------------------------ relationship preference filter

    public function testPreferenceFilter(): void
    {
        $warrior = self::player('warrior');
        $pref = fn(string $p, float $aff = 0.0) => RelationshipDynamics::attractionFor(self::AELA,
            $this->npc(self::AELA, ['relationship_preference' => $p], $aff), $warrior);

        $aro = $pref('aromantic');
        $this->assertFalse($aro['passes']);
        $this->assertTrue($aro['friendzoned'], 'aromantic: deep friendship, no romance');
        $this->assertSame(20.0, $aro['passion_cap']);
        $this->assertSame(['crush', 'romantic'], $aro['blocked_types']);

        $this->assertFalse($pref('not_interested')['passes']);

        $ace = $pref('asexual');
        $this->assertTrue($ace['passes'], 'asexual: romance possible');
        $this->assertFalse($ace['intimacy_allowed'], 'asexual: no handoff to Sharmat');

        $unc = $pref('uncommitted');
        $this->assertSame(1, $unc['romance']['allowed'], 'uncommitted: crush, never commitment');

        $demiLow = $pref('demisexual', 30.0);
        $this->assertFalse($demiLow['passes']);
        $this->assertTrue($demiLow['prebond'], 'demisexual: a slow burn toward the bond');
        $this->assertSame(0, $demiLow['romance']['allowed']);
        $this->assertSame('bond', $demiLow['gate']);
        $demiHigh = $pref('demisexual', 65.0);
        $this->assertTrue($demiHigh['passes'], 'demisexual: past the bond threshold');
        $this->assertSame(2, $demiHigh['romance']['allowed']);
        $this->assertFalse($demiHigh['intimacy_allowed'], 'demisexual intimacy: peak affinity 100 first');
        $d = $this->npc(self::AELA, ['relationship_preference' => 'demisexual'], 70.0);
        $d['_attraction_state'] = ['depth' => 'close_friend', 'romance' => 2, 'pending' => null, 'peak_core_aff' => 100.0];
        $this->assertTrue(RelationshipDynamics::attractionFor(self::AELA, $d, $warrior)['intimacy_allowed'], 'peak 100 and Fond+');

        // The settings page's Type Filter toggle switches the preference filter off
        $this->storeConfig(['type_filter_enabled' => false]);
        $this->assertTrue($pref('aromantic')['passes']);
        $this->assertNull($pref('aromantic')['preference']);
    }

    public function testGenderPreference(): void
    {
        $d = $this->npc('Uthgerd the Unbroken');   // female row
        $d['attraction_overrides'] = ['gender_pref' => 'heterosexual'];
        $female = self::player('warrior', ['gender' => ['value' => 'female', 'source' => 'test']]);
        $male = self::player('warrior', ['gender' => ['value' => 'male', 'source' => 'test']]);
        $this->assertFalse(RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, $female)['passes']);
        $this->assertTrue(RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, $male)['passes']);
        $this->assertTrue(RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player('warrior'))['passes'],
            'player gender unknown: no gate');
    }

    // ------------------------------------------------------------------ overrides, config, degraded modes

    public function testPerNpcOverrideAndEditorProfile(): void
    {
        // Someone rewrote Aela: she now values bards
        $d = $this->npc(self::AELA, ['attraction_overrides' => ['lens' => ['strength' => ['bard' => 1.0]]]]);
        $toBard = RelationshipDynamics::attractionFor(self::AELA, $d, self::player('bard'));
        $toWarrior = RelationshipDynamics::attractionFor(self::AELA, $d, self::player('warrior'));
        $this->assertTrue($toBard['passes']);
        $this->assertGreaterThan($toWarrior['pillars']['strength']['score'], $toBard['pillars']['strength']['score']);
        $this->assertSame('override', RelDynAttraction::definition(self::AELA, $d)['sources']['lens.strength']);
        // The PR 11 editor's intimacy gate
        $e = $this->npc(self::AELA, ['attraction_profile' => ['intimacy_gate' => 'bond']]);
        $this->assertSame('bond', RelDynAttraction::definition(self::AELA, $e)['gate']);
    }

    public function testConfigDriven(): void
    {
        $this->assertFalse(RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA), self::player('bard'))['passes']);
        $this->assertEqualsWithDelta(1.0, RelDynAttraction::definition(self::AELA, $this->npc(self::AELA))['lens']['strength']['druid'], 1e-9);
        $cfg = RelDynAttraction::defaults();
        $cfg['archetype_profiles']['Ranger']['rigidity']['strength'] = 'irrelevant';   // a Ranger who does not care for strength
        $cfg['facet_archetypes']['nature'] = ['hunter' => 0.8];                        // nature no longer speaks for druids
        $this->storeConfig(['attraction' => $cfg]);
        $this->assertTrue(RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA), self::player('bard'))['passes']);
        $this->assertLessThan(1.0, RelDynAttraction::definition(self::AELA, $this->npc(self::AELA))['lens']['strength']['druid']);
    }

    public function testNoPlayerDataOrMatrixOffGatesNothing(): void
    {
        $unknown = self::player('newbie');
        $unknown['known'] = false;
        $a = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA), $unknown);
        $this->assertTrue($a['passes']);
        $this->assertNull($a['passion_cap']);
        $this->assertSame([], $a['blocked_types']);

        $this->storeConfig(['attraction_matrix_enabled' => false]);
        $b = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA), self::player('newbie'));
        $this->assertTrue($b['passes']);
        $this->assertSame(1.0, $b['passion_mult']);
    }

    /**
     * The relationship preference is the NPC's own: it filters romance whether or not the
     * Matrix can judge the player (no player data yet, or the Matrix switched off).
     */
    public function testThePreferenceFilterHoldsWithoutPlayerData(): void
    {
        $unknown = ['known' => false];
        $aro = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA, ['relationship_preference' => 'aromantic']), $unknown);
        $this->assertSame(['crush', 'romantic'], $aro['blocked_types']);
        $this->assertFalse($aro['intimacy_allowed']);
        $this->assertSame(20.0, $aro['passion_cap'], 'no romance: capped like a friendzone');
        $this->assertSame('aromantic', $aro['preference']);

        $unc = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA, ['relationship_preference' => 'uncommitted']), $unknown);
        $this->assertSame(['romantic'], $unc['blocked_types'], 'uncommitted: a crush, never commitment');
        $this->assertNull($unc['passion_cap']);

        $demi = fn(float $aff) => RelationshipDynamics::attractionFor(self::AELA,
            $this->npc(self::AELA, ['relationship_preference' => 'demisexual'], $aff), $unknown)['blocked_types'];
        $this->assertSame(['crush', 'romantic'], $demi(30.0), 'demisexual: nothing before the bond');
        $this->assertSame([], $demi(65.0));

        $this->storeConfig(['attraction_matrix_enabled' => false]);
        $off = RelationshipDynamics::attractionFor(self::AELA, $this->npc(self::AELA, ['relationship_preference' => 'aromantic']), self::player('warrior'));
        $this->assertSame(['crush', 'romantic'], $off['blocked_types'], 'the Type Filter works with the Matrix off');
        $this->assertSame(['crush', 'romantic'], RelationshipDynamics::getBlockedTypes(['_attraction' => $off]));

        $this->storeConfig(['attraction_matrix_enabled' => false, 'type_filter_enabled' => false]);
        $this->assertSame([], RelationshipDynamics::attractionFor(self::AELA,
            $this->npc(self::AELA, ['relationship_preference' => 'aromantic']), $unknown)['blocked_types'], 'Type Filter off');
    }

    public function testFeltTextHasFeelingsNotNumbers(): void
    {
        foreach (['warrior', 'bard', 'newbie', 'newwarrior'] as $kind) {
            $d = $this->npc(self::AELA);
            RelationshipDynamics::updateAttraction(self::AELA, $d, self::player($kind));
            $text = RelDynAttraction::feltText(self::AELA, $d['_attraction']);
            $this->assertNotEmpty($text);
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$kind}: no numbers reach the LLM");
        }
        $d = $this->npc(self::AELA);
        RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('bard'));
        $this->assertStringContainsString('warm, kind deflection', RelDynAttraction::feltText(self::AELA, $d['_attraction']));
    }

    // ------------------------------------------------------------------ passion gated by attraction + attachment

    private function evalItem(string $npc, array $signals, float $significance, bool $positive, int $gamets): array
    {
        return [
            'v' => 1, 'npc' => $npc, 'npc_id' => 7, 'gamets' => $gamets, 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => ['quality_time'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => $positive, 'summary' => "exchange {$gamets}",
        ];
    }

    /** Aela with the attraction of this request recorded (prerequest's updateAttraction). */
    private function aelaFor(string $kind, array $extra = []): array
    {
        $d = $this->npc(self::AELA, $extra, 20.0);
        RelationshipDynamics::updateAttraction(self::AELA, $d, self::player($kind));
        return $d;
    }

    public function testLegacyPassionGainIsScaledByAttraction(): void
    {
        $warrior = $this->aelaFor('warrior');
        $bard = $this->aelaFor('bard');
        $gain = RelationshipDynamics::calculatePassionGain($warrior, RelationshipDynamics::LL_WORDS);
        $this->assertEqualsWithDelta($gain, RelationshipDynamics::calculatePassionGain($bard, RelationshipDynamics::LL_WORDS), 1e-9,
            'same raw gain before the attraction gate');
        $mw = RelationshipDynamics::attractionPassionMult(self::AELA, $warrior);
        $mb = RelationshipDynamics::attractionPassionMult(self::AELA, $bard);
        $this->assertGreaterThan(0.3, $mw, 'the warrior stirs Aela');
        $this->assertSame(0.0, $mb, 'the bard: her required strength is absent, 100 x 0 = 0 (rulings §11)');
        $a = $warrior['_attraction']['passion'];
        $this->assertEqualsWithDelta($a['modifier'] * $a['gate_product'] * RelDynAttraction::config()['passion']['attachment_mult']['avoidant'],
            $mw, 1e-3, 'modifier x gates x attachment (Aela is avoidant)');
    }

    public function testAttachmentStyleSetsThePace(): void
    {
        $anx = $this->aelaFor('warrior', ['attachment_style' => 'anxious']);
        $avo = $this->aelaFor('warrior', ['attachment_style' => 'avoidant']);
        $sec = $this->aelaFor('warrior', ['attachment_style' => 'secure']);
        $m = fn($d) => RelationshipDynamics::attractionPassionMult(self::AELA, $d);
        $this->assertEqualsWithDelta(1.3 / 0.7, $m($anx) / $m($avo), 1e-3, 'anxious attaches fast, avoidant slow');
        $this->assertGreaterThan($m($sec), $m($anx));
        $this->assertLessThan($m($sec), $m($avo));
    }

    public function testEvalPassionSignalIsGatedAndFriendzoneCappedWhileAffinityStillGrows(): void
    {
        $this->storeConfig(['log_enabled' => true]);
        $warrior = $this->aelaFor('warrior');
        $bard = $this->aelaFor('bard');
        $this->assertTrue($bard['_attraction']['friendzoned']);
        RelationshipDynamics::setPassion($warrior, 15.0);
        RelationshipDynamics::setPassion($bard, 19.5);   // just under the cap
        $affBefore = RelationshipDynamics::getCoreAffinity($bard);
        $t = 1000;
        $bardGains = [];
        for ($i = 0; $i < 30; $i++) {
            $t += 500;
            RelationshipDynamics::processEvalContractItem(self::AELA, $this->evalItem(self::AELA, ['passion' => 10, 'affinity' => 4], 0.4, true, $t), $warrior);
            $before = RelationshipDynamics::getPassion($bard);
            RelationshipDynamics::processEvalContractItem(self::AELA, $this->evalItem(self::AELA, ['passion' => 10, 'affinity' => 4], 0.4, true, $t), $bard);
            $bardGains[] = RelationshipDynamics::getPassion($bard) - $before;
        }
        $pw = RelationshipDynamics::getPassion($warrior);
        $pb = RelationshipDynamics::getPassion($bard);
        $this->assertGreaterThan(20.0, $pw, 'the warrior: passion builds');
        $this->assertLessThanOrEqual(20.0, $pb, 'the bard: friendzone hard cap (MDD 6.2)');
        $this->assertSame(0.0, max($bardGains), 'the bard: the gate is closed, not one eval adds passion (rulings §11)');
        $this->assertGreaterThan($pb, $pw);
        $this->assertGreaterThan($affBefore, RelationshipDynamics::getCoreAffinity($bard), 'friendzone: affinity can still grow');
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringContainsString('attraction x', $log, 'the gate shows in the eval math line');
    }

    public function testHardCapHoldsForEveryPassionWriter(): void
    {
        $d = $this->aelaFor('bard');
        RelationshipDynamics::addPassion($d, 50.0, 'love_match');
        $this->assertSame(20.0, RelationshipDynamics::getPassion($d), 'addPassion');
        RelationshipDynamics::applyDelta('passion', $d, 30.0, $d['inferred_temperament']);
        $this->assertSame(20.0, RelationshipDynamics::getPassion($d), 'applyDelta');
        // Friendzone begins while passion runs high: it drops to the cap
        $e = $this->aelaFor('warrior');
        RelationshipDynamics::setPassion($e, 70.0);
        RelationshipDynamics::updateAttraction(self::AELA, $e, self::player('bard'));
        $this->assertSame(20.0, RelationshipDynamics::getPassion($e));
        // ...and the friendzone breaks when the visceral checks are met later
        RelationshipDynamics::updateAttraction(self::AELA, $e, self::player('warrior'));
        $this->assertFalse($e['_attraction']['friendzoned']);
        RelationshipDynamics::addPassion($e, 30.0, 'love_match');
        $this->assertGreaterThan(20.0, RelationshipDynamics::getPassion($e));
    }

    // ------------------------------------------------------------------ tier ceiling + significant interactions

    public function testCeilingLiftNeedsSignificantInteractions(): void
    {
        // A strong newcomer: Aela's hookup (crush open, no commitment, depth capped at Fond)
        $d = $this->npc(self::AELA, [], 40.0);
        $a = RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newwarrior'));
        $this->assertSame('close_friend', $a['allowed_tier']);
        $this->assertSame(1, $a['romance']['allowed']);
        $this->assertSame(0, $a['romance']['effective'], 'lifted, not yet earned');
        $this->assertSame(['crush', 'romantic'], $d['_attraction']['blocked_types']);
        $this->assertSame('friend', $a['ceiling_tier'], 'where the bond already is');
        $need = $a['pending']['need'];
        $this->assertGreaterThanOrEqual(2, $need, 'memory: Aela transitions in 2-3 interactions');
        $this->assertLessThanOrEqual(3, $need);

        $t = 1000;
        // Pleasant chat (significance 0.33) and a significant quarrel do not count
        RelationshipDynamics::processEvalContractItem(self::AELA, $this->evalItem(self::AELA, ['affinity' => 2], 0.33, true, $t += 100), $d);
        RelationshipDynamics::processEvalContractItem(self::AELA, $this->evalItem(self::AELA, ['affinity' => -2], 0.9, false, $t += 100), $d);
        $this->assertSame(0, $d['_attraction_state']['pending']['have']);
        for ($i = 1; $i < $need; $i++) {
            RelationshipDynamics::processEvalContractItem(self::AELA, $this->evalItem(self::AELA, ['affinity' => 2], 0.8, true, $t += 100), $d);
            $this->assertFalse(RelationshipDynamics::attractionAllowsType(self::AELA, $d, 'crush'), "{$i}/{$need}: not yet");
        }
        RelationshipDynamics::processEvalContractItem(self::AELA, $this->evalItem(self::AELA, ['affinity' => 2], 0.8, true, $t += 100), $d);
        $this->assertTrue(RelationshipDynamics::attractionAllowsType(self::AELA, $d, 'crush'), 'the defining moment came');
        $this->assertFalse(RelationshipDynamics::attractionAllowsType(self::AELA, $d, 'romantic'), 'no commitment without standing');
        $this->assertSame('close_friend', $d['_attraction']['ceiling_tier']);
        $this->assertTrue(RelationshipDynamics::attractionAllowsType(self::AELA, $d, 'platonic'), 'not the Matrix\'s to block');

        // The next request re-evaluates: earned stays earned
        $b = RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newwarrior'));
        $this->assertSame(1, $b['romance']['effective']);
        $this->assertNull($b['pending']);

        // A ceiling that falls applies at once (she lost interest: no significance needed),
        // down to what the bond already was when the Matrix first saw it (a friendship at core
        // affinity 40: grandfathered while core holds it); the crush she earned goes
        $c = RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newbie'));
        $this->assertSame('friend', $c['ceiling_tier']);
        $this->assertSame(['crush', 'romantic'], $c['blocked_types']);
        self::setCoreAff($d, 10.0);   // core lets the friendship fall: the protection goes with it
        $this->assertSame('acquaintance', RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newbie'))['ceiling_tier']);
        self::setCoreAff($d, 40.0);   // and does not come back by itself
        $this->assertSame('acquaintance', RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newbie'))['ceiling_tier']);
    }

    public function testACautiousNobleTakesTenToFifteen(): void
    {
        $d = $this->npc('Jarl Hrothmund', [], 20.0);
        $this->assertSame('Proud', $d['inferred_temperament']);
        $def = RelDynAttraction::definition('Jarl Hrothmund', $d);
        $this->assertSame('bond', $def['gate']);
        $this->assertSame('low', $def['openness']);
        $need = RelDynAttraction::interactionsNeeded($d, $def['gate'], $def['openness'], false);
        $this->assertGreaterThanOrEqual(10, $need);
        $this->assertLessThanOrEqual(15, $need);
    }

    public function testExistingBondIsNotDemotedByTheFirstEvaluation(): void
    {
        $d = $this->npc(self::AELA, ['_core_rel_type' => 'romantic'], 60.0);
        $a = RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('warrior'));
        $this->assertSame([], $a['blocked_types']);
        $this->assertSame('close_friend', $a['ceiling_tier']);
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType(self::AELA, $d), 'core romantic keeps its modifiers');
    }

    public function testTierModifiersAboveTheCeilingAreBlocked(): void
    {
        // Core writes romantic after the Matrix first saw the bond, but Aela is not drawn to this
        // player: no romance modifiers (a romance that already existed is grandfathered: see
        // RelDynAttractionReviewPostgresTest)
        $d = $this->npc(self::AELA, ['_core_rel_type' => 'platonic'], 20.0);
        RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newbie'));
        $d['_core_rel_type'] = 'romantic';
        RelationshipDynamics::updateAttraction(self::AELA, $d, self::player('newbie'));
        $this->assertContains('romantic', $d['_attraction']['blocked_types']);
        $this->assertSame('acquaintance', RelationshipDynamics::getRelationshipType(self::AELA, $d));
        // A flavourless core type: depth from affinity, capped at the ceiling (a depth she had
        // earned through the Matrix, not one it found and grandfathered)
        $e = $this->npc(self::AELA, ['_core_rel_type' => 'neutral'], 80.0);
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType(self::AELA, $e), 'no attraction read yet');
        $e['_attraction_state'] = ['depth' => 'devoted', 'romance' => 0, 'pending' => null, 'peak_core_aff' => 80.0,
            'grandfathered' => ['depth' => '', 'romance' => 0]];
        RelationshipDynamics::updateAttraction(self::AELA, $e, self::player('newwarrior'));
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType(self::AELA, $e), 'close_friend ceiling: friend modifiers');
        $this->assertSame(80.0, RelationshipDynamics::getCoreAffinity($e), 'affinity itself is never capped');
        // Context depth too (plan §5): bonded affinity would be tier 3, the ceiling holds it at 2
        $this->assertSame(2, RelationshipDynamics::getAffinityContextTier($e));
        $this->assertSame(3, RelationshipDynamics::getAffinityContextTier($this->npc(self::AELA, [], 80.0)), 'no attraction read: uncapped');
    }

    public function testLowOpennessReachesTheIckSoonerPastAFailedCheck(): void
    {
        $mk = function (string $openness) {
            $d = $this->npc('Uthgerd the Unbroken', ['openness' => $openness], 10.0);
            RelationshipDynamics::updateAttraction('Uthgerd the Unbroken', $d, self::player('newbie'));
            $d['dimensions']['maturity']['x'] = 50;
            $d['dimensions']['comfort']['x'] = 30;
            RelationshipDynamics::setPassion($d, 5.0);
            $d['_ick_tracker'] = ['total_count' => 10, 'romantic_count' => 5];   // half the attempts romantic
            return $d;
        };
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($mk('low'), 'Bold'), 'low openness: the Ick');
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($mk('medium'), 'Bold'), 'medium: not yet');
    }

    /**
     * Romantic pressure from someone she is drawn to, once her romance axis is open and
     * earned (a crush or more), is not the Ick - whatever comfort / passion read today (their
     * XYZ baselines sit below the Ick's receptivity floors). Not yet earned, friendzoned or
     * unattracted: the Ick's own receptivity rules decide.
     */
    public function testAnEarnedCrushIsReceptiveToRomanticPressure(): void
    {
        $mk = function (string $player, int $earnedRomance) {
            $d = $this->npc(self::AELA, [], 60.0);
            $d['_attraction_state'] = ['depth' => 'close_friend', 'romance' => $earnedRomance, 'pending' => null, 'peak_core_aff' => 60.0];
            RelationshipDynamics::updateAttraction(self::AELA, $d, self::player($player));
            $d['dimensions']['maturity']['x'] = 50;
            $d['dimensions']['comfort']['x'] = 30;                               // below the Ick's comfort floor
            RelationshipDynamics::setPassion($d, 12.0);                          // below its passion floor
            $d['_ick_tracker'] = ['total_count' => 10, 'romantic_count' => 9];
            return $d;
        };
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($mk('warrior', 1), 'Bold'), 'an earned crush: receptive');
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($mk('warrior', 2), 'Bold'));
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($mk('warrior', 0), 'Bold'), 'drawn but not yet earned: the Ick rules');
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($mk('bard', 1), 'Bold'), 'friendzoned: the Ick rules');
    }
}
