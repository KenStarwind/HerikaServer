<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../ext/jev_tactical/lib/jev_client.php";
require_once __DIR__ . "/../../ext/jev_tactical/lib/jev_tactical.php";
require_once __DIR__ . "/../../ext/jev_tactical/lib/jev_body.php";

// RelDyn is not part of this tree. Its API as Jev uses it (the real classes live in ext/relationship_dynamics of Ken's reldyn branch),
// stubbed at the boundary: the block jevStateBlock returns, and pick(), which records what it was asked.
if (!class_exists("RelDynBody", false)) {
    final class RelDynBody
    {
        public static array $picks = [];
        public static array $result = ["ok" => true, "reason" => null, "cue" => null, "strength" => 0.7, "action" => null, "command" => null, "voice" => null];

        public static function pick(string $npcName, ?string $cue, string $source = "jev", ?bool $voice = null): array
        {
            self::$picks[] = [$npcName, $cue, $source, $voice];
            $action = $cue === "approach" ? "ComeCloser" : null;
            return array_replace(self::$result, ["cue" => $cue, "action" => $action, "command" => $action ? "{$npcName}|command|{$action}@" : null]);
        }
    }
}

/**
 * The body part of the Jev tactical layer (RelDyn's bio-mimetic feedback, Ken 2026-10-01 §24): the questions Jev is asked about the
 * body cues RelDyn offers and the voice, how its answers become a pick, and what comes back to send. Pure: no database, no network
 * (the HTTP call is the connector hook the layer's other tests use), RelDyn stubbed at its API.
 */
final class JevBodyTest extends TestCase
{
    protected function setUp(): void
    {
        RelDynBody::$picks = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS["mockConnectorSend"]);
    }

    /** What RelationshipDynamics::jevStateBlock('Muiri') returns, trimmed to what Jev reads of the body. */
    private static function block(array $offered = ["approach", "shy_glance"], ?array $voice = ["family" => "seductive", "mood" => "seductive", "intensity" => "strong"]): array
    {
        $cues = [];
        foreach (["turn_away", "tense_stance", "approach", "shy_glance"] as $id) {
            $cues[$id] = ["strength" => in_array($id, $offered, true) ? 0.6 : 0.0, "offered" => in_array($id, $offered, true),
                "action" => $id === "approach" ? "ComeCloser" : null, "cooldown_play_seconds" => 0.0];
        }
        return [
            "npc"  => "Muiri",
            "text" => "npc=Muiri affinity=60(close_friend) type=romantic passion=80 body=approach:0.60,shy_glance:0.60",
            "goal" => null,
            "body" => ["enabled" => true, "cues" => $cues, "offered" => $offered, "approach_threshold" => 58.0,
                "chosen" => ["cue" => $offered[0] ?? null, "source" => "default", "strength" => 0.6], "voice" => $voice],
        ];
    }

    private static function answers(?string $cue, ?float $conf = 0.9, ?float $voice = null): array
    {
        $a = [];
        if ($cue !== null) {
            $a["cue"] = ["type" => "choice", "choice" => $cue, "confidence" => $conf, "probabilities" => [$cue => $conf]];
        }
        if ($voice !== null) {
            $a["voice"] = ["type" => "noul", "noul" => $voice];
        }
        return $a;
    }

    /* ---------------- questions ---------------- */

    public function testTheCueQuestionOffersOnlyWhatRelDynOffersPlusNoneAndTheVoiceIsAYesNoWhenThereIsOne(): void
    {
        $q = jev_body_build_questions(self::block(), ["npc_name" => "Muiri", "player_name" => "Kaida"]);
        $this->assertSame(["cue", "voice"], array_keys($q));
        $this->assertSame("choice", $q["cue"]["type"]);
        $this->assertSame(["approach", "shy_glance", "none"], array_keys($q["cue"]["criteria"]));
        $this->assertStringContainsString("Muiri moves closer to Kaida", $q["cue"]["criteria"]["approach"]);
        $this->assertSame("noul", $q["voice"]["type"]);
        $this->assertStringContainsString("seductive", $q["voice"]["instructions"]);
        JevClient::validateQuestions($q);   // a request Jev can take

        $noVoice = jev_body_build_questions(self::block(["tense_stance"], null));
        $this->assertSame(["cue"], array_keys($noVoice));
        $voiceOnly = jev_body_build_questions(self::block([], ["family" => "kindly", "mood" => null, "intensity" => "low"]));
        $this->assertSame(["voice"], array_keys($voiceOnly));
        $this->assertSame([], jev_body_build_questions(self::block([], null)), "a quiet body and an ordinary voice: nothing to ask");
    }

    public function testUnknownCueIdsAreNotOffered(): void
    {
        $this->assertSame(["approach"], jev_body_offered_cues(["body" => ["offered" => ["approach", "moonwalk", 7]]]));
    }

    public function testTheStateHasRelDynsNumbersTheGoalAndThePlayer(): void
    {
        $s = jev_body_build_state(self::block(), ["goal" => "Guard the doorway", "rules" => "Do not start fights."], "Kaida");
        $this->assertSame("Muiri", $s["npc"]);
        $this->assertSame("Kaida", $s["player"]);
        $this->assertSame("Guard the doorway", $s["standing_goal"]["goal"]);
        $this->assertStringContainsString("passion=80", $s["relationship"]);
        $this->assertSame(0.6, $s["body"]["cues"]["approach"]["strength"]);
        $this->assertSame(58.0, $s["body"]["approach_threshold"]);
        $this->assertNull(jev_body_build_state(self::block(), null, "Kaida")["standing_goal"]);
    }

    /* ---------------- answers -> a pick ---------------- */

    public function testAConfidentCueIsPickedAndTheVoiceFollowsItsProbability(): void
    {
        $r = jev_body_resolve(self::answers("approach", 0.9, 0.8), self::block());
        $this->assertTrue($r["pick"]);
        $this->assertSame("approach", $r["cue"]);
        $this->assertTrue($r["voice"]);
        $this->assertSame("ok", $r["reason"]);
        $r = jev_body_resolve(self::answers("shy_glance", 0.8, 0.2), self::block());
        $this->assertSame("shy_glance", $r["cue"]);
        $this->assertFalse($r["voice"], "Jev says the voice stays ordinary");
        $this->assertNull(jev_body_resolve(self::answers("shy_glance", 0.8), self::block())["voice"], "no word on the voice: RelDyn's own rule");
    }

    public function testNoneIsAPickOfNoCueAndAnUnsureOrInvalidAnswerIsNoPick(): void
    {
        $none = jev_body_resolve(self::answers("none", 0.9), self::block());
        $this->assertTrue($none["pick"]);
        $this->assertNull($none["cue"]);
        $this->assertSame("quiet_body", $none["reason"]);

        $unsure = jev_body_resolve(self::answers("approach", 0.4, 0.9), self::block());
        $this->assertFalse($unsure["pick"]);
        $this->assertSame("low_confidence", $unsure["reason"]);
        $this->assertNull($unsure["voice"], "an unsure picker leaves the voice to RelDyn too");

        $this->assertSame("invalid_cue", jev_body_resolve(self::answers("turn_away", 0.9), self::block())["reason"], "not offered");
        $this->assertFalse(jev_body_resolve(self::answers("turn_away", 0.9), self::block())["pick"]);
        $this->assertSame("no_answer", jev_body_resolve([], self::block())["reason"]);
        $this->assertSame("nothing_offered", jev_body_resolve([], self::block([], null))["reason"]);
        $voiceOnly = jev_body_resolve(self::answers(null, null, 0.1), self::block([], ["family" => "kindly", "mood" => null, "intensity" => "low"]));
        $this->assertTrue($voiceOnly["pick"]);
        $this->assertNull($voiceOnly["cue"]);
        $this->assertFalse($voiceOnly["voice"]);
        $this->assertSame("voice_only", $voiceOnly["reason"]);
    }

    public function testTheThresholdsAreTheCallers(): void
    {
        $this->assertFalse(jev_body_resolve(self::answers("approach", 0.7), self::block(), ["min_confidence" => 0.8])["pick"]);
        $this->assertTrue(jev_body_resolve(self::answers("approach", 0.7), self::block(), ["min_confidence" => 0.6])["pick"]);
        $this->assertTrue(jev_body_resolve(self::answers("approach", 0.9, 0.55), self::block(), ["voice_at" => 0.6])["voice"] === false);
    }

    /* ---------------- decide: Jev -> RelDyn ---------------- */

    private function client(array $answers): JevClient
    {
        $GLOBALS["mockConnectorSend"] = function (string $url, $context) use ($answers) {
            return json_encode(["model" => "jev-test", "answers" => $answers, "usage" => ["input_tokens" => 100, "output_tokens" => 10]]);
        };
        return new JevClient("test-key", "jev-test", "https://example.invalid/v1/systemone", 5);
    }

    public function testApproachIsPickedAndComesBackAsComeCloserOnlyWhenTheActionIsEnabled(): void
    {
        $d = jev_body_decide("Muiri", self::block(), null, "Kaida", $this->client(self::answers("approach", 0.9, 0.9)), ["ComeCloser", "Follow"], ["min_confidence" => 0.6]);
        $this->assertSame([["Muiri", "approach", "jev", true]], RelDynBody::$picks);
        $this->assertSame("Muiri|command|ComeCloser@", $d["command"]);
        $this->assertSame("ComeCloser", $d["action"]);

        RelDynBody::$picks = [];
        $d = jev_body_decide("Muiri", self::block(), null, "Kaida", $this->client(self::answers("approach", 0.9)), ["Follow"], []);
        $this->assertCount(1, RelDynBody::$picks, "still picked: it speaks as felt text");
        $this->assertNull($d["command"], "but the action is not enabled for this NPC: nothing is sent");
    }

    public function testTheOtherCuesAreContextTextAndSendNothing(): void
    {
        $d = jev_body_decide("Muiri", self::block(["shy_glance"]), null, "Kaida", $this->client(self::answers("shy_glance", 0.9)), ["ComeCloser"], []);
        $this->assertSame([["Muiri", "shy_glance", "jev", null]], RelDynBody::$picks);
        $this->assertNull($d["command"], "core has no animation channel for a glance");
    }

    public function testNoPickIsMadeOnAnUnsureAnswerOrWhenNothingIsAsked(): void
    {
        $d = jev_body_decide("Muiri", self::block(), null, "Kaida", $this->client(self::answers("approach", 0.3)), ["ComeCloser"], ["min_confidence" => 0.6]);
        $this->assertSame([], RelDynBody::$picks);
        $this->assertNull($d["command"]);
        $d = jev_body_decide("Muiri", self::block([], null), null, "Kaida", $this->client([]), ["ComeCloser"], []);
        $this->assertSame([], RelDynBody::$picks);
        $this->assertSame("nothing_asked", $d["resolution"]["reason"]);
    }

    public function testAQuietBodyIsPickedAsNoCue(): void
    {
        jev_body_decide("Muiri", self::block(), ["goal" => "Stay unnoticed", "rules" => ""], "Kaida", $this->client(self::answers("none", 0.95, 0.1)), ["ComeCloser"], []);
        $this->assertSame([["Muiri", null, "jev", false]], RelDynBody::$picks);
    }

    public function testTheRequestJevGetsIsTheStateAndTheTwoQuestions(): void
    {
        $client = $this->client(self::answers("approach", 0.9));
        jev_body_decide("Muiri", self::block(), ["goal" => "Guard the doorway", "rules" => "Stay near the door."], "Kaida", $client, [], []);
        $req = $client->lastRequest;
        $this->assertSame(["cue", "voice"], array_keys($req["questions"]));
        $this->assertSame("Guard the doorway", $req["state"]["standing_goal"]["goal"]);
        $this->assertSame("Muiri", $req["state"]["npc"]);
    }

    public function testTheSummaryAndTheEventTextReadAsWords(): void
    {
        $this->assertSame("approach, voice carries it [confidence 0.90]", jev_body_describe(["pick" => true, "cue" => "approach", "voice" => true, "confidence" => 0.9, "reason" => "ok"]));
        $this->assertSame("quiet body, voice ordinary", jev_body_describe(["pick" => true, "cue" => null, "voice" => false, "confidence" => null, "reason" => "quiet_body"]));
        $this->assertSame("no pick (low_confidence)", jev_body_describe(["pick" => false, "cue" => null, "voice" => null, "confidence" => 0.3, "reason" => "low_confidence"]));
        $this->assertSame("Muiri moves closer to Kaida", jev_body_event_text("Muiri", "Kaida", "approach"));
    }

    public function testRequestTypesAndThresholdsComeFromTheSettings(): void
    {
        $this->assertContains("inputtext", jev_body_request_types());
        $this->assertNotContains("funcret", jev_body_request_types());
        $GLOBALS["JEV_BODY_TYPES"] = "InputText, bored";
        $this->assertSame(["inputtext", "bored"], jev_body_request_types());
        unset($GLOBALS["JEV_BODY_TYPES"]);
        $GLOBALS["JEV_MIN_CONFIDENCE"] = 0.75;
        $this->assertSame(0.75, jev_body_thresholds()["min_confidence"]);
        unset($GLOBALS["JEV_MIN_CONFIDENCE"]);
    }
}
