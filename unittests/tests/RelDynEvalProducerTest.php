<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * eval-producer-worker / eval-source-tags / interaction-classification / eval-input-quality:
 * the parts of RelDyn's own eval producer that need no database — eligibility (Narrator,
 * radiant / NPC-to-NPC), eventlog row parsing (speaker attribution, multi-word and non-ASCII
 * names), the strict LLM-output parser into the shared eval contract, and the one
 * classification source (tags <-> love languages, positive_interaction).
 * The database side (real postrequest, queue, worker, eventlog window) is
 * RelDynEvalWorkerPostgresTest.
 */
final class RelDynEvalProducerTest extends TestCase
{
    private array $savedGlobals = [];
    private $prevLog = null;
    private ?string $logFile = null;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdeval');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private const JOB = ['npc' => 'Aela the Huntress', 'npc_id' => 12, 'player_name' => 'Kaida', 'gamets' => 5000123, 'event_tags' => []];

    private static function cfg(): array
    {
        return RelDynEval::defaultConfig();
    }

    // ------------------------------------------------------------------ eligibility

    public function testTheNarratorIsNeverEvaluated(): void
    {
        foreach (['The Narrator', 'the narrator', '', '  '] as $npc) {
            $this->assertSame('narrator', RelDynEval::skipReason($npc, ['inputtext', '1', '5000', 'Kaida: hi'], 'Kaida', 'Kaida', self::cfg()), var_export($npc, true));
        }
        $this->assertSame('narrator', RelDynEval::skipReason('Lydia', ['narrator_inputtext', '1', '5000', 'Kaida: hi'], null, 'Kaida', self::cfg()));
    }

    public function testPlayerInputIsEvaluatedByDefaultEveryTime(): void
    {
        $cfg = self::cfg();
        $this->assertSame(1.0, $cfg['chance'], 'every eligible exchange, not core\'s 50%');
        $this->assertSame(0, $cfg['cooldown_gamets']);
        foreach (RelDynEval::PLAYER_INPUT_TYPES as $type) {
            $this->assertNull(RelDynEval::skipReason('Lydia', [$type, '1', '5000', 'Kaida: hi'], null, 'Kaida', $cfg), $type);
        }
    }

    public function testRadiantAndNpcToNpcAreSkippedUnlessThePlayerIsAddressed(): void
    {
        $cfg = self::cfg();
        $this->assertSame('player_not_addressed', RelDynEval::skipReason('Lydia', ['radiant', '1', '5000', ''], 'Faendal', 'Kaida', $cfg));
        $this->assertSame('player_not_addressed', RelDynEval::skipReason('Lydia', ['rechat', '1', '5000', ''], null, 'Kaida', $cfg));
        $this->assertNull(RelDynEval::skipReason('Lydia', ['radiant', '1', '5000', ''], 'Kaida', 'Kaida', $cfg), 'radiant addressed to the player');
        $this->assertNull(RelDynEval::skipReason('Lydia', ['rechat', '1', '5000', ''], 'Faendal and Kaida', 'Kaida', $cfg), 'group including the player');
        $this->assertNull(RelDynEval::skipReason('Lydia', ['instruction', '1', '5000', ''], 'Player', 'Kaida', $cfg), 'core key "Player"');
        $this->assertSame('disabled', RelDynEval::skipReason('Lydia', ['inputtext', '1', '5000', ''], null, 'Kaida', ['enabled' => false] + $cfg));
    }

    // ------------------------------------------------------------------ eventlog rows

    public function testDialogueRowsKeepMultiWordAndNonAsciiSpeakers(): void
    {
        $r = RelDynEval::parseDialogueRow('chat', 'Aela the Huntress: A fine pelt, Shield-Sibling. (talking to Kaida)', 'Aela the Huntress', 'Kaida');
        $this->assertSame(['role' => 'npc', 'speaker' => 'Aela the Huntress', 'text' => 'A fine pelt, Shield-Sibling.', 'listener' => 'Kaida'], $r);

        $r = RelDynEval::parseDialogueRow('chat', "J'zargo: J'zargo thinks: this scroll is fire. (whispering to Kaida)", "J'zargo", 'Kaida');
        $this->assertSame("J'zargo", $r['speaker']);
        $this->assertSame("J'zargo thinks: this scroll is fire.", $r['text'], 'only the first colon splits the speaker');

        $r = RelDynEval::parseDialogueRow('chat', 'Þórdís Ævarsdóttir: Hvað segirðu? (speaking loudly to Kaida from far away)', 'þórdís ævarsdóttir', 'Kaida');
        $this->assertSame('npc', $r['role'], 'non-ASCII names match case-insensitively');
        $this->assertSame('Kaida', $r['listener']);
        $this->assertSame('Hvað segirðu?', $r['text']);
    }

    public function testPlayerRowsDropTheContextLocationAndTalkingToSuffix(): void
    {
        $r = RelDynEval::parseDialogueRow('inputtext', '(Context location: Jorrvaskr, Whiterun)Kaida: Here, a wolf pelt. (Talking to Aela the Huntress)', 'Aela the Huntress', 'Kaida');
        $this->assertSame(['role' => 'player', 'speaker' => 'Kaida', 'text' => 'Here, a wolf pelt.', 'listener' => 'Aela the Huntress'], $r);

        // Player input is the player's by type, whatever name the line carries.
        $r = RelDynEval::parseDialogueRow('ginputtext', 'Player: Hello there. (Talking to Lydia)', 'Lydia', 'Kaida');
        $this->assertSame('player', $r['role']);
        $this->assertSame('Kaida', $r['speaker']);

        $r = RelDynEval::parseDialogueRow('chat', 'Farkas: Kodlak wants you. (talking to Aela the Huntress)', 'Aela the Huntress', 'Kaida');
        $this->assertSame('other', $r['role']);
        $this->assertSame('Farkas', $r['speaker']);
        $this->assertNull(RelDynEval::parseDialogueRow('chat', '(Context location: Whiterun)', 'Lydia', 'Kaida'));
    }

    // ------------------------------------------------------------------ strict parse

    public function testAValidReplyBecomesAContractItem(): void
    {
        $raw = json_encode([
            'signals' => ['affinity' => 4, 'trust' => 2.6, 'comfort' => 1, 'respect' => 3, 'passion' => 0, 'maturity' => 1],
            'tags' => ['gift', 'Praise', 'hugging', 'quality_time'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5,
            'summary' => "Grateful for the pelt;\nlikes being noticed.",
        ]);
        $item = RelDynEval::parseResponse($raw, self::JOB, $reason);
        $this->assertNull($reason);
        $this->assertSame([
            'v' => 1, 'npc' => 'Aela the Huntress', 'npc_id' => 12, 'gamets' => 5000123, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 4, 'trust' => 3, 'comfort' => 1, 'respect' => 3, 'passion' => 0, 'maturity' => 1],
            'tags' => ['gift', 'praise', 'quality_time'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5,
            'positive_interaction' => true,
            'summary' => 'Grateful for the pelt; likes being noticed.',
        ], $item);
    }

    public function testRangesAreClampedAndMissingFieldsDefaulted(): void
    {
        $item = RelDynEval::parseResponse('```json
{"signals": {"affinity": 80, "trust": -45, "maturity": -25, "respect": 12.4}, "significance": 7}
```', self::JOB, $reason);
        $this->assertNull($reason);
        $this->assertSame(['affinity' => 30, 'trust' => -30, 'comfort' => 0, 'respect' => 12, 'passion' => 0, 'maturity' => -10], $item['signals']);
        $this->assertSame(1.0, $item['significance'], 'significance is 0..1');
        $this->assertSame([], $item['tags']);
        $this->assertSame(['flag' => false, 'kind' => null, 'severity' => 0], $item['grievance']);
        $this->assertSame(['flag' => false, 'rival' => null, 'intensity' => 0], $item['jealousy']);
        $this->assertSame('', $item['summary']);

        $item = RelDynEval::parseResponse('{"signals": {}}', self::JOB, $reason);
        $this->assertSame(RelDynEval::DEFAULT_SIGNIFICANCE, $item['significance']);
        $this->assertFalse($item['positive_interaction'], 'nothing changed: not a positive interaction');

        $item = RelDynEval::parseResponse('{"signals": {"affinity": -2}, "grievance": {"flag": true, "kind": "Insult", "severity": 9}, "jealousy": {"flag": 1, "rival": "Kaida", "intensity": 0}}', self::JOB, $reason);
        $this->assertSame(['flag' => true, 'kind' => 'insult', 'severity' => 3], $item['grievance']);
        $this->assertSame(['flag' => true, 'rival' => null, 'intensity' => 1], $item['jealousy'], 'the player is never the rival; a raised flag has intensity >= 1');
    }

    #[DataProvider('malformedReplies')]
    public function testMalformedRepliesAreRejected(string $raw, string $why): void
    {
        $this->assertNull(RelDynEval::parseResponse($raw, self::JOB, $reason), $why);
        $this->assertNotNull($reason, $why);
    }

    public static function malformedReplies(): array
    {
        return [
            'prose'             => ['Aela is pleased. Affinity +4.', 'not JSON'],
            'truncated'         => ['{"signals": {"affinity": 4, "trust"', 'cut off'],
            'list'              => ['[{"signals": {}}]', 'a list, not an object'],
            'no signals'        => ['{"affinity_delta": 4}', 'old core shape, no signals'],
            'signals list'      => ['{"signals": [4, 2]}', 'signals must be an object'],
            'string number'     => ['{"signals": {"affinity": "+4"}}', 'a signal must be a number'],
            'tags string'       => ['{"signals": {}, "tags": "gift"}', 'tags must be a list'],
            'tag object'        => ['{"signals": {}, "tags": [{"gift": true}]}', 'tags must be strings'],
            'grievance string'  => ['{"signals": {}, "grievance": "ignored her"}', 'grievance must be an object'],
            'flag string'       => ['{"signals": {}, "grievance": {"flag": "yes"}}', 'flag must be a boolean'],
            'significance text' => ['{"signals": {}, "significance": "high"}', 'significance must be a number'],
            'text around JSON'  => ['Here you go: {"signals": {}}', 'one JSON object and nothing else'],
        ];
    }

    // ------------------------------------------------------------------ classification

    public function testTagsGrievanceJealousyAndPositiveAgree(): void
    {
        $sig = ['affinity' => 3, 'trust' => 1, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0];
        $none = ['flag' => false, 'kind' => null, 'severity' => 0];
        $noJ = ['flag' => false, 'rival' => null, 'intensity' => 0];

        $c = RelDynEval::classify($sig, ['quality_time', 'praise'], $none, $noJ);
        $this->assertTrue($c['positive_interaction']);
        $this->assertSame(['praise', 'quality_time'], $c['tags'], 'contract order');

        $c = RelDynEval::classify($sig, ['praise'], $none, ['flag' => true, 'rival' => 'Farkas', 'intensity' => 2]);
        $this->assertContains('jealousy_trigger', $c['tags'], 'jealousy flag carries its tag');
        $this->assertFalse($c['positive_interaction'], 'a jealousy trigger is not a positive interaction');

        $c = RelDynEval::classify($sig, ['jealousy_trigger'], $none, $noJ);
        $this->assertSame(['flag' => true, 'rival' => null, 'intensity' => 1], $c['jealousy'], 'the tag raises the flag');

        $c = RelDynEval::classify($sig, [], ['flag' => true, 'kind' => 'neglect', 'severity' => 1], $noJ);
        $this->assertSame(['neglect'], $c['tags'], 'a grievance kind that is a tag carries it');
        $this->assertFalse($c['positive_interaction'], 'an open grievance is not positive');

        $c = RelDynEval::classify(['affinity' => -1, 'trust' => 3] + $sig, ['help'], $none, $noJ);
        $this->assertFalse($c['positive_interaction'], 'liked less: not positive even with other gains');

        $c = RelDynEval::classify(['affinity' => 0, 'trust' => 0, 'maturity' => 5] + $sig, ['command'], $none, $noJ);
        $this->assertFalse($c['positive_interaction'], 'maturity alone is about the NPC, not the player');
    }

    public function testTagsAndLoveLanguagesAreOneClassification(): void
    {
        $lls = [RelationshipDynamics::LL_WORDS, RelationshipDynamics::LL_TIME, RelationshipDynamics::LL_TOUCH,
            RelationshipDynamics::LL_SERVICE, RelationshipDynamics::LL_GIFTS];
        foreach ($lls as $ll) {
            $tags = RelDynEval::tagsForLoveLanguage($ll);
            $this->assertCount(1, $tags, $ll);
            $this->assertArrayHasKey($tags[0], RelDynEval::TAG_DEFINITIONS, "{$ll} maps to a contract tag");
            $this->assertSame([$ll], RelDynEval::loveLanguagesForTags($tags), "{$ll} round-trips");
        }
        foreach (RelDynEval::TAG_LOVE_LANGUAGE as $tag => $ll) {
            $this->assertArrayHasKey($tag, RelDynEval::TAG_DEFINITIONS);
            $this->assertContains($ll, $lls, "{$tag} feeds a real love language");
        }
        $this->assertSame([RelationshipDynamics::LL_TOUCH], RelDynEval::loveLanguagesForTags(['touch', 'intimacy', 'insult']));
        $this->assertSame([], RelDynEval::tagsForLoveLanguage(null));

        // The request-level event tags come from the same classifier the passion path uses.
        $this->assertSame(['touch'], RelDynEval::eventTagsForRequest(['ext_nsfw_physics', '1', '5000', 'kiss']));
        $this->assertSame(['help'], RelDynEval::eventTagsForRequest(['combatend', '1', '5000', '']));
        $this->assertSame([], RelDynEval::eventTagsForRequest(['instruction', '1', '5000', 'x']), 'nothing observed');

        $item = RelDynEval::parseResponse('{"signals": {"affinity": 2}, "tags": ["quality_time"]}', ['event_tags' => ['touch']] + self::JOB, $reason);
        $this->assertSame(['quality_time', 'touch'], $item['tags'], 'observed events join the eval tags');
    }

    public function testEveryContractTagIsDefinedForTheModel(): void
    {
        $decided = ['gift', 'praise', 'help', 'rescue', 'quality_time', 'touch', 'intimacy', 'insult', 'criticism', 'neglect',
            'jealousy_trigger', 'command', 'betrayal', 'lie', 'competence', 'reassurance', 'apology'];
        $this->assertSame($decided, array_keys(RelDynEval::TAG_DEFINITIONS));
        foreach (RelDynEval::NEGATIVE_TAGS as $t) {
            $this->assertArrayHasKey($t, RelDynEval::TAG_DEFINITIONS);
        }
    }
}
