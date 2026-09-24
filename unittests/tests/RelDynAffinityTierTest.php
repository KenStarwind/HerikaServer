<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * RelDyn tiers on the core affinity scale.
 *
 * Core relationships.Player.aff (-100..+100) is the source of truth; dimensions.affinity.x
 * is only a mirror, x = (aff + 100) / 2. Every RelDyn tier decision (relationship tier,
 * context tier, high-water mark, friendzone) reads affinity through getCoreAffinity() and
 * maps it through getCurrentTier(), whose bands line up with core's own tier labels
 * (RelationshipManager::TIERS). Read as the old 0..100 scale, a neutral stranger (core 0,
 * mirror 50) was a RelDyn 'friend', got context tier 2, and the high-water mark locked
 * tier 2 in on the first context.
 */
final class RelDynAffinityTierTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) {
            $GLOBALS['db'] = $this->savedDb;
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function npcAtCoreAff(float $coreAff): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        return $d;
    }

    public function testCoreAffinityIsReadBackFromTheMirror(): void
    {
        $this->assertSame(0.0, RelationshipDynamics::getCoreAffinity($this->npcAtCoreAff(0)));
        $this->assertSame(-40.0, RelationshipDynamics::getCoreAffinity($this->npcAtCoreAff(-40)));
        $this->assertSame(73.0, RelationshipDynamics::getCoreAffinity($this->npcAtCoreAff(73)));

        // RelDyn's own uncommitted change to x counts (x units are half core units)
        $d = $this->npcAtCoreAff(10);
        $d['dimensions']['affinity']['x'] += 2.5;
        $this->assertSame(15.0, RelationshipDynamics::getCoreAffinity($d));
    }

    public function testWithoutAMirrorTheCoreDefaultNeutralIsAssumed(): void
    {
        // defaultDynamics() starts x at 0, which as a mirror would be core -100 (Hostile)
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $this->assertSame(0.0, RelationshipDynamics::getCoreAffinity($d));
    }

    public static function tierProvider(): array
    {
        // core aff => RelDyn tier (bands of core RelationshipManager::getTierLabel)
        return [
            'Hostile -100'      => [-100, 'hostile'],
            'Wary -6'           => [-6, 'hostile'],
            'Neutral -5'        => [-5, 'stranger'],
            'Neutral 0'         => [0, 'stranger'],
            'Neutral 5'         => [5, 'stranger'],
            'Acquaintance 6'    => [6, 'acquaintance'],
            'Acquaintance 30'   => [30, 'acquaintance'],
            'Friendly 31'       => [31, 'friend'],
            'Friendly 55'       => [55, 'friend'],
            'Fond 56'           => [56, 'close_friend'],
            'Fond 75'           => [75, 'close_friend'],
            'Devoted 76'        => [76, 'bonded'],
            'Devoted 90'        => [90, 'bonded'],
            'Bonded 91'         => [91, 'devoted'],
            'Bonded 100'        => [100, 'devoted'],
        ];
    }

    #[DataProvider('tierProvider')]
    public function testTierBandsFollowCoreAffinity(int $coreAff, string $tier): void
    {
        $this->assertSame($tier, RelationshipDynamics::getCurrentTier($coreAff));
    }

    public function testNeutralStrangerIsTierStrangerAndContextTierZero(): void
    {
        $d = $this->npcAtCoreAff(0);

        $this->assertSame('stranger', RelationshipDynamics::getCurrentTier(RelationshipDynamics::getCoreAffinity($d)));
        $this->assertSame(0, RelationshipDynamics::getContextTier($d));
        $this->assertFalse(RelationshipDynamics::updateContextTierHWM($d), 'a stranger does not raise the HWM');
        $this->assertSame(0, $d['context_tier_hwm']);
        $this->assertSame(0, RelationshipDynamics::getContextTier($d));
    }

    public static function contextTierProvider(): array
    {
        return [
            'Wary -30'          => [-30, 0],
            'Neutral 0'         => [0, 0],
            'Acquaintance 20'   => [20, 1],
            'Friendly 40'       => [40, 2],
            'Fond 70'           => [70, 2],
            'Devoted 80'        => [80, 3],
        ];
    }

    #[DataProvider('contextTierProvider')]
    public function testContextTierFollowsCoreAffinity(int $coreAff, int $contextTier): void
    {
        $this->assertSame($contextTier, RelationshipDynamics::getContextTier($this->npcAtCoreAff($coreAff)));
    }

    public function testHighWaterMarkKeepsTierTwoButNotTierThree(): void
    {
        $d = $this->npcAtCoreAff(80);
        $this->assertTrue(RelationshipDynamics::updateContextTierHWM($d));
        $this->assertSame(3, $d['context_tier_hwm']);

        // The bond breaks: core aff falls to Cold. You don't forget who someone is.
        RelationshipDynamics::refreshAffinityMirror($d, -50);
        $this->assertFalse(RelationshipDynamics::updateContextTierHWM($d));
        $this->assertSame(2, RelationshipDynamics::getContextTier($d), 'tier 2 is a permanent floor, tier 3 needs the bond');
    }

    public function testHighWaterMarkRatchetsOnCoreTiers(): void
    {
        $d = $this->npcAtCoreAff(0);
        $this->assertFalse(RelationshipDynamics::updateContextTierHWM($d));

        RelationshipDynamics::refreshAffinityMirror($d, 20);   // Acquaintance
        $this->assertTrue(RelationshipDynamics::updateContextTierHWM($d));
        $this->assertSame(1, $d['context_tier_hwm']);

        RelationshipDynamics::refreshAffinityMirror($d, 35);   // Friendly
        $this->assertTrue(RelationshipDynamics::updateContextTierHWM($d));
        $this->assertSame(2, $d['context_tier_hwm']);
    }

    public static function affinityBandProvider(): array
    {
        return [
            'Hostile -100'    => [-100, 'Hostile'],
            'Resentful -56'   => [-56, 'Hostile'],
            'Cold -40'        => [-40, 'Cold'],
            'Wary -10'        => [-10, 'Cold'],
            'Neutral 0'       => [0, 'Neutral'],
            'Acquaintance 20' => [20, 'Neutral'],
            'Friendly 40'     => [40, 'Warm'],
            'Fond 60'         => [60, 'Fond'],
            'Devoted 80'      => [80, 'Close'],
            'Bonded 95'       => [95, 'Devoted'],
        ];
    }

    #[DataProvider('affinityBandProvider')]
    public function testAffinityBandFollowsCoreAffinity(int $coreAff, string $label): void
    {
        $band = RelationshipDynamics::getAffinityBand($this->npcAtCoreAff($coreAff));
        $this->assertSame($label, $band['label'] ?? null);
    }

    public function testFriendzoneNeedsFriendTierOnCoreAffinity(): void
    {
        $d = $this->npcAtCoreAff(0);
        $d['_attraction_friendzoned'] = true;
        $this->assertNotSame('friendzone', RelationshipDynamics::getRelationshipType('Lydia', $d),
            'a neutral stranger (core 0) is not a friend, so not friendzoned');

        RelationshipDynamics::refreshAffinityMirror($d, 40);
        $this->assertSame('friendzone', RelationshipDynamics::getRelationshipType('Lydia', $d));
    }
}
