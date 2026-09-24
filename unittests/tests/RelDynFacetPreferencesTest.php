<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php';

/**
 * Answers the reads preferences() makes: the RelDyn config row and the NPC's
 * core_npc_master row. Stored data only; the derivation under test is the real code.
 */
final class RelDynFacetPrefsDb
{
    /** @var array<string, array> lower(npc_name) => core_npc_master row as PostgreSQL returns it */
    public array $rows = [];
    public array $queries = [];
    public ?array $configValue = null;

    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }

    public function fetchOne($sql, $params = null)
    {
        $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
        $this->queries[] = $sql;
        if (str_contains($sql, "FROM conf_opts WHERE id = 'relationship_dynamics_config'")) {
            return $this->configValue === null ? [] : ['value' => json_encode($this->configValue)];
        }
        if (str_contains($sql, 'FROM core_npc_master') && str_contains($sql, 'personality') && is_array($params)) {
            return $this->rows[strtolower((string) $params[0])] ?? [];
        }
        return [];
    }

    public function fetchAll($sql) { $this->queries[] = $sql; return []; }
    public function execQuery($sql) { $this->queries[] = $sql; return false; }
}

/**
 * signed-preferences / interests-11 (decisions 2026-09-23 §6): each NPC has a preference
 * per facet from -1 (hates) to +1 (loves), derived from class, skills, temperament and
 * traits in CHIM core data, with a per-NPC override; the MDD 1.2 0.5x-2.0x interest
 * multiplier is a documented mapping of it.
 */
final class RelDynFacetPreferencesTest extends TestCase
{
    private $savedDb = null;
    private bool $hadDb = false;

    protected function setUp(): void
    {
        $this->hadDb = array_key_exists('db', $GLOBALS);
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);   // getConfig() -> defaults
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->hadDb) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    /** A core_npc_master row as CHIM 3.4.1 stores it (processor/comm.php addnpc), jsonb as text. */
    private static function coreRow(string $name, string $class, array $factions, array $skills, string $race, string $voice, string $personality = ''): array
    {
        $baseSkills = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $fname) {
            $f[] = ['formid' => sprintf('0x%08x', 0x48362 + $i), 'rank' => 0, 'name' => $fname];
        }
        return [
            'npc_name' => $name, 'gender' => 'female', 'race' => $race, 'voiceid' => $voice,
            'personality' => $personality, 'speechstyle' => '', 'core' => "Roleplay as {$name}", 'npc_static_bio' => '',
            'metadata' => json_encode(['skills' => array_merge($baseSkills, array_map('strval', $skills))]),
            'extended_data' => json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f]),
        ];
    }

    /** A huntress of the Companions: hunter class, Companions factions, archery/sneak/light armor. */
    private static function huntressRow(): array
    {
        return self::coreRow('Test Huntress', 'Hunter', ['CompanionsFaction', 'CompanionsCircle'],
            ['archery' => 70, 'sneak' => 55, 'lightarmor' => 50, 'onehanded' => 45], 'NordRace', 'FemaleEvenToned');
    }

    /** A scholar-mage follower: sorcerer class, destruction/alteration/enchanting. */
    private static function scholarRow(): array
    {
        return self::coreRow('Test Scholar', 'Sorcerer', [],
            ['destruction' => 60, 'alteration' => 55, 'enchanting' => 55, 'conjuration' => 40, 'lightarmor' => 45,
             'onehanded' => 30, 'restoration' => 35], 'BretonRace', 'FemaleEvenToned');
    }

    /** The profile the real temperament auto-generation derives from that row. */
    private static function profileFor(array $row): array
    {
        $p = RelationshipDynamics::deriveNpcProfile($row['npc_name'], $row);
        return ['temperament' => $p['temperament'], 'traits' => $p['traits']];
    }

    public function testHuntressDerivesNatureCombatLoveAndScholarlyConfinedDislike(): void
    {
        $row = self::huntressRow();
        $profile = self::profileFor($row);
        $this->assertSame('Independent', $profile['temperament'], 'Ranger class votes Independent (MDD 1.3)');

        $prefs = RelDynFacets::derivePreferences($row, $profile)['prefs'];
        $this->assertEqualsWithDelta(1.0, $prefs['nature'], 0.1);
        $this->assertEqualsWithDelta(0.8, $prefs['combat'], 0.1);
        $this->assertEqualsWithDelta(-0.6, $prefs['scholarly'], 0.1);
        $this->assertEqualsWithDelta(-0.5, $prefs['confined'], 0.1);
    }

    public function testScholarDerivesScholarlyEnchantingAdventureLoveAndCrowdDislike(): void
    {
        $row = self::scholarRow();
        $profile = self::profileFor($row);
        $this->assertSame('Guarded', $profile['temperament'], 'Mage class votes Guarded (MDD 1.3)');

        $prefs = RelDynFacets::derivePreferences($row, $profile)['prefs'];
        $this->assertEqualsWithDelta(0.9, $prefs['scholarly'], 0.1);
        $this->assertEqualsWithDelta(0.6, $prefs['enchanting'], 0.1);
        $this->assertEqualsWithDelta(0.4, $prefs['adventure'], 0.1);
        $this->assertEqualsWithDelta(-0.3, $prefs['crowd'], 0.1);
    }

    public function testEveryFacetIsPresentSignedAndDeterministic(): void
    {
        foreach ([self::huntressRow(), self::scholarRow(), []] as $row) {
            $profile = $row ? self::profileFor($row) : ['temperament' => 'Stoic', 'traits' => []];
            $a = RelDynFacets::derivePreferences($row, $profile);
            $b = RelDynFacets::derivePreferences($row, $profile);
            $this->assertSame($a, $b, 'same row and profile give the same preferences');
            $this->assertSame(RelDynFacets::FACETS, array_keys($a['prefs']));
            foreach ($a['prefs'] as $facet => $v) {
                $this->assertGreaterThanOrEqual(-1.0, $v, $facet);
                $this->assertLessThanOrEqual(1.0, $v, $facet);
            }
        }
    }

    public function testTemperamentAndTraitsShiftPreferences(): void
    {
        $row = self::scholarRow();
        $guarded = RelDynFacets::derivePreferences($row, ['temperament' => 'Guarded', 'traits' => []])['prefs'];
        $anxious = RelDynFacets::derivePreferences($row, ['temperament' => 'Anxious', 'traits' => []])['prefs'];
        $this->assertLessThan($guarded['danger'], $anxious['danger'], 'Anxious dislikes danger more (MDD 1.3 "terrified")');

        $ego = RelDynFacets::derivePreferences($row, ['temperament' => 'Guarded', 'traits' => ['egocentric']])['prefs'];
        $this->assertGreaterThan($guarded['luxury'], $ego['luxury'], 'egocentric likes luxury');
    }

    public function testConfigTableReplacesDefaultTable(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->configValue = ['facet_preferences' => ['temperament_prefs' => ['Guarded' => ['crowd' => -0.9]]]];
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();

        $prefs = RelDynFacets::derivePreferences(self::scholarRow(), ['temperament' => 'Guarded', 'traits' => []])['prefs'];
        $this->assertEqualsWithDelta(-1.0, $prefs['crowd'], 1e-9, 'Mage -0.2 + stored Guarded -0.9, clamped to -1');
        $this->assertGreaterThan(0.5, $prefs['scholarly'], 'tables the stored config leaves out keep their defaults');
    }

    public function testPreferencesStoresDerivationAndOverrideWins(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->rows['test huntress'] = self::huntressRow();
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();

        $dyn = ['inferred_temperament' => 'Independent', 'traits' => []];
        $this->assertTrue(RelDynFacets::ensurePreferences('Test Huntress', $dyn));
        $this->assertEqualsWithDelta(1.0, $dyn['_facet_prefs']['prefs']['nature'], 0.1);
        $this->assertFalse(RelDynFacets::ensurePreferences('Test Huntress', $dyn), 'same basis: nothing to do');

        $coreReads = fn() => count(array_filter($db->queries, fn($q) => str_contains($q, 'FROM core_npc_master')));
        $reads = $coreReads();
        $prefs = RelDynFacets::preferences($dyn, 'Test Huntress');
        $this->assertSame($reads, $coreReads(), 'stored derivation is used without reading core again');
        $this->assertEqualsWithDelta(-0.6, $prefs['scholarly'], 0.1);

        $this->assertTrue(RelDynFacets::setPreferenceOverride($dyn, 'scholarly', 0.5));
        $this->assertSame(0.5, RelDynFacets::preferences($dyn, 'Test Huntress')['scholarly'], 'per-NPC override wins');
        $this->assertFalse(RelDynFacets::setPreferenceOverride($dyn, 'scholarly', 1.5), 'out of -1..+1');
        $this->assertFalse(RelDynFacets::setPreferenceOverride($dyn, 'gardening', 0.2), 'unknown facet');
        $this->assertTrue(RelDynFacets::setPreferenceOverride($dyn, 'scholarly', null));
        $this->assertEqualsWithDelta(-0.6, RelDynFacets::preferences($dyn, 'Test Huntress')['scholarly'], 0.1, 'cleared');
    }

    public function testTemperamentChangeRederivesStoredPreferences(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->rows['test scholar'] = self::scholarRow();
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();

        $dyn = ['inferred_temperament' => 'Guarded', 'traits' => []];
        RelDynFacets::ensurePreferences('Test Scholar', $dyn);
        $before = $dyn['_facet_prefs']['prefs']['danger'];

        $dyn['inferred_temperament'] = 'Anxious';   // e.g. an editor override
        $this->assertLessThan($before, RelDynFacets::preferences($dyn, 'Test Scholar')['danger'], 'read path re-derives');
        $this->assertTrue(RelDynFacets::ensurePreferences('Test Scholar', $dyn));
        $this->assertLessThan($before, $dyn['_facet_prefs']['prefs']['danger']);
    }

    public function testInterestMultiplierMapsSignedPreferenceOntoMddRange(): void
    {
        // MDD 1.2: 0.5x .. 2.0x, 1.0 = indifferent
        $this->assertSame(2.0, RelDynFacets::interestMultiplier(1.0));
        $this->assertSame(1.0, RelDynFacets::interestMultiplier(0.0));
        $this->assertSame(0.5, RelDynFacets::interestMultiplier(-1.0));
        $this->assertEqualsWithDelta(1.5, RelDynFacets::interestMultiplier(0.5), 1e-9);
        $this->assertEqualsWithDelta(0.7, RelDynFacets::interestMultiplier(-0.6), 1e-9);
        $this->assertSame(2.0, RelDynFacets::interestMultiplier(3.0), 'clamped');
    }
}
