<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * Bystander jealousy, rulings 2026-10-01 §20 #7, end to end on a real PostgreSQL with the four test beds (Aela the
 * Huntress, Ashe (Serene's hand-set vector, never read, nothing of her story anywhere), Muiri (toxic) and Lynly
 * Star-Sung (the shy bard)), plus Ysolda, a merchant who is nothing to the player.
 *
 * Muiri, Ashe and Lynly are all committed to the player (core bond types romantic, romantic, crush) and are in the room
 * when the player flirts openly with someone else. The eval (LLM stubbed at the connector boundary) scores the flirt
 * as romantic intent 2; the real worker applies it and scans the room (item.witnesses, the eventlog people).
 *   - The rival matters: with Aela, the player's own lover (core affinity 80, romantic), each of them minds about
 *     twice as much as when it is Ysolda, a stranger to the player (the rival-threat factor);
 *   - the observer matters: the same rival lands harder on the least settled of them than on the steadiest one, by the
 *     one maturity curve the affinity pipeline already has, not by a second maturity factor on top;
 *   - and no one is immune: the steadiest observer there is (maturity 100) is still made jealous, at half the rate
 *     an unsoftened one would be.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBystanderThreatTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const YSOLDA = RelDynNetworkBedsKit::YSOLDA;
    private const OBSERVERS = [self::MUIRI, self::ASHE, self::LYNLY];
    private const FLIRT = 'You are stunning by the fire tonight. Come closer, my love.';

    private ?RelDynNetworkBedsKit $kit = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if ($this->kit !== null) $this->kit->destroy();
        $this->kit = null;
    }

    /**
     * One evening: the player flirts with $rival in front of Muiri, Ashe and Lynly. Returns, per observer, what she gained
     * ('gain' jealousy points), her unfactored 'base', the maturity she stood at, her jealousy's named rival, and the
     * scene's closeness of the rival and the clean-log check.
     *
     * @param array $rivalCore the rival's core relationship toward the player ([aff, type])
     * @param array $maturity  observer => maturity dimension to set before the flirt (a steadier or less steady her)
     */
    private function scene(string $rival, array $rivalCore, array $maturity = []): array
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'bystander');
        $kit = $this->kit;
        $kit->seed([
            self::AELA => ['Player' => $rival === self::AELA ? $rivalCore : [20, 'friend']],
            self::YSOLDA => ['Player' => $rival === self::YSOLDA ? $rivalCore : [0, 'neutral']],
            self::MUIRI => ['Player' => [70, 'romantic']],
            self::ASHE => ['Player' => [60, 'romantic']],
            self::LYNLY => ['Player' => [50, 'crush']],
        ]);
        $kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        // each of them has had a turn with the player (her bond state, with core's bond type, is stored by her own prerequest)
        foreach (self::OBSERVERS as $i => $npc) $kit->turn($npc, 'Good evening.', RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $i * 0.1), 'hello');
        foreach ($maturity as $npc => $m) {
            pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data,
                '{reldyn,dynamics,dimensions,maturity,x}', to_jsonb(\$2::float8)) WHERE npc_name = \$1", [$npc, $m]);
        }

        $kit->evalReply = function (string $exchange): ?array {
            return str_contains($exchange, 'Come closer, my love')
                ? ['signals' => ['affinity' => 2], 'tags' => ['praise'], 'significance' => 0.5, 'summary' => 'The player flirted openly.', 'romantic_intent' => 2]
                : null;
        };
        $kit->people = '|' . $rival . '|' . implode('|', self::OBSERVERS) . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $kit->turn($rival, self::FLIRT, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 19.0), 'flirt');
        $kit->worker();

        $out = ['closeness' => RelationshipDynamics::rivalCloseness(RelationshipDynamics::getPlayerRelationship($rival), RelationshipDynamics::loadStoredDynamics($rival))];
        foreach (self::OBSERVERS as $npc) {
            $d = $kit->dynamics($npc);
            $full = RelationshipDynamics::getDynamics($npc);
            $out[$npc] = ['gain' => floatval($d['jealousy_anger'] ?? 0), 'rival' => $d['jealousy_trigger_npc'] ?? null,
                          'base' => RelationshipDynamics::bystanderJealousyGain($full), 'maturity' => floatval($d['dimensions']['maturity']['x'] ?? 50.0),
                          'core_type' => $d['_core_rel_type'] ?? null];
        }
        $out['clean'] = $kit->db->failures === [] && $kit->llmCalls === 0 && !str_contains($kit->errorLog(), 'ERROR');
        $out['log'] = $kit->errorLog();
        $kit->destroy();
        $this->kit = null;
        return $out;
    }

    public function testTheSameFlirtStingsMoreWhenTheRivalIsTheirPartnersLoverThanWhenSheIsNothingToThem(): void
    {
        $close = $this->scene(self::AELA, [80, 'romantic']);
        $stranger = $this->scene(self::YSOLDA, [0, 'neutral']);
        $this->assertTrue($close['clean'], $close['log']);
        $this->assertTrue($stranger['clean'], $stranger['log']);
        $this->assertEqualsWithDelta(0.85, $close['closeness'], 1e-9, 'a lover: the bond type is a floor under the number');
        $this->assertLessThan(0.05, $stranger['closeness'], 'a stranger (the flirt itself stirred a little passion in her)');
        foreach (self::OBSERVERS as $npc) {
            $this->assertContains($close[$npc]['core_type'], ['romantic', 'crush'], "{$npc} is committed to the player");
            $this->assertSame(self::AELA, $close[$npc]['rival'], "{$npc} knows who");
            $this->assertSame(self::YSOLDA, $stranger[$npc]['rival']);
            $this->assertGreaterThan(0.0, $stranger[$npc]['gain'], "{$npc}: even a stranger's flirting stings a little");
            $this->assertGreaterThan($stranger[$npc]['gain'], $close[$npc]['gain'], "{$npc}: a close rival stings more");
            // threat 0.6 + 0.8 x 0.85 = 1.28 against about 0.6 for a stranger, everything else about her the same
            $this->assertEqualsWithDelta(RelationshipDynamics::bystanderThreat($close['closeness']) / RelationshipDynamics::bystanderThreat($stranger['closeness']),
                $close[$npc]['gain'] / $stranger[$npc]['gain'], 0.05, $npc);
            $this->assertGreaterThan(1.9, $close[$npc]['gain'] / $stranger[$npc]['gain'], "{$npc}: about twice as much");
        }
    }

    public function testEachObserverIsSoftenedByWhoSheIsOnTheOneCurveAndTheLeastSettledMindsMost(): void
    {
        $s = $this->scene(self::AELA, [80, 'romantic']);
        $this->assertTrue($s['clean'], $s['log']);
        $threat = RelationshipDynamics::bystanderThreat(0.85);
        $factor = [];
        foreach (self::OBSERVERS as $npc) {
            $this->assertGreaterThan(0.0, $s[$npc]['base'], $npc);
            // gain = commitment gain x threat x (1 + (50 - maturity)/100): the pipeline's own maturity curve, once
            $curve = 1.0 + (50.0 - $s[$npc]['maturity']) / 100.0;
            $this->assertEqualsWithDelta($s[$npc]['base'] * $threat * $curve, $s[$npc]['gain'], 0.02, "{$npc} at maturity {$s[$npc]['maturity']}");
            $factor[$npc] = $s[$npc]['gain'] / $s[$npc]['base'];
        }
        // the beds are not all the same person: the least settled of them is more rattled than the steadiest
        $maturity = [];
        foreach (self::OBSERVERS as $npc) $maturity[$npc] = $s[$npc]['maturity'];
        asort($maturity);
        $names = array_keys($maturity);
        $least = $names[0];
        $steadiest = $names[count($names) - 1];
        $this->assertLessThan($s[$steadiest]['maturity'], $s[$least]['maturity'], 'the beds do differ in maturity');
        $this->assertGreaterThan($factor[$steadiest], $factor[$least], "{$least} (least settled) is rattled more than {$steadiest} (steadiest)");
    }

    public function testNoOneIsImmuneEvenTheSteadiestThereIs(): void
    {
        $steady = $this->scene(self::AELA, [80, 'romantic'], [self::ASHE => 100.0, self::MUIRI => 100.0, self::LYNLY => 100.0]);
        $unsteady = $this->scene(self::AELA, [80, 'romantic'], [self::ASHE => 0.0, self::MUIRI => 0.0, self::LYNLY => 0.0]);
        $this->assertTrue($steady['clean'], $steady['log']);
        $this->assertTrue($unsteady['clean'], $unsteady['log']);
        $threat = RelationshipDynamics::bystanderThreat(0.85);
        foreach (self::OBSERVERS as $npc) {
            $this->assertEqualsWithDelta(100.0, $steady[$npc]['maturity'], 1e-9, "{$npc} set to the most mature");
            $this->assertGreaterThan(0.0, $steady[$npc]['gain'], "{$npc}: maturity 100 still minds (a floor, not a zero)");
            // half of an unsoftened gain, not (1 - 100/100) = nothing
            $this->assertEqualsWithDelta($steady[$npc]['base'] * $threat * 0.5, $steady[$npc]['gain'], 0.02, $npc);
            $this->assertEqualsWithDelta($unsteady[$npc]['base'] * $threat * 1.5, $unsteady[$npc]['gain'], 0.02, "{$npc} at maturity 0");
            $this->assertEqualsWithDelta(3.0, $unsteady[$npc]['gain'] / $steady[$npc]['gain'], 0.15, "{$npc}: the whole spread is the curve's 1.5 against 0.5");
        }
    }
}
