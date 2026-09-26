<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql` stand-in: the stored RelDyn config row; every other read finds nothing. */
final class RelDynP4rIntegrationDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        if (str_contains((string) $sql, RelationshipDynamics::CONFIG_ROW_ID)) return ['value' => json_encode($this->config)];
        return [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Where the p4r lanes meet (reldyn-v0.18 integration), pure paths on real engine code:
 *   drunk-state x post-intimacy  drink still in her is intoxication for an encounter (not only an
 *                                active consumable), and the sober verdict waits while it is
 *   one night, one shame         the draft's worked example has ONE sober verdict on a drunken
 *                                night: the post-intimacy correction and the sober diary's ledger
 *                                share the night's resentment_self; whichever judges second adds
 *                                only what exceeds the first (either order); each keeps the rest of
 *                                its verdict (the scene's comfort crash, the flirting's gains)
 *   save-load                    a verdict the loaded game never lived is not counted
 *   memory x mf-coordinates      the memory note's envelope clause reads the coordinates as she
 *                                carries herself now (derived, Fix 6), like the felt line and Jev
 */
final class RelDynP4rIntegrationTest extends TestCase
{
    private const NPC = 'Aela the Huntress';
    private const HOUR = RelationshipDynamics::GAMETS_PER_HOUR;
    private const T0 = 310 * RelationshipDynamics::GAMETS_PER_DAY + 20 * self::HOUR;

    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['db'] = new RelDynP4rIntegrationDb(RelationshipDynamics::defaultConfig());
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_p4r_integration_test.log');
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdp4rint');
        $this->prevLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    /** The game clock now (currentGamets reads core's request). */
    private function clock(float $t): float
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) round($t), 'Kaida: hm'];
        return (float) (int) round($t);
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x']);
    }

    /** A Bold huntress at maturity 65, a friend of the player's she is not drawn to soberly (her curve 0.25). */
    private function npc(): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Bold',
            '_attraction' => ['enabled' => true, 'attracted' => false, 'won_over' => false, 'passion' => ['curve' => 0.25]],
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, 'friend');
        $d['dimensions']['maturity']['x'] = 65.0;
        $d['dimensions']['maturity']['baseline'] = 65.0;
        $d['dimensions']['affinity']['x'] = 60.0;
        $d['_aff_mirror_x'] = 60.0;
        RelationshipDynamics::setPassion($d, 30.0);   // something for the drunk flirting's passion to be taken back from
        return $d;
    }

    private function scene(array &$d, float $t): ?string
    {
        $t = $this->clock($t);
        $req = ['ext_nsfw_sexcene', '1727000000', (string) (int) $t, 'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/' . self::NPC . '^sub,vaginal'];
        return RelDynPostIntimacy::onIntimateRequest(self::NPC, $d, $req, 'Kaida', $t);
    }

    /**
     * The drunken night: five ales from T0 (her own consume lines), flirting at the fourth mug's
     * level goes to the night's ledger, a scene an hour in. Returns the encounter's start.
     */
    private function night(array &$d): float
    {
        for ($i = 0; $i < 5; $i++) RelDynSubstances::onConsume(self::NPC, $d, 'ale', $this->clock(self::T0 + $i));
        RelDynSubstances::update(self::NPC, $d, $this->clock(self::T0 + 0.1 * self::HOUR));
        RelDynSubstances::afterEvalItem(self::NPC, ['tags' => ['touch'], 'romantic_intent' => 2],
            ['affinity' => 2.5, 'comfort' => 5.0, 'passion' => 4.0], $d, $this->clock(self::T0 + 0.2 * self::HOUR));
        $this->assertCount(1, $d['_substances']['nights'] ?? [], 'the drunk self\'s night is in the ledger');
        $start = self::T0 + self::HOUR;
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, $start), 'drunk, a man she is not with');
        return $this->clock($start);
    }

    /** The resentment_self points her sober self asks for the scene (the casual drunk row by her own maturity). */
    private static function sceneAsk(array $d): float
    {
        $row = RelDynPostIntimacy::config()['outcomes'][RelDynPostIntimacy::DRUNK]['correction_casual']['resentment_self'];
        return RelDynPostIntimacy::resentmentSelf(floatval($row), RelDynDiary::ownMaturity($d));
    }

    // ------------------------------------------------------------------ drunk-state x post-intimacy

    /**
     * Her own drink (RelDynSubstances) makes her intoxicated for an encounter although no
     * consumable is active, and the sober self's verdict waits while drink is still in her.
     */
    public function testDrinkStillInHerIsIntoxicationForTheEncounter(): void
    {
        $d = $this->npc();
        RelDynSubstances::onConsume(self::NPC, $d, 'ale', $this->clock(self::T0));
        $this->assertSame([], (array) ($d['_active_consumables'] ?? []), 'no consumable window (processConsumable is not in this path)');
        $this->assertTrue(RelDynPostIntimacy::intoxicated($d), 'the drink in her is enough');
        $this->assertTrue(RelDynPostIntimacy::context($d)['intoxicated']);
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, self::T0 + 0.2 * self::HOUR));

        // Another round just before the verdict is due: she is not sober, the verdict waits
        $due = floatval($d[RelDynPostIntimacy::KEY]['correction_due']);
        RelDynSubstances::onConsume(self::NPC, $d, 'ale', $this->clock($due - 0.2 * self::HOUR));
        RelDynSubstances::update(self::NPC, $d, $this->clock($due + 0.1 * self::HOUR));
        $this->assertTrue(RelDynSubstances::intoxicated($d));
        $out = RelDynPostIntimacy::tick(self::NPC, $d, $this->clock($due + 0.1 * self::HOUR));
        $this->assertSame([], $out['corrected'], 'drink still in her: not yet the sober self');
        // Sober again: it lands
        RelDynSubstances::update(self::NPC, $d, $this->clock($due + 2 * self::HOUR));
        $this->assertFalse(RelDynSubstances::intoxicated($d));
        $out = RelDynPostIntimacy::tick(self::NPC, $d, $this->clock($due + 2 * self::HOUR));
        $this->assertArrayHasKey('resentment_self', $out['corrected'], 'the sober self');
    }

    // ------------------------------------------------------------------ one night, one shame

    /** The sober diary judges the night first; the post-intimacy verdict then adds only the excess. */
    public function testTheDiaryFirstThenTheMorningAddsOnlyTheExcess(): void
    {
        $d = $this->npc();
        $start = $this->night($d);
        // Sober (five ales clear in about six game hours), the diary page is read before the scene's verdict is due
        $t = $this->clock(self::T0 + 6.2 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $this->assertFalse(RelDynSubstances::intoxicated($d));
        $this->assertLessThan(floatval($d[RelDynPostIntimacy::KEY]['correction_due']), $t, 'the scene\'s verdict is not due yet');
        $rs0 = self::x($d, 'resentment_self');
        $r = array_values(RelDynSubstances::soberReflection(self::NPC, $d, 'examination'))[0];
        $S = floatval($r['asked']['resentment_self']);
        $this->assertGreaterThan(0.0, $S);
        $this->assertGreaterThan(0.0, floatval($r['applied']['resentment_self']), 'the first verdict is whole');
        $this->assertEqualsWithDelta($rs0 + floatval($r['applied']['resentment_self']), self::x($d, 'resentment_self'), 1e-6);
        $this->assertSame([$S], $this->verdictsOf($d));

        // The scene's verdict: its comfort crash whole, its shame only past what the diary gave
        $P = self::sceneAsk($d);
        $this->assertGreaterThan($S, $P, 'the scene asks more than the diary\'s share (Aela, 65)');
        $t = $this->clock($start + 6.1 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $before = $d;
        $comfort = self::x($d, 'comfort') - RelationshipDynamics::heldTemporaryOffset($d, 'comfort');
        $out = RelDynPostIntimacy::tick(self::NPC, $d, $t);
        $this->assertArrayHasKey('comfort', $out['corrected']);
        $this->assertLessThan($comfort, self::x($d, 'comfort') - RelationshipDynamics::heldTemporaryOffset($d, 'comfort'), 'the crash is whole');
        $expected = $before;
        $excess = RelationshipDynamics::applyDelta('resentment_self', $expected, $P - $S, 'Bold');
        $whole = $before;
        $full = RelationshipDynamics::applyDelta('resentment_self', $whole, $P, 'Bold');
        $this->assertEqualsWithDelta($excess, floatval($out['corrected']['resentment_self']), 0.05, 'only the excess');
        $this->assertLessThan($full - 1.0, floatval($out['corrected']['resentment_self']), 'not a second whole shame');
        $this->assertSame([$S, round($P, 4)], $this->verdictsOf($d));
    }

    /** The scene's verdict lands first; the diary then takes back the flirting's gains but adds no second shame. */
    public function testTheMorningFirstThenTheDiaryAddsNoSecondShame(): void
    {
        $d = $this->npc();
        $start = $this->night($d);
        $t = $this->clock($start + 6.1 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $P = self::sceneAsk($d);
        $out = RelDynPostIntimacy::tick(self::NPC, $d, $t);
        $this->assertGreaterThan(0.0, floatval($out['corrected']['resentment_self']), 'the first verdict is whole');
        $this->assertSame([round($P, 4)], $this->verdictsOf($d));

        $this->clock($start + 8 * self::HOUR);
        $rs = self::x($d, 'resentment_self');
        $aff = RelationshipDynamics::getCoreAffinity($d);
        $r = array_values(RelDynSubstances::soberReflection(self::NPC, $d, 'examination'))[0];
        $this->assertEqualsWithDelta(0.25, $r['endorsed'], 1e-9, 'her sober curve');
        $this->assertLessThan($P, floatval($r['asked']['resentment_self']), 'the diary asks less than the scene gave');
        $this->assertArrayNotHasKey('resentment_self', $r['applied'], 'no second shame for the same night');
        $this->assertEqualsWithDelta($rs, self::x($d, 'resentment_self'), 1e-6);
        foreach (['affinity', 'comfort', 'passion'] as $sig) {
            $this->assertLessThan(0.0, $r['applied'][$sig] ?? 0.0, "the flirting's {$sig} is still taken back");
        }
        $this->assertLessThan($aff, RelationshipDynamics::getCoreAffinity($d));
        $this->assertSame([round($P, 4), floatval($r['asked']['resentment_self'])], $this->verdictsOf($d));
    }

    /** Outside a drinking night nothing is shared: the verdict is whole and unrecorded. */
    public function testNoDrinkingNightNoSharing(): void
    {
        $d = $this->npc();
        $this->assertSame(10.0, RelDynSubstances::nightShame($d, self::T0, 10.0, self::T0));
        $this->assertArrayNotHasKey('shame', (array) ($d['_substances'] ?? []));
        // A night with no ledger (nothing gained drunk) closes without a night: the scene's verdict is whole
        for ($i = 0; $i < 5; $i++) RelDynSubstances::onConsume(self::NPC, $d, 'ale', $this->clock(self::T0 + $i));
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, self::T0 + self::HOUR));
        $t = $this->clock(self::T0 + 7.1 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $P = self::sceneAsk($d);
        $whole = $d;
        $full = RelationshipDynamics::applyDelta('resentment_self', $whole, $P, 'Bold');
        $out = RelDynPostIntimacy::tick(self::NPC, $d, $t);
        $this->assertEqualsWithDelta($full, floatval($out['corrected']['resentment_self']), 0.05);
        $this->assertArrayNotHasKey('shame', (array) ($d['_substances'] ?? []));
    }

    /** A save load between the verdicts: the one the loaded game never lived is not counted. */
    public function testALoadForgetsTheVerdictItNeverLived(): void
    {
        $d = $this->npc();
        $start = $this->night($d);
        $tDiary = $this->clock(self::T0 + 6.2 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $tDiary);
        $S = floatval(array_values(RelDynSubstances::soberReflection(self::NPC, $d, 'examination'))[0]['asked']['resentment_self']);
        $tScene = $this->clock($start + 6.1 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $tScene);
        RelDynPostIntimacy::tick(self::NPC, $d, $tScene);
        $this->assertCount(2, $this->verdictsOf($d));

        // Loaded between them: the diary's verdict stays, the scene's is gone (and asks the excess again)
        $mid = RelDynSubstances::rebaseline($d['_substances'], ($tDiary + $tScene) / 2);
        $this->assertSame([$S], array_map(fn($v) => floatval($v[1]), array_values($mid['shame'])[0]['verdicts']));
        $again = $d;
        $again['_substances'] = $mid;
        $this->assertEqualsWithDelta(12.0 - $S, RelDynSubstances::nightShame($again, $start, 12.0, $tScene), 1e-9);
        // Loaded before both: no verdict was given
        $this->assertArrayNotHasKey('shame', RelDynSubstances::rebaseline($d['_substances'], $tDiary - 1));
    }

    private function verdictsOf(array $d): array
    {
        $shame = (array) ($d['_substances']['shame'] ?? []);
        $this->assertCount(1, $shame, 'one night');
        return array_map(fn($v) => floatval($v[1]), (array) array_values($shame)[0]['verdicts']);
    }

    // ------------------------------------------------------------------ memory x mf-coordinates

    /**
     * The memory note's envelope clause reads how she carries herself now (RelDynMoodAxes, Fix 6),
     * not the stored anchor: an anchor of cold correctness (+M/-F) softened by trust and ease
     * reads steady and protective, like the felt band line and Jev.
     */
    public function testTheMemoryNoteReadsTheEnvelopeAsSheCarriesHerselfNow(): void
    {
        $d = $this->npc();
        $d['dimensions']['maturity']['x'] = 80.0;
        $d['dimensions']['coord_m']['x'] = 40.0;
        $d['dimensions']['coord_m']['baseline'] = 40.0;
        $d['dimensions']['coord_f']['x'] = -10.0;
        $d['dimensions']['coord_f']['baseline'] = -10.0;
        foreach (['trust', 'comfort'] as $dim) $d['dimensions'][$dim]['x'] = 95.0;
        $m = RelDynMoodAxes::derivedCoord($d, 'coord_m');
        $f = RelDynMoodAxes::derivedCoord($d, 'coord_f');
        $this->assertGreaterThan(0.0, $f, 'trust and ease carry her across to +F (precondition)');
        $derived = RelationshipDynamics::getMFQuadrantBand($m, $f)['quadrant'];
        $stored = RelationshipDynamics::getMFQuadrantBand(40.0, -10.0)['quadrant'];
        $this->assertNotSame($stored, $derived);

        $cfg = RelDynMemory::config();
        $cfg['translation']['max_clauses'] = 20;
        $cfg['translation']['token_budget'] = 1000;
        $clauses = RelDynMemory::clauses($d, self::NPC, 'Kaida', [], $cfg);
        $this->assertSame($cfg['text']['clauses']['mf'][$derived], $clauses['mf'] ?? null, json_encode($clauses));
        // Jev and the note agree
        $jev = RelDynMoodAxes::jev($d);
        $this->assertEqualsWithDelta($f, floatval($jev['f']), 0.01);
    }
}
