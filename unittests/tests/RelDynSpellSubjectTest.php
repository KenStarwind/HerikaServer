<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/RelDynFacetPreferencesTest.php';   // RelDynFacetProfiles (core_npc_master rows)

/**
 * spell-subject-facets (decisions 2026-09-24 §10, Ken): "Spells are not automatically bookish.
 * A spell's facets follow its subject: nature magic (animals, plants, weather, shapeshifting,
 * beast calls) reads as nature/wild, not scholarly. That's why a druid, or a bard who communes
 * with animals, can pass Aela's test."
 *
 * The rows are the live 3.4.1 Oghma rows (dwemer.oghma, 2026-09-24, SELECT-only), as the prior
 * and the builder read them; the database side (live prior lookup, the builder) is in
 * RelDynArchetypeBlendPostgresTest.
 */
final class RelDynSpellSubjectTest extends TestCase
{
    private const LIVE_ROWS = [
        'conjure_familiar' => ['topic' => 'conjure_familiar', 'aliases' => '', 'knowledge_class' => 'mage, companions', 'category' => 'spells',
            'tags' => 'Conjuration, spectral wolf, Novice, summoning, companions, temporary effects, wolves'],
        "kyne's_peace" => ['topic' => "kyne's_peace", 'aliases' => 'kaan drem ov', 'knowledge_class' => 'dragon', 'category' => 'spells',
            'tags' => "Dragons, Thu'um, Kyne, Nature, Beasts, Pacification, Words of Power, Goddess"],
        'animal_allegiance' => ['topic' => 'animal_allegiance', 'aliases' => 'raan mir tah', 'knowledge_class' => 'dragon', 'category' => 'spells',
            'tags' => "Dragon Shouts, Thu'um, Dragons, Word Wall, Beasts, Way of the Voice, Greybeards"],
        'storm_call' => ['topic' => 'storm_call', 'aliases' => 'strun bah qo', 'knowledge_class' => 'dragon', 'category' => 'spells',
            'tags' => "Dragon Shout, Dragon Language, Lightning, Storms, Wrath, Destruction magic, Area damage, Thu'um, Dragons, Weather magic"],
        'clear_skies' => ['topic' => 'clear_skies', 'aliases' => 'lok vah koor', 'knowledge_class' => 'dragon', 'category' => 'spells',
            'tags' => "Thu'um, Dragon Shout, weather, fog, Dragon Language, Lok, Vah, Koor, sky, spring, summer"],
        'lycanthropy' => ['topic' => 'lycanthropy', 'aliases' => 'werethropy', 'knowledge_class' => 'werewolf, daedra, healer', 'category' => 'spells',
            'tags' => 'Hircine, Sanies Lupinus, Werewolf, Tamriel, Argonians, Werebeasts, Daedric Prince, Disease, Blood, Curse'],
        'bend_will' => ['topic' => 'bend_will', 'aliases' => 'gol hah dov', 'knowledge_class' => 'dragon', 'category' => 'spells',
            'tags' => 'Dragon Shout, Dragon Language, Dragons, Mind Control, Animal Command, Earth, Mind, Gol, Hah, Dov'],
        'teleport_pet' => ['topic' => 'teleport_pet', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Alteration, animal companion, summoned creature, utility magic, travel, combat'],
        'flaming_familiar' => ['topic' => 'flaming_familiar', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Conjuration, Apprentice, spectral wolf, explosion, summon, flames'],
        'healing' => ['topic' => 'healing', 'aliases' => '', 'knowledge_class' => 'mage, healer', 'category' => 'spells',
            'tags' => 'Restoration, restore health, Novice spells, healing magic, mages, allied targets'],
        'fireball' => ['topic' => 'fireball', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Destruction, fire, explosion, area of effect, Apprentice, offensive magic'],
        'flames' => ['topic' => 'flames', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Destruction, Fire Damage, Stamina, Novice, Magic, Health'],
        'calm' => ['topic' => 'calm', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Illusion, pacify, hostility, Apprentice, offensive reduction, mind magic'],
        'fear' => ['topic' => 'fear', 'aliases' => '', 'knowledge_class' => 'alchemist, mage', 'category' => 'spells',
            'tags' => "Illusion, Blue Dartwing, Namira's Rot, Powdered Mammoth Tusk, Sabre Cat Eye, Fleeing, Enchanting, Alchemy, Namira"],
        'conjure_dremora_lord' => ['topic' => 'conjure_dremora_lord', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Conjuration, Dremora, Daedra, Oblivion, summoning, Expert magic, combat allies'],
        'raise_zombie' => ['topic' => 'raise_zombie', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Conjuration, Novice skill level, reanimation, undead, necromancy, corpse'],
        'ataxia' => ['topic' => 'ataxia', 'aliases' => '', 'knowledge_class' => 'alchemist, healer', 'category' => 'spells',
            'tags' => 'disease, slaughterfish, bear, zombie, skeever, alit, muscle stiffness, lockpicking, pickpocketing'],
        'restore_health' => ['topic' => 'restore_health', 'aliases' => '', 'knowledge_class' => 'alchemist, mage, healer', 'category' => 'spells',
            'tags' => 'Restoration, healing, potions, Blue Mountain Flower, Blue Dartwing, Butterfly Wing, Charred Skeever Hide, Daedra Heart, Eye of Sabre Cat, Imp Stool, Wheat, Swamp Fungal Pod'],
        'the_red_year' => ['topic' => 'the_red_year', 'aliases' => '', 'knowledge_class' => 'scholar', 'category' => 'lore',
            'tags' => 'Red Mountain, Morrowind, Dunmer, Melis Ravel, Tear, Vivec City, Mournhold, eruption, survival, resilience, catastrophe'],
        // Rows outside the magic category whose NAME carries a magic word ('scroll', 'magic', 'staff') or a school
        'the_art_of_war_magic' => ['topic' => 'the_art_of_war_magic', 'aliases' => '', 'knowledge_class' => 'scholar', 'category' => 'lore',
            'tags' => 'Zurin Arctus, battlemages, warfare, military strategy, Destruction, magic, philosophy, preparation'],
        'an_accounting_of_the_scrolls' => ['topic' => 'an_accounting_of_the_scrolls', 'aliases' => '', 'knowledge_class' => 'scholar', 'category' => 'lore',
            'tags' => 'Elder Scrolls, Cult of the Ancestor Moth, Quintus Nerevelus, Imperial Library, prophecy, fate, forbidden knowledge, metaphysics'],
        'magicka' => ['topic' => 'magicka', 'aliases' => 'Magic', 'knowledge_class' => 'mage, scholar', 'category' => 'lore',
            'tags' => 'Aetherius, Mundus, Magnus, Magna Ge, spellcasting, enchanting, souls, arcane energy, sun and stars'],
        'conjuration' => ['topic' => 'conjuration', 'aliases' => '', 'knowledge_class' => 'mage, scholar, college_of_winterhold', 'category' => 'lore',
            'tags' => 'College of Winterhold, summoning, Daedra, undead, bound weapons, reanimation, Oblivion, banishment, magic'],
        'staff_of_magnus' => ['topic' => 'staff_of_magnus', 'aliases' => '', 'knowledge_class' => 'college_of_winterhold, scholar, mage, priest',
            'category' => 'artifacts', 'tags' => 'Magnus, Mundus, Tamriel, God of Magic, Mages Guild, metaphysical battery, magic, enchantment, drain, legend'],
        'resistance_potions' => ['topic' => 'resistance_potions', 'knowledge_class' => 'alchemist', 'category' => 'items',
            'aliases' => 'potion of resist fire, potion of resist cold, potion of resist magic, potion of resist shock, elixir of resist magic',
            'tags' => 'Fire, Frost, Shock, Magic, Draughts, Philters, Elixirs, Elemental resistance'],
        // Diseases: the tags name the carriers
        'bone_break_fever' => ['topic' => 'bone_break_fever', 'aliases' => '', 'knowledge_class' => 'alchemist, healer', 'category' => 'spells',
            'tags' => 'rats, bears, disease, stamina drain, strength loss, fever, wildlife disease'],
        'rockjoint' => ['topic' => 'rockjoint', 'aliases' => '', 'knowledge_class' => 'alchemist, healer', 'category' => 'spells',
            'tags' => 'disease, melee weapon damage, dexterity, swelling, wolves, zombies, alit, guar, Morrowind'],
        'gutworm' => ['topic' => 'gutworm', 'aliases' => '', 'knowledge_class' => 'alchemist, healer', 'category' => 'spells',
            'tags' => 'disease, Trolls, Skyrim, stamina, stamina regeneration, food, hunger'],
        // Conjuring the dead and the daedra
        'dead_thrall' => ['topic' => 'dead_thrall', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Conjuration, Master skill, Reanimation, Undead, Necromancy, Permanent spells, Followers'],
        'conjure_ash_spawn' => ['topic' => 'conjure_ash_spawn', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Conjuration, Ash Spawn, Adept magic, summoning, conjured ally, combat magic'],
        "summon_arniel's_shade" => ['topic' => "summon_arniel's_shade", 'aliases' => '', 'knowledge_class' => 'mage, college_of_winterhold',
            'category' => 'spells', 'tags' => 'Conjuration, Arniel Gane, shade, summoning, Expert skill, College of Winterhold, undead, spells'],
        'conjure' => ['topic' => 'conjure', 'aliases' => '', 'knowledge_class' => 'mage', 'category' => 'spells',
            'tags' => 'Conjuration, Oblivion, Daedra, atronachs, summoning, protection, enchantment, combat magic'],
    ];

    /** Nature magic by Ken's list: animals, weather, shapeshifting, beast calls, a familiar. */
    private const NATURE_SPELLS = ['conjure_familiar', "kyne's_peace", 'animal_allegiance', 'storm_call', 'clear_skies',
        'lycanthropy', 'bend_will', 'teleport_pet'];

    private $savedDb = null;
    private bool $hadDb = false;

    protected function setUp(): void
    {
        $this->hadDb = array_key_exists('db', $GLOBALS);
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);   // config defaults; thingFacets has no Oghma to ask
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->hadDb) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    /** Aela as the plugin registers her: the Companions' huntress (the real preference derivation). */
    private static function aelaPrefs(): array
    {
        $row = RelDynFacetProfiles::coreRow('Aela the Huntress', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'NordRace', 'FemaleEvenToned');
        return RelDynFacets::derivePreferences($row, RelDynFacetProfiles::profileFor($row))['prefs'];
    }

    private static function prior(string $topic, ?array $cfg = null): array
    {
        return RelDynFacetClassifier::priorFacets(self::LIVE_ROWS[$topic], $cfg);
    }

    /** The old reading, for contrast: every spell carried the 'spells' category prior and the mage audience. */
    private static function pre10Config(): array
    {
        $cfg = RelDynFacetClassifier::configDefaults();
        $cfg['spell_subjects']['categories'] = [];
        $cfg['spell_subjects']['magic_markers'] = [];
        $cfg['spell_subjects']['schools'] = [];
        return $cfg;
    }

    // ------------------------------------------------------------------ nature magic is not bookish

    public function testNatureMagicReadsAsNatureAndWildNotScholarly(): void
    {
        foreach (self::NATURE_SPELLS as $topic) {
            $f = self::prior($topic);
            $top = array_key_first($f);
            $this->assertContains($top, ['nature', 'wild'], "{$topic}: " . json_encode($f));
            $this->assertLessThan($f[$top], $f['scholarly'] ?? 0.0, "{$topic}: never mainly scholarly");
            $this->assertLessThanOrEqual(0.2, $f['scholarly'] ?? 0.0, "{$topic}: at most a trace of learning: " . json_encode($f));
        }
    }

    public function testAelaFindsNatureMagicWildNotBookish(): void
    {
        $aela = self::aelaPrefs();
        foreach (self::NATURE_SPELLS as $topic) {
            $a = RelDynFacets::appraise($aela, self::prior($topic));
            $felt = (string) RelDynFacets::feltText('Aela', $a, 'topic', str_replace('_', ' ', $topic));
            $this->assertGreaterThan(0.2, $a['valence'], "{$topic}: she likes it: " . json_encode($a));
            $this->assertContains($a['dominant'], ['nature', 'wild'], $topic);
            $this->assertStringNotContainsString('bookish', $felt, $topic);
            $this->assertMatchesRegularExpression('/wild|untamed/', $felt, "{$topic}: {$felt}");
        }
        // Before decisions §10 (magic = enchanting + scholarly, the mage audience) a fiery wolf
        // spirit and a healing spell were "too bookish" for her; now they read by their subject
        foreach (['flaming_familiar', 'healing'] as $topic) {
            $before = (string) RelDynFacets::feltText('Aela', RelDynFacets::appraise($aela, self::prior($topic, self::pre10Config())), 'topic', $topic);
            $this->assertStringContainsString('bookish', $before, "{$topic}: the bug this fixes");
            $after = RelDynFacets::appraise($aela, self::prior($topic));
            $this->assertStringNotContainsString('bookish', (string) RelDynFacets::feltText('Aela', $after, 'topic', $topic), $topic);
            $this->assertGreaterThanOrEqual(0.0, $after['valence'], $topic);
        }
    }

    // ------------------------------------------------------------------ other schools by subject

    public function testEverySchoolReadsByWhatTheSpellDoes(): void
    {
        $cases = [
            // restoration / healing -> healer / spiritual
            'healing'        => ['spiritual', ['healer']],
            'restore_health' => ['spiritual', ['healer']],
            // destruction -> combat / danger
            'fireball'       => ['combat', ['mage']],
            'flames'         => ['combat', ['mage']],
            // illusion calm -> social, fear -> danger
            'calm'           => ['social', ['bard', 'mage']],
            'fear'           => ['danger', ['mage', 'bard']],
            // conjuring the dead -> dark
            'raise_zombie'   => ['dark', ['mage']],
        ];
        foreach ($cases as $topic => [$dominant, $archetypes]) {
            $row = self::LIVE_ROWS[$topic];
            $r = RelDynFacetClassifier::spellReading(str_replace('_', ' ', $row['topic']), $row['tags'], $row['category']);
            $this->assertNotNull($r, $topic);
            $this->assertSame($dominant, array_key_first($r['facets']), "{$topic}: " . json_encode($r));
            $this->assertSame($archetypes, array_slice(array_keys($r['archetypes']), 0, count($archetypes)), "{$topic}: " . json_encode($r['archetypes']));
            $this->assertArrayNotHasKey('druid', $r['archetypes'], $topic);
        }
        $this->assertSame('restoration', RelDynFacetClassifier::spellReading('healing', self::LIVE_ROWS['healing']['tags'], 'spells')['school']);
        $this->assertSame('destruction', RelDynFacetClassifier::spellReading('fireball', self::LIVE_ROWS['fireball']['tags'], 'spells')['school']);
    }

    /** Conjuring daedra is danger and darkness (Oblivion's beings); a familiar (a spectral wolf) is nature. */
    public function testConjurationReadsByWhatIsConjured(): void
    {
        $daedra = self::prior('conjure_dremora_lord');
        $this->assertSame(['danger', 'dark'], array_slice(array_keys($daedra), 0, 2), json_encode($daedra));
        $this->assertLessThanOrEqual(0.2, $daedra['scholarly'] ?? 0.0, 'at most a trace of learning: ' . json_encode($daedra));
        $this->assertArrayNotHasKey('nature', $daedra);
        $familiar = self::prior('conjure_familiar');
        $this->assertSame('nature', array_key_first($familiar), json_encode($familiar));

        $d = RelDynFacetClassifier::spellReading('Conjure Dremora Lord', '', null, true);
        $this->assertSame('conjuration', $d['school']);
        $this->assertArrayNotHasKey('druid', $d['archetypes']);
        $this->assertGreaterThanOrEqual(0.9, $d['archetypes']['mage']);
        $f = RelDynFacetClassifier::spellReading('Conjure Familiar', '', null, true);
        $this->assertSame('conjuration', $f['school']);
        $this->assertSame('druid', array_key_first($f['archetypes']), json_encode($f));
    }

    /** A disease's tags name its carriers (bears): that is not nature magic. */
    public function testADiseaseIsNotItsCarriers(): void
    {
        $r = RelDynFacetClassifier::spellReading('ataxia', self::LIVE_ROWS['ataxia']['tags'], 'spells');
        $this->assertSame(['disease'], $r['subjects']);
        $this->assertTrue($r['exclusive']);
        $this->assertArrayNotHasKey('nature', self::prior('ataxia'));
        // Not in the subject reading, and not through the generic tag keywords either: rats, bears,
        // wolves and trolls are carriers, not a breath of the wild or a fight
        $aela = self::aelaPrefs();
        foreach (['ataxia', 'bone_break_fever', 'rockjoint', 'gutworm'] as $topic) {
            $f = self::prior($topic);
            foreach (['nature', 'wild', 'combat'] as $carrier) {
                $this->assertArrayNotHasKey($carrier, $f, "{$topic}: " . json_encode($f));
            }
            $this->assertContains(array_key_first($f), ['danger', 'alchemy', 'spiritual'], "{$topic}: " . json_encode($f));
            $a = RelDynFacets::appraise($aela, $f);
            $felt = (string) RelDynFacets::feltText('Aela', $a, 'topic', str_replace('_', ' ', $topic));
            $this->assertDoesNotMatchRegularExpression('/wild|untamed|fighter/', $felt, "{$topic}: {$felt}");
            $this->assertLessThan(0.2, $a['valence'], "{$topic}: a disease is nothing she warms to: " . json_encode($a));
        }
        // ... but lycanthropy is the beast form, disease or not
        $l = RelDynFacetClassifier::spellReading('lycanthropy', self::LIVE_ROWS['lycanthropy']['tags'], 'spells');
        $this->assertSame('wild', array_key_first($l['facets']));
        $this->assertSame('druid', array_key_first($l['archetypes']));
    }

    // ------------------------------------------------------------------ names: held / cast spells, items, topics

    public function testHeldAndCastSpellNamesReadBySubject(): void
    {
        $expect = [
            'Conjure Familiar' => 'druid', "Kyne's Peace" => 'druid', 'Animal Allegiance' => 'druid', 'Calm Animal' => 'druid',
            'Summon Spriggan Matron' => 'druid', 'Beast Form' => 'druid',
            'Flames' => 'mage', 'Fireball' => 'mage', 'Raise Zombie' => 'mage', 'Conjure Flame Atronach' => 'mage',
            'Healing' => 'healer', 'Healing Hands' => 'healer', 'Muffle' => 'thief',
        ];
        foreach ($expect as $name => $archetype) {
            $r = RelDynFacetClassifier::spellReading($name, '', null, true);
            $this->assertNotNull($r, $name);
            $this->assertSame($archetype, array_key_first($r['archetypes']), "{$name}: " . json_encode($r));
        }
        // A spell RelDyn knows nothing about is unknown, never guessed
        $this->assertNull(RelDynFacetClassifier::spellReading('Torch', '', null, true));
        $this->assertNull(RelDynFacetClassifier::spellReading('Vaermina\'s Torpor', '', null, true));
        // Not magic at all unless something says so (a creature named wolf is not a spell)
        $this->assertNull(RelDynFacetClassifier::spellReading('Wolf'));
    }

    public function testSpellTomesAndMagicTopicsWithoutAnOghmaEntryReadBySubject(): void
    {
        $tome = RelDynFacets::thingFacets('item', 'Spell Tome: Conjure Familiar');
        $this->assertSame('nature', array_key_first($tome), json_encode($tome));
        $this->assertLessThanOrEqual(0.2, $tome['scholarly'] ?? 0.0, json_encode($tome));
        $dark = RelDynFacets::thingFacets('item', 'Spell Tome: Raise Zombie');
        $this->assertSame('dark', array_key_first($dark), json_encode($dark));
        $staff = RelDynFacets::thingFacets('item', 'Staff of Fireballs');
        $this->assertSame('combat', array_key_first($staff), json_encode($staff));
        $topic = RelDynFacets::thingFacets('topic', 'Animal Allegiance shout');
        $this->assertSame('nature', array_key_first($topic), json_encode($topic));
        // A book is still a book, a sword a sword
        $this->assertSame('scholarly', array_key_first(RelDynFacets::thingFacets('item', 'A History of the Empire book')));
        $this->assertSame('combat', array_key_first(RelDynFacets::thingFacets('item', 'Iron Sword')));

        $aela = self::aelaPrefs();
        $felt = (string) RelDynFacets::feltText('Aela', RelDynFacets::appraise($aela, $tome), 'item', 'Spell Tome: Conjure Familiar');
        $this->assertStringNotContainsString('bookish', $felt);
    }

    /** Only magic entries change: a history the scholars keep stays a book. */
    public function testLoreStaysLore(): void
    {
        $f = self::prior('the_red_year');
        $this->assertSame('scholarly', array_key_first($f));
        $this->assertNull(RelDynFacetClassifier::spellReading('the red year', self::LIVE_ROWS['the_red_year']['tags'], 'lore'));
    }

    /**
     * An Oghma row outside the magic category is what its category says, whatever its name: the
     * Elder Scrolls books ('scroll'), The Art of War Magic ('magic'), the lore of a school
     * ('conjuration'), the Staff of Magnus (an artifact, 'staff'), potions of resist magic. Their
     * prior is exactly the pre-§10 reading (category prior, audience, keywords).
     */
    public function testMagicWordsInANameDoNotMakeLoreArtifactsOrItemsSpells(): void
    {
        foreach (['the_art_of_war_magic', 'an_accounting_of_the_scrolls', 'magicka', 'conjuration', 'staff_of_magnus', 'resistance_potions'] as $topic) {
            $row = self::LIVE_ROWS[$topic];
            $name = str_replace('_', ' ', $row['topic']) . ' | ' . $row['aliases'];
            $this->assertNull(RelDynFacetClassifier::spellReading($name, $row['tags'], $row['category']), $topic);
            $this->assertSame(self::prior($topic, self::pre10Config()), self::prior($topic), "{$topic}: unchanged by §10");
        }
        $war = self::prior('the_art_of_war_magic');
        $this->assertGreaterThanOrEqual(0.6, $war['scholarly'] ?? 0.0, 'a treatise by Zurin Arctus is a book: ' . json_encode($war));
        $aela = self::aelaPrefs();
        $this->assertLessThan(0.3, RelDynFacets::appraise($aela, $war)['valence'], 'she does not warm to a treatise: ' . json_encode($war));
        $magnus = RelDynFacets::appraise($aela, self::prior('staff_of_magnus'));
        $this->assertStringNotContainsString('bookish', (string) RelDynFacets::feltText('Aela', $magnus, 'topic', 'staff of magnus'));
        $this->assertArrayHasKey('adventure', self::prior('staff_of_magnus'), 'the artifacts prior stays');
        // Without an Oghma row a name still reads by its subject (a staff someone carries, a spell tome)
        $this->assertSame('combat', array_key_first(RelDynFacets::thingFacets('item', 'Staff of Fireballs')));
    }

    /**
     * Conjuring the dead is dark, conjuring daedra is danger (decisions §10: a spell's facets
     * follow its subject): Aela reads raise zombie or a dremora lord by that, never as "too bookish".
     */
    public function testNecromancyAndDaedraAreNotBookishToAela(): void
    {
        $aela = self::aelaPrefs();
        foreach (['raise_zombie', 'dead_thrall', "summon_arniel's_shade", 'conjure_dremora_lord', 'conjure_ash_spawn', 'conjure'] as $topic) {
            $f = self::prior($topic);
            $a = RelDynFacets::appraise($aela, $f);
            $felt = (string) RelDynFacets::feltText('Aela', $a, 'topic', str_replace('_', ' ', $topic));
            $this->assertStringNotContainsString('bookish', $felt, "{$topic}: " . json_encode($f) . ' ' . json_encode($a));
            $this->assertContains($a['dominant'], ['dark', 'danger'], "{$topic}: " . json_encode($a));
            $this->assertLessThanOrEqual(0.2, $f['scholarly'] ?? 0.0, "{$topic}: at most a trace of learning: " . json_encode($f));
        }
        foreach (['raise_zombie', 'dead_thrall', "summon_arniel's_shade"] as $topic) {
            $this->assertSame('dark', array_key_first(self::prior($topic)), $topic);
        }
    }

    /** The table is config (facet_classifier.spell_subjects): an edited subject changes the reading. */
    public function testSubjectsAreEditableConfig(): void
    {
        $cfg = RelDynFacetClassifier::configDefaults();
        $this->assertArrayHasKey('spell_subjects', RelationshipDynamics::defaultConfig()['facet_classifier']);
        $cfg['spell_subjects']['subjects']['familiar'] = ['facets' => ['scholarly' => 0.9], 'archetypes' => ['scholar' => 0.9]];
        $cfg['spell_subjects']['subjects']['wolf'] = ['ignore' => true];
        $cfg['spell_subjects']['subjects']['wolves'] = ['ignore' => true];
        $r = RelDynFacetClassifier::spellReading('Conjure Familiar', '', null, true, $cfg);
        $this->assertSame('scholarly', array_key_first($r['facets']));
        $this->assertSame('scholar', array_key_first($r['archetypes']));
        $this->assertSame('scholarly', array_key_first(RelDynFacetClassifier::priorFacets(self::LIVE_ROWS['conjure_familiar'], $cfg)));
        // every facet and weight in the default table is valid
        foreach (RelDynFacetClassifier::spellSubjectDefaults()['subjects'] as $kw => $row) {
            foreach ((array) ($row['facets'] ?? []) as $facet => $w) {
                $this->assertContains($facet, RelDynFacets::FACETS, "subjects.{$kw}");
                $this->assertTrue($w > 0 && $w <= 1, "subjects.{$kw}.{$facet}");
            }
            foreach ((array) ($row['archetypes'] ?? []) as $a => $w) {
                $this->assertContains($a, RelDynPlayer::ARCHETYPES, "subjects.{$kw}");
            }
        }
    }
}
