<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * oghma-facet-classifier / item-interest-classification / interest-vector-8082, the parts
 * that need no database: the deterministic prior, the keyword tables, the cosine-vs-anchor
 * math, the noisy-OR combination, the MDD 1.2 multiplier and the removal of the dead :8082 path.
 * The database side (builder, stored rows, Oghma lookup, the hooks) is
 * RelDynFacetClassifierPostgresTest.
 */
final class RelDynFacetClassifierTest extends TestCase
{
    private array $savedGlobals = [];
    private ?string $logFile = null;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'FEATURES'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        RelDynFacetClassifier::setEmbedder(null);
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdfacet');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelDynFacetClassifier::setEmbedder(null);
        RelationshipDynamics::clearConfigCache();
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    // ------------------------------------------------------------------
    // Shared API shape
    // ------------------------------------------------------------------

    public function testFacetListIsTheElevenMddInterestsPlusTheSituationalFacets(): void
    {
        $this->assertSame(['combat', 'crafting', 'alchemy', 'enchanting', 'scholarly', 'nature', 'social', 'domestic',
            'adventure', 'spiritual', 'wealth'], RelDynFacets::INTERESTS, 'MDD 1.2: the 11 interests');
        $this->assertSame(['danger', 'crowd', 'wild', 'confined', 'dark', 'sacred', 'luxury', 'quiet'], RelDynFacets::SITUATIONAL);
        $this->assertSame(array_merge(RelDynFacets::INTERESTS, RelDynFacets::SITUATIONAL), RelDynFacets::FACETS);
    }

    public function testEveryFacetHasOneAnchorDescriptionInConfig(): void
    {
        $anchors = RelationshipDynamics::defaultConfig()['facet_classifier']['anchors'];
        $this->assertSame(RelDynFacets::FACETS, array_keys($anchors));
        $this->assertStringStartsWith('scholarly: books, research, ancient lore, study', $anchors['scholarly']);
    }

    public function testEveryConfiguredTableNamesOnlyKnownFacetsWithWeightsInRange(): void
    {
        $cfg = RelDynFacetClassifier::configDefaults();
        foreach (['knowledge_class_prior', 'category_prior', 'tag_keywords', 'item_keywords', 'creature_keywords', 'activity_keywords'] as $table) {
            foreach ($cfg[$table] as $key => $facets) {
                foreach ($facets as $facet => $w) {
                    $this->assertContains($facet, RelDynFacets::FACETS, "{$table}.{$key}");
                    $this->assertTrue($w > 0 && $w <= 1, "{$table}.{$key}.{$facet} = {$w}");
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Keyword tables and the deterministic prior
    // ------------------------------------------------------------------

    public function testKeywordsMatchWholeWordsAndPlurals(): void
    {
        $table = ['ring' => ['wealth' => 0.9], 'cave' => ['confined' => 0.6]];
        $this->assertSame(['wealth' => 0.9], RelDynFacetClassifier::keywordFacets('Gold Ring', $table));
        $this->assertSame([], RelDynFacetClassifier::keywordFacets('an offering', $table), "'ring' inside 'offering' is not a ring");
        $this->assertSame(['confined' => 0.6], RelDynFacetClassifier::keywordFacets('bandits, caves', $table), 'plural');
    }

    public function testALongerKeywordWinsOverTheShorterOneInsideIt(): void
    {
        $items = RelDynFacetClassifier::configDefaults()['item_keywords'];
        $mara = RelDynFacetClassifier::keywordFacets('Amulet of Mara', $items);
        $this->assertSame(['spiritual' => 1.0, 'sacred' => 0.6], $mara, "a divine amulet is spiritual, not the plain 'amulet' jewelry");
        $tome = RelDynFacetClassifier::keywordFacets('Spell Tome: Flames', $items);
        $this->assertSame(['enchanting' => 0.7, 'scholarly' => 0.7], $tome);
    }

    public function testPriorOfTheDwemerLoreEntryIsScholarlyWithRuinFacets(): void
    {
        // The live 3.4.1 oghma row 'dwemer' (knowledge_class / tags / category / aliases).
        $row = ['topic' => 'dwemer', 'knowledge_class' => 'scholar', 'category' => 'lore', 'aliases' => 'dwarves, Deep Elves',
            'tags' => 'Mer, Morrowind, Red Mountain, Velothi Mountains, Chimer, Heart of Lorkhan, disappearance, underground cities, Dwarven ruins, First Era, Rourken'];
        $f = RelDynFacetClassifier::priorFacets($row);
        // category lore 0.8 beats knowledge_class scholar 1.0 x 0.6 and the alias 'deep elves' 0.6
        $this->assertSame(0.8, $f['scholarly']);
        // its own name 'dwemer' (x1.0) beats the tags 'underground' 0.7 x 0.6 and 'ruins' 0.7 x 0.6
        $this->assertSame(0.5, $f['confined']);
        $this->assertSame(0.6, $f['adventure']);
        $this->assertSame(0.5, $f['crafting']);
        $this->assertSame(0.3, $f['dark'], "tag 'underground' 0.5 x 0.6");
        $this->assertSame('scholarly', array_key_first($f));
        $this->assertArrayNotHasKey('nature', $f, "'Red Mountain' is geography, not nature");
    }

    /** The hold says where, not what kind of place: only 'traveler' (adventure 0.3 x 0.6) speaks. */
    public function testAHoldCategoryCarriesNoFacet(): void
    {
        $this->assertSame(['adventure' => 0.18], RelDynFacetClassifier::priorFacets(['topic' => 'dragonsreach', 'category' => 'whiterun', 'knowledge_class' => 'whiterun, traveler']));
    }

    public function testThingFacetsWithoutADatabaseUsesTheKindsKeywordTable(): void
    {
        $this->assertSame(['combat' => 1.0, 'crafting' => 0.3], RelDynFacets::thingFacets('item', 'Steel Sword'));
        $this->assertSame(['wild' => 0.8, 'nature' => 0.7, 'danger' => 0.6, 'combat' => 0.5], RelDynFacets::thingFacets('creature', 'Sabre Cat'));
        $this->assertSame(['nature' => 0.7, 'quiet' => 0.7, 'domestic' => 0.3], RelDynFacets::thingFacets('activity', 'fishing'));
        $this->assertSame('scholarly', array_key_first(RelDynFacets::thingFacets('place', 'The Arcanaeum')));
        $this->assertSame([], RelDynFacets::thingFacets('item', 'Mysterious Thingamajig'), 'unknown -> []');
        $this->assertSame([], RelDynFacets::thingFacets('item', '   '));
    }

    public function testUnknownKindIsRefusedAndLogged(): void
    {
        $this->assertSame([], RelDynFacets::thingFacets('smell', 'bread'));
        $this->assertStringContainsString("thingFacets: unknown kind 'smell'", $this->log());
    }

    public function testKeywordTablesAreEditableConfig(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['facet_classifier'] = ['item_keywords' => ['thingamajig' => ['wealth' => 0.4]]];
        $GLOBALS['db'] = new RelDynFacetConfigDb(json_encode($cfg));
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(['wealth' => 0.4], RelDynFacets::thingFacets('item', 'Mysterious Thingamajig'));
        $this->assertSame([], RelDynFacets::thingFacets('item', 'Steel Sword'), 'a stored table replaces its default');
        $this->assertNotEmpty(RelDynFacetClassifier::config()['tag_keywords'], 'tables not stored keep their defaults');
    }

    // ------------------------------------------------------------------
    // Embedding math (vectors synthetic: the model is not under test)
    // ------------------------------------------------------------------

    /** Unit vector along axis $i of a 19-dim space, one axis per facet, as anchors. */
    private static function axisAnchors(): array
    {
        $anchors = [];
        foreach (RelDynFacets::FACETS as $i => $facet) {
            $v = array_fill(0, count(RelDynFacets::FACETS), 0.0);
            $v[$i] = 1.0;
            $anchors[$facet] = $v;
        }
        return $anchors;
    }

    private static function vec(array $weights): array
    {
        $v = array_fill(0, count(RelDynFacets::FACETS), 0.0);
        foreach ($weights as $facet => $w) {
            $v[array_search($facet, RelDynFacets::FACETS, true)] = $w;
        }
        return $v;
    }

    public function testEmbeddingFacetsAreTheSoftmaxRelativeToTheBestAnchor(): void
    {
        $p = ['temperature' => 0.05, 'min_similarity' => 0.15, 'min_weight' => 0.25];
        // cosines: scholarly 0.6/n, crafting 0.57/n, nature 0.3/n  (n = the vector norm)
        $v = self::vec(['scholarly' => 0.6, 'crafting' => 0.57, 'nature' => 0.3]);
        $n = sqrt(0.36 + 0.3249 + 0.09);
        $f = RelDynFacetClassifier::embeddingFacets($v, self::axisAnchors(), $p);
        $this->assertSame(1.0, $f['scholarly']);
        $this->assertEqualsWithDelta(exp(((0.57 - 0.6) / $n) / 0.05), $f['crafting'], 0.001);
        $this->assertArrayNotHasKey('nature', $f, 'exp(-0.34/0.05) is far below min_weight');
    }

    public function testCalibratedFacetsAreTheSoftmaxOfEachAnchorsZScore(): void
    {
        $p = ['z_temperature' => 0.5, 'min_top_z' => 1.0, 'min_weight' => 0.25];
        $v = self::vec(['scholarly' => 0.6, 'dark' => 0.8]);
        $n = sqrt(0.36 + 0.64);
        $cal = array_fill_keys(RelDynFacets::FACETS, ['mean' => 0.0, 'std' => 0.1]);
        // dark sits close to everything in the corpus: its cosine counts from a higher mean
        $cal['dark'] = ['mean' => 0.25, 'std' => 0.1];
        $f = RelDynFacetClassifier::embeddingFacets($v, self::axisAnchors(), $p, $cal);
        $zScholarly = (0.6 / $n) / 0.1;           // 6.0
        $zDark = (0.8 / $n - 0.25) / 0.1;         // 5.5
        $this->assertSame(['scholarly', 'dark'], array_keys($f), 'raw cosine ranks dark first; calibrated, scholarly stands out');
        $this->assertSame(1.0, $f['scholarly']);
        $this->assertEqualsWithDelta(exp(($zDark - $zScholarly) / 0.5), $f['dark'], 0.001);
        // nothing stands out: every z below min_top_z
        $flat = array_fill_keys(RelDynFacets::FACETS, ['mean' => 0.7, 'std' => 0.2]);
        $this->assertSame([], RelDynFacetClassifier::embeddingFacets($v, self::axisAnchors(), $p, $flat));
        // a facet without calibration cannot be scored
        unset($cal['quiet']);
        $this->assertSame([], RelDynFacetClassifier::embeddingFacets($v, self::axisAnchors(), $p, $cal));
    }

    public function testCalibrationIsEachAnchorsMeanAndStdOverTheCorpus(): void
    {
        $anchors = self::axisAnchors();
        $cal = RelDynFacetClassifier::calibrate([self::vec(['scholarly' => 1.0]), self::vec(['nature' => 1.0])], $anchors);
        $this->assertEqualsWithDelta(0.5, $cal['scholarly']['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $cal['scholarly']['std'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $cal['combat']['mean'], 1e-9);
        $this->assertNull(RelDynFacetClassifier::calibrate([self::vec(['scholarly' => 1.0])], $anchors), 'no corpus');
    }

    public function testAnEmbeddingThatResemblesNoAnchorSaysNothing(): void
    {
        $v = self::vec(['scholarly' => 0.1]);
        $v[] = 1.0;   // mostly along a direction no anchor has: best cosine ~0.1
        $anchors = array_map(fn($a) => array_merge($a, [0.0]), self::axisAnchors());
        $this->assertSame([], RelDynFacetClassifier::embeddingFacets($v, $anchors, ['temperature' => 0.05, 'min_similarity' => 0.15, 'min_weight' => 0.25]));
    }

    public function testPriorAndEmbeddingCombineByNoisyOr(): void
    {
        $f = RelDynFacetClassifier::combine(['scholarly' => 1.0, 'adventure' => 0.5], ['scholarly' => 0.5, 'crafting' => 0.4], ['weight' => 0.8]);
        $this->assertEqualsWithDelta(1 - 0.5 * (1 - 0.8), $f['scholarly'], 1e-9, 'agreement raises');
        $this->assertEqualsWithDelta(0.4, $f['adventure'], 1e-9, 'embedding alone is discounted by weight');
        $this->assertEqualsWithDelta(0.4, $f['crafting'], 1e-9, 'prior alone keeps its weight');
        $this->assertSame(['scholarly' => 0.9], RelDynFacetClassifier::combine([], ['scholarly' => 0.9], ['weight' => 0.8]));
    }

    public function testVersionChangesWhenATableOrAnchorChanges(): void
    {
        $cfg = RelDynFacetClassifier::configDefaults();
        $prior = RelDynFacetClassifier::version($cfg, 'prior');
        $emb = RelDynFacetClassifier::version($cfg, 'embedding');
        $this->assertNotSame($prior, $emb);
        $edited = $cfg;
        $edited['anchors']['quiet'] = 'quiet: hush';
        $this->assertSame($prior, RelDynFacetClassifier::version($edited, 'prior'), 'anchors do not shape a prior row');
        $this->assertNotSame($emb, RelDynFacetClassifier::version($edited, 'embedding'));
        $edited = $cfg;
        $edited['tag_keywords']['library'] = ['scholarly' => 0.5];
        $this->assertNotSame($prior, RelDynFacetClassifier::version($edited, 'prior'));
        $edited = $cfg;
        $edited['embedding']['timeout_s'] = 99;
        $this->assertSame($emb, RelDynFacetClassifier::version($edited, 'embedding'), 'the HTTP timeout shapes nothing');
    }

    public function testEmbedRejectsAVectorOfTheWrongSize(): void
    {
        RelDynFacetClassifier::setEmbedder(fn(string $t) => [0.1, 0.2]);
        $this->assertNull(RelDynFacetClassifier::embed('x'));
        $this->assertStringContainsString('embedding has 2 values, expected 384', $this->log());
    }

    public function testTheServiceIsCoresTxtaiUrl(): void
    {
        $GLOBALS['FEATURES'] = ['MEMORY_EMBEDDING' => ['TXTAI_URL' => 'http://127.0.0.1:8082/']];
        $this->assertSame('http://127.0.0.1:8082', RelDynFacetClassifier::serviceUrl());
        $GLOBALS['FEATURES'] = ['MEMORY_EMBEDDING' => ['TXTAI_URL' => '']];
        $this->assertNull(RelDynFacetClassifier::serviceUrl());
        $this->assertNull(RelDynFacetClassifier::embed('x'), 'no service configured');
        $this->assertStringContainsString('embedding service not configured', $this->log());
    }

    // ------------------------------------------------------------------
    // Hand-off to the appraisal
    // ------------------------------------------------------------------

    /** One MDD 1.2 mapping (RelDynFacets::interestMultiplier) for places, topics and gifts alike. */
    public function testTopicsAndGiftsUseTheOneMddInterestMapping(): void
    {
        $this->assertFalse(method_exists(RelDynFacetClassifier::class, 'interestMultiplier'), 'no second mapping');
        $this->assertArrayNotHasKey('interest_mult_min', RelDynFacetClassifier::appraisalDefaults());
        $this->assertArrayNotHasKey('interest_mult_max', RelDynFacetClassifier::appraisalDefaults());
        $this->assertSame(0.5, RelDynFacets::interestMultiplier(-1.0));
        $this->assertSame(0.75, RelDynFacets::interestMultiplier(-0.5));
        $this->assertSame(1.0, RelDynFacets::interestMultiplier(0.0));
        $this->assertSame(1.5, RelDynFacets::interestMultiplier(0.5));
        $this->assertSame(2.0, RelDynFacets::interestMultiplier(3.0), 'clamped');
    }

    public function testTurnTopicsAreThisTurnsGroundedOghmaTopics(): void
    {
        $saved = $GLOBALS['OGHMA_PARITY_RESULT'] ?? null;
        $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => ['dwemer', ' ', 'dwemer', 'blackreach']];
        $this->assertSame(['dwemer', 'blackreach'], RelDynFacetClassifier::turnTopics());
        unset($GLOBALS['OGHMA_PARITY_RESULT']);
        $this->assertSame([], RelDynFacetClassifier::turnTopics());
        if ($saved !== null) $GLOBALS['OGHMA_PARITY_RESULT'] = $saved;
    }

    public function testAGiftOfAnUnknownItemStaysTransactional(): void
    {
        $g = RelDynFacetClassifier::giftAppraisal([], 'Aela', 'Mysterious Thingamajig');
        $this->assertSame(0.5, $g['mult']);
        $this->assertNull($g['appraisal']);
    }

    public function testClassifyItemInterestIsTheStrongestInterestFacetOfTheItem(): void
    {
        $this->assertSame('combat', RelationshipDynamics::classifyItemInterest('Steel Sword'));
        $this->assertSame('spiritual', RelationshipDynamics::classifyItemInterest('Amulet of Mara'));
        $this->assertSame('alchemy', RelationshipDynamics::classifyItemInterest('Potion of Healing'));
        $this->assertNull(RelationshipDynamics::classifyItemInterest('Mysterious Thingamajig'));
        $this->assertNull(RelationshipDynamics::classifyItemInterest(''));
    }

    /** The batch builder CLI refuses unknown arguments before it touches any database. */
    public function testBuilderCliRejectsUnknownArguments(): void
    {
        $tool = realpath(__DIR__ . '/../../ext/relationship_dynamics/tools/build_oghma_facets.php');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --bogus 2>&1', $out, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('usage: php build_oghma_facets.php [--prior-only] [--force]', implode("\n", $out));
    }

    // ------------------------------------------------------------------
    // interest-vector-8082: the dead path is gone
    // ------------------------------------------------------------------

    public function testTheDead8082InterestVectorPathIsRemoved(): void
    {
        $this->assertFalse(method_exists('RelationshipDynamics', 'embedInterestVector'));
        $this->assertFalse(method_exists('RelationshipDynamics', 'getInterestVector'));
        $hits = [];
        foreach (glob(__DIR__ . '/../../ext/relationship_dynamics/*.php') as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match('/localhost:8082|api\/embedtext|_interest_vector|InterestVector|minai_items/', $line)) {
                    $hits[] = basename($file) . ':' . ($n + 1) . ': ' . trim($line);
                }
            }
        }
        $this->assertSame([], $hits);
    }
}

/** A $db that only answers the RelDyn config row (config-driven tables). */
final class RelDynFacetConfigDb
{
    public function __construct(private string $configJson) {}

    public function fetchOne($q, array $params = [])
    {
        if (str_contains($q, 'relationship_dynamics_config')) {
            return ['value' => $this->configJson];
        }
        return [];
    }

    public function fetchAll($q, $log = false) { return []; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}
