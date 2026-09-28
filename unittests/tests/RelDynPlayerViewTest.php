<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_player_view.php';

/**
 * player-profile-page (D:\docs\reldyn-player-profile-design.md "The Profile Page"): the page's view
 * model from the stored mirror, over the four test beds (Aela the Huntress, Ashe, Muiri, Lynly
 * Star-Sung; nothing of any story, only exchanges the eval scored). The design's bars with bands,
 * charisma, attachment with its transition, love language, reputation, the prose of how NPCs
 * experience the player (band keywords, no LLM), the growth trajectory, and the shareable card
 * (no NPC name on it). Pure: no database.
 */
final class RelDynPlayerViewTest extends TestCase
{
    private const H = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const BEDS = ['Aela the Huntress', 'Ashe', 'Muiri', 'Lynly Star-Sung'];
    private array $saved = [];
    private int $q = 0;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME'] as $k) {
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

    /** One observation as RelDynMirror::observe() stores it (q = its sequence number). */
    private function obs(string $npc, float $hour, array $s = [], array $tags = [], array $o = []): array
    {
        $sig = [];
        foreach (RelDynMirror::SIGNALS as $k) $sig[] = floatval($s[$k] ?? 0);
        return ['fp' => 'fp' . (++$this->q), 'q' => $this->q, 'g' => (int) round(100 * self::DAY + $hour * self::H),
            'n' => $npc, 's' => $sig, 't' => $tags, 'sig' => $o['sig'] ?? 0.4, 'pi' => $o['pi'] ?? 1, 'gv' => $o['gv'] ?? 0,
            'ri' => $o['ri'] ?? 0, 'c' => ($o['c'] ?? []) + ['cf' => false, 'cm' => false, 'pa' => false, 'b' => 50]]
            + (isset($o['ch']) ? ['ch' => $o['ch']] : []);
    }

    /** $n kind, reliable exchanges spread over the four beds, 6 game hours apart. */
    private function kind(int $n, float $from = 0.0): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->obs(self::BEDS[$i % 4], $from + 6.0 * $i, ['affinity' => 3, 'trust' => 3, 'comfort' => 2, 'respect' => 2, 'maturity' => 1],
                ['help'], ['ch' => 'rock']);
        }
        return $out;
    }

    private static function state(array $obs, array $history = []): array
    {
        return ['v' => 1, 'total' => count($obs), 'obs' => $obs, 'history' => $history];
    }

    private static function lastG(array $obs): float
    {
        return floatval(end($obs)['g']);
    }

    public function testAnUnknownPlayerIsSaidToBeUnknownNotScored(): void
    {
        $v = RelDynPlayerView::build(self::state([]), RelDynMirror::config(), 0.0, 'Kaida');
        $this->assertFalse($v['known']);
        $this->assertSame(0, $v['interactions']);
        $this->assertStringStartsWith('Nobody has formed a picture of you yet.', $v['prose']);
        $this->assertSame('Not read yet', $v['charisma']['text']);
        $this->assertSame('0 of 5 graded exchanges needed', $v['charisma']['detail']);
        $this->assertSame('Not read yet', $v['attachment']['text']);
        $this->assertSame('Not clear yet', $v['love_language']['text']);
        $this->assertSame('Unremarkable', $v['reputation']['text']);
        $this->assertSame([], $v['trajectory']['lines']);
        $this->assertStringStartsWith('No trajectory yet', $v['trajectory']['note']);
        foreach ($v['dimensions'] as $d) {
            $this->assertSame(50.0, $d['score']);
            $this->assertSame('middling', $d['band_name']);
        }
        $this->assertSame('Kaida', $v['card']['title']);
        $this->assertFalse($v['prompt_enabled'], 'the felt line is opt-in (Rangroo)');
    }

    public function testAReliableRockAcrossTheFourBedsReadsAsDependableWithProseTrajectoryAndReputation(): void
    {
        $obs = $this->kind(60);
        $now = self::lastG($obs);
        // a snapshot 30 game days before: everything neutral then
        $then = ['g' => (int) ($now - 30 * self::DAY), 'scores' => array_fill_keys(RelDynMirror::DIMENSIONS, 50.0)];
        $v = RelDynPlayerView::build(self::state($obs, [$then]), RelDynMirror::config(), $now, 'Kaida');
        $this->assertTrue($v['known']);
        $this->assertSame(4, $v['npcs']);
        $this->assertSame(60, $v['interactions']);
        $trust = $v['dimensions']['trust'];
        $this->assertEqualsWithDelta(68.75, $trust['score'], 0.01);
        $this->assertSame(4, $trust['band']);
        $this->assertSame('high', $trust['band_name']);
        $this->assertSame('dependable, follows through, good to their word', $trust['keywords']);
        $this->assertSame(60, $trust['evidence']);
        $this->assertSame([50.0, 68.75], $trust['history'], 'the snapshot, then now');

        $this->assertSame('rock', $v['charisma']['primary']);
        $this->assertSame('The Rock', $v['charisma']['text']);
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $v['love_language']['primary']);
        $this->assertSame('Acts of Service', $v['love_language']['text']);
        $this->assertSame('Well regarded', $v['reputation']['text']);
        $this->assertSame('trust +7.2 with new NPCs', $v['reputation']['detail']);

        // prose: the most telling bands in words, then style and love language
        $this->assertStringContainsString('Dependable, follows through, good to their word.', $v['prose']);
        $this->assertStringContainsString('Steady under pressure; people lean on that calm.', $v['prose']);
        $this->assertStringContainsString('Shows care by doing: help, protection, a rescue.', $v['prose']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $v['prose'], 'words, not numbers');

        // trajectory: up from the snapshot, dated
        $lines = array_column($v['trajectory']['lines'], null, 'axis');
        $this->assertSame('up', $lines['trust']['trend']);
        $this->assertSame('↑', $lines['trust']['arrow']);
        $this->assertSame('trending up (was 50, 30 game days ago)', $lines['trust']['text']);
        $this->assertNull($v['trajectory']['note']);
    }

    public function testAttachmentShowsItsTransitionFromThePreviousRead(): void
    {
        // 70 pushes where she pulls away (anxious), then 55 repairs of her open conflict (secure)
        $obs = [];
        for ($i = 0; $i < 70; $i++) {
            $obs[] = $this->obs(self::BEDS[$i % 4], 6.0 * $i, ['affinity' => 0], [], ['ri' => 2, 'pi' => 0, 'c' => ['pa' => true]]);
        }
        for ($i = 70; $i < 125; $i++) {
            $obs[] = $this->obs(self::BEDS[$i % 4], 6.0 * $i, ['affinity' => 1], ['apology'], ['c' => ['cf' => true]]);
        }
        $cfg = RelDynMirror::config();
        $v = RelDynPlayerView::build(self::state($obs), $cfg, self::lastG($obs), 'Kaida');
        $a = $v['attachment'];
        $this->assertSame('secure', $a['primary']);
        $this->assertTrue($a['confident']);
        $this->assertSame(['from' => 'anxious', 'from_label' => 'Anxious', 'to' => 'secure', 'to_label' => 'Secure'], $a['transition']);
        $this->assertMatchesRegularExpression('/^Anxious → Secure \(in transition, 6\d%\)$/', $a['text']);
        $this->assertStringStartsWith('secondary: Anxious', $a['detail']);
        $this->assertSame('in transition', $v['card']['tiles'][1]['sub']);

        // the same pattern read twice: no transition
        $steady = RelDynPlayerView::build(self::state(array_slice($obs, 0, 50)), $cfg, self::lastG($obs), 'Kaida');
        $this->assertSame('anxious', $steady['attachment']['primary']);
        $this->assertNull($steady['attachment']['transition']);
        $this->assertMatchesRegularExpression('/^Anxious \(\d+% match\)$/', $steady['attachment']['text']);
    }

    public function testCharacterModeHoldsTheSeedAndSaysSo(): void
    {
        $cfg = RelDynMirror::config();
        $cfg['mode'] = 'character';
        $cfg['character_seed'] = ['trust' => 90, 'attachment' => 'secure', 'charisma' => 'charmer', 'love_language' => RelationshipDynamics::LL_GIFTS];
        $v = RelDynPlayerView::build(self::state($this->kind(8)), $cfg, 0.0, 'Kaida');
        $this->assertSame('character', $v['mode']);
        $this->assertSame('Secure (character)', $v['attachment']['text']);
        $this->assertSame('held by Character mode', $v['attachment']['detail']);
        $this->assertSame('charmer', $v['charisma']['primary']);
        $this->assertSame('Gifts', $v['love_language']['text']);
        $this->assertGreaterThan(80.0, $v['dimensions']['trust']['score']);
        $this->assertStringContainsString('character mode', $v['card']['subtitle']);
        $this->assertSame('THE CHARMER', $v['card']['badge']);
    }

    public function testTheCardCarriesTheProfileButNoNpcNameAndCanHideThePlayersName(): void
    {
        $v = RelDynPlayerView::build(self::state($this->kind(40)), RelDynMirror::config(), 0.0, 'Kaida');
        $json = json_encode($v['card'], JSON_UNESCAPED_UNICODE);
        foreach (self::BEDS as $npc) $this->assertStringNotContainsString($npc, $json, "{$npc} stays off a card meant for sharing");
        $this->assertSame('Kaida', $v['card']['title']);
        $this->assertSame('Behavioral mirror // 40 interactions with 4 people', $v['card']['subtitle']);
        $this->assertSame(['Charisma', 'Attachment', 'Love language', 'Reputation'], array_column($v['card']['tiles'], 'label'));
        $this->assertCount(6, $v['card']['spider']['axes']);
        $this->assertStringContainsString('Dependable, follows through', $v['card']['quote']);
        $this->assertLessThanOrEqual(2, count(preg_split('/(?<=[.!?])\s+/', $v['card']['quote'])));
        $anon = RelDynPlayerView::card($v, 'Kaida', false);
        $this->assertSame('Anonymous adventurer', $anon['title']);
        // the SVG renders
        $svg = RelDynUiCharts::card($v['card']);
        $this->assertNotFalse(simplexml_load_string($svg));
    }
}
