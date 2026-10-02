<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * The extended bond kinds (decisions 2026-10-01 §24, roadmap relationship-types-extended), the pure halves: no database, the core
 * write replaced by a recording seam, fixed game timestamps. Core owns relationships.Player.type: committed, conflicted and sworn are
 * RelDyn's own state, mapped onto core's types (committed: romantic; conflicted: ex; sworn: the oath of fanatical / servant), the
 * breakup fork (ex, conflicted, friends) chosen by who the NPC is and how it ended, the way back from an ending, and the infidelity
 * loop on top of natural exclusivity. The four test beds through the real hooks are RelDynWDarkTestBedsPostgresTest.
 */
final class RelDynWBondsTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY + 12 * self::HOUR;

    private const SECURE = [0.15, 0.15];
    private const ANXIOUS = [0.85, 0.15];
    private const AVOIDANT = [0.15, 0.85];
    private const FEARFUL = [0.85, 0.85];

    private array $saved = [];
    private string $errorLog = '';
    private $prevErrorLog = null;
    /** @var array<int, array{0: string, 1: string, 2: string, 3: ?string}> the core writes the seam saw: [npc, to, reason, from] */
    private array $writes = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
        $this->writes = [];
        RelDynBonds::$coreWriter = function (string $npc, string $to, string $reason, ?string $from): bool {
            $this->writes[] = [$npc, $to, $reason, $from];
            return true;
        };
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdwbonds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        RelDynBonds::$coreWriter = null;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function at(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) round($gamets), 'Kaida: hello'];
    }

    /**
     * A partner of the player (core 'romantic', affinity 65, passion 40, a friend once): attachment axes, maturity, trust; traits,
     * dimensions and the rest by $o (traits, dims, aff, passion, core, hwm, preference, contact_days_ago).
     */
    private function npc(array $axes = self::SECURE, float $maturity = 75.0, float $trust = 60.0, array $o = []): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], (array) ($o['traits'] ?? []));
        $x['maturity_start'] = $maturity;
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src'] = ['assignment' => 'read'];
        $d['profile_overrides'] = ['attachment_axes' => ['anxiety' => $axes[0], 'avoidance' => $axes[1]]];
        $d['_aff_mirror_x'] = 50.0;
        RelationshipDynamics::setCoreAffinityValue($d, floatval($o['aff'] ?? 65.0));
        $dims = array_merge(['comfort' => 60.0, 'trust' => $trust, 'maturity' => $maturity, 'self_confidence' => 55.0, 'resentment' => 5.0, 'respect' => 60.0, 'jealousy' => 0.0], (array) ($o['dims'] ?? []));
        foreach ($dims as $k => $v) $d['dimensions'][$k]['x'] = $v;
        RelationshipDynamics::setPassion($d, floatval($o['passion'] ?? 40.0));
        $d['_core_rel_type'] = (string) ($o['core'] ?? 'romantic');
        $d['context_tier_hwm'] = intval($o['hwm'] ?? 3);
        if (isset($o['preference'])) $d['relationship_preference'] = (string) $o['preference'];
        if (isset($o['contact_days_ago'])) $d['_last_contact_gamets'] = self::T0 - floatval($o['contact_days_ago']) * self::DAY;
        return $d;
    }

    private function config(array $bonds): void
    {
        $GLOBALS['db'] = new RelDynWBondsConfigDb(['bonds' => $bonds]);
        RelationshipDynamics::clearConfigCache();
    }

    /** A suitor in the NPC's ledger with $interest at self::T0. */
    private function suitor(array &$d, string $name, float $interest, float $at = self::T0): void
    {
        $d[RelDynExclusivity::STATE_KEY]['suitors'][RelDynFulfillment::pairKey($name)] = ['name' => $name, 'interest' => $interest, 'moves' => 3, 'seen_rowid' => 1, 'gamets' => $at];
    }

    // =====================================================================
    // the kinds, and core's own types
    // =====================================================================

    public function testAKindHoldsOnlyWhileCoresTypeSupportsIt(): void
    {
        $d = $this->npc();
        $d[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'committed'];
        $this->assertSame(['committed'], RelDynBonds::kinds($d));
        $d['_core_rel_type'] = 'platonic';
        $this->assertSame([], RelDynBonds::kinds($d), 'core moved the type: the claim is stale');
        $d['_core_rel_type'] = 'crush';
        $this->assertSame([], RelDynBonds::kinds($d), 'a crush is not the formal rung');
        $c = $this->npc(self::SECURE, 75.0, 60.0, ['core' => 'ex']);
        $c[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'conflicted'];
        $this->assertSame(['conflicted'], RelDynBonds::kinds($c));
        $c['_core_rel_type'] = 'romantic';
        $this->assertSame([], RelDynBonds::kinds($c), 'together again: no longer the soft end of anything');
        // the oath: its own state, or core's types that are the oath
        $o = $this->npc(self::SECURE, 75.0, 60.0, ['core' => 'neutral']);
        $this->assertSame([], RelDynBonds::kinds($o));
        $o[RelDynBonds::KEY] = ['v' => 1, 'oath' => ['active' => true, 'source' => 'editor']];
        $this->assertSame(['sworn'], RelDynBonds::kinds($o));
        $o[RelDynBonds::KEY]['oath']['broken'] = true;
        $this->assertSame([], RelDynBonds::kinds($o), 'forsworn');
        foreach (['fanatical', 'servant'] as $core) {
            $this->assertSame(['sworn'], RelDynBonds::kinds($this->npc(self::SECURE, 75.0, 60.0, ['core' => $core])), "core {$core} is the oath");
        }
        // the switch
        $this->config(['enabled' => false]);
        $this->assertSame([], RelDynBonds::kinds($this->npc(self::SECURE, 75.0, 60.0, ['core' => 'servant'])));
    }

    public function testConflictedIsABondTypeOfItsOwnAndSwornFollowsTheOathWhileCoresTypesAndTheTablesStayAsTheyWere(): void
    {
        $type = fn(array $d) => RelationshipDynamics::getRelationshipType('Rowan', $d);
        $conflicted = $this->npc(self::SECURE, 75.0, 60.0, ['core' => 'ex']);
        $conflicted[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'conflicted'];
        $this->assertSame('conflicted', $type($conflicted));
        $ex = $this->npc(self::SECURE, 75.0, 60.0, ['core' => 'ex']);
        $this->assertNotSame('conflicted', $type($ex), 'a plain ex is not the soft state');
        $romantic = $this->npc();
        $romantic[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'committed'];
        $this->assertSame('bonded', $type($romantic), 'committed is the bonded row: nothing keyed by type loses a couple');
        $this->assertSame('bonded', $type($this->npc()));
        // the oath: for a bond without a romance or hostility
        foreach (['platonic' => 'sworn', 'neutral' => 'sworn', 'professional' => 'sworn', 'romantic' => 'bonded', 'crush' => 'crush', 'enemy' => 'hostile', 'rival' => 'rival'] as $core => $want) {
            $d = $this->npc(self::SECURE, 75.0, 60.0, ['core' => $core]);
            $d[RelDynBonds::KEY] = ['v' => 1, 'oath' => ['active' => true]];
            $this->assertSame($want, $type($d), "oath-bound with core {$core}");
        }
        $this->assertSame('sworn', $type($this->npc(self::SECURE, 75.0, 60.0, ['core' => 'fanatical'])), 'core\'s own mapping, as before');
        // the modifier row of the soft state
        $this->assertEqualsWithDelta(1.2, RelationshipDynamics::getTypeModifier('conflicted', 'warmth'), 1e-9);
        $this->assertEqualsWithDelta(0.8, RelationshipDynamics::getTypeModifier('conflicted', 'trust'), 1e-9);
        $this->assertSame('none', RelationshipDynamics::TIER_FLOOR_GATES['conflicted'], 'no floor holds the soft state');
        // the governor: the soft state can burn, a plain ex is the hostile row
        $this->assertSame('crush', RelDynGovernors::tier($conflicted));
        $this->assertSame('hostile', RelDynGovernors::tier($ex));
        $this->assertSame('committed', RelDynGovernors::tier($romantic));
    }

    public function testNoNewTypeIsEverWrittenToCoreAndEveryWriteIsOneOfCoresOwn(): void
    {
        foreach (['committed', 'conflicted', 'sworn'] as $kind) {
            $this->assertNotContains($kind, RelationshipManager::TYPES, "core's UI does not know {$kind}");
            $this->assertNotContains($kind, array_values(RelDynBonds::FORK_CORE_TYPE));
        }
        foreach (RelDynBonds::FORK_CORE_TYPE as $fork => $core) {
            $this->assertContains($core, RelationshipManager::TYPES, "{$fork} leaves core at {$core}");
        }
        $this->assertContains((string) RelDynBonds::config()['rekindle']['to'], RelationshipManager::TYPES);
        $this->assertContains((string) RelDynBonds::config()['rekindle']['thaw']['to'], RelationshipManager::TYPES);
        // and in practice: a run of endings, rekindles and thaws writes nothing else
        foreach ([self::SECURE, self::ANXIOUS, self::AVOIDANT] as $axes) {
            foreach (['standards', 'pursued', 'neglect'] as $cause) {
                $d = $this->npc($axes);
                RelDynBonds::breakup('Rowan', $d, $cause, 'npc', self::T0);
            }
        }
        $this->assertNotEmpty($this->writes);
        foreach ($this->writes as [$npc, $to]) $this->assertContains($to, RelationshipManager::TYPES);
    }

    // =====================================================================
    // committed
    // =====================================================================

    private function partnerAt(array &$d, float $t): void
    {
        $this->at($t);
        $d['_last_contact_gamets'] = $t;
    }

    public function testARomanceBecomesFormalAfterAWhileThatDependsOnWhoTheNpcIs(): void
    {
        $monogamous = $this->npc(self::SECURE, 80.0, 70.0, ['passion' => 60.0, 'preference' => 'monogamous']);
        $poly = $this->npc(self::SECURE, 80.0, 70.0, ['passion' => 60.0, 'preference' => 'polyamorous']);
        $this->assertLessThan(8.0, RelDynBonds::commitDays($monogamous), 'a monogamous NPC: about a week');
        $this->assertGreaterThan(RelDynBonds::commitDays($monogamous) * 2.0, RelDynBonds::commitDays($poly), 'a polyamorous one commits slowly');
        $avoidant = $this->npc(self::AVOIDANT, 80.0, 70.0, ['passion' => 60.0, 'preference' => 'monogamous']);
        $this->assertGreaterThan(RelDynBonds::commitDays($monogamous), RelDynBonds::commitDays($avoidant), 'avoidant keeps a door open');
        $this->assertLessThan(60.0, RelDynBonds::commitDays($this->npc(self::SECURE, 80.0, 70.0, ['preference' => 'not_interested'])), 'never a wall: the slowest is bounded');

        $d = $monogamous;
        $needed = RelDynBonds::commitDays($d);
        $this->partnerAt($d, self::T0);
        $r = RelDynBonds::advance('Rowan', $d, self::T0);
        $this->assertSame([], $r['kinds'], 'the first day of it is not formal');
        $this->assertEqualsWithDelta(self::T0, $d[RelDynBonds::KEY]['romantic_since'], 1.0);
        $t = self::T0 + ($needed - 0.5) * self::DAY;
        $this->partnerAt($d, $t);
        $this->assertSame([], RelDynBonds::advance('Rowan', $d, $t)['kinds'], 'not yet');
        $t = self::T0 + ($needed + 0.1) * self::DAY;
        $this->partnerAt($d, $t);
        $r = RelDynBonds::advance('Rowan', $d, $t);
        $this->assertContains('committed', $r['events']);
        $this->assertSame(['committed'], $r['kinds']);
        $this->assertSame('romantic', $d['_core_rel_type'], 'core\'s type is untouched');
        $this->assertSame([], $this->writes, 'nothing is written to core for it');
        $line = RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida')['lines'];
        $this->assertSame('committed', $line[0]['key']);
        $this->assertStringContainsString('couple', $line[0]['text']);
        // it stays while the romance does, and is gone when core's type leaves it
        $t += 30 * self::DAY;
        $this->partnerAt($d, $t);
        $this->assertSame(['committed'], RelDynBonds::advance('Rowan', $d, $t)['kinds']);
        $d['_core_rel_type'] = 'platonic';
        $this->assertSame([], RelDynBonds::advance('Rowan', $d, $t + self::HOUR)['kinds']);
        $this->assertArrayNotHasKey('committed_since', $d[RelDynBonds::KEY]);
    }

    public function testFormalOnlyWithThePullTrustAffinityAndNoQuarrelOpen(): void
    {
        $formalAfter = function (array $d, ?callable $then = null): bool {
            $this->partnerAt($d, self::T0);
            RelDynBonds::advance('Rowan', $d, self::T0);
            $t = self::T0 + 30 * self::DAY;
            $this->partnerAt($d, $t);
            if ($then) $then($d);
            return in_array('committed', RelDynBonds::advance('Rowan', $d, $t)['kinds'], true);
        };
        $base = $this->npc(self::SECURE, 80.0, 70.0, ['passion' => 60.0]);
        $this->assertTrue($formalAfter($base));
        $this->assertFalse($formalAfter($this->npc(self::SECURE, 80.0, 70.0, ['passion' => 5.0])), 'no passion, no exclusivity pull: a title, not a pair');
        $this->assertFalse($formalAfter($this->npc(self::SECURE, 80.0, 30.0, ['passion' => 60.0])), 'trust stands or it is not formal');
        $this->assertFalse($formalAfter($this->npc(self::SECURE, 80.0, 70.0, ['passion' => 60.0, 'aff' => 30.0])), 'affinity stands');
        $this->assertFalse($formalAfter($base, function (array &$d) { $d['in_conflict'] = true; }), 'not in the middle of a quarrel');
        $this->assertFalse($formalAfter($base, function (array &$d) { $d['_walkaway_state'] = 'active'; }), 'not while they have walked out');
        $this->assertFalse($formalAfter($this->npc(self::SECURE, 80.0, 70.0, ['passion' => 60.0, 'core' => 'crush'])), 'a crush is not the formal rung');
        $this->config(['committed' => ['enabled' => false]]);
        $this->assertFalse($formalAfter($base), 'off: a romance is never formal');
    }

    // =====================================================================
    // the breakup fork
    // =====================================================================

    /** The fork for $cause for an NPC of this kind, with the first core write. */
    private function ends(array $d, string $cause, string $by = 'npc'): array
    {
        $this->writes = [];
        $r = RelDynBonds::breakup('Rowan', $d, $cause, $by, self::T0);
        return [$r, $d, $this->writes[0] ?? null];
    }

    public function testTheSameEndingForkedByWhoTheNpcIsAndHowItEnded(): void
    {
        $trust = 45.0;
        $kinds = [
            'mature, secure'   => $this->npc(self::SECURE, 75.0, $trust),
            'mature, avoidant' => $this->npc(self::AVOIDANT, 75.0, $trust),
            'mature, anxious'  => $this->npc(self::ANXIOUS, 75.0, $trust),
            'raw, anxious'     => $this->npc(self::ANXIOUS, 20.0, $trust),
            'raw, fearful'     => $this->npc(self::FEARFUL, 20.0, $trust),
        ];
        $fork = function (string $cause) use ($kinds): array {
            $out = [];
            foreach ($kinds as $name => $d) $out[$name] = $this->ends($d, $cause)[0]['fork'];
            return $out;
        };
        $standards = $fork('standards');
        $this->assertSame('friends', $standards['mature, secure'], 'the romance is over and the friendship is not');
        $this->assertSame('ex', $standards['mature, avoidant'], 'cuts clean');
        $this->assertSame('conflicted', $standards['mature, anxious'], 'cannot let go');
        $this->assertSame('conflicted', $standards['raw, anxious']);
        $this->assertSame('conflicted', $standards['raw, fearful']);
        $this->assertGreaterThanOrEqual(3, count(array_unique($standards)), 'one ending, three destinations');
        // an ending that is hard is hard for everyone
        foreach (['pursued', 'left_for_other', 'resentment'] as $cause) {
            foreach ($fork($cause) as $name => $f) $this->assertSame('ex', $f, "{$cause}: {$name}");
        }
        // longing that has cooled, goodwill that is left: the mature part as friends, the raw as exes
        $cooled = fn(array $axes, float $m) => $this->ends($this->npc($axes, $m, 55.0, ['passion' => 5.0, 'aff' => 25.0, 'dims' => ['resentment' => 2.0]]), 'standards')[0]['fork'];
        $this->assertSame('friends', $cooled(self::SECURE, 75.0));
        $this->assertSame('ex', $cooled(self::ANXIOUS, 20.0));
    }

    public function testTheForkWritesCoresTypeAndLeavesTheSoftStateAndTheAftermath(): void
    {
        [$r, $d, $w] = $this->ends($this->npc(self::SECURE, 75.0, 45.0), 'standards');
        $this->assertSame('friends', $r['fork']);
        $this->assertSame('applied', $r['status']);
        $this->assertSame(['Rowan', 'platonic'], array_slice($w, 0, 2));
        $this->assertSame('romantic', $w[3], 'expected from the type it was');
        $this->assertSame('platonic', $d['_core_rel_type']);
        $this->assertSame('friends', $d[RelDynBonds::KEY]['breakup']['fork']);
        $this->assertNull($d[RelDynBonds::KEY]['kind'] ?? null);
        $this->assertSame('standards', $d[RelDynBonds::KEY]['breakup']['cause']);
        $this->assertSame([], RelDynBonds::kinds($d));

        [$r, $d, $w] = $this->ends($this->npc(self::ANXIOUS, 75.0, 45.0), 'standards');
        $this->assertSame('conflicted', $r['fork']);
        $this->assertSame('ex', $w[1], 'core only knows ex');
        $this->assertSame(['conflicted'], RelDynBonds::kinds($d), 'RelDyn knows the soft state');
        $this->assertSame('conflicted', RelationshipDynamics::getRelationshipType('Rowan', $d));
        $this->assertArrayNotHasKey('committed_since', $d[RelDynBonds::KEY]);

        // the aftermath is by the fork: an ex costs the most, friends the least, the soft state keeps its passion
        $loss = function (array $d, string $cause): array {
            $before = ['trust' => $d['dimensions']['trust']['x'], 'resentment' => $d['dimensions']['resentment']['x'], 'passion' => RelationshipDynamics::getPassion($d),
                'aff' => RelationshipDynamics::getCoreAffinity($d)];
            $r = RelDynBonds::breakup('Rowan', $d, $cause, 'npc', self::T0);
            return [$r['fork'], $d['dimensions']['trust']['x'] - $before['trust'], $d['dimensions']['resentment']['x'] - $before['resentment'],
                RelationshipDynamics::getPassion($d) / max(1.0, $before['passion']), RelationshipDynamics::getCoreAffinity($d) - $before['aff']];
        };
        [$f1, $t1, $res1, $p1, $a1] = $loss($this->npc(self::AVOIDANT, 75.0, 45.0), 'pursued');
        [$f2, $t2, $res2, $p2, $a2] = $loss($this->npc(self::ANXIOUS, 75.0, 45.0), 'standards');
        [$f3, $t3, $res3, $p3, $a3] = $loss($this->npc(self::SECURE, 75.0, 45.0), 'standards');
        $this->assertSame(['ex', 'conflicted', 'friends'], [$f1, $f2, $f3]);
        $this->assertLessThan($t2, $t1, 'trust: an ex loses most');
        $this->assertGreaterThan($res2, $res1, 'resentment');
        $this->assertEqualsWithDelta(0.5, $p1, 0.01, 'an ex keeps half the passion');
        $this->assertEqualsWithDelta(1.0, $p2, 0.01, 'the soft state keeps all of it');
        $this->assertEqualsWithDelta(0.7, $p3, 0.01, 'friends keep most');
        $this->assertLessThan($a2, $a1, 'affinity: an ex loses the most');
        $this->assertLessThan(0.0, $a3);
        $this->assertSame(0.0, $res3, 'friends: no resentment');
    }

    public function testAFormalRomanceCostsMoreToEnd(): void
    {
        $plain = $this->npc(self::AVOIDANT, 75.0, 45.0);
        $formal = $this->npc(self::AVOIDANT, 75.0, 45.0);
        $formal[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'committed', 'committed_since' => self::T0 - 30 * self::DAY, 'romantic_since' => self::T0 - 40 * self::DAY];
        $a = $plain; $b = $formal;
        RelDynBonds::breakup('Rowan', $a, 'pursued', 'npc', self::T0);
        RelDynBonds::breakup('Rowan', $b, 'pursued', 'npc', self::T0);
        $this->assertGreaterThan($a['dimensions']['resentment']['x'], $b['dimensions']['resentment']['x']);
        $this->assertTrue($b[RelDynBonds::KEY]['breakup']['committed']);
        $this->assertArrayNotHasKey('committed_since', $b[RelDynBonds::KEY], 'the formal rung ends with the romance');
    }

    public function testACoreRefusalLeavesTheRomanceAsItWasAndSaysSo(): void
    {
        RelDynBonds::$coreWriter = fn() => false;
        $d = $this->npc(self::AVOIDANT, 75.0, 45.0);
        $before = $d;
        $r = RelDynBonds::breakup('Rowan', $d, 'pursued', 'npc', self::T0);
        $this->assertSame('blocked', $r['status']);
        $this->assertSame('romantic', $d['_core_rel_type'], 'core kept its type');
        $this->assertSame($before['dimensions']['trust']['x'], $d['dimensions']['trust']['x'], 'no aftermath for an ending that did not happen');
        $this->assertArrayNotHasKey('breakup', $d[RelDynBonds::KEY] ?? []);
        $this->assertSame([], RelDynBonds::kinds($d));
        $this->assertStringContainsString('core refused', (string) file_get_contents($this->errorLog));
    }

    public function testCoresOwnEndingIsRecordedAndNothingIsWrittenBackToIt(): void
    {
        $d = $this->npc(self::ANXIOUS, 40.0, 45.0);
        $this->at(self::T0);
        RelDynBonds::advance('Rowan', $d, self::T0);   // looks at core's type: romantic
        $d['_core_rel_type'] = 'ex';                     // core's eval ended it
        $r = RelDynBonds::advance('Rowan', $d, self::T0 + self::HOUR);
        $this->assertSame([], $this->writes, 'core wrote it; RelDyn does not write it back');
        $this->assertNotEmpty(array_filter($r['events'], fn($e) => str_starts_with($e, 'breakup_')));
        $b = $d[RelDynBonds::KEY]['breakup'];
        $this->assertSame('core', $b['by']);
        $this->assertContains($b['fork'], ['ex', 'conflicted'], 'friends is not RelDyn\'s to offer over core\'s ex');
        $this->assertSame('romantic', $b['from']);
        // the same NPC would be the soft state; a cold one an ex
        $cold = $this->npc(self::AVOIDANT, 75.0, 20.0, ['dims' => ['resentment' => 70.0]]);
        RelDynBonds::advance('Rowan', $cold, self::T0);
        $cold['_core_rel_type'] = 'ex';
        RelDynBonds::advance('Rowan', $cold, self::T0 + self::HOUR);
        $this->assertSame('ex', $cold[RelDynBonds::KEY]['breakup']['fork']);
    }

    public function testTheBoundaryLanesCalmStepBackIsTheFriendsEndingRecordedNotRewritten(): void
    {
        $d = $this->npc(self::SECURE, 80.0, 60.0);
        $this->at(self::T0);
        RelDynBonds::advance('Rowan', $d, self::T0);
        // the fulfillment lane's mature boundary stepped core back to platonic (and recorded it)
        $d['_core_rel_type'] = 'platonic';
        $d[RelDynConcern::STATE_KEY]['boundary'] = ['state' => 'none', 'stepped_back_gamets' => self::T0, 'from' => 'romantic', 'to' => 'platonic', 'kind' => 'values'];
        $r = RelDynBonds::advance('Rowan', $d, self::T0 + self::HOUR);
        $this->assertContains('breakup_friends', $r['events']);
        $this->assertSame('friends', $d[RelDynBonds::KEY]['breakup']['fork']);
        $this->assertSame('boundary', $d[RelDynBonds::KEY]['breakup']['cause']);
        $this->assertSame([], $this->writes);
    }

    public function testOnlyAWalkedOutOfRomanceEndsAndOnlyForStandardsOrAGoneFeeling(): void
    {
        foreach (['deserve' => 'standards', 'affinity' => 'affinity'] as $reason => $cause) {
            $d = $this->npc(self::SECURE, 75.0, 45.0);
            $r = RelDynBonds::onWalkaway('Rowan', $d, $reason);
            $this->assertSame($cause, $r['cause'], $reason);
            $this->assertSame('applied', $r['status']);
        }
        foreach (['resentment', 'jealousy', 'autonomy', 'ick_comfort', 'shame', 'neglect'] as $reason) {
            $d = $this->npc(self::SECURE, 75.0, 45.0);
            $this->assertNull(RelDynBonds::onWalkaway('Rowan', $d, $reason), "{$reason}: a leaving the boundary test may undo ends nothing");
            $this->assertSame('romantic', $d['_core_rel_type']);
        }
        $friend = $this->npc(self::SECURE, 75.0, 45.0, ['core' => 'platonic']);
        $this->assertNull(RelDynBonds::onWalkaway('Rowan', $friend, 'deserve'), 'no romance, nothing to end');
        $crush = $this->npc(self::SECURE, 75.0, 45.0, ['core' => 'crush']);
        $this->assertNull(RelDynBonds::onWalkaway('Rowan', $crush, 'deserve'), 'a crush is not an ending');
        $this->assertNull(RelDynBonds::onSever('Rowan', $friend));
        $this->assertSame('pursued', RelDynBonds::onSever('Rowan', $this->npc(self::SECURE, 75.0, 45.0))['cause']);
    }

    public function testSeveringARomanceGoesThroughTheForkAndOtherBondsKeepTheOldWay(): void
    {
        $d = $this->npc(self::SECURE, 75.0, 45.0);
        $this->at(self::T0);
        $to = RelationshipDynamics::severBond('Rowan', $d);
        $this->assertTrue($d['_reject_recruitment']);
        $this->assertSame('ex', $to, 'followed after leaving: the hardest end');
        $this->assertSame('ex', $d['_core_rel_type']);
        // a friendship keeps the walkaway_sever_types way (no database: nothing written, the hard flag set)
        $friend = $this->npc(self::SECURE, 75.0, 45.0, ['core' => 'platonic']);
        $this->assertNull(RelationshipDynamics::severBond('Rowan', $friend));
        $this->assertTrue($friend['_reject_recruitment']);
    }

    public function testAnEndingThatEndedHardClosesIntimacyUntilItThawsOrStartsAgainAndTheSoftOnesDoNot(): void
    {
        [$r, $ex] = $this->ends($this->npc(self::AVOIDANT, 75.0, 45.0), 'pursued');
        $this->assertSame('ex', $r['fork']);
        $this->assertTrue(RelDynBonds::endedHard($ex));
        $this->assertContains('ended', RelDynConsent::closedReasons($ex), 'a state, not a verdict');
        $this->assertSame([], RelDynConsent::closedReasons($this->npc()), 'a couple is not closed');
        [$r, $soft] = $this->ends($this->npc(self::ANXIOUS, 75.0, 45.0), 'standards');
        $this->assertSame('conflicted', $r['fork']);
        $this->assertFalse(RelDynBonds::endedHard($soft));
        $this->assertNotContains('ended', RelDynConsent::closedReasons($soft), 'the soft state is weighed, not closed');
        [$r, $friends] = $this->ends($this->npc(self::SECURE, 80.0, 55.0, ['passion' => 5.0, 'aff' => 25.0, 'dims' => ['resentment' => 2.0]]), 'standards');
        $this->assertSame('friends', $r['fork']);
        $this->assertNotContains('ended', RelDynConsent::closedReasons($friends));
        // thawed into friends: open again to being weighed
        $ex[RelDynBonds::KEY]['breakup']['thaw'] = 1.0;
        $ex['dimensions']['resentment']['x'] = 5.0;
        $ex['dimensions']['trust']['x'] = 60.0;
        $ex['dimensions']['maturity']['x'] = 80.0;
        $this->at(self::T0);
        RelDynBonds::advance('Rowan', $ex, self::T0);
        RelDynBonds::advance('Rowan', $ex, self::T0 + self::HOUR);
        $this->assertSame('platonic', $ex['_core_rel_type']);
        $this->assertNotContains('ended', RelDynConsent::closedReasons($ex));
        $text = (string) RelDynConsent::config()['felt_text']['closed']['ended'];
        $this->assertStringContainsString('{NAME}', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|him)\b/i', $text);
    }

    public function testTheExclusivityPullIsReleasedByAnEnding(): void
    {
        $now = self::T0;
        $together = $this->npc(self::SECURE, 75.0, 60.0, ['passion' => 60.0]);
        $this->assertEqualsWithDelta(1.0, RelDynBonds::release($together), 1e-9);
        $pullTogether = RelDynExclusivity::pull($together, $now)['pull'];
        $this->assertGreaterThan(0.4, $pullTogether);
        foreach (['pursued' => 'ex', 'standards' => 'friends'] as $cause => $fork) {
            [$r, $after] = $this->ends($this->npc($cause === 'pursued' ? self::AVOIDANT : self::SECURE, 75.0, 45.0, ['passion' => 60.0]), $cause);
            $this->assertSame($fork, $r['fork']);
            $this->assertSame(0.0, RelDynBonds::release($after), "{$fork}: not holding out for the player");
            $this->assertLessThan(0.1, RelDynExclusivity::pull($after, $now)['pull'], $fork);
        }
        [$r, $soft] = $this->ends($this->npc(self::ANXIOUS, 75.0, 45.0, ['passion' => 60.0]), 'standards');
        $this->assertSame('conflicted', $r['fork']);
        $this->assertEqualsWithDelta(0.4, RelDynBonds::release($soft), 1e-9, 'a conflicted bond still carries a torch');
        $this->assertGreaterThan(0.0, RelDynExclusivity::pull($soft, $now)['pull']);
    }

    // =====================================================================
    // the way back (rekindle) and the way to a friendship (thaw), and hardening
    // =====================================================================

    private function conflicted(array $axes = self::ANXIOUS, float $maturity = 60.0): array
    {
        [$r, $d] = $this->ends($this->npc($axes, $maturity, 45.0), 'standards');
        $this->assertSame('conflicted', $r['fork']);
        $d['dimensions']['resentment']['x'] = 5.0;
        $d['dimensions']['trust']['x'] = 60.0;
        return $d;
    }

    private function warm(float $sig = 0.8, array $tags = ['quality_time']): array
    {
        return ['npc' => 'Rowan', 'positive_interaction' => true, 'significance' => $sig, 'tags' => $tags];
    }

    public function testExchangesThatMeanSomethingBuildTheWayBackTimeDoesNothingAndTheDayIsCapped(): void
    {
        $d = $this->conflicted();
        $this->assertSame(0.0, $d[RelDynBonds::KEY]['breakup']['rekindle']);
        // time alone: nothing
        $this->at(self::T0 + 30 * self::DAY);
        $r = RelDynBonds::advance('Rowan', $d, self::T0 + 30 * self::DAY);
        $this->assertSame(0.0, $d[RelDynBonds::KEY]['breakup']['rekindle'], 'time does not heal');
        $this->assertSame(['conflicted'], $r['kinds']);
        // an exchange that means something
        $p1 = RelDynBonds::onEvalItem('Rowan', $this->warm(0.8), $d, self::T0);
        $this->assertGreaterThan(0.0, $p1);
        $this->assertEqualsWithDelta($p1, $d[RelDynBonds::KEY]['breakup']['rekindle'], 1e-9);
        // forgiveness counts more than a gift
        $a = $this->conflicted();
        $b = $this->conflicted();
        $this->assertGreaterThan(RelDynBonds::onEvalItem('Rowan', $this->warm(0.8, ['gift']), $a, self::T0), RelDynBonds::onEvalItem('Rowan', $this->warm(0.8, ['forgiveness']), $b, self::T0));
        // the day is capped: spamming warmth is not love
        $c = $this->conflicted();
        for ($i = 0; $i < 20; $i++) RelDynBonds::onEvalItem('Rowan', $this->warm(1.0, ['forgiveness']), $c, self::T0);
        $this->assertLessThanOrEqual(0.3 + 1e-9, $c[RelDynBonds::KEY]['breakup']['rekindle']);
        // a new day, a bit more
        RelDynBonds::onEvalItem('Rowan', $this->warm(1.0, ['forgiveness']), $c, self::T0 + self::DAY);
        $this->assertGreaterThan(0.3, $c[RelDynBonds::KEY]['breakup']['rekindle']);
        // a setback takes it back, an unrelated exchange of little weight does nothing
        $before = $c[RelDynBonds::KEY]['breakup']['rekindle'];
        RelDynBonds::onEvalItem('Rowan', ['npc' => 'Rowan', 'positive_interaction' => false, 'significance' => 0.8, 'tags' => []], $c, self::T0 + 2 * self::DAY);
        $this->assertLessThan($before, $c[RelDynBonds::KEY]['breakup']['rekindle']);
        $this->assertSame(0.0, RelDynBonds::onEvalItem('Rowan', $this->warm(0.1), $c, self::T0 + 2 * self::DAY), 'little weight');
    }

    public function testWhoTheNpcIsAndHowHardItWasSetThePaceOfTheWayBack(): void
    {
        $pace = function (array $axes, string $cause) {
            [$r, $d] = $this->ends($this->npc($axes, 40.0, 45.0, ['passion' => 60.0]), $cause);
            if ($r['fork'] !== 'conflicted') return null;
            return RelDynBonds::onEvalItem('Rowan', $this->warm(1.0), $d, self::T0);
        };
        $anxious = $pace(self::ANXIOUS, 'standards');
        $this->assertNotNull($anxious);
        $fearful = $pace(self::FEARFUL, 'standards');
        $this->assertNotNull($fearful);
        $this->assertGreaterThan($fearful, $anxious * 1.0001 + 0.0, 'attachment sets the pace');
        $this->assertGreaterThan(0.05, $anxious);
        // an ending that was harder is a slower way back
        $soft = $this->conflicted();
        $hard = $this->conflicted();
        $hard[RelDynBonds::KEY]['breakup']['hardness'] = 0.8;
        $soft[RelDynBonds::KEY]['breakup']['hardness'] = 0.3;
        $this->assertGreaterThan(RelDynBonds::onEvalItem('Rowan', $this->warm(1.0), $hard, self::T0), RelDynBonds::onEvalItem('Rowan', $this->warm(1.0), $soft, self::T0));
    }

    public function testAtFullProgressWithAngerGoneAndTrustStandingTheNpcLetsItStartAgainOnTheRomanceLadder(): void
    {
        $d = $this->conflicted();
        $d[RelDynBonds::KEY]['breakup']['rekindle'] = 1.0;
        $this->writes = [];
        // resentment still too high: ready, but not yet
        $d['dimensions']['resentment']['x'] = 50.0;
        $this->at(self::T0);
        $this->assertSame([], RelDynBonds::advance('Rowan', $d, self::T0)['events'] ?? []);
        $this->assertSame([], $this->writes);
        $this->assertSame('ex', $d['_core_rel_type']);
        // anger gone: core goes ex -> crush and the normal ladder carries on
        $d['dimensions']['resentment']['x'] = 20.0;
        $r = RelDynBonds::advance('Rowan', $d, self::T0 + self::HOUR);
        $this->assertContains('rekindled', $r['events']);
        $this->assertSame([['Rowan', 'crush']], array_map(fn($w) => array_slice($w, 0, 2), $this->writes));
        $this->assertSame('ex', $this->writes[0][3], 'from ex');
        $this->assertSame('crush', $d['_core_rel_type']);
        $this->assertSame(1, RelDynRomance::rung($d['_core_rel_type']), 'a rung of the romance ladder: not as if nothing happened');
        $this->assertSame('rekindled', $d[RelDynBonds::KEY]['breakup']['status']);
        $this->assertSame([], RelDynBonds::kinds($d));
        $said = array_column(RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida')['lines'], 'key');
        $this->assertSame('rekindled', end($said), 'said to the player\'s face once');
        // trust must stand too
        $e = $this->conflicted();
        $e[RelDynBonds::KEY]['breakup']['rekindle'] = 1.0;
        $e['dimensions']['trust']['x'] = 20.0;
        $this->writes = [];
        $this->assertNotContains('rekindled', RelDynBonds::advance('Rowan', $e, self::T0)['events']);
        $this->assertSame([], $this->writes);
    }

    public function testResentmentHardensTheSoftStateIntoAnExAndAHardExThawsIntoAFriendOnlyForOneWhoCan(): void
    {
        $d = $this->conflicted();
        $this->at(self::T0);
        $d['dimensions']['resentment']['x'] = 65.0;
        $r = RelDynBonds::advance('Rowan', $d, self::T0);
        $this->assertContains('hardened', $r['events']);
        $this->assertSame('ex', $d[RelDynBonds::KEY]['breakup']['fork']);
        $this->assertSame([], RelDynBonds::kinds($d), 'the door is shut');
        $this->assertSame('ex', $d['_core_rel_type']);
        $said = array_column(RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida')['lines'], 'key');
        $this->assertSame('hardened', end($said));
        // a hard ex: the way to a friendship is slower, and needs the anger to be gone and a maturity to do it
        [$r, $ex] = $this->ends($this->npc(self::AVOIDANT, 75.0, 45.0), 'pursued');
        $this->assertSame('ex', $r['fork']);
        $p = RelDynBonds::onEvalItem('Rowan', $this->warm(1.0), $ex, self::T0);
        $this->assertGreaterThan(0.0, $p);
        $this->assertLessThan(0.1, $p, 'slow');
        $this->assertSame(0.0, $ex[RelDynBonds::KEY]['breakup']['rekindle'], 'an ex is not rekindled; it thaws');
        $ex[RelDynBonds::KEY]['breakup']['thaw'] = 1.0;
        $ex['dimensions']['resentment']['x'] = 10.0;
        $ex['dimensions']['trust']['x'] = 55.0;
        $this->writes = [];
        $r = RelDynBonds::advance('Rowan', $ex, self::T0 + self::HOUR);
        $this->assertContains('thawed', $r['events']);
        $this->assertSame('platonic', $ex['_core_rel_type']);
        $this->assertSame('friends', $ex[RelDynBonds::KEY]['breakup']['fork']);
        $raw = $this->npc(self::AVOIDANT, 20.0, 45.0);
        [, $rawEx] = $this->ends($raw, 'pursued');
        $rawEx[RelDynBonds::KEY]['breakup']['thaw'] = 1.0;
        $rawEx['dimensions']['resentment']['x'] = 10.0;
        $rawEx['dimensions']['trust']['x'] = 55.0;
        $this->assertNotContains('thawed', RelDynBonds::advance('Rowan', $rawEx, self::T0 + self::HOUR)['events'], 'not everyone can');
        // a new romance ends the old ending
        $again = $this->conflicted();
        $again['_core_rel_type'] = 'crush';
        RelDynBonds::advance('Rowan', $again, self::T0);
        $this->assertSame('over', $again[RelDynBonds::KEY]['breakup']['status']);
        $this->assertSame([], RelDynBonds::kinds($again));
    }

    // =====================================================================
    // the infidelity loop
    // =====================================================================

    /** A neglected partner: passion 60, the player away $days days, a suitor with $interest. */
    private function neglected(array $axes, float $maturity, float $days, float $interest, array $o = []): array
    {
        $d = $this->npc($axes, $maturity, 60.0, $o + ['passion' => 60.0, 'contact_days_ago' => $days]);
        if ($interest > 0.0) {
            $this->suitor($d, 'Mikael', $interest);
            $d['_test_suitor_interest'] = $interest;   // Mikael's attention goes on: the loops below keep the ledger at it
        }
        return $d;
    }

    /** The loop over $hours from T0 in 6-hour steps; returns the stages seen in order. */
    private function loop(array &$d, float $hours, float $step = 3.0): array
    {
        $stages = [];
        $t = self::T0;
        for ($h = 0.0; $h <= $hours; $h += $step) {
            $t = self::T0 + $h * self::HOUR;
            $this->at($t);
            if (isset($d['_test_suitor_interest'])) $this->suitor($d, 'Mikael', $d['_test_suitor_interest'], $t);
            $r = RelDynBonds::advance('Rowan', $d, $t);
            $stage = RelDynBonds::infidelityStage($d);
            if ($stages === [] || end($stages) !== $stage) $stages[] = $stage;
        }
        return $stages;
    }

    public function testNeglectAloneCutsThePullButWithoutASuitorNobodyStraysAndAttentionedLoyalNpcsHoldTheirPlace(): void
    {
        $fresh = $this->npc(self::SECURE, 80.0, 60.0, ['passion' => 60.0, 'contact_days_ago' => 1]);
        $this->suitor($fresh, 'Mikael', 60.0);
        $this->at(self::T0);
        $t = RelDynBonds::infidelityTarget($fresh, self::T0);
        $this->assertLessThan(0.05, $t['cut'], 'a partner who was just here: the pull is whole');
        $this->assertLessThan(0.1, $t['target'], 'and nothing pulls elsewhere');
        $alone = $this->neglected(self::SECURE, 80.0, 40, 0.0);
        $t = RelDynBonds::infidelityTarget($alone, self::T0);
        $this->assertGreaterThan(0.8, $t['cut'], 'weeks of neglect cut the pull');
        $this->assertEqualsWithDelta(0.35 * $t['opening'], $t['target'], 0.02, 'without anyone to be drawn to: a loneliness, not an affair');
        $this->assertLessThan($t['lines']['strayed'], $t['target']);
        $this->assertNull(RelDynBonds::infidelityStage($alone));
    }

    public function testNeglectAndAnAttentiveSuitorWalkAnNpcThroughDriftingSeekingAndStrayedByTheirOwnLines(): void
    {
        // the player left four days ago and does not come back: the neglect begins past the grace and the pull is cut a little more each day
        $loose = $this->neglected(self::ANXIOUS, 30.0, 4, 60.0, ['traits' => ['D' => 0.15, 'Po' => 0.3]]);
        $stages = $this->loop($loose, 24 * 60);
        $this->assertSame([null, 'drifting', 'seeking', 'strayed', null], array_slice($stages, 0, 5), 'nothing, then through the stages, and the ending clears the loop: ' . json_encode($stages));
        $this->assertNotEmpty($loose[RelDynBonds::KEY]['breakup'], 'a line crossed ends in an ending');
        $this->assertSame('Mikael', $loose[RelDynBonds::KEY]['infidelity']['with'] ?? ($loose[RelDynBonds::KEY]['breakup']['with'] ?? null));
        // the loyal and mature need more of everything: the same weeks of neglect and suitor, not the same place
        $loyal = $this->neglected(self::SECURE, 85.0, 4, 60.0, ['traits' => ['D' => 0.9, 'Po' => 0.7], 'preference' => 'monogamous']);
        $loyalStages = $this->loop($loyal, 24 * 25);
        $this->assertNotContains('strayed', $loyalStages, json_encode($loyalStages));
        $this->assertContains('drifting', $loyalStages);
        $a = RelDynBonds::infidelityTarget($loose, self::T0);
        $b = RelDynBonds::infidelityTarget($loyal, self::T0);
        $this->assertGreaterThan($a['restraint'], $b['restraint']);
        $this->assertGreaterThan($a['lines']['strayed'], $b['lines']['strayed'], 'restraint lifts the line');
        $this->assertLessThanOrEqual(0.98, $b['lines']['strayed'], 'never a wall');
        // no one is immune: a much longer neglect and a suitor of full interest takes even the loyal to the line
        $far = $this->neglected(self::SECURE, 85.0, 200, 70.0, ['traits' => ['D' => 0.9, 'Po' => 0.7], 'preference' => 'monogamous']);
        $f = RelDynBonds::infidelityTarget($far, self::T0);
        $this->assertGreaterThanOrEqual($f['lines']['strayed'], $f['target'], 'half a year of neglect and a suitor who never stopped: even the loyal are at the line');
    }

    public function testALongAbsenceIsWalkedThroughInDaysNotJumpedOverAndTheCalendarScanRunsItForNpcsNotTalkedTo(): void
    {
        $make = function (): array {
            $d = $this->neglected(self::ANXIOUS, 30.0, 3, 0.0, ['traits' => ['D' => 0.15, 'Po' => 0.3]]);
            $this->at(self::T0);
            RelDynBonds::advance('Rowan', $d, self::T0);
            $this->suitor($d, 'Mikael', 100.0, self::T0 + 39 * self::DAY);   // Mikael's attention went on; the ledger was last written the day before
            return $d;
        };
        // one turn after forty days away: the days in between count
        $d = $make();
        $t = self::T0 + 40 * self::DAY;
        $this->at($t);
        $r = RelDynBonds::advance('Rowan', $d, $t);
        $ended = !empty($d[RelDynBonds::KEY]['breakup']);
        $this->assertTrue($ended || in_array(RelDynBonds::infidelityStage($d), ['seeking', 'strayed'], true), 'a single turn after weeks away is not "nothing has happened"');
        // the calendar scan, with no turn of the NPC's own, does the same
        $e = $make();
        $out = RelDynBonds::calendarTick('Rowan', $e, $t);
        $this->assertTrue($out['changed']);
        $this->assertSame(!empty($d[RelDynBonds::KEY]['breakup']), !empty($e[RelDynBonds::KEY]['breakup']), 'the same place by either road');
        $this->assertSame(RelDynBonds::infidelityStage($d), RelDynBonds::infidelityStage($e));
        // an ending in the calendar scan that costs affinity says so, so the scan commits it to core
        if (!empty($e[RelDynBonds::KEY]['breakup']) && ($e[RelDynBonds::KEY]['breakup']['fork'] ?? '') !== 'friends') {
            $this->assertTrue($out['affinity']);
        }
        // nothing to tick for a bond that is no romance
        $friend = $this->npc(self::SECURE, 75.0, 60.0, ['core' => 'platonic']);
        $this->assertFalse(RelDynBonds::calendarTick('Rowan', $friend, $t)['changed']);
    }

    public function testTheLoopNeedsARealSuitorHeldForAWhileBeforeALineIsCrossed(): void
    {
        $d = $this->neglected(self::ANXIOUS, 30.0, 60, 25.0, ['traits' => ['D' => 0.15]]);
        $stages = $this->loop($d, 24 * 20);
        $this->assertNotContains('strayed', $stages, 'a suitor of little interest is a flirtation');
        $this->assertContains('seeking', $stages);
        $d = $this->neglected(self::ANXIOUS, 30.0, 60, 60.0, ['traits' => ['D' => 0.15]]);
        $firstSeeking = null;
        $firstStrayed = null;
        for ($h = 0.0; $h <= 24 * 6; $h += 1.0) {
            $t = self::T0 + $h * self::HOUR;
            $this->at($t);
            if (isset($d['_test_suitor_interest'])) $this->suitor($d, 'Mikael', $d['_test_suitor_interest'], $t);
            RelDynBonds::advance('Rowan', $d, $t);
            $stage = RelDynBonds::infidelityStage($d);
            if ($firstSeeking === null && in_array($stage, ['seeking', 'strayed'], true)) $firstSeeking = $h;
            if ($firstStrayed === null && $stage === 'strayed') $firstStrayed = $h;
        }
        $this->assertNotNull($firstStrayed);
        $this->assertGreaterThanOrEqual(12.0, $firstStrayed - $firstSeeking, 'held for at least strayed_hold_game_hours (12) after the pressure got there');
    }

    public function testThePullTowardSomeoneElseFadesPassionAndThatClosesTheLoopBoundedByAFloor(): void
    {
        $d = $this->neglected(self::ANXIOUS, 30.0, 40, 60.0, ['traits' => ['D' => 0.15]]);
        $p0 = RelationshipDynamics::getPassion($d);
        $this->loop($d, 24 * 2);
        $p1 = RelationshipDynamics::getPassion($d);
        $this->assertLessThan($p0, $p1, 'attention elsewhere: less for the player');
        $this->assertGreaterThanOrEqual(12.0 - 1e-6, $p1, 'bounded by the floor');
        // the same, without anyone to leave for (so the loop does not end): it settles at the floor and goes no lower
        $p = $this->neglected(self::ANXIOUS, 30.0, 40, 60.0, ['traits' => ['D' => 0.15], 'preference' => 'polyamorous']);
        $this->loop($p, 24 * 40);
        $this->assertGreaterThanOrEqual(12.0 - 1e-6, RelationshipDynamics::getPassion($p));
        // and the loop closes on itself: a weaker passion is a hollower pull, a wider opening
        $strong = $this->neglected(self::ANXIOUS, 30.0, 12, 0.0, ['traits' => ['D' => 0.15]]);
        $weak = $this->neglected(self::ANXIOUS, 30.0, 12, 0.0, ['traits' => ['D' => 0.15], 'passion' => 22.0]);
        $this->assertGreaterThan(RelDynBonds::infidelityTarget($strong, self::T0)['opening'], RelDynBonds::infidelityTarget($weak, self::T0)['opening']);
    }

    public function testContactAndFulfillmentBringThePressureDownBeforeALineIsCrossed(): void
    {
        // the player left four days ago and comes back before a line is crossed
        $d = $this->neglected(self::ANXIOUS, 30.0, 4, 60.0, ['traits' => ['D' => 0.15]]);
        $t = self::T0;
        for ($h = 0.0; $h <= 24 * 12; $h += 3.0) {
            $t = self::T0 + $h * self::HOUR;
            $this->at($t);
            if (isset($d['_test_suitor_interest'])) $this->suitor($d, 'Mikael', $d['_test_suitor_interest'], $t);
            RelDynBonds::advance('Rowan', $d, $t);
            if (RelDynBonds::infidelityStage($d) === 'seeking') break;
        }
        $this->assertSame('seeking', RelDynBonds::infidelityStage($d), 'neglect had them looking');
        $before = $d[RelDynBonds::KEY]['infidelity']['pressure'];
        // the player is back and stays: contact, and the pull is whole again
        $d['_last_contact_gamets'] = $t;
        for ($h = 3.0; $h <= 24 * 6; $h += 3.0) {
            $this->at($t + $h * self::HOUR);
            $d['_last_contact_gamets'] = $t + $h * self::HOUR;
            RelDynBonds::advance('Rowan', $d, $t + $h * self::HOUR);
        }
        $this->assertLessThan($before, $d[RelDynBonds::KEY]['infidelity']['pressure'], 'repaired by being there');
        $this->assertNull(RelDynBonds::infidelityStage($d), 'the pull toward someone else is gone');
    }

    public function testALineCrossedWeighsOnTheNpcByWhoTheyAreAndEndsTheirWay(): void
    {
        // [the NPC afterwards, the game days until the romance ended (null: not within 90 days), the days the line had been crossed]
        $cross = function (array $d): array {
            $this->writes = [];
            $t = self::T0;
            $ended = null;
            for ($h = 0.0; $h <= 24 * 90; $h += 6.0) {
                $t = self::T0 + $h * self::HOUR;
                $this->at($t);
                if (isset($d['_test_suitor_interest'])) $this->suitor($d, 'Mikael', $d['_test_suitor_interest'], $t);
                RelDynBonds::advance('Rowan', $d, $t);
                if (!empty($d[RelDynBonds::KEY]['breakup'])) { $ended = $h / 24.0; break; }
            }
            return [$d, $ended];
        };
        $loose = ['D' => 0.15, 'Po' => 0.3];
        // the mature and loyal-ish confess, soon
        [$mature, $matureDays] = $cross($this->neglected(self::SECURE, 85.0, 150, 70.0, ['traits' => ['D' => 0.75, 'Po' => 0.5]]));
        // the avoidant leave; the fearful keep both for a long time
        [$avoidant, $avoidantDays] = $cross($this->neglected(self::AVOIDANT, 40.0, 60, 70.0, ['traits' => $loose]));
        [$fearful, $fearfulDays] = $cross($this->neglected(self::FEARFUL, 40.0, 60, 70.0, ['traits' => $loose]));
        $this->assertSame('infidelity_confessed', $mature[RelDynBonds::KEY]['breakup']['cause'] ?? null, 'the mature confess');
        $this->assertSame('left_for_other', $avoidant[RelDynBonds::KEY]['breakup']['cause'] ?? null, 'the avoidant leave for the other');
        $this->assertSame('Mikael', $avoidant[RelDynBonds::KEY]['breakup']['with'] ?? null);
        $this->assertSame('left_for_other', $fearful[RelDynBonds::KEY]['breakup']['cause'] ?? null, 'the fearful leave in the end, no one is immune');
        $this->assertGreaterThan($avoidantDays + 10.0, $fearfulDays, 'but only after keeping both for a long while');
        $this->assertSame(['ex', 'ex'], [$avoidant[RelDynBonds::KEY]['breakup']['fork'], $fearful[RelDynBonds::KEY]['breakup']['fork']], 'leaving for someone else is a hard ending');
        // what each carries meanwhile
        $guilty = $this->neglected(self::SECURE, 85.0, 150, 70.0, ['traits' => ['D' => 0.9]]);
        $this->assertSame('guilt', RelDynBonds::strayedStyle($guilty));
        $this->assertSame('justified', RelDynBonds::strayedStyle($this->neglected(self::ANXIOUS, 30.0, 60, 70.0, ['traits' => ['D' => 0.1, 'Po' => 0.1]])));
        $this->assertSame('conceal', RelDynBonds::strayedStyle($this->neglected(self::FEARFUL, 30.0, 60, 70.0)));
        $this->assertSame(['kind' => 'confess', 'game_days' => 1.0], RelDynBonds::resolution($guilty));
        $this->assertSame('both', RelDynBonds::resolution($this->neglected(self::FEARFUL, 30.0, 60, 70.0))['kind']);
        $this->assertSame('leave', RelDynBonds::resolution($this->neglected(self::AVOIDANT, 30.0, 60, 70.0))['kind']);
        $this->assertSame('confess', RelDynBonds::resolution($this->neglected(self::ANXIOUS, 30.0, 60, 70.0))['kind']);
        $this->assertNotNull($matureDays);
    }

    public function testTheWeightOfALineCrossedIsGuiltForTheLoyalAndAGrievanceForTheRest(): void
    {
        $at = function (array $d): array {
            $before = ['rs' => $d['dimensions']['resentment_self']['x'] ?? 0.0, 'r' => $d['dimensions']['resentment']['x'] ?? 0.0];
            $t = self::T0;
            for ($h = 0.0; $h <= 24 * 12; $h += 6.0) {
                $t = self::T0 + $h * self::HOUR;
                $this->at($t);
                if (isset($d['_test_suitor_interest'])) $this->suitor($d, 'Mikael', $d['_test_suitor_interest'], $t);
                RelDynBonds::advance('Rowan', $d, $t);
                if (!empty($d[RelDynBonds::KEY]['infidelity']['line_crossed'])) break;
            }
            return [floatval($d['dimensions']['resentment_self']['x'] ?? 0.0) - $before['rs'], $d['dimensions']['resentment']['x'] - $before['r'], !empty($d[RelDynBonds::KEY]['infidelity']['line_crossed'])];
        };
        [$guilt, $grievance, $crossed] = $at($this->neglected(self::SECURE, 85.0, 150, 70.0, ['traits' => ['D' => 0.85]]));
        $this->assertTrue($crossed);
        [$guilt2, $grievance2, $crossed2] = $at($this->neglected(self::ANXIOUS, 30.0, 60, 70.0, ['traits' => ['D' => 0.1, 'Po' => 0.1]]));
        $this->assertTrue($crossed2);
        $this->assertGreaterThan($guilt2, $guilt, 'the loyal one is eaten by it');
        $this->assertGreaterThan($grievance, $grievance2, 'the other builds a case');
    }

    public function testAnNpcWhosePreferenceDoesNotExpectFidelityIsOpenNotUnfaithfulAndNothingEnds(): void
    {
        $poly = $this->neglected(self::SECURE, 70.0, 60, 70.0, ['preference' => 'polyamorous', 'traits' => ['D' => 0.2]]);
        $this->assertLessThan(0.3, RelDynBonds::expectation($poly));
        $t = self::T0;
        for ($h = 0.0; $h <= 24 * 30; $h += 6.0) {
            $t = self::T0 + $h * self::HOUR;
            $this->at($t);
            $this->suitor($poly, 'Mikael', 70.0, $t);
            RelDynBonds::advance('Rowan', $poly, $t);
        }
        $this->assertSame('strayed', RelDynBonds::infidelityStage($poly), 'they do see someone else');
        $this->assertArrayNotHasKey('breakup', $poly[RelDynBonds::KEY], 'and nothing ends: it is not infidelity');
        $this->assertSame([], $this->writes);
        $lines = array_column(RelDynBonds::feltLines($poly, 'Rowan', 'Kaida'), 'text', 'key');
        $this->assertArrayHasKey('strayed_open', $lines);
        $this->assertStringContainsString('not hiding', $lines['strayed_open']);
        // an uncommitted NPC expects less of the bond, and the formal rung weighs the rest
        $this->assertLessThan(RelDynBonds::expectation($this->npc()), RelDynBonds::expectation($this->npc(self::SECURE, 75.0, 60.0, ['preference' => 'uncommitted'])));
        $informal = $this->npc();
        $formal = $this->npc();
        $formal[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'committed'];
        $this->assertGreaterThan(RelDynBonds::fidelityWeight($informal), RelDynBonds::fidelityWeight($formal));
        $this->assertEqualsWithDelta(1.0 * 0.9, RelDynBonds::fidelityWeight($formal), 1e-9);
    }

    public function testAJealousNpcIsInvestedAndStraysLessAndTheSuitorLineIsWarmOnlyForTheOneTheyAreDrawnTo(): void
    {
        $calm = $this->neglected(self::ANXIOUS, 30.0, 40, 60.0);
        $jealous = $this->neglected(self::ANXIOUS, 30.0, 40, 60.0, ['dims' => ['jealousy' => 70.0]]);
        $this->assertGreaterThan(RelDynBonds::infidelityTarget($jealous, self::T0)['target'], RelDynBonds::infidelityTarget($calm, self::T0)['target']);
        // the NPC-NPC exchange: a warm reply for the suitor they are drawn to, nothing different for anyone else
        $d = $this->neglected(self::ANXIOUS, 30.0, 60, 70.0, ['traits' => ['D' => 0.15]]);
        $this->loop($d, 36);
        $this->assertContains(RelDynBonds::infidelityStage($d), ['seeking', 'strayed']);
        $line = RelDynBonds::suitorLine($d, 'Rowan', 'Mikael', 'Kaida');
        $this->assertNotNull($line);
        $this->assertStringContainsString('Mikael', $line);
        $this->assertDoesNotMatchRegularExpression('/\d|\b(he|she|his|her|him)\b/i', $line);
        $this->assertNull(RelDynBonds::suitorLine($d, 'Rowan', 'Ulfric', 'Kaida'), 'someone else\'s attention is not this');
        $this->assertNull(RelDynBonds::suitorLine($this->npc(), 'Rowan', 'Mikael', 'Kaida'));
    }

    // =====================================================================
    // the oath
    // =====================================================================

    public function testAnOathHoldsDutyWhateverTheNpcFeelsUntilRespectIsGoneAndMendsWhenItReturns(): void
    {
        $bad = ['resentment' => 85.0, 'respect' => 50.0, 'trust' => 15.0];
        $sworn = $this->npc(self::SECURE, 60.0, 15.0, ['core' => 'servant', 'aff' => -30.0, 'dims' => $bad]);
        $free = $this->npc(self::SECURE, 60.0, 15.0, ['core' => 'neutral', 'aff' => -30.0, 'dims' => $bad]);
        $order = ['compliant' => 0, 'resistant' => 1, 'refusing' => 2, 'walkaway' => 3];
        $a = RelationshipDynamics::evaluateAutonomyState($sworn, 'Stoic');
        $b = RelationshipDynamics::evaluateAutonomyState($free, 'Stoic');
        $this->assertContains($b['state'], ['refusing', 'walkaway'], 'without the oath this NPC refuses');
        $this->assertSame('resistant', $a['state'], 'sworn: reluctant, but the duty holds');
        $this->assertFalse($a['walkaway_due']);
        $this->assertSame([], RelationshipDynamics::getDeniedActions($a['state']), 'no action is taken off the list');
        $this->assertTrue(RelDynBonds::dutyHolds($sworn));
        // respect lost: the strain builds, and at 1 the oath breaks
        $sworn['dimensions']['respect']['x'] = 25.0;
        $this->assertGreaterThan(0.3, RelDynBonds::oathStrain($sworn));
        $this->assertLessThan(1.0, RelDynBonds::oathStrain($sworn));
        $this->at(self::T0);
        $this->assertNotContains('oath_broken', RelDynBonds::advance('Rowan', $sworn, self::T0)['events']);
        $sworn['dimensions']['respect']['x'] = 3.0;
        $r = RelDynBonds::advance('Rowan', $sworn, self::T0 + self::HOUR);
        $this->assertContains('oath_broken', $r['events']);
        $this->assertFalse(RelDynBonds::dutyHolds($sworn));
        $this->assertSame([], RelDynBonds::kinds($sworn));
        $this->assertContains(RelationshipDynamics::evaluateAutonomyState($sworn, 'Stoic')['state'], ['refusing', 'walkaway'], 'forsworn: the cap is gone');
        $this->assertSame('oath_broken', RelDynBonds::takeFeltLines($sworn, 'Rowan', 'Kaida')['lines'][0]['key']);
        // respect back: it mends
        $sworn['dimensions']['respect']['x'] = 70.0;
        $r = RelDynBonds::advance('Rowan', $sworn, self::T0 + 2 * self::HOUR);
        $this->assertContains('oath_renewed', $r['events']);
        $this->assertTrue(RelDynBonds::dutyHolds($sworn));
    }

    public function testABetrayalStrainsTheOathAndAPositiveExchangeRepairsIt(): void
    {
        $d = $this->npc(self::SECURE, 60.0, 50.0, ['core' => 'servant']);
        $this->assertEqualsWithDelta(0.0, RelDynBonds::oathStrain($d), 1e-9);
        RelDynBonds::onEvalItem('Rowan', ['npc' => 'Rowan', 'tags' => ['betrayal'], 'significance' => 0.9, 'positive_interaction' => false], $d, self::T0);
        $s1 = RelDynBonds::oathStrain($d);
        $this->assertGreaterThan(0.3, $s1);
        RelDynBonds::onEvalItem('Rowan', ['npc' => 'Rowan', 'tags' => [], 'significance' => 0.8, 'positive_interaction' => true], $d, self::T0);
        $this->assertLessThan($s1, RelDynBonds::oathStrain($d));
        RelDynBonds::onEvalItem('Rowan', ['npc' => 'Rowan', 'tags' => ['betrayal'], 'significance' => 1.0, 'positive_interaction' => false], $d, self::T0);
        RelDynBonds::onEvalItem('Rowan', ['npc' => 'Rowan', 'tags' => ['betrayal'], 'significance' => 1.0, 'positive_interaction' => false], $d, self::T0);
        $this->at(self::T0);
        $this->assertContains('oath_broken', RelDynBonds::advance('Rowan', $d, self::T0)['events'], 'betrayal after betrayal breaks it');
    }

    public function testAnOathBoundNpcsWordsAreColdOrWarmByHowTheyFeelAndNeverAScoreOrAPronoun(): void
    {
        $cold = $this->npc(self::SECURE, 60.0, 50.0, ['core' => 'servant', 'aff' => 5.0]);
        $warm = $this->npc(self::SECURE, 60.0, 50.0, ['core' => 'servant', 'aff' => 70.0]);
        $c = array_column(RelDynBonds::feltLines($cold, 'Rowan', 'Kaida'), 'text', 'key');
        $w = array_column(RelDynBonds::feltLines($warm, 'Rowan', 'Kaida'), 'text', 'key');
        $this->assertArrayHasKey('sworn_cold', $c);
        $this->assertArrayHasKey('sworn_warm', $w);
        foreach ([$c['sworn_cold'], $w['sworn_warm']] as $text) {
            $this->assertStringContainsString('Rowan', $text);
            $this->assertDoesNotMatchRegularExpression('/\d|\b(he|she|his|her|him|hers)\b/i', $text);
        }
        $this->assertSame([], RelDynBonds::feltLines($warm, 'Rowan', 'Kaida', 0));
        // a faction that binds needs the party; no database reads nothing and invents nothing
        $d = $this->npc(self::SECURE, 60.0, 50.0, ['core' => 'neutral']);
        $this->at(self::T0);
        RelDynBonds::advance('Rowan', $d, self::T0);
        $this->assertSame([], RelDynBonds::kinds($d));
    }

    // =====================================================================
    // words, numbers, settings
    // =====================================================================

    public function testEveryEndingIsSaidOnceToThePlayerInTheNpcsOwnWordsWithTheCauseAndNoNumberOrPronoun(): void
    {
        $all = [];
        foreach (['mature' => 80.0, 'raw' => 20.0] as $how => $m) {
            foreach (['friends' => [self::SECURE, 'standards'], 'conflicted' => [self::ANXIOUS, 'standards'], 'ex' => [self::AVOIDANT, 'pursued']] as $fork => [$axes, $cause]) {
                // the cooled longing and the goodwill that are left, for the NPC who can part as friends; a raw NPC with them parts as exes
                $cooled = $fork === 'friends' ? ['passion' => 5.0, 'aff' => 25.0, 'dims' => ['resentment' => 2.0]] : [];
                if ($how === 'raw' && $fork === 'friends') continue;
                $d = $this->npc($axes, $m, $fork === 'friends' ? 55.0 : 45.0, $cooled);
                $r = RelDynBonds::breakup('Rowan', $d, $cause, 'npc', self::T0);
                $this->assertSame($fork, $r['fork'], "{$how} {$fork}");
                $this->assertSame([], RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida', false)['lines'], 'only when the player is speaking to them');
                $lines = RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida', true)['lines'];
                $this->assertCount(1, $lines);
                $this->assertSame("breakup_{$fork}", $lines[0]['key']);
                $this->assertTrue($lines[0]['must']);
                $text = $lines[0]['text'];
                $this->assertStringContainsString('Rowan', $text);
                $this->assertStringContainsString('Kaida', $text);
                $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$how} {$fork}: feelings, never numbers");
                $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him|himself|herself)\b/i', $text, "{$how} {$fork}: no hard-coded pronoun");
                $this->assertDoesNotMatchRegularExpression('/\{[A-Z_]+\}/', $text, 'every var filled');
                $this->assertSame([], RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida', true)['lines'], 'said once');
                $all[] = $text;
            }
        }
        $this->assertCount(5, array_unique($all), 'five endings, five ways of saying it');
        // the cause is in the words
        $d = $this->npc(self::SECURE, 80.0, 45.0);
        RelDynBonds::breakup('Rowan', $d, 'standards', 'npc', self::T0);
        $this->assertStringContainsString('deserves better', RelDynBonds::takeFeltLines($d, 'Rowan', 'Kaida')['lines'][0]['text']);
    }

    public function testTheStandingLinesOfAnEndedRomanceAndTheStrayingAreFeelingsToo(): void
    {
        $d = $this->npc(self::ANXIOUS, 40.0, 45.0);
        RelDynBonds::breakup('Rowan', $d, 'standards', 'npc', self::T0);
        $soft = array_column(RelDynBonds::feltLines($d, 'Rowan', 'Kaida'), 'text', 'key');
        $this->assertArrayHasKey('conflicted', $soft);
        $e = $this->npc(self::AVOIDANT, 75.0, 45.0);
        RelDynBonds::breakup('Rowan', $e, 'pursued', 'npc', self::T0);
        $ex = array_column(RelDynBonds::feltLines($e, 'Rowan', 'Kaida'), 'text', 'key');
        $this->assertArrayHasKey('ex', $ex);
        $inf = $this->neglected(self::ANXIOUS, 30.0, 60, 70.0, ['traits' => ['D' => 0.1, 'Po' => 0.1]]);
        $this->loop($inf, 24 * 4);
        $line = RelDynBonds::feltLines($inf, 'Rowan', 'Kaida');
        $this->assertNotEmpty($line);
        foreach (array_merge($soft, $ex, array_column($line, 'text', 'key')) as $key => $text) {
            $this->assertStringContainsString('Rowan', $text, $key);
            $this->assertDoesNotMatchRegularExpression('/\d|\b(he|she|his|her|hers|him|himself|herself)\b/i', $text, $key);
            $this->assertDoesNotMatchRegularExpression('/\{[A-Z_]+\}/', $text, $key);
        }
    }

    public function testJevCarriesTheKindsTheEndingAndTheLoop(): void
    {
        $d = $this->npc(self::ANXIOUS, 40.0, 45.0);
        $d[RelDynBonds::KEY] = ['v' => 1, 'kind' => 'committed', 'romantic_since' => self::T0 - 10 * self::DAY, 'committed_since' => self::T0 - 3 * self::DAY];
        $j = RelDynBonds::jev($d, self::T0);
        $this->assertSame(['committed'], $j['kinds']);
        $this->assertSame('bonded', $j['bond_type']);
        $this->assertEqualsWithDelta(10.0, $j['committed']['held_game_days'], 0.01);
        $this->assertNull($j['breakup']);
        RelDynBonds::breakup('Rowan', $d, 'standards', 'npc', self::T0);
        $j = RelDynBonds::jev($d, self::T0);
        $this->assertSame('conflicted', $j['breakup']['fork']);
        $this->assertSame('conflicted', $j['bond_type']);
        $this->assertSame(['conflicted'], $j['kinds']);
        $this->assertNull($j['infidelity']['stage']);
        $this->assertEqualsWithDelta(0.9, $j['infidelity']['expectation'], 1e-9);
    }

    public function testTheSettingsMergePerEntryAndTheDefaultsAreWhatTheDocSays(): void
    {
        $c = RelDynBonds::config();
        $this->assertEqualsWithDelta(7.0, $c['committed']['base_game_days'], 1e-9);
        $this->assertSame('crush', $c['rekindle']['to']);
        $this->assertSame('platonic', $c['rekindle']['thaw']['to']);
        $this->config(['rekindle' => ['step' => 0.5, 'thaw' => ['step' => 0.2]], 'infidelity' => ['stages' => ['strayed' => 0.5]], 'breakup' => ['causes' => ['standards' => 0.9]]]);
        $c = RelDynBonds::config();
        $this->assertEqualsWithDelta(0.5, $c['rekindle']['step'], 1e-9);
        $this->assertEqualsWithDelta(0.3, $c['rekindle']['daily_cap'], 1e-9, 'the rest of the table stays');
        $this->assertEqualsWithDelta(0.2, $c['rekindle']['thaw']['step'], 1e-9);
        $this->assertEqualsWithDelta(30.0, $c['rekindle']['thaw']['resentment_max'], 1e-9, 'nested tables merge per entry');
        $this->assertEqualsWithDelta(0.5, $c['infidelity']['stages']['strayed'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $c['infidelity']['stages']['drifting'], 1e-9);
        $this->assertEqualsWithDelta(0.9, $c['breakup']['causes']['standards'], 1e-9);
        $this->assertEqualsWithDelta(0.65, $c['breakup']['causes']['resentment'], 1e-9);
        // a harder 'standards' ending: the mature secure one is no longer friends
        $this->assertNotSame('friends', RelDynBonds::forkScores($this->npc(self::SECURE, 75.0, 45.0), 'standards')['fork']);
    }
}

/** Just enough of `sql` for a stored RelDyn config row (the stored settings over the defaults). */
final class RelDynWBondsConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode(array_merge(RelationshipDynamics::defaultConfig(), $this->config))] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}
