<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynFulfillmentConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}

/**
 * Rulings 2026-09-24 §9, fulfillment coverage: a per-NPC needs vector (loved facets, love
 * languages, trait-driven needs), what the relationship delivers against it over game time
 * (eval tags, places and things shared), coverage per axis and a band -1..+1 that decays on
 * the game calendar; and the mature boundary's state machine. Pure: no database (a config
 * stub where a test changes config), fixed game timestamps.
 */
final class RelDynFulfillmentTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY;                          // game day 300, 00:00

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function config(array $overrides): void
    {
        $GLOBALS['db'] = new RelDynFulfillmentConfigDb(array_merge(RelationshipDynamics::defaultConfig(), $overrides));
        RelationshipDynamics::clearConfigCache();
    }

    /** Aela-like: loves the wilds and a fight, dislikes the library. */
    private function prefs(): array
    {
        return array_replace(RelDynFacets::neutralPreferences(), ['nature' => 0.9, 'combat' => 0.8, 'wild' => 0.2, 'scholarly' => -0.5]);
    }

    private function npc(array $o = [], float $maturity = 80.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Independent',
            'attachment_style' => 'secure',
            'traits' => [],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_core_rel_type' => 'romantic',
        ], $o));
        $d['dimensions']['maturity']['x'] = $maturity;
        return $d;
    }

    /** A shared-eval-contract v1 item, as the eval lane produces it. */
    private function item(float $gamets, array $tags, float $significance = 1.0, array $o = []): array
    {
        return array_replace([
            'v' => 1, 'npc' => 'Aela', 'npc_id' => 7, 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance,
            'positive_interaction' => false,
            'summary' => 'exchange ' . implode(',', $tags) . ' at ' . $gamets,
        ], $o);
    }

    public function testNeedsVectorIsLovedFacetsLoveLanguagesAndTraitNeeds(): void
    {
        $needs = RelDynFulfillment::needs($this->npc(), $this->prefs());
        $this->assertSame(['quality_time' => 1.0, 'nature' => 0.9, 'combat' => 0.8, 'words_of_affirmation' => 0.6,
            // rulings §10 intimacy axes from the same profile (RelDynIntimacy): emotional base 0.45,
            // Independent -0.1, quality time +0.15, words x0.6 +0.06; physical base 0.45, in play (romantic)
            RelDynIntimacy::EMOTIONAL => 0.56, RelDynIntimacy::PHYSICAL => 0.45], $needs,
            'loved facets by preference strength, primary 1.0 / secondary 0.6 love language; disliked and faint facets are no need');

        // Egocentric wants admiration; an anxious attachment wants time and reassurance; Proud adds admiration
        $needy = RelDynFulfillment::needs($this->npc(['inferred_temperament' => 'Proud', 'attachment_style' => 'anxious',
            'traits' => ['egocentric', 'insecure']]), $this->prefs());
        $this->assertSame(1.0, $needy['admiration'], '0.8 egocentric + 0.4 Proud, capped at 1');
        $this->assertSame(1.0, $needy['reassurance'], '0.8 insecure + 0.6 anxious, capped');
        $this->assertSame(1.0, $needy['quality_time'], 'primary 1.0 + anxious 0.6, capped');
    }

    public function testNeedRulesAndThresholdsComeFromConfig(): void
    {
        $this->config(['fulfillment' => ['facet_need_min' => 0.85,
            'need_rules' => [['temperament' => 'Independent', 'axes' => ['space' => 0.7]]]] + RelDynFulfillment::configDefaults()]);
        $needs = RelDynFulfillment::needs($this->npc(), $this->prefs());
        $this->assertSame(0.7, $needs['space'], 'a need rule from config');
        $this->assertArrayHasKey('nature', $needs);
        $this->assertArrayNotHasKey('combat', $needs, 'combat 0.8 is below the configured 0.85');
        $this->assertSame(RelDynFulfillment::KIND_NEED, RelDynFulfillment::axisKind('space'));
        $this->assertSame(RelDynFulfillment::KIND_FACET, RelDynFulfillment::axisKind('nature'));
        $this->assertSame(RelDynFulfillment::KIND_LOVE_LANGUAGE, RelDynFulfillment::axisKind('quality_time'));
    }

    public function testCoverageStartsNeutralAndDecaysOnTheGameCalendar(): void
    {
        $d = $this->npc();
        $this->assertTrue(RelDynFulfillment::ensure($d, $this->prefs(), self::T0));
        $f = RelDynFulfillment::compute($d, [], self::T0);
        $this->assertTrue($f['known']);
        $this->assertSame(0.0, $f['band'], 'a new relationship starts neutral');
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0], array_values($f['coverage']));

        // One half-life (3 game days) with nothing delivered: every level halves, coverage -0.5
        $f = RelDynFulfillment::compute($d, [], self::T0 + 3 * self::DAY);
        $this->assertEqualsWithDelta(-0.5, $f['band'], 1e-4);
        foreach ($f['coverage'] as $c) $this->assertEqualsWithDelta(-0.5, $c, 1e-4);
        $this->assertTrue($f['low_band']);
        // Recent matters more: much later it is fully uncovered
        $this->assertLessThan(-0.95, RelDynFulfillment::compute($d, [], self::T0 + 20 * self::DAY)['band']);
        $this->assertFalse(RelDynFulfillment::ensure($d, $this->prefs(), self::T0 + self::DAY), 'same needs: nothing to change');
    }

    public function testEvalTagsDeliverAgainstTheirNeedsAndHurtfulTagsTakeCoverageAway(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);

        // Real eval consumer path: a full-significance quality-time exchange at T0 + 1 h
        RelationshipDynamics::processEvalContractItem('Aela', $this->item(self::T0 + self::HOUR, ['quality_time']), $d);
        $lv = $d['_fulfillment']['lv'];
        $decayed = 1.5 * 0.5 ** ((1 / 24) / 3);
        $this->assertEqualsWithDelta($decayed + 1.0, $lv['quality_time'], 1e-3, 'quality_time: +1 unit');
        $this->assertEqualsWithDelta($decayed, $lv['words_of_affirmation'], 1e-3, 'untouched axis only decayed');
        $this->assertArrayNotHasKey('reassurance', $lv, 'not a need of hers: nothing stored');

        // Significance scales the delivery: 0.3 + 0.7 x 0.5 = 0.65 units of praise to words
        RelationshipDynamics::processEvalContractItem('Aela', $this->item(self::T0 + self::HOUR, ['praise'], 0.5), $d);
        $this->assertEqualsWithDelta($decayed + 0.65, $d['_fulfillment']['lv']['words_of_affirmation'], 1e-3);

        // An insult takes it away again (and more)
        RelationshipDynamics::processEvalContractItem('Aela', $this->item(self::T0 + self::HOUR, ['insult'], 1.0,
            ['signals' => ['affinity' => -10, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0]]), $d);
        $this->assertEqualsWithDelta($decayed - 0.35, $d['_fulfillment']['lv']['words_of_affirmation'], 1e-3);

        // A 'gift' in a grievance exchange is not a gift: its positive delivery is dropped
        $before = $d['_fulfillment']['lv'];
        $amounts = RelDynFulfillment::evalItemAmounts(RelationshipDynamics::normalizeEvalContractItem($this->item(self::T0, ['gift', 'criticism'], 1.0,
            ['grievance' => ['flag' => true, 'kind' => 'disrespect', 'severity' => 1]])));
        $this->assertArrayNotHasKey('gifts', $amounts);
        $this->assertEqualsWithDelta(-0.5, $amounts['words_of_affirmation'], 1e-9);
        $this->assertSame($before, $d['_fulfillment']['lv']);

        // A pleasant exchange without tags is still some time together
        $this->assertEqualsWithDelta(0.3, RelDynFulfillment::evalItemAmounts(RelationshipDynamics::normalizeEvalContractItem(
            $this->item(self::T0, [], 1.0, ['positive_interaction' => true])))['quality_time'], 1e-9);
    }

    public function testALateEvalItemLandsAlreadyDecayed(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::deliver($d, ['combat' => 0.0001], self::T0 + 3 * self::DAY);   // levels now as of day 3
        $at3 = $d['_fulfillment']['lv']['quality_time'];
        // An exchange from day 0 applied only now (the worker was behind): one half-life old
        RelDynFulfillment::deliver($d, ['quality_time' => 1.0], self::T0);
        $this->assertEqualsWithDelta($at3 + 0.5, $d['_fulfillment']['lv']['quality_time'], 1e-3);
        $this->assertEquals(self::T0 + 3 * self::DAY, $d['_fulfillment']['gamets'], 'the clock never goes back');
    }

    public function testPlacesAndExperiencesSharedCoverFacetNeeds(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $woods = ['nature' => 1.0, 'wild' => 0.8];
        RelDynFacets::placeTurn('Aela', $d, 'Fallowstone Woods', $woods, $this->prefs(), self::T0);                  // arrival
        $natureAt0 = $d['_fulfillment']['lv']['nature'];
        RelDynFacets::placeTurn('Aela', $d, 'Fallowstone Woods', $woods, $this->prefs(), self::T0 + 2 * self::HOUR); // 2 h there
        $decay = 0.5 ** ((2 / 24) / 3);
        $this->assertEqualsWithDelta($natureAt0 * $decay + 1.0 * 2 * 0.5, $d['_fulfillment']['lv']['nature'], 1e-3,
            'nature weight 1 x 2 game hours x 0.5 units per hour');

        // A fight together (RelDyn's combat activity facets): combat 1.0 x 0.5 units
        $combatBefore = RelDynFulfillment::levelsAt($d['_fulfillment'], self::T0 + 3 * self::HOUR)['combat'];
        RelDynFacets::experienceThing('Aela', $d, 'activity', 'combat', $this->prefs(), self::T0 + 3 * self::HOUR);
        $this->assertEqualsWithDelta($combatBefore + 0.5, $d['_fulfillment']['lv']['combat'], 1e-3);
    }

    public function testNoStateNoDelivery(): void
    {
        $d = $this->npc();
        $this->assertSame([], RelDynFulfillment::deliver($d, ['quality_time' => 1.0], self::T0));
        $this->assertArrayNotHasKey('_fulfillment', $d, 'only a contact creates the state');
        $f = RelationshipDynamics::fulfillment('Aela', $d + ['facet_pref_overrides' => ['nature' => 0.9]], self::T0);
        $this->assertFalse($f['known']);
        $this->assertSame(0.0, $f['band']);
        $this->assertSame(0.9, $f['needs']['nature'], 'needs are known before the first contact');
    }

    public function testTrendIsTheSlopeOfDailyBands(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::tick($d, self::T0 + 4 * self::DAY + self::HOUR);
        $this->assertCount(4, $d['_fulfillment']['days'], 'one sample per game-day end');
        $f = RelDynFulfillment::compute($d, [], self::T0 + 4 * self::DAY);
        $this->assertLessThan(0.0, $f['trend'], 'getting worse every day');
        $this->assertSame(0.0, RelDynFulfillment::trend([[1, 0.2]], RelDynFulfillment::config()));
        $this->assertEqualsWithDelta(0.1, RelDynFulfillment::trend([[1, 0.0], [2, 0.1], [3, 0.2]], RelDynFulfillment::config()), 1e-9);
    }

    public function testHighBandBuffersAbsenceLowBandSharpensIt(): void
    {
        $d = $this->npc();
        $this->assertSame(['grace' => 1.0, 'rate' => 1.0, 'band' => null], RelationshipDynamics::absenceBandFactors($d));
        $d['_fulfillment'] = ['contact_band' => 1.0];
        $this->assertEquals(['grace' => 2.0, 'rate' => 0.5, 'band' => 1.0], RelationshipDynamics::absenceBandFactors($d));
        $d['_fulfillment'] = ['contact_band' => -1.0];
        $this->assertEquals(['grace' => 0.5, 'rate' => 2.0, 'band' => -1.0], RelationshipDynamics::absenceBandFactors($d));
    }

    public function testLowBandIsRelationshipDeprivationForTheWeather(): void
    {
        $d = $this->npc();
        $this->assertNull(RelDynFulfillment::weatherDeprivation($d, self::T0), 'no state: the weather reads facets alone');
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $this->assertSame(0.0, RelDynFulfillment::weatherDeprivation($d, self::T0));
        $this->assertEqualsWithDelta(0.5, RelDynFulfillment::weatherDeprivation($d, self::T0 + 3 * self::DAY), 1e-4);
    }

    /** Deliver enough every game day to keep every need of hers covered. */
    private function goodDay(array &$d, float $at): void
    {
        RelDynFulfillment::deliver($d, ['quality_time' => 1.5, 'words_of_affirmation' => 1.5, 'nature' => 1.5, 'combat' => 1.5,
            RelDynIntimacy::EMOTIONAL => 1.5, RelDynIntimacy::PHYSICAL => 1.5], $at);
    }

    public function testMatureBoundaryIsStatedOnceAndConsistentChangeClearsIt(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);

        // Present but giving nothing: the band sinks below low_band after ~1.2 game days and
        // stays there; after 5 game days of that the boundary is decided (pending), not said yet
        for ($h = 12; $h <= 6 * 24 + 12; $h += 12) {
            RelDynFulfillment::tick($d, self::T0 + $h * self::HOUR);
        }
        $this->assertSame('pending', $d['_fulfillment']['boundary']['state']);

        // Said once, on her next turn, calmly; the probation window starts then
        $now = self::T0 + 6.5 * self::DAY;
        $said = RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', $now);
        $this->assertTrue($said['changed']);
        $this->assertArrayHasKey('boundary', $said['texts']);
        $this->assertStringContainsString('consistently', $said['texts']['boundary']);
        $this->assertStringContainsString('real time together', $said['texts']['boundary'], 'names what she misses most');
        $this->assertDoesNotMatchRegularExpression('/\d/', $said['texts']['boundary'], 'a feeling, never a number');
        $this->assertSame('probation', $d['_fulfillment']['boundary']['state']);
        $again = RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', $now + self::HOUR);
        $this->assertArrayNotHasKey('boundary', $again['texts'], 'stated once');
        $this->assertArrayHasKey('probation', $again['texts']);

        // Four consecutive covered days clear it (not one good day)
        for ($k = 0; $k < 4; $k++) {
            $this->goodDay($d, $now + ($k + 0.1) * self::DAY);
            RelDynFulfillment::tick($d, $now + ($k + 1) * self::DAY);
        }
        $this->assertSame('none', $d['_fulfillment']['boundary']['state'], 'resolved');
        $relief = RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', $now + 4 * self::DAY);
        $this->assertArrayHasKey('resolved', $relief['texts']);
        $this->assertArrayNotHasKey('resolved', RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', $now + 4 * self::DAY)['texts']);
    }

    public function testOneGoodDayDoesNotPassTheProbation(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $now = self::T0 + 7.5 * self::DAY;
        RelDynFulfillment::tick($d, $now);
        $this->assertSame('pending', $d['_fulfillment']['boundary']['state'], 'low from the day-2 end, 5 game days sustained');
        RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', $now);   // stated: probation starts
        $this->goodDay($d, $now + 0.5 * self::DAY);                    // one big effort
        $this->goodDay($d, $now + 0.6 * self::DAY);
        $t = RelDynFulfillment::tick($d, $now + 7 * self::DAY);        // then the old pattern
        $this->assertSame('failed', $d['_fulfillment']['boundary']['state']);
        $this->assertContains('step_back_due', $t['events']);
        $this->assertGreaterThan(0, max(array_column($d['_fulfillment']['days'], 1)), 'there was a good day');
    }

    public function testImmatureOrUnboundNpcsStateNoBoundary(): void
    {
        $d = $this->npc([], 30.0);   // immature: festers instead (neglect), still misses what she needs
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::tick($d, self::T0 + 10 * self::DAY);
        $this->assertSame('none', $d['_fulfillment']['boundary']['state']);
        $this->assertArrayHasKey('unmet', RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', self::T0 + 10 * self::DAY)['texts']);

        // A stranger (core neutral, no affinity): no bond to step back from, and no needs of
        // hers weigh on the player: no boundary, no unmet line, no relationship deprivation
        $d = $this->npc(['_core_rel_type' => 'neutral']);
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::tick($d, self::T0 + 10 * self::DAY);
        $this->assertSame('none', $d['_fulfillment']['boundary']['state']);
        $this->assertSame([], RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', self::T0 + 10 * self::DAY)['texts']);
        $this->assertSame(0.0, RelDynFulfillment::weatherDeprivation($d, self::T0 + 10 * self::DAY));
        $stranger = $this->npc(['_core_rel_type' => 'neutral']);
        $this->assertSame(['changed' => false, 'events' => [], 'step_back' => null, 'band' => null],
            RelationshipDynamics::advanceFulfillment('Aela', $stranger, self::T0, true));
        $this->assertArrayNotHasKey('_fulfillment', $stranger, 'a first contact with a stranger creates no state');
    }

    public function testLegacyLoveLanguageExchangeDelivers(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $this->assertEqualsWithDelta(0.5, RelationshipDynamics::recordLoveLanguageFulfillment($d, RelationshipDynamics::LL_TIME, self::T0)['quality_time'], 1e-9);
        $this->assertSame([], RelationshipDynamics::recordLoveLanguageFulfillment($d, null, self::T0));
        $this->assertSame([], RelationshipDynamics::recordLoveLanguageFulfillment($d, RelationshipDynamics::LL_GIFTS, self::T0), 'not a need of hers');
    }

    public function testFeltLinesCarryNoNumbers(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $felt = RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', self::T0 + 4 * self::DAY);
        $this->assertStringContainsString('Aela keeps waiting on something from Kaida that does not come', $felt['texts']['unmet']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt['texts']['unmet']);
        $this->assertSame([], RelDynFulfillment::takeFeltTexts($d, 'Aela', 'Kaida', self::T0)['texts'], 'covered: nothing to say');
    }

    public function testSpiderGraphAxes(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::deliver($d, ['nature' => 1.5], self::T0);
        $g = RelDynFulfillment::graph('Aela', $d, [], self::T0);
        $this->assertTrue($g['known']);
        $this->assertSame(['quality_time', 'nature', 'combat', 'words_of_affirmation', RelDynIntimacy::EMOTIONAL, RelDynIntimacy::PHYSICAL],
            array_column($g['axes'], 'axis'));
        $byAxis = array_column($g['axes'], null, 'axis');
        $this->assertSame(['axis' => 'nature', 'kind' => 'facet', 'label' => 'time out in the wilds', 'need' => 0.9, 'coverage' => 1.0], $byAxis['nature']);
        $this->assertSame('love_language', $byAxis['quality_time']['kind']);
        $this->assertSame(['intimacy', 'real closeness, being truly known'],
            [$byAxis[RelDynIntimacy::EMOTIONAL]['kind'], $byAxis[RelDynIntimacy::EMOTIONAL]['label']]);
        $this->assertSame(['state' => 'none'], $g['boundary']);
        $this->assertNotFalse(json_encode($g));
    }

    public function testFulfillmentOffChangesNothing(): void
    {
        $this->config(['fulfillment' => ['enabled' => false] + RelDynFulfillment::configDefaults()]);
        $d = $this->npc();
        $this->assertFalse(RelDynFulfillment::ensure($d, $this->prefs(), self::T0));
        $this->assertArrayNotHasKey('_fulfillment', $d);
        $d['_fulfillment'] = ['contact_band' => -1.0];
        $this->assertSame(['grace' => 1.0, 'rate' => 1.0, 'band' => null], RelationshipDynamics::absenceBandFactors($d));
    }
}
