<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../ext/jev_tactical/lib/jev_client.php";
require_once __DIR__ . "/../../ext/jev_tactical/lib/jev_tactical.php";

/**
 * Pure tests for the Jev tactical layer: no database, no network.
 */
final class JevTacticalTest extends TestCase
{
    private array $candidates;
    private array $enabled;

    protected function setUp(): void
    {
        $this->candidates = [
            "actor"       => ["Dragonborn", "Bandit", "Bandit Marauder"],
            "hostile"     => ["Bandit Marauder"],
            "location"    => ["Riverwood"],
            "nearby_item" => ["0x12345:Iron Sword"],
            "inventory"   => ["Potion of Minor Healing"],
            "spell"       => ["Healing"],
        ];
        $this->enabled = ["Attack", "Follow", "WaitHere", "PickupItem", "GiveItemTo", "CastSpell", "TravelTo", "Inspect"];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS["mockConnectorSend"]);
    }

    /* ---------------- catalog ---------------- */

    public function testCatalogKeepsOnlyEnabledTacticalActionsWithCandidates(): void
    {
        $catalog = jev_tactical_build_catalog($this->enabled, $this->candidates);

        $this->assertArrayHasKey("Attack", $catalog);
        $this->assertArrayHasKey("WaitHere", $catalog);
        $this->assertArrayHasKey("GiveItemTo", $catalog);
        $this->assertArrayNotHasKey("Inspect", $catalog, "informational actions stay with the main model");
        $this->assertArrayNotHasKey("Surrender", $catalog, "not enabled");

        // Attack targets only the known hostiles when there are any
        $this->assertSame(["Bandit Marauder"], $catalog["Attack"]["options"]["target"]);
        // CastSpell target list gets "self"
        $this->assertContains("self", $catalog["CastSpell"]["options"]["target"]);
    }

    public function testCatalogDropsActionsWhoseSlotHasNoCandidates(): void
    {
        $candidates = $this->candidates;
        $candidates["nearby_item"] = [];
        $candidates["location"]    = [];

        $catalog = jev_tactical_build_catalog($this->enabled, $candidates);

        $this->assertArrayNotHasKey("PickupItem", $catalog);
        $this->assertArrayNotHasKey("TravelTo", $catalog);
        $this->assertArrayHasKey("Attack", $catalog);
    }

    public function testAttackFallsBackToAllActorsWhenNoHostileIsKnown(): void
    {
        $candidates = $this->candidates;
        $candidates["hostile"] = [];

        $catalog = jev_tactical_build_catalog(["Attack"], $candidates);

        $this->assertSame(["Dragonborn", "Bandit", "Bandit Marauder"], $catalog["Attack"]["options"]["target"]);
    }

    public function testSlotOptionsAreDedupedCappedAndNeverNumeric(): void
    {
        $many = [];
        for ($i = 0; $i < 300; $i++) {
            $many[] = "Draugr " . $i;
        }
        $many[] = "Draugr 1"; // duplicate
        $many[] = "42";       // numeric label would become a JSON list key
        $many[] = "";

        $options = jev_tactical_slot_options("actor", ["actor" => $many]);

        $this->assertCount(JevClient::MAX_CHOICE_OPTIONS - 1, $options);
        $this->assertNotContains("42", $options);
        $this->assertSame(count($options), count(array_unique($options)));
    }

    /* ---------------- questions ---------------- */

    public function testQuestionsFanOutOneSlotQuestionPerActionSlotWithPremise(): void
    {
        $catalog   = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $questions = jev_tactical_build_questions($catalog, ["npc_name" => "Erik", "player_name" => "Dragonborn"]);

        $this->assertArrayHasKey("action", $questions);
        $this->assertArrayHasKey("Attack.target", $questions);
        $this->assertArrayHasKey("GiveItemTo.target", $questions);
        $this->assertArrayHasKey("GiveItemTo.item", $questions);
        $this->assertArrayHasKey("interrupt", $questions);
        $this->assertArrayHasKey("threat", $questions);
        $this->assertArrayHasKey("needs_deliberation", $questions);
        $this->assertArrayNotHasKey("WaitHere.target", $questions);

        $action = $questions["action"];
        $this->assertSame("choice", $action["type"]);
        $this->assertArrayHasKey(JEV_ACTION_WAIT, $action["criteria"]);
        $this->assertArrayHasKey(JEV_ACTION_ESCALATE, $action["criteria"]);
        $this->assertArrayHasKey("Attack", $action["criteria"]);
        $this->assertStringContainsString("Erik", $action["instructions"]);

        $slot = $questions["Attack.target"];
        $this->assertStringContainsString("'Attack'", $slot["instructions"], "conditional questions state their premise");
        $this->assertArrayHasKey(JEV_OPTION_NONE, $slot["criteria"]);
        $this->assertArrayHasKey("Bandit Marauder", $slot["criteria"]);

        $this->assertSame("noul", $questions["interrupt"]["type"]);
        $this->assertSame("score", $questions["threat"]["type"]);
        $this->assertCount(5, $questions["threat"]["criteria"]);

        JevClient::validateQuestions($questions); // must not throw
    }

    public function testValidateQuestionsRejectsBadChoices(): void
    {
        $this->expectException(JevClientException::class);
        JevClient::validateQuestions(["only" => JevClient::choice("?", ["single"])]);
    }

    public function testValidateQuestionsRejectsTooManyOptions(): void
    {
        $labels = [];
        for ($i = 0; $i < 256; $i++) {
            $labels[] = "opt" . $i;
        }
        $this->expectException(JevClientException::class);
        JevClient::validateQuestions(["big" => JevClient::choice("?", $labels)]);
    }

    /* ---------------- resolution ---------------- */

    private function answers(array $overrides = []): array
    {
        $base = [
            "action"             => ["type" => "choice", "choice" => "Attack", "confidence" => 0.91, "probabilities" => ["Attack" => 0.91, "WaitHere" => 0.05]],
            "Attack.target"      => ["type" => "choice", "choice" => "Bandit Marauder", "confidence" => 0.96, "probabilities" => ["Bandit Marauder" => 0.96, "none" => 0.04]],
            "GiveItemTo.target"  => ["type" => "choice", "choice" => "Dragonborn", "confidence" => 0.8, "probabilities" => []],
            "GiveItemTo.item"    => ["type" => "choice", "choice" => "Potion of Minor Healing", "confidence" => 0.8, "probabilities" => []],
            "interrupt"          => ["type" => "noul", "noul" => 0.98],
            "threat"             => ["type" => "score", "score" => 3.2, "confidence" => 0.8, "probabilities" => ["none" => 0.0, "low" => 0.05, "moderate" => 0.15, "high" => 0.6, "critical" => 0.2]],
            "needs_deliberation" => ["type" => "noul", "noul" => 0.1],
        ];
        return array_replace($base, $overrides);
    }

    public function testResolveActsAndReadsOnlyTheChosenActionsSlots(): void
    {
        $catalog  = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $decision = jev_tactical_resolve($this->answers(), $catalog, ["min_confidence" => 0.6, "escalate_threshold" => 0.7]);

        $this->assertSame(JEV_KIND_ACT, $decision["kind"]);
        $this->assertSame("Attack", $decision["action"]);
        $this->assertSame(["target" => "Bandit Marauder"], $decision["params"]);
        $this->assertSame("high", $decision["threat"]["level"]);
        $this->assertEqualsWithDelta(0.98, $decision["interrupt"], 0.001);
        $this->assertSame("Erik|command|Attack@Bandit Marauder", jev_tactical_format_command("Erik", $decision));
    }

    public function testResolveEscalatesWhenDeliberationIsNeeded(): void
    {
        $catalog  = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $decision = jev_tactical_resolve($this->answers(["needs_deliberation" => ["type" => "noul", "noul" => 0.85]]), $catalog);

        $this->assertSame(JEV_KIND_ESCALATE, $decision["kind"]);
        $this->assertSame("needs_deliberation", $decision["reason"]);
        $this->assertNull(jev_tactical_format_command("Erik", $decision));
    }

    public function testResolveEscalatesWhenModelPicksEscalate(): void
    {
        $catalog  = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $decision = jev_tactical_resolve($this->answers(["action" => ["type" => "choice", "choice" => JEV_ACTION_ESCALATE, "confidence" => 0.9, "probabilities" => []]]), $catalog);

        $this->assertSame(JEV_KIND_ESCALATE, $decision["kind"]);
        $this->assertSame("model_requested", $decision["reason"]);
    }

    public function testResolveWaitsOnLowActionConfidence(): void
    {
        $catalog  = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $decision = jev_tactical_resolve($this->answers(["action" => ["type" => "choice", "choice" => "Attack", "confidence" => 0.4, "probabilities" => []]]), $catalog, ["min_confidence" => 0.6]);

        $this->assertSame(JEV_KIND_WAIT, $decision["kind"]);
        $this->assertSame("low_confidence", $decision["reason"]);
    }

    public function testResolveWaitsWhenSlotAnswerIsNoneOrInvalid(): void
    {
        $catalog = jev_tactical_build_catalog($this->enabled, $this->candidates);

        $none = jev_tactical_resolve($this->answers(["Attack.target" => ["type" => "choice", "choice" => "none", "confidence" => 0.9, "probabilities" => []]]), $catalog);
        $this->assertSame(JEV_KIND_WAIT, $none["kind"]);
        $this->assertSame("no_target:target", $none["reason"]);

        $invalid = jev_tactical_resolve($this->answers(["Attack.target" => ["type" => "choice", "choice" => "Alduin", "confidence" => 0.9, "probabilities" => []]]), $catalog);
        $this->assertSame(JEV_KIND_WAIT, $invalid["kind"]);
        $this->assertSame("invalid_target:target", $invalid["reason"]);

        $missing = jev_tactical_resolve($this->answers(["Attack.target" => null]), $catalog);
        $this->assertSame(JEV_KIND_WAIT, $missing["kind"]);
    }

    public function testResolveWaitsWhenModelChoosesWait(): void
    {
        $catalog  = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $decision = jev_tactical_resolve($this->answers(["action" => ["type" => "choice", "choice" => JEV_ACTION_WAIT, "confidence" => 0.9, "probabilities" => []]]), $catalog);

        $this->assertSame(JEV_KIND_WAIT, $decision["kind"]);
        $this->assertSame("model_chose_wait", $decision["reason"]);
    }

    public function testMultiParameterActionsAreFormattedAsJson(): void
    {
        $catalog  = jev_tactical_build_catalog($this->enabled, $this->candidates);
        $decision = jev_tactical_resolve($this->answers(["action" => ["type" => "choice", "choice" => "GiveItemTo", "confidence" => 0.9, "probabilities" => []]]), $catalog);

        $this->assertSame(JEV_KIND_ACT, $decision["kind"]);
        $this->assertSame('Erik|command|GiveItemTo@{"target":"Dragonborn","item":"Potion of Minor Healing"}', jev_tactical_format_command("Erik", $decision));
    }

    public function testNoParameterActionsAreFormattedWithEmptyParameter(): void
    {
        $decision = ["kind" => JEV_KIND_ACT, "action" => "WaitHere", "params" => []];
        $this->assertSame("Erik|command|WaitHere@", jev_tactical_format_command("Erik", $decision));
    }

    /* ---------------- parsing helpers ---------------- */

    public function testActorListParsingTagsHostilesAndSkipsSelfAndDead(): void
    {
        $parsed = jev_tactical_parse_actor_list("|Erik|Bandit (hostile) (12m)|Draugr (dead)|Serana|", "Erik");

        $this->assertSame(["Bandit", "Serana"], $parsed["actor"]);
        $this->assertSame(["Bandit"], $parsed["hostile"]);
        $this->assertSame(["Bandit (hostile) (12m)", "Serana"], $parsed["annotated"]);
    }

    public function testItemListParsingKeepsRefIdAndDropsNotes(): void
    {
        $parsed = jev_tactical_parse_item_list("0x123:Iron Sword (STEALING), 0x456:Soul Gem (Grand) (Dragonborn is looking at this)");

        $this->assertSame(["0x123:Iron Sword", "0x456:Soul Gem (Grand)"], $parsed["nearby_item"]);
    }

    public function testGoalExpiresAfterTtl(): void
    {
        $now = 1000000;
        $ext = ["jev_goal" => ["goal" => "Guard", "set_localts" => $now - 100]];

        $this->assertNotNull(jev_tactical_goal_from_extended($ext, $now, 900));
        $this->assertNull(jev_tactical_goal_from_extended($ext, $now, 50));
        $this->assertNull(jev_tactical_goal_from_extended([], $now, 900));
        $this->assertNull(jev_tactical_goal_from_extended(["jev_goal" => ["goal" => ""]], $now, 900));
    }

    public function testStateCarriesGoalSituationAndTrigger(): void
    {
        $goal  = ["goal" => "Guard", "rules" => "No fights", "set_localts" => 1000, "issued" => 2, "last_decision" => "WaitHere"];
        $state = jev_tactical_build_state($goal, ["npc_name" => "Erik", "hostile" => ["Bandit"], "trigger" => ["type" => "bored"]], 1000 + 300);

        $this->assertSame("Erik", $state["character"]["name"]);
        $this->assertSame("Guard", $state["standing_goal"]["goal"]);
        $this->assertSame(5, $state["standing_goal"]["set_minutes_ago"]);
        $this->assertSame(["Bandit"], $state["hostiles"]);
        $this->assertSame("bored", $state["trigger"]["type"]);
    }

    /* ---------------- client ---------------- */

    public function testClientPostsBearerJsonAndParsesAnswers(): void
    {
        $captured = [];
        $GLOBALS["mockConnectorSend"] = function ($url, $context) use (&$captured) {
            $captured["url"]     = $url;
            $captured["options"] = stream_context_get_options($context);
            return json_encode([
                "model"   => "jev-1.13.0",
                "answers" => [
                    "action" => ["type" => "choice", "choice" => "Attack", "confidence" => 0.9, "probabilities" => ["Attack" => 0.9]],
                    "urgent" => ["type" => "noul", "noul" => 0.82],
                ],
                "usage"   => ["input_tokens" => 382, "output_tokens" => 55],
            ]);
        };

        $client  = new JevClient("test-key", "jev-latest");
        $answers = $client->decide(["hp" => 10], [
            "action" => JevClient::choice("?", ["Attack" => null, "Wait" => null]),
            "urgent" => JevClient::noul("Escalate?"),
        ]);

        $this->assertSame(JevClient::DEFAULT_URL, $captured["url"]);
        $this->assertSame("POST", $captured["options"]["http"]["method"]);
        $this->assertStringContainsString("Authorization: Bearer test-key", $captured["options"]["http"]["header"]);
        $this->assertStringContainsString("Content-Type: application/json", $captured["options"]["http"]["header"]);

        $body = json_decode($captured["options"]["http"]["content"], true);
        $this->assertSame("jev-latest", $body["model"]);
        $this->assertSame(["hp" => 10], $body["state"]);
        $this->assertSame("choice", $body["questions"]["action"]["type"]);
        $this->assertSame(["Attack" => null, "Wait" => null], $body["questions"]["action"]["criteria"]);

        $this->assertSame("Attack", JevClient::answerChoice($answers, "action")["choice"]);
        $this->assertEqualsWithDelta(0.82, JevClient::answerNoul($answers, "urgent"), 0.001);
        $this->assertNull(JevClient::answerChoice($answers, "missing"));
        $this->assertSame(382, $client->lastUsage["input_tokens"]);
    }

    public function testClientRaisesOnErrorResponse(): void
    {
        $GLOBALS["mockConnectorSend"] = fn($url, $context) => json_encode(["error" => ["message" => "invalid api key"]]);

        $client = new JevClient("bad-key");
        $this->expectException(JevClientException::class);
        $this->expectExceptionMessage("invalid api key");
        $client->decide("state", ["q" => JevClient::noul("?")]);
    }

    public function testClientRequiresApiKey(): void
    {
        putenv("TYPESAFE_API_KEY");
        $client = new JevClient("");
        $this->assertFalse($client->hasApiKey());
        $this->expectException(JevClientException::class);
        $client->decide("state", ["q" => JevClient::noul("?")]);
    }

    public function testScoreAnswerPicksMostLikelyLevel(): void
    {
        $score = JevClient::answerScore(["threat" => ["type" => "score", "score" => 1.2, "probabilities" => ["none" => 0.2, "low" => 0.7, "moderate" => 0.1]]], "threat");
        $this->assertSame("low", $score["level"]);
        $this->assertEqualsWithDelta(1.2, $score["score"], 0.001);
    }
}
