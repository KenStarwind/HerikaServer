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
 * Core-shaped NPC profiles for the facet tests (also used by RelDynFacetAppraisalTest):
 * rows as CHIM 3.4.1 stores them, and the profile the real temperament vote derives.
 */
final class RelDynFacetProfiles
{
    /** A core_npc_master row as CHIM 3.4.1 stores it (processor/comm.php addnpc), jsonb as text. */
    public static function coreRow(string $name, string $class, array $factions, array $skills, string $race, string $voice, string $personality = ''): array
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
    public static function huntressRow(): array
    {
        return self::coreRow('Test Huntress', 'Hunter', ['CompanionsFaction', 'CompanionsCircle'],
            ['archery' => 70, 'sneak' => 55, 'lightarmor' => 50, 'onehanded' => 45], 'NordRace', 'FemaleEvenToned');
    }

    /** A scholar-mage follower: sorcerer class, destruction/alteration/enchanting. */
    public static function scholarRow(): array
    {
        return self::coreRow('Test Scholar', 'Sorcerer', [],
            ['destruction' => 60, 'alteration' => 55, 'enchanting' => 55, 'conjuration' => 40, 'lightarmor' => 45,
             'onehanded' => 30, 'restoration' => 35], 'BretonRace', 'FemaleEvenToned');
    }

    /** The profile the real temperament auto-generation derives from that row. */
    public static function profileFor(array $row): array
    {
        $p = RelationshipDynamics::deriveNpcProfile($row['npc_name'], $row);
        return ['temperament' => $p['temperament'], 'traits' => $p['traits']];
    }

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

    public function testHuntressDerivesNatureCombatLoveAndScholarlyConfinedDislike(): void
    {
        $row = RelDynFacetProfiles::huntressRow();
        $profile = RelDynFacetProfiles::profileFor($row);
        $this->assertSame('Independent', $profile['temperament'], 'Ranger class votes Independent (MDD 1.3)');

        $prefs = RelDynFacets::derivePreferences($row, $profile)['prefs'];
        $this->assertEqualsWithDelta(1.0, $prefs['nature'], 0.1);
        $this->assertEqualsWithDelta(0.8, $prefs['combat'], 0.1);
        $this->assertEqualsWithDelta(-0.6, $prefs['scholarly'], 0.1);
        $this->assertEqualsWithDelta(-0.5, $prefs['confined'], 0.1);
    }

    public function testScholarDerivesScholarlyEnchantingAdventureLoveAndCrowdDislike(): void
    {
        $row = RelDynFacetProfiles::scholarRow();
        $profile = RelDynFacetProfiles::profileFor($row);
        $this->assertSame('Guarded', $profile['temperament'], 'Mage class votes Guarded (MDD 1.3)');

        $prefs = RelDynFacets::derivePreferences($row, $profile)['prefs'];
        $this->assertEqualsWithDelta(0.9, $prefs['scholarly'], 0.1);
        $this->assertEqualsWithDelta(0.6, $prefs['enchanting'], 0.1);
        $this->assertEqualsWithDelta(0.4, $prefs['adventure'], 0.1);
        $this->assertEqualsWithDelta(-0.3, $prefs['crowd'], 0.1);
    }

    public function testEveryFacetIsPresentSignedAndDeterministic(): void
    {
        foreach ([RelDynFacetProfiles::huntressRow(), RelDynFacetProfiles::scholarRow(), []] as $row) {
            $profile = $row ? RelDynFacetProfiles::profileFor($row) : ['temperament' => 'Stoic', 'traits' => []];
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
        $row = RelDynFacetProfiles::scholarRow();
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

        $prefs = RelDynFacets::derivePreferences(RelDynFacetProfiles::scholarRow(), ['temperament' => 'Guarded', 'traits' => []])['prefs'];
        $this->assertEqualsWithDelta(-1.0, $prefs['crowd'], 1e-9, 'Mage -0.2 + stored Guarded -0.9, clamped to -1');
        $this->assertGreaterThan(0.5, $prefs['scholarly'], 'tables the stored config leaves out keep their defaults');
    }

    public function testPreferencesStoresDerivationAndOverrideWins(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->rows['test huntress'] = RelDynFacetProfiles::huntressRow();
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
        $db->rows['test scholar'] = RelDynFacetProfiles::scholarRow();
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

    public function testInterestsAreTheSignedPreferencesThroughTheMddMapping(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->rows['test huntress'] = RelDynFacetProfiles::huntressRow();
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        $dyn = ['inferred_temperament' => 'Independent', 'traits' => []];
        RelDynFacets::ensurePreferences('Test Huntress', $dyn);
        RelDynFacets::setPreferenceOverride($dyn, 'social', -1.0);

        $interests = RelationshipDynamics::getInterests($dyn, 'Test Huntress');
        $this->assertSame(RelDynFacets::INTERESTS, array_keys($interests), 'the 11 MDD 1.2 interests');
        $prefs = RelDynFacets::preferences($dyn, 'Test Huntress');
        foreach ($interests as $interest => $mult) {
            $this->assertSame(RelDynFacets::interestMultiplier($prefs[$interest]), $mult, $interest);
            $this->assertGreaterThanOrEqual(0.5, $mult);
            $this->assertLessThanOrEqual(2.0, $mult);
        }
        $this->assertGreaterThan(1.9, $interests['nature']);
        $this->assertEqualsWithDelta(0.7, $interests['scholarly'], 0.05, 'she can dislike books now (April floor was 0.5-as-tolerates)');
        $this->assertSame(0.5, $interests['social'], 'override: hates');
    }

    /** What the NPC editor posts on Save: every slider, snapped to its 0.1 step (npc_editor_section.php). */
    private static function editorSliders(array $interests): array
    {
        return array_map(fn($m) => round(max(0.5, min(2.0, $m)) * 10) / 10, $interests);
    }

    /**
     * interests-11 review 2026-09-24: any editor Save (even one that only changed the love
     * language) posted all 11 sliders and api_save_npc turned each into a permanent override,
     * snapped to 0.1: temperament, trait and config changes never reached the interests again,
     * and nothing in the editor could undo it. A slider still at its current value (at the
     * slider's precision) is no edit; Auto-Generate clears the interest overrides.
     */
    public function testAnEditorSaveOverridesOnlyTheSlidersThatWereMoved(): void
    {
        $db = new RelDynFacetPrefsDb();
        $db->rows['test huntress'] = RelDynFacetProfiles::huntressRow();
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        $dyn = ['inferred_temperament' => 'Independent', 'traits' => []];
        RelDynFacets::ensurePreferences('Test Huntress', $dyn);
        $derivedCombat = RelDynFacets::preferences($dyn, 'Test Huntress')['combat'];

        // Save with nothing moved: nothing overridden
        $posted = self::editorSliders(RelationshipDynamics::getInterests($dyn, 'Test Huntress'));
        $this->assertSame([], RelDynFacets::applyInterestSliders($dyn, 'Test Huntress', $posted));
        $this->assertSame([], (array) ($dyn['facet_pref_overrides'] ?? []));

        // the derivation still reaches the interests: a new temperament moves combat
        $dyn['inferred_temperament'] = 'Gentle';
        $this->assertLessThan($derivedCombat, RelDynFacets::preferences($dyn, 'Test Huntress')['combat']);

        // Save with one slider moved: only that one is an override
        $posted = self::editorSliders(RelationshipDynamics::getInterests($dyn, 'Test Huntress'));
        $posted['scholarly'] = 1.6;
        $this->assertSame(['scholarly'], RelDynFacets::applyInterestSliders($dyn, 'Test Huntress', $posted));
        $this->assertSame(['scholarly'], array_keys($dyn['facet_pref_overrides']));
        $this->assertEqualsWithDelta(0.6, RelDynFacets::preferences($dyn, 'Test Huntress')['scholarly'], 1e-9);
        // the next untouched Save keeps it (the slider now shows the override)
        $posted = self::editorSliders(RelationshipDynamics::getInterests($dyn, 'Test Huntress'));
        $this->assertSame([], RelDynFacets::applyInterestSliders($dyn, 'Test Huntress', $posted));
        $this->assertEqualsWithDelta(0.6, RelDynFacets::preferences($dyn, 'Test Huntress')['scholarly'], 1e-9);

        // Auto-Generate: interests back to the derivation; a situational override is not a slider
        RelDynFacets::setPreferenceOverride($dyn, 'crowd', -0.8);
        $this->assertSame(['scholarly'], RelDynFacets::clearInterestOverrides($dyn));
        $this->assertSame(['crowd' => -0.8], $dyn['facet_pref_overrides']);
        $this->assertLessThan(0.0, RelDynFacets::preferences($dyn, 'Test Huntress')['scholarly'], 'derived again');
    }

    /** The editor and the endpoint use them: no Save path writes all 11 any more. */
    public function testTheEditorPostsOnlyMovedSlidersAndTheEndpointUsesTheFilter(): void
    {
        $api = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/api_save_npc.php');
        $this->assertStringContainsString('RelDynFacets::applyInterestSliders(', $api);
        $this->assertStringContainsString('RelDynFacets::clearInterestOverrides(', $api);
        $this->assertStringNotContainsString("foreach (\$input['interests'] as \$act => \$mult)", $api);
        $editor = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/npc_editor_section.php');
        $this->assertMatchesRegularExpression('/movedInterests\.has\(int\)/', $editor, 'collectData posts moved sliders only');
    }

    public function testInterestSliderValuesMapBackToPreferences(): void
    {
        foreach ([[0.5, -1.0], [0.7, -0.6], [1.0, 0.0], [1.5, 0.5], [2.0, 1.0]] as [$m, $p]) {
            $this->assertEqualsWithDelta($p, RelDynFacets::preferenceFromInterestMultiplier($m), 1e-9);
            $this->assertEqualsWithDelta($m, RelDynFacets::interestMultiplier(RelDynFacets::preferenceFromInterestMultiplier($m)), 1e-9);
        }
        $this->assertSame(1.0, RelDynFacets::preferenceFromInterestMultiplier(9.0), 'clamped');
    }

    /**
     * interests-11 / activity-preferences-legacy: the April single-label interest model is
     * gone (MinAI-blind location keywords, 0.5-2.0 class tables, the activity wrappers with
     * no callers); everything reads the signed facet preferences.
     */
    public function testLegacyInterestAndActivityLayersAreRetired(): void
    {
        foreach (['detectCurrentActivity', 'getActivityPreferences', 'generateActivityPreferences', 'getActivityMultiplier',
                  'getActivityResonanceText', 'detectCurrentInterest', 'detectInterestContext', 'generateInterests',
                  'getInterestResonanceText', 'getEnvironmentalResonanceText', 'calculateInterestSatisfaction'] as $m) {
            $this->assertFalse(method_exists('RelationshipDynamics', $m), "RelationshipDynamics::{$m} retired");
        }
        foreach (['INTEREST_TYPES', 'CLASS_INTEREST_DEFAULTS', 'SKILL_INTEREST_BONUS', 'KEYWORD_TO_INTEREST',
                  'LL_INTEREST_WEIGHT', 'WEATHER_MODIFIERS', 'FACTION_INTEREST_FLOORS'] as $c) {
            $this->assertFalse(defined("RelationshipDynamics::{$c}"), "RelationshipDynamics::{$c} retired (config / RelDynFacets now)");
        }
        $hooks = '';
        foreach (['prerequest.php', 'postrequest.php', 'context.php', 'reldyn_facets.php'] as $f) {
            $hooks .= file_get_contents(__DIR__ . "/../../ext/relationship_dynamics/{$f}");
        }
        $this->assertStringNotContainsString("['interests']", $hooks, 'nobody reads the raw April interests key');
        $this->assertStringNotContainsString('RELDYN_AMBIENT_', $hooks);
        $this->assertStringNotContainsString('_minai_', file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/reldyn_facets.php'));
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
