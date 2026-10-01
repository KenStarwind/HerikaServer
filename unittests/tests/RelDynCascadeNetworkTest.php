<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * cascade-network and npc-npc-tiered-eval, the parts that need no database: the ripple's math and
 * filters, what is a defining moment, what an eval item notes, the felt line, and the words and the
 * SQL of the NPC-NPC facts source. (The story, end to end on PostgreSQL with the four test beds, is
 * RelDynCascadeNetworkTestBedsPostgresTest and RelDynNpcFactsTestBedsPostgresTest.)
 */
final class RelDynCascadeNetworkTest extends TestCase
{
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['db']);
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ who hears, and how much

    public function testTheRippleIsTheChangeTimesTheBond(): void
    {
        // the MDD's Farkas: bonded to Aela at 80, she loses 10 with the player: he loses 8 (rulings 2026-10-01 §20 #13;
        // was x 0.3 for everyone, -2.4). What is only told is damped by cascade_decay (RelDynRippleDeliveryTest).
        $this->assertEqualsWithDelta(-8.0, RelDynCascade::rippleFor(-10.0, 80.0), 1e-9);
        $this->assertEqualsWithDelta(-10.0, RelDynCascade::rippleFor(-20.0, 50.0), 1e-9);
        $this->assertEqualsWithDelta(10.0, RelDynCascade::rippleFor(20.0, 50.0), 1e-9, 'good news travels the same way');
        // a closer friend hears more
        $this->assertLessThan(RelDynCascade::rippleFor(-20.0, 40.0), RelDynCascade::rippleFor(-20.0, 90.0));
    }

    public function testAnEnemyHearsItInvertedAndWeaker(): void
    {
        $ally = RelDynCascade::rippleFor(-20.0, 60.0);
        $enemy = RelDynCascade::rippleFor(-20.0, -60.0);
        $this->assertLessThan(0.0, $ally);
        $this->assertGreaterThan(0.0, $enemy, 'her enemy is glad of it');
        $this->assertEqualsWithDelta(abs($ally) * 0.5, $enemy, 1e-9, 'enemy_mult');
        $this->assertSame('ally_hurt', RelDynCascade::feltKind(-20.0, 60.0));
        $this->assertSame('ally_helped', RelDynCascade::feltKind(20.0, 60.0));
        $this->assertSame('rival_hurt', RelDynCascade::feltKind(-20.0, -60.0));
        $this->assertSame('rival_helped', RelDynCascade::feltKind(20.0, -60.0));
    }

    public function testTheBondFilterAndTheMinimumRipple(): void
    {
        $this->assertNull(RelDynCascade::rippleFor(-30.0, 30.0), 'a bond of exactly 30 does not hear');
        $this->assertNull(RelDynCascade::rippleFor(-30.0, -30.0));
        $this->assertNotNull(RelDynCascade::rippleFor(-30.0, 31.0));
        $this->assertNull(RelDynCascade::rippleFor(-1.5, 60.0), 'a ripple under a point is not carried (1.5 x 0.6 = 0.9; was -5 at x 0.3)');
        $this->assertNotNull(RelDynCascade::rippleFor(-2.0, 60.0));
        // the config moves both
        $cfg = array_replace(RelDynCascade::config(), ['min_bond' => 10.0, 'min_ripple' => 0.5]);
        $this->assertNotNull(RelDynCascade::rippleFor(-1.5, 40.0, $cfg), '1.5 x 0.4 = 0.6, over a minimum of half a point (was -5 at x 0.3)');
        $this->assertNull(RelDynCascade::rippleFor(-1.5, 40.0), 'and not over the default minimum');
        $this->assertNotNull(RelDynCascade::rippleFor(-30.0, 15.0, $cfg), 'a bond of 15 hears once the filter is 10');
        $this->assertNull(RelDynCascade::rippleFor(-30.0, 15.0), 'and not at the default filter');
        $this->assertSame(RelationshipDynamics::CASCADE_MAX_TARGETS, RelDynCascade::config()['max_targets']);
    }

    public function testTheBondIsCappedAtOneHundred(): void
    {
        $this->assertSame(RelDynCascade::rippleFor(-20.0, 100.0), RelDynCascade::rippleFor(-20.0, 250.0));
    }

    // ------------------------------------------------------------------ the source: what an eval item notes

    private static function item(float $significance, array $tags = []): array
    {
        return ['significance' => $significance, 'tags' => $tags];
    }

    public function testDefiningMoments(): void
    {
        $this->assertFalse(RelDynCascade::isDefining(self::item(0.5)));
        $this->assertTrue(RelDynCascade::isDefining(self::item(0.8)), 'high significance');
        $this->assertTrue(RelDynCascade::isDefining(self::item(0.4, ['rescue'])), 'a rescue');
        $this->assertTrue(RelDynCascade::isDefining(self::item(0.4, ['help', 'betrayal'])), 'a betrayal');
        $this->assertFalse(RelDynCascade::isDefining(self::item(0.39, ['betrayal'])), 'a passing remark tagged as one is not');
        $this->assertFalse(RelDynCascade::isDefining(self::item(0.9 - 0.2, ['insult'])));
    }

    /** processEvalContractItem's totals: affinity in mirror units, core points = x2. */
    private function note(float $mirrorChange, float $significance, array $tags = [], array $dynamics = []): array
    {
        $n = ['significance' => $significance, 'tags' => $tags];
        $entry = RelDynCascade::noteItem('Aela the Huntress', $n, ['affinity' => $mirrorChange], $dynamics, 1000.0, 'fp' . $mirrorChange . $significance, 'The player mocked her hunt');
        return [$entry, $dynamics];
    }

    public function testAnItemNotesARippleWhenItMovedHerAffinityEnough(): void
    {
        [$entry, $d] = $this->note(-8.0, 0.3);   // -16 core points: over the threshold of 15
        $this->assertSame(-16.0, $entry['delta']);
        $this->assertFalse($entry['defining']);
        $this->assertCount(1, $d[RelDynCascade::OUT_KEY]);
        $this->assertSame('The player mocked her hunt', $d[RelDynCascade::OUT_KEY][0]['anchor']);

        [$entry] = $this->note(-7.0, 0.3);       // -14: under it, an everyday exchange
        $this->assertNull($entry);
        [$entry] = $this->note(7.0, 0.3);        // kind words are no news either
        $this->assertNull($entry);
        [$entry] = $this->note(8.0, 0.3);
        $this->assertSame(16.0, $entry['delta'], 'a big gain ripples too');
    }

    public function testADefiningMomentRipplesFromTheLowerThreshold(): void
    {
        [$entry] = $this->note(-3.0, 0.9);       // -6 core points, significance 0.9
        $this->assertSame(-6.0, $entry['delta']);
        $this->assertTrue($entry['defining']);
        [$entry] = $this->note(-1.4, 0.9);       // -2.8: under even the defining floor of 3
        $this->assertNull($entry);
        [$entry] = $this->note(-3.0, 0.3, ['betrayal']);   // the tag alone needs its own significance
        $this->assertNull($entry);
        [$entry] = $this->note(-3.0, 0.5, ['betrayal']);
        $this->assertTrue($entry['defining']);
        [$entry] = $this->note(0.0, 1.0);        // a defining moment that moved nothing has no direction
        $this->assertNull($entry);
    }

    public function testTheSameItemIsNotNotedTwiceAndTheListIsBounded(): void
    {
        $d = [];
        $n = ['significance' => 0.9, 'tags' => []];
        RelDynCascade::noteItem('Aela the Huntress', $n, ['affinity' => -8.0], $d, 1.0, 'same', null);
        $this->assertNull(RelDynCascade::noteItem('Aela the Huntress', $n, ['affinity' => -8.0], $d, 1.0, 'same', null));
        $this->assertCount(1, $d[RelDynCascade::OUT_KEY]);
        for ($i = 0; $i < 12; $i++) RelDynCascade::noteItem('Aela the Huntress', $n, ['affinity' => -8.0], $d, 1.0, "fp{$i}", null);
        $this->assertCount(RelDynCascade::OUT_MAX, $d[RelDynCascade::OUT_KEY]);
        $this->assertSame('fp11', $d[RelDynCascade::OUT_KEY][RelDynCascade::OUT_MAX - 1]['fp'], 'the newest are kept');
    }

    public function testNothingIsNotedWithTheNetworkOff(): void
    {
        $GLOBALS['db'] = new class {
            public function fetchOne($q, array $params = []) { return ['value' => json_encode(['cascade_network_enabled' => false, 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA])]; }
            public function fetchAll($q, $log = false) { return []; }
            public function escape($s) { return str_replace("'", "''", (string) $s); }
        };
        RelationshipDynamics::clearConfigCache();
        $this->assertFalse(RelDynCascade::enabled());
        [$entry, $d] = $this->note(-15.0, 1.0);
        $this->assertNull($entry);
        $this->assertArrayNotHasKey(RelDynCascade::OUT_KEY, $d);
    }

    // ------------------------------------------------------------------ the felt line

    public function testTheFeltLineIsSaidOnceToThePlayersFaceAndIsFeelingsNotNumbers(): void
    {
        $d = [RelDynCascade::FELT_KEY => [
            ['source' => 'Aela the Huntress', 'kind' => 'ally_hurt', 'reason' => 'The player mocked her hunt', 'gamets' => 1.0],
            ['source' => 'Aela the Huntress', 'kind' => 'rival_helped', 'reason' => null, 'gamets' => 2.0],
        ]];
        // not said to anyone but the player
        $out = RelDynCascade::takeFeltLines($d, 'Farkas', 'Kaida', false);
        $this->assertSame([], $out['lines']);
        $this->assertFalse($out['changed']);
        $this->assertCount(2, $d[RelDynCascade::FELT_KEY], 'it waits');

        $out = RelDynCascade::takeFeltLines($d, 'Farkas', 'Kaida', true);
        $this->assertTrue($out['changed']);
        $this->assertSame(['ally_hurt', 'rival_helped'], array_column($out['lines'], 'key'));
        $this->assertStringContainsString('Farkas has heard what Kaida did to Aela the Huntress (The player mocked her hunt)', $out['lines'][0]['text']);
        $this->assertStringContainsString('cooler toward Kaida', $out['lines'][0]['text']);
        $this->assertStringNotContainsString('()', $out['lines'][1]['text'], 'no reason, no brackets');
        foreach ($out['lines'] as $l) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $l['text']);
            $this->assertEqualsWithDelta(0.7, $l['salience'], 1e-9);
        }
        $this->assertArrayNotHasKey(RelDynCascade::FELT_KEY, $d, 'said once');
        $this->assertSame([], RelDynCascade::takeFeltLines($d, 'Farkas', 'Kaida', true)['lines']);
    }

    // ------------------------------------------------------------------ the NPC-NPC facts source

    public function testTraitKeywordsAreTheMostExtremeThreeAndNeverTheMiddle(): void
    {
        $x = ['G' => 0.9, 'E' => 0.1, 'C' => 0.5, 'Pd' => 0.95, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.2, 'D' => 0.5, 'Po' => 0.05, 'Pr' => 0.5];
        $this->assertSame(['proud', 'guarded', 'reserved'], RelDynNpcFacts::traitKeywords($x, 3), 'by how far from the middle, not by table order');
        $this->assertSame(['proud'], RelDynNpcFacts::traitKeywords($x, 1));
        $this->assertSame([], RelDynNpcFacts::traitKeywords(array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.5), 3), 'nothing stands out: nothing is said');
        $this->assertSame([], RelDynNpcFacts::traitKeywords($x, 0));
        // not being possessive is no trait worth telling anyone
        $this->assertNotContains('unpossessive', RelDynNpcFacts::traitKeywords(['Po' => 0.0] + $x, 10));
        $this->assertSame(['possessive'], RelDynNpcFacts::traitKeywords(['Po' => 1.0], 3));
    }

    public function testTheSourceEntryIsTheShapeCoreReadsAndTheTiersAreInTheSql(): void
    {
        $e = RelDynNpcFacts::sourceEntry();
        $this->assertSame(RelDynNpcFacts::OWNER, $e['owner']);
        $this->assertSame('core_npc_master', $e['table']);
        $this->assertSame('npc_name', $e['name_column']);
        $this->assertSame(['Temperament', 'Attachment style', 'Strongest traits', 'Personality', 'Speech'], array_keys($e['facts']));
        foreach (['Temperament', 'Attachment style', 'Strongest traits'] as $k) $this->assertStringContainsString('>= 20 THEN', $e['facts'][$k], $k);
        foreach (['Personality', 'Speech'] as $k) $this->assertStringContainsString('> 50 THEN', $e['facts'][$k], $k);
        $this->assertStringContainsString("'_npc_facts'", $e['facts']['Temperament']);
        $this->assertStringContainsString("relationships' -> 'Player' -> 'aff'", $e['facts']['Temperament']);
        $this->assertStringContainsString('left(', $e['facts']['Speech']);
        $this->assertStringContainsString(', 200)', $e['facts']['Speech']);
        // JSON round trip: what core reads back is what was registered
        $this->assertSame($e, json_decode(json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true));
    }

    public function testTheSqlNumbersAreWholeAndInRangeWhateverTheConfigSays(): void
    {
        $e = RelDynNpcFacts::sourceEntry(['tier2_min' => "1; DROP TABLE conf_opts", 'tier3_min' => 10, 'trait_count' => 3, 'speech_max_chars' => 0]);
        $this->assertStringNotContainsString('DROP', json_encode($e));
        $this->assertStringContainsString('>= 1 THEN', $e['facts']['Temperament'], 'read as a whole number');
        $this->assertStringContainsString('> 10 THEN', $e['facts']['Personality']);
        $this->assertArrayNotHasKey('Speech', $e['facts'], 'no room for her speech: no fact');
        $e = RelDynNpcFacts::sourceEntry(['tier2_min' => 60, 'tier3_min' => 20, 'trait_count' => 3, 'speech_max_chars' => 99999]);
        $this->assertStringContainsString('>= 60 THEN', $e['facts']['Temperament']);
        $this->assertStringContainsString('> 60 THEN', $e['facts']['Personality'], 'tier 3 is never below tier 2');
        $this->assertStringContainsString(', 2000)', $e['facts']['Speech']);
        $this->assertSame(['tier2_min' => 20, 'tier3_min' => 50, 'trait_count' => 3, 'speech_max_chars' => 200], RelDynNpcFacts::numbers());
    }

    public function testTheWordsOfAnNpcAreHerOwnReadingsAndNeverNumbers(): void
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['inferred_temperament'] = 'Proud';
        $w = RelDynNpcFacts::words($d);
        $this->assertSame('Proud', $w['temperament']);
        $this->assertContains($w['attachment'], ['secure', 'anxious', 'avoidant', 'fearful']);
        $this->assertStringStartsWith('Proud: ', $w['personality']);
        $this->assertStringContainsString('proud', $w['traits']);
        $this->assertDoesNotMatchRegularExpression('/\d/', implode(' ', $w));
        // refresh writes them once and reports a change only when there is one
        $this->assertTrue(RelDynNpcFacts::refresh($d));
        $this->assertSame($w, $d[RelDynNpcFacts::KEY]);
        $this->assertFalse(RelDynNpcFacts::refresh($d));
        $d['inferred_temperament'] = 'Gentle';
        $this->assertTrue(RelDynNpcFacts::refresh($d));
        $this->assertSame('Gentle', $d[RelDynNpcFacts::KEY]['temperament']);
        // no label and no stored vector: nothing is invented
        $none = RelationshipDynamics::defaultDynamics();
        $this->assertArrayNotHasKey('temperament', RelDynNpcFacts::words($none));
        $this->assertArrayNotHasKey('personality', RelDynNpcFacts::words($none));
    }
}
