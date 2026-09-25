<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Config row only (conf_opts): everything else reads as absent. */
final class RelDynReputationLayerConfigDb
{
    public ?string $value = null;
    public function fetchOne($sql, $params = null) { return (strpos((string) $sql, 'conf_opts') !== false && $this->value !== null) ? ['value' => $this->value] : []; }
    public function fetchAll($sql) { return []; }
    public function escape($s) { return addslashes((string) $s); }
    public function escapeLiteral($s) { return "'" . addslashes((string) $s) . "'"; }
}

/**
 * reputation-layer (dimension draft "Reputation vs Experience"): what an NPC heard of the player
 * before meeting them shifts where trust / respect / comfort start, per NPC through her signed
 * preferences, and fades with meaningful interactions (under 10% after five, negligible after
 * fifteen). A held temporary offset, felt as a tone (no numbers), numbers for Jev. No database:
 * the scores are given (RelDynPlayer's profile is the Postgres test beds' part).
 */
final class RelDynReputationLayerTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private static function npc(array $state = []): array
    {
        $d = ['inferred_temperament' => 'Stoic', 'dimensions' => []];
        foreach (['trust' => 30.0, 'respect' => 35.0, 'comfort' => 25.0] as $dim => $x) $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        if ($state !== []) $d[RelDynReputation::KEY] = $state;
        return $d;
    }

    public function testTheDraftsTableThroughWhoSheIs(): void
    {
        // fame: respect +20 and trust +5 at full fame
        $this->assertEqualsWithDelta(['respect' => 10.0, 'trust' => 2.5, 'comfort' => 0.0],
            RelDynReputation::offsets(['fame' => 0.5, 'infamy' => 0.0, 'status' => 0.0], []), 1e-9);
        // infamy: trust -20, comfort -10; respect +-10 by how she regards danger and darkness
        $drawn = RelDynReputation::offsets(['fame' => 0.0, 'infamy' => 0.5, 'status' => 0.0], ['danger' => 0.5, 'dark' => 0.5]);
        $repelled = RelDynReputation::offsets(['fame' => 0.0, 'infamy' => 0.5, 'status' => 0.0], ['danger' => -0.5, 'dark' => -0.5]);
        $this->assertEqualsWithDelta(['respect' => 5.0, 'trust' => -10.0, 'comfort' => -5.0], $drawn, 1e-9, 'some respect power');
        $this->assertEqualsWithDelta(-5.0, $repelled['respect'], 1e-9, 'some despise it');
        // wealth display: respect up to +8, from status-oriented NPCs only
        $this->assertEqualsWithDelta(4.0, RelDynReputation::offsets(['status' => 0.5], ['wealth' => 0.5, 'luxury' => 0.5])['respect'], 1e-9);
        $this->assertEqualsWithDelta(0.0, RelDynReputation::offsets(['status' => 0.5], ['wealth' => -0.5, 'luxury' => -0.5])['respect'], 1e-9);
        // capped (REPUTATION_CAPS): respect at most +20
        $this->assertEqualsWithDelta(20.0, RelDynReputation::offsets(['fame' => 1.0, 'infamy' => 1.0, 'status' => 1.0],
            ['danger' => 1.0, 'dark' => 1.0, 'wealth' => 1.0, 'luxury' => 1.0])['respect'], 1e-9);
    }

    public function testItFadesWithMeaningfulInteractions(): void
    {
        $this->assertSame(1.0, RelDynReputation::weight([]));
        $this->assertLessThan(0.1, RelDynReputation::weight(['meaningful' => 5]), 'under 10% after about five');
        $this->assertLessThan(0.001, RelDynReputation::weight(['meaningful' => 15]), 'negligible after about fifteen');

        $d = self::npc(['raw' => ['trust' => 4.0, 'respect' => 16.0, 'comfort' => 0.0], 'fame' => 0.8, 'infamy' => 0.0, 'meaningful' => 0]);
        RelDynReputation::apply($d, 'Alvor');
        $this->assertEqualsWithDelta(51.0, $d['dimensions']['respect']['x'], 1e-9, 'stranger 35 + fame 16');
        $this->assertEqualsWithDelta(16.0, RelationshipDynamics::heldTemporaryOffset($d, 'respect'), 1e-9, 'held: not who she is');
        $this->assertStringContainsString('respectful curiosity', (string) RelDynReputation::feltText('Alvor', 'the stranger', $d));

        // Small talk does not count; three meaningful exchanges take most of it back
        $this->assertFalse(RelDynReputation::countInteraction($d, 0.1));
        foreach ([1, 2, 3] as $_) $this->assertTrue(RelDynReputation::countInteraction($d, 0.5));
        RelDynReputation::apply($d, 'Alvor');
        $this->assertEqualsWithDelta(35.0 + 16.0 * 0.25, $d['dimensions']['respect']['x'], 1e-9);
        $this->assertNull(RelDynReputation::feltText('Alvor', 'the stranger', $d), 'the first impression no longer colours the meeting');
        $this->assertSame(['fame' => 0.8, 'infamy' => 0.0, 'weight' => 0.25, 'meaningful' => 3, 'offsets' => ['trust' => 1.0, 'respect' => 4.0]],
            RelDynReputation::jev($d));

        // What she experienced since stays hers: an eval moving respect is not taken back
        $d['dimensions']['respect']['x'] += 7.0;
        foreach ([1, 2, 3] as $_) RelDynReputation::countInteraction($d, 0.5);
        RelDynReputation::apply($d, 'Alvor');
        $this->assertEqualsWithDelta(35.0 + 7.0 + 16.0 * 0.0625, $d['dimensions']['respect']['x'], 1e-9);
    }

    public function testInfamyReadsAsWarinessAndSwitchingItOffTakesItBack(): void
    {
        $d = self::npc(['raw' => ['trust' => -12.0, 'respect' => 3.0, 'comfort' => -6.0], 'fame' => 0.0, 'infamy' => 0.6, 'meaningful' => 0]);
        RelDynReputation::apply($d, 'Muiri');
        $this->assertEqualsWithDelta(18.0, $d['dimensions']['trust']['x'], 1e-9);
        $text = (string) RelDynReputation::feltText('Muiri', 'the stranger', $d);
        $this->assertStringContainsString('watchful distance', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);

        $db = new RelDynReputationLayerConfigDb();
        $db->value = json_encode(['reputation' => ['enabled' => false]]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        RelDynReputation::apply($d, 'Muiri');
        $this->assertEqualsWithDelta(30.0, $d['dimensions']['trust']['x'], 1e-9, 'taken back exactly');
        $this->assertSame([], $d[RelDynReputation::KEY]['effective']);
    }

    public function testMetBeforeAnythingWasKnownMeansNothingWasHeard(): void
    {
        // No profile (no database): the first contact asks again next time; after a meaningful
        // exchange it is settled at nothing
        $d = self::npc();
        RelDynReputation::apply($d, 'Lynly Star-Sung');
        $this->assertArrayNotHasKey('raw', $d[RelDynReputation::KEY] ?? []);
        RelDynReputation::countInteraction($d, 0.6);
        RelDynReputation::apply($d, 'Lynly Star-Sung');
        $this->assertSame(['trust' => 0.0, 'respect' => 0.0, 'comfort' => 0.0], $d[RelDynReputation::KEY]['raw']);
        $this->assertEqualsWithDelta(30.0, $d['dimensions']['trust']['x'], 1e-9);
    }
}
