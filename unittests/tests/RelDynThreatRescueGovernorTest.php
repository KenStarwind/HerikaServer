<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Phase 4 combat lane, the pure parts (no database):
 *   enemy-threat-scaling  MDD 3.3 Stage 2: combatendmighty 2.0x, a regular combat end 1.0x, the
 *                         death of a weak enemy (animals, bandits) 0.5x; the victim is read from
 *                         AIAgent 3.4.1's own death strings (the weapon is not the victim).
 *   rescue-bonus          MDD 3.3: the player's next exchange after her fall, caring, gives a
 *                         passion bonus by who she is: Guarded +4, Bold +1, Independent +1.5 at
 *                         their presets (the traits), Anxious +5 (and a dependency), Avoidant +2
 *                         at the corners of the attachment axes (decisions §12).
 *   tiered-governors      MDD 8.1 floor / ceiling by relationship tier from core's own data,
 *                         raised for an attracted NPC whose gate allows it (8.2), bounding gains only.
 */
final class RelDynThreatRescueGovernorTest extends TestCase
{
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'label';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdthreat');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ enemy threat

    public function testTheWeaponIsNotTheVictim(): void
    {
        foreach (['(Context location: Bleak Falls Barrow)Aela the Huntress has defeated Bandit Chief using weapon Dragonbone Sword',
                  'Aela the Huntress has defeated Bandit Chief using Dragonbone Sword ',
                  'Aela the Huntress has defeated Bandit Chief with Dragonbone Sword in an awesome move',
                  'Aela the Huntress has defeated Bandit Chief  in an awesome move'] as $row) {
            [, $killer, $victim] = RelDynCombat::parse($row, 'Kaida');
            $this->assertSame('Aela the Huntress', $killer, $row);
            $this->assertSame('Bandit Chief', $victim, $row);
            $this->assertSame('weak', RelDynCombat::threatClass('death', $victim), 'a Dragonbone Sword is no dragon');
        }
    }

    public function testThreatClassesFollowTheMdd(): void
    {
        $this->assertSame('mighty', RelDynCombat::threatClass('combatendmighty', null), 'the plugin names the mighty fight');
        $this->assertSame('regular', RelDynCombat::threatClass('combatend', null));
        $this->assertSame('regular', RelDynCombat::threatClass('radiantcombatfriend', null));
        foreach (['Skeever', 'Bandit Marauder', 'Cave Bear', 'Wolf', 'Giant Frostbite Spider', 'Mudcrab'] as $v) {
            $this->assertSame('weak', RelDynCombat::threatClass('death', $v), $v);
        }
        foreach (['Dwarven Centurion', 'Blood Dragon', 'Dragon Priest', 'Frost Giant', 'Mammoth', 'Vampire Lord'] as $v) {
            $this->assertSame('mighty', RelDynCombat::threatClass('death', $v), $v);
        }
        foreach (['Frost Troll', 'Werewolf', 'Draugr Wight', 'Nazeem'] as $v) {
            $this->assertSame('regular', RelDynCombat::threatClass('death', $v), "{$v}: whole words only, nothing else is weak or mighty");
        }
        $this->assertSame(2.0, RelDynCombat::threatMult('mighty'));
        $this->assertSame(1.0, RelDynCombat::threatMult('regular'));
        $this->assertSame(0.5, RelDynCombat::threatMult('weak'));
        $this->assertSame(1.0, RelDynCombat::threatMult('unheard of'), 'a class the table lacks is regular');
        $this->assertSame(RelDynCombat::configDefaults(), RelationshipDynamics::defaultConfig()['combat'], 'shipped in the config');
    }

    // ------------------------------------------------------------------ rescue response

    public function testTheSecureCornerIsExactAtTheMddPresets(): void
    {
        $cfg = RelDynCombat::rescueDefaults();
        foreach (['Guarded' => 4.0, 'Bold' => 1.0, 'Independent' => 1.5] as $preset => $want) {
            $this->assertEqualsWithDelta($want, RelDynCombat::secureBonus(RelDynTraits::presetPoint($preset), $cfg), 1e-9, $preset);
        }
        $model = RelDynCombat::secureModel($cfg);
        $this->assertGreaterThan(0.0, $model['G'], 'walls crack: the more guarded, the more a rescue means');
        $this->assertLessThan(0.0, $model['C'], 'one who is sure of themself does not need saving');
        $this->assertSame(2.0, RelDynCombat::secureBonus(null, $cfg), 'no vector: the configured middle');
        $sure = ['G' => 0.0, 'C' => 1.0] + RelDynTraits::presetPoint('Bold');
        $this->assertSame(0.0, RelDynCombat::secureBonus($sure, $cfg), 'clamped to the range, never a loss');
    }

    public function testASecureModelTheConfigCannotDefineIsLoggedNotGuessed(): void
    {
        $cfg = array_replace(RelDynCombat::rescueDefaults(), ['secure_anchors' => ['Guarded' => 4.0, 'Bold' => 1.0]]);
        $this->assertNull(RelDynCombat::secureModel($cfg));
        $this->assertSame(2.0, RelDynCombat::secureBonus(RelDynTraits::presetPoint('Guarded'), $cfg));
        $this->assertStringContainsString('ERROR combat.rescue', (string) file_get_contents($this->errorLog));
    }

    /** A bond at core affinity $aff (the mirror x) with a temperament label and attachment axes. */
    private static function npc(string $temperament, float $anxiety, float $avoidance, float $aff = 40.0, string $coreType = 'neutral'): array
    {
        return [
            'inferred_temperament' => $temperament, 'love_language_primary' => RelationshipDynamics::LL_TIME,
            'profile_overrides' => ['attachment_axes' => ['anxiety' => $anxiety, 'avoidance' => $avoidance]],
            '_aff_mirror_x' => ($aff + 100.0) / 2.0, 'dimensions' => ['affinity' => ['x' => ($aff + 100.0) / 2.0], 'passion' => ['x' => 5.0, 'baseline' => 0]],
            'passion' => 5.0, '_core_rel_type' => $coreType,
            // the Matrix does not judge here: no attraction factor, no raise
            '_attraction' => ['enabled' => false, 'attracted' => true, 'spark' => 20.0, 'passion_mult' => 1.0, 'spark_mult' => 1.0],
        ];
    }

    public function testTheResponseIsWhoSheIsAtTheCorners(): void
    {
        $cases = [
            // temperament, anxiety, avoidance => bonus, felt
            ['Guarded', 0.15, 0.15, 4.0, 'walls'],
            ['Bold', 0.15, 0.15, 1.0, 'nod'],
            ['Independent', 0.15, 0.15, 1.5, 'grudging'],
            ['Guarded', 0.85, 0.15, 5.0, 'cling'],
            ['Guarded', 0.15, 0.85, 2.0, 'ashamed'],
            ['Guarded', 0.85, 0.85, 3.5, 'torn'],
        ];
        foreach ($cases as [$t, $anx, $avo, $bonus, $felt]) {
            $r = RelDynCombat::rescueResponse(self::npc($t, $anx, $avo));
            $this->assertEqualsWithDelta($bonus, $r['bonus'], 1e-4, "{$t} {$anx}/{$avo}");
            $this->assertSame($felt, $r['felt'], "{$t} {$anx}/{$avo}");
        }
        $this->assertEqualsWithDelta(1.0, RelDynCombat::rescueResponse(self::npc('Bold', 0.85, 0.15))['anxious_lean'], 1e-9);
        $this->assertEqualsWithDelta(0.0, RelDynCombat::rescueResponse(self::npc('Bold', 0.15, 0.85))['anxious_lean'], 1e-9);
        // In between: a blend, never outside the rows
        $mid = RelDynCombat::rescueResponse(self::npc('Guarded', 0.5, 0.5));
        $this->assertGreaterThan(2.0, $mid['bonus']);
        $this->assertLessThan(5.0, $mid['bonus']);
    }

    public function testTheFirstExchangeAfterTheFallDecidesOnceAndOnlyInsideTheWindow(): void
    {
        $fall = 5_000_000_000.0;
        $minute = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;

        // Local path: she answers grateful -> caring, the bonus lands, the dependency of an anxious lean
        $d = self::npc('Guarded', 0.85, 0.15);
        RelDynCombat::noteFall($d, $fall);
        $changed = false;
        $r = RelDynCombat::onExchange('Lynly Star-Sung', $d, false, RelationshipDynamics::LL_SERVICE, 'Grateful', $fall + 2 * $minute, $changed);
        $this->assertTrue($changed);
        $this->assertTrue($r['caring']);
        $this->assertEqualsWithDelta(5.0, $r['bonus'], 1e-4);
        $this->assertEqualsWithDelta(10.0, RelationshipDynamics::getPassion($d), 1e-6, 'the bonus, below the spark and the governor');
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $d);
        $this->assertGreaterThan(0.0, floatval($d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY]['anxiety'] ?? 0), 'anxious: builds dependency');
        $this->assertNull(RelDynCombat::onExchange('Lynly Star-Sung', $d, false, null, 'Grateful', $fall + 3 * $minute), 'once');
        $vars = ['{NAME}' => 'Lynly Star-Sung', '{PLAYER}' => 'Kaida'];
        $this->assertStringContainsString('afraid to be left', (string) RelDynCombat::rescueFeltText($d, $vars, $fall + 3 * $minute));
        $this->assertNull(RelDynCombat::rescueFeltText($d, $vars, $fall + 2 * $minute + 6 * $minute), 'the felt read lasts five minutes of play');

        // Local path, not caring: the service a line right after a fight always reads as is not care
        $d = self::npc('Guarded', 0.15, 0.15);
        RelDynCombat::noteFall($d, $fall);
        $r = RelDynCombat::onExchange('Ashe', $d, false, RelationshipDynamics::LL_SERVICE, 'annoyed', $fall + $minute);
        $this->assertFalse($r['caring']);
        $this->assertEqualsWithDelta(5.0, RelationshipDynamics::getPassion($d), 1e-9);
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $d, 'the moment is spent');

        // Too late: the moment passed
        $d = self::npc('Guarded', 0.15, 0.15);
        RelDynCombat::noteFall($d, $fall);
        $this->assertNull(RelDynCombat::onExchange('Ashe', $d, false, null, 'grateful', $fall + 11 * $minute));
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $d);

        // Eval path: the exchange is claimed; only its own item decides
        $d = self::npc('Guarded', 0.15, 0.15);
        RelDynCombat::noteFall($d, $fall);
        $g = $fall + $minute;
        $this->assertNull(RelDynCombat::onExchange('Ashe', $d, true, RelationshipDynamics::LL_SERVICE, null, $g));
        $this->assertSame($g, $d[RelDynCombat::RESCUE_PENDING_KEY]['claimed_gamets']);
        $this->assertNull(RelDynCombat::onExchange('Ashe', $d, false, null, 'grateful', $g + $minute), 'a later exchange is not the next one');
        $item = ['gamets' => (int) ($g + $minute), 'tags' => ['rescue'], 'positive_interaction' => true];
        $this->assertNull(RelDynCombat::onEvalItem('Ashe', $item, $d), 'the item of a later exchange does not decide');
        $r = RelDynCombat::onEvalItem('Ashe', ['gamets' => (int) $g, 'tags' => ['reassurance', 'quality_time'], 'positive_interaction' => true], $d);
        $this->assertTrue($r['caring']);
        $this->assertEqualsWithDelta(4.0, $r['bonus'], 1e-4, 'Guarded: walls crack');
        $this->assertSame('walls', $d[RelDynCombat::RESCUE_LAST_KEY]['felt']);
        $jev = RelDynCombat::jev($d);
        $this->assertFalse($jev['pending']);
        $this->assertEqualsWithDelta(4.0, $jev['last_bonus'], 1e-4);

        // Eval path, a negative item: no bonus
        $d = self::npc('Guarded', 0.15, 0.15);
        RelDynCombat::noteFall($d, $fall);
        RelDynCombat::onExchange('Ashe', $d, true, null, null, $g);
        $r = RelDynCombat::onEvalItem('Ashe', ['gamets' => (int) $g, 'tags' => ['insult'], 'positive_interaction' => false], $d);
        $this->assertFalse($r['caring']);
    }

    // ------------------------------------------------------------------ tiered governors

    public function testTheTierComesFromCoresOwnData(): void
    {
        $g = fn(array $d) => RelDynGovernors::governor($d);
        // A stranger: Unknown / Acquaintance, 0 / 20; the 20 is the spark now (decisions §13: it
        // "uncaps passion", the uphill brakes the climb), so the next rung's 40 bounds it
        $this->assertSame(['tier' => 'distant', 'floor' => 0.0, 'ceiling' => 40.0, 'base_ceiling' => 20.0, 'raised' => false],
            $g(self::npc('Bold', 0.15, 0.15, 0.0)));
        $asWritten = ['spark_supersedes' => false] + RelDynGovernors::configDefaults();
        $this->assertSame(20.0, RelDynGovernors::governor(self::npc('Bold', 0.15, 0.15, 0.0), $asWritten)['ceiling'], 'the MDD row as written');
        $this->assertSame('distant', $g(self::npc('Bold', 0.15, 0.15, 20.0))['tier'], 'an acquaintance');
        $this->assertSame(['friendly', 5.0, 40.0], array_values(array_intersect_key($g(self::npc('Bold', 0.15, 0.15, 40.0)), array_flip(['tier', 'floor', 'ceiling']))));
        $this->assertSame('friendly', $g(self::npc('Bold', 0.15, 0.15, 95.0))['tier'], 'a devoted friendship is still platonic');
        $this->assertSame(['crush', 10.0, 80.0], array_values(array_intersect_key($g(self::npc('Bold', 0.15, 0.15, 60.0, 'crush')), array_flip(['tier', 'floor', 'ceiling']))));
        $this->assertSame(['committed', 20.0, 100.0], array_values(array_intersect_key($g(self::npc('Bold', 0.15, 0.15, 80.0, 'romantic')), array_flip(['tier', 'floor', 'ceiling']))));
        foreach ([[40.0, 'ex'], [40.0, 'enemy'], [-20.0, 'neutral']] as [$aff, $type]) {
            $h = $g(self::npc('Bold', 0.15, 0.15, $aff, $type));
            $this->assertSame(['hostile', 0.0, 0.0], [$h['tier'], $h['floor'], $h['ceiling']], "{$type} at {$aff}: Divorced / Hostile");
        }
        // A romance type the attraction blocks is no romance here: the depth decides
        $blocked = self::npc('Bold', 0.15, 0.15, 60.0, 'crush');
        $blocked['_attraction']['blocked_types'] = ['crush', 'romantic'];
        $this->assertSame('friendly', $g($blocked)['tier']);
        $this->assertNull(RelDynGovernors::governor(self::npc('Bold', 0.15, 0.15), ['enabled' => false] + RelDynGovernors::configDefaults()));
    }

    public function testAnAttractedNpcWhoseGateAllowsItReadsTheNextCeiling(): void
    {
        $judged = function (float $aff, string $gate, bool $attracted, ?string $hardZero = null, ?array $cfg = null): array {
            $d = self::npc('Bold', 0.15, 0.15, $aff);
            $d['_attraction'] = ['enabled' => true, 'attracted' => $attracted, 'gate' => $gate, 'hard_zero' => $hardZero,
                'spark' => 20.0, 'passion_mult' => 1.0, 'spark_mult' => 1.0, 'blocked_types' => []];
            return RelDynGovernors::governor($d, $cfg);
        };
        // The MDD rows as written (spark_supersedes off)
        $asWritten = ['spark_supersedes' => false] + RelDynGovernors::configDefaults();
        $aela = $judged(0.0, 'visceral', true, null, $asWritten);
        $this->assertSame(40.0, $aela['ceiling'], 'Aela (MDD 8.2): 40 instead of 20 at Acquaintance');
        $this->assertTrue($aela['raised']);
        $this->assertSame(20.0, $aela['base_ceiling']);
        $this->assertSame(20.0, $judged(0.0, 'bond', true, null, $asWritten)['ceiling'], 'Ashe (bond-gated): the table as it is');
        $this->assertSame(20.0, $judged(0.0, 'visceral', false, null, $asWritten)['ceiling'], 'not attracted: no raise');
        $this->assertSame(20.0, $judged(0.0, 'visceral', true, 'orientation', $asWritten)['ceiling'], 'a hard zero: no raise');
        // Shipped: the spark supersedes the 20, so 40 at Acquaintance for everyone; the raise shows from Friendly
        $this->assertSame(40.0, $judged(0.0, 'visceral', true)['ceiling']);
        $this->assertTrue($judged(0.0, 'visceral', true)['raised']);
        $this->assertSame(40.0, $judged(0.0, 'visceral', false)['ceiling'], 'the unattracted climb the uphill to 40 (won over there, §15)');
        $this->assertSame(80.0, $judged(40.0, 'balanced', true)['ceiling'], 'a friendship that attracts can grow toward a crush');
        $this->assertSame(40.0, $judged(40.0, 'bond', true)['ceiling'], 'Ashe (bond-gated): the Friendly row as it is');
        $this->assertSame(40.0, $judged(40.0, 'visceral', false)['ceiling'], 'the Friendly row until she is won over');
        $crush = self::npc('Bold', 0.15, 0.15, 60.0, 'crush');
        $crush['_attraction'] = ['enabled' => true, 'attracted' => true, 'gate' => 'visceral', 'spark' => 20.0, 'blocked_types' => []];
        $this->assertSame(100.0, RelDynGovernors::governor($crush)['ceiling'], 'an attracted crush may reach the top');
    }

    public function testTheCeilingBoundsGainsNeverCutsAndTheHooverIsExempt(): void
    {
        $d = self::npc('Bold', 0.15, 0.15, 40.0);   // a friend, not attracted: 40
        $this->assertEqualsWithDelta(0.5, RelDynGovernors::gainFactor($d, 35.0, 10.0), 1e-12, 'half the gain fits under 40');
        $this->assertSame(0.0, RelDynGovernors::gainFactor($d, 40.0, 1.0));
        $this->assertSame(0.0, RelDynGovernors::gainFactor($d, 55.0, 1.0), 'above the ceiling: no gain, and no cut');
        $this->assertSame(1.0, RelDynGovernors::gainFactor($d, 55.0, 1.0, 'hoover'), "the toxic NPC's own snap is not bounded");

        // Through the one passion writer: passion stops at the ceiling; above it, it stays
        RelationshipDynamics::setPassion($d, 38.0);
        RelationshipDynamics::gainPassion('Muiri', $d, 5.0, 'combat');
        $this->assertEqualsWithDelta(40.0, RelationshipDynamics::getPassion($d), 1e-9);
        RelationshipDynamics::setPassion($d, 50.0);
        $this->assertSame(0.0, RelationshipDynamics::gainPassion('Muiri', $d, 5.0, 'combat'));
        $this->assertEqualsWithDelta(50.0, RelationshipDynamics::getPassion($d), 1e-9, 'passion above a fallen tier is not cut');

        // Won over is reached where a gain reaches 40, though the Friendly ceiling holds it there
        // and it fades before the Matrix looks again (decisions §15, RelDynAttraction::notePassionReached)
        $bard = self::npc('Bold', 0.15, 0.15, 40.0);
        $bard['_attraction'] = ['enabled' => true, 'attracted' => false, 'gate' => 'visceral', 'spark' => 20.0,
            'passion_mult' => 1.0, 'spark_mult' => 1.0, 'blocked_types' => []];
        $bard['_attraction_state'] = ['won_over' => false];
        RelationshipDynamics::setPassion($bard, 39.0);
        $this->assertFalse($bard['_attraction_state']['won_over']);
        RelationshipDynamics::gainPassion('Lynly Star-Sung', $bard, 3.0, 'reunion');
        $this->assertEqualsWithDelta(40.0, RelationshipDynamics::getPassion($bard), 1e-9);
        $this->assertTrue($bard['_attraction_state']['won_over'], 'reaching the limit is what wins her over');
    }

    public function testTheTierFloorJoinsTheStageFloorNeverAboveTheCeiling(): void
    {
        $partner = self::npc('Bold', 0.15, 0.15, 80.0, 'romantic');
        RelationshipDynamics::setPassion($partner, 25.0);
        $this->assertSame(20.0, RelationshipDynamics::passionStageFloor($partner), 'Committed: 20');
        RelationshipDynamics::setPassion($partner, 5.0);
        $this->assertSame(0.0, RelationshipDynamics::passionStageFloor($partner),
            'the floor holds what was reached, it lifts nothing (MDD 8.3: committed and loveless is a real bond)');
        $crush = self::npc('Bold', 0.15, 0.15, 60.0, 'crush');
        RelationshipDynamics::setPassion($crush, 12.0);
        $this->assertSame(10.0, RelationshipDynamics::passionStageFloor($crush));
        $parasite = $partner + ['_relationship_type_override' => 'parasite'];
        RelationshipDynamics::setPassion($parasite, 25.0);
        $this->assertSame('distant', RelDynGovernors::governor($parasite)['tier'], 'a parasite is no partner (MDD 6.2)');
        $this->assertSame(0.0, RelationshipDynamics::passionStageFloor($parasite));
        $ex = self::npc('Bold', 0.15, 0.15, 40.0, 'ex') + ['stage' => RelationshipDynamics::STAGE_DEEP];
        $this->assertSame(0.0, RelationshipDynamics::passionStageFloor($ex), 'Divorced: no floor, whatever the stage');
        $friend = self::npc('Bold', 0.15, 0.15, 40.0) + ['stage' => RelationshipDynamics::STAGE_DEEP];
        RelationshipDynamics::setPassion($friend, 30.0);
        $this->assertSame(15.0, RelationshipDynamics::passionStageFloor($friend), 'the deep stage floor 15 is the higher one');
    }
}
