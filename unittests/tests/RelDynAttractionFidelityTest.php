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
 * attraction-modifier-and-gate / attraction-matrix review findings (design fidelity): rulings §11
 * ("required pillars multiply in; a zero on a required pillar zeroes the result"), MDD 8.3 (the
 * Matrix decides per NPC which pillars gate which axis), MDD 1.4 (openness: low = hard block,
 * medium / high passion ceiling cut 50% / 20%), MDD 2.6 / 6.2 (a failed visceral bar is no
 * romantic traction; friendzone / unattracted passion capped at 20), plan §7 (balanced: visceral
 * pass OR bonded tier), plan §4 respect rate. NPCs are CHIM 3.4.1 core_npc_master rows run through
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

    /** MDD 8.2 C / 8.3: Ysolda's passion answers to status; with none, the gate is shut. */
    public function testAMerchantsRequiredStatusGatesHerPassion(): void
    {
        $d = $this->npc('Ysolda');
        $def = RelDynAttraction::definition('Ysolda', $d);
        $this->assertSame('Merchant', $def['archetype']);
        $this->assertSame('rigid', $def['rigidity']['status']);
        $this->assertContains('status', $def['passion_pillars'], 'MDD 8.2 C: status is her passion pillar');

        $nobody = RelationshipDynamics::attractionFor('Ysolda', $d, self::player(['strength' => 0.8, 'status' => 0.0, 'competence' => 0.6]));
        $this->assertSame(0.0, floatval($nobody['passion']['gates']['status']), json_encode($nobody['passion']));
        $this->assertSame(0.0, $nobody['passion_mult'], '100 x 0 is still 0');
        $this->assertFalse($nobody['passes']);
        $this->assertSame(20.0, floatval($nobody['passion_cap']));

        $wealthy = RelationshipDynamics::attractionFor('Ysolda', $d, self::player(['strength' => 0.1, 'status' => 0.8, 'competence' => 0.6]));
        $this->assertSame(1.0, floatval($wealthy['passion']['gates']['status']));
        $this->assertGreaterThan(0.0, $wealthy['passion_mult'], 'strength is irrelevant to her');
        $this->assertTrue($wealthy['passes']);
    }

    /**
     * MDD 2.6 / 1.4: a player who fails the visceral bar gets no romantic traction: not
     * 'drawn', passion capped, no intimacy hint, at any score between zero and the bar; low
     * openness is a hard block even for a near miss.
     */
    public function testFailingTheVisceralBarIsNeverDrawnOrUncapped(): void
    {
        foreach (['low', 'medium', 'high'] as $openness) {
            $d = $this->uthgerd($openness);
            $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => 0.9, 'status' => 0.7, 'competence' => 0.8]))['pillars']['strength']['bar'];
            $margin = RelDynAttraction::config()['openness_margin'][$openness];
            // every failing score below the tolerated band: 0.01, then up to just under it
            foreach ([0.01, 0.12, 0.18, $bar * (1.0 - $margin) - 0.005] as $s) {
                if ($s <= 0.0 || $s >= $bar * (1.0 - $margin)) continue;
                $a = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => $s, 'status' => 0.7, 'competence' => 0.8]));
                $why = "{$openness} strength {$s} (bar {$bar}): " . $a['reason'];
                $this->assertFalse($a['pillars']['strength']['pass'], $why);
                $this->assertFalse($a['passes'], $why);
                $this->assertNotSame('drawn', $a['outcome'], $why);
                $this->assertSame(20.0, floatval($a['passion_cap']), $why);
                $this->assertFalse($a['intimacy_allowed'], $why);
                $this->assertSame(0.0, $a['passion_mult'], $why);
                $this->assertTrue($a['friendzoned'], "{$why}: status and competence met: the tolerated state");
            }
        }
        // low openness: a near miss is a hard block (MDD 1.4)
        $low = $this->uthgerd('low');
        $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $low, self::player(['strength' => 0.9, 'status' => 0.7, 'competence' => 0.8]))['pillars']['strength']['bar'];
        $near = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $low, self::player(['strength' => $bar - 0.02, 'status' => 0.7, 'competence' => 0.8]));
        $this->assertFalse($near['passes'], $near['reason']);
        $this->assertSame(20.0, floatval($near['passion_cap']));
        $this->assertFalse($near['intimacy_allowed']);
    }

    /** MDD 1.4: a tolerated near miss cuts the passion ceiling by 50% (medium) / 20% (high). */
    public function testANearMissCutsThePassionCeilingByTheMddValues(): void
    {
        $max = floatval(RelationshipDynamics::getConfig()['passion_max'] ?? 100.0);
        foreach (['medium' => 0.5, 'high' => 0.2] as $openness => $cut) {
            $d = $this->uthgerd($openness);
            $bar = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => 0.9, 'status' => 0.7, 'competence' => 0.8]))['pillars']['strength']['bar'];
            $a = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => $bar - 0.03, 'status' => 0.7, 'competence' => 0.8]));
            $this->assertTrue($a['pillars']['strength']['tolerated'], $a['reason']);
            $this->assertTrue($a['passes'], $a['reason']);
            $this->assertEqualsWithDelta($max * (1.0 - $cut), floatval($a['passion_cap']), 1e-6, "{$openness}: MDD 1.4 ceiling cut");
            $this->assertSame(RelDynAttraction::config()['openness_passion_ceiling_cut'][$openness], $cut);
            $met = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, self::player(['strength' => $bar + 0.05, 'status' => 0.7, 'competence' => 0.8]));
            $this->assertNull($met['passion_cap'], "{$openness}: the bar met, no cut");
        }
    }

    /** Plan §7: a balanced NPC's passion opens on its pillars OR the bonded tier, whichever comes first. */
    public function testABalancedNpcsBondOpensItsPassion(): void
    {
        $player = self::player(['strength' => 0.02, 'status' => 0.7, 'competence' => 0.8]);
        $d = $this->uthgerd('medium');
        $d['attraction_overrides']['gate'] = 'balanced';
        $stranger = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $d, $player);
        $this->assertFalse($stranger['passes'], $stranger['reason']);
        $bonded = $this->npc('Uthgerd the Unbroken', ['profile_overrides' => ['attachment_style' => 'secure']], 80.0);
        $bonded['attraction_overrides'] = $d['attraction_overrides'];
        $bonded['_attraction_state'] = ['depth' => 'devoted', 'romance' => 0, 'pending' => null, 'peak_core_aff' => 80.0,
            'grandfathered' => ['depth' => 'bonded', 'romance' => 0]];
        $b = RelationshipDynamics::attractionFor('Uthgerd the Unbroken', $bonded, $player);
        $this->assertTrue($b['passes'], $b['reason']);
        $this->assertGreaterThan(0.0, $b['passion_mult']);
    }

    // ------------------------------------------------------------------ the modifier, no invented values

    public function testTheModifierNeverSpeedsPassionPastItsRawRate(): void
    {
        $pc = RelDynAttraction::config()['passion'];
        $this->assertSame(1.0, floatval($pc['modifier_ceiling']), 'the most the attraction multiplier ever was');
        $this->assertSame(0.1, floatval($pc['modifier_floor']), 'plan §7 unattracted multiplier');
        foreach (['gate_absent_below', 'gate_open_factor', 'gate_min_span'] as $gone) {
            $this->assertArrayNotHasKey($gone, $pc, "{$gone}: no source");
        }
        $this->assertEqualsWithDelta(1.0, RelDynAttraction::attractionModifier(1.0), 1e-9);
        $this->assertLessThan(RelDynAttraction::attractionModifier(0.6), RelDynAttraction::attractionModifier(0.5), 'strictly increasing');
        $d = $this->npc('Aela the Huntress');
        $legend = RelationshipDynamics::attractionFor('Aela the Huntress', $d,
            self::player(['strength' => 1.0, 'status' => 1.0, 'competence' => 1.0], ['warrior' => 1.0, 'hunter' => 1.0],
                ['stat:the companions quests completed' => 20]));
        $this->assertLessThanOrEqual(1.0 + 1e-9, $legend['passion']['modifier']);
        $this->assertLessThanOrEqual($legend['passion']['attachment'] + 1e-9, $legend['passion_mult']);
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
