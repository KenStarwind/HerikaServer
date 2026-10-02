<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * Jev picks which body cue fires, from RelDyn's jevStateBlock (roadmap bio-mimetic-feedback, Ken 2026-10-01 §24): the Jev tactical
 * layer's body module (ext/jev_tactical/lib/jev_body.php on Ken's fork, branch jev-on-dd-unstable: a different tree, found next to this
 * one or at JEV_REPO) driven against the real RelDyn on a real PostgreSQL with the four test beds. Only the HTTP call to Jev is faked
 * (the connector hook the layer's own tests use): the questions Jev is sent, the explicit state it judges, the pick that reaches RelDyn,
 * the command line that comes back and the felt line the dialogue model then gets are all the real code.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer); skipped when the Jev tree is not there.
 */
final class RelDynBodyJevTestBedsPostgresTest extends TestCase
{
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const ASHE = RelDynNetworkBedsKit::ASHE;

    private ?RelDynNetworkBedsKit $kit = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        $jev = rtrim((string) (getenv('JEV_REPO') ?: dirname(__DIR__, 3) . '/HerikaServer-jev'), '/\\') . '/ext/jev_tactical/lib';
        if (!is_file($jev . '/jev_body.php')) $this->markTestSkipped("the Jev tree is not at {$jev} (set JEV_REPO)");
        require_once $jev . '/jev_client.php';
        require_once $jev . '/jev_body.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['mockConnectorSend']);
        if ($this->kit !== null) $this->kit->destroy();
        $this->kit = null;
    }

    private function world(array $npcs): RelDynNetworkBedsKit
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'bodyjev');
        $kit = $this->kit;
        $rels = [];
        foreach ($npcs as $npc) $rels[$npc] = ['Player' => [60, 'romantic']];
        $kit->seed($rels);
        $kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        foreach ($npcs as $i => $npc) $kit->turn($npc, 'Good evening.', RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $i * 0.1), 'hello');
        return $kit;
    }

    private function edit(string $npc, callable $change): void
    {
        $d = RelationshipDynamics::getDynamics($npc);
        $change($d);
        $this->assertTrue(RelationshipDynamics::saveDynamics($npc, $d));
        RelationshipDynamics::endRequest();
    }

    /** Jev over the connector hook: picks the first cue it is offered (or $cue) with this confidence, and answers the voice question. */
    private function jev(?string $cue = null, float $confidence = 0.9, float $voice = 0.8): JevClient
    {
        $GLOBALS['mockConnectorSend'] = function (string $url, $context) use ($cue, $confidence, $voice) {
            $req = json_decode((string) stream_context_get_options($context)['http']['content'], true);
            $answers = [];
            if (isset($req['questions']['cue'])) {
                $labels = array_keys($req['questions']['cue']['criteria']);
                $pick = $cue ?? $labels[0];
                $answers['cue'] = ['type' => 'choice', 'choice' => $pick, 'confidence' => $confidence, 'probabilities' => [$pick => $confidence]];
            }
            if (isset($req['questions']['voice'])) $answers['voice'] = ['type' => 'noul', 'noul' => $voice];
            return json_encode(['model' => 'jev-test', 'answers' => $answers, 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
        };
        return new JevClient('test-key', 'jev-test', 'https://example.invalid/v1/systemone', 5);
    }

    private function block(string $npc): array
    {
        $b = RelationshipDynamics::jevStateBlock($npc);
        RelationshipDynamics::endRequest();
        return $b;
    }

    public function testJevIsAskedAboutExactlyWhatRelDynOffersAndWhatItPicksSpeaks(): void
    {
        $kit = $this->world([self::MUIRI, self::LYNLY]);
        $this->edit(self::MUIRI, function (array &$d): void { RelationshipDynamics::setPassion($d, 92.0); });
        $this->edit(self::LYNLY, function (array &$d): void { RelationshipDynamics::setPassion($d, 55.0); $d['dimensions']['self_confidence']['x'] = 8.0; $d['dimensions']['resentment_self']['x'] = 60.0; });

        $out = [];
        foreach ([self::MUIRI, self::LYNLY] as $npc) {
            $block = $this->block($npc);
            $client = $this->jev();
            $d = jev_body_decide($npc, $block, ['goal' => 'Keep Kaida company', 'rules' => 'Do not leave the hearth.'], 'Kaida', $client, ['ComeCloser'], ['min_confidence' => 0.6]);
            $sent = $client->lastRequest;
            // Jev is asked about exactly the cues RelDyn offers, plus none
            $this->assertSame(array_merge($block['body']['offered'], ['none']), array_keys($sent['questions']['cue']['criteria']), $npc);
            $this->assertNotSame([], $block['body']['offered'], "{$npc} has a body to show");
            // and judges the explicit numbers and the goal, not the felt prose
            $this->assertStringContainsString('passion=', $sent['state']['relationship'], $npc);
            $this->assertSame('Keep Kaida company', $sent['state']['standing_goal']['goal']);
            $this->assertSame($block['body']['approach_threshold'], $sent['state']['body']['approach_threshold']);
            $this->assertTrue($d['pick']['ok'], json_encode($d['pick']));
            $out[$npc] = $d;
        }
        // Muiri's strongest cue is the approach: ComeCloser comes back to send; the shy copy's is a glance: words only
        $this->assertSame('approach', $out[self::MUIRI]['pick']['cue']);
        $this->assertSame(self::MUIRI . '|command|ComeCloser@', $out[self::MUIRI]['command']);
        $this->assertNotSame('approach', $out[self::LYNLY]['pick']['cue'], 'who she is: she does not close the distance');
        $this->assertNull($out[self::LYNLY]['command']);

        // what Jev picked is what the dialogue model is told next, and the voice follows Jev's yes
        foreach ([self::MUIRI, self::LYNLY] as $i => $npc) {
            $kit->turn($npc, 'What are you thinking about?', RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 19.0 + $i * 0.1), 'after');
            $felt = $kit->felt[$npc]['after'];
            $this->assertArrayHasKey('body_' . $out[$npc]['pick']['cue'], $felt, $npc);
            $this->assertSame('jev', RelDynBody::chosen(RelationshipDynamics::getDynamics($npc))['source']);
            RelationshipDynamics::endRequest();
        }
        $this->assertSame([], $kit->db->failures);
        $this->assertSame(0, $kit->llmCalls);
    }

    public function testAnUnsureJevPicksNothingAndALaterPickOfTheSameCueWaitsForItsCooldown(): void
    {
        $this->world([self::MUIRI]);
        $this->edit(self::MUIRI, function (array &$d): void { RelationshipDynamics::setPassion($d, 92.0); });
        $unsure = jev_body_decide(self::MUIRI, $this->block(self::MUIRI), null, 'Kaida', $this->jev(null, 0.3), ['ComeCloser'], ['min_confidence' => 0.6]);
        $this->assertFalse($unsure['resolution']['pick']);
        $this->assertNull($unsure['pick']);
        $this->assertSame('default', RelDynBody::chosen(RelationshipDynamics::getDynamics(self::MUIRI))['source'], 'RelDyn own default speaks');
        RelationshipDynamics::endRequest();

        $first = jev_body_decide(self::MUIRI, $this->block(self::MUIRI), null, 'Kaida', $this->jev('approach'), ['ComeCloser'], []);
        $this->assertSame(self::MUIRI . '|command|ComeCloser@', $first['command']);
        $again = jev_body_decide(self::MUIRI, $this->block(self::MUIRI), null, 'Kaida', $this->jev('approach'), ['ComeCloser'], []);
        $this->assertFalse($again['pick']['ok']);
        $this->assertSame('cooldown', $again['pick']['reason']);
        $this->assertNull($again['command'], 'no ComeCloser spam');
        // the block tells Jev the cue is cooling down
        $this->assertGreaterThan(0.0, $this->block(self::MUIRI)['body']['cues']['approach']['cooldown_play_seconds']);
    }

    public function testAQuietBodyAndAnOrdinaryVoiceAreJevsToChooseToo(): void
    {
        $kit = $this->world([self::AELA]);
        $this->edit(self::AELA, function (array &$d): void { RelationshipDynamics::setPassion($d, 88.0); $d['dimensions']['valence']['x'] = 30.0; $d['dimensions']['arousal']['x'] = 60.0; });
        $block = $this->block(self::AELA);
        $this->assertNotNull($block['body']['voice']);
        $d = jev_body_decide(self::AELA, $block, ['goal' => 'Stay unnoticed', 'rules' => 'Keep a guard up.'], 'Kaida', $this->jev('none', 0.95, 0.1), ['ComeCloser'], []);
        $this->assertNull($d['pick']['cue']);
        $this->assertNull($d['command']);
        $kit->turn(self::AELA, 'Well?', RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 19.0), 'quiet');
        $felt = array_keys($kit->felt[self::AELA]['quiet']);
        $this->assertSame([], array_filter($felt, fn($k) => str_starts_with((string) $k, 'body_')), 'a quiet body: no cue text');
        $this->assertNotContains('voice', $felt, 'and an ordinary voice');
        $this->assertTrue($this->block(self::AELA)['body']['voice_held_back']);
    }
}
