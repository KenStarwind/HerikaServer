<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Answers the reads the attraction path makes outside the code under test: the NPC's
 * core_npc_master row (profile auto-generation, facet preferences, gender) and the stored RelDyn
 * config row. It hands back stored rows only.
 */
final class RelDynAttractionFidelityRowDb
{
    /** @var array<string, array> lower(npc_name) => core_npc_master row as PostgreSQL returns it */
    public array $rows = [];
    public ?array $config = null;

    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }

    public function fetchOne($sql, $params = null)
    {
        $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
        if (str_contains($sql, "FROM conf_opts WHERE id = 'relationship_dynamics_config'")) {
            return $this->config === null ? [] : ['value' => json_encode($this->config)];
        }
        if (str_contains($sql, 'FROM core_npc_master') && is_array($params)) {
            return $this->rows[strtolower((string) $params[0])] ?? [];
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return false; }
}

/**
 * attraction-modifier-and-gate / attraction-matrix / attraction-uphill-curve review findings
 * (design fidelity): rulings §11 as decisions §13 keeps it ("a zero on a required pillar zeroes
 * the result" for a rigid pillar at 0), MDD 8.3 (the Matrix decides per NPC which pillars gate
 * which axis), MDD 1.4 (openness: low = hard block on the type axis; on passion the height of
 * the hill), MDD 2.6 (a failed visceral bar is no romantic traction), decisions §13 (the
 * friendzone cap of 20 is retired for the uphill; MDD 1.4 ceiling cut stays), plan §7
 * (balanced: visceral pass OR bonded tier), plan §4 respect rate. NPCs are CHIM 3.4.1 core_npc_master rows run through
 * the real profile auto-generation; players follow the RelDynPlayer::profile() contract.
 */
final class RelDynAttractionFidelityTest extends TestCase
{
    private $savedDb = null;
    private bool $hadDb = false;
    private RelDynAttractionFidelityRowDb $db;
    private $prevErrorLog = null;
    private string $errorLog;

    protected function setUp(): void
    {
        $this->hadDb = array_key_exists('db', $GLOBALS);
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->db = new RelDynAttractionFidelityRowDb();
        $this->db->rows['ysolda'] = self::row('Food Vendor', [], ['speech' => 40]) + ['npc_name' => 'Ysolda'];
        $this->db->rows['uthgerd the unbroken'] = self::row('Warrior', [], ['twohanded' => 60, 'heavyarmor' => 50, 'block' => 40]) + ['npc_name' => 'Uthgerd the Unbroken'];
        $this->db->rows['aela the huntress'] = self::row('Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]) + ['npc_name' => 'Aela the Huntress'];
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdattrfid');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        if ($this->hadDb) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    /** A core_npc_master row as CHIM 3.4.1 registers an NPC (processor/comm.php addnpc). */
    private static function row(string $class, array $factions, array $skills): array
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $name) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $name];
        return [
            'gender' => 'female', 'race' => 'NordRace', 'voiceid' => 'FemaleEvenToned', 'personality' => '',
            'speechstyle' => '', 'core' => '', 'npc_static_bio' => '',
            'metadata' => json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
            'extended_data' => json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f]),
        ];
    }

    /** A player in the RelDynPlayer::profile() contract; $pillars are the generic pillar scores (0..1). */
    private static function player(array $pillars, array $archetypes = ['warrior' => 0.8], array $evidence = []): array
    {
        return [
            'archetypes' => array_replace(array_fill_keys(RelDynAttraction::PLAYER_ARCHETYPES, 0.0), $archetypes),
            'archetype_raw' => array_replace(array_fill_keys(RelDynAttraction::PLAYER_ARCHETYPES, 0.0), $archetypes),
            'evidence' => $evidence,
            'pillars' => $pillars + ['beauty' => null],
            'facts' => [],
            'known' => true,
        ];
    }

    private function npc(string $name, array $extra = [], ?float $coreAff = null): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        RelationshipDynamics::ensureLoveLanguage($name, $d);
        RelDynFacets::ensurePreferences($name, $d);
        if ($coreAff !== null) {
            $d['_aff_mirror_x'] = ($coreAff + 100.0) / 2.0;
            $d['dimensions']['affinity']['x'] = ($coreAff + 100.0) / 2.0;
        }
        return array_replace($d, $extra);
    }

    /** Uthgerd reduced to one required pillar (rigid strength, read without a lens) at a chosen openness. */
    private function uthgerd(string $openness): array
    {
        $d = $this->npc('Uthgerd the Unbroken', ['profile_overrides' => ['attachment_style' => 'secure']]);
        $d['attraction_overrides'] = [
            'rigidity' => ['beauty' => 'soft', 'strength' => 'rigid', 'status' => 'soft', 'competence' => 'soft'],
            'lens_share' => ['strength' => 0.0], 'openness' => $openness,
        ];
        return $d;
    }

    // ------------------------------------------------------------------ the gate is the NPC's

    /** MDD 8.2 C / 8.3: Ysolda's passion answers to status (rigid); with none at all, 100 x 0 = 0. */
    public function testAMerchantsRequiredStatusGatesHerPassion(): void
    {
        $d = $this->npc('Ysolda');
        $def = RelDynAttraction::definition('Ysolda', $d);
        $this->assertSame('Merchant', $def['archetype']);
        $this->assertSame('rigid', $def['rigidity']['status']);
        $this->assertContains('status', $def['passion_pillars'], 'MDD 8.2 C: status is her passion pillar');

        $nobody = RelationshipDynamics::attractionFor('Ysolda', $d, self::player(['strength' => 0.8, 'status' => 0.0, 'competence' => 0.6]));
        $this->assertSame(0.0, $nobody['passion']['units']['status']['m'], json_encode($nobody['passion']));
        $this->assertSame('rigid:status', $nobody['hard_zero']);
        $this->assertSame(0.0, $nobody['passion_mult'], '100 x 0 is still 0');
        $this->assertSame(0.0, $nobody['spark_mult'], 'a non-negotiable: no spark either');
        $this->assertFalse($nobody['passes']);
        $this->assertArrayNotHasKey('passion_cap', $nobody);

        $wealthy = RelationshipDynamics::attractionFor('Ysolda', $d, self::player(['strength' => 0.1, 'status' => 0.8, 'competence' => 0.6]));
        $this->assertGreaterThanOrEqual(1.0, $wealthy['passion']['units']['status']['m'], 'past her floor');
        $this->assertArrayNotHasKey('strength', $wealthy['passion']['units'], 'strength is irrelevant to her');
        $this->assertGreaterThan(0.0, $wealthy['passion_mult']);
        $this->assertTrue($wealthy['passes']);
    }

    /**
     * MDD 2.6 / 1.4: a player who fails the visceral bar gets no romantic traction: not
     * 'drawn', no intimacy hint, at any score between zero and the bar; low openness is a hard
     * block on the type axis even for a near miss. On passion (decisions §13, Ken: rigid,
     * non-negotiable pillars stay a hard zero): a RIGID pillar below its bar is exactly 0,
     * spark included; a FLEXIBLE one is the foot of a steep hill, not a wall.
     */
    public function testFailingTheVisceralBarIsNeverDrawn(): void
    {
        foreach (['rigid', 'flexible'] as $rig) foreach (['low', 'medium', 'high'] as $openness) {
            $d = $this->uthgerd($openness);
            $d['attraction_overrides']['rigidity']['strength'] = $rig;
            $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => 0.9, 'status' => 0.7, 'competence' => 0.8]))['pillars']['strength']['bar'];
            $margin = RelDynAttraction::config()['openness_margin'][$openness];
            // every failing score below the tolerated band: 0.01, then up to just under it
            foreach ([0.01, 0.12, 0.18, $bar * (1.0 - $margin) - 0.005] as $s) {
                if ($s <= 0.0 || $s >= $bar * (1.0 - $margin)) continue;
                $a = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => $s, 'status' => 0.7, 'competence' => 0.8]));
                $why = "{$rig} {$openness} strength {$s} (bar {$bar}): " . $a['reason'];
                $this->assertFalse($a['pillars']['strength']['pass'], $why);
                $this->assertFalse($a['passes'], $why);
                $this->assertNotSame('drawn', $a['outcome'], $why);
                $this->assertArrayNotHasKey('passion_cap', $a, $why);
                $this->assertFalse($a['intimacy_allowed'], $why);
                if ($rig === 'rigid') {
                    $this->assertSame('rigid:strength', $a['hard_zero'], "{$why}: must pass, non-negotiable");
                    $this->assertSame(0.0, $a['passion_mult'], $why);
                    $this->assertSame(0.0, $a['spark_mult'], $why);
                } else {
                    $this->assertNull($a['hard_zero'], $why);
                    $this->assertGreaterThan(0.0, $a['passion_mult'], "{$why}: not a wall");
                    $this->assertLessThan(0.2, $a['passion']['curve'], $why);
                }
                $this->assertTrue($a['friendzoned'], "{$why}: status and competence met: the tolerated state");
            }
        }
        // low openness: a near miss is a hard block (MDD 1.4)
        $low = $this->uthgerd('low');
        $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $low, self::player(['strength' => 0.9, 'status' => 0.7, 'competence' => 0.8]))['pillars']['strength']['bar'];
        $near = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $low, self::player(['strength' => $bar - 0.02, 'status' => 0.7, 'competence' => 0.8]));
        $this->assertFalse($near['passes'], $near['reason']);
        $this->assertArrayNotHasKey('passion_cap', $near);
        $this->assertFalse($near['intimacy_allowed']);
    }

    /**
     * MDD 1.4 stays whole: on the type axis a tolerated near miss takes twice the effort to
     * advance, and on passion its "Failed Pillar Effect" ceiling cut holds (medium 50%, high
     * 20%). Decisions §13 retired only MDD 6.2's hard cap of 20. The bar met lifts the ceiling.
     */
    public function testANearMissKeepsTheMdd14PassionCeiling(): void
    {
        $this->assertSame(['low' => 0.0, 'medium' => 0.5, 'high' => 0.2], RelDynAttraction::defaults()['openness_passion_ceiling_cut']);
        foreach (['medium' => 50.0, 'high' => 80.0] as $openness => $ceiling) {
            $d = $this->uthgerd($openness);
            $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => 0.9, 'status' => 0.7, 'competence' => 0.8]))['pillars']['strength']['bar'];
            $a = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => $bar - 0.03, 'status' => 0.7, 'competence' => 0.8]));
            $this->assertTrue($a['pillars']['strength']['tolerated'], $a['reason']);
            $this->assertArrayNotHasKey('passion_cap', $a, "{$openness}: not the retired cap of 20");
            $this->assertSame($ceiling, $a['passion_ceiling'], "{$openness}: {$a['reason']}");
            $this->assertGreaterThan(0.0, $a['passion_mult']);
            $met = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => $bar + 0.05, 'status' => 0.7, 'competence' => 0.8]));
            $this->assertNull($met['passion_ceiling'], "{$openness}: the bar met, no ceiling");
            $this->assertGreaterThanOrEqual($a['passion']['curve'], $met['passion']['curve'], "{$openness}: the bar met is no lower");
        }
    }

    /** Plan §7: a balanced NPC's passion opens on its pillars OR the bonded tier, whichever comes first. */
    public function testABalancedNpcsBondOpensItsPassion(): void
    {
        $player = self::player(['strength' => 0.02, 'status' => 0.7, 'competence' => 0.8]);
        $d = $this->uthgerd('medium');
        $d['attraction_overrides']['gate'] = 'balanced';
        // flexible: the bond is the balanced NPC's visceral substitute; a RIGID pillar below its
        // bar stays a hard zero whatever the bond (non-negotiable)
        $d['attraction_overrides']['rigidity']['strength'] = 'flexible';
        $stranger = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, $player);
        $this->assertFalse($stranger['passes'], $stranger['reason']);
        $bonded = $this->npc('Uthgerd the Unbroken', ['profile_overrides' => ['attachment_style' => 'secure']], 80.0);
        $bonded['attraction_overrides'] = $d['attraction_overrides'];
        $bonded['_attraction_state'] = ['depth' => 'devoted', 'romance' => 0, 'pending' => null, 'peak_core_aff' => 80.0,
            'grandfathered' => ['depth' => 'bonded', 'romance' => 0]];
        $b = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $bonded, $player);
        $this->assertTrue($b['passes'], $b['reason']);
        $this->assertGreaterThan(0.0, $b['passion_mult']);
        $bonded['attraction_overrides']['rigidity']['strength'] = 'rigid';
        $r = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $bonded, $player);
        $this->assertSame('rigid:strength', $r['hard_zero'], $r['reason']);
        $this->assertFalse($r['passes']);
    }

    // ------------------------------------------------------------------ the curve, no invented values

    /**
     * Decisions §13 values (Ken: "about 0.1" far below, 1.0 at the floor, "+1% per point",
     * Aela "high 60s"), and the surplus cap: attraction x the fastest attachment (anxious 1.3)
     * stays under the MDD 1.1 redline 2.0x.
     */
    public function testTheCurveKeepsKensValuesAndACappedSurplus(): void
    {
        $cc = RelDynAttraction::curveConfig();
        $this->assertSame(20.0, floatval($cc['spark']));
        $this->assertSame(0.1, floatval($cc['m_min']));
        $this->assertSame(0.01, floatval($cc['surplus_per_point']));
        $this->assertSame(45.0, floatval($cc['floor']), "Ken's generic floor ('at 45 you get 1x')");
        $this->assertSame(68.0, floatval(RelDynAttraction::defaults()['npc_overrides']['aela the huntress']['floors']['strength']), 'Aela: high 60s in martial');
        $this->assertArrayNotHasKey('floor_by_openness', $cc, 'no source for 75 / 55');
        $this->assertArrayNotHasKey('friendzone_below', $cc, 'the label is the MDD bar');
        $pc = RelDynAttraction::config()['passion'];
        foreach (['modifier_floor', 'modifier_ceiling', 'modifier_curve', 'friendzone_cap', 'unattracted_cap',
                     'gate_absent_below', 'gate_open_factor', 'gate_min_span'] as $gone) {
            $this->assertArrayNotHasKey($gone, $pc, "{$gone}: retired or no source");
        }
        // below the smallest MDD 1.2 chemistry step (secondary love language x1.5)
        $secondary = null;
        foreach (RelationshipDynamics::affinityModifierDefaults() as $row) {
            if ($row['id'] === 'love_language_secondary') $secondary = floatval($row['mult']);
        }
        $this->assertSame(1.5, $secondary);
        $this->assertLessThan($secondary, floatval($cc['surplus_max']), 'the surplus is a smaller lever than chemistry');
        $d = $this->npc('Aela the Huntress');
        $legend = RelationshipDynamics::attractionFor('Aela the Huntress', $d,
            self::player(['strength' => 1.0, 'status' => 1.0, 'competence' => 1.0], ['warrior' => 1.0, 'hunter' => 1.0],
                ['stat:the companions quests completed' => 20]));
        $this->assertLessThanOrEqual(floatval($cc['surplus_max']) + 1e-9, $legend['passion']['curve']);
        $this->assertGreaterThan(1.0, $legend['passion']['curve'], 'a legend is past her floor');
        $this->assertLessThanOrEqual(floatval($cc['surplus_max']) * $legend['passion']['attachment'] + 1e-6, $legend['passion_mult']);
    }

    // ------------------------------------------------------------------ respect (plan §4)

    /** Plan §4 respect rate against the neutral pillar score, within MDD 1.2's 0.5x..2.0x. */
    public function testRespectGainsAreNotCutForAnAveragePlayer(): void
    {
        $d = $this->npc('Uthgerd the Unbroken');
        $d['attraction_overrides'] = ['lens_share' => ['strength' => 0.0, 'competence' => 0.0]];
        $at = fn(float $c, float $s) => RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d,
            self::player(['strength' => 0.5, 'status' => $s, 'competence' => $c]));
        $avg = $at(0.5, 0.5);
        $this->assertEqualsWithDelta(0.5, $avg['respect_rate'], 1e-3, 'plan §4 rate');
        $this->assertEqualsWithDelta(1.0, $avg['respect_mult'], 1e-3, 'an average player earns respect at the raw rate');
        $this->assertEqualsWithDelta(0.5, $at(0.05, 0.0)['respect_mult'], 1e-3, 'a nobody: half, never nothing');
        $this->assertEqualsWithDelta(1.8, $at(0.9, 0.9)['respect_mult'], 1e-3, 'a legend: more');
        $this->assertGreaterThan($at(0.3, 0.3)['respect_mult'], $at(0.6, 0.6)['respect_mult']);
    }

    // ------------------------------------------------------------------ felt text

    public function testTheFeltReadFollowsTierBondAndNamesThePlayer(): void
    {
        $d = $this->npc('Aela the Huntress');
        RelationshipDynamics::updateAttraction('Aela the Huntress', $d,
            self::player(['strength' => 0.9, 'status' => 0.8, 'competence' => 0.9], ['warrior' => 0.9], ['stat:the companions quests completed' => 4]));
        $sum = $d['_attraction'];
        $this->assertSame('drawn', $sum['outcome']);
        $ctx = ['player' => 'Kaida', 'passion' => 0.0];
        $acq = RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 1]);
        $this->assertStringContainsString('Kaida', $acq);
        $this->assertStringNotContainsString('the player', $acq);
        $this->assertDoesNotMatchRegularExpression('/flirtation gets|flirts back/', $acq, 'MDD 8.1: an acquaintance');
        $friend = RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 2]);
        $this->assertMatchesRegularExpression('/flirtation gets/', $friend);
        $this->assertMatchesRegularExpression('/flirtation gets/', RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 1, 'romantic' => true]));
        $this->assertNull(RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 3, 'strained' => true]), 'a strained bond: nothing');

        // pending: never at first sight; a romance lift reads as one, a depth lift does not
        $sum['pending'] = true;
        $sum['pending_romance'] = true;
        $this->assertStringNotContainsString('could change what they are', RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 0]));
        $this->assertStringContainsString('could change what they are', RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 1]));
        $sum['pending_romance'] = false;
        $depth = RelDynAttraction::feltText('Aela the Huntress', $sum, $ctx + ['tier' => 2]);
        $this->assertStringNotContainsString('could change what they are', $depth);
        $this->assertStringContainsString('bring them closer', $depth);
    }
}
