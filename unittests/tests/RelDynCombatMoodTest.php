<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing (no eventlog rows, no core row). */
final class RelDynCombatMoodConfigDb
{
    public function __construct(public array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Roadmap combat-arousal-temperament-context (MDD 3.3 Stage 2), the parts that need no database
 * rows: the foe read from core's combat rows by name (a skeever about 10, a bandit ambush about 40,
 * a dragon or a centurion 90+), arousal lifted toward it through the NPC's own reactivity, a win
 * after high arousal turning into valence by the NPC's taste for a fight, a fall hitting by the foe,
 * the near miss (terror then relief, and the bond), and the felt aftermath by who the NPC is. The
 * four test beds run it through the real hooks in RelDynCombatMoodTestBedsPostgresTest.
 */
final class RelDynCombatMoodTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const AT = 400 * self::DAY;
    private const WINDOW = RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS;

    private array $saved = [];
    private string $errorLog = '';
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdcm');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** The shipped config, with $combat laid over the combat.arousal table (per key). */
    private function setConfig(array $combatArousal): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['passion_dynamics'] = array_merge(RelDynPassion::configDefaults(), ['derived_warmth_enabled' => false]);
        $cfg['combat']['arousal'] = array_replace_recursive($cfg['combat']['arousal'], $combatArousal);
        $cfg['log_enabled'] = true;   // RelationshipDynamics::log writes only with the debug log on
        $GLOBALS['db'] = new RelDynCombatMoodConfigDb($cfg);
        RelationshipDynamics::clearConfigCache();
    }

    /** A bond state of $temperament, resting (arousal 10, valence 0), a friend with some passion. */
    private function npc(string $temperament): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['inferred_temperament' => $temperament]));
        $d['dimensions']['arousal']['x'] = 10.0;
        $d['dimensions']['valence']['x'] = 0.0;
        RelationshipDynamics::setPassion($d, 30.0);
        return $d;
    }

    private function mood(string $npc, array &$d, string $victim, bool $fought = true, float $combatPref = 0.0, string $type = 'death', float $at = self::AT): array
    {
        return RelDynCombat::fightMood($npc, $d, ['type' => $type, 'at' => $at, 'victim' => $victim, 'player' => 'Kaida', 'fought' => $fought,
            'victory' => true, 'prefs' => ['combat' => $combatPref]]);
    }

    private function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x']);
    }

    // ------------------------------------------------------------------ the foe, read from core's rows

    public function testTheFoeIsReadByNameFromTheCombatRows(): void
    {
        $this->assertSame(10.0, RelDynCombat::foePoints(RelDynCombat::foeTier('death', 'Skeever')), 'a skeever is about 10');
        $this->assertSame(40.0, RelDynCombat::foePoints(RelDynCombat::foeTier('death', 'Bandit Marauder')), 'a bandit ambush is about 40');
        $this->assertSame(40.0, RelDynCombat::foePoints(RelDynCombat::foeTier('death', 'Draugr')), 'a foe no list names is the default class');
        foreach (['Dragon', 'Dwarven Centurion', 'Ancient Dragon', 'Dragon Priest', 'Alduin'] as $name) {
            $this->assertGreaterThanOrEqual(90.0, RelDynCombat::foePoints(RelDynCombat::foeTier('death', $name)), "{$name}: 90+");
        }
        $this->assertSame('animal', RelDynCombat::foeTier('death', 'Giant Frostbite Spider'), 'a giant spider is a spider');
        $this->assertSame('animal', RelDynCombat::foeTier('death', 'Cave Bear'));
        $this->assertSame('dreaded', RelDynCombat::foeTier('death', 'Frost Troll'));
        $this->assertSame('dreaded', RelDynCombat::foeTier('death', 'Werewolf'), 'a werewolf is no wolf');
        $this->assertSame('serious', RelDynCombat::foeTier('death', 'Vampire'));
        $this->assertSame('overwhelming', RelDynCombat::foeTier('death', 'Vampire Lord'), 'the lord before the vampire');
        $this->assertSame('trivial', RelDynCombat::foeTier('death', 'a Skeever'));
        $this->assertNull(RelDynCombat::matchTier('Bandit'), 'bandits are the default class, not a listed one');
        $this->assertNull(RelDynCombat::matchTier(null));
        $this->assertSame('overwhelming', RelDynCombat::foeTier('combatendmighty', null), 'the plugin\'s mighty foe');
        // A combat end names no foe: the toughest kill just before it, else the default
        $this->assertSame('dreaded', RelDynCombat::foeTier('combatend', null, ['Skeever', 'Frost Troll', 'Bandit']));
        $this->assertSame('engaged', RelDynCombat::foeTier('combatend', null, ['Bandit']));
        $this->assertSame('engaged', RelDynCombat::foeTier('combatend', null, []));
        $this->assertSame('overwhelming', RelDynCombat::foeTier('combatendmighty', null, ['Skeever']));
    }

    public function testTheFoeTablesAreConfigAndAStoredTableReplacesItsOwnKeysOnly(): void
    {
        $this->setConfig(['foe_points' => ['trivial' => 5.0], 'foe_words' => ['trivial' => ['bandit']]]);
        $cfg = RelDynCombat::arousalConfig();
        $this->assertEqualsWithDelta(5.0, $cfg['foe_points']['trivial'], 1e-9);
        $this->assertEqualsWithDelta(95.0, $cfg['foe_points']['overwhelming'], 1e-9, 'the other classes keep their points');
        $this->assertSame('trivial', RelDynCombat::foeTier('death', 'Bandit'));
        $this->assertSame('overwhelming', RelDynCombat::foeTier('death', 'Dragon'));
        $this->assertEqualsWithDelta(5.0, RelDynCombat::foePoints('trivial', $cfg), 1e-9);
        $this->assertEqualsWithDelta(40.0, RelDynCombat::foePoints('no such class', $cfg), 1e-9, 'a class the points do not list is the default class');
    }

    public function testTheShippedConfigCarriesTheFightMood(): void
    {
        $d = RelDynCombat::configDefaults();
        $this->assertSame(RelDynCombat::arousalDefaults(), $d['arousal']);
        $this->assertSame($d, RelationshipDynamics::defaultConfig()['combat']);
        $a = RelDynCombat::arousalDefaults();
        $this->assertTrue($a['enabled']);
        $this->assertSame(0.15, $a['near_miss']['hp_below'], 'MDD 3.3: survived under 15% HP');
        $this->assertSame(['trivial', 'animal', 'overwhelming', 'dreaded', 'serious'], array_keys($a['foe_words']), 'checked in this order');
    }

    // ------------------------------------------------------------------ arousal by the foe, by who the NPC is

    public function testArousalRisesTowardTheFoeAndATrivialFoeStirsNothing(): void
    {
        $d = $this->npc('Romantic');   // Y 1.0: reactivity as it comes
        $r = $this->mood('Muiri', $d, 'Skeever');
        $this->assertSame('trivial', $r['tier']);
        $this->assertEqualsWithDelta(10.0, $this->x($d, 'arousal'), 1e-6, 'a skeever fight barely registers');
        $this->assertSame(0.0, $r['arousal']);
        $this->assertSame(0.0, $r['valence'], 'and no thrill in it');

        $d = $this->npc('Romantic');
        $this->mood('Muiri', $d, 'Bandit Marauder');
        $this->assertEqualsWithDelta(40.0, $this->x($d, 'arousal'), 1.0, 'alert, engaged');

        $d = $this->npc('Romantic');
        $this->mood('Muiri', $d, 'Dragon');
        $this->assertGreaterThanOrEqual(90.0, $this->x($d, 'arousal'), 'a full adrenaline dump');
    }

    public function testTheSameDragonLiftsEachTemperamentByItsOwnReactivity(): void
    {
        $x = [];
        foreach (['Stoic', 'Guarded', 'Bold', 'Romantic', 'Anxious'] as $t) {
            $d = $this->npc($t);
            $this->mood('N', $d, 'Dragon');
            $x[$t] = $this->x($d, 'arousal');
        }
        $why = json_encode($x);
        $this->assertLessThan($x['Guarded'], $x['Stoic'], $why);
        $this->assertLessThan($x['Bold'], $x['Guarded'], $why);
        $this->assertLessThan($x['Romantic'], $x['Bold'], $why);
        $this->assertLessThan($x['Anxious'] + 1e-9, $x['Romantic'], $why);
        $this->assertLessThan(50.0, $x['Stoic'], 'a stoic stays cool: ' . $why);
        $this->assertGreaterThanOrEqual(90.0, $x['Anxious'], 'an anxious NPC floods: ' . $why);
    }

    public function testSomeoneAroundTheFightIsLessStirredThanSomeoneInIt(): void
    {
        $in = $this->npc('Romantic');
        $near = $this->npc('Romantic');
        $this->mood('Muiri', $in, 'Dragon', true);
        $this->mood('Muiri', $near, 'Dragon', false);
        $this->assertGreaterThan($this->x($near, 'arousal') + 20.0, $this->x($in, 'arousal'));
        $this->assertGreaterThan(10.0, $this->x($near, 'arousal'), 'never untouched by a dragon');
        $this->assertGreaterThan($this->x($near, 'valence'), $this->x($in, 'valence'));
    }

    // ------------------------------------------------------------------ a win after high arousal is positive valence

    public function testWinningAfterHighArousalIsPositiveValenceByTasteForAFight(): void
    {
        $lover = $this->npc('Romantic');
        $averse = $this->npc('Romantic');
        $this->mood('Aela', $lover, 'Dragon', true, 0.8);
        $this->mood('Lynly', $averse, 'Dragon', true, -0.6);
        $this->assertGreaterThan(20.0, $this->x($lover, 'valence'), 'thrill');
        $this->assertGreaterThan(0.0, $this->x($averse, 'valence'), 'even one who dislikes a fight is glad it is won');
        $this->assertGreaterThan($this->x($averse, 'valence') * 1.5, $this->x($lover, 'valence'), 'but relief is less than thrill');
        // a bandit ambush, middling arousal: a little, never the dragon's
        $b = $this->npc('Romantic');
        $this->mood('Aela', $b, 'Bandit Marauder', true, 0.8);
        $this->assertGreaterThan(0.0, $this->x($b, 'valence'));
        $this->assertLessThan($this->x($lover, 'valence') / 2.0, $this->x($b, 'valence'));
    }

    public function testTheTemperamentsOwnValenceReactivityScalesTheThrillToo(): void
    {
        $x = [];
        foreach (['Stoic', 'Romantic', 'Anxious'] as $t) {
            $d = $this->npc($t);
            $this->mood('N', $d, 'Dragon', true, 0.0);
            $x[$t] = $this->x($d, 'valence');
        }
        $this->assertGreaterThan(0.0, min($x), json_encode($x));
        $this->assertCount(3, array_unique(array_map(fn($v) => round($v, 1), $x)), 'three temperaments, three thrills: ' . json_encode($x));
    }

    public function testAFightIsOneEpisodeThatGrantsItsThrillOnce(): void
    {
        $d = $this->npc('Romantic');
        $this->mood('Aela', $d, 'Dragon', true, 0.0, 'death', self::AT);
        $first = $this->x($d, 'valence');
        $this->assertGreaterThan(20.0, $first);
        // the next kill of the same fight, a skeever, or the dragon's second head: nothing more to grant
        $second = $this->mood('Aela', $d, 'Skeever', true, 0.0, 'death', self::AT + 1000);
        $this->assertSame(0.0, $second['valence']);
        $third = $this->mood('Aela', $d, 'Dragon', true, 0.0, 'death', self::AT + 2000);
        $this->assertSame(0.0, $third['valence'], 'the same foe adds no thrill');
        $this->assertEqualsWithDelta($first, $this->x($d, 'valence'), 1e-6);
        // a worse foe in the same fight adds only the difference
        $e = $this->npc('Romantic');
        $this->mood('Aela', $e, 'Bandit Marauder', true, 0.0, 'death', self::AT);
        $bandit = $this->x($e, 'valence');
        $this->mood('Aela', $e, 'Dragon', true, 0.0, 'death', self::AT + 1000);
        $this->assertGreaterThan($bandit + 10.0, $this->x($e, 'valence'));
        // a pack of skeevers never stacks
        $p = $this->npc('Romantic');
        for ($i = 0; $i < 8; $i++) $this->mood('Aela', $p, 'Skeever', true, 0.0, 'death', self::AT + $i * 1000);
        $this->assertEqualsWithDelta(10.0, $this->x($p, 'arousal'), 1e-6);
        $this->assertEqualsWithDelta(0.0, $this->x($p, 'valence'), 1e-6);
        // ... and the episode is over after the window: a new dragon is a new thrill
        $later = $this->mood('Aela', $d, 'Dragon', true, 0.0, 'death', self::AT + 2 * self::WINDOW);
        $this->assertEqualsWithDelta(self::AT + 2 * self::WINDOW, (float) $d[RelDynCombat::MOOD_KEY]['gamets'], 1e-6, 'a new episode');
        $this->assertNotSame(0.0, $later['valence'] + $later['arousal'], 'a new dragon moves the NPC again');
    }

    public function testACombatEndReadsTheFoeFromTheKillsJustBeforeItWhenThereAreRows(): void
    {
        // no rows (the fake database finds none): a combat end is the default class, a mighty one the mighty class
        $d = $this->npc('Romantic');
        $r = RelDynCombat::fightMood('N', $d, ['type' => 'combatend', 'at' => self::AT, 'victim' => null, 'player' => 'Kaida', 'fought' => true,
            'victory' => true, 'prefs' => []]);
        $this->assertSame('engaged', $r['tier']);
        $m = $this->npc('Romantic');
        $r = RelDynCombat::fightMood('N', $m, ['type' => 'combatendmighty', 'at' => self::AT, 'victim' => null, 'player' => 'Kaida', 'fought' => true,
            'victory' => true, 'prefs' => []]);
        $this->assertSame('overwhelming', $r['tier']);
        $this->assertGreaterThan($this->x($d, 'arousal') + 40.0, $this->x($m, 'arousal'));
    }

    public function testTheSwitchTurnsTheWholeFightMoodOff(): void
    {
        $this->setConfig(['enabled' => false]);
        $d = $this->npc('Romantic');
        $this->assertSame([], $this->mood('N', $d, 'Dragon'));
        $this->assertEqualsWithDelta(10.0, $this->x($d, 'arousal'), 1e-9);
        $this->assertArrayNotHasKey(RelDynCombat::MOOD_KEY, $d);
        $this->assertNull(RelDynCombat::aftermath(['_combat_mood' => ['gamets' => self::AT, 'outcome' => 'near_miss', 'reached' => 99.0]], self::AT + 100));
    }

    // ------------------------------------------------------------------ the fall hits by the foe

    public function testAFallHitsHarderAgainstAWorseFoeAndValenceStaysTheTraitOutcome(): void
    {
        $this->assertSame(1.0, RelDynCombat::defeatScale(null), 'no foe known yet');
        $this->assertSame(1.0, RelDynCombat::defeatScale(['arousal' => 0.0]));
        $this->assertSame(0.6, RelDynCombat::defeatScale(['arousal' => 10.0]), 'a skeever floors at the minimum');
        $this->assertSame(1.0, RelDynCombat::defeatScale(['arousal' => 40.0]));
        $this->assertSame(1.5, RelDynCombat::defeatScale(['arousal' => 95.0]), 'a dragon caps');
        foreach (['Bold', 'Anxious'] as $t) {
            $plain = $this->npc($t);
            $dragon = $this->npc($t);
            $a = RelationshipDynamics::bleedoutResponse($plain, true);
            $b = RelationshipDynamics::bleedoutResponse($dragon, true, 1.5);
            $this->assertEqualsWithDelta($a['arousal'] * 1.5, $b['arousal'], 1e-6, $t);
            $this->assertEqualsWithDelta($a['valence'] * 1.5, $b['valence'], 1e-6, $t);
            $this->assertSame($a['passion'], $b['passion'], "{$t}: the passion of the fall is the NPC's, not the foe's");
            $this->assertSame($a['valence'] <=> 0.0, $b['valence'] <=> 0.0, "{$t}: the direction is who the NPC is");
        }
        $bold = $this->npc('Bold');
        $anxious = $this->npc('Anxious');
        $this->assertGreaterThan(0.0, RelationshipDynamics::bleedoutResponse($bold)['valence'], 'the warrior\'s fall is rage');
        $this->assertLessThan(0.0, RelationshipDynamics::bleedoutResponse($anxious)['valence'], 'the anxious one\'s is terror');
    }

    // ------------------------------------------------------------------ who the NPC is in a fight

    public function testTheLeanFollowsTheBleedoutResponsesFightAndFear(): void
    {
        $this->assertSame('bold', RelDynCombat::lean($this->npc('Bold')));
        $this->assertSame('shaken', RelDynCombat::lean($this->npc('Anxious')));
        $this->assertContains(RelDynCombat::lean($this->npc('Stoic')), RelDynCombat::LEANS);
        $none = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['inferred_temperament' => 'No Such Label']));
        $this->assertSame('steady', RelDynCombat::lean($none), 'no trait vector: steady');
        $wide = array_replace(RelDynCombat::arousalConfig(), ['lean_bold_margin' => 1000.0, 'lean_shaken_margin' => 1000.0]);
        $this->assertSame('steady', RelDynCombat::lean($this->npc('Bold'), $wide));
        $this->assertSame('steady', RelDynCombat::lean($this->npc('Anxious'), $wide));
        $this->assertSame('steady', RelDynCombat::lean($this->npc('Romantic')), 'in between: steady');
    }

    // ------------------------------------------------------------------ the near miss

    public function testAnNpcWhoFellAndLivedThroughTheFightHasANearMissAndItBonds(): void
    {
        $plain = $this->npc('Romantic');
        $rush = $this->npc('Romantic');
        foreach ([&$plain, &$rush] as &$d) {
            $d['dimensions']['trust']['x'] = 40.0;
            $d['dimensions']['comfort']['x'] = 40.0;
        }
        unset($d);
        $rush[RelDynCombat::MOOD_KEY] = ['gamets' => self::AT - 1000, 'arousal' => 40.0, 'reached' => 40.0, 'tier' => 'engaged', 'valence_granted' => 0.0,
            'outcome' => 'beaten', 'fell_at' => self::AT - 1000, 'near_miss' => false];
        $before = ['trust' => $this->x($rush, 'trust'), 'comfort' => $this->x($rush, 'comfort')];
        $a = $this->mood('Aela', $plain, 'Dragon');
        $b = $this->mood('Aela', $rush, 'Dragon');
        $this->assertFalse($a['near_miss']);
        $this->assertTrue($b['near_miss']);
        $this->assertSame('near_miss', $b['outcome']);
        $this->assertSame('triumph', $a['outcome']);
        $this->assertTrue($rush[RelDynCombat::MOOD_KEY]['near_miss']);
        // the fall was the first valence (bleedoutResponse's, already felt at the fall); the survival is the second: relief
        $this->assertStringContainsString('NEAR MISS: Aela (fell in the fight) terror +0.00 then relief +', (string) file_get_contents($this->errorLog));
        $this->assertGreaterThan($this->x($plain, 'valence'), $this->x($rush, 'valence'), 'a rush on top of the win');
        $this->assertGreaterThanOrEqual(88.0, $this->x($rush, 'arousal') - 0.001, 'near-death adrenaline');
        // ... and the bond it makes
        $this->assertGreaterThan($before['trust'], $this->x($rush, 'trust'));
        $this->assertGreaterThan($before['comfort'], $this->x($rush, 'comfort'));
        // (the passion rush is governed like every gain: this state has no bond tier yet, so the governor holds it at the distant
        // tier's ceiling; the test beds, friends in core, feel it)
        $this->assertStringContainsString('[ATTRACTION] Aela: spike:near_miss passion', (string) file_get_contents($this->errorLog), 'a spike was asked');
        $this->assertEqualsWithDelta(40.0, $this->x($plain, 'trust'), 1e-9, 'the plain win bonds nothing in trust');
        // once per episode
        $trust = $this->x($rush, 'trust');
        $again = $this->mood('Aela', $rush, 'Dragon', true, 0.0, 'death', self::AT + 1000);
        $this->assertTrue($again['near_miss'], 'the episode is still a near miss');
        $this->assertEqualsWithDelta($trust, $this->x($rush, 'trust'), 1e-9, 'but it bonds once');
    }

    public function testAFallThatWasTheFirstValenceAsksNoTerrorAndTheSwitchTurnsTheRushOff(): void
    {
        $net = [];
        foreach (['Bold', 'Anxious'] as $t) {
            $d = $this->npc($t);
            $d[RelDynCombat::MOOD_KEY] = ['gamets' => self::AT - 1000, 'arousal' => 40.0, 'reached' => 40.0, 'tier' => 'engaged', 'valence_granted' => 0.0,
                'outcome' => 'beaten', 'fell_at' => self::AT - 1000, 'near_miss' => false];
            $this->mood('N', $d, 'Dragon', true, 0.0);
            $net[$t] = $this->x($d, 'valence');
            $this->assertGreaterThan(0.0, $net[$t], "{$t}: the win and the relief after the fall end well");
        }
        $this->assertStringNotContainsString('terror -', (string) file_get_contents($this->errorLog), 'the fall already was the first valence');
        $this->setConfig(['near_miss' => ['enabled' => false]]);
        $d = $this->npc('Romantic');
        $d[RelDynCombat::MOOD_KEY] = ['gamets' => self::AT - 1000, 'arousal' => 40.0, 'reached' => 40.0, 'tier' => 'engaged', 'valence_granted' => 0.0,
            'outcome' => 'beaten', 'fell_at' => self::AT - 1000, 'near_miss' => false];
        $this->assertFalse($this->mood('N', $d, 'Dragon')['near_miss']);
        $this->assertSame('triumph', $d[RelDynCombat::MOOD_KEY]['outcome'], 'a fall that was no near miss still ends in a win');
    }

    // ------------------------------------------------------------------ the felt aftermath

    public function testTheAftermathIsAWinANearMissOrADefeatByLeanAndFadesWithTheGlow(): void
    {
        $bold = $this->npc('Bold');
        $this->mood('Aela', $bold, 'Dragon');
        $a = RelDynCombat::aftermath($bold, self::AT + 5000);
        $this->assertSame(['triumph', 'bold'], [$a['outcome'], $a['lean']]);
        $this->assertSame('overwhelming', $a['tier']);
        $this->assertNull(RelDynCombat::aftermath($bold, self::AT + RelationshipDynamics::POST_COMBAT_GLOW_GAMETS + 1), 'five minutes of play, not forever');
        $this->assertNull(RelDynCombat::aftermath($bold, self::AT - 1), 'a clock behind the fight');
        $this->assertNull(RelDynCombat::aftermath($bold, 0.0));
        $skeever = $this->npc('Bold');
        $this->mood('Aela', $skeever, 'Skeever');
        $this->assertNull(RelDynCombat::aftermath($skeever, self::AT + 100), 'a skeever is not worth a felt aftermath');
        $beaten = $this->npc('Anxious');
        $beaten[RelDynCombat::MOOD_KEY] = ['gamets' => self::AT, 'arousal' => 0.0, 'reached' => 0.0, 'tier' => null, 'valence_granted' => 0.0,
            'outcome' => 'beaten', 'fell_at' => self::AT, 'near_miss' => false];
        $this->assertSame(['beaten', 'shaken'], array_values(array_intersect_key(RelDynCombat::aftermath($beaten, self::AT + 100), ['outcome' => 1, 'lean' => 1])));
    }

    public function testTheFeltTextsAreFeltNotNumbersNorGenderedAndEveryOutcomeHasEveryLean(): void
    {
        $text = RelDynFelt::TEXT_DEFAULTS['combat'];
        foreach (RelDynCombat::OUTCOMES as $outcome) {
            foreach (RelDynCombat::LEANS as $lean) {
                $line = $text['aftermath'][$outcome][$lean] ?? null;
                $this->assertIsString($line, "{$outcome}/{$lean}");
                $this->assertStringContainsString('{NAME}', $line);
                $this->assertDoesNotMatchRegularExpression('/\d/', $line, "{$outcome}/{$lean}: a number reached the LLM");
                $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|herself|he|him|his|himself)\b/i', $line, "{$outcome}/{$lean}");
            }
        }
        foreach (['fighting_bold', 'fighting_shaken'] as $k) {
            $this->assertDoesNotMatchRegularExpression('/\d|\b(she|her|he|him|his)\b/i', $text[$k], $k);
        }
        $this->assertCount(9, array_unique(array_merge(...array_map('array_values', array_values($text['aftermath'])))), 'nine different lines');
    }

    public function testTheAftermathReachesTheFeltLinesOnTheNpcsOwnWords(): void
    {
        $lines = [];
        foreach (['Bold', 'Anxious'] as $t) {
            $d = $this->npc($t);
            $this->mood('N', $d, 'Dragon');
            $now = self::AT + 3000;
            $c = RelDynFelt::compose('Muiri', 'Kaida', $d, (float) $now, ['player_addressed' => true]);
            $by = array_column($c['lines'], 'text', 'key');
            $this->assertArrayHasKey('post_combat', $by, $t);
            $lines[$t] = $by['post_combat'];
            $this->assertStringContainsString('Muiri', $lines[$t]);
        }
        $this->assertNotSame($lines['Bold'], $lines['Anxious'], json_encode($lines));
        $this->assertStringContainsString('burning', $lines['Bold']);
        $this->assertStringContainsString('shaking', $lines['Anxious']);
    }

    // ------------------------------------------------------------------ a fight routed on a later turn fades from its own time

    public function testAFightRoutedOnALaterTurnFadesFromItsOwnTimeNotFromTheNpcsLastTurn(): void
    {
        $halfLife = 5.0 * 60.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;   // arousal's half-life: five minutes of play
        $d = $this->npc('Romantic');
        $d['_accumulated_play_gamets'] = 1000.0;
        $d['dimensions']['arousal']['x'] = 60.0;   // left from something an hour of play ago
        $d[RelDynMoodAxes::CLOCK_KEY] = ['play' => 1000.0, 'gamets' => self::AT - 6 * $halfLife];
        // the dragon fell at AT; the NPC's own turn comes one half-life later, after hours of play credited since its last turn
        RelDynMoodAxes::settleToEvent($d, self::AT);
        $this->assertEqualsWithDelta(10.0 + 50.0 / 64.0, $this->x($d, 'arousal'), 0.05, 'what was there settled to the fight: six half-lives');
        $this->assertTrue($d[RelDynMoodAxes::CLOCK_KEY]['by_event']);
        $this->assertEqualsWithDelta(self::AT, $d[RelDynMoodAxes::CLOCK_KEY]['gamets'], 1e-6);
        $this->mood('N', $d, 'Dragon');
        $peak = $this->x($d, 'arousal');
        $this->assertGreaterThanOrEqual(90.0, $peak);
        $d['_accumulated_play_gamets'] = 1000.0 + 40.0 * $halfLife;   // the play credited since the last turn, all of it before the fight
        RelDynMoodAxes::settle($d, self::AT + $halfLife);
        $this->assertEqualsWithDelta(10.0 + ($peak - 10.0) / 2.0, $this->x($d, 'arousal'), 0.5,
            'one half-life after the fight: half of the dragon is left, not nothing');
        $this->assertArrayNotHasKey('by_event', $d[RelDynMoodAxes::CLOCK_KEY], 'the next settle stamps its own clock');
        // without the stamp the same turn would have erased it
        $e = $this->npc('Romantic');
        $e['_accumulated_play_gamets'] = 1000.0;
        $e[RelDynMoodAxes::CLOCK_KEY] = ['play' => 1000.0, 'gamets' => self::AT - 6 * $halfLife];
        $this->mood('N', $e, 'Dragon');
        $e['_accumulated_play_gamets'] = 1000.0 + 40.0 * $halfLife;
        RelDynMoodAxes::settle($e, self::AT + $halfLife);
        $this->assertLessThan(12.0, $this->x($e, 'arousal'), 'the old way: the fight faded for the hours before it');
        // an event older than the clock changes nothing
        $f = $this->npc('Romantic');
        $f[RelDynMoodAxes::CLOCK_KEY] = ['play' => 0.0, 'gamets' => self::AT];
        $before = $f;
        $this->assertSame([], RelDynMoodAxes::settleToEvent($f, self::AT - 1000));
        $this->assertSame($before, $f);
        $this->assertSame([], RelDynMoodAxes::settleToEvent($f, 0.0));
    }

    public function testStampEventMarksTheClockAtTheEventAndLeavesTheStateAlone(): void
    {
        $d = $this->npc('Romantic');
        $d['dimensions']['arousal']['x'] = 55.0;
        $d['_accumulated_play_gamets'] = 500.0;
        $d[RelDynMoodAxes::CLOCK_KEY] = ['play' => 400.0, 'gamets' => self::AT - 1000];
        RelDynMoodAxes::stampEvent($d, self::AT);
        $this->assertEqualsWithDelta(55.0, $this->x($d, 'arousal'), 1e-9, 'nothing settles');
        $this->assertEqualsWithDelta(500.0, $d[RelDynMoodAxes::CLOCK_KEY]['play'], 1e-9);
        $this->assertEqualsWithDelta(self::AT, $d[RelDynMoodAxes::CLOCK_KEY]['gamets'], 1e-6);
        $this->assertTrue($d[RelDynMoodAxes::CLOCK_KEY]['by_event']);
        $e = $this->npc('Romantic');
        RelDynMoodAxes::stampEvent($e, self::AT);
        $this->assertArrayNotHasKey(RelDynMoodAxes::CLOCK_KEY, $e, 'no clock yet: the first settle stamps it');
        $f = $d;
        RelDynMoodAxes::stampEvent($f, self::AT - 5000);
        $this->assertSame($d, $f, 'an event older than the clock changes nothing');
    }
}
