<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php';

/**
 * The duty affinity channel (roadmap duty-affinity-channel; Ken 2026-10-01 §24, pipeline Addendum 9), end to end on a real
 * PostgreSQL with the four test beds (Aela the Huntress, Ashe (Serene's hand-set vector, never read, nothing of her story
 * anywhere), Muiri (toxic) and Lynly Star-Sung (a bard; the bio does not establish shyness, so none is forced)) plus Farkas,
 * through the real hooks (prerequest -> context_pre -> context -> postrequest), CHIM 3.4.1 core-shaped rows. No LLM call
 * but the eval worker's, stubbed at the connector boundary.
 *
 * The party: Aela and Ashe follow the player (core's follower faction), Muiri is sworn to the player (core's type), Lynly
 * is nobody's follower. Over a day's march they talk to the player every few hours, fight beside the player, and the
 * quest they share moves and finishes:
 *   - the three who serve earn duty from service, from the fight (by the enemy's threat), from the quest and its
 *     finish; Lynly earns none, whoever she is and whatever she does;
 *   - who they are decides how fast (the dutiful trait), so the three end up in different places, and a sworn protector
 *     earns more than a follower for the same service (the role's strength);
 *   - the player looking after one earns more and treating one badly wears it down, each by who the NPC is;
 *   - it speaks in its own <duty_context> block (not in <subtext>), in the NPC's own gender, with no numbers;
 *   - it NEVER feeds desire: the same march with the channel switched off leaves every dimension, the desire the NPC feels
 *     (sex_disposal) and the published consent decision exactly as they were;
 *   - when the service ends the duty lingers, a long absence takes only a little of it, and the NPC says they once served.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynWSocialDutyTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const BEDS = [self::AELA, self::ASHE, self::MUIRI, self::LYNLY];
    private const INSULT = 'You call that a hunt? I mock your whole pack of hunters.';

    private ?RelDynNetworkBedsKit $kit = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if ($this->kit !== null) $this->kit->destroy();
        $this->kit = null;
        unset($GLOBALS['contextDataFull']);
    }

    private function t(float $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    private function world(array $config = []): RelDynNetworkBedsKit
    {
        $this->kit = $kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'wduty', $config);
        $kit->seed([
            self::AELA => ['Player' => [60, 'friend']],
            self::ASHE => ['Player' => [60, 'friend']],
            self::MUIRI => ['Player' => [60, 'sworn']],
            self::LYNLY => ['Player' => [60, 'friend']],
            self::FARKAS => ['Player' => [50, 'friend']],
        ]);
        pg_query($kit->db->link, "CREATE TABLE questlog (ts text, sess varchar(1024), id_quest varchar(1024), name text, editor_id text, giver_actor_id text,
            reward text, target_id text, is_unique boolean, mod text, stage integer, briefing text, briefing2 text, localts bigint, gamets bigint, data text,
            status text, rowid serial)");
        return $kit;
    }

    /** One march: first words at 0, a word to each every 3 game hours to $until. Returns the next free hour. */
    private function march(RelDynNetworkBedsKit $kit, float $from, float $until, array $npcs = self::BEDS, string $label = 'march'): void
    {
        for ($h = $from; $h <= $until + 1e-9; $h += 3.0) {
            foreach ($npcs as $i => $npc) $kit->turn($npc, 'Keep up.', $this->t($h) + $i * 600000, $label);
        }
    }

    /** Core's combat end after a fight the $fighters barked in, through the real hooks. */
    private function fight(RelDynNetworkBedsKit $kit, array $fighters, int $gamets, string $speaker = self::AELA): void
    {
        foreach ($fighters as $i => $npc) $kit->event('infoaction', RelDynNetworkBedsKit::PLAYER . ": Behind you! ({$npc} shouts during combat)", $gamets - 20000 + $i * 1000);
        $data = '(Context location: Whiterun outdoors)';
        $kit->event('combatend', $data, $gamets);
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = ['combatend', (string) $kit->realTs, (string) $gamets, $data];
            $GLOBALS['HERIKA_NAME'] = $speaker;
            $GLOBALS['RELDYN_NPC_NAME'] = $speaker;
            $GLOBALS['CACHE_PEOPLE'] = $kit->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $kit->clearReldynGlobals();
        }
        $kit->realTs += 60;
    }

    /** A journal quest naming the NPCs, and a stage of it in core's log. */
    private function quest(RelDynNetworkBedsKit $kit, string $id, array $aliases): void
    {
        pg_query_params($kit->db->link, "INSERT INTO quests (ts, gamets, name, briefing, data, stage, giver_actor_id, id_quest, sess, status, localts)
            VALUES ('1', \$1, 'The Long March', 'Reach Whiterun.', \$2, 20, '', \$3, 'pending', '', 1)", [$this->t(-2.0), json_encode($aliases), $id]);
    }

    private function stage(RelDynNetworkBedsKit $kit, string $id, int $stage, float $hours): void
    {
        pg_query_params($kit->db->link, "INSERT INTO questlog (ts, gamets, localts, briefing, data, id_quest, stage) VALUES ('1', \$1, 1, 'Reach Whiterun.', 'Reach Whiterun.', \$2, \$3)",
            [$this->t($hours), $id, $stage]);
    }

    private function duty(RelDynNetworkBedsKit $kit, string $npc): array
    {
        return $kit->dynamics($npc)[RelDynDuty::KEY] ?? [];
    }

    private function value(RelDynNetworkBedsKit $kit, string $npc): float
    {
        return floatval($this->duty($kit, $npc)['value'] ?? 0.0);
    }

    /** The system blocks the context hooks put in front of the LLM on the last turn. */
    private function blocks(): array
    {
        return array_values(array_map(fn($m) => (string) $m['content'], array_filter((array) ($GLOBALS['contextDataFull'] ?? []), fn($m) => is_array($m) && isset($m['content']))));
    }

    private function dutyBlock(): ?string
    {
        foreach ($this->blocks() as $b) if (str_contains($b, '<duty_context>')) return $b;
        return null;
    }

    private function assertClean(RelDynNetworkBedsKit $kit): void
    {
        $this->assertSame([], $kit->db->failures, 'failed SQL statements');
        $this->assertSame(0, $kit->llmCalls, 'no trait read');
        $this->assertStringNotContainsString('ERROR', $kit->errorLog(), $kit->errorLog());
    }

    /** The day's march, the fight and the quest, for the world $kit; returns the hour the last turn was at. */
    private function journey(RelDynNetworkBedsKit $kit): float
    {
        $this->march($kit, 0.0, 0.0, self::BEDS, 'first');   // the first words start each clock (and anchor the questlog watermark)
        $this->quest($kit, 'C02', ['QuestGiver' => self::AELA, 'Ally' => self::ASHE, 'Sworn' => self::MUIRI, 'Singer' => self::LYNLY]);
        $this->march($kit, 3.0, 9.0);
        $this->fight($kit, [self::AELA, self::ASHE, self::MUIRI], $this->t(10.0));
        $this->stage($kit, 'C02', 30, 11.0);
        $this->march($kit, 12.0, 15.0);
        $this->stage($kit, 'C02', 200, 16.0);
        $this->march($kit, 18.0, 24.0);
        return 24.0;
    }

    // ------------------------------------------------------------------ the story

    public function testTheServingEarnDutyFromServiceTheFightAndTheQuestAndTheOneWhoDoesNotServeEarnsNone(): void
    {
        $kit = $this->world();
        $this->journey($kit);
        $value = [];
        foreach (self::BEDS as $npc) $value[$npc] = $this->value($kit, $npc);
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $npc) {
            $d = $this->duty($kit, $npc);
            $this->assertTrue($d['bound'], $npc);
            $this->assertGreaterThan(3.0, $value[$npc], "{$npc} served a day");
            foreach (['service', 'combat', 'quest'] as $source) $this->assertGreaterThan(0.0, $d['sources'][$source], "{$npc}: {$source}");
            $this->assertEqualsWithDelta($value[$npc], array_sum($d['sources']), 0.01, "{$npc}: the ledger accounts for all of it (nothing decayed in a day)");
        }
        $this->assertSame('follower', $this->duty($kit, self::AELA)['role']);
        $this->assertSame('follower', $this->duty($kit, self::ASHE)['role']);
        $this->assertSame('sworn', $this->duty($kit, self::MUIRI)['role']);
        $this->assertSame([], $this->duty($kit, self::LYNLY), 'nobody\'s follower: no duty, and no state');
        // who they are decides how fast: the three end up in different places, and the sworn role counts for more than the follower's
        $this->assertGreaterThan(0.2, abs($value[self::AELA] - $value[self::ASHE]), 'two followers, two people');
        $disposition = [];
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $npc) $disposition[$npc] = RelDynDuty::jev($kit->dynamics($npc))['disposition'];
        $perStrength = fn(string $npc) => $value[$npc] / ($this->duty($kit, $npc)['role'] === 'sworn' ? 1.0 : 0.8) / $disposition[$npc];
        $this->assertEqualsWithDelta($perStrength(self::AELA), $perStrength(self::ASHE), $perStrength(self::AELA) * 0.2, 'same service, same arc: duty follows the role and who they are');
        $this->assertClean($kit);
    }

    public function testDutySpeaksInItsOwnBlockInTheNPCsOwnGenderWithNoNumbers(): void
    {
        $kit = $this->world();
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET gender = 'male' WHERE npc_name = \$1", [self::FARKAS]);
        $this->journey($kit);
        // Farkas keeps the Companions' hall: housecarl by the editor's hand-set role
        $kit->turn(self::FARKAS, 'Keep up.', $this->t(0.5), 'farkas');
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics,_duty_role_override}', '\"housecarl\"') WHERE npc_name = \$1", [self::FARKAS]);
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics,_duty_affinity}',
            '{\"value\": 45, \"gamets\": " . $this->t(24.0) . ", \"last_credit\": " . $this->t(24.0) . ", \"role\": \"housecarl\", \"bound\": true}') WHERE npc_name = \$1", [self::FARKAS]);
        $blocks = [];
        foreach ([self::AELA, self::MUIRI, self::LYNLY, self::FARKAS] as $i => $npc) {
            $kit->turn($npc, 'Keep up.', $this->t(25.0 + $i * 0.1), 'last');
            $blocks[$npc] = $this->dutyBlock();
            $subtext = implode("\n", array_filter($this->blocks(), fn($b) => str_contains($b, '<subtext>')));
            $this->assertStringNotContainsString('duty', strtolower((string) preg_replace('/duty_context/', '', $subtext)), "{$npc}: duty is not in <subtext>");
        }
        $this->assertNull($blocks[self::LYNLY], 'the bard serves no one: no block');
        $this->assertStringContainsString("Aela the Huntress travels in Kaida's service.", (string) $blocks[self::AELA]);
        $this->assertStringContainsString('Muiri is bound to Kaida by oath.', (string) $blocks[self::MUIRI]);
        $this->assertStringContainsString('Farkas is sworn to the household of Kaida.', (string) $blocks[self::FARKAS]);
        foreach ([self::AELA, self::MUIRI] as $npc) {
            $this->assertMatchesRegularExpression('/\b(She|she|her)\b/', (string) $blocks[$npc], "{$npc} is a woman in core");
            $this->assertDoesNotMatchRegularExpression('/\b(he|him|his)\b/i', (string) $blocks[$npc], $npc);
        }
        $this->assertMatchesRegularExpression('/\b(He|he|his)\b/', (string) $blocks[self::FARKAS], 'and Farkas a man');
        $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers)\b/i', (string) $blocks[self::FARKAS]);
        foreach ($blocks as $npc => $b) if ($b !== null) $this->assertDoesNotMatchRegularExpression('/\d/', $b, "{$npc}: feelings, never numbers");
        $this->assertClean($kit);
    }

    public function testBeingLookedAfterEarnsMoreAndBeingTreatedBadlyWearsItDownEachByWhoTheNPCIs(): void
    {
        $kit = $this->world();
        $this->journey($kit);
        $before = [];
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $npc) $before[$npc] = $this->value($kit, $npc);
        // the player binds their wounds: a help exchange, scored by the (stubbed) eval
        $kit->evalReply = function (string $exchange): ?array {
            if (str_contains($exchange, 'let me bind that wound')) {
                return ['signals' => ['affinity' => 3, 'trust' => 2], 'tags' => ['help'], 'significance' => 0.7, 'summary' => 'The player bound their wounds.'];
            }
            if (str_contains($exchange, 'mock your whole pack')) {
                return ['signals' => ['affinity' => -30, 'trust' => -4], 'tags' => ['insult'], 'significance' => 1.0, 'summary' => 'The player mocked their hunt.',
                    'grievance' => ['flag' => true, 'kind' => 'disrespect', 'severity' => 2]];
            }
            return null;
        };
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $i => $npc) $kit->turn($npc, 'Hold still, let me bind that wound.', $this->t(25.0 + $i * 0.1), 'care');
        $kit->worker();
        $care = [];
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $npc) {
            $care[$npc] = $this->duty($kit, $npc)['sources']['care'] ?? 0.0;
            $this->assertGreaterThan(0.0, $care[$npc], "{$npc}: looked after");
            $this->assertGreaterThan($before[$npc], $this->value($kit, $npc), $npc);
        }
        $mid = [];
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $npc) $mid[$npc] = $this->value($kit, $npc);
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $i => $npc) $kit->turn($npc, self::INSULT, $this->t(30.0 + $i * 0.1), 'insult');
        $kit->worker();
        // (the march went on between the exchanges, so the total also holds the service of those hours: the ledger is where the insult shows)
        foreach ([self::AELA, self::ASHE, self::MUIRI] as $npc) {
            $mistreat = $this->duty($kit, $npc)['sources']['mistreat'];
            $this->assertLessThan(0.0, $mistreat, "{$npc}: treated badly, duty wears down");
            $this->assertGreaterThan(-$mid[$npc] * 0.5, $mistreat, "{$npc}: but one insult is not the end of it");
            $this->assertGreaterThan(0.0, $this->value($kit, $npc), 'and nobody is brought to nothing');
        }
        $this->assertClean($kit);
    }

    public function testItNeverFeedsDesireTheSameMarchWithTheChannelOffLeavesEveryDimensionAndTheConsentDecisionAsTheyWere(): void
    {
        $snapshot = function (RelDynNetworkBedsKit $kit): array {
            $out = [];
            foreach (self::BEDS as $npc) {
                $d = $kit->dynamics($npc);
                $consent = $kit->pluginKey($npc, 'consent');
                if (is_array($consent)) foreach (array_keys($consent) as $k) if (preg_match('/(gamets|_at|time|stamp)/i', (string) $k)) unset($consent[$k]);
                $dims = [];
                foreach ((array) ($d['dimensions'] ?? []) as $dim => $v) $dims[$dim] = round(floatval($v['x'] ?? 0.0), 3);
                $out[$npc] = ['dims' => $dims, 'core_aff' => $kit->coreAff($npc), 'consent' => $consent,
                    'disposition' => RelationshipDynamics::getEffectiveDisposition(8, $d), 'passion' => round(RelationshipDynamics::getEffectivePassion($d), 3),
                    'jealousy' => round(floatval($d['jealousy_anger'] ?? 0.0), 3), 'attraction' => $d['_attraction'] ?? null];
            }
            return $out;
        };
        $on = $this->world();
        $this->journey($on);
        $this->assertGreaterThan(3.0, $this->value($on, self::AELA));
        $a = $snapshot($on);
        $on->destroy();
        $this->kit = null;
        $off = $this->world(['duty' => ['enabled' => false]]);
        $this->journey($off);
        $this->assertSame(0.0, $this->value($off, self::AELA), 'switched off: nothing is earned');
        $b = $snapshot($off);
        $this->assertSame($b, $a, 'duty moved no dimension, no desire, no consent decision');
        $this->assertNull($this->dutyBlock(), 'and the off world writes no block');
        $this->assertClean($off);
    }

    public function testWhenTheServiceEndsTheDutyLingersAndIsSaidInThePast(): void
    {
        $kit = $this->world();
        $this->journey($kit);
        $held = $this->value($kit, self::AELA);
        $this->assertGreaterThan(3.0, $held);
        // dismissed: no longer a follower
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{factions}', '[]'::jsonb) WHERE npc_name = \$1", [self::AELA]);
        $kit->turn(self::AELA, 'You may go.', $this->t(26.0), 'gone');
        $this->assertFalse($this->duty($kit, self::AELA)['bound']);
        $this->assertSame('follower', $this->duty($kit, self::AELA)['role'], 'the last role is remembered');
        $afterDay = $this->value($kit, self::AELA);
        $this->assertEqualsWithDelta($held, $afterDay, $held * 0.02, 'a day (inside the grace) takes nothing');
        // a month away: only a little of it is gone (the half-life is half a year or more, and a dutiful NPC holds longer)
        $kit->turn(self::AELA, 'Well met again.', $this->t(26.0 + 24.0 * 30), 'month');
        $kept = $this->value($kit, self::AELA) / $held;
        $this->assertGreaterThan(0.78, $kept, 'loyalty lingers');
        $this->assertLessThan(1.0, $kept, 'but it does fade');
        $this->assertGreaterThan(0.0, $this->value($kit, self::AELA));
        $this->assertClean($kit);
    }

    public function testTheEditorShowsTheChannelAndSetsOrClearsTheRole(): void
    {
        $kit = $this->world();
        $this->journey($kit);
        $fields = fn() => RelDynEditor::model(self::AELA)['sections']['states']['fields'];
        $state = $fields()['state:duty_affinity'];
        $this->assertStringContainsString('follower', (string) $state['value']);
        $this->assertStringContainsString('serving now', (string) $state['value']);
        $role = $fields()['duty:role'];
        $this->assertSame('derived', $role['state']);
        $this->assertSame('', $role['value']);
        $this->assertTrue($role['editable'], 'editable');
        // hand-set
        $session = [];
        $post = function (array $p) use (&$session): array { return RelDynEditor::handle('POST', [], $p + ['npc' => self::AELA, 'csrf_token' => RelDynEditor::csrfToken($session)], $session); };
        $this->assertSame(303, $post(['op' => 'save', 'section' => 'states', 'f' => ['duty:role' => 'housecarl']])['status']);
        $this->assertTrue($session[RelDynEditor::FLASH_SESSION_KEY]['ok'], json_encode($session));
        $this->assertSame('housecarl', $kit->dynamics(self::AELA)[RelDynDuty::ROLE_OVERRIDE_KEY]);
        $this->assertSame('override', $fields()['duty:role']['state']);
        $kit->turn(self::AELA, 'Keep up.', $this->t(30.0), 'after');
        $this->assertSame('housecarl', $this->duty($kit, self::AELA)['role'], 'the hand-set role outranks the follower faction');
        // a bad value is refused
        $post(['op' => 'save', 'section' => 'states', 'f' => ['duty:role' => 'overlord']]);
        $this->assertFalse($session[RelDynEditor::FLASH_SESSION_KEY]['ok']);
        $this->assertSame('housecarl', $kit->dynamics(self::AELA)[RelDynDuty::ROLE_OVERRIDE_KEY]);
        // reset the field: derived again
        $post(['op' => 'reset_field|duty:role']);
        $this->assertArrayNotHasKey(RelDynDuty::ROLE_OVERRIDE_KEY, $kit->dynamics(self::AELA));
        $this->assertSame('derived', $fields()['duty:role']['state']);
        // the duty earned is reset with its own field, and a fresh start of the whole NPC forgets it too
        $held = $this->value($kit, self::AELA);
        $this->assertGreaterThan(3.0, $held);
        $post(['op' => 'reset_field|state:duty_affinity']);
        $this->assertSame(0.0, $this->value($kit, self::AELA));
        $this->assertClean($kit);
    }

    public function testTheEditorsHandSetRoleBindsAnyoneAndNoneFreesAFollower(): void
    {
        $kit = $this->world();
        $this->march($kit, 0.0, 0.0, [self::LYNLY], 'a');
        $this->assertSame([], $this->duty($kit, self::LYNLY));
        // the editor's field: Lynly serves as a housecarl, by hand; a role of "none" frees Aela
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics,_duty_role_override}', '\"housecarl\"') WHERE npc_name = \$1", [self::LYNLY]);
        $this->march($kit, 3.0, 9.0, [self::LYNLY], 'b');
        $this->assertSame('housecarl', $this->duty($kit, self::LYNLY)['role']);
        $this->assertGreaterThan(1.0, $this->value($kit, self::LYNLY), 'a hand-set role earns like a derived one, at the housecarl\'s strength');
        $this->march($kit, 0.0, 0.0, [self::AELA], 'c');
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(plugin_extended_data, '{reldyn,dynamics,_duty_role_override}', '\"none\"') WHERE npc_name = \$1", [self::AELA]);
        $this->march($kit, 3.0, 9.0, [self::AELA], 'd');
        $this->assertSame(0.0, $this->value($kit, self::AELA), 'hand-set to none, a follower in the faction is not bound');
        $this->assertClean($kit);
    }
}
