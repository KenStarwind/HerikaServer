<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * internal-weather / activity-preferences-legacy review 2026-09-24: the intimacy deprivation
 * context (<intimacy_state>, PR 13, M/F-aware) read _interest_satisfaction['intimacy'], whose only
 * writers (calculateInterestSatisfaction / checkIntimacySatisfaction) were retired with the April
 * interest layer. A fresh NPC never got it; a legacy blob holding 0.1 got it on every turn,
 * forever. It now runs on the game calendar: an intimacy or touch exchange (the eval contract's
 * tags, a touch request) satisfies, satisfaction wears off over game days at the attachment
 * style's rate (PR 13: avoidant 0.5x, anxious 2x, toxic 1.5x), and only an NPC with physical
 * needs at all (passion from intimacy_min_passion, PR 13's inference) can be deprived.
 * Real engine code; no database (config defaults).
 */
final class RelDynIntimacyDeprivationTest extends TestCase
{
    const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    const T0 = 80 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'RELDYN_INTERACTION_SIGNIFICANCE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private static function lover(string $attachment = 'secure', float $passion = 45.0): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['inferred_temperament'] = 'Romantic';
        $d['attachment_style'] = $attachment;
        RelationshipDynamics::setPassion($d, $passion);
        return $d;
    }

    private static function evalItem(array $tags, int $gamets): array
    {
        return [
            'v' => 1, 'npc' => 'Ashe', 'npc_id' => 3, 'gamets' => $gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 2, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5, 'positive_interaction' => true, 'summary' => 'held her close',
        ];
    }

    public function testDeprivationBuildsOverGameDaysAndAnIntimateExchangeFeedsIt(): void
    {
        $d = self::lover();
        RelationshipDynamics::ensureIntimacyClock($d, self::T0);
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, self::T0), 'needs start counting now');
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, self::T0 + 3 * self::DAY));

        $later = self::T0 + 6 * self::DAY;
        $text = RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, $later);
        $this->assertNotNull($text, 'six game days without closeness');
        $this->assertStringContainsString('Ashe', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text);

        // the eval contract's 'intimacy' tag satisfies (the retired writer's OStim / full source)
        RelationshipDynamics::processEvalContractItem('Ashe', self::evalItem(['intimacy'], (int) $later), $d);
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, $later));
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, $later + 3 * self::DAY));

        // a touch (hug, kiss) half-satisfies: it wears off sooner
        $e = self::lover();
        RelationshipDynamics::ensureIntimacyClock($e, self::T0);
        RelationshipDynamics::processEvalContractItem('Ashe', self::evalItem(['touch'], (int) $later), $e);
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $e, $later));
        $this->assertNotNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $e, $later + 3 * self::DAY));
    }

    public function testATouchRequestFeedsItAndNeverUndoesAFullerSatisfaction(): void
    {
        $d = self::lover();
        RelationshipDynamics::ensureIntimacyClock($d, self::T0);
        $later = self::T0 + 6 * self::DAY;
        $kiss = ['inputtext', '1', (string) (int) $later, 'Kaida: ExtCmdKiss'];
        $ll = RelationshipDynamics::classifyInteraction($kiss);
        $this->assertTrue(RelationshipDynamics::recordIntimacyFromRequest($d, $ll, $later));
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, $later));
        $this->assertFalse(RelationshipDynamics::recordIntimacyFromRequest($d, RelationshipDynamics::LL_WORDS, $later), 'words are not touch');

        $full = self::lover();
        RelationshipDynamics::processEvalContractItem('Ashe', self::evalItem(['intimacy'], (int) self::T0), $full);
        $before = RelationshipDynamics::intimacySatisfaction($full, self::T0);
        RelationshipDynamics::recordIntimacyFromRequest($full, RelationshipDynamics::LL_TOUCH, self::T0);
        $this->assertSame($before, RelationshipDynamics::intimacySatisfaction($full, self::T0), 'a hug after lovemaking does not lower it');
    }

    public function testAttachmentStyleSetsTheRate(): void
    {
        $at = self::T0 + 2.5 * self::DAY;
        $states = [];
        foreach (['secure', 'anxious', 'avoidant'] as $style) {
            $d = self::lover($style);
            RelationshipDynamics::ensureIntimacyClock($d, self::T0);
            $states[$style] = RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $d, $at);
        }
        $this->assertNotNull($states['anxious'], 'PR 13: anxious deprives twice as fast');
        $this->assertNull($states['secure']);
        $this->assertNull($states['avoidant']);
    }

    public function testNoPhysicalNeedsNoDeprivationAndTheRetiredStateIsNotRead(): void
    {
        // passion below intimacy_min_passion: nothing to be deprived of, and the clock does not start
        $cold = self::lover('secure', 10.0);
        RelationshipDynamics::ensureIntimacyClock($cold, self::T0);
        $this->assertArrayNotHasKey('_intimacy_fed', $cold);
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $cold, self::T0 + 30 * self::DAY));

        // a base-era blob with the retired satisfaction at 0.1 is not stuck deprived
        $legacy = self::lover('secure', 10.0);
        $legacy['_interest_satisfaction'] = ['intimacy' => 0.1];
        $this->assertNull(RelationshipDynamics::generateIntimacyDeprivationContext('Ashe', $legacy, self::T0));
        $this->assertArrayNotHasKey('_interest_satisfaction', RelationshipDynamics::defaultDynamics(), 'no retired state in new blobs');
    }
}
