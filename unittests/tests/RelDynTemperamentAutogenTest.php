<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Answers only the one read the profile auto-generation makes: the NPC's core_npc_master
 * row, looked up by name. It hands back stored data; the derivation under test is the real
 * engine code. Anything else is recorded so a test can see it was not expected.
 */
final class RelDynTemperamentRowDb
{
    /** @var array<string, array> lower(npc_name) => core_npc_master row as PostgreSQL returns it */
    public array $rows = [];
    public array $queries = [];
    public bool $failCoreRead = false;
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
            if ($this->failCoreRead) {
                throw new RuntimeException('connection lost');
            }
            return $this->rows[strtolower((string) $params[0])] ?? [];
        }
        return [];
    }

    public function fetchAll($sql) { $this->queries[] = $sql; return []; }
    public function execQuery($sql) { $this->queries[] = $sql; return false; }
}

/**
 * ll-temperament-autogen / npc-trait-tags: temperament (MDD 1.3), attachment style (MDD 6.1),
 * maturity type (MDD 15.6) and trait tags (decisions 2026-09-23 §1) come from CHIM core data
 * on vanilla 3.4.1, where MARAS and Sharmat are absent.
 */
final class RelDynTemperamentAutogenTest extends TestCase
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

    /**
     * A core_npc_master row in the shape CHIM 3.4.1 stores it (processor/comm.php addnpc):
     * extended_data.class = {name, formid[, teaches, max_training_level]},
     * extended_data.factions = [{formid, rank, name}], metadata.skills = {skill: "level"}.
     * jsonb columns come back from PostgreSQL as JSON text.
     */
    private static function coreRow(array $o = [], array $ext = [], array $skills = []): array
    {
        $baseSkills = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        return array_merge([
            'npc_name' => 'Test NPC',
            'gender' => 'female',
            'race' => 'Nord',
            'voiceid' => 'sk_femaleeventoned',
            'personality' => '',
            'speechstyle' => '',
            'core' => '',
            'npc_static_bio' => '',
            'metadata' => json_encode(['skills' => array_merge($baseSkills, $skills), 'stats' => ['level' => 10]]),
            'extended_data' => json_encode(array_merge([
                'class' => ['name' => 'Citizen', 'formid' => '0x0001326b'],
                'factions' => [],
            ], $ext)),
        ], $o);
    }

    private static function withClass(string $className, array $o = []): array
    {
        return self::coreRow($o, ['class' => ['name' => $className, 'formid' => '0x00013176']]);
    }

    // ---------------------------------------------------------------------
    // Temperament from core data
    // ---------------------------------------------------------------------

    /** MDD 1.3 "Default temperament by NPC class", on the class names Skyrim.esm actually carries. */
    public static function classDefaults(): array
    {
        return [
            'Warrior (CombatWarrior1H FULL)'      => ['Warrior', 'Bold'],
            'Barbarian'                           => ['Barbarian', 'Bold'],
            'Orc Warrior (GuardOrc1H FULL)'       => ['Orc Warrior', 'Bold'],
            'Ranger'                              => ['Ranger', 'Independent'],
            'Scout'                               => ['Scout', 'Independent'],
            'Mage: Conjurer'                      => ['Conjurer', 'Guarded'],
            'Mage: Fire/Frost/Shock Mage'         => ['Fire/Frost/Shock Mage', 'Guarded'],
            'Mage: editor id CombatMageConjurer'  => ['CombatMageConjurer', 'Guarded'],
            'Mage: Spell Vendor is a mage'        => ['Spell Vendor', 'Guarded'],
            'Thief'                               => ['Thief', 'Playful'],
            'Rogue'                               => ['Rogue', 'Playful'],
            'Merchant: Blacksmith'                => ['Blacksmith', 'Humble'],
            'Merchant: Food Vendor'               => ['Food Vendor', 'Humble'],
            'Merchant: editor id VendorPawnbroker'=> ['VendorPawnbroker', 'Humble'],
            'Healer: Priest'                      => ['Priest', 'Nurturing'],
            'Healer: Apothecary'                  => ['Apothecary', 'Nurturing'],
        ];
    }

    #[DataProvider('classDefaults')]
    public function testClassGivesTheMddDefaultTemperament(string $className, string $expected): void
    {
        $p = RelationshipDynamics::deriveNpcProfile('Test NPC', self::withClass($className));
        $this->assertSame($expected, $p['temperament'], "class {$className}");
        $this->assertSame('core', $p['sources']['temperament']);
    }

    public function testNobleComesFromFactionBecauseVanillaHasNoNobleClass(): void
    {
        // Jarls are class Citizen in Skyrim.esm; the Noble preset is recognised by faction.
        $row = self::coreRow([], ['factions' => [
            ['formid' => '0x00050920', 'rank' => 0, 'name' => 'JobJarlFaction'],
        ]]);
        $p = RelationshipDynamics::deriveNpcProfile('Some Jarl', $row);
        $this->assertSame('Noble', $p['archetype']);
        $this->assertSame('Proud', $p['temperament']);
    }

    public function testNegativeFactionRankIsNotMembership(): void
    {
        $row = self::coreRow([], ['factions' => [
            ['formid' => '0x00050920', 'rank' => -1, 'name' => 'JobJarlFaction'],
        ]]);
        $p = RelationshipDynamics::deriveNpcProfile('Not A Jarl', $row);
        $this->assertNotSame('Noble', $p['archetype']);
        $this->assertNotSame('Proud', $p['temperament']);
    }

    public function testVanillaCitizenWithNoOtherSignalIsNotLeftAsStoic(): void
    {
        // The 3.4.1 regression: without MARAS/Sharmat every NPC fell back to Stoic.
        $p = RelationshipDynamics::deriveNpcProfile('Bystander', self::coreRow(['race' => 'Imperial']));
        $this->assertSame('Humble', $p['temperament'], 'race vote decides when class and text are silent');
        $this->assertSame('core', $p['sources']['temperament']);
    }

    public function testPersonalityTextOutweighsAGenericClass(): void
    {
        $row = self::withClass('Citizen', [
            'personality' => 'Arrogant and condescending, a pompous man who thinks the town owes him.',
        ]);
        $p = RelationshipDynamics::deriveNpcProfile('Merchant Snob', $row);
        $this->assertSame('Proud', $p['temperament']);
    }

    public function testLorePhrasesDoNotReadAsTraits(): void
    {
        $row = self::coreRow(['race' => 'Imperial',
            'core' => 'Roleplay as Tamsin. She fought in vain against the Forsworn rebellion in the Reach.']);
        $this->assertSame('Humble', RelationshipDynamics::deriveNpcProfile('Tamsin', $row)['temperament']);
    }

    public function testVoiceTypeCountsAsASignal(): void
    {
        $p = RelationshipDynamics::deriveNpcProfile('Coward', self::coreRow(['voiceid' => 'sk_malecoward', 'race' => 'Nord']));
        // voice Anxious (2 points) beats race Nord -> Bold (1 point)
        $this->assertSame('Anxious', $p['temperament']);
    }

    public function testSkillsStandInForClassWhenClassSaysNothing(): void
    {
        $row = self::coreRow(['race' => 'Imperial'], [], ['archery' => '62', 'sneak' => '40']);
        $p = RelationshipDynamics::deriveNpcProfile('Hunter', $row);
        $this->assertSame('Ranger', $p['archetype']);
        // skills Ranger -> Independent (1) ties race Imperial -> Humble (1); MDD 1.3 table order breaks the tie
        $this->assertSame('Humble', $p['temperament']);
        $row = self::coreRow(['race' => 'Imperial', 'voiceid' => 'sk_maleeventoned'], [], ['archery' => '62']);
        $row['personality'] = 'A self-reliant loner.';
        $this->assertSame('Independent', RelationshipDynamics::deriveNpcProfile('Hunter', $row)['temperament']);
    }

    public function testDerivationIsDeterministic(): void
    {
        $row = self::withClass('Bard', ['race' => 'Breton', 'voiceid' => 'sk_maleyoungeager',
            'personality' => 'Flirtatious and playful, a charming teller of tales.']);
        $a = RelationshipDynamics::deriveNpcProfile('Mikael', $row);
        $b = RelationshipDynamics::deriveNpcProfile('Mikael', array_reverse($row, true));
        $this->assertSame($a, $b);
        $this->assertSame($a, RelationshipDynamics::deriveNpcProfile('MIKAEL', $row), 'name lookup is case-insensitive');
    }

    public function testMissingRowFallsBackWithoutThrowing(): void
    {
        $p = RelationshipDynamics::deriveNpcProfile('Unknown', []);
        $this->assertContains($p['temperament'], RelationshipDynamics::TEMPERAMENT_TYPES);
        $this->assertSame('fallback', $p['sources']['temperament']);
    }

    public function testSharmatAndMarasStayAheadOfCoreData(): void
    {
        $row = self::withClass('Warrior', ['extended_data' => json_encode([
            'class' => ['name' => 'Warrior', 'formid' => '0x1'],
            'relationships' => ['Player' => ['aff' => 0, 'maras' => ['temperament' => 'Jealous']]],
        ])]);
        $p = RelationshipDynamics::deriveNpcProfile('Maras NPC', $row);
        $this->assertSame('Jealous', $p['temperament']);
        $this->assertSame('maras', $p['sources']['temperament']);

        $p = RelationshipDynamics::deriveNpcProfile('Sharmat NPC', self::withClass('Warrior'), ['sharmat_style' => 'shy']);
        $this->assertSame('Anxious', $p['temperament']);
        $this->assertSame('sharmat', $p['sources']['temperament']);
    }

    // ---------------------------------------------------------------------
    // Attachment style (MDD 6.1) and maturity type (MDD 15.6)
    // ---------------------------------------------------------------------

    public function testAttachmentHasItsOwnEvidenceAndMaturityTypeFollowsTemperamentAndClass(): void
    {
        $p = RelationshipDynamics::deriveNpcProfile('Guard', self::withClass('Warrior'));
        $this->assertSame('Bold', $p['temperament']);
        $this->assertSame('secure', $p['attachment_style']);
        $this->assertSame('derived', $p['sources']['attachment']);
        $this->assertSame('Resilient', $p['maturity_type']);

        // Decisions §12: a Guarded mage is hard to get close to, not avoidant once close
        $p = RelationshipDynamics::deriveNpcProfile('Wizard', self::withClass('Conjurer'));
        $this->assertSame('Guarded', $p['temperament']);
        $this->assertSame('secure', $p['attachment_style']);
        $this->assertSame(0.15, $p['attachment']['avoidance']);
        $this->assertSame('Brittle', $p['maturity_type']);

        // Class part of "temperament + NPC class" (MDD 15.6): the dramatic bard swings both ways.
        $p = RelationshipDynamics::deriveNpcProfile('Some Bard', self::withClass('Bard'));
        $this->assertSame('Volatile', $p['maturity_type']);
    }

    public function testTheWarmthCurveNoLongerSetsAttachment(): void
    {
        // The April "temperament + warmth curve" attachment is gone (decisions §12): the curve is
        // how fast warmth grows, the axes come from their own evidence.
        $row = self::withClass('Conjurer');
        $this->assertArrayNotHasKey('temperament_attachment', RelationshipDynamics::temperamentAutogenDefaults());
        $this->assertArrayNotHasKey('attachment_curve_shift', RelationshipDynamics::temperamentAutogenDefaults());
        $this->assertSame(RelationshipDynamics::deriveNpcProfile('W', $row)['attachment'],
            RelationshipDynamics::deriveNpcProfile('W', $row, ['warmth_curve' => 'quick_warmth'])['attachment']);
    }

    public function testToxicIsNeverAutoAssigned(): void
    {
        // Every derivation row pushed high: the fearful region is still not derived
        $cfg = RelationshipDynamics::attachmentDefaults();
        $cfg['derive']['temperament']['Bold'] = ['anxiety' => 0.8, 'avoidance' => 0.8];
        $a = RelationshipDynamics::deriveAttachmentAxes(['temperament' => 'Bold', 'traits' => []], $cfg);
        $this->assertNotSame('toxic', RelationshipDynamics::attachmentStyleOf($a['anxiety'], $a['avoidance'], $cfg));

        // ...but a manual override may set it.
        $p = RelationshipDynamics::deriveNpcProfile('Villain', self::withClass('Warrior'), ['overrides' => ['attachment_style' => 'toxic']]);
        $this->assertSame('toxic', $p['attachment_style']);
        $this->assertSame('override', $p['sources']['attachment']);
    }

    // ---------------------------------------------------------------------
    // Named NPCs from the MDD are overrides, not rules
    // ---------------------------------------------------------------------

    public static function namedPresets(): array
    {
        return [
            'Ashe'   => ['Ashe', 'temperament', 'Guarded'],   // rulings 2026-09-24 §8 (MDD 8.2/3.3)
            'Ashe2'  => ['Ashe', 'maturity_type', 'Resilient'],
            'Mikael' => ['Mikael', 'maturity_type', 'Volatile'],
            'Serana' => ['Serana', 'maturity_type', 'Growth'],
            'Nazeem' => ['Nazeem', 'maturity_type', 'Rigid'],
            'Ysolda' => ['Ysolda', 'temperament', 'Anxious'],
        ];
    }

    #[DataProvider('namedPresets')]
    public function testNamedMddNpcsArePresetOverrides(string $npc, string $field, string $expected): void
    {
        // Core data pointing elsewhere (a Nord warrior with a commander voice) must not win.
        $row = self::withClass('Warrior', ['voiceid' => 'sk_malecommander']);
        $p = RelationshipDynamics::deriveNpcProfile($npc, $row);
        $this->assertSame($expected, $p[$field]);
        $this->assertSame('preset', $p['sources'][$field]);
    }

    public function testPresetsDoNotLeakToOtherNpcsOfTheSameClass(): void
    {
        $p = RelationshipDynamics::deriveNpcProfile('Uthgerd', self::withClass('Warrior'));
        $this->assertSame('Bold', $p['temperament']);
        $this->assertSame('Resilient', $p['maturity_type']);
    }

    public function testPerNpcOverrideBeatsPresetAndDerivation(): void
    {
        $p = RelationshipDynamics::deriveNpcProfile('Ysolda', self::withClass('Food Vendor'),
            ['overrides' => ['temperament' => 'Gentle', 'maturity_type' => 'Rigid']]);
        $this->assertSame('Gentle', $p['temperament']);
        $this->assertSame('override', $p['sources']['temperament']);
        $this->assertSame('Rigid', $p['maturity_type']);
        // dependents re-derive from the overridden temperament
        $this->assertSame('secure', $p['attachment_style']);
    }

    // ---------------------------------------------------------------------
    // Trait tags (decisions §1)
    // ---------------------------------------------------------------------

    public function testProudIsEgocentricByDefault(): void
    {
        $row = self::withClass('Citizen', ['personality' => 'Arrogant, vain and haughty.']);
        $p = RelationshipDynamics::deriveNpcProfile('Snob', $row);
        $this->assertSame('Proud', $p['temperament']);
        $this->assertSame(['egocentric'], $p['traits']);
    }

    public function testNobleClassIsEgocentricEvenWhenNotProud(): void
    {
        $row = self::coreRow(['personality' => 'Gentle, soft-spoken and calm.'], ['factions' => [
            ['formid' => '0x00050920', 'rank' => 1, 'name' => 'JobJarlFaction'],
        ]]);
        $p = RelationshipDynamics::deriveNpcProfile('Gentle Jarl', $row);
        $this->assertSame('Gentle', $p['temperament']);
        $this->assertSame(['egocentric'], $p['traits']);
    }

    public function testAnxiousIsInsecureAndBoldHasNoTraits(): void
    {
        $this->assertSame(['insecure'], RelationshipDynamics::deriveNpcProfile('Ysolda', self::coreRow())['traits']);
        $this->assertSame([], RelationshipDynamics::deriveNpcProfile('Guard', self::withClass('Warrior'))['traits']);
    }

    // ---------------------------------------------------------------------
    // Wiring: ensureTemperamentProfile / ensureLoveLanguage / accessors
    // ---------------------------------------------------------------------

    private function fakeDb(array $rowsByName): RelDynTemperamentRowDb
    {
        $db = new RelDynTemperamentRowDb();
        foreach ($rowsByName as $name => $row) {
            $db->rows[strtolower($name)] = array_merge($row, ['npc_name' => $name]);
        }
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        return $db;
    }

    public function testEnsureLoveLanguageGivesAVanillaNpcARealTemperament(): void
    {
        $this->fakeDb(['Farengar Secret-Fire' => self::withClass('Spell Vendor', ['race' => 'Nord'])]);
        $d = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::ensureLoveLanguage('Farengar Secret-Fire', $d);

        $this->assertSame('Guarded', $d['inferred_temperament'], 'no longer null on 3.4.1');
        $this->assertSame('guarded', $d['warmth_curve'], 'curve follows the temperament');
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($d), 'Guarded is not avoidant (decisions §12)');
        $this->assertArrayNotHasKey('attachment_style', $d, 'no stored label: the axes are read from the profile');
        $this->assertSame('Brittle', $d['dimensions']['maturity']['plasticity_type']);
        $this->assertSame([], $d['traits']);
        $this->assertNotEmpty($d['love_language_primary']);
        $this->assertSame('core', $d['_profile_autogen']['temperament_source']);
        // Seeded here, so a first save does not merge onto the Stoic-seeded empty state.
        $this->assertEquals(RelationshipDynamics::getTemperamentBaseline('Guarded', 'trust'), $d['dimensions']['trust']['x']);
        $this->assertEquals(RelationshipDynamics::getTemperamentBaseline('Guarded', 'coord_m'), $d['dimensions']['coord_m']['baseline']);
    }

    public function testResolvedProfileIsStableWhenCoreTextChangesLater(): void
    {
        $db = $this->fakeDb(['Brenuin' => self::withClass('Beggar', ['race' => 'Imperial'])]);
        $d = RelationshipDynamics::defaultDynamics();
        $this->assertTrue(RelationshipDynamics::ensureTemperamentProfile('Brenuin', $d));
        $this->assertSame('Humble', $d['inferred_temperament']);

        // Dynamic profiles rewrite personality text over time; the stored profile must not flip.
        $db->rows['brenuin']['personality'] = 'Arrogant, pompous and condescending.';
        $this->assertFalse(RelationshipDynamics::ensureTemperamentProfile('Brenuin', $d));
        $this->assertSame('Humble', $d['inferred_temperament']);
    }

    public function testExistingTemperamentAndAttachmentAreKept(): void
    {
        $this->fakeDb(['Lydia' => self::withClass('Warrior')]);
        $d = RelationshipDynamics::defaultDynamics();
        $d['inferred_temperament'] = 'Romantic';     // set earlier (editor / Sharmat)
        $d['profile_overrides']['attachment_style'] = 'anxious';   // the editor's explicit override
        $d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] = ['anxiety' => -0.1, 'avoidance' => 0.0];   // experience so far
        RelationshipDynamics::ensureTemperamentProfile('Lydia', $d);
        $this->assertSame('Romantic', $d['inferred_temperament']);
        $this->assertSame('anxious', RelationshipDynamics::getAttachmentStyle($d));
        $this->assertEqualsWithDelta(0.75, RelationshipDynamics::getAttachmentAxes($d)['anxiety'], 1e-9, 'override point + the drift kept');
        $this->assertSame('Growth', $d['dimensions']['maturity']['plasticity_type'], 'Romantic -> Growth');
        $this->assertSame('stored', $d['_profile_autogen']['temperament_source']);
    }

    public function testStoicFallbackBaselinesAreReinitialisedWhenUntouched(): void
    {
        $this->fakeDb(['Camilla Valerius' => self::withClass('Citizen', ['race' => 'Imperial'])]);
        // Stored before the fix: temperament null, so migrateDimensions seeded Stoic baselines.
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $stoicTrust = RelationshipDynamics::getTemperamentBaseline('Stoic', 'trust');
        $this->assertEquals($stoicTrust, $d['dimensions']['trust']['x']);
        $this->assertSame('Resilient', $d['dimensions']['maturity']['plasticity_type']);
        $d['dimensions']['comfort']['x'] += 7;       // this one has moved since: leave it alone
        $comfortBefore = $d['dimensions']['comfort'];

        RelationshipDynamics::ensureTemperamentProfile('Camilla Valerius', $d);

        $this->assertSame('Humble', $d['inferred_temperament']);
        $humbleTrust = RelationshipDynamics::getTemperamentBaseline('Humble', 'trust');
        $this->assertNotEquals($stoicTrust, $humbleTrust, 'fixture must distinguish the two');
        $this->assertEquals($humbleTrust, $d['dimensions']['trust']['x']);
        $this->assertEquals($humbleTrust, $d['dimensions']['trust']['baseline']);
        $this->assertSame($comfortBefore, $d['dimensions']['comfort']);
        $this->assertSame('Growth', $d['dimensions']['maturity']['plasticity_type'], 'Humble -> Growth');
    }

    public function testFailedCoreReadIsLoggedAndRetriedNotPersistedAsFallback(): void
    {
        $db = $this->fakeDb(['Lydia' => self::withClass('Warrior')]);
        $db->failCoreRead = true;
        $d = RelationshipDynamics::defaultDynamics();
        $this->assertFalse(RelationshipDynamics::ensureTemperamentProfile('Lydia', $d));
        $this->assertNull($d['inferred_temperament']);
        $this->assertArrayNotHasKey('_profile_autogen', $d);

        $db->failCoreRead = false;
        $this->assertTrue(RelationshipDynamics::ensureTemperamentProfile('Lydia', $d));
        $this->assertSame('Bold', $d['inferred_temperament']);
    }

    public function testTraitAccessorsAndEditableOverride(): void
    {
        $this->fakeDb(['Idolaf Battle-Born' => self::withClass('Citizen', ['personality' => 'Proud and arrogant.'])]);
        $d = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::ensureTemperamentProfile('Idolaf Battle-Born', $d);
        $this->assertSame(['egocentric'], RelationshipDynamics::getTraits($d));
        $this->assertTrue(RelationshipDynamics::hasTrait($d, 'Egocentric'));

        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'traits', ['insecure', 'egocentric', 'insecure']));
        $this->assertSame(['egocentric', 'insecure'], RelationshipDynamics::getTraits($d));
        $this->assertFalse(RelationshipDynamics::setProfileOverride($d, 'traits', ['made_up_trait']));
        $this->assertSame(['egocentric', 'insecure'], RelationshipDynamics::getTraits($d), 'rejected edit changes nothing');

        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'traits', null));   // clear -> back to derived
        $this->assertSame(['egocentric'], RelationshipDynamics::getTraits($d));
    }

    public function testTemperamentOverrideRederivesDependentsThatStillHoldAutoValues(): void
    {
        $this->fakeDb(['Uthgerd the Unbroken' => self::withClass('Warrior')]);
        $d = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::ensureTemperamentProfile('Uthgerd the Unbroken', $d);
        $this->assertSame(['Bold', 'secure', 'Resilient', []], [$d['inferred_temperament'], RelationshipDynamics::getAttachmentStyle($d),
            $d['dimensions']['maturity']['plasticity_type'], $d['traits']]);
        [$anx0, $avo0] = array_values(array_intersect_key(RelationshipDynamics::getAttachmentAxes($d), ['anxiety' => 1, 'avoidance' => 1]));

        // Proud is a weak prior: a little more avoidance, still secure (the April map made every
        // Proud NPC avoidant). The egocentric trait Proud implies is the temperament again, not
        // attachment evidence (decisions §12), so it adds nothing here.
        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'temperament', 'Proud'));
        $this->assertSame('Proud', $d['inferred_temperament']);
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($d));
        $this->assertEqualsWithDelta($avo0 + 0.05, RelationshipDynamics::getAttachmentAxes($d)['avoidance'], 1e-9, 'the Proud prior only');
        $this->assertSame($anx0, RelationshipDynamics::getAttachmentAxes($d)['anxiety']);
        $this->assertSame('Brittle', $d['dimensions']['maturity']['plasticity_type']);
        $this->assertSame(['egocentric'], $d['traits']);

        // Drift (experience) is kept across a temperament edit; the base follows the profile
        $d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] = ['anxiety' => 0.2, 'avoidance' => 0.0];
        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'temperament', 'Bold'));
        $this->assertEqualsWithDelta($anx0 + 0.2, RelationshipDynamics::getAttachmentAxes($d)['anxiety'], 1e-9);
        $this->assertEqualsWithDelta($avo0, RelationshipDynamics::getAttachmentAxes($d)['avoidance'], 1e-9);

        $this->assertFalse(RelationshipDynamics::setProfileOverride($d, 'temperament', 'Grumpy'));
        $this->assertFalse(RelationshipDynamics::setProfileOverride($d, 'maturity_type', 'Squishy'));
        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'maturity_type', 'Rigid'));
        $this->assertSame('Rigid', $d['dimensions']['maturity']['plasticity_type']);
    }

    public function testMappingTablesLiveInConfig(): void
    {
        $db = $this->fakeDb(['Lydia' => self::withClass('Warrior')]);
        $cfg = RelationshipDynamics::defaultConfig();
        $this->assertArrayHasKey('temperament_autogen', $cfg);
        $this->assertSame(RelationshipDynamics::temperamentAutogenDefaults(), $cfg['temperament_autogen']);

        // A stored config replaces a table without code changes; untouched tables keep defaults.
        $stored = $cfg;
        $stored['temperament_autogen'] = ['archetype_temperament' => ['Warrior' => 'Defiant']];
        $db->configValue = $stored;
        RelationshipDynamics::clearConfigCache();
        $this->assertSame('Defiant', RelationshipDynamics::getTemperamentAutogenConfig()['archetype_temperament']['Warrior']);
        $this->assertSame(RelationshipDynamics::temperamentAutogenDefaults()['race_temperament'],
            RelationshipDynamics::getTemperamentAutogenConfig()['race_temperament']);
    }
}
