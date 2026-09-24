<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * One answer to "what are we to each other".
 *
 * Core's relationships.Player.type (lib/relationship_manager.php TYPES, written by core eval
 * and the relationship editor) is the source of truth. RelDyn's getRelationshipType() maps
 * it onto RelDyn's bond keys (RELATIONSHIP_TYPE_MODIFIERS / TIER_FLOOR_GATES) for every
 * consumer: absence decay gates, per-bond modifiers, friendzone cap, breaking arc, parasite.
 * RelDyn's interaction-count stage is only the fallback when core's type is unknown; it used
 * to be the answer, so a 200-interaction enemy was 'bonded'.
 */
final class RelDynRelationshipTypeTest extends TestCase
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

    private function npc(?string $coreType, float $coreAff = 0.0, array $extra = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), $extra));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        if ($coreType !== null) {
            RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        }
        return $d;
    }

    public static function coreTypeProvider(): array
    {
        return [
            'romantic'      => ['romantic', 'bonded'],
            'platonic'      => ['platonic', 'friend'],
            'familial'      => ['familial', 'bonded'],
            'professional'  => ['professional', 'acquaintance'],
            'rival'         => ['rival', 'rival'],
            'enemy'         => ['enemy', 'hostile'],
            'nemesis'       => ['nemesis', 'hostile'],
            'transactional' => ['transactional', 'mercenary'],
            'fanatical'     => ['fanatical', 'sworn'],
            'mentor'        => ['mentor', 'mentor'],
            'student'       => ['student', 'student'],
            'crush'         => ['crush', 'crush'],
        ];
    }

    #[DataProvider('coreTypeProvider')]
    public function testCoreTypeIsTheSourceOfTruth(string $coreType, string $relDynType): void
    {
        $this->assertSame($relDynType, RelationshipDynamics::getRelationshipType('Lydia', $this->npc($coreType, 40.0)));
    }

    public function testATwoHundredInteractionEnemyIsNotBonded(): void
    {
        $d = $this->npc('enemy', -60.0, ['stage' => 'deep', 'interaction_count' => 200]);
        $this->assertSame('hostile', RelationshipDynamics::getRelationshipType('Lydia', $d));
    }

    public static function neutralDepthProvider(): array
    {
        // core 'neutral' carries no flavour: the depth comes from core affinity, not the stage
        return [
            'Wary -20'        => [-20.0, 'stranger'],
            'Neutral 0'       => [0.0, 'stranger'],
            'Acquaintance 20' => [20.0, 'acquaintance'],
            'Friendly 40'     => [40.0, 'friend'],
            'Fond 70'         => [70.0, 'friend'],
            'Devoted 80'      => [80.0, 'bonded'],
        ];
    }

    #[DataProvider('neutralDepthProvider')]
    public function testNeutralCoreTypeTakesItsDepthFromCoreAffinity(float $coreAff, string $relDynType): void
    {
        $d = $this->npc('neutral', $coreAff, ['stage' => 'deep', 'interaction_count' => 200]);
        $this->assertSame($relDynType, RelationshipDynamics::getRelationshipType('Lydia', $d));
    }

    public function testAnUnknownCustomCoreTypeAlsoTakesDepthFromCoreAffinity(): void
    {
        $d = $this->npc('drinking buddy', 40.0, ['stage' => 'early']);
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType('Lydia', $d));
    }

    public function testCoreHostilityOutranksRelDynOverlays(): void
    {
        $d = $this->npc('enemy', 40.0, ['_relationship_type_override' => 'parasite', '_attraction_friendzoned' => true]);
        $this->assertSame('hostile', RelationshipDynamics::getRelationshipType('Lydia', $d));

        $d = $this->npc('rival', 40.0, ['_attraction_friendzoned' => true]);
        $this->assertSame('rival', RelationshipDynamics::getRelationshipType('Lydia', $d));
    }

    public function testRelDynOverlaysRefineAFriendlyCoreType(): void
    {
        $d = $this->npc('platonic', 40.0, ['_relationship_type_override' => 'parasite']);
        $this->assertSame('parasite', RelationshipDynamics::getRelationshipType('Lydia', $d));

        $d = $this->npc('platonic', 40.0, ['_attraction_friendzoned' => true]);
        $this->assertSame('friendzone', RelationshipDynamics::getRelationshipType('Lydia', $d));
    }

    public function testStageIsOnlyTheFallbackWithoutCoreData(): void
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['stage' => 'deep']));
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType('Lydia', $d));

        $d['stage'] = 'established';
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType('Lydia', $d));

        $this->assertSame('stranger', RelationshipDynamics::getRelationshipType('Lydia', []));
    }

    public function testCoreTypeSnapshotIsCanonical(): void
    {
        $d = [];
        RelationshipDynamics::setCoreRelationshipType($d, ' Enemy ');
        $this->assertSame('enemy', $d['_core_rel_type']);

        RelationshipDynamics::setCoreRelationshipType($d, null);
        $this->assertSame('neutral', $d['_core_rel_type'], "core's default type for a missing entry");
    }

    public function testParasiteRecoveryDoesNotFreezeTheCoreType(): void
    {
        $d = $this->npc('platonic', 40.0);
        $ref = new ReflectionClass('RelationshipDynamics');
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['parasite_detection_enabled'] = true;
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        $p->setValue(null, $cfg);

        $d['_interaction_pattern'] = ['total_window' => 10, 'gift_count' => 9, 'genuine_count' => 1];
        $this->assertSame('parasite', RelationshipDynamics::checkParasitePattern('Lydia', $d));
        $this->assertSame('parasite', RelationshipDynamics::getRelationshipType('Lydia', $d));

        $d['_interaction_pattern'] = ['total_window' => 10, 'gift_count' => 2, 'genuine_count' => 5];
        RelationshipDynamics::checkParasiteRecovery('Lydia', $d);
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType('Lydia', $d));

        // Later core eval makes it a romance: RelDyn follows core, no frozen 'friend' override
        RelationshipDynamics::setCoreRelationshipType($d, 'romantic');
        $this->assertSame('bonded', RelationshipDynamics::getRelationshipType('Lydia', $d));
    }
}
