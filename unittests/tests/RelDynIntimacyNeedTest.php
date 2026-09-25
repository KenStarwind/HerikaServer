<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql` stand-in holding core_npc_master rows (as PostgreSQL returns them, jsonb as text) and
 * an optional stored RelDyn config; every other read finds nothing. Parameterized and
 * escaped-literal lookups by npc_name are both answered, as the production reads use both.
 */
final class RelDynIntimacyNeedCoreDb
{
    public array $reads = [];

    public function __construct(private array $rows, private ?array $config = null) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (str_contains($sql, "conf_opts WHERE id = 'relationship_dynamics_config'")) {
            return $this->config !== null ? ['value' => json_encode($this->config)] : [];
        }
        if (!str_contains($sql, 'FROM core_npc_master')) return [];
        $this->reads[] = $sql;
        foreach ($this->rows as $name => $row) {
            if ((is_array($params) && strcasecmp((string) ($params[0] ?? ''), $name) === 0)
                || stripos($sql, "lower('" . $this->escape($name) . "')") !== false) {
                return $row;
            }
        }
        return [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * intimacy-need-profile (rulings 2026-09-24 §10): two need axes per NPC, physical and emotional
 * intimacy, from a trait combo (race lifespan, creature, temperament, attachment, traits, love
 * languages, the attraction gate, maturity), deterministic, with per-NPC overrides; they are
 * axes of the fulfillment needs vector (physical only while in play with the player), fed by
 * the eval tags on the game calendar, and the deprived axis the NPC needs most is what
 * the intimacy line (felt steering) and the internal weather feel.
 *
 * NPCs are auto-derived from core_npc_master-shaped rows through the real profile
 * auto-generation (ensureLoveLanguage -> ensureTemperamentProfile) and RelDynIntimacy::ensureNeed;
 * no name checks. Real engine code; the database is a stub holding those rows.
 */
final class RelDynIntimacyNeedTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 400 * self::DAY + 9 * self::HOUR;

    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'RELDYN_INTERACTION_SIGNIFICANCE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdintneed');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ core-shaped NPCs

    /** A core_npc_master row as the plugin registers the NPC (no RelDyn state). */
    private static function row(string $name, string $race, string $class, array $factions, array $skills): array
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $faction) {
            $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
        }
        return [
            'npc_name' => $name, 'gender' => 'female', 'race' => $race, 'voiceid' => 'FemaleEvenToned',
            'personality' => '', 'speechstyle' => '', 'core' => "Roleplay as {$name}", 'npc_static_bio' => '',
            'metadata' => json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
            'extended_data' => json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                'relationships' => ['Player' => ['aff' => 40, 'type' => 'romantic']]]),
        ];
    }

    /** Aela-like: a Nord huntress of the Companions. */
    private static function huntress(string $name = 'Huntress', string $race = 'NordRace'): array
    {
        return self::row($name, $race, 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]);
    }

    /** Ashe-like: a scholar-mage follower. */
    private static function scholar(string $name = 'Scholar', string $race = 'BretonRace'): array
    {
        return self::row($name, $race, 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
            ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55, 'conjuration' => 40, 'restoration' => 35]);
    }

    /** The NPC as RelDyn first meets it: the real profile auto-generation, then the intimacy need. */
    private function derived(array $rows, string $name, array $state = [], ?array $config = null): array
    {
        $GLOBALS['db'] = new RelDynIntimacyNeedCoreDb($rows, $config);
        RelationshipDynamics::clearConfigCache();
        $d = RelationshipDynamics::migrateDimensions(array_replace(RelationshipDynamics::defaultDynamics(), $state));
        RelationshipDynamics::ensureLoveLanguage($name, $d);
        $d['_core_rel_type'] = $d['_core_rel_type'] ?? 'romantic';
        RelDynIntimacy::ensureNeed($name, $d);
        return $d;
    }

    private static function need(array $d): array
    {
        return RelDynIntimacy::need($d);
    }

    private static function evalItem(string $npc, float $gamets, array $tags, float $significance = 0.8): array
    {
        return [
            'v' => 1, 'npc' => $npc, 'npc_id' => 9, 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 1, 'trust' => 0, 'comfort' => 1, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => true,
            'summary' => "{$npc}: " . implode(', ', $tags) . " at {$gamets}",
        ];
    }

    // ------------------------------------------------------------------ derivation

    public function testAelaLikeNordHuntressNeedsPhysicalIntimacyStrongly(): void
    {
        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $n = self::need($d);
        $this->assertSame('Ranger', $d['_profile_autogen']['archetype'], 'auto-derived from the core row (class Hunter)');
        $this->assertGreaterThanOrEqual(0.7, $n['physical'], 'Ken: Aela yes, strongly (physical, visceral)');
        $this->assertGreaterThan($n['emotional'], $n['physical']);
        $signals = $d['_intimacy_need']['signals'];
        $this->assertContains('gate:visceral', $signals, 'a Ranger approaches intimacy physical first');
        $this->assertContains('creature:werewolf', $signals, 'the Circle shares the beast blood');
        $this->assertSame('NordRace', $d['_intimacy_need']['race'], 'the race read once from core');
    }

    public function testAsheLikeGuardedScholarNeedsConnectionOverSex(): void
    {
        $d = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        $n = self::need($d);
        $this->assertSame('Guarded', $d['inferred_temperament']);
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($d), 'Guarded is not avoidant (decisions §12)');
        $this->assertGreaterThanOrEqual(0.6, $n['emotional'], 'Ken: Ashe is about the connection');
        $this->assertLessThanOrEqual(0.25, $n['physical'], 'less about the sex');
        $this->assertGreaterThanOrEqual(0.4, $n['emotional'] - $n['physical']);
        $this->assertContains('gate:bond', $d['_intimacy_need']['signals']);
    }

    public function testLongLivedElvesFeelLessPhysicalUrgency(): void
    {
        // Same class, skills and factions; only the race differs (both mages resolve Guarded).
        $altmer = $this->derived(['Altmer' => self::scholar('Altmer', 'HighElfRace')], 'Altmer');
        $breton = $this->derived(['Breton' => self::scholar('Breton', 'BretonRace')], 'Breton');
        $this->assertSame($breton['inferred_temperament'], $altmer['inferred_temperament']);
        $this->assertLessThan(self::need($breton)['physical'], self::need($altmer)['physical'] + 1e-9);
        $this->assertContains('race:long', $altmer['_intimacy_need']['signals']);
        $this->assertLessThanOrEqual(0.1, self::need($altmer)['physical'], 'an Altmer mage: little physical urgency');

        // A Bosmer huntress is still keen, but less urgent than a Nord one with the same life.
        $bosmer = $this->derived(['Bosmer' => self::huntress('Bosmer', 'WoodElfRace')], 'Bosmer');
        $nord = $this->derived(['Nord' => self::huntress('Nord', 'NordRace')], 'Nord');
        $this->assertLessThan(self::need($nord)['physical'], self::need($bosmer)['physical']);
    }

    public function testTheDerivationIsDeterministicAndPure(): void
    {
        $a = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $b = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $this->assertSame($a['_intimacy_need'], $b['_intimacy_need']);
        $in = RelDynIntimacy::inputs('Huntress', $a);
        $this->assertSame(RelDynIntimacy::derive($in), RelDynIntimacy::derive($in));
        $this->assertFalse(RelDynIntimacy::ensureNeed('Huntress', $a), 'nothing changed: nothing written');
    }

    public function testEachTraitComboInputMovesItsAxis(): void
    {
        $base = ['race' => 'ImperialRace', 'creature' => null, 'temperament' => 'Stoic', 'attachment' => 'secure', 'traits' => [],
            'love_language_primary' => RelationshipDynamics::LL_SERVICE, 'love_language_secondary' => RelationshipDynamics::LL_GIFTS,
            'maturity' => 50.0, 'preference' => null, 'gate' => 'balanced'];
        $d0 = RelDynIntimacy::derive($base);
        $with = fn(array $o) => RelDynIntimacy::derive(array_replace($base, $o));

        $this->assertGreaterThan($d0['emotional'], $with(['attachment' => 'anxious'])['emotional'], 'anxious needs closeness more');
        $avoidant = $with(['attachment' => 'avoidant', 'temperament' => 'Independent']);
        $this->assertLessThan($d0['emotional'], $avoidant['emotional'], 'avoidant expresses less of it');
        $this->assertGreaterThan(0.0, $avoidant['emotional'], '... but it is still there');
        $this->assertGreaterThan($d0['physical'], $with(['temperament' => 'Romantic'])['physical']);
        $this->assertGreaterThan($d0['physical'], $with(['temperament' => 'Playful'])['physical']);
        $this->assertLessThan($d0['physical'], $with(['temperament' => 'Guarded'])['physical']);
        $this->assertGreaterThan($d0['physical'], $with(['love_language_primary' => RelationshipDynamics::LL_TOUCH])['physical']);
        $this->assertGreaterThan($d0['emotional'], $with(['love_language_primary' => RelationshipDynamics::LL_TIME])['emotional']);
        $this->assertGreaterThan($d0['emotional'], $with(['love_language_secondary' => RelationshipDynamics::LL_WORDS])['emotional']);
        $this->assertGreaterThan($d0['emotional'], $with(['traits' => ['insecure']])['emotional']);
        $this->assertGreaterThan($d0['physical'], $with(['maturity' => 15.0])['physical'], 'immature: more physical urgency');
        $this->assertGreaterThan($d0['emotional'], $with(['maturity' => 90.0])['emotional'], 'mature: connection matters more');
        $this->assertGreaterThan($d0['physical'], $with(['gate' => 'visceral'])['physical']);
        $this->assertLessThan($d0['physical'], $with(['gate' => 'bond'])['physical']);
        $this->assertLessThan($d0['physical'], $with(['creature' => 'vampire'])['physical'], 'ageless: no mortal urgency');
        $this->assertGreaterThan($d0['physical'], $with(['creature' => 'werewolf'])['physical'], 'beast blood runs hot');
        $this->assertSame(0.0, $with(['preference' => 'asexual', 'temperament' => 'Romantic'])['physical']);
        $this->assertLessThan($d0['physical'], $with(['preference' => 'demisexual'])['physical']);
    }

    public function testCreatureComesFromCoreRaceOrFactions(): void
    {
        $this->assertSame('vampire', RelDynIntimacy::creatureFromCore('DarkElfRaceVampire', []));
        $this->assertSame('vampire', RelDynIntimacy::creatureFromCore('NordRace', [['name' => 'DLC1VampireFaction', 'rank' => 0]]));
        $this->assertSame('werewolf', RelDynIntimacy::creatureFromCore('NordRace', [['name' => 'WerewolfFaction', 'rank' => 1]]));
        $this->assertNull(RelDynIntimacy::creatureFromCore('NordRace', [['name' => 'WerewolfFaction', 'rank' => -1]]), 'rank -1: not a member');
        $v = $this->derived(['Thrall' => self::row('Thrall', 'NordRaceVampire', 'Warrior', [], ['onehanded' => 50])], 'Thrall');
        $this->assertSame('vampire', $v['_intimacy_need']['creature']);
        $this->assertContains('creature:vampire', $v['_intimacy_need']['signals']);
    }

    public function testConfigTablesAndPerNpcOverrides(): void
    {
        // A config table change is picked up (a stored table replaces the default one)
        $cfg = array_replace(RelationshipDynamics::defaultConfig(), ['intimacy_need' => ['gate' => ['visceral' => ['physical' => -0.3]]]
            + RelDynIntimacy::configDefaults()]);
        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress', [], $cfg);
        $plain = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $this->assertLessThan(self::need($plain)['physical'], self::need($d)['physical']);

        // Named preset from config, then the per-NPC editor override beats it
        $cfg = array_replace(RelationshipDynamics::defaultConfig(), ['intimacy_need' => ['npc_overrides' => ['huntress' => ['emotional' => 0.2]]]
            + RelDynIntimacy::configDefaults()]);
        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress', [], $cfg);
        $this->assertSame(0.2, self::need($d)['emotional']);
        $this->assertTrue(RelDynIntimacy::setNeedOverride($d, 'emotional', 0.9));
        $this->assertSame(0.9, self::need($d)['emotional']);
        $this->assertTrue(RelDynIntimacy::setNeedOverride($d, 'physical', 0.0));
        $this->assertSame(0.0, self::need($d)['physical']);
        $this->assertFalse(RelDynIntimacy::setNeedOverride($d, 'spiritual', 0.5), 'unknown axis');
        $this->assertFalse(RelDynIntimacy::setNeedOverride($d, 'physical', 1.5), 'outside 0..1');
        $this->assertTrue(RelDynIntimacy::setNeedOverride($d, 'emotional', null));
        $this->assertSame(0.2, self::need($d)['emotional'], 'cleared: back to the preset');
    }

    // ------------------------------------------------------------------ fulfillment axes

    public function testIntimacyAxesJoinTheNeedsVectorPhysicalOnlyWhileInPlay(): void
    {
        $prefs = RelDynFacets::neutralPreferences();
        $ashe = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        $needs = RelDynFulfillment::needs($ashe, $prefs);
        $this->assertArrayHasKey(RelDynIntimacy::EMOTIONAL, $needs);
        $this->assertArrayNotHasKey(RelDynIntimacy::PHYSICAL, $needs, 'her physical need is below axis_min: no axis');
        $this->assertSame(RelDynFulfillment::KIND_INTIMACY, RelDynFulfillment::axisKind(RelDynIntimacy::EMOTIONAL));

        $aela = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $this->assertArrayHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::needs($aela, $prefs), 'a romance: physical is in play');

        // Platonic: in play only from min_passion, held down to release_passion (hysteresis), never friendzoned
        $friend = $this->derived(['Huntress' => self::huntress()], 'Huntress', ['_core_rel_type' => 'platonic']);
        $this->assertArrayNotHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::needs($friend, $prefs));
        RelationshipDynamics::setPassion($friend, 35.0);
        RelDynIntimacy::ensureNeed('Huntress', $friend);
        $this->assertArrayHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::needs($friend, $prefs));
        RelationshipDynamics::setPassion($friend, 25.0);
        RelDynIntimacy::ensureNeed('Huntress', $friend);
        $this->assertArrayHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::needs($friend, $prefs), 'latched: 25 is above release');
        RelationshipDynamics::setPassion($friend, 15.0);
        RelDynIntimacy::ensureNeed('Huntress', $friend);
        $this->assertArrayNotHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::needs($friend, $prefs));
        $aela['_attraction'] = ['friendzoned' => true];
        $this->assertArrayNotHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::needs($aela, $prefs), 'friendzoned: not the player\'s to fill');
    }

    /**
     * Ken: "Ashe is less about the sex and more about the connection." Both get connection
     * every day (quality time, a confiding talk), neither gets sex: the Ashe-like
     * NPC is fulfilled on intimacy, the Aela-like one is not, and it weighs on her weather.
     */
    public function testConnectionWithoutSexFulfilsAsheButNotAela(): void
    {
        $rows = ['Scholar' => self::scholar(), 'Huntress' => self::huntress()];
        $prefs = RelDynFacets::neutralPreferences();
        $npcs = [];
        foreach (['Scholar', 'Huntress'] as $name) {
            $d = $this->derived($rows, $name);
            $this->assertTrue(RelDynFulfillment::ensure($d, $prefs, self::T0));
            for ($day = 0; $day < 6; $day++) {
                $at = self::T0 + $day * self::DAY + 2 * self::HOUR;
                RelationshipDynamics::processEvalContractItem($name, self::evalItem($name, $at, ['quality_time']), $d);
                RelationshipDynamics::processEvalContractItem($name, self::evalItem($name, $at + 3 * self::HOUR, ['confiding']), $d);
            }
            $npcs[$name] = $d;
        }
        $now = self::T0 + 6 * self::DAY;

        $ashe = RelDynIntimacy::axesAt($npcs['Scholar'], $now);
        $this->assertSame([RelDynIntimacy::EMOTIONAL], array_keys($ashe));
        $this->assertGreaterThan(0.5, $ashe[RelDynIntimacy::EMOTIONAL]['coverage'], 'her need is covered');
        $this->assertNull(RelDynIntimacy::deprivedAxis($npcs['Scholar'], $now));
        $this->assertNull(RelDynIntimacy::feltText('Scholar', 'Kaida', $npcs['Scholar'], $now));
        $this->assertSame(0.0, RelDynIntimacy::weatherDeprivation($npcs['Scholar'], $now));

        $aela = RelDynIntimacy::axesAt($npcs['Huntress'], $now);
        $this->assertGreaterThan(0.5, $aela[RelDynIntimacy::EMOTIONAL]['coverage'], 'the connection reaches her too');
        $this->assertTrue($aela[RelDynIntimacy::PHYSICAL]['deprived'], 'talk is not what she is missing');
        $this->assertSame(RelDynIntimacy::PHYSICAL, RelDynIntimacy::deprivedAxis($npcs['Huntress'], $now));
        $text = RelDynIntimacy::feltText('Huntress', 'Kaida', $npcs['Huntress'], $now);
        $this->assertNotNull($text);
        $this->assertStringContainsString('Huntress', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text, 'feelings, never numbers');
        $this->assertGreaterThan(0.2, RelDynIntimacy::weatherDeprivation($npcs['Huntress'], $now));
        $this->assertGreaterThanOrEqual(RelDynIntimacy::weatherDeprivation($npcs['Huntress'], $now),
            RelDynFulfillment::weatherDeprivation($npcs['Huntress'], $now), 'the weather reads the larger deprivation');

        // A night together covers it again
        $d = $npcs['Huntress'];
        RelationshipDynamics::processEvalContractItem('Huntress', self::evalItem('Huntress', $now, ['intimacy']), $d);
        RelationshipDynamics::processEvalContractItem('Huntress', self::evalItem('Huntress', $now + self::HOUR, ['intimacy']), $d);
        $this->assertNull(RelDynIntimacy::deprivedAxis($d, $now + self::HOUR));
        $this->assertNull(RelDynIntimacy::feltText('Huntress', 'Kaida', $d, $now + self::HOUR));
    }

    public function testNoConnectionAtAllIsEmotionalDeprivationInHerOwnVoice(): void
    {
        // The Guarded scholar is secure once close (decisions §12): she says she misses it
        $d = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $this->assertNull(RelDynIntimacy::feltText('Scholar', 'Kaida', $d, self::T0 + self::DAY), 'a day apart is nothing');
        $later = self::T0 + 5 * self::DAY;
        $this->assertSame(RelDynIntimacy::EMOTIONAL, RelDynIntimacy::deprivedAxis($d, $later));
        $text = RelDynIntimacy::feltText('Scholar', 'Kaida', $d, $later);
        $this->assertStringContainsString('Kaida', $text);
        $this->assertStringContainsString('misses feeling close', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);
        $this->assertStringNotContainsString('physical', strtolower($text));

        // An avoidant one (the editor pinned it) keeps it behind a wall
        $w = $this->derived(['Scholar' => self::scholar()], 'Scholar', ['profile_overrides' => ['attachment_style' => 'avoidant']]);
        RelDynFulfillment::ensure($w, RelDynFacets::neutralPreferences(), self::T0);
        $this->assertStringContainsString('would never say it', (string) RelDynIntimacy::feltText('Scholar', 'Kaida', $w, $later), 'avoidant: kept behind a wall');
    }

    public function testPhysicalDeprivationTextIsMFAwareAndMaturityAware(): void
    {
        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        RelDynIntimacy::setNeedOverride($d, 'emotional', 0.0);   // the physical axis alone speaks
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $this->assertArrayNotHasKey(RelDynIntimacy::EMOTIONAL, $d['_fulfillment']['w'], 'an overridden-away need is no axis');
        $later = self::T0 + 5 * self::DAY;
        $text = function (float $m, float $f, float $maturity) use ($d, $later) {
            $d['dimensions']['coord_m']['x'] = $m;
            $d['dimensions']['coord_f']['x'] = $f;
            $d['dimensions']['maturity']['x'] = $maturity;
            return (string) RelDynIntimacy::feltText('Huntress', 'Kaida', $d, $later);
        };
        $this->assertStringContainsString('restless', $text(80, 20, 50), 'high M: not the type to suffer in silence');
        $this->assertStringContainsString('aches', $text(20, 80, 50), 'high F: withdraws instead of chasing');
        $this->assertStringContainsString('snapping at people', $text(80, 20, 20), 'low maturity: it bleeds into everything');
        $this->assertStringContainsString('lingers close', $text(50, 50, 50), 'balanced: restless in a low, constant way');
    }

    public function testAHugRequestTheEvalDidNotScoreFeedsTheIntimacyAxes(): void
    {
        $d = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $ll = RelationshipDynamics::classifyInteraction(['inputtext', '1', (string) (int) self::T0, 'Kaida: ExtCmdHug']);
        $applied = RelationshipDynamics::recordLoveLanguageFulfillment($d, $ll, self::T0);
        $this->assertGreaterThan(0.0, $applied[RelDynIntimacy::EMOTIONAL] ?? 0.0, 'non-sexual touch is emotional closeness');
    }

    /**
     * PR 13 (D:/docs/pr13-environmental-quirks-plan.md, "Intimacy as a Deprivation Category"):
     * "Attachment style modifies deprivation rate: avoidant=0.5x, anxious=2.0x, toxic=1.5x".
     * The intimacy axes wear off at the fulfillment half-life x that rate; the other axes do not.
     */
    public function testAttachmentSetsHowFastIntimacyWearsOff(): void
    {
        $prefs = RelDynFacets::neutralPreferences();
        $levels = [];
        foreach (['avoidant', 'secure', 'toxic', 'anxious'] as $style) {
            $d = $this->derived(['Huntress' => self::huntress()], 'Huntress', ['profile_overrides' => ['attachment_style' => $style]]);
            $this->assertSame($style, RelationshipDynamics::getAttachmentStyle($d));
            $this->assertTrue(RelDynFulfillment::ensure($d, $prefs, self::T0));
            $levels[$style] = RelDynFulfillment::levelsAt($d['_fulfillment'], self::T0 + 3 * self::DAY);
        }
        $half = RelDynFulfillment::config()['half_life_game_days'];
        $start = RelDynFulfillment::config()['start_units'];
        foreach (['avoidant' => 0.5, 'secure' => 1.0, 'toxic' => 1.5, 'anxious' => 2.0] as $style => $rate) {
            foreach (RelDynIntimacy::AXES as $axis) {
                $this->assertEqualsWithDelta($start * 0.5 ** (3.0 * $rate / $half), $levels[$style][$axis], 1e-6, "{$style} {$axis}");
            }
            // every other axis wears off at the shared half-life whatever the attachment
            foreach (array_diff(array_keys($levels[$style]), RelDynIntimacy::AXES) as $axis) {
                $this->assertEqualsWithDelta($start * 0.5 ** (3.0 / $half), $levels[$style][$axis], 1e-6, "{$style} {$axis}");
            }
            $this->assertGreaterThan(2, count($levels[$style]), 'there are other axes');
        }
        $this->assertSame(RelDynIntimacy::configDefaults()['attachment_decay_rate'],
            ['secure' => 1.0, 'avoidant' => 0.5, 'anxious' => 2.0, 'toxic' => 1.5], 'PR 13 values');

        // A late delivery (an eval item applied after the fact) lands decayed at the axis's own rate
        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress', ['profile_overrides' => ['attachment_style' => 'anxious']]);
        RelDynFulfillment::ensure($d, $prefs, self::T0);
        RelDynFulfillment::deliver($d, [RelDynIntimacy::PHYSICAL => 0.0001], self::T0 + 3 * self::DAY);
        $other = array_values(array_diff(array_keys($d['_fulfillment']['w']), RelDynIntimacy::AXES))[0];
        $applied = RelDynFulfillment::deliver($d, [RelDynIntimacy::PHYSICAL => 1.0, $other => 1.0], self::T0);
        $this->assertEqualsWithDelta(0.5 ** (3.0 * 2.0 / $half), $applied[RelDynIntimacy::PHYSICAL], 1e-3);
        $this->assertEqualsWithDelta(0.5 ** (3.0 / $half), $applied[$other], 1e-3);
    }

    /**
     * A request the plugin reports as intimacy is an observed fact, fed whether or not the eval
     * scores the exchange: a Sharmat / OStim scene with the player in it covers the physical axis
     * in full (PR 13: "OStim/Sharmat events -> fully satisfied"); a VR touch of the body
     * (ext_nsfw_physics) is intimate touch, half a delivery; an NPC-only scene and a hug are not
     * the player's intimacy (a hug is the legacy 'touch' love language: mostly emotional).
     */
    public function testIntimateRequestsFeedPhysicalIntimacy(): void
    {
        $t = (string) (int) self::T0;
        $scene = ['ext_nsfw_sexcene', '1', $t, 'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/Huntress^sub,vaginal'];
        $this->assertSame('scene', RelDynIntimacy::requestKind($scene, 'Kaida'));
        $this->assertSame('scene', RelDynIntimacy::requestKind(['chatnf_sl_climax', '1', $t, 'Kaida and Huntress climax together'], 'Kaida'));
        $this->assertSame('scene', RelDynIntimacy::requestKind(['info', '1', $t, 'Kaida: OStimSceneStart with Huntress'], 'Kaida'));
        $this->assertSame('intimate_touch', RelDynIntimacy::requestKind(['ext_nsfw_physics', '1', $t, 'Huntress^breast^grab^0^^left^'], 'Kaida'));
        $this->assertNull(RelDynIntimacy::requestKind(['ext_nsfw_sexcene', '1', $t, 'OStimScene/vaginal/Stage1/Ulfberth^dom/Huntress^sub'], 'Kaida'),
            'a scene without the player is not the player\'s intimacy');
        $this->assertNull(RelDynIntimacy::requestKind(['ext_nsfw_npc_scene', '1', $t, 'Kaida^Huntress^1^S^0^0'], 'Kaida'));
        $this->assertNull(RelDynIntimacy::requestKind(['inputtext', '1', $t, 'Kaida: ExtCmdHug'], 'Kaida'));
        $this->assertNull(RelDynIntimacy::requestKind(['inputtext', '1', $t, 'Kaida: hello'], 'Kaida'));

        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $applied = RelDynIntimacy::recordRequest($d, $scene, 'Kaida', self::T0);
        $axes = RelDynIntimacy::axesAt($d, self::T0);
        $this->assertEqualsWithDelta(1.0, $axes[RelDynIntimacy::PHYSICAL]['coverage'], 1e-9, 'fully satisfied: ' . json_encode($applied));
        $this->assertLessThan(0.3, $applied[RelDynIntimacy::EMOTIONAL] ?? 0.0, 'sex is not what covers her need to be known');

        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $touch = RelDynIntimacy::recordRequest($d, ['ext_nsfw_physics', '1', $t, 'Huntress^breast^grab^0^^left^'], 'Kaida', self::T0);
        $this->assertEqualsWithDelta(0.5, $touch[RelDynIntimacy::PHYSICAL], 1e-9);
        $this->assertSame([], RelDynIntimacy::recordRequest($d, ['inputtext', '1', $t, 'Kaida: ExtCmdHug'], 'Kaida', self::T0));

        // The legacy love-language delivery of the same scene leaves the intimacy axes to it
        $ll = RelationshipDynamics::classifyInteraction($scene);
        $this->assertSame(RelationshipDynamics::LL_TOUCH, $ll);
        $copy = $d;
        $hug = RelationshipDynamics::recordLoveLanguageFulfillment($copy, $ll, self::T0);
        $this->assertArrayHasKey(RelDynIntimacy::EMOTIONAL, $hug, 'as a hug it would feed the intimacy axes');
        $legacy = RelationshipDynamics::recordLoveLanguageFulfillment($d, $ll, self::T0, false);
        $this->assertSame([], array_intersect(array_keys($legacy), RelDynIntimacy::AXES), 'the scene fed them itself');
    }

    /**
     * Ken: "Ashe is less about the sex and more about the connection." That is the named NPC,
     * whatever class core registered her with (a sellsword: Warrior): her attraction preset
     * gates intimacy on the bond, so her need is emotional and physical is no axis of hers.
     */
    public function testNamedAsheIsBondGatedWhateverHerCoreClass(): void
    {
        foreach (['Warrior', 'Sorcerer', 'Spellsword', 'Thief'] as $class) {
            $row = self::row('Ashe', 'BretonRace', $class, ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                ['onehanded' => 60, 'block' => 45, 'lightarmor' => 50, 'archery' => 35, 'destruction' => 30]);
            $d = $this->derived(['Ashe' => $row], 'Ashe');
            $n = self::need($d);
            $this->assertSame('bond', RelDynAttraction::gateOf('Ashe', $d), $class);
            $this->assertContains('gate:bond', $d['_intimacy_need']['signals'], $class);
            $this->assertLessThan(RelDynIntimacy::configDefaults()['axis_min'], $n['physical'], "{$class}: " . json_encode($n));
            $this->assertGreaterThanOrEqual(0.6, $n['emotional'], $class);
            $needs = RelDynFulfillment::needs($d, RelDynFacets::neutralPreferences());
            $this->assertArrayNotHasKey(RelDynIntimacy::PHYSICAL, $needs, $class);
            $this->assertArrayHasKey(RelDynIntimacy::EMOTIONAL, $needs, $class);
        }
    }

    /**
     * The intimacy line is the romance's feeling: it speaks only while intimacy is in play with
     * the player (a romance core type, or passion held at its threshold; never friendzoned) and
     * the bond is one whose neglect weighs (like the weather). A housecarl, a sister or a friend
     * who misses the closeness says so through the fulfillment text, not a romance-coded one;
     * an ended bond says nothing.
     */
    public function testIntimacyTextSpeaksOnlyWhileIntimacyIsInPlay(): void
    {
        $later = self::T0 + 6 * self::DAY;
        foreach (['servant', 'familial', 'platonic'] as $type) {
            $d = $this->derived(['Scholar' => self::scholar()], 'Scholar', ['_core_rel_type' => $type]);
            RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
            $this->assertNotNull(RelationshipDynamics::neglectBond($d), $type);
            $this->assertSame(RelDynIntimacy::EMOTIONAL, RelDynIntimacy::deprivedAxis($d, $later), "{$type}: the need is still there");
            $this->assertNull(RelDynIntimacy::feltText('Scholar', 'Kaida', $d, $later), "{$type}: no romance-coded text");
            $this->assertContains('real closeness, being truly known', RelDynFulfillment::unmetPhrases($d['_fulfillment'], $later, 3),
                "{$type}: missed through the fulfillment text");
        }
        // The romance: the text speaks; once it ends (professional: no bond) it stops, like the weather
        $d = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $this->assertNotNull(RelDynIntimacy::feltText('Scholar', 'Kaida', $d, $later));
        $d['_core_rel_type'] = 'professional';
        RelDynIntimacy::ensureNeed('Scholar', $d);
        $this->assertNull(RelationshipDynamics::neglectBond($d));
        $this->assertSame(0.0, RelDynFulfillment::weatherDeprivation($d, $later));
        $this->assertNull(RelDynIntimacy::feltText('Scholar', 'Kaida', $d, $later));
        // Passion held at its threshold outside a romance puts intimacy in play
        $friend = $this->derived(['Huntress' => self::huntress()], 'Huntress', ['_core_rel_type' => 'platonic']);
        RelationshipDynamics::setPassion($friend, 40.0);
        RelDynIntimacy::ensureNeed('Huntress', $friend);
        RelDynFulfillment::ensure($friend, RelDynFacets::neutralPreferences(), self::T0);
        $this->assertNotNull(RelDynIntimacy::feltText('Huntress', 'Kaida', $friend, $later));
    }

    public function testTheRetiredSingleStampIsGone(): void
    {
        $d = $this->derived(['Scholar' => self::scholar()], 'Scholar', ['_intimacy_fed' => ['gamets' => self::T0, 'level' => 0.1]]);
        $this->assertArrayNotHasKey('_intimacy_fed', $d, 'dropped on the first ensureNeed');
        foreach (['ensureIntimacyClock', 'intimacySatisfaction', 'markIntimacy', 'recordIntimacyFromRequest', 'generateIntimacyDeprivationContext'] as $m) {
            $this->assertFalse(method_exists('RelationshipDynamics', $m), "{$m} retired");
        }
        $this->assertArrayNotHasKey('intimacy_min_passion', RelDynFacets::appraisalDefaults());
        $this->assertArrayNotHasKey('_intimacy_fed', RelationshipDynamics::defaultDynamics());
        // a base-era blob with the retired satisfaction at 0.1 is not stuck deprived
        $legacy = RelationshipDynamics::defaultDynamics();
        $legacy['_interest_satisfaction'] = ['intimacy' => 0.1];
        $this->assertNull(RelDynIntimacy::feltText('Scholar', 'Kaida', $legacy, self::T0));
    }

    public function testConfidingIsAContractTag(): void
    {
        $this->assertContains('confiding', RelationshipDynamics::EVAL_CONTRACT_TAGS);
        $this->assertArrayHasKey('confiding', RelDynFulfillment::configDefaults()['tag_delivery']);
    }
}
